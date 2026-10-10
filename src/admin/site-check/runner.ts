/**
 * Browser runner for the two site checks: opens a run on the server
 * (`updatelens_site_check_start`), requests the homepage and the REST API
 * independently with a timeout per attempt and one confirming retry after a
 * failure, and reports each check's facts once
 * (`updatelens_site_check_report`). PHP classifies every report; the
 * runner returns PHP's verdict, or why there is none.
 *
 * The BEFORE baseline scheduler (baseline.ts) calls it with `before`, for
 * which the server may open only the due checks or none (`not_needed`);
 * the update follow-up (update-follow-up/afterChecks.ts) calls it with
 * `after` and the analysis its follow-up settled, for which the server opens
 * only the checks with a baseline, or none. Targets, tokens and timing come only
 * from the start response; the report secret only ever travels in the
 * report's POST body. No response content is kept or sent.
 */
import {
	CHECKS,
	REPORT_ACTION,
	START_ACTION,
	SUPPORTED_DEFINITIONS,
	parseNotNeeded,
	parseStartResponse,
	type AttemptFacts,
	type CheckId,
	type IssuedCheck,
	type NotNeededRun,
	type Purpose,
	type RefreshIn,
	type StartedRun,
} from './contract';
import {
	CancelledError,
	attemptPassed,
	observeHome,
	observeRest,
	targetProblem,
	type AttemptFailure,
	type AttemptRequest,
	type PageLocation,
	type TargetProblem,
} from './observe';

/** Settings printed by PHP for the page that runs checks. */
export interface RunnerSettings {
	/** `admin-ajax.php` URL (same origin as the page). */
	ajaxUrl: string;
	/** `updatelens_site_check` nonce. */
	nonce: string;
}

export interface RunnerOptions {
	purpose: Purpose;
	/** `after` runs: the analysis they belong to (from the follow-up response). */
	analysis?: number;
	/** Cancels the run: in-flight requests are aborted, nothing more is reported. */
	signal?: AbortSignal;
	fetch?: typeof fetch;
	now?: () => number;
	location?: PageLocation;
}

/** PHP's verdict of a reported check run. */
export type ServerVerdict = 'pass' | 'fail' | 'redirect' | 'inconclusive';

/** Why a check has no server verdict. */
export type NotRunReason =
	TargetProblem | 'not_issued' | 'unsupported_definition' | 'start_failed';

export type CheckOutcome =
	/** PHP accepted the report and classified it. */
	| {
			check: CheckId;
			status: 'reported';
			verdict: ServerVerdict;
			reason: string | null;
			attempts: AttemptFacts[];
	  }
	/** The browser observed the check but the report was not accepted. */
	| {
			check: CheckId;
			status: 'report_failed';
			error: string;
			attempts: AttemptFacts[];
	  }
	/** The session expired before the run could be reported. */
	| { check: CheckId; status: 'expired'; attempts: AttemptFacts[] }
	/** The check was not run. */
	| { check: CheckId; status: 'not_run'; error: NotRunReason }
	/** The caller cancelled the run before this check was reported. */
	| { check: CheckId; status: 'cancelled' };

export interface RunResult {
	/**
	 * `started`; `not_needed` (a start that the server answered without
	 * opening a session); or why no check could run.
	 */
	status: 'started' | 'not_needed' | 'start_failed' | 'cancelled';
	/** Start failure: the server's error code, `http_<status>`, `network_error` or `invalid_response`. */
	error?: string;
	/** `not_needed`: why. */
	reason?: NotNeededRun[ 'reason' ];
	/** `before` starts: seconds until each check's baseline is due. */
	refreshIn?: RefreshIn;
	/** `rate_limited` start failures: seconds until a start is allowed again. */
	retryAfter?: number;
	/** Both checks, always (not issued ones are `not_run`). */
	checks: Record< CheckId, CheckOutcome >;
}

/** Margin kept before the session's expiry when deciding to report. */
export const EXPIRY_MARGIN_MS = 2000;

const SERVER_CODE = /^[a-z_]{1,40}$/;
const VERDICTS: ServerVerdict[] = [
	'pass',
	'fail',
	'redirect',
	'inconclusive',
];

/** Everything one run needs. */
interface Context {
	fetch: typeof fetch;
	now: () => number;
	signal: AbortSignal;
	settings: RunnerSettings;
	run: StartedRun;
	deadline: number;
}

/**
 * Run both checks once.
 *
 * @param settings Settings from PHP.
 * @param options  Purpose, cancellation and dependencies.
 * @return Both checks' outcomes; never throws for check, network or server failures.
 */
