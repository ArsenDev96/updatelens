import { useRef, type KeyboardEvent, type ReactNode } from 'react';

import { cn } from '@/lib/utils';

export interface TabItem< K extends string > {
	key: K;
	label: string;
	/**
	 * Compact status after the label: a change count, or "Not available"
	 * (the tab stays selectable so the reason can be read).
	 */
	indicator?: {
		text: string;
		tone: 'changes' | 'none' | 'unavailable';
	};
}

interface TabsProps< K extends string > {
	items: TabItem< K >[];
	selected: K;
	onSelect: ( key: K ) => void;
	/** Accessible name of the tab list. */
	label: string;
	/** Tab element IDs are `${ idPrefix }-${ key }`. */
	idPrefix: string;
	panelId: string;
	children: ReactNode;
}

/*
 * A segmented control: the selected tab is filled in the accent color;
 * labels and counts stack on phones.
 */
const TAB = {
	list: 'grid grid-cols-3 gap-1 rounded-xl border bg-card p-1 shadow-surface sm:inline-grid sm:auto-cols-fr sm:grid-flow-col sm:grid-cols-none',
	tab: 'flex min-h-11 flex-col items-center justify-center gap-1 rounded-lg px-2 py-2 text-center text-sm font-medium leading-tight transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 sm:flex-row sm:gap-2 sm:px-4',
	selected: 'bg-primary text-primary-foreground shadow-sm',
	idle: 'text-slate-600 hover:bg-slate-100 hover:text-foreground',
};

/**
 * Classes of a tab's indicator.
 *
 * @param tone       Indicator tone.
 * @param isSelected Whether the tab is selected.
 */
function indicatorClassName(
	tone: 'changes' | 'none' | 'unavailable',
	isSelected: boolean
): string {
	return cn(
		'inline-block whitespace-nowrap rounded-full px-2 text-xs leading-5 tabular-nums',
		isSelected && 'bg-white/20 font-semibold',
		! isSelected &&
			( tone === 'changes'
				? 'bg-tint-strong font-semibold text-tint-foreground'
				: 'font-normal text-muted-foreground' )
	);
}

/**
 * Tabs (WAI-ARIA tabs pattern, automatic activation): arrow keys, Home and
 * End move between tabs; only the selected tab is in the tab order.
 *
 * @param props Props.
 */
export function Tabs< K extends string >( {
	items,
	selected,
	onSelect,
	label,
	idPrefix,
	panelId,
	children,
}: TabsProps< K > ) {
	const tabs = useRef< Partial< Record< K, HTMLButtonElement > > >( {} );
	const keys = items.map( ( item ) => item.key );
	const tabId = ( key: K ) => `${ idPrefix }-${ key }`;

	const onKeyDown = ( event: KeyboardEvent< HTMLDivElement > ) => {
		// Move from the focused tab (normally the selected one).
		const focused = keys.find(
			( key ) => tabs.current[ key ] === event.target
		);
		if ( focused === undefined ) {
			return; // A key press inside the panel.
		}
		const index = keys.indexOf( focused );
		const last = keys.length - 1;
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
		const key = keys[ next[ event.key ] ];
		onSelect( key );
		tabs.current[ key ]?.focus();
	};

	return (
		<div className="space-y-6">
			<div
				role="tablist"
				aria-label={ label }
				onKeyDown={ onKeyDown }
				className={ TAB.list }
			>
				{ items.map( ( item ) => {
					const isSelected = item.key === selected;
					return (
						<button
							key={ item.key }
							ref={ ( element ) => {
								tabs.current[ item.key ] = element ?? undefined;
							} }
							type="button"
							role="tab"
							id={ tabId( item.key ) }
							aria-selected={ isSelected }
							aria-controls={ panelId }
							tabIndex={ isSelected ? 0 : -1 }
							onClick={ () => onSelect( item.key ) }
							className={ cn(
								TAB.tab,
								isSelected ? TAB.selected : TAB.idle
							) }
						>
							{ item.label }
							{ item.indicator && (
								<>
									{ /* The accessible name is the visible text: "Net result, 4 changes". */ }
									<span className="sr-only">,</span>{ ' ' }
									<span
										className={ indicatorClassName(
											item.indicator.tone,
											isSelected
										) }
									>
										{ item.indicator.text }
									</span>
								</>
							) }
						</button>
					);
				} ) }
			</div>
			<div
				role="tabpanel"
				id={ panelId }
				aria-labelledby={ tabId( selected ) }
				tabIndex={ 0 }
				className="rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-ring"
			>
				{ children }
			</div>
		</div>
	);
}
