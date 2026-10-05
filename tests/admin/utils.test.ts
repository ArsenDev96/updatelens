import { describe, expect, it } from 'vitest';

import {
	formatBytes,
	formatBytesDelta,
	formatCount,
	formatCountDelta,
	formatDateTime,
} from '@/admin/utils/format';
import {
	defaultPhase,
	errorText,
	phaseLabel,
	phaseNote,
	reportNotice,
	settleOutcomeText,
	statusLabel,
	statusTone,
	unavailableReasonText,
} from '@/admin/utils/labels';
import { parseRoute, routeHref } from '@/admin/utils/route';

import {
	AWAITING,
	COMPLETED,
	CORRUPT_POST,
	EXPIRED,
	FAILED,
	INCOMPATIBLE,
	unavailable,
} from './fixtures';

const MINUS = '−';

describe( 'formatBytes', () => {
	it.each( [
		[ 0, '0 B' ],
		[ 1, '1 B' ],
		[ 512, '512 B' ],
		[ 1023, '1,023 B' ],
		[ 1024, '1 KB' ],
		[ 1536, '1.5 KB' ],
		[ 2048, '2 KB' ],
		[ 30459, '29.7 KB' ],
		[ 1048575, '1 MB' ],
		[ 1048576, '1 MB' ],
		[ 2411724, '2.3 MB' ],
		[ 1073741824, '1 GB' ],
	] )( '%d → %s', ( bytes, expected ) => {
		expect( formatBytes( bytes, 'en-US' ) ).toBe( expected );
	} );
} );

describe( 'formatBytesDelta', () => {
	it.each( [
		[ 0, '0 B' ],
		[ 31, '+31 B' ],
		[ 2048, '+2 KB' ],
		[ -2048, `${ MINUS }2 KB` ],
		[ -2009, `${ MINUS }2 KB` ],
		[ -3, `${ MINUS }3 B` ],
	] )( '%d → %s', ( delta, expected ) => {
		expect( formatBytesDelta( delta, 'en-US' ) ).toBe( expected );
	} );
} );

describe( 'counts', () => {
	it( 'formats counts and signed deltas', () => {
		expect( formatCount( 12345, 'en-US' ) ).toBe( '12,345' );
		expect( formatCountDelta( 2, 'en-US' ) ).toBe( '+2' );
		expect( formatCountDelta( -1, 'en-US' ) ).toBe( `${ MINUS }1` );
		expect( formatCountDelta( 0, 'en-US' ) ).toBe( '0' );
	} );
} );

describe( 'formatDateTime', () => {
	it( 'formats UTC ISO timestamps in the given time zone', () => {
		expect(
			formatDateTime( '2026-10-05T18:46:09Z', {
				locale: 'en-US',
				timeZone: 'UTC',
			} )
		).toMatch( /^Oct 5, 2026, 6:46\sPM$/ );
		expect(
			formatDateTime( '2026-10-05T18:46:09Z', {
				locale: 'en-US',
				timeZone: 'Asia/Yerevan',
			} )
		).toMatch( /^Oct 5, 2026, 10:46\sPM$/ );
	} );

	it( 'returns null for missing or invalid timestamps', () => {
		expect( formatDateTime( null ) ).toBeNull();
		expect( formatDateTime( '' ) ).toBeNull();
		expect( formatDateTime( 'not a date' ) ).toBeNull();
	} );
} );

