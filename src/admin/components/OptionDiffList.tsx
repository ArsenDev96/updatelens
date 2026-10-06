import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import type { AvailableOptionsPhase } from '../types/api';
import { ChangedOptionRow, OptionStateRow } from './OptionRows';

/**
 * Added, removed and changed options of one phase. Every section is always
 * shown; an empty one says so in one line.
 *
 * @param props       Props.
 * @param props.phase Available phase.
 */
export function OptionDiffList( { phase }: { phase: AvailableOptionsPhase } ) {
	const titles = {
		/* translators: %d: number of options. */
		added: sprintf( __( 'Added (%d)', 'updatelens' ), phase.added.length ),
		/* translators: %d: number of options. */
		removed: sprintf(
			__( 'Removed (%d)', 'updatelens' ),
			phase.removed.length
		),
		/* translators: %d: number of options. */
		changed: sprintf(
			__( 'Changed (%d)', 'updatelens' ),
			phase.changed.length
		),
	};

	return (
		<div className="space-y-5">
			<Section
				id="added"
				title={ titles.added }
				empty={ __( 'No added options.', 'updatelens' ) }
				count={ phase.added.length }
			>
				{ phase.added.map( ( option ) => (
					<OptionStateRow
						key={ option.name }
						option={ option }
						kind="added"
					/>
				) ) }
			</Section>
			<Section
				id="removed"
				title={ titles.removed }
				empty={ __( 'No removed options.', 'updatelens' ) }
				count={ phase.removed.length }
			>
				{ phase.removed.map( ( option ) => (
					<OptionStateRow
						key={ option.name }
						option={ option }
						kind="removed"
					/>
				) ) }
			</Section>
			<Section
				id="changed"
				title={ titles.changed }
				empty={ __( 'No changed options.', 'updatelens' ) }
				count={ phase.changed.length }
			>
				{ phase.changed.map( ( option ) => (
					<ChangedOptionRow key={ option.name } option={ option } />
				) ) }
			</Section>
			<p className="text-xs text-muted-foreground">
				{ __(
					'UpdateLens detects value changes without storing the option values themselves.',
					'updatelens'
				) }
			</p>
		</div>
	);
}

function Section( {
	id,
	title,
	empty,
	count,
	children,
}: {
	id: string;
	title: string;
	empty: string;
	count: number;
	children: ReactNode;
} ) {
	const headingId = `updatelens-options-${ id }`;
	return (
		<section aria-labelledby={ headingId } className="space-y-2">
			<h3 id={ headingId } className="text-sm font-semibold">
				{ title }
			</h3>
			{ count === 0 ? (
				<p className="text-sm text-muted-foreground">{ empty }</p>
			) : (
				<ul className="divide-y overflow-hidden rounded-lg border bg-card">
					{ children }
				</ul>
			) }
		</section>
	);
}
