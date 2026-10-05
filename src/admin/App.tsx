import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from 'react';

import { HistoryPage } from './pages/HistoryPage';
import { ReportPage } from './pages/ReportPage';
import { parseRoute, routeHref, type Route } from './utils/route';

/**
 * Two screens, History and Report, selected by the wp-admin URL
 * (`&analysis=<id>`, `&paged=<n>`). Navigation uses the History API, so
 * reports can be bookmarked and Back/Forward work without page reloads.
 */
export default function App() {
	const [ route, setRoute ] = useState< Route >( () =>
		parseRoute( window.location.search )
	);
	// After in-app navigation, focus moves to the new screen's heading.
	const [ navigated, setNavigated ] = useState( false );

	useEffect( () => {
		const onPopState = () => {
			setRoute( parseRoute( window.location.search ) );
			setNavigated( true );
		};
		window.addEventListener( 'popstate', onPopState );
		return () => window.removeEventListener( 'popstate', onPopState );
	}, [] );

	const navigate = useCallback( ( next: Route ) => {
		window.history.pushState(
			null,
			'',
			routeHref( next, window.location.href )
		);
		setRoute( next );
		setNavigated( true );
	}, [] );

	const href = ( next: Route ) => routeHref( next, window.location.href );

	// The page <h1> ("UpdateLens") is rendered server-side by AdminPage::render().
	return (
		<div className="mt-2 max-w-5xl space-y-5 pb-8">
			<p className="border-b pb-4 text-sm text-muted-foreground">
				{ __(
					'See what changed when WordPress plugins update.',
					'updatelens'
				) }
			</p>
			{ route.view === 'history' ? (
				<HistoryPage
					page={ route.page }
					reportHref={ ( id ) =>
						href( { view: 'report', id, page: route.page } )
					}
					onOpenReport={ ( id ) =>
						navigate( { view: 'report', id, page: route.page } )
					}
					onPageChange={ ( page ) =>
						navigate( { view: 'history', page } )
					}
					focusHeading={ navigated }
				/>
			) : (
				<ReportPage
					key={ route.id }
					id={ route.id }
					historyHref={ href( {
						view: 'history',
						page: route.page,
					} ) }
					onBack={ () =>
						navigate( { view: 'history', page: route.page } )
					}
					focusHeading={ navigated }
				/>
			) }
		</div>
	);
}
