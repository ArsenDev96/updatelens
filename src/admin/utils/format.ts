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
