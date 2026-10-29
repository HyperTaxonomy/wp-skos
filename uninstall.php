<?php
/**
 * Runs when the plugin is deleted via the WordPress admin.
 *
 * Removes the settings option and the cached SKOS documents (wpskos_* transients).
 * On multisite every site is cleaned. Rewrite rules are flushed on deactivation.
 *
 * @since 1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean up the current site.
 */
function wpskos_uninstall_cleanup(): void {
	global $wpdb;

	delete_option( 'wpskos_settings' );

	// TTL-0 transients never expire, so WP cron won't clean them up automatically.
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_wpskos\_%'
		    OR option_name LIKE '\_transient\_timeout\_wpskos\_%'"
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'number' => 0, 'fields' => 'ids' ) ) as $site_id ) {
		switch_to_blog( $site_id );
		wpskos_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	wpskos_uninstall_cleanup();
}
