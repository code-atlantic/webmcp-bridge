# WebMCP for WordPress for WordPress

[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![Chrome](https://img.shields.io/badge/Chrome-146%2B-4285F4?logo=googlechrome&logoColor=white)](https://developer.chrome.com/blog/webmcp-epp)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-blue)](LICENSE)
[![Tests](https://img.shields.io/badge/Tests-50%20passing-brightgreen)](#testing)
[![WebMCP Spec](https://img.shields.io/badge/spec-WebMCP%20W3C-orange)](https://webmachinelearning.github.io/webmcp/)

**Turn any WordPress site into a structured tool server for AI agents** — no custom API, no scraping, no prompt engineering required.

WebMCP for WordPress connects the [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/) to the [WebMCP browser standard](https://webmachinelearning.github.io/webmcp/), so AI agents running in Chrome 146+ can discover and call your site's capabilities as reliable, schema-driven tools.

---

## What Is WebMCP?

[WebMCP](https://webmachinelearning.github.io/webmcp/) is a browser API (`navigator.modelContext`) that lets websites register structured tools directly discoverable by AI agents. Instead of agents clicking through UIs, taking screenshots, and guessing at intent, they get:

- **Structured tool definitions** with JSON Schema inputs
- **Direct execution** via `navigator.modelContext.registerTool()`
- **Security enforced by the browser** — same-origin, HTTPS-only
- **~98% task accuracy** vs ~45% for vision-based approaches

> Currently in Early Preview — enable at `chrome://flags` → **WebMCP for testing** in Chrome 146+.

**References:**
- [WebMCP W3C Specification](https://webmachinelearning.github.io/webmcp/)
- [Google Chrome Blog: WebMCP Early Preview](https://developer.chrome.com/blog/webmcp-epp)
- [GitHub: webmachinelearning/webmcp](https://github.com/webmachinelearning/webmcp)

---

## What This Plugin Does

```
WordPress Site                          AI Agent (Claude, ChatGPT, etc.)
─────────────────                       ──────────────────────────────────
┌──────────────────────┐                ┌────────────────────────────────┐
│  WP Abilities API    │                │  Chrome 146+ browser           │
│  (register tools     │──── bridge ───▶│  navigator.modelContext        │
│   with schema +      │                │  .registerTool(...)            │
│   permissions)       │                └────────────────────────────────┘
└──────────────────────┘                          │
                                                  │ tool call
                                                  ▼
                                        ┌────────────────────────────────┐
                                        │  POST /wp-json/webmcp/v1/      │
                                        │  execute/{ability}             │
                                        │  with nonce + auth             │
                                        └────────────────────────────────┘
```

1. **Plugins register WordPress Abilities** — structured capabilities with labels, descriptions, JSON Schema inputs, permission callbacks, and execute callbacks.
2. **This plugin bridges them to WebMCP** — the front-end script calls `navigator.modelContext.registerTool()` for each exposed ability.
3. **AI agents discover and call tools** — structured JSON in, structured JSON out. No DOM parsing. No screenshots.

---

## Built-in Tools

Four starter tools are included out of the box:

| Tool | Description | Auth |
|------|-------------|------|
| `search-posts` | Full-text search across published posts | Public |
| `get-post` | Retrieve a post by ID or slug with full content | Public |
| `get-categories` | List all categories with counts and descriptions | Public |
| `submit-comment` | Submit a comment (respects WP comment settings) | Configurable |

Disable all built-ins with one filter:
```php
add_filter( 'wmcp_include_builtin_tools', '__return_false' );
```

---

## Installation

### Requirements

- WordPress **6.9+** (requires the Abilities API)
- PHP **8.0+**
- **HTTPS** (WebMCP is a secure context API)
- Chrome **146+** with WebMCP flag enabled (for AI agents)

### From Source

```bash
git clone https://github.com/code-atlantic/webmcp-for-wordpress.git
cd webmcp-for-wordpress
composer install --no-dev
```

Upload to `wp-content/plugins/webmcp-for-wordpress/` and activate.

---

## Registering Custom Tools

Any plugin can expose tools to AI agents by registering WordPress Abilities on the `wp_abilities_api_init` hook:

```php
add_action( 'wp_abilities_api_init', function () {
    wp_register_ability(
        'my-plugin/get-products',
        array(
            'label'               => __( 'Get Products', 'my-plugin' ),
            'description'         => __( 'Search the product catalog by keyword.', 'my-plugin' ),
            'category'            => 'my-plugin',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'query' => array( 'type' => 'string', 'description' => 'Search term' ),
                    'limit' => array( 'type' => 'integer', 'default' => 10 ),
                ),
                'required'   => array( 'query' ),
            ),
            'execute_callback'    => 'my_plugin_get_products',
            'permission_callback' => '__return_true',
            'meta'                => array( 'wmcp_visibility' => 'public' ),
        )
    );
} );
```

WebMCP for WordPress automatically picks up any registered ability and exposes it — no extra configuration needed.

### Visibility Control

```php
// Public: visible to all agents (logged-in or not)
'meta' => array( 'wmcp_visibility' => 'public' )

// Hidden: never exposed to agents, even if registered
'meta' => array( 'wmcp_visibility' => 'private' )
```

---

## REST API Endpoints

The plugin registers three endpoints under `/wp-json/webmcp/v1/`:

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/tools` | List all tools visible to the current user |
| `POST` | `/execute/{ability}` | Run a tool (requires auth + nonce) |
| `GET` | `/nonce` | Refresh the execution nonce |

The `/tools` endpoint supports:
- **Conditional requests** (`If-None-Match` / `ETag`) for efficient polling
- **`Cache-Control: private, max-age=300`** to reduce load
- **Public discovery mode** (opt-in) for unauthenticated tool listing

---

## Admin Settings

**Settings → WebMCP** provides:

- **Enable/disable** the bridge globally
- **Public tool discovery** — allow unauthenticated agents to list tools (execution still requires auth)
- **Per-tool toggle** — hide specific tools from discovery without unregistering them
- **Status panel** — HTTPS check, Abilities API availability, registered count

---

## Hooks & Filters

```php
// Allow/block a tool from appearing at all
add_filter( 'wmcp_expose_ability', function ( $expose, $name, $ability ) {
    return $name !== 'submit-comment'; // hide comment tool
}, 10, 3 );

// Customize the tool definition before it's sent to the browser
add_filter( 'wmcp_tool_definition', function ( $tool, $name, $ability ) {
    $tool['description'] .= ' Powered by Acme Corp.';
    return $tool;
}, 10, 3 );

// Block execution with context (user ID, input)
add_filter( 'wmcp_allow_execution', function ( $allow, $name, $input, $user_id ) {
    if ( $name === 'my-plugin/delete-data' && ! is_vip_user( $user_id ) ) {
        return new WP_Error( 'forbidden', 'VIP only.' );
    }
    return $allow;
}, 10, 4 );

// Adjust rate limits
add_filter( 'wmcp_rate_limit', fn() => 30 );          // requests per minute per user/tool
add_filter( 'wmcp_rate_limit_window', fn() => 60 );   // window in seconds

// Disable built-in tools
add_filter( 'wmcp_include_builtin_tools', '__return_false' );

// Conditionally load the bridge script
add_filter( 'wmcp_should_enqueue', fn() => is_front_page() );
```

---

## Security

- **HTTPS enforced** — bridge script does not load over HTTP
- **Nonce verification** on every execute request (`X-WP-Nonce` header)
- **Permission callbacks** re-evaluated at execution time (not just discovery)
- **Private visibility** flag prevents internal abilities from appearing
- **Admin allowlist** — site owner controls exactly which tools are exposed
- **Rate limiting** per user+tool pair plus global IP-based discovery limit
- **Input size cap** — 100 KB max payload (filterable)
- **Schema validation** — depth limit and `$ref` rejection to prevent injection
- **IP-based rate limiting** on tool discovery (REMOTE_ADDR only, no proxy header trust)

---

## Testing

50 PHPUnit integration tests run against a real WordPress 6.9 environment via [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/).

```bash
# Start the test environment
npx wp-env start

# Run the suite
npx wp-env run tests-cli \
  "bash -c 'cd /var/www/html/wp-content/plugins/webmcp-for-wordpress && WP_TESTS_DIR=/wordpress-phpunit ./vendor/bin/phpunit'"
```

Test coverage:
- `Ability_Bridge` — visibility filtering, permission checks, schema validation, filters
- `Builtin_Tools` — all four tools including edge cases and sanitization
- `REST_API` — all endpoints: auth, nonce, rate limiting, execution lifecycle
- `Rate_Limiter` — per-user and global ceiling enforcement

---

## Architecture

```
webmcp-for-wordpress/
├── webmcp-for-wordpress.php          # Bootstrap, version guard
├── includes/
│   ├── class-plugin.php       # Singleton wiring
│   ├── class-settings.php     # Options: enabled, discovery, exposed list
│   ├── class-ability-bridge.php  # WP_Ability → WebMCP tool definition
│   ├── class-builtin-tools.php   # 4 starter abilities
│   ├── class-rest-api.php     # /tools, /execute, /nonce endpoints
│   ├── class-rate-limiter.php # Transient-based rate limiting
│   └── class-admin-page.php   # Settings UI
├── assets/js/src/
│   └── webmcp-for-wordpress.js       # navigator.modelContext.registerTool() calls
└── tests/phpunit/             # 50 integration tests
```

---

## Roadmap

- [ ] MCP Adapter integration (expose tools to CLI/API agents, not just browser)
- [ ] WooCommerce tools (products, cart, checkout)
- [ ] BuddyPress / bbPress community tools
- [ ] Declarative WebMCP API support (HTML form population)
- [ ] Tool annotations (`readonly`, `destructive`, `idempotent`)
- [ ] WordPress.org plugin directory submission

---

## Contributing

PRs welcome. Please include tests for any new tools or behavior changes.

```bash
composer install
npx wp-env start
# make changes
npx wp-env run tests-cli "..."  # verify green
```

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) or [gnu.org/licenses/gpl-2.0](https://www.gnu.org/licenses/gpl-2.0.html).

Built by [Code Atlantic](https://code-atlantic.com).
