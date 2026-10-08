import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import type { PhaseKey } from '../types/api';
import { changeIndicator, PHASE_KEYS, phaseLabel } from '../utils/labels';
import { Tabs } from './Tabs';

/**
 * Tabs for the three phases, each with its change count ("2 changes"): on
 * the overview across the available signals (a phase is "not available"
 * only if none of its signals is), on a signal's details that signal's. The
 * only tabs of a report: signals are cards and detail views, never tabs.
 *
 * @param props          Props.
 * @param props.counts   Change count per phase, null if unavailable.
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
	counts: Record< PhaseKey, number | null >;
	selected: PhaseKey;
	onSelect: ( phase: PhaseKey ) => void;
	children: ReactNode;
} ) {
	return (
		<Tabs
			items={ PHASE_KEYS.map( ( phase ) => {
				return {
					key: phase,
					label: phaseLabel( phase ),
					indicator: changeIndicator( counts[ phase ] ),
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
