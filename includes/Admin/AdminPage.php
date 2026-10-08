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
 * Registers the top-level UpdateLens screen and loads the React admin app on it.
 */
final class AdminPage {

	/**
	 * Admin page slug (`admin.php?page=updatelens`).
	 */
	const SLUG = 'updatelens';

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
	 * Add the top-level UpdateLens menu page.
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

		// Recreate the analyses table if it went missing, and create the
		// monitoring baseline if activation did not (e.g. sites upgraded from a
		// version without it). Checked only when this screen loads.
		if ( $this->hook_suffix && ! is_multisite() ) {
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
	 * Enqueue the admin app, only on the UpdateLens screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$this->assets_loaded = AdminAssets::enqueue();
	}

	/**
	 * Render the page shell. React mounts into the root element.
	 *
	 * The Plugins screen URL is passed to the app only for users who can open it.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'updatelens' ) );
		}
		$plugins_url = current_user_can( 'activate_plugins' ) ? admin_url( 'plugins.php' ) : '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<hr class="wp-header-end">
			<?php if ( $this->assets_loaded ) : ?>
				<div id="<?php echo esc_attr( self::ROOT_ID ); ?>" data-plugins-url="<?php echo esc_url( $plugins_url ); ?>"></div>
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
