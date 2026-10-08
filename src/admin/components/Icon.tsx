import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/*
 * Small line icons drawn for UpdateLens (no icon font, no remote assets).
 * Always decorative: meaning is carried by the text next to them.
 */

const PATHS = {
	plugin: (
		<>
			<path d="M9 3v4.5M15 3v4.5" />
			<path d="M6 7.5h12V11a6 6 0 0 1-12 0V7.5Z" />
			<path d="M12 17v4" />
		</>
	),
	options: (
		<>
			<ellipse cx="12" cy="5.5" rx="7.5" ry="2.75" />
			<path d="M4.5 5.5v6.25c0 1.52 3.36 2.75 7.5 2.75s7.5-1.23 7.5-2.75V5.5" />
			<path d="M4.5 11.75v6.5c0 1.52 3.36 2.75 7.5 2.75s7.5-1.23 7.5-2.75v-6.5" />
		</>
	),
	cron: (
		<>
			<circle cx="12" cy="12" r="8.5" />
			<path d="M12 7.5V12l3 2" />
		</>
	),
	action_scheduler: (
		<>
			<path d="m12 3.5 8.5 4.25L12 12 3.5 7.75 12 3.5Z" />
			<path d="m3.5 12 8.5 4.25L20.5 12" />
			<path d="m3.5 16.25 8.5 4.25 8.5-4.25" />
		</>
	),
	check: (
		<>
			<circle cx="12" cy="12" r="8.5" />
			<path d="m8.5 12.25 2.5 2.5 4.5-5" />
		</>
	),
	progress: (
		<>
			<path d="M19.5 10.5A7.75 7.75 0 0 0 5.4 7.5M4.5 4v3.5H8" />
			<path d="M4.5 13.5a7.75 7.75 0 0 0 14.1 3M19.5 20v-3.5H16" />
		</>
	),
	caution: (
		<>
			<path d="M10.3 4.4 2.9 17.5A2 2 0 0 0 4.6 20.5h14.8a2 2 0 0 0 1.7-3L13.7 4.4a2 2 0 0 0-3.4 0Z" />
			<path d="M12 9.5v4M12 16.75h.01" />
		</>
	),
	failure: (
		<>
			<circle cx="12" cy="12" r="8.5" />
			<path d="m9.25 9.25 5.5 5.5M14.75 9.25l-5.5 5.5" />
		</>
	),
	info: (
		<>
			<circle cx="12" cy="12" r="8.5" />
			<path d="M12 11v5M12 8h.01" />
		</>
	),
	added: <path d="M12 6v12M6 12h12" />,
	removed: <path d="M6 12h12" />,
	changed: (
		<>
			<path d="M5 8.5h13.5M15 5l3.5 3.5L15 12" />
			<path d="M19 15.5H5.5M9 12l-3.5 3.5L9 19" />
		</>
	),
	'arrow-right': <path d="M5 12h14M13.5 6.5 19 12l-5.5 5.5" />,
	'arrow-left': <path d="M19 12H5M10.5 6.5 5 12l5.5 5.5" />,
	'chevron-down': <path d="m6.5 9.5 5.5 5.5 5.5-5.5" />,
} satisfies Record< string, ReactNode >;

export type IconName = keyof typeof PATHS;

/**
 * A decorative 24×24 line icon in the current text color.
 *
 * @param props           Props.
 * @param props.name      Icon.
 * @param props.className Size and color (default size: 1.25rem).
 */
export function Icon( {
	name,
	className,
}: {
	name: IconName;
	className?: string;
} ) {
	return (
		<svg
			aria-hidden="true"
			focusable="false"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth={ 1.75 }
			strokeLinecap="round"
			strokeLinejoin="round"
			className={ cn( 'size-5 shrink-0', className ) }
		>
			{ PATHS[ name ] }
		</svg>
	);
}
