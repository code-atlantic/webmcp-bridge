<?php
/**
 * Tests for Ability_Bridge.
 *
 * @package WebMCP
 */

namespace WebMCP\Tests;

use WebMCP\Ability_Bridge;
use WebMCP\Settings;
use WP_UnitTestCase;

/**
 * Tests for Ability_Bridge.
 */
class Test_Ability_Bridge extends WP_UnitTestCase {

	/**
	 * Ability bridge instance.
	 *
	 * @var Ability_Bridge
	 */
	private Ability_Bridge $bridge;

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Tracks registered ability names for teardown cleanup.
	 *
	 * @var array
	 */
	private array $registered = [];

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->settings   = new Settings();
		$this->bridge     = new Ability_Bridge( $this->settings );
		$this->registered = [];

		update_option( Settings::OPTION_ENABLED, true );

		// Explicitly expose test tools so convert() tests aren't blocked by the default allowlist.
		update_option( Settings::OPTION_EXPOSED_TOOLS, [
			'test/public',
			'test/private',
			'test/no-vis',
			'test/locked',
			'test/not-listed',
			'test/listed',
			'test/safe',
			'test/filtered',
		] );

		// Ensure the abilities registry is initialized so our test category is available.
		wp_get_abilities();

		// Register a test ability category if not already registered.
		if ( ! wp_has_ability_category( 'test' ) ) {
			global $wp_current_filter; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test helper to mock action context.
			$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test helper to mock action context.
			wp_register_ability_category(
				'test',
				[
					'label'       => 'Test',
					'description' => 'Test category for unit tests.',
				]
			);
			array_pop( $wp_current_filter ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test helper to mock action context.
		}
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->registered as $name ) {
			wp_unregister_ability( $name );
		}
		delete_option( Settings::OPTION_ENABLED );
		delete_option( Settings::OPTION_EXPOSED_TOOLS );
		parent::tearDown();
	}

	/**
	 * Register a test ability inside the wp_abilities_api_init context and track it for cleanup.
	 * Uses the $wp_current_filter trick to satisfy doing_action() checks.
	 *
	 * @param string $name The ability name.
	 * @param array  $args The ability arguments.
	 *
	 * @return ?\WP_Ability The registered ability or null on failure.
	 */
	private function register( string $name, array $args ): ?\WP_Ability {
		// Ensure test category is set.
		if ( ! isset( $args['category'] ) ) {
			$args['category'] = 'test';
		}

		global $wp_current_filter; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test helper to mock action context.
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test helper to mock action context.
		$ability             = wp_register_ability( $name, $args );
		array_pop( $wp_current_filter ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test helper to mock action context.

		if ( $ability instanceof \WP_Ability ) {
			$this->registered[] = $name;
		}
		return $ability;
	}

	/**
	 * Call convert() with a freshly registered WP_Ability.
	 *
	 * @param string $name The ability name.
	 * @param array  $args The ability arguments.
	 *
	 * @return ?array The converted tool array or null on failure.
	 */
	private function convert( string $name, array $args ): ?array {
		$this->register( $name, $args );
		$ability = wp_get_ability( $name );
		if ( ! $ability instanceof \WP_Ability ) {
			return null;
		}
		return $this->bridge->convert( $name, $ability );
	}

	// -------------------------------------------------------------------------
	// convert() — visibility
	// -------------------------------------------------------------------------

	/**
	 * Verifies convert returns null for private ability.
	 */
	public function test_convert_returns_null_for_private_ability(): void {
		$result = $this->convert(
			'test/private',
			[
				'label'               => 'Private Tool',
				'description'         => 'Should be hidden',
				'meta'                => [ 'wmcp_visibility' => 'private' ],
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_null',
			]
		);

		$this->assertNull( $result );
	}

	/**
	 * Verifies convert returns tool for public ability.
	 */
	public function test_convert_returns_tool_for_public_ability(): void {
		$tool = $this->convert(
			'test/public',
			[
				'label'               => 'Public Tool',
				'description'         => 'Should be visible',
				'meta'                => [ 'wmcp_visibility' => 'public' ],
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_null',
			]
		);

		$this->assertIsArray( $tool );
		$this->assertSame( 'test/public', $tool['name'] );
		$this->assertSame( 'Should be visible', $tool['description'] );
	}

	/**
	 * Verifies convert defaults to public when visibility not set.
	 */
	public function test_convert_defaults_to_public_when_visibility_not_set(): void {
		$tool = $this->convert(
			'test/no-vis',
			[
				'label'               => 'No Visibility Key',
				'description'         => 'Missing wmcp_visibility',
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_null',
			]
		);

		$this->assertIsArray( $tool );
	}

	// -------------------------------------------------------------------------
	// convert() — permission_callback
	// -------------------------------------------------------------------------

	/**
	 * Verifies convert returns null when permission denied.
	 */
	public function test_convert_returns_null_when_permission_denied(): void {
		$result = $this->convert(
			'test/locked',
			[
				'label'               => 'Locked Tool',
				'description'         => 'Admin only',
				'permission_callback' => '__return_false',
				'execute_callback'    => '__return_null',
			]
		);

		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// convert() — admin exposed list
	// -------------------------------------------------------------------------

	/**
	 * Verifies convert returns null when not in exposed list.
	 */
	public function test_convert_returns_null_when_not_in_exposed_list(): void {
		update_option( Settings::OPTION_EXPOSED_TOOLS, [ 'other/tool' ] );

		$result = $this->convert(
			'test/not-listed',
			[
				'label'               => 'Not Listed',
				'description'         => 'Not in exposed list',
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_null',
			]
		);

		$this->assertNull( $result );
	}

	/**
	 * Verifies convert returns tool when in exposed list.
	 */
	public function test_convert_returns_tool_when_in_exposed_list(): void {
		update_option( Settings::OPTION_EXPOSED_TOOLS, [ 'test/listed' ] );

		$tool = $this->convert(
			'test/listed',
			[
				'label'               => 'Listed Tool',
				'description'         => 'In exposed list',
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_null',
			]
		);

		$this->assertIsArray( $tool );
	}

	// -------------------------------------------------------------------------
	// convert() — description sanitization
	// -------------------------------------------------------------------------

	/**
	 * Verifies convert strips HTML from description.
	 */
	public function test_convert_strips_html_from_description(): void {
		$tool = $this->convert(
			'test/safe',
			[
				'label'               => 'Safe Tool',
				'description'         => '<script>alert("xss")</script>Safe description',
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_null',
			]
		);

		$this->assertIsArray( $tool );
		$this->assertStringNotContainsString( '<script>', $tool['description'] );
		$this->assertStringContainsString( 'Safe description', $tool['description'] );
	}

	// -------------------------------------------------------------------------
	// validate_schema()
	// -------------------------------------------------------------------------

	/**
	 * Verifies validate_schema returns empty object for empty input.
	 */
	public function test_validate_schema_returns_empty_object_for_empty_input(): void {
		$schema = $this->bridge->validate_schema( [] );
		$this->assertEquals( [
			'type'       => 'object',
			'properties' => new \stdClass(),
		], $schema );
	}

	/**
	 * Verifies validate_schema rejects excessive depth.
	 */
	public function test_validate_schema_rejects_excessive_depth(): void {
		$deep   = [ 'a' => [ 'b' => [ 'c' => [ 'd' => [ 'e' => [ 'f' => 'too deep' ] ] ] ] ] ];
		$schema = $this->bridge->validate_schema( $deep );
		$this->assertEquals( [
			'type'       => 'object',
			'properties' => new \stdClass(),
		], $schema );
	}

	/**
	 * Verifies validate_schema rejects dollar ref.
	 */
	public function test_validate_schema_rejects_dollar_ref(): void {
		$schema_with_ref = [
			'type'       => 'object',
			'properties' => [
				'id' => [ '$ref' => '#/definitions/Id' ],
			],
		];
		$schema          = $this->bridge->validate_schema( $schema_with_ref );
		$this->assertEquals( [
			'type'       => 'object',
			'properties' => new \stdClass(),
		], $schema );
	}

	/**
	 * Verifies validate_schema passes valid schema.
	 */
	public function test_validate_schema_passes_valid_schema(): void {
		$valid  = [
			'type'       => 'object',
			'properties' => [
				'query' => [ 'type' => 'string' ],
				'limit' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'query' ],
		];
		$schema = $this->bridge->validate_schema( $valid );
		$this->assertSame( $valid, $schema );
	}

	// -------------------------------------------------------------------------
	// wmcp_expose_ability filter
	// -------------------------------------------------------------------------

	/**
	 * Verifies wmcp_expose_ability filter can hide tool.
	 */
	public function test_wmcp_expose_ability_filter_can_hide_tool(): void {
		add_filter( 'wmcp_expose_ability', '__return_false' );

		$result = $this->convert(
			'test/filtered',
			[
				'label'               => 'Filtered Tool',
				'description'         => 'Hidden by filter',
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_null',
			]
		);

		remove_filter( 'wmcp_expose_ability', '__return_false' );

		$this->assertNull( $result );
	}
}
