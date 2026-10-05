import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import type { DiffSummary } from '../types/api';
import {
	formatBytes,
	formatBytesDelta,
	formatCount,
	formatCountDelta,
} from '../utils/format';

/**
 * Primary counts (added/removed/changed, autoloaded size change), then the
 * before → after totals with less emphasis.
 *
 * @param props         Props.
 * @param props.summary Phase summary.
 */
export function PhaseSummary( { summary }: { summary: DiffSummary } ) {
	return (
		<div className="space-y-3">
			<dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
				<Metric
					label={ __( 'Added', 'updatelens' ) }
					value={ formatCount( summary.added_count ) }
				/>
				<Metric
					label={ __( 'Removed', 'updatelens' ) }
					value={ formatCount( summary.removed_count ) }
				/>
				<Metric
					label={ __( 'Changed', 'updatelens' ) }
					value={ formatCount( summary.changed_count ) }
				/>
				<Metric
					label={ __( 'Autoloaded data', 'updatelens' ) }
					value={ formatBytesDelta( summary.autoloaded_bytes_delta ) }
				/>
			</dl>
			<dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 xl:grid-cols-4">
				<Total
					label={ __( 'Options', 'updatelens' ) }
					before={ formatCount( summary.before_option_count ) }
					after={ formatCount( summary.after_option_count ) }
					delta={ formatCountDelta( summary.option_count_delta ) }
				/>
				<Total
					label={ __( 'Total option data', 'updatelens' ) }
					before={ formatBytes( summary.before_total_bytes ) }
					after={ formatBytes( summary.after_total_bytes ) }
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
					before={ formatBytes( summary.before_autoloaded_bytes ) }
					after={ formatBytes( summary.after_autoloaded_bytes ) }
					delta={ formatBytesDelta( summary.autoloaded_bytes_delta ) }
				/>
			</dl>
		</div>
	);
}

function Metric( { label, value }: { label: string; value: ReactNode } ) {
	return (
		<div className="rounded-lg border bg-card px-4 py-3">
			<dt className="text-xs font-medium text-muted-foreground">
				{ label }
			</dt>
			<dd className="mt-1 text-xl font-semibold tabular-nums">
				{ value }
			</dd>
		</div>
	);
}

function Total( {
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
		<div>
			<dt className="text-xs text-muted-foreground">{ label }</dt>
			<dd className="tabular-nums">
				{ before } <span aria-hidden="true">→</span>
				<span className="sr-only">
					{ __( 'to', 'updatelens' ) }
				</span>{ ' ' }
				{ after }{ ' ' }
				<span className="text-xs text-muted-foreground">
					({ delta })
				</span>
			</dd>
		</div>
	);
}
