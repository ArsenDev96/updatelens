/**
 * Update follow-up: after WordPress's updates UI (`updates.js`) has updated
 * plugins over Ajax, ask UpdateLens once, when the update queue is idle, to
 * take the post-update observation (`admin-ajax.php`, FollowUpRequest in PHP).
 *
 * Event-driven, never polling:
 * - every `update-plugin` request is observed on its own XMLHttpRequest
 *   (`observeUpdateRequest()`: a passive `loadend` listener, attached through
 *   that request's `xhr` factory in jQuery's `ajaxSend`). When it ends, the
 *   plugin its response names is recorded, whether WordPress answered with
 *   success or an error: a plugin can be installed although the answer is an
 *   error (a translation download after the update failed, e.g. HTTP 401).
 *   The browser never decides whether the update worked: PHP settles only
 *   analyses its own updater hooks saw complete (others are `not_pending`).
 * - `wp-plugin-update-success` (fired by `updates.js` for each successful
 *   plugin update) records the plugin as well;
 * - when an observed request ends, and on jQuery's global `ajaxComplete`,
 *   the follow-up checks whether the updates are idle and then sends one
 *   request for all recorded plugins.
 *
 * Idle means no observed update request in flight and nothing in
 * `updates.js`'s queue, not `wp.updates.ajaxLocked`: since WordPress 6.5,
 * `updatePluginError()` throws on Installed Plugins, so after an error
 * answer `wp-plugin-update-error` and `ajaxComplete` never fire and the lock
 * stays set until the page reloads. Only if an update request could not be
 * observed does the lock count as well (the earlier rule).
 *
 * Theme updates, installs and other requests record nothing, and their
 * requests are never touched. While more updates are queued nothing is sent:
 * each next update ends the previous one's observation on the server
 * (`next_update`). The request is best-effort: `keepalive` lets it outlive
 * the page in browsers that support it, and if it is lost, the next eligible
 * admin page (within the observation window) still takes the observation.
 *
 * With site checks enabled (`siteChecks` in the settings), the response
 * names the analyses this request settled; their AFTER site checks then run
 * (afterChecks.ts), unless an update started meanwhile.
 *
 * Dashboard → Updates runs the update in the page itself (the update page,
 * `update.php?action=update-selected`, in an iframe), not over Ajax. There
 * PHP prints `updatelensUpdateFollowUpPage` with the plugin that page's own
 * request analysed, only after its update stored the IMMEDIATE observation
 * (a failed update lists nothing; an update of several plugins is not
 * analysed). The follow-up is sent once, for that plugin, when the page has
 * loaded: by then the update request has ended, its shutdown work included
 * (measured on WordPress 7.1). Same request, same server-side rules.
 */
import { runSiteChecks } from '../site-check/runner';
import { createAfterChecks, parseSettled } from './afterChecks';

/** Settings printed by PHP (AdminAssets::enqueue_update_follow_up()). */
export interface FollowUpSettings {
	ajaxUrl: string;
	action: string;
	nonce: string;
	/** UpdateLens's own basename: its updates are never analysed. */
	selfPlugin: string;
	/** Only when site checks are enabled: the site check nonce for AFTER checks. */
	siteChecks?: { nonce: string };
}

/** The parts of `wp.updates` the follow-up reads. */
export interface UpdatesState {
	ajaxLocked?: boolean;
	queue?: unknown[];
}

/** Most plugins one follow-up lists (FollowUpRequest::MAX_PLUGINS in PHP). */
export const MAX_PLUGINS = 100;

export interface FollowUp {
	/** A plugin update request ended (the plugin its response names; PHP decides whether it updated). */
	pluginUpdated: ( plugin: unknown ) => void;
	/** Send the follow-up if plugins are waiting and the update queue is idle. */
	check: () => void;
}

/**
 * Follow-up state machine.
 *
 * @param options            Dependencies.
 * @param options.isIdle     Whether `updates.js` has no running or queued job.
 * @param options.send       Sends one follow-up request for the plugins.
 * @param options.selfPlugin Plugin never sent (UpdateLens itself).
 */
