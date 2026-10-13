import apiFetch from '@wordpress/api-fetch';
import { act, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type {
	AnalysisReport,
	PhaseKey,
	SiteCheckImpact,
	SiteChecks,
} from '@/admin/types/api';

import cases from '../fixtures/site-check-impact-cases.json';
import { COMPLETED } from './fixtures';
import { openReport, tab } from './report-view';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

/*
 * "Site response checks" on the report overview, from real API output
 * (tests/fixtures/site-check-impact-cases.json): always visible, above the
 * phase tabs, one row per check with the API's comparison state, never
 * "passed" unless compared as passed, and "Before update" / "After update"
 * with every request behind each row.
 */

type CaseName = keyof typeof cases.cases;

function withSiteChecks(
	base: AnalysisReport,
	name: CaseName
): AnalysisReport {
	const data = cases.cases[ name ];
	return {
		...base,
		site_checks: data.site_checks as SiteChecks,
		potential_impact: {
			...base.potential_impact,
			site_checks: data.potential_impact_site_checks as SiteCheckImpact,
		},
	};
}

async function renderReport(
	report: AnalysisReport,
	options: { phase?: PhaseKey } = {}
) {
	apiFetchMock.mockResolvedValue( report );
	openReport( report.id, options );
	await screen.findByRole( 'heading', { level: 2 } );
}

const section = () =>
	screen.getByRole( 'region', { name: 'Site response checks' } );

/** The rows of the section: name → [status line, aria-expanded]. */
function checkRows() {
	const list = within( section() ).queryByRole( 'list', { name: 'Checks' } );
	if ( ! list ) {
		return null;
	}
	return Object.fromEntries(
		within( list )
			.getAllByRole( 'listitem' )
			.map( ( item ) => {
				const button = item.querySelector( 'h4 button' )!;
				return [
					button.textContent,
					item.querySelector( 'p' )!.textContent,
				];
			} )
	);
}

const checkButton = ( name: 'Homepage' | 'WordPress REST API' ) =>
	within( section() ).getByRole( 'button', { name } );

const FORBIDDEN =
	/\bsafe\b|no issues|no conflicts?|risk-free|healthy|caused by|definitely|site (is|went) down|https?:\/\//i;

describe( 'Site response checks on the report overview', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'both passed: its own section above the phase tabs, with short rows', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ) );

		expect(
			within( section() ).getByRole( 'heading', {
				level: 3,
				name: 'Site response checks',
			} )
		).toBeInTheDocument();
		expect( section() ).toHaveTextContent(
			'Just before and after the update'
		);
		// The brief statement of what these checks cover stays visible.
		expect( section() ).toHaveTextContent(
			'These checks are separate from the configuration phases below, and they do not test every page or feature.'
		);
		// A pass needs no explanation in the collapsed row.
		expect( checkRows() ).toEqual( {
			Homepage: 'Passed before and after',
			'WordPress REST API': 'Passed before and after',
		} );
		expect( checkButton( 'Homepage' ) ).toHaveAccessibleDescription(
			'Passed before and after'
		);
		expect( checkButton( 'Homepage' ) ).toHaveAttribute(
			'aria-expanded',
			'false'
		);
		// Collapsed: no requests or explanations in the DOM.
		expect( section() ).not.toHaveTextContent( 'Request 1' );
		expect( section() ).not.toHaveTextContent( 'completion marker' );
		// Not part of a phase: outside the tab panel, before the tabs and
		// Potential Impact.
		expect( section().closest( '[role="tabpanel"]' ) ).toBeNull();
		const following = ( other: Element ) =>
			section().compareDocumentPosition( other ) &
			Node.DOCUMENT_POSITION_FOLLOWING;
		expect( following( screen.getByRole( 'tablist' ) ) ).toBeTruthy();
		expect(
			following(
				screen.getByRole( 'region', { name: 'Potential impact' } )
			)
		).toBeTruthy();
	} );

	it( 'expands with the keyboard to labelled fields before and after the update', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ) );

		checkButton( 'Homepage' ).focus();
		await user.keyboard( '{Enter}' );
		expect( checkButton( 'Homepage' ) ).toHaveAttribute(
			'aria-expanded',
			'true'
		);
		expect( checkButton( 'Homepage' ) ).toHaveFocus();
		const row = checkButton( 'Homepage' ).closest( 'li' )!;
		const side = ( name: string ) =>
			within( row ).getByRole( 'heading', { level: 5, name } )
				.parentElement!;
		const fields = ( name: string ) =>
			Object.fromEntries(
				within( side( name ) )
					.getAllByRole( 'term' )
					.map( ( term ) => [
						term.textContent,
						term.nextElementSibling!.textContent,
					] )
			);
		expect( fields( 'Before update' ) ).toEqual( {
			// HTTP and the status are kept together (no-break space).
			Result: expect.stringMatching( /^Passed · HTTP\s200$/ ),
			Checked: expect.stringMatching(
				/4[.,]2 minutes before the update started$/
			),
			'Request 1': expect.stringMatching(
				/^HTTP 200 · .*Source: Verified as WordPressCompletion marker: Present$/
			),
		} );
		expect( fields( 'After update' ) ).toMatchObject( {
			// HTTP and the status are kept together (no-break space).
			Result: expect.stringMatching( /^Passed · HTTP\s200$/ ),
			Checked: expect.stringMatching(
				/3 seconds after the update finished$/
			),
		} );
		// How to read it, only once expanded.
		// What "verified" means is said once, after the requests.
		expect( row ).toHaveTextContent(
			'A pass needs HTTP 200, a response verified as WordPress (the one-time UpdateLens marker came back) and the completion marker'
		);
		expect( row ).toHaveTextContent(
			'Missing, inconclusive and unsupported checks never count as passed.'
		);
		expect( row.textContent!.split( 'UpdateLens marker' ).length - 1 ).toBe(
			1
		);
		await user.keyboard( ' ' );
		expect( checkButton( 'Homepage' ) ).toHaveAttribute(
			'aria-expanded',
			'false'
		);
		expect( section() ).not.toHaveTextContent( 'Request 1' );
	} );

	it( 'homepage newly failing: what was observed, and a link to the finding', async () => {
		vi.useFakeTimers( { shouldAdvanceTime: true } );
		try {
			const user = userEvent.setup( {
				advanceTimers: vi.advanceTimersByTime,
			} );
			await renderReport(
				withSiteChecks( COMPLETED, 'home_newly_failing' )
			);

			expect( checkRows()!.Homepage ).toBe(
				'Passed before, failed after Both requests after the update returned HTTP 500.'
			);
			expect( checkRows()![ 'WordPress REST API' ] ).toMatch(
				/^Passed before and after/
			);
			await user.click( checkButton( 'Homepage' ) );
			const row = checkButton( 'Homepage' ).closest( 'li' )!;
			expect( row ).toHaveTextContent(
				'Failed in both requests · HTTP 500'
			);
			// The next step and the finding link are in the collapsed row.
			expect( row ).toHaveTextContent(
				'Next: Check whether the homepage is accessible now. Show the finding'
			);
			// The response source is verified for the failed responses too.
			expect( row ).toHaveTextContent(
				/Request 2HTTP 500 · .*Source: Verified as WordPress, also for this error/
			);
			await user.click(
				within( row ).getByRole( 'button', {
					name: 'Show the finding',
				} )
			);
			await act( () => vi.runOnlyPendingTimersAsync() );
			const finding = screen.getByRole( 'button', {
				name: 'Homepage check newly failing, 1 finding',
			} );
			expect( finding ).toHaveAttribute( 'aria-expanded', 'true' );
			expect( finding ).toHaveFocus();
		} finally {
			vi.useRealTimers();
		}
	} );

	it( 'REST newly failing and both newly failing', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'rest_newly_failing' ) );
		expect( checkRows()![ 'WordPress REST API' ] ).toBe(
			'Passed before, failed after Both requests after the update returned a response that was not valid JSON.'
		);
		expect( checkRows()!.Homepage ).toMatch( /^Passed before and after/ );
		expect(
			checkButton( 'WordPress REST API' ).closest( 'li' )
		).toHaveTextContent(
			'Next: Check whether the block editor works. Show the finding'
		);
		// No next step where there is no finding.
		expect(
			checkButton( 'Homepage' ).closest( 'li' )
		).not.toHaveTextContent( 'Next:' );
	} );

	it.each( [
		[ 'failing_both', 'Failed before and after' ],
		[ 'recovered', 'Failed before, passed after' ],
		[ 'home_redirect', 'Passed before, redirected after' ],
		[ 'redirect_disappeared', 'Redirected before, passed after' ],
		[ 'redirect_then_failure', 'Redirected before, failed after' ],
		[ 'home_timeouts', 'Passed before, failed after' ],
	] as Array< [ CaseName, string ] > )(
		'%s: the comparison as observed',
		async ( name, status ) => {
			await renderReport( withSiteChecks( COMPLETED, name ) );
			expect( checkRows()!.Homepage ).toMatch(
				new RegExp( `^${ status }` )
			);
			expect( section().textContent ).not.toMatch( FORBIDDEN );
		}
	);

	it( 'no check before the update for either: the reason once, never passed', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'no_baseline' ) );

		const reason =
			'No check from this login session had finished when the update started. These checks run in the background on Installed Plugins; wait until UpdateLens shows “Ready to compare site responses” there before clicking “Update now”.';
		expect( checkRows() ).toEqual( {
			Homepage: 'Not compared: no check before the update',
			'WordPress REST API': 'Not compared: no check before the update',
		} );
		expect( section().textContent!.split( reason ).length - 1 ).toBe( 1 );
		await user.click( checkButton( 'Homepage' ) );
		const row = checkButton( 'Homepage' ).closest( 'li' )!;
		// Not repeated in the details; the later side says why it is missing.
		expect( row ).not.toHaveTextContent( reason );
		expect( row ).toHaveTextContent(
			'After updateResultNot availableThere was no check before the update to compare with, so none ran after it.'
		);
		expect( section().textContent ).not.toMatch( /Passed/ );
	} );

	it( 'a check after the update that was never reported', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'after_not_reported' ) );
		expect( checkRows() ).toEqual( {
			Homepage: 'Not compared: no check after the update',
			'WordPress REST API': 'Not compared: no check after the update',
		} );
		expect( section() ).toHaveTextContent(
			'The check after the update started, but its result was not reported, for example because the page was closed while it ran.'
		);
	} );

	it( 'one compared, one unavailable: each row says its own', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'one_unavailable' ) );
		expect( checkRows() ).toEqual( {
			Homepage: 'Passed before and after',
			'WordPress REST API':
				'Not compared: no check before the update The update started before the check before it had finished.',
		} );
	} );

	it( 'an inconclusive check after the update (caching or security restrictions) is not compared', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'unconfirmed' ) );
		expect( checkRows()!.Homepage ).toBe(
			'Could not be compared The check after the update was inconclusive: one request failed and the retry did not confirm it.'
		);
	} );

	it( 'a check before the update too large to inspect is not compared', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'home_not_inspected' ) );
		expect( checkRows()!.Homepage ).toMatch( /^Could not be compared/ );
	} );

	it( 'disabled: one sentence and the settings link, no rows and no timing badge', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'disabled' ) );
		expect( checkRows() ).toBeNull();
		expect( section() ).not.toHaveTextContent(
			'Just before and after the update'
		);
		expect( section() ).toHaveTextContent(
			'Site checks were turned off when this update ran, so the homepage and the REST API were not checked.'
		);
		expect(
			within( section() ).getByRole( 'link', {
				name: 'Site check settings',
			} )
		).toHaveAttribute( 'href', 'admin.php?page=updatelens-settings' );
		expect( section().textContent ).not.toMatch( /Passed|failed/i );
	} );

	it( 'historical report: readable, quiet, no warning', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'historical' ) );
		expect( checkRows() ).toBeNull();
		expect( section() ).toHaveTextContent(
			'Site checks were not recorded for this update: it was observed before UpdateLens had them.'
		);
		expect( section().querySelector( '.bg-amber-50\\/70' ) ).toBeNull();
		expect( section() ).not.toHaveTextContent(
			'Just before and after the update'
		);
	} );

	it( 'a report from an API without site checks reads the same', async () => {
		const { site_checks: omitted, ...older } = COMPLETED;
		void omitted;
		await renderReport( {
			...older,
			potential_impact: {
				...COMPLETED.potential_impact,
				site_checks: undefined,
			},
		} as unknown as AnalysisReport );
		expect( section() ).toHaveTextContent(
			'Site checks were not recorded for this update'
		);
	} );

	it( 'on another origin: not supported, never passed', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'home_cross_origin' ) );
		expect( checkRows()!.Homepage ).toMatch(
			/^Not supported on this site The homepage is on a different address than wp-admin/
		);
		expect( checkRows()![ 'WordPress REST API' ] ).toMatch(
			/^Passed before and after/
		);
	} );

	it( 'both on another origin: one sentence, never passed', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_cross_origin' ) );
		expect( checkRows() ).toBeNull();
		expect( section() ).toHaveTextContent(
			'They were not checked and are not counted as passed.'
		);
		expect( section() ).not.toHaveTextContent(
			'Just before and after the update'
		);
	} );

	it( 'pending: waiting, with the refresh line, never passed', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'pending' ) );
		expect( checkRows() ).toEqual( {
			Homepage:
				'Waiting for the check after the update The browser that ran the update is still checking. Results appear here when they arrive.',
			'WordPress REST API':
				'Waiting for the check after the update The browser that ran the update is still checking. Results appear here when they arrive.',
		} );
		expect( section() ).toHaveTextContent(
			'This report checks for the pending site check results every few seconds while it is open.'
		);
		expect(
			within( section() ).getByRole( 'button', {
				name: 'Refresh results',
			} )
		).toBeInTheDocument();
		expect( section().textContent ).not.toMatch( /Passed/ );
	} );

	it( 'is the same in every phase and keeps an expanded check', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ), {
			phase: 'during_update',
		} );
		await user.click( checkButton( 'WordPress REST API' ) );
		const before = section().textContent;
		for ( const name of [ /^After update/, /^Net result/ ] ) {
			await user.click( tab( name ) );
			expect( section().textContent ).toBe( before );
			expect( checkButton( 'WordPress REST API' ) ).toHaveAttribute(
				'aria-expanded',
				'true'
			);
		}
	} );

	it.each( Object.keys( cases.cases ) as CaseName[] )(
		'%s: "passed" only for a check compared as passed; no raw codes',
		async ( name ) => {
			const user = userEvent.setup();
			await renderReport( withSiteChecks( COMPLETED, name ) );
			for ( const button of within( section() ).queryAllByRole(
				'button',
				{ expanded: false }
			) ) {
				await user.click( button );
			}
			const states = cases.cases[ name ].site_checks.checks;
			for ( const [ check, label ] of [
				[ 'home', 'Homepage' ],
				[ 'rest', 'WordPress REST API' ],
			] as const ) {
				const status = checkRows()?.[ label ] ?? '';
				expect( status.startsWith( 'Passed before and after' ) ).toBe(
					states[ check ].state === 'passed_both'
				);
			}
			const text = section().textContent ?? '';
			expect( text ).not.toMatch( FORBIDDEN );
			expect( text ).not.toMatch(
				/newly_failing|not_comparable|after_inconclusive|baseline_missing|not_reported|server_error|end_marker|\bnull\b|undefined|\[object/
			);
		}
	);
} );
