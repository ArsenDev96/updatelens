import apiFetch from '@wordpress/api-fetch';
import { setLocaleData } from '@wordpress/i18n';
import { cleanup, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { AnalysisReport, Provider } from '@/admin/types/api';

import {
	AWAITING,
	COMPLETED,
	CRON_FINAL,
	CRON_ONLY,
	EXPIRED,
	MALFORMED_CRON,
	PARTIAL_CRON,
	PRE_CRON,
	T,
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
 * Opens a report on the WP-Cron page (or another view).
 *
 * @param report Report served by the API.
 * @param signal Signal page; null for the overview.
 */
function renderReport(
	report: AnalysisReport,
	signal: Provider | null = 'cron'
) {
	apiFetchMock.mockResolvedValue( report );
	openReport( report.id, { signal: signal ?? undefined } );
}

/** The selected phase of the WP-Cron page. */
const signalPanel = phasePanel;
const iso = ( seconds: number ) =>
	new Date( seconds * 1000 ).toISOString().replace( '.000Z', 'Z' );

async function openCron( phase: RegExp ) {
	const user = userEvent.setup();
	await screen.findAllByRole( 'tablist' );
	await user.click( tab( phase ) );
	return user;
}

describe( 'WP-Cron in reports', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'gives every signal its own page instead of signal tabs', async () => {
		renderReport( COMPLETED, null );
		const user = userEvent.setup();

		await screen.findAllByRole( 'tablist' );
		// The phase selector is the only tab list.
		expect(
			screen
				.getAllByRole( 'tablist' )
				.map( ( list ) => list.getAttribute( 'aria-label' ) )
		).toEqual( [ 'Observation phases' ] );
		expect( screen.queryByRole( 'tab', { name: /^WP-Cron/ } ) ).toBeNull();

		// One card per signal, in order, each linking to its page.
		const links = within(
			screen.getByRole( 'list', { name: 'Signals' } )
		).getAllByRole( 'link' );
		expect(
			links.map( ( link ) =>
				new URLSearchParams(
					link.getAttribute( 'href' )!.split( '?' )[ 1 ]
				).get( 'signal' )
			)
		).toEqual( [ 'options', 'cron', 'action_scheduler' ] );
		expect( cardTexts()[ 1 ] ).toBe(
			'WP-CronEvents scheduled with WordPress cron.5 changes Added 2 · No longer present 1 · Rescheduled 2View details'
		);
		// No rows of any signal on the overview.
		expect( document.body ).not.toHaveTextContent(
			'ul_fixture_task_1.5.0'
		);

		// Arrow keys move between phases only.
		tab( /Net result/ ).focus();
		await user.keyboard( '{ArrowLeft}' );
		expect( tab( /After update/ ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect( tab( /After update/ ) ).toHaveFocus();

		// The WP-Cron page shows WP-Cron only.
		await user.click( screen.getByRole( 'link', { name: 'WP-Cron' } ) );
		// …in the phase chosen on the overview.
		expect( tab( /After update/ ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect( signalPanel() ).toHaveTextContent( 'ul_fixture_task_1.5.0' );
		expect( document.body ).not.toHaveTextContent( 'Total option data' );
	} );

	it( 'swaps the page content when the phase changes', async () => {
		renderReport( COMPLETED );
		const user = await openCron( /Net result/ );

		expect( signalPanel() ).toHaveTextContent( 'ul_fixture_task_1.5.0' );
		await user.click( tab( /During update/ ) );
		expect( signalPanel() ).toHaveTextContent( 'ul_fixture_update_once' );
		// Nothing of Net result is left behind.
		expect( document.body ).not.toHaveTextContent(
			'ul_fixture_task_1.5.0'
		);
		expect( screen.getAllByRole( 'tabpanel' ) ).toHaveLength( 1 );
	} );

	it( 'shows WP-Cron when only WP-Cron is available', async () => {
		renderReport( CRON_ONLY, null );

		await screen.findAllByRole( 'tablist' );
		expect( tab( /Net result/ ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect( tab( /Net result/ ) ).not.toHaveTextContent( 'Not available' );
		expect( signalCard( 'WP-Cron' ) ).toHaveTextContent(
			/^WP-CronEvents scheduled with WordPress cron\.\d+ changes?/
		);
		expect( signalCard( 'Options & autoload' ) ).toHaveTextContent(
			/^Options & autoloadStored settings in wp_options, including autoloaded data\.Not available/
		);
	} );

	it( 'shows the Cron summary with rescheduled as a separate count', async () => {
		renderReport( COMPLETED );
		await openCron( /Net result/ );

		// The phase, the headline and the counts first; lists, then the
		// site totals as quiet statistics.
		expect( signalPanel() ).toHaveTextContent(
			/^Net result5 observed WP-Cron changesAdded2No longer present1Changed0Rescheduled2.*Added \(2\).*Site totals/
		);
		const metrics = within( signalPanel() ).getAllByRole( 'definition' );
		expect(
			within( signalPanel() )
				.getAllByRole( 'term' )
				.slice( 0, 4 )
				.map( ( t ) => t.textContent )
		).toEqual( [ 'Added', 'No longer present', 'Changed', 'Rescheduled' ] );
		expect( metrics.slice( 0, 4 ).map( ( m ) => m.textContent ) ).toEqual( [
			'2',
			'1',
			'0',
			'2',
		] );
		expect( signalPanel() ).toHaveTextContent(
			/Events\s*12\s*→\s*to\s*13\s*\(\+1\)/
		);
		expect( signalPanel() ).toHaveTextContent(
			/One-time\s*1\s*→\s*to\s*2\s*\(\+1\)/
		);
		expect( signalPanel() ).toHaveTextContent(
			/Recurring\s*11\s*→\s*to\s*11/
		);
		expect( signalPanel() ).toHaveTextContent(
			/Unique hooks\s*11\s*→\s*to\s*11/
		);
		expect( signalPanel() ).toHaveTextContent(
			'WordPress core and other plugins may also schedule or reschedule jobs during the observation window.'
		);
	} );

	it( 'renders added events with recurrence, next run and interval', async () => {
		renderReport( COMPLETED );
		await openCron( /Net result/ );

		const task = within( signalPanel() )
			.getByText( 'ul_fixture_task_1.5.0' )
			.closest( 'li' )!;
		expect( task ).toHaveTextContent( 'Recurring · hourly' );
		expect( task ).toHaveTextContent( /Next run/ );
		expect( task ).toHaveTextContent( /Interval\s*1 hour/ );
		expect( task.querySelector( 'time' ) ).toHaveAttribute(
			'datetime',
			iso( T + 1800 )
		);

		const once = within( signalPanel() )
			.getByText( 'ul_fixture_update_once' )
			.closest( 'li' )!;
		expect( once ).toHaveTextContent( 'One-time' );
		expect( once ).not.toHaveTextContent( 'Interval' );
	} );

	it( 'renders removed events with their former schedule', async () => {
		renderReport( COMPLETED );
		await openCron( /After update/ );

		const removed = within( signalPanel() )
			.getByRole( 'region', { name: 'Removed (1)' } )
			.querySelector( 'li' )!;
		expect( removed ).toHaveTextContent( 'ul_fixture_task_1.4.0' );
		expect( removed ).toHaveTextContent( 'Recurring · hourly' );
		expect( removed ).toHaveTextContent( /Was scheduled for/ );
		expect( removed.querySelector( 'time' ) ).toHaveAttribute(
			'datetime',
			iso( T + 1200 )
		);
	} );

	it( 'renders rescheduled events, including a core job, without causal wording', async () => {
		renderReport( COMPLETED );
		await openCron( /After update/ );

		const section = within( signalPanel() ).getByRole( 'region', {
			name: 'Rescheduled (2)',
		} );
		const rows = within( section ).getAllByRole( 'listitem' );
		expect( rows[ 0 ] ).toHaveTextContent( 'ul_fixture_cleanup' );
		expect( rows[ 0 ] ).toHaveTextContent( '(+2 hours)' );
		expect( rows[ 0 ] ).toHaveTextContent( 'Recurring · daily' );
		const times = rows[ 0 ].querySelectorAll( 'time' );
		expect( times[ 0 ] ).toHaveAttribute( 'datetime', iso( T + 3600 ) );
		expect( times[ 1 ] ).toHaveAttribute( 'datetime', iso( T + 10800 ) );

		// WordPress core activity in the window is shown, not hidden.
		expect( rows[ 1 ] ).toHaveTextContent(
			'wp_privacy_delete_old_export_files'
		);
		expect( rows[ 1 ] ).toHaveTextContent( '(+1 hour)' );
		expect( document.body ).not.toHaveTextContent(
			/caused|created by|plugin rescheduled|warning|danger|regression/i
		);
	} );

	it( 'uses translated duration units for movements and intervals', async () => {
		setLocaleData(
			{
				'': {
					domain: 'updatelens',
					plural_forms: 'nplurals=2; plural=(n != 1);',
				},
				'%s hour': [ '%s xhour', '%s xhours' ],
			},
			'updatelens'
		);
		renderReport( COMPLETED );
		await openCron( /After update/ );

		const rows = within(
			within( signalPanel() ).getByRole( 'region', {
				name: 'Rescheduled (2)',
			} )
		).getAllByRole( 'listitem' );
		expect( rows[ 0 ] ).toHaveTextContent( '(+2 xhours)' );
		expect( rows[ 1 ] ).toHaveTextContent( '(+1 xhour)' );

		await openCron( /Net result/ );
		const task = within( signalPanel() )
			.getByText( 'ul_fixture_task_1.5.0' )
			.closest( 'li' )!;
		expect( task ).toHaveTextContent( /Interval\s*1 xhour/ );
		const once = within( signalPanel() )
			.getByText( 'ul_fixture_update_once' )
			.closest( 'li' )!;
		expect( once ).toHaveTextContent( 'One-time' );
		expect( once ).not.toHaveTextContent( /xhour|Interval/ );
		expect( document.body ).not.toHaveTextContent( /\d hours?\b/ );
	} );

	it( 'renders recurrence changes and one-time to recurring', async () => {
		renderReport( {
			...COMPLETED,
			phases: {
				...COMPLETED.phases,
				final: {
					...COMPLETED.phases.final,
					cron: {
						...CRON_FINAL,
						changed: [
							{
								hook: 'plugin_cleanup',
								before_timestamp: T,
								after_timestamp: T,
								timestamp_changed: false,
								before_schedule: 'daily',
								after_schedule: 'hourly',
								before_interval: 86400,
								after_interval: 3600,
								before_is_recurring: true,
								after_is_recurring: true,
							},
							{
								hook: 'plugin_import',
								before_timestamp: T,
								after_timestamp: T + 1800,
								timestamp_changed: true,
								before_schedule: null,
								after_schedule: 'hourly',
								before_interval: null,
								after_interval: 3600,
								before_is_recurring: false,
								after_is_recurring: true,
							},
						],
					},
				},
			},
		} );
		await openCron( /Net result/ );

		const cleanup = within( signalPanel() )
			.getByText( 'plugin_cleanup' )
			.closest( 'li' )!;
		expect( cleanup ).toHaveTextContent( 'Schedule changed' );
		expect( cleanup ).toHaveTextContent(
			/Schedule\s*daily\s*→\s*to\s*hourly/
		);
		expect( cleanup ).toHaveTextContent(
			/Interval\s*1 day\s*→\s*to\s*1 hour/
		);
		expect( cleanup ).toHaveTextContent( '(unchanged)' );

		const imported = within( signalPanel() )
			.getByText( 'plugin_import' )
			.closest( 'li' )!;
		expect( imported ).toHaveTextContent(
			/One-time\s*→\s*to\s*Recurring · hourly/
		);
		expect( imported.querySelectorAll( 'time' ) ).toHaveLength( 2 );
	} );

	it( 'shows the same hook more than once and explains hidden arguments', async () => {
		renderReport( {
			...COMPLETED,
			phases: {
				...COMPLETED.phases,
				final: {
					...COMPLETED.phases.final,
					cron: {
						...CRON_FINAL,
						added: [
							CRON_FINAL.added[ 1 ],
							{ ...CRON_FINAL.added[ 1 ] },
						],
					},
				},
			},
		} );
		await openCron( /Net result/ );

		expect(
			within( signalPanel() ).getAllByText( 'ul_fixture_update_once' )
		).toHaveLength( 2 );
		expect( signalPanel() ).toHaveTextContent(
			'WP-Cron event arguments are fingerprinted for matching but are never stored or shown. Events with the same hook may therefore represent different argument sets.'
		);
		// No one-time event moved or disappeared here, so that note is left out.
		expect( signalPanel() ).not.toHaveTextContent(
			'A moved one-time event may represent'
		);
		expect( signalPanel() ).not.toHaveTextContent(
			'One-time jobs may disappear'
		);
	} );

	it( 'shows malformed Cron state as unavailable while Options stay usable', async () => {
		renderReport( MALFORMED_CRON );
		const user = userEvent.setup();

		await screen.findAllByRole( 'tablist' );
		// The WP-Cron page has no phase with WP-Cron data.
		expect( tab( /Net result/ ) ).toHaveTextContent( 'Not available' );
		// Unavailable is a calm empty state, in text.
		expect( signalPanel().querySelector( 'ul' ) ).toBeNull();
		expect(
			within( signalPanel() ).queryByRole( 'definition' )
		).toBeNull();
		expect( signalPanel() ).toHaveTextContent(
			'WP-Cron analysis unavailable'
		);
		expect( signalPanel() ).toHaveTextContent(
			"The site's Cron state contained data UpdateLens could not safely normalize."
		);
		expect( signalPanel() ).not.toHaveTextContent( 'malformed_cron_state' );

		// The raw code is technical detail only.
		await user.click( screen.getByText( 'Technical details' ) );
		expect(
			screen
				.getAllByText( 'malformed_cron_state' )[ 0 ]
				.closest( 'details' )
		).not.toBeNull();

		// Options stay usable: the report keeps its changes.
		cleanup();
		renderReport( MALFORMED_CRON, 'options' );
		await screen.findAllByRole( 'tablist' );
		expect( tab( /Net result/ ) ).not.toHaveTextContent( 'Not available' );
		expect( signalPanel() ).toHaveTextContent( 'ulfx_a_feature_flags' );
	} );

	it( 'keeps each signal’s availability per phase (partial WP-Cron)', async () => {
		renderReport( PARTIAL_CRON );
		const user = await openCron( /During update/ );

		expect( signalPanel() ).toHaveTextContent( 'ul_fixture_update_once' );

		await user.click( tab( /After update/ ) );
		expect( tab( /After update/ ) ).toHaveTextContent( 'Not available' );
		expect( signalPanel() ).toHaveTextContent(
			"UpdateLens couldn't capture or read the WP-Cron state this phase needs."
		);

		await user.click( tab( /Net result/ ) );
		expect( signalPanel() ).toHaveTextContent( 'ul_fixture_task_1.5.0' );
	} );

	it( 'explains an expired Cron phase with the window from the API', async () => {
		renderReport( { ...EXPIRED, observation_window_seconds: 600 } );
		const user = await openCron( /During update/ );

		expect( signalPanel() ).toHaveTextContent( 'ul_fixture_update_once' );
		await user.click( tab( /Net result/ ) );
		expect( signalPanel() ).toHaveTextContent(
			'Post-update WP-Cron state was not captured within 10 minutes of the update.'
		);
		expect( document.body ).not.toHaveTextContent( '5 minutes' );

		// The overview's status strip uses the same window.
		await user.click(
			within(
				screen.getByRole( 'navigation', { name: 'Breadcrumb' } )
			).getByRole( 'link', { name: 'UpdateLens Fixture A' } )
		);
		expect(
			within(
				screen.getByRole( 'region', { name: 'Analysis status' } )
			).getByText( /within 10 minutes of the update/ )
		).toBeInTheDocument();
		expect( document.body ).not.toHaveTextContent( '5 minutes' );
	} );

	it( 'uses the API window in the awaiting notice', async () => {
		renderReport( { ...AWAITING, observation_window_seconds: 120 }, null );

		expect(
			await screen.findByText( /within 2 minutes of the update/ )
		).toBeInTheDocument();
	} );

	it( 'renders reports from before WP-Cron observation', async () => {
		renderReport( PRE_CRON, null );

		await screen.findAllByRole( 'tablist' );
		expect( signalCard( 'Options & autoload' ) ).toHaveTextContent(
			'6 changes'
		);
		expect( signalCard( 'WP-Cron' ) ).toHaveTextContent(
			'Not available Not captured'
		);
		cleanup();

		renderReport( PRE_CRON );
		await screen.findAllByRole( 'tablist' );
		expect( signalPanel() ).toHaveTextContent(
			'WP-Cron was not captured for this phase.'
		);
	} );
} );
