/**
 * The readiness line on Installed Plugins: whether the earlier (BEFORE) site
 * checks are in place for the next "Update now" ("Preparing site checks…",
 * "Ready to compare site responses", "Preparing site checks for another
 * update…"). Ready means a comparison is possible, never that it will pass.
 * On Dashboard → Updates, whose updates get no later check, a fixed note
 * says so instead and links to Installed Plugins.
 *
 * Built only from what PHP said when the page loaded (Baselines::status():
 * a stored, attachable baseline and its verdict per check) and from what this
 * page's runs reported since; it never sends a request of its own. "Ready"
 * means a stored earlier check with a conclusive result (pass, failure or
 * redirect) that an update starting now could be compared with. Inconclusive,
 * failed, expired, unsupported and unknown checks are never ready, and an
 * update that starts makes every earlier check outdated.
 *
 * A passive element near the page title: no buttons, no focus, no notices,
 * nothing an update waits for (the only link is the Updates screen's). Texts come from PHP
 * (BaselinePage::status_labels()).
 */
import { CHECKS, type CheckId } from './contract';
import type { RunResult, ServerVerdict } from './runner';

/** Texts of the line, translated by PHP. */
export interface ReadinessLabels {
	prefix: string;
	preparing: string;
	preparing_next: string;
	ready: string;
	ready_home: string;
	ready_rest: string;
	unavailable: string;
	/** The Updates screen: only "Update now" on Installed Plugins is compared. */
	other_screen: string;
	plugins_link: string;
}

const LABEL_KEYS: Array< keyof ReadinessLabels > = [
	'prefix',
	'preparing',
	'preparing_next',
	'ready',
	'ready_home',
	'ready_rest',
	'unavailable',
	'other_screen',
	'plugins_link',
];

/** One check as PHP saw it when the page was built. */
export interface ReadinessCheck {
	state: 'ready' | 'none' | 'unsupported';
	verdict: ServerVerdict | null;
	/** Seconds until a ready baseline is too old to be attached. */
	expires_in: number;
}

/** `status` of the baseline settings (BaselinePage::settings()). */
export interface ReadinessSettings {
	/** Seconds a newly stored baseline can be attached (Baselines::MAX_AGE_SECONDS). */
	max_age: number;
	/**
	 * `plugins`: the readiness line. `updates` (Dashboard → Updates): a note
	 * that only "Update now" on Installed Plugins is compared, with a link.
	 */
	screen: 'plugins' | 'updates';
	/** Installed Plugins, plugins with an update (admin URL from PHP). */
	plugins_url: string;
	checks: Record< CheckId, ReadinessCheck >;
	labels: ReadinessLabels;
}

/** What the line says. */
export type ReadinessState =
	/** Earlier checks are not in place yet (running, or due on this page). */
	| 'preparing'
	/** The same after an update started: they are for the next update. */
	| 'preparing_next'
	| 'ready'
	| 'ready_home'
	| 'ready_rest'
	| 'unavailable';

/** A check's earlier result shared with other tabs. */
export interface SharedBaseline {
	verdict: ServerVerdict;
	/** Milliseconds (wall clock) until it is too old. */
	until: number;
}

type CheckReadiness =
	| { kind: 'ready'; verdict: ServerVerdict; until: number }
	| { kind: 'none' }
	| { kind: 'failed' }
	| { kind: 'unsupported' };

const VERDICTS: readonly ServerVerdict[] = [
	'pass',
	'fail',
	'redirect',
	'inconclusive',
];

/** Run outcomes after which a check stays unusable on this page. */
const PERMANENT = [
	'invalid_target',
	'mixed_content',
	'cross_origin',
	'unsupported_definition',
];

const isRecord = ( value: unknown ): value is Record< string, unknown > =>
	typeof value === 'object' && value !== null && ! Array.isArray( value );

/**
 * The `status` object PHP prints, validated, or null (then no line is shown).
 *
 * @param value `schedule`'s sibling `status`.
 */
