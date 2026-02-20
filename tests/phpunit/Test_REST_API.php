<?php
/**
 * Tests for REST_API endpoints.
 *
 * @package WebMCP
 */

namespace WebMCP\Tests;

use WebMCP\Ability_Bridge;
use WebMCP\Rate_Limiter;
use WebMCP\REST_API;
use WebMCP\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

class Test_REST_API extends WP_UnitTestCase {

	private Settings $settings;
	private int $admin_id;

	public function setUp(): void {
		parent::setUp();
		$this->settings = new Settings();

		// Enable the plugin.
		update_option( Settings::OPTION_ENABLED, true );

		// Create an admin user.
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Initialise REST server.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	public function tearDown(): void {
		wp_delete_user( $this->admin_id );
		delete_option( Settings::OPTION_ENABLED );
		delete_option( Settings::OPTION_EXPOSED_TOOLS );
		delete_option( Settings::OPTION_DISCOVERY_PUBLIC );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// GET /webmcp/v1/tools
	// -------------------------------------------------------------------------

	public function test_tools_endpoint_requires_auth_by_default(): void {
		wp_set_current_user( 0 ); // Anonymous.

		$request  = new WP_REST_Request( 'GET', '/webmcp/v1/tools' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_tools_endpoint_returns_200_for_logged_in_user(): void {
		$request  = new WP_REST_Request( 'GET', '/webmcp/v1/tools' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_tools_endpoint_returns_tools_array(): void {
		$request  = new WP_REST_Request( 'GET', '/webmcp/v1/tools' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'tools', $data );
		$this->assertIsArray( $data['tools'] );
	}

	public function test_tools_endpoint_includes_nonce(): void {
		$request  = new WP_REST_Request( 'GET', '/webmcp/v1/tools' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'nonce', $data );
		$this->assertNotEmpty( $data['nonce'] );
	}

	public function test_tools_endpoint_is_public_when_discovery_enabled(): void {
		update_option( Settings::OPTION_DISCOVERY_PUBLIC, true );
		wp_set_current_user( 0 ); // Anonymous.

		$request  = new WP_REST_Request( 'GET', '/webmcp/v1/tools' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_tools_endpoint_has_cache_headers(): void {
		$request  = new WP_REST_Request( 'GET', '/webmcp/v1/tools' );
		$response = rest_do_request( $request );
		$headers  = $response->get_headers();

		$this->assertArrayHasKey( 'ETag', $headers );
		$this->assertArrayHasKey( 'Cache-Control', $headers );
	}

	public function test_tools_endpoint_returns_404_when_plugin_disabled(): void {
		update_option( Settings::OPTION_ENABLED, false );

		$request  = new WP_REST_Request( 'GET', '/webmcp/v1/tools' );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// GET /webmcp/v1/nonce
	// -------------------------------------------------------------------------

	public function test_nonce_endpoint_returns_nonce(): void {
		$request  = new WP_REST_Request( 'GET', '/webmcp/v1/nonce' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'nonce', $data );
		$this->assertNotEmpty( $data['nonce'] );
	}

	// -------------------------------------------------------------------------
	// POST /webmcp/v1/execute/{ability}
	// -------------------------------------------------------------------------

	public function test_execute_succeeds_anonymously_for_public_tool(): void {
		// get-categories has __return_true permission callback — no auth needed.
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/webmcp/v1/execute/wp%2Fget-categories' );
		$request->set_body( '{}' );
		$request->set_header( 'Content-Type', 'application/json' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_execute_succeeds_without_nonce_for_read_only_tool(): void {
		// Read-only tools skip CSRF nonce check even for logged-in users.
		$request  = new WP_REST_Request( 'POST', '/webmcp/v1/execute/wp%2Fget-categories' );
		$request->set_body( '{}' );
		$request->set_header( 'Content-Type', 'application/json' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_execute_returns_403_without_nonce_for_write_tool(): void {
		// Write tools (no wmcp_read_only) require a nonce for logged-in users.
		$request  = new WP_REST_Request( 'POST', '/webmcp/v1/execute/wp%2Fsubmit-comment' );
		$request->set_body( '{}' );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_execute_succeeds_with_valid_nonce(): void {
		$nonce = wp_create_nonce( 'wmcp_execute' );

		$request = new WP_REST_Request( 'POST', '/webmcp/v1/execute/wp%2Fget-categories' );
		$request->add_header( 'X-WP-Nonce', $nonce );
		$request->set_body( '{}' );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = rest_do_request( $request );

		// Should be 200 with result data.
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $response->get_data() );
	}

	public function test_execute_returns_404_for_unknown_ability(): void {
		$nonce = wp_create_nonce( 'wmcp_execute' );

		$request = new WP_REST_Request( 'POST', '/webmcp/v1/execute/nonexistent%2Ftool' );
		$request->add_header( 'X-WP-Nonce', $nonce );
		$request->set_body( '{}' );

		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_execute_returns_404_when_not_in_plugin_disabled(): void {
		update_option( Settings::OPTION_ENABLED, false );

		$nonce = wp_create_nonce( 'wmcp_execute' );

		$request = new WP_REST_Request( 'POST', '/webmcp/v1/execute/wp%2Fget-categories' );
		$request->add_header( 'X-WP-Nonce', $nonce );

		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_execute_returns_429_when_rate_limited(): void {
		// Set rate limit to 0 so everything is blocked.
		add_filter( 'wmcp_rate_limit', function () { return 0; } );
		add_filter( 'wmcp_rate_limit_global_ceiling', function () { return 0; } );

		$nonce = wp_create_nonce( 'wmcp_execute' );

		$request = new WP_REST_Request( 'POST', '/webmcp/v1/execute/wp%2Fget-categories' );
		$request->add_header( 'X-WP-Nonce', $nonce );
		$request->set_body( '{}' );

		$response = rest_do_request( $request );

		remove_all_filters( 'wmcp_rate_limit' );
		remove_all_filters( 'wmcp_rate_limit_global_ceiling' );

		$this->assertSame( 429, $response->get_status() );
	}

	public function test_execute_allows_filtering_via_wmcp_allow_execution(): void {
		add_filter( 'wmcp_allow_execution', function () {
			return new \WP_Error( 'blocked', 'Blocked by filter.' );
		} );

		$nonce = wp_create_nonce( 'wmcp_execute' );

		$request = new WP_REST_Request( 'POST', '/webmcp/v1/execute/wp%2Fget-categories' );
		$request->add_header( 'X-WP-Nonce', $nonce );
		$request->set_body( '{}' );

		$response = rest_do_request( $request );

		remove_all_filters( 'wmcp_allow_execution' );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_wmcp_tool_executed_action_fires_on_success(): void {
		$fired      = false;
		$fired_name = null;

		add_action( 'wmcp_tool_executed', function ( $name, $uid, $success ) use ( &$fired, &$fired_name ) {
			$fired      = $success;
			$fired_name = $name;
		}, 10, 3 );

		$nonce = wp_create_nonce( 'wmcp_execute' );

		$request = new WP_REST_Request( 'POST', '/webmcp/v1/execute/wp%2Fget-categories' );
		$request->add_header( 'X-WP-Nonce', $nonce );
		$request->set_body( '{}' );
		rest_do_request( $request );

		remove_all_actions( 'wmcp_tool_executed' );

		$this->assertTrue( $fired );
		$this->assertSame( 'wp/get-categories', $fired_name );
	}
}
