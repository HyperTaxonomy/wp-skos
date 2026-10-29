<?php
/**
 * Plugin Name: WP-SKOS
 * Plugin URI:  https://github.com/HyperTaxonomy/wp-skos
 * Description: Exposes WordPress taxonomies as SKOS concept schemes via the Skosmos REST API. Integrates with HyperTaxonomy for full SKOS relationship coverage.
 * Version:     1.0.0
 * Author:      Eugene Weinstein
 * Author URI:  https://hypertaxonomy.org
 * Requires at least: 5.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-skos
 * Domain Path: /languages
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPSKOS_VERSION',         '1.0.0' );
define( 'WPSKOS_DIR',             plugin_dir_path( __FILE__ ) );
define( 'WPSKOS_URL',             plugin_dir_url( __FILE__ ) );
// Stable namespace for built-in WP-SKOS properties (wpskos:includes, wpskos:container, wpskos:synonym).
// Custom relationship type properties use {home_url}/wp-skos/vocab/{slug} instead.
define( 'WPSKOS_VOCAB_NS',        'https://hypertaxonomy.org/wp-skos/vocab/' );
define( 'WPSKOS_SKOSMOS_PREFIX',  'skosmos/rest/v1' );

// Load translations. Hooked on init (not plugins_loaded) per WordPress 6.7+ guidance.
add_action( 'init', static function () {
    load_plugin_textdomain( 'wp-skos', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

require_once WPSKOS_DIR . 'includes/class-settings.php';
require_once WPSKOS_DIR . 'includes/class-skos-builder.php';
require_once WPSKOS_DIR . 'includes/class-skosmos-api.php';
require_once WPSKOS_DIR . 'includes/class-document-search.php';
require_once WPSKOS_DIR . 'includes/class-concept-uri.php';

add_action( 'plugins_loaded', 'wpskos_init' );
// Built dynamically (not hardcoded 'wp-skos/wp-skos.php') so the Settings link still
// appears when the plugin is installed under a different folder name, e.g. a GitHub
// zip extracted as 'wp-skos-main'.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wpskos_action_links' );

/**
 * @since 1.0.0
 */
function wpskos_action_links( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'options-general.php?page=wp-skos' ) ),
		esc_html__( 'Settings', 'wp-skos' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}

/**
 * Bootstrap all WP-SKOS components.
 *
 * @since 1.0.0
 */
function wpskos_init(): void {
	WP_SKOS_Settings::register();
	WP_SKOS_Skosmos_API::register();
	WP_SKOS_Document_Search::register();
	WP_SKOS_Concept_URI::register();
}

/**
 * Keep WP-SKOS URLs exactly as requested.
 *
 * Concept, scheme, collection and vocab URIs are stable identifiers, and the Skosmos
 * REST endpoints are documented without a trailing slash. WordPress's canonical
 * redirect would otherwise 301 them to a trailing-slash variant (and API clients that
 * don't follow redirects would fail).
 *
 * @since 1.0.0
 * @param string|false $redirect_url Canonical URL WordPress would redirect to.
 * @return string|false
 */
function wpskos_disable_canonical_redirect( $redirect_url ) {
	foreach ( array( 'wpskos_concept_tax', 'wpskos_collection', 'wpskos_scheme', 'wpskos_vocab', 'wpskos_skosmos_path' ) as $var ) {
		if ( '' !== (string) get_query_var( $var ) ) {
			return false;
		}
	}
	return $redirect_url;
}
add_filter( 'redirect_canonical', 'wpskos_disable_canonical_redirect' );

register_activation_hook( __FILE__, 'wpskos_activate' );
register_deactivation_hook( __FILE__, 'wpskos_deactivate' );

/**
 * @since 1.0.0
 */
function wpskos_activate(): void {
	WP_SKOS_Concept_URI::add_rewrite_rules();
	WP_SKOS_Skosmos_API::add_rewrite_rules();
	flush_rewrite_rules();
}

/**
 * Remove the concept/scheme/collection URI rewrite rules: the plugin's rules are still registered during this request, so flush_rewrite_rules() would rebuild them; dropping the stored rules makes WordPress rebuild them without this plugin on the next request.
 *
 * @since 1.0.0
 */
function wpskos_deactivate(): void {
	delete_option( 'rewrite_rules' );
}
