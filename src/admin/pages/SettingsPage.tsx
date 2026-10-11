import { __ } from '@wordpress/i18n';
import {
	useCallback,
	useEffect,
	useId,
	useRef,
	useState,
	type FormEvent,
	type ReactNode,
} from 'react';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

import type { ApiError } from '../api/errors';
import { getSettings, saveSiteChecks } from '../api/settings';
import { Icon } from '../components/Icon';
import { LoadError } from '../components/LoadError';
import { useRequest } from '../hooks/use-request';
import type { SiteCheckSettings } from '../types/api';
import type { Tone } from '../utils/labels';
import { TONE_BADGE, TONE_DOT } from '../utils/tone';

/** Outcome of the last save, until the setting is changed again. */
type SaveState =
	| { status: 'idle' }
	| { status: 'saving' }
	| { status: 'saved' }
	| { status: 'error'; error: ApiError };

/**
 * UpdateLens settings: the one optional feature, site checks. Shows the
 * value the server stored (and whether the site's configuration keeps
 * checks off), changes it only with "Save settings", and nothing here
 * starts a check.
 */
export function SettingsPage() {
	const [ request, retry ] = useRequest( getSettings );

	return (
		<section
			aria-labelledby="updatelens-settings-title"
			className="space-y-4"
		>
			<div className="space-y-1">
				<h2
					id="updatelens-settings-title"
					className="text-lg font-semibold"
				>
					{ __( 'Settings', 'updatelens' ) }
				</h2>
				<p className="text-sm text-muted-foreground">
					{ __(
						'Update analysis needs no setup. Site checks are optional.',
						'updatelens'
					) }
				</p>
			</div>

			{ request.status === 'loading' && <SettingsSkeleton /> }

			{ request.status === 'error' && (
				<LoadError
					error={ request.error }
					message={ __(
						"Settings couldn't be loaded. Try again.",
						'updatelens'
					) }
					forbiddenMessage={ __(
						"You don't have permission to change UpdateLens settings.",
						'updatelens'
					) }
					onRetry={ retry }
				/>
			) }

			{ request.status === 'ready' && (
				<SiteChecksSection initial={ request.data.site_checks } />
			) }
		</section>
	);
}

/**
 * The site check opt-in: a checkbox saved with "Save settings".
 *
 * @param props         Props.
 * @param props.initial Settings as loaded.
 */
