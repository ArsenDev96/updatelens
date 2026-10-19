import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
	bindBaseline,
	parseBaselineSettings,
	type BaselineWindow,
} from '../../src/admin/site-check/baseline';
import type { CheckId } from '../../src/admin/site-check/contract';
import {
	createReadiness,
	mountReadinessLine,
	parseReadinessSettings,
	type ReadinessCheck,
	type ReadinessSettings,
} from '../../src/admin/site-check/readiness';
import type {
	CheckOutcome,
	RunResult,
} from '../../src/admin/site-check/runner';

/*
 * The readiness line on Installed Plugins: "ready" only for earlier checks
 * that an update starting now could be compared with, never for missing,
 * running, inconclusive, failed, expired or outdated ones. Dashboard →
 * Updates gets a fixed note with a link to Installed Plugins instead.
 */

const NOW = 1760090000000;

const LABELS = {
	prefix: 'UpdateLens:',
	preparing: 'Preparing website checks…',
	preparing_next: 'Preparing website checks for another update…',
	ready: 'Ready to compare website responses',
	ready_home: 'Ready to compare the homepage only',
	ready_rest: 'Ready to compare the REST API only',
	unavailable: 'Website checks unavailable for now',
	other_screen:
		'Website responses are compared only for updates of a single plugin: select one plugin on this screen, or use “Update now” on Installed Plugins.',
	plugins_link: 'Go to Installed Plugins',
};

const PLUGINS_URL = `${ window.location.origin }/wp-admin/plugins.php?plugin_status=upgrade`;

const none: ReadinessCheck = { state: 'none', verdict: null, expires_in: 0 };

const status = (
	checks: Partial< Record< CheckId, ReadinessCheck > > = {}
): ReadinessSettings => ( {
	max_age: 600,
	screen: 'plugins',
	plugins_url: PLUGINS_URL,
	checks: { home: none, rest: none, ...checks },
	labels: LABELS,
} );

const outcome = (
	check: CheckId,
	state: CheckOutcome[ 'status' ],
	extra: Record< string, unknown > = {}
) => ( { check, status: state, ...extra } ) as unknown as CheckOutcome;

const run = (
	home: CheckOutcome,
	rest: CheckOutcome,
	extra: Partial< RunResult > = {}
): RunResult => ( { status: 'started', checks: { home, rest }, ...extra } );

const reported = ( check: CheckId, verdict: string ) =>
	outcome( check, 'reported', { verdict, reason: null, attempts: [] } );

beforeEach( () => {
	vi.useFakeTimers( { toFake: [ 'setTimeout', 'clearTimeout', 'Date' ] } );
	vi.setSystemTime( NOW );
} );

afterEach( () => {
	vi.useRealTimers();
	document.body.innerHTML = '';
} );

describe( 'parseReadinessSettings', () => {
	it( 'accepts what PHP prints and rejects anything else', () => {
		expect( parseReadinessSettings( status() ) ).toEqual( status() );
		for ( const broken of [
			undefined,
			'x',
			{ ...status(), max_age: 0 },
			{ ...status(), labels: { ...LABELS, ready: 3 } },
			{ ...status(), screen: 'dashboard' },
			{ ...status(), plugins_url: null },
			{ ...status(), checks: { home: none } },
			{
				...status(),
				checks: { home: { ...none, state: 'maybe' }, rest: none },
			},
			{
				...status(),
				checks: { home: { ...none, verdict: 'great' }, rest: none },
			},
			{
				...status(),
				checks: { home: { ...none, expires_in: -1 }, rest: none },
			},
		] ) {
			expect( parseReadinessSettings( broken ) ).toBeNull();
		}
	} );

	it( 'is optional in the baseline settings: without it the baselines still run', () => {
		const base = {
			ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
			nonce: 'n',
			schedule: {
				refresh_after: 300,
				retry_after_failure: 120,
				refresh_in: { home: 0, rest: 0 },
			},
		};
		expect( parseBaselineSettings( base )?.status ).toBeUndefined();
		expect(
			parseBaselineSettings( { ...base, status: { broken: true } } )
		).toEqual( base );
		expect(
			parseBaselineSettings( { ...base, status: status() } )?.status
		).toEqual( status() );
	} );
} );

