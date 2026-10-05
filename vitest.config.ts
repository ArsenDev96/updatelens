import { fileURLToPath, URL } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

/**
 * Frontend unit/component tests (jsdom). Separate from vite.config.ts, whose
 * WordPress build plugins are not needed here. `@wordpress/*` packages
 * resolve from node_modules; tests mock `@wordpress/api-fetch`.
 */
export default defineConfig( {
	plugins: [ react() ],
	resolve: {
		alias: {
			'@': fileURLToPath( new URL( './src', import.meta.url ) ),
		},
	},
	test: {
		environment: 'jsdom',
		include: [ 'tests/admin/**/*.test.{ts,tsx}' ],
		setupFiles: [ 'tests/admin/setup.ts' ],
		restoreMocks: true,
	},
} );
