import apiFetch from '@wordpress/api-fetch';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { getAnalyses, getAnalysis } from '@/admin/api/analyses';
import { ApiError, toApiError } from '@/admin/api/errors';

import { COMPLETED, historyItem, listResponse, restError } from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

describe( 'getAnalyses', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'requests one page without parsing and reads the pagination headers', async () => {
		apiFetchMock.mockResolvedValue(
			listResponse( [ historyItem( COMPLETED ) ], 41, 3 )
		);

		const page = await getAnalyses( 2, 20 );

		expect( apiFetchMock ).toHaveBeenCalledTimes( 1 );
		expect( apiFetchMock ).toHaveBeenCalledWith( {
			path: '/updatelens/v1/analyses?page=2&per_page=20',
			parse: false,
		} );
		expect( page.total ).toBe( 41 );
		expect( page.totalPages ).toBe( 3 );
		expect( page.items ).toEqual( [ historyItem( COMPLETED ) ] );
	} );

	it( 'rejects with a normalized error for a failed Response', async () => {
		apiFetchMock.mockRejectedValue( new Response( '{}', { status: 500 } ) );

		await expect( getAnalyses( 1 ) ).rejects.toMatchObject( {
			kind: 'failed',
			status: 500,
		} );
	} );
} );

describe( 'getAnalysis', () => {
	it( 'requests one report', async () => {
		apiFetchMock.mockResolvedValue( COMPLETED );

		await expect( getAnalysis( 1 ) ).resolves.toEqual( COMPLETED );
		expect( apiFetchMock ).toHaveBeenCalledWith( {
			path: '/updatelens/v1/analyses/1',
		} );
	} );

	it( 'maps a missing report to not_found', async () => {
		apiFetchMock.mockRejectedValue(
			restError( 'updatelens_analysis_not_found', 404 )
		);

		const error = await getAnalysis( 9 ).catch( ( e: unknown ) => e );
		expect( error ).toBeInstanceOf( ApiError );
		expect( error ).toMatchObject( { kind: 'not_found', status: 404 } );
		expect( ( error as Error ).message ).not.toContain( 'Server says' );
	} );
} );

describe( 'toApiError', () => {
	it.each( [
		[ restError( 'rest_forbidden', 401 ), 'forbidden' ],
		[ restError( 'rest_forbidden', 403 ), 'forbidden' ],
		[ new Response( null, { status: 403 } ), 'forbidden' ],
		[ new Response( null, { status: 404 } ), 'not_found' ],
		[ restError( 'updatelens_reports_unavailable', 500 ), 'failed' ],
		[ { code: 'fetch_error', message: 'offline' }, 'failed' ],
		[ 'boom', 'failed' ],
		[ null, 'failed' ],
	] )( '%o → %s', ( error, kind ) => {
		expect( toApiError( error ).kind ).toBe( kind );
	} );
} );
