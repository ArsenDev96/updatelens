import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type {
	AttemptFacts,
	CheckId,
} from '../../src/admin/site-check/contract';
import {
	CHANNEL_NAME,
	LOCK_NAME,
	START_GRACE_MS,
	UPDATE_PAUSE_MS,
	bindBaseline,
	createBaselineScheduler,
	createRunLock,
	parseBaselineSettings,
	type BaselineSettings,
	type BaselineWindow,
	type SchedulerDependencies,
	type SchedulerMessage,
} from '../../src/admin/site-check/baseline';
import {
	runSiteChecks,
	type CheckOutcome,
	type RunResult,
} from '../../src/admin/site-check/runner';
import { attemptPassed } from '../../src/admin/site-check/observe';

const ORIGIN = 'https://example.test';
const LOCATION = { origin: ORIGIN, protocol: 'https:' } as Location;
const AJAX = `${ ORIGIN }/wp-admin/admin-ajax.php`;
const HOME = 'a1'.repeat( 16 );
const REST = 'b2'.repeat( 16 );
const HOME_URL = `${ ORIGIN }/?updatelens_check=${ HOME }`;
const REST_URL = `${ ORIGIN }/wp-json/wp/v2/users/me?_fields=id`;
const USER = 7;

const settings = (
	refreshIn: Record< CheckId, number > = { home: 0, rest: 0 }
): BaselineSettings => ( {
	ajaxUrl: AJAX,
	nonce: 'n0nce',
	schedule: {
		refresh_after: 300,
		retry_after_failure: 120,
		refresh_in: refreshIn,
	},
} );

const homeIssued = {
	check: 'home',
	definition: 1,
	session: HOME,
	secret: 'c3'.repeat( 32 ),
	url: HOME_URL,
};
const restIssued = {
	check: 'rest',
	definition: 1,
	session: REST,
	secret: 'd4'.repeat( 32 ),
	url: REST_URL,
	rest_nonce: 'abc123',
	user: USER,
};

/** A `before` start response's `data`. */
const started = (
	checks: unknown[] = [ homeIssued, restIssued ],
	refreshIn: Record< string, number > = { home: 0, rest: 0 }
) => ( {
	code: 'started',
	purpose: 'before',
	expires_in: 120,
	timeout_ms: 10000,
	retry_delay_ms: 5000,
	max_attempts: 2,
	header: 'X-UpdateLens-Check',
	end_marker_attribute: 'data-updatelens-check',
	checks,
	refresh_in: refreshIn,
} );

const ajax = ( data: unknown, status = 200 ) =>
	new Response( JSON.stringify( { success: status === 200, data } ), {
		status,
		headers: { 'Content-Type': 'application/json' },
	} );

const page = () =>
	new Response(
		`<!doctype html><p>Hi</p><span hidden data-updatelens-check="${ HOME }"></span>`,
		{ status: 200, headers: { 'X-UpdateLens-Check': HOME } }
	);

const restOk = ( status = 200 ) =>
	new Response( JSON.stringify( { id: USER } ), {
		status,
		headers: {
			'Content-Type': 'application/json',
			'X-UpdateLens-Check': REST,
		},
	} );

/** Answers after `ms` (fake timers), or rejects when aborted. */
const after = ( ms: number, response: () => Response, init?: RequestInit ) =>
	new Promise< Response >( ( resolve, reject ) => {
		const timer = setTimeout( () => resolve( response() ), ms );
		init?.signal?.addEventListener( 'abort', () => {
			clearTimeout( timer );
			reject( new DOMException( 'Aborted', 'AbortError' ) );
		} );
	} );

type Handler = ( init?: RequestInit ) => Response | Promise< Response >;

/** A scripted WordPress: start, homepage, REST and report handlers. */
function server(
	options: {
		start?: Handler;
		home?: Handler;
		rest?: Handler;
	} = {}
) {
	const log: { at: number; what: string }[] = [];
	const reports: { check: CheckId; attempts: AttemptFacts[] }[] = [];
	const fetch = vi.fn(
		async ( input: RequestInfo | URL, init?: RequestInit ) => {
			const url = String( input );
			if ( url === AJAX ) {
				const fields = Object.fromEntries(
					new URLSearchParams( String( init?.body ) )
				);
				if ( fields.action === 'updatelens_site_check_start' ) {
					log.push( { at: Date.now(), what: 'start' } );
					return options.start
						? options.start( init )
						: ajax( started() );
				}
				const report = JSON.parse( fields.report );
				reports.push( report );
				log.push( {
					at: Date.now(),
					what: `report:${ report.check }`,
				} );
				const last = report.attempts[ report.attempts.length - 1 ];
				return ajax( {
					code: 'accepted',
					check: report.check,
					purpose: 'before',
					verdict: attemptPassed( report.check, last, USER )
						? 'pass'
						: 'fail',
					reason: null,
					baseline: 'saved',
				} );
			}
			if ( url === HOME_URL ) {
				log.push( { at: Date.now(), what: 'home' } );
				return options.home ? options.home( init ) : page();
			}
			if ( url === REST_URL ) {
				log.push( { at: Date.now(), what: 'rest' } );
				return options.rest ? options.rest( init ) : restOk();
			}
			throw new TypeError( `Unexpected request ${ url }` );
		}
	);

	return {
		fetch: fetch as unknown as typeof globalThis.fetch,
		spy: fetch,
		log,
		reports,
	};
}

