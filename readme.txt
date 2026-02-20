=== WebMCP Abilities ===
Contributors: codeatlantic
Tags: ai, agents, webmcp, abilities, mcp
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.6.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bridges WordPress Abilities to the WebMCP browser API, making your site's capabilities discoverable by AI agents in Chrome 146+.

== Description ==

**WebMCP Abilities** connects the [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/) to the [WebMCP browser standard](https://webmachinelearning.github.io/webmcp/), allowing AI agents running in Chrome 146+ to discover and invoke your site's registered capabilities as structured tools.

[youtube https://youtu.be/7A34ZNz2bMM]

= How It Works =

When enabled, the plugin:

1. Registers a lightweight JavaScript bridge on your site's front end
2. Fetches all registered WordPress Abilities visible to the current user
3. Exposes them to the browser's AI agent via `navigator.modelContext.registerTool()`
4. Agents can then invoke tools, which execute server-side via a secure REST API

= Built-in Tools =

The plugin ships four starter tools that work immediately — no other plugins needed:

* **Search Posts** — Search published posts by keyword (public)
* **Get Post** — Retrieve a post by ID or slug (public)
* **Get Categories** — List all post categories (public)
* **Submit Comment** — Submit a comment on a post (respects WordPress comment settings)

= Ecosystem Fit =

* **Complements [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter)** — which handles CLI and API agents via the MCP protocol. This plugin handles browser-based agents.
* **Complements [wmcp.dev](https://www.wmcp.dev/)** — which handles declarative form annotations. This plugin handles registered Abilities as imperative tools.

= Browser Support =

WebMCP requires Chrome 146 or higher. On other browsers, the plugin loads but silently does nothing — no errors.

= HTTPS Required =

The WebMCP standard requires a secure context. The front-end bridge will not load on HTTP sites. The plugin displays a warning in the admin if HTTPS is not detected.

= For Plugin Developers =

Any ability registered via `wp_register_ability()` automatically becomes a WebMCP tool. Add a `wmcp_visibility` key to your ability definition to control discoverability:

`
wp_register_ability( 'my-plugin/my-action', array(
    'label'               => 'My Action',
    'description'         => 'Does something useful for agents.',
    'wmcp_visibility'     => 'public',   // 'public' (default) or 'private'
    'inputSchema'         => array( ... ),
    'execute_callback'    => function( $input ) { ... },
    'permission_callback' => function() { return current_user_can( 'read' ); },
) );
`

== Installation ==

1. Upload the `webmcp-abilities` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** screen in WordPress
3. Go to **Settings → WebMCP** to enable and configure the plugin
4. Ensure your site is served over HTTPS

= Requirements =

* WordPress 6.9 or higher (requires the Abilities API)
* PHP 8.0 or higher
* Chrome 146+ on the visitor's browser for WebMCP to be active

== Frequently Asked Questions ==

= Do I need to configure anything? =

Just enable the plugin on the Settings → WebMCP page. Four built-in tools work immediately. Other plugins that register WordPress Abilities will also appear automatically.

= Is this safe? =

Yes. Tool execution requires authentication (visitors must be logged in). The admin's "Exposed Tools" list controls which tools are available. Each tool's own `permission_callback` enforces WordPress capabilities at execution time.

= Can anonymous visitors use tools? =

It depends on the tool. Public tools (like the built-in search and category tools) can be executed by anyone. Write tools and tools with custom permission callbacks may require authentication. The admin's "Exposed Tools" list controls which tools are visible.

= Does this work with the WordPress MCP Adapter? =

Yes — they are complementary. Built-in tools are registered as real WordPress Abilities, so they also appear via the MCP Adapter for CLI/API agents. The two plugins serve different transports (browser vs. API/CLI).

= What about the `.well-known/webmcp.json` manifest? =

This feature (which allows agents to discover tools before visiting the page) is planned for a future release. In the current version, agents discover tools when they load a page on your site.

== Screenshots ==

1. The WebMCP Abilities settings page — enable the bridge, control tool discovery, and manage which tools are exposed to AI agents.

== Changelog ==

= 0.6.1 =
* Security: third-party abilities now default to hidden on fresh installs
* Only built-in tools (search, get post, categories, comments) are exposed by default
* Admins must explicitly enable new tools via Settings → WebMCP

= 0.6.0 =
* Renamed plugin from "WebMCP for WordPress" to "WebMCP Abilities for WordPress" for WordPress.org compliance
* Updated text domain, slugs, and all references

= 0.5.0 =
* TypeScript conversion: front-end bridge rewritten in TypeScript with full type safety
* Build pipeline: @wordpress/scripts v31.5 with webpack, output to dist/ with .asset.php manifests
* PHPCS compliance: CodeAtlantic coding standards, zero violations
* PHPDoc: comprehensive documentation across all PHP source and test files
* Cleanup: removed stale build artifacts

= 0.4.0 =
* Initial release
* Four built-in tools: wp/search-posts, wp/get-post, wp/get-categories, wp/submit-comment
* Per-tool visibility control via Settings checkboxes
* Public discovery toggle
* Rate limiting: 30 executions/min per user, 100 discovery requests/min per IP
* Full WordPress Abilities API integration
* ETag-based client-side caching (24h TTL)

== Upgrade Notice ==

= 0.6.0 =
Plugin renamed to "WebMCP Abilities for WordPress". Please update any references in your code or configuration.

= 0.5.0 =
TypeScript rewrite, PHPCS compliance, improved build pipeline.

= 0.4.0 =
Initial release.
