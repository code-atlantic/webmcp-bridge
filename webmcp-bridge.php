<?php
/**
 * Plugin Name: WebMCP Bridge
 * Plugin URI:  https://github.com/code-atlantic/webmcp-bridge
 * Description: Bridges WordPress Abilities to the WebMCP browser API (navigator.modelContext), making any WordPress site's capabilities discoverable and invocable by AI agents in Chrome 146+.
 * Version:     0.2.1
 * Author:      Code Atlantic
 * Author URI:  https://code-atlantic.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: webmcp-bridge
 * Requires PHP: 8.0
 * Requires at least: 6.9
 *
 * @package WebMCP_Bridge
 */

defined( 'ABSPATH' ) || exit;

define( 'WMCP_VERSION', '0.2.1' );
define( 'WMCP_PLUGIN_FILE', __FILE__ );
define( 'WMCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WMCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Verify minimum WordPress version before loading.
register_activation_hook( __FILE__, 'wmcp_activation_check' );

/**
 * Refuse activation on WordPress versions that lack the Abilities API.
 */
function wmcp_activation_check(): void {
	global $wp_version;

	if ( version_compare( $wp_version, '6.9', '<' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			sprintf(
				/* translators: %s: Required WordPress version */
				esc_html__( 'WebMCP Bridge requires WordPress %s or higher. Please update WordPress and try again.', 'webmcp-bridge' ),
				'6.9'
			),
			esc_html__( 'Plugin Activation Error', 'webmcp-bridge' ),
			array( 'back_link' => true )
		);
	}

	if ( ! function_exists( 'wp_register_ability' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'WebMCP Bridge requires the WordPress Abilities API. Please ensure you are running WordPress 6.9 or higher with the Abilities API available.', 'webmcp-bridge' ),
			esc_html__( 'Plugin Activation Error', 'webmcp-bridge' ),
			array( 'back_link' => true )
		);
	}
}

/**
 * Boot the plugin after all plugins are loaded.
 *
 * We only bail on WP version — REST routes and script enqueue always register
 * so that settings are accessible regardless of load order. Individual feature
 * guards (function_exists checks) handle the case where the Abilities API
 * plugin hasn't loaded yet or isn't installed.
 */
function wmcp_init(): void {
	global $wp_version;

	if ( version_compare( $wp_version, '6.9', '<' ) ) {
		add_action( 'admin_notices', 'wmcp_incompatible_notice' );
		return;
	}

	// Load class files.
	require_once WMCP_PLUGIN_DIR . 'includes/class-rate-limiter.php';
	require_once WMCP_PLUGIN_DIR . 'includes/class-ability-bridge.php';
	require_once WMCP_PLUGIN_DIR . 'includes/class-builtin-tools.php';
	require_once WMCP_PLUGIN_DIR . 'includes/class-rest-api.php';
	require_once WMCP_PLUGIN_DIR . 'includes/class-settings.php';
	require_once WMCP_PLUGIN_DIR . 'includes/class-admin-page.php';
	require_once WMCP_PLUGIN_DIR . 'includes/class-plugin.php';

	WebMCP_Bridge\Plugin::instance();
}
add_action( 'plugins_loaded', 'wmcp_init' );

/**
 * Admin notice shown when the plugin is active on an incompatible WordPress version.
 */
function wmcp_incompatible_notice(): void {
	?>
	<div class="notice notice-error">
		<p>
			<?php esc_html_e( 'WebMCP Bridge requires WordPress 6.9 or higher. The plugin is currently inactive.', 'webmcp-bridge' ); ?>
		</p>
	</div>
	<?php
}
