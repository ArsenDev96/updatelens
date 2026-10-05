<?php
/**
 * UpdateLens admin screen.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Admin;

use UpdateLens\Core\Plugin;
use UpdateLens\Storage\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Tools → UpdateLens screen and loads the React admin app on it.
 */
final class AdminPage {

	/**
	 * Admin page slug.
	 */
	const SLUG = 'updatelens';

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
	 * Hook suffix returned by add_management_page().
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
	 * Add the page under the Tools menu.
	 *
	 * @return void
	 */
	public function add_page() {
		$this->hook_suffix = add_management_page(
			__( 'UpdateLens', 'updatelens' ),
			__( 'UpdateLens', 'updatelens' ),
			Plugin::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);

		// Recreate the analyses table if it went missing. Checked only when
		// this screen loads, not on every request.
		if ( $this->hook_suffix && ! is_multisite() ) {
			add_action( 'load-' . $this->hook_suffix, array( Schema::class, 'repair' ) );
		}
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
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'updatelens' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<hr class="wp-header-end">
			<?php if ( $this->assets_loaded ) : ?>
				<div id="<?php echo esc_attr( self::ROOT_ID ); ?>"></div>
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
