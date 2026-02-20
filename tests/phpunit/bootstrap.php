<?php
/**
 * PHPUnit bootstrap for WebMCP Bridge.
 *
 * @package WebMCP
 */

// Path to the WordPress test suite.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "$_tests_dir/includes/functions.php" ) ) {
	echo "Could not find WordPress test suite at '$_tests_dir'.\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	echo "Run: composer require --dev wp-phpunit/wp-phpunit\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	exit( 1 );
}

// Point WP bootstrap to PHPUnit Polyfills (required by WP test suite).
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
}

// Must load functions.php before calling tests_add_filter().
require_once "$_tests_dir/includes/functions.php";

// Load the plugin.
$_plugin_dir = dirname( __DIR__, 2 );

tests_add_filter( 'muplugins_loaded', function () use ( $_plugin_dir ) {
	require_once "$_plugin_dir/webmcp-for-wordpress.php";
} );

require_once "$_tests_dir/includes/bootstrap.php";
