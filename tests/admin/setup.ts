import '@testing-library/jest-dom/vitest';
import * as React from 'react';
import { resetLocaleData } from '@wordpress/i18n';
import { cleanup } from '@testing-library/react';
import { afterEach, beforeEach } from 'vitest';

// WordPress's `react` script defines React as a global; the classic JSX
// transform (vite.config.ts) refers to it.
( globalThis as typeof globalThis & { React: typeof React } ).React = React;

// UI text is English (untranslated source strings) in every test, whatever the
// host locale; tests that need a translation set their own locale data.
beforeEach( () => {
	resetLocaleData();
} );

afterEach( () => {
	// tests/admin/build.test.ts runs in Node, without a DOM.
	if ( typeof window === 'undefined' ) {
		return;
	}
	cleanup();
	window.history.replaceState(
		null,
		'',
		'/wp-admin/admin.php?page=updatelens'
	);
} );