export function createFollowUp( options: {
	isIdle: () => boolean;
	send: ( plugins: string[] ) => void;
	selfPlugin?: string;
} ): FollowUp {
	let pending: string[] = [];

	return {
		pluginUpdated( plugin ) {
			if (
				typeof plugin !== 'string' ||
				plugin === '' ||
				plugin === options.selfPlugin
			) {
				return;
			}
			pending = pending.filter( ( item ) => item !== plugin );
			pending.push( plugin );
			if ( pending.length > MAX_PLUGINS ) {
				pending = pending.slice( -MAX_PLUGINS );
			}
		},
		check() {
			if ( pending.length === 0 || ! options.isIdle() ) {
				return;
			}
			const plugins = pending;
			pending = [];
			options.send( plugins );
		},
	};
}

/** The plugin update requests the follow-up observed. */
export interface UpdateRequests {
	/** Observed update requests that have not ended yet. */
	inFlight: number;
	/** Whether an update request could not be observed on this page. */
	unobserved: boolean;
}

/**
 * Whether no plugin update runs or waits: no observed update request in
 * flight and nothing in `updates.js`'s queue. `ajaxLocked` counts only once
 * an update request could not be observed: it stays set after core's error
 * handler throws (WordPress 6.5+), so it alone would wait forever.
 *
 * @param updates  `wp.updates`.
 * @param requests The observed update requests.
 */
export function isUpdatesIdle(
	updates: UpdatesState,
	requests: UpdateRequests
): boolean {
	return (
		requests.inFlight === 0 &&
		! ( Array.isArray( updates.queue ) && updates.queue.length > 0 ) &&
		! ( requests.unobserved && updates.ajaxLocked )
	);
}

/** The parts of jQuery's request settings (`ajaxSend`) read here. */
interface AjaxSettings {
	type?: unknown;
	data?: unknown;
	xhr?: unknown;
}

/** What an observed update request reports. */
export interface UpdateRequestObserver {
	/** The request's XMLHttpRequest exists and its end will be reported. */
	started: () => void;
	/** The request ended (loaded, failed or aborted), with the plugin its response names. */
	ended: ( plugin: string | undefined ) => void;
	/** An update request whose end cannot be seen. */
	unobservable: () => void;
}

/**
 * Whether jQuery's request settings are those of a plugin update by
 * `updates.js` (`wp.ajax.send( 'update-plugin', … )`: a POST whose data
 * jQuery has already serialized).
 *
 * @param settings The settings `ajaxSend` passes.
 */
export function isPluginUpdateRequest( settings: unknown ): boolean {
	if ( typeof settings !== 'object' || settings === null ) {
		return false;
	}
	const { type, data } = settings as AjaxSettings;

	return (
		typeof type === 'string' &&
		type.toUpperCase() === 'POST' &&
		typeof data === 'string' &&
		/(?:^|&)action=update-plugin(?:&|$)/.test( data )
	);
}

/**
 * The plugin an ended update request's response names (`data.plugin` of
 * `wp_ajax_update_plugin()`'s answer, in its success and its error
 * responses), or undefined: aborted, not 2xx, not JSON, another shape.
 * Nothing else of the response is read or kept.
 *
 * @param xhr The request's XMLHttpRequest.
 */
export function respondedPlugin( xhr: {
	status?: unknown;
	responseText?: unknown;
} ): string | undefined {
	try {
		const status = xhr.status;
		const text = xhr.responseText;
		if (
			typeof status !== 'number' ||
			status < 200 ||
			status > 299 ||
			typeof text !== 'string'
		) {
			return undefined;
		}
		const json: unknown = JSON.parse( text );
		const data =
			typeof json === 'object' && json !== null
				? ( json as { data?: unknown } ).data
				: undefined;
		if ( typeof data !== 'object' || data === null ) {
			return undefined;
		}
		const { update, plugin } = data as {
			update?: unknown;
			plugin?: unknown;
		};

		return update === 'plugin' &&
			typeof plugin === 'string' &&
			plugin !== ''
			? plugin
			: undefined;
	} catch {
		// Not JSON, or a response type without text.
		return undefined;
	}
}

/**
 * Observe one plugin update request's end on its own XMLHttpRequest, so the
 * end is seen even when WordPress's callbacks throw and jQuery's completion
 * events never fire. Only this request's `xhr` factory is wrapped (jQuery
 * calls it right after `ajaxSend`); the wrapper returns the same object,
 * adds one passive `loadend` listener and changes nothing else: not the
 * request, its data, headers or response, nor WordPress's callbacks. Other
 * requests are left untouched.
 *
 * @param settings The settings `ajaxSend` passes.
 * @param observer Receives the request's start and end.
 */
