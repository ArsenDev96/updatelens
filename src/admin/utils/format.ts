/**
 * Presentation formatting. Backend values (UTC timestamps, raw byte counts)
 * are never changed; they are only formatted for display.
 *
 * `locale` and `timeZone` default to the browser's; tests pass fixed values.
 * Words (duration units) come from UpdateLens translations, not from `locale`.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

const BYTE_UNITS = [ 'B', 'KB', 'MB', 'GB', 'TB' ];

/** True minus sign, for negative deltas. */
export const MINUS = '−';

/**
 * Byte count with binary units (1 KB = 1024 B), at most one decimal (or
 * `fractionDigits`).
 *
 * @param bytes          Non-negative byte count.
 * @param locale         Locale for the number.
 * @param fractionDigits Maximum decimals above bytes.
 */
export function formatBytes(
	bytes: number,
	locale?: string,
	fractionDigits = 1
): string {
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
		maximumFractionDigits: unit === 0 ? 0 : fractionDigits,
	} ).format( value );

	return `${ number } ${ BYTE_UNITS[ unit ] }`;
}

/**
 * A formatted value whose parts must stay on one line ("196.8 KB", "+2 KB"):
 * its spaces become no-break spaces, so a narrow row never wraps between the
 * number and the unit.
 *
 * @param text Formatted value, e.g. from `formatBytes()`.
 */
export function keepTogether( text: string ): string {
	return text.replace( / /g, '\u00a0' );
}

/**
 * Before and after sizes for "before → after". When different sizes would
 * look the same at one decimal ("6 KB → 6 KB"), both get two decimals
 * ("6.02 KB → 6.08 KB"); the exact delta is shown separately.
 *
 * @param before Bytes before.
 * @param after  Bytes after.
 * @param locale Locale for the numbers.
 */
