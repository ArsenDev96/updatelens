import apiFetch from '@wordpress/api-fetch';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type {
	AnalysisReport,
	PhaseKey,
	Provider,
	RecurringCronEventRemovedFinding,
} from '@/admin/types/api';
import { formatBytes, formatCount } from '@/admin/utils/format';

import { WOOCOMMERCE } from './action-scheduler-fixtures';
import { cronPhase, oneTime, RANK_MATH, recurring } from './dogfood-fixtures';
import {
	AWAITING,
	EXPIRED,
	FAILED,
	MALFORMED_CRON,
	T,
	withImpact,
} from './fixtures';
import {
	AS_GONE,
	AS_NEW,
	cronChange,
	cronChangeFinding,
	cronFinal,
	CRON_REMOVED,
	HISTORICAL_NOT_INSTALLED,
	IMPACT,
	LONG_HOOK,
	LONG_IDS,
	LONG_OPTION,
	manyFindings,
	ONE_FINDING,
} from './impact-fixtures';
import { openReport, tab } from './report-view';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

/**
 * Opens a report at its URL.
 *
 * @param report  Report served by the API.
 * @param options View.
 */
async function renderReport(
	report: AnalysisReport,
	options: {
		signal?: Provider;
		phase?: PhaseKey;
		item?: string;
		group?: string;
		kind?: string;
		finding?: number;
	} = {}
) {
	apiFetchMock.mockResolvedValue( report );
	openReport( report.id, options );
	await screen.findByRole( 'heading', { level: 2 } );
}

/** The Potential Impact section. */
const impact = () => screen.getByRole( 'region', { name: 'Potential impact' } );

/** Finding groups (rule and signal). */
const groups = () =>
	within( within( impact() ).getByRole( 'list', { name: 'Findings' } ) )
		.getAllByRole( 'listitem' )
		.filter(
			( item ) =>
				item.parentElement?.getAttribute( 'aria-label' ) === 'Findings'
		);

/** The expand/collapse button of a group (in its title). */
const groupButton = ( group: HTMLElement ) =>
	group.querySelector< HTMLButtonElement >( 'h4 button' )!;

/**
 * Expands every collapsed group.
 *
 * @param user User events.
 */
async function expandAll( user: ReturnType< typeof userEvent.setup > ) {
	for ( const group of groups() ) {
		if ( groupButton( group ).getAttribute( 'aria-expanded' ) !== 'true' ) {
			await user.click( groupButton( group ) );
		}
	}
}

/**
 * Each signal's check state in "What was checked": status and detail, without
 * the longer description.
 */
const checkTexts = () =>
	within( impact().querySelector( 'details dl' )! )
		.getAllByRole( 'definition' )
		.map( ( item ) =>
			( item.textContent ?? '' ).replace(
				item.querySelector( '.block' )?.textContent ?? '',
				''
			)
		);

/** Checks shown outside "What was checked" because they did not run. */
const incompleteTexts = () =>
	within( impact() )
		.queryByRole( 'list', { name: 'Checks that did not run' } )
		?.querySelectorAll( 'li' ) ?? [];

/** Wording that must never appear: no verdicts, no attribution. */
const FORBIDDEN =
	/\bsafe\b|no issues|no conflicts?|risk-free|healthy|caused by|definitely|(?<!not )confirmed (problem|failure)|stopped running|runs? more often|runs? less often/i;

