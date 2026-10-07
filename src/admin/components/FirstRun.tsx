import { _n, __, sprintf } from '@wordpress/i18n';
import type { Ref } from 'react';

import { buttonClassName } from '@/components/ui/button-class-name';
import { Skeleton } from '@/components/ui/skeleton';

import { getBaseline } from '../api/baseline';
import { useRequest } from '../hooks/use-request';
import type { MonitoringBaseline } from '../types/api';
import { formatCount, formatDateTime } from '../utils/format';
import { BaselinePlugins } from './BaselinePlugins';

interface FirstRunProps {
	/** Plugins screen URL; empty if the user cannot open it. */
	pluginsUrl: string;
	headingRef: Ref< HTMLHeadingElement >;
}

/**
 * Before the first analysis: what to do, what UpdateLens watches, and when
 * monitoring started. There is nothing to start manually.
 *
 * @param props            Props.
 * @param props.pluginsUrl Plugins screen URL.
 * @param props.headingRef Ref of the heading (focus after navigation).
 */
export function FirstRun( { pluginsUrl, headingRef }: FirstRunProps ) {
	const steps = [
		{
			title: __( 'Update a plugin', 'updatelens' ),
			text: __(
				'Update a plugin from the Plugins screen as usual.',
				'updatelens'
			),
		},
		{
			title: __( 'UpdateLens observes', 'updatelens' ),
			text: __(
				'Options, autoload data, WP-Cron and Action Scheduler are compared automatically.',
				'updatelens'
			),
		},
		{
			title: __( 'Review the report', 'updatelens' ),
			text: __(
				'Return to UpdateLens to see what changed.',
				'updatelens'
			),
		},
	];

	return (
		<section
			aria-labelledby="updatelens-first-run-title"
			className="space-y-5"
		>
			<div className="space-y-5 rounded-lg border bg-card p-5 sm:p-6">
				<div className="space-y-1">
					<h2
						id="updatelens-first-run-title"
						ref={ headingRef }
						tabIndex={ -1 }
						className="text-lg font-semibold outline-none"
					>
						{ __( 'UpdateLens is ready', 'updatelens' ) }
					</h2>
					<p className="text-sm text-muted-foreground">
						{ __(
							'Update one plugin normally from WordPress admin. UpdateLens will observe what changes during the update and shortly afterward.',
							'updatelens'
						) }
					</p>
				</div>

				<ol
					aria-label={ __( 'How UpdateLens works', 'updatelens' ) }
					className="grid gap-3 md:grid-cols-3"
				>
					{ steps.map( ( step, index ) => (
						<li
							key={ step.title }
							className="flex gap-3 rounded-md border bg-muted/40 p-4"
						>
							<span
								aria-hidden="true"
								className="flex size-6 shrink-0 items-center justify-center rounded-full border bg-card text-xs font-semibold text-primary"
							>
								{ index + 1 }
							</span>
							<div className="min-w-0 space-y-1">
								<h3 className="text-sm font-semibold">
									{ step.title }
								</h3>
								<p className="text-sm text-muted-foreground">
									{ step.text }
								</p>
							</div>
						</li>
					) ) }
				</ol>

				<div className="space-y-2">
					<div className="flex flex-wrap items-center gap-x-4 gap-y-2">
						{ pluginsUrl && (
							<a
								href={ pluginsUrl }
								className={ buttonClassName(
									'default',
									'default',
									'no-underline hover:text-primary-foreground focus:text-primary-foreground'
								) }
							>
								{ __( 'Go to Plugins', 'updatelens' ) }
							</a>
						) }
						<p className="text-sm">
							{ __(
								'For the clearest report, update one plugin at a time.',
								'updatelens'
							) }
						</p>
					</div>
					<p className="text-xs text-muted-foreground">
						{ __(
							'UpdateLens currently analyzes supported single-plugin updates made from WordPress admin.',
							'updatelens'
						) }
					</p>
				</div>
			</div>

			<Watches />

			<MonitoringStart />
		</section>
	);
}

