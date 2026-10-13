import apiFetch from '@wordpress/api-fetch';
import { act, fireEvent, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { PENDING_REFRESH_INTERVAL_MS } from '@/admin/hooks/use-pending-refresh';
import type {
	AnalysisReport,
	SiteCheckImpact,
	SiteChecks,
} from '@/admin/types/api';

import cases from '../fixtures/site-check-impact-cases.json';
import { AWAITING, COMPLETED, restError } from './fixtures';
import { IMPACT } from './impact-fixtures';
import { openReport, tab } from './report-view';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

/*
 * Refresh on an open report reads it again in place, like the background
 * reads of pending site check results: the report stays mounted, so the
 * chosen phase, expanded findings and focus are kept.
 */

/** IMPACT (Net result findings), still awaiting its settled capture. */
const OPEN: AnalysisReport = {
	...IMPACT,
	status: 'awaiting_settle',
	settle_outcome: null,
	timestamps: { ...IMPACT.timestamps, completed_at: null },
};

/** The same analysis once completed. */
const DONE: AnalysisReport = { ...IMPACT };

/**
 * OPEN with the site checks of one case.
 *
 * @param name Case.
 */
function withSiteChecks( name: keyof typeof cases.cases ): AnalysisReport {
	const data = cases.cases[ name ];
	return {
		...OPEN,
		site_checks: data.site_checks as SiteChecks,
		potential_impact: {
			...OPEN.potential_impact,
			site_checks: data.potential_impact_site_checks as SiteCheckImpact,
		},
	};
}

/** A read the test settles itself. */
function deferred() {
	let resolve: ( value: AnalysisReport ) => void = () => {};
	let reject: ( reason: unknown ) => void = () => {};
	const promise = new Promise< AnalysisReport >( ( done, fail ) => {
		resolve = done;
		reject = fail;
	} );
	return { promise, resolve, reject };
}

const impact = () => screen.getByRole( 'region', { name: 'Potential impact' } );
/** The site response checks section (pending results and their refresh). */
const site = () =>
	screen.getByRole( 'region', { name: 'Site response checks' } );
const statusLine = () =>
	screen.getByRole( 'region', { name: 'Analysis status' } );
const refreshButton = () =>
	within( statusLine() ).getByRole( 'button', { name: /^Refresh/ } );
const group = ( title: string ) =>
	within( impact() ).getByRole( 'button', {
		name: new RegExp( `^${ title }` ),
	} );
const heading = () => screen.getByRole( 'heading', { level: 2 } );

/**
 * Opens the report and waits until it is shown.
 *
 * @param served Report served first.
 */
async function open( served: AnalysisReport ) {
	apiFetchMock.mockResolvedValueOnce( served );
	openReport( served.id );
	expect(
		await screen.findByRole( 'heading', { level: 2 } )
	).toBeInTheDocument();
}

describe( 'Refresh', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );
	afterEach( () => {
		vi.useRealTimers();
	} );

	it( 'updates the report in place, keeping the phase and expanded findings', async () => {
		await open( OPEN );
		const user = userEvent.setup();
		await user.click( tab( /^During update/ ) );
		await user.click( group( 'Large autoloaded options' ) );
		expect( group( 'Large autoloaded options' ) ).toHaveAttribute(
			'aria-expanded',
			'true'
		);
		const title = heading();

		const read = deferred();
		apiFetchMock.mockReturnValueOnce( read.promise );
		await user.click( refreshButton() );

		// The report stays while it reads: no skeleton, a busy button.
		expect( screen.queryByText( 'Loading report…' ) ).toBeNull();
		expect( refreshButton() ).toHaveAccessibleName( 'Refreshing…' );
		expect( refreshButton() ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( screen.getByText( 'Refreshing report…' ) ).toHaveAttribute(
			'role',
			'status'
		);
		// Pressing again sends nothing more.
		await user.click( refreshButton() );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 2 );

		await act( async () => read.resolve( DONE ) );

		expect( screen.getByText( 'Report refreshed.' ) ).toHaveAttribute(
			'role',
			'status'
		);
		// Completed now: the status line is gone, and the rest is kept.
		expect(
			screen.queryByRole( 'region', { name: 'Analysis status' } )
		).toBeNull();
		expect( heading() ).toBe( title );
		expect( tab( /^During update/ ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect( group( 'Large autoloaded options' ) ).toHaveAttribute(
			'aria-expanded',
			'true'
		);
		expect( apiFetchMock ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'keeps the default phase it showed, even if the new report would open another', async () => {
		// Awaiting: only During update has data; once completed, the report
		// alone would open on the Net result.
		await open( AWAITING );
		const user = userEvent.setup();
		expect( tab( /^During update/ ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);

		apiFetchMock.mockResolvedValueOnce( { ...COMPLETED, id: AWAITING.id } );
		await user.click( refreshButton() );
		await screen.findByText( 'Report refreshed.' );

		expect( tab( /^Net result/ ) ).not.toHaveTextContent( 'Not available' );
		expect( tab( /^During update/ ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect( window.location.search ).not.toContain( 'phase=' );
	} );

	it( 'keeps focus on Refresh while the analysis stays open, and moves it to the title when the line goes', async () => {
		await open( OPEN );
		const user = userEvent.setup();
		const button = refreshButton();
		button.focus();

		apiFetchMock.mockResolvedValueOnce( OPEN );
		await user.keyboard( '{Enter}' );
		await screen.findByText( 'Report refreshed.' );
		// The same button, still focused.
		expect( refreshButton() ).toBe( button );
		expect( button ).toHaveFocus();
		expect( button ).toHaveAccessibleName( 'Refresh' );

		apiFetchMock.mockResolvedValueOnce( DONE );
		await user.keyboard( '{Enter}' );
		await screen.findByText( 'Report refreshed.' );
		expect(
			screen.queryByRole( 'region', { name: 'Analysis status' } )
		).toBeNull();
		expect( heading() ).toHaveFocus();
	} );

	it( 'keeps the report and says so when a refresh fails', async () => {
		await open( OPEN );
		const user = userEvent.setup();
		await user.click( group( 'Large autoloaded options' ) );

		apiFetchMock.mockRejectedValueOnce(
			restError( 'updatelens_reports_unavailable', 500 )
		);
		await user.click( refreshButton() );

		const alert = await within( statusLine() ).findByRole( 'alert' );
		expect( alert ).toHaveTextContent(
			"The report couldn't be refreshed. The details shown are from the last successful read. The site's REST API returned an error (HTTP 500). Stored reports are not affected."
		);
		expect( document.body ).not.toHaveTextContent( 'Server says' );
		expect( screen.queryByText( 'Refreshing report…' ) ).toBeNull();
		expect( screen.queryByText( 'Report refreshed.' ) ).toBeNull();
		// The report shown stays, with its expanded group.
		expect( group( 'Large autoloaded options' ) ).toHaveAttribute(
			'aria-expanded',
			'true'
		);
		expect( refreshButton() ).toHaveAccessibleName( 'Refresh' );
		expect( refreshButton() ).toHaveFocus();

		// Trying again clears the failure.
		apiFetchMock.mockResolvedValueOnce( OPEN );
		await user.click( refreshButton() );
		await screen.findByText( 'Report refreshed.' );
		expect( within( statusLine() ).queryByRole( 'alert' ) ).toBeNull();
	} );

	it( 'explains a report that became too large to load', async () => {
		await open( OPEN );
		const user = userEvent.setup();

		apiFetchMock.mockRejectedValueOnce(
			restError( 'updatelens_report_too_large', 500 )
		);
		await user.click( refreshButton() );

		expect(
			await within( statusLine() ).findByRole( 'alert' )
		).toHaveTextContent(
			"This report is too large to load with the site's current PHP memory limit. The analysis is still stored, but its details cannot be displayed."
		);
		expect( heading() ).toHaveTextContent( 'Acme Feeds' );
	} );

	it( 'works the same on a signal page', async () => {
		apiFetchMock.mockResolvedValueOnce( OPEN );
		openReport( OPEN.id, { signal: 'options', phase: 'during_update' } );
		await screen.findByRole( 'heading', {
			level: 2,
			name: 'Options & autoload',
		} );
		const user = userEvent.setup();
		const title = heading();

		apiFetchMock.mockResolvedValueOnce( DONE );
		await user.click( refreshButton() );
		await screen.findByText( 'Report refreshed.' );

		expect( heading() ).toBe( title );
		expect( title ).toHaveFocus();
		expect( tab( /^During update/ ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
	} );
} );

describe( 'Refresh with pending site check results', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );
	afterEach( () => {
		vi.useRealTimers();
	} );

	/**
	 * Lets time pass (and the reads it starts settle).
	 *
	 * @param ms Milliseconds.
	 */
	async function wait( ms: number ) {
		await act( async () => {
			await vi.advanceTimersByTimeAsync( ms );
		} );
	}

	/** Opens the pending report with fake timers. */
	async function openPending() {
		vi.useFakeTimers( { toFake: [ 'setTimeout', 'clearTimeout' ] } );
		apiFetchMock.mockResolvedValueOnce( withSiteChecks( 'pending' ) );
		openReport( OPEN.id );
		await wait( 0 );
		expect( heading() ).toBeInTheDocument();
	}

	it( 'waits for a background read in flight instead of overlapping it', async () => {
		await openPending();
		const background = deferred();
		apiFetchMock.mockReturnValueOnce( background.promise );
		await wait( PENDING_REFRESH_INTERVAL_MS );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 2 );
		expect(
			within( site() ).getByRole( 'button', { name: 'Refresh results' } )
		).toHaveAttribute( 'aria-disabled', 'true' );

		const manual = deferred();
		apiFetchMock.mockReturnValueOnce( manual.promise );
		fireEvent.click( refreshButton() );
		await wait( 0 );
		// Not while the background read is in flight.
		expect( apiFetchMock ).toHaveBeenCalledTimes( 2 );
		expect( refreshButton() ).toHaveAccessibleName( 'Refreshing…' );

		await act( async () =>
			background.resolve( withSiteChecks( 'pending' ) )
		);
		await wait( 0 );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 3 );

		await act( async () =>
			manual.resolve( withSiteChecks( 'both_newly_failing' ) )
		);
		await wait( 0 );
		expect(
			screen.getByText( 'Site check results arrived.' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Report refreshed.' ) ).toBeInTheDocument();
		expect(
			within( site() ).queryByRole( 'button', {
				name: 'Refresh results',
			} )
		).toBeNull();

		// Resolved: no further background reads.
		await wait( PENDING_REFRESH_INTERVAL_MS * 5 );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 3 );
	} );

	it( 'delays the next background read while a manual one runs', async () => {
		await openPending();
		const manual = deferred();
		apiFetchMock.mockReturnValueOnce( manual.promise );
		await wait( PENDING_REFRESH_INTERVAL_MS - 1000 );
		fireEvent.click( refreshButton() );
		await wait( 0 );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 2 );
		// The background read now in flight is the manual one.
		expect(
			within( site() ).getByRole( 'button', { name: 'Refresh results' } )
		).toHaveAttribute( 'aria-disabled', 'true' );

		// Its turn comes, but the manual read is still in flight.
		await wait( 2000 );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 2 );

		apiFetchMock.mockResolvedValue( withSiteChecks( 'pending' ) );
		await act( async () => manual.resolve( withSiteChecks( 'pending' ) ) );
		await wait( PENDING_REFRESH_INTERVAL_MS - 1 );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 2 );
		await wait( 1 );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 3 );
		// Still pending: background reading goes on.
		expect( site() ).toHaveTextContent(
			'This report checks for the pending site check results every few seconds while it is open.'
		);
	} );
} );

describe( 'Report load errors', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'explains a report too large to load, without Retry or server text', async () => {
		apiFetchMock.mockRejectedValue(
			restError( 'updatelens_report_too_large', 500 )
		);
		openReport( 60 );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			"This report is too large to load with the site's current PHP memory limit. The analysis is still stored, but its details cannot be displayed."
		);
		expect( screen.queryByRole( 'button', { name: 'Retry' } ) ).toBeNull();
		expect( document.body ).not.toHaveTextContent( 'Server says' );
		expect(
			screen.getByRole( 'link', { name: /Update History/ } )
		).toBeInTheDocument();
	} );

	it( 'keeps the generic message for other server errors', async () => {
		apiFetchMock.mockRejectedValue(
			restError( 'updatelens_reports_unavailable', 500 )
		);
		openReport( 60 );

		const alert = await screen.findByRole( 'alert' );
		expect( alert ).toHaveTextContent( "This report couldn't be loaded." );
		expect( alert ).not.toHaveTextContent( 'memory' );
		expect( document.body ).not.toHaveTextContent( 'Server says' );
		expect(
			screen.getByRole( 'button', { name: 'Retry' } )
		).toBeInTheDocument();
	} );
} );