export function observeUpdateRequest(
	settings: unknown,
	observer: UpdateRequestObserver
): void {
	if ( ! isPluginUpdateRequest( settings ) ) {
		return;
	}
	const ajax = settings as AjaxSettings;
	const create = ajax.xhr;
	if ( typeof create !== 'function' ) {
		observer.unobservable();
		return;
	}
	ajax.xhr = function ( this: unknown, ...args: unknown[] ): unknown {
		const xhr: unknown = create.apply( this, args );
		try {
			( xhr as XMLHttpRequest ).addEventListener(
				'loadend',
				() =>
					observer.ended( respondedPlugin( xhr as XMLHttpRequest ) ),
				{ once: true }
			);
		} catch {
			observer.unobservable();
			return xhr;
		}
		observer.started();
		return xhr;
	};
}

/**
 * Send one follow-up request. Errors are ignored: the server keeps the
 * analysis waiting, and a later admin page can still take the observation.
 *
 * @param fetchFn  `window.fetch`.
 * @param settings Settings from PHP.
 * @param plugins  Plugin basenames.
 * @return The response's `data`, or null (failed, unreadable, page gone).
 */
export function sendFollowUp(
	fetchFn: typeof fetch,
	settings: FollowUpSettings,
	plugins: string[]
): Promise< unknown > {
	const body = new URLSearchParams();
	body.append( 'action', settings.action );
	body.append( '_ajax_nonce', settings.nonce );
	plugins.forEach( ( plugin ) => body.append( 'plugins[]', plugin ) );

	const init: RequestInit = {
		method: 'POST',
		credentials: 'same-origin',
		body,
	};

	let request: Promise< Response >;
	try {
		request = fetchFn( settings.ajaxUrl, { ...init, keepalive: true } );
	} catch {
		// A browser that rejects `keepalive` synchronously: send without it.
		try {
			request = fetchFn( settings.ajaxUrl, init );
		} catch {
			// Nothing else to do; a later admin page can still settle.
			return Promise.resolve( null );
		}
	}

	return Promise.resolve( request )
		.then( ( response ) => ( response.ok ? response.json() : null ) )
		.then( ( json: unknown ) =>
			typeof json === 'object' &&
			json !== null &&
			( json as { success?: unknown } ).success === true
				? ( ( json as { data?: unknown } ).data ?? null )
				: null
		)
		.catch( () => null );
}

/** Minimal jQuery surface used for binding. */
type JQueryLike = ( target: unknown ) => {
	on: (
		events: string,
		handler: ( event: unknown, ...args: unknown[] ) => void
	) => unknown;
};

/** The globals the follow-up binds to. */
export interface FollowUpWindow {
	document: Document;
	jQuery?: JQueryLike;
	wp?: { updates?: UpdatesState };
	fetch?: typeof fetch;
	updatelensUpdateFollowUp?: unknown;
	/** Only on an update page whose own request analysed a plugin (Dashboard → Updates). */
	updatelensUpdateFollowUpPage?: unknown;
	addEventListener?: (
		type: 'load',
		listener: () => void,
		options?: { once?: boolean }
	) => void;
}

/**
 * The plugins the update page's own request analysed, from
 * `updatelensUpdateFollowUpPage` (AdminAssets::add_update_page_follow_up()).
 * Anything malformed yields nothing, so nothing is sent.
 *
 * @param value The printed object.
 */
export function pagePlugins( value: unknown ): string[] {
	if ( typeof value !== 'object' || value === null ) {
		return [];
	}
	const plugins = ( value as { plugins?: unknown } ).plugins;
	if (
		! Array.isArray( plugins ) ||
		plugins.length === 0 ||
		plugins.length > MAX_PLUGINS ||
		! plugins.every(
			( plugin ) => typeof plugin === 'string' && plugin !== ''
		)
	) {
		return [];
	}

	return plugins as string[];
}

/**
 * Run once the page has loaded (now, if it already has).
 *
 * @param win      Window.
 * @param callback Callback.
 */
