<?php
/**
 * Builds SKOS data structures from WordPress taxonomy and term data.
 *
 * HT integration is entirely guarded by function_exists() checks — this class
 * degrades gracefully when HyperTaxonomy is absent or deactivated.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_SKOS_Builder {

	// -------------------------------------------------------------------------
	// URIs
	// -------------------------------------------------------------------------

	/**
	 * @since 1.0.0
	 */
	public static function concept_uri( string $taxonomy, string $term_slug ): string {
		return home_url( '/wp-skos/concept/' . rawurlencode( $taxonomy ) . '/' . rawurlencode( $term_slug ) );
	}

	/**
	 * @since 1.0.0
	 */
	public static function scheme_uri( string $taxonomy ): string {
		return home_url( '/wp-skos/scheme/' . $taxonomy );
	}

	/**
	 * URI for a term-group collection.
	 *
	 * Groups live only in the 'group' taxonomy, so the slug alone identifies the
	 * collection — no taxonomy segment is required.
	 *
	 * @since 1.0.0
	 */
	public static function collection_uri( string $group_slug ): string {
		return home_url( '/wp-skos/collection/' . rawurlencode( $group_slug ) );
	}

	/**
	 * URI for a vocabulary property declaration.
	 * Built-in types (includes, container, synonym) use the stable cross-site namespace.
	 * Local custom types use home_url(). TC-delegated types use the source site's URL.
	 *
	 * @since 1.0.0
	 */
	public static function vocab_property_uri( string $slug, string $source_site = '' ): string {
		if ( in_array( $slug, array( 'includes', 'container', 'synonym' ), true ) ) {
			return WPSKOS_VOCAB_NS . $slug;
		}
		$base = $source_site !== '' ? rtrim( $source_site, '/' ) : rtrim( home_url(), '/' );
		return $base . '/wp-skos/vocab/' . $slug;
	}

	// -------------------------------------------------------------------------
	// Cache
	// -------------------------------------------------------------------------

	/**
	 * @since 1.0.0
	 */
	public static function cache_key_concept( int $term_id, bool $authed ): string {
		return 'wpskos_cpt_' . $term_id . '_' . ( $authed ? '1' : '0' );
	}

	/**
	 * @since 1.0.0
	 */
	public static function cache_key_scheme( string $taxonomy ): string {
		return 'wpskos_sch_' . md5( $taxonomy );
	}

	/**
	 * @since 1.0.0
	 */
	public static function invalidate_term( int $term_id, string $taxonomy ): void {
		delete_transient( self::cache_key_concept( $term_id, false ) );
		delete_transient( self::cache_key_concept( $term_id, true ) );
		self::invalidate_scheme( $taxonomy );
	}

	/**
	 * @since 1.0.0
	 */
	public static function invalidate_scheme( string $taxonomy ): void {
		delete_transient( self::cache_key_scheme( $taxonomy ) );
	}

	// -------------------------------------------------------------------------
	// Labels
	// -------------------------------------------------------------------------

	/**
	 * Build prefLabel map keyed by BCP 47 language tag.
	 *
	 * Returns at minimum one entry using the site's default language.
	 * Adds translated labels from Polylang, WPML, or HT Language Tools
	 * when available.
	 *
	 * @since 1.0.0
	 * @return array<string, string>  e.g. ['en' => 'React', 'fr' => 'Réagir']
	 */
	public static function get_pref_labels( WP_Term $term ): array {
		$labels   = array();
		$own_lang = self::term_language( $term );

		// Own term's label in its detected language.
		$labels[ $own_lang ] = $term->name;

		// Polylang.
		if ( function_exists( 'pll_get_term_translations' ) ) {
			$translations = pll_get_term_translations( $term->term_id );
			foreach ( $translations as $lang => $trans_id ) {
				$lang = sanitize_key( $lang );
				if ( (int) $trans_id === $term->term_id || $lang === '' ) {
					continue;
				}
				$t = get_term( (int) $trans_id );
				if ( $t instanceof WP_Term ) {
					$labels[ $lang ] = $t->name;
				}
			}
		}

		// WPML.
		if ( defined( 'ICL_SYSLANGS' ) && function_exists( 'apply_filters' ) ) {
			$translations = apply_filters( 'wpml_get_element_translations', null, $term->term_taxonomy_id, 'tax_' . $term->taxonomy );
			if ( is_array( $translations ) ) {
				foreach ( $translations as $lang => $trans ) {
					$lang = sanitize_key( (string) $lang );
					if ( $lang === '' || ! isset( $trans->element_id ) ) {
						continue;
					}
					$t = get_term( (int) $trans->element_id );
					if ( $t instanceof WP_Term && $t->term_id !== $term->term_id ) {
						$labels[ $lang ] = $t->name;
					}
				}
			}
		}

		// HT Language Tools: 'translation' relationship type.
		if ( function_exists( 'htax_get_related_terms' ) && function_exists( 'htax_get_reverse_related_terms' ) ) {
			$reg_types = function_exists( 'htax_get_registered_relationship_types' )
				? htax_get_registered_relationship_types( true )
				: array();

			if ( isset( $reg_types['translation'] ) ) {
				$translations = htax_get_related_terms( $term->term_id, 'translation' )
					+ htax_get_reverse_related_terms( $term->term_id, 'translation' );

				foreach ( $translations as $trans_id => $t ) {
					$lang = (string) get_term_meta( (int) $trans_id, '_lang', true );
					if ( $lang !== '' && $lang !== $own_lang ) {
						$labels[ $lang ] = $t->name;
					}
				}
			}
		}

		return $labels;
	}

	/**
	 * Detect the BCP 47 language tag for a term.
	 *
	 * @since 1.0.0
	 */
	private static function term_language( WP_Term $term ): string {
		if ( function_exists( 'pll_get_term_language' ) ) {
			$lang = pll_get_term_language( $term->term_id );
			if ( $lang ) {
				return sanitize_key( $lang );
			}
		}
		$meta_lang = (string) get_term_meta( $term->term_id, '_lang', true );
		if ( $meta_lang !== '' ) {
			return sanitize_key( $meta_lang );
		}
		return WP_SKOS_Settings::get_default_language();
	}

	// -------------------------------------------------------------------------
	// SKOS relations
	// -------------------------------------------------------------------------

	/**
	 * All terms with any semantic relationship to $term, keyed by term_id.
	 * Excludes parent/child (WP hierarchy) — those are skos:broader/narrower.
	 *
	 * @since 1.0.0
	 * @return WP_Term[]
	 */
	public static function get_related_terms( WP_Term $term ): array {
		if ( ! function_exists( 'htax_get_included_terms' ) ) {
			return array();
		}

		$related = array();

		// Built-in: inclusions and containers (cross-branch only; HT excludes ancestors/descendants).
		$included   = htax_get_included_terms( $term );
		$containers = htax_get_container_terms( $term );
		foreach ( $included as $t_id => $t ) {
			$related[ $t_id ] = $t;
		}
		foreach ( $containers as $t_id => $t ) {
			$related[ $t_id ] = $t;
		}

		// All registered custom types (both directions).
		if ( function_exists( 'htax_get_registered_relationship_types' ) && function_exists( 'htax_get_related_terms' ) ) {
			foreach ( htax_get_registered_relationship_types( true ) as $slug => $type ) {
				if ( $type['is_core'] ?? false ) {
					continue;
				}
				foreach ( htax_get_related_terms( $term->term_id, $slug ) as $t_id => $t ) {
					$related[ $t_id ] = $t;
				}
				if ( function_exists( 'htax_get_reverse_related_terms' ) ) {
					foreach ( htax_get_reverse_related_terms( $term->term_id, $slug ) as $t_id => $t ) {
						$related[ $t_id ] = $t;
					}
				}
			}
		}

		return $related;
	}

	/**
	 * Relations organized by typed sub-property, for emitting wpskos:* triples.
	 *
	 * Returns:
	 *   [
	 *     'includes'      => [ term_id => WP_Term ],
	 *     'container'     => [ term_id => WP_Term ],
	 *     'synonym'       => [ term_id => WP_Term ],
	 *     '{custom-slug}' => [ term_id => WP_Term ],  // forward direction
	 *     '{custom-slug}_reverse' => [ ... ],          // reverse direction
	 *   ]
	 *
	 * @since 1.0.0
	 */
	public static function get_typed_relations( WP_Term $term ): array {
		if ( ! function_exists( 'htax_get_included_terms' ) ) {
			return array();
		}

		$typed = array();

		// Synonyms: mutual inclusions. HT exposes a dedicated helper if available.
		$synonyms = function_exists( 'htax_get_synonym_terms' )
			? htax_get_synonym_terms( $term )
			: array();
		if ( ! empty( $synonyms ) ) {
			$typed['synonym'] = $synonyms;
		}

		// Inclusions (A includes B, excluding mutual/synonym pairs).
		$synonym_ids = array_keys( $synonyms );
		$included    = htax_get_included_terms( $term );
		$one_way     = array_filter( $included, fn( $t_id ) => ! in_array( $t_id, $synonym_ids, true ), ARRAY_FILTER_USE_KEY );
		if ( ! empty( $one_way ) ) {
			$typed['includes'] = $one_way;
		}

		// Containers (reverse direction of one-way inclusions).
		$containers = htax_get_container_terms( $term );
		// Exclude containers that are also synonyms.
		$one_way_containers = array_filter( $containers, fn( $t_id ) => ! in_array( $t_id, $synonym_ids, true ), ARRAY_FILTER_USE_KEY );
		if ( ! empty( $one_way_containers ) ) {
			$typed['container'] = $one_way_containers;
		}

		// Custom types.
		if ( function_exists( 'htax_get_registered_relationship_types' ) && function_exists( 'htax_get_related_terms' ) ) {
			foreach ( htax_get_registered_relationship_types( true ) as $slug => $type ) {
				if ( $type['is_core'] ?? false ) {
					continue;
				}
				$forward = htax_get_related_terms( $term->term_id, $slug );
				if ( ! empty( $forward ) ) {
					$typed[ $slug ] = $forward;
				}
				if ( function_exists( 'htax_get_reverse_related_terms' ) ) {
					$reverse = htax_get_reverse_related_terms( $term->term_id, $slug );
					if ( ! empty( $reverse ) ) {
						$typed[ $slug . '_reverse' ] = $reverse;
					}
				}
			}
		}

		return $typed;
	}

	/**
	 * External KB URIs for skos:closeMatch.
	 *
	 * Returns [ ['url' => '...', 'label' => '...', 'is_wikidata' => bool], ... ]
	 *
	 * @since 1.0.0
	 */
	public static function get_close_matches( int $term_id ): array {
		if ( ! function_exists( 'htax_get_term_uris' ) ) {
			return array();
		}

		$uris    = htax_get_term_uris( $term_id );
		$matches = array();

		foreach ( $uris as $uri_data ) {
			// htax_term_uris implementations may return plain URL strings or arrays.
			if ( is_string( $uri_data ) ) {
				$url   = $uri_data;
				$label = '';
			} else {
				$url   = (string) ( $uri_data['url'] ?? '' );
				$label = (string) ( $uri_data['label'] ?? '' );
			}

			if ( $url === '' ) {
				continue;
			}

			// Wikidata: document URL uses /wiki/Q…; entity URI for closeMatch is /entity/Q….
			$is_wikidata = (bool) preg_match( '#^https?://www\.wikidata\.org/wiki/(Q\d+)#', $url, $m );
			$close_match_uri = $is_wikidata
				? 'http://www.wikidata.org/entity/' . $m[1]
				: $url;

			$matches[] = array(
				'close_match_uri' => $close_match_uri,
				'see_also_url'    => $url,
				'label'           => $label,
				'is_wikidata'     => $is_wikidata,
			);
		}

		return $matches;
	}

	/**
	 * Physical locations for a concept, formatted as schema:Place JSON-LD objects.
	 *
	 * Delegates to HT's htax_get_term_locations(), which returns raw location rows
	 * (associative arrays). Each non-empty row is mapped to a schema:Place object,
	 * omitting empty fields and skipping rows with no usable data.
	 *
	 * @since 1.0.0
	 */
	public static function get_locations( WP_Term $term ): array {
		if ( ! function_exists( 'htax_get_term_locations' ) ) {
			return array();
		}

		// Respect HT's per-taxonomy "share locations" feature flag: if HT is active
		// and the term's taxonomy does not have share_locations enabled, omit
		// schema:location entirely. When HT does not expose this check, fall back to
		// the existing behavior (htax_get_term_locations() already returns [] when HT
		// is inactive).
		if ( function_exists( 'htax_taxonomy_has_feature' )
			&& ! htax_taxonomy_has_feature( $term->taxonomy, 'share_locations' ) ) {
			return array();
		}

		$rows   = htax_get_term_locations( $term->term_id );
		$places = array();

		foreach ( (array) $rows as $row ) {
			$place = array( '@type' => 'schema:Place' );

			$label = isset( $row['label'] ) ? trim( (string) $row['label'] ) : '';
			if ( '' !== $label ) {
				$place['schema:name'] = $label;
			}

			$address      = array( '@type' => 'schema:PostalAddress' );
			$address_keys = array(
				'street_address' => 'schema:streetAddress',
				'locality'       => 'schema:addressLocality',
				'region'         => 'schema:addressRegion',
				'postal_code'    => 'schema:postalCode',
				'country'        => 'schema:addressCountry',
			);
			foreach ( $address_keys as $src => $dest ) {
				$value = isset( $row[ $src ] ) ? trim( (string) $row[ $src ] ) : '';
				if ( '' !== $value ) {
					$address[ $dest ] = $value;
				}
			}
			if ( count( $address ) > 1 ) {
				$place['schema:address'] = $address;
			}

			$lat = isset( $row['lat'] ) ? $row['lat'] : null;
			$lng = isset( $row['lng'] ) ? $row['lng'] : null;
			if ( null !== $lat && null !== $lng ) {
				$place['schema:geo'] = array(
					'@type'           => 'schema:GeoCoordinates',
					'schema:latitude'  => (float) $lat,
					'schema:longitude' => (float) $lng,
				);
			}

			// Skip rows that carried no usable data beyond the @type.
			if ( count( $place ) > 1 ) {
				$places[] = $place;
			}
		}

		return $places;
	}

	// -------------------------------------------------------------------------
	// Data builders
	// -------------------------------------------------------------------------

	/**
	 * Whether a related/hierarchy term must be omitted from output because it is
	 * an HT hidden term and the caller is not authorized.
	 *
	 * @since 1.0.0
	 * @param int  $term_id Term ID.
	 * @param bool $authed  Whether the caller is authorized to see hidden terms.
	 * @return bool
	 */
	private static function is_hidden_for( int $term_id, bool $authed ): bool {
		return ! $authed && function_exists( 'htax_is_term_hidden' ) && htax_is_term_hidden( $term_id );
	}

	/**
	 * Build the full SKOS data array for a concept.
	 *
	 * Returns null when the term is not found or the taxonomy is not enabled.
	 * Returns null (and sets a 404 condition) for hidden terms when the caller
	 * lacks the required capability.
	 *
	 * @since 1.0.0
	 */
	public static function build_concept( WP_Term $term, bool $authed = false ): ?array {
		// Hidden term check (HT).
		if ( function_exists( 'htax_is_term_hidden' ) && htax_is_term_hidden( $term->term_id ) && ! $authed ) {
			return null;
		}

		$taxonomy    = $term->taxonomy;
		$concept_uri = self::concept_uri( $taxonomy, $term->slug );
		$scheme_uri  = self::scheme_uri( $taxonomy );
		$lang        = WP_SKOS_Settings::get_default_language();
		$pref_labels = self::get_pref_labels( $term );

		$data = array(
			'@type'        => 'skos:Concept',
			'@id'          => $concept_uri,
			'skos:inScheme' => array( '@id' => $scheme_uri ),
			'skos:prefLabel' => self::labels_to_jsonld( $pref_labels ),
			'skos:notation' => $term->slug,
		);

		if ( ! empty( $term->description ) ) {
			// Plain-text descriptions become skos:definition; HTML gets scopeNote.
			$is_plain = ( $term->description === wp_strip_all_tags( $term->description ) );
			$prop     = $is_plain ? 'skos:definition' : 'skos:scopeNote';
			$data[ $prop ] = array( '@value' => wp_strip_all_tags( $term->description ), '@language' => $lang );
		}

		// Hierarchy. Hidden terms (HT) are omitted for unauthorized clients so the
		// hierarchy does not leak the existence of hidden concepts.
		if ( $term->parent ) {
			$parent = get_term( $term->parent, $taxonomy );
			if ( $parent instanceof WP_Term && ! self::is_hidden_for( $parent->term_id, $authed ) ) {
				$data['skos:broader'] = array( '@id' => self::concept_uri( $taxonomy, $parent->slug ) );
			}
		}

		$children = get_terms( array(
			'taxonomy'   => $taxonomy,
			'parent'     => $term->term_id,
			'hide_empty' => false,
		) );
		if ( ! empty( $children ) && ! is_wp_error( $children ) ) {
			$narrower = array();
			foreach ( $children as $child ) {
				if ( self::is_hidden_for( $child->term_id, $authed ) ) {
					continue;
				}
				$narrower[] = array( '@id' => self::concept_uri( $taxonomy, $child->slug ) );
			}
			if ( ! empty( $narrower ) ) {
				$data['skos:narrower'] = $narrower;
			}
		}

		// Transitive hierarchy (all ancestors / all descendants). Additive to the
		// direct skos:broader / skos:narrower above.
		$ancestor_ids = get_ancestors( $term->term_id, $taxonomy, 'taxonomy' );
		if ( ! empty( $ancestor_ids ) ) {
			// Prime the term cache once so the per-ancestor get_term() calls below
			// hit cache instead of issuing a query each (same data, fewer queries).
			_prime_term_caches( array_map( 'intval', $ancestor_ids ) );
			$broader_transitive = array();
			foreach ( $ancestor_ids as $ancestor_id ) {
				if ( self::is_hidden_for( (int) $ancestor_id, $authed ) ) {
					continue;
				}
				$ancestor = get_term( (int) $ancestor_id, $taxonomy );
				if ( $ancestor instanceof WP_Term ) {
					$broader_transitive[] = array( '@id' => self::concept_uri( $taxonomy, $ancestor->slug ) );
				}
			}
			if ( ! empty( $broader_transitive ) ) {
				$data['skos:broaderTransitive'] = $broader_transitive;
			}
		}

		$descendant_ids = get_term_children( $term->term_id, $taxonomy );
		if ( ! empty( $descendant_ids ) && ! is_wp_error( $descendant_ids ) ) {
			// Prime the term cache once (see note above) before the per-descendant loop.
			_prime_term_caches( array_map( 'intval', $descendant_ids ) );
			$narrower_transitive = array();
			foreach ( $descendant_ids as $descendant_id ) {
				if ( self::is_hidden_for( (int) $descendant_id, $authed ) ) {
					continue;
				}
				$descendant = get_term( (int) $descendant_id, $taxonomy );
				if ( $descendant instanceof WP_Term ) {
					$narrower_transitive[] = array( '@id' => self::concept_uri( $taxonomy, $descendant->slug ) );
				}
			}
			if ( ! empty( $narrower_transitive ) ) {
				$data['skos:narrowerTransitive'] = $narrower_transitive;
			}
		}

		// skos:related — union of all HT relationship paths.
		$related = self::get_related_terms( $term );
		if ( ! empty( $related ) ) {
			$data['skos:related'] = array_values( array_map(
				fn( $t ) => array( '@id' => self::concept_uri( $t->taxonomy, $t->slug ) ),
				$related
			) );
		}

		// Typed sub-properties (wpskos:includes, wpskos:container, wpskos:synonym, custom).
		$typed = self::get_typed_relations( $term );
		foreach ( $typed as $slug => $terms ) {
			$is_reverse = '_reverse' === substr( $slug, -8 );
			$base_slug  = $is_reverse ? substr( $slug, 0, -8 ) : $slug;
			$prop_uri   = self::vocab_property_uri( $base_slug, self::type_source_site( $base_slug ) );

			if ( $is_reverse ) {
				// Emit reverse only if no symmetric declaration (custom non-symmetric).
				$prop_uri = self::vocab_property_uri( $base_slug . '_reverse', self::type_source_site( $base_slug ) );
			}

			$data[ $prop_uri ] = array_values( array_map(
				fn( $t ) => array( '@id' => self::concept_uri( $t->taxonomy, $t->slug ) ),
				$terms
			) );
		}

		// Redirect (owl:deprecated + dct:isReplacedBy).
		if ( function_exists( 'htax_get_redirect_target' ) ) {
			$target = htax_get_redirect_target( $term->term_id );
			if ( $target instanceof WP_Term ) {
				$data['owl:deprecated'] = true;
				$data['dct:isReplacedBy'] = array( '@id' => self::concept_uri( $target->taxonomy, $target->slug ) );
			}
		}

		// skos:closeMatch to external KB URIs.
		$close_matches = self::get_close_matches( $term->term_id );
		if ( ! empty( $close_matches ) ) {
			$data['skos:closeMatch'] = array();
			$data['rdfs:seeAlso']    = array();
			foreach ( $close_matches as $cm ) {
				$data['skos:closeMatch'][] = array( '@id' => $cm['close_match_uri'] );
				if ( $cm['is_wikidata'] || $cm['see_also_url'] !== $cm['close_match_uri'] ) {
					$data['rdfs:seeAlso'][] = array(
						'@id'        => $cm['see_also_url'],
						'@type'      => 'foaf:Document',
						'rdfs:label' => $cm['label'],
					);
				}
			}
			if ( empty( $data['rdfs:seeAlso'] ) ) {
				unset( $data['rdfs:seeAlso'] );
			}
		}

		// Physical locations via HT's location API (ATCF or any other provider).
		$locations = self::get_locations( $term );
		if ( ! empty( $locations ) ) {
			$data['schema:location'] = $locations;
		}

		// Term archive URL.
		$archive_url = get_term_link( $term );
		if ( ! is_wp_error( $archive_url ) ) {
			$data['rdfs:seeAlso'][] = array( '@id' => $archive_url, '@type' => 'foaf:Document' );
		}

		// Discovery link to document search endpoint.
		$data['_links'] = array(
			'posts' => add_query_arg(
				array( 'concept' => $concept_uri ),
				rest_url( 'wp-skos/v1/posts' )
			),
		);

		// dct:modified is injected live at output by inject_modified() — it reflects the
		// most recently modified post in the concept's archive, which changes when posts
		// are edited, so it must not be baked into the cached concept document.

		return $data;
	}

	/**
	 * Most recent post-modified time across the concept's archive, as ISO 8601 (GMT),
	 * or null when the archive has no published posts.
	 *
	 * "Archive" = posts assigned to the term, plus its HyperTaxonomy expansion
	 * (descendants + inclusions) when HT is active — the same set that makes up the
	 * term's archive page. Computed live (one indexed MAX query); see inject_modified().
	 *
	 * @since 1.0.0
	 */
	public static function get_modified_date( WP_Term $term ): ?string {
		global $wpdb;

		$term_ids = array();
		if ( function_exists( 'htax_get_expanded_term_ids' ) ) {
			$term_ids = htax_get_expanded_term_ids( $term->term_id );
		}
		if ( empty( $term_ids ) ) {
			$term_ids = array( $term->term_id );
			if ( is_taxonomy_hierarchical( $term->taxonomy ) ) {
				$children = get_term_children( $term->term_id, $term->taxonomy );
				if ( ! is_wp_error( $children ) ) {
					$term_ids = array_merge( $term_ids, $children );
				}
			}
		}
		$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );
		if ( empty( $term_ids ) ) {
			return null;
		}

		$placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
		$modified = $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX( p.post_modified_gmt )
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 WHERE tt.term_id IN ($placeholders)
			   AND p.post_status = 'publish'",
			$term_ids
		) );

		if ( empty( $modified ) || '0000-00-00 00:00:00' === $modified ) {
			return null;
		}
		$ts = strtotime( $modified . ' UTC' );
		return false !== $ts ? gmdate( 'c', $ts ) : null;
	}

	/**
	 * Inject a live dct:modified into a (possibly cached) concept response.
	 *
	 * The concept document is cached with no expiry and invalidated only on term-level
	 * events, but dct:modified depends on post edits — so it is added here, after cache
	 * retrieval, on every response. Operates on the first node of the response @graph.
	 *
	 * @since 1.0.0
	 */
	public static function inject_modified( array $response, WP_Term $term ): array {
		$modified = self::get_modified_date( $term );
		if ( null !== $modified && isset( $response['@graph'][0] ) && is_array( $response['@graph'][0] ) ) {
			$response['@graph'][0]['dct:modified'] = $modified;
		}
		return $response;
	}

	/**
	 * Build the SKOS ConceptScheme for a taxonomy.
	 *
	 * @since 1.0.0
	 */
	public static function build_scheme( string $taxonomy ): ?array {
		$tax_obj = get_taxonomy( $taxonomy );
		if ( ! $tax_obj ) {
			return null;
		}

		$scheme_uri = self::scheme_uri( $taxonomy );

		$top_terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'parent'     => 0,
			'hide_empty' => false,
		) );
		if ( is_wp_error( $top_terms ) ) {
			$top_terms = array();
		}

		$has_top_concepts = array_map(
			fn( $t ) => array( '@id' => self::concept_uri( $taxonomy, $t->slug ) ),
			$top_terms
		);

		$lang = WP_SKOS_Settings::get_default_language();

		return array(
			'@type'             => 'skos:ConceptScheme',
			'@id'               => $scheme_uri,
			'skos:prefLabel'    => array( '@value' => $tax_obj->label, '@language' => $lang ),
			'skos:hasTopConcept' => $has_top_concepts,
		);
	}

	/**
	 * Build the SKOS Collection for a term group.
	 *
	 * Term groups are terms in the 'group' taxonomy with native WP parent/child
	 * hierarchy. A Collection's members combine:
	 *   - direct child groups (each a nested skos:Collection), and
	 *   - direct member concepts (terms with a 'member_of' relationship to this group).
	 *
	 * The group hierarchy is NOT expanded for members: child groups are listed as
	 * nested Collections, so flattening would duplicate their members here.
	 *
	 * Returns null when HT is absent, or when the group is hidden and the caller
	 * is not authenticated.
	 *
	 * @since 1.0.0
	 */
	public static function build_collection( WP_Term $group, bool $authed = false ): ?array {
		if ( ! function_exists( 'htax_get_all_term_groups' ) ) {
			return null;
		}

		if ( function_exists( 'htax_is_term_hidden' ) && htax_is_term_hidden( $group->term_id ) && ! $authed ) {
			return null;
		}

		$pref_labels = self::get_pref_labels( $group );

		$data = array(
			'@type'          => 'skos:Collection',
			'@id'            => self::collection_uri( $group->slug ),
			'skos:prefLabel' => self::labels_to_jsonld( $pref_labels ),
		);

		$members = array();

		// (a) Direct child groups → nested Collections.
		$child_groups = get_terms( array(
			'taxonomy'   => 'group',
			'parent'     => $group->term_id,
			'hide_empty' => false,
		) );
		if ( ! is_wp_error( $child_groups ) ) {
			foreach ( $child_groups as $child ) {
				if ( function_exists( 'htax_is_term_hidden' ) && htax_is_term_hidden( $child->term_id ) && ! $authed ) {
					continue;
				}
				$members[] = array( '@id' => self::collection_uri( $child->slug ) );
			}
		}

		// (b) Direct member concepts (member_of → this group). Members can live in
		// any taxonomy, so query without a source-taxonomy filter and resolve each
		// member's taxonomy from the loaded term.
		global $wpdb;
		$table = $wpdb->prefix . 'htax_term_relationships';
		$member_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT source_term_id FROM {$table}
			 WHERE target_term_id = %d
			   AND target_taxonomy = 'group'
			   AND relationship_type = 'member_of'",
			$group->term_id
		) );

		foreach ( $member_ids as $member_id ) {
			$member = get_term( (int) $member_id );
			if ( ! ( $member instanceof WP_Term ) ) {
				continue;
			}
			if ( ! WP_SKOS_Settings::is_taxonomy_enabled( $member->taxonomy ) ) {
				continue;
			}
			if ( function_exists( 'htax_is_term_hidden' ) && htax_is_term_hidden( $member->term_id ) && ! $authed ) {
				continue;
			}
			$members[] = array( '@id' => self::concept_uri( $member->taxonomy, $member->slug ) );
		}

		if ( ! empty( $members ) ) {
			$data['skos:member'] = $members;
		}

		return $data;
	}

	/**
	 * Build the OWL property declaration for a vocabulary property.
	 *
	 * @since 1.0.0
	 */
	public static function build_vocab_property( string $slug ): ?array {
		$uri = self::vocab_property_uri( $slug );

		switch ( $slug ) {
			case 'includes':
				return array(
					'@id'                    => $uri,
					'@type'                  => 'owl:ObjectProperty',
					'rdfs:subPropertyOf'     => array( '@id' => 'http://www.w3.org/2004/02/skos/core#semanticRelation' ),
					'owl:inverseOf'          => array( '@id' => self::vocab_property_uri( 'container' ) ),
					'rdfs:label'             => 'includes',
					'rdfs:comment'           => 'HyperTaxonomy inclusion: the subject term\'s archive incorporates posts tagged with the object term.',
				);

			case 'container':
				return array(
					'@id'                    => $uri,
					'@type'                  => 'owl:ObjectProperty',
					'rdfs:subPropertyOf'     => array( '@id' => 'http://www.w3.org/2004/02/skos/core#semanticRelation' ),
					'owl:inverseOf'          => array( '@id' => self::vocab_property_uri( 'includes' ) ),
					'rdfs:label'             => 'container',
					'rdfs:comment'           => 'Inverse of wpskos:includes.',
				);

			case 'synonym':
				return array(
					'@id'                    => $uri,
					'@type'                  => array( 'owl:ObjectProperty', 'owl:SymmetricProperty' ),
					'rdfs:subPropertyOf'     => array( '@id' => 'http://www.w3.org/2004/02/skos/core#related' ),
					'rdfs:label'             => 'synonym',
					'rdfs:comment'           => 'HyperTaxonomy mutual inclusion: both terms incorporate each other\'s posts.',
				);

			default:
				// Custom relationship type.
				if ( ! function_exists( 'htax_get_registered_relationship_types' ) ) {
					return null;
				}
				$types = htax_get_registered_relationship_types( true );

				// Reverse (inverse) property of a non-symmetric custom type:
				// /wp-skos/vocab/{base}_reverse declares the owl:inverseOf the base.
				if ( '_reverse' === substr( $slug, -8 ) ) {
					$base_slug = substr( $slug, 0, -8 );
					if ( ! isset( $types[ $base_slug ] ) ) {
						return null;
					}
					$base_type = $types[ $base_slug ];
					// A symmetric property is its own inverse — it has no distinct reverse property.
					if ( ! empty( $base_type['symmetric'] ) ) {
						return null;
					}
					$base_label = $base_type['label'] ?? $base_slug;

					return array(
						'@id'                => $uri,
						'@type'              => 'owl:ObjectProperty',
						'rdfs:subPropertyOf' => array( '@id' => 'http://www.w3.org/2004/02/skos/core#semanticRelation' ),
						'owl:inverseOf'      => array( '@id' => self::vocab_property_uri( $base_slug, self::type_source_site( $base_slug ) ) ),
						'rdfs:label'         => $base_label . ' (reverse)',
						'rdfs:comment'       => 'Inverse of ' . $base_label . '.',
					);
				}

				if ( ! isset( $types[ $slug ] ) ) {
					return null;
				}
				$type        = $types[ $slug ];
				$is_symmetric = (bool) ( $type['symmetric'] ?? false );
				$parent_prop = $is_symmetric
					? 'http://www.w3.org/2004/02/skos/core#related'
					: 'http://www.w3.org/2004/02/skos/core#semanticRelation';

				$def = array(
					'@id'                => $uri,
					'@type'              => $is_symmetric
						? array( 'owl:ObjectProperty', 'owl:SymmetricProperty' )
						: 'owl:ObjectProperty',
					'rdfs:subPropertyOf' => array( '@id' => $parent_prop ),
					'rdfs:label'         => $type['label'] ?? $slug,
				);

				if ( ! empty( $type['description'] ) ) {
					$def['rdfs:comment'] = $type['description'];
				}

				if ( ! $is_symmetric ) {
					$def['owl:inverseOf'] = array( '@id' => self::vocab_property_uri( $slug . '_reverse' ) );
				}

				return $def;
		}
	}

	// -------------------------------------------------------------------------
	// JSON-LD helpers
	// -------------------------------------------------------------------------

	/**
	 * Standard @context for concept/scheme JSON-LD responses.
	 *
	 * @since 1.0.0
	 */
	public static function jsonld_context(): array {
		return array(
			'skos'   => 'http://www.w3.org/2004/02/skos/core#',
			'owl'    => 'http://www.w3.org/2002/07/owl#',
			'rdfs'   => 'http://www.w3.org/2000/01/rdf-schema#',
			'dct'    => 'http://purl.org/dc/terms/',
			'foaf'   => 'http://xmlns.com/foaf/0.1/',
			'schema' => 'https://schema.org/',
			'wpskos' => WPSKOS_VOCAB_NS,
		);
	}

	/**
	 * Convert a [ lang => label ] map to JSON-LD language-tagged literal array.
	 *
	 * @since 1.0.0
	 */
	public static function labels_to_jsonld( array $labels ): array {
		$out = array();
		foreach ( $labels as $lang => $value ) {
			$out[] = array( '@value' => $value, '@language' => $lang );
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the source_site for a custom type, if it was delegated from a TC peer.
	 *
	 * @since 1.0.0
	 */
	private static function type_source_site( string $slug ): string {
		if ( ! function_exists( 'htax_get_registered_relationship_types' ) ) {
			return '';
		}
		$types = htax_get_registered_relationship_types( true );
		return (string) ( $types[ $slug ]['source_site'] ?? '' );
	}
}

// -------------------------------------------------------------------------
// Cache invalidation hooks
// -------------------------------------------------------------------------

add_action( 'saved_term',   'wpskos_invalidate_term_cache', 10, 3 );
add_action( 'delete_term',  'wpskos_invalidate_term_cache', 10, 3 );

/**
 * @since 1.0.0
 */
function wpskos_invalidate_term_cache( int $term_id, int $tt_id, string $taxonomy ): void {
	if ( ! WP_SKOS_Settings::is_taxonomy_enabled( $taxonomy ) ) {
		return;
	}
	WP_SKOS_Builder::invalidate_term( $term_id, $taxonomy );
}

// HT relationship change invalidation.
add_action( 'htax_includes_changed', 'wpskos_invalidate_relationship_cache', 10, 2 );

/**
 * @since 1.0.0
 */
function wpskos_invalidate_relationship_cache( int $term_id, string $taxonomy ): void {
	WP_SKOS_Builder::invalidate_term( $term_id, $taxonomy );
}

// HT-relayed external URI change invalidation (TC fires tc_term_uris_changed; HT relays as htax_term_uris_changed).
add_action( 'htax_term_uris_changed', 'wpskos_invalidate_term_cache_by_id' );

// HT-relayed location change invalidation (ATCF fires atcf_term_location_changed; HT relays as htax_term_locations_changed).
add_action( 'htax_term_locations_changed', 'wpskos_invalidate_term_cache_by_id' );

/**
 * Invalidate concept transients for a term given only its ID (taxonomy derived automatically).
 * Used by TC and ATCF invalidation hooks which pass term_id without taxonomy.
 *
 * @since 1.0.0
 */
function wpskos_invalidate_term_cache_by_id( int $term_id ): void {
	$term = get_term( $term_id );
	if ( ! ( $term instanceof WP_Term ) ) {
		return;
	}
	if ( ! WP_SKOS_Settings::is_taxonomy_enabled( $term->taxonomy ) ) {
		return;
	}
	WP_SKOS_Builder::invalidate_term( $term_id, $term->taxonomy );
}

// dct:modified is computed live from the archive's most recent post-modified date —
// see WP_SKOS_Builder::get_modified_date() / inject_modified(). No term meta is stored.