function SiteChecksSection( { initial }: { initial: SiteCheckSettings } ) {
	const id = useId();
	const [ saved, setSaved ] = useState( initial );
	const [ checked, setChecked ] = useState( initial.enabled );
	const [ save, setSave ] = useState< SaveState >( { status: 'idle' } );
	const [ howOpen, setHowOpen ] = useState( false );
	// Set synchronously, so a second submit before the next render is ignored.
	const saving = useRef( false );
	const mounted = useRef( true );

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );

	const onSubmit = useCallback(
		( event: FormEvent< HTMLFormElement > ) => {
			event.preventDefault();
			if ( saving.current ) {
				return;
			}
			saving.current = true;
			setSave( { status: 'saving' } );
			saveSiteChecks( checked ).then(
				( next ) => {
					saving.current = false;
					if ( mounted.current ) {
						setSaved( next.site_checks );
						setChecked( next.site_checks.enabled );
						setSave( { status: 'saved' } );
					}
				},
				( error: ApiError ) => {
					saving.current = false;
					if ( mounted.current ) {
						setSave( { status: 'error', error } );
					}
				}
			);
		},
		[ checked ]
	);

	const isSaving = save.status === 'saving';
	const unsaved = ! isSaving && checked !== saved.enabled;
	const restricted = saved.restriction !== null;

	return (
		<section
			aria-labelledby={ `${ id }-title` }
			className="rounded-lg border bg-card"
		>
			<form
				onSubmit={ onSubmit }
				noValidate
				className="space-y-4 p-4 sm:p-6"
			>
				<div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
					<h3
						id={ `${ id }-title` }
						className="text-base font-semibold"
					>
						{ __( 'Site checks', 'updatelens' ) }
					</h3>
					<StateBadge settings={ saved } />
				</div>

				<div className="flex items-start gap-3">
					<input
						id={ `${ id }-enabled` }
						type="checkbox"
						checked={ checked }
						aria-describedby={ `${ id }-description` }
						onChange={ ( event ) => {
							// The value being saved stays what the screen shows.
							if ( saving.current ) {
								return;
							}
							setChecked( event.currentTarget.checked );
							if ( save.status !== 'idle' ) {
								setSave( { status: 'idle' } );
							}
						} }
						// WordPress styles checkboxes; the scoped CSS reset removes their border.
						className="!mt-0.5 shrink-0 border border-[#8c8f94] checked:border-[var(--wp-admin-theme-color,#2271b1)]"
					/>
					<div className="min-w-0 space-y-1">
						<label
							htmlFor={ `${ id }-enabled` }
							className="block text-sm font-medium leading-5"
						>
							{ __(
								'Check site responses around plugin updates',
								'updatelens'
							) }
						</label>
						<p
							id={ `${ id }-description` }
							className="text-[13px] leading-5 text-slate-600"
						>
							{ __(
								'Compare your homepage and WordPress REST API responses before and after supported manual plugin updates. UpdateLens records observed changes to help you investigate possible problems.',
								'updatelens'
							) }
						</p>
					</div>
				</div>

				{ restricted && <RestrictionNote enabled={ saved.enabled } /> }

				<div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-t pt-4">
					<Button
						type="submit"
						aria-disabled={ isSaving }
						className={ cn(
							isSaving && 'cursor-progress opacity-70'
						) }
					>
						{ isSaving && (
							<Icon
								name="progress"
								className="size-4 motion-safe:animate-spin"
							/>
						) }
						{ isSaving
							? __( 'Saving…', 'updatelens' )
							: __( 'Save settings', 'updatelens' ) }
					</Button>
					{ unsaved && save.status !== 'error' && (
						<span className="text-[13px] text-slate-600">
							{ __( 'Unsaved change', 'updatelens' ) }
						</span>
					) }
					<p
						role="status"
						className={ cn(
							'text-[13px] leading-5',
							save.status !== 'saved' && 'sr-only'
						) }
					>
						{ save.status === 'saved' && (
							<SavedMessage settings={ saved } />
						) }
					</p>
				</div>

				{ save.status === 'error' && (
					<SaveError error={ save.error } />
				) }
			</form>

			<HowItWorks open={ howOpen } onToggle={ setHowOpen } />
		</section>
	);
}

/**
 * The stored state as a badge: On, Off, or "Off for this site" when the
 * administrator turned site checks on but the site's configuration keeps
 * them off. Turned off by the administrator, it is the plain "Off".
 *
 * @param props          Props.
 * @param props.settings Stored settings.
 */
function StateBadge( { settings }: { settings: SiteCheckSettings } ) {
	let tone: Tone = 'neutral';
	let label: string = __( 'Off', 'updatelens' );
	if ( settings.enabled && settings.restriction !== null ) {
		tone = 'caution';
		label = __( 'Off for this site', 'updatelens' );
	} else if ( settings.effective ) {
		tone = 'positive';
		label = __( 'On', 'updatelens' );
	}

	return (
		<p
			className={ cn(
				'inline-flex shrink-0 items-center gap-1.5 rounded-full border py-0.5 pl-1.5 pr-2 text-xs font-medium',
				TONE_BADGE[ tone ]
			) }
		>
			<span
				aria-hidden="true"
				className={ cn( 'size-1.5 rounded-full', TONE_DOT[ tone ] ) }
			/>
			<span className="sr-only">
				{ __( 'Saved setting:', 'updatelens' ) }{ ' ' }
			</span>
			{ label }
		</p>
	);
}

