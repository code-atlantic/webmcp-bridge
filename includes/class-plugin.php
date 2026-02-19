<?php
/**
 * Main plugin class.
 *
 * @package WebMCP_Bridge
 */

namespace WebMCP_Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires up all plugin components.
 */
class Plugin {

	/** @var Plugin|null */
	private static ?Plugin $instance = null;

	/** @var Settings */
	public readonly Settings $settings;

	/** @var Ability_Bridge */
	public readonly Ability_Bridge $bridge;

	/** @var REST_API */
	public readonly REST_API $rest;

	/** @var Builtin_Tools */
	public readonly Builtin_Tools $builtin_tools;

	/** @var Rate_Limiter */
	public readonly Rate_Limiter $rate_limiter;

	private function __construct() {
		$this->settings      = new Settings();
		$this->rate_limiter  = new Rate_Limiter();
		$this->bridge        = new Ability_Bridge( $this->settings );
		$this->builtin_tools = new Builtin_Tools();
		$this->rest          = new REST_API( $this->bridge, $this->rate_limiter, $this->settings );

		$this->register_hooks();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function register_hooks(): void {
		// Register built-in tools as WordPress Abilities.
		add_action( 'wp_abilities_api_init', array( $this->builtin_tools, 'register' ) );

		// Enqueue front-end JS.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );

		// Admin page.
		if ( is_admin() ) {
			$admin_page = new Admin_Page( $this->settings, $this->bridge );
			$admin_page->register();
		}

		// Cache invalidation when plugins activate/deactivate.
		add_action( 'activate_plugin',   array( $this->bridge, 'invalidate_cache' ) );
		add_action( 'deactivate_plugin', array( $this->bridge, 'invalidate_cache' ) );
	}

	/**
	 * Enqueue the WebMCP Bridge front-end script.
	 */
	public function enqueue_frontend(): void {
		// Only load when enabled and on HTTPS.
		if ( ! $this->settings->is_enabled() || ! is_ssl() ) {
			return;
		}

		// Allow themes/plugins to suppress loading on specific pages.
		if ( ! apply_filters( 'wmcp_should_enqueue', true ) ) {
			return;
		}

		$asset_file = WMCP_PLUGIN_DIR . 'assets/js/build/webmcp-bridge.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array( 'dependencies' => array(), 'version' => WMCP_VERSION );

		wp_enqueue_script(
			'webmcp-bridge',
			WMCP_PLUGIN_URL . 'assets/js/build/webmcp-bridge.js',
			$asset['dependencies'],
			$asset['version'],
			array( 'strategy' => 'defer' )
		);

		// Pass configuration to the script.
		wp_localize_script(
			'webmcp-bridge',
			'wmcpBridge',
			array(
				'toolsEndpoint'   => rest_url( 'webmcp/v1/tools' ),
				'executeEndpoint' => rest_url( 'webmcp/v1/execute/' ),
				'nonceEndpoint'   => rest_url( 'webmcp/v1/nonce' ),
				'nonce'           => wp_create_nonce( 'wmcp_execute' ),
			)
		);
	}
}
