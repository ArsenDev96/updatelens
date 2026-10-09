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
			// The admin app, and the update follow-up script loaded on the
			// WordPress update screens (AdminAssets::FOLLOW_UP_ENTRY).
			input: [ 'src/admin/main.tsx', 'src/admin/update-follow-up.ts' ],
			outDir: 'assets/admin/dist',
		} ),
		wpExternals,
		externalGlobals( WP_GLOBALS ),
		externals( { development: { externals: WP_GLOBALS } } ),
		react( {
			// Babel instead of esbuild strips TypeScript and JSX, because esbuild
			// drops the `translators:` comments that translate.wordpress.org
			// extracts from the built bundle.
			babel: {
				presets: [
					'@babel/preset-typescript',
					[ '@babel/preset-react', { runtime: 'automatic' } ],
				],
			},
		} ),
	],
	esbuild: false,
	build: {
		// Terser keeps the `translators:` comments; esbuild's minifier cannot.
		// Third-party notices are in THIRD-PARTY-NOTICES.txt.
		minify: 'terser',
		terserOptions: { format: { comments: /translators:/i } },
		// Source maps stay local (the release ZIP excludes them), so the bundle
		// does not reference a missing file.
		sourcemap: 'hidden',
	},
	server: {
		cors: { origin: DEV_CORS_ORIGIN },
	},
	resolve: {
		alias: {
			'@': fileURLToPath( new URL( './src', import.meta.url ) ),
		},
	},
} );
