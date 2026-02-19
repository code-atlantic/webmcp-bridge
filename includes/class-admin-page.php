<?php
/**
 * Admin settings page renderer.
 *
 * @package WebMCP_Bridge
 */

namespace WebMCP_Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Settings → WebMCP admin page and registers its fields.
 */
class Admin_Page {

	/** @var Settings */
	private Settings $settings;

	/** @var Ability_Bridge */
	private Ability_Bridge $bridge;

	public function __construct( Settings $settings, Ability_Bridge $bridge ) {
		$this->settings = $settings;
		$this->bridge   = $bridge;
	}

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this->settings, 'register' ) );
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_notices', array( $this, 'https_notice' ) );
	}

	/**
	 * Add the settings page under Settings → WebMCP.
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'WebMCP Bridge', 'webmcp-bridge' ),
			__( 'WebMCP', 'webmcp-bridge' ),
			'manage_options',
			'webmcp-bridge',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Show a notice in the WordPress admin if the site is not HTTPS.
	 * Only shown to administrators.
	 */
	public function https_notice(): void {
		if ( is_ssl() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_webmcp-bridge' !== $screen->id ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'WebMCP Bridge: HTTPS required.', 'webmcp-bridge' ); ?></strong>
				<?php esc_html_e( 'Your site is not served over HTTPS. The WebMCP standard requires a secure context — the front-end bridge will not load until HTTPS is enabled.', 'webmcp-bridge' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Get all registered abilities for the exposed-tools list.
		$all_abilities = function_exists( 'wp_get_registered_abilities' )
			? wp_get_registered_abilities()
			: array();

		$exposed_tools = $this->settings->get_exposed_tools();
		$is_enabled    = $this->settings->is_enabled();
		$is_public     = $this->settings->is_discovery_public();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WebMCP Bridge', 'webmcp-bridge' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Allow AI agents visiting your site in Chrome 146+ to discover and use WordPress features as structured tools.', 'webmcp-bridge' ); ?>
				<?php if ( ! is_ssl() ) : ?>
					<br><strong style="color:#d63638;"><?php esc_html_e( '⚠ HTTPS is required for WebMCP to work. The front-end bridge is currently disabled.', 'webmcp-bridge' ); ?></strong>
				<?php endif; ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'wmcp_settings_group' ); ?>

				<table class="form-table" role="presentation">

					<!-- Global enable/disable -->
					<tr>
						<th scope="row">
							<label for="wmcp_enabled">
								<?php esc_html_e( 'Enable WebMCP Bridge', 'webmcp-bridge' ); ?>
							</label>
						</th>
						<td>
							<label>
								<input type="checkbox"
									name="<?php echo esc_attr( Settings::OPTION_ENABLED ); ?>"
									id="wmcp_enabled"
									value="1"
									<?php checked( $is_enabled ); ?>>
								<?php esc_html_e( 'Allow AI agents to use WordPress features as tools', 'webmcp-bridge' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When disabled, no WebMCP tools will be registered in the browser.', 'webmcp-bridge' ); ?>
							</p>
						</td>
					</tr>

					<!-- Public discovery toggle -->
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Tool Discovery', 'webmcp-bridge' ); ?>
						</th>
						<td>
							<label>
								<input type="checkbox"
									name="<?php echo esc_attr( Settings::OPTION_DISCOVERY_PUBLIC ); ?>"
									id="wmcp_discovery_public"
									value="1"
									<?php checked( $is_public ); ?>>
								<?php esc_html_e( 'Allow agents to discover available tools without logging in', 'webmcp-bridge' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When checked, tool names and descriptions are visible to any visitor. Execution still requires the appropriate permissions. Suitable for public content sites, e-commerce, and community forums.', 'webmcp-bridge' ); ?>
							</p>
						</td>
					</tr>

					<!-- Per-tool exposed list -->
					<?php if ( ! empty( $all_abilities ) ) : ?>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Exposed Tools', 'webmcp-bridge' ); ?>
						</th>
						<td>
							<p class="description" style="margin-bottom:8px;">
								<?php esc_html_e( 'Choose which tools agents can discover and use. Uncheck any tool to hide it completely.', 'webmcp-bridge' ); ?>
							</p>
							<fieldset>
								<?php foreach ( $all_abilities as $name => $ability ) :
									// Skip private tools — they should never appear in the UI.
									if ( ( $ability['wmcp_visibility'] ?? 'public' ) === 'private' ) {
										continue;
									}

									$label       = wp_strip_all_tags( $ability['label'] ?? $ability['description'] ?? $name );
									$description = wp_strip_all_tags( $ability['description'] ?? '' );

									// An empty exposed list means "all exposed" (first install).
									$is_checked = empty( $exposed_tools ) || in_array( $name, $exposed_tools, true );

									// Determine permission label.
									$perm_label = '';
									if ( isset( $ability['permission_callback'] ) ) {
										if ( '__return_true' === $ability['permission_callback'] ) {
											$perm_label = __( 'Public', 'webmcp-bridge' );
										} else {
											$perm_label = __( 'Requires login', 'webmcp-bridge' );
										}
									}
									?>
									<label style="display:block; margin-bottom:6px;">
										<input type="checkbox"
											name="<?php echo esc_attr( Settings::OPTION_EXPOSED_TOOLS ); ?>[]"
											value="<?php echo esc_attr( $name ); ?>"
											<?php checked( $is_checked ); ?>>
										<strong><?php echo esc_html( $label ); ?></strong>
										<?php if ( $perm_label ) : ?>
											<span style="color:#666; font-style:italic;">— <?php echo esc_html( $perm_label ); ?></span>
										<?php endif; ?>
										<?php if ( $description && $description !== $label ) : ?>
											<br><span class="description" style="margin-left:22px;"><?php echo esc_html( $description ); ?></span>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<?php endif; ?>

				</table>

				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Status', 'webmcp-bridge' ); ?></h2>
			<ul>
				<li>
					<?php esc_html_e( 'HTTPS:', 'webmcp-bridge' ); ?>
					<?php if ( is_ssl() ) : ?>
						<span style="color:#00a32a;">✓ <?php esc_html_e( 'Enabled', 'webmcp-bridge' ); ?></span>
					<?php else : ?>
						<span style="color:#d63638;">✗ <?php esc_html_e( 'Not enabled — WebMCP will not work', 'webmcp-bridge' ); ?></span>
					<?php endif; ?>
				</li>
				<li>
					<?php esc_html_e( 'WordPress Abilities API:', 'webmcp-bridge' ); ?>
					<?php if ( function_exists( 'wp_get_registered_abilities' ) ) : ?>
						<span style="color:#00a32a;">✓ <?php esc_html_e( 'Available', 'webmcp-bridge' ); ?></span>
					<?php else : ?>
						<span style="color:#d63638;">✗ <?php esc_html_e( 'Not available', 'webmcp-bridge' ); ?></span>
					<?php endif; ?>
				</li>
				<li>
					<?php
					$count = function_exists( 'wp_get_registered_abilities' )
						? count( wp_get_registered_abilities() )
						: 0;
					printf(
						/* translators: %d: number of registered abilities */
						esc_html__( 'Registered abilities: %d', 'webmcp-bridge' ),
						esc_html( $count )
					);
					?>
				</li>
				<li>
					<?php esc_html_e( 'Browser support: Chrome 146+ required for WebMCP.', 'webmcp-bridge' ); ?>
				</li>
			</ul>

			<p>
				<a href="https://github.com/code-atlantic/webmcp-bridge" target="_blank">
					<?php esc_html_e( 'Plugin documentation & source code →', 'webmcp-bridge' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
