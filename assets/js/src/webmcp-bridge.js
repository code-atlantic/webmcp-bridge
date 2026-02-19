/**
 * WebMCP Bridge — front-end imperative tool registration.
 *
 * Fetches registered WordPress Abilities from the REST API and registers
 * each one as a WebMCP tool via navigator.modelContext, making them available
 * to AI agents in Chrome 146+.
 */

( function () {
	'use strict';

	// Guard: only run in browsers that support the WebMCP API.
	if ( typeof navigator === 'undefined' || ! ( 'modelContext' in navigator ) ) {
		return;
	}

	// Guard: wmcpBridge config must be present (injected via wp_localize_script).
	if ( typeof wmcpBridge === 'undefined' ) {
		return;
	}

	const { toolsEndpoint, executeEndpoint, nonceEndpoint } = wmcpBridge;
	let currentNonce = wmcpBridge.nonce;

	// -------------------------------------------------------------------------
	// LocalStorage cache for tool definitions (24h TTL).
	// Nonces are NOT cached — they're returned fresh with each /tools response.
	// -------------------------------------------------------------------------

	const CACHE_KEY = 'wmcp_tools_cache';
	const CACHE_TTL = 24 * 60 * 60 * 1000; // 24 hours in ms

	function getCachedTools() {
		try {
			const raw = localStorage.getItem( CACHE_KEY );
			if ( ! raw ) return null;

			const { tools, etag, expiry } = JSON.parse( raw );
			if ( Date.now() > expiry ) {
				localStorage.removeItem( CACHE_KEY );
				return null;
			}
			return { tools, etag };
		} catch {
			return null;
		}
	}

	function setCachedTools( tools, etag ) {
		try {
			localStorage.setItem(
				CACHE_KEY,
				JSON.stringify( {
					tools,
					etag,
					expiry: Date.now() + CACHE_TTL,
				} )
			);
		} catch {
			// localStorage may be unavailable (private browsing, quota exceeded).
		}
	}

	// -------------------------------------------------------------------------
	// Nonce refresh — called on 403 responses.
	// -------------------------------------------------------------------------

	async function refreshNonce() {
		try {
			const response = await fetch( nonceEndpoint, {
				credentials: 'same-origin',
			} );
			if ( response.ok ) {
				const data = await response.json();
				currentNonce = data.nonce ?? currentNonce;
			}
		} catch {
			// Silently fail — the next execution attempt will hit a 403 and retry.
		}
	}

	// -------------------------------------------------------------------------
	// Fetch tools from the REST API, respecting ETag for cache validation.
	// -------------------------------------------------------------------------

	async function fetchTools() {
		const cached = getCachedTools();

		const headers = {
			'X-WP-Nonce': currentNonce,
		};

		if ( cached?.etag ) {
			headers[ 'If-None-Match' ] = `"${ cached.etag }"`;
		}

		let response;
		try {
			response = await fetch( toolsEndpoint, {
				headers,
				credentials: 'same-origin',
			} );
		} catch {
			// Network error — use cached tools if available.
			return cached?.tools ?? [];
		}

		// 304 Not Modified — cached tools are still valid.
		if ( response.status === 304 && cached?.tools ) {
			return cached.tools;
		}

		if ( ! response.ok ) {
			return cached?.tools ?? [];
		}

		const data = await response.json();
		const tools = data.tools ?? [];
		const etag  = response.headers.get( 'ETag' )?.replace( /"/g, '' ) ?? '';

		// Update the current nonce from the response.
		if ( data.nonce ) {
			currentNonce = data.nonce;
		}

		setCachedTools( tools, etag );
		return tools;
	}

	// -------------------------------------------------------------------------
	// Execute a tool via the REST API.
	// Auto-retries once on 403 (expired nonce).
	// -------------------------------------------------------------------------

	async function executeTool( toolName, input ) {
		const url = executeEndpoint + encodeURIComponent( toolName );

		async function doRequest() {
			return fetch( url, {
				method:      'POST',
				credentials: 'same-origin',
				headers:     {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   currentNonce,
				},
				body: JSON.stringify( input ),
			} );
		}

		let response = await doRequest();

		// On 403, refresh the nonce and retry once.
		if ( response.status === 403 ) {
			await refreshNonce();
			response = await doRequest();
		}

		if ( ! response.ok ) {
			let errorMessage = `HTTP ${ response.status }`;
			try {
				const errData = await response.json();
				errorMessage = errData.message ?? errorMessage;
			} catch { /* non-JSON body — use status code */ }
			throw new Error( `WebMCP Bridge: ${ errorMessage }` );
		}

		const data = await response.json();

		// Return the raw result — WebMCP spec execute() returns Promise<any>;
		// the browser passes the value directly to the agent.
		return data.result;
	}

	// -------------------------------------------------------------------------
	// Register all tools with navigator.modelContext.
	// -------------------------------------------------------------------------

	async function registerTools() {
		let tools;
		try {
			tools = await fetchTools();
		} catch {
			return;
		}

		if ( ! Array.isArray( tools ) || tools.length === 0 ) {
			return;
		}

		// Build the tool list in the format the spec requires.
		// execute receives (input, client) — client is ModelContextClient for
		// requestUserInteraction(); we don't use it since auth happens server-side.
		const mcpTools = tools
			.filter( ( tool ) => tool.name && tool.description )
			.map( ( tool ) => {
				const entry = {
					name:        tool.name,
					description: tool.description,
					inputSchema: tool.inputSchema ?? { type: 'object', properties: {} },
					execute:     async ( input /*, client */ ) => executeTool( tool.name, input ),
				};
				if ( tool.annotations ) {
					entry.annotations = tool.annotations;
				}
				return entry;
			} );

		if ( mcpTools.length === 0 ) {
			return;
		}

		// provideContext() atomically replaces the full tool set — safer than
		// looping registerTool() which throws on duplicate names.
		navigator.modelContext.provideContext( { tools: mcpTools } );
	}

	// -------------------------------------------------------------------------
	// Entry point.
	// -------------------------------------------------------------------------

	registerTools();
} )();
