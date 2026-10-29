<?php
/**
 * Handles stable concept, scheme, and vocabulary property URIs.
 *
 *   /wp-skos/concept/{taxonomy}/{term-slug}   → skos:Concept JSON-LD (or 303 to archive for browsers)
 *   /wp-skos/scheme/{taxonomy}                → skos:ConceptScheme JSON-LD
 *   /wp-skos/vocab/{slug}                     → OWL property declaration JSON-LD
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_SKOS_Concept_URI {

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
		add_rewrite_rule( '^wp-skos/concept/([^/]+)/([^/]+)/?$', 'index.php?wpskos_concept_tax=$matches[1]&wpskos_concept=$matches[2]', 'top' );
		add_rewrite_rule( '^wp-skos/collection/([^/]+)/?$', 'index.php?wpskos_collection=$matches[1]', 'top' );
		add_rewrite_rule( '^wp-skos/scheme/([^/]+)/?$',  'index.php?wpskos_scheme=$matches[1]',  'top' );
		add_rewrite_rule( '^wp-skos/vocab/([^/]+)/?$',   'index.php?wpskos_vocab=$matches[1]',   'top' );
	}

	/**
	 * @since 1.0.0
	 */
	public static function add_query_vars( array $vars ): array {
		$vars[] = 'wpskos_concept_tax';
		$vars[] = 'wpskos_concept';
		$vars[] = 'wpskos_collection';
		$vars[] = 'wpskos_scheme';
		$vars[] = 'wpskos_vocab';
		return $vars;
	}

	/**
	 * @since 1.0.0
	 */
	public static function dispatch(): void {
		$concept     = get_query_var( 'wpskos_concept' );
		$concept_tax = get_query_var( 'wpskos_concept_tax' );
		$collection  = get_query_var( 'wpskos_collection' );
		$scheme      = get_query_var( 'wpskos_scheme' );
		$vocab       = get_query_var( 'wpskos_vocab' );

		if ( $concept !== '' && $concept !== false ) {
			self::handle_concept(
				sanitize_key( rawurldecode( (string) $concept_tax ) ),
				sanitize_text_field( rawurldecode( (string) $concept ) )
			);
		} elseif ( $collection !== '' && $collection !== false ) {
			self::handle_collection( sanitize_text_field( rawurldecode( (string) $collection ) ) );
		} elseif ( $scheme !== '' && $scheme !== false ) {
			self::handle_scheme( sanitize_key( $scheme ) );
		} elseif ( $vocab !== '' && $vocab !== false ) {
			self::handle_vocab_property( sanitize_key( $vocab ) );
		}
	}

	/**
	 * Serve a concept document.
	 *
	 * Content negotiation:
	 *   Accept: text/html                 → 303 See Other to term archive
	 *   Accept: application/ld+json       → JSON-LD directly
	 *   Default (no Accept / other)       → JSON-LD
	 *
	 * @since 1.0.0
	 */
	private static function handle_concept( string $taxonomy, string $slug ): void {
		// Resolve the term within its own taxonomy (URIs are taxonomy-qualified).
		if ( ! WP_SKOS_Settings::is_taxonomy_enabled( $taxonomy ) ) {
			self::not_found();
		}

		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! ( $term instanceof WP_Term ) ) {
			self::not_found();
		}

		// Hidden term check (HT) — mirror build_concept() so the redirect path does
		// not leak a hidden term's existence to unauthorized clients.
		if ( function_exists( 'htax_is_term_hidden' ) && htax_is_term_hidden( $term->term_id ) && ! current_user_can( 'read_private_posts' ) ) {
			self::not_found();
		}

		// Browser redirect.
		if ( self::prefers_html() ) {
			$archive = get_term_link( $term );
			if ( ! is_wp_error( $archive ) ) {
				wp_redirect( $archive, 303 );
				exit;
			}
		}

		$authed = current_user_can( 'read_private_posts' );
		$cache_key = WP_SKOS_Builder::cache_key_concept( $term->term_id, $authed );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			self::jsonld_response( WP_SKOS_Builder::inject_modified( $cached, $term ) );
		}

		$data = WP_SKOS_Builder::build_concept( $term, $authed );
		if ( null === $data ) {
			self::not_found();
		}

		$response = array(
			'@context' => WP_SKOS_Builder::jsonld_context(),
			'@graph'   => array( $data ),
		);

		set_transient( $cache_key, $response, 0 );
		self::jsonld_response( WP_SKOS_Builder::inject_modified( $response, $term ) );
	}

	/**
	 * Serve a ConceptScheme document.
	 *
	 * @since 1.0.0
	 */
	private static function handle_scheme( string $taxonomy ): void {
		if ( ! WP_SKOS_Settings::is_taxonomy_enabled( $taxonomy ) ) {
			self::not_found();
		}

		// There is no single public "archive" for an entire taxonomy the way there is
		// for one term, so there is nowhere public to send an HTML-preferring visitor.
		// Only redirect someone who can actually use the admin Terms screen; everyone
		// else gets the JSON-LD response rather than being bounced into a login wall.
		if ( self::prefers_html() && current_user_can( 'manage_categories' ) ) {
			wp_redirect( admin_url( 'edit-tags.php?taxonomy=' . $taxonomy ), 303 );
			exit;
		}

		$cache_key = WP_SKOS_Builder::cache_key_scheme( $taxonomy );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			self::jsonld_response( $cached );
		}

		$data = WP_SKOS_Builder::build_scheme( $taxonomy );
		if ( null === $data ) {
			self::not_found();
		}

		$response = array(
			'@context' => WP_SKOS_Builder::jsonld_context(),
			'@graph'   => array( $data ),
		);

		set_transient( $cache_key, $response, 0 );
		self::jsonld_response( $response );
	}

	/**
	 * Serve a term-group Collection document.
	 *
	 * Always serves JSON-LD: groups have no public archive, so there is no HTML
	 * 303 redirect. Collections are built on demand (not cached) because group
	 * membership changes are not reliably invalidated.
	 *
	 * @since 1.0.0
	 */
	private static function handle_collection( string $slug ): void {
		if ( ! function_exists( 'htax_get_all_term_groups' ) ) {
			self::not_found();
		}

		$group = get_term_by( 'slug', $slug, 'group' );
		if ( ! ( $group instanceof WP_Term ) ) {
			self::not_found();
		}

		$authed = current_user_can( 'read_private_posts' );
		$data   = WP_SKOS_Builder::build_collection( $group, $authed );
		if ( null === $data ) {
			self::not_found();
		}

		self::jsonld_response( array(
			'@context' => WP_SKOS_Builder::jsonld_context(),
			'@graph'   => array( $data ),
		) );
	}

	/**
	 * Serve a vocabulary property declaration.
	 *
	 * @since 1.0.0
	 */
	private static function handle_vocab_property( string $slug ): void {
		$data = WP_SKOS_Builder::build_vocab_property( $slug );
		if ( null === $data ) {
			self::not_found();
		}

		$response = array(
			'@context' => WP_SKOS_Builder::jsonld_context(),
			'@graph'   => array( $data ),
		);

		self::jsonld_response( $response );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * True when the request explicitly prefers text/html over application/ld+json.
	 *
	 * @since 1.0.0
	 */
	private static function prefers_html(): bool {
		$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
		$wants_html    = strpos( $accept, 'text/html' ) !== false;
		$wants_jsonld  = strpos( $accept, 'application/ld+json' ) !== false;
		return $wants_html && ! $wants_jsonld;
	}

	/**
	 * @since 1.0.0
	 */
	private static function jsonld_response( array $data ): void {
		status_header( 200 );
		header( 'Content-Type: application/ld+json; charset=utf-8' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * @since 1.0.0
	 */
	private static function not_found(): void {
		status_header( 404 );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( array( 'error' => __( 'Not found', 'wp-skos' ) ) );
		exit;
	}
}
