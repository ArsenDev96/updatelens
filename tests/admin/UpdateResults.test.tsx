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
import {
	AWAITING,
	COMPLETED,
	EXPIRED,
	FAILED,
	MALFORMED_CRON,
	PRE_CRON,
	withSiteCheckCase,
} from './fixtures';
import {
	HISTORICAL_NOT_INSTALLED,
	IMPACT,
	ONE_FINDING,
} from './impact-fixtures';
import {
	openReport,
	reportPanel,
	reportTab,
	reportTabs,
	signalCard,
} from './report-view';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

/*
 * A report's three tabs, and the first of them, "Update results": the
 * decision. With findings, they are listed by the priority the API gave
 * them; without, one panel worded by how complete the observations were
 * (green only when everything applicable was assessed and no website check
 * failed after the update). What could not be assessed and the changes no
 * rule matched (informational) are kept apart from the priorities. Never
 * one verdict on the update.
 */

type CaseName = keyof typeof cases.cases;

/**
 * A report with a site check case (real API output).
 *
 * @param base Report.
 * @param name Case.
 */
function withSiteChecks( base: AnalysisReport, name: CaseName ) {
	const data = cases.cases[ name ];
	return withSiteCheckCase(
		base,
		data.site_checks as SiteChecks,
		data.potential_impact_site_checks as SiteCheckImpact
	);
}

async function renderReport( report: AnalysisReport ) {
	apiFetchMock.mockResolvedValue( report );
	openReport( report.id );
	await screen.findByRole( 'heading', { level: 2 } );
}

/** The result panel shown without findings, or null. */
const panel = () =>
	document.querySelector< HTMLElement >(
		'[aria-labelledby="updatelens-result-title"]'
	);

/** Lines of "Not assessed", or null without it. */
function notAssessed(): string[] | null {
	const region = screen.queryByRole( 'region', { name: 'Not assessed' } );
	return region
		? within( region )
				.getAllByRole( 'listitem' )
				.map( ( item ) => item.textContent ?? '' )
		: null;
}

/** Priority groups as their headings' accessible names, in order. */
const priorityGroups = () =>
	Array.from(
		reportPanel().querySelectorAll< HTMLElement >(
			'section[data-priority]'
		)
	).map(
		( group ) =>
			within( group ).getByRole( 'heading', { level: 3 } ).textContent
	);

const FORBIDDEN =
	/\bsafe\b|healthy|no issues|no problems|passed update|update passed|successful update|caused by|\brisk|\bcomplete\b/i;

