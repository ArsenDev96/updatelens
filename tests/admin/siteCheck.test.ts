import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import shared from '../fixtures/site-check-attempts.json';
import {
	MAX_HOME_BYTES,
	parseStartResponse,
	type AttemptFacts,
	type CheckId,
} from '../../src/admin/site-check/contract';
import {
	attemptPassed,
	classifyRestBody,
	scanEndMarker,
	targetProblem,
} from '../../src/admin/site-check/observe';
import {
	runSiteChecks,
	type RunResult,
} from '../../src/admin/site-check/runner';

const ORIGIN = 'https://example.test';
const LOCATION = { origin: ORIGIN, protocol: 'https:' };
const AJAX = `${ ORIGIN }/wp-admin/admin-ajax.php`;
const SETTINGS = { ajaxUrl: AJAX, nonce: 'n0nce' };
const HOME = 'a1'.repeat( 16 );
const REST = 'b2'.repeat( 16 );
const HOME_SECRET = 'c3'.repeat( 32 );
const REST_SECRET = 'd4'.repeat( 32 );
const HOME_URL = `${ ORIGIN }/?updatelens_check=${ HOME }`;
const REST_URL = `${ ORIGIN }/wp-json/wp/v2/users/me?_fields=id`;
const USER = 7;

/** A start response's `data`. */
function startData( overrides: Record< string, unknown > = {} ) {
	return {
		code: 'started',
		purpose: 'before',
		expires_in: 120,
		timeout_ms: 10000,
		retry_delay_ms: 5000,
		max_attempts: 2,
		header: 'X-UpdateLens-Check',
		end_marker_attribute: 'data-updatelens-check',
		checks: [
			{
				check: 'home',
				definition: 1,
				session: HOME,
				secret: HOME_SECRET,
				url: HOME_URL,
			},
			{
				check: 'rest',
				definition: 1,
				session: REST,
				secret: REST_SECRET,
				url: REST_URL,
				rest_nonce: 'abc123',
				user: USER,
			},
		],
		...overrides,
	};
}

const ajax = ( data: unknown, status = 200, success = status === 200 ) =>
	new Response( JSON.stringify( { success, data } ), {
		status,
		headers: { 'Content-Type': 'application/json' },
	} );

/** A homepage response. */
function page(
	options: {
		status?: number;
		header?: string | null;
		end?: string | null;
		headers?: Record< string, string >;
		body?: string;
	} = {}
) {
	const { status = 200, header = HOME, end = HOME, headers = {} } = options;
	const body =
		options.body ??
		`<!doctype html><html><body><p>Hello</p>${
			end ? `<span hidden data-updatelens-check="${ end }"></span>` : ''
		}</body></html>`;
	return new Response( body, {
		status,
		headers: {
			'Content-Type': 'text/html',
			...( header ? { 'X-UpdateLens-Check': header } : {} ),
			...headers,
		},
	} );
}

/** A REST response. */
function rest(
	body: unknown = { id: USER },
	options: { status?: number; header?: string | null } = {}
) {
	const { status = 200, header = REST } = options;
	return new Response(
		typeof body === 'string' ? body : JSON.stringify( body ),
		{
			status,
			headers: {
				'Content-Type': 'application/json',
				...( header ? { 'X-UpdateLens-Check': header } : {} ),
			},
		}
	);
}

/** A request that never answers until aborted. */
const hang = ( init?: RequestInit ) =>
	new Promise< Response >( ( _resolve, reject ) => {
		init?.signal?.addEventListener( 'abort', () =>
			reject(
				new DOMException( 'The operation was aborted.', 'AbortError' )
			)
		);
	} );

type Handler = ( init?: RequestInit ) => Response | Promise< Response >;

/**
 * A scripted server. Homepage and REST handlers are used in order, one per
 * attempt (the last one repeats). Reports are answered with a verdict from
 * the runner's own pass rule unless `report` is given.
 */
function server(
	options: {
		start?: Handler;
		home?: Handler[];
		rest?: Handler[];
		report?: (
			check: CheckId,
			attempts: AttemptFacts[]
		) => Response | Promise< Response >;
	} = {}
) {
	const calls: { url: string; init?: RequestInit }[] = [];
	const reports: {
		fields: Record< string, string >;
		report: { check: CheckId; attempts: AttemptFacts[] };
	}[] = [];
	const counts = { home: 0, rest: 0 };
	const next = (
		list: Handler[],
		key: 'home' | 'rest',
		init?: RequestInit
	) => {
		const handler = list[ Math.min( counts[ key ], list.length - 1 ) ];
		counts[ key ]++;
		return handler( init );
	};
	const fetch = vi.fn(
		async ( input: RequestInfo | URL, init?: RequestInit ) => {
			const url = String( input );
			calls.push( { url, init } );
			if ( url === AJAX ) {
				const fields = Object.fromEntries(
					new URLSearchParams( String( init?.body ) )
				);
				if ( fields.action === 'updatelens_site_check_start' ) {
					return options.start
						? options.start( init )
						: ajax( startData() );
				}
				const report = JSON.parse( fields.report );
				reports.push( { fields, report } );
				if ( options.report ) {
					return options.report( report.check, report.attempts );
				}
				const last = report.attempts[ report.attempts.length - 1 ];
				return ajax( {
					code: 'accepted',
					check: report.check,
					purpose: 'before',
					verdict: attemptPassed( report.check, last, USER )
						? 'pass'
						: 'fail',
					reason: null,
				} );
			}
			if ( url === HOME_URL ) {
				return next( options.home ?? [ () => page() ], 'home', init );
			}
			if ( url === REST_URL ) {
				return next( options.rest ?? [ () => rest() ], 'rest', init );
			}
			throw new TypeError( `Unexpected request ${ url }` );
		}
	);

	return {
		fetch: fetch as unknown as typeof globalThis.fetch,
		calls,
		reports,
		counts,
		spy: fetch,
	};
}

