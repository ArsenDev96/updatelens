/**
 * BEFORE baselines in the background on the Plugins and Updates screens.
 * Loaded by AdminAssets::enqueue_site_check_baseline() only where PHP allows
 * it (BaselinePage: opt-in on, `update_plugins`, a plugin update available,
 * no maintenance mode). No UI, no strings, no console output.
 *
 * Event-driven, never polling: the page asks when it loads and whenever it
 * becomes visible again (`visibilitychange`, window `focus`, a page restored
 * from the back/forward cache), and only for checks that are due. PHP owns
 * the freshness rules (Baselines): it says per check how long a baseline
 * stays fresh (`refresh_in`), and a `before` start opens sessions only for
 * the checks that are due, so a fresh homepage baseline is reused while
 * only REST runs again. Each check is reported and stored on its own.
 *
 * Never in the way of an update: nothing listens to clicks or delays
 * WordPress's handlers. A run starts only START_GRACE_MS after the page has
 * loaded or become visible, so an immediate "Update now" never shares the
 * server with baseline requests (aborting in the browser cannot stop PHP
 * from finishing a request it already received). When an update starts
 * (`wp-plugin-updating`, or any form submission), a pending start is
 * dropped, a running baseline is aborted and no new one starts for
 * UPDATE_PAUSE_MS or while `updates.js` is busy; other tabs are told to do
 * the same.
 *
 * Tabs of one browser share the work: one run at a time (Web Locks, else a
 * short localStorage lease, else none), and each finished run tells the
 * other tabs when their checks are due (BroadcastChannel), so they make no
 * redundant requests.
 */
import { CHECKS, REFRESH_IN_BOUNDS, type CheckId } from './contract';
import { runSiteChecks, type RunResult, type RunnerSettings } from './runner';

/** Settings printed by PHP (BaselinePage::settings()). */
export interface BaselineSettings extends RunnerSettings {
	schedule: {
		/** Seconds a newly stored baseline stays fresh. */
		refresh_after: number;
		/** Seconds to wait after a run that produced no baseline. */
		retry_after_failure: number;
		/** Seconds until each check's baseline is due, when the page was built. */
		refresh_in: Record< CheckId, number >;
	};
}

/** Web Lock (and fallback lease key) that allows one run per browser. */
export const LOCK_NAME = 'updatelens-site-check-before';

/** BroadcastChannel shared by the tabs. */
export const CHANNEL_NAME = 'updatelens-site-check';

/** No baseline starts this long after an update started. */
export const UPDATE_PAUSE_MS = 60000;

/**
 * Wait after the page loads or becomes visible before a run starts: an
 * immediate update click cancels the start instead of competing with it.
 */
export const START_GRACE_MS = 750;

/** Fallback lease, longer than a run (start, two attempts, retry delay, reports). */
export const LEASE_MS = 60000;

/** Start errors after which this page stops asking until it is reloaded. */
const STOP_ERRORS = [
	'disabled',
	'forbidden',
	'invalid_nonce',
	'invalid_settings',
];

/** Check outcomes that will not change on this page (configuration, version). */
const PERMANENT_NOT_RUN = [
	'invalid_target',
	'mixed_content',
	'cross_origin',
	'unsupported_definition',
];

/** Messages between tabs. */
export type SchedulerMessage =
	| { type: 'due'; due: Partial< Record< CheckId, number > > }
	| { type: 'updating' };

export interface SchedulerDependencies {
	/** Wall-clock milliseconds (shared by the tabs). */
	now: () => number;
	isVisible: () => boolean;
	/** Whether `updates.js` is running or has queued an update. */
	isUpdating: () => boolean;
	run: ( signal: AbortSignal ) => Promise< RunResult >;
	/** Runs the task unless another tab is running one; false if it did not run. */
	lock: ( task: () => Promise< void > ) => Promise< boolean >;
	publish: ( message: SchedulerMessage ) => void;
}

