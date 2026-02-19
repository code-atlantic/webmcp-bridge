<?php
/**
 * Rate limiter for tool execution and discovery endpoints.
 *
 * @package WebMCP_Bridge
 */

namespace WebMCP_Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * Provides per-user execution rate limiting and per-IP discovery rate limiting.
 * Uses the WordPress object cache so it works with Redis, Memcached, or local cache.
 */
class Rate_Limiter {

	/** Cache group for rate limit counters. */
	const CACHE_GROUP = 'wmcp_rate';

	/** Execution rate limit window in seconds. */
	const EXECUTION_WINDOW = 60;

	/** Discovery rate limit window in seconds. */
	const DISCOVERY_WINDOW = 60;

	/**
	 * Check and increment the execution rate limit for a user.
	 * Returns true if the request is allowed, false if rate-limited.
	 *
	 * @param int    $user_id     The executing user's ID.
	 * @param string $ability_name The ability being executed.
	 */
	public function check_execution( int $user_id, string $ability_name ): bool {
		/**
		 * Filter the per-ability execution rate limit.
		 *
		 * @param int    $limit        Max executions per minute. Default 30.
		 * @param string $ability_name The ability name.
		 * @param int    $user_id      The user ID.
		 */
		$limit = (int) apply_filters( 'wmcp_rate_limit', 30, $ability_name, $user_id );

		/**
		 * Hard ceiling on total executions per user per minute regardless of
		 * per-ability overrides.
		 *
		 * @param int $ceiling Default 60.
		 */
		$ceiling = (int) apply_filters( 'wmcp_rate_limit_global_ceiling', 60 );

		$per_ability_key = "exec_{$user_id}_" . md5( $ability_name );
		$global_key      = "exec_{$user_id}_global";

		$per_ability_count = (int) wp_cache_get( $per_ability_key, self::CACHE_GROUP );
		$global_count      = (int) wp_cache_get( $global_key, self::CACHE_GROUP );

		if ( $per_ability_count >= $limit || $global_count >= $ceiling ) {
			return false;
		}

		$this->increment( $per_ability_key, self::EXECUTION_WINDOW );
		$this->increment( $global_key, self::EXECUTION_WINDOW );

		return true;
	}

	/**
	 * Check and increment the discovery rate limit for an IP address.
	 * Returns true if the request is allowed, false if rate-limited.
	 *
	 * @param string $ip Client IP address.
	 */
	public function check_discovery( string $ip ): bool {
		/**
		 * Filter the per-IP discovery rate limit.
		 *
		 * @param int    $limit Default 100 requests per minute.
		 * @param string $ip    The client IP address.
		 */
		$limit = (int) apply_filters( 'wmcp_discovery_rate_limit', 100, $ip );

		$key   = 'disc_' . md5( $ip );
		$count = (int) wp_cache_get( $key, self::CACHE_GROUP );

		if ( $count >= $limit ) {
			return false;
		}

		$this->increment( $key, self::DISCOVERY_WINDOW );

		return true;
	}

	/**
	 * Increment a rate limit counter using a simple add-or-increment pattern.
	 * Not perfectly atomic on all backends, but sufficient for rate limiting
	 * where occasional over-counts are acceptable.
	 *
	 * @param string $key    Cache key.
	 * @param int    $window TTL in seconds.
	 */
	private function increment( string $key, int $window ): void {
		$current = (int) wp_cache_get( $key, self::CACHE_GROUP );

		if ( false === wp_cache_get( $key, self::CACHE_GROUP ) ) {
			wp_cache_set( $key, 1, self::CACHE_GROUP, $window );
		} else {
			wp_cache_set( $key, $current + 1, self::CACHE_GROUP, $window );
		}
	}
}
