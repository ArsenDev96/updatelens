import { clsx, type ClassValue } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

// The theme's own font sizes (tailwind.config.js), so they are merged as
// sizes, never mistaken for text colors and dropped.
const twMerge = extendTailwindMerge( {
	extend: {
		classGroups: {
			'font-size': [
				{ text: [ 'section-title', 'section-description' ] },
			],
		},
	},
} );

export function cn( ...inputs: ClassValue[] ) {
	return twMerge( clsx( inputs ) );
}
