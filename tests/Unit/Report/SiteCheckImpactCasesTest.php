<?php
/**
 * Shared site check report cases for the admin UI tests.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use UpdateLens\Report\PotentialImpact;
use UpdateLens\Report\SiteCheckReport;
use UpdateLens\Tests\Support\SiteCheckFixture as F;

/**
 * `tests/fixtures/site-check-impact-cases.json` holds, per realistic case,
 * the report's `site_checks` and `potential_impact.site_checks` exactly as
 * PHP builds them from stored results. The Vitest report tests render these
 * outputs, so the UI is tested against real API output.
 *
 * This test fails when the PHP output and the file differ. After an
 * intended change, regenerate the file:
 * `UPDATELENS_WRITE_FIXTURES=1 composer test -- --filter SiteCheckImpactCasesTest`
 * (then `npx prettier --write tests/fixtures/site-check-impact-cases.json`).
 */
final class SiteCheckImpactCasesTest extends TestCase {

	/**
	 * Fixture file.
	 */
	const FILE = '/fixtures/site-check-impact-cases.json';

	/**
	 * Long after the update.
	 */
	const LATER = F::UPDATE_END + 3600;

	/**
	 * A BEFORE run that completed 4.2 minutes before the update started.
	 *
	 * @param string  $check Check identifier.
	 * @param array[] $facts Facts of each attempt.
	 * @return \UpdateLens\SiteCheck\CheckResult
	 */
	private static function before( $check, array $facts ) {
		return F::before(
			$check,
			$facts,
			array(
				'started_at'   => F::UPDATE_START - 253.8,
				'completed_at' => F::UPDATE_START - 252.4,
			)
		);
	}

	/**
	 * An AFTER run that started 3 seconds after the update finished.
	 *
	 * @param string  $check Check identifier.
	 * @param array[] $facts Facts of each attempt.
	 * @return \UpdateLens\SiteCheck\CheckResult
	 */
	private static function after( $check, array $facts ) {
		$duration = 0;
		foreach ( $facts as $attempt ) {
			$duration += $attempt['duration_ms'];
		}

		return F::after(
			$check,
			$facts,
			array(
				'started_at'   => F::UPDATE_END + 2.75,
				'completed_at' => F::UPDATE_END + 3.0 + $duration / 1000 + ( count( $facts ) > 1 ? 5 : 0 ),
			)
		);
	}

	/**
	 * Both attempts with the same facts.
	 *
	 * @param array $facts Facts.
	 * @return array[]
	 */
	private static function twice( array $facts ) {
		return array( $facts, $facts );
	}

