import { __ } from '@wordpress/i18n';

import type { PluginInfo } from '../types/api';

/**
 * "1.0.0 → 1.1.0". A missing version (e.g. the update did not finish) shows as "–".
 *
 * @param props        Props.
 * @param props.plugin Plugin metadata.
 */
export function Versions( { plugin }: { plugin: PluginInfo } ) {
	return (
		<>
			<Version value={ plugin.version_before } />{ ' ' }
			<span aria-hidden="true">→</span>
			<span className="sr-only">{ __( 'to', 'updatelens' ) }</span>{ ' ' }
			<Version value={ plugin.version_after } />
		</>
	);
}

function Version( { value }: { value: string | null } ) {
	if ( value ) {
		return <>{ value }</>;
	}
	return (
		<>
			<span aria-hidden="true">–</span>
			<span className="sr-only">
				{ __( 'unknown version', 'updatelens' ) }
			</span>
		</>
	);
}
