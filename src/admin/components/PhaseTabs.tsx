import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import type { PhaseKey, Provider } from '../types/api';
import type { PhaseChangeCounts } from '../utils/changes';
import {
	changeIndicator,
	PHASE_KEYS,
	phaseLabel,
	PROVIDERS,
	providerLabel,
} from '../utils/labels';
import { Tabs } from './Tabs';

/**
 * Tabs for the three phases, each with its change count across the
 * available signals. A phase is "not available" only if none of its
 * signals is.
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

/**
 * Second-level tabs for the signals of one phase (Options, WP-Cron), each
 * with its own change count or availability.
 *
 * @param props          Props.
 * @param props.counts   Change counts of the phase.
 * @param props.selected Selected signal.
 * @param props.onSelect Selection handler.
 * @param props.children Panel content.
 */
export function ProviderTabs( {
	counts,
	selected,
	onSelect,
	children,
}: {
	counts: PhaseChangeCounts;
	selected: Provider;
	onSelect: ( provider: Provider ) => void;
	children: ReactNode;
} ) {
	return (
		<Tabs
			items={ PROVIDERS.map( ( provider ) => {
				const label = providerLabel( provider );
				const { text, tone, accessibleName } = changeIndicator(
					label,
					counts[ provider ]
				);
				return {
					key: provider,
					label,
					indicator: { text, tone },
					accessibleName,
				};
			} ) }
			selected={ selected }
			onSelect={ onSelect }
			label={ __( 'Observed signals', 'updatelens' ) }
			idPrefix="updatelens-provider-tab"
			panelId="updatelens-provider-panel"
			level="secondary"
		>
			{ children }
		</Tabs>
	);
}
