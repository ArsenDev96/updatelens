import { render, screen, within } from '@testing-library/react';

import App from '@/admin/App';
import type { PhaseKey, Provider } from '@/admin/types/api';

/*
 * Reports are rendered through the app at their URL, like a bookmark:
 * the overview, or one signal's details (`&signal=`). Each test file mocks
 * `@wordpress/api-fetch` itself.
 */

/**
 * Renders the app at a report URL.
 *
 * @param id             Analysis ID.
 * @param options        View.
 * @param options.signal Signal whose details are open (default: the overview).
 * @param options.phase  Phase in the URL (default: none, the report's default phase).
 * @param options.item   Located option name or hook (`&item=`).
 * @param options.group  Located Action Scheduler group (`&group=`).
 * @param options.kind   Located change list (`&kind=`).
 * @param options.finding Index of the located finding (`&finding=`).
 */
export function openReport(
	id: number,
	{
		signal,
		phase,
		item,
		group,
		kind,
		finding,
	}: {
		signal?: Provider;
		phase?: PhaseKey;
		item?: string;
		group?: string;
		kind?: string;
		finding?: number;
	} = {}
) {
	const params = new URLSearchParams( {
		page: 'updatelens',
		analysis: String( id ),
	} );
	if ( signal ) {
		params.set( 'signal', signal );
	}
	if ( phase ) {
		params.set( 'phase', phase );
	}
	if ( item !== undefined ) {
		params.set( 'item', item );
	}
	if ( group !== undefined ) {
		params.set( 'group', group );
	}
	if ( kind !== undefined ) {
		params.set( 'kind', kind );
	}
	if ( finding !== undefined ) {
		params.set( 'finding', String( finding ) );
	}
	window.history.replaceState(
		null,
		'',
		`/wp-admin/admin.php?${ params.toString() }`
	);
	render( <App /> );
}

/** The selected phase's panel (the only tab panel of a view). */
export const phasePanel = () => screen.getByRole( 'tabpanel' );

export const tab = ( name: RegExp ) => screen.getByRole( 'tab', { name } );

/** Visible text of the phase tabs, in order. */
export const tabTexts = () =>
	screen.getAllByRole( 'tab' ).map( ( item ) => item.textContent );

/**
 * A signal card of the overview.
 *
 * @param name Signal name, e.g. "WP-Cron".
 */
export const signalCard = ( name: string ) =>
	within( screen.getByRole( 'list', { name: 'Signals' } ) )
		.getByRole( 'link', { name } )
		.closest( 'li' )!;

/** Text of each signal card, in order (name, state, breakdown, link text). */
export const cardTexts = () =>
	within( screen.getByRole( 'list', { name: 'Signals' } ) )
		.getAllByRole( 'listitem' )
		.map( ( item ) => item.textContent );
