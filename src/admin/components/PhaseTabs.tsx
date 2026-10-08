import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import type { PhaseKey } from '../types/api';
import type { PhaseChangeCounts } from '../utils/changes';
import { changeIndicator, PHASE_KEYS, phaseLabel } from '../utils/labels';
import { Tabs } from './Tabs';

/**
 * Tabs for the three phases, each with its change count across the
 * available signals ("2 changes"). A phase is "not available" only if none
 * of its signals is. The only tabs of a report: signals are stacked
 * sections inside the panel.
 *
 * @param props          Props.
 * @param props.counts   Change counts per phase.
 * @param props.selected Selected phase.
 * @param props.onSelect Selection handler.
 * @param props.children Panel content.
 */
export function PhaseTabs( {
	counts,
	selected,
	onSelect,
	children,
}: {
	counts: Record< PhaseKey, PhaseChangeCounts >;
	selected: PhaseKey;
	onSelect: ( phase: PhaseKey ) => void;
	children: ReactNode;
} ) {
	return (
		<Tabs
			items={ PHASE_KEYS.map( ( phase ) => {
				const label = phaseLabel( phase );
				const { text, tone, accessibleName } = changeIndicator(
					label,
					counts[ phase ].total
				);
				return {
					key: phase,
					label,
					indicator: { text, tone },
					accessibleName,
				};
			} ) }
			selected={ selected }
			onSelect={ onSelect }
			label={ __( 'Observation phases', 'updatelens' ) }
			idPrefix="updatelens-tab"
			panelId="updatelens-phase-panel"
		>
			{ children }
		</Tabs>
	);
}
