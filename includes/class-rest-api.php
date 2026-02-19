<?php
/**
 * REST API endpoint registration and request handling.
 *
 * @package WebMCP_Bridge
 */

namespace WebMCP_Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the three WebMCP Bridge REST endpoints:
 *   GET  /wp-json/webmcp/v1/tools
 *   POST /wp-json/webmcp/v1/execute/{ability-name}
 *   GET  /wp-json/webmcp/v1/nonce
 */
class REST_API {

	const NAMESPACE = 'webmcp/v1';

	/** @var Ability_Bridge */
	private Ability_Bridge $bridge;

	/** @var Rate_Limiter */
	private Rate_Limiter $rate_limiter;

	/** @var Settings */
	private Settings $settings;

	public function __construct(
		Ability_Bridge $bridge,
		Rate_Limiter $rate_limiter,
		Settings $settings
	) {
		$this->bridge       = $bridge;
		$this->rate_limiter = $rate_limiter;
		$this->settings     = $settings;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 */
	public function register_routes(): void {
		// Tool discovery endpoint.
		register_rest_route(
			self::NAMESPACE,
			'/tools',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tools' ),
				'permission_callback' => array( $this, 'tools_permission_check' ),
			)
		);

		// Tool execution endpoint.
		register_rest_route(
			self::NAMESPACE,
			'/execute/(?P<ability>[a-zA-Z0-9_\-\/]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'execute_tool' ),
				'permission_callback' => array( $this, 'execute_permission_check' ),
				'args'                => array(
					'ability' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Nonce refresh endpoint.
		register_rest_route(
			self::NAMESPACE,
			'/nonce',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_nonce' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// =========================================================================
	// GET /tools
	// =========================================================================

	/**
	 * Permission check for the tools discovery endpoint.
	 * Enforces authentication unless public discovery is enabled.
	 */
	public function tools_permission_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! $this->settings->is_enabled() ) {
			return new \WP_Error( 'wmcp_disabled', __( 'WebMCP Bridge is not enabled.', 'webmcp-bridge' ), array( 'status' => 404 ) );
		}

		// Rate-limit discovery by IP.
		$ip = $this->get_client_ip();
		if ( ! $this->rate_limiter->check_discovery( $ip ) ) {
			return new \WP_Error(
				'wmcp_rate_limited',
				__( 'Too many requests. Please slow down.', 'webmcp-bridge' ),
				array( 'status' => 429 )
			);
		}

		// If public discovery is on, anyone can list tools.
		if ( $this->settings->is_discovery_public() ) {
			return true;
		}

		// Otherwise require authentication.
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'wmcp_auth_required', __( 'Authentication required.', 'webmcp-bridge' ), array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Handle GET /tools — return all discoverable tools for the current user.
	 */
	public function get_tools( \WP_REST_Request $request ): \WP_REST_Response {
		$tools = $this->bridge->get_tools_for_current_user();
		$etag  = $this->bridge->compute_etag();

		// Support conditional requests.
		$if_none_match = $request->get_header( 'if_none_match' );
		if ( $if_none_match && trim( $if_none_match, '"' ) === $etag ) {
			return new \WP_REST_Response( null, 304 );
		}

		$response = new \WP_REST_Response(
			array(
				'tools' => $tools,
				'nonce' => wp_create_nonce( 'wmcp_execute' ),
			),
			200
		);

		$response->header( 'Cache-Control', 'private, max-age=300' );
		$response->header( 'Vary', 'Cookie' );
		$response->header( 'ETag', '"' . $etag . '"' );

		return $response;
	}

	// =========================================================================
	// POST /execute/{ability}
	// =========================================================================

	/**
	 * Permission check for the execute endpoint.
	 * Requires authentication and a valid nonce.
	 */
	public function execute_permission_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! $this->settings->is_enabled() ) {
			return new \WP_Error( 'wmcp_disabled', __( 'WebMCP Bridge is not enabled.', 'webmcp-bridge' ), array( 'status' => 404 ) );
		}

		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'wmcp_auth_required', __( 'Authentication required.', 'webmcp-bridge' ), array( 'status' => 401 ) );
		}

