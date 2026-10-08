import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { formatUnixDateTime, unixToIso } from '../utils/format';

/*
 * Building blocks of WP-Cron and Action Scheduler diff rows.
 */

/**
 * A hook name in monospace; long names wrap anywhere.
 *
 * @param props      Props.
 * @param props.hook Hook name.
 */
export function HookName( { hook }: { hook: string } ) {
	return (
		<code className="m-0 block select-text break-all bg-transparent p-0 font-mono text-sm font-medium text-foreground">
			{ hook }
		</code>
	);
}

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

/**
 * Definition list of a row.
 *
 * @param props      Props.
 * @param props.rows Label and content pairs.
 */
export function Details( { rows }: { rows: Array< [ string, ReactNode ] > } ) {
	return (
		<dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[auto_minmax(0,1fr)]">
			{ rows.map( ( [ label, content ] ) => (
				<div key={ label } className="contents">
					<dt className="text-muted-foreground">{ label }</dt>
					<dd className="min-w-0 break-words">{ content }</dd>
				</div>
			) ) }
		</dl>
	);
}
