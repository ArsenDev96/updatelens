import apiFetch from '@wordpress/api-fetch';
import { setLocaleData } from '@wordpress/i18n';
import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { DEFAULT_VISIBLE_DIFF_ROWS } from '@/admin/components/DiffSection';
import { HistoryPage } from '@/admin/pages/HistoryPage';
import type {
	ActionSchedulerUnavailableReason,
	AnalysisReport,
	Provider,
	ReportPhase,
} from '@/admin/types/api';
import { phaseChangeCounts } from '@/admin/utils/changes';

import {
	action,
	AS_CORRUPT_FINAL,
	AS_POST,
	asPhase,
	asReasonReport,
	asReport,
	cronSchedule,
	largeQueue,
	SINGLE,
	WOOCOMMERCE,
} from './action-scheduler-fixtures';
import {
	addedOption,
	cronPhase,
	oneTime,
	optionsPhase,
} from './dogfood-fixtures';
import {
	asEverywhere,
	asUnavailable,
	COMPLETED,
	cronUnavailable,
	historyItem,
	listResponse,
	PRE_CRON,
	T,
	unavailable,
} from './fixtures';
import {
	cardTexts,
	openReport,
	phasePanel,
	signalCard,
	tab,
} from './report-view';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

/**
 * Opens a report on the Action Scheduler page (or another view).
 *
 * @param report Report served by the API.
 * @param signal Signal page; null for the overview.
 */
function renderReport(
	report: AnalysisReport,
	signal: Provider | null = 'action_scheduler'
) {
	apiFetchMock.mockResolvedValue( report );
	openReport( report.id, { signal: signal ?? undefined } );
	return screen.findAllByRole( 'tablist' );
}

const tabNames = ( list: string ) =>
	within( screen.getByRole( 'tablist', { name: list } ) )
		.getAllByRole( 'tab' )
		.map( ( t ) => t.textContent );
/** The selected phase of the Action Scheduler page. */
const signalPanel = phasePanel;
const section = ( name: string | RegExp ) =>
	within( signalPanel() ).getByRole( 'region', { name } );
/** The state line of each overview card, in signal order. */
const cardStates = () =>
	within( screen.getByRole( 'list', { name: 'Signals' } ) )
		.getAllByRole( 'listitem' )
		.map( ( card ) => card.querySelector( 'p > span' )!.textContent );
/** A number as the UI formats it in the host locale. */
const num = ( n: number ) => new Intl.NumberFormat().format( n );
const row = ( hook: string ) =>
	within( signalPanel() ).getAllByText( hook )[ 0 ].closest( 'li' )!;

async function openActionScheduler( phase: RegExp ) {
	const user = userEvent.setup();
	await screen.findAllByRole( 'tablist' );
	await user.click( tab( phase ) );
	return user;
}

/** A phase with the given change counts per signal (null = unavailable). */
function countsPhase(
	options: 'changes' | 'none' | null,
	actionScheduler: 'changes' | 'none' | null,
	cron: 'changes' | 'none' | null
): ReportPhase {
	return {
		options:
			options === null
				? unavailable( 'net_across_phases', 'data_corrupt' )
				: optionsPhase(
						'net_across_phases',
						options === 'changes'
							? { added: [ addedOption( 'acme_flag', 4 ) ] }
							: {}
					),
		cron:
			cron === null
				? cronUnavailable( 'net_across_phases', 'data_corrupt' )
				: cronPhase(
						'net_across_phases',
						cron === 'changes'
							? { added: [ oneTime( 'cron_job', T ) ] }
							: {}
					),
		action_scheduler:
			actionScheduler === null
				? asUnavailable( 'net_across_phases', 'not_installed' )
				: asPhase(
						'net_across_phases',
						actionScheduler === 'changes'
							? { added: [ action( 'as_job', '', T, SINGLE ) ] }
							: {}
					),
	};
}

