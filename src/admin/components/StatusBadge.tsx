import { cn } from '@/lib/utils';

import type { AnalysisStatus } from '../types/api';
import { statusLabel, statusTone } from '../utils/labels';
import { TONE_BADGE } from '../utils/tone';

export function StatusBadge( { status }: { status: AnalysisStatus } ) {
	return (
		<span
			className={ cn(
				'inline-flex shrink-0 items-center rounded-full border px-2 py-0.5 text-xs font-medium',
				TONE_BADGE[ statusTone( status ) ]
			) }
		>
			{ statusLabel( status ) }
		</span>
	);
}
