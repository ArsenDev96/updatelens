import apiFetch from '@wordpress/api-fetch';
import { cleanup, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type {
	AnalysisReport,
	ImpactFinding,
	LargeAutoloadedOptionFinding,
	PhaseKey,
	Provider,
	RecurringCronEventRemovedFinding,
} from '@/admin/types/api';
import {
	formatBytes,
	formatBytesDelta,
	formatCount,
	keepTogether,
} from '@/admin/utils/format';
import {
	autoloadedBytes,
	groupFacts,
	groupFindings,
	groupSummary,
	thresholdText,
	whatToCheck,
	whyItMatters,
} from '@/admin/utils/impact';

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
	AS_TYPE_CHANGED,
	cronChange,
	cronChangeFinding,
	cronFinal,
	CRON_INTERVAL,
	CRON_REMOVED,
	HISTORICAL_NOT_INSTALLED,
	IMPACT,
	LARGE_ADDED,
	LARGE_BECAME,
	LONG_HOOK,
	LONG_IDS,
	LONG_OPTION,
	manyFindings,
	ONE_FINDING,
} from './impact-fixtures';
import { openReport, phasePanel, tab } from './report-view';

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
const impact = () => screen.getByRole( 'region', { name: 'Findings' } );

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

/** The report summary. */
const summary = () => screen.getByRole( 'region', { name: 'Report summary' } );

/**
 * One row of the summary (its answer and action), or null.
 *
 * @param label Row label, e.g. "Findings".
 */
const summaryRow = ( label: string ) =>
	within( summary() )
		.queryAllByRole( 'term' )
		.find( ( term ) => term.textContent === label )?.parentElement ?? null;

/** Sources that could not be assessed, always visible in the summary. */
const notAssessed = () => summaryRow( 'Not assessed' );

/** Wording that must never appear: no verdicts, no attribution. */
const FORBIDDEN =
	/\bsafe\b|no issues|no conflicts?|risk-free|healthy|caused by|definitely|(?<!not )confirmed (problem|failure)|stopped running|runs? more often|runs? less often/i;

