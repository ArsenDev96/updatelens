import { __, sprintf } from '@wordpress/i18n';
import { createContext, Fragment, useContext, type ReactNode } from 'react';

import { cn } from '@/lib/utils';

import { useEmbedded } from '../utils/embedded';
import { locatedPrecision, useLocated, useMatched } from '../utils/highlight';
import { findingTitle, priorityLabel } from '../utils/impact';
import { Icon, type IconName } from './Icon';

/*
 * Building blocks of the redesigned signal pages (Options & autoload,
 * WP-Cron): one summary panel, change marks, list rows, quiet totals,
 * notes and the empty state. Meaning is always carried by text; marks and
 * colors only help scanning.
 */

/** Extra line of a signal's summary (whether review rules matched), if any. */
const SummaryExtra = createContext< ReactNode >( null );

export const SummaryExtraProvider = SummaryExtra.Provider;

export type ChangeKind =
	'added' | 'removed' | 'changed' | 'gone' | 'rescheduled';

const MARK: Record< ChangeKind, { icon: IconName; className: string } > = {
	added: {
		icon: 'added',
		className: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
	},
	removed: {
		icon: 'removed',
		className: 'bg-rose-50 text-rose-700 ring-rose-200',
	},
	changed: {
		icon: 'changed',
		className: 'bg-tint-strong text-tint-foreground ring-tint-border',
	},
	// Gone from the state, for reasons UpdateLens cannot tell: neutral.
	gone: {
		icon: 'removed',
		className: 'bg-slate-100 text-slate-500 ring-slate-200',
	},
	// Often a normal run: quiet.
	rescheduled: {
		icon: 'cron',
		className: 'bg-slate-50 text-slate-500 ring-slate-200',
	},
};

/**
 * Decorative mark of a change type (+, −, ⇄, clock).
 *
 * @param props           Props.
 * @param props.kind      Change type.
 * @param props.className Extra classes.
 */
export function ChangeMark( {
	kind,
	className,
}: {
	kind: ChangeKind;
	className?: string;
} ) {
	return (
		<span
			aria-hidden="true"
			className={ cn(
				'grid size-6 shrink-0 place-items-center rounded-md ring-1 ring-inset',
				MARK[ kind ].className,
				className
			) }
		>
			<Icon name={ MARK[ kind ].icon } className="size-3.5" />
		</span>
	);
}

/**
 * An option or hook name in monospace. Long names wrap after `_`, `-`, `.`
 * or `/` where possible (and anywhere if a part is still too long); the
 * text itself is unchanged.
 *
 * @param props           Props.
 * @param props.name      Identifier.
 * @param props.className Extra classes.
 */
export function Identifier( {
	name,
	className,
}: {
	name: string;
	className?: string;
} ) {
	return (
		<code
			className={ cn(
				'm-0 block min-w-0 select-text bg-transparent p-0 font-mono text-[13px] font-medium leading-6 text-slate-900 [overflow-wrap:anywhere]',
				className
			) }
		>
			{ ( name.match( /[^_\-./]*[_\-./]|[^_\-./]+$/g ) ?? [ name ] ).map(
				( part, index ) => (
					<Fragment key={ index }>
						{ index > 0 && <wbr /> }
						{ part }
					</Fragment>
				)
			) }
		</code>
	);
}

/**
 * One row of a change list: the mark, the name, its facts (right-aligned
 * from `sm` up, under the name on phones) and optional details below.
 *
 * @param props          Props.
 * @param props.kind     Change type.
 * @param props.name     Option or hook name.
 * @param props.facts    Main facts of the row.
 * @param props.meta     Secondary line directly under the name (e.g. an
 *                       Action Scheduler group).
 * @param props.quiet    Less emphasis (rescheduling).
 * @param props.record   Diff record of the row, for locating it from a
 *                       Potential Impact finding.
 * @param props.children Extra lines under the row.
 */
