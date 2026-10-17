import apiFetch from '@wordpress/api-fetch';
import { act, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type {
	AnalysisReport,
	PhaseKey,
	SiteCheckId,
	SiteCheckImpact,
	SiteChecks,
} from '@/admin/types/api';

import cases from '../fixtures/site-check-impact-cases.json';
import { COMPLETED, withSiteCheckCase } from './fixtures';
import { MINUS } from '@/admin/utils/format';
import type { ReportTab } from '@/admin/utils/route';
import { siteCheckRequestTimes } from '@/admin/utils/siteChecks';

import { openReport, reportTab, tab } from './report-view';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

/*
 * The report's "Website checks" tab, from real API output
 * (tests/fixtures/site-check-impact-cases.json): one row per check with the
 * API's comparison state, never "passed" unless compared as passed, and
 * "Before update" / "After update" with every request behind each row.
 */

type CaseName = keyof typeof cases.cases;

function withSiteChecks(
	base: AnalysisReport,
	name: CaseName
): AnalysisReport {
	const data = cases.cases[ name ];
	return withSiteCheckCase(
		base,
		data.site_checks as SiteChecks,
		data.potential_impact_site_checks as SiteCheckImpact
	);
}

async function renderReport(
	report: AnalysisReport,
	options: { phase?: PhaseKey; tab?: ReportTab } = {}
) {
	apiFetchMock.mockResolvedValue( report );
	openReport( report.id, { tab: 'checks', ...options } );
	await screen.findByRole( 'heading', { level: 2 } );
}

const section = () => screen.getByRole( 'region', { name: 'Website checks' } );

/** The collapsed row of a check (before its details). */
const rowOf = ( item: Element ) => item.firstElementChild!;

/**
 * The rows of the section: name → the comparison result, and the row's one
 * sentence when it has one.
 */
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
				const values = rowOf( item ).querySelectorAll( 'dl dd' );
				// The row's sentence (the request time line, between them,
				// has no paragraph).
				const detail = rowOf( item ).querySelector(
					'dl ~ div > p:not(.relative)'
				)?.textContent;
				return [
					button.textContent,
					[ values[ 2 ].textContent, detail ]
						.filter( Boolean )
						.join( ' ' ),
				];
			} )
	);
}

/**
 * What a check returned before and after the update, as its row shows it.
 *
 * @param name Check name.
 */
const sides = ( name: 'Homepage' | 'WordPress REST API' ) =>
	Array.from(
		rowOf( checkButton( name ).closest( 'li' )! ).querySelectorAll(
			'dl dd'
		)
	)
		.slice( 0, 2 )
		// "HTTP 200" is kept together with a no-break space.
		.map( ( value ) => value.textContent!.replace( /\u00a0/g, ' ' ) );

const checkButton = ( name: 'Homepage' | 'WordPress REST API' ) =>
	within( section() ).getByRole( 'button', { name } );

const FORBIDDEN =
	/\bsafe\b|no issues|no conflicts?|risk-free|healthy|caused by|definitely|site (is|went) down|https?:\/\//i;

