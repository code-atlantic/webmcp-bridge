# WebMCP Bridge — Plugin Plan (v4 — Panel Approved)

## Vision

A WordPress plugin that bridges WordPress Abilities to the WebMCP browser API (`navigator.modelContext`), making any WordPress site's capabilities automatically discoverable and invocable by AI agents visiting the site in a browser. Ships with built-in starter tools so it delivers value on day one.

**Plugin name:** `webmcp-bridge`
**Function prefix:** `wmcp_`
**REST namespace:** `webmcp/v1`
**Positioning:** Complementary to the WordPress MCP Adapter (CLI/API agents via MCP protocol) and wmcp.dev (declarative form annotations). This plugin handles imperative tool registration from Abilities + provides built-in starter tools.

---

## Architecture Overview

```
┌─────────────────────────────────────────────────┐
│  Browser (Chrome 146+)                          │
│  ┌───────────────────────────────────────────┐  │
│  │  AI Agent (Gemini, Claude, etc.)          │  │
│  │  ↕ navigator.modelContext                 │  │
│  │  ┌─────────────────────────────────────┐  │  │
│  │  │  WebMCP Tools                       │  │  │
│  │  │  - Built-in (search, posts, etc.)   │  │  │
│  │  │  - Plugin abilities (WooCommerce…)  │  │  │
│  │  └──────────────┬──────────────────────┘  │  │
│  └─────────────────┼────────────────────────┘  │
│                    │ fetch() / REST API          │
└────────────────────┼────────────────────────────┘
                     │
┌────────────────────┼────────────────────────────┐
│  WordPress Server  │                            │
│  ┌─────────────────▼──────────────────────┐     │
│  │  WebMCP Bridge REST Endpoints          │     │
│  │  GET  /wp-json/webmcp/v1/tools         │     │
│  │  POST /wp-json/webmcp/v1/execute/{name}│     │
│  │  GET  /wp-json/webmcp/v1/nonce         │     │
│  └─────────────────┬──────────────────────┘     │
│                    │                            │
│  ┌─────────────────▼──────────────────────┐     │
│  │  Built-in Tools + Abilities API        │     │
│  │  - webmcp/search-posts (built-in)      │     │
│  │  - webmcp/get-post (built-in)          │     │
│  │  - webmcp/get-categories (built-in)    │     │
│  │  - webmcp/submit-comment (built-in)    │     │
│  │  - core/* (WP core abilities)          │     │
│  │  - plugin/* (3rd-party abilities)      │     │
│  └────────────────────────────────────────┘     │
│                                                 │
│  ┌────────────────────────────────────────┐     │
│  │  MCP Adapter (optional, complementary) │     │
│  │  Handles CLI/API agents via MCP        │     │
│  └────────────────────────────────────────┘     │
└─────────────────────────────────────────────────┘
```

---

## How It Works

### 1. Server Side (PHP)

**Built-in Starter Tools**

The plugin registers 4 built-in tools that work out of the box — no other plugins needed:

| Tool | Description | Permission | Type |
|------|-------------|-----------|------|
| `webmcp/search-posts` | Search published posts by keyword | Public | Read |
| `webmcp/get-post` | Get a post by ID or slug | Public (published only) | Read |
| `webmcp/get-categories` | List categories with post counts | Public | Read |
| `webmcp/submit-comment` | Submit a comment on a post | Authenticated (`moderate_comments` or open comments setting) | Write |

These are registered as real WordPress Abilities via `wp_register_ability()` so they also appear in the MCP Adapter. Disableable via `wmcp_include_builtin_tools` filter (default: true).