const runBefore = ( srv: ReturnType< typeof server >, signal?: AbortSignal ) =>
	runSiteChecks( settings(), {
		purpose: 'before',
		fetch: srv.fetch,
		now: () => Date.now(),
		location: LOCATION,
		signal,
	} );

const outcome = (
	check: CheckId,
	status: CheckOutcome[ 'status' ],
	extra: Record< string, unknown > = {}
) => ( { check, status, ...extra } ) as unknown as CheckOutcome;

const result = (
	home: CheckOutcome,
	rest: CheckOutcome,
	extra: Partial< RunResult > = {}
): RunResult => ( {
	status: 'started',
	checks: { home, rest },
	...extra,
} );

/** Scheduler dependencies with a controllable run. */
function deps( overrides: Partial< SchedulerDependencies > = {} ) {
	const published: SchedulerMessage[] = [];
	const runs: AbortSignal[] = [];
	let visible = true;
	let updating = false;
	const base: SchedulerDependencies = {
		now: () => Date.now(),
		isVisible: () => visible,
		isUpdating: () => updating,
		run: async ( signal ) => {
			runs.push( signal );
			return result(
				outcome( 'home', 'reported', { verdict: 'pass' } ),
				outcome( 'rest', 'reported', { verdict: 'pass' } )
			);
		},
		lock: async ( task ) => {
			await task();
			return true;
		},
		publish: ( message ) => published.push( message ),
		...overrides,
	};

	return {
		deps: base,
		published,
		runs,
		setVisible: ( value: boolean ) => ( visible = value ),
		setUpdating: ( value: boolean ) => ( updating = value ),
	};
}

beforeEach( () => {
	vi.useFakeTimers( { toFake: [ 'setTimeout', 'clearTimeout', 'Date' ] } );
	vi.setSystemTime( 1760090000000 );
} );

afterEach( () => {
	vi.useRealTimers();
} );

describe( 'parseBaselineSettings', () => {
	it( 'accepts the settings PHP prints', () => {
		expect(
			parseBaselineSettings( settings( { home: 0, rest: 240 } ) )
		).toEqual( settings( { home: 0, rest: 240 } ) );
	} );

	it.each( [
		[ 'nothing', undefined ],
		[ 'no nonce', { ...settings(), nonce: 1 } ],
		[ 'no schedule', { ajaxUrl: AJAX, nonce: 'n' } ],
		[
			'string numbers (top-level localize)',
			{
				...settings(),
				schedule: { ...settings().schedule, refresh_after: '300' },
			},
		],
		[
			'missing check',
			{
				...settings(),
				schedule: { ...settings().schedule, refresh_in: { home: 0 } },
			},
		],
		[
			'negative',
			{
				...settings(),
				schedule: {
					...settings().schedule,
					refresh_in: { home: -1, rest: 0 },
				},
			},
		],
		[
			'zero refresh',
			{
				...settings(),
				schedule: { ...settings().schedule, refresh_after: 0 },
			},
		],
	] )( 'rejects %s', ( _name, value ) => {
		expect( parseBaselineSettings( value ) ).toBeNull();
	} );
} );

