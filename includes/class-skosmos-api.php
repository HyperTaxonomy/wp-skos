<?php
/**
 * Skosmos REST API implementation.
 *
 * Maps Skosmos REST API paths to WordPress taxonomy data:
 *
 *   GET /skosmos/rest/v1/vocabularies
 *   GET /skosmos/rest/v1/{vocab}/
 *   GET /skosmos/rest/v1/{vocab}/topConcepts
 *   GET /skosmos/rest/v1/{vocab}/lookup?label=X&lang=en
 *   GET /skosmos/rest/v1/{vocab}/data?uri=X
 *   GET /skosmos/rest/v1/search?query=X&vocab={v}&lang=en
 *
 * {vocab} maps to an enabled WP taxonomy slug.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_SKOS_Skosmos_API {

	/**
	 * @since 1.0.0
	 */
	public static function register(): void {
		add_action( 'init',              array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars',        array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'dispatch' ) );
	}

	/**
	 * @since 1.0.0
	 */
	public static function add_rewrite_rules(): void {
		// Single catch-all rule; path parsing happens in dispatch().
		add_rewrite_rule( '^skosmos/rest/v1/(.*)$', 'index.php?wpskos_skosmos_path=$matches[1]', 'top' );
	}

	/**
	 * @since 1.0.0
	 */
	public static function add_query_vars( array $vars ): array {
		$vars[] = 'wpskos_skosmos_path';
		return $vars;
	}

	/**
	 * Route the request to the appropriate handler.
	 *
	 * @since 1.0.0
	 */
	public static function dispatch(): void {
		$path = get_query_var( 'wpskos_skosmos_path' );
		if ( $path === '' || $path === false ) {
			return;
		}

		// Normalize: trim slashes, remove query string (handled via $_GET).
		$path  = trim( $path, '/' );
		$parts = explode( '/', $path );

		$first = $parts[0] ?? '';

		// /vocabularies
		if ( $first === 'vocabularies' ) {
			self::vocabularies();
		}

		// /search (cross-vocabulary)
		if ( $first === 'search' ) {
			self::search();
		}

		// /{vocab}/...
		$vocab = sanitize_key( $first );
		if ( ! WP_SKOS_Settings::is_taxonomy_enabled( $vocab ) ) {
			self::error( __( 'Unknown vocabulary', 'wp-skos' ), 404 );
		}

		$endpoint = $parts[1] ?? '';

		switch ( $endpoint ) {
			case '':
				self::vocab_info( $vocab );
				break;
			case 'topConcepts':
				self::top_concepts( $vocab );
				break;
			case 'lookup':
				self::lookup( $vocab );
				break;
			case 'data':
				self::data( $vocab );
				break;
			default:
				self::error( __( 'Unknown endpoint', 'wp-skos' ), 404 );
		}
	}

	// -------------------------------------------------------------------------
	// Endpoints
	// -------------------------------------------------------------------------

	/**
	 * GET /skosmos/rest/v1/vocabularies
	 *
	 * @since 1.0.0
	 */
	private static function vocabularies(): void {
		$default_lang = WP_SKOS_Settings::get_default_language();
		$vocabs       = array();

		foreach ( WP_SKOS_Settings::get_enabled_taxonomies() as $taxonomy ) {
			$tax_obj = get_taxonomy( $taxonomy );
			if ( ! $tax_obj ) {
				continue;
			}
			$vocabs[] = array(
				'uri'             => self::api_base( $taxonomy ),
				'id'              => $taxonomy,
				'title'           => $tax_obj->label,
				'defaultLanguage' => $default_lang,
				'languages'       => array( $default_lang ),
			);
		}

		self::json_response( array(
			'@context'    => self::skosmos_context(),
			'uri'         => home_url( '/skosmos/rest/v1/vocabularies' ),
			'vocabularies' => $vocabs,
		) );
	}

	/**
	 * GET /skosmos/rest/v1/{vocab}/
	 *
	 * @since 1.0.0
	 */
	private static function vocab_info( string $vocab ): void {
		$tax_obj = get_taxonomy( $vocab );
		if ( ! $tax_obj ) {
			self::error( __( 'Taxonomy not found', 'wp-skos' ), 404 );
		}

		$scheme_uri = WP_SKOS_Builder::scheme_uri( $vocab );

		self::json_response( array(
			'@context'        => self::skosmos_context(),
			'uri'             => self::api_base( $vocab ),
			'id'              => $vocab,
			'title'           => $tax_obj->label,
			'defaultLanguage' => WP_SKOS_Settings::get_default_language(),
			'languages'       => array( WP_SKOS_Settings::get_default_language() ),
			'conceptschemes'  => array(
				array(
					'uri'       => $scheme_uri,
					'prefLabel' => $tax_obj->label,
				),
			),
		) );
	}

	/**
	 * GET /skosmos/rest/v1/{vocab}/topConcepts?lang=en
	 *
	 * @since 1.0.0
	 */
	private static function top_concepts( string $vocab ): void {
		$lang = sanitize_key( $_GET['lang'] ?? WP_SKOS_Settings::get_default_language() );

		$terms = get_terms( array(
			'taxonomy'   => $vocab,
			'parent'     => 0,
			'hide_empty' => false,
			'number'     => 500,
		) );

		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		// Respect hidden terms, then resolve hasChildren for all visible roots in one
		// batched query (avoids an N+1 of one children lookup per root).
		$visible = array();
		foreach ( $terms as $term ) {
			if ( function_exists( 'htax_is_term_hidden' ) && htax_is_term_hidden( $term->term_id ) && ! current_user_can( 'read_private_posts' ) ) {
				continue;
			}
			$visible[] = $term;
		}

		$child_map = self::visible_children_map( $vocab, wp_list_pluck( $visible, 'term_id' ) );

		$top = array();
		foreach ( $visible as $term ) {
			$top[] = array(
				'uri'         => WP_SKOS_Builder::concept_uri( $vocab, $term->slug ),
				'prefLabel'   => self::pref_label_for_lang( $term, $lang ),
				'hasChildren' => $child_map[ $term->term_id ] ?? false,
			);
		}

		self::json_response( array(
			'@context'    => self::skosmos_context(),
			'uri'         => self::api_base( $vocab ),
			'scheme'      => WP_SKOS_Builder::scheme_uri( $vocab ),
			'topconcepts' => $top,
		) );
	}

	/**
	 * GET /skosmos/rest/v1/{vocab}/lookup?label=X&lang=en
	 *
	 * Finds concepts by exact or partial label match.
	 *
	 * @since 1.0.0
	 */
	private static function lookup( string $vocab ): void {
		$label = sanitize_text_field( $_GET['label'] ?? '' );
		$lang  = sanitize_key( $_GET['lang'] ?? WP_SKOS_Settings::get_default_language() );

		if ( $label === '' ) {
			self::error( __( 'Missing label parameter', 'wp-skos' ), 400 );
		}

		$terms = get_terms( array(
			'taxonomy'   => $vocab,
			'name__like' => $label,
			'hide_empty' => false,
			'number'     => 50,
		) );

		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		$results = array();
		foreach ( $terms as $term ) {
			if ( function_exists( 'htax_is_term_hidden' ) && htax_is_term_hidden( $term->term_id ) && ! current_user_can( 'read_private_posts' ) ) {
				continue;
			}
			$results[] = array(
				'uri'       => WP_SKOS_Builder::concept_uri( $vocab, $term->slug ),
				'type'      => array( 'skos:Concept' ),
				'prefLabel' => self::pref_label_for_lang( $term, $lang ),
				'lang'      => $lang,
			);
		}

		self::json_response( array(
			'@context' => self::skosmos_context(),
			'result'   => $results,
		) );
	}

	/**
	 * GET /skosmos/rest/v1/{vocab}/data?uri=X
	 *
	 * Returns full JSON-LD for a concept URI or scheme URI within this vocabulary.
	 *
	 * @since 1.0.0
	 */
	private static function data( string $vocab ): void {
		$uri = esc_url_raw( $_GET['uri'] ?? '' );
		if ( $uri === '' ) {
			self::error( __( 'Missing uri parameter', 'wp-skos' ), 400 );
		}

		$authed     = current_user_can( 'read_private_posts' );
		$scheme_uri = WP_SKOS_Builder::scheme_uri( $vocab );

		// ConceptScheme URI.
		if ( rtrim( $uri, '/' ) === rtrim( $scheme_uri, '/' ) ) {
			$data = WP_SKOS_Builder::build_scheme( $vocab );
			if ( null === $data ) {
				self::error( __( 'Scheme not found', 'wp-skos' ), 404 );
			}
			self::jsonld_response( array(
				'@context' => WP_SKOS_Builder::jsonld_context(),
				'@graph'   => array( $data ),
			) );
		}

		// Concept URI: extract taxonomy and slug from the taxonomy-qualified pattern.
		$home     = rtrim( home_url(), '/' );
		$taxonomy = '';
		$slug     = '';
		if ( preg_match( '~' . preg_quote( $home . '/wp-skos/concept/', '~' ) . '([^/?#]+)/([^/?#]+)~', $uri, $m ) ) {
			$taxonomy = sanitize_key( rawurldecode( $m[1] ) );
			$slug     = rawurldecode( $m[2] );
		}

		if ( $slug === '' || $taxonomy === '' ) {
			self::error( __( 'URI not recognized', 'wp-skos' ), 400 );
		}

		// The URI is authoritative across taxonomies, but only enabled taxonomies
		// are ever exposed — never serve a concept from a taxonomy the admin did
		// not enable (mirrors the guard in the concept-URI and document-search resolvers).
		if ( ! WP_SKOS_Settings::is_taxonomy_enabled( $taxonomy ) ) {
			self::error( __( 'URI not recognized', 'wp-skos' ), 404 );
		}

		// Resolve the term within the taxonomy embedded in the URI, not solely
		// the requested vocab — the URI is the authoritative identifier.
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! ( $term instanceof WP_Term ) ) {
			self::error( __( 'Concept not found', 'wp-skos' ), 404 );
		}

		$cache_key = WP_SKOS_Builder::cache_key_concept( $term->term_id, $authed );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			self::jsonld_response( WP_SKOS_Builder::inject_modified( $cached, $term ) );
		}

		$concept_data = WP_SKOS_Builder::build_concept( $term, $authed );
		if ( null === $concept_data ) {
			self::error( __( 'Concept not found or access denied', 'wp-skos' ), 404 );
		}

		$response = array(
			'@context' => WP_SKOS_Builder::jsonld_context(),
			'@graph'   => array( $concept_data ),
		);

		set_transient( $cache_key, $response, 0 );
		self::jsonld_response( WP_SKOS_Builder::inject_modified( $response, $term ) );
	}

	/**
	 * GET /skosmos/rest/v1/search?query=X&vocab={v}&lang=en&maxhits=N
	 *
	 * Full-text label search across one or all enabled vocabularies.
	 *
	 * @since 1.0.0
	 */
	private static function search(): void {
		$query   = sanitize_text_field( $_GET['query'] ?? '' );
		$vocab   = sanitize_key( $_GET['vocab'] ?? '' );
		$lang    = sanitize_key( $_GET['lang'] ?? WP_SKOS_Settings::get_default_language() );
		$maxhits = max( 1, min( 200, (int) ( $_GET['maxhits'] ?? 50 ) ) );

		if ( $query === '' ) {
			self::error( __( 'Missing query parameter', 'wp-skos' ), 400 );
		}

		// Strip trailing wildcard — WP doesn't need it.
		$search = rtrim( $query, '*' );

		// An unknown/disabled vocab is an error here, matching dispatch()'s handling
		// of a bad vocab in the path — it must not silently fall back to searching
		// every enabled taxonomy instead of the one the caller explicitly asked for.
		if ( $vocab !== '' && ! WP_SKOS_Settings::is_taxonomy_enabled( $vocab ) ) {
			self::error( __( 'Unknown vocabulary', 'wp-skos' ), 404 );
		}

		$taxonomies = $vocab !== ''
			? array( $vocab )
			: WP_SKOS_Settings::get_enabled_taxonomies();

		$results = array();

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms( array(
				'taxonomy'   => $taxonomy,
				'name__like' => $search,
				'hide_empty' => false,
				'number'     => $maxhits,
			) );

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( function_exists( 'htax_is_term_hidden' ) && htax_is_term_hidden( $term->term_id ) && ! current_user_can( 'read_private_posts' ) ) {
					continue;
				}
				$results[] = array(
					'uri'       => WP_SKOS_Builder::concept_uri( $taxonomy, $term->slug ),
					'type'      => array( 'skos:Concept' ),
					'prefLabel' => self::pref_label_for_lang( $term, $lang ),
					'lang'      => $lang,
					'vocab'     => $taxonomy,
					'notation'  => $term->slug,
				);
			}

			if ( count( $results ) >= $maxhits ) {
				break;
			}
		}

		self::json_response( array(
			'@context' => self::skosmos_context(),
			'results'  => array_slice( $results, 0, $maxhits ),
		) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * @since 1.0.0
	 */
	private static function api_base( string $vocab ): string {
		return home_url( '/skosmos/rest/v1/' . $vocab . '/' );
	}

	/**
	 * @since 1.0.0
	 */
	private static function skosmos_context(): array {
		return array(
			'skos'      => 'http://www.w3.org/2004/02/skos/core#',
			'uri'       => '@id',
			'type'      => '@type',
			'lang'      => '@language',
			'prefLabel' => 'skos:prefLabel',
			'title'     => 'http://purl.org/dc/terms/title',
		);
	}

	/**
	 * Whether each of many parents has at least one visible child, in one query.
	 *
	 * Returns [ parent_id => bool ]; a child counts only if it is visible to the caller.
	 * Fetches all children in a single query instead of one per parent (avoids an
	 * N+1 in top_concepts()).
	 *
	 * @since 1.0.0
	 * @param string $vocab      Taxonomy slug.
	 * @param int[]  $parent_ids Parent term IDs.
	 * @return array<int,bool>
	 */
	private static function visible_children_map( string $vocab, array $parent_ids ): array {
		$parent_ids = array_values( array_unique( array_filter( array_map( 'intval', $parent_ids ) ) ) );
		$map        = array();
		if ( empty( $parent_ids ) ) {
			return $map;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_id, parent FROM {$wpdb->term_taxonomy}
				 WHERE taxonomy = %s AND parent IN ({$placeholders})",
				array_merge( array( $vocab ), $parent_ids )
			)
		);

		$children = array();
		foreach ( $rows as $row ) {
			$children[ (int) $row->parent ][] = (int) $row->term_id;
		}

		$apply_hidden = function_exists( 'htax_is_term_hidden' ) && ! current_user_can( 'read_private_posts' );

		foreach ( $parent_ids as $pid ) {
			$kids = $children[ $pid ] ?? array();
			if ( empty( $kids ) ) {
				$map[ $pid ] = false;
				continue;
			}
			if ( ! $apply_hidden ) {
				$map[ $pid ] = true;
				continue;
			}
			$map[ $pid ] = false;
			foreach ( $kids as $cid ) {
				if ( ! htax_is_term_hidden( $cid ) ) {
					$map[ $pid ] = true;
					break;
				}
			}
		}

		return $map;
	}

	/**
	 * Return the best prefLabel string for a given language, falling back to the default language.
	 *
	 * @since 1.0.0
	 */
	private static function pref_label_for_lang( WP_Term $term, string $lang ): string {
		$labels = WP_SKOS_Builder::get_pref_labels( $term );
		return $labels[ $lang ] ?? $labels[ WP_SKOS_Settings::get_default_language() ] ?? $term->name;
	}

	/**
	 * @since 1.0.0
	 */
	private static function json_response( array $data, int $status = 200 ): void {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * @since 1.0.0
	 */
	private static function jsonld_response( array $data, int $status = 200 ): void {
		status_header( $status );
		header( 'Content-Type: application/ld+json; charset=utf-8' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * @since 1.0.0
	 */
	private static function error( string $message, int $status = 400 ): void {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( array( 'error' => $message ) );
		exit;
	}
}
