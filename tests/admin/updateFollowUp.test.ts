import { describe, expect, it, vi } from 'vitest';
import {
	MAX_PLUGINS,
	bindUpdateFollowUp,
	createFollowUp,
	isPluginUpdateRequest,
	isUpdatesIdle,
	observeUpdateRequest,
	respondedPlugin,
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

type Handler = ( event: unknown, ...args: unknown[] ) => void;

/** XMLHttpRequest stand-in: the fields the follow-up reads, and its events. */
class FakeXhr extends EventTarget {
	status = 0;
	responseText = '';
}

/** jQuery's request settings as `ajaxSend` passes them. */
interface AjaxSettings {
	type: string;
	data: string;
	xhr: () => unknown;
}

/** What `wp.updates.ajax( 'update-plugin', … )` sends (jQuery has serialized it). */
const updateData = ( plugin: string ) =>
	new URLSearchParams( {
		plugin,
		slug: plugin.split( '/' )[ 0 ],
		_ajax_nonce: 'updates-nonce',
		_fs_nonce: '',
		username: '',
		password: '',
		connection_type: '',
		public_key: '',
		private_key: '',
		action: 'update-plugin',
	} ).toString();

/** How an update request ends: its response and what core then does. */
interface Outcome {
	/** HTTP status (default 200). */
	status?: number;
	/** Response body (JSON-encoded unless a string). */
	body: unknown;
	/** The event core's callback fires, with the response's `data`. */
	event?: string;
	/**
	 * Core's callback throws before firing its event (WordPress 6.5+,
	 * `updatePluginError()` on Installed Plugins): `ajaxAlways` never unlocks,
	 * the next queued job never starts and jQuery's `ajaxComplete` never fires.
	 */
	coreThrows?: boolean;
}

/**
 * A page with WordPress's updates UI: a jQuery stand-in that records document
 * handlers, `wp.updates` and `fetch`. Every request goes through `ajaxSend`
 * and then the settings' `xhr` factory, like jQuery's XHR transport;
 * `finishJob()` replays what jQuery and `updates.js` do when the running
 * update request returns, in their real order (the request's `load` with
 * core's callbacks, then its `loadend`).
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
	// jQuery.ajaxSettings.xhr: the same factory for every request.
	const createXhr = vi.fn( () => new FakeXhr() );
	let running: FakeXhr | null = null;

	const trigger = ( event: string, ...args: unknown[] ) =>
		( handlers[ event ] ?? [] ).forEach( ( handler ) =>
			handler( {}, ...args )
		);

	/** One jQuery request: `ajaxSend`, then the transport creates its XHR. */
	const ajax = ( data: string, type = 'POST' ) => {
		const ajaxSettings: AjaxSettings = { type, data, xhr: createXhr };
		trigger( 'ajaxSend', {}, ajaxSettings );
		return { settings: ajaxSettings, xhr: ajaxSettings.xhr() as FakeXhr };
	};
	const send = ( plugin: string ) => {
		running = ajax( updateData( plugin ) ).xhr;
	};

	return {
		win,
		updates,
		fetch,
		handlers,
		createXhr,
		ajax,
		/** The user clicks "Update now": a job starts (or is queued if one runs). */
		startJob( plugin: string ) {
			trigger( 'wp-plugin-updating' );
			if ( updates.ajaxLocked ) {
				updates.queue!.push( plugin );
			} else {
				updates.ajaxLocked = true;
				send( plugin );
			}
		},
		/**
		 * The running update request returns: on `load`, jQuery runs core's
		 * callback (which fires the event), `ajaxAlways` unlocks and starts
		 * the next queued job, then jQuery fires `ajaxComplete`; then the
		 * request's own `loadend`.
		 */
		finishJob( outcome: Outcome ) {
			const xhr = running!;
			running = null;
			xhr.status = outcome.status ?? 200;
			xhr.responseText =
				typeof outcome.body === 'string'
					? outcome.body
					: JSON.stringify( outcome.body );
			if ( ! outcome.coreThrows ) {
				if ( outcome.event ) {
					trigger(
						outcome.event,
						( outcome.body as { data?: unknown } ).data
					);
				}
				updates.ajaxLocked = false;
				if ( updates.queue!.length ) {
					updates.ajaxLocked = true;
					send( updates.queue!.shift() as string );
				}
				trigger( 'ajaxComplete' );
			}
			xhr.dispatchEvent( new Event( 'loadend' ) );

			return xhr;
		},
		trigger,
	};
}