/** Run with the scripted server, letting fake timers run. */
async function run(
	srv: ReturnType< typeof server >,
	options: {
		signal?: AbortSignal;
		settings?: typeof SETTINGS;
		location?: typeof LOCATION;
	} = {}
): Promise< RunResult > {
	const promise = runSiteChecks( options.settings ?? SETTINGS, {
		purpose: 'before',
		fetch: srv.fetch,
		now: () => Date.now(),
		location: options.location ?? LOCATION,
		signal: options.signal,
	} );
	await vi.advanceTimersByTimeAsync( 60000 );
	return promise;
}

const reportOf = ( srv: ReturnType< typeof server >, check: CheckId ) =>
	srv.reports.find( ( entry ) => entry.report.check === check );

beforeEach( () => {
	vi.useFakeTimers( { toFake: [ 'setTimeout', 'clearTimeout', 'Date' ] } );
} );

afterEach( () => {
	vi.useRealTimers();
} );

describe( 'runSiteChecks: successful checks', () => {
	it( 'checks the homepage and the REST API once each and reports PHP’s verdicts', async () => {
		const srv = server();
		const result = await run( srv );

		expect( result.status ).toBe( 'started' );
		expect( result.checks.home ).toEqual( {
			check: 'home',
			status: 'reported',
			verdict: 'pass',
			reason: null,
			attempts: [
				{
					kind: 'response',
					status: 200,
					duration_ms: 0,
					header: 'current',
					end_marker: 'current',
					retry_after: false,
					challenge: false,
				},
			],
		} );
		expect( result.checks.rest ).toMatchObject( {
			status: 'reported',
			verdict: 'pass',
		} );
		expect( reportOf( srv, 'rest' )!.report.attempts ).toEqual( [
			{
				kind: 'response',
				status: 200,
				duration_ms: 0,
				header: 'current',
				retry_after: false,
				challenge: false,
				body: 'json_object',
				user_id: USER,
				error_code: null,
			},
		] );
		expect( srv.counts ).toEqual( { home: 1, rest: 1 } );
	} );

	it( 'requests exactly the server-issued URLs with the required options', async () => {
		const srv = server();
		await run( srv );

		const home = srv.calls.find( ( call ) => call.url === HOME_URL )!;
		expect( home.init ).toMatchObject( {
			method: 'GET',
			credentials: 'omit',
			cache: 'no-store',
			redirect: 'manual',
		} );
		const restCall = srv.calls.find( ( call ) => call.url === REST_URL )!;
		expect( restCall.init ).toMatchObject( {
			credentials: 'same-origin',
			cache: 'no-store',
			redirect: 'manual',
			headers: { 'X-WP-Nonce': 'abc123', 'X-UpdateLens-Check': REST },
		} );
		for ( const call of srv.calls.filter(
			( entry ) => entry.url === AJAX
		) ) {
			expect( call.init ).toMatchObject( {
				method: 'POST',
				credentials: 'same-origin',
				cache: 'no-store',
			} );
		}
		expect( srv.calls.map( ( call ) => call.url ).sort() ).toEqual(
			[ AJAX, AJAX, AJAX, HOME_URL, REST_URL ].sort()
		);
	} );

	it( 'sends each report once, with the secret only in the POST body and no content', async () => {
		const srv = server();
		await run( srv );

		const fields = reportOf( srv, 'home' )!.fields;
		expect( Object.keys( fields ).sort() ).toEqual(
			[ '_ajax_nonce', 'action', 'report', 'secret', 'session' ].sort()
		);
		expect( fields ).toMatchObject( {
			action: 'updatelens_site_check_report',
			_ajax_nonce: 'n0nce',
			session: HOME,
			secret: HOME_SECRET,
		} );
		expect( fields.report.length ).toBeLessThan( 2048 );
		expect( fields.report ).not.toContain( 'Hello' );
		expect( fields.report ).not.toContain( HOME_SECRET );
		for ( const call of srv.calls ) {
			expect( call.url ).not.toContain( HOME_SECRET );
			expect( call.url ).not.toContain( REST_SECRET );
		}
		expect( srv.reports ).toHaveLength( 2 );
	} );
} );

