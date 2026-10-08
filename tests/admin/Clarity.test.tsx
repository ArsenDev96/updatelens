import apiFetch from '@wordpress/api-fetch';
import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { DEFAULT_VISIBLE_DIFF_ROWS } from '@/admin/components/DiffSection';
import { HistoryPage } from '@/admin/pages/HistoryPage';
import type {
	AnalysisReport,
	ChangedOption,
	Provider,
} from '@/admin/types/api';

import {
	CANCELLED,
	changedOption,
	CRON_CHANGES_ONLY,
	cronPhase,
	ELEMENTOR,
	MIXED_WOOCOMMERCE,
	moved,
	oneTime,
	optionsPhase,
	RANK_MATH,
	STRESS,
	WORDFENCE,
} from './dogfood-fixtures';
import {
	COMPLETED,
	EXPIRED,
	FAILED,
	historyItem,
	listResponse,
	MALFORMED_CRON,
	T,
} from './fixtures';
import { openReport, phasePanel, signalCard, tab } from './report-view';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

/**
 * Opens a report's overview, or one signal's details.
 *
 * @param report Report served by the API.
 * @param signal Signal whose details are open.
 */
function renderReport( report: AnalysisReport, signal?: Provider ) {
	apiFetchMock.mockResolvedValue( report );
	openReport( report.id, { signal } );
	return screen.findAllByRole( 'tablist' );
}

const tabNames = ( list: string ) =>
	within( screen.getByRole( 'tablist', { name: list } ) )
		.getAllByRole( 'tab' )
		.map( ( t ) => t.textContent );
/** The selected phase of a signal page. */
const signalPanel = phasePanel;
const section = ( name: string | RegExp ) =>
	within( signalPanel() ).getByRole( 'region', { name } );
/** The state line of each overview card, in signal order. */
const cardStates = () =>
	within( screen.getByRole( 'list', { name: 'Signals' } ) )
		.getAllByRole( 'listitem' )
		.map( ( card ) => card.querySelector( 'p > span' )!.textContent );

/**
 * A report whose Net result Options phase has `n` changed options.
 *
 * @param n Number of changed options.
 */
function withChangedOptions( n: number ): AnalysisReport {
	const changed: ChangedOption[] = Array.from( { length: n }, ( _, i ) =>
		changedOption( `option_${ String( i ).padStart( 2, '0' ) }`, 10, 12 )
	);
	return {
		...RANK_MATH,
		phases: {
			...RANK_MATH.phases,
			final: {
				...RANK_MATH.phases.final,
				options: optionsPhase( 'net_across_phases', { changed } ),
			},
		},
	};
}

