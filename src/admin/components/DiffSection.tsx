import { useId, useState, type ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/** Rows of a diff list shown before "Show more". */
export const DEFAULT_VISIBLE_DIFF_ROWS = 10;

/**
 * One titled list of a diff (e.g. "Changed (12)"). Long lists show their
 * first DEFAULT_VISIBLE_DIFF_ROWS rows and a button for the rest; hidden
 * rows are not rendered. The total stays in the title.
 *
 * @param props            Props.
 * @param props.id         Section ID (unique within the panel).
 * @param props.title      Heading, including the total.
 * @param props.items      Records of the list.
 * @param props.renderItem Row for one record (an `li`).
 * @param props.moreLabel  Button text for the hidden rows, e.g. "Show 37 more changed options".
 * @param props.lessLabel  Button text to collapse, e.g. "Show fewer changed options".
 * @param props.quiet      Less emphasis (WP-Cron rescheduling).
 */
export function DiffSection< T >( {
	id,
	title,
	items,
	renderItem,
	moreLabel,
	lessLabel,
	quiet = false,
}: {
	id: string;
	title: string;
	items: T[];
	renderItem: ( item: T, index: number ) => ReactNode;
	moreLabel: ( hidden: number ) => string;
	lessLabel: string;
	quiet?: boolean;
} ) {
	const [ expanded, setExpanded ] = useState( false );
	const listId = useId();
	const headingId = `${ id }-heading`;
	const hidden = items.length - DEFAULT_VISIBLE_DIFF_ROWS;
	const visible =
		expanded || hidden <= 0
			? items
			: items.slice( 0, DEFAULT_VISIBLE_DIFF_ROWS );

	return (
		<section aria-labelledby={ headingId } className="space-y-2">
			<h4
				id={ headingId }
				className={ cn(
					'text-sm',
					quiet
						? 'font-medium text-muted-foreground'
						: 'font-semibold'
				) }
			>
				{ title }
			</h4>
			<ul
				id={ listId }
				className="divide-y overflow-hidden rounded-lg border bg-card"
			>
				{ visible.map( renderItem ) }
			</ul>
			{ hidden > 0 && (
				<Button
					type="button"
					variant="outline"
					size="sm"
					aria-expanded={ expanded }
					aria-controls={ listId }
					onClick={ () => setExpanded( ! expanded ) }
				>
					{ expanded ? lessLabel : moreLabel( hidden ) }
				</Button>
			) }
		</section>
	);
}
