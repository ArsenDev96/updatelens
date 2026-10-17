import apiFetch from '@wordpress/api-fetch';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { HistoryPage } from '@/admin/pages/HistoryPage';
import type { SiteCheckImpact } from '@/admin/types/api';

import cases from '../fixtures/site-check-impact-cases.json';

import {
	COMPLETED,
	EXPIRED,
	FAILED,
	historyItem,
	listResponse,
	pending,
} from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

function renderHistory( page = 1 ) {
	const props = {
		page,
		reportHref: ( id: number ) =>
			`/wp-admin/admin.php?page=updatelens&analysis=${ id }`,
		onOpenReport: vi.fn(),
		onPageChange: vi.fn(),
		focusHeading: false,
	};
	render( <HistoryPage { ...props } /> );
	return props;
}

describe( 'HistoryPage', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'shows a busy skeleton while loading', () => {
		apiFetchMock.mockReturnValue( pending() );
		renderHistory();

		expect(
			screen.getByRole( 'heading', { name: 'Update History' } )
		).toBeInTheDocument();
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Loading update history…'
		);
		expect(
			screen.getByRole( 'status' ).closest( '[aria-busy="true"]' )
		).not.toBeNull();
	} );

	it( 'lists analyses with name, versions, status and phase availability', async () => {
		apiFetchMock.mockResolvedValue(
			listResponse( [
				historyItem( COMPLETED ),
				historyItem( EXPIRED, {
					id: 2,
					plugin: { ...COMPLETED.plugin, name: 'Second Plugin' },
				} ),
				historyItem( FAILED, {
					plugin: { ...FAILED.plugin, name: 'Third Plugin' },
				} ),
			] )
		);
		renderHistory();

		const links = await screen.findAllByRole( 'link' );
		expect( links ).toHaveLength( 3 );

		const first = links[ 0 ];
		expect( first ).toHaveAttribute(
			'href',
			'/wp-admin/admin.php?page=updatelens&analysis=1'
		);
		expect( first ).toHaveTextContent( 'UpdateLens Fixture A' );
		expect( first ).toHaveTextContent( /1\.0\.0\s*→\s*to\s*1\.1\.0/ );
		expect( first ).toHaveTextContent( 'Update completed' );
		expect( first ).not.toHaveTextContent( 'Partial report' );
		expect( first ).toHaveTextContent( 'View report' );
		expect(
			within( first )
				.getAllByRole( 'listitem' )
				.map( ( item ) => item.textContent )
		).toEqual( [
			'During update:Changes observed',
			'After update:Changes observed',
			'Net result:Changes observed',
		] );
		expect( first ).not.toHaveTextContent( '✓' );

		const expired = links[ 1 ];
		expect( expired ).toHaveTextContent( 'Second Plugin' );
		// The update succeeded, but the post-update state was not captured.
		expect( expired ).toHaveTextContent( 'Partial report' );
		expect( expired ).not.toHaveTextContent( 'Completed' );
		expect(
			within( expired )
				.getAllByRole( 'listitem' )
				.map( ( item ) => item.textContent )
		).toEqual( [
			'During update:Changes observed',
			'After update:Not available',
			'Net result:Not available',
		] );

		const failed = links[ 2 ];
		expect( failed ).toHaveTextContent( 'Failed' );
		expect( failed ).not.toHaveTextContent( 'Partial report' );
		expect( failed ).toHaveTextContent( /Net result:\s*Not available/ );
		expect( failed ).toHaveTextContent(
			/1\.0\.0\s*→\s*to\s*–\s*unknown version/
		);
		expect( failed.querySelector( 'time' ) ).toHaveAttribute(
			'datetime',
			'2026-10-05T18:46:06Z'
		);
	} );

	it( 'opens a report in the app on click, but leaves modified clicks to the browser', async () => {
		apiFetchMock.mockResolvedValue(
			listResponse( [ historyItem( COMPLETED ) ] )
		);
		const props = renderHistory();
		const user = userEvent.setup();

		const link = await screen.findByRole( 'link', {
			name: /UpdateLens Fixture A/,
		} );
		await user.click( link );
		expect( props.onOpenReport ).toHaveBeenCalledWith( 1 );

		props.onOpenReport.mockClear();
		link.focus();
		await user.keyboard( '{Enter}' );
		expect( props.onOpenReport ).toHaveBeenCalledWith( 1 );

		props.onOpenReport.mockClear();
		await user.keyboard( '{Control>}' );
		await user.click( link );
		await user.keyboard( '{/Control}' );
		expect( props.onOpenReport ).not.toHaveBeenCalled();
	} );

	it( 'paginates with Previous/Next', async () => {
		apiFetchMock.mockResolvedValue(
			listResponse( [ historyItem( COMPLETED ) ], 41, 3 )
		);
		const props = renderHistory( 2 );
		const user = userEvent.setup();

		expect( await screen.findByText( 'Page 2 of 3' ) ).toBeInTheDocument();
		expect( apiFetchMock ).toHaveBeenCalledWith( {
			path: '/updatelens/v1/analyses?page=2&per_page=20',
			parse: false,
		} );

		await user.click( screen.getByRole( 'button', { name: /Next/ } ) );
		expect( props.onPageChange ).toHaveBeenCalledWith( 3 );
		await user.click( screen.getByRole( 'button', { name: /Previous/ } ) );
		expect( props.onPageChange ).toHaveBeenCalledWith( 1 );
	} );

	it( 'disables Previous on the first page and Next on the last', async () => {
		apiFetchMock.mockResolvedValue(
			listResponse( [ historyItem( COMPLETED ) ], 1, 1 )
		);
		renderHistory( 1 );

		expect( await screen.findByText( 'Page 1 of 1' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Previous/ } )
		).toBeDisabled();
		expect( screen.getByRole( 'button', { name: /Next/ } ) ).toBeDisabled();
	} );

	it( 'shows a safe error with Retry, then loads', async () => {
		apiFetchMock.mockRejectedValueOnce(
			new Response(
				JSON.stringify( {
					code: 'updatelens_reports_unavailable',
					message: 'WordPress database error Table wp_x',
				} ),
				{ status: 500 }
			)
		);
		apiFetchMock.mockResolvedValueOnce(
			listResponse( [ historyItem( COMPLETED ) ] )
		);
		// Monitoring start for the boundary on the (only, so last) page.
		apiFetchMock.mockResolvedValueOnce( { started_at: null } );
		renderHistory();
		const user = userEvent.setup();

		const alert = await screen.findByRole( 'alert' );
		expect( alert ).toHaveTextContent(
			"Update history couldn't be loaded."
		);
		expect( document.body ).not.toHaveTextContent(
			/database|updatelens_reports_unavailable/
		);

		await user.click(
			within( alert ).getByRole( 'button', { name: 'Retry' } )
		);
		expect(
			await screen.findByRole( 'link', { name: /UpdateLens Fixture A/ } )
		).toBeInTheDocument();
		expect( apiFetchMock ).toHaveBeenCalledTimes( 3 );
	} );

	it.each( [
		[
			'home_newly_failing',
			'newly_failing',
			'Newly failing right after the update: Homepage',
		],
		[
			'rest_newly_failing',
			'newly_failing',
			'Newly failing right after the update: REST API',
		],
		[ 'both_passed', 'passed', 'Passed before and after' ],
		// Partly compared: each check's own state follows.
		[
			'one_unavailable',
			'partial',
			'1 of 2 compared · Homepage: passed before and after · REST API: not compared',
		],
		[
			'unconfirmed',
			'partial',
			'1 of 2 compared · Homepage: not compared · REST API: passed before and after',
		],
		[ 'no_baseline', 'not_compared', 'Not compared' ],
		// No follow-up from the updating tab (Dashboard → Updates, a page
		// left, or a site that did not answer): never presented as passed.
		[ 'not_followed_up', 'not_compared', 'Not compared' ],
		[
			'rest_route_missing',
			'newly_failing',
			'Newly failing right after the update: REST API',
		],
		[ 'pending', 'pending', 'Waiting for results' ],
		[ 'disabled', 'disabled', 'Off for this update' ],
		[ 'both_cross_origin', 'unsupported', 'Not supported on this site' ],
		[ 'historical', 'not_recorded', 'Not recorded for this update' ],
	] as Array< [ keyof typeof cases.cases, string, string ] > )(
		'%s: website checks have their own line, apart from the status badge',
		async ( name, kind, text ) => {
			const evaluation = cases.cases[ name ]
				.potential_impact_site_checks as SiteCheckImpact;
			apiFetchMock.mockResolvedValue(
				listResponse( [
					historyItem( COMPLETED, {
						site_checks: {
							status: evaluation.status,
							checks: evaluation.checks,
						},
					} ),
				] )
			);
			renderHistory();

			const [ link ] = await screen.findAllByRole( 'link' );
			const line = link.querySelector( '[data-outcome]' )!;
			expect( line ).toHaveAttribute( 'data-outcome', kind );
			expect( line.textContent ).toBe( text );
			// Labelled apart from the configuration phases.
			const label = within( link ).getByText( 'Website checks' );
			expect( label.tagName ).toBe( 'DT' );
			expect( label.nextElementSibling ).toContainElement(
				line as HTMLElement
			);
			// The update's own status stays a separate badge.
			expect( link ).toHaveTextContent( 'Update completed' );
			expect( line ).not.toHaveTextContent( 'Update completed' );
			// Visible text, not a hidden detail: a failure needs no report open.
			if ( kind === 'newly_failing' ) {
				expect(
					line.querySelector( '.text-rose-800' )
				).toHaveTextContent( text );
			}
			if ( kind !== 'passed' ) {
				expect( line.textContent ).not.toMatch( /Passed/ );
			}
		}
	);

	// "Newly failing" needs a stored comparison that establishes it: a verified
	// pass before and a confirmed failure after. A check that failed before
	// and after, recovered, or failed after a redirect is never newly failing.
	it.each( [
		[ 'failing_both', 'failing', 'Failing after the update: Homepage' ],
		[
			'redirect_then_failure',
			'failing',
			'Failing after the update: Homepage',
		],
		[ 'recovered', 'compared', 'Compared before and after' ],
	] as Array< [ keyof typeof cases.cases, string, string ] > )(
		'%s: History never says newly failing',
		async ( name, kind, text ) => {
			const evaluation = cases.cases[ name ]
				.potential_impact_site_checks as SiteCheckImpact;
			expect(
				Object.values( evaluation.checks ).map(
					( check ) => check.state
				)
			).not.toContain( 'newly_failing' );
			apiFetchMock.mockResolvedValue(
				listResponse( [
					historyItem( COMPLETED, {
						site_checks: {
							status: evaluation.status,
							checks: evaluation.checks,
						},
					} ),
				] )
			);
			renderHistory();

			const [ link ] = await screen.findAllByRole( 'link' );
			const line = link.querySelector( '[data-outcome]' )!;
			expect( line ).toHaveAttribute( 'data-outcome', kind );
			expect( line.textContent?.startsWith( text ) ).toBe( true );
			expect( link.textContent ).not.toMatch( /newly failing/i );
			expect( line.querySelector( '.text-rose-800' ) ).toBeNull();
		}
	);

	it( 'marks confirmed high priority findings, and nothing else', async () => {
		apiFetchMock.mockResolvedValue(
			listResponse( [
				historyItem( COMPLETED, { id: 2, high_priority: 1 } ),
				historyItem( COMPLETED, { id: 1, high_priority: 0 } ),
			] )
		);
		renderHistory();

		const [ flagged, quiet ] = await screen.findAllByRole( 'link' );
		expect(
			flagged.querySelector( '[data-priority="high"]' )
		).toHaveTextContent( /^1 high priority$/ );
		// The link's name includes it as text, not only color.
		expect( flagged ).toHaveAccessibleName( /1 high priority/ );
		expect( quiet.querySelector( '[data-priority]' ) ).toBeNull();
		// No overall verdict and no badge for ordinary findings.
		expect( document.body ).not.toHaveTextContent(
			/needs review|safe|no issues/i
		);
	} );

	it( 'an older API without website checks reads as not recorded', async () => {
		const { site_checks: omitted, ...older } = historyItem( COMPLETED );
		void omitted;
		apiFetchMock.mockResolvedValue( listResponse( [ older ] ) );
		renderHistory();

		const [ link ] = await screen.findAllByRole( 'link' );
		expect( link.querySelector( '[data-outcome]' ) ).toHaveTextContent(
			'Not recorded for this update'
		);
	} );

	it( 'explains a permission error', async () => {
		apiFetchMock.mockRejectedValue(
			new Response(
				JSON.stringify( {
					code: 'rest_forbidden',
					data: { status: 403 },
				} ),
				{ status: 403 }
			)
		);
		renderHistory();

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			"You don't have permission to view UpdateLens reports."
		);
		expect( screen.queryByRole( 'button', { name: 'Retry' } ) ).toBeNull();
	} );

	it( 'tells a REST API blocked in front of WordPress from a missing permission', async () => {
		// A firewall's HTML page: not UpdateLens's permission check.
		apiFetchMock.mockRejectedValue(
			new Response( '<!doctype html><h1>Forbidden</h1>', { status: 403 } )
		);
		renderHistory();

		const alert = await screen.findByRole( 'alert' );
		expect( alert ).toHaveTextContent(
			"Update history couldn't be loaded."
		);
		expect( alert ).toHaveTextContent(
			"The site's REST API refused the request (HTTP 403). A security plugin, firewall or host setting may be blocking it for administrators. Stored reports are not affected."
		);
		expect( alert ).not.toHaveTextContent( 'permission' );
		expect( alert ).not.toHaveTextContent( 'Forbidden' );
		expect(
			screen.getByRole( 'button', { name: 'Retry' } )
		).toBeInTheDocument();
	} );

	it( 'says when the REST API answers with something other than data', async () => {
		// PHP output printed before the JSON.
		apiFetchMock.mockResolvedValue(
			new Response( 'Warning: output before JSON\n[]', { status: 200 } )
		);
		renderHistory();

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			"The site's REST API answered with something other than data (HTTP 200), for example PHP output, an error page or a firewall page. Stored reports are not affected."
		);
	} );
} );
