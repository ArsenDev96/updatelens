import { createContext, useContext } from 'react';

import type { AnalysisReport, ImpactFinding, Provider } from '../types/api';
import { findingSubject } from './impact';
import type { RouteItem, RouteItemKind } from './route';

/*
 * Locating rows on a signal's details: a Potential Impact finding links to
 * its signal page with the option name or hook (and Action Scheduler group),
 * the change list it is about and its index in the report's findings.
 *
 * Rows are marked as the finding's own ("exact") only when the finding's
 * evidence identifies them: an option by its name; a WP-Cron removal by the
 * removed recurring instances it lists; a schedule change by its before and
 * after values, when exactly one row has them. Entries of one hook can
 * differ only in arguments, which are never shown, so otherwise the rows of
 * the hook (and group) are marked neutrally as the "group" the finding
 * belongs to. Lists that would hide a marked row start expanded. Nothing is
 * filtered or reordered.
 */

/**
 * `exact`: the rows are the entries the finding refers to.
 * `group`: rows of the same hook (and group); the finding's own entry could
 * not be told apart from them, or not be found.
 */
export type LocatedPrecision = 'exact' | 'group';

export interface Located {
	item: RouteItem;
	precision: LocatedPrecision;
	/** Diff records to mark, by identity (rows of the loaded report). */
	rows: ReadonlySet< object >;
	/** The finding the link came from, when it is still in the report. */
	finding: ImpactFinding | null;
	/**
	 * `group` only: true when more rows match the finding's evidence than it
	 * refers to (alike except for hidden arguments), false when no row could
	 * be matched to it.
	 */
	ambiguous: boolean;
}

const LocatedContext = createContext< Located | null >( null );

export const LocatedProvider = LocatedContext.Provider;

/** The rows located on this page, if any. */
export function useLocated(): Located | null {
	return useContext( LocatedContext );
}

/**
 * How a diff record is marked: as the finding's own, as a row of its hook,
 * or not at all.
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
 * Rows of the Net result that a located item refers to.
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
	const phase = report.phases.final[ signal ];
	const none = ( ambiguous: boolean, rows: object[] = [] ): Located => ( {
		item,
		precision: 'group',
		rows: new Set( rows ),
		finding,
		ambiguous,
	} );
	if ( ! phase.available ) {
		return none( false );
	}

	const kind = finding ? findingListKind( finding ) : item.kind;
	const lists: RouteItemKind[] = kind
		? [ kind ]
		: [ 'added', 'removed', 'changed' ];
	const candidates = lists
		.flatMap( ( list ): object[] => phase[ list ] )
		.filter( ( record ) => sameSubject( record, item ) );

	if ( finding ) {
		const matches = candidates.filter( ( record ) =>
			matchesEvidence( finding, record )
		);
		if (
			matches.length > 0 &&
			matches.length === expectedRows( finding )
		) {
			return { ...none( false, matches ), precision: 'exact' };
		}
		return matches.length > 0
			? none( true, matches )
			: none( false, candidates );
	}

	// A link without its finding: only a unique option name is exact.
	if ( signal === 'options' && candidates.length === 1 ) {
		return { ...none( false, candidates ), precision: 'exact' };
	}
	return none( false, candidates );
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
 * Number of rows the finding's evidence refers to.
 *
 * @param finding Finding.
 */
function expectedRows( finding: ImpactFinding ): number {
	return finding.code === 'recurring_cron_event_removed'
		? finding.evidence.removed_recurring_count
		: 1;
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

/**
 * Whether a record of the finding's list (with the finding's subject) has the
 * values of the finding's evidence.
 *
 * @param finding Finding.
 * @param record  Diff record.
 */
function matchesEvidence( finding: ImpactFinding, record: object ): boolean {
	const row = record as Record< string, unknown >;
	switch ( finding.code ) {
		case 'large_autoloaded_option':
			return true;
		case 'recurring_cron_event_removed':
			return (
				row.is_recurring === true &&
				finding.before.removed_recurring.some(
					( instance ) =>
						instance.timestamp === row.timestamp &&
						instance.schedule === row.schedule &&
						instance.interval === row.interval
				)
			);
	}
	const sides = [ 'before', 'after' ] as const;
	return sides.every( ( side ) =>
		Object.entries( finding[ side ] ).every(
			( [ key, value ] ) => row[ `${ side }_${ key }` ] === value
		)
	);
}
