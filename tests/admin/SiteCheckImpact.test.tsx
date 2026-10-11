import apiFetch from '@wordpress/api-fetch';
import { screen, within } from '@testing-library/react';
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
import { IMPACT } from './impact-fixtures';
import { openReport, tab } from './report-view';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

/*
 * Site checks in Potential Impact, rendered from real API output: every
 * case in tests/fixtures/site-check-impact-cases.json is what PHP returns
 * for stored results (SiteCheckImpactCasesTest keeps the file in sync).
 */

type CaseName = keyof typeof cases.cases;

/**
 * A report with the site checks of one case.
 *
 * @param base Report (its Net result findings stay as they are).
 * @param name Case.
 */
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

/**
 * Opens a report at its URL.
 *
 * @param report  Report served by the API.
 * @param options View.
 */
async function renderReport(
	report: AnalysisReport,
	options: { phase?: PhaseKey } = {}
) {
	apiFetchMock.mockResolvedValue( report );
	openReport( report.id, options );
	await screen.findByRole( 'heading', { level: 2 } );
}

/** The Potential Impact section. */
const impact = () => screen.getByRole( 'region', { name: 'Potential impact' } );

/** Rows of the findings list (site check findings and finding groups). */
const rows = () =>
	within( within( impact() ).getByRole( 'list', { name: 'Findings' } ) )
		.getAllByRole( 'listitem' )
		.filter(
			( item ) =>
				item.parentElement?.getAttribute( 'aria-label' ) === 'Findings'
		);

/** The expand/collapse button of a row. */
const rowButton = ( row: HTMLElement ) =>
	row.querySelector< HTMLButtonElement >( 'h4 button' )!;

/** Rows' titles without their counts. */
const rowTitles = () =>
	rows().map(
		( row ) => row.querySelector( 'h4 button' )!.firstChild!.textContent
	);

/** Checks listed as not complete, outside "What was checked". */
const incompleteTexts = () =>
	Array.from(
		within( impact() )
			.queryByRole( 'list', { name: 'Checks that did not run' } )
			?.querySelectorAll( 'li' ) ?? []
	).map( ( item ) => item.textContent );

/** "What was checked" rows: label → text. */
async function checkedRows( user: ReturnType< typeof userEvent.setup > ) {
	const details = impact().querySelector( 'details' )!;
	if ( ! details.open ) {
		await user.click( within( impact() ).getByText( 'What was checked' ) );
	}
	return Object.fromEntries(
		within( details.querySelector( 'dl' )! )
			.getAllByRole( 'term' )
			.map( ( term ) => [
				term.textContent,
				term.nextElementSibling!.textContent,
			] )
	);
}

/** Wording that must never appear: no verdicts, causes or invented destinations. */
const FORBIDDEN =
	/\bsafe\b|no issues|no conflicts?|risk-free|healthy|caused by|definitely|PHP fatal|site (is|went) down|was down\b(?!\.)|https?:\/\/|confirmed conflict/i;