describe( 'runSiteChecks: homepage observations', () => {
	const homeFacts = async ( handlers: Handler[] ) => {
		const srv = server( { home: handlers } );
		const result = await run( srv );
		return {
			result,
			srv,
			attempts: reportOf( srv, 'home' )!.report.attempts,
		};
	};

	it( 'reports an HTTP 500 twice (confirmed) and still reports REST', async () => {
		const { result, attempts } = await homeFacts( [
			() => page( { status: 500, end: null } ),
		] );

		expect( attempts ).toHaveLength( 2 );
		expect( attempts[ 0 ] ).toMatchObject( {
			kind: 'response',
			status: 500,
			header: 'current',
			end_marker: 'absent',
		} );
		expect( result.checks.home ).toMatchObject( {
			status: 'reported',
			verdict: 'fail',
		} );
		expect( result.checks.rest ).toMatchObject( {
			status: 'reported',
			verdict: 'pass',
		} );
	} );

	it( 'reports a page without the end-of-page marker as absent', async () => {
		const { attempts } = await homeFacts( [ () => page( { end: null } ) ] );
		expect(
			attempts.map( ( a ) => ( a as { end_marker: string } ).end_marker )
		).toEqual( [ 'absent', 'absent' ] );
	} );

	it( 'never counts another session’s markers', async () => {
		const stale = 'ff'.repeat( 16 );
		const { attempts } = await homeFacts( [
			() => page( { header: stale, end: stale } ),
		] );
		expect( attempts ).toHaveLength( 2 );
		expect( attempts[ 0 ] ).toMatchObject( {
			header: 'other',
			end_marker: 'other',
		} );
	} );

	it( 'treats a cached 200 without markers as unverified, not as a pass', async () => {
		const { attempts } = await homeFacts( [
			() => page( { header: null, end: null } ),
		] );
		expect( attempts ).toHaveLength( 2 );
		expect( attempts[ 0 ] ).toMatchObject( {
			status: 200,
			header: 'absent',
			end_marker: 'absent',
		} );
	} );

	it( 'reports redirects without following them', async () => {
		const { attempts } = await homeFacts( [
			() =>
				new Response( null, {
					status: 302,
					headers: { Location: `${ ORIGIN }/en/` },
				} ),
		] );
		expect( attempts ).toEqual( [
			{ kind: 'redirect', duration_ms: 0 },
			{ kind: 'redirect', duration_ms: 0 },
		] );
	} );

	it( 'recognises a Cloudflare challenge', async () => {
		const { attempts } = await homeFacts( [
			() =>
				page( {
					status: 403,
					header: null,
					end: null,
					headers: { 'cf-mitigated': 'challenge' },
				} ),
		] );
		expect( attempts[ 0 ] ).toMatchObject( {
			status: 403,
			challenge: true,
		} );
	} );

	it( 'notes Retry-After (maintenance)', async () => {
		const { attempts } = await homeFacts( [
			() =>
				page( {
					status: 503,
					header: null,
					end: null,
					headers: { 'Retry-After': '600' },
				} ),
		] );
		expect( attempts[ 0 ] ).toMatchObject( {
			status: 503,
			retry_after: true,
		} );
	} );

	it( 'finds a marker split across chunks and in minified HTML', async () => {
		const tag = `<span hidden data-updatelens-check="${ HOME }"></span>`;
		const split = new ReadableStream< Uint8Array >( {
			start( controller ) {
				const bytes = new TextEncoder().encode(
					`<html>${ tag }</html>`
				);
				controller.enqueue( bytes.slice( 0, 20 ) );
				controller.enqueue( bytes.slice( 20, 41 ) );
				controller.enqueue( bytes.slice( 41 ) );
				controller.close();
			},
		} );
		expect( await scanEndMarker( split, HOME ) ).toBe( 'current' );
		expect(
			await scanEndMarker(
				new Response(
					`<span hidden data-updatelens-check=${ HOME }></span>`
				).body,
				HOME
			)
		).toBe( 'current' );
		expect( await scanEndMarker( null, HOME ) ).toBe( 'absent' );
	} );

	it( 'does not inspect a page larger than the limit (unread, never failed) and keeps no content', async () => {
		let produced = 0;
		const huge = () =>
			new Response(
				new ReadableStream< Uint8Array >( {
					pull( controller ) {
						const chunk = new Uint8Array( 256 * 1024 ).fill( 0x20 );
						produced += chunk.byteLength;
						controller.enqueue( chunk );
						if ( produced > 20 * MAX_HOME_BYTES ) {
							controller.close();
						}
					},
				} ),
				{ headers: { 'X-UpdateLens-Check': HOME } }
			);
		const { attempts } = await homeFacts( [ huge ] );

		expect( attempts[ 0 ] ).toMatchObject( {
			status: 200,
			header: 'current',
			end_marker: 'unread',
		} );
		// Per attempt: the limit plus the stream's read-ahead of a few chunks.
		expect( produced ).toBeLessThanOrEqual(
			2 * ( MAX_HOME_BYTES + 3 * 256 * 1024 )
		);
	} );

	it( 'reports a timeout after 10 seconds per attempt, then retries once', async () => {
		const srv = server( { home: [ hang ] } );
		const promise = runSiteChecks( SETTINGS, {
			purpose: 'before',
			fetch: srv.fetch,
			now: () => Date.now(),
			location: LOCATION,
		} );
		await vi.advanceTimersByTimeAsync( 9999 );
		expect( srv.counts.home ).toBe( 1 );
		await vi.advanceTimersByTimeAsync( 1 + 4999 );
		expect( srv.counts.home ).toBe( 1 );
		await vi.advanceTimersByTimeAsync( 1 );
		expect( srv.counts.home ).toBe( 2 );
		await vi.advanceTimersByTimeAsync( 10000 );
		const result = await promise;

		expect( reportOf( srv, 'home' )!.report.attempts ).toEqual( [
			{ kind: 'timeout', duration_ms: 10000 },
			{ kind: 'timeout', duration_ms: 10000 },
		] );
		expect( result.checks.rest ).toMatchObject( {
			status: 'reported',
			verdict: 'pass',
		} );
	} );

	it( 'distinguishes a network error from a timeout', async () => {
		const { attempts } = await homeFacts( [
			() => {
				throw new TypeError( 'Failed to fetch' );
			},
		] );
		expect( attempts ).toEqual( [
			{ kind: 'network_error', duration_ms: 0 },
			{ kind: 'network_error', duration_ms: 0 },
		] );
	} );

	it( 'treats a body that breaks off as a network error', async () => {
		const broken = () =>
			new Response(
				new ReadableStream< Uint8Array >( {
					pull( controller ) {
						controller.error( new TypeError( 'network error' ) );
					},
				} ),
				{ headers: { 'X-UpdateLens-Check': HOME } }
			);
		const { attempts } = await homeFacts( [ broken ] );
		expect( attempts[ 0 ] ).toEqual( {
			kind: 'network_error',
			duration_ms: 0,
		} );
	} );

	it( 'retries once after a failure and stops when the retry passes', async () => {
		// PHP never turns a failure followed by a pass into a pass.
		const srv = server( {
			home: [ () => page( { status: 500, end: null } ), () => page() ],
			report: ( check ) =>
				ajax( {
					code: 'accepted',
					check,
					purpose: 'before',
					verdict: check === 'home' ? 'inconclusive' : 'pass',
					reason: check === 'home' ? 'unconfirmed' : null,
				} ),
		} );
		const result = await run( srv );
		const attempts = reportOf( srv, 'home' )!.report.attempts;

		expect(
			attempts.map( ( a ) => ( a as { status: number } ).status )
		).toEqual( [ 500, 200 ] );
		expect( srv.counts.home ).toBe( 2 );
		expect( result.checks.home ).toMatchObject( {
			status: 'reported',
			verdict: 'inconclusive',
			reason: 'unconfirmed',
		} );
	} );

	it( 'waits 5 seconds before the retry', async () => {
		const srv = server( {
			home: [ () => page( { status: 500, end: null } ), () => page() ],
		} );
		const promise = runSiteChecks( SETTINGS, {
			purpose: 'before',
			fetch: srv.fetch,
			location: LOCATION,
		} );
		await vi.advanceTimersByTimeAsync( 4999 );
		expect( srv.counts.home ).toBe( 1 );
		await vi.advanceTimersByTimeAsync( 1 );
		expect( srv.counts.home ).toBe( 2 );
		await vi.advanceTimersByTimeAsync( 1000 );
		await promise;
	} );
} );

