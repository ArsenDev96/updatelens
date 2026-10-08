import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

import type { OptionsDiffSummary } from '../types/api';
import {
	formatBytesDelta,
	formatBytesPair,
	formatCount,
	formatCountDelta,
} from '../utils/format';

/*
 * Signal summaries: the change counts first (what changed), then the site
 * totals before → after as quiet tertiary text.
 */

export interface CountItem {
	label: string;
	value: string;
	/** Less emphasis (e.g. normal rescheduling). */
	quiet?: boolean;
}

/**
 * Change counts of a signal in one line ("Added 2 · Removed 1 · Changed 3").
 *
 * @param props       Props.
 * @param props.items Counts in display order.
 */
export function ChangeCounts( { items }: { items: CountItem[] } ) {
	return (
		<dl className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
			{ items.map( ( item ) => (
				<div key={ item.label } className="flex items-baseline gap-1.5">
					<dt className="text-muted-foreground">{ item.label }</dt>
					<dd
						className={ cn(
							'tabular-nums',
							item.quiet
								? 'text-muted-foreground'
								: 'text-base font-semibold text-foreground'
						) }
					>
						{ item.value }
					</dd>
				</div>
			) ) }
		</dl>
	);
}

/**
 * Site totals of a signal, before → after with the delta: tertiary information.
 *
 * @param props          Props.
 * @param props.label    Accessible name of the list.
 * @param props.children `Total` items.
 */
export function Totals( {
	label,
	children,
}: {
	label: string;
	children: ReactNode;
} ) {
	return (
		<dl
			aria-label={ label }
			className="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted-foreground"
		>
			{ children }
		</dl>
	);
}

/**
 * Options: added, removed and changed counts (and the autoloaded data change
 * when there is one), then the option totals before → after.
 *
 * @param props         Props.
 * @param props.summary Options summary of the phase.
 */
export function OptionsSummary( { summary }: { summary: OptionsDiffSummary } ) {
	const total = formatBytesPair(
		summary.before_total_bytes,
		summary.after_total_bytes
	);
	const autoloaded = formatBytesPair(
		summary.before_autoloaded_bytes,
		summary.after_autoloaded_bytes
	);
	const counts: CountItem[] = [
		{
			label: __( 'Added', 'updatelens' ),
			value: formatCount( summary.added_count ),
		},
		{
			label: __( 'Removed', 'updatelens' ),
			value: formatCount( summary.removed_count ),
		},
		{
			label: __( 'Changed', 'updatelens' ),
			value: formatCount( summary.changed_count ),
		},
	];
	if ( summary.autoloaded_bytes_delta !== 0 ) {
		counts.push( {
			label: __( 'Autoloaded data', 'updatelens' ),
			value: formatBytesDelta( summary.autoloaded_bytes_delta ),
		} );
	}

	return (
		<div className="space-y-2">
			<ChangeCounts items={ counts } />
			<Totals label={ __( 'Option totals', 'updatelens' ) }>
				<Total
					label={ __( 'Options', 'updatelens' ) }
					before={ formatCount( summary.before_option_count ) }
					after={ formatCount( summary.after_option_count ) }
					delta={ formatCountDelta( summary.option_count_delta ) }
				/>
				<Total
					label={ __( 'Total option data', 'updatelens' ) }
					before={ total[ 0 ] }
					after={ total[ 1 ] }
					delta={ formatBytesDelta( summary.total_bytes_delta ) }
				/>
				<Total
					label={ __( 'Autoloaded options', 'updatelens' ) }
					before={ formatCount( summary.before_autoloaded_count ) }
					after={ formatCount( summary.after_autoloaded_count ) }
					delta={ formatCountDelta( summary.autoloaded_count_delta ) }
				/>
				<Total
					label={ __( 'Autoloaded data', 'updatelens' ) }
					before={ autoloaded[ 0 ] }
					after={ autoloaded[ 1 ] }
					delta={ formatBytesDelta( summary.autoloaded_bytes_delta ) }
				/>
			</Totals>
		</div>
	);
}

/**
 * One total before → after with its delta.
 *
 * @param props        Props.
 * @param props.label  Label.
 * @param props.before Formatted value before.
 * @param props.after  Formatted value after.
 * @param props.delta  Formatted delta.
 */
export function Total( {
	label,
	before,
	after,
	delta,
}: {
	label: string;
	before: string;
	after: string;
	delta: string;
} ) {
	return (
		<div className="flex flex-wrap items-baseline gap-x-1.5">
			<dt>{ label }</dt>
			<dd className="tabular-nums">
				{ before } <span aria-hidden="true">→</span>
				<span className="sr-only">
					{ __( 'to', 'updatelens' ) }
				</span>{ ' ' }
				{ after } <span>({ delta })</span>
			</dd>
		</div>
	);
}
