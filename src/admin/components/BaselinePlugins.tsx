import { _n, __, sprintf } from '@wordpress/i18n';

import type { BaselinePlugin } from '../types/api';
import { formatCount } from '../utils/format';
import { sortPlugins } from '../utils/plugin';
import { DiffSection } from './DiffSection';

/**
 * Plugins installed when monitoring started, as recorded then. Long lists
 * collapse like diff lists.
 *
 * @param props         Props.
 * @param props.plugins Plugins from the baseline.
 */
export function BaselinePlugins( { plugins }: { plugins: BaselinePlugin[] } ) {
	const total = plugins.length;

	return (
		<DiffSection
			id="updatelens-baseline-plugins"
			title={ __( 'Plugins when monitoring started', 'updatelens' ) }
			items={ sortPlugins( plugins ) }
			renderItem={ ( plugin ) => (
				<PluginRow key={ plugin.file } plugin={ plugin } />
			) }
			moreLabel={ () =>
				sprintf(
					/* translators: %s: number of plugins. */
					_n(
						'Show all %s plugin',
						'Show all %s plugins',
						total,
						'updatelens'
					),
					formatCount( total )
				)
			}
			lessLabel={ __( 'Show fewer plugins', 'updatelens' ) }
		/>
	);
}

function PluginRow( { plugin }: { plugin: BaselinePlugin } ) {
	return (
		<li className="grid grid-cols-[minmax(0,1fr)_auto] items-baseline gap-x-4 gap-y-0.5 px-4 py-2.5 text-sm sm:grid-cols-[minmax(0,1fr)_minmax(0,9rem)_5rem]">
			<span className="col-start-1 row-start-1 break-words font-medium">
				{ plugin.name }
			</span>
			<span className="col-start-1 row-start-2 break-words font-mono text-xs text-muted-foreground sm:col-start-2 sm:row-start-1">
				{ plugin.version ? (
					<>
						<span className="sr-only">
							{ __( 'Version', 'updatelens' ) }{ ' ' }
						</span>
						{ plugin.version }
					</>
				) : (
					__( 'Version unknown', 'updatelens' )
				) }
			</span>
			<span
				className={
					plugin.active
						? 'col-start-2 row-start-1 text-right text-xs font-medium text-foreground sm:col-start-3'
						: 'col-start-2 row-start-1 text-right text-xs text-muted-foreground sm:col-start-3'
				}
			>
				{ plugin.active
					? __( 'Active', 'updatelens' )
					: __( 'Inactive', 'updatelens' ) }
			</span>
		</li>
	);
}
