import { setLocaleData } from '@wordpress/i18n';
import { describe, expect, it } from 'vitest';

import type { HistoryPhase } from '@/admin/types/api';
import {
	cronChangeCount,
	historyPhaseState,
	optionsChangeCount,
	phaseChangeCounts,
	reportChangeCounts,
} from '@/admin/utils/changes';
import { formatBytesPair } from '@/admin/utils/format';
import {
	changeCountNoun,
	changeIndicator,
	defaultPhase,
	historyPhaseText,
	observedChangesText,
	signalStatusText,
} from '@/admin/utils/labels';

import {
	CANCELLED,
	CRON_CHANGES_ONLY,
	ELEMENTOR,
	MIXED_WOOCOMMERCE,
	RANK_MATH,
	WORDFENCE,
} from './dogfood-fixtures';
import {
	COMPLETED,
	CRON_POST,
	EXPIRED,
	FAILED,
	FINAL,
	MALFORMED_CRON,
	PARTIAL_CRON,
} from './fixtures';

describe( 'change counts', () => {
	it( 'counts option records, not summary deltas or changed fields', () => {
		// 2 added + 1 removed + 3 changed, whatever the byte and count deltas.
		expect( optionsChangeCount( FINAL ) ).toBe( 6 );
		// A changed option with value and autoload changes still counts once.
		expect(
			optionsChangeCount( MIXED_WOOCOMMERCE.phases.final.options )
		).toBe( 5 );
	} );

	it( 'counts Cron records including rescheduled', () => {
		// 1 added + 1 removed + 2 rescheduled.
		expect( cronChangeCount( CRON_POST ) ).toBe( 4 );
	} );

	it( 'is null for an unavailable signal, never zero', () => {
		expect( optionsChangeCount( FAILED.phases.final.options ) ).toBeNull();
		expect(
			cronChangeCount( MALFORMED_CRON.phases.final.cron )
		).toBeNull();
	} );

	it( 'sums the available signals of a phase', () => {
		expect( phaseChangeCounts( COMPLETED.phases.final ) ).toEqual( {
			options: 6,
			cron: 5,
			action_scheduler: null,
			total: 11,
		} );
		// Options only.
		expect( phaseChangeCounts( ELEMENTOR.phases.final ) ).toEqual( {
			options: 5,
			cron: 0,
			action_scheduler: null,
			total: 5,
		} );
		// Cron only.
		expect( phaseChangeCounts( CRON_CHANGES_ONLY.phases.final ) ).toEqual( {
			options: 0,
			cron: 1,
			action_scheduler: null,
			total: 1,
		} );
		// Captured without changes.
		expect( phaseChangeCounts( RANK_MATH.phases.final ) ).toEqual( {
			options: 0,
			cron: 0,
			action_scheduler: null,
			total: 0,
		} );
		// One signal unavailable: the phase still counts the other.
		expect( phaseChangeCounts( MALFORMED_CRON.phases.final ) ).toEqual( {
			options: 6,
			cron: null,
			action_scheduler: null,
			total: 6,
		} );
		// Both unavailable.
		expect( phaseChangeCounts( FAILED.phases.final ) ).toEqual( {
			options: null,
			cron: null,
			action_scheduler: null,
			total: null,
		} );
	} );

	it( 'computes every phase of a report', () => {
		const counts = reportChangeCounts( EXPIRED.phases );
		expect( counts.during_update.total ).toBe( 2 );
		expect( counts.post_update.total ).toBeNull();
		expect( counts.final.total ).toBeNull();
	} );
} );

describe( 'default phase', () => {
	it( 'prefers Net result when it has changes', () => {
		expect( defaultPhase( COMPLETED.phases ) ).toBe( 'final' );
		expect( defaultPhase( WORDFENCE.phases ) ).toBe( 'final' );
	} );

	it( 'does not open an empty Net result when another phase has changes', () => {
		// Changes during and after the update cancel out in Net result.
		expect( defaultPhase( CANCELLED.phases ) ).toBe( 'post_update' );
		const duringOnly = {
			...RANK_MATH.phases,
			during_update: CANCELLED.phases.during_update,
		};
		expect( defaultPhase( duringOnly ) ).toBe( 'during_update' );
	} );

	it( 'falls back to available phases when nothing changed', () => {
		expect( defaultPhase( RANK_MATH.phases ) ).toBe( 'final' );
		// Net and After unavailable: During update, even without changes.
		expect(
			defaultPhase( {
				during_update: RANK_MATH.phases.during_update,
				post_update: EXPIRED.phases.post_update,
				final: EXPIRED.phases.final,
			} )
		).toBe( 'during_update' );
		expect( defaultPhase( FAILED.phases ) ).toBeNull();
	} );
} );

