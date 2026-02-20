<?php
/**
 * Plugin uninstall cleanup.
 *
 * Removes all options, transients, and object cache keys created by WebMCP Bridge.
 *
 * @package WebMCP
 */

// Only run when WordPress itself calls this during plugin uninstallation.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove all plugin options.
delete_option( 'wmcp_enabled' );
delete_option( 'wmcp_exposed_tools' );
delete_option( 'wmcp_discovery_public' );

// Remove transients (rate limit counters have a TTL so they expire naturally,
// but we clean them up immediately on uninstall for tidiness).
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_wmcp_%',
		'_transient_timeout_wmcp_%'
	)
);

// Flush object cache entries in the wmcp_* groups.
// wp_cache_flush_group() is used if the backend supports it;
// otherwise we do a best-effort flush of the whole cache.
if ( function_exists( 'wp_cache_flush_group' ) ) {
	wp_cache_flush_group( 'wmcp_bridge' );
	wp_cache_flush_group( 'wmcp_rate' );
}
