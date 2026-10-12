import { StrictMode } from 'react';
// WordPress's React (vite.config.ts WP_GLOBALS): wp.element's createRoot is
// React DOM's, through WordPress's client entry point.
import { createRoot } from '@wordpress/element';

import App from './App';
import './index.css';

// Rendered by includes/Admin/AdminPage.php (with the view and the Plugins
// screen URL); also the CSS scope in postcss.config.cjs.
const root = document.getElementById( 'updatelens-root' );

if ( root ) {
	createRoot( root ).render(
		<StrictMode>
			<App
				view={
					root.dataset.view === 'settings' ? 'settings' : 'reports'
				}
				pluginsUrl={ root.dataset.pluginsUrl ?? '' }
			/>
		</StrictMode>
	);
}