export function parseReadinessSettings(
	value: unknown
): ReadinessSettings | null {
	if (
		! isRecord( value ) ||
		! Number.isSafeInteger( value.max_age ) ||
		( value.max_age as number ) < 1 ||
		! isRecord( value.checks ) ||
		! isRecord( value.labels )
	) {
		return null;
	}
	const labels = value.labels;
	if (
		! LABEL_KEYS.every( ( key ) => typeof labels[ key ] === 'string' ) ||
		! [ 'plugins', 'updates' ].includes( value.screen as string ) ||
		typeof value.plugins_url !== 'string'
	) {
		return null;
	}
	const checks = {} as Record< CheckId, ReadinessCheck >;
	for ( const check of CHECKS ) {
		const item = ( value.checks as Record< string, unknown > )[ check ];
		if (
			! isRecord( item ) ||
			! [ 'ready', 'none', 'unsupported' ].includes(
				item.state as string
			) ||
			! (
				item.verdict === null ||
				VERDICTS.includes( item.verdict as ServerVerdict )
			) ||
			! Number.isSafeInteger( item.expires_in ) ||
			( item.expires_in as number ) < 0
		) {
			return null;
		}
		checks[ check ] = {
			state: item.state as ReadinessCheck[ 'state' ],
			verdict: item.verdict as ServerVerdict | null,
			expires_in: item.expires_in as number,
		};
	}

	return {
		max_age: value.max_age as number,
		screen: value.screen as ReadinessSettings[ 'screen' ],
		plugins_url: value.plugins_url,
		checks,
		labels: Object.fromEntries(
			LABEL_KEYS.map( ( key ) => [ key, labels[ key ] as string ] )
		) as unknown as ReadinessLabels,
	};
}

export interface Readiness {
	state: () => ReadinessState;
	/** Whether a run of this page is in progress (the icon only). */
	isRunning: () => boolean;
	/** A run of this page started. */
	runStarted: () => void;
	/** A run of this page ended (also aborted ones). */
	runFinished: ( result: RunResult | null ) => void;
	/** An update started here or in another tab: earlier checks are outdated. */
	updateStarted: () => void;
	/** Another tab stored earlier checks. */
	receive: ( shared: Partial< Record< CheckId, SharedBaseline > > ) => void;
	/** Earlier checks this page stored, for other tabs. */
	shared: () => Partial< Record< CheckId, SharedBaseline > >;
	/** The page stopped asking (e.g. a refused nonce). */
	stop: () => void;
	/** When the state may change by itself (a baseline gets too old), or null. */
	nextChange: () => number | null;
}

/**
 * The readiness state machine, independent of the page.
 *
 * @param settings `status` from PHP.
 * @param now      Wall-clock milliseconds.
 */
