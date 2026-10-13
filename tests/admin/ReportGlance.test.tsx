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
import { AWAITING, COMPLETED, EXPIRED, FAILED } from './fixtures';
import { openReport } from './report-view';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

/*
 * The summary below the report header: configuration changes, site
 * response checks and Potential Impact as three separate facts, never one
 * verdict on the update, with the observation coverage of the
 * configuration sources below them. Newly failing and pending checks and
 * findings to review show here, before the longer sections; next steps and
 * explanations stay in those sections.
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

async function renderReport( report: AnalysisReport ) {
	apiFetchMock.mockResolvedValue( report );
	openReport( report.id );
	await screen.findByRole( 'heading', { level: 2 } );
}

const summary = () => screen.getByRole( 'region', { name: 'Report summary' } );

/** The summary as label → text. */
function glance(): Record< string, string > {
	return Object.fromEntries(
		within( summary() )
			.getAllByRole( 'term' )
			.map( ( term ) => [
				term.textContent,
				term.nextElementSibling!.textContent,
			] )
	);
}

/** The coverage line below the three facts. */
function coverage(): string {
	return summary().querySelector( ':scope > p' )?.textContent ?? '';
}

const FORBIDDEN =
	/\bsafe\b|healthy|no issues|no problems|passed update|update passed|successful update|caused by|\brisk|\bcomplete\b/i;

/**
 * A report whose Action Scheduler Net result is unavailable for a reason.
 *
 * @param reason     Phase reason.
 * @param applicable Potential Impact's status for the signal.
 */
function actionScheduler(
	reason: string,
	applicable: 'not_applicable' | 'not_evaluated'
): AnalysisReport {
	const report = withSiteChecks( COMPLETED, 'both_passed' );
	return {
		...report,
		phases: {
			...report.phases,
			final: {
				...report.phases.final,
				action_scheduler: {
					...report.phases.final.action_scheduler,
					available: false,
					reason,
				} as AnalysisReport[ 'phases' ][ 'final' ][ 'action_scheduler' ],
			},
		},
		potential_impact: {
			...report.potential_impact,
			signals: {
				...report.potential_impact.signals,
				action_scheduler: {
					...report.potential_impact.signals.action_scheduler,
					status: applicable,
					reason,
				} as AnalysisReport[ 'potential_impact' ][ 'signals' ][ 'action_scheduler' ],
			},
		},
	};
}

