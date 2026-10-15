import { __, _n, sprintf } from '@wordpress/i18n';

import type { IconName } from '../components/Icon';
import type { AnalysisReport } from '../types/api';
import { formatCount } from './format';
import {
	impactCompleteness,
	impactDescription,
	impactHeadline,
	impactState,
} from './impact';
import type { Tone } from './labels';

/*
 * Wording of a report's "Update results" tab when no review rule matched:
 * one panel whose tone and words follow the API's evaluation and how
 * complete it was. Green only when every applicable observation was
 * assessed and no website check failed after the update; never "safe", and
 * never a statement about the whole website.
 */

/** The result panel of the Update results tab. */
export interface ResultPanel {
	tone: Tone;
	icon: IconName;
	title: string;
	/** The answer, in one sentence. */
	text: string;
	/** What it does not show, or what to expect. */
	note: string | null;
}

/**
 * The panel shown when there are no findings, from the API's states only.
 * Null when there are findings (they are listed instead).
 *
 * @param report Report.
 */
export function resultPanel( report: AnalysisReport ): ResultPanel | null {
	const impact = report.potential_impact;
	const state = impactState( impact );
	const windowSeconds = report.observation_window_seconds;
	const limit = __(
		'UpdateLens checks selected patterns only, so this does not guarantee that the update had no other effects.',
		'updatelens'
	);
	switch ( state ) {
		case 'findings':
			return null;
		case 'none': {
			const completeness = impactCompleteness( impact );
			if ( completeness === 'pending' ) {
				return {
					tone: 'progress',
					icon: 'progress',
					title: __( 'Nothing flagged so far', 'updatelens' ),
					text: __(
						'The website checks after the update have not arrived yet. Until they do, they are not counted as passed.',
						'updatelens'
					),
					note: __(
						'This report checks for them by itself while it is open.',
						'updatelens'
					),
				};
			}
			// A website check already failing before the update is not a
			// finding, but the panel must not read as a pass.
			const failing =
				impact.site_checks !== undefined &&
				Object.values( impact.site_checks.checks ).some(
					( check ) =>
						check?.state === 'failing_both' ||
						check?.change === 'redirect_then_failure'
				);
			return {
				tone:
					completeness === 'complete' && ! failing
						? 'positive'
						: 'neutral',
				icon: 'check',
				title: __( 'Nothing flagged for review', 'updatelens' ),
				text:
					completeness === 'complete'
						? __(
								'No concerning patterns matched the completed observations.',
								'updatelens'
							)
						: __(
								'No concerning patterns matched the completed observations, but not everything could be assessed.',
								'updatelens'
							),
				note: limit,
			};
		}
		case 'waiting':
			return {
				tone: 'progress',
				icon: 'progress',
				title: impactHeadline( impact, state ),
				text: impactDescription( impact, state, windowSeconds ),
				note: null,
			};
		case 'expired':
			// The status above the tabs explains it, in amber.
			return {
				tone: 'neutral',
				icon: 'info',
				title: impactHeadline( impact, state ),
				text: impactDescription( impact, state, windowSeconds ),
				note: null,
			};
		case 'unavailable':
			return {
				tone: 'neutral',
				icon: 'info',
				title: impactHeadline( impact, state ),
				text: impactDescription( impact, state, windowSeconds ),
				note: null,
			};
	}
}

/**
 * How many Net result configuration changes matched no review rule, out of
 * those assessed, from the API's exact per-record counts: "2 of 5
 * configuration changes in the Net result matched no review rule
 * (informational)." When some sources were not assessed, it says the count
 * covers the assessed ones only. Null without informational changes.
 *
 * @param report Report.
 */
export function informationalText( report: AnalysisReport ): string | null {
	const impact = report.potential_impact;
	const { informational, assessed_changes: assessed } = impact.assessment;
	if ( ! informational || ! assessed ) {
		return null;
	}
	return impact.status === 'evaluated'
		? sprintf(
				/* translators: 1: changes no review rule matched. 2: all configuration changes in the Net result. */
				_n(
					'%1$s of %2$s configuration change in the Net result matched no review rule (informational).',
					'%1$s of %2$s configuration changes in the Net result matched no review rule (informational).',
					assessed,
					'updatelens'
				),
				formatCount( informational ),
				formatCount( assessed )
			)
		: sprintf(
				/* translators: 1: changes no review rule matched. 2: configuration changes in the sources that were assessed. */
				_n(
					'%1$s of %2$s configuration change in the assessed sources of the Net result matched no review rule (informational).',
					'%1$s of %2$s configuration changes in the assessed sources of the Net result matched no review rule (informational).',
					assessed,
					'updatelens'
				),
				formatCount( informational ),
				formatCount( assessed )
			);
}