export interface BaselineScheduler {
	/** Run the due checks if this page may, now. */
	evaluate: () => Promise< 'ran' | 'skipped' >;
	/** evaluate() after START_GRACE_MS, unless an update starts first (calls coalesce). */
	schedule: () => void;
	/** An update started (here, or in another tab): drop a pending start, abort and pause. */
	updateStarted: ( fromOtherTab?: boolean ) => void;
	/** Drop a pending start and abort a running baseline without pausing (the page is going away). */
	cancel: () => void;
	/** A message from another tab. */
	receive: ( message: unknown ) => void;
	/** When each check is due (milliseconds, `now()` clock). */
	dueAt: () => Record< CheckId, number >;
	isRunning: () => boolean;
	isStopped: () => boolean;
}

const isRecord = ( value: unknown ): value is Record< string, unknown > =>
	typeof value === 'object' && value !== null && ! Array.isArray( value );

const isSeconds = (
	value: unknown,
	[ min, max ]: readonly [ number, number ]
): value is number =>
	Number.isSafeInteger( value ) &&
	( value as number ) >= min &&
	( value as number ) <= max;

/**
 * The settings object PHP prints, validated, or null.
 *
 * @param value `window.updatelensSiteCheckBaseline`.
 */
export function parseBaselineSettings(
	value: unknown
): BaselineSettings | null {
	if (
		! isRecord( value ) ||
		typeof value.ajaxUrl !== 'string' ||
		typeof value.nonce !== 'string' ||
		! isRecord( value.schedule )
	) {
		return null;
	}
	const schedule = value.schedule;
	const refreshIn = schedule.refresh_in;
	if (
		! isSeconds( schedule.refresh_after, [ 1, 3600 ] ) ||
		! isSeconds( schedule.retry_after_failure, [ 1, 3600 ] ) ||
		! isRecord( refreshIn ) ||
		! CHECKS.every( ( check ) =>
			isSeconds( refreshIn[ check ], REFRESH_IN_BOUNDS )
		)
	) {
		return null;
	}

	return {
		ajaxUrl: value.ajaxUrl,
		nonce: value.nonce,
		schedule: {
			refresh_after: schedule.refresh_after,
			retry_after_failure: schedule.retry_after_failure,
			refresh_in: Object.fromEntries(
				CHECKS.map( ( check ) => [ check, refreshIn[ check ] ] )
			) as Record< CheckId, number >,
		},
	};
}

/**
 * The baseline state machine, independent of the browser.
 *
 * @param settings Settings from PHP.
 * @param deps     Dependencies.
 */
