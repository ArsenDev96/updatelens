import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

import type { ChangedOption, OptionState } from '../types/api';
import { formatBytes, formatBytesDelta } from '../utils/format';

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
				<Raw>({ option.autoload })</Raw>
			</div>
		</li>
	);
}

/**
 * Option present before and after whose value, size or autoload state differs.
 *
 * @param props        Props.
 * @param props.option Changed option.
 */
export function ChangedOptionRow( { option }: { option: ChangedOption } ) {
	return (
		<li className="space-y-2 border-l-2 border-l-sky-300 px-4 py-2.5">
			<OptionName name={ option.name } />
			<dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[auto_minmax(0,1fr)]">
				<dt className="text-muted-foreground">
					{ __( 'Value', 'updatelens' ) }
				</dt>
				<dd className="flex flex-wrap items-baseline gap-x-2">
					<span>
						{ option.value_changed
							? __( 'Value changed', 'updatelens' )
							: __( 'Value unchanged', 'updatelens' ) }
					</span>
					<span className="tabular-nums text-muted-foreground">
						{ formatBytes( option.before_size ) }{ ' ' }
						<span aria-hidden="true">→</span>
						<span className="sr-only">
							{ __( 'to', 'updatelens' ) }
						</span>{ ' ' }
						{ formatBytes( option.after_size ) }
					</span>
					{ option.size_delta !== 0 && (
						<span className="font-medium tabular-nums">
							{ formatBytesDelta( option.size_delta ) }
						</span>
					) }
				</dd>

				<dt className="text-muted-foreground">
					{ __( 'Autoload setting', 'updatelens' ) }
				</dt>
				<dd>
					{ option.autoload_value_changed ? (
						<Raw>
							{ option.before_autoload }{ ' ' }
							<span aria-hidden="true">→</span>
							<span className="sr-only">
								{ __( 'to', 'updatelens' ) }
							</span>{ ' ' }
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
							{ onOff( option.before_is_autoloaded ) }{ ' ' }
							<span aria-hidden="true">→</span>
							<span className="sr-only">
								{ __( 'to', 'updatelens' ) }
							</span>{ ' ' }
							{ onOff( option.after_is_autoloaded ) }
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
		</li>
	);
}
