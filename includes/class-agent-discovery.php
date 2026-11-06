<?php
/**
 * Tells AI agents and other API clients how to use this site's vocabularies.
 *
 * Serves a generated guide (llms.txt) and an RFC 9727 API catalog. Only requests WordPress
 * is about to answer with a 404 are handled, so a real file or another plugin's response at
 * the same path always wins, and nothing is written to disk or the database.
 *
 * The output is English because it is written for software, like the RDF ontology texts.
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
class WP_SKOS_Agent_Discovery {

	const PLUGIN_GUIDE_PATH = '/wp-skos/llms.txt';
	const ROOT_GUIDE_PATH   = '/llms.txt';
	const CATALOG_PATH      = '/.well-known/api-catalog';
	const DOCS_URL          = 'https://hypertaxonomy.org/wp-skos/';

	/**
	 * @since 1.0.0
	 */
	public static function register(): void {
		// Before redirect_canonical (priority 10), which would otherwise act on the 404.
		add_action( 'template_redirect', array( __CLASS__, 'dispatch' ), 1 );
	}

	/**
	 * Serve the guide or catalog when the request is for one of their paths.
	 *
	 * @since 1.0.0
	 */
	public static function dispatch(): void {
		if ( ! is_404() ) {
			return;
		}

		$path = self::request_path();
		if ( self::PLUGIN_GUIDE_PATH !== $path && self::ROOT_GUIDE_PATH !== $path && self::CATALOG_PATH !== $path ) {
			return;
		}

		// Concept and scheme URIs need pretty permalinks, so there is nothing to describe without them.
		if ( '' === (string) get_option( 'permalink_structure' ) || ! WP_SKOS_Settings::get_enabled_taxonomies() ) {
			return;
		}

		if ( self::CATALOG_PATH === $path ) {
			self::send_catalog();
		}

		/**
		 * Filters whether the guide is also served at the site root, /llms.txt.
		 *
		 * A site has only one root file, so a site can return false to leave it for something
		 * else. The guide stays available at /wp-skos/llms.txt.
		 *
		 * @since 1.0.0
		 * @param bool $serve Whether to serve /llms.txt. Default true.
		 */
		if ( self::ROOT_GUIDE_PATH === $path && ! apply_filters( 'wpskos_serve_root_llms_txt', true ) ) {
			return;
		}

		self::send_guide();
	}

	/**
	 * The request path relative to the site's home URL, without a trailing slash.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	private static function request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return rtrim( $path, '/' );
	}

	/**
	 * Send headers for a successful response, replacing the no-cache headers of the 404.
	 *
	 * @since 1.0.0
	 * @param string $content_type Content-Type header value.
	 */
	private static function send_headers( string $content_type ): void {
		status_header( 200 );
		header_remove( 'Expires' );
		header_remove( 'Pragma' );
		header( 'Content-Type: ' . $content_type );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * @since 1.0.0
	 */
	private static function send_guide(): void {
		self::send_headers( 'text/plain; charset=utf-8' );
		header( 'Link: <' . esc_url_raw( home_url( self::CATALOG_PATH ) ) . '>; rel="api-catalog"', false );

		if ( ! self::is_head_request() ) {
			echo self::build_guide(); // Plain text, not HTML.
		}
		exit;
	}

	/**
	 * @since 1.0.0
	 */
	private static function send_catalog(): void {
		self::send_headers( 'application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"' );
		header( 'Link: <' . esc_url_raw( home_url( self::CATALOG_PATH ) ) . '>; rel="api-catalog"', false );

		if ( ! self::is_head_request() ) {
			echo wp_json_encode( self::build_catalog(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		exit;
	}

	/**
	 * @since 1.0.0
	 * @return bool
	 */
	private static function is_head_request(): bool {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'];
	}

	/**
	 * Build the llms.txt guide for this site's enabled vocabularies.
	 *
	 * @since 1.0.0
	 * @return string Markdown.
	 */
	public static function build_guide(): string {
		$site    = self::one_line( get_bloginfo( 'name' ) );
		$skosmos = home_url( '/' . WPSKOS_SKOSMOS_PREFIX );
		$posts   = rest_url( 'wp-skos/v1/posts' );

		$lines = array(
			'# ' . ( '' !== $site ? $site : wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ),
			'',
			'> This site publishes its WordPress taxonomies as SKOS vocabularies: each taxonomy is a concept scheme and each term is a concept with its own URI. All endpoints below are read-only, public, and need no authentication.',
			'',
			'Concept URIs have the form `' . home_url( '/wp-skos/concept/{taxonomy}/{slug}' ) . '` and return JSON-LD unless the request explicitly prefers HTML. To find a concept, search its label with the search endpoint below, then use the returned `uri`. To find the posts about a concept, pass that URI as `concept` to the document search endpoint; repeat `concept` to search several, with `operator=AND` or `operator=OR`. Follow `skos:broader`, `skos:narrower` and `skos:related` to move between concepts, and `skos:closeMatch` to reach the same concept in external vocabularies.',
			'',
			'## Vocabularies',
			'',
		);

		foreach ( WP_SKOS_Settings::get_enabled_taxonomies() as $taxonomy ) {
			$tax_obj = get_taxonomy( $taxonomy );
			if ( ! $tax_obj ) {
				continue;
			}
			$lines[] = sprintf(
				'- [%s](%s): Concept scheme for `%s`, with its top concepts. Use `vocab=%s` to search only this vocabulary.',
				self::one_line( $tax_obj->label ),
				WP_SKOS_Builder::scheme_uri( $taxonomy ),
				$taxonomy,
				$taxonomy
			);
		}

		$lines = array_merge(
			$lines,
			array(
				'',
				'## Endpoints',
				'',
				'- [List vocabularies](' . $skosmos . '/vocabularies): All enabled vocabularies.',
				'- [Search concepts](' . $skosmos . '/search?query=example): Label search. Parameters: `query`, optional `vocab`, `lang`, and `maxhits` (default 50, maximum 200).',
				'- [Search documents](' . $posts . '): Posts about one or more concepts, ranked by relevance, as JSON-LD. Requires `concept`, a concept URI. Optional parameters: `operator`, `page`, `per_page`.',
				'',
				'## Optional',
				'',
				'- [WP-SKOS documentation](' . self::DOCS_URL . '): The full REST API, URI scheme, and output format.',
				'',
			)
		);

		/**
		 * Filters the generated llms.txt guide.
		 *
		 * @since 1.0.0
		 * @param string $guide Markdown text.
		 */
		return (string) apply_filters( 'wpskos_llms_txt', implode( "\n", $lines ) );
	}

	/**
	 * Build the RFC 9727 API catalog, in Linkset format.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public static function build_catalog(): array {
		$guide = array(
			'href' => home_url( self::PLUGIN_GUIDE_PATH ),
			'type' => 'text/plain',
		);

		return array(
			'linkset' => array(
				array(
					'anchor'      => home_url( '/' . WPSKOS_SKOSMOS_PREFIX . '/vocabularies' ),
					'service-doc' => array(
						array(
							'href' => self::DOCS_URL . 'skosmos-rest-api.html',
							'type' => 'text/html',
						),
						$guide,
					),
				),
				array(
					'anchor'       => rest_url( 'wp-skos/v1/posts' ),
					// WordPress describes a REST namespace's routes and arguments at its index.
					'service-desc' => array(
						array(
							'href' => rest_url( 'wp-skos/v1' ),
							'type' => 'application/json',
						),
					),
					'service-doc'  => array(
						array(
							'href' => self::DOCS_URL . 'document-search.html',
							'type' => 'text/html',
						),
						$guide,
					),
				),
			),
		);
	}

	/**
	 * Make text safe for a one-line Markdown link label or heading.
	 *
	 * @since 1.0.0
	 * @param string $text Text.
	 * @return string
	 */
	private static function one_line( string $text ): string {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) ) );
		return str_replace( array( '[', ']' ), array( '(', ')' ), $text );
	}
}
