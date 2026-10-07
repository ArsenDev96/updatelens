import { __, sprintf } from '@wordpress/i18n';

import { getMonitoringStart } from '../api/baseline';
import { useRequest } from '../hooks/use-request';
import { formatDateTime } from '../utils/format';

/**
 * Where the history begins: when monitoring started, and that earlier updates
 * were not observed. Shown after the oldest analysis (last History page);
 * nothing while loading or when the start is unknown.
 */
export function MonitoringBoundary() {
	const [ request ] = useRequest( getMonitoringStart );
	const date =
		request.status === 'ready' ? formatDateTime( request.data ) : null;

	if ( ! date ) {
		return null;
	}

	return (
		<p className="border-t border-dashed pt-3 text-xs text-muted-foreground">
			{ sprintf(
				/* translators: %s: date and time when UpdateLens started monitoring. */
				__( 'Monitoring began on %s.', 'updatelens' ),
				date
			) }{ ' ' }
			{ __(
				'Updates before this point were not observed by UpdateLens.',
				'updatelens'
			) }
		</p>
	);
}