describe( 'Action Scheduler in reports', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	describe( 'signal cards and counts', () => {
		it( 'puts Action Scheduler after Options and WP-Cron, with counts in every phase', async () => {
			await renderReport( WOOCOMMERCE, null );

			expect( cardStates() ).toEqual( [
				'6 changes',
				'5 changes',
				'5 changes',
			] );
			expect( cardTexts()[ 2 ] ).toMatch(
				/^Action SchedulerPending and in-progress background actions\.5 changes Added \d+/
			);
			// Net result: 6 options + 5 WP-Cron + 5 Action Scheduler.
			expect( tabNames( 'Observation phases' ) ).toEqual( [
				'During update, 1 change',
				'After update, 5 changes',
				'Net result, 16 changes',
			] );
			expect( screen.getByText( '16 observed changes' ) ).toBeVisible();
			// Rows are on the Action Scheduler page, not the overview.
			expect( document.body ).not.toHaveTextContent( 'fetch_patterns' );
			cleanup();

			await renderReport( WOOCOMMERCE );
			expect( tabNames( 'Observation phases' ) ).toEqual( [
				'During update, 1 change',
				'After update, 5 changes',
				'Net result, 5 changes',
			] );
			expect( signalPanel() ).toHaveTextContent( 'fetch_patterns' );
		} );

		it( 'keeps the phase total equal to the sum of its signal cards', async () => {
			await renderReport( WOOCOMMERCE, null );
			const user = userEvent.setup();

			for ( const [ phase, total ] of [
				[ /During update/, 1 ],
				[ /After update/, 5 ],
				[ /Net result/, 16 ],
			] as const ) {
				await user.click( tab( phase ) );
				const sum = cardStates()
					.map( ( state ) => /^(\d+) changes?$/.exec( state! ) )
					.reduce(
						( acc, match ) =>
							acc + ( match ? Number( match[ 1 ] ) : 0 ),
						0
					);
				expect( sum ).toBe( total );
				expect( tab( phase ) ).toHaveTextContent(
					total === 1 ? '1 change' : `${ total } changes`
				);
			}
		} );

		it( 'counts Action Scheduler-only changes and distinguishes zero from unavailable', async () => {
			const user = userEvent.setup();
			await renderReport(
				asReport( 50, ( association ) => ( {
					action_scheduler:
						association === 'update_request'
							? asUnavailable( association, 'not_installed' )
							: association === 'observed_after_update'
								? asPhase( association, {} )
								: AS_POST,
				} ) )
			);

			// The Action Scheduler page counts Action Scheduler only.
			expect( tabNames( 'Observation phases' ) ).toEqual( [
				'During update, Not available',
				'After update, 0 changes',
				'Net result, 5 changes',
			] );
			expect( signalPanel() ).toHaveTextContent(
				'5 observed Action Scheduler changes'
			);
			await user.click( tab( /After update/ ) );
			expect( signalPanel() ).toHaveTextContent(
				'No Action Scheduler changes observed'
			);
			await user.click( tab( /During update/ ) );
			expect( signalPanel() ).toHaveTextContent(
				'Action Scheduler not detected'
			);
			expect( signalPanel() ).not.toHaveTextContent( 'No changes' );

			// The overview tabs count every signal.
			cleanup();
			await renderReport(
				asReport( 50, ( association ) => ( {
					action_scheduler:
						association === 'update_request'
							? asUnavailable( association, 'not_installed' )
							: association === 'observed_after_update'
								? asPhase( association, {} )
								: AS_POST,
				} ) ),
				null
			);
			expect( tabNames( 'Observation phases' ) ).toEqual( [
				'During update, 0 changes',
				'After update, 0 changes',
				'Net result, 5 changes',
			] );
		} );

		it( 'sums all available signals and skips unavailable ones', () => {
			expect( phaseChangeCounts( WOOCOMMERCE.phases.final ) ).toEqual( {
				options: 6,
				cron: 5,
				action_scheduler: 5,
				total: 16,
			} );
			expect(
				phaseChangeCounts( countsPhase( 'none', null, 'none' ) )
			).toEqual( {
				options: 0,
				cron: 0,
				action_scheduler: null,
				total: 0,
			} );
			expect(
				phaseChangeCounts( countsPhase( null, 'changes', null ) )
			).toEqual( {
				options: null,
				cron: null,
				action_scheduler: 1,
				total: 1,
			} );
		} );

		it( 'has no signal tabs to navigate', async () => {
			for ( const signal of [ null, 'action_scheduler' ] as const ) {
				await renderReport( WOOCOMMERCE, signal );

				expect( screen.getAllByRole( 'tablist' ) ).toHaveLength( 1 );
				expect( screen.getAllByRole( 'tab' ) ).toHaveLength( 3 );
				expect(
					screen.queryByRole( 'tab', { name: /^Action Scheduler/ } )
				).toBeNull();
				cleanup();
			}
		} );
	} );

	describe( 'only signal with changes', () => {
		it( 'leads to Action Scheduler when it is the only signal with changes', async () => {
			const report = asReport( 51, ( association ) => ( {
				action_scheduler:
					association === 'net_across_phases'
						? AS_POST
						: asPhase( association, {} ),
			} ) );
			await renderReport( report, null );
			const user = userEvent.setup();

			expect( phasePanel() ).toHaveTextContent(
				'Changes were observed in Action Scheduler. No changes were observed in Options & autoload and WP-Cron.'
			);
			// The other signals keep their place, without breakdowns.
			expect( cardStates() ).toEqual( [
				'No changes',
				'No changes',
				'5 changes',
			] );

			await user.click(
				screen.getByRole( 'link', { name: 'Action Scheduler' } )
			);
			expect( signalPanel() ).toHaveTextContent(
				'wpforms_admin_notifications_update'
			);
			await user.click( tab( /During update/ ) );
			expect( signalPanel() ).toHaveTextContent(
				'No Action Scheduler changes observed'
			);
			expect( document.body ).not.toHaveTextContent(
				'wpforms_admin_notifications_update'
			);
		} );
	} );

	describe( 'unavailable states', () => {
		it( 'presents a site without Action Scheduler as normal, not as an error', async () => {
			await renderReport( asReasonReport( 'not_installed' ) );
			await openActionScheduler( /Net result/ );

			expect( signalPanel() ).toHaveTextContent(
				'Action Scheduler not detected'
			);
			expect( signalPanel() ).toHaveTextContent(
				'Action Scheduler was not active at the start of this phase.'
			);
			expect( signalPanel() ).not.toHaveTextContent(
				/error|failed|broken|unreadable|corrupt/i
			);
			expect(
				within( screen.getByRole( 'banner' ) ).getByText( 'Completed' )
			).toBeInTheDocument();
		} );

		it.each< [ ActionSchedulerUnavailableReason, string, string ] >( [
			[
				'unsupported_store',
				'Action Scheduler not supported',
				'Action Scheduler uses a storage configuration UpdateLens does not currently support.',
			],
			[
				'unsupported_schema',
				'Action Scheduler not supported',
				'This Action Scheduler database schema is not supported by this UpdateLens version.',
			],
			[
				'unsupported_schedule',
				'Action Scheduler analysis unavailable',
				'An Action Scheduler schedule type could not be normalized safely.',
			],
			[
				'malformed_action_scheduler_state',
				'Action Scheduler analysis unavailable',
				'Action Scheduler contained data UpdateLens could not safely normalize.',
			],
			[
				'snapshot_unavailable',
				'Action Scheduler analysis unavailable',
				'Action Scheduler state could not be captured for this phase.',
			],
			[
				'fingerprint_context_changed',
				'Comparison stopped',
				"Action Scheduler comparison stopped because the site's fingerprint context changed.",
			],
			[
				'settle_expired',
				'Not captured',
				'Post-update Action Scheduler state was not captured within 5 minutes of the update.',
			],
			[
				'not_captured',
				'Not captured',
				'Action Scheduler was not captured for this phase.',
			],
			[
				'storage_failed',
				'Action Scheduler analysis unavailable',
				'Action Scheduler analysis could not be stored safely for this phase.',
			],
			[
				'data_corrupt',
				'Unreadable',
				"This stored Action Scheduler phase couldn't be read safely.",
			],
			[
				'unknown',
				'Not available',
				'No Action Scheduler data was recorded for this phase.',
			],
		] )(
			'explains %s while Options and WP-Cron stay usable',
			async ( reason, title, description ) => {
				await renderReport( asReasonReport( reason ), null );

				expect( tab( /^Net result/ ) ).toHaveAccessibleName(
					'Net result, 11 changes'
				);
				expect( signalCard( 'Options & autoload' ) ).toHaveTextContent(
					'6 changes'
				);
				expect( signalCard( 'Action Scheduler' ) ).toHaveTextContent(
					`Not available ${ title }`
				);
				cleanup();

				await renderReport( asReasonReport( reason ) );
				await openActionScheduler( /Net result/ );
				expect( signalPanel() ).toHaveTextContent( title );
				expect( signalPanel() ).toHaveTextContent( description );
			}
		);

		it( 'renders reports from before Action Scheduler observation', async () => {
			await renderReport( {
				...PRE_CRON,
				phases: {
					...COMPLETED.phases,
					...Object.fromEntries(
						Object.entries( COMPLETED.phases ).map(
							( [ key, phase ] ) => [
								key,
								{
									...phase,
									action_scheduler:
										asEverywhere( 'not_captured' )[
											key as keyof typeof COMPLETED.phases
										],
								},
							]
						)
					),
				} as AnalysisReport[ 'phases' ],
			} );

			expect( tab( /^Net result/ ) ).toHaveTextContent( 'Not available' );
			expect( signalPanel() ).toHaveTextContent( 'Not captured' );
			expect( signalPanel() ).not.toHaveTextContent( 'No changes' );
		} );

		it( 'isolates a corrupt Action Scheduler phase', async () => {
			await renderReport( AS_CORRUPT_FINAL, null );

			expect( tab( /^Net result/ ) ).toHaveAccessibleName(
				'Net result, 11 changes'
			);
			expect( cardStates() ).toEqual( [
				'6 changes',
				'5 changes',
				'Not available',
			] );
			cleanup();

			await renderReport( AS_CORRUPT_FINAL );
			const user = await openActionScheduler( /Net result/ );
			expect( signalPanel() ).toHaveTextContent( 'Unreadable' );
			await user.click( tab( /After update/ ) );
			expect( signalPanel() ).toHaveTextContent(
				'wpforms_admin_notifications_update'
			);
		} );
	} );

	describe( 'available phases', () => {
		it( 'is compact without changes', async () => {
			await renderReport(
				asReport( 52, ( association ) => ( {
					options: optionsPhase( association, {
						added: [ addedOption( 'acme_flag', 4 ) ],
					} ),
				} ) )
			);
			await openActionScheduler( /Net result/ );

			expect( signalPanel() ).toHaveTextContent(
				'No Action Scheduler changes observed'
			);
			expect( within( signalPanel() ).queryByRole( 'term' ) ).toBeNull();
			expect(
				within( signalPanel() ).queryByRole( 'region' )
			).toBeNull();
			expect( signalPanel() ).not.toHaveTextContent(
				/arguments|short-lived/
			);
		} );

		it( 'shows "No tracked changes" only when every available signal has none', async () => {
			await renderReport(
				asReport( 53, () => ( {} ) ),
				null
			);
			expect(
				screen.getByText(
					'No tracked changes observed during this phase.'
				)
			).toBeInTheDocument();
		} );

		it( 'shows the summary with "No longer active" and quiet rescheduling', async () => {
			await renderReport( WOOCOMMERCE );
			await openActionScheduler( /After update/ );

			// The counts first; the active-action totals are quiet statistics
			// after the lists.
			const terms = within( signalPanel() )
				.getAllByRole( 'term' )
				.map( ( t ) => t.textContent );
			expect( terms.slice( 0, 4 ) ).toEqual( [
				'Added',
				'No longer active',
				'Changed',
				'Rescheduled',
			] );
			expect( terms.slice( -4 ) ).toEqual( [
				'Active actions',
				'Recurring',
				'One-time',
				'Unique hooks',
			] );
			expect( signalPanel() ).not.toHaveTextContent(
				/Completed|Canceled|Deleted|Removed|Executed/
			);
			expect( signalPanel() ).toHaveTextContent(
				'This is scheduled state, not execution history.'
			);
			expect(
				within( signalPanel() )
					.getAllByRole( 'heading', { level: 3 } )
					.map( ( heading ) => heading.textContent )
			).toEqual( [
				'Added (1)',
				'No longer active (1)',
				'Changed (1)',
				'Rescheduled (2)',
				'Site totals',
			] );
		} );

		it( 'renders an added interval action with group, next run, interval and status', async () => {
			await renderReport( WOOCOMMERCE );
			await openActionScheduler( /During update/ );

			const added = row( 'fetch_patterns' );
			expect( added ).toHaveTextContent( 'Group: woocommerce' );
			expect( added ).toHaveTextContent( 'Interval · Every 1 day' );
			expect( added ).toHaveTextContent( /Next run\s*\w+/ );
			expect( added ).toHaveTextContent( /Status\s*Pending/ );
			expect(
				within( added ).getByText( 'fetch_patterns' ).tagName
			).toBe( 'CODE' );
			expect( within( added ).getByRole( 'time' ) ).toHaveAttribute(
				'dateTime',
				'2026-10-06T10:00:05Z'
			);
		} );

		it( 'renders an async in-progress action as one-time work, never recurring', async () => {
			await renderReport( WOOCOMMERCE );
			await openActionScheduler( /After update/ );

			const added = row( 'woocommerce_run_on_woocommerce_admin_updated' );
			expect( added ).toHaveTextContent(
				'Group: woocommerce-remote-inbox-engine'
			);
			expect( added ).toHaveTextContent( 'Async · As soon as possible' );
			expect( added ).toHaveTextContent( /Scheduled for/ );
			expect( added ).toHaveTextContent( /Status\s*In progress/ );
			expect( added ).not.toHaveTextContent( /Next run|Every|Recurring/ );
		} );

		it( 'renders a single action that is no longer active without claiming why', async () => {
			await renderReport( WOOCOMMERCE );
			await openActionScheduler( /After update/ );

			const gone = row( 'wpforms_admin_notifications_update' );
			expect( gone ).toHaveTextContent( 'Group: wpforms' );
			expect( gone ).toHaveTextContent( 'No longer active · One-time' );
			expect( gone ).toHaveTextContent( /Previously scheduled for/ );
			expect( signalPanel() ).toHaveTextContent(
				'An action may leave the active queue because it ran, was canceled, or otherwise changed state.'
			);
			expect( gone ).not.toHaveTextContent(
				/\bran\b|completed|canceled|deleted|executed/i
			);
		} );

		it( 'renders rescheduled actions quietly with translated movement', async () => {
			await renderReport( WOOCOMMERCE );
			await openActionScheduler( /After update/ );

			const moved = within( section( 'Rescheduled (2)' ) ).getAllByRole(
				'listitem'
			);
			expect( moved[ 0 ] ).toHaveTextContent(
				'action_scheduler/migration_hook'
			);
			expect( moved[ 0 ] ).toHaveTextContent(
				'Group: action-scheduler-migration'
			);
			expect( moved[ 0 ] ).toHaveTextContent(
				`(+${ num( 1.4 ) } minutes)`
			);
			expect( moved[ 0 ] ).toHaveTextContent( 'One-time' );
			expect( moved[ 1 ] ).toHaveTextContent( 'fetch_patterns' );
			expect( moved[ 1 ] ).toHaveTextContent( '(+1 day)' );
			expect( moved[ 1 ] ).toHaveTextContent( 'Interval · Every 1 day' );
			// A same-day move does not repeat the date; a move to another day
			// does. Both times keep their full value for assistive technology.
			const [ from, to ] = Array.from(
				moved[ 0 ].querySelectorAll( 'time' )
			);
			expect( from.textContent ).toMatch( /2026/ );
			expect( to.textContent ).not.toMatch( /2026/ );
			expect( to.getAttribute( 'datetime' ) ).toMatch(
				/^2026-\d\d-\d\dT[\d:]+Z$/
			);
			expect(
				moved[ 1 ].querySelectorAll( 'time' )[ 1 ].textContent
			).toMatch( /2026/ );
			// Quieter than the other lists, never a warning.
			const quiet = within( section( 'Rescheduled (2)' ) ).getByRole(
				'heading'
			);
			expect( quiet ).toHaveClass( 'font-medium', 'text-slate-600' );
			expect( quiet ).not.toHaveClass( 'font-semibold' );
			expect(
				within( section( 'Added (1)' ) ).getByRole( 'heading' )
			).toHaveClass( 'font-semibold' );
			expect( document.body ).not.toHaveTextContent(
				/warning|problem|danger|caused/i
			);
		} );

		it( 'renders a schedule change from interval to cron expression', async () => {
			await renderReport( WOOCOMMERCE );
			await openActionScheduler( /After update/ );

			const changed = row( 'wpforms_email_summaries_fetch_info_blocks' );
			expect( changed ).toHaveTextContent( 'Schedule changed' );
			expect( changed ).toHaveTextContent(
				/Schedule type\s*Interval\s*→\s*to\s*Cron schedule/
			);
			expect( changed ).toHaveTextContent(
				/Interval\s*Every 7 days\s*→\s*to\s*None/
			);
			expect( changed ).toHaveTextContent(
				/Cron expression\s*None\s*→\s*to\s*0 \*\/6 \* \* \*/
			);
			expect( changed ).toHaveTextContent( '(unchanged)' );
			expect( within( changed ).getByText( '0 */6 * * *' ).tagName ).toBe(
				'CODE'
			);
		} );

		it( 'renders interval changes and cron expressions as stored', async () => {
			await renderReport(
				asReport( 54, ( association ) => ( {
					action_scheduler: asPhase( association, {
						added: [
							action(
								'acme_report',
								'acme',
								T,
								cronSchedule( '15 3 * * 1-5' )
							),
						],
						changed: [
							{
								hook: 'acme_sync',
								group: '',
								before_timestamp: T,
								after_timestamp: T + 3600,
								timestamp_changed: true,
								before_schedule_type: 'interval',
								after_schedule_type: 'interval',
								before_interval: 86400,
								after_interval: 3600,
								before_cron_expression: null,
								after_cron_expression: null,
								before_is_recurring: true,
								after_is_recurring: true,
							},
						],
					} ),
				} ) )
			);
			await openActionScheduler( /Net result/ );

			expect( row( 'acme_report' ) ).toHaveTextContent(
				'Cron schedule · 15 3 * * 1-5'
			);
			const changed = row( 'acme_sync' );
			expect( changed ).toHaveTextContent(
				/Interval\s*Every 1 day\s*→\s*to\s*Every 1 hour/
			);
			expect( changed ).not.toHaveTextContent( /Schedule type|Group/ );
			expect( within( changed ).getAllByRole( 'time' ) ).toHaveLength(
				2
			);
		} );

		it( 'shows groups as metadata and never claims ownership', async () => {
			await renderReport( WOOCOMMERCE );
			await openActionScheduler( /After update/ );

			// Other plugins' work in the same window is shown, not filtered.
			for ( const hook of [
				'wpforms_admin_notifications_update',
				'wpforms_email_summaries_fetch_info_blocks',
				'action_scheduler/migration_hook',
			] ) {
				expect( signalPanel() ).toHaveTextContent( hook );
			}
			expect( document.body ).not.toHaveTextContent(
				/owned by|WooCommerce action|WPForms action|other plugin action|likely|caused by|created by/i
			);
		} );

		it( 'shows repeated hooks and explains hidden arguments and short-lived actions', async () => {
			await renderReport( largeQueue( 3 ) );
			await openActionScheduler( /Net result/ );

			expect(
				within( section( 'Added (3)' ) ).getAllByText(
					'ul_stress_import_batch'
				)
			).toHaveLength( 3 );
			expect( signalPanel() ).toHaveTextContent(
				'Action arguments are fingerprinted for matching but are never stored or shown. Similar-looking rows may represent different argument sets.'
			);
			expect( signalPanel() ).toHaveTextContent(
				'UpdateLens compares active Action Scheduler state at observation points. Very short-lived actions that are queued and completed between captures may not appear.'
			);
			// Nothing left the queue: no note about leaving it.
			expect( signalPanel() ).not.toHaveTextContent(
				'leave the active queue'
			);
		} );

		// Renders and expands 1000 rows in jsdom: slower than the default
		// 5 s timeout when the whole suite runs in parallel.
		it( `collapses long lists after ${ DEFAULT_VISIBLE_DIFF_ROWS } rows`, async () => {
			await renderReport( largeQueue( 1000 ) );
			const user = await openActionScheduler( /Net result/ );

			expect( signalPanel() ).toHaveTextContent(
				`${ num( 2000 ) } observed Action Scheduler changes`
			);
			expect(
				within( signalPanel() ).getAllByRole( 'listitem' )
			).toHaveLength( 2 * DEFAULT_VISIBLE_DIFF_ROWS );
			const added = section( `Added (${ num( 1000 ) })` );
			const more = within( added ).getByRole( 'button', {
				name: 'Show 990 more added actions',
			} );
			expect( more ).toHaveAttribute( 'aria-expanded', 'false' );
			await user.click( more );
			expect( within( added ).getAllByRole( 'listitem' ) ).toHaveLength(
				1000
			);
			const less = within( added ).getByRole( 'button', {
				name: 'Show fewer added actions',
			} );
			expect( less ).toHaveFocus();
			await user.click( less );
			expect( within( added ).getAllByRole( 'listitem' ) ).toHaveLength(
				DEFAULT_VISIBLE_DIFF_ROWS
			);
			expect(
				within( section( `Rescheduled (${ num( 1000 ) })` ) ).getByRole(
					'button',
					{
						name: 'Show 990 more rescheduled actions',
					}
				)
			).toBeInTheDocument();
		}, 20000 );

		it( 'uses UpdateLens translations for duration units', async () => {
			setLocaleData(
				{
					'': {
						domain: 'updatelens',
						plural_forms: 'nplurals=2; plural=(n != 1);',
					},
					'%s day': [ '%s xday', '%s xdays' ],
					'Every %s': [ 'Xevery %s' ],
				},
				'updatelens'
			);
			await renderReport( WOOCOMMERCE );
			await openActionScheduler( /After update/ );

			const moved = within( section( 'Rescheduled (2)' ) ).getAllByRole(
				'listitem'
			);
			expect( moved[ 1 ] ).toHaveTextContent( '(+1 xday)' );
			expect( moved[ 1 ] ).toHaveTextContent( 'Xevery 1 xday' );
			expect( document.body ).not.toHaveTextContent( /\d days?\b/ );
		} );
	} );

	describe( 'privacy', () => {
		it( 'renders only typed fields, never arguments or internal identity', async () => {
			const SECRET = 'sk_test_UPDATE_LENS_ACTION_SCHEDULER_REPORT_SECRET';
			type Leaky = Record< string, unknown >;
			const leaky = structuredClone( WOOCOMMERCE ) as unknown as {
				phases: Record<
					string,
					{
						action_scheduler: Leaky &
							Record<
								'added' | 'removed' | 'rescheduled' | 'changed',
								Leaky[]
							>;
					}
				>;
			};
			for ( const phase of Object.values( leaky.phases ) ) {
				const as = phase.action_scheduler;
				as.fingerprint_context = `as-args-hmac-sha256-v1:${ SECRET }`;
				for ( const list of [
					as.added,
					as.removed,
					as.rescheduled,
					as.changed,
				] ) {
					for ( const item of list ) {
						Object.assign( item, {
							args: { token: SECRET },
							args_fingerprint: `fp_${ SECRET }`,
							action_id: 987654,
							claim_id: 123456,
							schedule: `O:30:"ActionScheduler_SimpleSchedule":${ SECRET }`,
						} );
					}
				}
			}
			await renderReport( leaky as unknown as AnalysisReport );

			for ( const phase of [
				/During update/,
				/After update/,
				/Net result/,
			] ) {
				await openActionScheduler( phase );
				const html = document.body.innerHTML;
				for ( const needle of [
					SECRET,
					'fp_',
					'987654',
					'123456',
					'ActionScheduler_',
					'as-args-hmac',
					'token',
				] ) {
					expect( html ).not.toContain( needle );
				}
			}
		} );
	} );
} );

