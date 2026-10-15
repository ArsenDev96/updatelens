import { useRef, type KeyboardEvent, type ReactNode } from 'react';

import { cn } from '@/lib/utils';

import { Icon, type IconName } from './Icon';

export interface TabItem< K extends string > {
	key: K;
	label: string;
	/** Decorative icon before the label (primary tabs). */
	icon?: IconName;
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
	/**
	 * `segmented` (default): a quiet segmented control, secondary to the view.
	 * `primary`: the report's one tab bar, underlined, with icons.
	 */
	variant?: 'segmented' | 'primary';
	/** Classes of the panel. */
	panelClassName?: string;
	/** Heading beside the tab list (segmented; from `lg` up). */
	header?: ReactNode;
	children: ReactNode;
}

/*
 * Segmented: the selected tab is a white segment with dark text on a light
 * track; labels and counts stack on phones. Primary: an underlined tab bar
 * with an icon per tab; the selected tab is blue with a blue rule. On phones
 * the three tabs share the width, icons above their labels.
 */
const TAB = {
	segmented: {
		list: 'grid grid-cols-3 gap-1 rounded-lg bg-slate-100 p-1 ring-1 ring-inset ring-slate-200 sm:inline-grid sm:auto-cols-fr sm:grid-flow-col sm:grid-cols-none',
		tab: 'flex min-h-10 flex-col items-center justify-center gap-1 rounded-md px-2 py-1.5 text-center text-sm font-medium leading-tight transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 sm:flex-row sm:gap-2 sm:px-4',
		selected: 'bg-card text-slate-900 shadow-sm ring-1 ring-slate-200',
		idle: 'text-slate-600 hover:bg-white/60 hover:text-foreground',
	},
	primary: {
		list: 'grid grid-cols-3 border-b px-1 sm:flex sm:gap-1 sm:px-3',
		tab: '-mb-px flex min-h-12 flex-col items-center justify-center gap-1 rounded-t-md border-b-2 px-1.5 py-2 text-center text-[13px] font-medium leading-tight transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring sm:flex-row sm:gap-2 sm:px-4 sm:py-3 sm:text-sm',
		selected: 'border-primary text-primary',
		idle: 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900',
	},
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
		// On the selected (white) tab: the accent pill when there are changes.
		isSelected &&
			( tone === 'changes'
				? 'bg-tint-strong font-semibold text-tint-foreground'
				: 'font-normal text-slate-600' ),
		! isSelected &&
			( tone === 'changes'
				? 'bg-tint-strong font-semibold text-tint-foreground'
				: 'font-normal text-muted-foreground' )
	);
}

/**
 * Tabs (WAI-ARIA tabs pattern, automatic activation): arrow keys, Home and
 * End move between tabs; only the selected tab is in the tab order. Only the
 * selected tab's panel is rendered.
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
	variant = 'segmented',
	panelClassName,
	header,
	children,
}: TabsProps< K > ) {
	const tabs = useRef< Partial< Record< K, HTMLButtonElement > > >( {} );
	const keys = items.map( ( item ) => item.key );
	const tabId = ( key: K ) => `${ idPrefix }-${ key }`;
	const style = TAB[ variant ];

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

	const tablist = (
		<div
			role="tablist"
			aria-label={ label }
			onKeyDown={ onKeyDown }
			className={ cn( style.list, header && 'lg:shrink-0' ) }
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
							style.tab,
							isSelected ? style.selected : style.idle
						) }
					>
						{ item.icon && (
							<Icon
								name={ item.icon }
								className={ cn(
									'size-[1.125rem]',
									isSelected
										? 'text-primary'
										: 'text-slate-500'
								) }
							/>
						) }
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
	);

	return (
		<div className={ variant === 'segmented' ? 'space-y-3' : undefined }>
			{ header ? (
				<div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between lg:gap-6">
					{ header }
					{ tablist }
				</div>
			) : (
				tablist
			) }
			<div
				role="tabpanel"
				id={ panelId }
				aria-labelledby={ tabId( selected ) }
				tabIndex={ 0 }
				className={ cn(
					'outline-none focus-visible:ring-2 focus-visible:ring-ring',
					variant === 'segmented' ? 'rounded-lg' : 'rounded-b-xl',
					panelClassName
				) }
			>
				{ children }
			</div>
		</div>
	);
}
