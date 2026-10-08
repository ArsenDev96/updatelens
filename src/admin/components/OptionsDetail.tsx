import { _n, __, sprintf } from '@wordpress/i18n';

import type { OptionsDiffSummary, PhaseKey, ReportPhase } from '../types/api';
import {
	formatBytesDelta,
	formatBytesPair,
	formatCount,
	formatCountDelta,
} from '../utils/format';
import {
	noChangesText,
	phaseLabel,
	signalUnavailableText,
} from '../utils/labels';
import { OptionDiffList } from './OptionDiffList';
import {
	SignalEmptyState,
	SignalSummary,
	SignalTotals,
	type SummaryCount,
} from './SignalParts';

/*
 * The Options & autoload page of one phase: a summary of the observed
 * option changes, the change lists, then the site's option totals as quiet
 * statistics. Without changes, one calm empty or unavailable state.
 */

/**
 * Options & autoload in one phase.
 *
 * @param props               Props.
 * @param props.phase         Phase.
 * @param props.data          Phase data of every signal.
 * @param props.count         Options change count, null if unavailable.
 * @param props.windowSeconds Observation window length from the API.
 */
export function OptionsDetail( {
	phase,
	data,
	count,
	windowSeconds,
}: {
	phase: PhaseKey;
	data: ReportPhase;
	count: number | null;
	windowSeconds: number;
} ) {
	const options = data.options;
	if ( count === null || count === 0 || ! options.available ) {
		const unavailable = count === null;
		const text = unavailable
			? signalUnavailableText( 'options', data, windowSeconds )
			: noChangesText( 'options' );
		return (
			<SignalEmptyState
				icon={ unavailable ? 'info' : 'options' }
				title={ text?.title ?? '' }
				description={ text?.description ?? '' }
			/>
		);
	}

	const summary = options.summary;
	const counts: SummaryCount[] = [
		{
			kind: 'added',
			label: __( 'Added', 'updatelens' ),
			value: formatCount( summary.added_count ),
			quiet: summary.added_count === 0,
		},
		{
			kind: 'removed',
			label: __( 'Removed', 'updatelens' ),
			value: formatCount( summary.removed_count ),
			quiet: summary.removed_count === 0,
		},
		{
			kind: 'changed',
			label: __( 'Changed', 'updatelens' ),
			value: formatCount( summary.changed_count ),
			quiet: summary.changed_count === 0,
		},
	];
	if ( summary.autoloaded_bytes_delta !== 0 ) {
		counts.push( {
			label: __( 'Autoloaded data', 'updatelens' ),
			value: formatBytesDelta( summary.autoloaded_bytes_delta ),
		} );
	}

	return (
		<div className="space-y-8">
			<SignalSummary
				id="updatelens-options-summary"
				eyebrow={ phaseLabel( phase ) }
				headline={ sprintf(
					/* translators: %s: number of observed option changes in a phase. */
					_n(
						'%s observed option change',
						'%s observed option changes',
						count,
						'updatelens'
					),
					formatCount( count )
				) }
				counts={ counts }
			/>
			<OptionDiffList phase={ options } />
			<SignalTotals
				id="updatelens-option-totals"
				label={ __( 'Option totals', 'updatelens' ) }
				items={ totals( summary ) }
			/>
		</div>
	);
}

/**
 * All options of the site before → after, with deltas.
 *
 * @param summary Options summary of the phase.
 */
function totals(
	summary: OptionsDiffSummary
): Array< [ string, string, string, string ] > {
	const total = formatBytesPair(
		summary.before_total_bytes,
		summary.after_total_bytes
	);
	const autoloaded = formatBytesPair(
		summary.before_autoloaded_bytes,
		summary.after_autoloaded_bytes
	);
	return [
		[
			__( 'Options', 'updatelens' ),
			formatCount( summary.before_option_count ),
			formatCount( summary.after_option_count ),
			formatCountDelta( summary.option_count_delta ),
		],
		[
			__( 'Total option data', 'updatelens' ),
			total[ 0 ],
			total[ 1 ],
			formatBytesDelta( summary.total_bytes_delta ),
		],
		[
			__( 'Autoloaded options', 'updatelens' ),
			formatCount( summary.before_autoloaded_count ),
			formatCount( summary.after_autoloaded_count ),
			formatCountDelta( summary.autoloaded_count_delta ),
		],
		[
			__( 'Autoloaded data', 'updatelens' ),
			autoloaded[ 0 ],
			autoloaded[ 1 ],
			formatBytesDelta( summary.autoloaded_bytes_delta ),
		],
	];
}
