import apiFetch from '@wordpress/api-fetch';
import { act, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
	PENDING_REFRESH_INTERVAL_MS,
	PENDING_REFRESH_MAX_REQUESTS,
} from '@/admin/hooks/use-pending-refresh';
import type {
	AnalysisReport,
	PhaseKey,
	SiteCheckImpact,
	SiteChecks,
} from '@/admin/types/api';

import cases from '../fixtures/site-check-impact-cases.json';
import { IMPACT } from './impact-fixtures';
import { withSiteCheckCase } from './fixtures';
import type { ReportTab } from '@/admin/utils/route';

import { openReport, reportPanel, reportTab } from './report-view';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

/*
 * An open report re-reads itself while site check results are pending
 * (real API output from tests/fixtures/site-check-impact-cases.json), and
 * only then.
 */

type CaseName = keyof typeof cases.cases;

/**
 * IMPACT (Net result findings) with the site checks of one case.
 *
 * @param name Case.
 */
function report( name: CaseName ): AnalysisReport {
	const data = cases.cases[ name ];
	return withSiteCheckCase(
		IMPACT,
		data.site_checks as SiteChecks,
		data.potential_impact_site_checks as SiteCheckImpact
	);
}

/**
 * Opens a report, then switches to fake timers for the background reads.
 *
 * @param served Report served first.
 * @param phase  Phase in the URL.
 * @param tab    Tab of the report (default: Website checks).
 */
async function open(
	served: AnalysisReport,
	phase?: PhaseKey,
	tab: ReportTab = 'checks'
) {
	vi.useFakeTimers( { toFake: [ 'setTimeout', 'clearTimeout' ] } );
	apiFetchMock.mockResolvedValue( served );
	openReport( served.id, { phase, tab } );
	await wait( 0 );
	expect( screen.getByRole( 'heading', { level: 2 } ) ).toBeInTheDocument();
}

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

/** The selected tab's content. */
const impact = () => reportPanel();
/** The website checks (pending results and their refresh). */
const site = () => screen.getByRole( 'region', { name: 'Website checks' } );

/** Titles of the finding rows (Update results). */
const rowTitles = () =>
	Array.from(
		impact().querySelectorAll(
			'section[data-priority] > ul > li h4 button'
		)
	).map( ( button ) => button.firstChild!.textContent );

/** The button of a finding row by its title. */
const rowButton = ( title: string ) =>
	within( impact() ).getByRole( 'button', {
		name: new RegExp( `^${ title }` ),
	} );