describe( 'runSiteChecks: REST observations', () => {
	const restFacts = async ( handlers: Handler[] ) => {
		const srv = server( { rest: handlers } );
		const result = await run( srv );
		return {
			result,
			srv,
			attempts: reportOf( srv, 'rest' )!.report.attempts,
		};
	};

	it( 'reports an authentication failure with WordPress’s error code', async () => {
		const { attempts } = await restFacts( [
			() =>
				rest(
					{
						code: 'rest_not_logged_in',
						message: 'You are not currently logged in.',
						data: { status: 401 },
					},
					{ status: 401 }
				),
		] );
		expect( attempts[ 0 ] ).toMatchObject( {
			status: 401,
			header: 'current',
			body: 'json_object',
			user_id: null,
			error_code: 'rest_not_logged_in',
		} );
		expect( JSON.stringify( attempts ) ).not.toContain(
			'currently logged in'
		);
	} );

	it( 'reports invalid JSON', async () => {
		const { attempts } = await restFacts( [
			() => rest( '<html>Fatal error</html>', { status: 200 } ),
		] );
		expect( attempts[ 0 ] ).toMatchObject( {
			body: 'invalid_json',
			user_id: null,
			error_code: null,
		} );
		expect( JSON.stringify( attempts ) ).not.toContain( 'Fatal' );
	} );

	it( 'reports JSON after other output (PHP notices)', async () => {
		const { attempts } = await restFacts( [
			() =>
				rest(
					`<br />\n<b>Notice</b>: Undefined index in /srv/x.php on line 3<br />\n{"id":${ USER }}`
				),
		] );
		expect( attempts[ 0 ] ).toMatchObject( {
			body: 'json_after_output',
			user_id: null,
		} );
		expect( JSON.stringify( attempts ) ).not.toContain( '/srv/' );
	} );

	it( 'never accepts another user, or the right id without the verification header', async () => {
		const wrong = await restFacts( [ () => rest( { id: 1 } ) ] );
		expect( wrong.attempts ).toHaveLength( 2 );
		expect( wrong.attempts[ 0 ] ).toMatchObject( { user_id: 1 } );

		const unverified = await restFacts( [
			() => rest( { id: USER }, { header: null } ),
		] );
		expect( unverified.attempts ).toHaveLength( 2 );
		expect( unverified.attempts[ 0 ] ).toMatchObject( {
			header: 'absent',
			user_id: USER,
		} );

		const string = await restFacts( [
			() => rest( { id: String( USER ) } ),
		] );
		expect( string.attempts[ 0 ] ).toMatchObject( {
			body: 'json_object',
			user_id: null,
		} );
	} );

	it( 'reports a login redirect', async () => {
		const { attempts } = await restFacts( [
			() =>
				new Response( null, {
					status: 302,
					headers: { Location: `${ ORIGIN }/wp-login.php` },
				} ),
		] );
		expect( attempts[ 0 ] ).toEqual( { kind: 'redirect', duration_ms: 0 } );
	} );

	it( 'reports a REST timeout without affecting the homepage', async () => {
		const { result, attempts } = await restFacts( [ hang ] );
		expect( attempts ).toEqual( [
			{ kind: 'timeout', duration_ms: 10000 },
			{ kind: 'timeout', duration_ms: 10000 },
		] );
		expect( result.checks.home ).toMatchObject( {
			status: 'reported',
			verdict: 'pass',
		} );
		expect( result.checks.rest ).toMatchObject( {
			status: 'reported',
			verdict: 'fail',
		} );
	} );

	it( 'classifies REST bodies', () => {
		const none = { user_id: null, error_code: null };
		expect( classifyRestBody( '' ) ).toEqual( { body: 'empty', ...none } );
		expect( classifyRestBody( null ) ).toEqual( {
			body: 'unread',
			...none,
		} );
		expect( classifyRestBody( '[1]' ) ).toEqual( {
			body: 'json_other',
			...none,
		} );
		expect( classifyRestBody( '{"id":7}' ) ).toEqual( {
			body: 'json_object',
			user_id: 7,
			error_code: null,
		} );
		expect( classifyRestBody( '{"id":-1}' ) ).toEqual( {
			body: 'json_object',
			...none,
		} );
		expect( classifyRestBody( '{"id":1.5}' ) ).toEqual( {
			body: 'json_object',
			...none,
		} );
		expect(
			classifyRestBody( '{"code":"x","message":"y","id":7}' )
		).toEqual( {
			body: 'json_object',
			user_id: null,
			error_code: 'x',
		} );
		expect(
			classifyRestBody(
				`{"code":"${ 'x'.repeat( 129 ) }","message":"y"}`
			).error_code
		).toBe( 'other' );
		expect( classifyRestBody( 'Warning!{"id":7,"a":{"b":1}}' ).body ).toBe(
			'json_after_output'
		);
		expect( classifyRestBody( '{"id":7' ).body ).toBe( 'invalid_json' );
	} );
} );

