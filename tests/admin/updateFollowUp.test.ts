import { describe, expect, it, vi } from 'vitest';
import {
	MAX_PLUGINS,
	bindUpdateFollowUp,
	createFollowUp,
	isQueueIdle,
	sendFollowUp,
	type FollowUpSettings,
	type FollowUpWindow,
	type UpdatesState,
} from '../../src/admin/update-follow-up/followUp';
import {
	createAfterChecks,
	parseSettled,
} from '../../src/admin/update-follow-up/afterChecks';
import type { RunResult } from '../../src/admin/site-check/runner';

const SETTINGS: FollowUpSettings = {
	ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
	action: 'updatelens_settle',
	nonce: 'abc123',
	selfPlugin: 'updatelens/updatelens.php',
};

type Handler = ( event: unknown, response?: unknown ) => void;

/**
 * A page with WordPress's updates UI: a jQuery stand-in that records document
 * handlers, `wp.updates` and `fetch`. `finishJob()` replays what `updates.js`
 * and jQuery do when an update request returns, in their real order.
 */
function page( settings: unknown = SETTINGS ) {
	const handlers: Record< string, Handler[] > = {};
	const jQuery = vi.fn( () => ( {
		on: ( events: string, handler: Handler ) => {
			( handlers[ events ] ??= [] ).push( handler );
		},
	} ) );
	const updates: UpdatesState = { ajaxLocked: false, queue: [] };
	const fetch = vi.fn< typeof globalThis.fetch >( () =>
		Promise.resolve( new Response( '{}' ) )
	);
	const win: FollowUpWindow = {
		document: {} as Document,
		jQuery,
		wp: { updates },
		fetch,
		updatelensUpdateFollowUp: settings,
	};

	const trigger = ( event: string, response?: unknown ) =>
		( handlers[ event ] ?? [] ).forEach( ( handler ) =>
			handler( {}, response )
		);

	return {
		win,
		updates,
		fetch,
		handlers,
		/** The user clicks "Update now": a job starts (or is queued if one runs). */
		startJob( job: string ) {
			if ( updates.ajaxLocked ) {
				updates.queue!.push( job );
			} else {
				updates.ajaxLocked = true;
			}
		},
		/**
		 * An update request returns: `wp.ajax.send` resolves (success or error
		 * callback, which fires the event), `ajaxAlways` unlocks and starts the
		 * next queued job, then jQuery fires the global `ajaxComplete`.
		 */
		finishJob( outcome: { event?: string; response?: unknown } ) {
			if ( outcome.event ) {
				trigger( outcome.event, outcome.response );
			}
			updates.ajaxLocked = false;
			if ( updates.queue!.length ) {
				updates.queue!.shift();
				updates.ajaxLocked = true;
			}
			trigger( 'ajaxComplete' );
		},
		trigger,
	};
}

/** Plugins listed in a sent request. */
function sentPlugins( call: unknown[] ): string[] {
	const init = call[ 1 ] as RequestInit;
	return ( init.body as URLSearchParams ).getAll( 'plugins[]' );
}

const success = ( plugin: string ) => ( {
	event: 'wp-plugin-update-success',
	response: { plugin, slug: plugin.split( '/' )[ 0 ], newVersion: '2.0' },
} );

