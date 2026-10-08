import type { AnchorHTMLAttributes, MouseEvent } from 'react';

/**
 * A real link (open in new tab, copy address) that navigates inside the
 * app on a plain left click.
 *
 * @param props            Anchor props.
 * @param props.onNavigate In-app navigation for a plain left click.
 */
export function AppLink( {
	onNavigate,
	...props
}: AnchorHTMLAttributes< HTMLAnchorElement > & {
	href: string;
	onNavigate: () => void;
} ) {
	const onClick = ( event: MouseEvent< HTMLAnchorElement > ) => {
		// Let the browser handle new-tab/window clicks.
		if (
			event.button !== 0 ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey
		) {
			return;
		}
		event.preventDefault();
		onNavigate();
	};

	return <a { ...props } onClick={ onClick } />;
}
