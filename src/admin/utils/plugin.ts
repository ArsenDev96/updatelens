import { __ } from '@wordpress/i18n';

import type { PluginInfo } from '../types/api';

/**
 * Display name of the analysed plugin.
 *
 * @param plugin Plugin metadata.
 */
export function pluginName( plugin: PluginInfo ): string {
	return plugin.name || plugin.file || __( 'Unknown plugin', 'updatelens' );
}