describe( 'Potential Impact', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'sits between the update summary and the signal cards', async () => {
		await renderReport( IMPACT );

		const summary = screen.getByRole( 'region', {
			name: 'Update summary',
		} );
		const cards = screen.getByRole( 'list', { name: 'Signals' } );
		expect(
			summary.compareDocumentPosition( impact() ) &
				Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		expect(
			impact().compareDocumentPosition( cards ) &
				Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
	} );

	it( 'starts with compact group rows: title, count, signal, names and one sentence', async () => {
		await renderReport( IMPACT );

		expect(
			within( impact() ).getByText( '5 findings to review' )
		).toBeInTheDocument();
		expect(
			within( impact() ).getByText( 'Based on Net result' )
		).toBeInTheDocument();

		// The count is part of each button's name.
		expect(
			groups().map( ( group ) =>
				groupButton( group ).getAttribute( 'aria-expanded' )
			)
		).toEqual( [ 'false', 'false', 'false', 'false' ] );
		expect(
			within( impact() )
				.getAllByRole( 'button', { expanded: false } )
				.map( ( button ) => button.textContent )
		).toEqual( [
			'Large autoloaded options2, 2 findings',
			'Recurring WP-Cron event no longer observed1, 1 finding',
			'Recurring WP-Cron schedule changed1, 1 finding',
			'Recurring Action Scheduler schedule changed1, 1 finding',
		] );
		expect(
			screen.getByRole( 'button', {
				name: 'Large autoloaded options, 2 findings',
			} )
		).toHaveAccessibleDescription(
			`2 options, ${ formatBytes(
				182340 + 163000
			) } in total, are autoloaded and each above the ${ formatBytes(
				150000
			) } review size. Check whether they need to load on every page request.`
		);

		const [ options, removed, cron, actionScheduler ] = groups();
		expect( options ).toHaveTextContent(
			'Options & autoload · acme_feed_cache, acme_feed_index'
		);
		expect( removed ).toHaveTextContent(
			'1 recurring instance scheduled before the update was not observed after it. Check whether the hook still has scheduled events.'
		);
		expect( cron ).toHaveTextContent(
			'The recurring interval changed. Check that the new schedule is intended.'
		);
		expect( actionScheduler ).toHaveTextContent(
			'Action Scheduler · wpforms_email_summaries_fetch_info_blocks'
		);

		// No details are rendered while collapsed.
		expect(
			within( impact() ).queryByRole( 'heading', { level: 5 } )
		).not.toBeInTheDocument();
		expect( within( impact() ).queryAllByRole( 'link' ) ).toHaveLength( 0 );
		expect( impact().textContent ).not.toMatch( FORBIDDEN );
	} );

	it( 'expands and collapses a group with the mouse and the keyboard', async () => {
		const user = userEvent.setup();
		await renderReport( IMPACT );
		const [ options ] = groups();
		const button = groupButton( options );

		await user.click( button );
		expect( button ).toHaveAttribute( 'aria-expanded', 'true' );
		const panel = document.getElementById(
			button.getAttribute( 'aria-controls' )!
		)!;
		expect( panel ).toBeVisible();
		expect( panel ).toHaveTextContent( 'What changed' );
		expect( options ).toHaveTextContent( 'Hide details' );

		button.focus();
		await user.keyboard( '{Enter}' );
		expect( button ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( panel ).not.toBeVisible();
		expect( panel ).toBeEmptyDOMElement();

		await user.keyboard( ' ' );
		expect( button ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( button ).toHaveFocus();
	} );

	it( 'explains every finding type once expanded: what changed, why, what to check, and a link', async () => {
		const user = userEvent.setup();
		await renderReport( IMPACT );
		await expandAll( user );

		for ( const group of groups() ) {
			for ( const heading of [
				'What changed',
				'Why it might matter',
				'What to check next',
			] ) {
				expect(
					within( group ).getByRole( 'heading', {
						level: 5,
						name: heading,
					} )
				).toBeInTheDocument();
			}
		}

		const [ options, removed, cron, actionScheduler ] = groups();
		// Large options: before/after values and the review size.
		expect( options ).toHaveTextContent( 'acme_feed_cache' );
		expect( options ).toHaveTextContent(
			'Became autoloaded; its value did not change.'
		);
		expect( options ).toHaveTextContent( /Off →s?to On/ );
		expect( options ).toHaveTextContent(
			`${ formatBytes( 182340 ) } (unchanged)`
		);
		expect( options ).toHaveTextContent(
			// Grouping may use a narrow no-break space; text content is normalized.
			`More than ${ formatCount( 150000 ) } bytes (${ formatBytes(
				150000
			) })`.replace( /\s/g, ' ' )
		);
		expect( options ).toHaveTextContent( 'Added as an autoloaded option.' );
		expect( options ).toHaveTextContent( 'On (auto)' );

		// Removed recurring event: never "stopped running" or "gone".
		expect( removed ).toHaveTextContent( 'acme_sync_feeds' );
		expect( removed ).toHaveTextContent(
			'1 recurring instance scheduled before the update was not observed after it.'
		);
		expect( removed ).toHaveTextContent( 'hourly · every 1 hour' );
		expect( removed ).toHaveTextContent(
			'this does not show whether the hook still has other scheduled events'
		);
		expect( removed ).toHaveTextContent( 'wp cron event list' );

		// WP-Cron interval change with its schedule names.
		expect( cron ).toHaveTextContent(
			/daily · every 1 day →s?to weekly · every (7 days|1 week)/
		);

		// Action Scheduler: group as metadata, cron expression as stored,
		// no frequency claim.
		expect( actionScheduler ).toHaveTextContent( 'Group: wpforms' );
		expect( actionScheduler ).toHaveTextContent( '0 */6 * * *' );
		expect( actionScheduler ).toHaveTextContent(
			'does not calculate how often a cron expression runs'
		);
		expect( impact().textContent ).not.toMatch( FORBIDDEN );

		// Every finding links to its signal page with the row located.
		const links = within( impact() ).getAllByRole( 'link' );
		expect( links ).toHaveLength( 5 );
		expect( links.map( ( link ) => link.getAttribute( 'href' ) ) ).toEqual(
			[
				'/wp-admin/admin.php?page=updatelens&analysis=60&signal=options&phase=final&item=acme_feed_cache&kind=changed&finding=0',
				'/wp-admin/admin.php?page=updatelens&analysis=60&signal=options&phase=final&item=acme_feed_index&kind=added&finding=1',
				'/wp-admin/admin.php?page=updatelens&analysis=60&signal=cron&phase=final&item=acme_sync_feeds&kind=removed&finding=2',
				'/wp-admin/admin.php?page=updatelens&analysis=60&signal=cron&phase=final&item=acme_cleanup&kind=changed&finding=3',
				'/wp-admin/admin.php?page=updatelens&analysis=60&signal=action_scheduler&phase=final&item=wpforms_email_summaries_fetch_info_blocks&group=wpforms&kind=changed&finding=4',
			]
		);
		expect(
			within( impact() ).getByRole( 'link', {
				name: 'View in WP-Cron: acme_sync_feeds',
			} )
		).toBeInTheDocument();
	} );

	it( 'says what was checked in a collapsed disclosure', async () => {
		const user = userEvent.setup();
		await renderReport( IMPACT );

		const details = impact().querySelector( 'details' )!;
		expect( details.open ).toBe( false );
		await user.click( within( impact() ).getByText( 'What was checked' ) );
		expect( details.open ).toBe( true );
		expect( checkTexts() ).toEqual( [
			'Checked · 2 findings',
			'Checked · 2 findings',
			'Checked · 1 finding',
		] );
		expect( details ).toHaveTextContent(
			'WP-Cron: recurring events no longer observed, and recurring schedules that changed.'
		);
		expect( details ).toHaveTextContent( 'Checks use the Net result only' );
		// Every check ran: nothing is shown as incomplete.
		expect( incompleteTexts() ).toHaveLength( 0 );
	} );

	it( 'is the same whichever phase is selected, and offers the Net result', async () => {
		const user = userEvent.setup();
		await renderReport( IMPACT, { phase: 'during_update' } );

		expect(
			within( impact() ).getByText( '5 findings to review' )
		).toBeInTheDocument();
		// Said before the findings, in the section itself.
		expect( impact() ).toHaveTextContent(
			'You are viewing During update. These findings do not come from that phase: Potential Impact always uses the Net result (before the update compared with after it).'
		);
		await user.click(
			within( impact() ).getByRole( 'button', {
				name: 'Show the Net result phase',
			} )
		);
		expect( tab( /^Net result/ ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect(
			within( impact() ).queryByRole( 'button', {
				name: 'Show the Net result phase',
			} )
		).not.toBeInTheDocument();
		expect(
			within( impact() ).getByText( '5 findings to review' )
		).toBeInTheDocument();
		expect( impact() ).not.toHaveTextContent( 'You are viewing' );
	} );

	it( 'opens the signal page with the finding located, and returns to it', async () => {
		const user = userEvent.setup();
		await renderReport( IMPACT );
		await user.click( groupButton( groups()[ 1 ] ) );

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
		expect( tab( /^Net result/ ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		const located = screen.getByRole( 'region', {
			name: 'Located from Potential Impact',
		} );
		expect( located ).toHaveTextContent(
			'Showing the Net result with the entry of this finding highlighted: acme_sync_feeds'
		);
		const rows = document.querySelectorAll( '[data-updatelens-located]' );
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toHaveAttribute(
			'data-updatelens-located',
			'exact'
		);
		expect( rows[ 0 ] ).toHaveTextContent( 'From Potential Impact' );
		expect( rows[ 0 ] ).toHaveTextContent( 'acme_sync_feeds' );

		await user.click(
			within( located ).getByRole( 'button', { name: 'Clear highlight' } )
		);
		expect(
			document.querySelectorAll( '[data-updatelens-located]' )
		).toHaveLength( 0 );
		expect( window.location.search ).toBe(
			'?page=updatelens&analysis=60&signal=cron&phase=final'
		);

		// Back on the overview: the group is still expanded and focus is on
		// the finding's link.
		await user.click(
			within(
				screen.getByRole( 'navigation', { name: 'Breadcrumb' } )
			).getByRole( 'link', { name: 'Acme Feeds' } )
		);
		expect( groupButton( groups()[ 1 ] ) ).toHaveAttribute(
			'aria-expanded',
			'true'
		);
		expect(
			within( impact() ).getByRole( 'link', {
				name: 'View in WP-Cron: acme_sync_feeds',
			} )
		).toHaveFocus();
	} );

	it( 'locates an Action Scheduler finding by its evidence', async () => {
		const hook = 'wpforms_email_summaries_fetch_info_blocks';
		await renderReport( IMPACT, {
			signal: 'action_scheduler',
			phase: 'final',
			item: hook,
			group: 'wpforms',
			kind: 'changed',
			finding: 4,
		} );
		const rows = document.querySelectorAll( '[data-updatelens-located]' );
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toHaveAttribute(
			'data-updatelens-located',
			'exact'
		);
	} );

	it( 'marks rows of the hook neutrally for a link without its finding', async () => {
		// E.g. a link copied before findings were indexed: the hook is located,
		// the exact entry is not claimed.
		await renderReport( IMPACT, {
			signal: 'action_scheduler',
			phase: 'final',
			item: 'wpforms_email_summaries_fetch_info_blocks',
			group: 'wpforms',
		} );
		const rows = document.querySelectorAll( '[data-updatelens-located]' );
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toHaveAttribute(
			'data-updatelens-located',
			'group'
		);
		expect( rows[ 0 ] ).toHaveTextContent( 'Same hook as the finding' );
		expect( rows[ 0 ] ).not.toHaveTextContent( 'From Potential Impact' );
		expect(
			screen.getByRole( 'region', {
				name: 'Located from Potential Impact',
			} )
		).toHaveTextContent(
			'The exact entry of this finding could not be identified'
		);
	} );

	it( 'locates only the change list the finding is about', async () => {
		// The same hook was removed (recurring) and added (one-time).
		const report = withImpact( {
			...ONE_FINDING,
			phases: {
				...ONE_FINDING.phases,
				final: {
					...ONE_FINDING.phases.final,
					cron: cronPhase( 'net_across_phases', {
						added: [ oneTime( 'acme_sync_feeds', T + 60 ) ],
						removed: [
							recurring( 'acme_sync_feeds', T, 'hourly', 3600 ),
						],
					} ),
				},
			},
		} );
		await renderReport( report, {
			signal: 'cron',
			phase: 'final',
			item: 'acme_sync_feeds',
			kind: 'removed',
		} );

		const located = document.querySelectorAll(
			'[data-updatelens-located]'
		);
		expect( located ).toHaveLength( 1 );
		expect( located[ 0 ] ).toHaveTextContent( 'Recurring · hourly' );
	} );

	it( 'does not locate a row of another group', async () => {
		await renderReport( IMPACT, {
			signal: 'action_scheduler',
			phase: 'final',
			item: 'wpforms_email_summaries_fetch_info_blocks',
			group: 'other',
		} );
		expect(
			document.querySelectorAll( '[data-updatelens-located]' )
		).toHaveLength( 0 );
	} );

	it( 'expands a long list to show a located row', async () => {
		await renderReport( manyFindings( 30, 0 ), {
			signal: 'options',
			phase: 'final',
			item: 'stress_option_0025',
		} );

		expect( screen.getAllByText( 'From Potential Impact' ) ).toHaveLength(
			1
		);
		expect(
			screen.getByRole( 'button', { name: /Show fewer added options/ } )
		).toHaveAttribute( 'aria-expanded', 'true' );
	} );

	it( 'drops the located row when another phase is chosen', async () => {
		const user = userEvent.setup();
		await renderReport( IMPACT, {
			signal: 'cron',
			phase: 'final',
			item: 'acme_sync_feeds',
			kind: 'removed',
			finding: 2,
		} );
		expect(
			screen.getByText( 'From Potential Impact' )
		).toBeInTheDocument();

		await user.click( tab( /^During update/ ) );
		expect(
			screen.queryByText( 'From Potential Impact' )
		).not.toBeInTheDocument();
		expect( window.location.search ).not.toContain( 'item=' );
	} );

	describe( 'events sharing a hook', () => {
		/** Marked rows and how each is marked. */
		const marked = () =>
			Array.from(
				document.querySelectorAll( '[data-updatelens-located]' )
			).map( ( row ) => ( {
				precision: row.getAttribute( 'data-updatelens-located' ),
				text: row.textContent ?? '',
			} ) );
		const notice = () =>
			screen.getByRole( 'region', {
				name: 'Located from Potential Impact',
			} );

		it( 'highlights only the changed entry the finding describes', async () => {
			// Same hook, other arguments (hidden): told apart by their schedules.
			const daily = cronChange(
				'acme_cleanup',
				T + 600,
				[ 'daily', 86400 ],
				[ 'weekly', 604800 ]
			);
			const hourly = cronChange(
				'acme_cleanup',
				T + 900,
				[ 'hourly', 3600 ],
				[ 'daily', 86400 ]
			);
			const report = cronFinal( { changed: [ daily, hourly ] }, [
				cronChangeFinding( daily ),
				cronChangeFinding( hourly ),
			] );
			await renderReport( report, {
				signal: 'cron',
				phase: 'final',
				item: 'acme_cleanup',
				kind: 'changed',
				finding: 1,
			} );

			const rows = marked();
			expect( rows ).toHaveLength( 1 );
			expect( rows[ 0 ].precision ).toBe( 'exact' );
			expect( rows[ 0 ].text ).toMatch( /hourly/ );
			expect( rows[ 0 ].text ).toContain( 'From Potential Impact' );
			expect( notice() ).toHaveTextContent(
				'Showing the Net result with the entry of this finding highlighted: acme_cleanup'
			);
		} );

		it( 'marks alike entries neutrally when the finding cannot tell them apart', async () => {
			// Two events of one hook that differ only in their arguments.
			const first = cronChange(
				'acme_cleanup',
				T + 600,
				[ 'daily', 86400 ],
				[ 'weekly', 604800 ]
			);
			const second = { ...first };
			const report = cronFinal( { changed: [ first, second ] }, [
				cronChangeFinding( first ),
				cronChangeFinding( second ),
			] );
			await renderReport( report, {
				signal: 'cron',
				phase: 'final',
				item: 'acme_cleanup',
				kind: 'changed',
				finding: 0,
			} );

			const rows = marked();
			expect( rows ).toHaveLength( 2 );
			for ( const row of rows ) {
				expect( row.precision ).toBe( 'group' );
				expect( row.text ).toContain( 'Same hook as the finding' );
				expect( row.text ).not.toContain( 'From Potential Impact' );
			}
			expect( notice() ).toHaveTextContent(
				'2 entries of this hook match this finding. Arguments are not shown, so UpdateLens cannot tell which one the finding refers to'
			);
			expect( notice().textContent ).not.toMatch( /highlighted/ );
		} );

		it( 'highlights the removed recurring entries the finding counts, not other rows of the hook', async () => {
			const removed = [
				recurring( 'acme_sync_feeds', T, 'hourly', 3600 ),
				recurring( 'acme_sync_feeds', T + 60, 'hourly', 3600 ),
				oneTime( 'acme_sync_feeds', T + 120 ),
			];
			const added = [
				recurring( 'acme_sync_feeds', T + 200, 'hourly', 3600 ),
			];
			const finding: RecurringCronEventRemovedFinding = {
				...CRON_REMOVED,
				evidence: {
					...CRON_REMOVED.evidence,
					removed_recurring_count: 2,
					added_recurring_count: 1,
					not_replaced_count: 1,
				},
				before: {
					removed_recurring: [
						{ timestamp: T, schedule: 'hourly', interval: 3600 },
						{
							timestamp: T + 60,
							schedule: 'hourly',
							interval: 3600,
						},
					],
				},
				after: {
					added_recurring: [
						{
							timestamp: T + 200,
							schedule: 'hourly',
							interval: 3600,
						},
					],
				},
			};
			await renderReport( cronFinal( { added, removed }, [ finding ] ), {
				signal: 'cron',
				phase: 'final',
				item: 'acme_sync_feeds',
				kind: 'removed',
				finding: 0,
			} );

			const rows = marked();
			expect( rows ).toHaveLength( 2 );
			expect( rows.every( ( row ) => row.precision === 'exact' ) ).toBe(
				true
			);
			expect(
				rows.every( ( row ) =>
					row.text.includes( 'Recurring · hourly' )
				)
			).toBe( true );
			expect( notice() ).toHaveTextContent(
				'Showing the Net result with the 2 entries of this finding highlighted: acme_sync_feeds'
			);
		} );

		it( 'keeps a long list navigable: expanded, marked, keyboard clearable', async () => {
			const user = userEvent.setup();
			const others = Array.from( { length: 12 }, ( _, i ) =>
				cronChange(
					`a_hook_${ String( i ).padStart( 2, '0' ) }`,
					T + i,
					[ 'daily', 86400 ],
					[ 'hourly', 3600 ]
				)
			);
			const alike = cronChange(
				'zz_hook',
				T + 600,
				[ 'daily', 86400 ],
				[ 'weekly', 604800 ]
			);
			const report = cronFinal(
				{ changed: [ ...others, alike, { ...alike } ] },
				[ ...others, alike, alike ].map( cronChangeFinding )
			);
			await renderReport( report, {
				signal: 'cron',
				phase: 'final',
				item: 'zz_hook',
				kind: 'changed',
				finding: 12,
			} );

			expect( marked() ).toHaveLength( 2 );
			expect(
				screen.getByRole( 'button', {
					name: 'Show fewer changed events',
				} )
			).toHaveAttribute( 'aria-expanded', 'true' );

			within( notice() )
				.getByRole( 'button', { name: 'Clear highlight' } )
				.focus();
			await user.keyboard( '{Enter}' );
			expect( marked() ).toHaveLength( 0 );
			expect( window.location.search ).not.toContain( 'finding=' );
		} );

		it( 'does not trust a finding index that names another item', async () => {
			const hostile = '<img src=x onerror=alert(1)>';
			const report = cronFinal(
				{
					removed: [
						recurring( hostile, T, 'hourly', 3600 ),
						recurring( 'acme_sync_feeds', T, 'hourly', 3600 ),
					],
				},
				[ CRON_REMOVED ]
			);
			// Finding 0 is about acme_sync_feeds, not this hook.
			await renderReport( report, {
				signal: 'cron',
				phase: 'final',
				item: hostile,
				kind: 'removed',
				finding: 0,
			} );

			const rows = marked();
			expect( rows ).toHaveLength( 1 );
			expect( rows[ 0 ].precision ).toBe( 'group' );
			expect( notice() ).toHaveTextContent( hostile );
			expect( document.querySelector( 'img' ) ).toBeNull();
		} );
	} );

	describe( 'states', () => {
		it( 'one finding, Action Scheduler not applicable', async () => {
			await renderReport( ONE_FINDING );

			expect(
				within( impact() ).getByText( '1 finding to review' )
			).toBeInTheDocument();
			expect( groups() ).toHaveLength( 1 );
			expect( groupButton( groups()[ 0 ] ) ).toHaveAttribute(
				'aria-expanded',
				'false'
			);
			expect( checkTexts() ).toEqual( [
				'Checked · no findings',
				'Checked · 1 finding',
				'Not applicable · Action Scheduler was not detected during this update.',
			] );
			// Not applicable is not an incomplete check.
			expect( incompleteTexts() ).toHaveLength( 0 );
			expect( impact().textContent ).not.toMatch( /Not checked/ );
		} );

		it( 'evaluated without findings: compact, no verdict', async () => {
			await renderReport( RANK_MATH );

			expect(
				within( impact() ).getByText(
					'No Potential Impact patterns matched the available observations.'
				)
			).toBeInTheDocument();
			expect( impact() ).toHaveTextContent(
				'Only a few specific patterns are checked, so this does not show that the update had no other effects.'
			);
			expect(
				within( impact() ).queryByRole( 'list', { name: 'Findings' } )
			).not.toBeInTheDocument();
			expect( incompleteTexts() ).toHaveLength( 0 );
			// Statuses and limitations wait in "What was checked".
			expect( impact().querySelector( 'details' )!.open ).toBe( false );
			expect( impact().querySelector( 'details' ) ).toHaveTextContent(
				'No match does not show that the update had no other effects.'
			);
			expect( impact().textContent ).not.toMatch( FORBIDDEN );
		} );

		it( 'partial: a failed provider stays visible with its reason', async () => {
			await renderReport( MALFORMED_CRON );

			expect(
				within( impact() ).getByText(
					'No Potential Impact patterns matched the available observations.'
				)
			).toBeInTheDocument();
			const [ cron ] = incompleteTexts();
			expect( incompleteTexts() ).toHaveLength( 1 );
			expect( cron ).toHaveAttribute( 'data-category', 'failed' );
			expect( cron ).toHaveTextContent(
				'WP-Cron: Not checked (failed) · WP-Cron analysis unavailable'
			);
			expect( checkTexts()[ 1 ] ).toBe(
				'Not checked (failed) · WP-Cron analysis unavailable'
			);
		} );

		it( 'partial: Action Scheduler no longer detected is unavailable, not "not applicable"', async () => {
			await renderReport( AS_GONE );

			const [ actionScheduler ] = incompleteTexts();
			expect( actionScheduler ).toHaveAttribute(
				'data-category',
				'unavailable'
			);
			expect( actionScheduler ).toHaveTextContent(
				'Action Scheduler: Not checked (unavailable) · Action Scheduler no longer detected'
			);
			expect( impact() ).not.toHaveTextContent( 'Not applicable' );
		} );

		it( 'partial: Action Scheduler newly detected is unavailable, not "not applicable"', async () => {
			await renderReport( AS_NEW );

			expect( checkTexts()[ 2 ] ).toBe(
				'Not checked (unavailable) · Action Scheduler newly detected'
			);
			expect( incompleteTexts() ).toHaveLength( 1 );
			expect( impact() ).not.toHaveTextContent( 'Not applicable' );
		} );

		it( 'historical report: absence at every capture is not claimed', async () => {
			await renderReport( HISTORICAL_NOT_INSTALLED );

			expect( incompleteTexts()[ 0 ] ).toHaveTextContent(
				'Action Scheduler: Not checked (unavailable) · This report cannot confirm that Action Scheduler was absent throughout the update.'
			);
			expect( impact() ).not.toHaveTextContent( 'Not applicable' );
			expect(
				within( impact() ).getByText( '1 finding to review' )
			).toBeInTheDocument();
		} );

		it( 'with every signal: evaluated, zero findings', async () => {
			await renderReport( WOOCOMMERCE );

			expect(
				within( impact() ).getByText(
					'No Potential Impact patterns matched the available observations.'
				)
			).toBeInTheDocument();
			expect( checkTexts() ).toEqual( [
				'Checked · no findings',
				'Checked · no findings',
				'Checked · no findings',
			] );
		} );

		it( 'awaiting the settled observation', async () => {
			await renderReport( AWAITING );

			expect(
				within( impact() ).getByText( 'Waiting for the Net result' )
			).toBeInTheDocument();
			expect( impact() ).toHaveTextContent( 'Refresh the report later' );
			// The Net result has nothing to show yet: no shortcut to it.
			expect(
				within( impact() ).queryByRole( 'button', {
					name: 'Show the Net result phase',
				} )
			).not.toBeInTheDocument();
			expect( checkTexts()[ 0 ] ).toBe(
				'Not checked yet (waiting for the settled capture)'
			);
			expect(
				impact().querySelector( 'dd[data-category="waiting"]' )
			).not.toBeNull();
		} );

		it( 'expired observation', async () => {
			await renderReport( EXPIRED );

			expect(
				within( impact() ).getByText( 'Not available for this report' )
			).toBeInTheDocument();
			expect( impact() ).toHaveTextContent(
				'The post-update observation was not captured within 5 minutes, so there is no Net result to check.'
			);
			expect( checkTexts()[ 0 ] ).toBe(
				'Not checked (observation expired)'
			);
			// It opens on During update, but there are no findings to set apart.
			expect( tab( /^During update/ ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			expect( impact() ).not.toHaveTextContent( 'You are viewing' );
		} );

		it( 'is left out when no phase is available (failed update)', async () => {
			await renderReport( FAILED );

			expect(
				screen.queryByRole( 'region', { name: 'Potential impact' } )
			).not.toBeInTheDocument();
		} );
	} );

	describe( 'large reports', () => {
		it( 'keeps counts accurate while collapsed and reveals findings in steps', async () => {
			const user = userEvent.setup();
			await renderReport( manyFindings( 1200, 300 ) );

			expect(
				within( impact() ).getByText(
					`${ formatCount( 1500 ) } findings to review`
				)
			).toBeInTheDocument();
			const [ options, cron ] = groups();
			expect(
				screen.getByRole( 'button', {
					name: `Large autoloaded options, ${ formatCount(
						1200
					) } findings`,
				} )
			).toBeInTheDocument();
			expect( options ).toHaveTextContent(
				`stress_option_0000, stress_option_0001 and ${ formatCount(
					1198
				) } more`
			);
			const rows = ( group: HTMLElement ) =>
				within( group ).queryAllByRole( 'link', { name: /^View in/ } );
			expect( rows( options ) ).toHaveLength( 0 );
			expect( rows( cron ) ).toHaveLength( 0 );
			// One instance per hook: no separate total.
			expect( cron ).toHaveTextContent(
				'300 hooks have recurring instances that were scheduled before the update and not observed after it. Check whether these hooks still have scheduled events.'
			);

			await user.click( groupButton( options ) );
			expect( rows( options ) ).toHaveLength( 3 );
			expect( rows( cron ) ).toHaveLength( 0 );
			expect( options ).toHaveTextContent(
				`Showing 3 of ${ formatCount( 1200 ) }`
			);
			// One explanation per group, not per finding.
			expect(
				within( options ).getAllByRole( 'heading', {
					name: 'Why it might matter',
				} )
			).toHaveLength( 1 );

			const more = within( options ).getByRole( 'button', {
				name: `Show 50 more (${ formatCount( 1197 ) } hidden)`,
			} );
			await user.click( more );
			expect( rows( options ) ).toHaveLength( 53 );
			expect( more ).toHaveFocus();
			expect( more ).toHaveTextContent(
				`Show 50 more (${ formatCount( 1147 ) } hidden)`
			);
			// Deterministic order: the API's.
			expect(
				rows( options )
					.slice( 0, 4 )
					.map( ( link ) => link.getAttribute( 'aria-label' ) )
			).toEqual( [
				'View in Options & autoload: stress_option_0000',
				'View in Options & autoload: stress_option_0001',
				'View in Options & autoload: stress_option_0002',
				'View in Options & autoload: stress_option_0003',
			] );

			await user.click(
				within( options ).getByRole( 'button', { name: 'Show fewer' } )
			);
			expect( rows( options ) ).toHaveLength( 3 );
		} );

		it( 'keeps keyboard focus when a reveal button disappears', async () => {
			const user = userEvent.setup();
			await renderReport( manyFindings( 55, 0 ) );
			const [ options ] = groups();
			await user.click( groupButton( options ) );

			await user.click(
				within( options ).getByRole( 'button', {
					name: 'Show 50 more (52 hidden)',
				} )
			);
			// The last step removes "Show more": focus moves to "Show fewer".
			await user.click(
				within( options ).getByRole( 'button', {
					name: 'Show 2 more (2 hidden)',
				} )
			);
			const fewer = within( options ).getByRole( 'button', {
				name: 'Show fewer',
			} );
			expect( fewer ).toHaveFocus();
			expect( options ).toHaveTextContent( 'Showing 55 of 55' );

			// Back to the first rows: "Show fewer" disappears, focus moves on.
			await user.keyboard( '{Enter}' );
			expect(
				within( options ).getByRole( 'button', {
					name: 'Show 50 more (52 hidden)',
				} )
			).toHaveFocus();
		} );

		it( 'renders a 1,500-finding report quickly, without rows while collapsed', async () => {
			const report = manyFindings( 1200, 300 );
			const start = performance.now();
			await renderReport( report );
			const elapsed = performance.now() - start;

			expect(
				within( impact() ).queryAllByRole( 'link', {
					name: /^View in/,
				} )
			).toHaveLength( 0 );
			expect( elapsed ).toBeLessThan( 2000 );
		} );
	} );

	it( 'renders long and hostile identifiers as text', async () => {
		const user = userEvent.setup();
		const hostile = '<img src=x onerror=alert(1)>_option';
		await renderReport(
			withImpact(
				{
					...LONG_IDS,
					phases: {
						...LONG_IDS.phases,
						final: {
							...LONG_IDS.phases.final,
						},
					},
				},
				[
					...LONG_IDS.potential_impact.findings,
					{
						...LONG_IDS.potential_impact.findings[ 0 ],
						option: hostile,
					} as AnalysisReport[ 'potential_impact' ][ 'findings' ][ number ],
				]
			)
		);

		// Collapsed rows truncate visually but keep the full name as text.
		expect( impact() ).toHaveTextContent( LONG_OPTION );
		expect( impact() ).toHaveTextContent( LONG_HOOK );
		expect( impact() ).toHaveTextContent( hostile );
		await expandAll( user );
		expect( impact() ).toHaveTextContent( hostile );
		expect( impact().querySelector( 'img' ) ).toBeNull();
	} );

	it( 'never shows API internals or raw codes as main text', async () => {
		const user = userEvent.setup();
		await renderReport( IMPACT );
		await expandAll( user );
		await user.click( within( impact() ).getByText( 'What was checked' ) );

		const text = impact().textContent ?? '';
		for ( const code of [
			'large_autoloaded_option',
			'recurring_cron_event_removed',
			'recurring_schedule_changed',
			'schedule_type_changed',
			'not_evaluated',
			'not_applicable',
			'grew_past_threshold',
			'fingerprint',
		] ) {
			expect( text ).not.toContain( code );
		}
		expect( text ).not.toMatch( FORBIDDEN );
	} );
} );