/** Plugins listed in a sent request. */
function sentPlugins( call: unknown[] ): string[] {
	const init = call[ 1 ] as RequestInit;
	return ( init.body as URLSearchParams ).getAll( 'plugins[]' );
}

/** `wp_ajax_update_plugin()`'s answer fields for a plugin. */
const answer = ( plugin: string ) => ( {
	update: 'plugin',
	slug: plugin.split( '/' )[ 0 ],
	oldVersion: 'Version 1.0',
	plugin,
	pluginName: 'Acme',
	debug: [ 'Plugin updated successfully.' ],
} );

/** A successful update (scenario A). */
const success = ( plugin: string ): Outcome => ( {
	body: {
		success: true,
		data: { ...answer( plugin ), newVersion: 'Version 2.0' },
	},
	event: 'wp-plugin-update-success',
} );

/**
 * An update WordPress answered with an error: the plugin installed and a
 * translation download failed (scenario B), or the update itself failed
 * (C1: download failed; C2: `incompatible_archive_no_plugins`). The browser
 * cannot tell them apart; the server can.
 */
const failure = (
	plugin: string,
	error: { errorMessage: string; errorCode?: string },
	coreThrows = false
): Outcome => ( {
	body: {
		success: false,
		data: { ...answer( plugin ), newVersion: '', ...error },
	},
	event: 'wp-plugin-update-error',
	coreThrows,
} );

const TRANSLATION_401 = { errorMessage: 'Download failed. Unauthorized' };

