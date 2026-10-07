import { __ } from '@wordpress/i18n';

import type { BaselinePlugin, PluginInfo } from '../types/api';

/**
 * Display name of the analysed plugin.
 *
 * @param plugin Plugin metadata.
 */
export function pluginName( plugin: PluginInfo ): string {
	return plugin.name || plugin.file || __( 'Unknown plugin', 'updatelens' );
}

const collator = new Intl.Collator( undefined, {
	numeric: true,
	sensitivity: 'base',
} );

/**
 * Plugins in alphabetical order of their names (locale-aware), then by file.
 * Active plugins are not moved up, so the order never jumps.
 *
 * @param plugins Plugins.
 */
export function sortPlugins( plugins: BaselinePlugin[] ): BaselinePlugin[] {
	return [ ...plugins ].sort(
		( a, b ) =>
			collator.compare( a.name, b.name ) ||
			( a.file < b.file ? -1 : Number( a.file > b.file ) )
	);
}
