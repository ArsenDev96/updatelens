import apiFetch from '@wordpress/api-fetch';
import { setLocaleData } from '@wordpress/i18n';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ReportPage } from '@/admin/pages/ReportPage';
import type { AnalysisReport } from '@/admin/types/api';

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

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

function renderReport( report: AnalysisReport ) {
	apiFetchMock.mockResolvedValue( report );
	render(
		<ReportPage
			id={ report.id }
			historyHref="/wp-admin/admin.php?page=updatelens"
			onBack={ vi.fn() }
			focusHeading={ false }
		/>
	);
}

const tab = ( name: RegExp ) => screen.getByRole( 'tab', { name } );
/** The WP-Cron section of the selected phase (signals are stacked, not tabs). */
const signalPanel = () => screen.getByRole( 'region', { name: /^WP-Cron/ } );
const optionsSection = () =>
	screen.getByRole( 'region', { name: /^Options & autoload/ } );
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

	it( 'stacks every signal of the phase as a section, without signal tabs', async () => {
		renderReport( COMPLETED );
		const user = userEvent.setup();

		await screen.findAllByRole( 'tablist' );
		// The phase selector is the only navigation.
		expect(
			screen
				.getAllByRole( 'tablist' )
				.map( ( list ) => list.getAttribute( 'aria-label' ) )
		).toEqual( [ 'Observation phases' ] );
		expect(
			screen.queryByRole( 'tablist', { name: 'Observed signals' } )
		).toBeNull();
		expect( screen.queryByRole( 'tab', { name: /^WP-Cron/ } ) ).toBeNull();

		// Options and WP-Cron in full, Action Scheduler as one line, in that order.
		const sections = [
			optionsSection(),
			signalPanel(),
			screen.getByRole( 'region', { name: /^Action Scheduler/ } ),
		];
		expect(
			sections.map( ( section ) => section.getAttribute( 'id' ) )
		).toEqual( [
			'updatelens-signal-options',
			'updatelens-signal-cron',
			'updatelens-signal-action_scheduler',
		] );
		expect( sections[ 0 ] ).toHaveAccessibleName(
			'Options & autoload · 6 changes'
		);
		expect( sections[ 1 ] ).toHaveAccessibleName( 'WP-Cron · 5 changes' );
		expect( sections[ 2 ] ).toHaveAccessibleName( 'Action Scheduler' );
		expect( optionsSection() ).toHaveTextContent( 'ulfx_a_feature_flags' );
		expect( signalPanel() ).toHaveTextContent( 'ul_fixture_task_1.5.0' );
		expect( optionsSection() ).not.toHaveTextContent(
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
	} );

	it( 'swaps every section when the phase changes', async () => {
		renderReport( COMPLETED );
		const user = await openCron( /Net result/ );

		await user.click( tab( /During update/ ) );
		expect( signalPanel() ).toHaveTextContent( 'ul_fixture_update_once' );
		expect( optionsSection() ).toHaveTextContent(
			'ulfx_a_needs_migration'
		);
		// Nothing of Net result is left behind.
		expect( document.body ).not.toHaveTextContent( 'ulfx_a_feature_flags' );
		expect( document.body ).not.toHaveTextContent(
			'ul_fixture_task_1.5.0'
		);
	} );

	it( 'shows WP-Cron in full when only WP-Cron is available', async () => {
		renderReport( CRON_ONLY );

		await screen.findAllByRole( 'tablist' );
		expect( tab( /Net result/ ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect( tab( /Net result/ ) ).not.toHaveTextContent( 'not available' );
		expect( signalPanel() ).toHaveAccessibleName(
			/^WP-Cron · \d+ changes?$/
		);
		expect( optionsSection() ).toHaveAccessibleName( 'Options & autoload' );
		expect( optionsSection().querySelector( 'ul' ) ).toBeNull();
	} );

	it( 'shows the Cron summary with rescheduled as a separate count', async () => {
		renderReport( COMPLETED );
		await openCron( /Net result/ );

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
		expect( screen.getByText( 'Analysis completed' ) ).toBeInTheDocument();
		expect( tab( /Net result/ ) ).not.toHaveTextContent( 'not available' );
		expect( optionsSection() ).toHaveTextContent( 'ulfx_a_feature_flags' );
		// Unavailable is one neutral line, in text.
		expect( signalPanel() ).toHaveAccessibleName( 'WP-Cron' );
		expect( signalPanel().querySelector( 'ul' ) ).toBeNull();
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
	} );

	it( 'keeps each signal’s availability per phase (partial WP-Cron)', async () => {
		renderReport( PARTIAL_CRON );
		const user = await openCron( /During update/ );

		expect( signalPanel() ).toHaveTextContent( 'ul_fixture_update_once' );

		await user.click( tab( /After update/ ) );
		expect( tab( /After update/ ) ).not.toHaveTextContent(
			'not available'
		);
		expect( signalPanel() ).toHaveAccessibleName( 'WP-Cron' );
		expect( signalPanel() ).toHaveTextContent(
			"UpdateLens couldn't capture or read the WP-Cron state this phase needs."
		);
		expect( optionsSection() ).toHaveTextContent( 'ulfx_a_api_key' );

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
		expect(
			within(
				screen.getByRole( 'region', { name: 'Analysis status' } )
			).getByText( /within 10 minutes of the update/ )
		).toBeInTheDocument();
		expect( document.body ).not.toHaveTextContent( '5 minutes' );
	} );

	it( 'uses the API window in the awaiting notice', async () => {
		renderReport( { ...AWAITING, observation_window_seconds: 120 } );

		expect(
			await screen.findByText( /within 2 minutes of the update/ )
		).toBeInTheDocument();
	} );

	it( 'renders reports from before WP-Cron observation', async () => {
		renderReport( PRE_CRON );

		await screen.findAllByRole( 'tablist' );
		expect( optionsSection() ).toHaveTextContent( 'ulfx_a_feature_flags' );
		expect( signalPanel() ).toHaveTextContent(
			'WP-Cron was not captured for this phase.'
		);
	} );
} );