describe( 'Report tabs', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'opens on Update results: one tab bar of three, one panel', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ) );
		expect( reportTabs().map( ( item ) => item.textContent ) ).toEqual( [
			'Update results',
			'Website checks',
			'Configuration changes',
		] );
		expect( reportTab( 'Update results' ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		// Only the selected tab is in the tab order, and only its panel exists.
		expect(
			reportTabs().map( ( item ) => item.getAttribute( 'tabindex' ) )
		).toEqual( [ '0', '-1', '-1' ] );
		expect(
			screen.getByRole( 'tabpanel', { name: 'Update results' } )
		).toBe( reportPanel() );
		expect(
			screen.queryByRole( 'region', { name: 'Website checks' } )
		).toBeNull();
		expect(
			screen.queryByRole( 'region', { name: 'Configuration changes' } )
		).toBeNull();
		// The header stays above the tabs.
		expect(
			screen
				.getByRole( 'banner' )
				.compareDocumentPosition( reportTabs()[ 0 ] ) &
				Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
	} );

	it( 'switches tabs with the keyboard, keeping focus on the tabs', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ) );
		await user.click( reportTab( 'Update results' ) );
		await user.keyboard( '{ArrowRight}' );
		expect( reportTab( 'Website checks' ) ).toHaveFocus();
		expect( reportTab( 'Website checks' ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect(
			screen.getByRole( 'region', { name: 'Website checks' } )
		).toBeInTheDocument();
		expect( window.location.search ).toContain( 'tab=checks' );
		await user.keyboard( '{End}' );
		expect( reportTab( 'Configuration changes' ) ).toHaveFocus();
		expect(
			screen.getByRole( 'region', { name: 'Configuration changes' } )
		).toBeInTheDocument();
		await user.keyboard( '{ArrowRight}' );
		expect( reportTab( 'Update results' ) ).toHaveFocus();
		await user.keyboard( '{ArrowLeft}' );
		expect( reportTab( 'Configuration changes' ) ).toHaveFocus();
		await user.keyboard( '{Home}' );
		expect( reportTab( 'Update results' ) ).toHaveFocus();
		expect( panel() ).not.toBeNull();
	} );

	it( 'opens the tab the URL names; a URL with a phase opens Configuration changes', async () => {
		apiFetchMock.mockResolvedValue( COMPLETED );
		openReport( COMPLETED.id, { tab: 'checks' } );
		await screen.findByRole( 'heading', { level: 2 } );
		expect( reportTab( 'Website checks' ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		cleanup();
		// Older report URLs chose a phase on the overview.
		openReport( COMPLETED.id, { phase: 'during_update' } );
		await screen.findByRole( 'heading', { level: 2 } );
		expect( reportTab( 'Configuration changes' ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		// A phase in the URL wins over the Net result default.
		expect(
			within(
				screen.getByRole( 'tablist', { name: 'Observation phases' } )
			).getByRole( 'tab', { selected: true } )
		).toHaveTextContent( /^During update/ );
	} );

	it( 'Configuration changes: Net result first, every source opens in place and links to its page', async () => {
		const user = userEvent.setup();
		apiFetchMock.mockResolvedValue( COMPLETED );
		openReport( COMPLETED.id, { tab: 'configuration' } );
		await screen.findByRole( 'heading', { level: 2 } );
		const phases = within(
			screen.getByRole( 'tablist', { name: 'Observation phases' } )
		).getAllByRole( 'tab' );
		expect(
			phases.map( ( item ) => item.textContent?.split( ',' )[ 0 ] )
		).toEqual( [ 'Net result', 'During update', 'After update' ] );
		expect( phases[ 0 ] ).toHaveAttribute( 'aria-selected', 'true' );

		// Every row links to its signal page, with changes or without.
		const links = () =>
			within(
				screen.getByRole( 'list', { name: 'Signals' } )
			).getAllByRole( 'link' );
		expect( links().map( ( link ) => link.textContent ) ).toEqual( [
			'Open page',
			'Open page',
			'Open page',
		] );
		expect( links()[ 0 ] ).toHaveAccessibleName(
			'Open page: Options & autoload'
		);
		expect( links()[ 2 ] ).toHaveAccessibleName(
			'Open page: Action Scheduler'
		);
		expect( links()[ 1 ] ).toHaveAttribute(
			'href',
			expect.stringContaining(
				`analysis=${ COMPLETED.id }&signal=cron&tab=configuration`
			)
		);

		// The changes still open in place.
		const options = screen.getByRole( 'button', {
			name: 'Options & autoload',
		} );
		await user.click( options );
		expect( options ).toHaveAttribute( 'aria-expanded', 'true' );

		await user.click( links()[ 1 ] );
		expect(
			await screen.findByRole( 'heading', { level: 2, name: 'WP-Cron' } )
		).toHaveFocus();

		// Back: Configuration changes again, the source still expanded and
		// focus on the link that was opened.
		await user.click(
			within(
				screen.getByRole( 'navigation', { name: 'Breadcrumb' } )
			).getAllByRole( 'link' )[ 1 ]
		);
		expect( reportTab( 'Configuration changes' ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect(
			screen.getByRole( 'button', { name: 'Options & autoload' } )
		).toHaveAttribute( 'aria-expanded', 'true' );
		expect( links()[ 1 ] ).toHaveFocus();
	} );

	it( 'Configuration changes: expanded sources give their regions distinct names', async () => {
		const user = userEvent.setup();
		apiFetchMock.mockResolvedValue( IMPACT );
		openReport( IMPACT.id, { tab: 'configuration' } );
		await screen.findByRole( 'heading', { level: 2 } );
		const sources = within(
			screen.getByRole( 'list', { name: 'Signals' } )
		).getAllByRole( 'button', { expanded: false } );
		expect( sources ).toHaveLength( 3 );
		for ( const source of sources ) {
			await user.click( source );
		}

		// Landmarks need distinct names (axe landmark-unique): with every source
		// open, "Changed (1)" and "Site totals" would otherwise repeat. Lists are
		// named with their source; totals by their source-specific label, under
		// the same visible "Site totals" heading.
		const regions = within( reportPanel() )
			.getAllByRole( 'region' )
			.map(
				( region ) =>
					region.getAttribute( 'aria-label' ) ??
					( region.getAttribute( 'aria-labelledby' ) ?? '' )
						.split( ' ' )
						.map(
							( id ) => document.getElementById( id )?.textContent
						)
						.join( ' ' )
			);
		expect( regions ).toEqual( [
			'Configuration changes',
			'Options & autoload Added (1)',
			'Options & autoload Changed (2)',
			'Option totals',
			'WP-Cron Removed (1)',
			'WP-Cron Changed (1)',
			'WP-Cron Rescheduled (1)',
			'WP-Cron totals',
			'Action Scheduler Changed (1)',
			'Action Scheduler totals',
		] );
		expect( new Set( regions ).size ).toBe( regions.length );
		// The same names as computed by the accessibility tree.
		for ( const name of regions ) {
			expect(
				within( reportPanel() ).getAllByRole( 'region', { name } )
			).toHaveLength( 1 );
		}
		expect(
			within( reportPanel() )
				.getAllByRole( 'heading', { name: 'Site totals' } )
				.map( ( heading ) => heading.tagName )
		).toEqual( [ 'H5', 'H5', 'H5' ] );
		// The lists inside carry no second label.
		for ( const name of [
			'Option totals',
			'WP-Cron totals',
			'Action Scheduler totals',
		] ) {
			const region = within( reportPanel() ).getByRole( 'region', {
				name,
			} );
			expect( region.querySelector( 'dl' ) ).not.toHaveAttribute(
				'aria-label'
			);
		}
	} );
} );

describe( 'Update results', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'without findings: nothing flagged, in green only when everything was assessed', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ) );
		expect( panel() ).toHaveAttribute( 'data-tone', 'positive' );
		expect( panel() ).toHaveAttribute( 'data-icon', 'check' );
		// The green result names its limited scope: two requests, not the site.
		expect( panel() ).toHaveTextContent(
			'Nothing flagged for reviewNo concerning patterns matched the completed observations.UpdateLens checks selected patterns, and its website checks covered only the homepage and the REST API route /wp/v2/users/me, so this does not guarantee that the update had no other effects.'
		);
		expect( priorityGroups() ).toEqual( [] );
		expect( notAssessed() ).toBeNull();
		// The changes no rule matched are informational, never "safe".
		expect( reportPanel() ).toHaveTextContent(
			/\d+ of \d+ configuration changes? in the Net result matched no review rule \(informational\)\./
		);
		expect( reportPanel().textContent ).not.toMatch( FORBIDDEN );
	} );

	it( 'lists findings by priority, high priority first', async () => {
		await renderReport( withSiteChecks( IMPACT, 'home_newly_failing' ) );
		expect( panel() ).toBeNull();
		expect( priorityGroups() ).toEqual( [
			'High priority, 1',
			'Needs review, 5',
		] );
		expect( reportPanel() ).toHaveTextContent(
			'Based on Net result and website checks'
		);
		expect( reportPanel().textContent ).not.toMatch( FORBIDDEN );
	} );

	it( 'one finding to review', async () => {
		await renderReport( ONE_FINDING );
		expect( priorityGroups() ).toEqual( [ 'Needs review, 1' ] );
	} );

	it( 'both checks newly failing are two high priority findings', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'both_newly_failing' ) );
		expect( priorityGroups() ).toEqual( [ 'High priority, 2' ] );
	} );

	it( 'pending results: nothing flagged so far, never passed', async () => {
		await renderReport( withSiteChecks( COMPLETED, 'pending' ) );
		expect( panel() ).toHaveAttribute( 'data-tone', 'progress' );
		expect( panel() ).toHaveTextContent( /^Nothing flagged so far/ );
		expect( panel() ).toHaveTextContent( 'they are not counted as passed' );
		// Waiting is not "not assessed".
		expect( notAssessed() ).toBeNull();
		expect( reportPanel().textContent ).not.toMatch( /Passed/ );
	} );

	it( 'without findings, a configuration source not assessed: nothing flagged, never green', async () => {
		await renderReport( MALFORMED_CRON );
		expect( panel() ).toHaveAttribute( 'data-tone', 'neutral' );
		expect( panel() ).toHaveTextContent(
			'Nothing flagged for reviewNo concerning patterns matched the completed observations, but not everything could be assessed.'
		);
		expect( notAssessed() ).toEqual( [
			'WP-Cron: WP-Cron analysis unavailable',
		] );
		expect( priorityGroups() ).toEqual( [] );
	} );

	/** The panel's text without its title and note. */
	const NOT_FULLY = {
		all: 'No review rule matched the completed observations, but the website checks were not compared before and after the update. UpdateLens cannot tell whether the website responded as it did before the update.',
		home: 'No review rule matched the completed observations, but the homepage check was not compared before and after the update. UpdateLens cannot tell whether the homepage responded as it did before the update.',
		rest: 'No review rule matched the completed observations, but the REST API check was not compared before and after the update. UpdateLens cannot tell whether the REST API route /wp/v2/users/me responded as it did before the update.',
	};

	it.each( [
		[
			// The whole site failed after the update (the updating browser's
			// follow-up could not succeed), Dashboard → Updates, or a page left
			// during the update: the stored evidence cannot tell them apart.
			'no follow-up from the updating tab',
			COMPLETED,
			'not_followed_up',
			NOT_FULLY.all,
			[
				'Website checks not compared: Homepage and REST API (no check after the update)',
			],
		],
		[
			'checks after the update never reported',
			COMPLETED,
			'after_not_reported',
			NOT_FULLY.all,
			[
				'Website checks not compared: Homepage and REST API (no check after the update)',
			],
		],
		[
			'another update right after this one',
			COMPLETED,
			'next_update',
			NOT_FULLY.all,
			[
				'Website checks not compared: Homepage and REST API (another update ran at the same time)',
			],
		],
		[
			'an update overlapping the checks after it',
			COMPLETED,
			'overlapping_update',
			NOT_FULLY.all,
			[
				'Website checks not compared: Homepage and REST API (another update ran at the same time)',
			],
		],
		[
			'one website check only (REST)',
			COMPLETED,
			'one_unavailable',
			NOT_FULLY.rest,
			[
				'Website checks not compared: REST API (no check before the update)',
			],
		],
		[
			'one website check only (homepage, inconclusive after the update)',
			COMPLETED,
			'unconfirmed',
			NOT_FULLY.home,
			[ 'Website checks not compared: Homepage (could not be compared)' ],
		],
		[
			'website checks and a configuration source',
			MALFORMED_CRON,
			'no_baseline',
			NOT_FULLY.all,
			[
				'WP-Cron: WP-Cron analysis unavailable',
				'Website checks not compared: Homepage and REST API (no check before the update)',
			],
		],
	] as Array< [ string, AnalysisReport, CaseName, string, string[] ] > )(
		'without findings, website checks used but not compared: not fully assessed, never "Nothing flagged": %s',
		async ( _label, base, name, text, lines ) => {
			await renderReport( withSiteChecks( base, name ) );
			expect( panel() ).toHaveAttribute( 'data-tone', 'neutral' );
			// No check mark: nothing here reads as all clear.
			expect( panel() ).toHaveAttribute( 'data-icon', 'info' );
			expect( panel() ).toHaveTextContent(
				`Not fully assessed${ text }Open the homepage and WordPress admin yourself to check that the site works.`
			);
			expect( panel() ).not.toHaveTextContent( 'Nothing flagged' );
			// It never claims a failure or a cause the evidence does not show.
			expect( panel()?.textContent ).not.toMatch(
				/down|outage|failed|closed|broke/i
			);
			expect( notAssessed() ).toEqual( lines );
			// Not assessed is never a priority.
			expect( priorityGroups() ).toEqual( [] );
		}
	);

	it( 'findings stay listed by priority when website checks were not compared', async () => {
		await renderReport( withSiteChecks( IMPACT, 'not_followed_up' ) );
		expect( panel() ).toBeNull();
		expect( priorityGroups() ).toEqual( [ 'Needs review, 5' ] );
		expect( notAssessed() ).toEqual( [
			'Website checks not compared: Homepage and REST API (no check after the update)',
		] );
		expect( reportPanel() ).not.toHaveTextContent( 'Nothing flagged' );
	} );

	it( 'an expired observation keeps its own panel', async () => {
		await renderReport( EXPIRED );
		expect( panel() ).toHaveTextContent(
			/^Not assessed in this partial report/
		);
		expect( panel() ).not.toHaveTextContent( 'Not fully assessed' );
		expect( panel() ).toHaveAttribute( 'data-icon', 'info' );
	} );

	it.each( [ 'failing_both', 'redirect_then_failure' ] as CaseName[] )(
		'%s: a check failing after the update keeps the panel from reading as a pass',
		async ( name ) => {
			await renderReport( withSiteChecks( COMPLETED, name ) );
			expect( panel() ).toHaveAttribute( 'data-tone', 'neutral' );
			expect( panel() ).toHaveTextContent( 'Nothing flagged for review' );
		}
	);

	it.each( [
		[
			'disabled',
			'UpdateLens checks selected patterns only, and website checks were off for this update, so this does not guarantee that the update had no other effects.',
		],
		[
			'historical',
			'UpdateLens checks selected patterns only, and the site’s responses were not checked for this update, so this does not guarantee that the update had no other effects.',
		],
		[
			'both_cross_origin',
			'UpdateLens checks selected patterns only, and the site’s responses were not checked for this update, so this does not guarantee that the update had no other effects.',
		],
		[
			'home_cross_origin',
			'UpdateLens checks selected patterns, and its website checks covered only the REST API route /wp/v2/users/me, so this does not guarantee that the update had no other effects.',
		],
	] as Array< [ CaseName, string ] > )(
		'%s: website checks that were not used do not count against completeness, and the panel says what was checked',
		async ( name, note ) => {
			await renderReport( withSiteChecks( COMPLETED, name ) );
			expect( panel() ).toHaveAttribute( 'data-tone', 'positive' );
			expect( panel() ).toHaveTextContent(
				`Nothing flagged for reviewNo concerning patterns matched the completed observations.${ note }`
			);
			expect( notAssessed() ).toBeNull();
		}
	);

	it( 'leads from what was not assessed to Website checks', async () => {
		vi.useFakeTimers( { shouldAdvanceTime: true } );
		try {
			const user = userEvent.setup( {
				advanceTimers: vi.advanceTimersByTime,
			} );
			await renderReport( withSiteChecks( COMPLETED, 'no_baseline' ) );
			await user.click(
				screen.getByRole( 'button', { name: 'View website checks' } )
			);
			await act( () => vi.runOnlyPendingTimersAsync() );
			expect( reportTab( 'Website checks' ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			expect(
				screen.getByRole( 'heading', { name: 'Website checks' } )
			).toHaveFocus();
		} finally {
			vi.useRealTimers();
		}
	} );

	it( 'leads from the informational changes to Configuration changes', async () => {
		vi.useFakeTimers( { shouldAdvanceTime: true } );
		try {
			const user = userEvent.setup( {
				advanceTimers: vi.advanceTimersByTime,
			} );
			await renderReport( withSiteChecks( COMPLETED, 'both_passed' ) );
			await user.click(
				screen.getByRole( 'button', {
					name: 'View configuration changes',
				} )
			);
			await act( () => vi.runOnlyPendingTimersAsync() );
			expect(
				screen.getByRole( 'heading', { name: 'Configuration changes' } )
			).toHaveFocus();
		} finally {
			vi.useRealTimers();
		}
	} );

	it( 'lists unavailable sources apart from the priorities', async () => {
		await renderReport( HISTORICAL_NOT_INSTALLED );
		expect( notAssessed() ).toEqual( [
			'Action Scheduler not detected (not confirmed for the whole update)',
		] );
	} );

	it( 'a historical report: sources it predates are not recorded, not unavailable', async () => {
		const user = userEvent.setup();
		await renderReport( PRE_CRON );
		expect( notAssessed()?.[ 0 ] ).toMatch(
			/^Not recorded for this report: WP-Cron/
		);
		expect( notAssessed()?.join( ' ' ) ).not.toMatch(
			/expired|unavailable/i
		);
		await user.click( reportTab( 'Configuration changes' ) );
		expect( signalCard( 'WP-Cron' ) ).toHaveTextContent(
			/^WP-CronNot available · /
		);
	} );

	it( 'an expired observation: explained once, by the status above the tabs', async () => {
		await renderReport( EXPIRED );
		expect(
			screen.getByRole( 'region', { name: 'Analysis status' } )
		).toHaveTextContent( /^Partial report/ );
		expect( panel() ).toHaveAttribute( 'data-tone', 'neutral' );
		expect( panel() ).toHaveTextContent(
			/^Not assessed in this partial report/
		);
		// The status already names what expired.
		expect( notAssessed() ).toBeNull();
		expect( reportPanel() ).not.toHaveTextContent( 'Observation expired' );
	} );

	it( 'an open analysis is still observing, not "not assessed"', async () => {
		await renderReport( AWAITING );
		expect( panel() ).toHaveAttribute( 'data-tone', 'progress' );
		expect( panel() ).toHaveTextContent( /^Waiting for the Net result/ );
		expect( notAssessed() ).toBeNull();
	} );

	it( 'a failed analysis: the review rules are not available', async () => {
		await renderReport( FAILED );
		expect( panel() ).toHaveTextContent( /^Not available for this report/ );
		expect( priorityGroups() ).toEqual( [] );
	} );

	it( 'what UpdateLens checks: the review rules behind one button', async () => {
		const user = userEvent.setup();
		await renderReport( withSiteChecks( COMPLETED, 'both_passed' ) );
		const button = screen.getByRole( 'button', {
			name: 'View review rules',
		} );
		expect( button ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( screen.queryByText( 'Patterns checked' ) ).toBeNull();
		await user.click( button );
		expect( button ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( button ).toHaveTextContent( 'Hide review rules' );
		expect( screen.getByText( 'Patterns checked' ) ).toBeInTheDocument();
		expect( screen.getByText( 'What was checked' ) ).toBeInTheDocument();
	} );
} );
