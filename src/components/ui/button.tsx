import * as React from 'react';

import {
	buttonClassName,
	type SIZES,
	type VARIANTS,
} from './button-class-name';

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
			className={ buttonClassName( variant, size, className ) }
			{ ...props }
		/>
	)
);
Button.displayName = 'Button';

export { Button };
