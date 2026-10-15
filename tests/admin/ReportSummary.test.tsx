import apiFetch from '@wordpress/api-fetch';
import { act, cleanup, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type {
	AnalysisReport,
	SiteCheckImpact,
	SiteChecks,
} from '@/admin/types/api';

import cases from '../fixtures/site-check-impact-cases.json';
import {
	AWAITING,
	COMPLETED,
	EXPIRED,
	FAILED,
	MALFORMED_CRON,
	PRE_CRON,
	withSiteCheckCase,
} from './fixtures';
import {
	HISTORICAL_NOT_INSTALLED,
	IMPACT,
	ONE_FINDING,
} from './impact-fixtures';
import { openReport } from './report-view';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

/*
 * The summary below the report header: the decision in short rows. Findings
 * by the priority the API gave them (or, without any, how complete the
 * observations were) with the changes no rule matched as informational, the
 * configuration changes, the site checks, and what could not be assessed,
 * kept apart from the priorities. Never one verdict on the update.
 */

type CaseName = keyof typeof cases.cases;

/**
 * A report with a site check case (real API output).
 *
 * @param base Report.
 * @param name Case.
 */
function withSiteChecks( base: AnalysisReport, name: CaseName ) {
	const data = cases.cases[ name ];
	return withSiteCheckCase(
		base,
		data.site_checks as SiteChecks,
		data.potential_impact_site_checks as SiteCheckImpact
	);
}

async function renderReport( report: AnalysisReport ) {
	apiFetchMock.mockResolvedValue( report );
	openReport( report.id );
	await screen.findByRole( 'heading', { level: 2 } );
}

const summary = () => screen.getByRole( 'region', { name: 'Report summary' } );

/** The summary as label → answer (without the row's action). */
function rows(): Record< string, string > {
	return Object.fromEntries(
		within( summary() )
			.getAllByRole( 'term' )
			.map( ( term ) => [
				term.textContent,
				term.nextElementSibling!.textContent,
			] )
	);
}

/** Actions of the summary, in order. */
const actions = () =>
	within( summary() )
		.queryAllByRole( 'button' )
		.map( ( button ) => button.textContent );

const FORBIDDEN =
	/\bsafe\b|healthy|no issues|no problems|passed update|update passed|successful update|caused by|\brisk|\bcomplete\b/i;

describe( 'Report summary', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'comes right after the header, before findings, site checks and phases', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ) );
		const following = ( other: Element ) =>
			summary().compareDocumentPosition( other ) &
			Node.DOCUMENT_POSITION_FOLLOWING;
		expect(
			following( screen.getByRole( 'region', { name: 'Findings' } ) )
		).toBeTruthy();
		expect(
			following( screen.getByRole( 'region', { name: 'Site checks' } ) )
		).toBeTruthy();
		expect( following( screen.getByRole( 'tablist' ) ) ).toBeTruthy();
		expect(
			screen.getByRole( 'banner' ).compareDocumentPosition( summary() ) &
				Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
	} );

	it( 'answers in short rows, without a verdict', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ) );
		expect( Object.keys( rows() ) ).toEqual( [
			'Findings',
			'Configuration',
			'Site checks',
		] );
		// No findings is worded by completeness; the changes no rule matched
		// are informational, never "safe".
		expect( rows().Findings ).toMatch(
			/^No findings matched the completed observations\.\d+ of \d+ configuration changes? in the Net result matched no review rule \(informational\)\.$/
		);
		expect( rows().Configuration ).toMatch(
			/^\d+ changes? observedNet result · /
		);
		// Both checks in the same state: said once.
		expect( rows()[ 'Site checks' ] ).toBe( 'Passed before and after' );
		// Where to look: no "Go to findings" without findings.
		expect( actions() ).toEqual( [
			'Inspect changes',
			'Site check details',
		] );
		expect( summary().textContent ).not.toMatch( FORBIDDEN );
	} );

	it( 'counts findings by priority, high priority first, and leads to them', async () => {
		vi.useFakeTimers( { shouldAdvanceTime: true } );
		try {
			const user = userEvent.setup( {
				advanceTimers: vi.advanceTimersByTime,
			} );
			await renderReport(
				withSiteChecks( IMPACT, 'home_newly_failing' )
			);
			expect( rows().Findings ).toMatch(
				/^1 high priority5 need review/
			);
			expect( rows()[ 'Site checks' ] ).toBe(
				'Newly failing: HomepageHomepage: newly failing · REST API: passed before and after'
			);
			// Next steps stay in Findings and Site checks.
			expect( summary().textContent ).not.toMatch( /Check whether/ );
			expect( summary().textContent ).not.toMatch( FORBIDDEN );
			await user.click(
				within( summary() ).getByRole( 'button', {
					name: 'Go to findings',
				} )
			);
			await act( () => vi.runOnlyPendingTimersAsync() );
			expect(
				screen.getByRole( 'heading', { name: 'Findings' } )
			).toHaveFocus();
			await user.click(
				within( summary() ).getByRole( 'button', {
					name: 'Inspect changes',
				} )
			);
			await act( () => vi.runOnlyPendingTimersAsync() );
			expect(
				screen.getByRole( 'heading', { name: 'Configuration changes' } )
			).toHaveFocus();
			await user.click(
				within( summary() ).getByRole( 'button', {
					name: 'Site check details',
				} )
			);
			await act( () => vi.runOnlyPendingTimersAsync() );
			expect(
				screen.getByRole( 'heading', { name: 'Site checks' } )
			).toHaveFocus();
		} finally {
			vi.useRealTimers();
		}
	} );

	it( 'one finding to review', async () => {
		await renderReport( ONE_FINDING );
		expect( rows().Findings ).toMatch( /^1 needs review/ );
		expect( rows().Findings ).not.toMatch( /high priority/ );
	} );

	it( 'both checks newly failing are two high priority findings', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_newly_failing' ) );
		expect( rows()[ 'Site checks' ] ).toBe(
			'Newly failing: Homepage and REST API'
		);
		expect( rows().Findings ).toMatch( /^2 high priority/ );
	} );

	it( 'surfaces pending results, never as passed', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'pending' ) );
		expect( rows()[ 'Site checks' ] ).toBe( 'Waiting for results' );
		expect( rows().Findings ).toMatch(
			/^No findings yet — site checks are still pending\./
		);
		// Waiting is not "not assessed".
		expect( rows()[ 'Not assessed' ] ).toBeUndefined();
		expect( summary().textContent ).not.toMatch( /Passed/ );
	} );

	it.each( [
		[ 'no_baseline', 'Not compared' ],
		[
			'unconfirmed',
			'1 of 2 comparedHomepage: not compared · REST API: passed before and after',
		],
		[
			'home_cross_origin',
			'Compared before and afterHomepage: not supported · REST API: passed before and after',
		],
		[
			'failing_both',
			'Failing after the update: HomepageHomepage: failing before and after · REST API: passed before and after',
		],
		[
			'redirect_then_failure',
			'Failing after the update: HomepageHomepage: redirected before, failed after · REST API: passed before and after',
		],
	] as Array< [ CaseName, string ] > )(
		'%s: never passed when it was not compared as passed',
		async ( name, site ) => {
			await renderReport( withSiteChecks( COMPLETED, name ) );
			expect( rows()[ 'Site checks' ] ).toBe( site );
		}
	);

	it( 'checks that were not compared: no findings is not "no problems"', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'no_baseline' ) );
		expect( rows().Findings ).toMatch(
			/^No findings in the completed observations; not all site checks were compared\./
		);
	} );

	it.each( [
		[
			'configuration sources only',
			MALFORMED_CRON,
			null,
			'No findings in the completed observations; some configuration sources were not assessed.',
			'WP-Cron: WP-Cron analysis unavailable',
		],
		[
			'site checks only',
			COMPLETED,
			'after_not_reported',
			'No findings in the completed observations; not all site checks were compared.',
			undefined,
		],
		[
			'one site check only',
			COMPLETED,
			'one_unavailable',
			'No findings in the completed observations; not all site checks were compared.',
			undefined,
		],
		[
			'both',
			MALFORMED_CRON,
			'no_baseline',
			'No findings in the completed observations; some configuration sources were not assessed and not all site checks were compared.',
			'WP-Cron: WP-Cron analysis unavailable',
		],
		[
			'nothing missing',
			COMPLETED,
			'both_passed',
			'No findings matched the completed observations.',
			undefined,
		],
	] as Array<
		[ string, AnalysisReport, CaseName | null, string, string | undefined ]
	> )(
		'without findings, the headline says what is missing: %s',
		async ( _label, base, name, headline, notAssessed ) => {
			await renderReport( name ? withSiteChecks( base, name ) : base );
			// The headline, then the informational count.
			expect( rows().Findings.startsWith( headline ) ).toBe( true );
			// The summary names configuration sources only where its
			// headline mentions them; site checks are in their own row.
			expect( rows()[ 'Not assessed' ] ).toBe( notAssessed );
			expect( document.body.textContent ).not.toMatch(
				/some observations were not assessed/
			);
		}
	);

	it.each( [
		[ 'disabled', 'Off for this update' ],
		[ 'historical', 'Not recorded for this update' ],
		[ 'both_cross_origin', 'Not supported on this site' ],
	] as Array< [ CaseName, string ] > )(
		'%s: one quiet state, nothing to jump to',
		async ( name, site ) => {
			await renderReport( withSiteChecks( COMPLETED, name ) );
			expect( rows()[ 'Site checks' ] ).toBe( site );
			expect( actions() ).not.toContain( 'Site check details' );
		}
	);

	it( 'lists what could not be assessed apart from the priorities', async () => {
		await renderReport( MALFORMED_CRON );
		expect( rows()[ 'Not assessed' ] ).toBe(
			'WP-Cron: WP-Cron analysis unavailable'
		);
		// Not assessed is never a priority.
		expect( rows().Findings ).not.toMatch( /priority|needs? review/ );

		cleanup();
		await renderReport( HISTORICAL_NOT_INSTALLED );
		expect( rows()[ 'Not assessed' ] ).toBe(
			'Action Scheduler not detected (not confirmed for the whole update)'
		);
	} );

	it.each( [
		[ 'no_baseline', 'Not compared' ],
		[ 'after_not_reported', 'Not compared' ],
		[ 'overlapping_update', 'Not compared' ],
		[
			'unconfirmed',
			'1 of 2 comparedHomepage: not compared · REST API: passed before and after',
		],
		[
			'one_unavailable',
			'1 of 2 comparedHomepage: passed before and after · REST API: not compared',
		],
	] as Array< [ CaseName, string ] > )(
		'%s: site checks not compared are said once, in the Site checks row',
		async ( name, site ) => {
			const report = withSiteChecks( COMPLETED, name );
			await renderReport( report );
			expect( rows()[ 'Site checks' ] ).toBe( site );
			// Not repeated under Not assessed; the API's entries are kept.
			expect( rows()[ 'Not assessed' ] ).toBeUndefined();
			expect(
				report.potential_impact.assessment.not_assessed.some(
					( entry ) => entry.type === 'site_check'
				)
			).toBe( true );
			expect( rows().Findings ).not.toMatch( /priority|needs? review/ );
		}
	);

	it( 'still lists configuration sources that were not assessed beside not compared checks', async () => {
		await renderReport( withSiteChecks( MALFORMED_CRON, 'no_baseline' ) );
		expect( rows()[ 'Site checks' ] ).toBe( 'Not compared' );
		expect( rows()[ 'Not assessed' ] ).toBe(
			'WP-Cron: WP-Cron analysis unavailable'
		);
	} );

	it( 'a historical report: sources it predates are not recorded, not unavailable', async () => {
		await renderReport( PRE_CRON );
		expect( rows()[ 'Not assessed' ] ).toMatch(
			/^Not recorded for this report: WP-Cron/
		);
		expect( rows()[ 'Not assessed' ] ).not.toMatch(
			/expired|unavailable/i
		);
		// The coverage line names the historical gap, without a warning.
		const coverage = screen.getByRole( 'region', {
			name: 'Configuration changes',
		} );
		expect( coverage ).toHaveTextContent(
			'WP-Cron not recorded for this report'
		);
		expect( coverage ).not.toHaveTextContent( /not available/ );
	} );

	it( 'an expired observation: no Net result, and what was not assessed', async () => {
		await renderReport( EXPIRED );
		expect( rows().Configuration ).toMatch(
			/^Net result not availableDuring update: \d+ changes?$/
		);
		expect( rows().Findings ).toBe( 'Not assessed in this partial report' );
		// One line naming what expired; why is in the status at the top.
		expect( rows()[ 'Not assessed' ] ).toMatch(
			/^Observation expired: Options & autoload and WP-Cron$/
		);
		// The configuration section says what was observed.
		expect(
			screen.getByRole( 'region', { name: 'Configuration changes' } )
		).toHaveTextContent(
			'During update only: the observation after the update expired.'
		);
	} );

	it( 'an open analysis is still observing, not "not assessed"', async () => {
		await renderReport( AWAITING );
		expect( rows().Findings ).toBe( 'Waiting for the Net result' );
		expect( rows()[ 'Not assessed' ] ).toBeUndefined();
		expect(
			screen.getByRole( 'region', { name: 'Configuration changes' } )
		).toHaveTextContent( /Still observing/ );
	} );

	it( 'a failed analysis: no findings section, the observation did not finish', async () => {
		await renderReport( FAILED );
		expect(
			screen.queryByRole( 'region', { name: 'Findings' } )
		).toBeNull();
		expect( actions() ).not.toContain( 'Go to findings' );
	} );

	it( 'the Action Scheduler row and the coverage use the same words for the same evidence', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ) );
		const configuration = screen.getByRole( 'region', {
			name: 'Configuration changes',
		} );
		expect( configuration ).toHaveTextContent(
			'Options & autoload and WP-Cron observed · Action Scheduler not detected'
		);
		expect(
			screen
				.getByRole( 'link', { name: 'Action Scheduler' } )
				.closest( 'li' )
		).toHaveTextContent( /Not available · Action Scheduler not detected$/ );
	} );
} );