export function formatBytesPair(
	before: number,
	after: number,
	locale?: string
): [ string, string ] {
	const pair: [ string, string ] = [
		formatBytes( before, locale ),
		formatBytes( after, locale ),
	];
	if ( before === after || pair[ 0 ] !== pair[ 1 ] ) {
		return pair;
	}
	return [
		formatBytes( before, locale, 2 ),
		formatBytes( after, locale, 2 ),
	];
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

type DurationUnit = 'day' | 'hour' | 'minute' | 'second';

/** Units for durations, largest first, with their length in seconds. */
const DURATION_UNITS: Array< [ DurationUnit, number ] > = [
	[ 'day', 86400 ],
	[ 'hour', 3600 ],
	[ 'minute', 60 ],
	[ 'second', 1 ],
];

/**
 * Translated "<number> <unit>" phrase. Each unit is a literal `_n()` call so
 * the strings are extracted for translation.
 *
 * @param unit   Unit.
 * @param count  Rounded value, for choosing the plural form.
 * @param number Formatted value.
 */
function durationUnitText(
	unit: DurationUnit,
	count: number,
	number: string
): string {
	switch ( unit ) {
		case 'day':
			return sprintf(
				/* translators: %s: number of days, e.g. "7" or "1.5". */
				_n( '%s day', '%s days', count, 'updatelens' ),
				number
			);
		case 'hour':
			return sprintf(
				/* translators: %s: number of hours, e.g. "2" or "1.5". */
				_n( '%s hour', '%s hours', count, 'updatelens' ),
				number
			);
		case 'minute':
			return sprintf(
				/* translators: %s: number of minutes, e.g. "5" or "1.5". */
				_n( '%s minute', '%s minutes', count, 'updatelens' ),
				number
			);
		default:
			return sprintf(
				/* translators: %s: number of seconds, e.g. "45". */
				_n( '%s second', '%s seconds', count, 'updatelens' ),
				number
			);
	}
}

/**
 * Duration in the largest fitting unit, at most one decimal: `1 hour`,
 * `1.5 hours`, `7 days`. Unit words are UpdateLens translations (the
 * wp-admin language), never the browser's; only the number uses `locale`.
 *
 * @param seconds Duration in seconds (the sign is ignored).
 * @param locale  Locale for the number.
 */
export function formatDuration( seconds: number, locale?: string ): string {
	const value = Math.abs( seconds );
	const [ unit, size ] = DURATION_UNITS.find(
		( [ , length ] ) => value >= length
	) ?? [ 'second', 1 ];
	// The displayed value (one decimal) decides singular/plural: 1 → "1 hour".
	const count = Math.round( ( value / size ) * 10 ) / 10;

	return durationUnitText(
		unit,
		count,
		new Intl.NumberFormat( locale, {
			maximumFractionDigits: 1,
		} ).format( count )
	);
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

/** Request times of one check (see formatRequestTimes()). */
export interface RequestTimesText {
	before: string | null;
	after: string | null;
	/** Signed `after - before`; null unless both times are given. */
	difference: string | null;
}

/**
 * Request times of one check, in seconds: "0.31 s", "1.6 s". Both share one
 * precision, hundredths when either is under a second (as displayed) and
 * tenths otherwise, and the signed difference ("+0.04 s", "−0.1 s", "0.00 s")
 * is that of the displayed values, so the three always add up. The unit is
 * an UpdateLens translation; only the number uses `locale`.
 *
 * @param beforeMs Earlier time in milliseconds, or null.
 * @param afterMs  Later time in milliseconds, or null.
 * @param locale   Locale for the numbers.
 */
export function formatRequestTimes(
	beforeMs: number | null,
	afterMs: number | null,
	locale?: string
): RequestTimesText {
	const given = [ beforeMs, afterMs ].filter(
		( ms ): ms is number => ms !== null
	);
	const decimals = given.some( ( ms ) => Math.round( ms / 10 ) < 100 )
		? 2
		: 1;
	const step = decimals === 2 ? 10 : 100;
	const number = new Intl.NumberFormat( locale, {
		minimumFractionDigits: decimals,
		maximumFractionDigits: decimals,
	} );
	const text = ( steps: number ) =>
		sprintf(
			/* translators: %s: a request time in seconds, e.g. "1.6" or "0.31". "s" is the abbreviation for seconds. */
			__( '%s s', 'updatelens' ),
			number.format( ( Math.abs( steps ) * step ) / 1000 )
		);
	const steps = ( ms: number | null ) =>
		ms === null ? null : Math.round( ms / step );
	const before = steps( beforeMs );
	const after = steps( afterMs );
	let difference: string | null = null;
	if ( before !== null && after !== null ) {
		const delta = after - before;
		difference =
			( delta > 0 ? '+' : delta < 0 ? MINUS : '' ) + text( delta );
	}

	return {
		before: before === null ? null : text( before ),
		after: after === null ? null : text( after ),
		difference,
	};
}

/**
 * How far a scheduled time moved, for a sentence: whole units with the
 * direction ("1 hour later", "2 minutes earlier"), and "about" when the
 * movement was rounded ("about 1 hour later" for 59 min 28 s). A unit counts
 * from 95 % of its length. Null for no movement.
 *
 * @param seconds Signed seconds (after - before).
 * @param locale  Locale for the number.
 */
export function formatShift( seconds: number, locale?: string ): string | null {
	if ( seconds === 0 ) {
		return null;
	}
	const value = Math.abs( seconds );
	const [ unit, size ] = DURATION_UNITS.find(
		( [ , length ] ) => value >= length * 0.95
	) ?? [ 'second', 1 ];
	const count = Math.max( 1, Math.round( value / size ) );
	const amount = durationUnitText(
		unit,
		count,
		new Intl.NumberFormat( locale ).format( count )
	);
	const exact = count * size === value;
	if ( seconds > 0 ) {
		return exact
			? /* translators: %s: duration, e.g. "1 hour". */
				sprintf( __( '%s later', 'updatelens' ), amount )
			: /* translators: %s: rounded duration, e.g. "1 hour". */
				sprintf( __( 'about %s later', 'updatelens' ), amount );
	}
	return exact
		? /* translators: %s: duration, e.g. "1 hour". */
			sprintf( __( '%s earlier', 'updatelens' ), amount )
		: /* translators: %s: rounded duration, e.g. "1 hour". */
			sprintf( __( 'about %s earlier', 'updatelens' ), amount );
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
 * The end of a time range (e.g. a rescheduled run) after its start: only the
 * local time ("5:51 PM") when both fall on the same local calendar day, so
 * the date is not repeated; else the full date and time. The day is
 * compared in the same time zone the times are shown in.
 *
 * @param start   Unix timestamp of the start.
 * @param end     Unix timestamp of the end.
 * @param options Locale and time zone (default: the browser's).
 */
export function formatUnixRangeEnd(
	start: number,
	end: number,
	options: { locale?: string; timeZone?: string } = {}
): string | null {
	const from = new Date( start * 1000 );
	const to = new Date( end * 1000 );
	if ( Number.isNaN( from.getTime() ) || Number.isNaN( to.getTime() ) ) {
		return formatUnixDateTime( end, options );
	}
	const day = new Intl.DateTimeFormat( options.locale, {
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		timeZone: options.timeZone,
	} );
	if ( day.format( from ) !== day.format( to ) ) {
		return formatUnixDateTime( end, options );
	}
	return new Intl.DateTimeFormat( options.locale, {
		timeStyle: 'short',
		timeZone: options.timeZone,
	} ).format( to );
}

/**
 * Unix timestamp as the ISO string for a `<time dateTime>` attribute.
 *
 * @param seconds Unix timestamp.
 */
export function unixToIso( seconds: number ): string {
	return new Date( seconds * 1000 ).toISOString().replace( '.000Z', 'Z' );
}
