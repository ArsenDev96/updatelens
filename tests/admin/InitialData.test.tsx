import apiFetch from '@wordpress/api-fetch';
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import App from '@/admin/App';
import { pageLoad } from '@/admin/hooks/use-page-load-fallback';
import { PENDING_REFRESH_INTERVAL_MS } from '@/admin/hooks/use-pending-refresh';
import type {
	AnalysisReport,
	SiteCheckImpact,
	SiteChecks,
} from '@/admin/types/api';

import cases from '../fixtures/site-check-impact-cases.json';
import { COMPLETED, EXPIRED, historyItem, withSiteCheckCase } from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

/*
 * The data the admin page prints for the URL it was opened with
 * (Admin\InitialData): stored reports and the History open without the
 * REST API; views reached in the app read over REST and, when that fails,
 * load the page again; refreshes keep what is shown when they fail.
 */

type Printed = Record< string, unknown >;

function print( data: Printed | undefined ) {
	( window as unknown as Record< string, unknown > ).updatelensInitialData =
		data;
}

function visit( search: string ) {
	window.history.replaceState( null, '', `/wp-admin/admin.php${ search }` );
}

/** The REST API answers with PHP output before its JSON, for every read. */
function restUnreadable() {
	apiFetchMock.mockRejectedValue( {
		code: 'invalid_json',
		message: 'The response is not a valid JSON response.',
	} );
}

const printedReport = ( report: AnalysisReport ): Printed => ( {
	view: 'report',
	id: report.id,
	status: 'ok',
	report,
} );

const pendingReport = (): AnalysisReport => {
	const data = cases.cases.pending;
	return withSiteCheckCase(
		COMPLETED,
		data.site_checks as SiteChecks,
		data.potential_impact_site_checks as SiteCheckImpact
	);
};