export function createReadiness(
	settings: ReadinessSettings,
	now: () => number
): Readiness {
	const loaded = now();
	const checks = Object.fromEntries(
		CHECKS.map( ( check ) => {
			const item = settings.checks[ check ];
			let state: CheckReadiness;
			if ( item.state === 'unsupported' ) {
				state = { kind: 'unsupported' };
			} else if ( item.state === 'ready' && item.verdict !== null ) {
				state = {
					kind: 'ready',
					verdict: item.verdict,
					until: loaded + item.expires_in * 1000,
				};
			} else {
				state = { kind: 'none' };
			}
			return [ check, state ];
		} )
	) as Record< CheckId, CheckReadiness >;
	let running = false;
	let stopped = false;
	// An update started since: "not ready" is about another update.
	let updated = false;

	const comparable = ( check: CheckId, at: number ) => {
		const item = checks[ check ];
		return (
			item.kind === 'ready' &&
			item.verdict !== 'inconclusive' &&
			item.until > at
		);
	};

	return {
		state() {
			if ( stopped ) {
				return 'unavailable';
			}
			const at = now();
			const ready = CHECKS.filter( ( check ) => comparable( check, at ) );
			if ( ready.length === CHECKS.length ) {
				return 'ready';
			}
			if ( running ) {
				return updated ? 'preparing_next' : 'preparing';
			}
			if ( ready.length > 0 ) {
				return ready[ 0 ] === 'home' ? 'ready_home' : 'ready_rest';
			}
			// Nothing usable: unavailable when no check can become ready by
			// itself on this page, otherwise still preparing (a run is due).
			const stuck = CHECKS.every( ( check ) => {
				const item = checks[ check ];
				return (
					item.kind === 'unsupported' ||
					item.kind === 'failed' ||
					( item.kind === 'ready' && item.verdict === 'inconclusive' )
				);
			} );
			if ( stuck ) {
				return 'unavailable';
			}
			return updated ? 'preparing_next' : 'preparing';
		},
		isRunning: () => running,
		runStarted() {
			running = true;
		},
		runFinished( result ) {
			running = false;
			if ( ! result || result.status === 'cancelled' ) {
				return;
			}
			const at = now();
			if ( result.status === 'start_failed' ) {
				CHECKS.forEach( ( check ) => {
					if (
						! comparable( check, at ) &&
						checks[ check ].kind !== 'unsupported'
					) {
						checks[ check ] = { kind: 'failed' };
					}
				} );
				return;
			}
			if ( result.status !== 'started' ) {
				return;
			}
			CHECKS.forEach( ( check ) => {
				const outcome = result.checks[ check ];
				if ( outcome.status === 'reported' ) {
					checks[ check ] = {
						kind: 'ready',
						verdict: outcome.verdict,
						until: at + settings.max_age * 1000,
					};
				} else if (
					outcome.status === 'not_run' &&
					PERMANENT.includes( outcome.error )
				) {
					checks[ check ] = { kind: 'unsupported' };
				} else if (
					outcome.status !== 'cancelled' &&
					! (
						outcome.status === 'not_run' &&
						outcome.error === 'not_issued'
					) &&
					! comparable( check, at )
				) {
					// A failed refresh leaves a stored, still usable one alone.
					checks[ check ] = { kind: 'failed' };
				}
			} );
		},
		updateStarted() {
			running = false;
			updated = true;
			CHECKS.forEach( ( check ) => {
				if ( checks[ check ].kind !== 'unsupported' ) {
					checks[ check ] = { kind: 'none' };
				}
			} );
		},
		receive( shared ) {
			CHECKS.forEach( ( check ) => {
				const item = shared[ check ];
				if (
					isRecord( item ) &&
					VERDICTS.includes( item.verdict ) &&
					typeof item.until === 'number' &&
					Number.isFinite( item.until ) &&
					checks[ check ].kind !== 'unsupported'
				) {
					checks[ check ] = {
						kind: 'ready',
						verdict: item.verdict,
						until: item.until,
					};
				}
			} );
		},
		shared() {
			const shared: Partial< Record< CheckId, SharedBaseline > > = {};
			CHECKS.forEach( ( check ) => {
				const item = checks[ check ];
				if ( item.kind === 'ready' ) {
					shared[ check ] = {
						verdict: item.verdict,
						until: item.until,
					};
				}
			} );
			return shared;
		},
		stop() {
			stopped = true;
			running = false;
		},
		nextChange() {
			const at = now();
			const times = CHECKS.flatMap( ( check ) => {
				const item = checks[ check ];
				return item.kind === 'ready' && item.until > at
					? [ item.until ]
					: [];
			} );
			return times.length > 0 ? Math.min( ...times ) : null;
		},
	};
}

/** Dashicon and its color per state (WordPress admin colors). */
const LOOK: Record< ReadinessState, { icon: string; color: string } > = {
	preparing: { icon: 'dashicons-clock', color: '#646970' },
	preparing_next: { icon: 'dashicons-clock', color: '#646970' },
	ready: { icon: 'dashicons-yes-alt', color: '#008a20' },
	ready_home: { icon: 'dashicons-yes-alt', color: '#996800' },
	ready_rest: { icon: 'dashicons-yes-alt', color: '#996800' },
	unavailable: { icon: 'dashicons-warning', color: '#996800' },
};