describe( 'update follow-up binding', () => {
	it( 'sends one request after a successful update while the admin stays on the page', () => {
		const p = page();
		expect( bindUpdateFollowUp( p.win ) ).toBe( true );

		p.startJob( 'acme' );
		p.finishJob( success( 'acme/acme.php' ) );

		expect( p.fetch ).toHaveBeenCalledTimes( 1 );
		const [ url, init ] = p.fetch.mock.calls[ 0 ];
		expect( url ).toBe( SETTINGS.ajaxUrl );
		expect( init ).toMatchObject( {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
		} );
		const body = init!.body as URLSearchParams;
		expect( body.get( 'action' ) ).toBe( 'updatelens_settle' );
		expect( body.get( '_ajax_nonce' ) ).toBe( 'abc123' );
		expect( body.getAll( 'plugins[]' ) ).toEqual( [ 'acme/acme.php' ] );

		// Later Ajax (e.g. Heartbeat) sends nothing more: no polling, no duplicates.
		p.trigger( 'ajaxComplete' );
		p.trigger( 'ajaxComplete' );
		expect( p.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'waits for queued updates and then sends one request for all', () => {
		const p = page();
		bindUpdateFollowUp( p.win );

		p.startJob( 'a' );
		p.startJob( 'b' ); // Queued while "a" runs.
		p.startJob( 'c' );

		p.finishJob( success( 'a/a.php' ) ); // "b" starts.
		expect( p.fetch ).not.toHaveBeenCalled();
		p.finishJob( success( 'b/b.php' ) ); // "c" starts.
		expect( p.fetch ).not.toHaveBeenCalled();
		p.finishJob( success( 'c/c.php' ) ); // Queue idle.

		expect( p.fetch ).toHaveBeenCalledTimes( 1 );
		expect( sentPlugins( p.fetch.mock.calls[ 0 ] ) ).toEqual( [
			'a/a.php',
			'b/b.php',
			'c/c.php',
		] );
	} );

	it( 'sends for earlier successes when the last queued update fails', () => {
		const p = page();
		bindUpdateFollowUp( p.win );

		p.startJob( 'a' );
		p.startJob( 'b' );
		p.finishJob( success( 'a/a.php' ) );
		p.finishJob( {
			event: 'wp-plugin-update-error',
			response: { plugin: 'b/b.php', errorCode: 'download_failed' },
		} );

		expect( p.fetch ).toHaveBeenCalledTimes( 1 );
		expect( sentPlugins( p.fetch.mock.calls[ 0 ] ) ).toEqual( [
			'a/a.php',
		] );
	} );

	it( 'sends nothing after a failed update, a theme update or other Ajax', () => {
		const p = page();
		bindUpdateFollowUp( p.win );

		p.startJob( 'a' );
		p.finishJob( {
			event: 'wp-plugin-update-error',
			response: { plugin: 'a/a.php' },
		} );
		p.startJob( 'theme' );
		p.finishJob( {
			event: 'wp-theme-update-success',
			response: { slug: 'twentytwentyfive' },
		} );
		p.trigger( 'wp-plugin-install-success', { plugin: 'new/new.php' } );
		p.trigger( 'ajaxComplete' );

		expect( p.fetch ).not.toHaveBeenCalled();
	} );

	it( 'ignores UpdateLens itself and malformed success responses', () => {
		const p = page();
		bindUpdateFollowUp( p.win );

		p.startJob( 'self' );
		p.finishJob( success( SETTINGS.selfPlugin ) );
		p.startJob( 'x' );
		p.finishJob( { event: 'wp-plugin-update-success', response: null } );
		p.startJob( 'y' );
		p.finishJob( {
			event: 'wp-plugin-update-success',
			response: { plugin: 42 },
		} );

		expect( p.fetch ).not.toHaveBeenCalled();
	} );

	it( 'sends again for a later update after the first follow-up', () => {
		const p = page();
		bindUpdateFollowUp( p.win );

		p.startJob( 'a' );
		p.finishJob( success( 'a/a.php' ) );
		p.startJob( 'b' );
		p.finishJob( success( 'b/b.php' ) );

		expect( p.fetch ).toHaveBeenCalledTimes( 2 );
		expect( sentPlugins( p.fetch.mock.calls[ 1 ] ) ).toEqual( [
			'b/b.php',
		] );
	} );

	it( 'does not bind without its settings, jQuery, wp.updates or fetch', () => {
		expect( bindUpdateFollowUp( page( null ).win ) ).toBe( false );
		expect(
			bindUpdateFollowUp( page( { ...SETTINGS, nonce: 1 } ).win )
		).toBe( false );

		const noJQuery = page();
		delete noJQuery.win.jQuery;
		expect( bindUpdateFollowUp( noJQuery.win ) ).toBe( false );

		const noUpdates = page();
		noUpdates.win.wp = {};
		expect( bindUpdateFollowUp( noUpdates.win ) ).toBe( false );

		const noFetch = page();
		delete noFetch.win.fetch;
		expect( bindUpdateFollowUp( noFetch.win ) ).toBe( false );
	} );
} );

describe( 'follow-up state', () => {
	it( 'keeps each plugin once, in the order of its latest update, at most MAX_PLUGINS', () => {
		const send = vi.fn();
		const followUp = createFollowUp( { isIdle: () => true, send } );

		followUp.pluginUpdated( 'a/a.php' );
		followUp.pluginUpdated( 'b/b.php' );
		followUp.pluginUpdated( 'a/a.php' );
		followUp.check();
		expect( send ).toHaveBeenCalledWith( [ 'b/b.php', 'a/a.php' ] );

		for ( let i = 0; i < MAX_PLUGINS + 5; i++ ) {
			followUp.pluginUpdated( `p${ i }/p${ i }.php` );
		}
		followUp.check();
		const plugins = send.mock.calls[ 1 ][ 0 ] as string[];
		expect( plugins ).toHaveLength( MAX_PLUGINS );
		expect( plugins[ 0 ] ).toBe( 'p5/p5.php' );
	} );

	it( 'treats a locked or non-empty queue as busy', () => {
		expect( isQueueIdle( { ajaxLocked: false, queue: [] } ) ).toBe( true );
		expect( isQueueIdle( {} ) ).toBe( true );
		expect( isQueueIdle( { ajaxLocked: true, queue: [] } ) ).toBe( false );
		expect( isQueueIdle( { ajaxLocked: false, queue: [ {} ] } ) ).toBe(
			false
		);
	} );
} );

describe( 'sending', () => {
	it( 'ignores a failed or blocked request', async () => {
		const fetch = vi.fn( () => Promise.reject( new Error( 'blocked' ) ) );
		expect( () =>
			sendFollowUp(
				fetch as unknown as typeof globalThis.fetch,
				SETTINGS,
				[ 'a/a.php' ]
			)
		).not.toThrow();
		await Promise.resolve();
		expect( fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'retries without keepalive if the browser rejects it synchronously', () => {
		const fetch = vi.fn( ( _url: string, init: RequestInit ) => {
			if ( init.keepalive ) {
				throw new TypeError( 'keepalive not supported' );
			}
			return Promise.resolve( new Response( '{}' ) );
		} );

		sendFollowUp( fetch as unknown as typeof globalThis.fetch, SETTINGS, [
			'a/a.php',
		] );

		expect( fetch ).toHaveBeenCalledTimes( 2 );
		expect( fetch.mock.calls[ 1 ][ 1 ].keepalive ).toBeUndefined();
	} );
} );

describe( 'follow-up response', () => {
	it( 'resolves with the data of a successful response, else null', async () => {
		const respond = ( response: Response | Error ) =>
			sendFollowUp(
				vi.fn( () =>
					response instanceof Error
						? Promise.reject( response )
						: Promise.resolve( response )
				) as unknown as typeof globalThis.fetch,
				SETTINGS,
				[ 'a/a.php' ]
			);

		await expect(
			respond(
				new Response(
					JSON.stringify( { success: true, data: { results: [] } } )
				)
			)
		).resolves.toEqual( { results: [] } );
		await expect(
			respond(
				new Response(
					JSON.stringify( {
						success: false,
						data: { code: 'invalid_nonce' },
					} ),
					{ status: 403 }
				)
			)
		).resolves.toBeNull();
		await expect( respond( new Response( '0' ) ) ).resolves.toBeNull();
		await expect( respond( new Response( '<html>' ) ) ).resolves.toBeNull();
		await expect( respond( new Error( 'offline' ) ) ).resolves.toBeNull();
	} );

	it( 'lists only analyses the follow-up settled with site checks wanted', () => {
		expect(
			parseSettled( {
				results: [
					{
						plugin: 'a/a.php',
						result: 'settled',
						analysis: 5,
						site_checks: true,
					},
					{
						plugin: 'b/b.php',
						result: 'settled',
						analysis: 6,
						site_checks: false,
					},
					{ plugin: 'c/c.php', result: 'not_pending' },
					{
						plugin: 'd/d.php',
						result: 'conflict',
						analysis: 7,
						site_checks: true,
					},
					{
						plugin: 'e/e.php',
						result: 'settled',
						analysis: '8',
						site_checks: true,
					},
					{
						plugin: 'f/f.php',
						result: 'settled',
						analysis: 0,
						site_checks: true,
					},
					{
						plugin: 'g/g.php',
						result: 'settled',
						analysis: 5,
						site_checks: true,
					},
					null,
				],
			} )
		).toEqual( [ 5 ] );
		for ( const data of [ null, undefined, 'x', {}, { results: {} } ] ) {
			expect( parseSettled( data ) ).toEqual( [] );
		}
	} );
} );

describe( 'AFTER checks', () => {
	const result = { status: 'started' } as unknown as RunResult;
	const settled = {
		success: true,
		data: {
			results: [
				{
					plugin: 'a/a.php',
					result: 'settled',
					analysis: 9,
					site_checks: true,
				},
			],
		},
	};
	const sameOrigin = () => ( {
		...SETTINGS,
		ajaxUrl: `${ window.location.origin }/wp-admin/admin-ajax.php`,
		siteChecks: { nonce: 'sc-nonce' },
	} );

	it( 'runs each settled analysis once, one after another, while idle', async () => {
		const runs: number[] = [];
		const after = createAfterChecks( {
			isIdle: () => true,
			run: async ( analysis ) => {
				runs.push( analysis );
				return result;
			},
		} );
		const token = after.followUpSent();

		await expect( after.settled( [ 5, 6 ], token ) ).resolves.toEqual( [
			5, 6,
		] );
		expect( runs ).toEqual( [ 5, 6 ] );
		expect( after.isRunning() ).toBe( false );
	} );

	it( 'starts nothing while updates are queued or after an update started', async () => {
		const run = vi.fn( async () => result );
		let idle = false;
		const after = createAfterChecks( { isIdle: () => idle, run } );

		await after.settled( [ 5 ], after.followUpSent() );
		expect( run ).not.toHaveBeenCalled();

		idle = true;
		const token = after.followUpSent();
		after.updateStarted();
		await after.settled( [ 5 ], token );
		expect( run ).not.toHaveBeenCalled();
	} );

	it( 'aborts the running analysis when an update starts and drops the rest', async () => {
		const signals: AbortSignal[] = [];
		const after = createAfterChecks( {
			isIdle: () => true,
			run: ( _analysis, signal ) => {
				signals.push( signal );
				return new Promise( ( resolve ) =>
					signal.addEventListener( 'abort', () => resolve( result ) )
				);
			},
		} );
		const done = after.settled( [ 5, 6 ], after.followUpSent() );
		expect( after.isRunning() ).toBe( true );
		await expect(
			after.settled( [ 7 ], after.followUpSent() )
		).resolves.toEqual( [] );

		after.updateStarted();
		await expect( done ).resolves.toEqual( [ 5 ] );
		expect( signals ).toHaveLength( 1 );
		expect( signals[ 0 ].aborted ).toBe( true );
	} );

	it( 'starts the AFTER run from the follow-up response, with the site check nonce', async () => {
		const p = page( sameOrigin() );
		p.fetch.mockImplementation( ( _url, init ) => {
			const fields = new URLSearchParams( String( init?.body ) );
			const data =
				fields.get( 'action' ) === 'updatelens_settle'
					? settled.data
					: {
							code: 'not_needed',
							purpose: 'after',
							reason: 'no_baseline',
						};
			return Promise.resolve(
				new Response( JSON.stringify( { success: true, data } ) )
			);
		} );
		expect( bindUpdateFollowUp( p.win ) ).toBe( true );

		p.startJob( 'a' );
		p.finishJob( success( 'a/a.php' ) );
		await vi.waitFor( () => expect( p.fetch ).toHaveBeenCalledTimes( 2 ) );

		const start = Object.fromEntries(
			new URLSearchParams( String( p.fetch.mock.calls[ 1 ][ 1 ]?.body ) )
		);
		expect( start ).toEqual( {
			action: 'updatelens_site_check_start',
			_ajax_nonce: 'sc-nonce',
			purpose: 'after',
			analysis: '9',
		} );
	} );

	it( 'starts nothing without site check settings', async () => {
		const plain = page();
		plain.fetch.mockImplementation( () =>
			Promise.resolve( new Response( JSON.stringify( settled ) ) )
		);
		bindUpdateFollowUp( plain.win );
		plain.startJob( 'a' );
		plain.finishJob( success( 'a/a.php' ) );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		expect( plain.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'starts nothing when an update started before the follow-up response', async () => {
		const busy = page( sameOrigin() );
		let answer: ( response: Response ) => void = () => undefined;
		busy.fetch.mockImplementation(
			() =>
				new Promise( ( resolve ) => {
					answer = resolve;
				} )
		);
		bindUpdateFollowUp( busy.win );
		busy.startJob( 'a' );
		busy.finishJob( success( 'a/a.php' ) );
		busy.trigger( 'wp-plugin-updating' );
		answer( new Response( JSON.stringify( settled ) ) );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		expect( busy.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'rejects malformed site check settings', () => {
		expect(
			bindUpdateFollowUp( page( { ...SETTINGS, siteChecks: {} } ).win )
		).toBe( false );
		expect(
			bindUpdateFollowUp(
				page( { ...SETTINGS, siteChecks: 'sc-nonce' } ).win
			)
		).toBe( false );
	} );
} );
