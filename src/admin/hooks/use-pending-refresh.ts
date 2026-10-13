import { useCallback, useEffect, useRef, useState } from 'react';

import { getAnalysis } from '../api/analyses';
import { toApiError, type ApiError } from '../api/errors';
import type { AnalysisReport } from '../types/api';

/** Delay between two background reads while results are pending. */
export const PENDING_REFRESH_INTERVAL_MS = 5000;

/**
 * Background reads per run (about three minutes of visible time): the AFTER
 * checks resolve within that once the update has settled, and longer waits
 * end in a manual "Check again".
 */
export const PENDING_REFRESH_MAX_REQUESTS = 36;

/** Consecutive failed reads after which a run stops. */
export const PENDING_REFRESH_MAX_FAILURES = 3;

/**
 * `off`: nothing pending (disabled, historical, resolved or completed
 * reports are never read again); `auto`: reading in the background;
 * `stopped`: still pending, but the run ended (limit or failures).
 */
export type PendingRefreshState = 'off' | 'auto' | 'stopped';

export interface PendingRefresh {
	state: PendingRefreshState;
	/**
	 * The last failed read: why a stopped run stopped (null when it stopped
	 * at the read limit), or, while running, a read of this run that failed
	 * and was not followed by a successful one.
	 */
	error: ApiError | null;
	/** A read is in flight (a background read or a manual refresh). */
	checking: boolean;
	/** Reads now and starts a new run. */
	refreshNow: () => void;
}

/**
 * The report's Refresh button: `idle` until first used, then `refreshing`,
 * `done` or `failed` (with the error) for the latest refresh.
 */
export interface ManualRefresh {
	status: 'idle' | 'refreshing' | 'done' | 'failed';
	error: ApiError | null;
	/** Reads the report again; ignored while a refresh is running. */
	refresh: () => void;
}

export interface ReportRefresh extends PendingRefresh {
	manual: ManualRefresh;
}

const ignore = () => {};

/**
 * Whether a report has results that can still arrive: the API says a site
 * check's AFTER result is pending. Nothing is derived from times here.
 *
 * @param report Report.
 */
export function hasPendingResults( report: AnalysisReport ): boolean {
	return (
		report.site_checks?.status === 'pending' ||
		report.potential_impact.site_checks?.status === 'pending'
	);
}

/**
 * Re-reads a report through the existing analysis API, in place: in the
 * background while it has pending results, so an open report shows them
 * when they arrive, and when the administrator asks for it (`manual`).
 *
 * Background reads are bounded: one read at a time, every
 * PENDING_REFRESH_INTERVAL_MS, none while the document is hidden (the next
 * read runs when it is visible again), and the run stops when nothing is
 * pending any more, the view unmounts, the report changes, after
 * PENDING_REFRESH_MAX_REQUESTS reads or after PENDING_REFRESH_MAX_FAILURES
 * failed reads in a row. Failed background reads keep the current report; a
 * run that stopped because reads failed reports the last failure (`error`),
 * and a failed manual refresh keeps the report too and reports its error.
 *
 * Reads never overlap: a manual refresh waits for a background read in
 * flight and then reads, and the background waits a full interval after a
 * manual read.
 *
 * @param id       Analysis ID.
 * @param report   Current report, null while loading.
 * @param onUpdate Receives a newer report.
 */
