import apiFetch from '@wordpress/api-fetch';
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import App from '@/admin/App';
import type { SiteCheckSettings } from '@/admin/types/api';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
const apiFetchMock = vi.mocked( apiFetch );

type Options = { path?: string; method?: string; data?: unknown };

const OFF: SiteCheckSettings = {
	enabled: false,
	effective: false,
	restriction: null,
};
const ON: SiteCheckSettings = {
	enabled: true,
	effective: true,
	restriction: null,
};

/** A promise settled from the test. */
function deferred< T >() {
	let resolve!: ( value: T ) => void;
	let reject!: ( reason: unknown ) => void;
	const promise = new Promise< T >( ( res, rej ) => {
		resolve = res;
		reject = rej;
	} );
	return { promise, resolve, reject };
}

/**
 * A settings endpoint that stores what is posted, like the server.
 *
 * @param stored Initial stored settings.
 * @param save   Optional handler for POSTs (default: store and answer).
 */
function serve(
	stored: SiteCheckSettings,
	save?: ( data: unknown ) => Promise< unknown >
) {
	let current = stored;
	apiFetchMock.mockImplementation( ( async ( options: Options ) => {
		if ( options.path !== '/updatelens/v1/settings' ) {
			throw new Error( `Unexpected request ${ options.path }` );
		}
		if ( options.method === 'POST' ) {
			if ( save ) {
				return save( options.data );
			}
			const enabled = (
				options.data as { site_checks: { enabled: boolean } }
			 ).site_checks.enabled;
			current = {
				enabled,
				effective: enabled && current.restriction === null,
				restriction: current.restriction,
			};
		}
		return { site_checks: current };
	} ) as unknown as typeof apiFetch );
}

function posts() {
	return apiFetchMock.mock.calls.filter(
		( [ options ] ) => ( options as Options ).method === 'POST'
	);
}

function openSettings() {
	window.history.replaceState(
		null,
		'',
		'/wp-admin/admin.php?page=updatelens-settings'
	);
	return render( <App view="settings" /> );
}

function checkbox() {
	return screen.getByRole( 'checkbox', {
		name: 'Check site responses around plugin updates',
	} );
}

function saveButton() {
	return screen.getByRole( 'button', { name: /Save settings|Saving…/ } );
}

function section() {
	return screen.getByRole( 'region', { name: /Site checks/ } );
}

/** The stored state's badge ("Saved setting: On"). */
const badge = () => within( section() ).getByText( /^Saved setting:/ );