describe( 'Site checks in Potential Impact', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'homepage newly failing, REST passed: one compact row, first', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'home_newly_failing' ) );

		expect(
			within( impact() ).getByText( '1 finding to review' )
		).toBeInTheDocument();
		expect(
			within( impact() ).getByText(
				'Based on Net result and site checks'
			)
		).toBeInTheDocument();
		const [ row ] = rows();
		expect( rowTitles() ).toEqual( [ 'Homepage check newly failing' ] );
		expect( row ).toHaveTextContent( 'Site checks · Homepage' );
		expect( rowButton( row ) ).toHaveAccessibleName(
			'Homepage check newly failing, 1 finding'
		);
		expect( rowButton( row ) ).toHaveAccessibleDescription(
			'Homepage returned HTTP 500 twice after the update. Check whether it is accessible now.'
		);
		expect( rowButton( row ) ).toHaveAttribute( 'aria-expanded', 'false' );
		// Collapsed: no evidence in the DOM.
		expect( row ).not.toHaveTextContent( 'What changed' );
		expect( incompleteTexts() ).toEqual( [] );
	} );

	it( 'expands to the exact earlier and later evidence with the keyboard', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'home_newly_failing' ) );
		const [ row ] = rows();

		rowButton( row ).focus();
		await user.keyboard( '{Enter}' );
		expect( rowButton( row ) ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( rowButton( row ) ).toHaveFocus();
		expect( row ).toHaveTextContent(
			'The homepage responded successfully during the earlier check. Two later requests returned HTTP 500.'
		);
		const evidence = within( row ).getAllByRole( 'definition' );
		expect( evidence[ 0 ] ).toHaveTextContent(
			/^Passed.*4[.,]2 minutes before the update started.*Request 1: HTTP 200 · WordPress verified · page completion marker present · 0[.,]3 seconds$/
		);
		expect( evidence[ 1 ] ).toHaveTextContent(
			/^Failed in both requests.*3 seconds after the update finished.*Request 1: HTTP 500 · WordPress verified · 0[.,]2 seconds.*Request 2: HTTP 500 · WordPress verified · 0[.,]2 seconds$/
		);
		expect( row ).toHaveTextContent(
			'Visitors may see an error instead of the homepage.'
		);
		expect( row ).toHaveTextContent(
			'The check does not establish what caused the change.'
		);
		expect( row ).toHaveTextContent(
			'Open the homepage in a private window to see whether the problem continues.'
		);
		expect( row ).toHaveTextContent(
			'If the problem continues, review your hosting and PHP error logs.'
		);
		// No signal page to open: the evidence is here.
		expect( within( row ).queryByRole( 'link' ) ).not.toBeInTheDocument();

		await user.keyboard( ' ' );
		expect( rowButton( row ) ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( row ).not.toHaveTextContent( 'What changed' );
	} );

	it( 'REST newly failing, homepage passed: block editor next step', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'rest_newly_failing' ) );

		expect( rowTitles() ).toEqual( [ 'REST API check newly failing' ] );
		expect( rowButton( rows()[ 0 ] ) ).toHaveAccessibleDescription(
			'The REST API returned invalid JSON twice after the update. Check whether the block editor works.'
		);
		await user.click( rowButton( rows()[ 0 ] ) );
		expect( rows()[ 0 ] ).toHaveTextContent(
			'The REST API responded as expected during the earlier check. Two later requests returned a response that was not valid JSON.'
		);
		expect( rows()[ 0 ] ).toHaveTextContent(
			'Request 1: HTTP 200 · WordPress verified · invalid JSON'
		);
		expect( rows()[ 0 ] ).toHaveTextContent(
			'The block editor and other features that use the REST API may not work as expected.'
		);
		expect( rows()[ 0 ] ).toHaveTextContent(
			'Open the block editor and try opening or saving a draft.'
		);
		expect( rows()[ 0 ] ).toHaveTextContent( 'Review PHP warnings' );
	} );

	it( 'both newly failing: two rows, homepage first', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_newly_failing' ) );

		expect(
			within( impact() ).getByText( '2 findings to review' )
		).toBeInTheDocument();
		expect( rowTitles() ).toEqual( [
			'Homepage check newly failing',
			'REST API check newly failing',
		] );
		expect( rowButton( rows()[ 1 ] ) ).toHaveAccessibleDescription(
			'The REST API returned HTTP 500 twice after the update. Check whether the block editor works.'
		);
	} );

	it( 'a new homepage redirect is a changed response, not a failure', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'home_redirect' ) );

		expect( rowTitles() ).toEqual( [ 'Homepage now redirects' ] );
		await user.click( rowButton( rows()[ 0 ] ) );
		const row = rows()[ 0 ];
		expect( row ).toHaveTextContent(
			'Two later requests were redirected. The browser cannot see where a redirect leads.'
		);
		expect( row ).toHaveTextContent( 'Redirected in both requests' );
		expect( row ).toHaveTextContent(
			'Request 1: Redirected (the browser cannot see where)'
		);
		expect( row ).toHaveTextContent(
			'A redirect is a changed response, not a failure.'
		);
		expect( row.textContent ).not.toMatch(
			/fail(ed|ing)? in|newly failing/i
		);
		expect( row.textContent ).not.toMatch( FORBIDDEN );
	} );

	it( 'a redirect that disappeared is an observation in the check details, not a finding', async () => {
		const user = userEvent.setup();
		await renderReport(
			withSiteChecks( COMPLETED, 'redirect_disappeared' )
		);

		expect(
			within( impact() ).queryByRole( 'list', { name: 'Findings' } )
		).not.toBeInTheDocument();
		expect(
			within( impact() ).getByText(
				'No findings matched the completed observations.'
			)
		).toBeInTheDocument();
		expect( ( await checkedRows( user ) )[ 'Homepage check' ] ).toMatch(
			/^Changed · The homepage redirected before the update and loaded directly after it\./
		);
	} );

	it( 'a redirect followed by a failure keeps the comparison, without a newly failing finding', async () => {
		const user = userEvent.setup();
		await renderReport(
			withSiteChecks( COMPLETED, 'redirect_then_failure' )
		);

		expect(
			within( impact() ).queryByRole( 'list', { name: 'Findings' } )
		).not.toBeInTheDocument();
		const checked = await checkedRows( user );
		expect( checked[ 'Homepage check' ] ).toMatch(
			/^Changed · The homepage redirected before the update and failed in both requests after it\. The earlier check was a redirect, not a verified pass, so this is not reported as a newly failing check\./
		);
		expect( impact().textContent ).not.toMatch( /Newly failing/ );
	} );

	it( 'confirmed timeouts never claim the site was down', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'home_timeouts' ) );

		expect( rowButton( rows()[ 0 ] ) ).toHaveAccessibleDescription(
			'Two homepage requests timed out after the update. Check whether it loads now.'
		);
		await user.click( rowButton( rows()[ 0 ] ) );
		const row = rows()[ 0 ];
		expect( row ).toHaveTextContent(
			'Request 1: No response within 10 seconds'
		);
		expect( row ).toHaveTextContent(
			'they do not show that the site was down.'
		);
		expect( row.textContent ).not.toMatch( FORBIDDEN );
	} );

	it( 'an incomplete homepage: the later page missed its completion marker', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'home_incomplete' ) );

		await user.click( rowButton( rows()[ 0 ] ) );
		expect( rows()[ 0 ] ).toHaveTextContent(
			'WordPress answered two later requests, but the page did not reach the expected completion marker.'
		);
		expect( rows()[ 0 ] ).toHaveTextContent(
			'HTTP 200 · WordPress verified · page completion marker missing'
		);
	} );

	it( 'a page too large to inspect both times is not a broken page', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'home_not_inspected' ) );

		expect(
			within( impact() ).queryByRole( 'list', { name: 'Findings' } )
		).not.toBeInTheDocument();
		expect( incompleteTexts() ).toEqual( [
			'Homepage check: Could not be compared · The earlier check was inconclusive: the response was too large to inspect.',
		] );
	} );

	it( 'an unverified HTTP 500 is reported as observed, without attribution', async () => {
		const user = userEvent.setup();
		await renderReport(
			withSiteChecks( COMPLETED, 'home_unverified_500' )
		);

		await user.click( rowButton( rows()[ 0 ] ) );
		const row = rows()[ 0 ];
		expect( row ).toHaveTextContent(
			/Request 1: HTTP 500 · Not verified as WordPress · 0[.,]1 seconds/
		);
		expect( row ).toHaveTextContent(
			'These responses did not carry the UpdateLens verification marker, so the check cannot tell which part of the setup produced them.'
		);
		expect( row ).toHaveTextContent(
			'If the error page does not look like your site, also check any proxy, CDN or firewall in front of it.'
		);
		expect( row.textContent ).not.toMatch(
			/WordPress (returned|produced)|the host (returned|caused)|the plugin|updated plugin/i
		);
	} );

	it( 'both checks passed: no row, both listed as passed with their timing', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ) );

		expect(
			within( impact() ).getByText(
				'No findings matched the completed observations.'
			)
		).toBeInTheDocument();
		expect(
			within( impact() ).getByText(
				'Based on Net result and site checks'
			)
		).toBeInTheDocument();
		const checked = await checkedRows( user );
		expect( checked[ 'Homepage check' ] ).toMatch(
			/^Passed at both observation points · The homepage came back from WordPress with its completion marker before and after the update.Earlier check 4[.,]2 minutes before the update started; later check 3 seconds after the update finished\.$/
		);
		expect( checked[ 'REST API check' ] ).toMatch(
			/^Passed at both observation points/
		);
		const details = impact().querySelector( 'details' )!;
		expect( details ).toHaveTextContent(
			'A passing check shows that one page or request responded as expected at that moment, not that every page or feature works.'
		);
		expect( details ).toHaveTextContent(
			'Site checks: a homepage or REST API check that passed before the update'
		);
		// The Net result limitation names the signals it covers.
		expect( details ).toHaveTextContent(
			'Options & autoload, WP-Cron and Action Scheduler checks use the Net result only'
		);
		expect( impact().textContent ).not.toMatch( FORBIDDEN );
	} );

	it( 'failing before and after is a check detail, not a new finding', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'failing_both' ) );

		expect(
			within( impact() ).queryByRole( 'list', { name: 'Findings' } )
		).not.toBeInTheDocument();
		expect( incompleteTexts() ).toEqual( [] );
		expect( ( await checkedRows( user ) )[ 'Homepage check' ] ).toMatch(
			/^Already failing at the earlier observation · It failed before and after the update, so it is not a new finding\.Earlier check 4[.,]2 minutes before the update started; later check 3 seconds after the update finished\.$/
		);
	} );

	it( 'a recovery is an observation in the check details', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'recovered' ) );

		expect(
			within( impact() ).queryByRole( 'list', { name: 'Findings' } )
		).not.toBeInTheDocument();
		expect( ( await checkedRows( user ) )[ 'Homepage check' ] ).toMatch(
			/^Recovered · It failed before the update and passed after it\./
		);
	} );

	it( 'a failure followed by a passing retry is not confirmed', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'unconfirmed' ) );

		expect(
			within( impact() ).queryByRole( 'list', { name: 'Findings' } )
		).not.toBeInTheDocument();
		expect( incompleteTexts() ).toEqual( [
			'Homepage check: Could not be compared · The later check was inconclusive: one request failed and the retry did not confirm it.',
		] );
		const checked = await checkedRows( user );
		expect( checked[ 'Homepage check' ] ).not.toMatch( /Passed|finding/ );
		expect(
			impact().querySelector( 'dd[data-category="unavailable"]' )
		).toHaveClass( 'text-amber-900' );
	} );

	it.each( [
		[
			'no_baseline',
			'No earlier check from this administrator’s login session was available when the update started. Earlier checks run on the Plugins and Updates screens.',
		],
		[
			'after_not_reported',
			'The later check started, but its result was not reported, for example because the page was closed while it ran.',
		],
		[
			'overlapping_update',
			'Another update ran after this one finished, before the later check was reported.',
		],
	] as Array< [ CaseName, string ] > )(
		'%s: both checks stay visibly not compared',
		async ( name, reason ) => {
			const user = userEvent.setup();
			await renderReport( withSiteChecks( COMPLETED, name ) );

			expect( incompleteTexts() ).toEqual( [
				`Homepage check: Could not be compared · ${ reason }`,
				`REST API check: Could not be compared · ${ reason }`,
			] );
			// Not compared is never "no findings" for site checks.
			expect(
				within( impact() ).getByText( 'Based on Net result' )
			).toBeInTheDocument();
			const checked = await checkedRows( user );
			expect( checked[ 'Homepage check' ] ).toBe(
				`Could not be compared · ${ reason }`
			);
			expect( impact().textContent ).not.toMatch( /Passed at both/ );
		}
	);

	it( 'disabled: one quiet row, no warning', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'disabled' ) );

		// Off for this update: the result is complete, nothing is pending.
		expect(
			within( impact() ).getByText(
				'No findings matched the completed observations.'
			)
		).toBeInTheDocument();
		expect( incompleteTexts() ).toEqual( [] );
		const checked = await checkedRows( user );
		expect( checked[ 'Site checks' ] ).toBe(
			'Not used · Site checks were disabled for this update.'
		);
		expect( checked[ 'Homepage check' ] ).toBeUndefined();
		expect( impact().querySelector( 'details' ) ).not.toHaveTextContent(
			'Site checks: a homepage'
		);
		expect(
			within( impact() ).getByText( 'Based on Net result' )
		).toBeInTheDocument();
	} );

	it( 'historical report without site check data: quiet, not a failure', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'historical' ) );

		expect( incompleteTexts() ).toEqual( [] );
		expect( ( await checkedRows( user ) )[ 'Site checks' ] ).toBe(
			'Not captured · This update was observed before UpdateLens recorded site checks.'
		);
		expect(
			impact().querySelector( 'dd[data-category="not_applicable"]' )
		).toHaveClass( 'text-slate-600' );
	} );

	it( 'one check compared, one unavailable', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'one_unavailable' ) );

		expect(
			within( impact() ).getByText(
				'No findings in completed observations; some checks were unavailable.'
			)
		).toBeInTheDocument();
		expect( incompleteTexts() ).toEqual( [
			'REST API check: Could not be compared · The update started before the earlier check finished.',
		] );
		expect(
			within( impact() ).getByText(
				'Based on Net result and site checks'
			)
		).toBeInTheDocument();
		const checked = await checkedRows( user );
		expect( checked[ 'Homepage check' ] ).toMatch(
			/^Passed at both observation points/
		);
		expect( checked[ 'REST API check' ] ).toBe(
			'Could not be compared · The update started before the earlier check finished.'
		);
	} );

	it( 'pending AFTER results: still pending, never passed', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'pending' ) );

		expect(
			within( impact() ).getByText(
				'No findings yet — site checks are still pending.'
			)
		).toBeInTheDocument();
		expect( impact() ).toHaveTextContent(
			'This report checks for the pending site check results every few seconds while it is open.'
		);
		expect(
			within( impact() ).getByRole( 'button', { name: 'Check now' } )
		).toBeInTheDocument();
		expect( incompleteTexts() ).toEqual( [
			'Homepage check: Still pending · Waiting for the later check from the browser that ran the update.',
			'REST API check: Still pending · Waiting for the later check from the browser that ran the update.',
		] );
		expect(
			within( impact() ).getByText( 'Based on Net result' )
		).toBeInTheDocument();
	} );

	it( 'a finding already reported while the other check is pending', async () => {
		await renderReport(
			withSiteChecks( COMPLETED, 'pending_with_finding' )
		);

		expect( rowTitles() ).toEqual( [ 'REST API check newly failing' ] );
		expect( rowButton( rows()[ 0 ] ) ).toHaveAccessibleDescription(
			'The REST API returned other output before its JSON twice after the update. Check whether the block editor works.'
		);
		expect( incompleteTexts() ).toEqual( [
			'Homepage check: Still pending · Waiting for the later check from the browser that ran the update.',
		] );
		expect(
			within( impact() ).getByText(
				'Based on Net result and site checks'
			)
		).toBeInTheDocument();
	} );

	it( 'stays the same in every phase and is not described as the Net result', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( IMPACT, 'home_newly_failing' ), {
			phase: 'during_update',
		} );

		expect( impact() ).toHaveTextContent(
			'You are viewing During update. These findings do not come from that phase: Potential Impact uses the Net result (before the update compared with after it) and site checks taken just before and after the update.'
		);
		await user.click( rowButton( rows()[ 0 ] ) );
		const titles = rowTitles();

		for ( const name of [ /^After update/, /^Net result/ ] ) {
			await user.click( tab( name ) );
			expect( rowTitles() ).toEqual( titles );
			// The expanded row stays expanded.
			expect( rowButton( rows()[ 0 ] ) ).toHaveAttribute(
				'aria-expanded',
				'true'
			);
		}
		expect( impact() ).not.toHaveTextContent( 'You are viewing' );
		expect( rows()[ 0 ].textContent ).not.toMatch( /Net result/ );
	} );

	it( 'puts site checks before Net result findings and keeps their links', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( IMPACT, 'home_newly_failing' ) );

		expect(
			within( impact() ).getByText( '6 findings to review' )
		).toBeInTheDocument();
		expect( impact() ).toHaveTextContent(
			'Site check results and changes in the Net result that are worth a closer look.'
		);
		expect( rowTitles() ).toEqual( [
			'Homepage check newly failing',
			'Large autoloaded options',
			'Recurring WP-Cron event no longer observed',
			'Recurring WP-Cron schedule changed',
			'Recurring Action Scheduler schedule changed',
		] );

		// Net result deep links and focus on return are unchanged.
		await user.click( rowButton( rows()[ 2 ] ) );
		await user.click(
			within( impact() ).getByRole( 'link', {
				name: 'View in WP-Cron: acme_sync_feeds',
			} )
		);
		expect(
			await screen.findByRole( 'heading', { level: 2, name: 'WP-Cron' } )
		).toHaveFocus();
		expect( window.location.search ).toBe(
			'?page=updatelens&analysis=60&signal=cron&phase=final&item=acme_sync_feeds&kind=removed&finding=2'
		);
		await user.click(
			within(
				screen.getByRole( 'navigation', { name: 'Breadcrumb' } )
			).getByRole( 'link', { name: 'Acme Feeds' } )
		);
		expect(
			within( impact() ).getByRole( 'link', {
				name: 'View in WP-Cron: acme_sync_feeds',
			} )
		).toHaveFocus();
		expect( rowButton( rows()[ 0 ] ) ).toHaveAttribute(
			'aria-expanded',
			'false'
		);
	} );

	it.each( Object.keys( cases.cases ) as CaseName[] )(
		'%s: no verdicts, causes, URLs or raw codes',
		async ( name ) => {
			const user = userEvent.setup();
			await renderReport( withSiteChecks( IMPACT, name ) );
			for ( const row of rows() ) {
				if (
					rowButton( row ).getAttribute( 'aria-expanded' ) !== 'true'
				) {
					await user.click( rowButton( row ) );
				}
			}
			await checkedRows( user );

			const text = impact().textContent ?? '';
			expect( text ).not.toMatch( FORBIDDEN );
			expect( text ).not.toMatch(
				/newly_failing|not_comparable|after_inconclusive|baseline_missing|not_reported|server_error|end_marker|\bnull\b|undefined|\[object/
			);
		}
	);
} );