export function usePendingRefresh(
	id: number,
	report: AnalysisReport | null,
	onUpdate: ( report: AnalysisReport ) => void
): ReportRefresh {
	const pending = report !== null && hasPendingResults( report );
	const [ run, setRun ] = useState( 0 );
	const [ stopped, setStopped ] = useState< {
		id: number;
		run: number;
		error: ApiError | null;
	} | null >( null );
	const [ reads, setReads ] = useState( 0 );
	// The latest failed background read of the running run, cleared by a
	// successful one: a pressed "Refresh results" that fails says so at once.
	const [ failure, setFailure ] = useState< {
		id: number;
		run: number;
		error: ApiError;
	} | null >( null );
	const [ manual, setManual ] = useState< {
		status: ManualRefresh[ 'status' ];
		error: ApiError | null;
	} >( { status: 'idle', error: null } );
	const update = useRef( onUpdate );
	const immediate = useRef( false );
	// The read in flight, background or manual.
	const busy = useRef< Promise< AnalysisReport > | null >( null );
	const manualRunning = useRef( false );
	const mounted = useRef( true );

	useEffect( () => {
		update.current = onUpdate;
	} );

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	const read = useCallback( () => {
		const next = getAnalysis( id );
		busy.current = next;
		setReads( ( n ) => n + 1 );
		const done = () => {
			if ( busy.current === next ) {
				busy.current = null;
			}
			setReads( ( n ) => n - 1 );
		};
		next.then( done, done );
		return next;
	}, [ id ] );

	useEffect( () => {
		if ( ! pending ) {
			return;
		}
		let active = true;
		let timer: number | undefined;
		let inFlight = false;
		let deferred = false;
		let requests = 0;
		let failures = 0;
		let lastError: ApiError | null = null;

		const schedule = ( delay: number ) => {
			timer = window.setTimeout( tick, delay );
		};
		function tick() {
			timer = undefined;
			if ( ! active || inFlight ) {
				return;
			}
			if ( document.hidden ) {
				deferred = true;
				return;
			}
			// A manual refresh is reading: it is this read; the next one
			// follows a full interval after it.
			if ( busy.current !== null ) {
				busy.current.then( ignore, ignore ).then( () => {
					if ( active && timer === undefined ) {
						schedule( PENDING_REFRESH_INTERVAL_MS );
					}
				} );
				return;
			}
			inFlight = true;
			read()
				.then(
					( next ) => {
						failures = 0;
						lastError = null;
						if ( active ) {
							setFailure( null );
							update.current( next );
						}
					},
					( error: unknown ) => {
						failures++;
						lastError = toApiError( error );
						if ( active ) {
							setFailure( { id, run, error: lastError } );
						}
					}
				)
				.finally( () => {
					inFlight = false;
					requests++;
					if ( ! active ) {
						return;
					}
					if (
						requests >= PENDING_REFRESH_MAX_REQUESTS ||
						failures >= PENDING_REFRESH_MAX_FAILURES
					) {
						setStopped( {
							id,
							run,
							error:
								failures >= PENDING_REFRESH_MAX_FAILURES
									? lastError
									: null,
						} );
						return;
					}
					// If the update resolved the results, this effect is cleaned
					// up before the timer fires: no further read.
					schedule( PENDING_REFRESH_INTERVAL_MS );
				} );
		}
		const onVisibility = () => {
			if ( active && deferred && ! document.hidden ) {
				deferred = false;
				schedule( 0 );
			}
		};

		document.addEventListener( 'visibilitychange', onVisibility );
		schedule( immediate.current ? 0 : PENDING_REFRESH_INTERVAL_MS );
		immediate.current = false;

		return () => {
			active = false;
			window.clearTimeout( timer );
			document.removeEventListener( 'visibilitychange', onVisibility );
		};
	}, [ id, pending, run, read ] );

	const refreshNow = useCallback( () => {
		immediate.current = true;
		setRun( ( n ) => n + 1 );
	}, [] );

	const refresh = useCallback( () => {
		if ( manualRunning.current ) {
			return;
		}
		manualRunning.current = true;
		setManual( { status: 'refreshing', error: null } );
		// After a background read in flight, never alongside it.
		( busy.current ?? Promise.resolve( null ) )
			.then( ignore, ignore )
			.then( read )
			.then(
				( next ) => {
					if ( mounted.current ) {
						update.current( next );
						setManual( { status: 'done', error: null } );
					}
				},
				( error: unknown ) => {
					if ( mounted.current ) {
						setManual( {
							status: 'failed',
							error: toApiError( error ),
						} );
					}
				}
			)
			.finally( () => {
				manualRunning.current = false;
			} );
	}, [ read ] );

	let state: PendingRefreshState = 'off';
	const ended = stopped !== null && stopped.id === id && stopped.run === run;
	if ( pending ) {
		state = ended ? 'stopped' : 'auto';
	}

	return {
		state,
		error: ! pending
			? null
			: ended
				? stopped.error
				: failure !== null && failure.id === id && failure.run === run
					? failure.error
					: null,
		checking: pending && reads > 0,
		refreshNow,
		manual: { ...manual, refresh },
	};
}