describe( 'Initial data printed by the admin page', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
		pageLoad.reload = vi.fn();
	} );

	afterEach( () => {
		print( undefined );
		vi.useRealTimers();
	} );

	it( 'opens a stored report without the REST API', async () => {
		restUnreadable();
		visit( `?page=updatelens&analysis=${ COMPLETED.id }` );
		print( printedReport( COMPLETED ) );
		render( <App /> );

		expect(
			await screen.findByRole( 'heading', { level: 2 } )
		).toHaveTextContent( 'UpdateLens Fixture A' );
		expect( apiFetchMock ).not.toHaveBeenCalled();
		expect( screen.queryByRole( 'alert' ) ).toBeNull();
	} );

	it( 'moves between its overview and signal pages without reading it again', async () => {
		restUnreadable();
		visit( `?page=updatelens&analysis=${ COMPLETED.id }` );
		print( printedReport( COMPLETED ) );
		render( <App /> );
		const user = userEvent.setup();

		await user.click(
			await screen.findByRole( 'link', { name: 'WP-Cron' } )
		);
		expect(
			await screen.findByRole( 'heading', { level: 2, name: 'WP-Cron' } )
		).toBeInTheDocument();
		expect( apiFetchMock ).not.toHaveBeenCalled();
		expect( pageLoad.reload ).not.toHaveBeenCalled();
	} );

	it( 'says when the printed report does not exist, without asking REST', async () => {
		visit( '?page=updatelens&analysis=99' );
		print( { view: 'report', id: 99, status: 'not_found' } );
		render( <App /> );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'This analysis no longer exists.'
		);
		expect( apiFetchMock ).not.toHaveBeenCalled();
	} );

	it( 'reads over REST when the page could not read it, and never reloads that view', async () => {
		restUnreadable();
		visit( `?page=updatelens&analysis=${ COMPLETED.id }` );
		print( { view: 'report', id: COMPLETED.id, status: 'unavailable' } );
		render( <App /> );

		const alert = await screen.findByRole( 'alert' );
		expect( alert ).toHaveTextContent( "This report couldn't be loaded." );
		expect( alert ).toHaveTextContent(
			"The site's REST API answered with something other than data"
		);
		expect( apiFetchMock ).toHaveBeenCalledTimes( 1 );
		expect( pageLoad.reload ).not.toHaveBeenCalled();
	} );

	it( 'ignores data printed for another report', async () => {
		apiFetchMock.mockResolvedValue( EXPIRED );
		visit( `?page=updatelens&analysis=${ EXPIRED.id }` );
		print( printedReport( COMPLETED ) );
		render( <App /> );

		expect(
			await screen.findByText( 'Post-update observation expired' )
		).toBeInTheDocument();
		expect( apiFetchMock ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'opens the History and its monitoring start without the REST API', async () => {
		restUnreadable();
		visit( '?page=updatelens' );
		print( {
			view: 'history',
			page: 1,
			per_page: 20,
			status: 'ok',
			items: [ historyItem( COMPLETED ), historyItem( EXPIRED ) ],
			total: 2,
			total_pages: 1,
			monitoring: { started_at: '2026-10-01T08:00:00Z' },
		} );
		render( <App /> );

		expect( await screen.findAllByRole( 'link' ) ).toHaveLength( 2 );
		expect(
			await screen.findByText( /Monitoring began on/ )
		).toBeInTheDocument();
		expect( apiFetchMock ).not.toHaveBeenCalled();
	} );

	it( 'a report opened from the printed History while REST fails loads the page again', async () => {
		restUnreadable();
		visit( '?page=updatelens' );
		print( {
			view: 'history',
			page: 1,
			per_page: 20,
			status: 'ok',
			items: [ historyItem( COMPLETED ) ],
			total: 1,
			total_pages: 1,
			monitoring: null,
		} );
		render( <App /> );
		const user = userEvent.setup();

		await user.click( ( await screen.findAllByRole( 'link' ) )[ 0 ] );
		// The REST read failed: the report's URL is loaded as a page instead
		// of showing an error (the page then prints the report).
		await vi.waitFor( () =>
			expect( pageLoad.reload ).toHaveBeenCalledTimes( 1 )
		);
		expect( window.location.search ).toBe(
			`?page=updatelens&analysis=${ COMPLETED.id }`
		);
		expect( screen.queryByRole( 'alert' ) ).toBeNull();
		expect( screen.getByText( 'Loading report…' ) ).toBeInTheDocument();
	} );

	it( 'a report that does not exist never loads the page again', async () => {
		apiFetchMock.mockRejectedValue( {
			code: 'updatelens_analysis_not_found',
			data: { status: 404 },
		} );
		visit( '?page=updatelens' );
		print( {
			view: 'history',
			page: 1,
			per_page: 20,
			status: 'ok',
			items: [ historyItem( COMPLETED ) ],
			total: 1,
			total_pages: 1,
			monitoring: null,
		} );
		render( <App /> );
		const user = userEvent.setup();

		await user.click( ( await screen.findAllByRole( 'link' ) )[ 0 ] );
		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'This analysis no longer exists.'
		);
		expect( pageLoad.reload ).not.toHaveBeenCalled();
	} );

	it( 'a printed report with pending results keeps them when refreshing fails', async () => {
		vi.useFakeTimers( { shouldAdvanceTime: true } );
		restUnreadable();
		const report = pendingReport();
		visit( `?page=updatelens&analysis=${ report.id }` );
		print( printedReport( report ) );
		render( <App /> );
		const section = await screen.findByRole( 'region', {
			name: 'Site checks',
		} );

		await act( () =>
			vi.advanceTimersByTimeAsync( PENDING_REFRESH_INTERVAL_MS + 100 )
		);
		expect( section ).toHaveTextContent(
			'Refreshing the results failed; this report tries again in a few seconds. The results shown are from the last successful read, and checks still waiting stay unresolved.'
		);
		// The report is still shown, still waiting, never passed: each row
		// shows its earlier result and "Waiting" as the comparison.
		const rows = within( section ).getAllByRole( 'listitem' );
		expect(
			rows.map(
				( row ) =>
					within( row ).getAllByRole( 'definition' )[ 2 ].textContent
			)
		).toEqual( [ 'Waiting', 'Waiting' ] );
		expect( section.textContent ).not.toMatch(
			/Passed both|Passed before and after/
		);
		expect( screen.queryByRole( 'alert' ) ).toBeNull();
		expect( pageLoad.reload ).not.toHaveBeenCalled();
	} );
} );
