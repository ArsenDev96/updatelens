import apiFetch from '@wordpress/api-fetch';
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import App from '@/admin/App';
import { HistoryPage } from '@/admin/pages/HistoryPage';
import type { BaselinePlugin, MonitoringBaseline } from '@/admin/types/api';
import { formatDateTime } from '@/admin/utils/format';

import {
	BASELINE,
	COMPLETED,
	historyItem,
	listResponse,
	restError,
	UNKNOWN_BASELINE,
} from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

const PLUGINS_URL = 'https://example.test/wp-admin/plugins.php';

interface Api {
	/** History response for any page. */
	history?: Response | ( () => Response );
	/** Baseline body, or an error to reject with. */
	baseline?: unknown;
	baselineError?: unknown;
}

function serve( { history, baseline = BASELINE, baselineError }: Api ) {
	apiFetchMock.mockImplementation( ( async ( options: { path?: string } ) => {
		const path = options.path ?? '';
		if ( path.startsWith( '/updatelens/v1/baseline' ) ) {
			if ( baselineError ) {
				throw baselineError;
			}
			if (
				path.endsWith( '?_fields=started_at' ) &&
				baseline &&
				typeof baseline === 'object'
			) {
				return {
					started_at: ( baseline as MonitoringBaseline ).started_at,
				};
			}
			return baseline;
		}
		if ( typeof history === 'function' ) {
			return history();
		}
		return history ?? listResponse( [] );
	} ) as unknown as typeof apiFetch );
}

function baselinePaths(): string[] {
	return apiFetchMock.mock.calls
		.map( ( [ options ] ) => ( options as { path?: string } ).path ?? '' )
		.filter( ( path ) => path.startsWith( '/updatelens/v1/baseline' ) );
}

function renderHistory( page = 1, pluginsUrl = PLUGINS_URL ) {
	render(
		<HistoryPage
			page={ page }
			reportHref={ ( id ) =>
				`/wp-admin/admin.php?page=updatelens&analysis=${ id }`
			}
			onOpenReport={ vi.fn() }
			onPageChange={ vi.fn() }
			focusHeading={ false }
			pluginsUrl={ pluginsUrl }
		/>
	);
}

function plugin( n: number, active = n % 3 !== 0 ): BaselinePlugin {
	return {
		file: `plugin-${ n }/plugin-${ n }.php`,
		name: `Plugin ${ String( n ).padStart( 2, '0' ) }`,
		version: `1.${ n }.0`,
		active,
	};
}

function pluginRows(): HTMLElement[] {
	const list = screen.getByRole( 'region', {
		name: 'Plugins when monitoring started',
	} );
	return within( list ).getAllByRole( 'listitem' );
}

