import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import type { PhaseKey, Provider, ReportPhase } from '../types/api';
import {
	isPhaseAvailable,
	PHASE_KEYS,
	phaseLabel,
	PROVIDERS,
	providerLabel,
} from '../utils/labels';
import { Tabs } from './Tabs';

/**
 * Tabs for the three phases. A phase is "not available" only if none of its
 * signals is.
 *
 * @param props          Props.
 * @param props.phases   Report phases.
 * @param props.selected Selected phase.
 * @param props.onSelect Selection handler.
 * @param props.children Panel content.
 */
export function PhaseTabs( {
	phases,
	selected,
	onSelect,
	children,
}: {
	phases: Record< PhaseKey, ReportPhase >;
	selected: PhaseKey;
	onSelect: ( phase: PhaseKey ) => void;
	children: ReactNode;
} ) {
	return (
		<Tabs
			items={ PHASE_KEYS.map( ( phase ) => ( {
				key: phase,
				label: phaseLabel( phase ),
				unavailable: ! isPhaseAvailable( phases[ phase ] ),
			} ) ) }
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
 * with its own availability.
 *
 * @param props          Props.
 * @param props.phase    Report phase.
 * @param props.selected Selected signal.
 * @param props.onSelect Selection handler.
 * @param props.children Panel content.
 */
export function ProviderTabs( {
	phase,
	selected,
	onSelect,
	children,
}: {
	phase: ReportPhase;
	selected: Provider;
	onSelect: ( provider: Provider ) => void;
	children: ReactNode;
} ) {
	return (
		<Tabs
			items={ PROVIDERS.map( ( provider ) => ( {
				key: provider,
				label: providerLabel( provider ),
				unavailable: ! phase[ provider ].available,
			} ) ) }
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