function Watches() {
	const signals = [
		{
			name: __( 'Options & autoload', 'updatelens' ),
			text: __(
				'Value changes, size and autoload behavior',
				'updatelens'
			),
		},
		{
			name: __( 'WP-Cron', 'updatelens' ),
			text: __( 'Scheduled WordPress events', 'updatelens' ),
		},
		{
			name: __( 'Action Scheduler', 'updatelens' ),
			text: __( 'Active background actions and schedules', 'updatelens' ),
		},
	];

	return (
		<section
			aria-labelledby="updatelens-watches-title"
			className="space-y-3"
		>
			<h3 id="updatelens-watches-title" className="text-sm font-semibold">
				{ __( 'What UpdateLens watches', 'updatelens' ) }
			</h3>
			<ul className="grid gap-3 md:grid-cols-3">
				{ signals.map( ( signal ) => (
					<li
						key={ signal.name }
						className="space-y-1 rounded-lg border bg-card px-4 py-3"
					>
						<p className="text-sm font-medium">{ signal.name }</p>
						<p className="text-sm text-muted-foreground">
							{ signal.text }
						</p>
					</li>
				) ) }
			</ul>
			<div className="space-y-1 text-sm">
				<p>
					<span className="font-medium">
						{ __( 'Private by design.', 'updatelens' ) }
					</span>{ ' ' }
					{ __(
						'Raw option values and job arguments are not stored.',
						'updatelens'
					) }
				</p>
				<p className="text-muted-foreground">
					{ __(
						'Reports show observed changes. WordPress core or other plugins may also perform background work during the same observation window.',
						'updatelens'
					) }
				</p>
			</div>
		</section>
	);
}

/**
 * When monitoring started and the plugins installed then. Supportive data:
 * if it is unavailable, a fallback sentence keeps the page usable.
 */
function MonitoringStart() {
	const [ request ] = useRequest( getBaseline );

	if ( request.status === 'loading' ) {
		return (
			<div aria-busy="true" className="space-y-2">
				<span role="status" className="sr-only">
					{ __( 'Loading monitoring details…', 'updatelens' ) }
				</span>
				<Skeleton className="h-4 w-40" />
				<Skeleton className="h-3 w-72 max-w-full" />
			</div>
		);
	}

	const baseline: MonitoringBaseline | null =
		request.status === 'ready' ? request.data : null;
	const date = formatDateTime( baseline?.started_at ?? null );

	if ( ! baseline?.started_at || ! date ) {
		return (
			<p className="text-sm text-muted-foreground">
				{ __(
					'Monitoring begins with future supported plugin updates.',
					'updatelens'
				) }
			</p>
		);
	}

	return (
		<section
			aria-labelledby="updatelens-monitoring-title"
			className="space-y-4 rounded-lg border bg-card p-5 sm:p-6"
		>
			<div className="space-y-1">
				<h3
					id="updatelens-monitoring-title"
					className="text-sm font-semibold"
				>
					{ __( 'Monitoring started', 'updatelens' ) }
				</h3>
				<p className="text-sm">
					<time dateTime={ baseline.started_at }>{ date }</time>
				</p>
				<p className="text-sm text-muted-foreground">
					{ __(
						'UpdateLens starts observing plugin updates from this point forward. Earlier updates were not observed.',
						'updatelens'
					) }
				</p>
				{ baseline.plugins && baseline.plugin_count !== null && (
					<p className="text-sm text-muted-foreground">
						{ baseline.plugin_count === 0
							? __(
									'No other plugins were installed when monitoring began.',
									'updatelens'
								)
							: sprintf(
									/* translators: %s: number of plugins. */
									_n(
										'Besides UpdateLens, %s plugin was installed when monitoring began.',
										'Besides UpdateLens, %s plugins were installed when monitoring began.',
										baseline.plugin_count,
										'updatelens'
									),
									formatCount( baseline.plugin_count )
								) }
					</p>
				) }
			</div>
			{ baseline.plugins && baseline.plugins.length > 0 && (
				<BaselinePlugins plugins={ baseline.plugins } />
			) }
		</section>
	);
}