describe( 'First run (no analyses yet)', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'replaces the empty history with onboarding', async () => {
		serve( {} );
		renderHistory();

		const heading = await screen.findByRole( 'heading', {
			level: 2,
			name: 'UpdateLens is ready',
		} );
		expect( heading ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'heading', { name: 'Update History' } )
		).toBeNull();
		expect(
			screen.getByText(
				'Update one plugin normally from WordPress admin. UpdateLens will observe what changes during the update and shortly afterward.'
			)
		).toBeInTheDocument();

		const steps = within(
			screen.getByRole( 'list', { name: 'How UpdateLens works' } )
		).getAllByRole( 'listitem' );
		expect(
			steps.map(
				( step ) =>
					within( step ).getByRole( 'heading', { level: 3 } )
						.textContent
			)
		).toEqual( [
			'Update a plugin',
			'UpdateLens observes',
			'Review the report',
		] );
		expect( steps[ 1 ] ).toHaveTextContent(
			'Options, autoload data, WP-Cron and Action Scheduler are compared automatically.'
		);

		expect( screen.queryByRole( 'navigation' ) ).toBeNull();
		expect( screen.queryByRole( 'alert' ) ).toBeNull();
	} );

	it( 'links to the Plugins screen and states the supported workflow', async () => {
		serve( {} );
		renderHistory();

		const cta = await screen.findByRole( 'link', {
			name: 'Go to Plugins',
		} );
		expect( cta ).toHaveAttribute( 'href', PLUGINS_URL );
		expect(
			screen.getByText(
				'For the clearest report, update one plugin at a time.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'UpdateLens currently analyzes supported single-plugin updates made from WordPress admin.'
			)
		).toBeInTheDocument();
		// Nothing to start manually.
		expect(
			screen.queryByRole( 'button', {
				name: /scan|baseline|start|analy/i,
			} )
		).toBeNull();
	} );

	it( 'omits the Plugins link when the user cannot open the Plugins screen', async () => {
		serve( {} );
		renderHistory( 1, '' );

		await screen.findByRole( 'heading', { name: 'UpdateLens is ready' } );
		expect(
			screen.queryByRole( 'link', { name: 'Go to Plugins' } )
		).toBeNull();
	} );

	it( 'lists the three report signals with the privacy and observation notes', async () => {
		serve( {} );
		renderHistory();

		const watches = await screen.findByRole( 'region', {
			name: 'What UpdateLens watches',
		} );
		expect(
			within( watches )
				.getAllByRole( 'listitem' )
				.map( ( item ) => item.textContent )
		).toEqual( [
			'Options & autoloadValue changes, size and autoload behavior',
			'WP-CronScheduled WordPress events',
			'Action SchedulerActive background actions and schedules',
		] );
		expect( watches ).toHaveTextContent(
			'Private by design. Raw option values and job arguments are not stored.'
		);
		expect( watches ).toHaveTextContent(
			'Reports show observed changes. WordPress core or other plugins may also perform background work during the same observation window.'
		);
	} );

	it( 'shows when monitoring started and the plugins installed then', async () => {
		serve( {} );
		renderHistory();

		const section = await screen.findByRole( 'region', {
			name: 'Monitoring started',
		} );
		const time = section.querySelector( 'time' );
		expect( time ).toHaveAttribute( 'datetime', '2026-10-07T22:32:00Z' );
		expect( time ).toHaveTextContent(
			formatDateTime( '2026-10-07T22:32:00Z' )!
		);
		expect( section ).toHaveTextContent(
			'UpdateLens starts observing plugin updates from this point forward. Earlier updates were not observed.'
		);
		expect( section ).toHaveTextContent(
			'Besides UpdateLens, 4 plugins were installed when monitoring began.'
		);
		expect( section ).not.toHaveTextContent( /historically/ );

		expect( pluginRows().map( ( row ) => row.textContent ) ).toEqual( [
			'Classic EditorVersion 1.7.0Inactive',
			'ElementorVersion 4.3.4Active',
			'NewsletterVersion 9.4.7Active',
			'WooCommerceVersion 11.1.2Active',
		] );
	} );

	it( 'renders the baseline as recorded: UpdateLens is not added, missing versions are explained', async () => {
		serve( {
			baseline: {
				started_at: '2026-10-07T22:32:00Z',
				plugin_count: 1,
				plugins: [
					{
						file: 'no-version.php',
						name: 'No Version',
						version: '',
						active: false,
					},
				],
			},
		} );
		renderHistory();

		await screen.findByRole( 'region', { name: 'Monitoring started' } );
		const rows = pluginRows();
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toHaveTextContent(
			'No VersionVersion unknownInactive'
		);
		expect(
			within(
				screen.getByRole( 'region', {
					name: 'Plugins when monitoring started',
				} )
			).queryByText( 'UpdateLens' )
		).toBeNull();
		expect(
			screen.getByText(
				'Besides UpdateLens, 1 plugin was installed when monitoring began.'
			)
		).toBeInTheDocument();
	} );

	it( 'sorts plugins alphabetically, not by active state', async () => {
		serve( {
			baseline: {
				...BASELINE,
				plugins: [
					{ ...BASELINE.plugins![ 3 ] },
					{ ...BASELINE.plugins![ 1 ] },
					{
						file: 'akismet/akismet.php',
						name: 'akismet Anti-spam',
						version: '5.3',
						active: false,
					},
					{ ...BASELINE.plugins![ 0 ] },
				],
			},
		} );
		renderHistory();

		await screen.findByRole( 'region', { name: 'Monitoring started' } );
		expect(
			pluginRows().map(
				( row ) => row.querySelector( 'span' )?.textContent
			)
		).toEqual( [
			'akismet Anti-spam',
			'Classic Editor',
			'Elementor',
			'WooCommerce',
		] );
	} );

	it( 'states active and inactive in text, not only by color', async () => {
		serve( {} );
		renderHistory();

		await screen.findByRole( 'region', { name: 'Monitoring started' } );
		const [ inactive, active ] = pluginRows();
		expect( within( inactive ).getByText( 'Inactive' ) ).toBeVisible();
		expect( within( active ).getByText( 'Active' ) ).toBeVisible();
	} );

	it( 'collapses a long plugin list behind an accessible button', async () => {
		const plugins = Array.from( { length: 27 }, ( _, i ) =>
			plugin( 27 - i )
		);
		serve( {
			baseline: {
				started_at: '2026-10-07T22:32:00Z',
				plugin_count: 27,
				plugins,
			},
		} );
		renderHistory();
		const user = userEvent.setup();

		await screen.findByRole( 'region', { name: 'Monitoring started' } );
		expect( pluginRows() ).toHaveLength( 10 );
		expect( pluginRows()[ 0 ] ).toHaveTextContent( 'Plugin 01' );

		const toggle = screen.getByRole( 'button', {
			name: 'Show all 27 plugins',
		} );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );

		await user.click( toggle );
		expect( pluginRows() ).toHaveLength( 27 );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( toggle ).toHaveAccessibleName( 'Show fewer plugins' );
		expect( toggle ).toHaveFocus();

		await user.click( toggle );
		expect( pluginRows() ).toHaveLength( 10 );
	} );

	it( 'says when no other plugins were installed', async () => {
		serve( {
			baseline: {
				started_at: '2026-10-07T22:32:00Z',
				plugin_count: 0,
				plugins: [],
			},
		} );
		renderHistory();

		expect(
			await screen.findByText(
				'No other plugins were installed when monitoring began.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'region', {
				name: 'Plugins when monitoring started',
			} )
		).toBeNull();
	} );

	it.each( [
		[ 'missing or unreadable baseline', { baseline: UNKNOWN_BASELINE } ],
		[ 'unexpected response', { baseline: '<html>oops</html>' } ],
		[
			'server error',
			{
				baselineError: restError(
					'updatelens_reports_unavailable',
					500
				),
			},
		],
		[ 'forbidden', { baselineError: restError( 'rest_forbidden', 403 ) } ],
	] )( 'falls back safely on a %s', async ( _, api ) => {
		serve( api );
		renderHistory();

		expect(
			await screen.findByText(
				'Monitoring begins with future supported plugin updates.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'UpdateLens is ready' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Go to Plugins' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'region', { name: 'Monitoring started' } )
		).toBeNull();
		expect( screen.queryByRole( 'alert' ) ).toBeNull();
		expect( document.body ).not.toHaveTextContent(
			/updatelens_reports_unavailable|Server says|oops/
		);
	} );

	it( 'shows the plugin list without the history request blocking on it', async () => {
		let resolveBaseline: ( value: unknown ) => void = () => {};
		apiFetchMock.mockImplementation( ( ( options: { path?: string } ) =>
			options.path?.startsWith( '/updatelens/v1/baseline' )
				? new Promise( ( resolve ) => {
						resolveBaseline = resolve;
					} )
				: Promise.resolve(
						listResponse( [] )
					) ) as unknown as typeof apiFetch );
		renderHistory();

		await screen.findByRole( 'heading', { name: 'UpdateLens is ready' } );
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Loading monitoring details…'
		);

		await act( async () => resolveBaseline( BASELINE ) );
		expect(
			await screen.findByRole( 'region', { name: 'Monitoring started' } )
		).toBeInTheDocument();
	} );
} );