describe( 'runSiteChecks: independence', () => {
	it( 'homepage fails while REST succeeds', async () => {
		const srv = server( {
			home: [ () => page( { status: 500, end: null } ) ],
		} );
		const result = await run( srv );
		expect( result.checks.home ).toMatchObject( {
			status: 'reported',
			verdict: 'fail',
		} );
		expect( result.checks.rest ).toMatchObject( {
			status: 'reported',
			verdict: 'pass',
		} );
	} );

	it( 'REST fails while the homepage succeeds', async () => {
		const srv = server( {
			rest: [
				() => {
					throw new TypeError( 'Failed to fetch' );
				},
			],
		} );
		const result = await run( srv );
		expect( result.checks.rest ).toMatchObject( {
			status: 'reported',
			verdict: 'fail',
		} );
		expect( result.checks.home ).toMatchObject( {
			status: 'reported',
			verdict: 'pass',
		} );
	} );

	it( 'a failed report of one check does not affect the other', async () => {
		const srv = server( {
			report: ( check ) =>
				check === 'home'
					? ajax( { code: 'session_unavailable' }, 410, false )
					: ajax( {
							code: 'accepted',
							check,
							purpose: 'before',
							verdict: 'pass',
							reason: null,
						} ),
		} );
		const result = await run( srv );
		expect( result.checks.home ).toMatchObject( {
			status: 'report_failed',
			error: 'session_unavailable',
		} );
		expect( result.checks.rest ).toMatchObject( {
			status: 'reported',
			verdict: 'pass',
		} );
	} );
} );

describe( 'runSiteChecks: reports', () => {
	it.each( [
		[
			'server error code',
			() => ajax( { code: 'already_reported' }, 409, false ),
			'already_reported',
		],
		[
			'HTTP error without JSON',
			() => new Response( 'Internal error', { status: 500 } ),
			'http_500',
		],
		[
			'network error',
			() => {
				throw new TypeError( 'Failed to fetch' );
			},
			'network_error',
		],
		[
			'malformed acceptance',
			() => ajax( { code: 'accepted', check: 'home', verdict: 'great' } ),
			'invalid_response',
		],
		[
			'acceptance for another check',
			() =>
				ajax( {
					code: 'accepted',
					check: 'rest',
					verdict: 'pass',
					reason: null,
				} ),
			'invalid_response',
		],
		[
			'admin-ajax “0”',
			() => new Response( '0', { status: 400 } ),
			'http_400',
		],
	] )(
		'never turns a failed submission into a result: %s',
		async ( _name, respond, error ) => {
			const srv = server( {
				report: ( check ) =>
					check === 'home'
						? respond()
						: ajax( {
								code: 'accepted',
								check,
								purpose: 'before',
								verdict: 'pass',
								reason: null,
							} ),
			} );
			const result = await run( srv );
			expect( result.checks.home ).toMatchObject( {
				status: 'report_failed',
				error,
			} );
			expect(
				( result.checks.home as { attempts: unknown[] } ).attempts
			).toHaveLength( 1 );
		}
	);

	it( 'returns the server’s verdict and reason, not its own', async () => {
		const srv = server( {
			report: ( check ) =>
				ajax( {
					code: 'accepted',
					check,
					purpose: 'before',
					verdict: 'inconclusive',
					reason: 'stale_marker',
				} ),
		} );
		const result = await run( srv );
		expect( result.checks.home ).toMatchObject( {
			status: 'reported',
			verdict: 'inconclusive',
			reason: 'stale_marker',
		} );
	} );
} );

