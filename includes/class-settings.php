<?php
/**
 * Settings management.
 *
 * @package WebMCP_Bridge
 */

namespace WebMCP_Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and retrieves plugin settings via the WordPress Settings API.
 */
class Settings {

	const OPTION_ENABLED        = 'wmcp_enabled';
	const OPTION_EXPOSED_TOOLS  = 'wmcp_exposed_tools';
	const OPTION_DISCOVERY_PUBLIC = 'wmcp_discovery_public';

	/**
	 * Register settings with the WordPress Settings API.
	 * Called by Admin_Page on admin_init.
	 */
	public function register(): void {
		register_setting(
			'wmcp_settings_group',
			self::OPTION_ENABLED,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			'wmcp_settings_group',
			self::OPTION_EXPOSED_TOOLS,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_exposed_tools' ),
			)
		);

		register_setting(
			'wmcp_settings_group',
			self::OPTION_DISCOVERY_PUBLIC,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);
	}

	/**
	 * Whether WebMCP Bridge is globally enabled.
	 */
	public function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	/**
	 * Whether public (unauthenticated) discovery is enabled.
	 */
	public function is_discovery_public(): bool {
		$public = (bool) get_option( self::OPTION_DISCOVERY_PUBLIC, false );

		/**
		 * Filter whether tool discovery requires authentication.
		 *
		 * When true, unauthenticated requests to the /tools endpoint return 401.
		 * Note: execution always requires authentication regardless of this setting.
		 *
		 * @param bool $require_auth Default true (authenticated required).
		 */
		$require_auth = apply_filters( 'wmcp_tools_require_auth', ! $public );

		return ! $require_auth;
	}

	/**
	 * Get the list of tool names the admin has chosen to expose.
	 * An empty array means the admin has not yet configured the list —
	 * in that case all tools are exposed (permissive default for first install).
	 *
	 * @return string[] Tool name slugs.
	 */
	public function get_exposed_tools(): array {
		return (array) get_option( self::OPTION_EXPOSED_TOOLS, array() );
	}

	/**
	 * Whether a given tool name is in the admin's exposed list.
	 * If no list has been saved yet (first install), all tools are allowed.
	 *
	 * @param string $tool_name Ability identifier.
	 */
	public function is_tool_exposed( string $tool_name ): bool {
		$exposed = $this->get_exposed_tools();

		// Empty list = not yet configured = allow everything.
		if ( empty( $exposed ) ) {
			return true;
		}

		return in_array( $tool_name, $exposed, true );
	}

	/**
	 * Sanitize the exposed tools array: ensure all items are strings.
	 *
	 * @param mixed $value Raw option value.
	 * @return string[]
	 */
	public function sanitize_exposed_tools( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_text_field', $value ) ) );
	}
}
