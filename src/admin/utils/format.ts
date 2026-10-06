/**
 * Presentation formatting. Backend values (UTC timestamps, raw byte counts)
 * are never changed; they are only formatted for display.
 *
 * `locale` and `timeZone` default to the browser's; tests pass fixed values.
 */

const BYTE_UNITS = [ 'B', 'KB', 'MB', 'GB', 'TB' ];

/** True minus sign, for negative deltas. */
export const MINUS = '−';

/**
 * Byte count with binary units (1 KB = 1024 B), at most one decimal.
 *
 * @param bytes  Non-negative byte count.
 * @param locale Locale for the number.
 */
export function formatBytes( bytes: number, locale?: string ): string {
	let value = Math.abs( bytes );
	let unit = 0;

	while ( value >= 1024 && unit < BYTE_UNITS.length - 1 ) {
		value /= 1024;
		unit++;
	}
	// 1023.96 KB would display as "1,024 KB": move up a unit instead.
	if (
		unit > 0 &&
		Math.round( value * 10 ) / 10 >= 1024 &&
		unit < BYTE_UNITS.length - 1
	) {
		value /= 1024;
		unit++;
	}

	const number = new Intl.NumberFormat( locale, {
		maximumFractionDigits: unit === 0 ? 0 : 1,
	} ).format( value );

	return `${ number } ${ BYTE_UNITS[ unit ] }`;
}

/**
 * Signed byte delta: `+2 KB`, `−2 KB`, `0 B`.
 *
 * @param delta  Signed byte count (after - before).
 * @param locale Locale for the number.
 */
export function formatBytesDelta( delta: number, locale?: string ): string {
	if ( delta === 0 ) {
		return formatBytes( 0, locale );
	}
	return ( delta > 0 ? '+' : MINUS ) + formatBytes( delta, locale );
}

/**
 * Count with locale grouping.
 *
 * @param count  Count.
 * @param locale Locale.
 */
export function formatCount( count: number, locale?: string ): string {
	return new Intl.NumberFormat( locale ).format( count );
}

/**
 * Signed count delta: `+2`, `−1`, `0`.
 *
 * @param delta  Signed count.
 * @param locale Locale.
 */
export function formatCountDelta( delta: number, locale?: string ): string {
	if ( delta === 0 ) {
		return formatCount( 0, locale );
	}
	return (
		( delta > 0 ? '+' : MINUS ) + formatCount( Math.abs( delta ), locale )
	);
}

/**
 * UTC ISO 8601 timestamp in local date and time, e.g. "Oct 5, 2026, 10:46 PM".
 *
 * @param iso     API timestamp or null.
 * @param options Locale and time zone (default: the browser's).
 * @return Formatted date, or null if missing/invalid.
 */
export function formatDateTime(
	iso: string | null,
	options: { locale?: string; timeZone?: string } = {}
): string | null {
	if ( ! iso ) {
		return null;
	}
	const date = new Date( iso );
	if ( Number.isNaN( date.getTime() ) ) {
		return null;
	}
	return new Intl.DateTimeFormat( options.locale, {
		dateStyle: 'medium',
		timeStyle: 'short',
		timeZone: options.timeZone,
	} ).format( date );
}

/** Units for durations, largest first, with their length in seconds. */
const DURATION_UNITS: Array< [ string, number ] > = [
	[ 'day', 86400 ],
	[ 'hour', 3600 ],
	[ 'minute', 60 ],
	[ 'second', 1 ],
];

/**
 * Duration in the largest fitting unit, at most one decimal: `1 hour`,
 * `1.5 hours`, `7 days`.
 *
 * @param seconds Duration in seconds (the sign is ignored).
 * @param locale  Locale.
 */
export function formatDuration( seconds: number, locale?: string ): string {
	const value = Math.abs( seconds );
	const [ unit, size ] = DURATION_UNITS.find(
		( [ , length ] ) => value >= length
	) ?? [ 'second', 1 ];

	return new Intl.NumberFormat( locale, {
		style: 'unit',
		unit,
		unitDisplay: 'long',
		maximumFractionDigits: 1,
	} ).format( value / size );
}

/**
 * Signed duration: `+1 hour`, `−30 minutes`; null for no movement.
 *
 * @param seconds Signed seconds (after - before).
 * @param locale  Locale.
 */
export function formatDurationDelta(
	seconds: number,
	locale?: string
): string | null {
	if ( seconds === 0 ) {
		return null;
	}
	return ( seconds > 0 ? '+' : MINUS ) + formatDuration( seconds, locale );
}

/**
 * Unix timestamp (UTC seconds) in local date and time, like formatDateTime().
 *
 * @param seconds Unix timestamp.
 * @param options Locale and time zone (default: the browser's).
 */
export function formatUnixDateTime(
	seconds: number,
	options: { locale?: string; timeZone?: string } = {}
): string | null {
	const date = new Date( seconds * 1000 );
	return Number.isNaN( date.getTime() )
		? null
		: formatDateTime( date.toISOString(), options );
}

/**
 * Unix timestamp as the ISO string for a `<time dateTime>` attribute.
 *
 * @param seconds Unix timestamp.
 */
export function unixToIso( seconds: number ): string {
	return new Date( seconds * 1000 ).toISOString().replace( '.000Z', 'Z' );
}