describe( 'runSiteChecks: session expiry', () => {
	it( 'does not start an attempt that cannot be reported before the session ends', async () => {
		const srv = server( {
			start: () => ajax( startData( { expires_in: 10 } ) ),
		} );
		const result = await run( srv );

		expect( result.checks.home ).toEqual( {
			check: 'home',
			status: 'expired',
			attempts: [],
		} );
		expect( srv.counts ).toEqual( { home: 0, rest: 0 } );
		expect( srv.reports ).toHaveLength( 0 );
	} );

	it( 'reports the first attempt when no time is left for the retry', async () => {
		const srv = server( {
			start: () => ajax( startData( { expires_in: 16 } ) ),
			home: [ () => page( { status: 500, end: null } ) ],
		} );
		const result = await run( srv );

		expect( srv.counts.home ).toBe( 1 );
		expect( reportOf( srv, 'home' )!.report.attempts ).toHaveLength( 1 );
		expect( result.checks.home ).toMatchObject( {
			status: 'reported',
			verdict: 'fail',
		} );
	} );

	it( 'counts the session lifetime from before the start request', async () => {
		const srv = server( {
			start: () =>
				new Promise< Response >( ( resolve ) =>
					setTimeout(
						() =>
							resolve( ajax( startData( { expires_in: 20 } ) ) ),
						9000
					)
				),
		} );
		const result = await run( srv );
		expect( result.checks.home ).toEqual( {
			check: 'home',
			status: 'expired',
			attempts: [],
		} );
		expect( srv.counts ).toEqual( { home: 0, rest: 0 } );
		expect( srv.reports ).toHaveLength( 0 );
	} );

	it( 'reports a server-side expiry as a failed submission', async () => {
		const srv = server( {
			report: () => ajax( { code: 'session_unavailable' }, 410, false ),
		} );
		const result = await run( srv );
		expect( result.checks.home ).toMatchObject( {
			status: 'report_failed',
			error: 'session_unavailable',
		} );
		expect( result.checks.rest ).toMatchObject( {
			status: 'report_failed',
			error: 'session_unavailable',
		} );
	} );
} );

describe( 'runSiteChecks: start', () => {
	it.each( [
		[
			'invalid nonce',
			() => ajax( { code: 'invalid_nonce' }, 403, false ),
			'invalid_nonce',
		],
		[
			'disabled',
			() => ajax( { code: 'disabled' }, 403, false ),
			'disabled',
		],
		[
			'rate limited',
			() => ajax( { code: 'rate_limited', retry_after: 30 }, 429, false ),
			'rate_limited',
		],
		[
			'logged out',
			() => new Response( '0', { status: 400 } ),
			'http_400',
		],
		[
			'malformed data',
			() => ajax( { ...startData(), timeout_ms: 999999 } ),
			'invalid_response',
		],
		[
			'other purpose',
			() => ajax( startData( { purpose: 'after' } ) ),
			'invalid_response',
		],
		[
			'network error',
			() => {
				throw new TypeError( 'Failed to fetch' );
			},
			'network_error',
		],
	] )( 'reports why no check ran: %s', async ( _name, start, error ) => {
		const srv = server( { start } );
		const result = await run( srv );
		expect( result ).toEqual( {
			status: 'start_failed',
			error,
			// The server's own retry time, for a rate-limited start only.
			...( error === 'rate_limited' ? { retryAfter: 30 } : {} ),
			checks: {
				home: {
					check: 'home',
					status: 'not_run',
					error: 'start_failed',
				},
				rest: {
					check: 'rest',
					status: 'not_run',
					error: 'start_failed',
				},
			},
		} );
		expect( srv.counts ).toEqual( { home: 0, rest: 0 } );
	} );

	it( 'refuses an admin-ajax URL on another origin', async () => {
		const srv = server();
		const result = await run( srv, {
			settings: {
				ajaxUrl: 'https://evil.test/wp-admin/admin-ajax.php',
				nonce: 'n',
			},
		} );
		expect( result ).toMatchObject( {
			status: 'start_failed',
			error: 'invalid_settings',
		} );
		expect( srv.spy ).not.toHaveBeenCalled();
	} );

	it( 'validates the start response strictly', () => {
		expect( parseStartResponse( startData() ) ).not.toBeNull();
		const bad = [
			startData( { header: 'X-Other' } ),
			startData( { max_attempts: 3 } ),
			startData( {
				checks: [ { ...startData().checks[ 0 ], secret: 'short' } ],
			} ),
			startData( {
				checks: [ { ...startData().checks[ 0 ], session: 'ABC' } ],
			} ),
			startData( {
				checks: [ startData().checks[ 0 ], startData().checks[ 0 ] ],
			} ),
			startData( {
				checks: [ { ...startData().checks[ 1 ], user: 0 } ],
			} ),
			startData( {
				checks: [
					{ ...startData().checks[ 1 ], rest_nonce: undefined },
				],
			} ),
			startData( {
				checks: [
					{
						...startData().checks[ 0 ],
						url: `${ HOME_URL }&k=${ HOME_SECRET }`,
					},
				],
			} ),
			startData( {
				checks: [ { ...startData().checks[ 0 ], check: 'theme' } ],
			} ),
			{ ...startData(), code: 'other' },
		];
		for ( const data of bad ) {
			expect( parseStartResponse( data ) ).toBeNull();
		}
	} );
} );

