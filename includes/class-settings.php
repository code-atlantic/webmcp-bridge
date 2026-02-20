<?php
/**
 * Settings management.
 *
 * @package WebMCP
 */

namespace WebMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and retrieves plugin settings via the WordPress Settings API.
 */
class Settings {

	const OPTION_ENABLED          = 'wmcp_enabled';
	const OPTION_EXPOSED_TOOLS    = 'wmcp_exposed_tools';
	const OPTION_DISCOVERY_PUBLIC = 'wmcp_discovery_public';

	/**
	 * Register settings with the WordPress Settings API.
	 * Called by Admin_Page on admin_init.
	 */
	public function register(): void {
		register_setting(
			'wmcp_settings_group',
			self::OPTION_ENABLED,
			[
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			]
		);

		register_setting(
			'wmcp_settings_group',
			self::OPTION_EXPOSED_TOOLS,
			[
				'type'              => 'array',
				'default'           => [],
				'sanitize_callback' => [ $this, 'sanitize_exposed_tools' ],
			]
		);

		register_setting(
			'wmcp_settings_group',
			self::OPTION_DISCOVERY_PUBLIC,
			[
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			]
		);
	}

	/**
	 * Whether WebMCP Abilities is globally enabled.
	 * Defaults to true on fresh installs — the plugin does nothing harmful
	 * when enabled and "install and it works" is the right first-run experience.
	 */
	public function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, true );
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

	/** Built-in tool names exposed by default on fresh installs. */
	const DEFAULT_EXPOSED_TOOLS = [
		'wp/search-posts',
		'wp/get-post',
		'wp/get-categories',
		'wp/submit-comment',
	];

	/**
	 * Get the list of tool names the admin has chosen to expose.
	 * If never configured, returns only the built-in tools.
	 *
	 * @return string[] Tool name slugs.
	 */
	public function get_exposed_tools(): array {
		$value = get_option( self::OPTION_EXPOSED_TOOLS, null );

		// Never saved = fresh install — default to built-ins only.
		if ( null === $value ) {
			return self::DEFAULT_EXPOSED_TOOLS;
		}

		return (array) $value;
	}

	/**
	 * Whether the exposed tools list has been explicitly saved by an admin.
	 */
	public function has_exposed_tools_been_configured(): bool {
		return null !== get_option( self::OPTION_EXPOSED_TOOLS, null );
	}

	/**
	 * Whether a given tool name is in the admin's exposed list.
	 *
	 * @param string $tool_name Ability identifier.
	 */
	public function is_tool_exposed( string $tool_name ): bool {
		return in_array( $tool_name, $this->get_exposed_tools(), true );
	}

	/**
	 * Sanitize the exposed tools array: ensure all items are strings.
	 *
	 * @param mixed $value Raw option value.
	 * @return string[]
	 */
	public function sanitize_exposed_tools( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		return array_values( array_filter( array_map( 'sanitize_text_field', $value ) ) );
	}
}