function whenLoaded( win: FollowUpWindow, callback: () => void ): void {
	if ( win.document.readyState === 'complete' ) {
		callback();
		return;
	}
	win.addEventListener?.( 'load', callback, { once: true } );
}

/**
 * Whether a value is the settings object PHP prints.
 *
 * @param value Value.
 */
function isSettings( value: unknown ): value is FollowUpSettings {
	if ( typeof value !== 'object' || value === null ) {
		return false;
	}
	const settings = value as Record< string, unknown >;

	const siteChecks = settings.siteChecks;

	return (
		[ 'ajaxUrl', 'action', 'nonce', 'selfPlugin' ].every(
			( key ) => typeof settings[ key ] === 'string'
		) &&
		( siteChecks === undefined ||
			( typeof siteChecks === 'object' &&
				siteChecks !== null &&
				typeof ( siteChecks as { nonce?: unknown } ).nonce ===
					'string' ) )
	);
}

/**
 * Bind the follow-up to WordPress's updates UI.
 *
 * @param win Window.
 * @return Whether it was bound (false without jQuery, `wp.updates`, `fetch` or settings).
 */
export function bindUpdateFollowUp( win: FollowUpWindow ): boolean {
	const jQuery = win.jQuery;
	const updates = win.wp?.updates;
	const settings = win.updatelensUpdateFollowUp;
	const fetchFn = win.fetch;
	if (
		typeof jQuery !== 'function' ||
		! updates ||
		typeof fetchFn !== 'function' ||
		! isSettings( settings )
	) {
		return false;
	}

	const requests: UpdateRequests = { inFlight: 0, unobserved: false };
	const isIdle = () => isUpdatesIdle( updates, requests );

	const siteChecks = settings.siteChecks;
	const afterChecks = siteChecks
		? createAfterChecks( {
				isIdle,
				run: ( analysis, signal ) =>
					runSiteChecks(
						{ ajaxUrl: settings.ajaxUrl, nonce: siteChecks.nonce },
						{
							purpose: 'after',
							analysis,
							signal,
							fetch: fetchFn.bind( win ),
						}
					),
			} )
		: null;
	const followUp = createFollowUp( {
		isIdle,
		send: ( plugins ) => {
			const token = afterChecks?.followUpSent() ?? 0;
			sendFollowUp( fetchFn.bind( win ), settings, plugins ).then(
				( data ) => afterChecks?.settled( parseSettled( data ), token )
			);
		},
		selfPlugin: settings.selfPlugin,
	} );

	const document = jQuery( win.document );
	// An update that starts stops AFTER checks (they would overlap it anyway).
	document.on( 'wp-plugin-updating', () => afterChecks?.updateStarted() );
	document.on( 'wp-plugin-update-success', ( _event, response ) => {
		followUp.pluginUpdated(
			typeof response === 'object' && response !== null
				? ( response as { plugin?: unknown } ).plugin
				: undefined
		);
	} );
	// Every plugin update request's own end, also when core's callbacks throw.
	document.on( 'ajaxSend', ( _event, _jqXHR, ajax ) => {
		try {
			observeUpdateRequest( ajax, {
				started: () => {
					requests.inFlight++;
				},
				ended: ( plugin ) => {
					requests.inFlight = Math.max( 0, requests.inFlight - 1 );
					try {
						followUp.pluginUpdated( plugin );
						followUp.check();
					} catch {
						// Never in the way of WordPress; a later admin page can still settle.
					}
				},
				unobservable: () => {
					requests.unobserved = true;
				},
			} );
		} catch {
			requests.unobserved = true;
		}
	} );
	document.on( 'ajaxComplete', () => followUp.check() );

	// Dashboard → Updates: this page's own request ran the update. The plugin
	// is listed only once the page has loaded, so an `ajaxComplete` before
	// that cannot send it while the update request may still be running.
	const updatedHere = pagePlugins( win.updatelensUpdateFollowUpPage );
	if ( updatedHere.length > 0 ) {
		whenLoaded( win, () => {
			try {
				updatedHere.forEach( ( plugin ) =>
					followUp.pluginUpdated( plugin )
				);
				followUp.check();
			} catch {
				// Never in the way of WordPress; a later admin page can still settle.
			}
		} );
	}

	return true;
}
