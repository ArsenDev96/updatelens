<?php
/**
 * UpdateLens admin screen.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Admin;

use UpdateLens\Baseline\MonitoringBaseline;
use UpdateLens\Core\Plugin;
use UpdateLens\Storage\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level UpdateLens screen and, on single sites, its two
 * entries (Update History, Settings), and loads the React admin app on them.
 */
final class AdminPage {

	/**
	 * Admin page slug (`admin.php?page=updatelens`).
	 */
	const SLUG = 'updatelens';

	/**
	 * Settings screen slug (`admin.php?page=updatelens-settings`).
	 */
	const SETTINGS_SLUG = 'updatelens-settings';

	/**
	 * Menu icon: a built-in Dashicon (an eye, for observing).
	 */
	const MENU_ICON = 'dashicons-visibility';

	/**
	 * Menu position: directly below Plugins (65), above Users (70). WordPress
	 * sorts float positions between them and resolves collisions itself.
	 */
	const MENU_POSITION = 65.5;

	/**
	 * DOM id the React app mounts into. Must match src/admin/main.tsx and the
	 * CSS scope in postcss.config.cjs.
	 */
	const ROOT_ID = 'updatelens-root';

	/**
	 * Hook suffix returned by add_menu_page().
	 *
	 * @var string|false
	 */
	private $hook_suffix = false;

	/**
	 * Hook suffix of the Settings screen, or false. WordPress derives it
	 * from the top-level menu title, so it changes with the new-reports
	 * count and translations (e.g. `updatelens-3-3-new-updatelens-reports_page_updatelens-settings`):
	 * compare with this value, never with a literal.
	 *
	 * @var string|false
	 */
	private $settings_hook_suffix = false;

	/**
	 * Whether the admin app assets were enqueued successfully.
	 *
	 * @var bool
	 */
	private $assets_loaded = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the top-level UpdateLens menu page and, on single sites, its
	 * Settings page (the site check opt-in; site checks are single site only).
	 * The first entry keeps the top-level page under the name "Update History".
	 *
	 * @return void
	 */
	public function add_page() {
		$this->hook_suffix = add_menu_page(
			__( 'UpdateLens', 'updatelens' ),
			__( 'UpdateLens', 'updatelens' ) . UnreadReports::menu_count( $this->unread_count() ),
			Plugin::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			self::MENU_ICON,
			self::MENU_POSITION
		);

		if ( $this->hook_suffix && ! is_multisite() ) {
			add_submenu_page( self::SLUG, __( 'UpdateLens', 'updatelens' ), __( 'Update History', 'updatelens' ), Plugin::CAPABILITY, self::SLUG );
			$this->settings_hook_suffix = add_submenu_page(
				self::SLUG,
				__( 'UpdateLens Settings', 'updatelens' ),
				__( 'Settings', 'updatelens' ),
				Plugin::CAPABILITY,
				self::SETTINGS_SLUG,
				array( $this, 'render_settings' )
			);

			// Recreate the analyses table if it went missing, and create the
			// monitoring baseline if activation did not (e.g. sites upgraded from a
			// version without it). Checked only when the History screen loads.
			add_action( 'load-' . $this->hook_suffix, array( Schema::class, 'repair' ) );
			add_action( 'load-' . $this->hook_suffix, array( $this, 'ensure_baseline' ) );
		}
	}

	/**
	 * New reports since the current user's last visit, for the menu count.
	 * Opening the UpdateLens screen marks every existing report as seen first,
	 * so the count is gone on that screen. Only for users who can open it;
	 * single sites only. Database errors never reach the page.
	 *
	 * @return int
	 */
	private function unread_count() {
		global $wpdb, $plugin_page;

		if ( is_multisite() || ! current_user_can( Plugin::CAPABILITY ) ) {
			return 0;
		}

		$unread      = UnreadReports::create();
		$user_id     = get_current_user_id();
		$show_errors = $wpdb->hide_errors();
		try {
			if ( self::SLUG === $plugin_page ) {
				$unread->mark_seen( $user_id );
			}

			return $unread->count_for_user( $user_id );
		} finally {
			$wpdb->show_errors( $show_errors );
		}
	}

	/**
	 * Create the monitoring baseline if none is stored. Never throws.
	 *
	 * @return void
	 */
	public function ensure_baseline() {
		MonitoringBaseline::create()->ensure();
	}

	/**
	 * Enqueue the admin app, only on the UpdateLens screens.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array_filter( array( $this->hook_suffix, $this->settings_hook_suffix ) ), true ) ) {
			return;
		}

		$this->assets_loaded = AdminAssets::enqueue();
	}

	/**
	 * Render the History and report screen. React mounts into the root element.
	 *
	 * @return void
	 */
	public function render() {
		$this->render_shell( 'reports' );
	}

	/**
	 * Render the Settings screen.
	 *
	 * @return void
	 */
	public function render_settings() {
		$this->render_shell( 'settings' );
	}

	/**
	 * Render the page shell for a view of the app (`data-view`).
	 *
	 * The Plugins screen URL is passed to the app only for users who can open it.
	 *
	 * @param string $view `reports` or `settings`.
	 * @return void
	 */
	private function render_shell( $view ) {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'updatelens' ) );
		}
		$plugins_url = current_user_can( 'activate_plugins' ) ? admin_url( 'plugins.php' ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'UpdateLens', 'updatelens' ); ?></h1>
			<hr class="wp-header-end">
			<?php if ( $this->assets_loaded ) : ?>
				<div id="<?php echo esc_attr( self::ROOT_ID ); ?>" data-view="<?php echo esc_attr( $view ); ?>" data-plugins-url="<?php echo esc_url( $plugins_url ); ?>"></div>
				<noscript>
					<p><?php esc_html_e( 'UpdateLens requires JavaScript.', 'updatelens' ); ?></p>
				</noscript>
			<?php else : ?>
				<div class="notice notice-error inline">
					<p><?php esc_html_e( 'UpdateLens cannot display this screen because some of its files are missing. Please reinstall the plugin.', 'updatelens' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
