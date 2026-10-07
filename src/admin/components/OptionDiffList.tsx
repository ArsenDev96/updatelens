import { _n, __, sprintf } from '@wordpress/i18n';

import type { AvailableOptionsPhase } from '../types/api';
import { formatCount } from '../utils/format';
import { DiffSection } from './DiffSection';
import { ChangedOptionRow, OptionStateRow } from './OptionRows';

/**
 * Added, removed and changed options of one phase with changes. Empty lists
 * are left out (the summary shows their zero count); long lists collapse.
 *
 * @param props       Props.
 * @param props.phase Available phase with at least one change.
 */
export function OptionDiffList( { phase }: { phase: AvailableOptionsPhase } ) {
	const titles = {
		/* translators: %s: number of listed items (options, WP-Cron events or Action Scheduler actions). */
		added: sprintf(
			__( 'Added (%s)', 'updatelens' ),
			formatCount( phase.added.length )
		),
		/* translators: %s: number of listed items (options, WP-Cron events or Action Scheduler actions). */
		removed: sprintf(
			__( 'Removed (%s)', 'updatelens' ),
			formatCount( phase.removed.length )
		),
		/* translators: %s: number of listed items (options, WP-Cron events or Action Scheduler actions). */
		changed: sprintf(
			__( 'Changed (%s)', 'updatelens' ),
			formatCount( phase.changed.length )
		),
	};

	return (
		<div className="space-y-5">
			{ phase.added.length > 0 && (
				<DiffSection
					id="updatelens-options-added"
					title={ titles.added }
					items={ phase.added }
					renderItem={ ( option ) => (
						<OptionStateRow
							key={ option.name }
							option={ option }
							kind="added"
						/>
					) }
					moreLabel={ ( hidden ) =>
						sprintf(
							/* translators: %s: number of hidden rows. */
							_n(
								'Show %s more added option',
								'Show %s more added options',
								hidden,
								'updatelens'
							),
							formatCount( hidden )
						)
					}
					lessLabel={ __( 'Show fewer added options', 'updatelens' ) }
				/>
			) }
			{ phase.removed.length > 0 && (
				<DiffSection
					id="updatelens-options-removed"
					title={ titles.removed }
					items={ phase.removed }
					renderItem={ ( option ) => (
						<OptionStateRow
							key={ option.name }
							option={ option }
							kind="removed"
						/>
					) }
					moreLabel={ ( hidden ) =>
						sprintf(
							/* translators: %s: number of hidden rows. */
							_n(
								'Show %s more removed option',
								'Show %s more removed options',
								hidden,
								'updatelens'
							),
							formatCount( hidden )
						)
					}
					lessLabel={ __(
						'Show fewer removed options',
						'updatelens'
					) }
				/>
			) }
			{ phase.changed.length > 0 && (
				<DiffSection
					id="updatelens-options-changed"
					title={ titles.changed }
					items={ phase.changed }
					renderItem={ ( option ) => (
						<ChangedOptionRow
							key={ option.name }
							option={ option }
						/>
					) }
					moreLabel={ ( hidden ) =>
						sprintf(
							/* translators: %s: number of hidden rows. */
							_n(
								'Show %s more changed option',
								'Show %s more changed options',
								hidden,
								'updatelens'
							),
							formatCount( hidden )
						)
					}
					lessLabel={ __(
						'Show fewer changed options',
						'updatelens'
					) }
				/>
			) }
			<p className="text-xs text-muted-foreground">
				{ __(
					'UpdateLens detects value changes without storing the option values themselves.',
					'updatelens'
				) }
			</p>
		</div>
	);
}
