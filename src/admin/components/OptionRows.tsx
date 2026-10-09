import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import type { ChangedOption, OptionState } from '../types/api';
import {
	formatBytes,
	formatBytesDelta,
	formatBytesPair,
} from '../utils/format';
import { ChangeRow, RowDetails, StatePill } from './SignalParts';

/*
 * Option rows. They show names, sizes and autoload state only: option values
 * are never stored by UpdateLens, so there are none to show.
 */

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
		<ChangeRow
			kind={ kind }
			name={ option.name }
			record={ option }
			facts={
				<>
					<span className="font-medium tabular-nums text-slate-700">
						{ formatBytes( option.size ) }
					</span>
					<StatePill muted={ ! option.is_autoloaded }>
						<span>
							{ __( 'Autoload:', 'updatelens' ) }{ ' ' }
							{ onOff( option.is_autoloaded ) }
						</span>
						{ ! autoloadRawIsRedundant(
							option.autoload,
							option.is_autoloaded
						) && <Raw>({ option.autoload })</Raw> }
					</StatePill>
				</>
			}
		/>
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
 * What changed about an option, most significant first: its value, else
 * its stored autoload setting, else only its effective autoload behavior.
 *
 * @param option Changed option.
 */
function changeText( option: ChangedOption ): string {
	if ( option.value_changed ) {
		return __( 'Value changed', 'updatelens' );
	}
	if ( option.autoload_value_changed ) {
		return __( 'Autoload setting changed', 'updatelens' );
	}
	if ( option.autoload_behavior_changed ) {
		return __( 'Autoload behavior changed', 'updatelens' );
	}
	return __( 'Value unchanged', 'updatelens' );
}

/**
 * Option present before and after whose value, size or autoload state
 * differs. One line for what changed; autoload details only if they
 * changed.
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
		<ChangeRow
			kind="changed"
			name={ option.name }
			record={ option }
			facts={
				<p className="flex flex-wrap items-baseline gap-x-2 sm:justify-end">
					<span className="font-medium text-slate-800">
						{ changeText( option ) }
					</span>
					<span aria-hidden="true" className="text-slate-300">
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
							<span className="font-medium text-slate-800">
								({ formatBytesDelta( option.size_delta ) })
							</span>
						</span>
					) }
				</p>
			}
		>
			{ autoloadChanged && (
				<RowDetails
					boxed
					rows={ [
						[
							__( 'Autoload setting', 'updatelens' ),
							option.autoload_value_changed ? (
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
							),
						],
						[
							__( 'Effective behavior', 'updatelens' ),
							option.autoload_behavior_changed ? (
								<span className="inline-flex flex-wrap items-baseline gap-x-2 rounded bg-amber-50 px-1.5 font-medium text-amber-900 ring-1 ring-amber-200">
									<span>
										{ onOff( option.before_is_autoloaded ) }
										<To />
										{ onOff( option.after_is_autoloaded ) }
									</span>
									<span className="font-normal">
										{ __(
											'(behavior changed)',
											'updatelens'
										) }
									</span>
								</span>
							) : (
								<span className="text-muted-foreground">
									{ onOff( option.after_is_autoloaded ) }{ ' ' }
									{ __( '(unchanged)', 'updatelens' ) }
								</span>
							),
						],
					] }
				/>
			) }
		</ChangeRow>
	);
}
