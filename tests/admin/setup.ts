import '@testing-library/jest-dom/vitest';
import { resetLocaleData } from '@wordpress/i18n';
import { cleanup } from '@testing-library/react';
import { afterEach, beforeEach } from 'vitest';

// UI text is English (untranslated source strings) in every test, whatever the
// host locale; tests that need a translation set their own locale data.
beforeEach( () => {
	resetLocaleData();
} );

afterEach( () => {
	cleanup();
	window.history.replaceState(
		null,
		'',
		'/wp-admin/tools.php?page=updatelens'
	);
} );
