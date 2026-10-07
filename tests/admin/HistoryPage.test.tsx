import apiFetch from '@wordpress/api-fetch';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { HistoryPage } from '@/admin/pages/HistoryPage';

import {
	COMPLETED,
	EXPIRED,
	FAILED,
	historyItem,
	listResponse,
	pending,
} from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

function renderHistory( page = 1 ) {
	const props = {
		page,
		reportHref: ( id: number ) =>
			`/wp-admin/admin.php?page=updatelens&analysis=${ id }`,
		onOpenReport: vi.fn(),
		onPageChange: vi.fn(),
		focusHeading: false,
	};
	render( <HistoryPage { ...props } /> );
	return props;
}

describe( 'HistoryPage', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'shows a busy skeleton while loading', () => {
		apiFetchMock.mockReturnValue( pending() );
		renderHistory();

		expect(
			screen.getByRole( 'heading', { name: 'Update History' } )
		).toBeInTheDocument();
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Loading update history…'
		);
		expect(
			screen.getByRole( 'status' ).closest( '[aria-busy="true"]' )
		).not.toBeNull();
	} );

	it( 'lists analyses with name, versions, status and phase availability', async () => {
		apiFetchMock.mockResolvedValue(
			listResponse( [
				historyItem( COMPLETED ),
				historyItem( EXPIRED, {
					id: 2,
					plugin: { ...COMPLETED.plugin, name: 'Second Plugin' },
				} ),
				historyItem( FAILED, {
					plugin: { ...FAILED.plugin, name: 'Third Plugin' },
				} ),
			] )
		);
		renderHistory();

		const links = await screen.findAllByRole( 'link' );
		expect( links ).toHaveLength( 3 );

		const first = links[ 0 ];
		expect( first ).toHaveAttribute(
			'href',
			'/wp-admin/admin.php?page=updatelens&analysis=1'
		);
		expect( first ).toHaveTextContent( 'UpdateLens Fixture A' );
		expect( first ).toHaveTextContent( /1\.0\.0\s*→\s*to\s*1\.1\.0/ );
		expect( first ).toHaveTextContent( 'Completed' );
		expect( first ).toHaveTextContent( 'View report' );
		expect(
			within( first )
				.getAllByRole( 'listitem' )
				.map( ( item ) => item.textContent )
		).toEqual( [
			'During update:Changes observed',
			'After update:Changes observed',
			'Net result:Changes observed',
		] );
		expect( first ).not.toHaveTextContent( '✓' );

		const expired = links[ 1 ];
		expect( expired ).toHaveTextContent( 'Second Plugin' );
		expect(
			within( expired )
				.getAllByRole( 'listitem' )
				.map( ( item ) => item.textContent )
		).toEqual( [
			'During update:Changes observed',
			'After update:Not available',
			'Net result:Not available',
		] );

		const failed = links[ 2 ];
		expect( failed ).toHaveTextContent( 'Failed' );
		expect( failed ).toHaveTextContent( /Net result:\s*Not available/ );
		expect( failed ).toHaveTextContent(
			/1\.0\.0\s*→\s*to\s*–\s*unknown version/
		);
		expect( failed.querySelector( 'time' ) ).toHaveAttribute(
			'datetime',
			'2026-10-05T18:46:06Z'
		);
	} );

	it( 'opens a report in the app on click, but leaves modified clicks to the browser', async () => {
		apiFetchMock.mockResolvedValue(
			listResponse( [ historyItem( COMPLETED ) ] )
		);
		const props = renderHistory();
		const user = userEvent.setup();

		const link = await screen.findByRole( 'link', {
			name: /UpdateLens Fixture A/,
		} );
		await user.click( link );
		expect( props.onOpenReport ).toHaveBeenCalledWith( 1 );

		props.onOpenReport.mockClear();
		link.focus();
		await user.keyboard( '{Enter}' );
		expect( props.onOpenReport ).toHaveBeenCalledWith( 1 );

		props.onOpenReport.mockClear();
		await user.keyboard( '{Control>}' );
		await user.click( link );
		await user.keyboard( '{/Control}' );
		expect( props.onOpenReport ).not.toHaveBeenCalled();
	} );

	it( 'paginates with Previous/Next', async () => {
		apiFetchMock.mockResolvedValue(
			listResponse( [ historyItem( COMPLETED ) ], 41, 3 )
		);
		const props = renderHistory( 2 );
		const user = userEvent.setup();

		expect( await screen.findByText( 'Page 2 of 3' ) ).toBeInTheDocument();
		expect( apiFetchMock ).toHaveBeenCalledWith( {
			path: '/updatelens/v1/analyses?page=2&per_page=20',
			parse: false,
		} );

		await user.click( screen.getByRole( 'button', { name: /Next/ } ) );
		expect( props.onPageChange ).toHaveBeenCalledWith( 3 );
		await user.click( screen.getByRole( 'button', { name: /Previous/ } ) );
		expect( props.onPageChange ).toHaveBeenCalledWith( 1 );
	} );

	it( 'disables Previous on the first page and Next on the last', async () => {
		apiFetchMock.mockResolvedValue(
			listResponse( [ historyItem( COMPLETED ) ], 1, 1 )
		);
		renderHistory( 1 );

		expect( await screen.findByText( 'Page 1 of 1' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Previous/ } )
		).toBeDisabled();
		expect( screen.getByRole( 'button', { name: /Next/ } ) ).toBeDisabled();
	} );

	it( 'shows a safe error with Retry, then loads', async () => {
		apiFetchMock.mockRejectedValueOnce(
			new Response(
				JSON.stringify( {
					code: 'updatelens_reports_unavailable',
					message: 'WordPress database error Table wp_x',
				} ),
				{ status: 500 }
			)
		);
		apiFetchMock.mockResolvedValueOnce(
			listResponse( [ historyItem( COMPLETED ) ] )
		);
		// Monitoring start for the boundary on the (only, so last) page.
		apiFetchMock.mockResolvedValueOnce( { started_at: null } );
		renderHistory();
		const user = userEvent.setup();

		const alert = await screen.findByRole( 'alert' );
		expect( alert ).toHaveTextContent(
			"Update history couldn't be loaded. Try again."
		);
		expect( document.body ).not.toHaveTextContent(
			/database|updatelens_reports_unavailable/
		);

		await user.click(
			within( alert ).getByRole( 'button', { name: 'Retry' } )
		);
		expect(
			await screen.findByRole( 'link', { name: /UpdateLens Fixture A/ } )
		).toBeInTheDocument();
		expect( apiFetchMock ).toHaveBeenCalledTimes( 3 );
	} );

	it( 'explains a permission error', async () => {
		apiFetchMock.mockRejectedValue( new Response( '{}', { status: 403 } ) );
		renderHistory();

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			"You don't have permission to view UpdateLens reports."
		);
		expect( screen.queryByRole( 'button', { name: 'Retry' } ) ).toBeNull();
	} );
} );