export async function runSiteChecks(
	settings: RunnerSettings,
	options: RunnerOptions
): Promise< RunResult > {
	const fetchFn = options.fetch ?? globalThis.fetch.bind( globalThis );
	const now = options.now ?? ( () => Date.now() );
	const page = options.location ?? globalThis.location;
	const signal = options.signal ?? new AbortController().signal;

	const all = ( outcome: ( check: CheckId ) => CheckOutcome ) =>
		Object.fromEntries(
			CHECKS.map( ( check ) => [ check, outcome( check ) ] )
		) as Record< CheckId, CheckOutcome >;
	const notStarted = (
		status: RunResult[ 'status' ],
		error?: string
	): RunResult => ( {
		status,
		...( error ? { error } : {} ),
		checks: all( ( check ) =>
			status === 'cancelled'
				? { check, status: 'cancelled' }
				: { check, status: 'not_run', error: 'start_failed' }
		),
	} );

	if ( signal.aborted ) {
		return notStarted( 'cancelled' );
	}
	if (
		! sameOrigin( settings.ajaxUrl, page ) ||
		typeof settings.nonce !== 'string' ||
		settings.nonce === '' ||
		( options.purpose === 'after' &&
			! (
				Number.isSafeInteger( options.analysis ) &&
				( options.analysis as number ) > 0
			) )
	) {
		return notStarted( 'start_failed', 'invalid_settings' );
	}

	const startedAt = now();
	let started: PostResult;
	try {
		started = await post(
			fetchFn,
			settings.ajaxUrl,
			{
				action: START_ACTION,
				_ajax_nonce: settings.nonce,
				purpose: options.purpose,
				...( options.purpose === 'after'
					? { analysis: String( options.analysis ) }
					: {} ),
			},
			signal
		);
	} catch {
		return notStarted( 'cancelled' );
	}
	if ( ! started.ok ) {
		return {
			...notStarted( 'start_failed', started.error ),
			...( started.retryAfter ? { retryAfter: started.retryAfter } : {} ),
		};
	}
	const notNeeded = parseNotNeeded( started.data, options.purpose );
	if ( notNeeded ) {
		return {
			status: 'not_needed',
			reason: notNeeded.reason,
			...( notNeeded.refreshIn
				? { refreshIn: notNeeded.refreshIn }
				: {} ),
			checks: all( ( check ) => ( {
				check,
				status: 'not_run',
				error: 'not_issued',
			} ) ),
		};
	}
	const run = parseStartResponse( started.data );
	if ( ! run || run.purpose !== options.purpose ) {
		return notStarted( 'start_failed', 'invalid_response' );
	}

	const context: Context = {
		fetch: fetchFn,
		now,
		signal,
		settings,
		run,
		deadline: startedAt + run.expiresInSeconds * 1000 - EXPIRY_MARGIN_MS,
	};
	const outcomes = await Promise.all(
		CHECKS.map( ( check ) =>
			runCheck( check, context, page ).catch( (): CheckOutcome =>
				// Never let one check's unexpected error affect the other.
				signal.aborted
					? { check, status: 'cancelled' }
					: { check, status: 'not_run', error: 'start_failed' }
			)
		)
	);

	return {
		status: 'started',
		...( run.refreshIn ? { refreshIn: run.refreshIn } : {} ),
		checks: Object.fromEntries(
			outcomes.map( ( outcome ) => [ outcome.check, outcome ] )
		) as Record< CheckId, CheckOutcome >,
	};
}

/**
 * Run and report one check.
 *
 * @param check   Check.
 * @param context Run context.
 * @param page    The page's location.
 */
async function runCheck(
	check: CheckId,
	context: Context,
	page: PageLocation
): Promise< CheckOutcome > {
	const issued = context.run.checks[ check ];
	if ( ! issued ) {
		return { check, status: 'not_run', error: 'not_issued' };
	}
	if ( issued.definition !== SUPPORTED_DEFINITIONS[ check ] ) {
		return { check, status: 'not_run', error: 'unsupported_definition' };
	}
	const problem = targetProblem( issued, page );
	if ( problem ) {
		return { check, status: 'not_run', error: problem };
	}

	const attempts: AttemptFacts[] = [];
	try {
		for ( let index = 0; index < context.run.maxAttempts; index++ ) {
			if ( index > 0 ) {
				await sleep( context.run.retryDelayMs, context.signal );
			}
			// Only attempt what can still be reported before the session ends.
			if ( context.now() + context.run.timeoutMs > context.deadline ) {
				break;
			}
			const facts = await attempt( issued, context );
			attempts.push( facts );
			if ( attemptPassed( check, facts, issued.user ) ) {
				break;
			}
		}
		if ( attempts.length === 0 || context.now() > context.deadline ) {
			return { check, status: 'expired', attempts };
		}

		const report = await post(
			context.fetch,
			context.settings.ajaxUrl,
			{
				action: REPORT_ACTION,
				_ajax_nonce: context.settings.nonce,
				session: issued.session,
				secret: issued.secret,
				report: JSON.stringify( { check, attempts } ),
			},
			context.signal
		);
		if ( ! report.ok ) {
			return {
				check,
				status: 'report_failed',
				error: report.error,
				attempts,
			};
		}
		const data = report.data as {
			code?: unknown;
			check?: unknown;
			verdict?: unknown;
			reason?: unknown;
		};
		if (
			data?.code !== 'accepted' ||
			data.check !== check ||
			! VERDICTS.includes( data.verdict as ServerVerdict ) ||
			! (
				data.reason === null ||
				( typeof data.reason === 'string' &&
					SERVER_CODE.test( data.reason ) )
			)
		) {
			return {
				check,
				status: 'report_failed',
				error: 'invalid_response',
				attempts,
			};
		}

		return {
			check,
			status: 'reported',
			verdict: data.verdict as ServerVerdict,
			reason: data.reason as string | null,
			attempts,
		};
	} catch ( error ) {
		if ( error instanceof CancelledError || context.signal.aborted ) {
			return { check, status: 'cancelled' };
		}
		throw error;
	}
}

