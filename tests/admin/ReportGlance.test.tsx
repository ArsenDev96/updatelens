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
 * response checks and observation coverage as three separate facts, never
 * one verdict on the update. Newly failing and pending checks show here,
 * before the longer sections, a finding with its next step and a link to
 * it; coverage names what was observed, sources and site responses apart.
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

/** The coverage lines. */
function coverage(): string[] {
	const term = within( summary() ).getByText( 'Observation coverage' );
	return within( term.nextElementSibling as HTMLElement )
		.getAllByRole( 'listitem' )
		.map( ( item ) => item.textContent ?? '' );
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

	it( 'keeps configuration changes, site checks and coverage apart, without a verdict', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ) );
		expect( Object.keys( glance() ) ).toEqual( [
			'Configuration changes',
			'Site response checks',
			'Observation coverage',
		] );
		expect( glance()[ 'Configuration changes' ] ).toMatch(
			/^\d+ changes? observedNet result · /
		);
		expect( glance()[ 'Site response checks' ] ).toBe(
			'Passed before and afterHomepage: passed before and after · REST API: passed before and after'
		);
		// What was observed, sources and site responses apart; never "complete".
		expect( coverage() ).toEqual( [
			'Configuration sources: Options & autoload and WP-Cron observed · Action Scheduler not detected',
			'Site responses: Homepage and REST API compared',
		] );
		expect( summary().textContent ).not.toMatch( FORBIDDEN );
	} );

	it( 'a newly failing homepage: its next step and a link to the finding, not a cause', async () => {
		vi.useFakeTimers( { shouldAdvanceTime: true } );
		try {
			const user = userEvent.setup( {
				advanceTimers: vi.advanceTimersByTime,
			} );
			await renderReport(
				withSiteChecks( COMPLETED, 'home_newly_failing' )
			);
			expect( glance()[ 'Site response checks' ] ).toBe(
				'Newly failing: HomepageHomepage: newly failing · REST API: passed before and afterHomepage: Check whether the homepage is accessible now. Show the homepage findingAn observed result, not proof that the updated plugin caused it.'
			);
			await user.click(
				within( summary() ).getByRole( 'button', {
					name: 'Show the homepage finding',
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

	it( 'a newly failing REST API: check the block editor', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'rest_newly_failing' ) );
		expect( glance()[ 'Site response checks' ] ).toMatch(
			/^Newly failing: REST API.*REST API: Check whether the block editor works\. Show the REST API finding/
		);
	} );

	it( 'names both checks when both newly fail, each with its next step', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_newly_failing' ) );
		expect( glance()[ 'Site response checks' ] ).toMatch(
			/^Newly failing: Homepage and REST API/
		);
		expect(
			within( summary() ).getAllByRole( 'button', {
				name: /^Show the (homepage|REST API) finding$/,
			} )
		).toHaveLength( 2 );
	} );

	it( 'surfaces pending results', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'pending' ) );
		expect( glance()[ 'Site response checks' ] ).toMatch(
			/^Waiting for results/
		);
		expect( coverage()[ 1 ] ).toBe(
			'Site responses: Waiting for the checks after the update'
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
		expect( coverage()[ 1 ] ).toBe(
			'Site responses: REST API compared · Homepage waiting for results'
		);
	} );

	it.each( [
		[
			'no_baseline',
			'Not comparedHomepage: not compared · REST API: not compared',
			'Site responses: Homepage and REST API not compared',
		],
		[
			'unconfirmed',
			'1 of 2 comparedHomepage: not compared · REST API: passed before and after',
			'Site responses: REST API compared · Homepage not compared',
		],
		[
			'home_cross_origin',
			'Compared before and afterHomepage: not supported · REST API: passed before and after',
			'Site responses: REST API compared · Homepage not supported',
		],
		[
			'failing_both',
			'Failing after the update: HomepageHomepage: failing before and after · REST API: passed before and after',
			'Site responses: Homepage and REST API compared',
		],
		[
			'redirect_then_failure',
			'Failing after the update: HomepageHomepage: redirected before, failed after · REST API: passed before and after',
			'Site responses: Homepage and REST API compared',
		],
	] as Array< [ CaseName, string, string ] > )(
		'%s: never passed when it was not compared as passed',
		async ( name, site, responses ) => {
			await renderReport( withSiteChecks( COMPLETED, name ) );
			expect( glance()[ 'Site response checks' ] ).toBe( site );
			expect( coverage()[ 1 ] ).toBe( responses );
		}
	);

	it.each( [
		[
			'disabled',
			'Off for this update',
			'Site responses: Not checked: site checks were off',
		],
		[
			'historical',
			'Not recorded for this update',
			'Site responses: Not checked: not recorded for this update',
		],
		[
			'both_cross_origin',
			'Not supported on this site',
			'Site responses: Not checked: not supported on this site',
		],
	] as Array< [ CaseName, string, string ] > )(
		'%s: one quiet state, and coverage says the site was not checked',
		async ( name, site, responses ) => {
			await renderReport( withSiteChecks( COMPLETED, name ) );
			expect( glance()[ 'Site response checks' ] ).toBe( site );
			expect( coverage()[ 1 ] ).toBe( responses );
		}
	);

	it( 'an Action Scheduler that could not be observed is not "not installed"', async () => {
		await renderReport(
			actionScheduler( 'snapshot_unavailable', 'not_evaluated' )
		);
		expect( coverage()[ 0 ] ).toMatch(
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
		expect( coverage()[ 0 ] ).toMatch( /· Action Scheduler not detected$/ );
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
		expect( coverage()[ 0 ] ).toContain(
			`Action Scheduler not available: ${ title }`
		);
	} );

	it( 'an absence seen at one capture only (older analyses) is not confirmed', async () => {
		await renderReport(
			actionScheduler( 'not_installed', 'not_evaluated' )
		);
		expect( coverage()[ 0 ] ).toBe(
			'Configuration sources: Options & autoload and WP-Cron observed · Action Scheduler not detected (not confirmed for the whole update)'
		);
	} );

	it( 'an expired observation: no Net result, and only the update itself observed', async () => {
		await renderReport( EXPIRED );
		expect( glance()[ 'Configuration changes' ] ).toMatch(
			/^Net result not availableDuring update: \d+ changes?$/
		);
		expect( coverage()[ 0 ] ).toBe(
			'Configuration sources: Observed during the update only: the state after it was not captured in time.'
		);
	} );

	it( 'an open analysis is still observing', async () => {
		await renderReport( AWAITING );
		expect( coverage()[ 0 ] ).toMatch(
			/^Configuration sources: Still observing/
		);
	} );

	it( 'a failed analysis did not finish', async () => {
		await renderReport( FAILED );
		expect( coverage()[ 0 ] ).toBe(
			'Configuration sources: The observation did not finish; see the status above.'
		);
	} );
} );