export function createBaselineScheduler(
	settings: BaselineSettings,
	deps: SchedulerDependencies
): BaselineScheduler {
	const loadedAt = deps.now();
	const due = Object.fromEntries(
		CHECKS.map( ( check ) => [
			check,
			loadedAt + settings.schedule.refresh_in[ check ] * 1000,
		] )
	) as Record< CheckId, number >;
	let running: AbortController | null = null;
	let stopped = false;
	let pausedUntil = 0;
	let pending: ReturnType< typeof setTimeout > | null = null;
	const dropPending = () => {
		if ( pending !== null ) {
			clearTimeout( pending );
			pending = null;
		}
	};

	/**
	 * Learn from a finished run when each check is due again.
	 *
	 * @param result Run result.
	 */
	const apply = ( result: RunResult ) => {
		const now = deps.now();
		const after = ( seconds: number ) => now + seconds * 1000;
		const failure = after( settings.schedule.retry_after_failure );

		if ( result.status === 'cancelled' ) {
			return;
		}
		if (
			result.status === 'not_needed' &&
			result.reason === 'no_updates'
		) {
			stopped = true;
			return;
		}
		if ( result.status === 'start_failed' ) {
			if ( STOP_ERRORS.includes( result.error ?? '' ) ) {
				stopped = true;
				return;
			}
			const retry = result.retryAfter
				? after( result.retryAfter )
				: failure;
			CHECKS.forEach( ( check ) => {
				if ( due[ check ] <= now ) {
					due[ check ] = retry;
				}
			} );
		} else {
			CHECKS.forEach( ( check ) => {
				const outcome = result.checks[ check ];
				const refreshIn = result.refreshIn?.[ check ];
				if ( outcome.status === 'reported' ) {
					due[ check ] = after( settings.schedule.refresh_after );
				} else if (
					outcome.status === 'not_run' &&
					outcome.error === 'not_issued'
				) {
					due[ check ] =
						refreshIn !== undefined && refreshIn > 0
							? after( refreshIn )
							: failure;
				} else if (
					outcome.status === 'not_run' &&
					PERMANENT_NOT_RUN.includes( outcome.error )
				) {
					due[ check ] = Infinity;
				} else if ( outcome.status !== 'cancelled' ) {
					due[ check ] = failure;
				}
			} );
		}
		deps.publish( { type: 'due', due: { ...due } } );
	};

	const scheduler: BaselineScheduler = {
		async evaluate() {
			const now = deps.now();
			if (
				stopped ||
				running ||
				now < pausedUntil ||
				! deps.isVisible() ||
				deps.isUpdating() ||
				! CHECKS.some( ( check ) => due[ check ] <= now )
			) {
				return 'skipped';
			}

			const controller = new AbortController();
			running = controller;
			let result = null as RunResult | null;
			try {
				await deps.lock( async () => {
					if ( ! controller.signal.aborted ) {
						result = await deps.run( controller.signal );
					}
				} );
			} catch {
				// A failing lock or run must never surface on the page.
			} finally {
				if ( running === controller ) {
					running = null;
				}
			}
			if ( ! result ) {
				return 'skipped';
			}
			apply( result );

			return 'ran';
		},
		schedule() {
			if ( pending !== null || stopped ) {
				return;
			}
			pending = setTimeout( () => {
				pending = null;
				scheduler.evaluate().catch( () => undefined );
			}, START_GRACE_MS );
		},
		updateStarted( fromOtherTab = false ) {
			pausedUntil = deps.now() + UPDATE_PAUSE_MS;
			dropPending();
			running?.abort();
			if ( ! fromOtherTab ) {
				deps.publish( { type: 'updating' } );
			}
		},
		cancel() {
			dropPending();
			running?.abort();
		},
		receive( message ) {
			if ( ! isRecord( message ) ) {
				return;
			}
			if ( message.type === 'updating' ) {
				scheduler.updateStarted( true );
				return;
			}
			if ( message.type === 'due' && isRecord( message.due ) ) {
				const received = message.due;
				CHECKS.forEach( ( check ) => {
					const value = received[ check ];
					// Finite times only: a check another tab can never run here
					// (Infinity) is decided by this tab's own runs.
					if (
						typeof value === 'number' &&
						Number.isFinite( value )
					) {
						due[ check ] = value;
					}
				} );
			}
		},
		dueAt: () => ( { ...due } ),
		isRunning: () => running !== null,
		isStopped: () => stopped,
	};

	return scheduler;
}

/** The parts of `wp.updates` read here. */
interface UpdatesState {
	ajaxLocked?: boolean;
	queue?: unknown[];
}

/** Minimal Web Locks surface. */
interface LockManagerLike {
	request: (
		name: string,
		options: { ifAvailable: boolean },
		callback: ( lock: unknown ) => Promise< void >
	) => Promise< unknown >;
}

/** Minimal jQuery surface used for binding. */
type JQueryLike = ( target: unknown ) => {
	on: ( events: string, handler: () => void ) => unknown;
};

/** The globals the baseline script binds to. */
export interface BaselineWindow {
	document: Document;
	location: Location;
	navigator?: { locks?: LockManagerLike };
	localStorage?: Storage;
	BroadcastChannel?: typeof BroadcastChannel;
	jQuery?: JQueryLike;
	wp?: { updates?: UpdatesState };
	fetch?: typeof fetch;
	addEventListener: (
		type: string,
		listener: ( event: Event ) => void
	) => void;
	updatelensSiteCheckBaseline?: unknown;
}