/**
 * The site's configuration keeps site checks off whatever is saved. With
 * the preference on, an amber note says no checks run; with it off (the
 * administrator's own choice), a quiet line keeps the restriction findable.
 *
 * @param props         Props.
 * @param props.enabled Whether the saved preference is on.
 */
function RestrictionNote( { enabled }: { enabled: boolean } ) {
	const filter = (
		<code
			className={ cn(
				'rounded px-1 font-mono text-xs [overflow-wrap:anywhere]',
				enabled ? 'bg-amber-100/70' : 'bg-slate-100'
			) }
		>
			updatelens_site_checks_enabled
		</code>
	);
	if ( ! enabled ) {
		return (
			<div className="flex items-start gap-2 text-[13px] leading-5 text-slate-600">
				<Icon name="info" className="mt-px size-4 text-slate-500" />
				<p className="min-w-0">
					{ __(
						"This site's configuration also keeps site checks off, so turning them on here would have no effect until that changes.",
						'updatelens'
					) }{ ' ' }
					{ __( 'Set by the filter:', 'updatelens' ) } { filter }
				</p>
			</div>
		);
	}

	return (
		<div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-[13px] leading-5 text-amber-950">
			<Icon name="caution" className="mt-px size-4 text-amber-600" />
			<div className="min-w-0 space-y-1">
				<p>
					{ __(
						"Your saved setting is on, but this site's configuration keeps site checks off, so none run. The saved setting applies if that configuration changes.",
						'updatelens'
					) }
				</p>
				<p className="text-amber-900">
					{ __( 'Set by the filter:', 'updatelens' ) } { filter }
				</p>
			</div>
		</div>
	);
}

/**
 * Confirmation of a save, worded from the stored state.
 *
 * @param props          Props.
 * @param props.settings Stored settings.
 */
function SavedMessage( { settings }: { settings: SiteCheckSettings } ) {
	let text: string = __(
		'Settings saved. Site checks are off. Existing reports keep their site check results.',
		'updatelens'
	);
	if ( settings.enabled && settings.restriction !== null ) {
		text = __(
			"Settings saved. Site checks stay off because of this site's configuration.",
			'updatelens'
		);
	} else if ( settings.effective ) {
		text = __(
			'Settings saved. Site checks are on. They start the next time Plugins or Updates is opened while plugin updates are available.',
			'updatelens'
		);
	}

	return (
		<span className="inline-flex items-start gap-1.5 text-slate-700">
			<Icon name="check" className="mt-px size-4 text-emerald-600" />
			<span>{ text }</span>
		</span>
	);
}

/**
 * A failed save. Server messages are never shown.
 *
 * @param props       Props.
 * @param props.error Failure.
 */
function SaveError( { error }: { error: ApiError } ) {
	return (
		<div
			role="alert"
			className="flex items-start gap-2 rounded-md border border-rose-200 bg-rose-50 px-3 py-2.5 text-[13px] leading-5 text-rose-900"
		>
			<Icon name="failure" className="mt-px size-4 text-rose-600" />
			<p className="min-w-0">
				{ error.kind === 'forbidden'
					? __(
							"You don't have permission to change UpdateLens settings.",
							'updatelens'
						)
					: __(
							"Settings couldn't be saved. The setting shown as saved is unchanged. Try again.",
							'updatelens'
						) }
			</p>
		</div>
	);
}

/**
 * What site checks do, when, and what to know, behind a disclosure.
 *
 * @param props          Props.
 * @param props.open     Whether it is open.
 * @param props.onToggle Called with the new state.
 */
