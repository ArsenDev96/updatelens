import { __ } from '@wordpress/i18n';
import { useRef, type KeyboardEvent, type ReactNode } from 'react';

import { cn } from '@/lib/utils';

export interface TabItem< K extends string > {
	key: K;
	label: string;
	/** Shown as "(not available)"; the tab stays selectable so the reason can be read. */
	unavailable: boolean;
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
	/** Secondary tabs are smaller, for a second level inside a panel. */
	level?: 'primary' | 'secondary';
	children: ReactNode;
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
	level = 'primary',
	children,
}: TabsProps< K > ) {
	const tabs = useRef< Partial< Record< K, HTMLButtonElement > > >( {} );
	const keys = items.map( ( item ) => item.key );
	const tabId = ( key: K ) => `${ idPrefix }-${ key }`;
	const secondary = level === 'secondary';

	const onKeyDown = ( event: KeyboardEvent< HTMLDivElement > ) => {
		// Move from the focused tab (normally the selected one).
		const focused = keys.find(
			( key ) => tabs.current[ key ] === event.target
		);
		if ( focused === undefined ) {
			return; // A key press inside a nested tab list.
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
		<div className={ secondary ? 'space-y-3' : 'space-y-4' }>
			<div
				role="tablist"
				aria-label={ label }
				onKeyDown={ onKeyDown }
				className={ cn(
					'inline-flex max-w-full flex-wrap gap-1 rounded-lg border',
					secondary ? 'bg-background p-0.5' : 'bg-muted/60 p-1'
				) }
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
								'rounded-md font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
								secondary
									? 'px-2.5 py-1 text-xs'
									: 'px-3 py-1.5 text-sm',
								isSelected &&
									secondary &&
									'bg-muted text-foreground',
								isSelected &&
									! secondary &&
									'bg-background text-foreground shadow-sm',
								! isSelected &&
									'text-muted-foreground hover:text-foreground'
							) }
						>
							{ item.label }
							{ item.unavailable && (
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
