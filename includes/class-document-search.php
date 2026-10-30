<?php
/**
 * Document search endpoint.
 *
 *   GET /wp-json/wp-skos/v1/posts
 *       ?concept=URI&concept=URI&operator=AND|OR&page=1&per_page=20
 *
 * Accepts SKOS concept URIs, resolves to WP term IDs, applies HT expansion,
 * and returns relevance-scored documents as JSON-LD.
 *
 * Without HT: queries WP_Query with the exact term IDs, ordered by date.
 * With HT: expands inclusions and hierarchy before querying.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_SKOS_Document_Search {

	/**
	 * @since 1.0.0
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	/**
	 * @since 1.0.0
	 */
	public static function register_route(): void {
		register_rest_route( 'wp-skos/v1', '/posts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'concept'  => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'string' ),
					'required' => true,
				),
				'operator' => array(
					'type'    => 'string',
					'enum'    => array( 'AND', 'OR' ),
					'default' => 'OR',
				),
				'page'     => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 1,
					'maximum' => 100,
				),
				// External (peer/feed) document scope. Passed opaquely to HyperTaxonomy;
				// the specific peer topology is intentionally not exposed here.
				'platforms'  => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'default'     => array(),
					'description' => __( 'Peer platform types for external documents (e.g. wordpress, feed). Empty = all supported.', 'wp-skos' ),
				),
				'foaf_depth' => array(
					'type'        => 'integer',
					'default'     => 0,
					'minimum'     => 0,
					'description' => __( 'FOAF traversal depth for external documents. 0 = direct peers only.', 'wp-skos' ),
				),
			),
		) );
	}

	/**
	 * @since 1.0.0
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$concept_uris = (array) $request->get_param( 'concept' );
		$operator     = strtoupper( (string) $request->get_param( 'operator' ) );
		$page         = max( 1, (int) $request->get_param( 'page' ) );
		$per_page     = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );

		if ( empty( $concept_uris ) ) {
			return new WP_REST_Response( array( 'error' => __( 'At least one concept URI is required.', 'wp-skos' ) ), 400 );
		}

		// Resolve URIs → WP_Term objects. Track which URIs mapped to which terms.
		$resolved_terms = array(); // [ uri => WP_Term ]
		foreach ( $concept_uris as $uri ) {
			$term = self::resolve_concept_uri( (string) $uri );
			if ( $term instanceof WP_Term ) {
				$resolved_terms[ (string) $uri ] = $term;
			}
		}

		// HyperTaxonomy owns term visibility: drop concepts hidden from the current
		// user so neither local results nor external documents leak them. Hidden
		// terms are an HT concept, so this is a no-op when HT is not active. Guard
		// on both functions actually called here, not just the first one — a build
		// exposing htax_is_term_hidden() without htax_can_see_hidden_terms() would
		// otherwise fatal instead of gracefully degrading.
		if ( function_exists( 'htax_is_term_hidden' ) && function_exists( 'htax_can_see_hidden_terms' ) ) {
			foreach ( $resolved_terms as $uri => $term ) {
				if ( htax_is_term_hidden( $term->term_id ) && ! htax_can_see_hidden_terms( $term->taxonomy ) ) {
					unset( $resolved_terms[ $uri ] );
				}
			}
		}

		if ( empty( $resolved_terms ) ) {
			return new WP_REST_Response(
				self::build_envelope( array(), 0, $page, $per_page ),
				200
			);
		}

		// Build expanded term IDs per concept URI (preserving which URI → which term IDs).
		$expanded_per_concept = array();
		$all_expanded_ids     = array();
		foreach ( $resolved_terms as $uri => $term ) {
			$ids = self::expand_term_ids( $term );
			$expanded_per_concept[ $uri ] = $ids;
			$all_expanded_ids             = array_merge( $all_expanded_ids, $ids );
		}
		$all_expanded_ids = array_unique( array_map( 'intval', $all_expanded_ids ) );

		// Build WP_Query tax_query.
		$tax_groups = array();
		foreach ( $resolved_terms as $uri => $term ) {
			$ids = $expanded_per_concept[ $uri ];
			if ( empty( $ids ) ) {
				continue;
			}
			$tax_groups[] = array(
				'taxonomy'         => $term->taxonomy,
				'field'            => 'term_id',
				'terms'            => $ids,
				'operator'         => 'IN',
				'include_children' => false, // already expanded
			);
		}

		if ( empty( $tax_groups ) ) {
			return new WP_REST_Response(
				self::build_envelope( array(), 0, $page, $per_page ),
				200
			);
		}

		// AND means every concept group must match; OR means any one.
		if ( count( $tax_groups ) > 1 ) {
			$tax_query = array_merge( array( 'relation' => $operator ), $tax_groups );
		} else {
			$tax_query = $tax_groups;
		}

		// Count, rank, and paginate in SQL, so the cost of a request does not grow
		// with the number of matching posts on the PHP side.
		global $wpdb;
		$candidate_sql = self::candidate_sql( $tax_query );
		$total         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( {$candidate_sql} ) AS cand" );

		// The taxonomies of the queried concepts gate which relationship types count
		// toward relevance, per their enabled features.
		$taxonomies  = array();
		foreach ( $resolved_terms as $term ) {
			$taxonomies[ $term->taxonomy ] = true;
		}
		// Hidden-term index rows count only for users HyperTaxonomy lets see hidden
		// terms in every queried taxonomy (its configurable visibility policy).
		$authed = function_exists( 'htax_can_see_hidden_terms' );
		foreach ( array_keys( $taxonomies ) as $tax ) {
			$authed = $authed && htax_can_see_hidden_terms( $tax );
		}
		$page_ids = self::ranked_page_ids( $candidate_sql, $all_expanded_ids, $authed, array_keys( $taxonomies ), $per_page, ( $page - 1 ) * $per_page );

		// Load the page's posts, preserving rank order.
		$posts    = array();
		foreach ( $page_ids as $pid ) {
			$post = get_post( $pid );
			if ( $post instanceof WP_Post ) {
				$posts[] = $post;
			}
		}

		$resolved_uris = array_keys( $resolved_terms );
		$results       = self::build_results( $posts, $expanded_per_concept );

		// Append external/peer-site documents sourced through HyperTaxonomy's generic
		// term API (visibility-gated by HT; absent when HyperTaxonomy is not active).
		// Scope is opaque to specific peers — only platform type and FOAF depth.
		$external_args = array(
			'platforms'  => (array) $request->get_param( 'platforms' ),
			'foaf_depth' => max( 0, (int) $request->get_param( 'foaf_depth' ) ),
			'per_page'   => $per_page,
		);
		$results = self::append_external_documents( $results, $resolved_terms, $page, $per_page, $external_args, $operator );

		$response = new WP_REST_Response(
			self::build_envelope( $results, $total, $page, $per_page ),
			200
		);

		self::add_link_headers( $response, $resolved_uris, $operator, $page, $per_page, $total );

		return $response;
	}

	/**
	 * SQL for the candidate post set: published posts matching the concepts' tax query.
	 *
	 * WP_Query builds the SQL, so every tax-query filter (including HyperTaxonomy's
	 * hidden-term handling on parse_tax_query) applies exactly as it does for any
	 * other query, but the query is not run: the SQL is used as a subquery, so the
	 * matching IDs are never loaded into PHP. Selects a single `ID` column.
	 *
	 * @since 1.0.0
	 * @param array $tax_query Tax query for the requested concepts.
	 * @return string
	 */
	private static function candidate_sql( array $tax_query ): string {
		$query      = new WP_Query();
		$skip_query = static function ( $posts, WP_Query $q ) use ( $query ) {
			return $q === $query ? array() : $posts;
		};
		add_filter( 'posts_pre_query', $skip_query, PHP_INT_MAX, 2 );
		$query->query( array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'tax_query'           => $tax_query,
			'fields'              => 'ids',
			'posts_per_page'      => -1,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'cache_results'       => false,
		) );
		remove_filter( 'posts_pre_query', $skip_query, PHP_INT_MAX );

		return $query->request;
	}

	/**
	 * One page of candidate post IDs, ranked by descending summed HT relevance strength.
	 *
	 * Scored posts (highest strength first) come before candidates absent from the
	 * index (implicit score 0). Within equal scores, and for unscored posts, newer
	 * posts come first, then higher post IDs. Without HyperTaxonomy or its index
	 * table, every candidate is ordered by that date/ID order alone.
	 *
	 * @since 1.0.0
	 * @param string   $candidate_sql Candidate SQL from candidate_sql().
	 * @param int[]    $expanded_ids  All expanded term IDs across every concept.
	 * @param bool     $authed        Whether the user may see hidden terms' rows.
	 * @param string[] $taxonomies    Taxonomies of the queried concepts (for feature gating).
	 * @param int      $limit         Page size.
	 * @param int      $offset        Rows to skip.
	 * @return int[]
	 */
	private static function ranked_page_ids( string $candidate_sql, array $expanded_ids, bool $authed, array $taxonomies, int $limit, int $offset ): array {
		global $wpdb;

		$scores_sql = self::scores_sql( $expanded_ids, $authed, $taxonomies );
		$page_sql   = ' LIMIT ' . absint( $limit ) . ' OFFSET ' . absint( $offset );

		// The candidate SQL is spliced in after prepare() so that any literal '%' it
		// contains is never read as a placeholder.
		if ( '' === $scores_sql ) {
			$sql = "SELECT p.ID
					FROM ( {$candidate_sql} ) AS cand
					INNER JOIN {$wpdb->posts} AS p ON p.ID = cand.ID
					ORDER BY p.post_date DESC, p.ID DESC" . $page_sql;
		} else {
			$scores_sql = str_replace( '{candidates}', $candidate_sql, $scores_sql );
			$sql        = "SELECT p.ID
					FROM ( {$candidate_sql} ) AS cand
					INNER JOIN {$wpdb->posts} AS p ON p.ID = cand.ID
					LEFT JOIN ( {$scores_sql} ) AS s ON s.post_id = p.ID
					ORDER BY ( s.post_id IS NULL ), s.score DESC, p.post_date DESC, p.ID DESC" . $page_sql;
		}

		return array_map( 'intval', $wpdb->get_col( $sql ) );
	}

	/**
	 * Prepared SQL computing each candidate's summed relevance score from HT's index.
	 *
	 * Returns rows of (post_id, score) for candidates with index rows. Contains a
	 * literal `{candidates}` token for the caller to replace with the candidate SQL.
	 * Returns '' when HyperTaxonomy or its index table is unavailable.
	 *
	 * @since 1.0.0
	 * @param int[]    $expanded_ids All expanded term IDs across every concept.
	 * @param bool     $authed       Whether the user may see hidden terms' rows.
	 * @param string[] $taxonomies   Taxonomies of the queried concepts (for feature gating).
	 * @return string
	 */
	private static function scores_sql( array $expanded_ids, bool $authed, array $taxonomies ): string {
		if ( empty( $expanded_ids ) || ! function_exists( 'htax_get_expanded_term_ids' ) ) {
			return '';
		}

		global $wpdb;
		$table = $wpdb->prefix . 'htax_search_terms';

		// Fall back to date order when the index table does not exist.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return '';
		}

		$term_ph = implode( ',', array_fill( 0, count( $expanded_ids ), '%d' ) );

		$hidden_clause = $authed ? '' : ' AND is_hidden = 0';

		// The index stores strength per scoring model; aggregate only the one
		// HyperTaxonomy uses for search, or rows would count once per model.
		// Guarded like allowed_rel_types()'s HT call below: a build exposing
		// htax_get_expanded_term_ids() without htax_strength_model_sql() degrades
		// to no model filter instead of a fatal error.
		$model_clause = function_exists( 'htax_strength_model_sql' ) ? htax_strength_model_sql() : '';

		// Gate relationship types per taxonomy by that taxonomy's enabled features —
		// the same gate HyperTaxonomy applies in its own archive/search scoring, so a
		// disabled feature (e.g. inclusions or redirects) never contributes strength.
		// The index stores a taxonomy per row, so the gate is OR'd per taxonomy.
		$rel_gate    = '';
		$gate_params = array();
		$gate_parts  = array();
		foreach ( $taxonomies as $tax ) {
			$allowed = self::allowed_rel_types( (string) $tax );
			$rel_ph  = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
			$gate_parts[]  = "(taxonomy = %s AND relationship_type IN ($rel_ph))";
			$gate_params[] = (string) $tax;
			foreach ( $allowed as $rt ) {
				$gate_params[] = $rt;
			}
		}
		if ( ! empty( $gate_parts ) ) {
			$rel_gate = ' AND (' . implode( ' OR ', $gate_parts ) . ')';
		}

		// Per-term score combines all rows for a (post, term) pair via noisy-OR
		// (1 - Π(1 - strength)) — multiple paths to the same term (multiple source
		// terms, or one source reached via more than one relationship type) raise the
		// term's score monotonically while staying strictly below 1.0, the ceiling a
		// single direct row can reach. A term reached by exactly one path is
		// unaffected. The outer SUM then keeps the breadth of distinct query terms.
		// Matches HyperTaxonomy's own search/widget scoring.
		// LEAST(1, ...) bounds (1 - strength): every stored strength is in [0,1] (HyperTaxonomy's built-in
		// formula, and htax_set_term_strengths() rejects out-of-range scores), so this only
		// guards against a negative value written to the table directly, which would push
		// the noisy-OR product above 1 and yield a negative combined score.
		$sql = "SELECT post_id, SUM(term_score) AS score
				FROM (
					SELECT post_id, term_id,
					       1 - EXP(SUM(LN(LEAST(1, GREATEST(1 - strength, 0.000001))))) AS term_score
					FROM {$table}
					WHERE term_id IN ($term_ph)
					  AND post_id IN ( SELECT ID FROM ( {candidates} ) AS cand_scored )$hidden_clause$model_clause$rel_gate
					GROUP BY post_id, term_id
				) AS per_term
				GROUP BY post_id";

		return $wpdb->prepare( $sql, array_merge( $expanded_ids, $gate_params ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Relationship types that count toward relevance for a taxonomy, gated by its
	 * enabled HyperTaxonomy features (matching HyperTaxonomy's own scoring gate).
	 *
	 * @since 1.0.0
	 * @param string $taxonomy
	 * @return string[]
	 */
	private static function allowed_rel_types( string $taxonomy ): array {
		$allowed = array( 'direct' );
		if ( is_taxonomy_hierarchical( $taxonomy ) ) {
			$allowed[] = 'hierarchy';
		}
		if ( function_exists( 'htax_taxonomy_has_feature' ) ) {
			if ( htax_taxonomy_has_feature( $taxonomy, 'term_relationships' ) ) {
				$allowed[] = 'container';
			}
			if ( htax_taxonomy_has_feature( $taxonomy, 'redirects' ) ) {
				$allowed[] = 'redirect_target';
			}
		}
		return $allowed;
	}

	/**
	 * Resolve a concept URI to a WP_Term.
	 *
	 * Parses taxonomy and slug from pattern
	 * {home_url}/wp-skos/concept/{taxonomy}/{slug} and looks the term up
	 * within its own taxonomy (URIs are taxonomy-qualified).
	 *
	 * @since 1.0.0
	 */
	private static function resolve_concept_uri( string $uri ): ?WP_Term {
		$home = rtrim( home_url(), '/' );
		// Use ~ as the delimiter: the character classes below contain '#', which would
		// otherwise collide with a '#' delimiter and break the pattern.
		$pattern = '~^' . preg_quote( $home . '/wp-skos/concept/', '~' ) . '([^/?#]+)/([^/?#]+)~';

		if ( ! preg_match( $pattern, $uri, $m ) ) {
			return null;
		}

		$taxonomy = sanitize_key( rawurldecode( $m[1] ) );
		$slug     = rawurldecode( $m[2] );

		if ( ! WP_SKOS_Settings::is_taxonomy_enabled( $taxonomy ) ) {
			return null;
		}

		$term = get_term_by( 'slug', $slug, $taxonomy );
		return $term instanceof WP_Term ? $term : null;
	}

	/**
	 * Return an array of WP term IDs covering the concept and its HT expansion.
	 *
	 * With HT active: uses htax_get_expanded_term_ids() — the canonical archive
	 * expansion (term + visible WP descendants + fully expanded HT inclusions and
	 * group-based expansion). Falls back to the term + WP children when HT returns
	 * an empty set (taxonomy not HT-enabled) or HT is absent.
	 *
	 * @since 1.0.0
	 * @return int[]
	 */
	private static function expand_term_ids( WP_Term $term ): array {
		if ( function_exists( 'htax_get_expanded_term_ids' ) ) {
			// Include redirect sources so querying a target also returns the
			// redirecting terms' posts, matching the HyperTaxonomy search index.
			$ids = htax_get_expanded_term_ids( $term->term_id, true );
			if ( ! empty( $ids ) ) {
				return array_unique( array_map( 'intval', $ids ) );
			}
		}

		// Fallback: term + WP hierarchy only.
		$ids      = array( $term->term_id );
		$children = get_term_children( $term->term_id, $term->taxonomy );
		if ( ! is_wp_error( $children ) ) {
			foreach ( $children as $child_id ) {
				$ids[] = (int) $child_id;
			}
		}

		return array_unique( $ids );
	}

	/**
	 * Build the local schema:Article result items for a page of posts.
	 *
	 * Each item's dct:subject lists only the queried concepts the post actually
	 * matches (a term from that concept's expanded set is assigned to it) — under
	 * OR a post may match a subset of the concepts.
	 *
	 * @since 1.0.0
	 * @param WP_Post[] $posts
	 * @param array     $expanded_per_concept Concept URI => expanded term IDs.
	 * @return array[]
	 */
	private static function build_results( array $posts, array $expanded_per_concept ): array {
		$results = array();

		// Prime the term cache for every post in one batch, so each wp_get_object_terms()
		// call below hits a warm cache instead of issuing its own query per post.
		if ( ! empty( $posts ) ) {
			update_object_term_cache(
				wp_list_pluck( $posts, 'ID' ),
				array_unique( wp_list_pluck( $posts, 'post_type' ) )
			);
		}

		foreach ( $posts as $post ) {
			$post_term_ids = wp_get_object_terms( $post->ID, get_object_taxonomies( $post->post_type ), array( 'fields' => 'ids' ) );
			$post_term_ids = is_wp_error( $post_term_ids ) ? array() : array_map( 'intval', $post_term_ids );
			$concept_uris  = array();
			foreach ( $expanded_per_concept as $uri => $ids ) {
				if ( array_intersect( $post_term_ids, array_map( 'intval', $ids ) ) ) {
					$concept_uris[] = (string) $uri;
				}
			}

			$result = array(
				'@type'     => 'schema:Article',
				'@id'       => get_permalink( $post ),
				'dct:title' => get_the_title( $post ),
				'dct:date'  => get_post_time( 'c', true, $post ),
				'dct:subject' => array_values( array_map(
					fn( $uri ) => array( '@id' => $uri ),
					$concept_uris
				) ),
			);

			if ( has_post_thumbnail( $post ) ) {
				$result['schema:image'] = get_the_post_thumbnail_url( $post, 'medium' );
			}

			$excerpt = get_the_excerpt( $post );
			if ( $excerpt ) {
				$result['dct:description'] = $excerpt;
			}

			$results[] = $result;
		}

		return $results;
	}

	/**
	 * Append external/peer-site documents as foaf:Document results, sourced through
	 * HyperTaxonomy's generic term API.
	 *
	 * Documents come from HT's `htax_get_term_external_posts()` (which any provider —
	 * e.g. TermConnect — populates via the `htax_term_external_posts` filter). wp-skos
	 * neither knows nor cares which plugin supplied them, and the provider never knows
	 * wp-skos consumed them. HyperTaxonomy enforces term visibility, so hidden concepts
	 * contribute nothing. When HyperTaxonomy is not active, the getter is absent and no
	 * external documents are added.
	 *
	 * `$args` carries opaque scope hints (platform types, FOAF depth) — the specific
	 * peer topology is deliberately not exposed by this endpoint.
	 *
	 * Contributed on the first page only: wp-skos paginates and totals its local
	 * results independently, so appending the external set to page 1 avoids
	 * duplicating it across pages.
	 *
	 * @since 1.0.0
	 * @param array[]               $results        Local results to append to.
	 * @param array<string,WP_Term> $resolved_terms Visible concept URI => term map.
	 * @param int                   $page
	 * @param int                   $per_page
	 * @param array                 $args           Opaque scope hints for HT's getter.
	 * @param string                $operator       'AND' keeps only documents matching every
	 *                                              concept; 'OR' (default) keeps the union.
	 * @return array[]
	 */
	private static function append_external_documents( array $results, array $resolved_terms, int $page, int $per_page, array $args = array(), string $operator = 'OR' ): array {
		if ( $page > 1 || ! function_exists( 'htax_get_term_external_posts' ) ) {
			return $results;
		}

		// Collect documents across the (already visibility-gated) concept terms,
		// tracking which concept URI(s) each document matched, and deduplicating
		// by URL (a post may match more than one concept).
		$by_url = array();
		foreach ( $resolved_terms as $uri => $term ) {
			$docs = htax_get_term_external_posts( $term->term_id, $args );
			foreach ( $docs as $doc ) {
				$url = (string) ( $doc['url'] ?? '' );
				if ( '' === $url ) {
					continue;
				}
				if ( ! isset( $by_url[ $url ] ) ) {
					$by_url[ $url ] = array( 'post' => $doc, 'subjects' => array() );
				} elseif ( (float) ( $doc['score'] ?? 0 ) > (float) ( $by_url[ $url ]['post']['score'] ?? 0 ) ) {
					$by_url[ $url ]['post'] = $doc;
				}
				$by_url[ $url ]['subjects'][ (string) $uri ] = true;
			}
		}

		// With operator=AND across multiple concepts, keep only documents that
		// matched every queried concept (intersection by URL) — mirroring the
		// local query's AND semantics. Each peer source is fetched per concept,
		// so a document present for all of them carries every concept in subjects.
		if ( 'AND' === $operator && count( $resolved_terms ) > 1 ) {
			$required = count( $resolved_terms );
			$by_url   = array_filter(
				$by_url,
				static function ( array $entry ) use ( $required ): bool {
					return count( $entry['subjects'] ) >= $required;
				}
			);
		}

		if ( empty( $by_url ) ) {
			return $results;
		}

		$collected = array_values( $by_url );

		// Sort by score desc, then recency desc.
		usort( $collected, static function ( array $a, array $b ): int {
			$sa = (float) ( $a['post']['score'] ?? 0 );
			$sb = (float) ( $b['post']['score'] ?? 0 );
			if ( $sa !== $sb ) {
				return $sb <=> $sa;
			}
			return strcmp( (string) ( $b['post']['date_gmt'] ?? '' ), (string) ( $a['post']['date_gmt'] ?? '' ) );
		} );

		$collected = array_slice( $collected, 0, $per_page );

		foreach ( $collected as $entry ) {
			$results[] = self::to_foaf_document( $entry['post'], array_keys( $entry['subjects'] ) );
		}

		return $results;
	}

	/**
	 * Convert a normalized peer post (tc_external_normalize_post shape, as returned
	 * through HT's htax_get_term_external_posts) into a foaf:Document JSON-LD array.
	 *
	 * @since 1.0.0
	 * @param array    $post         Normalized post.
	 * @param string[] $concept_uris Concept URIs this document matched (dct:subject).
	 * @return array
	 */
	private static function to_foaf_document( array $post, array $concept_uris ): array {
		$subjects = array_values( array_map(
			static fn( $uri ) => array( '@id' => (string) $uri ),
			$concept_uris
		) );

		$doc = array(
			'@type'       => 'foaf:Document',
			'@id'         => (string) $post['url'],
			'dct:title'   => (string) ( $post['title'] ?? '' ),
			'dct:subject' => $subjects,
		);

		$date_gmt = (string) ( $post['date_gmt'] ?? '' );
		if ( '' !== $date_gmt ) {
			$ts = strtotime( $date_gmt . ' UTC' );
			if ( false !== $ts ) {
				$doc['dct:date'] = gmdate( 'c', $ts );
			}
		}

		$excerpt = (string) ( $post['excerpt'] ?? '' );
		if ( '' !== $excerpt ) {
			$doc['dct:description'] = $excerpt;
		}

		if ( ! empty( $post['thumbnail_url'] ) ) {
			$doc['schema:image'] = (string) $post['thumbnail_url'];
		}

		if ( ! empty( $post['origin'] ) ) {
			$doc['foaf:homepage'] = (string) $post['origin'];
		}

		return $doc;
	}

	/**
	 * Build the JSON-LD response envelope.
	 *
	 * @since 1.0.0
	 * @param array[] $results
	 */
	private static function build_envelope( array $results, int $total, int $page, int $per_page ): array {
		$context = array(
			'schema' => 'https://schema.org/',
			'foaf'   => 'http://xmlns.com/foaf/0.1/',
			'dct'    => 'http://purl.org/dc/terms/',
		);

		return array(
			'@context'    => $context,
			'results'     => $results,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * Set RFC 5988 pagination Link headers on the response.
	 *
	 * Emits rel="self" plus rel="first"/"prev"/"next"/"last" as applicable, each
	 * pointing at this endpoint with the same concept/operator/per_page params and
	 * the appropriate page. All links are combined into a single comma-joined
	 * Link header value.
	 *
	 * @since 1.0.0
	 * @param string[] $concept_uris
	 */
	private static function add_link_headers( WP_REST_Response $response, array $concept_uris, string $operator, int $page, int $per_page, int $total ): void {
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;
		$base        = rest_url( 'wp-skos/v1/posts' );

		$build = static function ( int $target_page ) use ( $base, $concept_uris, $operator, $per_page ): string {
			$args = array(
				'concept'  => $concept_uris,
				'operator' => $operator,
				'per_page' => $per_page,
				'page'     => $target_page,
			);
			return add_query_arg( $args, $base );
		};

		$links   = array();
		$links[] = sprintf( '<%s>; rel="self"', $build( $page ) );
		$links[] = sprintf( '<%s>; rel="first"', $build( 1 ) );

		if ( $page > 1 ) {
			$links[] = sprintf( '<%s>; rel="prev"', $build( $page - 1 ) );
		}
		if ( $total_pages > 0 && $page < $total_pages ) {
			$links[] = sprintf( '<%s>; rel="next"', $build( $page + 1 ) );
		}
		if ( $total_pages > 0 ) {
			$links[] = sprintf( '<%s>; rel="last"', $build( $total_pages ) );
		}

		$response->header( 'Link', implode( ', ', $links ) );
	}
}