describe( 'History after the first analysis', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'shows the normal History without onboarding', async () => {
		serve( { history: listResponse( [ historyItem( COMPLETED ) ] ) } );
		renderHistory();

		expect(
			await screen.findByRole( 'link', { name: /UpdateLens Fixture A/ } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { level: 2, name: 'Update History' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'heading', { name: 'UpdateLens is ready' } )
		).toBeNull();
		expect(
			screen.queryByRole( 'link', { name: 'Go to Plugins' } )
		).toBeNull();
		expect(
			screen.queryByText( 'Plugins when monitoring started' )
		).toBeNull();
	} );

	it( 'marks where monitoring began after the oldest analysis (single page)', async () => {
		serve( { history: listResponse( [ historyItem( COMPLETED ) ] ) } );
		renderHistory();

		const date = formatDateTime( '2026-10-07T22:32:00Z' )!;
		const boundary = await screen.findByText(
			`Monitoring began on ${ date }. Updates before this point were not observed by UpdateLens.`
		);
		// After the list, before the pagination.
		const list = screen.getAllByRole( 'list' )[ 0 ];
		expect(
			list.compareDocumentPosition( boundary ) &
				Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		expect(
			boundary.compareDocumentPosition(
				screen.getByRole( 'navigation' )
			) & Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		// Only the start time is requested, not the plugin list.
		expect( baselinePaths() ).toEqual( [
			'/updatelens/v1/baseline?_fields=started_at',
		] );
		// No rows are invented for earlier updates.
		expect( screen.getAllByRole( 'link' ) ).toHaveLength( 1 );
	} );

	it( 'shows the boundary only on the last History page', async () => {
		serve( {
			history: () => listResponse( [ historyItem( COMPLETED ) ], 45, 3 ),
		} );
		renderHistory( 1 );

		expect( await screen.findByText( 'Page 1 of 3' ) ).toBeInTheDocument();
		expect( screen.queryByText( /Monitoring began on/ ) ).toBeNull();
		expect( baselinePaths() ).toEqual( [] );
	} );

	it( 'shows the boundary on the last of several pages', async () => {
		serve( {
			history: () => listResponse( [ historyItem( COMPLETED ) ], 45, 3 ),
		} );
		renderHistory( 3 );

		expect(
			await screen.findByText(
				/^Monitoring began on .+\. Updates before this point were not observed by UpdateLens\.$/
			)
		).toBeInTheDocument();
	} );

	it.each( [
		[ 'unknown', { baseline: UNKNOWN_BASELINE } ],
		[
			'unavailable',
			{
				baselineError: restError(
					'updatelens_reports_unavailable',
					500
				),
			},
		],
	] )( 'leaves the boundary out when the start is %s', async ( _, api ) => {
		serve( {
			history: listResponse( [ historyItem( COMPLETED ) ] ),
			...api,
		} );
		renderHistory();

		await screen.findByRole( 'link', { name: /UpdateLens Fixture A/ } );
		await act( async () => {} );
		expect( screen.queryByText( /Monitoring began on/ ) ).toBeNull();
		expect( screen.queryByRole( 'alert' ) ).toBeNull();
	} );
} );

describe( 'First run in the app', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
		window.history.replaceState(
			null,
			'',
			'/wp-admin/admin.php?page=updatelens'
		);
	} );

	it( 'passes the Plugins URL from the page shell to the onboarding', async () => {
		serve( {} );
		render( <App pluginsUrl={ PLUGINS_URL } /> );

		expect(
			await screen.findByRole( 'link', { name: 'Go to Plugins' } )
		).toHaveAttribute( 'href', PLUGINS_URL );
		expect(
			screen.getByText(
				'See what changed when WordPress plugins update.'
			)
		).toBeInTheDocument();
	} );
} );