describe( 'runSiteChecks for BEFORE baselines', () => {
	it( 'runs nothing when the server says every baseline is fresh', async () => {
		const srv = server( {
			start: () =>
				ajax( {
					code: 'not_needed',
					purpose: 'before',
					reason: 'fresh',
					refresh_in: { home: 120, rest: 200 },
				} ),
		} );
		const outcomeOf = await runBefore( srv );

		expect( outcomeOf ).toMatchObject( {
			status: 'not_needed',
			reason: 'fresh',
			refreshIn: { home: 120, rest: 200 },
		} );
		expect( outcomeOf.checks.home ).toEqual( {
			check: 'home',
			status: 'not_run',
			error: 'not_issued',
		} );
		expect( srv.log.map( ( entry ) => entry.what ) ).toEqual( [ 'start' ] );
	} );

	it( 'runs nothing without relevant updates', async () => {
		const srv = server( {
			start: () =>
				ajax( {
					code: 'not_needed',
					purpose: 'before',
					reason: 'no_updates',
				} ),
		} );

		expect( await runBefore( srv ) ).toMatchObject( {
			status: 'not_needed',
			reason: 'no_updates',
		} );
		expect( srv.spy ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'runs nothing while an update is running', async () => {
		const srv = server( {
			start: () =>
				ajax( {
					code: 'not_needed',
					purpose: 'before',
					reason: 'update_in_progress',
					refresh_in: { home: 120, rest: 120 },
				} ),
		} );

		expect( await runBefore( srv ) ).toMatchObject( {
			status: 'not_needed',
			reason: 'update_in_progress',
			refreshIn: { home: 120, rest: 120 },
		} );
		expect( srv.spy ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'runs only the checks the server issued', async () => {
		const srv = server( {
			start: () =>
				ajax( started( [ restIssued ], { home: 270, rest: 0 } ) ),
		} );
		const outcomeOf = await runBefore( srv );

		expect( outcomeOf.status ).toBe( 'started' );
		expect( outcomeOf.refreshIn ).toEqual( { home: 270, rest: 0 } );
		expect( outcomeOf.checks.home ).toMatchObject( {
			status: 'not_run',
			error: 'not_issued',
		} );
		expect( outcomeOf.checks.rest ).toMatchObject( {
			status: 'reported',
			verdict: 'pass',
		} );
		expect( srv.log.map( ( entry ) => entry.what ) ).toEqual( [
			'start',
			'rest',
			'report:rest',
		] );
	} );

	it( 'passes the server’s retry time on when rate limited', async () => {
		const srv = server( {
			start: () => ajax( { code: 'rate_limited', retry_after: 42 }, 429 ),
		} );

		expect( await runBefore( srv ) ).toMatchObject( {
			status: 'start_failed',
			error: 'rate_limited',
			retryAfter: 42,
		} );
	} );

	it.each( [
		[ 'unknown check', { home: 0, probe: 0 } ],
		[ 'out of range', { home: 3601 } ],
		[ 'not an object', [ 0, 0 ] ],
	] )(
		'rejects a start with a malformed refresh_in (%s)',
		async ( _name, refreshIn ) => {
			const srv = server( {
				start: () => ajax( { ...started(), refresh_in: refreshIn } ),
			} );

			expect( await runBefore( srv ) ).toMatchObject( {
				status: 'start_failed',
				error: 'invalid_response',
			} );
			expect( srv.spy ).toHaveBeenCalledTimes( 1 );
		}
	);

	it( 'stores the homepage baseline while REST is still pending', async () => {
		// REST answers after 25 seconds: two timeouts and the retry delay.
		const srv = server( {
			home: ( init ) => after( 300, page, init ),
			rest: ( init ) => after( 12000, restOk, init ),
		} );
		const promise = runBefore( srv );

		await vi.advanceTimersByTimeAsync( 1000 );
		expect( srv.reports.map( ( report ) => report.check ) ).toEqual( [
			'home',
		] );
		expect(
			srv.log.find( ( entry ) => entry.what === 'report:home' )!.at
		).toBe( 1760090000300 );

		await vi.advanceTimersByTimeAsync( 30000 );
		const outcomeOf = await promise;
		expect( outcomeOf.checks.home ).toMatchObject( {
			status: 'reported',
			verdict: 'pass',
		} );
		expect( outcomeOf.checks.rest ).toMatchObject( {
			status: 'reported',
			attempts: [ { kind: 'timeout' }, { kind: 'timeout' } ],
		} );
		expect(
			srv.log.find( ( entry ) => entry.what === 'report:rest' )!.at
		).toBe( 1760090025000 );
	} );

	it( 'keeps the homepage pass when REST fails', async () => {
		const srv = server( { rest: () => restOk( 500 ) } );
		const promise = runBefore( srv );
		await vi.advanceTimersByTimeAsync( 10000 );
		const outcomeOf = await promise;

		expect( outcomeOf.checks.home ).toMatchObject( { verdict: 'pass' } );
		expect( outcomeOf.checks.rest ).toMatchObject( {
			status: 'reported',
			verdict: 'fail',
		} );
		expect( srv.reports.map( ( report ) => report.check ) ).toEqual( [
			'home',
			'rest',
		] );
	} );
} );

describe( 'createBaselineScheduler', () => {
	it( 'runs only when a check is due, the page is visible and no update runs', async () => {
		const harness = deps();
		const scheduler = createBaselineScheduler(
			settings( { home: 0, rest: 10 } ),
			harness.deps
		);

		harness.setVisible( false );
		expect( await scheduler.evaluate() ).toBe( 'skipped' );
		harness.setVisible( true );
		harness.setUpdating( true );
		expect( await scheduler.evaluate() ).toBe( 'skipped' );
		harness.setUpdating( false );
		expect( harness.runs ).toHaveLength( 0 );

		expect( await scheduler.evaluate() ).toBe( 'ran' );
		expect( scheduler.dueAt() ).toEqual( {
			home: 1760090300000,
			rest: 1760090300000,
		} );
		expect( await scheduler.evaluate() ).toBe( 'skipped' );
		expect( harness.runs ).toHaveLength( 1 );
		expect( harness.published ).toEqual( [
			{ type: 'due', due: scheduler.dueAt() },
		] );
	} );

	it( 'reuses fresh baselines: nothing runs until one is due', async () => {
		const harness = deps();
		const scheduler = createBaselineScheduler(
			settings( { home: 200, rest: 250 } ),
			harness.deps
		);

		expect( await scheduler.evaluate() ).toBe( 'skipped' );
		vi.advanceTimersByTime( 199999 );
		expect( await scheduler.evaluate() ).toBe( 'skipped' );
		vi.advanceTimersByTime( 1 );
		expect( await scheduler.evaluate() ).toBe( 'ran' );
	} );

	it( 'a check on another origin (never due, 3600 s from PHP) never starts a run on its own', async () => {
		const harness = deps( {
			run: async ( signal ) => {
				harness.runs.push( signal );
				return result(
					outcome( 'home', 'not_run', { error: 'not_issued' } ),
					outcome( 'rest', 'reported', { verdict: 'pass' } ),
					{ refreshIn: { home: 3600, rest: 0 } }
				);
			},
		} );
		const scheduler = createBaselineScheduler(
			settings( { home: 3600, rest: 0 } ),
			harness.deps
		);

		expect( await scheduler.evaluate() ).toBe( 'ran' );
		expect( scheduler.dueAt().home ).toBe( 1760093600000 );
		// The REST baseline is refreshed every five minutes; the homepage never asks.
		vi.advanceTimersByTime( 299999 );
		expect( await scheduler.evaluate() ).toBe( 'skipped' );
		vi.advanceTimersByTime( 1 );
		expect( await scheduler.evaluate() ).toBe( 'ran' );
		expect( harness.runs ).toHaveLength( 2 );
		expect( scheduler.dueAt().home ).toBe( 1760093900000 );
	} );

	it( 'never runs twice at once', async () => {
		let finish: () => void = () => undefined;
		const harness = deps( {
			run: () =>
				new Promise( ( resolve ) => {
					finish = () =>
						resolve(
							result(
								outcome( 'home', 'reported' ),
								outcome( 'rest', 'reported' )
							)
						);
				} ),
		} );
		const scheduler = createBaselineScheduler( settings(), harness.deps );

		const first = scheduler.evaluate();
		expect( scheduler.isRunning() ).toBe( true );
		expect( await scheduler.evaluate() ).toBe( 'skipped' );
		finish();
		expect( await first ).toBe( 'ran' );
		expect( scheduler.isRunning() ).toBe( false );
	} );

	it( 'schedules each check from its own outcome', async () => {
		const harness = deps( {
			run: async () =>
				result(
					outcome( 'home', 'reported', { verdict: 'pass' } ),
					outcome( 'rest', 'report_failed', {
						error: 'network_error',
					} )
				),
		} );
		const scheduler = createBaselineScheduler( settings(), harness.deps );
		await scheduler.evaluate();

		expect( scheduler.dueAt() ).toEqual( {
			home: 1760090300000,
			rest: 1760090120000,
		} );
	} );

	it.each< [ string, RunResult, Record< CheckId, number > ] >( [
		[
			'not issued: the server’s refresh time',
			result(
				outcome( 'home', 'not_run', { error: 'not_issued' } ),
				outcome( 'rest', 'reported' ),
				{ refreshIn: { home: 270, rest: 0 } }
			),
			{ home: 1760090270000, rest: 1760090300000 },
		],
		[
			'expired and not run: retry later',
			result(
				outcome( 'home', 'expired', { attempts: [] } ),
				outcome( 'rest', 'not_run', { error: 'start_failed' } )
			),
			{ home: 1760090120000, rest: 1760090120000 },
		],
		[
			'cross-origin homepage: never on this page',
			result(
				outcome( 'home', 'not_run', { error: 'cross_origin' } ),
				outcome( 'rest', 'reported' )
			),
			{ home: Infinity, rest: 1760090300000 },
		],
		[
			'fresh',
			{
				status: 'not_needed',
				reason: 'fresh',
				refreshIn: { home: 100, rest: 200 },
				checks: {
					home: outcome( 'home', 'not_run', { error: 'not_issued' } ),
					rest: outcome( 'rest', 'not_run', { error: 'not_issued' } ),
				},
			},
			{ home: 1760090100000, rest: 1760090200000 },
		],
		[
			'an update is running: ask again later',
			{
				status: 'not_needed',
				reason: 'update_in_progress',
				refreshIn: { home: 120, rest: 120 },
				checks: {
					home: outcome( 'home', 'not_run', { error: 'not_issued' } ),
					rest: outcome( 'rest', 'not_run', { error: 'not_issued' } ),
				},
			},
			{ home: 1760090120000, rest: 1760090120000 },
		],
		[
			'rate limited',
			{
				status: 'start_failed',
				error: 'rate_limited',
				retryAfter: 42,
				checks: {
					home: outcome( 'home', 'not_run', {
						error: 'start_failed',
					} ),
					rest: outcome( 'rest', 'not_run', {
						error: 'start_failed',
					} ),
				},
			},
			{ home: 1760090042000, rest: 1760090042000 },
		],
		[
			'maintenance or server error at start',
			{
				status: 'start_failed',
				error: 'http_503',
				checks: {
					home: outcome( 'home', 'not_run', {
						error: 'start_failed',
					} ),
					rest: outcome( 'rest', 'not_run', {
						error: 'start_failed',
					} ),
				},
			},
			{ home: 1760090120000, rest: 1760090120000 },
		],
	] )( '%s', async ( _name, runResult, expected ) => {
		const harness = deps( { run: async () => runResult } );
		const scheduler = createBaselineScheduler( settings(), harness.deps );
		await scheduler.evaluate();

		expect( scheduler.dueAt() ).toEqual( expected );
		expect( scheduler.isStopped() ).toBe( false );
	} );

	it.each( [ 'disabled', 'forbidden', 'invalid_nonce', 'invalid_settings' ] )(
		'stops after %s',
		async ( error ) => {
			const harness = deps( {
				run: async () => ( {
					status: 'start_failed',
					error,
					checks: {
						home: outcome( 'home', 'not_run', {
							error: 'start_failed',
						} ),
						rest: outcome( 'rest', 'not_run', {
							error: 'start_failed',
						} ),
					},
				} ),
			} );
			const scheduler = createBaselineScheduler(
				settings(),
				harness.deps
			);
			await scheduler.evaluate();
			vi.advanceTimersByTime( 3600000 );

			expect( scheduler.isStopped() ).toBe( true );
			expect( await scheduler.evaluate() ).toBe( 'skipped' );
			expect( harness.runs ).toHaveLength( 0 );
		}
	);

	it( 'stops when no update is left', async () => {
		const harness = deps( {
			run: async () => ( {
				status: 'not_needed',
				reason: 'no_updates',
				checks: {
					home: outcome( 'home', 'not_run', { error: 'not_issued' } ),
					rest: outcome( 'rest', 'not_run', { error: 'not_issued' } ),
				},
			} ),
		} );
		const scheduler = createBaselineScheduler( settings(), harness.deps );
		await scheduler.evaluate();

		expect( scheduler.isStopped() ).toBe( true );
	} );

	it( 'aborts when an update starts and pauses new runs', async () => {
		const harness = deps( {
			run: ( signal ) =>
				new Promise( ( resolve ) => {
					signal.addEventListener( 'abort', () =>
						resolve( {
							status: 'cancelled',
							checks: {
								home: outcome( 'home', 'cancelled' ),
								rest: outcome( 'rest', 'cancelled' ),
							},
						} )
					);
				} ),
		} );
		const scheduler = createBaselineScheduler( settings(), harness.deps );
		const running = scheduler.evaluate();

		scheduler.updateStarted();
		expect( await running ).toBe( 'ran' );
		expect( scheduler.dueAt() ).toEqual( {
			home: 1760090000000,
			rest: 1760090000000,
		} );
		expect( harness.published ).toEqual( [ { type: 'updating' } ] );

		expect( await scheduler.evaluate() ).toBe( 'skipped' );
		vi.advanceTimersByTime( UPDATE_PAUSE_MS );
		harness.deps.run = async () =>
			result(
				outcome( 'home', 'reported' ),
				outcome( 'rest', 'reported' )
			);
		expect( await scheduler.evaluate() ).toBe( 'ran' );
	} );

	it( 'follows other tabs: their due times and their updates', async () => {
		const harness = deps();
		const scheduler = createBaselineScheduler( settings(), harness.deps );

		scheduler.receive( {
			type: 'due',
			due: { home: 1760090300000, rest: Infinity },
		} );
		scheduler.receive( 'noise' );
		scheduler.receive( { type: 'due', due: 'x' } );
		expect( scheduler.dueAt() ).toEqual( {
			home: 1760090300000,
			rest: 1760090000000,
		} );

		scheduler.receive( { type: 'updating' } );
		expect( await scheduler.evaluate() ).toBe( 'skipped' );
		expect( harness.published ).toEqual( [] );
	} );

	it( 'never throws when the lock or the run fails', async () => {
		const harness = deps( {
			lock: async () => {
				throw new Error( 'lock' );
			},
		} );
		const scheduler = createBaselineScheduler( settings(), harness.deps );

		expect( await scheduler.evaluate() ).toBe( 'skipped' );
		expect( scheduler.isRunning() ).toBe( false );
	} );
} );

/** A fake Web Locks manager shared by tabs. */
function fakeLocks() {
	const held = new Set< string >();
	return {
		request: async (
			name: string,
			_options: { ifAvailable: boolean },
			callback: ( lock: unknown ) => Promise< void >
		) => {
			if ( held.has( name ) ) {
				return callback( null );
			}
			held.add( name );
			try {
				return await callback( { name } );
			} finally {
				held.delete( name );
			}
		},
	};
}

/** A fake localStorage. */
function fakeStorage() {
	const values = new Map< string, string >();
	return {
		getItem: ( key: string ) => values.get( key ) ?? null,
		setItem: ( key: string, value: string ) => values.set( key, value ),
		removeItem: ( key: string ) => values.delete( key ),
		values,
	} as unknown as Storage & { values: Map< string, string > };
}

describe( 'createRunLock', () => {
	it( 'uses Web Locks: a second tab skips while the first runs', async () => {
		const locks = fakeLocks();
		const tabA = createRunLock( { navigator: { locks } }, Date.now );
		const tabB = createRunLock( { navigator: { locks } }, Date.now );
		let release: () => void = () => undefined;
		const running = tabA(
			() => new Promise< void >( ( resolve ) => ( release = resolve ) )
		);

		const task = vi.fn( async () => undefined );
		expect( await tabB( task ) ).toBe( false );
		expect( task ).not.toHaveBeenCalled();
		release();
		expect( await running ).toBe( true );
		expect( await tabB( task ) ).toBe( true );
	} );

	it( 'falls back to a localStorage lease that expires', async () => {
		const storage = fakeStorage();
		const lock = createRunLock( { localStorage: storage }, Date.now );
		let release: () => void = () => undefined;
		const running = lock(
			() => new Promise< void >( ( resolve ) => ( release = resolve ) )
		);

		expect( storage.values.get( LOCK_NAME ) ).toMatch( / 1760090060000$/ );
		expect( await lock( async () => undefined ) ).toBe( false );
		release();
		await running;
		expect( storage.values.has( LOCK_NAME ) ).toBe( false );

		storage.setItem( LOCK_NAME, 'crashed-tab 1760090000001' );
		expect( await lock( async () => undefined ) ).toBe( false );
		vi.advanceTimersByTime( 2 );
		expect( await lock( async () => undefined ) ).toBe( true );
	} );

	it( 'runs without a lock when storage is blocked', async () => {
		const blocked = {
			get localStorage(): Storage {
				throw new DOMException( 'Blocked', 'SecurityError' );
			},
		};
		const task = vi.fn( async () => undefined );

		expect( await createRunLock( blocked, Date.now )( task ) ).toBe( true );
		expect( task ).toHaveBeenCalledTimes( 1 );
	} );
} );

/** A fake BroadcastChannel bus. */
function channelBus() {
	const channels: FakeChannel[] = [];
	class FakeChannel {
		onmessage: ( ( event: { data: unknown } ) => void ) | null = null;
		constructor( public name: string ) {
			channels.push( this );
		}
		postMessage( data: unknown ) {
			channels
				.filter(
					( other ) => other !== this && other.name === this.name
				)
				.forEach( ( other ) => other.onmessage?.( { data } ) );
		}
		close() {}
	}
	return FakeChannel as unknown as typeof BroadcastChannel;
}

/** A fake page (window) for bindBaseline. */
function fakePage(
	options: {
		fetch?: typeof fetch;
		settings?: unknown;
		visible?: boolean;
		locks?: ReturnType< typeof fakeLocks >;
		BroadcastChannel?: typeof BroadcastChannel;
		updates?: { ajaxLocked?: boolean; queue?: unknown[] };
	} = {}
) {
	const listeners = new Map< string, ( ( event: Event ) => void )[] >();
	const on = ( type: string, listener: ( event: Event ) => void ) =>
		listeners.set( type, [ ...( listeners.get( type ) ?? [] ), listener ] );
	const jQueryHandlers = new Map< string, () => void >();
	const document = {
		visibilityState: options.visible === false ? 'hidden' : 'visible',
		addEventListener: (
			type: string,
			listener: ( event: Event ) => void
		) => on( `document:${ type }`, listener ),
	};
	const win = {
		document,
		location: LOCATION,
		navigator: { locks: options.locks ?? fakeLocks() },
		BroadcastChannel: options.BroadcastChannel,
		fetch: options.fetch,
		wp: { updates: options.updates ?? { ajaxLocked: false, queue: [] } },
		jQuery: () => ( {
			on: ( events: string, handler: () => void ) =>
				jQueryHandlers.set( events, handler ),
		} ),
		addEventListener: on,
		updatelensSiteCheckBaseline:
			'settings' in options ? options.settings : settings(),
	};
	const fire = ( type: string, event: Event = new Event( type ) ) =>
		( listeners.get( type ) ?? [] ).forEach( ( listener ) =>
			listener( event )
		);

	return {
		win: win as unknown as BaselineWindow,
		document,
		fire,
		jQuery: ( event: string ) => jQueryHandlers.get( event )?.(),
	};
}

describe( 'bindBaseline', () => {
	it( 'does nothing without settings (feature disabled): no request at all', async () => {
		const srv = server();
		const page = fakePage( { fetch: srv.fetch, settings: undefined } );

		expect( bindBaseline( page.win ) ).toBeNull();
		page.fire( 'document:visibilitychange' );
		page.fire( 'focus' );
		await vi.advanceTimersByTimeAsync( 600000 );
		expect( srv.spy ).not.toHaveBeenCalled();
	} );

	it( 'starts a baseline when the page opens and stores both checks', async () => {
		const srv = server();
		const page = fakePage( { fetch: srv.fetch } );
		const scheduler = bindBaseline( page.win )!;
		await vi.advanceTimersByTimeAsync( 1000 );

		expect( srv.log.map( ( entry ) => entry.what ).sort() ).toEqual(
			[ 'home', 'report:home', 'report:rest', 'rest', 'start' ].sort()
		);
		expect( scheduler.dueAt() ).toEqual( {
			home: 1760090300000 + START_GRACE_MS,
			rest: 1760090300000 + START_GRACE_MS,
		} );
	} );

	it( 'reuses fresh baselines: no request on load or on focus', async () => {
		const srv = server();
		const page = fakePage( {
			fetch: srv.fetch,
			settings: settings( { home: 250, rest: 290 } ),
		} );
		bindBaseline( page.win );
		page.fire( 'focus' );
		page.fire( 'document:visibilitychange' );
		await vi.advanceTimersByTimeAsync( 1000 );

		expect( srv.spy ).not.toHaveBeenCalled();
	} );

	it( 'waits for a hidden tab to become visible, and refreshes stale baselines then', async () => {
		const srv = server();
		const page = fakePage( { fetch: srv.fetch, visible: false } );
		bindBaseline( page.win );
		await vi.advanceTimersByTimeAsync( 1000 );
		expect( srv.spy ).not.toHaveBeenCalled();

		page.document.visibilityState = 'visible';
		page.fire( 'document:visibilitychange' );
		await vi.advanceTimersByTimeAsync( 1000 );
		expect( srv.reports ).toHaveLength( 2 );

		// Hidden for six minutes, then visible again: older than five minutes.
		page.document.visibilityState = 'hidden';
		page.fire( 'document:visibilitychange' );
		await vi.advanceTimersByTimeAsync( 360000 );
		page.document.visibilityState = 'visible';
		page.fire( 'document:visibilitychange' );
		await vi.advanceTimersByTimeAsync( 1000 );
		expect( srv.reports ).toHaveLength( 4 );

		// A page restored from the back/forward cache counts too.
		await vi.advanceTimersByTimeAsync( 300000 );
		page.fire(
			'pageshow',
			Object.assign( new Event( 'pageshow' ), { persisted: true } )
		);
		await vi.advanceTimersByTimeAsync( 1000 );
		expect( srv.reports ).toHaveLength( 6 );
	} );

	it( 'never competes with an immediate update: no request during the grace period', async () => {
		const srv = server();
		const pageFake = fakePage( { fetch: srv.fetch } );
		const scheduler = bindBaseline( pageFake.win )!;
		await vi.advanceTimersByTimeAsync( START_GRACE_MS - 1 );
		expect( srv.spy ).not.toHaveBeenCalled();

		pageFake.jQuery( 'wp-plugin-updating' );
		await vi.advanceTimersByTimeAsync( UPDATE_PAUSE_MS - 1 );
		expect( srv.spy ).not.toHaveBeenCalled();
		expect( scheduler.isRunning() ).toBe( false );
	} );

	it( 'aborts a running baseline when an update starts', async () => {
		const srv = server( {
			home: ( init ) => after( 400, page, init ),
			rest: ( init ) => after( 400, restOk, init ),
		} );
		const pageFake = fakePage( { fetch: srv.fetch } );
		const scheduler = bindBaseline( pageFake.win )!;
		await vi.advanceTimersByTimeAsync( START_GRACE_MS + 100 );
		expect( scheduler.isRunning() ).toBe( true );

		pageFake.jQuery( 'wp-plugin-updating' );
		await vi.advanceTimersByTimeAsync( 0 );
		expect( scheduler.isRunning() ).toBe( false );
		await vi.advanceTimersByTimeAsync( 60000 );
		expect( srv.reports ).toHaveLength( 0 );

		// Form submissions (Updates screen) abort as well; they never block it.
		const event = new Event( 'submit', { cancelable: true } );
		pageFake.fire( 'document:submit', event );
		expect( event.defaultPrevented ).toBe( false );
	} );

	it( 'waits the grace period after the page becomes visible again, and coalesces triggers', async () => {
		const srv = server();
		const pageFake = fakePage( {
			fetch: srv.fetch,
			settings: settings( { home: 10, rest: 10 } ),
		} );
		bindBaseline( pageFake.win );
		await vi.advanceTimersByTimeAsync( 10000 );
		expect( srv.spy ).not.toHaveBeenCalled();

		pageFake.fire( 'document:visibilitychange' );
		pageFake.fire( 'focus' );
		pageFake.fire( 'focus' );
		await vi.advanceTimersByTimeAsync( START_GRACE_MS - 1 );
		expect( srv.spy ).not.toHaveBeenCalled();
		await vi.advanceTimersByTimeAsync( 1000 );
		expect(
			srv.log.filter( ( entry ) => entry.what === 'start' )
		).toHaveLength( 1 );

		// Leaving the page drops a pending start.
		await vi.advanceTimersByTimeAsync( 300000 );
		pageFake.fire( 'focus' );
		pageFake.fire( 'pagehide' );
		await vi.advanceTimersByTimeAsync( 2000 );
		expect(
			srv.log.filter( ( entry ) => entry.what === 'start' )
		).toHaveLength( 1 );
	} );

	it( 'does not start while updates.js is busy', async () => {
		const srv = server();
		const page = fakePage( {
			fetch: srv.fetch,
			updates: { ajaxLocked: true, queue: [] },
		} );
		bindBaseline( page.win );
		await vi.advanceTimersByTimeAsync( 1000 );

		expect( srv.spy ).not.toHaveBeenCalled();
	} );

	it( 'shares one run between two tabs of the same login session', async () => {
		const srv = server( {
			home: ( init ) => after( 300, page, init ),
			rest: ( init ) => after( 300, restOk, init ),
		} );
		const locks = fakeLocks();
		const BroadcastChannel = channelBus();
		const tabA = fakePage( { fetch: srv.fetch, locks, BroadcastChannel } );
		const tabB = fakePage( { fetch: srv.fetch, locks, BroadcastChannel } );

		const schedulerA = bindBaseline( tabA.win )!;
		const schedulerB = bindBaseline( tabB.win )!;
		await vi.advanceTimersByTimeAsync( 1000 );

		expect(
			srv.log.filter( ( entry ) => entry.what === 'start' )
		).toHaveLength( 1 );
		expect( schedulerB.dueAt() ).toEqual( schedulerA.dueAt() );

		tabB.fire( 'focus' );
		tabB.fire( 'document:visibilitychange' );
		await vi.advanceTimersByTimeAsync( 1000 );
		expect( srv.spy ).toHaveBeenCalledTimes( 5 );

		// An update in tab A stops tab B too.
		await vi.advanceTimersByTimeAsync( 300000 );
		tabA.jQuery( 'wp-plugin-updating' );
		tabB.fire( 'focus' );
		await vi.advanceTimersByTimeAsync( 1000 );
		expect( srv.spy ).toHaveBeenCalledTimes( 5 );
		expect( CHANNEL_NAME ).toBe( 'updatelens-site-check' );
	} );

	it( 'adds nothing to the page and writes nothing to the console', async () => {
		const consoleSpies = [ 'log', 'info', 'warn', 'error', 'debug' ].map(
			( method ) =>
				vi
					.spyOn( console, method as 'log' )
					.mockImplementation( () => undefined )
		);
		document.body.innerHTML =
			'<div id="wpbody"><a href="#">Update now</a></div>';
		const before = document.documentElement.outerHTML;
		const active = document.activeElement;
		const srv = server( { rest: () => restOk( 500 ) } );

		bindBaseline( fakePage( { fetch: srv.fetch } ).win );
		await vi.advanceTimersByTimeAsync( 20000 );

		expect( srv.reports ).toHaveLength( 2 );
		expect( document.documentElement.outerHTML ).toBe( before );
		expect( document.activeElement ).toBe( active );
		consoleSpies.forEach( ( spy ) => expect( spy ).not.toHaveBeenCalled() );
	} );
} );