describe( 'Settings page', () => {
	beforeEach( () => {
		apiFetchMock.mockReset();
	} );

	it( 'shows the saved value (off by default) and starts nothing', async () => {
		serve( OFF );
		const fetchSpy = vi.spyOn( window, 'fetch' );
		openSettings();

		expect(
			screen.getByRole( 'heading', { level: 2, name: 'Settings' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Loading settings…' ) ).toBeInTheDocument();
		expect( await screen.findByRole( 'checkbox' ) ).not.toBeChecked();
		expect(
			screen.getByRole( 'heading', { level: 3, name: 'Site checks' } )
		).toBeInTheDocument();
		expect( badge() ).toHaveTextContent( /^Saved setting: Off$/ );
		expect( checkbox() ).toHaveAccessibleDescription(
			'Compare your homepage and WordPress REST API responses before and after a plugin update. Each report shows the results in its “Site response checks” section.'
		);
		// The workflow, visible without opening anything.
		expect(
			within(
				screen.getByRole( 'list', { name: 'To compare an update:' } )
			)
				.getAllByRole( 'listitem' )
				.map( ( item ) => item.textContent )
		).toEqual( [
			'Open Installed Plugins and wait until UpdateLens shows “Ready to compare site responses”.',
			'Click “Update now” for the plugin there.',
			'Keep that tab open for a few seconds after “Updated!” appears, so the checks after the update can finish.',
		] );
		// Further explanations are in "How site checks work".
		for ( const point of [
			/Turning site checks on does not test your site right away\./,
			/Updates from Dashboard → Updates get no check after the update, so they are not compared\./,
			/Missing, inconclusive and unsupported checks are never counted as passed\./,
		] ) {
			expect( screen.getByText( point ) ).not.toBeVisible();
		}

		// One read of the settings; no history, reports or site check requests.
		expect( apiFetchMock ).toHaveBeenCalledTimes( 1 );
		expect( apiFetchMock ).toHaveBeenCalledWith( {
			path: '/updatelens/v1/settings',
		} );
		expect( fetchSpy ).not.toHaveBeenCalled();
		expect(
			screen.queryByRole( 'heading', { name: 'Update History' } )
		).not.toBeInTheDocument();
	} );

	it( 'shows a saved "on" preference', async () => {
		serve( ON );
		openSettings();

		expect( await screen.findByRole( 'checkbox' ) ).toBeChecked();
		expect( badge() ).toHaveTextContent( /^Saved setting: On$/ );
		// The badge names what it shows: the saved setting.
		expect(
			within( section() ).getByText( /Saved setting:/ )
		).toBeVisible();
	} );

	it( 'saves only on "Save settings", then shows the stored value', async () => {
		serve( OFF );
		openSettings();
		const user = userEvent.setup();
		await screen.findByRole( 'checkbox' );

		await user.click( checkbox() );
		expect( checkbox() ).toBeChecked();
		expect( screen.getByText( 'Unsaved change' ) ).toBeInTheDocument();
		expect( posts() ).toHaveLength( 0 );
		expect( badge() ).toHaveTextContent( /^Saved setting: Off$/ );

		await user.click( saveButton() );

		expect( posts() ).toEqual( [
			[
				{
					path: '/updatelens/v1/settings',
					method: 'POST',
					data: { site_checks: { enabled: true } },
				},
			],
		] );
		expect( await screen.findByRole( 'status' ) ).toHaveTextContent(
			'Settings saved. Site checks are on. They start the next time Plugins or Updates is opened while plugin updates are available.'
		);
		expect( badge() ).toHaveTextContent( /^Saved setting: On$/ );
		expect(
			screen.queryByText( 'Unsaved change' )
		).not.toBeInTheDocument();
		expect( saveButton() ).toHaveFocus();
	} );

	it( 'keeps the saved value after a reload', async () => {
		serve( OFF );
		const first = openSettings();
		const user = userEvent.setup();
		await screen.findByRole( 'checkbox' );
		await user.click( checkbox() );
		await user.click( saveButton() );
		await screen.findByText( /Settings saved/ );
		first.unmount();

		openSettings();
		expect( await screen.findByRole( 'checkbox' ) ).toBeChecked();
		expect( badge() ).toHaveTextContent( /^Saved setting: On$/ );
	} );

	it( 'turns site checks off', async () => {
		serve( ON );
		openSettings();
		const user = userEvent.setup();
		await screen.findByRole( 'checkbox' );

		await user.click( checkbox() );
		await user.click( saveButton() );

		expect( ( posts()[ 0 ][ 0 ] as Options ).data ).toEqual( {
			site_checks: { enabled: false },
		} );
		expect( await screen.findByRole( 'status' ) ).toHaveTextContent(
			'Settings saved. Site checks are off. Existing reports keep their site check results.'
		);
		expect( checkbox() ).not.toBeChecked();
		expect( badge() ).toHaveTextContent( /^Saved setting: Off$/ );
	} );

	it( 'shows the saving state and sends one request for repeated saves', async () => {
		const pending = deferred< unknown >();
		serve( OFF, () => pending.promise );
		openSettings();
		const user = userEvent.setup();
		await screen.findByRole( 'checkbox' );

		await user.click( checkbox() );
		await user.click( saveButton() );
		const button = saveButton();
		expect( button ).toHaveTextContent( 'Saving…' );
		expect( button ).toHaveAttribute( 'aria-disabled', 'true' );

		await user.click( button );
		await user.keyboard( '{Enter}' );
		// The value being saved cannot change underneath.
		await user.click( checkbox() );
		expect( checkbox() ).toBeChecked();
		expect( posts() ).toHaveLength( 1 );

		await act( async () => pending.resolve( { site_checks: ON } ) );
		expect( saveButton() ).toHaveTextContent( 'Save settings' );
		expect( saveButton() ).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			/Settings saved\. Site checks are on\./
		);
	} );

	it( 'reports a failed save without changing the saved state', async () => {
		let fail = true;
		serve( OFF, async ( data ) => {
			if ( fail ) {
				throw {
					code: 'updatelens_settings_not_saved',
					message: 'Server detail that is never shown',
					data: { status: 500 },
				};
			}
			return {
				site_checks: {
					...ON,
					enabled: ( data as { site_checks: { enabled: boolean } } )
						.site_checks.enabled,
				},
			};
		} );
		openSettings();
		const user = userEvent.setup();
		await screen.findByRole( 'checkbox' );

		await user.click( checkbox() );
		await user.click( saveButton() );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			"Settings couldn't be saved. The setting shown as saved is unchanged. The site's REST API returned an error (HTTP 500)."
		);
		expect( screen.queryByText( /Server detail/ ) ).not.toBeInTheDocument();
		expect( badge() ).toHaveTextContent( /^Saved setting: Off$/ );
		expect( checkbox() ).toBeChecked();
		expect( screen.getByRole( 'status' ) ).toBeEmptyDOMElement();

		fail = false;
		await user.click( saveButton() );
		expect( await screen.findByText( /Settings saved/ ) ).toBeVisible();
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		expect( badge() ).toHaveTextContent( /^Saved setting: On$/ );
	} );

	it( 'explains a refused save', async () => {
		serve( OFF, async () => {
			throw { code: 'rest_cookie_invalid_nonce', data: { status: 403 } };
		} );
		openSettings();
		const user = userEvent.setup();
		await screen.findByRole( 'checkbox' );

		await user.click( checkbox() );
		await user.click( saveButton() );

		// A refused nonce is the login session, not a missing permission.
		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			"Settings couldn't be saved. The setting shown as saved is unchanged. Your login session may have expired. Reload the page or log in again."
		);
	} );

	it( 'does not guess a state from an unexpected response', async () => {
		serve( OFF, async () => ( { site_checks: { enabled: 'yes' } } ) );
		openSettings();
		const user = userEvent.setup();
		await screen.findByRole( 'checkbox' );

		await user.click( checkbox() );
		await user.click( saveButton() );

		expect( await screen.findByRole( 'alert' ) ).toBeInTheDocument();
		expect( badge() ).toHaveTextContent( /^Saved setting: Off$/ );
	} );

	it( 'separates the saved preference from a host restriction', async () => {
		serve( { enabled: true, effective: false, restriction: 'filter' } );
		openSettings();

		expect( await screen.findByRole( 'checkbox' ) ).toBeChecked();
		expect( badge() ).toHaveTextContent(
			/^Saved setting: Off for this site$/
		);
		expect(
			within( section() ).queryByText( 'On' )
		).not.toBeInTheDocument();
		expect(
			screen.getByText(
				/Your saved setting is on, but this site's configuration keeps site checks off, so none run\./
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( 'updatelens_site_checks_enabled' )
		).toBeInTheDocument();
	} );

	it( 'shows a plain "Off" with a quiet note when the admin turned checks off and the host also keeps them off', async () => {
		serve( { enabled: false, effective: false, restriction: 'filter' } );
		openSettings();
		const user = userEvent.setup();
		await screen.findByRole( 'checkbox' );

		expect( badge() ).toHaveTextContent( /^Saved setting: Off$/ );
		expect(
			within( section() ).queryByText( 'Off for this site' )
		).not.toBeInTheDocument();
		const note = screen.getByText(
			/This site's configuration also keeps site checks off, so turning them on here would have no effect until that changes./
		);
		expect( note.closest( '.bg-amber-50' ) ).toBeNull();
		expect( note ).toHaveTextContent( 'updatelens_site_checks_enabled' );

		// Turning it on under the restriction: saved, but still not running.
		await user.click( checkbox() );
		await user.click( saveButton() );

		expect( await screen.findByRole( 'status' ) ).toHaveTextContent(
			"Settings saved. Site checks stay off because of this site's configuration."
		);
		expect( badge() ).toHaveTextContent(
			/^Saved setting: Off for this site$/
		);
		expect(
			screen
				.getByText( /Your saved setting is on, but/ )
				.closest( '.bg-amber-50' )
		).not.toBeNull();
	} );

	it.each( [
		[ 'saved off', OFF, false, 'Off', 'none' ],
		[ 'saved on', ON, true, 'On', 'none' ],
		[
			'saved on, host off',
			{ enabled: true, effective: false, restriction: 'filter' } as const,
			true,
			'Off for this site',
			'warning',
		],
		[
			'saved off, host off',
			{
				enabled: false,
				effective: false,
				restriction: 'filter',
			} as const,
			false,
			'Off',
			'quiet',
		],
	] )(
		'%s: checkbox, badge and note follow the stored state',
		async ( _name, stored, checked, label, note ) => {
			serve( stored );
			openSettings();
			await screen.findByRole( 'checkbox' );

			expect( ( checkbox() as HTMLInputElement ).checked ).toBe(
				checked
			);
			expect(
				within( section() ).getByText( /Saved setting:/ ).parentElement
			).toHaveTextContent( new RegExp( `^Saved setting: ${ label }$` ) );
			const warning = screen.queryByText(
				/Your saved setting is on, but/
			);
			const quiet = screen.queryByText( /also keeps site checks off/ );
			expect( Boolean( warning ) ).toBe( note === 'warning' );
			expect( Boolean( quiet ) ).toBe( note === 'quiet' );
			expect( section().textContent ).not.toMatch( /\bactive\b/i );
		}
	);

	it( 'shows load failures with a retry', async () => {
		let attempt = 0;
		apiFetchMock.mockImplementation( ( async () => {
			attempt++;
			if ( attempt === 1 ) {
				throw {
					code: 'updatelens_settings_unavailable',
					data: { status: 500 },
				};
			}
			return { site_checks: OFF };
		} ) as unknown as typeof apiFetch );
		openSettings();
		const user = userEvent.setup();

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			"Settings couldn't be loaded."
		);
		expect( screen.queryByRole( 'checkbox' ) ).not.toBeInTheDocument();
		await user.click( screen.getByRole( 'button', { name: 'Retry' } ) );
		expect( await screen.findByRole( 'checkbox' ) ).not.toBeChecked();
	} );

	it( 'explains missing permission when loading', async () => {
		apiFetchMock.mockRejectedValue( {
			code: 'rest_forbidden',
			data: { status: 403 },
		} );
		openSettings();

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			"You don't have permission to change UpdateLens settings."
		);
	} );

	it( 'works with the keyboard', async () => {
		serve( OFF );
		openSettings();
		const user = userEvent.setup();
		await screen.findByRole( 'checkbox' );

		await user.tab();
		expect( checkbox() ).toHaveFocus();
		await user.keyboard( ' ' );
		expect( checkbox() ).toBeChecked();
		await user.tab();
		expect( saveButton() ).toHaveFocus();
		await user.keyboard( '{Enter}' );
		expect( await screen.findByText( /Settings saved/ ) ).toBeVisible();
		expect( saveButton() ).toHaveFocus();

		await user.tab();
		const summary = screen.getByText( 'How site checks work' );
		expect( summary.closest( 'summary' ) ).toHaveFocus();
		// jsdom does not open <details> from the keyboard (checked in a real browser).
		await user.click( summary );
		expect( summary.closest( 'details' ) ).toHaveAttribute( 'open' );
		for ( const title of [
			'What is checked',
			'When checks run',
			'What to know',
		] ) {
			expect(
				screen.getByRole( 'list', { name: title } )
			).toBeInTheDocument();
		}
	} );

	it( 'explains site checks without claiming more than they do', async () => {
		serve( OFF );
		openSettings();
		await screen.findByRole( 'checkbox' );

		const text = section().textContent ?? '';
		expect( text ).toContain( 'a request as an anonymous visitor.' );
		expect( text ).toContain(
			'a signed-in request to a standard WordPress endpoint.'
		);
		expect( text ).toContain(
			'Plugin updates never wait for a site check.'
		);
		expect( text ).toContain(
			'Results are observations, not proof that an updated plugin caused a problem.'
		);
		expect( text ).toContain(
			'may appear in hosting, security and analytics logs.'
		);
		expect( text ).toContain(
			'never response bodies, cookies or credentials. Nothing is sent to an UpdateLens service.'
		);
		expect( text ).toContain(
			"Site checks don't test your whole site and are not uptime monitoring."
		);
		for ( const claim of [
			/healthy/i,
			/safe/i,
			/guarantee/i,
			/caused by/i,
			/monitors your site/i,
			/active/i,
		] ) {
			expect( text ).not.toMatch( claim );
		}
	} );
} );