	/**
	 * The cases: name => [description, row, read time].
	 *
	 * @return array<string, array{0: string, 1: array, 2: float}>
	 */
	private static function cases() {
		$home     = F::home( array( 'duration_ms' => 284 ) );
		$rest     = F::rest( array( 'duration_ms' => 182 ) );
		$home_500 = F::home(
			array(
				'status'      => 500,
				'end_marker'  => 'absent',
				'duration_ms' => 221,
			)
		);
		$passing  = array(
			'home' => self::before( 'home', array( $home ) ),
			'rest' => self::before( 'rest', array( $rest ) ),
		);
		$after_ok = array(
			'home' => self::after( 'home', array( $home ) ),
			'rest' => self::after( 'rest', array( $rest ) ),
		);
		$row      = array( F::class, 'analysis_row' );
		$timeout  = F::no_response( 'timeout', 10000 );

		return array(
			'home_newly_failing'    => array(
				'Homepage HTTP 500 twice after the update (verified), REST passed at both.',
				$row( $passing, array( 'home' => self::after( 'home', self::twice( $home_500 ) ) ) + $after_ok ),
				self::LATER,
			),
			'rest_newly_failing'    => array(
				'REST API invalid JSON twice after the update, homepage passed at both.',
				$row(
					$passing,
					array(
						'rest' => self::after(
							'rest',
							self::twice(
								F::rest(
									array(
										'body'        => 'invalid_json',
										'user_id'     => null,
										'duration_ms' => 240,
									)
								)
							)
						),
					) + $after_ok
				),
				self::LATER,
			),
			'both_newly_failing'    => array(
				'Homepage HTTP 500 and REST API HTTP 500 with an empty body, both twice.',
				$row(
					$passing,
					array(
						'home' => self::after( 'home', self::twice( $home_500 ) ),
						'rest' => self::after(
							'rest',
							self::twice(
								F::rest(
									array(
										'status'      => 500,
										'body'        => 'empty',
										'user_id'     => null,
										'duration_ms' => 198,
									)
								)
							)
						),
					)
				),
				self::LATER,
			),
			'home_redirect'         => array(
				'Homepage loaded before, redirected in both requests after.',
				$row( $passing, array( 'home' => self::after( 'home', self::twice( F::no_response( 'redirect', 96 ) ) ) ) + $after_ok ),
				self::LATER,
			),
			'home_timeouts'         => array(
				'Homepage: no response within 10 seconds, twice.',
				$row( $passing, array( 'home' => self::after( 'home', self::twice( $timeout ) ) ) + $after_ok ),
				self::LATER,
			),
			'home_incomplete'       => array(
				'Homepage verified but without the end-of-page marker, twice.',
				$row( $passing, array( 'home' => self::after( 'home', self::twice( F::home( array( 'end_marker' => 'absent' ) ) ) ) ) + $after_ok ),
				self::LATER,
			),
			'home_not_inspected'    => array(
				'Homepage too large to inspect before and after: not compared.',
				$row(
					array( 'home' => self::before( 'home', self::twice( F::home( array( 'end_marker' => 'unread' ) ) ) ) ) + $passing,
					array( 'home' => self::after( 'home', self::twice( F::home( array( 'end_marker' => 'unread' ) ) ) ) ) + $after_ok
				),
				self::LATER,
			),
			'home_unverified_500'   => array(
				'Homepage HTTP 500 twice without the verification marker.',
				$row(
					$passing,
					array(
						'home' => self::after(
							'home',
							self::twice(
								F::home(
									array(
										'status'      => 500,
										'header'      => 'absent',
										'end_marker'  => 'absent',
										'duration_ms' => 64,
									)
								)
							)
						),
					) + $after_ok
				),
				self::LATER,
			),
			'both_passed'           => array(
				'Both checks passed before and after.',
				$row( $passing, $after_ok ),
				self::LATER,
			),
			'failing_both'          => array(
				'Homepage HTTP 500 before and after: not a new failure.',
				$row(
					array( 'home' => self::before( 'home', self::twice( $home_500 ) ) ) + $passing,
					array( 'home' => self::after( 'home', self::twice( $home_500 ) ) ) + $after_ok
				),
				self::LATER,
			),
			'recovered'             => array(
				'Homepage HTTP 500 before, passed after.',
				$row( array( 'home' => self::before( 'home', self::twice( $home_500 ) ) ) + $passing, $after_ok ),
				self::LATER,
			),
			'unconfirmed'           => array(
				'Homepage HTTP 500 once, then passed on the retry: inconclusive.',
				$row( $passing, array( 'home' => self::after( 'home', array( $home_500, $home ) ) ) + $after_ok ),
				self::LATER,
			),
			'no_baseline'           => array(
				'No baseline for either check (none taken in this login session).',
				$row(
					array(
						'home' => 'missing',
						'rest' => 'missing',
					),
					array()
				),
				self::LATER,
			),
			'after_not_reported'    => array(
				'Both AFTER runs started but were never reported (page closed).',
				$row( $passing, array() ),
				self::LATER,
			),
			'overlapping_update'    => array(
				'Another update ran before the AFTER results were accepted.',
				$row(
					$passing,
					array(
						'home' => array( self::after( 'home', self::twice( $home_500 ) ), 'overlapping_update' ),
						'rest' => array( self::after( 'rest', array( $rest ) ), 'overlapping_update' ),
					)
				),
				self::LATER,
			),
			'disabled'              => array(
				'Site checks were disabled for this update.',
				$row(
					array(
						'home' => 'disabled',
						'rest' => 'disabled',
					),
					array()
				),
				self::LATER,
			),
			'home_cross_origin'     => array(
				'The homepage is on another origin than wp-admin (www); REST passed at both.',
				$row(
					array(
						'home' => 'cross_origin',
						'rest' => $passing['rest'],
					),
					array( 'rest' => $after_ok['rest'] )
				),
				self::LATER,
			),
			'rest_cross_origin'     => array(
				'The REST API is on another origin than wp-admin; the homepage passed at both.',
				$row(
					array(
						'home' => $passing['home'],
						'rest' => 'cross_origin',
					),
					array( 'home' => $after_ok['home'] )
				),
				self::LATER,
			),
			'both_cross_origin'     => array(
				'Homepage and REST API on another origin than wp-admin: no AFTER run started.',
				$row(
					array(
						'home' => 'cross_origin',
						'rest' => 'cross_origin',
					),
					array(),
					array( 'site_check_after_started_at' => null )
				),
				self::LATER,
			),
			'historical'            => array(
				'Recorded before site checks existed (no site check columns).',
				array(
					'status'         => 'completed',
					'settle_outcome' => 'admin_shutdown',
					'completed_at'   => '2025-10-10 09:53:22',
				),
				self::LATER,
			),
			'one_unavailable'       => array(
				'Homepage passed at both; the REST baseline started before the update but finished after it began.',
				$row(
					array(
						'home' => $passing['home'],
						'rest' => 'not_completed_before_update',
					),
					array( 'home' => $after_ok['home'] )
				),
				self::LATER,
			),
			'pending'               => array(
				'Both AFTER runs claimed 10 seconds ago, results not reported yet.',
				$row( $passing, array() ),
				F::UPDATE_END + 10,
			),
			'pending_with_finding'  => array(
				'REST API failing already reported; the homepage AFTER result may still arrive.',
				$row(
					$passing,
					array(
						'rest' => self::after(
							'rest',
							self::twice(
								F::rest(
									array(
										'body'        => 'json_after_output',
										'user_id'     => null,
										'duration_ms' => 205,
									)
								)
							)
						),
					)
				),
				F::UPDATE_END + 10,
			),
			'redirect_disappeared'  => array(
				'Homepage redirected before, loaded directly after.',
				$row(
					array( 'home' => self::before( 'home', self::twice( F::no_response( 'redirect', 88 ) ) ) ) + $passing,
					$after_ok
				),
				self::LATER,
			),
			'redirect_then_failure' => array(
				'Homepage redirected before, HTTP 500 twice after: a change, not a newly failing check.',
				$row(
					array( 'home' => self::before( 'home', self::twice( F::no_response( 'redirect', 88 ) ) ) ) + $passing,
					array( 'home' => self::after( 'home', self::twice( $home_500 ) ) ) + $after_ok
				),
				self::LATER,
			),
		);
	}

