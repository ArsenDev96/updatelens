import { fileURLToPath, URL } from 'node:url';
import { v4wp } from '@kucrut/vite-for-wp';
import react from '@vitejs/plugin-react';
import externalGlobals from 'rollup-plugin-external-globals';
import { defineConfig, type Plugin } from 'vite';
import externals from 'vite-plugin-external';

/**
 * WordPress-provided script packages. Imports of these are mapped to the
 * globals WordPress's scripts define instead of being bundled, so WordPress
 * supplies them (React, its REST nonce middleware, loaded translations).
 * Each global's script handle (WP_SCRIPT_HANDLES) must also be listed in
 * Admin\AdminAssets::DEPENDENCIES (tests/admin/build.test.ts checks both).
 *
 * React is WordPress's (`react`: React 18.2 in WordPress 6.2, 18.3 later),
 * never a second copy. The root is created with `wp.element.createRoot()`,
 * which loads `react-dom`. JSX uses the classic transform
 * (`React.createElement`): the automatic one imports `react/jsx-runtime`,
 * whose script handle (`react-jsx-runtime`) WordPress registers only since
 * 6.6, and bundling it would ship part of React again.
 */
export const WP_GLOBALS: Record< string, string > = {
	react: 'React',
	'@wordpress/api-fetch': 'wp.apiFetch',
	'@wordpress/element': 'wp.element',
	'@wordpress/i18n': 'wp.i18n',
};

/**
 * Script handle that defines each global in WP_GLOBALS.
 */
export const WP_SCRIPT_HANDLES: Record< string, string > = {
	React: 'react',
	'wp.apiFetch': 'wp-api-fetch',
	'wp.element': 'wp-element',
	'wp.i18n': 'wp-i18n',
};

/**
 * Packages that must never end up in the build: WordPress provides React
 * and React DOM (with Scheduler) to every admin screen.
 */
export const NEVER_BUNDLED =
	/[\\/]node_modules[\\/](?:react|react-dom|scheduler)[\\/]/;

const noBundledReact: Plugin = {
	name: 'updatelens:no-bundled-react',
	apply: 'build',
	generateBundle( _options, bundle ) {
		for ( const output of Object.values( bundle ) ) {
			if ( output.type !== 'chunk' ) {
				continue;
			}
			const bundled = output.moduleIds.filter( ( id ) =>
				NEVER_BUNDLED.test( id )
			);
			if ( bundled.length ) {
				this.error(
					`${ output.fileName } bundles ${ bundled.join(
						', '
					) }; use WordPress's React (WP_GLOBALS).`
				);
			}
		}
	},
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
			// WordPress update screens (AdminAssets::FOLLOW_UP_ENTRY), and the
			// site check baseline script (AdminAssets::SITE_CHECK_ENTRY).
			input: [
				'src/admin/main.tsx',
				'src/admin/update-follow-up.ts',
				'src/admin/site-check-baseline.ts',
			],
			outDir: 'assets/admin/dist',
		} ),
		wpExternals,
		noBundledReact,
		externalGlobals( WP_GLOBALS ),
		externals( { development: { externals: WP_GLOBALS } } ),
		react( {
			// Babel instead of esbuild strips TypeScript and JSX, because esbuild
			// drops the `translators:` comments that translate.wordpress.org
			// extracts from the built bundle. Classic JSX: see WP_GLOBALS.
			jsxRuntime: 'classic',
			babel: {
				presets: [
					'@babel/preset-typescript',
					[ '@babel/preset-react', { runtime: 'classic' } ],
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
