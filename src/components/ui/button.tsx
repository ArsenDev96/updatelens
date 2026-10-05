import * as React from 'react';

import { cn } from '@/lib/utils';

const VARIANTS = {
	default: 'bg-primary text-primary-foreground shadow-sm hover:bg-primary/90',
	outline:
		'border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground',
	ghost: 'hover:bg-accent hover:text-accent-foreground',
} as const;

const SIZES = {
	default: 'h-9 px-4 py-2',
	sm: 'h-8 px-3 text-xs',
} as const;

export interface ButtonProps extends React.ButtonHTMLAttributes< HTMLButtonElement > {
	variant?: keyof typeof VARIANTS;
	size?: keyof typeof SIZES;
}

const Button = React.forwardRef< HTMLButtonElement, ButtonProps >(
	(
		{ className, variant = 'default', size = 'default', type, ...props },
		ref
	) => (
		<button
			ref={ ref }
			type={ type ?? 'button' }
			className={ cn(
				'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50',
				VARIANTS[ variant ],
				SIZES[ size ],
				className
			) }
			{ ...props }
		/>
	)
);
Button.displayName = 'Button';

export { Button };