	/**
	 * Every case as the API returns it.
	 *
	 * @return array
	 */
	private static function generate() {
		$cases = array();
		foreach ( self::cases() as $name => list( $description, $row, $now ) ) {
			$site_checks    = SiteCheckReport::build( $row, $now );
			$cases[ $name ] = array(
				'description'                  => $description,
				'site_checks'                  => $site_checks,
				'potential_impact_site_checks' => ( new PotentialImpact() )->evaluate_site_checks( $site_checks ),
			);
		}

		return array(
			'_about' => 'Generated by tests/Unit/Report/SiteCheckImpactCasesTest.php from real stored results; do not edit by hand.',
			'cases'  => $cases,
		);
	}

	/**
	 * The fixture file matches what PHP returns.
	 */
	public function test_fixture_matches_the_api() {
		$file      = dirname( __DIR__, 2 ) . self::FILE;
		$generated = self::generate();
		if ( getenv( 'UPDATELENS_WRITE_FIXTURES' ) ) {
			file_put_contents( $file, json_encode( $generated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- No WordPress in unit tests.
		}

		$this->assertSame( $generated, json_decode( (string) file_get_contents( $file ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
	}

	/**
	 * The cases cover each finding, each compared state and the unavailable kinds.
	 */
	public function test_cases_cover_the_report_states() {
		$states   = array();
		$statuses = array();
		$codes    = array();
		foreach ( self::generate()['cases'] as $case ) {
			foreach ( $case['potential_impact_site_checks']['checks'] as $check ) {
				$states[]   = $check['state'];
				$statuses[] = $check['status'];
			}
			foreach ( $case['potential_impact_site_checks']['findings'] as $finding ) {
				$codes[] = $finding['code'];
			}
		}

		$this->assertEqualsCanonicalizing( SiteCheckReport::STATES, array_values( array_unique( $states ) ) );
		$this->assertEqualsCanonicalizing( array( 'evaluated', 'pending', 'not_evaluated', 'not_applicable' ), array_values( array_unique( $statuses ) ) );
		$this->assertEqualsCanonicalizing( array( 'site_check_newly_failing', 'site_check_changed' ), array_values( array_unique( $codes ) ) );
	}
}
