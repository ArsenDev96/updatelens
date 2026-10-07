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
	 * Admin file of the former Tools → UpdateLens screen, redirected to the top-level screen.
	 */
	const LEGACY_PARENT = 'tools.php';

	/**
	 * Screen URL parameters kept by the legacy redirect (see src/admin/utils/route.ts).
	 */
	const ROUTE_PARAMS = array( 'analysis', 'paged' );

	/**
	 * Script handle for the admin app.
	 */
	const SCRIPT_HANDLE = 'updatelens-admin';

	/**
	 * DOM id the React app mounts into. Must match src/admin/main.tsx and the
	 * CSS scope in postcss.config.cjs.
	 */
	const ROOT_ID = 'updatelens-root';

	/**
	 * Vite entry point, relative to the plugin root.
	 */
	const ENTRY = 'src/admin/main.tsx';

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
		// Before WordPress checks access to the requested admin page (also on admin_menu).
		add_action( 'admin_menu', array( $this, 'redirect_legacy_url' ), 0 );
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
			__( 'UpdateLens', 'updatelens' ),
			Plugin::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			self::MENU_ICON
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
	 * Create the monitoring baseline if none is stored. Never throws.
	 *
	 * @return void
	 */
	public function ensure_baseline() {
		MonitoringBaseline::create()->ensure();
	}

	/**
	 * Send bookmarks of the former Tools → UpdateLens screen to the top-level
	 * screen, keeping the History page or report they point to.
	 *
	 * @return void
	 */
	public function redirect_legacy_url() {
		global $pagenow;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only redirect between screens; values are validated.
		if ( self::LEGACY_PARENT !== $pagenow || ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
			return;
		}

		$args = array( 'page' => self::SLUG );
		foreach ( self::ROUTE_PARAMS as $param ) {
			$value = isset( $_GET[ $param ] ) ? absint( $_GET[ $param ] ) : 0;
			if ( $value > 0 ) {
				$args[ $param ] = $value;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
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

		require_once UPDATELENS_DIR . 'libs/assets.php';

		$this->assets_loaded = \UpdateLens\Libs\Assets\enqueue_asset(
			UPDATELENS_DIR . 'assets/admin/dist',
			self::ENTRY,
			array(
				// WordPress-provided packages; the Vite build maps these imports to wp.* globals.
				'dependencies' => array( 'wp-api-fetch', 'wp-i18n' ),
				'handle'       => self::SCRIPT_HANDLE,
				'in-footer'    => true,
			)
		);

		if ( $this->assets_loaded ) {
			wp_set_script_translations( self::SCRIPT_HANDLE, 'updatelens' );
		}
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
					<p><?php esc_html_e( 'UpdateLens admin assets are missing. Run "npm run build" (or "npm run dev") in the plugin directory.', 'updatelens' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
