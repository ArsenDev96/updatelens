import apiFetch from '@wordpress/api-fetch';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import App from '@/admin/App';
import type { AnalysisReport } from '@/admin/types/api';

import { COMPLETED, EXPIRED, historyItem, listResponse } from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

const SECRET = 'sk_test_UPDATE_LENS_UI_SECRET';

function serveApi( reports: AnalysisReport[] ) {
	apiFetchMock.mockImplementation( ( async ( options: { path?: string } ) => {
		const path = options.path ?? '';
		const match = path.match( /^\/updatelens\/v1\/analyses\/(\d+)$/ );
		if ( match ) {
			const report = reports.find(
				( r ) => r.id === Number( match[ 1 ] )
			);
			if ( ! report ) {
				throw {
					code: 'updatelens_analysis_not_found',
					data: { status: 404 },
				};
			}
			return report;
		}
		return listResponse(
			reports.map( ( report ) => historyItem( report ) )
		);
	} ) as unknown as typeof apiFetch );
}

function visit( search: string ) {
	window.history.replaceState( null, '', `/wp-admin/tools.php${ search }` );
}

describe( 'App navigation', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
		visit( '?page=updatelens' );
	} );

	it( 'opens a report, puts it in the URL, and goes back with the browser', async () => {
		serveApi( [ EXPIRED, COMPLETED ] );
		render( <App /> );
		const user = userEvent.setup();

		expect(
			screen.getByText(
				'See what changed when WordPress plugins update.'
			)
		).toBeInTheDocument();
		const links = await screen.findAllByRole( 'link', {
			name: /UpdateLens Fixture A/,
		} );
		await user.click(
			links.find( ( link ) =>
				link.getAttribute( 'href' )?.endsWith( 'analysis=1' )
			)!
		);

		expect( window.location.search ).toBe( '?page=updatelens&analysis=1' );
		const title = await screen.findByRole( 'heading', {
			level: 2,
			name: 'UpdateLens Fixture A',
		} );
		expect( title ).toHaveFocus();
		expect(
			screen.getByRole( 'tab', { name: /Net result/ } )
		).toHaveAttribute( 'aria-selected', 'true' );

		await act( async () => {
			window.history.back();
			await new Promise( ( resolve ) =>
				window.addEventListener( 'popstate', resolve, { once: true } )
			);
		} );

		expect( window.location.search ).toBe( '?page=updatelens' );
		expect(
			await screen.findByRole( 'heading', { name: 'Update History' } )
		).toHaveFocus();
	} );

	it( 'loads a report URL directly', async () => {
		serveApi( [ COMPLETED, EXPIRED ] );
		visit( '?page=updatelens&analysis=2' );
		render( <App /> );

		expect(
			await screen.findByText( 'Post-update observation expired' )
		).toBeInTheDocument();
		expect( apiFetchMock ).toHaveBeenCalledTimes( 1 );
		expect( apiFetchMock ).toHaveBeenCalledWith( {
			path: '/updatelens/v1/analyses/2',
		} );
		// Not focused on initial load.
		expect( screen.getByRole( 'heading', { level: 2 } ) ).not.toHaveFocus();
	} );

	it( 'returns to History from the back link without the analysis parameter', async () => {
		serveApi( [ COMPLETED ] );
		visit( '?page=updatelens&paged=2&analysis=1' );
		render( <App /> );
		const user = userEvent.setup();

		await user.click(
			await screen.findByRole( 'link', { name: /Update History/ } )
		);

		expect( window.location.search ).toBe( '?page=updatelens&paged=2' );
		expect( apiFetchMock ).toHaveBeenLastCalledWith( {
			path: '/updatelens/v1/analyses?page=2&per_page=20',
			parse: false,
		} );
	} );

	it( 'puts the history page in the URL', async () => {
		apiFetchMock.mockImplementation( ( async () =>
			listResponse(
				[ historyItem( COMPLETED ) ],
				25,
				2
			) ) as unknown as typeof apiFetch );
		render( <App /> );
		const user = userEvent.setup();

		await user.click(
			await screen.findByRole( 'button', { name: /Next/ } )
		);

		expect( window.location.search ).toBe( '?page=updatelens&paged=2' );
		expect( await screen.findByText( 'Page 2 of 2' ) ).toBeInTheDocument();
	} );
} );

describe( 'privacy', () => {
	const CRON_SECRET = 'sk_test_UPDATE_LENS_CRON_REPORT_SECRET';

	/**
	 * Fields that must never be in API responses, injected into every object
	 * of a report (phases, signals, options, Cron events) to prove the UI
	 * only renders fields of the approved contract.
	 */
	const LEAK = {
		option_value: SECRET,
		fingerprint: 'a'.repeat( 64 ),
		fingerprint_context: `hmac-sha256-v1:${ SECRET }`,
		before_snapshot: `{"secret":"${ SECRET }"}`,
		immediate_snapshot: `{"secret":"${ SECRET }"}`,
		args: [ `https://hooks.example.test/${ CRON_SECRET }` ],
		args_fingerprint: 'b'.repeat( 64 ),
		cron_before_snapshot: `{"fingerprint_context":"cron-args-hmac-sha256-v1:${ CRON_SECRET }"}`,
		key: 'c'.repeat( 32 ),
	};

	function leakEverywhere( value: unknown ): unknown {
		if ( Array.isArray( value ) ) {
			return value.map( leakEverywhere );
		}
		if ( value && typeof value === 'object' ) {
			return {
				...Object.fromEntries(
					Object.entries( value ).map( ( [ k, v ] ) => [
						k,
						leakEverywhere( v ),
					] )
				),
				...LEAK,
			};
		}
		return value;
	}

	it( 'renders only contract fields in History and every phase and signal', async () => {
		const report = leakEverywhere( COMPLETED ) as AnalysisReport;
		serveApi( [ report ] );
		render( <App /> );
		const user = userEvent.setup();

		await screen.findByRole( 'link', { name: /UpdateLens Fixture A/ } );
		const check = () => {
			const html = document.body.innerHTML;
			for ( const needle of [
				SECRET,
				CRON_SECRET,
				'option_value',
				'fingerprint_context',
				'args_fingerprint',
				'hmac-sha256',
				'snapshot',
				'hooks.example.test',
				'a'.repeat( 64 ),
				'b'.repeat( 64 ),
				'c'.repeat( 32 ),
			] ) {
				expect( html ).not.toContain( needle );
			}
		};
		check();

		await user.click(
			screen.getByRole( 'link', { name: /UpdateLens Fixture A/ } )
		);
		await screen.findAllByRole( 'tablist' );
		await user.click( screen.getByText( 'Technical details' ) );
		for ( const name of [
			/During update/,
			/After update/,
			/Net result/,
		] ) {
			await user.click( screen.getByRole( 'tab', { name } ) );
			for ( const signal of [ /^Options/, /^WP-Cron/ ] ) {
				await user.click( screen.getByRole( 'tab', { name: signal } ) );
				check();
			}
		}
		// The Cron hooks themselves are shown.
		expect( document.body ).toHaveTextContent( 'ul_fixture_cleanup' );
	} );
} );
