<?php
/**
 * Serves a whole vocabulary as one JSON-LD file.
 *
 * For clients that want everything at once: agents building a local index, and triple stores
 * such as Apache Jena Fuseki, which is how a Skosmos installation can read these vocabularies.
 *
 * @package WP_SKOS
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 1.0.0
 */
class WP_SKOS_Export {

	/** Terms loaded per query, so a large taxonomy doesn't load every term object at once. */
	const BATCH_SIZE = 200;

	/** Seconds after which a lock is treated as abandoned, as when a server kills the request. */
	const LOCK_TTL = 600;

	/**
	 * @since 1.0.0
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'dispatch' ), 1 );
	}

	/**
	 * URL of a taxonomy's export.
	 *
	 * @since 1.0.0
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	public static function url( string $taxonomy ): string {
		return home_url( '/wp-skos/export/' . $taxonomy . '.jsonld' );
	}

	/**
	 * Serve the export when the request is for its path, and nothing else has claimed it.
	 *
	 * @since 1.0.0
	 */
	public static function dispatch(): void {
		if ( ! is_404() || ! preg_match( '#^/wp-skos/export/([a-z0-9_-]+)\.jsonld$#', WP_SKOS_Agent_Discovery::request_path(), $matches ) ) {
			return;
		}

		$taxonomy = $matches[1];
		if ( ! WP_SKOS_Settings::is_taxonomy_enabled( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		/**
		 * Filters whether a vocabulary can be exported as one file.
		 *
		 * The first export after a change has to build many concept documents, so a site with a
		 * very large taxonomy on modest hosting may prefer to turn this off.
		 *
		 * @since 1.0.0
		 * @param bool   $allow    Whether to serve the export. Default true.
		 * @param string $taxonomy Taxonomy slug.
		 */
		if ( ! apply_filters( 'wpskos_allow_export', true, $taxonomy ) ) {
			return;
		}

		$is_head = isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'];

		// An export after a change can take minutes, so run one per taxonomy at a time, or
		// repeated requests could tie up every PHP worker.
		if ( ! $is_head && ! self::acquire_lock( $taxonomy ) ) {
			status_header( 503 );
			header( 'Retry-After: 60' );
			header( 'Cache-Control: no-store' );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( array( 'error' => 'An export of this vocabulary is already running. Try again shortly.' ) );
			exit;
		}

		$authed = function_exists( 'htax_can_see_hidden_terms' ) && htax_can_see_hidden_terms( $taxonomy );

		status_header( 200 );
		header_remove( 'Expires' );
		header_remove( 'Pragma' );
		header( 'Content-Type: application/ld+json; charset=utf-8' );
		// A viewer who can see hidden terms gets a different file, which must not be shared.
		header( 'Cache-Control: ' . ( $authed ? 'private, no-store' : 'public, max-age=3600' ) );
		header( 'X-Content-Type-Options: nosniff' );

		if ( $is_head ) {
			exit;
		}

		self::stream( $taxonomy, $authed );
		exit;
	}

	/**
	 * Claim the taxonomy's export lock. It is released when the request ends.
	 *
	 * Uses add_option() because it fails atomically when the option already exists.
	 *
	 * @since 1.0.0
	 * @param string $taxonomy Taxonomy slug.
	 * @return bool False when another export of the taxonomy is running.
	 */
	private static function acquire_lock( string $taxonomy ): bool {
		$option = 'wpskos_export_lock_' . md5( $taxonomy );

		if ( ! add_option( $option, time(), '', 'no' ) ) {
			wp_cache_delete( $option, 'options' );
			if ( time() - (int) get_option( $option, 0 ) < self::LOCK_TTL ) {
				return false;
			}
			update_option( $option, time(), false );
		}

		register_shutdown_function( 'delete_option', $option );

		return true;
	}

	/**
	 * Write the scheme and all its concepts as one JSON-LD document, without holding it in memory.
	 *
	 * @since 1.0.0
	 * @param string $taxonomy Taxonomy slug.
	 * @param bool   $authed   Whether the viewer can see hidden terms.
	 */
	private static function stream( string $taxonomy, bool $authed ): void {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

		echo '{"@context":' . wp_json_encode( WP_SKOS_Builder::jsonld_context(), $flags ) . ',"@graph":[';

		$first  = true;
		$scheme = WP_SKOS_Builder::build_scheme( $taxonomy );
		if ( null !== $scheme ) {
			echo wp_json_encode( $scheme, $flags );
			$first = false;
		}

		$term_ids = get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids',
			'orderby'    => 'none',
		) );

		foreach ( array_chunk( is_wp_error( $term_ids ) ? array() : array_map( 'intval', $term_ids ), self::BATCH_SIZE ) as $batch ) {
			$terms = get_terms( array(
				'taxonomy'   => $taxonomy,
				'include'    => $batch,
				'hide_empty' => false,
				'orderby'    => 'none',
			) );

			foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
				$concept = self::concept( $term, $authed );
				if ( null === $concept ) {
					continue;
				}
				echo ( $first ? '' : ',' ) . wp_json_encode( $concept, $flags );
				$first = false;
			}

			flush();
		}

		echo ']}';
	}

	/**
	 * A term's concept node, through the same cache and visibility rules as its concept URI.
	 *
	 * @since 1.0.0
	 * @param WP_Term $term   Term.
	 * @param bool    $authed Whether the viewer can see hidden terms.
	 * @return array|null Null when the viewer can't see the term.
	 */
	private static function concept( WP_Term $term, bool $authed ): ?array {
		$cache_key = WP_SKOS_Builder::cache_key_concept( $term->term_id, $authed );
		$response  = get_transient( $cache_key );

		if ( false === $response ) {
			$data = WP_SKOS_Builder::build_concept( $term, $authed );
			if ( null === $data ) {
				return null;
			}
			$response = array(
				'@context' => WP_SKOS_Builder::jsonld_context(),
				'@graph'   => array( $data ),
			);
			set_transient( $cache_key, $response, WP_SKOS_Builder::CACHE_TTL );
		}

		$response = WP_SKOS_Builder::inject_modified( $response, $term );

		return $response['@graph'][0] ?? null;
	}
}
