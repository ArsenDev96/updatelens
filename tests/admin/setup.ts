import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

afterEach( () => {
	cleanup();
	window.history.replaceState(
		null,
		'',
		'/wp-admin/tools.php?page=updatelens'
	);
} );
