import { __ } from '@wordpress/i18n';

import {
	formatUnixDateTime,
	formatUnixRangeEnd,
	unixToIso,
} from '../utils/format';

/*
 * Building blocks of WP-Cron and Action Scheduler diff rows.
 */

/**
 * A Unix timestamp as local date and time.
 *
 * @param props           Props.
 * @param props.timestamp Unix timestamp (UTC seconds).
 */
export function Time( { timestamp }: { timestamp: number } ) {
	return (
		<time dateTime={ unixToIso( timestamp ) }>
			{ formatUnixDateTime( timestamp ) }
		</time>
	);
}

/**
 * A move from one time to another: "Oct 8, 2026, 5:46 PM → 5:51 PM". The
 * end repeats the date only when it falls on another local day; both keep
 * their full ISO value in `dateTime`.
 *
 * @param props      Props.
 * @param props.from Unix timestamp before.
 * @param props.to   Unix timestamp after.
 */
export function TimeRange( { from, to }: { from: number; to: number } ) {
	return (
		<>
			<Time timestamp={ from } />
			<To />
			<time dateTime={ unixToIso( to ) }>
				{ formatUnixRangeEnd( from, to ) }
			</time>
		</>
	);
}

/** "→" between a before and an after value, read as "to". */
export function To() {
	return (
		<>
			{ ' ' }
			<span aria-hidden="true">→</span>
			<span className="sr-only">{ __( 'to', 'updatelens' ) }</span>{ ' ' }
		</>
	);
}