describe( 'phase and signal wording', () => {
	it( 'states the observed total of a phase', () => {
		expect( observedChangesText( 1 ) ).toBe( '1 observed change' );
		expect( observedChangesText( 16 ) ).toBe( '16 observed changes' );
		expect( observedChangesText( 0 ) ).toBe(
			'No tracked changes observed during this phase.'
		);
		expect( observedChangesText( null ) ).toBe(
			'Not available for this phase'
		);
	} );

	it( 'states each signal in text, never as a bare color or number', () => {
		expect( signalStatusText( 3 ) ).toBe( '3 changes' );
		expect( signalStatusText( 1 ) ).toBe( '1 change' );
		expect( signalStatusText( 0 ) ).toBe( 'No changes' );
		expect( signalStatusText( null ) ).toBe( 'Not available' );
	} );

	it( 'keeps the phase total equal to the sum of its available signals', () => {
		for ( const report of [
			COMPLETED,
			CRON_CHANGES_ONLY,
			RANK_MATH,
			PARTIAL_CRON,
		] ) {
			for ( const counts of Object.values(
				reportChangeCounts( report.phases )
			) ) {
				const available = [
					counts.options,
					counts.cron,
					counts.action_scheduler,
				].filter( ( count ): count is number => count !== null );
				expect( counts.total ).toBe(
					available.length
						? available.reduce( ( sum, n ) => sum + n, 0 )
						: null
				);
			}
		}
	} );
} );

describe( 'change wording', () => {
	it( 'says how many changes, or none', () => {
		expect( signalStatusText( 0 ) ).toBe( 'No changes' );
		expect( signalStatusText( 1 ) ).toBe( '1 change' );
		expect( signalStatusText( 4 ) ).toBe( '4 changes' );
		expect( signalStatusText( null ) ).toBe( 'Not available' );
	} );

	it( 'names the changes in phase indicators', () => {
		expect( changeCountNoun( 0 ) ).toBe( '0 changes' );
		expect( changeCountNoun( 1 ) ).toBe( '1 change' );
		expect( changeCountNoun( 2 ) ).toBe( '2 changes' );
		expect( changeIndicator( 1 ) ).toEqual( {
			text: '1 change',
			tone: 'changes',
		} );
		expect( changeIndicator( 0 ) ).toEqual( {
			text: '0 changes',
			tone: 'none',
		} );
		expect( changeIndicator( null ) ).toEqual( {
			text: 'Not available',
			tone: 'unavailable',
		} );
	} );

	it( 'takes plural forms from WordPress translations', () => {
		// Three plural forms: the rule comes from the locale, not from English.
		setLocaleData(
			{
				'': {
					domain: 'updatelens',
					plural_forms:
						'nplurals=3; plural=(n==1 ? 0 : n>=2 && n<=4 ? 1 : 2);',
				},
				'%s change': [ '%s xone', '%s xfew', '%s xmany' ],
			},
			'updatelens'
		);

		expect( changeCountNoun( 0 ) ).toBe( '0 xmany' );
		expect( changeCountNoun( 1 ) ).toBe( '1 xone' );
		expect( changeCountNoun( 2 ) ).toBe( '2 xfew' );
		expect( changeCountNoun( 5 ) ).toBe( '5 xmany' );
	} );
} );

describe( 'history phase state', () => {
	const signal = ( recorded: boolean, has_changes: boolean | null ) => ( {
		recorded,
		has_changes,
	} );
	const state = (
		options: HistoryPhase[ 'options' ],
		cron = options,
		action_scheduler = signal( false, null )
	) => historyPhaseState( { options, cron, action_scheduler } );

	it( 'separates recorded-with-changes from recorded-without', () => {
		expect( state( signal( true, true ), signal( true, false ) ) ).toBe(
			'changes'
		);
		expect( state( signal( true, false ), signal( true, true ) ) ).toBe(
			'changes'
		);
		expect( state( signal( true, false ) ) ).toBe( 'none' );
		expect( historyPhaseText( 'none' ) ).toBe( 'No changes' );
		expect( historyPhaseText( 'changes' ) ).toBe( 'Changes observed' );
	} );

	it( 'is unavailable only if no signal was recorded', () => {
		expect( state( signal( false, null ) ) ).toBe( 'unavailable' );
		expect( state( signal( true, false ), signal( false, null ) ) ).toBe(
			'none'
		);
		expect( historyPhaseText( 'unavailable' ) ).toBe( 'Not available' );
	} );
} );

describe( 'byte pairs', () => {
	it( 'adds precision only when different sizes would look the same', () => {
		expect( formatBytesPair( 6124, 6182, 'en-US' ) ).toEqual( [
			'5.98 KB',
			'6.04 KB',
		] );
		expect( formatBytesPair( 84, 115, 'en-US' ) ).toEqual( [
			'84 B',
			'115 B',
		] );
		expect( formatBytesPair( 6124, 6124, 'en-US' ) ).toEqual( [
			'6 KB',
			'6 KB',
		] );
		expect( formatBytesPair( 9400, 12000, 'en-US' ) ).toEqual( [
			'9.2 KB',
			'11.7 KB',
		] );
	} );
} );