describe( 'update follow-up binding', () => {
	it( 'sends one request after a successful update while the admin stays on the page', () => {
		const p = page();
		expect( bindUpdateFollowUp( p.win ) ).toBe( true );

		p.startJob( 'acme/acme.php' );
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
		p.ajax(
			'interval=60&_nonce=hb&action=heartbeat&screen_id=plugins'
		).xhr.dispatchEvent( new Event( 'loadend' ) );
		p.trigger( 'ajaxComplete' );
		expect( p.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'waits for queued updates and then sends one request for all', () => {
		const p = page();
		bindUpdateFollowUp( p.win );

		p.startJob( 'a/a.php' );
		p.startJob( 'b/b.php' ); // Queued while "a" runs.
		p.startJob( 'c/c.php' );

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

	it( 'lists a plugin whose update WordPress answered with an error (a translation download failed)', () => {
		// WordPress 6.2–6.4: core fires its error event, unlocks and fires ajaxComplete.
		const p = page();
		bindUpdateFollowUp( p.win );

		p.startJob( 'acme/acme.php' );
		p.finishJob( failure( 'acme/acme.php', TRANSLATION_401 ) );

		expect( p.fetch ).toHaveBeenCalledTimes( 1 );
		expect( sentPlugins( p.fetch.mock.calls[ 0 ] ) ).toEqual( [
			'acme/acme.php',
		] );
	} );

	it( 'sends one request when core’s error handler throws before jQuery’s completion events (WordPress 6.5+)', () => {
		const p = page();
		bindUpdateFollowUp( p.win );
		const errors = vi.fn();
		const ajaxComplete = vi.fn();
		( p.handlers[ 'wp-plugin-update-error' ] ??= [] ).push( errors );
		p.handlers.ajaxComplete.push( ajaxComplete );

		p.startJob( 'acme/acme.php' );
		p.finishJob( failure( 'acme/acme.php', TRANSLATION_401, true ) );

		expect( errors ).not.toHaveBeenCalled();
		expect( ajaxComplete ).not.toHaveBeenCalled();
		expect( p.updates.ajaxLocked ).toBe( true ); // Stuck until the page reloads.
		expect( p.fetch ).toHaveBeenCalledTimes( 1 );
		expect( sentPlugins( p.fetch.mock.calls[ 0 ] ) ).toEqual( [
			'acme/acme.php',
		] );

		// Nothing more later: Heartbeat, a stray ajaxComplete.
		p.ajax( 'action=heartbeat' ).xhr.dispatchEvent(
			new Event( 'loadend' )
		);
		p.trigger( 'ajaxComplete' );
		expect( p.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'lists genuinely failed updates too: the server answers not_pending for them', () => {
		const p = page();
		bindUpdateFollowUp( p.win );

		p.startJob( 'a/a.php' );
		p.finishJob(
			failure( 'a/a.php', {
				errorMessage: 'Download failed. Service Unavailable',
			} )
		);
		p.startJob( 'b/b.php' );
		p.finishJob(
			failure(
				'b/b.php',
				{
					errorCode: 'incompatible_archive_no_plugins',
					errorMessage: 'The package could not be installed.',
				},
				true
			)
		);

		expect( p.fetch ).toHaveBeenCalledTimes( 2 );
		expect( sentPlugins( p.fetch.mock.calls[ 0 ] ) ).toEqual( [
			'a/a.php',
		] );
		expect( sentPlugins( p.fetch.mock.calls[ 1 ] ) ).toEqual( [
			'b/b.php',
		] );
	} );

	it( 'sends for every queued update once the last one ended, also after an error answer', () => {
		const p = page();
		bindUpdateFollowUp( p.win );

		p.startJob( 'a/a.php' );
		p.startJob( 'b/b.php' );
		p.startJob( 'c/c.php' );
		p.finishJob( success( 'a/a.php' ) );
		p.finishJob( failure( 'b/b.php', TRANSLATION_401 ) ); // 6.2: "c" starts.
		expect( p.fetch ).not.toHaveBeenCalled();
		p.finishJob( success( 'c/c.php' ) );

		expect( p.fetch ).toHaveBeenCalledTimes( 1 );
		expect( sentPlugins( p.fetch.mock.calls[ 0 ] ) ).toEqual( [
			'a/a.php',
			'b/b.php',
			'c/c.php',
		] );
	} );

	it( 'sends for the updates before a throwing error handler; core never starts the queued rest', () => {
		const p = page();
		bindUpdateFollowUp( p.win );

		p.startJob( 'a/a.php' );
		p.startJob( 'b/b.php' );
		p.startJob( 'c/c.php' );
		p.finishJob( success( 'a/a.php' ) ); // "b" starts.
		p.finishJob( failure( 'b/b.php', TRANSLATION_401, true ) );

		// "c" stays in core's queue (ajaxLocked stuck): it may still run, so nothing is sent.
		expect( p.updates.queue ).toEqual( [ 'c/c.php' ] );
		expect( p.fetch ).not.toHaveBeenCalled();
	} );

	it( 'sends at most one request per update sequence', () => {
		const p = page();
		bindUpdateFollowUp( p.win );

		// The success event and the request's own end both record the plugin: listed once.
		p.startJob( 'acme/acme.php' );
		const xhr = p.finishJob( success( 'acme/acme.php' ) );
		expect( p.fetch ).toHaveBeenCalledTimes( 1 );
		expect( sentPlugins( p.fetch.mock.calls[ 0 ] ) ).toEqual( [
			'acme/acme.php',
		] );

		// A repeated end of the same request, completions of other requests: nothing more.
		xhr.dispatchEvent( new Event( 'loadend' ) );
		p.trigger( 'ajaxComplete' );
		p.ajax( 'action=heartbeat' ).xhr.dispatchEvent(
			new Event( 'loadend' )
		);
		expect( p.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'sends nothing for theme updates, installs, Heartbeat or other Ajax, and never touches their requests', () => {
		const p = page();
		bindUpdateFollowUp( p.win );

		const others = [
			p.ajax(
				'interval=60&_nonce=hb&action=heartbeat&screen_id=plugins'
			),
			p.ajax( 'slug=twentytwentyfive&_ajax_nonce=n&action=update-theme' ),
			p.ajax( 'slug=new&_ajax_nonce=n&action=install-plugin' ),
			p.ajax( 'action=update-plugins&plugin=a/a.php' ),
			p.ajax( 'action=update-plugin&plugin=a/a.php', 'GET' ),
		];
		for ( const other of others ) {
			expect( other.settings.xhr ).toBe( p.createXhr );
			other.xhr.status = 200;
			other.xhr.responseText = JSON.stringify( {
				success: true,
				data: { update: 'plugin', plugin: 'a/a.php' },
			} );
			other.xhr.dispatchEvent( new Event( 'loadend' ) );
		}
		p.trigger( 'wp-theme-update-success', { slug: 'twentytwentyfive' } );
		p.trigger( 'wp-plugin-install-success', { plugin: 'new/new.php' } );
		p.trigger( 'ajaxComplete' );

		expect( p.fetch ).not.toHaveBeenCalled();
	} );

	it( 'does not wait for unrelated requests still in flight', () => {
		const p = page();
		bindUpdateFollowUp( p.win );

		p.ajax( 'action=heartbeat' ); // Never ends.
		p.startJob( 'acme/acme.php' );
		p.finishJob( success( 'acme/acme.php' ) );

		expect( p.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'ignores UpdateLens itself and responses that name no plugin', () => {
		const p = page();
		bindUpdateFollowUp( p.win );

		p.startJob( SETTINGS.selfPlugin );
		p.finishJob( success( SETTINGS.selfPlugin ) );
		p.startJob( 'x/x.php' );
		p.finishJob( {
			event: 'wp-plugin-update-success',
			body: { data: null },
		} );
		p.startJob( 'y/y.php' );
		p.finishJob( {
			event: 'wp-plugin-update-success',
			body: { success: true, data: { update: 'plugin', plugin: 42 } },
		} );
		// Last: core's lock stays set after its handler throws.
		p.startJob( SETTINGS.selfPlugin );
		p.finishJob( failure( SETTINGS.selfPlugin, TRANSLATION_401, true ) );

		expect( p.fetch ).not.toHaveBeenCalled();
	} );

	it( 'records nothing from aborted, failed, unreadable or malformed responses', () => {
		const bodies: Outcome[] = [
			{ status: 0, body: '' }, // Aborted, or the page was left during the update.
			{ status: 500, body: { data: answer( 'a/a.php' ) } },
			{ status: 403, body: '-1' },
			{ body: '<p>Fatal error</p>' },
			{
				body: 'Notice: x {"data":{"update":"plugin","plugin":"a/a.php"}}',
			},
			{ body: '0' },
			{ body: 'null' },
			{ body: { success: false, data: 'a/a.php' } },
			{ body: { success: false, data: { update: 'theme', slug: 'a' } } },
			{
				body: {
					success: false,
					data: { update: 'plugin', plugin: '' },
				},
			},
			{ body: { success: false, data: { errorMessage: 'Sorry…' } } },
		];
		for ( const outcome of bodies ) {
			const p = page();
			bindUpdateFollowUp( p.win );
			p.startJob( 'a/a.php' );
			expect( () => p.finishJob( outcome ) ).not.toThrow();
			expect( p.fetch ).not.toHaveBeenCalled();

			// The request no longer counts as in flight: the next update is followed up.
			p.startJob( 'b/b.php' );
			p.finishJob( success( 'b/b.php' ) );
			expect( sentPlugins( p.fetch.mock.calls[ 0 ] ) ).toEqual( [
				'b/b.php',
			] );
		}
	} );

	it( 'sends again for a later update after the first follow-up', () => {
		const p = page();
		bindUpdateFollowUp( p.win );

		p.startJob( 'a/a.php' );
		p.finishJob( success( 'a/a.php' ) );
		p.startJob( 'b/b.php' );
		p.finishJob( success( 'b/b.php' ) );

		expect( p.fetch ).toHaveBeenCalledTimes( 2 );
		expect( sentPlugins( p.fetch.mock.calls[ 1 ] ) ).toEqual( [
			'b/b.php',
		] );
	} );

	it( 'falls back to core’s lock for an update request it cannot observe', () => {
		const p = page();
		bindUpdateFollowUp( p.win );

		// A transport without an `xhr` factory: success events still count, and the lock decides.
		const settings = { type: 'POST', data: updateData( 'a/a.php' ) };
		p.updates.ajaxLocked = true;
		p.trigger( 'ajaxSend', {}, settings );
		p.trigger( 'wp-plugin-update-success', { plugin: 'a/a.php' } );
		p.trigger( 'ajaxComplete' );
		expect( p.fetch ).not.toHaveBeenCalled();
		p.updates.ajaxLocked = false;
		p.trigger( 'ajaxComplete' );
		expect( sentPlugins( p.fetch.mock.calls[ 0 ] ) ).toEqual( [
			'a/a.php',
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

describe( 'update request observation', () => {
	const observer = () => ( {
		started: vi.fn(),
		ended: vi.fn(),
		unobservable: vi.fn(),
	} );

	it( 'recognizes only plugin update POSTs', () => {
		expect(
			isPluginUpdateRequest( {
				type: 'POST',
				data: updateData( 'a/a.php' ),
			} )
		).toBe( true );
		expect(
			isPluginUpdateRequest( {
				type: 'post',
				data: 'action=update-plugin',
			} )
		).toBe( true );
		for ( const settings of [
			null,
			'action=update-plugin',
			{ type: 'GET', data: 'action=update-plugin' },
			{ type: 'POST', data: 'action=update-plugins' },
			{ type: 'POST', data: 'action=update-theme&plugin=update-plugin' },
			{ type: 'POST', data: 'xaction=update-plugin' },
			{ type: 'POST', data: { action: 'update-plugin' } },
			{ data: 'action=update-plugin' },
		] ) {
			expect( isPluginUpdateRequest( settings ) ).toBe( false );
		}
	} );

	it( 'wraps only that request’s factory and returns the same XMLHttpRequest, unchanged', () => {
		const xhr = new FakeXhr();
		const create = vi.fn( function ( this: unknown ) {
			return xhr;
		} );
		const settings = {
			type: 'POST',
			data: updateData( 'a/a.php' ),
			xhr: create as ( ...args: unknown[] ) => unknown,
			url: '/wp-admin/admin-ajax.php',
		};
		const watch = observer();
		observeUpdateRequest( settings, watch );

		expect( settings.xhr ).not.toBe( create );
		expect( settings.data ).toBe( updateData( 'a/a.php' ) );
		expect( watch.started ).not.toHaveBeenCalled();
		expect( settings.xhr.call( settings, 'arg' ) ).toBe( xhr );
		expect( create ).toHaveBeenCalledWith( 'arg' );
		expect( create.mock.contexts[ 0 ] ).toBe( settings );
		expect( watch.started ).toHaveBeenCalledTimes( 1 );

		xhr.status = 200;
		xhr.responseText = JSON.stringify(
			failure( 'a/a.php', TRANSLATION_401 ).body
		);
		const load = vi.fn();
		xhr.addEventListener( 'load', load );
		xhr.dispatchEvent( new Event( 'load' ) );
		expect( load ).toHaveBeenCalledTimes( 1 );
		expect( watch.ended ).not.toHaveBeenCalled();
		xhr.dispatchEvent( new Event( 'loadend' ) );
		xhr.dispatchEvent( new Event( 'loadend' ) );
		expect( watch.ended ).toHaveBeenCalledTimes( 1 );
		expect( watch.ended ).toHaveBeenCalledWith( 'a/a.php' );
		expect( xhr.responseText ).toContain( 'Download failed. Unauthorized' );
		expect( watch.unobservable ).not.toHaveBeenCalled();
	} );

	it( 'leaves other requests untouched', () => {
		const create = vi.fn();
		const settings = {
			type: 'POST',
			data: 'action=heartbeat',
			xhr: create,
		};
		const watch = observer();
		observeUpdateRequest( settings, watch );

		expect( settings.xhr ).toBe( create );
		expect( Object.keys( settings ) ).toEqual( [ 'type', 'data', 'xhr' ] );
		expect( watch.started ).not.toHaveBeenCalled();
		expect( watch.unobservable ).not.toHaveBeenCalled();
	} );

	it( 'reports update requests it cannot observe, without breaking them', () => {
		const noFactory = observer();
		observeUpdateRequest(
			{ type: 'POST', data: 'action=update-plugin' },
			noFactory
		);
		expect( noFactory.unobservable ).toHaveBeenCalledTimes( 1 );

		const notAnXhr = { status: 200 };
		const settings = {
			type: 'POST',
			data: 'action=update-plugin',
			xhr: () => notAnXhr,
		};
		const watch = observer();
		observeUpdateRequest( settings, watch );
		expect( settings.xhr() ).toBe( notAnXhr );
		expect( watch.unobservable ).toHaveBeenCalledTimes( 1 );
		expect( watch.started ).not.toHaveBeenCalled();

		// A factory that throws keeps throwing to jQuery, as without UpdateLens.
		const failing = {
			type: 'POST',
			data: 'action=update-plugin',
			xhr: () => {
				throw new Error( 'no XMLHttpRequest' );
			},
		};
		observeUpdateRequest( failing, observer() );
		expect( () => failing.xhr() ).toThrow( 'no XMLHttpRequest' );
	} );

	it( 'reads only the plugin of a 2xx JSON answer', () => {
		expect(
			respondedPlugin( {
				status: 200,
				responseText: JSON.stringify( success( 'a/a.php' ).body ),
			} )
		).toBe( 'a/a.php' );
		expect(
			respondedPlugin( {
				status: 200,
				responseText: JSON.stringify(
					failure( 'a/a.php', TRANSLATION_401 ).body
				),
			} )
		).toBe( 'a/a.php' );
		for ( const xhr of [
			{ status: 0, responseText: '' },
			{
				status: 302,
				responseText: '{"data":{"update":"plugin","plugin":"a"}}',
			},
			{ status: 200, responseText: '' },
			{ status: 200, responseText: '{' },
			{ status: 200, responseText: '[]' },
			{ status: 200, responseText: '{"data":["a/a.php"]}' },
			{ status: 200 },
			{},
		] ) {
			expect( respondedPlugin( xhr ) ).toBeUndefined();
		}
		// responseText throws for other response types.
		const json = {
			status: 200,
			get responseText(): string {
				throw new DOMException( 'InvalidStateError' );
			},
		};
		expect( respondedPlugin( json ) ).toBeUndefined();
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

	it( 'is idle only with no observed update in flight and nothing queued', () => {
		const observed = { inFlight: 0, unobserved: false };
		expect(
			isUpdatesIdle( { ajaxLocked: false, queue: [] }, observed )
		).toBe( true );
		expect( isUpdatesIdle( {}, observed ) ).toBe( true );
		expect( isUpdatesIdle( { queue: [ {} ] }, observed ), 'queued' ).toBe(
			false
		);
		expect(
			isUpdatesIdle( {}, { inFlight: 1, unobserved: false } ),
			'in flight'
		).toBe( false );
		// Core's lock, stuck after its error handler threw, does not count…
		expect(
			isUpdatesIdle( { ajaxLocked: true, queue: [] }, observed )
		).toBe( true );
		// …unless an update request could not be observed.
		expect(
			isUpdatesIdle(
				{ ajaxLocked: true, queue: [] },
				{ inFlight: 0, unobserved: true }
			)
		).toBe( false );
		expect(
			isUpdatesIdle(
				{ ajaxLocked: false, queue: [] },
				{ inFlight: 0, unobserved: true }
			)
		).toBe( true );
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

describe( 'AFTER checks after an error answer', () => {
	const sameOrigin = () => ( {
		...SETTINGS,
		ajaxUrl: `${ window.location.origin }/wp-admin/admin-ajax.php`,
		siteChecks: { nonce: 'sc-nonce' },
	} );

	/** The server settled the plugin (it installed) and wants its AFTER checks. */
	const respond = ( p: ReturnType< typeof page > ) =>
		p.fetch.mockImplementation( ( _url, init ) => {
			const fields = new URLSearchParams( String( init?.body ) );
			const data =
				fields.get( 'action' ) === 'updatelens_settle'
					? {
							results: [
								{
									plugin: 'acme/acme.php',
									result: 'settled',
									analysis: 9,
									site_checks: true,
								},
							],
						}
					: {
							code: 'not_needed',
							purpose: 'after',
							reason: 'no_baseline',
						};
			return Promise.resolve(
				new Response( JSON.stringify( { success: true, data } ) )
			);
		} );

	it.each( [
		[ 'WordPress 6.2–6.4', false ],
		[
			'WordPress 6.5+ (core’s handler throws, ajaxLocked stays set)',
			true,
		],
	] )(
		'starts the AFTER run the server asks for: %s',
		async ( _version, coreThrows ) => {
			const p = page( sameOrigin() );
			respond( p );
			bindUpdateFollowUp( p.win );

			p.startJob( 'acme/acme.php' );
			p.finishJob(
				failure( 'acme/acme.php', TRANSLATION_401, coreThrows )
			);
			await vi.waitFor( () =>
				expect( p.fetch ).toHaveBeenCalledTimes( 2 )
			);

			expect( p.updates.ajaxLocked ).toBe( coreThrows );
			expect(
				Object.fromEntries(
					new URLSearchParams(
						String( p.fetch.mock.calls[ 1 ][ 1 ]?.body )
					)
				)
			).toEqual( {
				action: 'updatelens_site_check_start',
				_ajax_nonce: 'sc-nonce',
				purpose: 'after',
				analysis: '9',
			} );
		}
	);

	it( 'starts nothing for a plugin the server did not settle (a failed update)', async () => {
		const p = page( sameOrigin() );
		p.fetch.mockImplementation( () =>
			Promise.resolve(
				new Response(
					JSON.stringify( {
						success: true,
						data: {
							results: [
								{
									plugin: 'acme/acme.php',
									result: 'not_pending',
								},
							],
						},
					} )
				)
			)
		);
		bindUpdateFollowUp( p.win );

		p.startJob( 'acme/acme.php' );
		p.finishJob(
			failure(
				'acme/acme.php',
				{ errorMessage: 'Download failed. Service Unavailable' },
				true
			)
		);
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( p.fetch ).toHaveBeenCalledTimes( 1 );
	} );
} );
