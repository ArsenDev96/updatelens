import { describe, expect, it } from 'vitest';

import type { HistoryPhase, ReportPhase } from '@/admin/types/api';
import {
	cronChangeCount,
	historyPhaseState,
	optionsChangeCount,
	phaseChangeCounts,
	reportChangeCounts,
} from '@/admin/utils/changes';
import { formatBytesPair } from '@/admin/utils/format';
import {
	changeCountText,
	changeIndicator,
	defaultPhase,
	defaultProvider,
	historyPhaseText,
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
	CRON_ONLY,
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
			total: 11,
		} );
		// Options only.
		expect( phaseChangeCounts( ELEMENTOR.phases.final ) ).toEqual( {
			options: 5,
			cron: 0,
			total: 5,
		} );
		// Cron only.
		expect( phaseChangeCounts( CRON_CHANGES_ONLY.phases.final ) ).toEqual( {
			options: 0,
			cron: 1,
			total: 1,
		} );
		// Captured without changes.
		expect( phaseChangeCounts( RANK_MATH.phases.final ) ).toEqual( {
			options: 0,
			cron: 0,
			total: 0,
		} );
		// One signal unavailable: the phase still counts the other.
		expect( phaseChangeCounts( MALFORMED_CRON.phases.final ) ).toEqual( {
			options: 6,
			cron: null,
			total: 6,
		} );
		// Both unavailable.
		expect( phaseChangeCounts( FAILED.phases.final ) ).toEqual( {
			options: null,
			cron: null,
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

describe( 'default signal', () => {
	const phase = (
		options: ReportPhase,
		cron: ReportPhase
	): ReportPhase => ( {
		options: options.options,
		cron: cron.cron,
	} );

	it( 'prefers Options when both have changes', () => {
		expect( defaultProvider( COMPLETED.phases.final ) ).toBe( 'options' );
	} );

	it( 'selects WP-Cron when only WP-Cron changed', () => {
		expect( defaultProvider( CRON_CHANGES_ONLY.phases.final ) ).toBe(
			'cron'
		);
	} );

	it( 'falls back to Options, then WP-Cron, when nothing changed', () => {
		expect( defaultProvider( RANK_MATH.phases.final ) ).toBe( 'options' );
		expect(
			defaultProvider(
				phase( CRON_ONLY.phases.final, RANK_MATH.phases.final )
			)
		).toBe( 'cron' );
		expect(
			defaultProvider(
				phase( RANK_MATH.phases.final, MALFORMED_CRON.phases.final )
			)
		).toBe( 'options' );
		// Options changed, WP-Cron unavailable.
		expect( defaultProvider( PARTIAL_CRON.phases.post_update ) ).toBe(
			'options'
		);
	} );
} );

describe( 'change wording', () => {
	it( 'says how many changes, or none', () => {
		expect( changeCountText( 0 ) ).toBe( 'no changes' );
		expect( changeCountText( 1 ) ).toBe( '1 change' );
		expect( changeCountText( 4 ) ).toBe( '4 changes' );
	} );

	it( 'builds compact tab indicators with useful accessible names', () => {
		expect( changeIndicator( 'Net result', 4 ) ).toEqual( {
			text: '4',
			tone: 'changes',
			accessibleName: 'Net result, 4 changes',
		} );
		expect( changeIndicator( 'Options', 0 ) ).toEqual( {
			text: '0',
			tone: 'none',
			accessibleName: 'Options, no changes',
		} );
		expect( changeIndicator( 'WP-Cron', null ) ).toEqual( {
			text: 'Not available',
			tone: 'unavailable',
			accessibleName: 'WP-Cron, not available',
		} );
	} );
} );

describe( 'history phase state', () => {
	const signal = ( recorded: boolean, has_changes: boolean | null ) => ( {
		recorded,
		has_changes,
	} );
	const state = ( options: HistoryPhase[ 'options' ], cron = options ) =>
		historyPhaseState( { options, cron } );

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