describe( 'runSiteChecks: targets', () => {
	const withHome = ( url: string ) =>
		server( {
			start: () =>
				ajax(
					startData( {
						checks: [
							{ ...startData().checks[ 0 ], url },
							startData().checks[ 1 ],
						],
					} )
				),
		} );

	it.each( [
		[
			'another origin',
			`https://cdn.example.test/?updatelens_check=${ HOME }`,
			'cross_origin',
		],
		[
			'mixed content',
			`http://example.test/?updatelens_check=${ HOME }`,
			'mixed_content',
		],
		[
			'not http',
			`ftp://example.test/?updatelens_check=${ HOME }`,
			'invalid_target',
		],
		[
			'credentials',
			`https://user:pass@example.test/?updatelens_check=${ HOME }`,
			'invalid_target',
		],
		[ 'without the marker', `${ ORIGIN }/`, 'invalid_target' ],
		[
			'another marker',
			`${ ORIGIN }/?updatelens_check=${ 'ee'.repeat( 16 ) }`,
			'invalid_target',
		],
		[ 'not a URL', 'not a url', 'invalid_target' ],
	] )(
		'refuses a homepage target on %s and still runs REST',
		async ( _name, url, error ) => {
			const srv = withHome( url );
			const result = await run( srv );
			expect( result.checks.home ).toEqual( {
				check: 'home',
				status: 'not_run',
				error,
			} );
			expect( result.checks.rest ).toMatchObject( {
				status: 'reported',
				verdict: 'pass',
			} );
			expect( srv.calls.some( ( call ) => call.url === url ) ).toBe(
				false
			);
			expect( reportOf( srv, 'home' ) ).toBeUndefined();
		}
	);

	it( 'allows plain http on an http admin page', () => {
		const issued = {
			check: 'home' as const,
			definition: 1,
			session: HOME,
			secret: HOME_SECRET,
			url: `http://example.test/?updatelens_check=${ HOME }`,
		};
		expect(
			targetProblem( issued, {
				origin: 'http://example.test',
				protocol: 'http:',
			} )
		).toBeNull();
	} );

	it( 'skips a check of an unsupported definition version or one not issued', async () => {
		const srv = server( {
			start: () =>
				ajax(
					startData( {
						checks: [
							{ ...startData().checks[ 0 ], definition: 2 },
						],
					} )
				),
		} );
		const result = await run( srv );
		expect( result.checks.home ).toEqual( {
			check: 'home',
			status: 'not_run',
			error: 'unsupported_definition',
		} );
		expect( result.checks.rest ).toEqual( {
			check: 'rest',
			status: 'not_run',
			error: 'not_issued',
		} );
	} );
} );

describe( 'runSiteChecks: cancellation', () => {
	it( 'aborts in-flight requests, reports nothing and leaves no timers', async () => {
		const srv = server( { home: [ hang ], rest: [ hang ] } );
		const controller = new AbortController();
		const promise = runSiteChecks( SETTINGS, {
			purpose: 'before',
			fetch: srv.fetch,
			location: LOCATION,
			signal: controller.signal,
		} );
		await vi.advanceTimersByTimeAsync( 1000 );
		controller.abort();
		const result = await promise;

		expect( result.checks ).toEqual( {
			home: { check: 'home', status: 'cancelled' },
			rest: { check: 'rest', status: 'cancelled' },
		} );
		expect( srv.reports ).toHaveLength( 0 );
		expect( vi.getTimerCount() ).toBe( 0 );
		const signals = srv.calls
			.filter( ( call ) => call.url !== AJAX )
			.map( ( call ) => call.init!.signal! );
		expect( signals.every( ( signal ) => signal.aborted ) ).toBe( true );
	} );

	it( 'cancels during the retry delay', async () => {
		const srv = server( {
			home: [ () => page( { status: 500, end: null } ) ],
		} );
		const controller = new AbortController();
		const promise = runSiteChecks( SETTINGS, {
			purpose: 'before',
			fetch: srv.fetch,
			location: LOCATION,
			signal: controller.signal,
		} );
		await vi.advanceTimersByTimeAsync( 2000 );
		controller.abort();
		const result = await promise;

		expect( result.checks.home ).toEqual( {
			check: 'home',
			status: 'cancelled',
		} );
		expect( srv.counts.home ).toBe( 1 );
		expect( reportOf( srv, 'home' ) ).toBeUndefined();
		expect( vi.getTimerCount() ).toBe( 0 );
	} );

	it( 'does nothing when already cancelled', async () => {
		const srv = server();
		const controller = new AbortController();
		controller.abort();
		const result = await run( srv, { signal: controller.signal } );
		expect( result.status ).toBe( 'cancelled' );
		expect( srv.spy ).not.toHaveBeenCalled();
	} );

	it( 'leaves no timers after a normal run', async () => {
		const srv = server( {
			home: [ () => page( { status: 500, end: null } ) ],
			rest: [ hang ],
		} );
		await run( srv );
		expect( vi.getTimerCount() ).toBe( 0 );
	} );
} );