describe( 'report clarity', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	describe( 'tab change indicators', () => {
		it( 'counts every signal per phase and per signal card', async () => {
			await renderReport( COMPLETED );

			expect( tabNames( 'Observation phases' ) ).toEqual( [
				'During update, 2 changes',
				'After update, 11 changes',
				'Net result, 11 changes',
			] );
			// Phase indicators name the changes; the phase total is the sum of the signals.
			expect( tab( /^Net result/ ) ).toHaveTextContent(
				/^Net result, 11 changes$/
			);
			expect( phasePanel() ).toHaveTextContent( '11 observed changes' );
			expect( cardStates() ).toEqual( [
				'6 changes',
				'5 changes',
				'Not available',
			] );
		} );

		it( 'shows option-only changes (Elementor style)', async () => {
			await renderReport( ELEMENTOR );

			expect( tabNames( 'Observation phases' ) ).toEqual( [
				'During update, 0 changes',
				'After update, 5 changes',
				'Net result, 5 changes',
			] );
			expect( cardStates() ).toEqual( [
				'5 changes',
				'No changes',
				'Not available',
			] );
			expect( phasePanel() ).toHaveTextContent(
				'Changes were observed in Options & autoload. No changes were observed in WP-Cron. Action Scheduler was not available for this phase.'
			);
			expect( tab( /^During update/ ) ).toHaveTextContent(
				/^During update, 0 changes$/
			);
		} );

		it( 'shows WP-Cron-only changes and opens them on the WP-Cron page', async () => {
			await renderReport( CRON_CHANGES_ONLY );
			const user = userEvent.setup();

			expect( cardStates() ).toEqual( [
				'No changes',
				'1 change',
				'Not available',
			] );
			expect( phasePanel() ).not.toHaveTextContent( 'cron_only_once' );

			await user.click( screen.getByRole( 'link', { name: 'WP-Cron' } ) );
			expect( signalPanel() ).toHaveTextContent( 'cron_only_once' );
		} );

		it( 'shows zero changes everywhere (Rank Math style)', async () => {
			await renderReport( RANK_MATH );

			expect( tabNames( 'Observation phases' ) ).toEqual( [
				'During update, 0 changes',
				'After update, 0 changes',
				'Net result, 0 changes',
			] );
		} );

		it( 'keeps a phase available when one signal is unavailable', async () => {
			await renderReport( MALFORMED_CRON );

			expect( tab( /^Net result/ ) ).toHaveAccessibleName(
				'Net result, 6 changes'
			);
			expect( signalCard( 'WP-Cron' ) ).toHaveTextContent(
				/^WP-CronNot available WP-Cron analysis unavailable/
			);
			cleanup();

			await renderReport( MALFORMED_CRON, 'cron' );
			expect( tab( /^Net result/ ) ).toHaveAccessibleName(
				'Net result, Not available'
			);
			expect( signalPanel() ).toHaveTextContent(
				'WP-Cron analysis unavailable'
			);
		} );

		it( 'marks a phase unavailable only when no signal is available', async () => {
			await renderReport( EXPIRED );

			expect( tabNames( 'Observation phases' ) ).toEqual( [
				'During update, 2 changes',
				'After update, Not available',
				'Net result, Not available',
			] );
		} );
	} );

	describe( 'defaults', () => {
		it( 'opens Net result when it has changes, on the overview and every page', async () => {
			for ( const signal of [ undefined, 'options', 'cron' ] as const ) {
				await renderReport( COMPLETED, signal );
				expect( tab( /^Net result/ ) ).toHaveAttribute(
					'aria-selected',
					'true'
				);
				cleanup();
			}
		} );

		it( 'opens the phase with changes instead of an empty Net result', async () => {
			await renderReport( CANCELLED );

			expect( tab( /^After update/ ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			cleanup();
			await renderReport( CANCELLED, 'options' );
			expect( tab( /^After update/ ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			expect( signalPanel() ).toHaveTextContent(
				'jetpack_waf_needs_update'
			);
		} );
	} );

	describe( 'no-change states', () => {
		it( 'is compact for a report without any change', async () => {
			await renderReport( RANK_MATH );

			expect( phasePanel() ).toHaveTextContent(
				'No tracked changes observed during this phase.'
			);
			expect( cardStates() ).toEqual( [
				'No changes',
				'No changes',
				'Not available',
			] );
			// No breakdowns on cards without changes.
			expect( signalCard( 'Options & autoload' ) ).toHaveTextContent(
				/^Options & autoloadNo changes View details →$/
			);
		} );

		it( 'shows a calm empty state on a signal page without changes', async () => {
			await renderReport( RANK_MATH, 'options' );

			expect( signalPanel() ).toHaveTextContent(
				'No option changes observed'
			);
			expect( signalPanel() ).toHaveTextContent(
				'UpdateLens captured this phase successfully, but the tracked option state did not change.'
			);
			// No zero counts, empty lists or notes.
			expect(
				within( signalPanel() ).queryByRole( 'definition' )
			).toBeNull();
			expect(
				within( signalPanel() ).queryByRole( 'heading' )
			).toBeNull();
			expect( signalPanel() ).not.toHaveTextContent(
				'No added options.'
			);
			expect( signalPanel() ).not.toHaveTextContent(
				'without storing the option values'
			);
			cleanup();

			await renderReport( RANK_MATH, 'cron' );
			expect( signalPanel() ).toHaveTextContent(
				'No WP-Cron changes observed'
			);
			expect( signalPanel() ).toHaveTextContent(
				'UpdateLens captured this phase successfully, but no scheduled events changed.'
			);
			expect( signalPanel() ).not.toHaveTextContent( 'arguments' );
			expect( signalPanel() ).not.toHaveTextContent(
				'WordPress core and other plugins may also'
			);
		} );

		it( 'is compact for one empty signal next to one with changes', async () => {
			await renderReport( ELEMENTOR );

			expect( phasePanel() ).not.toHaveTextContent(
				'No tracked changes observed'
			);
			expect( signalCard( 'WP-Cron' ) ).toHaveTextContent(
				/^WP-CronNo changes View details →$/
			);
			expect( signalCard( 'Options & autoload' ) ).toHaveTextContent(
				'5 changes'
			);
			cleanup();

			await renderReport( ELEMENTOR, 'cron' );
			expect( signalPanel() ).toHaveTextContent(
				'No WP-Cron changes observed'
			);
			expect(
				within( signalPanel() ).queryByRole( 'definition' )
			).toBeNull();
		} );
	} );

	describe( 'WP-Cron disappearances', () => {
		it( 'says a one-time job is no longer scheduled, never that it ran or was deleted', async () => {
			await renderReport( MIXED_WOOCOMMERCE, 'cron' );

			const gone = section( 'No longer scheduled (1)' );
			const row = within( gone ).getByRole( 'listitem' );
			expect( row ).toHaveTextContent(
				'wordfence_completeCoreUpdateNotification'
			);
			expect( row ).toHaveTextContent( 'One-time · No longer scheduled' );
			expect( row ).toHaveTextContent( /Previously scheduled for/ );
			expect( row.querySelector( 'time' ) ).not.toBeNull();
			expect( row ).not.toHaveTextContent(
				/\b(ran|executed|completed|deleted|removed|unscheduled)\b/i
			);
			expect( signalPanel() ).toHaveTextContent(
				'One-time jobs may disappear because they ran or were unscheduled.'
			);
			expect( signalPanel() ).not.toHaveTextContent( /executed/i );
		} );

		it( 'keeps "Removed" for recurring events', async () => {
			await renderReport( MIXED_WOOCOMMERCE, 'cron' );

			const removed = section( 'Removed (1)' );
			expect( removed ).toHaveTextContent( 'acme_legacy_sync' );
			expect( removed ).toHaveTextContent( 'Recurring · hourly' );
			expect( removed ).toHaveTextContent( /Was scheduled for/ );
			expect( removed ).not.toHaveTextContent( 'wordfence' );
		} );

		it( 'counts recurring and one-time disappearances together as "No longer present"', async () => {
			await renderReport( MIXED_WOOCOMMERCE, 'cron' );

			const terms = within( signalPanel() )
				.getAllByRole( 'term' )
				.slice( 0, 4 );
			expect( terms.map( ( t ) => t.textContent ) ).toEqual( [
				'Added',
				'No longer present',
				'Changed',
				'Rescheduled',
			] );
			// One recurring "Removed" plus one one-time "No longer scheduled".
			expect( terms[ 1 ].nextElementSibling ).toHaveTextContent( '2' );
			expect( section( 'Removed (1)' ) ).toBeInTheDocument();
			expect( section( 'No longer scheduled (1)' ) ).toBeInTheDocument();
		} );

		it( 'never labels only one-time disappearances "Removed"', async () => {
			await renderReport(
				{
					...WORDFENCE,
					phases: {
						...WORDFENCE.phases,
						final: {
							...WORDFENCE.phases.final,
							cron: cronPhase( 'net_across_phases', {
								removed: [
									oneTime(
										'wordfence_completeCoreUpdateNotification',
										T + 60
									),
									oneTime(
										'wordfence_version_check',
										T + 120
									),
								],
							} ),
						},
					},
				},
				'cron'
			);

			const terms = within( signalPanel() ).getAllByRole( 'term' );
			expect( terms[ 1 ] ).toHaveTextContent( 'No longer present' );
			expect( terms[ 1 ].nextElementSibling ).toHaveTextContent( '2' );
			expect( section( 'No longer scheduled (2)' ) ).toBeInTheDocument();
			expect( signalPanel() ).not.toHaveTextContent( /Removed/ );
		} );
	} );

	describe( 'conditional WP-Cron notes', () => {
		it( 'explains hidden arguments when rows exist, but no one-time notes without one-time movement', async () => {
			await renderReport( WORDFENCE, 'cron' );

			expect( signalPanel() ).toHaveTextContent(
				'WP-Cron event arguments are fingerprinted for matching but are never stored or shown.'
			);
			expect( signalPanel() ).not.toHaveTextContent(
				'One-time jobs may disappear'
			);
			expect( signalPanel() ).not.toHaveTextContent(
				'A moved one-time event'
			);
			// Rescheduled stays visible, quietly: no warning wording.
			const rescheduled = section( 'Rescheduled (2)' );
			expect( rescheduled ).toHaveTextContent( 'wordfence_daily_cron' );
			expect( rescheduled ).toHaveTextContent( '(−1 day)' );
			expect( signalPanel() ).not.toHaveTextContent( /warning|problem/i );
		} );

		it( 'explains a moved one-time event only when there is one', async () => {
			await renderReport(
				{
					...WORDFENCE,
					phases: {
						...WORDFENCE.phases,
						final: {
							...WORDFENCE.phases.final,
							cron: cronPhase( 'net_across_phases', {
								rescheduled: [
									moved(
										oneTime( 'acme_once', T + 60 ),
										600
									),
								],
							} ),
						},
					},
				},
				'cron'
			);

			expect( signalPanel() ).toHaveTextContent(
				'A moved one-time event may represent a reschedule or a new equivalent event after execution.'
			);
		} );
	} );

	describe( 'changed option density', () => {
		it( 'shows a value-only change on one line', async () => {
			await renderReport( ELEMENTOR, 'options' );

			const version = within( signalPanel() )
				.getByText( 'elementor_version' )
				.closest( 'li' )!;
			expect( version ).toHaveTextContent(
				/^elementor_versionValue changed\s*·\s*5 B$/
			);
			expect( version ).not.toHaveTextContent( 'Autoload' );
			expect( version.querySelector( 'dl' ) ).toBeNull();
		} );

		it( 'shows a size change with the exact delta', async () => {
			await renderReport( ELEMENTOR, 'options' );

			const history = within( signalPanel() )
				.getByText( 'elementor_install_history' )
				.closest( 'li' )!;
			expect( history ).toHaveTextContent(
				/Value changed\s*·\s*31 B\s*→\s*to\s*56 B\s*\(\+25 B\)/
			);
			expect( history ).not.toHaveTextContent( 'Autoload' );
		} );

		it( 'shows autoload details only when autoload changed', async () => {
			const flipped: ChangedOption = {
				...changedOption( 'acme_cache', 2048, 2048, 'off' ),
				value_changed: false,
				after_autoload: 'on',
				autoload_value_changed: true,
				after_is_autoloaded: true,
				autoload_behavior_changed: true,
			};
			await renderReport(
				{
					...ELEMENTOR,
					phases: {
						...ELEMENTOR.phases,
						final: {
							...ELEMENTOR.phases.final,
							options: optionsPhase( 'net_across_phases', {
								changed: [ flipped ],
							} ),
						},
					},
				},
				'options'
			);

			const row = within( signalPanel() )
				.getByText( 'acme_cache' )
				.closest( 'li' )!;
			expect( row ).toHaveTextContent( /Value unchanged\s*·\s*2 KB/ );
			expect( row ).toHaveTextContent(
				/Autoload setting\s*off\s*→\s*to\s*on/
			);
			expect( row ).toHaveTextContent(
				/Autoload behavior\s*Off\s*→\s*to\s*On\s*\(behavior changed\)/
			);
		} );

		it( 'adds precision when rounded totals would look identical', async () => {
			await renderReport( ELEMENTOR, 'options' );

			expect( signalPanel() ).toHaveTextContent(
				/Autoloaded data\s*5[.,]98 KB\s*→\s*to\s*6[.,]04 KB\s*\(\+58 B\)/
			);
		} );
	} );

	describe( 'long lists', () => {
		it( `shows the first ${ DEFAULT_VISIBLE_DIFF_ROWS } rows and expands and collapses`, async () => {
			await renderReport( STRESS, 'options' );
			const user = userEvent.setup();

			const added = section( 'Added (470)' );
			expect( within( added ).getAllByRole( 'listitem' ) ).toHaveLength(
				DEFAULT_VISIBLE_DIFF_ROWS
			);
			const more = within( added ).getByRole( 'button', {
				name: 'Show 460 more added options',
			} );
			expect( more ).toHaveAttribute( 'aria-expanded', 'false' );
			expect(
				document.getElementById( more.getAttribute( 'aria-controls' )! )
			).toBe( within( added ).getByRole( 'list' ) );

			await user.click( more );
			expect( within( added ).getAllByRole( 'listitem' ) ).toHaveLength(
				470
			);
			const less = within( added ).getByRole( 'button', {
				name: 'Show fewer added options',
			} );
			expect( less ).toHaveAttribute( 'aria-expanded', 'true' );
			expect( less ).toHaveFocus();

			await user.click( less );
			expect( within( added ).getAllByRole( 'listitem' ) ).toHaveLength(
				DEFAULT_VISIBLE_DIFF_ROWS
			);
			// Focus stays on the control instead of jumping elsewhere.
			expect(
				within( added ).getByRole( 'button', {
					name: 'Show 460 more added options',
				} )
			).toHaveFocus();
		} );

		it( 'collapses one row over the threshold, with a singular label', async () => {
			await renderReport(
				withChangedOptions( DEFAULT_VISIBLE_DIFF_ROWS + 1 ),
				'options'
			);

			const changed = section( /^Changed/ );
			expect( within( changed ).getAllByRole( 'listitem' ) ).toHaveLength(
				DEFAULT_VISIBLE_DIFF_ROWS
			);
			expect(
				within( changed ).getByRole( 'button', {
					name: 'Show 1 more changed option',
				} )
			).toBeInTheDocument();
		} );

		it.each( [ DEFAULT_VISIBLE_DIFF_ROWS, DEFAULT_VISIBLE_DIFF_ROWS - 3 ] )(
			'shows %i rows without a button',
			async ( n ) => {
				await renderReport( withChangedOptions( n ), 'options' );

				const changed = section( `Changed (${ n })` );
				expect(
					within( changed ).getAllByRole( 'listitem' )
				).toHaveLength( n );
				expect( within( changed ).queryByRole( 'button' ) ).toBeNull();
			}
		);

		it( 'collapses repeated Cron hooks too, keeping the total', async () => {
			await renderReport( STRESS, 'cron' );

			const added = section( 'Added (300)' );
			expect(
				within( added ).getAllByText( 'ul_stress_same_hook' )
			).toHaveLength( DEFAULT_VISIBLE_DIFF_ROWS );
			expect(
				within( added ).getByRole( 'button', {
					name: 'Show 290 more added events',
				} )
			).toBeInTheDocument();
			expect( signalPanel() ).toHaveTextContent(
				'Events with the same hook may therefore represent different argument sets.'
			);
		} );
	} );

	describe( 'observed data is never hidden', () => {
		it( 'shows unrelated and cache-like records next to the plugin’s own', async () => {
			await renderReport( MIXED_WOOCOMMERCE, 'options' );

			for ( const name of [
				'woocommerce_version',
				'_elementor_design_system_sync_css_meta',
				'action_scheduler_lock_async-request-runner',
			] ) {
				expect( signalPanel() ).toHaveTextContent( name );
			}
			cleanup();
			await renderReport( MIXED_WOOCOMMERCE, 'cron' );
			expect( signalPanel() ).toHaveTextContent(
				'action_scheduler_run_queue'
			);
			expect( document.body ).not.toHaveTextContent(
				/likely plugin|related to|caused|owned by/i
			);
		} );
	} );
} );

describe( 'history change wording', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'separates changes, no changes and unavailable', async () => {
		apiFetchMock.mockResolvedValue(
			listResponse( [
				historyItem( ELEMENTOR ),
				historyItem( RANK_MATH ),
				historyItem( FAILED ),
				historyItem( MALFORMED_CRON ),
			] )
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
			'Net result:Changes observed',
		] );
		expect( phases( rows[ 1 ] ) ).toEqual( [
			'During update:No changes',
			'After update:No changes',
			'Net result:No changes',
		] );
		expect( phases( rows[ 2 ] ) ).toEqual( [
			'During update:Not available',
			'After update:Not available',
			'Net result:Not available',
		] );
		// WP-Cron unavailable: the phase is still described by Options.
		expect( phases( rows[ 3 ] ) ).toEqual( [
			'During update:Changes observed',
			'After update:Changes observed',
			'Net result:Changes observed',
		] );
		expect( document.body ).not.toHaveTextContent( '✓' );
	} );
} );