describe( 'Potential Impact', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'comes right after the summary, before site checks and configuration changes', async () => {
		await renderReport( IMPACT );

		const configuration = screen.getByRole( 'region', {
			name: 'Configuration changes',
		} );
		const following = ( a: Element, b: Element ) =>
			Boolean(
				a.compareDocumentPosition( b ) &
				Node.DOCUMENT_POSITION_FOLLOWING
			);
		expect( following( summary(), impact() ) ).toBe( true );
		expect(
			following(
				impact(),
				screen.getByRole( 'region', { name: 'Site checks' } )
			)
		).toBe( true );
		expect( following( impact(), configuration ) ).toBe( true );
		// The summary counts them by priority and leads to them.
		expect( summaryRow( 'Findings' ) ).toHaveTextContent(
			/^Findings5 need review/
		);
	} );

	it( 'starts with compact group rows: title, count, signal, names and one sentence', async () => {
		await renderReport( IMPACT );

		expect(
			within( impact() ).getByText( 'Based on Net result' )
		).toBeInTheDocument();
		// Every row carries the priority the API gave it.
		expect(
			groups().map( ( group ) => group.getAttribute( 'data-priority' ) )
		).toEqual( [ 'review', 'review', 'review', 'review' ] );

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
			'Large autoloaded options2, 2 findings, Needs review',
			'Recurring WP-Cron event no longer observed, 1 finding, Needs review',
			'Recurring WP-Cron schedule changed, 1 finding, Needs review',
			'Recurring Action Scheduler schedule changed, 1 finding, Needs review',
		] );
		expect(
			screen.getByRole( 'button', {
				name: 'Large autoloaded options, 2 findings, Needs review',
			} )
		).toHaveAccessibleDescription(
			// Stored data now autoloaded across the options: one became
			// autoloaded without growing, one was added; neither was
			// autoloaded before.
			`Now autoloaded across these options: ${ keepTogether(
				formatBytes( 182340 + 163000 )
			) }, all newly autoloaded. Check whether they need to load on every page.`
		);

		const [ options, removed, cron, actionScheduler ] = groups();
		expect( options ).toHaveTextContent(
			'Options & autoload · acme_feed_cache, acme_feed_index'
		);
		expect( removed ).toHaveTextContent(
			'1 recurring instance is no longer observed. Check whether the hook is still scheduled.'
		);
		expect( cron ).toHaveTextContent(
			'The interval changed. Check that the new schedule is intended.'
		);
		// The review size and longer explanations wait for the details.
		for ( const group of groups() ) {
			expect( group ).not.toHaveTextContent( 'review size' );
		}
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
			// Recorded before site checks: one quiet row, not a warning.
			'Not recorded · This update was observed before UpdateLens recorded site checks.',
		] );
		expect( details ).toHaveTextContent(
			'WP-Cron: recurring events no longer observed, and recurring schedules that changed.'
		);
		expect( details ).toHaveTextContent( 'Checks use the Net result only' );
		// Every check ran: nothing is shown as not assessed.
		expect( notAssessed() ).toBeNull();
	} );

	it( 'is the same whichever phase is selected', async () => {
		const user = userEvent.setup();
		await renderReport( IMPACT, { phase: 'during_update' } );

		// Outside the phase tabs: labelled with its source, never a phase.
		expect( within( impact() ).queryByRole( 'tabpanel' ) ).toBeNull();
		expect(
			within( impact() ).getByText( 'Based on Net result' )
		).toBeInTheDocument();
		const before = groups().map( ( group ) => group.textContent );
		await user.click( tab( /^Net result/ ) );
		expect( tab( /^Net result/ ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect( groups().map( ( group ) => group.textContent ) ).toEqual(
			before
		);
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
		expect( rows[ 0 ] ).toHaveTextContent( 'From Findings' );
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
		expect( rows[ 0 ] ).not.toHaveTextContent( 'From Findings' );
		expect(
			screen.getByRole( 'region', {
				name: 'Located from Potential Impact',
			} )
		).toHaveTextContent(
			'This link no longer matches a finding of the report'
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

		expect( screen.getAllByText( 'From Findings' ) ).toHaveLength( 1 );
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
		expect( screen.getByText( 'From Findings' ) ).toBeInTheDocument();

		await user.click( tab( /^During update/ ) );
		expect( screen.queryByText( 'From Findings' ) ).not.toBeInTheDocument();
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
			expect( rows[ 0 ].text ).toContain( 'From Findings' );
			expect( notice() ).toHaveTextContent(
				'Showing the Net result with the entry of this finding highlighted: acme_cleanup'
			);
		} );

		it( 'highlights the entry the API names, even among entries that look alike', async () => {
			// Two events of one hook that differ only in their arguments: the
			// API names each finding's own record, so nothing is guessed.
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
			expect(
				report.potential_impact.findings.map(
					( finding ) => finding.records[ 0 ].index
				)
			).toEqual( [ 0, 1 ] );
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
			expect( rows[ 0 ].text ).toContain( 'From Findings' );
			expect( notice() ).toHaveTextContent(
				'Showing the Net result with the entry of this finding highlighted: acme_cleanup'
			);
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

		it( 'regression: two instances removed and one re-added count the same on the overview and the signal page', async () => {
			// The API names the records: both removed recurring instances
			// (subjects) and the re-added one (a replacement it weighed). A
			// one-time instance of the same hook is not covered.
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
				records: [
					{ list: 'removed', index: 0, role: 'subject' },
					{ list: 'removed', index: 1, role: 'subject' },
					{ list: 'added', index: 0, role: 'replacement' },
				],
			};
			const report = cronFinal( { added, removed }, [ finding ] );
			const { assessment } = report.potential_impact;
			expect( assessment.net_result.cron ).toEqual( {
				changes: 4,
				matched: 3,
				informational: 1,
			} );

			// Overview: the API's totals, never recounted.
			await renderReport( report );
			expect( summaryRow( 'Findings' ) ).toHaveTextContent(
				`${ assessment.informational } of ${ assessment.assessed_changes } configuration changes in the Net result matched no review rule (informational).`
			);

			cleanup();
			// Signal page: the same records, marked and counted.
			await renderReport( report, { signal: 'cron', phase: 'final' } );
			expect(
				screen.getByText(
					'Review rules matched 3 changes; they are marked below. 1 other change matched no rule (informational).'
				)
			).toBeInTheDocument();
			const rowsOf = ( name: string ) =>
				within(
					within( phasePanel() ).getByRole( 'region', { name } )
				).getAllByRole( 'listitem' );
			// Both removed recurring instances are the finding's subjects…
			expect(
				rowsOf( 'Removed (2)' ).map( ( row ) =>
					row.textContent?.includes(
						'Needs reviewRecurring WP-Cron event no longer observed'
					)
				)
			).toEqual( [ true, true ] );
			// …the one-time instance of the same hook is informational.
			expect(
				rowsOf( 'No longer scheduled (1)' )[ 0 ]
			).not.toHaveTextContent( 'Needs review' );
			expect( rowsOf( 'Added (1)' )[ 0 ] ).toHaveTextContent(
				'Counted as a replacement in: Recurring WP-Cron event no longer observed'
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

			expect( marked() ).toHaveLength( 1 );
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

			expect( summaryRow( 'Findings' ) ).toHaveTextContent(
				/^Findings1 needs review/
			);
			expect( groups() ).toHaveLength( 1 );
			expect( groupButton( groups()[ 0 ] ) ).toHaveAttribute(
				'aria-expanded',
				'false'
			);
			expect( checkTexts() ).toEqual( [
				'Checked · no findings',
				'Checked · 1 finding',
				'Not applicable · Action Scheduler was not detected during this update.',
				// Recorded before site checks: one quiet row, not a warning.
				'Not recorded · This update was observed before UpdateLens recorded site checks.',
			] );
			// Not applicable is not "not assessed".
			expect( notAssessed() ).toBeNull();
			expect( impact().textContent ).not.toMatch( /Not checked/ );
		} );

		it( 'evaluated without findings: compact, no verdict', async () => {
			await renderReport( RANK_MATH );

			expect( summaryRow( 'Findings' ) ).toHaveTextContent(
				'No findings matched the completed observations.'
			);
			// Said once: the section explains what it means, not the headline again.
			expect( impact() ).not.toHaveTextContent(
				'No findings matched the completed observations.'
			);
			expect( impact() ).toHaveTextContent(
				'Only a few specific patterns are checked, so this does not show that the update had no other effects.'
			);
			expect(
				within( impact() ).queryByRole( 'list', { name: 'Findings' } )
			).not.toBeInTheDocument();
			expect( notAssessed() ).toBeNull();
			expect( summary().textContent ).not.toMatch( FORBIDDEN );
			// Statuses and limitations wait in "What was checked".
			expect( impact().querySelector( 'details' )!.open ).toBe( false );
			expect( impact().querySelector( 'details' ) ).toHaveTextContent(
				'No match does not show that the update had no other effects.'
			);
			expect( impact().textContent ).not.toMatch( FORBIDDEN );
		} );

		it( 'partial: a failed provider stays visible with its reason', async () => {
			await renderReport( MALFORMED_CRON );

			expect( summaryRow( 'Findings' ) ).toHaveTextContent(
				'No findings in the completed observations; some observations were not assessed.'
			);
			// Kept apart from the priorities, with its reason.
			expect( notAssessed() ).toHaveTextContent(
				/^Not assessedWP-Cron: WP-Cron analysis unavailable$/
			);
			expect( checkTexts()[ 1 ] ).toBe(
				'Not checked (failed) · WP-Cron analysis unavailable'
			);
		} );

		it( 'partial: Action Scheduler no longer detected is unavailable, not "not applicable"', async () => {
			await renderReport( AS_GONE );

			expect( notAssessed() ).toHaveTextContent(
				'Action Scheduler: Action Scheduler no longer detected'
			);
			expect( checkTexts()[ 2 ] ).toBe(
				'Not checked (unavailable) · Action Scheduler no longer detected'
			);
			expect( impact() ).not.toHaveTextContent( 'Not applicable' );
		} );

		it( 'partial: Action Scheduler newly detected is unavailable, not "not applicable"', async () => {
			await renderReport( AS_NEW );

			expect( checkTexts()[ 2 ] ).toBe(
				'Not checked (unavailable) · Action Scheduler newly detected'
			);
			expect( notAssessed() ).toHaveTextContent(
				'Action Scheduler: Action Scheduler newly detected'
			);
			expect( impact() ).not.toHaveTextContent( 'Not applicable' );
		} );

		it( 'historical report: absence at every capture is not claimed', async () => {
			await renderReport( HISTORICAL_NOT_INSTALLED );

			expect( notAssessed() ).toHaveTextContent(
				'Action Scheduler not detected (not confirmed for the whole update)'
			);
			expect( checkTexts()[ 2 ] ).toBe(
				'Not checked (unavailable) · This report cannot confirm that Action Scheduler was absent throughout the update.'
			);
			expect( impact() ).not.toHaveTextContent( 'Not applicable' );
			expect( summaryRow( 'Findings' ) ).toHaveTextContent(
				/^Findings1 needs review/
			);
		} );

		it( 'with every signal: evaluated, zero findings', async () => {
			await renderReport( WOOCOMMERCE );

			expect( summaryRow( 'Findings' ) ).toHaveTextContent(
				'No findings matched the completed observations.'
			);
			expect( checkTexts() ).toEqual( [
				'Checked · no findings',
				'Checked · no findings',
				'Checked · no findings',
				// Recorded before site checks: one quiet row, not a warning.
				'Not recorded · This update was observed before UpdateLens recorded site checks.',
			] );
		} );

		it( 'awaiting the settled observation', async () => {
			await renderReport( AWAITING );

			expect( summaryRow( 'Findings' ) ).toHaveTextContent(
				'Waiting for the Net result'
			);
			expect( impact() ).toHaveTextContent( 'Refresh the report later' );
			// Still observing is not "not assessed".
			expect( notAssessed() ).toBeNull();
			expect( checkTexts()[ 0 ] ).toBe(
				'Not checked yet (waiting for the settled capture)'
			);
			expect(
				impact().querySelector( 'dd[data-category="waiting"]' )
			).not.toBeNull();
		} );

		it( 'expired observation', async () => {
			await renderReport( EXPIRED );

			expect( summaryRow( 'Findings' ) ).toHaveTextContent(
				'Not assessed in this partial report'
			);
			// Why is said once, at the top; this section only refers to it.
			expect( impact() ).toHaveTextContent(
				'Review rules need the Net result, which this partial report does not have.'
			);
			expect( checkTexts()[ 0 ] ).toBe(
				'Not checked (observation expired)'
			);
			// It opens on During update; the missing Net result is not assessed.
			expect( tab( /^During update/ ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			expect( notAssessed() ).toHaveTextContent(
				/^Not assessedObservation expired: Options & autoload and WP-Cron$/
			);
		} );

		it( 'is left out when no phase is available (failed update)', async () => {
			await renderReport( FAILED );

			expect(
				screen.queryByRole( 'region', { name: 'Findings' } )
			).not.toBeInTheDocument();
		} );
	} );

	describe( 'large reports', () => {
		it( 'keeps counts accurate while collapsed and reveals findings in steps', async () => {
			const user = userEvent.setup();
			await renderReport( manyFindings( 1200, 300 ) );

			expect( summaryRow( 'Findings' ) ).toHaveTextContent(
				`${ formatCount( 1500 ) } need review`
			);
			const [ options, cron ] = groups();
			expect(
				screen.getByRole( 'button', {
					name: `Large autoloaded options, ${ formatCount(
						1200
					) } findings, Needs review`,
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
			expect( options ).toHaveTextContent(
				`Now autoloaded across these options: ${ formatBytes(
					1200 * 163000
				) }, all newly autoloaded. Check whether they need to load on every page.`
			);
			expect( cron ).toHaveTextContent(
				'300 recurring instances are no longer observed. Check whether these hooks are still scheduled.'
			);

			await user.click( groupButton( options ) );
			expect( rows( options ) ).toHaveLength( 3 );
			expect( rows( cron ) ).toHaveLength( 0 );
			expect( options ).toHaveTextContent(
				`Showing 3 of ${ formatCount( 1200 ) }`
			);
			// One explanation per group, not per finding, worded for several.
			expect(
				within( options ).getAllByRole( 'heading', {
					name: 'Why it might matter',
				} )
			).toHaveLength( 1 );
			expect(
				within(
					within( options )
						.getAllByRole( 'term' )[ 0 ]
						.closest( 'dl' )!
				)
					.getAllByRole( 'definition' )
					.map( ( value ) => value.textContent )
			).toEqual( [
				keepTogether( formatBytes( 1200 * 163000 ) ),
				keepTogether( formatBytes( 0 ) ),
				keepTogether( formatBytesDelta( 1200 * 163000 ) ),
			] );
			expect(
				within( options )
					.getAllByRole( 'term' )
					.map( ( label ) => label.textContent )
			).toEqual( [
				'Now autoloaded across these options',
				'Autoloaded before the update',
				'Change in autoloaded stored data',
				// Then the first finding row's own details.
				'Size',
				'Autoload',
				'Review size',
				'Size',
				'Autoload',
				'Review size',
				'Size',
				'Autoload',
				'Review size',
			] );
			expect( options ).toHaveTextContent(
				`${ formatCount(
					1200
				) } of these options were not present before the update and count as zero there. These figures cover only these options, not the site’s total autoloaded data or memory use.`
			);
			expect( options ).toHaveTextContent(
				'Large autoloaded options add to that work'
			);
			expect( options ).toHaveTextContent(
				'Check whether each of these options needs to be loaded on every request.'
			);

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

describe( 'Potential Impact group wording', () => {
	const GREW: LargeAutoloadedOptionFinding = {
		...LARGE_BECAME,
		option: 'acme_grown',
		evidence: {
			transition: 'grew_past_threshold',
			threshold_bytes: 150000,
			size_delta: 40000,
			value_changed: true,
		},
		before: { size: 140000, autoload: 'on', is_autoloaded: true },
		after: { size: 180000, autoload: 'on', is_autoloaded: true },
	};
	const TWO_REMOVED: RecurringCronEventRemovedFinding = {
		...CRON_REMOVED,
		hook: 'other_vendor_sync',
		evidence: { ...CRON_REMOVED.evidence, removed_recurring_count: 2 },
	};

	/**
	 * The one group of some findings.
	 *
	 * @param findings Findings of one rule and signal.
	 */
	const group = ( ...findings: ImpactFinding[] ) => {
		const [ only ] = groupFindings( findings );
		return only;
	};

	/**
	 * Guidance of a group as one text.
	 *
	 * @param findings Findings of one rule and signal.
	 */
	const guidance = ( ...findings: ImpactFinding[] ) =>
		[
			...whyItMatters( group( ...findings ) ),
			...whatToCheck( group( ...findings ) ),
		].join( ' ' );

	it( 'words guidance for one finding in the singular', () => {
		expect( guidance( LARGE_ADDED ) ).toContain(
			'Check whether this option needs to be loaded on every request.'
		);
		expect( guidance( CRON_REMOVED ) ).toContain(
			'Check whether the hook still has scheduled events'
		);
		expect( guidance( CRON_INTERVAL ) ).toContain(
			'Open WP-Cron to see the next scheduled run.'
		);
	} );

	it( 'words guidance for several findings in the plural, never as one plugin', () => {
		const texts = [
			guidance( LARGE_ADDED, LARGE_BECAME ),
			guidance( CRON_REMOVED, TWO_REMOVED ),
			guidance( CRON_INTERVAL, { ...CRON_INTERVAL, hook: 'other_job' } ),
			guidance( AS_TYPE_CHANGED, {
				...AS_TYPE_CHANGED,
				hook: 'other_action',
				evidence: { change: 'cron_expression_changed' },
			} ),
		];
		for ( const text of texts ) {
			// No singular subject, and no single plugin for every item.
			expect( text ).not.toMatch(
				/\bthis (option|action|task)\b|\bthe hook\b|\bits size\b|related plugin’s|the same plugin/
			);
		}
		const [ options, removed, cron, actionScheduler ] = texts;
		expect( options ).toContain(
			'Large autoloaded options add to that work'
		);
		expect( options ).toContain(
			'Check whether each of these options needs to be loaded on every request.'
		);
		expect( options ).toContain(
			'ask the developer of the plugin that uses it'
		);
		expect( removed ).toContain(
			'whether these hooks still have other scheduled events'
		);
		expect( removed ).toContain(
			'Check whether each hook still has scheduled events'
		);
		expect( cron ).toContain(
			'A different interval changes how often a task runs'
		);
		expect( cron ).toContain(
			'Open WP-Cron to see the next scheduled runs.'
		);
		expect( actionScheduler ).toContain(
			'Read the cron expressions to confirm when each action runs'
		);
	} );

	/**
	 * A size as the summaries write it: number and unit kept together.
	 *
	 * @param bytes  Bytes.
	 * @param digits Maximum decimals.
	 */
	const size = ( bytes: number, digits?: number ) =>
		keepTogether( formatBytes( bytes, undefined, digits ) );

	/**
	 * A large-option finding with the given sizes and autoload flags.
	 *
	 * @param option Option name.
	 * @param before Size and effective autoload before, or null (added).
	 * @param after  Size now (autoloaded).
	 */
	const option = (
		option: string,
		before: [ number, boolean ] | null,
		after: number
	): LargeAutoloadedOptionFinding => ( {
		code: 'large_autoloaded_option',
		records: [],
		signal: 'options',
		priority: 'review',
		option,
		evidence: {
			transition:
				before === null
					? 'added'
					: before[ 1 ]
						? 'grew_past_threshold'
						: 'became_autoloaded',
			threshold_bytes: 150000,
			size_delta: before === null ? null : after - before[ 0 ],
			value_changed: before === null ? null : after !== before[ 0 ],
		},
		before:
			before === null
				? null
				: {
						size: before[ 0 ],
						autoload: before[ 1 ] ? 'on' : 'off',
						is_autoloaded: before[ 1 ],
					},
		after: { size: after, autoload: 'on', is_autoloaded: true },
	} );

	describe( 'autoloaded stored data of large options', () => {
		it( 'counts an option that switched from off to on without growing as newly autoloaded', () => {
			// LARGE_BECAME: 182,340 bytes before and after, autoload off → on.
			expect( autoloadedBytes( group( LARGE_BECAME ) ) ).toEqual( {
				before: 0,
				after: 182340,
				storedBefore: 182340,
				storedAfter: 182340,
				added: 0,
				becameAutoloaded: 1,
			} );
			expect( groupSummary( group( LARGE_BECAME ) ) ).toBe(
				`Now autoloaded: ${ size(
					182340
				) }; not autoloaded before the update. Check whether it needs to load on every page.`
			);
			// Two of them: the stored size did not change, the autoloaded
			// data grew by their whole size.
			const switched = group(
				LARGE_BECAME,
				option( 'acme_other', [ 170000, false ], 170000 )
			);
			expect( groupSummary( switched ) ).toBe(
				`Now autoloaded across these options: ${ size(
					352340
				) }, all newly autoloaded. Check whether they need to load on every page.`
			);
			expect( groupFacts( switched ).rows ).toEqual( [
				[ 'Now autoloaded across these options', size( 352340 ) ],
				[ 'Autoloaded before the update', size( 0 ) ],
				[
					'Change in autoloaded stored data',
					keepTogether( formatBytesDelta( 352340 ) ),
				],
				[
					'Stored size before the update, autoloaded or not',
					size( 352340 ),
				],
			] );
		} );

		it( 'counts the growth of an option that was already autoloaded', () => {
			expect( autoloadedBytes( group( GREW ) ) ).toMatchObject( {
				before: 140000,
				after: 180000,
				storedBefore: 140000,
			} );
			expect( groupSummary( group( GREW ) ) ).toBe(
				`Now autoloaded: ${ size( 180000 ) }, up ${ size(
					40000
				) }. Check whether it needs to load on every page.`
			);
			const grown = group(
				GREW,
				option( 'acme_more', [ 100000, true ], 160000 )
			);
			expect( groupSummary( grown ) ).toBe(
				`Now autoloaded across these options: ${ size(
					340000
				) }, up ${ size(
					100000
				) }. Check whether they need to load on every page.`
			);
			// Stored and autoloaded sizes agree: no extra stored-size row.
			expect( groupFacts( grown ).rows ).toHaveLength( 3 );
		} );

		it( 'counts a newly added option as zero before the update', () => {
			expect( autoloadedBytes( group( LARGE_ADDED ) ) ).toMatchObject( {
				before: 0,
				after: 163000,
				storedBefore: 0,
				added: 1,
			} );
			expect( groupSummary( group( LARGE_ADDED ) ) ).toBe(
				`Now autoloaded: ${ size(
					163000
				) }; not present before the update. Check whether it needs to load on every page.`
			);
		} );

		it( 'combines all three transitions in one group', () => {
			const mixed = group( GREW, LARGE_ADDED, LARGE_BECAME );
			// Autoloaded before: only GREW (140,000). Now: 180,000 + 163,000
			// + 182,340. Stored before: 140,000 + 182,340 (added counts 0).
			expect( autoloadedBytes( mixed ) ).toEqual( {
				before: 140000,
				after: 525340,
				storedBefore: 322340,
				storedAfter: 525340,
				added: 1,
				becameAutoloaded: 1,
			} );
			expect( groupSummary( mixed ) ).toBe(
				`Now autoloaded across these options: ${ size(
					525340
				) }, up ${ size(
					385340
				) }. Check whether they need to load on every page.`
			);
			expect( groupFacts( mixed ) ).toEqual( {
				rows: [
					[ 'Now autoloaded across these options', size( 525340 ) ],
					[ 'Autoloaded before the update', size( 140000 ) ],
					[
						'Change in autoloaded stored data',
						keepTogether( formatBytesDelta( 385340 ) ),
					],
					[
						'Stored size before the update, autoloaded or not',
						size( 322340 ),
					],
				],
				notes: [
					'1 of these options was not present before the update and counts as zero there.',
					'1 of these options existed before without autoload; its stored size counts as autoloaded only after the update.',
					'These figures cover only these options, not the site’s total autoloaded data or memory use.',
				],
			} );
		} );

		it( 'avoids comparisons that round to the same value', () => {
			// Almost nothing autoloaded before: "up 8.7 MB" would repeat the
			// current size, so the line says what it was before.
			const tiny = option( 'acme_tiny', [ 244, true ], 9123456 );
			expect( groupSummary( group( tiny ) ) ).toBe(
				`Now autoloaded: ${ size( 9123456 ) }, up from ${ size(
					244
				) }. Check whether it needs to load on every page.`
			);
			expect(
				groupSummary(
					group(
						tiny,
						option( 'acme_tiny_2', [ 100, true ], 160000 )
					)
				)
			).toBe(
				`Now autoloaded across these options: ${ size(
					9123456 + 160000
				) }, up from ${ size(
					344
				) }. Check whether they need to load on every page.`
			);

			// Close values: the small change is exact, not "146.5 KB → 146.5 KB".
			const close = option( 'acme_close', [ 149990, true ], 150010 );
			expect( groupSummary( group( close ) ) ).toBe(
				`Now autoloaded: ${ size( 150010 ) }, up ${ size(
					20
				) }. Check whether it needs to load on every page.`
			);
			const pair = group(
				option( 'acme_a', [ 150000, true ], 150010 ),
				option( 'acme_b', [ 150000, true ], 150010 )
			);
			// Before and after would both show "293 KB": two decimals.
			expect( formatBytes( 300000 ) ).toBe( formatBytes( 300020 ) );
			expect( groupFacts( pair ).rows ).toEqual( [
				[ 'Now autoloaded across these options', size( 300020, 2 ) ],
				[ 'Autoloaded before the update', size( 300000, 2 ) ],
				[
					'Change in autoloaded stored data',
					keepTogether( formatBytesDelta( 20 ) ),
				],
			] );
		} );

		it( 'keeps numbers and units together for narrow rows', () => {
			const texts = [
				groupSummary( group( GREW ) ),
				groupSummary( group( GREW, LARGE_ADDED, LARGE_BECAME ) ),
				...groupFacts(
					group( GREW, LARGE_ADDED, LARGE_BECAME )
				).rows.map( ( [ , value ] ) => value ),
				thresholdText( 150000 ),
			];
			for ( const text of texts ) {
				// No breakable space between a number and its unit.
				expect( text ).not.toMatch( /\d \S*B\b/ );
				expect( text ).toMatch( /\d\u00a0(B|KB|MB)\b/ );
			}
			expect( keepTogether( '+196.8 KB' ) ).toBe( '+196.8\u00a0KB' );
		} );
	} );

	it( 'keeps collapsed lines short', () => {
		expect( groupSummary( group( CRON_REMOVED, TWO_REMOVED ) ) ).toBe(
			'3 recurring instances are no longer observed. Check whether these hooks are still scheduled.'
		);
		expect( groupSummary( group( AS_TYPE_CHANGED ) ) ).toBe(
			'Switched between an interval and a cron expression. Check that the new schedule is intended.'
		);

		// Short enough for a 320px row; the review size is in the details.
		for ( const findings of [
			[ GREW ],
			[ LARGE_ADDED ],
			[ LARGE_BECAME ],
			[ GREW, LARGE_ADDED, LARGE_BECAME ],
			[ LARGE_ADDED, LARGE_ADDED ],
			[ CRON_REMOVED ],
			[ CRON_REMOVED, TWO_REMOVED ],
			[ CRON_INTERVAL ],
			[ AS_TYPE_CHANGED ],
		] ) {
			const summary = groupSummary( group( ...findings ) );
			expect( summary.length ).toBeLessThanOrEqual( 115 );
			expect( summary ).not.toContain( 'review size' );
		}
	} );

	it( 'moves the longer facts of several findings into the details', () => {
		expect( groupFacts( group( LARGE_ADDED ) ) ).toEqual( {
			rows: [],
			notes: [],
		} );
		expect( groupFacts( group( CRON_REMOVED, TWO_REMOVED ) ) ).toEqual( {
			rows: [],
			notes: [
				'3 recurring instances scheduled before the update were not observed after it, across 2 hooks.',
			],
		} );
		expect(
			groupFacts(
				group( CRON_INTERVAL, { ...CRON_INTERVAL, hook: 'other_job' } )
			)
		).toEqual( { rows: [], notes: [] } );
	} );
} );