		// Verify nonce.
		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wmcp_execute' ) ) {
			return new \WP_Error( 'wmcp_invalid_nonce', __( 'Invalid or expired security token.', 'webmcp-bridge' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Handle POST /execute/{ability} — validate, rate-limit, and run the ability.
	 */
	public function execute_tool( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ability_name = $request->get_param( 'ability' );
		$user_id      = get_current_user_id();

		// Input size check.
		$max_size = (int) apply_filters( 'wmcp_max_input_size', 100 * 1024 ); // 100 KB
		if ( strlen( $request->get_body() ) > $max_size ) {
			return new \WP_REST_Response(
				array( 'code' => 'wmcp_payload_too_large', 'message' => __( 'Request payload exceeds the maximum allowed size.', 'webmcp-bridge' ) ),
				400
			);
		}

		// Get registered abilities.
		if ( ! function_exists( 'wp_get_registered_abilities' ) ) {
			return new \WP_REST_Response(
				array( 'code' => 'wmcp_abilities_unavailable', 'message' => __( 'WordPress Abilities API is not available.', 'webmcp-bridge' ) ),
				500
			);
		}

		$abilities = wp_get_registered_abilities();

		if ( ! isset( $abilities[ $ability_name ] ) ) {
			return new \WP_REST_Response(
				array( 'code' => 'wmcp_not_found', 'message' => __( 'Tool not found.', 'webmcp-bridge' ) ),
				404
			);
		}

		$ability = $abilities[ $ability_name ];

		// Check wmcp_visibility.
		if ( ( $ability['wmcp_visibility'] ?? 'public' ) === 'private' ) {
			return new \WP_REST_Response(
				array( 'code' => 'wmcp_not_found', 'message' => __( 'Tool not found.', 'webmcp-bridge' ) ),
				404
			);
		}

		// Check admin exposed-tools list.
		if ( ! $this->settings->is_tool_exposed( $ability_name ) ) {
			return new \WP_REST_Response(
				array( 'code' => 'wmcp_not_found', 'message' => __( 'Tool not found.', 'webmcp-bridge' ) ),
				404
			);
		}

		// Re-check permission_callback at execution time.
		if ( ! empty( $ability['permission_callback'] ) && is_callable( $ability['permission_callback'] ) ) {
			if ( ! call_user_func( $ability['permission_callback'] ) ) {
				return new \WP_REST_Response(
					array( 'code' => 'wmcp_forbidden', 'message' => __( 'You do not have permission to use this tool.', 'webmcp-bridge' ) ),
					403
				);
			}
		}

		// Pre-execution filter — last chance to block.
		$input = $request->get_json_params() ?? array();

		/**
		 * Filter to block execution before it happens.
		 * Return a WP_Error to block; return true to allow.
		 *
		 * @param true|\WP_Error $allow
		 * @param string         $ability_name
		 * @param array          $input
		 * @param int            $user_id
		 */
		$allow = apply_filters( 'wmcp_allow_execution', true, $ability_name, $input, $user_id );
		if ( is_wp_error( $allow ) ) {
			return new \WP_REST_Response(
				array( 'code' => $allow->get_error_code(), 'message' => $allow->get_error_message() ),
				403
			);
		}

		// Rate limit check.
		if ( ! $this->rate_limiter->check_execution( $user_id, $ability_name ) ) {
			$response = new \WP_REST_Response(
				array( 'code' => 'wmcp_rate_limited', 'message' => __( 'Rate limit exceeded. Please wait before making more requests.', 'webmcp-bridge' ) ),
				429
			);
			$response->header( 'Retry-After', '60' );
			return $response;
		}

		// Execute the ability.
		$result  = null;
		$success = false;

		try {
			if ( ! empty( $ability['execute_callback'] ) && is_callable( $ability['execute_callback'] ) ) {
				$result = call_user_func( $ability['execute_callback'], $input );
			} else {
				$result = new \WP_Error( 'wmcp_no_callback', __( 'This ability has no execute callback.', 'webmcp-bridge' ) );
			}

			if ( is_wp_error( $result ) ) {
				// Log only the error code, not the message (may contain PII).
				do_action( 'wmcp_tool_executed', $ability_name, $user_id, false );

				return new \WP_REST_Response(
					array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ),
					$result->get_error_data( 'status' ) ?? 500
				);
			}

			$success = true;

		} catch ( \Throwable $e ) {
			do_action( 'wmcp_tool_executed', $ability_name, $user_id, false );

			return new \WP_REST_Response(
				array( 'code' => 'wmcp_execution_error', 'message' => __( 'Tool execution failed.', 'webmcp-bridge' ) ),
				500
			);
		}

		/**
		 * Fires after a tool is successfully executed.
		 * Receives only PII-safe data: no raw input or output.
		 *
		 * @param string $ability_name The ability that was executed.
		 * @param int    $user_id      The executing user's ID.
		 * @param bool   $success      Whether execution succeeded.
		 */
		do_action( 'wmcp_tool_executed', $ability_name, $user_id, $success );

		return new \WP_REST_Response( array( 'result' => $result ), 200 );
	}

	// =========================================================================
	// GET /nonce
	// =========================================================================

	/**
	 * Return a fresh nonce for the execution endpoint.
	 */
	public function get_nonce( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			array( 'nonce' => wp_create_nonce( 'wmcp_execute' ) ),
			200
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Get the client's IP address for rate limiting.
	 * Uses REMOTE_ADDR as the authoritative source; does not trust proxy headers
	 * to avoid IP spoofing.
	 */
	private function get_client_ip(): string {
		return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
	}
}