describe( 'createReadiness', () => {
	it( 'is preparing (idle or running), then ready once both checks reported', () => {
		const readiness = createReadiness( status(), Date.now );
		expect( readiness.state() ).toBe( 'preparing' );
		expect( readiness.isRunning() ).toBe( false );
		readiness.runStarted();
		expect( readiness.state() ).toBe( 'preparing' );
		expect( readiness.isRunning() ).toBe( true );
		readiness.runFinished(
			run( reported( 'home', 'pass' ), reported( 'rest', 'pass' ) )
		);
		expect( readiness.state() ).toBe( 'ready' );
		expect( readiness.isRunning() ).toBe( false );
	} );

	it( 'a verified failure or redirect is ready (it can be compared); inconclusive never is', () => {
		const readiness = createReadiness( status(), Date.now );
		readiness.runFinished(
			run(
				reported( 'home', 'fail' ),
				reported( 'rest', 'inconclusive' )
			)
		);
		expect( readiness.state() ).toBe( 'ready_home' );

		const other = createReadiness( status(), Date.now );
		other.runFinished(
			run(
				reported( 'home', 'inconclusive' ),
				reported( 'rest', 'redirect' )
			)
		);
		expect( other.state() ).toBe( 'ready_rest' );

		const neither = createReadiness( status(), Date.now );
		neither.runFinished(
			run(
				reported( 'home', 'inconclusive' ),
				reported( 'rest', 'inconclusive' )
			)
		);
		expect( neither.state() ).toBe( 'unavailable' );
	} );

	it( 'starts ready from PHP and stops being ready when too old', () => {
		const readiness = createReadiness(
			status( {
				home: { state: 'ready', verdict: 'pass', expires_in: 100 },
				rest: { state: 'ready', verdict: 'pass', expires_in: 400 },
			} ),
			Date.now
		);
		expect( readiness.state() ).toBe( 'ready' );
		expect( readiness.nextChange() ).toBe( NOW + 100000 );
		vi.setSystemTime( NOW + 100000 );
		expect( readiness.state() ).toBe( 'ready_rest' );
		vi.setSystemTime( NOW + 400000 );
		expect( readiness.state() ).toBe( 'preparing' );
		expect( readiness.nextChange() ).toBeNull();
	} );

	it( 'stays ready while a refresh runs, and a failed refresh keeps a usable earlier check', () => {
		const readiness = createReadiness(
			status( {
				home: { state: 'ready', verdict: 'pass', expires_in: 300 },
				rest: { state: 'ready', verdict: 'pass', expires_in: 300 },
			} ),
			Date.now
		);
		readiness.runStarted();
		expect( readiness.state() ).toBe( 'ready' );
		readiness.runFinished(
			run(
				outcome( 'home', 'report_failed', { error: 'unavailable' } ),
				outcome( 'rest', 'expired', { attempts: [] } )
			)
		);
		expect( readiness.state() ).toBe( 'ready' );
	} );

	it( 'an update makes every earlier check outdated until new ones are reported', () => {
		const readiness = createReadiness(
			status( {
				home: { state: 'ready', verdict: 'pass', expires_in: 300 },
				rest: { state: 'ready', verdict: 'pass', expires_in: 300 },
			} ),
			Date.now
		);
		readiness.updateStarted();
		expect( readiness.state() ).toBe( 'preparing_next' );
		readiness.runStarted();
		expect( readiness.state() ).toBe( 'preparing_next' );
		readiness.runFinished(
			run( reported( 'home', 'pass' ), reported( 'rest', 'pass' ) )
		);
		expect( readiness.state() ).toBe( 'ready' );
	} );

	it( 'failed or refused runs are unavailable, never ready', () => {
		const readiness = createReadiness( status(), Date.now );
		readiness.runFinished( {
			status: 'start_failed',
			error: 'rate_limited',
			checks: {
				home: outcome( 'home', 'not_run', { error: 'start_failed' } ),
				rest: outcome( 'rest', 'not_run', { error: 'start_failed' } ),
			},
		} );
		expect( readiness.state() ).toBe( 'unavailable' );

		const partly = createReadiness( status(), Date.now );
		partly.runFinished(
			run(
				outcome( 'home', 'report_failed', { error: 'unavailable' } ),
				reported( 'rest', 'pass' )
			)
		);
		expect( partly.state() ).toBe( 'ready_rest' );

		const stopped = createReadiness( status(), Date.now );
		stopped.stop();
		expect( stopped.state() ).toBe( 'unavailable' );
	} );

	it( 'a check that cannot run here is unsupported; the other can still be ready', () => {
		const readiness = createReadiness(
			status( {
				home: { state: 'unsupported', verdict: null, expires_in: 0 },
			} ),
			Date.now
		);
		readiness.runFinished(
			run(
				outcome( 'home', 'not_run', { error: 'not_issued' } ),
				reported( 'rest', 'pass' )
			)
		);
		expect( readiness.state() ).toBe( 'ready_rest' );

		const both = createReadiness( status(), Date.now );
		both.runFinished(
			run(
				outcome( 'home', 'not_run', { error: 'cross_origin' } ),
				outcome( 'rest', 'not_run', { error: 'mixed_content' } )
			)
		);
		expect( both.state() ).toBe( 'unavailable' );
	} );

	it( 'a cancelled run changes nothing', () => {
		const readiness = createReadiness( status(), Date.now );
		readiness.runStarted();
		readiness.runFinished( {
			status: 'cancelled',
			checks: {
				home: outcome( 'home', 'cancelled' ),
				rest: outcome( 'rest', 'cancelled' ),
			},
		} );
		expect( readiness.state() ).toBe( 'preparing' );
		readiness.runFinished( null );
		expect( readiness.state() ).toBe( 'preparing' );
	} );

	it( 'shares stored earlier checks with other tabs, and ignores malformed ones', () => {
		const source = createReadiness( status(), Date.now );
		source.runFinished(
			run( reported( 'home', 'pass' ), reported( 'rest', 'fail' ) )
		);
		const shared = source.shared();
		expect( shared ).toEqual( {
			home: { verdict: 'pass', until: NOW + 600000 },
			rest: { verdict: 'fail', until: NOW + 600000 },
		} );

		const other = createReadiness( status(), Date.now );
		other.receive( {
			home: { verdict: 'nope', until: 1 },
			rest: { verdict: 'pass', until: Infinity },
		} as never );
		expect( other.state() ).toBe( 'preparing' );
		other.receive( shared );
		expect( other.state() ).toBe( 'ready' );
	} );
} );

