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
import { COMPLETED, withSiteCheckCase } from './fixtures';
import { IMPACT } from './impact-fixtures';
import { openReport, reportPanel, reportTab, tab } from './report-view';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

/*
 * Website checks in Update results, rendered from real API output: every
 * case in tests/fixtures/site-check-impact-cases.json is what PHP returns
 * for stored results (SiteCheckImpactCasesTest keeps the file in sync).
 * Findings, guidance and coverage stay in Update results; the requests
 * before and after are in the Website checks tab (SiteResponseChecks.test.tsx),
 * one link away.
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
	return withSiteCheckCase(
		base,
		data.site_checks as SiteChecks,
		data.potential_impact_site_checks as SiteCheckImpact
	);
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
	openReport( report.id, { ...options, tab: 'results' } );
	await screen.findByRole( 'heading', { level: 2 } );
}

/** The Update results tab's content. */
const impact = () => reportPanel();

/** The website checks section (Website checks tab). */
const siteSection = () =>
	screen.getByRole( 'region', { name: 'Website checks' } );

/** The findings lists (by priority), or none. */
const findingLists = () =>
	impact().querySelectorAll( 'section[data-priority]' );

/** Rows of the findings lists (site check findings and finding groups). */
const rows = () =>
	Array.from(
		impact().querySelectorAll< HTMLElement >(
			'section[data-priority] > ul > li'
		)
	);

/** Priority groups as their headings' accessible names, in order. */
const priorityGroups = () =>
	Array.from( findingLists() ).map(
		( group ) =>
			within( group as HTMLElement ).getByRole( 'heading', { level: 3 } )
				.textContent
	);

/** The panel shown without findings. */
const resultPanel = () =>
	document.querySelector< HTMLElement >(
		'[aria-labelledby="updatelens-result-title"]'
	);

/** "Not assessed", or null. */
const notAssessed = () =>
	screen.queryByRole( 'region', { name: 'Not assessed' } );

/** The expand/collapse button of a row. */
const rowButton = ( row: HTMLElement ) =>
	row.querySelector< HTMLButtonElement >( 'h4 button' )!;

/** Rows' titles without their counts. */
const rowTitles = () =>
	rows().map(
		( row ) => row.querySelector( 'h4 button' )!.firstChild!.textContent
	);

/** The review rules' content (after "View review rules"), or null. */
const rules = () => document.getElementById( 'updatelens-review-rules' )!;

/** "What was checked" rows: label → text. */
async function checkedRows( user: ReturnType< typeof userEvent.setup > ) {
	const button = screen.getByRole( 'button', {
		name: /review rules$/,
	} );
	if ( button.getAttribute( 'aria-expanded' ) !== 'true' ) {
		await user.click( button );
	}
	return Object.fromEntries(
		within( rules().querySelector( 'dl' )! )
			.getAllByRole( 'term' )
			.map( ( term ) => [
				term.textContent,
				term.nextElementSibling!.textContent,
			] )
	);
}

/**
 * Each check's result in the Website checks tab (opens it).
 *
 * @param user User.
 */
async function siteResults( user: ReturnType< typeof userEvent.setup > ) {
	await user.click( reportTab( 'Website checks' ) );
	return Object.fromEntries(
		within( siteSection() )
			.getAllByRole( 'listitem' )
			.map( ( item ) => [
				item.querySelector( 'h4 button' )!.textContent,
				item.firstElementChild!.querySelectorAll( 'dl dd' )[ 2 ]
					.textContent,
			] )
	);
}

/** Wording that must never appear: no verdicts, causes or invented destinations. */
const FORBIDDEN =
	/\bsafe\b|no issues|no conflicts?|risk-free|healthy|caused by|definitely|PHP fatal|site (is|went) down|was down\b(?!\.)|https?:\/\/|confirmed conflict/i;

const NOTHING_FLAGGED = 'Nothing flagged for review';
const NOT_FULLY = 'Not fully assessed';
/** A finding's requests after the update, as one observation at its time. */
const OBSERVED_ONCE =
	/These requests ran at .+, right after the update; UpdateLens did not check again\./;