/** While a run is in progress, preparing states turn the clock into this. */
const RUNNING = { icon: 'dashicons-update', color: '#2271b1' };

/** The line on the page. */
export interface ReadinessLine {
	render: ( state: ReadinessState, running: boolean ) => void;
	remove: () => void;
}

/**
 * Whether a URL from PHP is a plain link on the page's own site.
 *
 * @param doc Document.
 * @param url URL.
 */
function sameOriginLink( doc: Document, url: string ): boolean {
	try {
		const parsed = new URL( url, doc.baseURI );
		return (
			( parsed.protocol === 'https:' || parsed.protocol === 'http:' ) &&
			parsed.origin === new URL( doc.baseURI ).origin
		);
	} catch {
		return false;
	}
}

/**
 * Insert the line next to the screen's title, or null if there is no title.
 *
 * Installed Plugins (`plugins`): a polite status beside "Add Plugin" that
 * render() updates. Dashboard → Updates (`updates`): a fixed note below the
 * title that updates from there are not compared, with a link to Installed
 * Plugins; render() leaves it alone (earlier checks still run there).
 *
 * @param doc      Document.
 * @param settings `status` from PHP.
 */
export function mountReadinessLine(
	doc: Document,
	settings: Pick< ReadinessSettings, 'labels' | 'screen' | 'plugins_url' >
): ReadinessLine | null {
	if ( typeof doc.querySelector !== 'function' ) {
		return null;
	}
	const { labels } = settings;
	const wrap = doc.querySelector( '#wpbody-content .wrap' );
	const anchor =
		wrap?.querySelector( ':scope > .wp-header-end' ) ??
		wrap?.querySelector( ':scope > h1' )?.nextSibling ??
		null;
	if ( ! wrap || ! anchor ) {
		return null;
	}
	const other = settings.screen === 'updates';
	const line = doc.createElement( other ? 'p' : 'span' );
	line.className = 'updatelens-site-check-status';
	line.style.cssText = other
		? 'display:flex;align-items:flex-start;gap:4px;margin:4px 0 0;max-width:60em;font-size:13px;line-height:1.5;color:#3c434a;'
		: 'display:inline-flex;align-items:flex-start;gap:4px;margin:0 0 0 12px;vertical-align:middle;font-size:13px;line-height:1.5;color:#3c434a;';
	const icon = doc.createElement( 'span' );
	icon.setAttribute( 'aria-hidden', 'true' );
	icon.style.cssText =
		'flex-shrink:0;font-size:18px;width:18px;height:18px;margin-top:1px;';
	const prefix = doc.createElement( 'strong' );
	prefix.style.fontWeight = '600';
	prefix.textContent = labels.prefix;
	const text = doc.createElement( 'span' );
	// One inline run, so a narrow screen wraps the words, not a column.
	const words = doc.createElement( 'span' );
	words.append( prefix, ' ', text );
	line.append( icon, words );

	if ( other ) {
		// A fixed note, not a live region: nothing on it changes.
		icon.className = 'dashicons dashicons-info';
		icon.style.color = '#2271b1';
		text.textContent = labels.other_screen;
		line.dataset.state = 'other_screen';
		if ( sameOriginLink( doc, settings.plugins_url ) ) {
			const link = doc.createElement( 'a' );
			link.href = settings.plugins_url;
			link.textContent = labels.plugins_link;
			words.append( ' ', link );
		}
		wrap.insertBefore( line, anchor );
		return {
			render: () => undefined,
			remove: () => line.remove(),
		};
	}

	line.setAttribute( 'role', 'status' );
	wrap.insertBefore( line, anchor );

	return {
		render( state, running ) {
			const look =
				running &&
				( state === 'preparing' || state === 'preparing_next' )
					? RUNNING
					: LOOK[ state ];
			icon.className = `dashicons ${ look.icon }`;
			icon.style.color = look.color;
			if ( text.textContent !== labels[ state ] ) {
				text.textContent = labels[ state ];
			}
			line.dataset.state = state;
		},
		remove() {
			line.remove();
		},
	};
}
