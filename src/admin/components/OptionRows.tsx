import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

import type { ChangedOption, OptionState } from '../types/api';
import {
	formatBytes,
	formatBytesDelta,
	formatBytesPair,
} from '../utils/format';

/*
 * Option rows. They show names, sizes and autoload state only: option values
 * are never stored by UpdateLens, so there are none to show.
 */

function OptionName( { name }: { name: string } ) {
	return (
		<code className="m-0 block select-text break-all bg-transparent p-0 font-mono text-[13px] text-foreground">
			{ name }
		</code>
	);
}

function onOff( autoloaded: boolean ) {
	return autoloaded ? __( 'On', 'updatelens' ) : __( 'Off', 'updatelens' );
}

/**
 * Whether the stored autoload value only repeats the On/Off label: the
 * explicit values (`on`/`yes`, `off`/`no`). Others (`auto`, `auto-on`,
 * `auto-off`) say WordPress decided, so they stay visible.
 *
 * @param raw        Stored autoload value.
 * @param autoloaded Effective autoload behavior.
 */
function autoloadRawIsRedundant( raw: string, autoloaded: boolean ) {
	return ( autoloaded ? [ 'on', 'yes' ] : [ 'off', 'no' ] ).includes( raw );
}

function Raw( { children }: { children: ReactNode } ) {
	return (
		<span className="font-mono text-xs text-muted-foreground">
			{ children }
		</span>
	);
}

/**
 * Added option (state after) or removed option (state before).
 *
 * @param props        Props.
 * @param props.option Option state.
 * @param props.kind   Added or removed.
 */
export function OptionStateRow( {
	option,
	kind,
}: {
	option: OptionState;
	kind: 'added' | 'removed';
} ) {
	return (
		<li
			className={ cn(
				'grid gap-1 border-l-2 px-4 py-2.5 sm:grid-cols-[minmax(0,1fr)_auto] sm:gap-4',
				kind === 'added' ? 'border-l-emerald-400' : 'border-l-rose-300'
			) }
		>
			<OptionName name={ option.name } />
			<div className="flex flex-wrap items-baseline gap-x-3 text-sm sm:justify-end">
				<span className="tabular-nums">
					{ formatBytes( option.size ) }
				</span>
				<span>
					{ __( 'Autoload:', 'updatelens' ) }{ ' ' }
					{ onOff( option.is_autoloaded ) }
				</span>
				{ ! autoloadRawIsRedundant(
					option.autoload,
					option.is_autoloaded
				) && <Raw>({ option.autoload })</Raw> }
			</div>
		</li>
	);
}

function To() {
	return (
		<>
			{ ' ' }
			<span aria-hidden="true">→</span>
			<span className="sr-only">{ __( 'to', 'updatelens' ) }</span>{ ' ' }
		</>
	);
}

/**
 * Option present before and after whose value, size or autoload state
 * differs. One line for the value; autoload details only if they changed.
 *
 * @param props        Props.
 * @param props.option Changed option.
 */
export function ChangedOptionRow( { option }: { option: ChangedOption } ) {
	const [ before, after ] = formatBytesPair(
		option.before_size,
		option.after_size
	);
	const autoloadChanged =
		option.autoload_value_changed || option.autoload_behavior_changed;

	return (
		<li className="space-y-1.5 border-l-2 border-l-sky-300 px-4 py-2.5">
			<OptionName name={ option.name } />
			<p className="flex flex-wrap items-baseline gap-x-2 text-sm">
				<span>
					{ option.value_changed
						? __( 'Value changed', 'updatelens' )
						: __( 'Value unchanged', 'updatelens' ) }
				</span>
				<span aria-hidden="true" className="text-muted-foreground">
					·
				</span>
				{ option.size_delta === 0 ? (
					<span className="tabular-nums text-muted-foreground">
						{ after }
					</span>
				) : (
					<span className="tabular-nums">
						<span className="text-muted-foreground">
							{ before }
							<To />
							{ after }
						</span>{ ' ' }
						<span className="font-medium">
							({ formatBytesDelta( option.size_delta ) })
						</span>
					</span>
				) }
			</p>
			{ autoloadChanged && (
				<dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[auto_minmax(0,1fr)]">
					<dt className="text-muted-foreground">
						{ __( 'Autoload setting', 'updatelens' ) }
					</dt>
					<dd>
						{ option.autoload_value_changed ? (
							<Raw>
								{ option.before_autoload }
								<To />
								{ option.after_autoload }
							</Raw>
						) : (
							<>
								<Raw>{ option.after_autoload }</Raw>{ ' ' }
								<span className="text-muted-foreground">
									{ __( '(unchanged)', 'updatelens' ) }
								</span>
							</>
						) }
					</dd>

					<dt className="text-muted-foreground">
						{ __( 'Autoload behavior', 'updatelens' ) }
					</dt>
					<dd>
						{ option.autoload_behavior_changed ? (
							<span className="inline-flex flex-wrap items-baseline gap-x-2 rounded bg-amber-50 px-1.5 py-0.5 font-medium text-amber-900 ring-1 ring-amber-200">
								<span>
									{ onOff( option.before_is_autoloaded ) }
									<To />
									{ onOff( option.after_is_autoloaded ) }
								</span>
								<span className="font-normal">
									{ __( '(behavior changed)', 'updatelens' ) }
								</span>
							</span>
						) : (
							<span className="text-muted-foreground">
								{ onOff( option.after_is_autoloaded ) }{ ' ' }
								{ __( '(no effective change)', 'updatelens' ) }
							</span>
						) }
					</dd>
				</dl>
			) }
		</li>
	);
}