describe( 'labels', () => {
	it.each( [
		[ 'captured', 'Updating', 'progress' ],
		[ 'awaiting_settle', 'Observing', 'progress' ],
		[ 'completed', 'Completed', 'positive' ],
		[ 'failed', 'Failed', 'negative' ],
		[ 'incompatible', 'Incompatible', 'caution' ],
		[ 'abandoned', 'Incomplete', 'neutral' ],
		[ 'unknown', 'Unknown', 'neutral' ],
		[ 'something_new', 'Unknown', 'neutral' ],
	] )( 'status %s → %s', ( status, label, tone ) => {
		expect( statusLabel( status ) ).toBe( label );
		expect( statusTone( status ) ).toBe( tone );
	} );

	it( 'names phases observationally', () => {
		expect( phaseLabel( 'during_update' ) ).toBe( 'During update' );
		expect( phaseLabel( 'post_update' ) ).toBe( 'After update' );
		expect( phaseLabel( 'final' ) ).toBe( 'Net result' );
		expect( phaseNote( 'post_update' ) ).toContain(
			'Other site activity may also contribute.'
		);
		for ( const phase of [
			'during_update',
			'post_update',
			'final',
		] as const ) {
			expect( phaseNote( phase ) ).not.toMatch( /caus|created by/i );
		}
	} );

	it.each( [
		[ 'settle_expired', 'within the 5-minute observation window' ],
		[ 'update_failed', 'the plugin update failed' ],
		[ 'fingerprint_context_changed', 'fingerprint context changed' ],
		[ 'awaiting_settle', 'Waiting for the first eligible admin request' ],
		[ 'data_corrupt', "couldn't be read safely" ],
		[ 'update_in_progress', "hasn't reported back" ],
		[ 'analysis_failed', "couldn't complete the analysis" ],
		[ 'analysis_abandoned', "didn't finish" ],
		[ 'not_recorded', 'No data was recorded' ],
		[ 'future_reason', 'No data was recorded' ],
	] )( 'reason %s', ( reason, text ) => {
		expect( unavailableReasonText( reason ).description ).toContain( text );
		expect( unavailableReasonText( reason ).description ).not.toContain(
			reason
		);
	} );

	it( 'explains settle outcomes and errors', () => {
		expect( settleOutcomeText( 'admin_shutdown' ) ).toBe(
			'Observation completed on the next admin request.'
		);
		expect( settleOutcomeText( 'next_update' ) ).toContain(
			'before another plugin update began'
		);
		expect( settleOutcomeText( 'expired' ) ).toContain( '5 minutes' );
		expect( settleOutcomeText( 'not_applicable' ) ).toBeNull();
		expect( settleOutcomeText( null ) ).toBeNull();

		expect( errorText( 'update_not_completed', 'failed' ) ).toBe(
			'The plugin update did not complete.'
		);
		expect( errorText( 'incompatible_archive', 'failed' ) ).toBe(
			'The plugin update failed.'
		);
		expect( errorText( 'stale', 'abandoned' ) ).toContain(
			'never reported completion'
		);
	} );

	it( 'summarizes report states', () => {
		expect( reportNotice( COMPLETED ) ).toMatchObject( {
			title: 'Analysis completed',
			details: [ 'Observation completed on the next admin request.' ],
			open: false,
		} );
		expect( reportNotice( EXPIRED ).title ).toBe(
			'Post-update observation expired'
		);
		expect( reportNotice( FAILED ) ).toMatchObject( {
			title: 'Plugin update failed',
			details: [ 'The plugin update failed.' ],
			tone: 'negative',
		} );
		expect(
			reportNotice( { ...FAILED, error: { code: 'analysis_error' } } )
				.title
		).toBe( "Analysis couldn't be completed" );
		expect( reportNotice( INCOMPATIBLE ).description ).toContain(
			'fingerprint context changed'
		);
		expect( reportNotice( AWAITING ) ).toMatchObject( {
			title: 'Observing post-update activity…',
			open: true,
		} );
		expect(
			reportNotice( { ...COMPLETED, status: 'unknown' } ).title
		).toBe( 'Unknown analysis state' );
	} );
} );

describe( 'defaultPhase', () => {
	it( 'prefers Net result, then During update, then After update', () => {
		expect( defaultPhase( COMPLETED.phases ) ).toBe( 'final' );
		expect( defaultPhase( EXPIRED.phases ) ).toBe( 'during_update' );
		expect( defaultPhase( CORRUPT_POST.phases ) ).toBe( 'final' );
		expect(
			defaultPhase( {
				...COMPLETED.phases,
				during_update: unavailable( 'update_request', 'data_corrupt' ),
				final: unavailable( 'net_across_phases', 'data_corrupt' ),
			} )
		).toBe( 'post_update' );
		expect( defaultPhase( FAILED.phases ) ).toBeNull();
	} );
} );

describe( 'routes', () => {
	const base = 'https://example.test/wp-admin/tools.php?page=updatelens';

	it( 'parses history and report URLs', () => {
		expect( parseRoute( '?page=updatelens' ) ).toEqual( {
			view: 'history',
			page: 1,
		} );
		expect( parseRoute( '?page=updatelens&paged=3' ) ).toEqual( {
			view: 'history',
			page: 3,
		} );
		expect( parseRoute( '?page=updatelens&analysis=42' ) ).toEqual( {
			view: 'report',
			id: 42,
			page: 1,
		} );
	} );

	it.each( [ 'abc', '0', '-1', '1.5', '99999999999999999999' ] )(
		'ignores invalid analysis %s',
		( value ) => {
			expect(
				parseRoute( `?page=updatelens&analysis=${ value }` )
			).toEqual( { view: 'history', page: 1 } );
		}
	);

	it( 'builds URLs that keep the admin page', () => {
		expect( routeHref( { view: 'report', id: 42, page: 1 }, base ) ).toBe(
			'/wp-admin/tools.php?page=updatelens&analysis=42'
		);
		expect(
			routeHref( { view: 'history', page: 2 }, `${ base }&analysis=42` )
		).toBe( '/wp-admin/tools.php?page=updatelens&paged=2' );
		expect(
			routeHref(
				{ view: 'history', page: 1 },
				`${ base }&analysis=42&paged=2`
			)
		).toBe( '/wp-admin/tools.php?page=updatelens' );
	} );
} );
