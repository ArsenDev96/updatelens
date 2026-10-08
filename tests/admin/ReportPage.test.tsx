import apiFetch from '@wordpress/api-fetch';
import { cleanup, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type {
	AnalysisReport,
	AvailableOptionsPhase,
	Provider,
} from '@/admin/types/api';

import {
	AWAITING,
	COMPLETED,
	CORRUPT_POST,
	EXPIRED,
	FAILED,
	FINAL,
	INCOMPATIBLE,
	pending,
	restError,
} from './fixtures';
import {
	cardTexts,
	openReport,
	phasePanel,
	signalCard,
	tab,
	tabTexts,
} from './report-view';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

/**
 * Opens a report's overview, or one signal's details.
 *
 * @param report Report served by the API (null: set up by the test).
 * @param signal Signal whose details are open.
 */
function renderReport( report: AnalysisReport | null, signal?: Provider ) {
	if ( report ) {
		apiFetchMock.mockResolvedValue( report );
	}
	openReport( report?.id ?? 1, { signal } );
}

const panel = phasePanel;
/** The collapsed technical details of a report. */
const technicalDetails = () =>
	screen.getByText( 'Technical details' ).closest( 'details' )!;

describe( 'ReportPage', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'shows a skeleton while loading', () => {
		apiFetchMock.mockReturnValue( pending() );
		renderReport( null );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Loading report…'
		);
		expect(
			screen.getByRole( 'link', { name: /Update History/ } )
		).toBeInTheDocument();
	} );

	describe( 'overview', () => {
		it( 'summarizes a completed report with Net result selected', async () => {
			renderReport( COMPLETED );

			expect(
				await screen.findByRole( 'heading', {
					level: 2,
					name: 'UpdateLens Fixture A',
				} )
			).toBeInTheDocument();
			const header = screen.getByRole( 'banner' );
			expect(
				within( header ).getByText(
					'updatelens-fixture-a/updatelens-fixture-a.php'
				)
			).toBeInTheDocument();
			expect(
				within( header ).getByText( 'Completed' )
			).toBeInTheDocument();
			expect(
				within( header ).getByText( /^Updated / )
			).toBeInTheDocument();
			// Completed: the header badge says so; no status section repeats
			// it. How the observation ended is in the technical details.
			expect(
				screen.queryByRole( 'region', { name: 'Analysis status' } )
			).toBeNull();
			expect( document.body ).not.toHaveTextContent(
				'Analysis completed'
			);
			expect( technicalDetails() ).toHaveTextContent(
				'ObservationObservation completed on the next admin request.'
			);
			expect( document.body ).not.toHaveTextContent(
				/lifecycle|eligible/
			);

			expect( tab( /Net result/ ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			// The phase tabs are the only tabs; they name the changes.
			expect( tabTexts() ).toEqual( [
				'During update, 2 changes',
				'After update, 11 changes',
				'Net result, 11 changes',
			] );
			expect( screen.getAllByRole( 'tablist' ) ).toHaveLength( 1 );
			expect( screen.getAllByRole( 'tabpanel' ) ).toHaveLength( 1 );

			// Headline (the tab's total) and a plain summary.
			expect(
				within( panel() ).getByText( '11 observed changes' )
			).toBeVisible();
			expect( panel() ).toHaveTextContent(
				'Changes were observed in Options & autoload and WP-Cron. Action Scheduler was not available for this phase.'
			);
			// The selected tab names the phase; its description is technical
			// detail, not a second block in the summary.
			expect( panel() ).not.toHaveTextContent( 'Observation period' );
			expect( panel() ).not.toHaveTextContent( 'Net difference' );
			expect( technicalDetails() ).toHaveTextContent(
				'Selected phaseNet result: Net difference between the state before the update and the end of the observation window.'
			);

			// One card per signal, in order, as links to their details.
			expect( cardTexts() ).toEqual( [
				'Options & autoloadStored settings in wp_options, including autoloaded data.6 changes Added 2 · Removed 1 · Changed 3View details',
				'WP-CronEvents scheduled with WordPress cron.5 changes Added 2 · No longer present 1 · Rescheduled 2View details',
				'Action SchedulerPending and in-progress background actions.Not available Action Scheduler not detectedView details',
			] );
			const options = screen.getByRole( 'link', {
				name: 'Options & autoload',
			} );
			expect( options ).toHaveAttribute(
				'href',
				'/wp-admin/admin.php?page=updatelens&analysis=1&signal=options'
			);
			expect( options ).toHaveAccessibleDescription(
				'6 changes Added 2 · Removed 1 · Changed 3'
			);
			expect(
				screen.getByRole( 'heading', { level: 3, name: 'WP-Cron' } )
			).toBeInTheDocument();

			// No change rows, lists or site totals on the overview.
			expect( document.body ).not.toHaveTextContent(
				'recently_activated'
			);
			expect( document.body ).not.toHaveTextContent(
				'ul_fixture_cleanup'
			);
			expect( document.body ).not.toHaveTextContent(
				'Total option data'
			);
			expect(
				screen.queryByRole( 'heading', { name: /^Added \(/ } )
			).toBeNull();
			expect(
				screen.queryByRole( 'region', { name: /WP-Cron/ } )
			).toBeNull();

			// Technical details stay collapsed.
			expect(
				screen.getByText( 'Technical details' ).closest( 'details' )
			).not.toHaveAttribute( 'open' );
		} );

		it( 'switches phases by click and keyboard', async () => {
			renderReport( COMPLETED );
			const user = userEvent.setup();
			await screen.findAllByRole( 'tablist' );

			await user.click( tab( /During update/ ) );
			expect( tab( /During update/ ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			expect( technicalDetails() ).toHaveTextContent(
				'During update: Changes observed while WordPress was performing this plugin update.'
			);
			expect( panel() ).toHaveTextContent( '2 observed changes' );
			expect( signalCard( 'Options & autoload' ) ).toHaveTextContent(
				'1 change Added 1'
			);
			// The phase is in the URL (replacing the entry) and in the card links.
			expect( window.location.search ).toBe(
				'?page=updatelens&analysis=1&phase=during_update'
			);
			expect(
				screen.getByRole( 'link', { name: 'WP-Cron' } )
			).toHaveAttribute(
				'href',
				'/wp-admin/admin.php?page=updatelens&analysis=1&signal=cron&phase=during_update'
			);

			await user.keyboard( '{ArrowRight}' );
			expect( tab( /After update/ ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			expect( tab( /After update/ ) ).toHaveFocus();
			expect( technicalDetails() ).toHaveTextContent(
				'Other site activity may also contribute.'
			);

			await user.keyboard( '{End}' );
			expect( tab( /Net result/ ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			await user.keyboard( '{ArrowRight}' );
			expect( tab( /During update/ ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			await user.keyboard( '{ArrowLeft}' );
			expect( tab( /Net result/ ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			expect( tab( /Net result/ ) ).toHaveAttribute( 'tabindex', '0' );
			expect( tab( /During update/ ) ).toHaveAttribute(
				'tabindex',
				'-1'
			);

			// Arrow keys move from the focused tab, even if it is not the selected one.
			tab( /During update/ ).focus();
			await user.keyboard( '{ArrowRight}' );
			expect( tab( /After update/ ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			expect( tab( /After update/ ) ).toHaveFocus();
		} );

		it( 'renders an expired report with During update selected', async () => {
			renderReport( EXPIRED );
			const user = userEvent.setup();

			expect(
				await screen.findByText( 'Post-update observation expired' )
			).toBeInTheDocument();
			expect( tab( /During update/ ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			expect( tab( /After update/ ) ).toHaveTextContent(
				'Not available'
			);
			expect( tab( /Net result/ ) ).toHaveAccessibleName(
				'Net result, Not available'
			);

			await user.click( tab( /Net result/ ) );
			expect( panel() ).toHaveTextContent(
				'Not available for this phase'
			);
			// The phase's reason, once; each card names its own.
			expect( panel() ).toHaveTextContent(
				'No eligible admin request occurred within 5 minutes of the update.'
			);
			expect( signalCard( 'Options & autoload' ) ).toHaveTextContent(
				'Not available Not captured'
			);
			expect( panel() ).not.toHaveTextContent( 'settle_expired' );
		} );

		it( 'labels an expired observation a partial report on the overview and every signal page', async () => {
			for ( const signal of [
				undefined,
				'options',
				'cron',
				'action_scheduler',
			] as const ) {
				renderReport( EXPIRED, signal );

				const header = await screen.findByRole( 'banner' );
				expect( header ).toHaveTextContent( 'Partial report' );
				expect( header ).not.toHaveTextContent( 'Completed' );
				// The amber explanation stays.
				expect(
					screen.getByText( 'Post-update observation expired' )
				).toBeInTheDocument();
				cleanup();
			}
		} );

		it.each( [
			[ 'completed', COMPLETED, 'Completed' ],
			[ 'observing', AWAITING, 'Observing' ],
			[ 'failed', FAILED, 'Failed' ],
		] )(
			'keeps the %s badge on the overview and signal pages',
			async ( _name, report, label ) => {
				for ( const signal of [ undefined, 'cron' ] as const ) {
					renderReport( report, signal );

					const header = await screen.findByRole( 'banner' );
					expect( header ).toHaveTextContent( label );
					expect( header ).not.toHaveTextContent( 'Partial report' );
					cleanup();
				}
			}
		);

		it( 'renders a failed report without phases', async () => {
			renderReport( FAILED );

			expect(
				await screen.findByText( 'Plugin update failed' )
			).toBeInTheDocument();
			expect(
				screen.getByText( 'No completed change analysis is available.' )
			).toBeInTheDocument();
			expect(
				screen.getByText( 'The plugin update failed.' )
			).toBeInTheDocument();
			expect( screen.getByText( 'Failed' ) ).toBeInTheDocument();
			expect( screen.queryByRole( 'tablist' ) ).toBeNull();
			expect(
				screen.queryByRole( 'list', { name: 'Signals' } )
			).toBeNull();
			// The raw code is technical detail only.
			expect(
				screen.getByText( 'update_not_installed' ).closest( 'details' )
			).not.toBeNull();
		} );

		it( 'explains a known failure code', async () => {
			renderReport( {
				...FAILED,
				error: { code: 'update_not_completed' },
			} );

			expect(
				await screen.findByText( 'The plugin update did not complete.' )
			).toBeInTheDocument();
		} );

		it( 'renders an incompatible report', async () => {
			renderReport( INCOMPATIBLE );
			const user = userEvent.setup();

			expect(
				await screen.findByText( "Analysis couldn't be completed" )
			).toBeInTheDocument();
			expect(
				screen.getByText(
					/fingerprint context changed while the update was being observed/
				)
			).toBeInTheDocument();
			expect( screen.getByText( 'Incompatible' ) ).toBeInTheDocument();
			expect( tab( /During update/ ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);

			await user.click( tab( /After update/ ) );
			expect( signalCard( 'Options & autoload' ) ).toHaveTextContent(
				'Comparison stopped'
			);
		} );

		it( 'keeps valid phases when one phase is corrupt', async () => {
			renderReport( CORRUPT_POST );
			const user = userEvent.setup();

			await screen.findAllByRole( 'tablist' );
			expect( tab( /Net result/ ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			expect( signalCard( 'Options & autoload' ) ).toHaveTextContent(
				'6 changes'
			);

			// Options is unreadable here; WP-Cron keeps its count.
			await user.click( tab( /After update/ ) );
			expect( signalCard( 'Options & autoload' ) ).toHaveTextContent(
				/^Options & autoloadStored settings in wp_options, including autoloaded data\.Not available/
			);
			expect( signalCard( 'WP-Cron' ) ).toHaveTextContent(
				/WP-Cron[^\d]*\d+ changes?/
			);
			expect( panel() ).toHaveTextContent(
				'Changes were observed in WP-Cron. Options & autoload and Action Scheduler were not available for this phase.'
			);
			expect( panel() ).not.toHaveTextContent( 'data_corrupt' );
		} );

		it( 'shows the awaiting state with a manual Refresh', async () => {
			apiFetchMock.mockResolvedValueOnce( AWAITING );
			apiFetchMock.mockResolvedValueOnce( {
				...COMPLETED,
				id: AWAITING.id,
			} );
			renderReport( null );
			const user = userEvent.setup();

			expect(
				await screen.findByText( 'Observing post-update activity…' )
			).toBeInTheDocument();
			expect( screen.getByText( 'Observing' ) ).toBeInTheDocument();
			expect( tab( /During update/ ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			await user.click( tab( /Net result/ ) );
			expect( panel() ).toHaveTextContent(
				'Waiting for the first eligible admin request after the update.'
			);

			await user.click(
				screen.getByRole( 'button', { name: 'Refresh' } )
			);
			// Once completed, the status section with Refresh is gone.
			expect(
				await screen.findByText(
					'Observation completed on the next admin request.'
				)
			).toBeInTheDocument();
			expect(
				screen.queryByRole( 'region', { name: 'Analysis status' } )
			).toBeNull();
			expect(
				screen.queryByRole( 'button', { name: 'Refresh' } )
			).toBeNull();
			expect( apiFetchMock ).toHaveBeenCalledTimes( 2 );
		} );

		it( 'goes back to History in the app', async () => {
			renderReport( COMPLETED );
			const user = userEvent.setup();

			await screen.findAllByRole( 'tablist' );
			const back = screen.getByRole( 'link', { name: /Update History/ } );
			expect( back ).toHaveAttribute(
				'href',
				'/wp-admin/admin.php?page=updatelens'
			);
			await user.click( back );
			expect( window.location.search ).toBe( '?page=updatelens' );
			expect(
				await screen.findByRole( 'heading', { name: 'Update History' } )
			).toHaveFocus();
		} );
	} );

	describe( 'Options & autoload details', () => {
		it( 'shows the counts, quiet totals and every list', async () => {
			renderReport( COMPLETED, 'options' );

			const title = await screen.findByRole( 'heading', {
				level: 2,
				name: 'Options & autoload',
			} );
			expect( title ).not.toHaveFocus();
			const crumbs = within(
				screen.getByRole( 'navigation', { name: 'Breadcrumb' } )
			).getAllByRole( 'listitem' );
			expect( crumbs.map( ( crumb ) => crumb.textContent ) ).toEqual( [
				'Update History›',
				'UpdateLens Fixture A›',
				'Options & autoload',
			] );
			expect( crumbs[ 2 ] ).toHaveAttribute( 'aria-current', 'page' );
			// Report context: plugin, versions and status.
			expect( screen.getByRole( 'banner' ) ).toHaveTextContent(
				/UpdateLens Fixture A\s*1\.0\.0 →\s*to 1\.1\.0\s*Completed/
			);

			// Tabs count this signal only.
			expect( tabTexts() ).toEqual( [
				'During update, 1 change',
				'After update, 7 changes',
				'Net result, 6 changes',
			] );
			expect( panel() ).toHaveTextContent( '6 observed option changes' );

			// Counts first (the autoloaded data change because it is not 0 B);
			// the site totals are quiet statistics after the lists.
			expect( panel() ).toHaveTextContent(
				/^Net result6 observed option changesAdded2.*Changed \(3\).*Site totals/
			);
			const metrics = within( panel() ).getAllByRole( 'definition' );
			expect(
				within( panel() )
					.getAllByRole( 'term' )
					.slice( 0, 4 )
					.map( ( term ) => term.textContent )
			).toEqual( [ 'Added', 'Removed', 'Changed', 'Autoloaded data' ] );
			expect(
				metrics.slice( 0, 4 ).map( ( m ) => m.textContent )
			).toEqual( [ '2', '1', '3', '+30 B' ] );
			// Secondary totals (decimal separator follows the browser locale).
			expect( panel() ).toHaveTextContent(
				/Total option data\s*29[.,]7 KB\s*→\s*to\s*27[.,]8 KB\s*\(−2 KB\)/
			);
			expect( panel() ).toHaveTextContent(
				/Options\s*154\s*→\s*to\s*155\s*\(\+1\)/
			);

			// Lists (h3 under the signal's h2), including WordPress core activity.
			for ( const name of [
				'Added (2)',
				'Removed (1)',
				'Changed (3)',
			] ) {
				expect(
					within( panel() ).getByRole( 'heading', { level: 3, name } )
				).toBeInTheDocument();
			}
			expect(
				within( panel() ).getByText( 'recently_activated' )
			).toBeInTheDocument();
			expect(
				within( panel() ).getByText( 'ulfx_a_legacy_cache' )
			).toBeInTheDocument();
			expect(
				screen.getByText(
					'UpdateLens detects value changes without storing the option values themselves.'
				)
			).toBeInTheDocument();
			// Other signals stay on their own pages.
			expect( document.body ).not.toHaveTextContent(
				'ul_fixture_cleanup'
			);
			// Technical details stay available, collapsed, without other signals' reasons.
			const details = screen
				.getByText( 'Technical details' )
				.closest( 'details' )!;
			expect( details ).not.toHaveAttribute( 'open' );
			expect( details ).not.toHaveTextContent(
				'Action Scheduler reason'
			);
		} );

		it( 'switches phases on the details and keeps the signal', async () => {
			renderReport( COMPLETED, 'options' );
			const user = userEvent.setup();
			await screen.findAllByRole( 'tablist' );

			await user.click( tab( /During update/ ) );
			expect( window.location.search ).toBe(
				'?page=updatelens&analysis=1&signal=options&phase=during_update'
			);
			expect(
				screen.getByRole( 'heading', { level: 2 } )
			).toHaveTextContent( 'Options & autoload' );
			expect(
				within( panel() ).getByRole( 'heading', { name: 'Added (1)' } )
			).toBeInTheDocument();
			// Empty lists are left out; the summary shows their zero counts.
			expect(
				within( panel() ).queryByRole( 'heading', { name: /^Removed/ } )
			).toBeNull();
			expect(
				within( panel() ).queryByRole( 'heading', { name: /^Changed/ } )
			).toBeNull();

			await user.keyboard( '{ArrowRight}' );
			expect( tab( /After update/ ) ).toHaveFocus();
			expect(
				within( panel() ).getByRole( 'heading', {
					name: 'Removed (2)',
				} )
			).toBeInTheDocument();
		} );

		it( 'explains an unreadable phase without a dead dashboard', async () => {
			renderReport( CORRUPT_POST, 'options' );
			const user = userEvent.setup();
			await screen.findAllByRole( 'tablist' );

			await user.click( tab( /After update/ ) );
			expect( panel() ).toHaveTextContent(
				"This stored phase couldn't be read safely."
			);
			expect( within( panel() ).queryByRole( 'definition' ) ).toBeNull();
			expect( within( panel() ).queryByRole( 'heading' ) ).toBeNull();
			expect( panel() ).not.toHaveTextContent( 'data_corrupt' );
		} );

		it( 'renders changed options with sizes, deltas and autoload changes', async () => {
			renderReport(
				{
					...COMPLETED,
					phases: {
						...COMPLETED.phases,
						final: {
							...COMPLETED.phases.final,
							options: {
								...FINAL,
								changed: [
									{
										name: 'plugin_settings',
										value_changed: true,
										before_size: 84,
										after_size: 115,
										size_delta: 31,
										before_autoload: 'yes',
										after_autoload: 'auto-off',
										autoload_value_changed: true,
										before_is_autoloaded: true,
										after_is_autoloaded: false,
										autoload_behavior_changed: true,
									},
									{
										name: 'plugin_db_version',
										value_changed: false,
										before_size: 5,
										after_size: 5,
										size_delta: 0,
										before_autoload: 'yes',
										after_autoload: 'on',
										autoload_value_changed: true,
										before_is_autoloaded: true,
										after_is_autoloaded: true,
										autoload_behavior_changed: false,
									},
								],
							},
						},
					},
				},
				'options'
			);

			const settings = (
				await screen.findByText( 'plugin_settings' )
			).closest( 'li' )!;
			expect( settings ).toHaveTextContent(
				/Value changed\s*·\s*84 B\s*→\s*to\s*115 B\s*\(\+31 B\)/
			);
			expect( settings ).toHaveTextContent( /yes\s*→\s*to\s*auto-off/ );
			expect( settings ).toHaveTextContent(
				/On\s*→\s*to\s*Off\s*\(behavior changed\)/
			);

			const version = screen
				.getByText( 'plugin_db_version' )
				.closest( 'li' )!;
			// Only the stored autoload setting changed, not the value.
			expect( version ).toHaveTextContent(
				/Autoload setting changed\s*·\s*5 B/
			);
			expect( version ).not.toHaveTextContent( 'Value' );
			expect( version ).not.toHaveTextContent( '→ to 5 B' );
			expect( version ).not.toHaveTextContent( '+0' );
			// The raw value changed but the behavior did not.
			expect( version ).toHaveTextContent( /yes\s*→\s*to\s*on/ );
			expect( version ).toHaveTextContent(
				/Effective behavior\s*On \(unchanged\)/
			);
		} );

		it( 'renders added and removed options with effective autoload, without repeating it', async () => {
			renderReport( COMPLETED, 'options' );

			const added = (
				await screen.findByText( 'ulfx_a_feature_flags' )
			).closest( 'li' )!;
			expect( added ).toHaveTextContent( '30 B' );
			expect( added ).toHaveTextContent( /Autoload: On$/ );
			expect( added ).not.toHaveTextContent( '(on)' );

			const removed = screen
				.getByText( 'ulfx_a_legacy_cache' )
				.closest( 'li' )!;
			expect( removed ).toHaveTextContent( '2 KB' );
			expect( removed ).toHaveTextContent( /Autoload: Off$/ );
			expect( removed ).not.toHaveTextContent( '(off)' );
		} );

		it( 'wraps long option names after separators without changing them', async () => {
			const options = COMPLETED.phases.final
				.options as AvailableOptionsPhase;
			const names = [
				'woocommerce_marketplace_suggestions_last_fetch_timestamp',
				'_leading__double-dash_',
				'nosplit',
			];
			renderReport(
				{
					...COMPLETED,
					phases: {
						...COMPLETED.phases,
						final: {
							...COMPLETED.phases.final,
							options: {
								...options,
								added: names.map( ( name ) => ( {
									name,
									size: 3,
									autoload: 'auto',
									is_autoloaded: true,
								} ) ),
							},
						},
					},
				},
				'options'
			);

			for ( const name of names ) {
				const code = await screen.findByText( name );
				expect( code.tagName ).toBe( 'CODE' );
				expect( code.textContent ).toBe( name );
			}
			expect(
				screen.getByText( names[ 0 ] ).querySelectorAll( 'wbr' )
			).toHaveLength( 5 );
			expect(
				screen.getByText( 'nosplit' ).querySelectorAll( 'wbr' )
			).toHaveLength( 0 );
		} );

		it( 'keeps stored autoload values that say more than On/Off', async () => {
			const options = COMPLETED.phases.final
				.options as AvailableOptionsPhase;
			renderReport(
				{
					...COMPLETED,
					phases: {
						...COMPLETED.phases,
						final: {
							...COMPLETED.phases.final,
							options: {
								...options,
								added: [
									{
										name: 'ulfx_large',
										size: 200000,
										autoload: 'auto-off',
										is_autoloaded: false,
									},
									{
										name: 'ulfx_default',
										size: 3,
										autoload: 'auto',
										is_autoloaded: true,
									},
									{
										name: 'ulfx_legacy_yes',
										size: 3,
										autoload: 'yes',
										is_autoloaded: true,
									},
									{
										name: 'ulfx_legacy_no',
										size: 3,
										autoload: 'no',
										is_autoloaded: false,
									},
								],
							},
						},
					},
				},
				'options'
			);

			const row = async ( name: string ) =>
				( await screen.findByText( name ) ).closest( 'li' )!;
			expect( await row( 'ulfx_large' ) ).toHaveTextContent(
				'Autoload: Off(auto-off)'
			);
			expect( await row( 'ulfx_default' ) ).toHaveTextContent(
				'Autoload: On(auto)'
			);
			// Explicit legacy values only repeat the label.
			expect( await row( 'ulfx_legacy_yes' ) ).toHaveTextContent(
				/Autoload: On$/
			);
			expect( await row( 'ulfx_legacy_no' ) ).toHaveTextContent(
				/Autoload: Off$/
			);
		} );
	} );

	it( 'shows a missing report without Retry', async () => {
		apiFetchMock.mockRejectedValue(
			restError( 'updatelens_analysis_not_found', 404 )
		);
		renderReport( null );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'This analysis no longer exists.'
		);
		expect( screen.queryByRole( 'button', { name: 'Retry' } ) ).toBeNull();
		expect( document.body ).not.toHaveTextContent( 'Server says' );
	} );

	it( 'shows a load failure with Retry, also for a details URL', async () => {
		apiFetchMock.mockRejectedValue(
			restError( 'updatelens_reports_unavailable', 500 )
		);
		renderReport( null, 'cron' );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			"This report couldn't be loaded. Try again."
		);
		expect(
			screen.getByRole( 'button', { name: 'Retry' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: /Update History/ } )
		).toBeInTheDocument();
		expect( document.body ).not.toHaveTextContent(
			/Server says|updatelens_reports_unavailable/
		);
	} );

	it( 'never uses causal wording on the overview or the details', async () => {
		for ( const signal of [
			undefined,
			'options',
			'cron',
			'action_scheduler',
		] as const ) {
			renderReport( COMPLETED, signal );
			const user = userEvent.setup();
			await screen.findAllByRole( 'tablist' );

			for ( const name of [
				/During update/,
				/After update/,
				/Net result/,
			] ) {
				await user.click( tab( name ) );
				expect( document.body ).not.toHaveTextContent(
					/caused|created by|definitely|plugin changed/i
				);
			}
			cleanup();
		}
	} );
} );
