<?php
/**
 * Plugin Name: UpdateLens Vite dev server (development only)
 * Description: Loads the UpdateLens admin app from the running Vite dev server (`npm run dev`) with hot module replacement. Development only; never part of the plugin release.
 *
 * Install as a must-use plugin of a local development site (`npm run dev:server`
 * mounts it into WordPress Playground). It does nothing unless
 * `assets/admin/dist/vite-dev-server.json`, written by `npm run dev`, exists.
 * Run `npm run build` once first: the plugin registers the admin app from the
 * production manifest, and this file only points it at the dev server.
 *
 * @package UpdateLens
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_enqueue_scripts', 'updatelens_dev_use_vite_server', 20 );

/**
 * Point the registered admin app at the Vite dev server.
 *
 * @return void
 */
function updatelens_dev_use_vite_server() {
	if ( ! defined( 'UPDATELENS_DIR' ) || ! wp_script_is( 'updatelens-admin', 'registered' ) ) {
		return;
	}

	$marker = UPDATELENS_DIR . 'assets/admin/dist/vite-dev-server.json';
	if ( ! is_readable( $marker ) ) {
		return;
	}
	$server = wp_json_file_decode( $marker, array( 'associative' => true ) );
	if ( ! is_array( $server ) || empty( $server['origin'] ) || ! is_string( $server['origin'] ) ) {
		return;
	}
	$origin = untrailingslashit( esc_url_raw( $server['origin'] ) );

	$app      = wp_scripts()->registered['updatelens-admin'];
	$app->src = $origin . '/src/admin/main.tsx';
	$app->ver = null;

	// The dev server injects the CSS through JavaScript.
	foreach ( wp_styles()->queue as $handle ) {
		if ( 0 === strpos( $handle, 'updatelens-admin-' ) ) {
			wp_dequeue_style( $handle );
		}
	}

	// Vite client and React Fast Refresh preamble, before the app's module script.
	add_action(
		'admin_print_footer_scripts',
		static function () use ( $origin ) {
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Development only: a module script that must run before the app's.
			printf( '<script type="module" src="%s"></script>' . "\n", esc_url( $origin . '/@vite/client' ) );
			printf(
				'<script type="module">%s</script>' . "\n",
				'import RefreshRuntime from ' . wp_json_encode( $origin . '/@react-refresh' ) . ';'
				. 'RefreshRuntime.injectIntoGlobalHook(window);'
				. 'window.$RefreshReg$ = () => {};'
				. 'window.$RefreshSig$ = () => (type) => type;'
				. 'window.__vite_plugin_react_preamble_installed__ = true;'
			);
		},
		1
	);
}
