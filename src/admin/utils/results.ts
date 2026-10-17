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
import { SITE_CHECKS } from './siteCheckImpact';

/*
 * Wording of a report's "Update results" tab when no review rule matched:
 * one panel whose tone and words follow the API's evaluation and how
 * complete it was. Green only when every applicable observation was
 * assessed and no website check failed after the update; never "safe", and
 * never a statement about the whole website. When website checks were used
 * for the update but one was not compared, the panel says the update was not
 * fully assessed (never "Nothing flagged"): UpdateLens cannot tell how the
 * site responded afterwards, and it never says why beyond the stored
 * reasons, which the lines below the panel give.
 */

/**
 * Applicable website checks that were not compared (`not_evaluated` in the
 * API's evaluation): `all` when none of them was, else the one that was
 * not. Null when every applicable check was compared, is still pending, or
 * website checks were not part of the update (off, not recorded, another
 * origin).
 *
 * @param report Report.
 */
export function websiteGap(
	report: AnalysisReport
): 'all' | 'home' | 'rest' | null {
	const site = report.potential_impact.site_checks;
	if ( ! site || site.status === 'not_applicable' ) {
		return null;
	}
	const checks = SITE_CHECKS.filter(
		( check ) =>
			site.checks[ check ] !== undefined &&
			site.checks[ check ]?.status !== 'not_applicable'
	);
	if (
		checks.some( ( check ) => site.checks[ check ]?.status === 'pending' )
	) {
		return null;
	}
	const missing = checks.filter(
		( check ) => site.checks[ check ]?.status === 'not_evaluated'
	);
	if ( missing.length === 0 ) {
		return null;
	}
	return missing.length === checks.length && checks.length > 1
		? 'all'
		: missing[ 0 ];
}

/**
 * What a "Nothing flagged" panel covered, in its closing sentence: which
 * website checks were compared, that they were off, or that the site's
 * responses were not checked at all. Never more than the API's states say.
 *
 * @param report Report.
 */
function scopeNote( report: AnalysisReport ): string {
	const site = report.potential_impact.site_checks;
	const compared = SITE_CHECKS.filter(
		( check ) => site?.checks[ check ]?.status === 'evaluated'
	);
	if ( compared.length === 2 ) {
		return __(
			'UpdateLens checks selected patterns, and its website checks covered only the homepage and the REST API route /wp/v2/users/me, so this does not guarantee that the update had no other effects.',
			'updatelens'
		);
	}
	if ( compared.length === 1 ) {
		return compared[ 0 ] === 'home'
			? __(
					'UpdateLens checks selected patterns, and its website checks covered only the homepage, so this does not guarantee that the update had no other effects.',
					'updatelens'
				)
			: __(
					'UpdateLens checks selected patterns, and its website checks covered only the REST API route /wp/v2/users/me, so this does not guarantee that the update had no other effects.',
					'updatelens'
				);
	}
	const off =
		site !== undefined &&
		SITE_CHECKS.some(
			( check ) => site.checks[ check ]?.reason === 'disabled'
		);
	return off
		? __(
				'UpdateLens checks selected patterns only, and website checks were off for this update, so this does not guarantee that the update had no other effects.',
				'updatelens'
			)
		: __(
				'UpdateLens checks selected patterns only, and the site’s responses were not checked for this update, so this does not guarantee that the update had no other effects.',
				'updatelens'
			);
}

/**
 * The panel when website checks were used but not all were compared.
 *
 * @param gap Which applicable checks were not compared.
 */
function notFullyAssessed( gap: 'all' | 'home' | 'rest' ): ResultPanel {
	const text = {
		all: __(
			'No review rule matched the completed observations, but the website checks were not compared before and after the update. UpdateLens cannot tell whether the website responded as it did before the update.',
			'updatelens'
		),
		home: __(
			'No review rule matched the completed observations, but the homepage check was not compared before and after the update. UpdateLens cannot tell whether the homepage responded as it did before the update.',
			'updatelens'
		),
		rest: __(
			'No review rule matched the completed observations, but the REST API check was not compared before and after the update. UpdateLens cannot tell whether the REST API route /wp/v2/users/me responded as it did before the update.',
			'updatelens'
		),
	}[ gap ];
	return {
		tone: 'neutral',
		icon: 'info',
		title: __( 'Not fully assessed', 'updatelens' ),
		text,
		note: __(
			'Open the homepage and WordPress admin yourself to check that the site works.',
			'updatelens'
		),
	};
}

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
			const gap = websiteGap( report );
			if ( gap !== null ) {
				return notFullyAssessed( gap );
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
				note: scopeNote( report ),
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
