import apiFetch from '@wordpress/api-fetch';
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
			historyHref="/wp-admin/tools.php?page=updatelens"
			onBack={ vi.fn() }
			focusHeading={ false }
		/>
	);
}

const tab = ( name: RegExp ) => screen.getByRole( 'tab', { name } );
/** The signal panel inside the phase panel. */
const signalPanel = () => screen.getAllByRole( 'tabpanel' )[ 1 ];
const iso = ( seconds: number ) =>
	new Date( seconds * 1000 ).toISOString().replace( '.000Z', 'Z' );

async function openCron( phase: RegExp ) {
	const user = userEvent.setup();
	await screen.findAllByRole( 'tablist' );
	await user.click( tab( phase ) );
	await user.click( tab( /^WP-Cron/ ) );
	return user;
}

describe( 'WP-Cron in reports', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'defaults to Options and switches signals by click and keyboard', async () => {
		renderReport( COMPLETED );
		const user = userEvent.setup();

		const signals = await screen.findByRole( 'tablist', {
			name: 'Observed signals',
		} );
		expect(
			within( signals )
				.getAllByRole( 'tab' )
				.map( ( t ) => t.getAttribute( 'aria-label' ) )
		).toEqual( [ 'Options, 6 changes', 'WP-Cron, 5 changes' ] );
		expect( tab( /^Options/ ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( signalPanel() ).toHaveTextContent( 'ulfx_a_feature_flags' );

		await user.click( tab( /^WP-Cron/ ) );
		expect( tab( /^WP-Cron/ ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( signalPanel() ).toHaveTextContent( 'ul_fixture_task_1.5.0' );
		expect( signalPanel() ).not.toHaveTextContent( 'ulfx_a_feature_flags' );

		await user.keyboard( '{ArrowLeft}' );
		expect( tab( /^Options/ ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( tab( /^Options/ ) ).toHaveFocus();
		expect( tab( /^WP-Cron/ ) ).toHaveAttribute( 'tabindex', '-1' );
		await user.keyboard( '{End}' );
		expect( tab( /^WP-Cron/ ) ).toHaveFocus();
		// Phase tabs are unaffected by signal keys.
		expect( tab( /Net result/ ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
	} );

	it( 'keeps the chosen signal across phases', async () => {
		renderReport( COMPLETED );
		const user = await openCron( /Net result/ );

		await user.click( tab( /During update/ ) );
		expect( tab( /^WP-Cron/ ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( signalPanel() ).toHaveTextContent( 'ul_fixture_update_once' );
	} );

	it( 'defaults to WP-Cron when only WP-Cron is available', async () => {
		renderReport( CRON_ONLY );

		await screen.findAllByRole( 'tablist' );
		expect( tab( /Net result/ ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect( tab( /Net result/ ) ).not.toHaveTextContent( 'not available' );
		expect( tab( /^WP-Cron/ ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( tab( /^Options/ ) ).toHaveTextContent( 'Not available' );
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
		expect( tab( /^Options/ ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( signalPanel() ).toHaveTextContent( 'ulfx_a_feature_flags' );
		expect( tab( /^WP-Cron/ ) ).toHaveTextContent( 'Not available' );
		expect( tab( /^WP-Cron/ ) ).toHaveAccessibleName(
			'WP-Cron, not available'
		);

		await user.click( tab( /^WP-Cron/ ) );
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
		expect( tab( /^WP-Cron/ ) ).toHaveTextContent( 'Not available' );
		expect( signalPanel() ).toHaveTextContent(
			"UpdateLens couldn't capture or read the WP-Cron state this phase needs."
		);
		await user.click( tab( /^Options/ ) );
		expect( signalPanel() ).toHaveTextContent( 'ulfx_a_api_key' );

		await user.click( tab( /Net result/ ) );
		await user.click( tab( /^WP-Cron/ ) );
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
			screen.getByText( /within 10 minutes of the update/, {
				selector: 'section p',
			} )
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
		const user = userEvent.setup();

		await screen.findAllByRole( 'tablist' );
		expect( signalPanel() ).toHaveTextContent( 'ulfx_a_feature_flags' );
		await user.click( tab( /^WP-Cron/ ) );
		expect( signalPanel() ).toHaveTextContent(
			'WP-Cron was not captured for this phase.'
		);
	} );
} );
