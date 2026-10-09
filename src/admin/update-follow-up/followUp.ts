/**
 * Update follow-up: after WordPress's updates UI (`updates.js`) has updated
 * plugins over Ajax, ask UpdateLens once, when the update queue is idle, to
 * take the post-update observation (`admin-ajax.php`, FollowUpRequest in PHP).
 *
 * Event-driven, never polling:
 * - `wp-plugin-update-success` (fired by `updates.js` for each successful
 *   plugin update) records the plugin;
 * - jQuery's global `ajaxComplete` (fired after the update request's own
 *   callbacks, so after `updates.js` has unlocked its queue and started the
 *   next queued job, if any) checks whether the queue is idle and then sends
 *   one request for all recorded plugins.
 *
 * Failed updates, theme updates and other events record nothing. While more
 * updates are queued nothing is sent: each next update ends the previous
 * one's observation on the server (`next_update`). The request is
 * best-effort: `keepalive` lets it outlive the page in browsers that support
 * it, and if it is lost, the next eligible admin page (within the observation
 * window) still takes the observation.
 */

/** Settings printed by PHP (AdminAssets::enqueue_update_follow_up()). */
export interface FollowUpSettings {
	ajaxUrl: string;
	action: string;
	nonce: string;
	/** UpdateLens's own basename: its updates are never analysed. */
	selfPlugin: string;
}

/** The parts of `wp.updates` the follow-up reads. */
export interface UpdatesState {
	ajaxLocked?: boolean;
	queue?: unknown[];
}

/** Most plugins one follow-up lists (FollowUpRequest::MAX_PLUGINS in PHP). */
export const MAX_PLUGINS = 100;

export interface FollowUp {
	/** A plugin update succeeded (`response.plugin` of `wp-plugin-update-success`). */
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

/**
 * Whether `updates.js` has no running or queued job.
 *
 * @param updates `wp.updates`.
 */
export function isQueueIdle( updates: UpdatesState ): boolean {
	return (
		! updates.ajaxLocked &&
		! ( Array.isArray( updates.queue ) && updates.queue.length > 0 )
	);
}

/**
 * Send one follow-up request. Errors are ignored: the server keeps the
 * analysis waiting, and a later admin page can still take the observation.
 *
 * @param fetchFn  `window.fetch`.
 * @param settings Settings from PHP.
 * @param plugins  Plugin basenames.
 */
export function sendFollowUp(
	fetchFn: typeof fetch,
	settings: FollowUpSettings,
	plugins: string[]
): void {
	const body = new URLSearchParams();
	body.append( 'action', settings.action );
	body.append( '_ajax_nonce', settings.nonce );
	plugins.forEach( ( plugin ) => body.append( 'plugins[]', plugin ) );

	const init: RequestInit = {
		method: 'POST',
		credentials: 'same-origin',
		body,
	};

	try {
		fetchFn( settings.ajaxUrl, { ...init, keepalive: true } ).catch(
			() => undefined
		);
	} catch {
		// A browser that rejects `keepalive` synchronously: send without it.
		try {
			fetchFn( settings.ajaxUrl, init ).catch( () => undefined );
		} catch {
			// Nothing else to do; a later admin page can still settle.
		}
	}
}

/** Minimal jQuery surface used for binding. */
type JQueryLike = ( target: unknown ) => {
	on: (
		events: string,
		handler: ( event: unknown, response?: unknown ) => void
	) => unknown;
};

/** The globals the follow-up binds to. */
export interface FollowUpWindow {
	document: Document;
	jQuery?: JQueryLike;
	wp?: { updates?: UpdatesState };
	fetch?: typeof fetch;
	updatelensUpdateFollowUp?: unknown;
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

	return [ 'ajaxUrl', 'action', 'nonce', 'selfPlugin' ].every(
		( key ) => typeof settings[ key ] === 'string'
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

	const followUp = createFollowUp( {
		isIdle: () => isQueueIdle( updates ),
		send: ( plugins ) =>
			sendFollowUp( fetchFn.bind( win ), settings, plugins ),
		selfPlugin: settings.selfPlugin,
	} );

	const document = jQuery( win.document );
	document.on( 'wp-plugin-update-success', ( _event, response ) => {
		followUp.pluginUpdated(
			typeof response === 'object' && response !== null
				? ( response as { plugin?: unknown } ).plugin
				: undefined
		);
	} );
	document.on( 'ajaxComplete', () => followUp.check() );

	return true;
}