export function ChangeRow( {
	kind,
	name,
	facts,
	meta,
	quiet = false,
	record,
	children,
}: {
	kind: ChangeKind;
	name: string;
	facts: ReactNode;
	meta?: ReactNode;
	quiet?: boolean;
	record?: object;
	children?: ReactNode;
} ) {
	// `exact`: the finding's own entry; `group`: an entry of the same hook.
	const located = locatedPrecision( useLocated(), record );
	// A review rule that matched this row (Net result only).
	const matched = useMatched( record );

	return (
		<li
			data-updatelens-located={ located ?? undefined }
			className={ cn(
				'grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 px-4 py-3 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:gap-x-4',
				located && 'scroll-mt-24',
				located === 'exact' &&
					'bg-tint shadow-[inset_3px_0_0_hsl(var(--primary))]',
				located === 'group' &&
					'shadow-[inset_3px_0_0_theme(colors.slate.300)]'
			) }
		>
			<ChangeMark kind={ kind } />
			<Identifier
				name={ name }
				className={ cn( quiet && 'font-normal text-slate-700' ) }
			/>
			{ located && (
				<p
					className={ cn(
						'col-start-2 -mt-0.5 text-xs font-medium leading-5 sm:col-end-[-1]',
						located === 'exact'
							? 'text-tint-foreground'
							: 'text-slate-600'
					) }
				>
					{ located === 'exact'
						? __( 'From Findings', 'updatelens' )
						: __( 'Same hook as the finding', 'updatelens' ) }
				</p>
			) }
			{ matched && (
				<p className="col-start-2 -mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs leading-5 text-slate-700 sm:col-end-[-1]">
					<span
						className={ cn(
							'rounded-full px-1.5 font-semibold ring-1 ring-inset',
							matched.finding.priority === 'high'
								? 'bg-rose-50 text-rose-800 ring-rose-200'
								: 'bg-amber-50 text-amber-900 ring-amber-200'
						) }
					>
						{ priorityLabel( matched.finding.priority ) }
					</span>
					<span>
						{ matched.role === 'subject'
							? findingTitle( matched.finding )
							: sprintf(
									/* translators: %s: review rule, e.g. "Recurring WP-Cron event no longer observed". */
									__(
										'Counted as a replacement in: %s',
										'updatelens'
									),
									findingTitle( matched.finding )
								) }
					</span>
				</p>
			) }
			{ meta && (
				<div className="col-start-2 -mt-1 text-xs leading-5 text-muted-foreground [overflow-wrap:anywhere] sm:col-end-[-1]">
					{ meta }
				</div>
			) }
			<div className="col-start-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm leading-6 sm:col-start-3 sm:row-start-1 sm:justify-end sm:text-right">
				{ facts }
			</div>
			{ children }
		</li>
	);
}

/**
 * Label/value pairs under a row's name.
 *
 * @param props       Props.
 * @param props.rows  Label and content pairs.
 * @param props.boxed In a light gray box (details that changed); plain
 *                    otherwise.
 */
export function RowDetails( {
	rows,
	boxed = false,
}: {
	rows: Array< [ string, ReactNode ] >;
	boxed?: boolean;
} ) {
	return (
		<dl
			className={ cn(
				'col-start-2 grid gap-y-1 text-[13px] leading-5 sm:col-end-[-1] sm:grid-cols-[auto_minmax(0,1fr)] sm:gap-x-6',
				// Boxed details stack on phones; plain ones keep label and
				// value side by side.
				boxed
					? 'mt-1.5 rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-inset ring-slate-200/70'
					: 'grid-cols-[auto_minmax(0,1fr)] gap-x-4'
			) }
		>
			{ rows.map( ( [ label, content ] ) => (
				<div key={ label } className="contents">
					<dt className="text-muted-foreground">{ label }</dt>
					<dd className="min-w-0 break-words text-slate-700">
						{ content }
					</dd>
				</div>
			) ) }
		</dl>
	);
}

/**
 * A small neutral pill for a state (autoload, recurrence).
 *
 * @param props           Props.
 * @param props.children  Content.
 * @param props.muted     Lighter text.
 * @param props.className Extra classes.
 */
export function StatePill( {
	children,
	muted = false,
	className,
}: {
	children: ReactNode;
	muted?: boolean;
	className?: string;
} ) {
	return (
		<span
			className={ cn(
				'inline-flex items-baseline gap-1 rounded-full bg-slate-100 px-2 text-xs font-medium leading-5',
				muted ? 'text-slate-600' : 'text-slate-700',
				className
			) }
		>
			{ children }
		</span>
	);
}

export interface SummaryCount {
	/** Mark of the count; none for a delta such as autoloaded data. */
	kind?: ChangeKind;
	label: string;
	value: string;
	/** Zero, or less important (rescheduling): no emphasis. */
	quiet?: boolean;
}

/**
 * A signal's result in one phase: the phase name, the total of observed
 * changes, the counts per type and an optional note.
 *
 * @param props          Props.
 * @param props.id       ID of the headline (the section's name).
 * @param props.eyebrow  Phase name.
 * @param props.headline E.g. "6 observed option changes".
 * @param props.counts   Counts in display order.
 * @param props.note     Observational note at the bottom.
 */
