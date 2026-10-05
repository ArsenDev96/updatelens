/**
 * Scope every selector to the admin app root so Tailwind's Preflight and
 * utilities cannot restyle unrelated wp-admin UI. Keep in sync with
 * AdminPage::ROOT_ID and src/admin/main.tsx.
 */
const ROOT = '#updatelens-root';

module.exports = {
	plugins: {
		tailwindcss: {},
		'postcss-prefix-selector': {
			prefix: ROOT,
			transform( prefix, selector, prefixedSelector ) {
				// Document-level selectors (Preflight, CSS variables) map to the root itself.
				if ( /^(html|body|:root|:host)$/.test( selector ) ) {
					return prefix;
				}
				return prefixedSelector;
			},
		},
		autoprefixer: {},
	},
};