describe( 'attemptPassed agrees with PHP (tests/fixtures/site-check-attempts.json)', () => {
	it.each( shared.cases.map( ( c ) => [ c.name, c ] as const ) )(
		'%s',
		( _name, c ) => {
			expect(
				attemptPassed(
					c.check as CheckId,
					c.facts as AttemptFacts,
					shared.expected_user
				)
			).toBe( c.verdict === 'pass' );
		}
	);
} );

describe( 'runSiteChecks: AFTER runs', () => {
	const runAfter = async (
		srv: ReturnType< typeof server >,
		analysis?: number
	) => {
		const promise = runSiteChecks( SETTINGS, {
			purpose: 'after',
			analysis,
			fetch: srv.fetch,
			now: () => Date.now(),
			location: LOCATION,
		} );
		await vi.advanceTimersByTimeAsync( 60000 );
		return promise;
	};
	const startFields = ( srv: ReturnType< typeof server > ) =>
		Object.fromEntries(
			new URLSearchParams( String( srv.calls[ 0 ].init?.body ) )
		);

	it( 'starts with the analysis the follow-up settled and reports both checks', async () => {
		const srv = server( {
			start: () => ajax( startData( { purpose: 'after' } ) ),
		} );
		const result = await runAfter( srv, 42 );

		expect( startFields( srv ) ).toEqual( {
			action: 'updatelens_site_check_start',
			_ajax_nonce: 'n0nce',
			purpose: 'after',
			analysis: '42',
		} );
		expect( result.status ).toBe( 'started' );
		expect( result.checks.home.status ).toBe( 'reported' );
		expect( result.checks.rest.status ).toBe( 'reported' );
		expect( srv.reports ).toHaveLength( 2 );
	} );

	it( 'runs only the checks the server opened (those with a baseline)', async () => {
		const data = startData( { purpose: 'after' } );
		const srv = server( {
			start: () =>
				ajax( {
					...data,
					checks: [ ( data.checks as unknown[] )[ 1 ] ],
				} ),
		} );
		const result = await runAfter( srv, 42 );

		expect( result.checks.home ).toEqual( {
			check: 'home',
			status: 'not_run',
			error: 'not_issued',
		} );
		expect( result.checks.rest.status ).toBe( 'reported' );
		expect( srv.counts ).toEqual( { home: 0, rest: 1 } );
	} );

	it.each( [ 'no_baseline', 'overlapping_update', 'update_order_unknown' ] )(
		'runs nothing when the server answers not_needed: %s',
		async ( reason ) => {
			const srv = server( {
				start: () =>
					ajax( { code: 'not_needed', purpose: 'after', reason } ),
			} );
			const result = await runAfter( srv, 42 );

			expect( result.status ).toBe( 'not_needed' );
			expect( result.reason ).toBe( reason );
			expect( srv.counts ).toEqual( { home: 0, rest: 0 } );
			expect( srv.reports ).toHaveLength( 0 );
		}
	);

	it( 'rejects BEFORE answers to an AFTER start, and AFTER answers to a BEFORE start', async () => {
		const fresh = server( {
			start: () =>
				ajax( {
					code: 'not_needed',
					purpose: 'before',
					reason: 'fresh',
				} ),
		} );
		expect( ( await runAfter( fresh, 42 ) ).error ).toBe(
			'invalid_response'
		);
		const wrong = server( {
			start: () =>
				ajax( {
					code: 'not_needed',
					purpose: 'after',
					reason: 'fresh',
				} ),
		} );
		expect( ( await runAfter( wrong, 42 ) ).error ).toBe(
			'invalid_response'
		);
		const after = server( {
			start: () =>
				ajax( {
					code: 'not_needed',
					purpose: 'after',
					reason: 'no_baseline',
				} ),
		} );
		expect( ( await run( after ) ).error ).toBe( 'invalid_response' );
	} );

	it.each( [ undefined, 0, -1, 1.5, Number.MAX_SAFE_INTEGER + 1 ] )(
		'sends nothing without a valid analysis: %s',
		async ( analysis ) => {
			const srv = server();
			const result = await runAfter( srv, analysis );

			expect( result.status ).toBe( 'start_failed' );
			expect( result.error ).toBe( 'invalid_settings' );
			expect( srv.calls ).toHaveLength( 0 );
		}
	);

	it.each( [
		[ 'already started', 'already_started', 409 ],
		[ 'not eligible', 'not_eligible', 409 ],
		[ 'another login', 'not_owner', 403 ],
	] )( 'reports a refused start: %s', async ( _name, code, status ) => {
		const srv = server( {
			start: () => ajax( { code }, status, false ),
		} );
		const result = await runAfter( srv, 42 );

		expect( result.status ).toBe( 'start_failed' );
		expect( result.error ).toBe( code );
		expect( srv.counts ).toEqual( { home: 0, rest: 0 } );
	} );
} );
