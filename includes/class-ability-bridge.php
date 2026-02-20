<?php
/**
 * Bridges WordPress Abilities to WebMCP tool definitions.
 *
 * @package WebMCP
 */

namespace WebMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Converts registered WordPress Abilities into WebMCP-compatible tool definitions,
 * applying visibility controls and permission filtering.
 */
class Ability_Bridge {

	/** Object cache group for the tools list. */
	const CACHE_GROUP = 'wmcp_bridge';

	/** @var Settings */
	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Return WebMCP tool definitions for all abilities the current user
	 * is allowed to discover.
	 *
	 * @return array<int, array{name: string, description: string, inputSchema: array}>
	 */
	public function get_tools_for_current_user(): array {
		$user_id   = get_current_user_id();
		$cache_key = "tools_{$user_id}";

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$tools = $this->build_tools();

		wp_cache_set( $cache_key, $tools, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $tools;
	}

	/**
	 * Build the full list of tool definitions for the current user.
	 *
	 * @return array<int, array>
	 */
	private function build_tools(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$abilities = wp_get_abilities();
		$tools     = array();

		foreach ( $abilities as $name => $ability ) {
			$tool = $this->convert( $name, $ability );
			if ( null !== $tool ) {
				$tools[] = $tool;
			}
		}

		return $tools;
	}

	/**
	 * Convert a single WP_Ability to a WebMCP tool definition.
	 * Returns null if the ability should not be exposed.
	 *
	 * @param string      $name    Ability identifier.
	 * @param \WP_Ability $ability Ability object.
	 * @return array|null
	 */
	public function convert( string $name, \WP_Ability $ability ): ?array {
		// 1. Check wmcp_visibility flag — 'private' always hides.
		$visibility = $ability->get_meta_item( 'wmcp_visibility', 'public' );
		if ( 'private' === $visibility ) {
			return null;
		}

		// 2. Check admin's exposed-tools allowlist.
		if ( ! $this->settings->is_tool_exposed( $name ) ) {
			return null;
		}

		// 3. Check permission callback for the current user.
		$permission = $ability->check_permissions();
		if ( true !== $permission ) {
			return null;
		}

		// 4. Validate and sanitize the inputSchema.
		$input_schema = $this->validate_schema( $ability->get_input_schema() );

		// 5. Build the tool definition.
		$tool = array(
			'name'        => $name,
			'description' => wp_strip_all_tags( $ability->get_description() ),
			'inputSchema' => $input_schema,
		);

		// 6. Add readOnlyHint annotation if the ability declares itself read-only.
		if ( $ability->get_meta_item( 'wmcp_read_only', false ) ) {
			$tool['annotations'] = array( 'readOnlyHint' => true );
		}

		/**
		 * Filter the WebMCP tool definition before it's sent to the browser.
		 * Use to customize description, add tool annotations, etc.
		 *
		 * @param array       $tool    The tool definition.
		 * @param string      $name    The ability name.
		 * @param \WP_Ability $ability The ability object.
		 */
		$tool = apply_filters( 'wmcp_tool_definition', $tool, $name, $ability );

		/**
		 * Filter whether an ability is exposed via WebMCP.
		 * Return false to hide a tool from discovery.
		 *
		 * @param bool        $expose  Whether to expose this ability.
		 * @param string      $name    Ability name.
		 * @param \WP_Ability $ability The ability object.
		 */
		if ( ! apply_filters( 'wmcp_expose_ability', true, $name, $ability ) ) {
			return null;
		}

		return $tool;
	}

	/**
	 * Validate a JSON Schema object for use as a tool inputSchema.
	 * Rejects schemas with depth > 5 or unsupported $ref usage.
	 *
	 * @param array $schema Raw schema from ability definition.
	 * @return array Validated schema, or empty-object schema on failure.
	 */
	public function validate_schema( array $schema ): array {
		// Cast properties to stdClass so JSON encodes as {} not [].
		$empty = array( 'type' => 'object', 'properties' => new \stdClass() );

		if ( empty( $schema ) ) {
			return $empty;
		}

		if ( $this->schema_depth( $schema ) > 5 ) {
			return $empty;
		}

		if ( $this->schema_has_ref( $schema ) ) {
			return $empty;
		}

		return $this->fix_empty_properties( $schema );
	}

	/**
	 * Recursively cast any empty 'properties' arrays to stdClass so they
	 * serialize as JSON objects ({}) rather than arrays ([]).
	 * Gemini and other models reject [] as an invalid JSON Schema properties value.
	 *
	 * @param array $schema Schema to fix.
	 * @return array
	 */
	private function fix_empty_properties( array $schema ): array {
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			if ( empty( $schema['properties'] ) ) {
				$schema['properties'] = new \stdClass();
			} else {
				foreach ( $schema['properties'] as &$prop ) {
					if ( is_array( $prop ) ) {
						$prop = $this->fix_empty_properties( $prop );
					}
				}
				unset( $prop );
			}
		}

		// Also recurse into 'items' for array schemas.
		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$schema['items'] = $this->fix_empty_properties( $schema['items'] );
		}

		return $schema;
	}

	/**
	 * Compute the maximum nesting depth of an array/schema.
	 *
	 * @param array $schema Schema to inspect.
	 * @param int   $depth  Current depth (1-based at the top level).
	 */
	private function schema_depth( array $schema, int $depth = 1 ): int {
		$max = $depth;
		foreach ( $schema as $value ) {
			if ( is_array( $value ) ) {
				$child = $this->schema_depth( $value, $depth + 1 );
				$max   = max( $max, $child );
			}
		}
		return $max;
	}

	/**
	 * Check if a schema contains $ref keys (not supported).
	 *
	 * @param array $schema Schema to inspect.
	 */
	private function schema_has_ref( array $schema ): bool {
		if ( array_key_exists( '$ref', $schema ) ) {
			return true;
		}
		foreach ( $schema as $value ) {
			if ( is_array( $value ) && $this->schema_has_ref( $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compute an ETag for the current user's tool set.
	 * Used for HTTP caching on the /tools endpoint.
	 */
	public function compute_etag(): string {
		return md5( (string) wp_json_encode( $this->get_tools_for_current_user() ) );
	}

	/**
	 * Invalidate all cached tool lists.
	 * Called when plugins activate or deactivate.
	 */
	public function invalidate_cache(): void {
		wp_cache_flush_group( self::CACHE_GROUP );
	}
}