function HowItWorks( {
	open,
	onToggle,
}: {
	open: boolean;
	onToggle: ( open: boolean ) => void;
} ) {
	return (
		<details
			open={ open }
			onToggle={ ( event ) => {
				if ( event.currentTarget.open !== open ) {
					onToggle( event.currentTarget.open );
				}
			} }
			className="group border-t px-4 py-3 sm:px-6"
		>
			<summary className="-mx-1 inline-flex cursor-pointer select-none list-none items-center gap-1.5 rounded px-1 py-1 text-sm font-medium text-slate-700 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring [&::-webkit-details-marker]:hidden">
				<Icon
					name="chevron-down"
					className="size-4 -rotate-90 text-slate-500 transition-transform group-open:rotate-0"
				/>
				{ __( 'How site checks work', 'updatelens' ) }
			</summary>
			<div className="mt-3 grid gap-5 pb-2 text-[13px] leading-5 text-slate-700 md:grid-cols-3 md:gap-6">
				<HowGroup title={ __( 'What is checked', 'updatelens' ) }>
					<li>
						<strong className="font-medium text-slate-900">
							{ __( 'Homepage:', 'updatelens' ) }
						</strong>{ ' ' }
						{ __(
							'a request as an anonymous visitor.',
							'updatelens'
						) }
					</li>
					<li>
						<strong className="font-medium text-slate-900">
							{ __( 'REST API:', 'updatelens' ) }
						</strong>{ ' ' }
						{ __(
							'a signed-in request to a standard WordPress endpoint.',
							'updatelens'
						) }
					</li>
					<li>
						{ __(
							'A request that does not pass is repeated once.',
							'updatelens'
						) }
					</li>
				</HowGroup>
				<HowGroup title={ __( 'When checks run', 'updatelens' ) }>
					<li>
						{ __(
							'Earlier checks: when an administrator who can update plugins opens Plugins or Updates while plugin updates are available.',
							'updatelens'
						) }
					</li>
					<li>
						{ __(
							'Later checks: in the same browser tab, right after a successful update of a single plugin with “Update now”, while the tab stays open and the site can record the result.',
							'updatelens'
						) }
					</li>
					<li>
						{ __(
							'Plugin updates never wait for a site check.',
							'updatelens'
						) }
					</li>
				</HowGroup>
				<HowGroup title={ __( 'What to know', 'updatelens' ) }>
					<li>
						{ __(
							'Results are observations, not proof that an updated plugin caused a problem.',
							'updatelens'
						) }
					</li>
					<li>
						{ __(
							'Cached pages, login or firewall restrictions and browser differences can prevent a comparison.',
							'updatelens'
						) }
					</li>
					<li>
						{ __(
							'The extra requests to your site may appear in hosting, security and analytics logs.',
							'updatelens'
						) }
					</li>
					<li>
						{ __(
							'Only response facts, such as the HTTP status and timing, are stored in your WordPress database: never response bodies, cookies or credentials. Nothing is sent to an UpdateLens service.',
							'updatelens'
						) }
					</li>
					<li>
						{ __(
							"Site checks don't test your whole site and are not uptime monitoring.",
							'updatelens'
						) }
					</li>
				</HowGroup>
			</div>
		</details>
	);
}

/**
 * One titled list of the explanation.
 *
 * @param props          Props.
 * @param props.title    Title.
 * @param props.children List items.
 */
function HowGroup( {
	title,
	children,
}: {
	title: string;
	children: ReactNode;
} ) {
	const id = useId();
	return (
		<div className="space-y-2">
			<h4 id={ id } className="text-[13px] font-semibold text-slate-900">
				{ title }
			</h4>
			<ul
				aria-labelledby={ id }
				className="list-disc space-y-1.5 pl-4 marker:text-slate-400"
			>
				{ children }
			</ul>
		</div>
	);
}

function SettingsSkeleton() {
	return (
		<div
			className="space-y-4 rounded-lg border bg-card p-4 sm:p-6"
			aria-busy="true"
		>
			<p className="sr-only">
				{ __( 'Loading settings…', 'updatelens' ) }
			</p>
			<Skeleton className="h-5 w-32" />
			<Skeleton className="h-4 w-3/4" />
			<Skeleton className="h-9 w-32" />
		</div>
	);
}
