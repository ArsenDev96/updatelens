import animate from 'tailwindcss-animate';

/**
 * All generated CSS is scoped to #updatelens-root by postcss.config.cjs, so
 * Tailwind (including Preflight) never affects the rest of wp-admin.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
	content: [ './src/**/*.{ts,tsx}' ],
	theme: {
		extend: {
			colors: {
				border: 'hsl(var(--border))',
				input: 'hsl(var(--input))',
				ring: 'hsl(var(--ring))',
				background: 'hsl(var(--background))',
				foreground: 'hsl(var(--foreground))',
				primary: {
					DEFAULT: 'hsl(var(--primary))',
					foreground: 'hsl(var(--primary-foreground))',
				},
				secondary: {
					DEFAULT: 'hsl(var(--secondary))',
					foreground: 'hsl(var(--secondary-foreground))',
				},
				destructive: {
					DEFAULT: 'hsl(var(--destructive))',
					foreground: 'hsl(var(--destructive-foreground))',
				},
				muted: {
					DEFAULT: 'hsl(var(--muted))',
					foreground: 'hsl(var(--muted-foreground))',
				},
				accent: {
					DEFAULT: 'hsl(var(--accent))',
					foreground: 'hsl(var(--accent-foreground))',
				},
				popover: {
					DEFAULT: 'hsl(var(--popover))',
					foreground: 'hsl(var(--popover-foreground))',
				},
				card: {
					DEFAULT: 'hsl(var(--card))',
					foreground: 'hsl(var(--card-foreground))',
				},
				tint: {
					DEFAULT: 'hsl(var(--tint))',
					strong: 'hsl(var(--tint-strong))',
					border: 'hsl(var(--tint-border))',
					foreground: 'hsl(var(--tint-foreground))',
				},
			},
			boxShadow: {
				// Restrained elevation of report surfaces, and on hover.
				surface:
					'0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 3px 0 rgb(15 23 42 / 0.05)',
				raised: '0 8px 20px -6px rgb(15 23 42 / 0.12), 0 2px 4px -2px rgb(15 23 42 / 0.06)',
			},
			borderRadius: {
				lg: 'var(--radius)',
				md: 'calc(var(--radius) - 2px)',
				sm: 'calc(var(--radius) - 4px)',
			},
		},
	},
	plugins: [ animate ],
};
