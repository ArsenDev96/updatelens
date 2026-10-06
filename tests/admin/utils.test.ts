import { setLocaleData } from '@wordpress/i18n';
import { describe, expect, it } from 'vitest';

import {
	formatBytes,
	formatBytesDelta,
	formatCount,
	formatCountDelta,
	formatDateTime,
	formatDuration,
	formatDurationDelta,
	formatUnixDateTime,
	unixToIso,
} from '@/admin/utils/format';
import {
	cronPhaseNote,
	cronUnavailableReasonText,
	defaultPhase,
	defaultProvider,
	errorText,
	phaseLabel,
	phaseNote,
	providerLabel,
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
	CRON_ONLY,
	cronUnavailable,
	EXPIRED,
	FAILED,
	INCOMPATIBLE,
	MALFORMED_CRON,
	T,
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
		[ 'settle_expired', 'within 5 minutes of the update' ],
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
		expect( unavailableReasonText( reason, 300 ).description ).toContain(
			text
		);
		expect(
			unavailableReasonText( reason, 300 ).description
		).not.toContain( reason );
	} );

	it( 'explains settle outcomes and errors', () => {
		expect( settleOutcomeText( 'admin_shutdown', 300 ) ).toBe(
			'Observation completed on the next admin request.'
		);
		expect( settleOutcomeText( 'next_update', 300 ) ).toContain(
			'before another plugin update began'
		);
		expect( settleOutcomeText( 'expired', 300 ) ).toContain( '5 minutes' );
		expect( settleOutcomeText( 'expired', 600 ) ).toContain( '10 minutes' );
		expect( settleOutcomeText( 'not_applicable', 300 ) ).toBeNull();
		expect( settleOutcomeText( null, 300 ) ).toBeNull();

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
				during_update: {
					options: unavailable( 'update_request', 'data_corrupt' ),
					cron: cronUnavailable( 'update_request', 'data_corrupt' ),
				},
				final: {
					options: unavailable( 'net_across_phases', 'data_corrupt' ),
					cron: cronUnavailable(
						'net_across_phases',
						'data_corrupt'
					),
				},
			} )
		).toBe( 'post_update' );
		// A phase with only WP-Cron available still counts.
		expect( defaultPhase( CRON_ONLY.phases ) ).toBe( 'final' );
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

