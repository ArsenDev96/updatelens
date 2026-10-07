import { cn } from '@/lib/utils';

export const VARIANTS = {
	default: 'bg-primary text-primary-foreground shadow-sm hover:bg-primary/90',
	outline:
		'border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground',
	ghost: 'hover:bg-accent hover:text-accent-foreground',
} as const;

export const SIZES = {
	default: 'h-9 px-4 py-2',
	sm: 'h-8 px-3 text-xs',
} as const;

const BASE =
	'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50';

/**
 * Button classes, also for links styled as buttons.
 *
 * @param variant   Variant.
 * @param size      Size.
 * @param className Extra classes.
 */
export function buttonClassName(
	variant: keyof typeof VARIANTS = 'default',
	size: keyof typeof SIZES = 'default',
	className?: string
): string {
	return cn( BASE, VARIANTS[ variant ], SIZES[ size ], className );
}
