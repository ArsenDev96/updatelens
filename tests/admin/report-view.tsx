import { render, screen, within } from '@testing-library/react';

import App from '@/admin/App';
import type { PhaseKey, Provider } from '@/admin/types/api';
import type { ReportTab } from '@/admin/utils/route';

/*
 * Reports are rendered through the app at their URL, like a bookmark:
 * a tab of the overview (Update results by default), or one signal's
 * details (`&signal=`). Each test file mocks `@wordpress/api-fetch` itself.
 */

/**
 * Renders the app at a report URL.
 *
 * @param id             Analysis ID.
 * @param options        View.
 * @param options.tab    Tab of the overview (`&tab=`; default: none, Update results).
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
		tab: reportTab,
		signal,
		phase,
		item,
		group,
		kind,
		finding,
	}: {
		tab?: ReportTab;
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
	if ( reportTab ) {
		params.set( 'tab', reportTab );
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

/** The phase tabs (a signal page's, or Configuration changes'). */
const phaseList = () =>
	screen.getByRole( 'tablist', { name: 'Observation phases' } );

/** The selected phase's panel. */
export const phasePanel = () =>
	document.getElementById( 'updatelens-phase-panel' ) as HTMLElement;

/** A phase tab. */
export const tab = ( name: RegExp ) =>
	within( phaseList() ).getByRole( 'tab', { name } );

/** Visible text of the phase tabs, in order. */
export const tabTexts = () =>
	within( phaseList() )
		.getAllByRole( 'tab' )
		.map( ( item ) => item.textContent );

/** The report's tabs (Update results, Website checks, Configuration changes). */
export const reportTabs = () =>
	within( screen.getByRole( 'tablist', { name: 'Report' } ) ).getAllByRole(
		'tab'
	);

/**
 * A tab of the report.
 *
 * @param name Its name, e.g. "Website checks".
 */
export const reportTab = ( name: string | RegExp ) =>
	within( screen.getByRole( 'tablist', { name: 'Report' } ) ).getByRole(
		'tab',
		{ name }
	);

/** The report's tab panel. */
export const reportPanel = () =>
	document.getElementById( 'updatelens-report-panel' ) as HTMLElement;

/**
 * A source row of Configuration changes.
 *
 * @param name Signal name, e.g. "WP-Cron".
 */
export const signalCard = ( name: string ) =>
	within( screen.getByRole( 'list', { name: 'Signals' } ) )
		.getAllByRole( 'heading', { level: 4 } )
		.find( ( heading ) => heading.textContent?.startsWith( name ) )!
		.closest( 'li' )!;

/**
 * Text of each source row, in order (name, count, state, breakdown), without
 * its link to the signal page.
 */
export const cardTexts = () =>
	within( screen.getByRole( 'list', { name: 'Signals' } ) )
		.getAllByRole( 'listitem' )
		.filter( ( item ) => item.hasAttribute( 'data-signal' ) )
		.map( ( item ) => {
			const copy = item.cloneNode( true ) as HTMLElement;
			copy.querySelector( '[data-updatelens-signal-link]' )?.remove();
			return copy.textContent;
		} );