describe( 'durations', () => {
	it.each( [
		[ 1, '1 second' ],
		[ 45, '45 seconds' ],
		[ 60, '1 minute' ],
		[ 120, '2 minutes' ],
		[ 300, '5 minutes' ],
		[ 3600, '1 hour' ],
		[ 5400, '1.5 hours' ],
		[ 7200, '2 hours' ],
		[ 43200, '12 hours' ],
		[ 86400, '1 day' ],
		[ 172800, '2 days' ],
		[ 604800, '7 days' ],
		[ 3601, '1 hour' ],
	] )( '%d seconds → %s', ( seconds, text ) => {
		expect( formatDuration( seconds, 'en' ) ).toBe( text );
	} );

	it( 'signs deltas with a true minus and omits no movement', () => {
		expect( formatDurationDelta( 3600, 'en' ) ).toBe( '+1 hour' );
		expect( formatDurationDelta( -1800, 'en' ) ).toBe(
			`${ MINUS }30 minutes`
		);
		expect( formatDurationDelta( 172800, 'en' ) ).toBe( '+2 days' );
		expect( formatDurationDelta( 0, 'en' ) ).toBeNull();
	} );

	it( 'takes unit words from UpdateLens translations, not the number locale', () => {
		// German number formatting, untranslated (English) UI.
		expect( formatDuration( 5400, 'de' ) ).toBe( '1,5 hours' );
		expect( formatDuration( 300, 'hy-AM' ) ).toBe( '5 minutes' );
		// Default locale (the host's): the words stay English.
		expect( formatDuration( 300 ) ).toBe( '5 minutes' );
	} );

	it( 'uses translated unit words with their plural forms', () => {
		setLocaleData(
			{
				'': {
					domain: 'updatelens',
					plural_forms: 'nplurals=2; plural=(n != 1);',
				},
				'%s second': [ '%s xsec', '%s xsecs' ],
				'%s minute': [ '%s xmin', '%s xmins' ],
				'%s hour': [ '%s xhour', '%s xhours' ],
				'%s day': [ '%s xday', '%s xdays' ],
			},
			'updatelens'
		);

		expect( formatDuration( 1, 'en' ) ).toBe( '1 xsec' );
		expect( formatDuration( 60, 'en' ) ).toBe( '1 xmin' );
		expect( formatDuration( 300, 'en' ) ).toBe( '5 xmins' );
		expect( formatDuration( 3600, 'en' ) ).toBe( '1 xhour' );
		expect( formatDuration( 5400, 'en' ) ).toBe( '1.5 xhours' );
		expect( formatDuration( 86400, 'en' ) ).toBe( '1 xday' );
		expect( formatDuration( 604800, 'en' ) ).toBe( '7 xdays' );
		expect( formatDurationDelta( -1800, 'en' ) ).toBe(
			`${ MINUS }30 xmins`
		);
		expect( formatDuration( 300, 'hy-AM' ) ).toBe( '5 xmins' );
		// Observation-window copy goes through the same formatter.
		expect(
			unavailableReasonText( 'settle_expired', 300 ).description
		).toContain( 'within 5 xmins of the update' );
		expect(
			cronUnavailableReasonText( 'settle_expired', 300 ).description
		).toContain( 'within 5 xmins of the update' );
	} );

	it( 'renders the observation window from the API value in English', () => {
		expect(
			unavailableReasonText( 'settle_expired', 300 ).description
		).toContain( 'within 5 minutes of the update' );
		expect(
			unavailableReasonText( 'settle_expired', 600 ).description
		).toContain( 'within 10 minutes of the update' );
	} );

	it( 'formats Unix timestamps like API timestamps', () => {
		expect(
			formatUnixDateTime( T, { locale: 'en-US', timeZone: 'UTC' } )
		).toBe(
			formatDateTime( '2026-10-06T10:00:00Z', {
				locale: 'en-US',
				timeZone: 'UTC',
			} )
		);
		expect( unixToIso( T ) ).toBe( '2026-10-06T10:00:00Z' );
	} );
} );

describe( 'WP-Cron labels', () => {
	it( 'names the signals', () => {
		expect( providerLabel( 'options' ) ).toBe( 'Options' );
		expect( providerLabel( 'cron' ) ).toBe( 'WP-Cron' );
	} );

	it( 'defaults to Options unless only WP-Cron is available', () => {
		expect( defaultProvider( COMPLETED.phases.final ) ).toBe( 'options' );
		expect( defaultProvider( MALFORMED_CRON.phases.final ) ).toBe(
			'options'
		);
		expect( defaultProvider( CRON_ONLY.phases.final ) ).toBe( 'cron' );
		expect( defaultProvider( FAILED.phases.final ) ).toBe( 'options' );
	} );

	it.each( [
		[ 'malformed_cron_state', 'could not safely normalize' ],
		[ 'fingerprint_context_changed', "site's fingerprint context changed" ],
		[ 'settle_expired', 'not captured within 5 minutes of the update' ],
		[ 'storage_failed', 'could not be stored safely' ],
		[ 'not_captured', 'WP-Cron was not captured for this phase.' ],
		[ 'update_failed', 'because the plugin update failed' ],
		[ 'snapshot_unavailable', "couldn't capture or read" ],
		[ 'analysis_failed', "couldn't compare" ],
		[ 'analysis_abandoned', "didn't finish" ],
		[ 'analysis_ended', 'stopped early' ],
		[ 'update_in_progress', "hasn't reported back" ],
		[ 'awaiting_settle', 'Waiting for the first eligible admin request' ],
		[ 'data_corrupt', "couldn't be read safely" ],
		[ 'not_recorded', 'No WP-Cron data was recorded' ],
		[ 'unknown', 'No WP-Cron data was recorded' ],
	] )( 'reason %s', ( reason, text ) => {
		const { title, description } = cronUnavailableReasonText( reason, 300 );
		expect( description ).toContain( text );
		expect( `${ title } ${ description }` ).not.toContain( reason );
	} );

	it( 'keeps WP-Cron wording observational', () => {
		expect( cronPhaseNote() ).toContain(
			'WordPress core and other plugins may also schedule or reschedule jobs'
		);
		expect( cronPhaseNote() ).not.toMatch( /caus|created by/i );
	} );
} );