export function SignalSummary( {
	id,
	eyebrow,
	headline,
	counts,
	note,
}: {
	id: string;
	eyebrow: string;
	headline: string;
	counts: SummaryCount[];
	note?: string;
} ) {
	const extra = useContext( SummaryExtra );
	const embedded = useEmbedded();
	if ( embedded ) {
		// The row above names the signal and its counts.
		return extra || note ? (
			<div className="space-y-2">
				{ extra }
				{ note && <SignalNote>{ note }</SignalNote> }
			</div>
		) : null;
	}
	return (
		<section
			aria-labelledby={ id }
			className="rounded-lg border bg-card px-4 py-3 shadow-surface sm:px-5"
		>
			<p className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
				<span className="text-[13px] text-muted-foreground">
					{ eyebrow }
				</span>
				<span
					id={ id }
					className="text-base font-semibold leading-6 text-slate-900"
				>
					{ headline }
				</span>
			</p>
			<dl className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-[13px] leading-5">
				{ counts.map( ( count ) => (
					<div
						key={ count.label }
						className="flex items-center gap-1.5"
					>
						{ count.kind && (
							<ChangeMark
								kind={ count.kind }
								className={ cn(
									'size-5',
									count.quiet && 'opacity-50'
								) }
							/>
						) }
						<dt className="text-slate-600">{ count.label }</dt>
						<dd
							className={ cn(
								'tabular-nums',
								count.quiet
									? 'text-muted-foreground'
									: 'font-semibold text-slate-900'
							) }
						>
							{ count.value }
						</dd>
					</div>
				) ) }
			</dl>
			{ extra && <div className="mt-2">{ extra }</div> }
			{ note && (
				<SignalNote className="mt-2 border-t pt-2">{ note }</SignalNote>
			) }
		</section>
	);
}

/**
 * Site-wide totals before → after: quiet statistics after the changes. The
 * region is named per source ("Option totals", …), not by its "Site totals"
 * heading: Configuration changes can show all three sources at once, and
 * landmarks need distinct names.
 *
 * @param props           Props.
 * @param props.id        Heading ID.
 * @param props.label     Accessible name of the region, per source.
 * @param props.items     Label, before, after and delta per total.
 */
export function SignalTotals( {
	id,
	label,
	items,
}: {
	id: string;
	label: string;
	items: Array< [ string, string, string, string ] >;
} ) {
	const Heading = useEmbedded() ? 'h5' : 'h3';
	return (
		<section
			aria-label={ label }
			className="flex flex-wrap items-baseline gap-x-4 gap-y-1 border-t pt-3 text-[13px] leading-5 text-muted-foreground"
		>
			<Heading id={ id } className="font-medium text-slate-600">
				{ __( 'Site totals', 'updatelens' ) }
			</Heading>
			<dl className="flex flex-wrap gap-x-4 gap-y-1">
				{ items.map( ( [ name, before, after, delta ] ) => (
					<div key={ name } className="flex gap-1.5">
						<dt>{ name }</dt>
						<dd className="tabular-nums">
							<span className="text-slate-700">
								{ before } <span aria-hidden="true">→</span>
								<span className="sr-only">
									{ __( 'to', 'updatelens' ) }
								</span>{ ' ' }
								{ after }
							</span>{ ' ' }
							({ delta })
						</dd>
					</div>
				) ) }
			</dl>
		</section>
	);
}

/**
 * A short note with an info icon.
 *
 * @param props           Props.
 * @param props.children  Text.
 * @param props.className Spacing.
 */
export function SignalNote( {
	children,
	className,
}: {
	children: ReactNode;
	className?: string;
} ) {
	return (
		<p
			className={ cn(
				'flex gap-2 text-[13px] leading-5 text-slate-600',
				className
			) }
		>
			<Icon name="info" className="mt-0.5 size-4 text-slate-500" />
			<span>{ children }</span>
		</p>
	);
}

/**
 * A signal without changes or without data in a phase: one calm card.
 *
 * @param props             Props.
 * @param props.icon        Icon.
 * @param props.title       Title.
 * @param props.description Explanation.
 */
export function SignalEmptyState( {
	icon,
	title,
	description,
}: {
	icon: IconName;
	title: string;
	description: string;
} ) {
	return (
		<div className="flex gap-4 rounded-xl border bg-card px-5 py-6 shadow-surface sm:px-6">
			<span className="grid size-10 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-500">
				<Icon name={ icon } />
			</span>
			<div className="min-w-0 space-y-1 pt-0.5">
				<p className="text-base font-semibold text-slate-900">
					{ title }
				</p>
				<p className="max-w-2xl text-sm leading-relaxed text-muted-foreground">
					{ description }
				</p>
			</div>
		</div>
	);
}