/**
 * One attempt with its own timeout, aborted with the run.
 *
 * @param issued  Issued check.
 * @param context Run context.
 */
async function attempt(
	issued: IssuedCheck,
	context: Context
): Promise< AttemptFacts > {
	if ( context.signal.aborted ) {
		throw new CancelledError();
	}
	const controller = new AbortController();
	let reason: AttemptFailure | null = null;
	const abort = ( why: AttemptFailure ) => {
		reason ??= why;
		controller.abort();
	};
	const onCancel = () => abort( 'cancelled' );
	const timer = setTimeout( () => abort( 'timeout' ), context.run.timeoutMs );
	context.signal.addEventListener( 'abort', onCancel );

	const request: AttemptRequest = {
		fetch: context.fetch,
		signal: controller.signal,
		aborted: () => reason,
		now: context.now,
	};
	try {
		return issued.check === 'home'
			? await observeHome( issued, request )
			: await observeRest( issued, request );
	} finally {
		clearTimeout( timer );
		context.signal.removeEventListener( 'abort', onCancel );
	}
}

/** Longest `retry_after` accepted from a `rate_limited` error (RateLimiter window). */
const MAX_RETRY_AFTER_SECONDS = 600;

type PostResult =
	| { ok: true; data: unknown }
	| { ok: false; error: string; retryAfter?: number };

/**
 * POST to admin-ajax and read WordPress's `{success, data}` envelope.
 *
 * @param fetchFn Fetch.
 * @param url     admin-ajax URL.
 * @param fields  Form fields.
 * @param signal  Cancellation.
 * @return The `data` on success, else a fixed error (the server's `code`,
 *         `http_<status>`, `network_error` or `invalid_response`) and, for
 *         `rate_limited`, the server's `retry_after`.
 * @throws CancelledError When cancelled.
 */
async function post(
	fetchFn: typeof fetch,
	url: string,
	fields: Record< string, string >,
	signal: AbortSignal
): Promise< PostResult > {
	let response: Response;
	let text: string;
	try {
		response = await fetchFn( url, {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			redirect: 'error',
			body: new URLSearchParams( fields ),
			signal,
		} );
		text = await response.text();
	} catch {
		if ( signal.aborted ) {
			throw new CancelledError();
		}
		return { ok: false, error: 'network_error' };
	}

	let json: unknown;
	try {
		json = JSON.parse( text );
	} catch {
		return {
			ok: false,
			error: response.ok
				? 'invalid_response'
				: `http_${ response.status }`,
		};
	}
	const envelope = json as {
		success?: unknown;
		data?: { code?: unknown; retry_after?: unknown };
	};
	if ( envelope?.success === true && response.ok ) {
		return { ok: true, data: envelope.data };
	}
	const code = envelope?.data?.code;
	const retryAfter = envelope?.data?.retry_after;

	return {
		ok: false,
		error:
			typeof code === 'string' && SERVER_CODE.test( code )
				? code
				: `http_${ response.status }`,
		...( code === 'rate_limited' &&
		Number.isSafeInteger( retryAfter ) &&
		( retryAfter as number ) > 0 &&
		( retryAfter as number ) <= MAX_RETRY_AFTER_SECONDS
			? { retryAfter: retryAfter as number }
			: {} ),
	};
}

/**
 * Wait, unless cancelled.
 *
 * @param ms     Milliseconds.
 * @param signal Cancellation.
 * @throws CancelledError When cancelled.
 */
function sleep( ms: number, signal: AbortSignal ): Promise< void > {
	return new Promise( ( resolve, reject ) => {
		if ( signal.aborted ) {
			reject( new CancelledError() );
			return;
		}
		const onAbort = () => {
			clearTimeout( timer );
			reject( new CancelledError() );
		};
		const timer = setTimeout( () => {
			signal.removeEventListener( 'abort', onAbort );
			resolve();
		}, ms );
		signal.addEventListener( 'abort', onAbort, { once: true } );
	} );
}

/**
 * Whether a URL is on the page's origin.
 *
 * @param url  URL.
 * @param page The page's location.
 */
function sameOrigin( url: unknown, page: PageLocation | undefined ): boolean {
	if ( typeof url !== 'string' || ! page ) {
		return false;
	}
	try {
		return new URL( url ).origin === page.origin;
	} catch {
		return false;
	}
}
