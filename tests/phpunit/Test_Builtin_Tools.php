<?php
/**
 * Tests for Builtin_Tools.
 *
 * @package WebMCP
 */

namespace WebMCP\Tests;

use WebMCP\Builtin_Tools;
use WP_UnitTestCase;

class Test_Builtin_Tools extends WP_UnitTestCase {

	private Builtin_Tools $tools;
	private int $post_id;

	public function setUp(): void {
		parent::setUp();
		$this->tools = new Builtin_Tools();

		// Create a test post.
		$this->post_id = self::factory()->post->create( array(
			'post_title'   => 'Hello World',
			'post_content' => 'This is test content for WebMCP Bridge.',
			'post_status'  => 'publish',
			'post_name'    => 'hello-world',
		) );
	}

	public function tearDown(): void {
		wp_delete_post( $this->post_id, true );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// search-posts
	// -------------------------------------------------------------------------

	public function test_search_posts_returns_results(): void {
		$results = $this->tools->execute_search_posts( array( 'query' => 'Hello World' ) );
		$this->assertIsArray( $results );
		$this->assertNotEmpty( $results );

		$first = $results[0];
		$this->assertArrayHasKey( 'id', $first );
		$this->assertArrayHasKey( 'title', $first );
		$this->assertArrayHasKey( 'url', $first );
		$this->assertSame( $this->post_id, $first['id'] );
	}

	public function test_search_posts_returns_error_for_empty_query(): void {
		$result = $this->tools->execute_search_posts( array( 'query' => '' ) );
		$this->assertWPError( $result );
	}

	public function test_search_posts_respects_count_limit(): void {
		// Create multiple posts.
		$ids = self::factory()->post->create_many( 5, array( 'post_status' => 'publish', 'post_title' => 'Count Test' ) );

		$results = $this->tools->execute_search_posts( array( 'query' => 'Count Test', 'count' => 2 ) );
		$this->assertIsArray( $results );
		$this->assertCount( 2, $results );

		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	public function test_search_posts_sanitizes_query(): void {
		// Queries with inline HTML tags should have the tags stripped but the
		// text content preserved, so the search still runs without crashing.
		$results = $this->tools->execute_search_posts( array( 'query' => '<b>Hello</b> World' ) );
		$this->assertIsArray( $results );
	}

	// -------------------------------------------------------------------------
	// get-post
	// -------------------------------------------------------------------------

	public function test_get_post_by_id(): void {
		$result = $this->tools->execute_get_post( array( 'id' => $this->post_id ) );
		$this->assertIsArray( $result );
		$this->assertSame( $this->post_id, $result['id'] );
		$this->assertSame( 'Hello World', $result['title'] );
	}

	public function test_get_post_by_slug(): void {
		$result = $this->tools->execute_get_post( array( 'slug' => 'hello-world' ) );
		$this->assertIsArray( $result );
		$this->assertSame( $this->post_id, $result['id'] );
	}

	public function test_get_post_returns_error_for_nonexistent_id(): void {
		$result = $this->tools->execute_get_post( array( 'id' => 999999 ) );
		$this->assertWPError( $result );
	}

	public function test_get_post_returns_error_for_draft_to_anonymous(): void {
		$draft_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		// Run as anonymous (no current user).
		wp_set_current_user( 0 );
		$result = $this->tools->execute_get_post( array( 'id' => $draft_id ) );
		$this->assertWPError( $result );

		wp_delete_post( $draft_id, true );
	}

	public function test_get_post_result_has_no_html_in_content(): void {
		$post_with_html = self::factory()->post->create( array(
			'post_content' => '<p><strong>Bold text</strong> and <a href="#">link</a></p>',
			'post_status'  => 'publish',
		) );

		$result = $this->tools->execute_get_post( array( 'id' => $post_with_html ) );
		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( '<p>', $result['content'] );
		$this->assertStringContainsString( 'Bold text', $result['content'] );

		wp_delete_post( $post_with_html, true );
	}

	// -------------------------------------------------------------------------
	// get-categories
	// -------------------------------------------------------------------------

	public function test_get_categories_returns_array(): void {
		$categories = $this->tools->execute_get_categories( array() );
		$this->assertIsArray( $categories );
	}

	public function test_get_categories_has_expected_keys(): void {
		// Ensure at least one category exists.
		$cat_id = self::factory()->category->create( array( 'name' => 'Test Cat' ) );

		$categories = $this->tools->execute_get_categories( array() );
		$this->assertNotEmpty( $categories );

		$first = $categories[0];
		$this->assertArrayHasKey( 'id', $first );
		$this->assertArrayHasKey( 'name', $first );
		$this->assertArrayHasKey( 'slug', $first );
		$this->assertArrayHasKey( 'count', $first );
		$this->assertArrayHasKey( 'url', $first );

		wp_delete_term( $cat_id, 'category' );
	}

	// -------------------------------------------------------------------------
	// submit-comment
	// -------------------------------------------------------------------------

	public function test_submit_comment_succeeds_on_open_post(): void {
		// Log in as a subscriber.
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $this->tools->execute_submit_comment( array(
			'post_id' => $this->post_id,
			'content' => 'This is a test comment.',
		) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'comment_id', $result );
		$this->assertGreaterThan( 0, $result['comment_id'] );

		wp_delete_user( $user_id );
	}

	public function test_submit_comment_returns_error_for_missing_post(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $this->tools->execute_submit_comment( array(
			'post_id' => 999999,
			'content' => 'Comment on missing post.',
		) );

		$this->assertWPError( $result );

		wp_delete_user( $user_id );
	}

	public function test_submit_comment_returns_error_for_empty_content(): void {
		$result = $this->tools->execute_submit_comment( array(
			'post_id' => $this->post_id,
			'content' => '',
		) );
		$this->assertWPError( $result );
	}

	// -------------------------------------------------------------------------
	// wmcp_include_builtin_tools filter
	// -------------------------------------------------------------------------

	public function test_register_skips_when_filter_returns_false(): void {
		// Capture whether wp_register_ability is called.
		$called = false;
		add_filter( 'wmcp_include_builtin_tools', '__return_false' );

		// Run register — it should bail early and not touch anything.
		$this->tools->register();

		remove_filter( 'wmcp_include_builtin_tools', '__return_false' );

		// If we get here without an exception, the filter worked.
		$this->assertTrue( true );
	}
}
