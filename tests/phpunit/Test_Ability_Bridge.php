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

class Test_Ability_Bridge extends WP_UnitTestCase {

	private Ability_Bridge $bridge;
	private Settings $settings;

	/** Tracks registered ability names for teardown cleanup. */
	private array $registered = array();

	public function setUp(): void {
		parent::setUp();
		$this->settings   = new Settings();
		$this->bridge     = new Ability_Bridge( $this->settings );
		$this->registered = array();

		update_option( Settings::OPTION_ENABLED, true );

		// Ensure the abilities registry is initialized so our test category is available.
		wp_get_abilities();

		// Register a test ability category if not already registered.
		if ( ! wp_has_ability_category( 'test' ) ) {
			global $wp_current_filter;
			$wp_current_filter[] = 'wp_abilities_api_categories_init';
			wp_register_ability_category(
				'test',
				array(
					'label'       => 'Test',
					'description' => 'Test category for unit tests.',
				)
			);
			array_pop( $wp_current_filter );
		}
	}

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
	 */
	private function register( string $name, array $args ): ?\WP_Ability {
		// Ensure test category is set.
		if ( ! isset( $args['category'] ) ) {
			$args['category'] = 'test';
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init';
		$ability              = wp_register_ability( $name, $args );
		array_pop( $wp_current_filter );

		if ( $ability instanceof \WP_Ability ) {
			$this->registered[] = $name;
		}
		return $ability;
	}

	/** Call convert() with a freshly registered WP_Ability. */
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

	public function test_convert_returns_null_for_private_ability(): void {
		$result = $this->convert(
			'test/private',
			array(
				'label'               => 'Private Tool',
				'description'         => 'Should be hidden',
				'meta'                => array( 'wmcp_visibility' => 'private' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_null',
			)
		);

		$this->assertNull( $result );
	}

	public function test_convert_returns_tool_for_public_ability(): void {
		$tool = $this->convert(
			'test/public',
			array(
				'label'               => 'Public Tool',
				'description'         => 'Should be visible',
				'meta'                => array( 'wmcp_visibility' => 'public' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_null',
			)
		);

		$this->assertIsArray( $tool );
		$this->assertSame( 'test/public', $tool['name'] );
		$this->assertSame( 'Should be visible', $tool['description'] );
	}

	public function test_convert_defaults_to_public_when_visibility_not_set(): void {
		$tool = $this->convert(
			'test/no-vis',
			array(
				'label'               => 'No Visibility Key',
				'description'         => 'Missing wmcp_visibility',
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_null',
			)
		);

		$this->assertIsArray( $tool );
	}

	// -------------------------------------------------------------------------
	// convert() — permission_callback
	// -------------------------------------------------------------------------

	public function test_convert_returns_null_when_permission_denied(): void {
		$result = $this->convert(
			'test/locked',
			array(
				'label'               => 'Locked Tool',
				'description'         => 'Admin only',
				'permission_callback' => '__return_false',
				'execute_callback'    => '__return_null',
			)
		);

		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// convert() — admin exposed list
	// -------------------------------------------------------------------------

	public function test_convert_returns_null_when_not_in_exposed_list(): void {
		update_option( Settings::OPTION_EXPOSED_TOOLS, array( 'other/tool' ) );

		$result = $this->convert(
			'test/not-listed',
			array(
				'label'               => 'Not Listed',
				'description'         => 'Not in exposed list',
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_null',
			)
		);

		$this->assertNull( $result );
	}

	public function test_convert_returns_tool_when_in_exposed_list(): void {
		update_option( Settings::OPTION_EXPOSED_TOOLS, array( 'test/listed' ) );

		$tool = $this->convert(
			'test/listed',
			array(
				'label'               => 'Listed Tool',
				'description'         => 'In exposed list',
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_null',
			)
		);

		$this->assertIsArray( $tool );
	}

	// -------------------------------------------------------------------------
	// convert() — description sanitization
	// -------------------------------------------------------------------------

	public function test_convert_strips_html_from_description(): void {
		$tool = $this->convert(
			'test/safe',
			array(
				'label'               => 'Safe Tool',
				'description'         => '<script>alert("xss")</script>Safe description',
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_null',
			)
		);

		$this->assertIsArray( $tool );
		$this->assertStringNotContainsString( '<script>', $tool['description'] );
		$this->assertStringContainsString( 'Safe description', $tool['description'] );
	}

	// -------------------------------------------------------------------------
	// validate_schema()
	// -------------------------------------------------------------------------

	public function test_validate_schema_returns_empty_object_for_empty_input(): void {
		$schema = $this->bridge->validate_schema( array() );
		$this->assertEquals( array( 'type' => 'object', 'properties' => new \stdClass() ), $schema );
	}

	public function test_validate_schema_rejects_excessive_depth(): void {
		$deep   = array( 'a' => array( 'b' => array( 'c' => array( 'd' => array( 'e' => array( 'f' => 'too deep' ) ) ) ) ) );
		$schema = $this->bridge->validate_schema( $deep );
		$this->assertEquals( array( 'type' => 'object', 'properties' => new \stdClass() ), $schema );
	}

	public function test_validate_schema_rejects_dollar_ref(): void {
		$schema_with_ref = array(
			'type'       => 'object',
			'properties' => array(
				'id' => array( '$ref' => '#/definitions/Id' ),
			),
		);
		$schema          = $this->bridge->validate_schema( $schema_with_ref );
		$this->assertEquals( array( 'type' => 'object', 'properties' => new \stdClass() ), $schema );
	}

	public function test_validate_schema_passes_valid_schema(): void {
		$valid  = array(
			'type'       => 'object',
			'properties' => array(
				'query' => array( 'type' => 'string' ),
				'limit' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'query' ),
		);
		$schema = $this->bridge->validate_schema( $valid );
		$this->assertSame( $valid, $schema );
	}

	// -------------------------------------------------------------------------
	// wmcp_expose_ability filter
	// -------------------------------------------------------------------------

	public function test_wmcp_expose_ability_filter_can_hide_tool(): void {
		add_filter( 'wmcp_expose_ability', '__return_false' );

		$result = $this->convert(
			'test/filtered',
			array(
				'label'               => 'Filtered Tool',
				'description'         => 'Hidden by filter',
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_null',
			)
		);

		remove_filter( 'wmcp_expose_ability', '__return_false' );

		$this->assertNull( $result );
	}
}
