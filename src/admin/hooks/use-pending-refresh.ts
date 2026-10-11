import { useCallback, useEffect, useRef, useState } from 'react';

import { getAnalysis } from '../api/analyses';
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
	/** A read is in flight. */
	checking: boolean;
	/** Reads now and starts a new run. */
	refreshNow: () => void;
}

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
 * Re-reads a report through the existing analysis API while it has pending
 * results, so an open report shows them when they arrive.
 *
 * Bounded: one read at a time, every PENDING_REFRESH_INTERVAL_MS, none while
 * the document is hidden (the next read runs when it is visible again), and
 * the run stops when nothing is pending any more, the view unmounts, the
 * report changes, after PENDING_REFRESH_MAX_REQUESTS reads or after
 * PENDING_REFRESH_MAX_FAILURES failed reads in a row. Failed reads keep the
 * current report and are not shown.
 *
 * @param id       Analysis ID.
 * @param report   Current report, null while loading.
 * @param onUpdate Receives a newer report.
 */
export function usePendingRefresh(
	id: number,
	report: AnalysisReport | null,
	onUpdate: ( report: AnalysisReport ) => void
): PendingRefresh {
	const pending = report !== null && hasPendingResults( report );
	const [ run, setRun ] = useState( 0 );
	const [ stopped, setStopped ] = useState< {
		id: number;
		run: number;
	} | null >( null );
	const [ checking, setChecking ] = useState( false );
	const update = useRef( onUpdate );
	const immediate = useRef( false );

	useEffect( () => {
		update.current = onUpdate;
	} );

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
			inFlight = true;
			setChecking( true );
			getAnalysis( id )
				.then(
					( next ) => {
						failures = 0;
						if ( active ) {
							update.current( next );
						}
					},
					() => {
						failures++;
					}
				)
				.finally( () => {
					inFlight = false;
					requests++;
					setChecking( false );
					if ( ! active ) {
						return;
					}
					if (
						requests >= PENDING_REFRESH_MAX_REQUESTS ||
						failures >= PENDING_REFRESH_MAX_FAILURES
					) {
						setStopped( { id, run } );
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
	}, [ id, pending, run ] );

	const refreshNow = useCallback( () => {
		immediate.current = true;
		setRun( ( n ) => n + 1 );
	}, [] );

	let state: PendingRefreshState = 'off';
	if ( pending ) {
		state =
			stopped !== null && stopped.id === id && stopped.run === run
				? 'stopped'
				: 'auto';
	}

	return { state, checking: pending && checking, refreshNow };
}