describe( 'Website checks tab', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'both passed: its own tab, with compact rows', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ) );

		expect(
			within( section() ).getByRole( 'heading', {
				level: 3,
				name: 'Website checks',
			} )
		).toBeInTheDocument();
		// The brief statement of what these checks cover stays visible.
		expect( section() ).toHaveTextContent(
			'Compare your homepage and WordPress REST API responses around this update. Results are shown only for checks that actually ran. They do not test every page or feature.'
		);
		// Before → after → result; a pass needs no explanation.
		expect( checkRows() ).toEqual( {
			Homepage: 'Passed before and after',
			'WordPress REST API': 'Passed before and after',
		} );
		expect( sides( 'Homepage' ) ).toEqual( [
			'Passed HTTP 200',
			'Passed HTTP 200',
		] );
		expect( checkButton( 'Homepage' ) ).toHaveAccessibleDescription(
			/^Before update Passed HTTP.200 After update Passed HTTP.200 Result Passed before and after$/
		);
		expect( checkButton( 'Homepage' ) ).toHaveAttribute(
			'aria-expanded',
			'false'
		);
		// Collapsed: no requests or explanations in the DOM.
		expect( section() ).not.toHaveTextContent( 'Request 1' );
		expect( section() ).not.toHaveTextContent( 'completion marker' );
		// Not part of a phase: the tab has no phase tabs, and the other
		// tabs' content is not rendered.
		expect( section().closest( '[role="tabpanel"]' ) ).toHaveAccessibleName(
			'Website checks'
		);
		expect(
			screen.queryByRole( 'tablist', { name: 'Observation phases' } )
		).toBeNull();
		expect( screen.queryByText( 'Nothing flagged for review' ) ).toBeNull();
		// "Passed before and after" is said once per check, never again.
		expect(
			section().textContent?.match( /Passed before and after/g )
		).toHaveLength( 2 );
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
			'A pass needs HTTP 200, a response verified as WordPress (the one-time UpdateLens marker came back) and the completion marker, which WordPress’s page footer prints at the end of the page. A pass does not compare what the page shows. A failure counts only when both requests agree.'
		);
		expect( row ).not.toHaveTextContent( 'the page loaded to its end' );
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

		// The REST API note is unchanged and never mentions the homepage's marker.
		await user.click( checkButton( 'WordPress REST API' ) );
		const rest = checkButton( 'WordPress REST API' ).closest( 'li' )!;
		expect( rest ).toHaveTextContent(
			'A pass needs HTTP 200, a response verified as WordPress (the one-time UpdateLens marker came back) and the signed-in user’s data from /wp/v2/users/me. A failure counts only when both requests agree. UpdateLens loads its reports through its own REST routes, so this check can fail while reports still open. Missing, inconclusive and unsupported checks never count as passed.'
		);
		expect( rest.textContent ).not.toMatch(
			/completion marker|what the page shows/
		);
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

			// The After cell says it; the sentence would repeat it.
			expect( checkRows()!.Homepage ).toBe( 'Newly failing' );
			expect( sides( 'Homepage' ) ).toEqual( [
				'Passed HTTP 200',
				'Failed in both requests · HTTP 500',
			] );
			expect( checkRows()![ 'WordPress REST API' ] ).toBe(
				'Passed before and after'
			);
			await user.click( checkButton( 'Homepage' ) );
			const row = checkButton( 'Homepage' ).closest( 'li' )!;
			// Expanded: the comparison in words, then both sides in full.
			expect( row ).toHaveTextContent( 'Passed before, failed after' );
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
			// The finding is in Update results, which opens.
			expect( reportTab( 'Update results' ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			const finding = screen.getByRole( 'button', {
				name: 'Homepage check newly failing, 1 finding, High priority',
			} );
			expect( finding ).toHaveAttribute( 'aria-expanded', 'true' );
			expect( finding ).toHaveFocus();
		} finally {
			vi.useRealTimers();
		}
	} );

	it( 'REST newly failing and both newly failing', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'rest_newly_failing' ) );
		expect( checkRows()![ 'WordPress REST API' ] ).toBe( 'Newly failing' );
		expect( checkRows()!.Homepage ).toBe( 'Passed before and after' );
		expect(
			checkButton( 'WordPress REST API' ).closest( 'li' )
		).toHaveTextContent(
			'Next: Check the features that use this route, such as the block editor. Show the finding'
		);
		// No next step where there is no finding.
		expect(
			checkButton( 'Homepage' ).closest( 'li' )
		).not.toHaveTextContent( 'Next:' );
	} );

	it.each( [
		[ 'failing_both', 'Failed before and after' ],
		[ 'recovered', 'Recovered' ],
		[ 'home_redirect', 'Now redirects' ],
		[ 'redirect_disappeared', 'No longer redirects' ],
		[ 'redirect_then_failure', 'Now failing' ],
		[ 'home_timeouts', 'Newly failing' ],
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
			'No check from this login session had finished when the update started. These checks run in the background on Installed Plugins; wait until UpdateLens shows “Ready to compare website responses” there before clicking “Update now”.';
		expect( checkRows() ).toEqual( {
			Homepage: 'Not compared',
			'WordPress REST API': 'Not compared',
		} );
		expect( sides( 'Homepage' ) ).toEqual( [ 'Not run', 'Not run' ] );
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

	it( 'a REST route WordPress reported as missing: the row names the setting to check', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'rest_route_missing' ) );
		expect( checkRows()![ 'WordPress REST API' ] ).toBe( 'Newly failing' );
		expect(
			checkButton( 'WordPress REST API' ).closest( 'li' )
		).toHaveTextContent(
			'Next: Check whether a setting now hides this route. Show the finding'
		);
	} );

	it( 'no follow-up from the updating tab: every possible cause, none claimed', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'not_followed_up' ) );
		expect( checkRows() ).toEqual( {
			Homepage: 'Not compared',
			'WordPress REST API': 'Not compared',
		} );
		// Dashboard → Updates, a page left or closed, a site that did not
		// answer, or another plugin that answered the follow-up with its own
		// page: the stored evidence cannot tell these apart.
		expect( section() ).toHaveTextContent(
			'No follow-up from that tab reached UpdateLens for this update. That happens when the update ran from Dashboard → Updates, when the page was left or closed during the update, when the site did not answer the browser’s follow-up request, or when another plugin answered that request with its own page, such as a setup screen shown after the plugin updates.'
		);
		expect( section().textContent ).not.toMatch(
			/it ran from Dashboard|the page was left while|outage|site (was|went) down/
		);
		expect( section().textContent ).not.toMatch(
			/Passed before and after/
		);
	} );

	it( 'no follow-up from the updating tab: next time, conditional on a tab that stayed open, once', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'not_followed_up' ) );
		const next =
			'For a comparison next time, click “Update now” on Installed Plugins and keep that tab open for a few seconds after “Updated!”. If the tab stayed open, another plugin may have answered UpdateLens’s request; this can repeat until any setup screen that plugin shows is completed.';
		expect( section() ).toHaveTextContent( next );
		expect(
			section().textContent!.split(
				'If the tab stayed open, another plugin may have answered UpdateLens’s request'
			).length - 1
		).toBe( 1 );
		// Never a claim that another plugin did answer it.
		expect( section().textContent ).not.toMatch(
			/another plugin (answered|redirected|intercepted) UpdateLens’s request/
		);
	} );

	it( 'another update right after this one: said once, from the stored reason', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'next_update' ) );
		expect( checkRows() ).toEqual( {
			Homepage: 'Not compared',
			'WordPress REST API': 'Not compared',
		} );
		expect( section() ).toHaveTextContent(
			'Another plugin update started right after this one'
		);
		expect( section() ).toHaveTextContent(
			'For a comparison next time, update one plugin at a time'
		);
	} );

	it( 'a check after the update that was never reported', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'after_not_reported' ) );
		expect( checkRows() ).toEqual( {
			Homepage: 'Not compared',
			'WordPress REST API': 'Not compared',
		} );
		expect( sides( 'Homepage' ) ).toEqual( [
			'Passed HTTP 200',
			'Not run',
		] );
		// The page being closed is one possible cause, never the only one.
		expect( section() ).toHaveTextContent(
			'The check after the update started, but its result did not reach UpdateLens. That happens when the page was closed while it ran, or when the request that reports the result did not succeed.'
		);
		// How to get a comparison next time, once; never an action for this update.
		expect( section() ).toHaveTextContent(
			'For a comparison next time, click “Update now” on Installed Plugins and keep that tab open for a few seconds after “Updated!”.'
		);
		// The note about another plugin belongs to `not_followed_up` only.
		expect( section() ).not.toHaveTextContent( 'If the tab stayed open' );
		expect(
			within( section() ).queryByRole( 'button', {
				name: /compare|retry|run/i,
			} )
		).toBeNull();
	} );

	it( 'one compared, one unavailable: each row says its own', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'one_unavailable' ) );
		expect( checkRows() ).toEqual( {
			Homepage: 'Passed before and after',
			'WordPress REST API':
				'Not compared The update started before the check before it had finished.',
		} );
	} );

	it( 'an inconclusive check after the update (caching or security restrictions) is not compared', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'unconfirmed' ) );
		expect( checkRows()!.Homepage ).toBe(
			'Not compared The check after the update was inconclusive: one request failed and the retry did not confirm it.'
		);
	} );

	it( 'a check before the update too large to inspect is not compared', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'home_not_inspected' ) );
		expect( checkRows()!.Homepage ).toMatch( /^Not compared/ );
	} );

	it( 'disabled: one sentence and the settings link, no rows and no timing badge', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'disabled' ) );
		expect( checkRows() ).toBeNull();
		expect( section() ).not.toHaveTextContent(
			'Before and after the update'
		);
		expect( section() ).toHaveTextContent(
			'Website checks were turned off when this update ran, so the homepage and the REST API were not checked.'
		);
		expect(
			within( section() ).getByRole( 'link', {
				name: 'Website check settings',
			} )
		).toHaveAttribute( 'href', 'admin.php?page=updatelens-settings' );
		expect( section().textContent ).not.toMatch( /Passed|failed/i );
	} );

	it( 'historical report: readable, quiet, no warning', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'historical' ) );
		expect( checkRows() ).toBeNull();
		expect( section() ).toHaveTextContent(
			'Website checks were not recorded for this update: it was observed before UpdateLens had them.'
		);
		expect( section().querySelector( '.bg-amber-50\\/70' ) ).toBeNull();
		expect( section() ).not.toHaveTextContent(
			'Before and after the update'
		);
	} );

	it( 'a report from an API without website checks reads the same', async () => {
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
			'Website checks were not recorded for this update'
		);
	} );

	it( 'on another origin: not supported, never passed', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'home_cross_origin' ) );
		expect( checkRows()!.Homepage ).toMatch(
			/^Not supported The homepage is on a different address than wp-admin/
		);
		expect( checkRows()![ 'WordPress REST API' ] ).toBe(
			'Passed before and after'
		);
	} );

	it( 'both on another origin: one sentence, never passed', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_cross_origin' ) );
		expect( checkRows() ).toBeNull();
		expect( section() ).toHaveTextContent(
			'They were not checked and are not counted as passed.'
		);
		expect( section() ).not.toHaveTextContent(
			'Before and after the update'
		);
	} );

	it( 'pending: waiting, with the refresh line, never passed', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'pending' ) );
		expect( checkRows() ).toEqual( {
			Homepage: 'Waiting',
			'WordPress REST API': 'Waiting',
		} );
		// The reason both share, said once below the rows.
		expect(
			section().textContent!.split(
				'The browser that ran the update is still checking. Results appear here when they arrive.'
			).length - 1
		).toBe( 1 );
		expect( section() ).toHaveTextContent(
			'This report checks for the pending website check results every few seconds while it is open.'
		);
		expect(
			within( section() ).getByRole( 'button', {
				name: 'Refresh results',
			} )
		).toBeInTheDocument();
		// The earlier results are shown; the comparison is never "passed".
		expect( section().textContent ).not.toMatch(
			/Passed both|Passed before and after/
		);
	} );

	it( 'is the same in every phase and keeps an expanded check', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ), {
			phase: 'during_update',
		} );
		await user.click( checkButton( 'WordPress REST API' ) );
		const before = section().textContent;
		for ( const name of [ /^After update/, /^Net result/ ] ) {
			await user.click( reportTab( 'Configuration changes' ) );
			await user.click( tab( name ) );
			await user.click( reportTab( 'Website checks' ) );
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

/*
 * Request times: each side's first request, only when it measured a complete
 * response (from the recorded `duration_ms`), and the signed difference only
 * for equivalent measurements. Never a finding, warning, score or threshold.
 */
describe( 'Request times', () => {
	const times = ( name: CaseName, check: SiteCheckId ) => {
		const view = siteCheckRequestTimes(
			( cases.cases[ name ].site_checks as SiteChecks ).checks[ check ],
			'en'
		);
		return view === null
			? null
			: [
					view.before.text,
					view.after.text,
					view.difference?.text ?? null,
				];
	};

	it.each( [
		// Compared, same response, same displayed duration: never "0.00 s".
		[
			'both_passed',
			'home',
			[ '0.28 s', '0.28 s', 'No measured difference' ],
		],
		[
			'both_passed',
			'rest',
			[ '0.18 s', '0.18 s', 'No measured difference' ],
		],
		[
			'failing_both',
			'home',
			[ '0.22 s', '0.22 s', 'No measured difference' ],
		],
		[
			'rest_newly_failing',
			'home',
			[ '0.28 s', '0.28 s', 'No measured difference' ],
		],
		// Compared, but not the same response: no difference.
		[
			'home_newly_failing',
			'home',
			[ '0.28 s', '0.22 s', 'Not shown: different responses' ],
		],
		[
			'recovered',
			'home',
			[ '0.22 s', '0.28 s', 'Not shown: different responses' ],
		],
		// HTTP 200 both times, but the later page was incomplete (failed).
		[
			'home_incomplete',
			'home',
			[ '0.28 s', '0.91 s', 'Not shown: different responses' ],
		],
		[
			'rest_newly_failing',
			'rest',
			[ '0.18 s', '0.24 s', 'Not shown: different responses' ],
		],
		// Measured both, but the API did not compare the check.
		[
			'overlapping_update',
			'rest',
			[ '0.18 s', '0.18 s', 'Not shown: check not compared' ],
		],
		[
			'unconfirmed',
			'home',
			[ '0.28 s', '0.22 s', 'Not shown: check not compared' ],
		],
		// No complete response on one side: no time, never a difference.
		[ 'home_timeouts', 'home', [ '0.28 s', 'Not measured', null ] ],
		[ 'home_redirect', 'home', [ '0.28 s', 'Not measured', null ] ],
		[ 'redirect_disappeared', 'home', [ 'Not measured', '0.28 s', null ] ],
		[ 'pending', 'home', [ '0.28 s', 'Waiting', null ] ],
		[ 'after_not_reported', 'rest', [ '0.18 s', 'Not available', null ] ],
	] as Array< [ CaseName, SiteCheckId, Array< string | null > ] > )(
		'%s %s: %j',
		( name, check, expected ) => {
			expect( times( name, check ) ).toEqual( expected );
		}
	);

	it.each( [
		[ 'home_not_inspected', 'home' ],
		[ 'no_baseline', 'home' ],
		[ 'disabled', 'rest' ],
		[ 'both_cross_origin', 'home' ],
		[ 'home_cross_origin', 'home' ],
		[ 'historical', 'rest' ],
		[ 'one_unavailable', 'rest' ],
	] as Array< [ CaseName, SiteCheckId ] > )(
		'%s %s: nothing measured, no line (no time is made up)',
		( name, check ) => {
			expect( times( name, check ) ).toBeNull();
		}
	);

	it( 'reads the first request of each side, as recorded', () => {
		const base = ( cases.cases.both_passed.site_checks as SiteChecks )
			.checks.home;
		const withDuration = ( ms: number ) => ( {
			...base.after,
			result: {
				...base.after.result!,
				attempts: [
					{ ...base.after.result!.attempts[ 0 ], duration_ms: ms },
					{ ...base.after.result!.attempts[ 0 ], duration_ms: 9999 },
				],
			},
		} );
		expect(
			siteCheckRequestTimes(
				{
					...base,
					before: withDuration( 1600 ),
					after: withDuration( 1500 ),
				},
				'en'
			)
		).toEqual( {
			before: { text: '1.6 s', measured: true },
			after: { text: '1.5 s', measured: true },
			difference: { text: `${ MINUS }0.1 s`, kind: 'value' },
		} );
		// 1.50 s and 1.549 s both display as 1.5 s: no measured difference.
		expect(
			siteCheckRequestTimes(
				{
					...base,
					before: withDuration( 1500 ),
					after: withDuration( 1549 ),
				},
				'en'
			)?.difference
		).toEqual( { text: 'No measured difference', kind: 'same' } );
	} );

	/**
	 * The request time line of a check's row as "label: value", or null. The
	 * decimal separator follows the browser locale (here read as a point).
	 */
	const timeLine = ( name: 'Homepage' | 'WordPress REST API' ) => {
		const row = rowOf(
			checkButton( name ).closest( 'li' )!
		) as HTMLElement;
		const line = within( row ).queryByLabelText( 'Request time' );
		return line
			? Array.from( line.querySelectorAll( ':scope > div' ) ).map(
					( item ) =>
						`${ item.querySelector( 'dt' )!.textContent }: ${ item
							.querySelector( 'dd' )!
							.textContent!.replace( /(\d),(\d)/g, '$1.$2' ) }`
				)
			: null;
	};

	it( 'shows the times in each row, neutral, with the explanation once', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'home_newly_failing' ) );
		expect( timeLine( 'Homepage' ) ).toEqual( [
			'Before: 0.28 s',
			'After: 0.22 s',
			'Difference: Not shown: different responses',
		] );
		expect( timeLine( 'WordPress REST API' ) ).toEqual( [
			'Before: 0.18 s',
			'After: 0.18 s',
			'Difference: No measured difference',
		] );
		expect( section() ).not.toHaveTextContent( '0.00 s' );
		expect(
			within( section() ).getAllByText(
				'Request times are individual observations, not a performance benchmark.'
			)
		).toHaveLength( 1 );
		// Neutral: no tone colors on the times; the status is unchanged.
		const line = within(
			rowOf( checkButton( 'Homepage' ).closest( 'li' )! ) as HTMLElement
		).getByLabelText( 'Request time' );
		expect( line.innerHTML ).not.toMatch( /rose|amber|emerald|red-|green/ );
		expect( checkRows() ).toEqual( {
			Homepage: 'Newly failing',
			'WordPress REST API': 'Passed before and after',
		} );
		expect( sides( 'Homepage' ) ).toEqual( [
			'Passed HTTP 200',
			'Failed in both requests · HTTP 500',
		] );
	} );

	it( 'durations stand out from their labels; sentences stay quiet', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'home_newly_failing' ) );
		const field = (
			name: 'Homepage' | 'WordPress REST API',
			label: string
		) =>
			Array.from(
				within(
					rowOf( checkButton( name ).closest( 'li' )! ) as HTMLElement
				)
					.getByLabelText( 'Request time' )
					.querySelectorAll( ':scope > div' )
			).find(
				( item ) => item.querySelector( 'dt' )!.textContent === label
			)!;
		const value = ( item: Element ) => item.querySelector( 'dd' )!;
		// A duration: semibold, tabular figures; its label quieter.
		expect( value( field( 'Homepage', 'Before' ) ) ).toHaveClass(
			'font-semibold',
			'tabular-nums',
			'text-slate-900'
		);
		expect(
			field( 'Homepage', 'Before' ).querySelector( 'dt' )
		).toHaveClass( 'text-xs', 'text-slate-600' );
		// Equal durations and non-equivalent responses are plain text.
		expect(
			value( field( 'WordPress REST API', 'Difference' ) )
		).not.toHaveClass( 'font-semibold' );
		expect( value( field( 'Homepage', 'Difference' ) ) ).not.toHaveClass(
			'font-semibold'
		);
		// The reason takes the whole width on phones, a column from md.
		expect( field( 'Homepage', 'Difference' ) ).toHaveClass(
			'col-span-3',
			'md:col-span-1'
		);
		expect( field( 'WordPress REST API', 'Difference' ) ).not.toHaveClass(
			'col-span-3'
		);
	} );

	it( 'keeps every request and its timing in the details', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'home_newly_failing' ) );
		expect( section() ).not.toHaveTextContent( 'Request 2' );
		await user.click( checkButton( 'Homepage' ) );
		expect( section() ).toHaveTextContent( 'Request 1' );
		expect( section() ).toHaveTextContent( 'Request 2' );
		expect( section() ).toHaveTextContent( /HTTP 500 · 0[.,]2 seconds/ );
	} );

	it( 'pending: the earlier time, the later one waiting, no difference', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'pending' ) );
		expect( timeLine( 'Homepage' ) ).toEqual( [
			'Before: 0.28 s',
			'After: Waiting',
		] );
	} );

	it.each( [
		'disabled',
		'historical',
		'both_cross_origin',
		'no_baseline',
	] as CaseName[] )(
		'%s: no request times and no explanation',
		async ( name ) => {
			await renderReport( withSiteChecks( COMPLETED, name ) );
			expect( section() ).not.toHaveTextContent( 'Request time' );
			expect( section() ).not.toHaveTextContent(
				'performance benchmark'
			);
		}
	);
} );