/**
 * One run per browser: a Web Lock if available, else a localStorage lease,
 * else no lock (duplicates are then bounded by the server's rate limit).
 *
 * @param win Window.
 * @param now Clock.
 */
export function createRunLock(
	win: Pick< BaselineWindow, 'navigator' | 'localStorage' >,
	now: () => number
): SchedulerDependencies[ 'lock' ] {
	const locks = win.navigator?.locks;
	if ( locks && typeof locks.request === 'function' ) {
		return async ( task ) => {
			let ran = false;
			await locks.request(
				LOCK_NAME,
				{ ifAvailable: true },
				async ( lock ) => {
					if ( lock ) {
						ran = true;
						await task();
					}
				}
			);

			return ran;
		};
	}

	return async ( task ) => {
		const id = Math.random().toString( 36 ).slice( 2 );
		let storage: Storage | undefined;
		try {
			storage = win.localStorage;
			const held = storage?.getItem( LOCK_NAME )?.split( ' ' );
			if ( held && Number( held[ 1 ] ) > now() ) {
				return false;
			}
			storage?.setItem( LOCK_NAME, `${ id } ${ now() + LEASE_MS }` );
			if (
				storage &&
				storage.getItem( LOCK_NAME )?.split( ' ' )[ 0 ] !== id
			) {
				return false;
			}
		} catch {
			// Storage blocked: run without a lock.
			storage = undefined;
		}
		try {
			await task();
		} finally {
			try {
				if ( storage?.getItem( LOCK_NAME )?.split( ' ' )[ 0 ] === id ) {
					storage.removeItem( LOCK_NAME );
				}
			} catch {
				// The lease expires on its own.
			}
		}

		return true;
	};
}

/**
 * Bind the baseline scheduler to the page.
 *
 * @param win Window.
 * @return The scheduler, or null without settings or `fetch`.
 */
export function bindBaseline( win: BaselineWindow ): BaselineScheduler | null {
	const settings = parseBaselineSettings( win.updatelensSiteCheckBaseline );
	const fetchFn = win.fetch;
	if ( ! settings || typeof fetchFn !== 'function' ) {
		return null;
	}

	let channel: BroadcastChannel | null = null;
	const now = () => Date.now();
	const scheduler = createBaselineScheduler( settings, {
		now,
		isVisible: () => win.document.visibilityState === 'visible',
		isUpdating: () => {
			const updates = win.wp?.updates;
			return (
				!! updates &&
				( !! updates.ajaxLocked ||
					( Array.isArray( updates.queue ) &&
						updates.queue.length > 0 ) )
			);
		},
		run: ( signal ) =>
			runSiteChecks( settings, {
				purpose: 'before',
				signal,
				fetch: fetchFn.bind( win ),
				location: win.location,
			} ),
		lock: createRunLock( win, now ),
		publish: ( message ) => {
			try {
				channel?.postMessage( message );
			} catch {
				// Other tabs then ask the server themselves.
			}
		},
	} );

	try {
		if ( typeof win.BroadcastChannel === 'function' ) {
			channel = new win.BroadcastChannel( CHANNEL_NAME );
			channel.onmessage = ( event ) => scheduler.receive( event.data );
		}
	} catch {
		channel = null;
	}

	const schedule = () => scheduler.schedule();
	win.document.addEventListener( 'visibilitychange', schedule );
	win.addEventListener( 'focus', schedule );
	win.addEventListener( 'pageshow', ( event ) => {
		if ( ( event as PageTransitionEvent ).persisted ) {
			schedule();
		}
	} );
	win.addEventListener( 'pagehide', () => scheduler.cancel() );
	// Passive listeners only: WordPress's own handlers run unchanged.
	win.document.addEventListener(
		'submit',
		() => scheduler.updateStarted(),
		true
	);
	if ( typeof win.jQuery === 'function' ) {
		win.jQuery( win.document ).on( 'wp-plugin-updating', () =>
			scheduler.updateStarted()
		);
	}
	// Module scripts run when the document is parsed: the grace period starts
	// there, without waiting for images (plugin icons on the Updates screen).
	schedule();

	return scheduler;
}
