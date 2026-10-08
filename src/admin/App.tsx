import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from 'react';

import { HistoryPage } from './pages/HistoryPage';
import { ReportPage } from './pages/ReportPage';
import { parseRoute, routeHref, type Route } from './utils/route';

/**
 * History, a report's overview and its signal details, selected by the
 * wp-admin URL (`&analysis=<id>`, `&signal=<signal>`, `&phase=<phase>`,
 * `&paged=<n>`). Navigation uses the History API, so reports can be
 * bookmarked and Back/Forward work without page reloads. Switching phases
 * replaces the current entry instead of adding one.
 */
export default function App( {
	pluginsUrl = '',
}: {
	/** Plugins screen URL (from the page shell); empty if the user cannot open it. */
	pluginsUrl?: string;
} ) {
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

	const navigate = useCallback( ( next: Route, replace = false ) => {
		const url = routeHref( next, window.location.href );
		if ( replace ) {
			window.history.replaceState( null, '', url );
		} else {
			window.history.pushState( null, '', url );
			setNavigated( true );
		}
		setRoute( next );
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
						href( {
							view: 'report',
							id,
							page: route.page,
							phase: null,
							signal: null,
						} )
					}
					onOpenReport={ ( id ) =>
						navigate( {
							view: 'report',
							id,
							page: route.page,
							phase: null,
							signal: null,
						} )
					}
					onPageChange={ ( page ) =>
						navigate( { view: 'history', page } )
					}
					focusHeading={ navigated }
					pluginsUrl={ pluginsUrl }
				/>
			) : (
				<ReportPage
					key={ route.id }
					route={ route }
					href={ href }
					onNavigate={ navigate }
					focusHeading={ navigated }
				/>
			) }
		</div>
	);
}
