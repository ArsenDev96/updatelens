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