describe( 'Report summary', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'comes right after the header and before the site response checks and phases', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ) );
		const following = ( other: Element ) =>
			summary().compareDocumentPosition( other ) &
			Node.DOCUMENT_POSITION_FOLLOWING;
		expect(
			following(
				screen.getByRole( 'region', { name: 'Site response checks' } )
			)
		).toBeTruthy();
		expect( following( screen.getByRole( 'tablist' ) ) ).toBeTruthy();
		expect(
			screen.getByRole( 'banner' ).compareDocumentPosition( summary() ) &
				Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
	} );

	it( 'keeps configuration changes, site checks and Potential Impact apart, without a verdict', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ) );
		expect( Object.keys( glance() ) ).toEqual( [
			'Configuration changes',
			'Site response checks',
			'Potential impact',
		] );
		expect( glance()[ 'Configuration changes' ] ).toMatch(
			/^\d+ changes? observedNet result · /
		);
		// Both checks in the same state: said once.
		expect( glance()[ 'Site response checks' ] ).toBe(
			'Passed before and after'
		);
		// No findings is worded by completeness, with what it was based on.
		expect( glance()[ 'Potential impact' ] ).toBe(
			'No findings matched the completed observations.Based on Net result and site checks'
		);
		expect(
			within( summary() ).queryByRole( 'button' )
		).not.toBeInTheDocument();
		// What was observed of the configuration sources; never "complete".
		expect( coverage() ).toBe(
			'Configuration sources: Options & autoload and WP-Cron observed · Action Scheduler not detected'
		);
		expect( summary().textContent ).not.toMatch( FORBIDDEN );
	} );

	it( 'a newly failing homepage: the outcome and a finding to review, next steps stay below', async () => {
		vi.useFakeTimers( { shouldAdvanceTime: true } );
		try {
			const user = userEvent.setup( {
				advanceTimers: vi.advanceTimersByTime,
			} );
			await renderReport(
				withSiteChecks( COMPLETED, 'home_newly_failing' )
			);
			expect( glance()[ 'Site response checks' ] ).toBe(
				'Newly failing: HomepageHomepage: newly failing · REST API: passed before and after'
			);
			expect( glance()[ 'Potential impact' ] ).toBe(
				'1 finding to reviewBased on Net result and site checksGo to findings'
			);
			// The next step is in Site response checks and Potential Impact.
			expect( summary().textContent ).not.toMatch( /Check whether/ );
			expect( summary().textContent ).not.toMatch( FORBIDDEN );
			await user.click(
				within( summary() ).getByRole( 'button', {
					name: 'Go to findings',
				} )
			);
			await act( () => vi.runOnlyPendingTimersAsync() );
			expect(
				screen.getByRole( 'heading', { name: 'Potential impact' } )
			).toHaveFocus();
		} finally {
			vi.useRealTimers();
		}
	} );

	it( 'a newly failing REST API', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'rest_newly_failing' ) );
		expect( glance()[ 'Site response checks' ] ).toMatch(
			/^Newly failing: REST APIHomepage: passed before and after · REST API: newly failing$/
		);
	} );

	it( 'names both checks when both newly fail', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_newly_failing' ) );
		expect( glance()[ 'Site response checks' ] ).toBe(
			'Newly failing: Homepage and REST API'
		);
		expect( glance()[ 'Potential impact' ] ).toMatch(
			/^2 findings to review/
		);
	} );

	it( 'surfaces pending results', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'pending' ) );
		expect( glance()[ 'Site response checks' ] ).toBe(
			'Waiting for results'
		);
		expect( glance()[ 'Potential impact' ] ).toMatch(
			/^No findings yet — site checks are still pending\./
		);
		expect( summary().textContent ).not.toMatch( /Passed/ );
	} );

	it( 'a finding while the other check is still pending: both are said', async () => {
		await renderReport(
			withSiteChecks( COMPLETED, 'pending_with_finding' )
		);
		expect( glance()[ 'Site response checks' ] ).toMatch(
			/^Newly failing: REST APIHomepage: waiting for results/
		);
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
			expect( glance()[ 'Site response checks' ] ).toBe( site );
		}
	);

	it.each( [
		[ 'disabled', 'Off for this update' ],
		[ 'historical', 'Not recorded for this update' ],
		[ 'both_cross_origin', 'Not supported on this site' ],
	] as Array< [ CaseName, string ] > )(
		'%s: one quiet state',
		async ( name, site ) => {
			await renderReport( withSiteChecks( COMPLETED, name ) );
			expect( glance()[ 'Site response checks' ] ).toBe( site );
		}
	);

	it( 'an Action Scheduler that could not be observed is not "not installed"', async () => {
		await renderReport(
			actionScheduler( 'snapshot_unavailable', 'not_evaluated' )
		);
		expect( coverage() ).toMatch(
			/^Configuration sources: Options & autoload and WP-Cron observed · Action Scheduler not available: /
		);
	} );

	it( 'the Action Scheduler card and the coverage use the same words for the same evidence', async () => {
		const card = () =>
			screen
				.getByRole( 'link', { name: 'Action Scheduler' } )
				.closest( 'li' )!;

		// Absent at every capture (Potential Impact: not applicable).
		await renderReport(
			actionScheduler( 'not_installed', 'not_applicable' )
		);
		expect( coverage() ).toMatch( /· Action Scheduler not detected$/ );
		expect( card() ).toHaveTextContent(
			/Not available Action Scheduler not detectedView details$/
		);

		// Seen absent at the start of a phase only: not confirmed.
		cleanup();
		await renderReport(
			actionScheduler( 'not_installed', 'not_evaluated' )
		);
		expect( card() ).toHaveTextContent(
			'Action Scheduler not detected (not confirmed for the whole update)'
		);

		// Installed, but its snapshot could not be read.
		cleanup();
		await renderReport(
			actionScheduler( 'snapshot_unavailable', 'not_evaluated' )
		);
		// The card's reason title, as the coverage names it.
		const title = card()
			.textContent!.replace( /^.*Not available /, '' )
			.replace( /View details$/, '' );
		expect( title ).not.toBe( '' );
		expect( coverage() ).toContain(
			`Action Scheduler not available: ${ title }`
		);
	} );

	it( 'an absence seen at one capture only (older analyses) is not confirmed', async () => {
		await renderReport(
			actionScheduler( 'not_installed', 'not_evaluated' )
		);
		expect( coverage() ).toBe(
			'Configuration sources: Options & autoload and WP-Cron observed · Action Scheduler not detected (not confirmed for the whole update)'
		);
	} );

	it( 'an expired observation: no Net result, and only the update itself observed', async () => {
		await renderReport( EXPIRED );
		expect( glance()[ 'Configuration changes' ] ).toMatch(
			/^Net result not availableDuring update: \d+ changes?$/
		);
		expect( glance()[ 'Potential impact' ] ).toBe(
			'Not available for this report'
		);
		expect( coverage() ).toBe(
			'Configuration sources: Observed during the update only: the state after it was not captured in time.'
		);
	} );

	it( 'an open analysis is still observing', async () => {
		await renderReport( AWAITING );
		expect( glance()[ 'Potential impact' ] ).toBe(
			'Waiting for the Net result'
		);
		expect( coverage() ).toMatch(
			/^Configuration sources: Still observing/
		);
	} );

	it( 'a failed analysis did not finish', async () => {
		await renderReport( FAILED );
		expect( coverage() ).toBe(
			'Configuration sources: The observation did not finish; see the status above.'
		);
	} );
} );
