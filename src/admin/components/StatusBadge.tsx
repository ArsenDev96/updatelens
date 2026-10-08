import { cn } from '@/lib/utils';

import type { AnalysisStatus, SettleOutcome } from '../types/api';
import { statusLabel, statusTone } from '../utils/labels';
import { TONE_BADGE, TONE_DOT } from '../utils/tone';

/**
 * The analysis status as a small badge.
 *
 * @param props        Props.
 * @param props.status        Analysis status.
 * @param props.settleOutcome Settle outcome (an expired observation is a partial report).
 * @param props.dot           Show a colored dot before the label (report header).
 */
export function StatusBadge( {
	status,
	settleOutcome,
	dot = false,
}: {
	status: AnalysisStatus;
	settleOutcome: SettleOutcome | null;
	dot?: boolean;
} ) {
	const tone = statusTone( status, settleOutcome );

	return (
		<span
			className={ cn(
				'inline-flex shrink-0 items-center rounded-full border px-2 py-0.5 text-xs font-medium',
				dot && 'gap-1.5 pl-1.5',
				TONE_BADGE[ tone ]
			) }
		>
			{ dot && (
				<span
					aria-hidden="true"
					className={ cn(
						'size-1.5 rounded-full',
						TONE_DOT[ tone ]
					) }
				/>
			) }
			{ statusLabel( status, settleOutcome ) }
		</span>
	);
}