describe( 'Website checks in Update results', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'homepage newly failing, REST passed: one compact row, first', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'home_newly_failing' ) );

		expect( priorityGroups() ).toEqual( [ 'High priority, 1' ] );
		expect(
			within( impact() ).getByText(
				'Based on Net result and website checks'
			)
		).toBeInTheDocument();
		const [ row ] = rows();
		expect( rowTitles() ).toEqual( [ 'Homepage check newly failing' ] );
		expect( row ).toHaveTextContent( 'Website checks · Homepage' );
		expect( rowButton( row ) ).toHaveAccessibleName(
			'Homepage check newly failing, 1 finding, High priority'
		);
		expect( rowButton( row ) ).toHaveAccessibleDescription(
			'Homepage returned HTTP 500 twice after the update. Check whether it is accessible now.'
		);
		expect( rowButton( row ) ).toHaveAttribute( 'aria-expanded', 'false' );
		// Collapsed: no evidence in the DOM.
		expect( row ).not.toHaveTextContent( 'What changed' );
		expect( notAssessed() ).toBeNull();
	} );

	it( 'expands to what changed and the guidance, and links to the requests', async () => {
		vi.useFakeTimers( { shouldAdvanceTime: true } );
		try {
			const user = userEvent.setup( {
				advanceTimers: vi.advanceTimersByTime,
			} );
			await renderReport(
				withSiteChecks( COMPLETED, 'home_newly_failing' )
			);
			const [ row ] = rows();

			rowButton( row ).focus();
			await user.keyboard( '{Enter}' );
			expect( rowButton( row ) ).toHaveAttribute(
				'aria-expanded',
				'true'
			);
			expect( rowButton( row ) ).toHaveFocus();
			expect( row ).toHaveTextContent(
				'The homepage responded successfully before the update. Both requests after the update returned HTTP 500. These requests ran at'
			);
			expect( row ).toHaveTextContent( OBSERVED_ONCE );
			expect( row ).toHaveTextContent(
				'Visitors may have seen an error instead of the homepage at that time.'
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
			// The requests are not repeated here, and there is no signal page.
			expect( row ).not.toHaveTextContent( 'Request 1' );
			expect(
				within( row ).queryByRole( 'link' )
			).not.toBeInTheDocument();

			// The link opens the check in Website checks and moves focus there.
			await user.click(
				within( row ).getByRole( 'button', {
					name: 'Show the homepage requests',
				} )
			);
			await act( () => vi.runOnlyPendingTimersAsync() );
			const home = within( siteSection() ).getByRole( 'button', {
				name: 'Homepage',
			} );
			expect( home ).toHaveAttribute( 'aria-expanded', 'true' );
			expect( home ).toHaveFocus();
			expect( reportTab( 'Website checks' ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			expect( siteSection() ).toHaveTextContent(
				/Request 1HTTP 500 · 0[.,]2 secondsSource: Verified as WordPress, also for this error/
			);

			// Back in Update results, the finding is still expanded.
			await user.click( reportTab( 'Update results' ) );
			const again = rows()[ 0 ];
			expect( rowButton( again ) ).toHaveAttribute(
				'aria-expanded',
				'true'
			);
			rowButton( again ).focus();
			await user.keyboard( ' ' );
			expect( rowButton( again ) ).toHaveAttribute(
				'aria-expanded',
				'false'
			);
			expect( again ).not.toHaveTextContent( 'What changed' );
		} finally {
			vi.useRealTimers();
		}
	} );

	it( 'REST newly failing, homepage passed: the checked route, not the whole REST API', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'rest_newly_failing' ) );

		expect( rowTitles() ).toEqual( [ 'REST API check newly failing' ] );
		expect( rowButton( rows()[ 0 ] ) ).toHaveAccessibleDescription(
			'The REST API route /wp/v2/users/me returned invalid JSON twice after the update. Check the features that use it, such as the block editor.'
		);
		await user.click( rowButton( rows()[ 0 ] ) );
		expect( rows()[ 0 ] ).toHaveTextContent(
			'The REST API route /wp/v2/users/me responded as expected before the update. Both requests after the update returned a response that was not valid JSON.'
		);
		expect( rows()[ 0 ] ).toHaveTextContent( OBSERVED_ONCE );
		// Never the whole REST API, and never "the block editor works" as a verdict.
		expect( rows()[ 0 ].textContent ).not.toMatch(
			/The REST API returned|block editor works/
		);
		expect(
			within( rows()[ 0 ] ).getByRole( 'button', {
				name: 'Show the REST API requests',
			} )
		).toBeInTheDocument();
		expect( rows()[ 0 ] ).toHaveTextContent(
			'Features that request this route, such as the block editor, may not have worked as expected at that time.'
		);
		expect( rows()[ 0 ] ).toHaveTextContent(
			'Open the block editor and try opening or saving a draft.'
		);
		expect( rows()[ 0 ] ).toHaveTextContent( 'Review PHP warnings' );
	} );

	it( 'both newly failing: two rows, homepage first', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_newly_failing' ) );

		expect( priorityGroups() ).toEqual( [ 'High priority, 2' ] );
		expect( rowTitles() ).toEqual( [
			'Homepage check newly failing',
			'REST API check newly failing',
		] );
		expect( rowButton( rows()[ 1 ] ) ).toHaveAccessibleDescription(
			'The REST API route /wp/v2/users/me returned HTTP 500 twice after the update. Check the features that use it, such as the block editor.'
		);
	} );

	it( 'REST route missing (verified HTTP 404, rest_no_route): WordPress said the route does not exist', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'rest_route_missing' ) );

		expect( priorityGroups() ).toEqual( [ 'High priority, 1' ] );
		expect( rowTitles() ).toEqual( [ 'REST API check newly failing' ] );
		expect( rowButton( rows()[ 0 ] ) ).toHaveAccessibleDescription(
			'In both requests after the update, WordPress reported that the REST API route /wp/v2/users/me does not exist. Check whether a setting now hides it.'
		);
		await user.click( rowButton( rows()[ 0 ] ) );
		const row = rows()[ 0 ];
		expect( row ).toHaveTextContent(
			'The REST API route /wp/v2/users/me responded as expected before the update. In both requests after the update, WordPress answered HTTP 404 with the error code rest_no_route: no REST API route matched /wp/v2/users/me.'
		);
		expect( row ).toHaveTextContent( OBSERVED_ONCE );
		expect( row ).toHaveTextContent(
			'WordPress itself answered, so this route was not registered on the site at that time. Security or REST API settings can hide user routes on purpose.'
		);
		expect( row ).toHaveTextContent(
			'Check whether a security or REST API setting hides user routes; if it does, this may be intended.'
		);
		expect( row.textContent ).not.toMatch(
			/The REST API returned|block editor works|returned HTTP 404 twice/
		);
		expect( row.textContent ).not.toMatch( FORBIDDEN );
	} );

	it( 'a new homepage redirect is a changed response, not a failure', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'home_redirect' ) );

		expect( rowTitles() ).toEqual( [ 'Homepage now redirects' ] );
		await user.click( rowButton( rows()[ 0 ] ) );
		const row = rows()[ 0 ];
		expect( row ).toHaveTextContent(
			'Both requests after the update were redirected. The browser cannot see where a redirect leads.'
		);
		expect( row ).toHaveTextContent(
			'A redirect is a changed response, not a failure.'
		);
		expect( row.textContent ).not.toMatch(
			/fail(ed|ing)? in|newly failing/i
		);
		expect( row.textContent ).not.toMatch( FORBIDDEN );
	} );

	it( 'a redirect that disappeared is a compared check, not a finding', async () => {
		const user = userEvent.setup();
		await renderReport(
			withSiteChecks( COMPLETED, 'redirect_disappeared' )
		);

		expect( findingLists() ).toHaveLength( 0 );
		expect( resultPanel() ).toHaveTextContent( NOTHING_FLAGGED );
		expect( resultPanel() ).toHaveAttribute( 'data-tone', 'positive' );
		expect( ( await checkedRows( user ) )[ 'Homepage check' ] ).toBe(
			'Changed'
		);
	} );

	it( 'a redirect followed by a failure keeps the comparison, without a newly failing finding', async () => {
		const user = userEvent.setup();
		await renderReport(
			withSiteChecks( COMPLETED, 'redirect_then_failure' )
		);

		expect( findingLists() ).toHaveLength( 0 );
		expect( ( await checkedRows( user ) )[ 'Homepage check' ] ).toBe(
			'Changed'
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
			'they do not show that the site was down.'
		);
		// One observation at its time, worded in the past.
		expect( row ).toHaveTextContent( OBSERVED_ONCE );
		expect( row ).toHaveTextContent(
			'The homepage may have been slow or unreachable for visitors at that time.'
		);
		expect( row.textContent ).not.toMatch( FORBIDDEN );
	} );

	it( 'an incomplete homepage: the page after the update missed its completion marker', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'home_incomplete' ) );

		await user.click( rowButton( rows()[ 0 ] ) );
		expect( rows()[ 0 ] ).toHaveTextContent(
			'WordPress answered both requests after the update, but the page did not reach the expected completion marker.'
		);
	} );

	it( 'a page too large to inspect both times is not a broken page', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'home_not_inspected' ) );

		expect( findingLists() ).toHaveLength( 0 );
		expect( resultPanel() ).toHaveTextContent( NOT_FULLY );
		expect( resultPanel() ).toHaveTextContent(
			'UpdateLens cannot tell whether the homepage responded as it did before the update.'
		);
		const user = userEvent.setup();
		expect( await siteResults( user ) ).toEqual( {
			Homepage: 'Not compared',
			'WordPress REST API': 'Passed before and after',
		} );
	} );

	it( 'an unverified HTTP 500 is reported as observed, without attribution', async () => {
		const user = userEvent.setup();
		await renderReport(
			withSiteChecks( COMPLETED, 'home_unverified_500' )
		);

		await user.click( rowButton( rows()[ 0 ] ) );
		const row = rows()[ 0 ];
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

	it( 'both checks passed: no row, both listed as passed', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ) );

		expect( resultPanel() ).toHaveTextContent( NOTHING_FLAGGED );
		expect( resultPanel() ).toHaveAttribute( 'data-tone', 'positive' );
		const checked = await checkedRows( user );
		// The timing and evidence are in Website checks.
		expect( checked[ 'Homepage check' ] ).toBe(
			'Passed at both observation points'
		);
		expect( checked[ 'REST API check' ] ).toBe(
			'Passed at both observation points'
		);
		const details = rules();
		expect( details ).toHaveTextContent(
			'A passing check shows that one page or request responded as expected at that moment, not that every page or feature works.'
		);
		expect( details ).toHaveTextContent(
			'Website checks: a homepage or REST API check that passed before the update'
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

		expect( findingLists() ).toHaveLength( 0 );
		expect( notAssessed() ).toBeNull();
		// Not a pass: the panel is not green.
		expect( resultPanel() ).toHaveAttribute( 'data-tone', 'neutral' );
		expect( ( await checkedRows( user ) )[ 'Homepage check' ] ).toBe(
			'Already failing before the update'
		);
	} );

	it( 'a recovery is a compared check, not a finding', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'recovered' ) );

		expect( findingLists() ).toHaveLength( 0 );
		expect( ( await checkedRows( user ) )[ 'Homepage check' ] ).toBe(
			'Recovered'
		);
	} );

	it( 'a failure followed by a passing retry is not confirmed', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'unconfirmed' ) );

		expect( findingLists() ).toHaveLength( 0 );
		const checked = await checkedRows( user );
		expect( checked[ 'Homepage check' ] ).toBe( 'Could not be compared' );
		expect(
			impact().querySelector( 'dd[data-category="unavailable"]' )
		).toHaveClass( 'text-amber-900' );
		expect( await siteResults( user ) ).toEqual( {
			Homepage: 'Not compared',
			'WordPress REST API': 'Passed before and after',
		} );
	} );

	it.each( [
		'no_baseline',
		'after_not_reported',
		'overlapping_update',
		'not_followed_up',
		'next_update',
	] as CaseName[] )(
		'%s: both checks stay visibly not compared',
		async ( name ) => {
			const user = userEvent.setup();
			await renderReport( withSiteChecks( COMPLETED, name ) );

			// Not compared is never "no findings" for website checks.
			expect( resultPanel() ).toHaveTextContent( NOT_FULLY );
			expect( resultPanel() ).not.toHaveTextContent( NOTHING_FLAGGED );
			expect( resultPanel() ).toHaveAttribute( 'data-tone', 'neutral' );
			expect( notAssessed() ).toHaveTextContent(
				/Website checks not compared: Homepage and REST API/
			);
			const checked = await checkedRows( user );
			expect( checked[ 'Homepage check' ] ).toBe(
				'Could not be compared'
			);
			expect( impact().textContent ).not.toMatch( /Passed at both/ );
			expect( await siteResults( user ) ).toEqual( {
				Homepage: 'Not compared',
				'WordPress REST API': 'Not compared',
			} );
		}
	);

	it( 'disabled: one quiet row, no warning', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'disabled' ) );

		// Off for this update: the result is complete, nothing is pending.
		expect( resultPanel() ).toHaveAttribute( 'data-tone', 'positive' );
		expect( notAssessed() ).toBeNull();
		const checked = await checkedRows( user );
		expect( checked[ 'Website checks' ] ).toBe(
			'Not used · Website checks were disabled for this update.'
		);
		expect( checked[ 'Homepage check' ] ).toBeUndefined();
		expect( rules() ).not.toHaveTextContent( 'Website checks: a homepage' );
	} );

	it( 'homepage on another origin: not supported, quiet, never passed; REST still compared', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'home_cross_origin' ) );

		expect( resultPanel() ).toHaveTextContent( NOTHING_FLAGGED );
		expect( notAssessed() ).toBeNull();
		const checked = await checkedRows( user );
		expect( checked[ 'Homepage check' ] ).toBe(
			'Not supported on this site'
		);
		expect( checked[ 'REST API check' ] ).toBe(
			'Passed at both observation points'
		);
		expect(
			impact().querySelector( 'dd[data-category="not_applicable"]' )
		).toHaveClass( 'text-slate-600' );
		expect( impact().textContent ).not.toMatch( FORBIDDEN );
	} );

	it( 'both on another origin: one quiet row, no warning, no website check patterns', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'both_cross_origin' ) );

		expect( notAssessed() ).toBeNull();
		const checked = await checkedRows( user );
		expect( checked[ 'Website checks' ] ).toBe(
			'Not supported on this site · The homepage and the REST API are on a different address than wp-admin (another scheme, host or port), so the browser cannot check them from the update screens. They were not checked and are not counted as passed.'
		);
		expect( checked[ 'Homepage check' ] ).toBeUndefined();
		expect( rules() ).not.toHaveTextContent( 'Website checks: a homepage' );
	} );

	it( 'historical report without website check data: quiet, not a failure', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'historical' ) );

		expect( notAssessed() ).toBeNull();
		expect( ( await checkedRows( user ) )[ 'Website checks' ] ).toBe(
			'Not recorded · This update was observed before UpdateLens recorded website checks.'
		);
		expect(
			impact().querySelector( 'dd[data-category="not_applicable"]' )
		).toHaveClass( 'text-slate-600' );
	} );

	it( 'one check compared, one unavailable', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'one_unavailable' ) );

		expect( resultPanel() ).toHaveTextContent( NOT_FULLY );
		expect( resultPanel() ).toHaveTextContent(
			'UpdateLens cannot tell whether the REST API route /wp/v2/users/me responded as it did before the update.'
		);
		const checked = await checkedRows( user );
		expect( checked[ 'Homepage check' ] ).toBe(
			'Passed at both observation points'
		);
		expect( checked[ 'REST API check' ] ).toBe( 'Could not be compared' );
		expect( await siteResults( user ) ).toEqual( {
			Homepage: 'Passed before and after',
			'WordPress REST API': 'Not compared',
		} );
	} );

	it( 'pending AFTER results: still pending, never passed; refreshing is in Website checks', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'pending' ) );

		expect( resultPanel() ).toHaveTextContent( 'Nothing flagged so far' );
		expect(
			within( impact() ).queryByRole( 'button', {
				name: 'Refresh results',
			} )
		).not.toBeInTheDocument();
		expect( await siteResults( user ) ).toEqual( {
			Homepage: 'Waiting',
			'WordPress REST API': 'Waiting',
		} );
		expect(
			within( siteSection() ).getByRole( 'button', {
				name: 'Refresh results',
			} )
		).toBeInTheDocument();
	} );

	it( 'a finding already reported while the other check is pending', async () => {
		await renderReport(
			withSiteChecks( COMPLETED, 'pending_with_finding' )
		);

		expect( rowTitles() ).toEqual( [ 'REST API check newly failing' ] );
		expect( rowButton( rows()[ 0 ] ) ).toHaveAccessibleDescription(
			'The REST API route /wp/v2/users/me returned other output before its JSON twice after the update. Check the features that use it, such as the block editor.'
		);
		expect(
			within( impact() ).getByText(
				'Based on Net result and website checks'
			)
		).toBeInTheDocument();
		// The other check is still on its way: said, never passed.
		expect( impact() ).toHaveTextContent(
			'The website checks after the update have not arrived yet'
		);
		const user = userEvent.setup();
		expect( await siteResults( user ) ).toEqual( {
			Homepage: 'Waiting',
			'WordPress REST API': 'Newly failing',
		} );
	} );

	it( 'stays the same in every phase and is not described as the Net result', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( IMPACT, 'home_newly_failing' ), {
			phase: 'during_update',
		} );

		// No phase tabs here: no phase to explain away.
		expect( within( impact() ).queryByRole( 'tabpanel' ) ).toBeNull();
		await user.click( rowButton( rows()[ 0 ] ) );
		const titles = rowTitles();

		for ( const name of [ /^After update/, /^Net result/ ] ) {
			await user.click( reportTab( 'Configuration changes' ) );
			await user.click( tab( name ) );
			await user.click( reportTab( 'Update results' ) );
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

	it( 'puts website checks before Net result findings and keeps their links', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( IMPACT, 'home_newly_failing' ) );

		// Highest priority first: the confirmed failure, then the rules to review.
		expect( priorityGroups() ).toEqual( [
			'High priority, 1',
			'Needs review, 5',
		] );
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
