import apiFetch from '@wordpress/api-fetch';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ReportPage } from '@/admin/pages/ReportPage';
import type { AnalysisReport, AvailableOptionsPhase } from '@/admin/types/api';

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

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

function renderReport( report: AnalysisReport | null, onBack = vi.fn() ) {
	if ( report ) {
		apiFetchMock.mockResolvedValue( report );
	}
	render(
		<ReportPage
			id={ report?.id ?? 1 }
			historyHref="/wp-admin/admin.php?page=updatelens"
			onBack={ onBack }
			focusHeading={ false }
		/>
	);
	return onBack;
}

/** The phase panel (the first tab panel; the signal panel is nested in it). */
const panel = () => screen.getAllByRole( 'tabpanel' )[ 0 ];
const tab = ( name: RegExp ) => screen.getByRole( 'tab', { name } );

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

	it( 'renders a completed report with Net result selected', async () => {
		renderReport( COMPLETED );

		expect(
			await screen.findByRole( 'heading', {
				level: 2,
				name: 'UpdateLens Fixture A',
			} )
		).toBeInTheDocument();
		expect(
			within( screen.getByRole( 'banner' ) ).getByText(
				'updatelens-fixture-a/updatelens-fixture-a.php'
			)
		).toBeInTheDocument();
		expect(
			within( screen.getByRole( 'banner' ) ).getByText( 'Completed' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Analysis completed' ) ).toBeInTheDocument();
		expect(
			screen.getByText(
				'UpdateLens captured changes during the update and shortly afterward.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Observation completed on the next admin request.'
			)
		).toBeInTheDocument();
		expect( document.body ).not.toHaveTextContent( /lifecycle|eligible/ );
		expect( screen.getByText( /^Updated / ) ).toBeInTheDocument();

		expect( tab( /Net result/ ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect( tab( /During update/ ) ).toHaveAttribute(
			'aria-selected',
			'false'
		);
		// Phase tabs name the changes; signal tabs stay compact.
		expect(
			screen.getAllByRole( 'tab' ).map( ( item ) => item.textContent )
		).toEqual( [
			'During update2 changes',
			'After update11 changes',
			'Net result11 changes',
			'Options6',
			'WP-Cron5',
			'Action SchedulerNot available',
		] );
		expect( panel() ).toHaveTextContent(
			'Net difference between the state before the update and the end of the observation window.'
		);

		// Primary summary cards.
		const metrics = within( panel() ).getAllByRole( 'definition' );
		expect( metrics.slice( 0, 4 ).map( ( m ) => m.textContent ) ).toEqual( [
			'2',
			'1',
			'3',
			'+30 B',
		] );
		// Secondary totals (decimal separator follows the browser locale).
		expect( panel() ).toHaveTextContent(
			/Total option data\s*29[.,]7 KB\s*→\s*to\s*27[.,]8 KB\s*\(−2 KB\)/
		);
		expect( panel() ).toHaveTextContent(
			/Options\s*154\s*→\s*to\s*155\s*\(\+1\)/
		);

		// Sections, including WordPress core activity observed in the window.
		expect(
			within( panel() ).getByRole( 'heading', { name: 'Added (2)' } )
		).toBeInTheDocument();
		expect(
			within( panel() ).getByText( 'recently_activated' )
		).toBeInTheDocument();
		expect(
			within( panel() ).getByRole( 'heading', { name: 'Removed (1)' } )
		).toBeInTheDocument();
		expect(
			within( panel() ).getByText( 'ulfx_a_legacy_cache' )
		).toBeInTheDocument();
		expect(
			within( panel() ).getByRole( 'heading', { name: 'Changed (3)' } )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'UpdateLens detects value changes without storing the option values themselves.'
			)
		).toBeInTheDocument();
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
		expect( panel() ).toHaveTextContent(
			'Changes observed while WordPress was performing this plugin update.'
		);
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
		expect( tab( /After update/ ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect( tab( /After update/ ) ).toHaveFocus();
		expect( panel() ).toHaveTextContent(
			'Other site activity may also contribute.'
		);
		expect(
			within( panel() ).getByRole( 'heading', { name: 'Removed (2)' } )
		).toBeInTheDocument();

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
		expect( tab( /During update/ ) ).toHaveAttribute( 'tabindex', '-1' );

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
		expect( tab( /After update/ ) ).toHaveTextContent( 'Not available' );
		expect( tab( /Net result/ ) ).toHaveAccessibleName(
			'Net result, not available'
		);

		await user.click( tab( /Net result/ ) );
		expect( panel() ).toHaveTextContent( 'Not captured' );
		expect( panel() ).toHaveTextContent(
			'No eligible admin request occurred within 5 minutes of the update.'
		);
		expect( within( panel() ).queryByRole( 'definition' ) ).toBeNull();
		expect( panel() ).not.toHaveTextContent( 'settle_expired' );
	} );

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
		// The raw code is technical detail only.
		expect( screen.getByText( 'Technical details' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'update_not_installed' ).closest( 'details' )
		).not.toBeNull();
	} );

	it( 'explains a known failure code', async () => {
		renderReport( { ...FAILED, error: { code: 'update_not_completed' } } );

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
		expect( panel() ).toHaveTextContent( 'Comparison stopped' );
	} );

	it( 'keeps valid phases when one phase is corrupt', async () => {
		renderReport( CORRUPT_POST );
		const user = userEvent.setup();

		expect(
			await screen.findByText( 'Analysis completed' )
		).toBeInTheDocument();
		expect( tab( /Net result/ ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect(
			within( panel() ).getByRole( 'heading', { name: 'Added (2)' } )
		).toBeInTheDocument();

		// Options is unreadable here, so the available WP-Cron signal is shown first.
		await user.click( tab( /After update/ ) );
		expect( tab( /^WP-Cron/ ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( tab( /^Options/ ) ).toHaveTextContent( 'Not available' );
		await user.click( tab( /^Options/ ) );
		expect( panel() ).toHaveTextContent(
			"This stored phase couldn't be read safely."
		);
		expect( panel() ).not.toHaveTextContent( 'data_corrupt' );

		await user.click( tab( /During update/ ) );
		expect(
			within( panel() ).getByText( 'ulfx_a_needs_migration' )
		).toBeInTheDocument();
	} );

	it( 'shows the awaiting state with a manual Refresh', async () => {
		apiFetchMock.mockResolvedValueOnce( AWAITING );
		apiFetchMock.mockResolvedValueOnce( { ...COMPLETED, id: AWAITING.id } );
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

		await user.click( screen.getByRole( 'button', { name: 'Refresh' } ) );
		expect(
			await screen.findByText( 'Analysis completed' )
		).toBeInTheDocument();
		expect( apiFetchMock ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'renders changed options with sizes, deltas and autoload changes', async () => {
		renderReport( {
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
		} );

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
		expect( version ).toHaveTextContent( /Value unchanged\s*·\s*5 B/ );
		expect( version ).not.toHaveTextContent( '→ to 5 B' );
		expect( version ).not.toHaveTextContent( '+0' );
		// The raw value changed but the behavior did not.
		expect( version ).toHaveTextContent( /yes\s*→\s*to\s*on/ );
		expect( version ).toHaveTextContent( 'On (no effective change)' );
	} );

	it( 'renders added and removed options with effective autoload, without repeating it', async () => {
		renderReport( COMPLETED );

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
		expect( removed ).not.toHaveTextContent( 'Autoload: Off (off)' );
		expect( removed ).not.toHaveTextContent( '(off)' );
	} );

	it( 'keeps stored autoload values that say more than On/Off', async () => {
		const options = COMPLETED.phases.final.options as AvailableOptionsPhase;
		renderReport( {
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
		} );

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

	it( 'goes back to History in the app', async () => {
		const onBack = renderReport( COMPLETED );
		const user = userEvent.setup();

		await screen.findByText( 'Analysis completed' );
		const back = screen.getByRole( 'link', { name: /Update History/ } );
		expect( back ).toHaveAttribute(
			'href',
			'/wp-admin/admin.php?page=updatelens'
		);
		await user.click( back );
		expect( onBack ).toHaveBeenCalled();
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

	it( 'shows a load failure with Retry', async () => {
		apiFetchMock.mockRejectedValue(
			restError( 'updatelens_reports_unavailable', 500 )
		);
		renderReport( null );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			"This report couldn't be loaded. Try again."
		);
		expect(
			screen.getByRole( 'button', { name: 'Retry' } )
		).toBeInTheDocument();
		expect( document.body ).not.toHaveTextContent(
			/Server says|updatelens_reports_unavailable/
		);
	} );

	it( 'never uses causal wording', async () => {
		renderReport( COMPLETED );
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
	} );
} );