**Tool Discovery Endpoint**
- `GET /wp-json/webmcp/v1/tools`
- Default: requires authentication. Toggle in Settings to allow public discovery.
- Returns tools as WebMCP definitions: `{ name, description, inputSchema }`
- Only returns tools that: (a) are in the admin's exposed-tools list, (b) pass `permission_callback` for current user, (c) have `wmcp_visibility !== 'private'`
- Descriptions sanitized via `wp_strip_all_tags()` before output
- HTTP caching: `Cache-Control: private, max-age=300` + `Vary: Cookie` + `ETag`
- ETag computed as `md5(json_encode($tools))` — changes when abilities are added/removed/modified
- Server-side object cache via `wp_cache_get/set` (1 hour TTL, keyed by user ID)
- Plugin hooks `activate_plugin`/`deactivate_plugin` to invalidate cache
- Discovery rate limiting: 100 requests/min per IP (separate from execution rate limit)

**Tool Execution Endpoint**
- `POST /wp-json/webmcp/v1/execute/{ability-name}`
- Requires authentication (logged-in users only). Anonymous execution always blocked.
- WordPress cookie auth + nonce verification (nonce scope: `wmcp_execute`)
- Nonce TTL: WordPress default (~12-24h). JS fetches fresh nonce via lightweight `GET /nonce` endpoint, not cached with tool definitions.
- Pre-execution permission re-check via ability's `permission_callback`
- Input validated against ability's `inputSchema` (strict JSON Schema; reject unknown properties)
- Ability's `inputSchema` validated at bridge time: max depth 5, no circular refs, no unsupported `$ref`
- Input size limit: 100KB max payload (filterable via `wmcp_max_input_size`)
- Rate limiting: 30 executions/min per user globally. Filterable per-ability via `wmcp_rate_limit`. Hard global ceiling: 60/min via `wmcp_rate_limit_global_ceiling` filter. Returns 429 with `Retry-After: 60` header.
- Output sanitized: descriptions stripped of HTML, result validated if output schema exists
- HTTP error responses with structured JSON:
  - 400: Invalid input (schema validation failed, payload too large, depth exceeded)
  - 403: Permission denied (nonce invalid, capability check failed, `wmcp_allow_execution` returned WP_Error)
  - 404: Ability not found or not in exposed-tools list
  - 429: Rate limited
  - 500: Execution error (ability's execute_callback threw/returned WP_Error)

**Settings (via WordPress Settings API)**
- Admin page: Settings → WebMCP (requires `manage_options`)
- Uses `register_setting()`, `add_settings_section()`, `add_settings_field()`
- Settings UI:

```
[ x ] Allow AI agents to use WordPress features as tools
      (If disabled, no WebMCP tools will be registered)

⚠ Your site is not HTTPS. WebMCP requires a secure context.
  (Only shown if !is_ssl())

Exposed Tools:
  ☑ Search posts          (Public)
  ☑ Get post              (Public)
  ☑ Get categories        (Public)
  ☑ Submit comment        (Requires login)
  ☐ Get site info         (Core — requires login)
  ☐ Get user info         (Core — requires login)
  ☐ Get environment info  (Core — admin only)
  [Other plugin abilities appear here automatically]

Tool Discovery:
  ☐ Allow agents to find tools without logging in
    (Unchecked by default. When checked, tool names
     and descriptions are visible to any visitor.
     Execution still requires permission.)
```

- Option names: `wmcp_enabled` (bool), `wmcp_exposed_tools` (array of tool names), `wmcp_discovery_public` (bool)

### 2. Client Side (JavaScript)

**Imperative Tool Registration**
- Enqueued on front-end only when: enabled in settings AND `is_ssl()`
- Conditional loading: `wmcp_should_enqueue` filter lets plugins/themes disable per page
- Feature detection: `if (!('modelContext' in navigator)) return;`
- Fetches tool definitions from `/wp-json/webmcp/v1/tools` (with nonce in `X-WP-Nonce` header)
- Caches **tool definitions** in localStorage with 24h TTL + cache-bust key from ETag. ETag changes when abilities are added/removed/modified. Plugin hooks `activate_plugin`/`deactivate_plugin` to invalidate server-side cache, causing next request to return fresh ETag.
- Nonces are **NOT cached** — the `/tools` endpoint returns a single nonce (`wmcp_execute`) valid for all execute calls. On nonce rotation (~12h), `GET /wp-json/webmcp/v1/nonce` returns a fresh one.
- On 403 response (expired nonce), auto-refreshes nonce and retries once
- Registers each tool via `navigator.modelContext.registerTool()`:
  - `name`: tool identifier
  - `description`: tool description (plain text, sanitized server-side)
  - `inputSchema`: tool's JSON Schema
  - `execute`: async callback → `POST /wp-json/webmcp/v1/execute/{name}` with nonce
- Minimal footprint: ~3KB gzipped, no dependencies, `defer` loading

---

## File Structure

```
webmcp-bridge/
├── webmcp-bridge.php                # Plugin bootstrap
├── composer.json                    # Autoloading config
├── package.json                     # JS build (wp-scripts)
├── readme.txt                       # WordPress.org readme
├── uninstall.php                    # Cleanup on uninstall
│
├── includes/
│   ├── class-plugin.php             # Main plugin class, hooks, init
│   ├── class-rest-api.php           # REST endpoint registration + handlers
│   ├── class-ability-bridge.php     # Abilities → WebMCP tool converter
│   ├── class-builtin-tools.php      # Built-in starter tools (search, post, categories, comment)
│   ├── class-settings.php           # Settings registration (Settings API)
│   ├── class-admin-page.php         # Settings page renderer
│   └── class-rate-limiter.php       # Per-user + per-IP rate limiting
│
├── assets/
│   └── js/
│       ├── src/
│       │   └── webmcp-bridge.js     # Imperative tool registration
│       └── build/                   # Compiled output (wp-scripts)
│
└── tests/
    └── phpunit/
        ├── bootstrap.php
        ├── test-rest-api.php
        ├── test-ability-bridge.php
        ├── test-builtin-tools.php
        └── test-rate-limiter.php
```

---

## Built-in Tools Detail

### `webmcp/search-posts`
```php
wp_register_ability( 'webmcp/search-posts', [
    'label'       => 'Search Posts',
    'description' => 'Search published posts by keyword',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'query' => [ 'type' => 'string', 'description' => 'Search query' ],
            'count' => [ 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50 ],
        ],
        'required' => [ 'query' ],
    ],
    'outputSchema' => [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'id'      => [ 'type' => 'integer' ],
                'title'   => [ 'type' => 'string' ],
                'excerpt' => [ 'type' => 'string' ],
                'url'     => [ 'type' => 'string' ],
                'date'    => [ 'type' => 'string' ],
            ],
        ],
    ],
    'execute_callback'    => [ Builtin_Tools::class, 'search_posts' ],
    'permission_callback' => '__return_true',  // Public — published posts are public
    'wmcp_visibility'     => 'public',
] );
```

### `webmcp/get-post`
- Input: `{ id?: int, slug?: string }` (one required)
- Output: `{ id, title, content, excerpt, categories, tags, date, author, url }`
- Permission: `__return_true` for published posts; `read` cap for drafts/private
- Only returns published posts to unauthenticated users

### `webmcp/get-categories`
- Input: none (empty object)
- Output: array of `{ id, name, slug, description, count, url }`
- Permission: `__return_true`

### `webmcp/submit-comment`
- Input: `{ post_id: int, content: string, author_name?: string, author_email?: string }`
- Output: `{ comment_id: int, status: 'approved'|'pending'|'spam', message: string }`
- Permission: respects WordPress comment settings (open/closed per post, require login setting)
- Uses `wp_new_comment()` with standard sanitization and spam checks

---

## Key Design Decisions

### 1. Abilities API as Source of Truth
Bridge directly to WordPress Abilities API. Any plugin that registers an ability automatically gets a WebMCP tool — zero additional work for plugin authors.

### 2. Built-in Starter Tools
Ship 4 tools that work immediately: search posts, get post, get categories, submit comment. These bootstrap the ecosystem and prove the pattern. Plugin authors see how it works, agents have something useful to do on day one.

### 3. Imperative-Only for MVP
Focus on `navigator.modelContext.registerTool()`. Declarative form annotation handled by wmcp.dev or deferred to Phase 2.

### 4. Per-Tool Visibility Control
Three layers of visibility control:
- **Admin UI**: Checkboxes in Settings for each discovered tool (stored in `wmcp_exposed_tools`)
- **Ability flag**: `wmcp_visibility => 'private'` hides a tool from discovery entirely
- **Permission callback**: Standard WordPress capabilities gate execution
- **Filter**: `wmcp_expose_ability` for programmatic control

Visibility is a discovery concern. Executability is a security concern. A tool can be discoverable but not executable (agent sees it, can't run it). A tool can be hidden entirely via `wmcp_visibility: 'private'`.

### 5. Discovery: Authenticated by Default, Public Opt-in
Tool discovery requires login by default. Site owners can enable public discovery via a visible Settings toggle. Even with public discovery, execution always requires authentication + permission checks.

Public discovery is appropriate for: public content sites, e-commerce, community forums.
Keep private for: intranets, admin-heavy sites, sites with sensitive tool descriptions.

### 6. Progressive Enhancement
- No `navigator.modelContext`? JS no-ops silently.
- No HTTPS? Plugin disables front-end JS, shows admin warning.
- No Abilities API (WP < 6.9)? Checked in `register_activation_hook()` — prevents activation with admin notice. Also checked in `plugins_loaded` as safety net.

### 7. Multisite
- Phase 1: Single-site only. `manage_options` capability controls settings access.
- Noted for Phase 3: Per-site toggles, `manage_network_options` for network-wide config.

### 8. Uninstall Cleanup
- `uninstall.php` deletes: `wmcp_enabled`, `wmcp_exposed_tools`, `wmcp_discovery_public` options, all `wmcp_*` transients, scoped object cache keys.
- No persistent logs to clean (audit action is fire-and-forget).

### 9. No `wp_` Prefix
All functions, hooks, options, and CSS classes use `wmcp_` prefix to avoid confusion with WordPress core.

---

## Security Model

1. **Authentication**: WordPress cookie auth + nonce verification. Nonce scope: `wmcp_execute`. Fresh nonce provided with tool discovery response.
2. **Authorization**: Triple-checked — (a) admin's exposed-tools list, (b) `wmcp_visibility` flag, (c) ability's `permission_callback` at both discovery AND execution.
3. **Input Validation**: Strict JSON Schema (reject unknown properties). Input size: 100KB. JSON depth: 5 levels. Abilities without `inputSchema` get empty-object schema.
4. **Output Sanitization**: Descriptions: `wp_strip_all_tags()`. Results: validated against `outputSchema` if defined; `additionalProperties: false` enforced.
5. **Rate Limiting — Execution**: Per-user via object cache. 30/min default, 60/min hard ceiling. Filterable per-ability. Returns 429 + `Retry-After`.
6. **Rate Limiting — Discovery**: Per-IP, 100/min. Prevents reconnaissance enumeration on public discovery endpoints.
7. **HTTPS Required**: Front-end JS only on HTTPS. Admin warning if not.
8. **CORS**: No custom headers. Same-origin policy applies naturally. Document: "Tools only invokable by agents on this site's origin."
9. **Audit Logging**: `wmcp_tool_executed` action: ability name + user ID + success bool only. No raw input/output. Error codes only (not messages — may contain PII).
10. **Schema Validation**: `inputSchema` validated at bridge time: max depth 5, no circular `$ref`. Malformed schemas excluded with `_doing_it_wrong()`.
11. **Per-Tool Visibility**: `wmcp_visibility: 'private'` hides tools from all discovery (even authenticated). Admin UI checkboxes control which tools are exposed. Permission callbacks control execution.

---

## Hooks & Filters

```php
// Control which abilities are exposed as WebMCP tools
// Return false to hide. Checked after admin UI allowlist.
apply_filters('wmcp_expose_ability', bool $expose, string $ability_name, array $ability);

// Modify tool definition before it's sent to the browser
apply_filters('wmcp_tool_definition', array $tool, string $ability_name);

// Control rate limit per ability (execution)
apply_filters('wmcp_rate_limit', int $limit, string $ability_name, int $user_id);

// Hard ceiling on total executions per user per minute
apply_filters('wmcp_rate_limit_global_ceiling', int $ceiling); // default: 60

// Control discovery rate limit per IP
apply_filters('wmcp_discovery_rate_limit', int $limit, string $ip); // default: 100

// Control whether tool discovery requires authentication
// Default: true. Admin UI toggle overrides. Filter overrides admin UI.
apply_filters('wmcp_tools_require_auth', bool $require_auth);

// Control whether front-end JS is enqueued on this page
apply_filters('wmcp_should_enqueue', bool $should_enqueue);

// Pre-execution filter — last chance to block execution
apply_filters('wmcp_allow_execution', true|WP_Error, string $ability_name, array $input, int $user_id);

// Control whether built-in tools are registered
apply_filters('wmcp_include_builtin_tools', bool $include); // default: true

// Action fired after every tool execution (for logging/analytics)
do_action('wmcp_tool_executed', string $ability_name, int $user_id, bool $success);
```

---

## Phase 1 Scope (MVP)

1. **Built-in starter tools**: search-posts, get-post, get-categories, submit-comment
2. **Ability bridge**: Fetch all registered abilities → merge with built-ins → expose as WebMCP tools
3. **REST endpoints**: `GET /tools` (discovery) + `POST /execute/{name}` (execution) + `GET /nonce` (refresh)
4. **Per-tool visibility**: Admin UI checkboxes + `wmcp_visibility` ability flag + `wmcp_expose_ability` filter
5. **Discovery toggle**: Authenticated by default, public opt-in via Settings
6. **Auth + validation**: Cookie auth, nonce, JSON Schema validation, rate limiting (execution + discovery)
7. **Settings page**: Enable/disable toggle, HTTPS warning, exposed tools checkboxes, discovery toggle
8. **Feature detection**: Graceful no-op on unsupported browsers or non-HTTPS
9. **Output sanitization**: Strip HTML from descriptions, validate results
10. **Audit hook**: `wmcp_tool_executed` action
11. **Uninstall cleanup**: `uninstall.php`

### Phase 2 (Post-MVP)

1. **`.well-known/webmcp.json` manifest**: Pre-visit discovery (names + visibility only, no schemas). Disabled by default, opt-in via Advanced settings.
2. **Declarative form annotations**: Auto-annotate WP core forms (search, login, comments)
3. **Custom tool registration**: `wmcp_register_tool()` for non-ability tools
4. **Analytics dashboard**: Tool usage tracking, agent interaction stats
5. **Site Health checks**: HTTPS, WP version, Abilities API presence
6. **`requestUserInteraction()`**: Tools that need user confirmation before executing

### Phase 3 (Future)

1. **Tool categories/namespaces**: Group tools for agent discovery
2. **SSE streaming**: Long-running tool results
3. **Multi-site support**: Network-wide tool configuration
4. **WooCommerce integration**: Auto-annotate product/cart/checkout forms
5. **Schema.org integration**: Structured data for tool discovery

---

## Compatibility

- **Requires**: WordPress 6.9+ (Abilities API)
- **Browser**: Chrome 146+ (WebMCP standard). Other browsers: plugin loads but does nothing (safe).
- **WP < 6.9**: Plugin refuses activation with admin notice.
- **Optional**: MCP Adapter (complementary, for CLI/API agents)
- **Coexists with**: wmcp.dev (handles form annotations; this plugin handles abilities)
- **PHP**: 8.0+
- **No external Composer dependencies** (autoloading only)
