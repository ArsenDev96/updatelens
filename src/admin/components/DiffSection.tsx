import { useId, useState, type ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import { useLocated } from '../utils/highlight';

/** Rows of a diff list shown before "Show more". */
export const DEFAULT_VISIBLE_DIFF_ROWS = 10;

/**
 * One titled list of a diff (e.g. "Changed (12)"). Long lists show their
 * first DEFAULT_VISIBLE_DIFF_ROWS rows and a button for the rest; hidden
 * rows are not rendered. The total stays in the title. A list whose hidden
 * rows include a located row (from a Potential Impact link) starts expanded.
 *
 * @param props            Props.
 * @param props.id         Section ID (unique within the panel).
 * @param props.title      Heading, including the total.
 * @param props.items      Records of the list.
 * @param props.renderItem Row for one record (an `li`).
 * @param props.moreLabel  Button text for the hidden rows, e.g. "Show 37 more changed options".
 * @param props.lessLabel  Button text to collapse, e.g. "Show fewer changed options".
 * @param props.quiet      Less emphasis (rescheduling).
 * @param props.appearance `card`: the signal pages (a raised list with the
 *                         button as its footer); `default`: a plain list
 *                         (the first-run plugin list).
 * @param props.icon       Decorative mark before the title (`card` only).
 */
export function DiffSection< T >( {
	id,
	title,
	items,
	renderItem,
	moreLabel,
	lessLabel,
	quiet = false,
	appearance = 'default',
	icon,
}: {
	id: string;
	title: string;
	items: T[];
	renderItem: ( item: T, index: number ) => ReactNode;
	moreLabel: ( hidden: number ) => string;
	lessLabel: string;
	quiet?: boolean;
	appearance?: 'default' | 'card';
	icon?: ReactNode;
} ) {
	const located = useLocated();
	const [ expanded, setExpanded ] = useState(
		() =>
			located !== null &&
			items.findIndex(
				( item ) =>
					typeof item === 'object' &&
					item !== null &&
					located.rows.has( item )
			) >= DEFAULT_VISIBLE_DIFF_ROWS
	);
	const listId = useId();
	const headingId = `${ id }-heading`;
	const hidden = items.length - DEFAULT_VISIBLE_DIFF_ROWS;
	const visible =
		expanded || hidden <= 0
			? items
			: items.slice( 0, DEFAULT_VISIBLE_DIFF_ROWS );
	const toggle = hidden > 0 && (
		<Button
			type="button"
			variant={ appearance === 'card' ? 'ghost' : 'outline' }
			size="sm"
			aria-expanded={ expanded }
			aria-controls={ listId }
			onClick={ () => setExpanded( ! expanded ) }
			className={
				appearance === 'card'
					? 'h-11 w-full rounded-none border-t text-sm text-primary hover:bg-tint hover:text-primary focus-visible:ring-inset focus-visible:ring-offset-0'
					: undefined
			}
		>
			{ expanded ? lessLabel : moreLabel( hidden ) }
		</Button>
	);

	if ( appearance === 'card' ) {
		return (
			<section aria-labelledby={ headingId } className="space-y-3">
				<div className="flex items-center gap-2.5">
					{ icon }
					<h3
						id={ headingId }
						className={ cn(
							'text-base',
							quiet
								? 'font-medium text-slate-600'
								: 'font-semibold text-slate-900'
						) }
					>
						{ title }
					</h3>
				</div>
				<div className="overflow-hidden rounded-xl border bg-card shadow-surface">
					<ul id={ listId } className="divide-y">
						{ visible.map( renderItem ) }
					</ul>
					{ toggle }
				</div>
			</section>
		);
	}

	return (
		<section aria-labelledby={ headingId } className="space-y-2">
			<h3
				id={ headingId }
				className={ cn(
					'text-sm',
					quiet
						? 'font-medium text-muted-foreground'
						: 'font-semibold'
				) }
			>
				{ title }
			</h3>
			<ul
				id={ listId }
				className="divide-y overflow-hidden rounded-lg border bg-card"
			>
				{ visible.map( renderItem ) }
			</ul>
			{ toggle }
		</section>
	);
}