describe( 'Action Scheduler in the history', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'counts Action Scheduler-only changes as changes observed', async () => {
		const onlyAs = asReport( 60, ( association ) => ( {
			action_scheduler:
				association === 'observed_after_update'
					? AS_POST
					: asPhase( association, {} ),
		} ) );
		const noAs = asReport( 61, ( association ) => ( {
			action_scheduler: asUnavailable( association, 'not_installed' ),
		} ) );
		apiFetchMock.mockResolvedValue(
			listResponse( [ historyItem( onlyAs ), historyItem( noAs ) ] )
		);
		render(
			<HistoryPage
				page={ 1 }
				reportHref={ ( id ) => `?page=updatelens&analysis=${ id }` }
				onOpenReport={ vi.fn() }
				onPageChange={ vi.fn() }
				focusHeading={ false }
			/>
		);

		const rows = await screen.findAllByRole( 'link' );
		const phases = ( link: HTMLElement ) =>
			within( link )
				.getAllByRole( 'listitem' )
				.map( ( item ) => item.textContent );

		expect( phases( rows[ 0 ] ) ).toEqual( [
			'During update:No changes',
			'After update:Changes observed',
			'Net result:No changes',
		] );
		// Not installed: described by the other signals, never as unavailable.
		expect( phases( rows[ 1 ] ) ).toEqual( [
			'During update:No changes',
			'After update:No changes',
			'Net result:No changes',
		] );
		expect( document.body ).not.toHaveTextContent( /Action Scheduler/ );
	} );
} );
