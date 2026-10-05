import { fileURLToPath, URL } from 'node:url';
import { v4wp } from '@kucrut/vite-for-wp';
import react from '@vitejs/plugin-react';
import externalGlobals from 'rollup-plugin-external-globals';
import { defineConfig, type Plugin } from 'vite';
import externals from 'vite-plugin-external';

/**
 * WordPress-provided script packages. Imports of these are mapped to the
 * `wp.*` globals instead of being bundled, so WordPress supplies them (with
 * its REST nonce middleware and loaded translations). Each one must also be
 * listed as a script dependency in includes/Admin/AdminPage.php.
 *
 * React is intentionally bundled rather than taken from WordPress, so the
 * admin app does not depend on the React version of the host WordPress.
 */
const WP_GLOBALS: Record< string, string > = {
	'@wordpress/api-fetch': 'wp.apiFetch',
	'@wordpress/i18n': 'wp.i18n',
};

const wpExternals: Plugin = {
	name: 'updatelens:wp-externals',
	apply: 'build',
	config: () => ( {
		build: { rollupOptions: { external: Object.keys( WP_GLOBALS ) } },
	} ),
};

/**
 * Origins allowed to load modules from the dev server. Vite only allows
 * localhost by default; local WordPress sites often use *.local or *.test.
 */
const DEV_CORS_ORIGIN =
	/^https?:\/\/(?:(?:[^:/]+\.)?localhost|[^:/]+\.(?:local|test)|127\.0\.0\.1|\[::1\])(?::\d+)?$/;

export default defineConfig( {
	plugins: [
		v4wp( {
			input: 'src/admin/main.tsx',
			outDir: 'assets/admin/dist',
		} ),
		wpExternals,
		externalGlobals( WP_GLOBALS ),
		externals( { development: { externals: WP_GLOBALS } } ),
		react(),
	],
	server: {
		cors: { origin: DEV_CORS_ORIGIN },
	},
	resolve: {
		alias: {
			'@': fileURLToPath( new URL( './src', import.meta.url ) ),
		},
	},
} );