/**
 * A Plugins screen header.
 *
 * @param title Whether the title has WordPress's header end marker.
 */
function screenHeader( title: 'plugins' | 'updates' = 'plugins' ) {
	document.body.innerHTML =
		title === 'plugins'
			? '<div id="wpbody-content"><div class="wrap"><h1 class="wp-heading-inline">Plugins</h1> <a class="page-title-action" href="#">Add Plugin</a><hr class="wp-header-end"></div></div>'
			: '<div id="wpbody-content"><div class="wrap"><h1>WordPress Updates</h1><p>Last checked…</p></div></div>';
}

describe( 'mountReadinessLine', () => {
	it( 'sits next to the title as a polite status, never a control', () => {
		screenHeader();
		const line = mountReadinessLine( document, status() )!;
		line.render( 'preparing', true );

		const element = document.querySelector(
			'.updatelens-site-check-status'
		)!;
		expect( element.getAttribute( 'role' ) ).toBe( 'status' );
		expect( element.nextElementSibling?.className ).toBe( 'wp-header-end' );
		expect( element.textContent ).toBe(
			'UpdateLens: Preparing website checks…'
		);
		expect( element.querySelector( 'button, a, input' ) ).toBeNull();
		const icon = element.querySelector( '.dashicons' )!;
		expect( icon.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
		expect( icon.classList.contains( 'dashicons-update' ) ).toBe( true );
		line.render( 'preparing', false );
		expect( icon.classList.contains( 'dashicons-clock' ) ).toBe( true );

		line.render( 'ready', false );
		expect( element.textContent ).toBe(
			'UpdateLens: Ready to compare website responses'
		);
		expect( ( element as HTMLElement ).dataset.state ).toBe( 'ready' );
		line.remove();
		expect(
			document.querySelector( '.updatelens-site-check-status' )
		).toBeNull();
	} );

	it( 'on Dashboard → Updates is a fixed note below the title with a link to Installed Plugins', () => {
		screenHeader( 'updates' );
		const line = mountReadinessLine( document, {
			...status(),
			screen: 'updates',
		} )!;
		line.render( 'ready', false );
		const element = document.querySelector( 'h1' )!.nextElementSibling!;
		expect( element.className ).toBe( 'updatelens-site-check-status' );
		// Never "ready" here: updates from this screen get no later check.
		expect( element.textContent ).toBe(
			`UpdateLens: ${ LABELS.other_screen } ${ LABELS.plugins_link }`
		);
		expect( element.getAttribute( 'role' ) ).toBeNull();
		expect( element.querySelector( 'a' )?.getAttribute( 'href' ) ).toBe(
			PLUGINS_URL
		);

		// A link to another site is never rendered.
		screenHeader( 'updates' );
		mountReadinessLine( document, {
			...status(),
			screen: 'updates',
			plugins_url: 'https://elsewhere.test/wp-admin/plugins.php',
		} );
		expect(
			document.querySelector( '.updatelens-site-check-status a' )
		).toBeNull();
	} );

	it( 'is nowhere without a title', () => {
		document.body.innerHTML = '<div id="wpbody-content"></div>';
		expect( mountReadinessLine( document, status() ) ).toBeNull();
	} );
} );

describe( 'bindBaseline with the readiness line', () => {
	/**
	 * A page with a real header and a fake server answering every check.
	 *
	 * @param start     Answer of the start request.
	 * @param refreshIn Seconds until the checks are due (0: due, nothing stored).
	 */
	function page( start?: unknown, refreshIn = 300 ) {
		screenHeader();
		const handlers = new Map< string, () => void >();
		const origin = 'https://example.test';
		const fetchFn = vi.fn(
			async ( input: RequestInfo | URL, init?: RequestInit ) => {
				const fields = Object.fromEntries(
					new URLSearchParams( String( init?.body ?? '' ) )
				);
				if ( String( input ).endsWith( 'admin-ajax.php' ) ) {
					if ( fields.action === 'updatelens_site_check_start' ) {
						return new Response(
							JSON.stringify(
								start ?? {
									success: true,
									data: {
										code: 'not_needed',
										purpose: 'before',
										reason: 'no_updates',
									},
								}
							),
							{
								status: 200,
								headers: { 'Content-Type': 'application/json' },
							}
						);
					}
				}
				throw new TypeError( 'unexpected' );
			}
		);
		const win = {
			document: {
				visibilityState: 'visible',
				addEventListener: () => undefined,
				querySelector: document.querySelector.bind( document ),
				createElement: document.createElement.bind( document ),
			},
			location: { origin, protocol: 'https:' },
			fetch: fetchFn,
			wp: { updates: { ajaxLocked: false, queue: [] } },
			jQuery: () => ( {
				on: ( events: string, handler: () => void ) =>
					handlers.set( events, handler ),
			} ),
			addEventListener: () => undefined,
			updatelensSiteCheckBaseline: {
				ajaxUrl: `${ origin }/wp-admin/admin-ajax.php`,
				nonce: 'n',
				schedule: {
					refresh_after: 300,
					retry_after_failure: 120,
					refresh_in: { home: refreshIn, rest: refreshIn },
				},
				status:
					refreshIn > 0
						? status( {
								home: {
									state: 'ready',
									verdict: 'pass',
									expires_in: 590,
								},
								rest: {
									state: 'ready',
									verdict: 'pass',
									expires_in: 590,
								},
							} )
						: status(),
			},
		};
		return {
			win: win as unknown as BaselineWindow,
			fetchFn,
			fire: ( event: string ) => handlers.get( event )?.(),
			text: () =>
				document.querySelector( '.updatelens-site-check-status' )
					?.textContent ?? null,
		};
	}

	it( 'shows fresh earlier checks as ready without a request, and an update makes them outdated', async () => {
		const p = page();
		bindBaseline( p.win );
		expect( p.text() ).toBe(
			'UpdateLens: Ready to compare website responses'
		);
		await vi.advanceTimersByTimeAsync( 1000 );
		expect( p.fetchFn ).not.toHaveBeenCalled();

		p.fire( 'wp-plugin-updating' );
		expect( p.text() ).toBe(
			'UpdateLens: Preparing website checks for another update…'
		);
	} );

	it( 'asks for new earlier checks once the update pause ends, without a focus change', async () => {
		const p = page();
		bindBaseline( p.win );
		await vi.advanceTimersByTimeAsync( 1000 );
		p.fire( 'wp-plugin-updating' );
		await vi.advanceTimersByTimeAsync( 59000 );
		expect( p.fetchFn ).not.toHaveBeenCalled();
		await vi.advanceTimersByTimeAsync( 2000 );
		expect( p.fetchFn ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stops being ready when the earlier checks get too old to be attached', async () => {
		const p = page();
		bindBaseline( p.win );
		await vi.advanceTimersByTimeAsync( 589000 );
		expect( p.text() ).toBe(
			'UpdateLens: Ready to compare website responses'
		);
		await vi.advanceTimersByTimeAsync( 2000 );
		expect( p.text() ).toBe( 'UpdateLens: Preparing website checks…' );
	} );

	it( 'disappears when no plugin update is left', async () => {
		const p = page( undefined, 0 );
		bindBaseline( p.win );
		expect( p.text() ).toBe( 'UpdateLens: Preparing website checks…' );
		// The due run asks; the server says nothing is left to update.
		await vi.advanceTimersByTimeAsync( 1000 );
		expect( p.fetchFn ).toHaveBeenCalledTimes( 1 );
		expect( p.text() ).toBeNull();
	} );
} );
