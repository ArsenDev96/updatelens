import { __ } from '@wordpress/i18n';
import { useRef, type KeyboardEvent, type ReactNode } from 'react';

import { cn } from '@/lib/utils';

import type { AnalysisPhase, PhaseKey } from '../types/api';
import { PHASE_KEYS, phaseLabel } from '../utils/labels';

interface PhaseTabsProps {
	phases: Record< PhaseKey, AnalysisPhase >;
	selected: PhaseKey;
	onSelect: ( phase: PhaseKey ) => void;
	children: ReactNode;
}

const tabId = ( phase: PhaseKey ) => `updatelens-tab-${ phase }`;
const PANEL_ID = 'updatelens-phase-panel';

/**
 * Tabs for the three phases (WAI-ARIA tabs pattern, automatic activation).
 * Unavailable phases stay selectable so their reason can be read.
 *
 * @param props Props.
 */
export function PhaseTabs( {
	phases,
	selected,
	onSelect,
	children,
}: PhaseTabsProps ) {
	const tabs = useRef< Partial< Record< PhaseKey, HTMLButtonElement > > >(
		{}
	);

	const onKeyDown = ( event: KeyboardEvent< HTMLDivElement > ) => {
		// Move from the focused tab (normally the selected one).
		const focused = PHASE_KEYS.find(
			( phase ) => tabs.current[ phase ] === event.target
		);
		const index = PHASE_KEYS.indexOf( focused ?? selected );
		const last = PHASE_KEYS.length - 1;
		const next: Record< string, number > = {
			ArrowRight: index === last ? 0 : index + 1,
			ArrowLeft: index === 0 ? last : index - 1,
			Home: 0,
			End: last,
		};
		if ( ! ( event.key in next ) ) {
			return;
		}
		event.preventDefault();
		const phase = PHASE_KEYS[ next[ event.key ] ];
		onSelect( phase );
		tabs.current[ phase ]?.focus();
	};

	return (
		<div className="space-y-4">
			<div
				role="tablist"
				aria-label={ __( 'Observation phases', 'updatelens' ) }
				onKeyDown={ onKeyDown }
				className="inline-flex max-w-full flex-wrap gap-1 rounded-lg border bg-muted/60 p-1"
			>
				{ PHASE_KEYS.map( ( phase ) => {
					const isSelected = phase === selected;
					const available = phases[ phase ].available;
					return (
						<button
							key={ phase }
							ref={ ( element ) => {
								tabs.current[ phase ] = element ?? undefined;
							} }
							type="button"
							role="tab"
							id={ tabId( phase ) }
							aria-selected={ isSelected }
							aria-controls={ PANEL_ID }
							tabIndex={ isSelected ? 0 : -1 }
							onClick={ () => onSelect( phase ) }
							className={ cn(
								'rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
								isSelected
									? 'bg-background text-foreground shadow-sm'
									: 'text-muted-foreground hover:text-foreground'
							) }
						>
							{ phaseLabel( phase ) }
							{ ! available && (
								<span className="ml-1.5 text-xs font-normal text-muted-foreground">
									{ __( '(not available)', 'updatelens' ) }
								</span>
							) }
						</button>
					);
				} ) }
			</div>
			<div
				role="tabpanel"
				id={ PANEL_ID }
				aria-labelledby={ tabId( selected ) }
				tabIndex={ 0 }
				className="rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-ring"
			>
				{ children }
			</div>
		</div>
	);
}
