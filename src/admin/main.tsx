import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import App from './App';
import './index.css';

// Rendered by includes/Admin/AdminPage.php (with the Plugins screen URL); also the CSS scope in postcss.config.cjs.
const root = document.getElementById( 'updatelens-root' );

if ( root ) {
	createRoot( root ).render(
		<StrictMode>
			<App pluginsUrl={ root.dataset.pluginsUrl ?? '' } />
		</StrictMode>
	);
}