describe( 'Pending website check results', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );
	afterEach( () => {
		vi.useRealTimers();
		Reflect.deleteProperty( document, 'hidden' );
	} );

	it( 'updates the open report in place when they arrive, then stops reading', async () => {
		await open(
			report( 'pending_with_finding' ),
			'during_update',
			'results'
		);
		const rest = rowButton( 'REST API check newly failing' );
		fireEvent.click( rest );
		rest.focus();
		expect( rest ).toHaveAttribute( 'aria-expanded', 'true' );

		// Nothing yet before the interval.
		await wait( PENDING_REFRESH_INTERVAL_MS - 1 );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 1 );

		apiFetchMock.mockResolvedValue( report( 'both_newly_failing' ) );
		await wait( 1 );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 2 );
		expect( rowTitles() ).toEqual( [
			'Homepage check newly failing',
			'REST API check newly failing',
			'Large autoloaded options',
			'Recurring WP-Cron event no longer observed',
			'Recurring WP-Cron schedule changed',
			'Recurring Action Scheduler schedule changed',
		] );
		// The same row, still expanded and focused; the phase is kept.
		expect( rowButton( 'REST API check newly failing' ) ).toBe( rest );
		expect( rest ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( rest ).toHaveFocus();
		expect( window.location.search ).toContain( 'phase=during_update' );
		// Announced whichever tab is open.
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Website check results arrived.'
		);
		fireEvent.click( reportTab( 'Website checks' ) );
		expect(
			within( site() ).queryByRole( 'button', {
				name: 'Refresh results',
			} )
		).not.toBeInTheDocument();

		// Resolved: no further reads.
		await wait( PENDING_REFRESH_INTERVAL_MS * 10 );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'reads one at a time, stops at the limit and offers Refresh results', async () => {
		await open( report( 'pending' ) );
		let resolve: ( value: AnalysisReport ) => void = () => {};
		apiFetchMock.mockImplementation(
			() =>
				new Promise( ( done ) => {
					resolve = done;
				} )
		);

		// A slow read: no second request while it is in flight.
		await wait( PENDING_REFRESH_INTERVAL_MS * 3 );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 2 );
		await act( async () => resolve( report( 'pending' ) ) );

		apiFetchMock.mockResolvedValue( report( 'pending' ) );
		await wait(
			PENDING_REFRESH_INTERVAL_MS * PENDING_REFRESH_MAX_REQUESTS
		);
		expect( apiFetchMock ).toHaveBeenCalledTimes(
			1 + PENDING_REFRESH_MAX_REQUESTS
		);
		expect( site() ).toHaveTextContent(
			'Automatic checking stopped; the website check results have not arrived yet.'
		);
		// It only reads the report again: the same name in both states.
		const again = within( site() ).getByRole( 'button', {
			name: 'Refresh results',
		} );
		await wait( PENDING_REFRESH_INTERVAL_MS * 5 );
		expect( apiFetchMock ).toHaveBeenCalledTimes(
			1 + PENDING_REFRESH_MAX_REQUESTS
		);

		// Still pending: visible in the rows, never passed.
		expect( site() ).toHaveTextContent( 'Waiting' );
		expect( impact().textContent ).not.toMatch(
			/Passed at both|Passed before and after/
		);
		again.focus();
		fireEvent.click( again );
		await wait( 0 );
		expect( apiFetchMock ).toHaveBeenCalledTimes(
			2 + PENDING_REFRESH_MAX_REQUESTS
		);
		// The same button, now for a new run, keeps focus.
		expect( again ).toHaveAccessibleName( 'Refresh results' );
		expect( again ).toHaveFocus();
	} );

	it( 'keeps the report when reads fail, and says why it stopped', async () => {
		await open( report( 'pending' ) );
		apiFetchMock.mockRejectedValue( {
			code: 'invalid_json',
			message: 'The response is not a valid JSON response.',
		} );

		// The first failed read is said at once; the run keeps trying.
		await wait( PENDING_REFRESH_INTERVAL_MS );
		expect( site() ).toHaveTextContent(
			'Refreshing the results failed; this report tries again in a few seconds. The results shown are from the last successful read, and checks still waiting stay unresolved.'
		);
		await wait( PENDING_REFRESH_INTERVAL_MS * 2 );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 4 );
		// Not "the results have not arrived": the report could not be read.
		expect( site() ).toHaveTextContent(
			"Automatic checking stopped: refreshed results are unavailable. The results shown are from the last successful read, and checks still waiting stay unresolved and are not counted as passed. The site's REST API answered with something other than data, for example PHP output, an error page or a firewall page. Stored reports are not affected."
		);
		// Each row keeps its earlier result; the comparison is never "passed".
		expect( site().textContent ).not.toMatch(
			/Passed both|Passed before and after/
		);
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		expect( document.body ).not.toHaveTextContent( 'not a valid JSON' );
		fireEvent.click( reportTab( 'Update results' ) );
		expect( rowTitles() ).toHaveLength( 4 );

		await wait( PENDING_REFRESH_INTERVAL_MS * 5 );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 4 );
	} );

	it( 'pauses while the page is hidden', async () => {
		await open( report( 'pending' ) );
		let hidden = true;
		Object.defineProperty( document, 'hidden', {
			configurable: true,
			get: () => hidden,
		} );

		await wait( PENDING_REFRESH_INTERVAL_MS * 6 );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 1 );

		hidden = false;
		await act( async () => {
			document.dispatchEvent( new Event( 'visibilitychange' ) );
		} );
		await wait( 0 );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 2 );
	} );

	it.each( [
		'both_passed',
		'home_newly_failing',
		'disabled',
		'historical',
		'after_not_reported',
		'one_unavailable',
	] as CaseName[] )( '%s: never read again', async ( name ) => {
		await open( report( name ) );

		await wait( PENDING_REFRESH_INTERVAL_MS * 12 );
		expect( apiFetchMock ).toHaveBeenCalledTimes( 1 );
		expect(
			within( site() ).queryByRole( 'button', { name: /^Check/ } )
		).not.toBeInTheDocument();
	} );

	it( 'stops when the report is left', async () => {
		await open( report( 'pending' ) );
		act( () => {
			window.history.pushState( {}, '', '?page=updatelens' );
			window.dispatchEvent( new PopStateEvent( 'popstate' ) );
		} );
		apiFetchMock.mockClear();

		await wait( PENDING_REFRESH_INTERVAL_MS * 4 );
		expect(
			apiFetchMock.mock.calls.filter( ( [ options ] ) =>
				String( ( options as { path?: string } ).path ).match(
					/\/analyses\/\d+$/
				)
			)
		).toHaveLength( 0 );
	} );
} );
