import { createContext, useContext } from 'react';

import type {
	AnalysisReport,
	FindingRecord,
	ImpactFinding,
	Provider,
} from '../types/api';
import { findingSubject } from './impact';
import type { RouteItem, RouteItemKind } from './route';

/*
 * Locating rows on a signal's details: a finding links to its signal page
 * with the option name or hook (and Action Scheduler group), the change list
 * it is about and its index in the report's findings.
 *
 * Which rows a finding covers is the API's decision: each configuration
 * finding lists its Net result records (`records`: list and index in
 * `phases.final.<signal>`), and the assessment counts those same records.
 * Rows are only looked up here, never matched to evidence, so the marks and
 * the counts cannot disagree. A link whose finding is no longer in the
 * report (an older link, a stale index) marks the rows of its option or hook
 * neutrally as the "group". Lists that would hide a marked row start
 * expanded. Nothing is filtered or reordered.
 */

/**
 * `exact`: the rows are the records the finding covers.
 * `group`: rows of the same option or hook (and group), for a link without
 * its finding.
 */
export type LocatedPrecision = 'exact' | 'group';

export interface Located {
	item: RouteItem;
	precision: LocatedPrecision;
	/** Diff records to mark, by identity (rows of the loaded report). */
	rows: ReadonlySet< object >;
	/** The finding the link came from, when it is still in the report. */
	finding: ImpactFinding | null;
}

const LocatedContext = createContext< Located | null >( null );

/** A row a finding covers, with its role (the finding's subject, or a replacement it weighed). */
export interface MatchedFinding {
	finding: ImpactFinding;
	role: FindingRecord[ 'role' ];
}

/** Rows of the Net result with the finding that covers each one. */
export type MatchedRows = ReadonlyMap< object, MatchedFinding >;

const MatchedContext = createContext< MatchedRows | null >( null );

export const MatchedRowsProvider = MatchedContext.Provider;

/** The finding that covers a row, if any. */
export function useMatched(
	record: object | undefined
): MatchedFinding | null {
	const matched = useContext( MatchedContext );
	return ( record !== undefined && matched?.get( record ) ) || null;
}

/**
 * The Net result rows of one record list of a signal.
 *
 * @param report Loaded report.
 * @param signal Signal.
 * @param list   Record list.
 */
function rowsOf(
	report: AnalysisReport,
	signal: Provider,
	list: FindingRecord[ 'list' ]
): object[] {
	const phase = report.phases.final[ signal ];
	if ( ! phase.available ) {
		return [];
	}
	if ( list === 'rescheduled' ) {
		return 'rescheduled' in phase ? phase.rescheduled : [];
	}
	return phase[ list ];
}

/**
 * The rows a finding's records point at (all of them, or those of one role).
 *
 * @param report  Loaded report.
 * @param finding Configuration finding.
 * @param role    Only records of this role, if given.
 */
function recordRows(
	report: AnalysisReport,
	finding: ImpactFinding,
	role?: FindingRecord[ 'role' ]
): Array< [ object, FindingRecord ] > {
	return finding.records.flatMap(
		( record ): Array< [ object, FindingRecord ] > => {
			const row = rowsOf( report, finding.signal, record.list )[
				record.index
			];
			return row !== undefined && ( ! role || record.role === role )
				? [ [ row, record ] ]
				: [];
		}
	);
}

/**
 * Every Net result row of a signal that a finding covers, from the API's
 * records: the same records the assessment counts.
 *
 * @param report Loaded report.
 * @param signal Signal of the page.
 */
export function matchedRows(
	report: AnalysisReport,
	signal: Provider
): MatchedRows {
	const rows = new Map< object, MatchedFinding >();
	report.potential_impact.findings.forEach( ( finding ) => {
		if ( finding.signal !== signal ) {
			return;
		}
		recordRows( report, finding ).forEach( ( [ row, record ] ) => {
			if ( ! rows.has( row ) ) {
				rows.set( row, { finding, role: record.role } );
			}
		} );
	} );
	return rows;
}

export const LocatedProvider = LocatedContext.Provider;

/** The rows located on this page, if any. */
export function useLocated(): Located | null {
	return useContext( LocatedContext );
}

/**
 * How a diff record is marked: as the finding's own, as a row of its option
 * or hook, or not at all.
 *
 * @param located Located rows, or null.
 * @param record  Diff record of a row.
 */
export function locatedPrecision(
	located: Located | null,
	record: object | undefined
): LocatedPrecision | null {
	return located !== null &&
		record !== undefined &&
		located.rows.has( record )
		? located.precision
		: null;
}

/**
 * The change list a finding is about.
 *
 * @param finding Finding.
 */
export function findingListKind( finding: ImpactFinding ): RouteItemKind {
	switch ( finding.code ) {
		case 'recurring_cron_event_removed':
			return 'removed';
		case 'recurring_schedule_changed':
			return 'changed';
		default:
			return finding.before === null ? 'added' : 'changed';
	}
}

/**
 * Rows of the Net result that a located item refers to: the records of its
 * finding the API names as its subjects, or, without that finding, the rows
 * of the option or hook.
 *
 * @param report Loaded report.
 * @param signal Signal of the page.
 * @param item   Located item from the URL.
 */
export function locate(
	report: AnalysisReport,
	signal: Provider,
	item: RouteItem
): Located {
	const finding = linkedFinding( report, signal, item );
	const located = (
		precision: LocatedPrecision,
		rows: object[] = []
	): Located => ( {
		item,
		precision,
		rows: new Set( rows ),
		finding,
	} );
	if ( ! report.phases.final[ signal ].available ) {
		return located( 'group' );
	}

	if ( finding ) {
		const own = recordRows( report, finding, 'subject' ).map(
			( [ row ] ) => row
		);
		if ( own.length > 0 ) {
			return located( 'exact', own );
		}
	}

	const lists: RouteItemKind[] = item.kind
		? [ item.kind ]
		: [ 'added', 'removed', 'changed' ];
	const candidates = lists
		.flatMap( ( list ) => rowsOf( report, signal, list ) )
		.filter( ( record ) => sameSubject( record, item ) );

	// A link without its finding: only a unique option name is exact.
	if ( signal === 'options' && candidates.length === 1 ) {
		return located( 'exact', candidates );
	}
	return located( 'group', candidates );
}

/**
 * The finding a link names, if it is in this report and about the same item.
 *
 * @param report Report.
 * @param signal Signal of the page.
 * @param item   Located item.
 */
function linkedFinding(
	report: AnalysisReport,
	signal: Provider,
	item: RouteItem
): ImpactFinding | null {
	if ( item.finding === undefined || item.finding === null ) {
		return null;
	}
	const finding = report.potential_impact.findings[ item.finding ];
	if (
		! finding ||
		finding.signal !== signal ||
		findingSubject( finding ) !== item.name ||
		( item.kind && item.kind !== findingListKind( finding ) ) ||
		( finding.signal === 'action_scheduler' &&
			item.group !== null &&
			item.group !== finding.group )
	) {
		return null;
	}
	return finding;
}

/**
 * Whether a record has the located name (option or hook) and group.
 *
 * @param record Diff record.
 * @param item   Located item.
 */
function sameSubject( record: object, item: RouteItem ): boolean {
	const { name, hook, group } = record as {
		name?: unknown;
		hook?: unknown;
		group?: unknown;
	};
	return (
		( typeof name === 'string' ? name : hook ) === item.name &&
		( item.group === null ||
			typeof group !== 'string' ||
			group === item.group )
	);
}
