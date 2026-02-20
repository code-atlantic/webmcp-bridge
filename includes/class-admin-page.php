<?php
/**
 * Admin settings page renderer.
 *
 * @package WebMCP
 */

namespace WebMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Settings → WebMCP admin page and registers its fields.
 */
class Admin_Page {

	/**
	 * Plugin settings instance.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Ability bridge instance.
	 *
	 * @var Ability_Bridge
	 */
	private Ability_Bridge $bridge;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Plugin settings.
	 * @param Ability_Bridge $bridge   Ability bridge.
	 */
	public function __construct( Settings $settings, Ability_Bridge $bridge ) {
		$this->settings = $settings;
		$this->bridge   = $bridge;
	}

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this->settings, 'register' ] );
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_notices', [ $this, 'https_notice' ] );
	}

	/**
	 * Add the settings page under Settings → WebMCP.
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'WebMCP Abilities for WordPress', 'webmcp-abilities' ),
			__( 'WebMCP', 'webmcp-abilities' ),
			'manage_options',
			'webmcp-abilities',
			[ $this, 'render_page' ]
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
		if ( ! $screen || 'settings_page_webmcp-abilities' !== $screen->id ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'WebMCP Abilities: HTTPS required.', 'webmcp-abilities' ); ?></strong>
				<?php esc_html_e( 'Your site is not served over HTTPS. The WebMCP standard requires a secure context — the front-end bridge will not load until HTTPS is enabled.', 'webmcp-abilities' ); ?>
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
		$all_abilities = function_exists( 'wp_get_abilities' )
			? wp_get_abilities()
			: [];

		$exposed_tools = $this->settings->get_exposed_tools();
		$is_enabled    = $this->settings->is_enabled();
		$is_public     = $this->settings->is_discovery_public();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WebMCP Abilities', 'webmcp-abilities' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Allow AI agents visiting your site in Chrome 146+ to discover and use WordPress features as structured tools.', 'webmcp-abilities' ); ?>
				<?php if ( ! is_ssl() ) : ?>
					<br><strong style="color:#d63638;"><?php esc_html_e( '⚠ HTTPS is required for WebMCP to work. The front-end bridge is currently disabled.', 'webmcp-abilities' ); ?></strong>
				<?php endif; ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'wmcp_settings_group' ); ?>

				<table class="form-table" role="presentation">

					<!-- Global enable/disable -->
					<tr>
						<th scope="row">
							<label for="wmcp_enabled">
								<?php esc_html_e( 'Enable WebMCP Abilities', 'webmcp-abilities' ); ?>
							</label>
						</th>
						<td>
							<label>
								<input type="checkbox"
									name="<?php echo esc_attr( Settings::OPTION_ENABLED ); ?>"
									id="wmcp_enabled"
									value="1"
									<?php checked( $is_enabled ); ?>>
								<?php esc_html_e( 'Allow AI agents to use WordPress features as tools', 'webmcp-abilities' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When disabled, no WebMCP tools will be registered in the browser.', 'webmcp-abilities' ); ?>
							</p>
						</td>
					</tr>

					<!-- Public discovery toggle -->
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Tool Discovery', 'webmcp-abilities' ); ?>
						</th>
						<td>
							<label>
								<input type="checkbox"
									name="<?php echo esc_attr( Settings::OPTION_DISCOVERY_PUBLIC ); ?>"
									id="wmcp_discovery_public"
									value="1"
									<?php checked( $is_public ); ?>>
								<?php esc_html_e( 'Allow agents to discover available tools without logging in', 'webmcp-abilities' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When checked, tool names and descriptions are visible to any visitor. Execution still requires the appropriate permissions. Suitable for public content sites, e-commerce, and community forums.', 'webmcp-abilities' ); ?>
							</p>
						</td>
					</tr>

					<!-- Per-tool exposed list -->
					<?php if ( ! empty( $all_abilities ) ) : ?>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Exposed Tools', 'webmcp-abilities' ); ?>
						</th>
						<td>
							<p class="description" style="margin-bottom:8px;">
								<?php esc_html_e( 'Choose which tools agents can discover and use. Uncheck any tool to hide it completely.', 'webmcp-abilities' ); ?>
							</p>
							<fieldset>
								<?php
								foreach ( $all_abilities as $name => $ability ) :
									// Skip private tools — they should never appear in the UI.
									if ( 'private' === $ability->get_meta_item( 'wmcp_visibility', 'public' ) ) {
										continue;
									}

									$label       = wp_strip_all_tags( $ability->get_label() );
									$description = wp_strip_all_tags( $ability->get_description() );

									$is_checked = in_array( $name, $exposed_tools, true );

									// Determine permission label from meta visibility.
									$perm_label = ( 'public' === $ability->get_meta_item( 'wmcp_visibility', 'public' ) )
										? __( 'Public', 'webmcp-abilities' )
										: __( 'Requires login', 'webmcp-abilities' );
									?>
									<label style="display:block; margin-bottom:6px;">
										<input type="checkbox"
											name="<?php echo esc_attr( Settings::OPTION_EXPOSED_TOOLS ); ?>[]"
											value="<?php echo esc_attr( $name ); ?>"
											<?php checked( $is_checked ); ?>>
										<strong><?php echo esc_html( $label ); ?></strong>
										<span style="color:#666; font-style:italic;">— <?php echo esc_html( $perm_label ); ?></span>
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
			<h2><?php esc_html_e( 'Status', 'webmcp-abilities' ); ?></h2>
			<ul>
				<li>
					<?php esc_html_e( 'HTTPS:', 'webmcp-abilities' ); ?>
					<?php if ( is_ssl() ) : ?>
						<span style="color:#00a32a;">✓ <?php esc_html_e( 'Enabled', 'webmcp-abilities' ); ?></span>
					<?php else : ?>
						<span style="color:#d63638;">✗ <?php esc_html_e( 'Not enabled — WebMCP will not work', 'webmcp-abilities' ); ?></span>
					<?php endif; ?>
				</li>
				<li>
					<?php esc_html_e( 'WordPress Abilities API:', 'webmcp-abilities' ); ?>
					<?php if ( function_exists( 'wp_get_abilities' ) ) : ?>
						<span style="color:#00a32a;">✓ <?php esc_html_e( 'Available', 'webmcp-abilities' ); ?></span>
					<?php else : ?>
						<span style="color:#d63638;">✗ <?php esc_html_e( 'Not available', 'webmcp-abilities' ); ?></span>
					<?php endif; ?>
				</li>
				<li>
					<?php
					$count = function_exists( 'wp_get_abilities' )
						? count( wp_get_abilities() )
						: 0;
					printf(
						/* translators: %d: number of registered abilities */
						esc_html__( 'Registered abilities: %d', 'webmcp-abilities' ),
						esc_html( $count )
					);
					?>
				</li>
				<li>
					<?php esc_html_e( 'Browser support: Chrome 146+ required for WebMCP.', 'webmcp-abilities' ); ?>
				</li>
			</ul>

			<p>
				<a href="https://github.com/code-atlantic/webmcp-abilities" target="_blank">
					<?php esc_html_e( 'Plugin documentation & source code →', 'webmcp-abilities' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
