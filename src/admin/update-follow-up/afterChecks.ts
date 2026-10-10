/**
 * AFTER site checks once the update follow-up has settled an analysis.
 *
 * The follow-up response (PluginUpdateTracker::on_follow_up() in PHP) lists
 * per plugin the result; a `settled` plugin also carries the `analysis` it
 * settled and `site_checks` (whether its AFTER checks should start). Only
 * those analyses are run, each with the runner's `after` purpose, one after
 * another: PHP decides everything else (owner, login session, the time
 * window, one run per analysis, which checks have a baseline, and whether
 * another update has run since, from its updater activity ledger).
 *
 * Never in the way of an update: nothing starts while `updates.js` has a
 * job, or when an update started after the follow-up was sent; an update
 * that starts during the run (`wp-plugin-updating`) aborts it, and the
 * remaining analyses are dropped. An aborted or lost run is never retried:
 * PHP reports it as not started or not reported, never as a result.
 */
import type { RunResult } from '../site-check/runner';

/** One analysis whose AFTER checks should run. */
export type AfterCheckAnalysis = number;

/**
 * The analyses of a follow-up response whose AFTER checks should start:
 * `settled` results with `site_checks: true` and a positive integer
 * `analysis`. Anything malformed yields nothing.
 *
 * @param data `data` of the follow-up's `{"success":true,"data":…}`.
 */
export function parseSettled( data: unknown ): AfterCheckAnalysis[] {
	if (
		typeof data !== 'object' ||
		data === null ||
		! Array.isArray( ( data as { results?: unknown } ).results )
	) {
		return [];
	}
	const analyses: AfterCheckAnalysis[] = [];
	for ( const entry of ( data as { results: unknown[] } ).results ) {
		if ( typeof entry !== 'object' || entry === null ) {
			continue;
		}
		const {
			result,
			analysis,
			site_checks: siteChecks,
		} = entry as {
			result?: unknown;
			analysis?: unknown;
			site_checks?: unknown;
		};
		if (
			result === 'settled' &&
			siteChecks === true &&
			Number.isSafeInteger( analysis ) &&
			( analysis as number ) > 0 &&
			! analyses.includes( analysis as number )
		) {
			analyses.push( analysis as number );
		}
	}

	return analyses;
}

export interface AfterChecksDependencies {
	/** Whether `updates.js` has no running or queued job. */
	isIdle: () => boolean;
	/** Run one analysis's AFTER checks (runSiteChecks with `after`). */
	run: (
		analysis: AfterCheckAnalysis,
		signal: AbortSignal
	) => Promise< RunResult >;
}

export interface AfterChecks {
	/** The follow-up is being sent: returns the token its response must bring back. */
	followUpSent: () => number;
	/** The follow-up response arrived: run its analyses' AFTER checks now. */
	settled: (
		analyses: AfterCheckAnalysis[],
		token: number
	) => Promise< AfterCheckAnalysis[] >;
	/** An update started: abort the running AFTER run, drop the rest. */
	updateStarted: () => void;
	isRunning: () => boolean;
}

/**
 * The AFTER check state machine, independent of the browser.
 *
 * @param deps Dependencies.
 */
export function createAfterChecks(
	deps: AfterChecksDependencies
): AfterChecks {
	// Moves on every update start: a follow-up sent before it is outdated.
	let epoch = 0;
	let running: AbortController | null = null;

	return {
		followUpSent() {
			return epoch;
		},
		async settled( analyses, token ) {
			const ran: AfterCheckAnalysis[] = [];
			if ( running ) {
				return ran; // One run at a time; PHP keeps the rest unstarted.
			}
			for ( const analysis of analyses ) {
				if ( token !== epoch || ! deps.isIdle() ) {
					break;
				}
				const controller = new AbortController();
				running = controller;
				try {
					await deps.run( analysis, controller.signal );
				} catch {
					// The runner contains its own failures; nothing to do here.
				} finally {
					running = null;
				}
				ran.push( analysis );
				if ( controller.signal.aborted ) {
					break;
				}
			}

			return ran;
		},
		updateStarted() {
			epoch++;
			running?.abort();
		},
		isRunning() {
			return running !== null;
		},
	};
}
