<?php
/**
 * Browser attempt facts shared with the TypeScript runner's tests.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\SiteCheck;

use PHPUnit\Framework\TestCase;
use UpdateLens\SiteCheck\CheckDefinition;
use UpdateLens\SiteCheck\ReportValidator;

/**
 * `tests/fixtures/site-check-attempts.json` is read here and by
 * `tests/admin/siteCheck.test.ts`: the runner's retry decision (does an
 * attempt pass?) must agree with PHP's classification, which stays the
 * authority for every verdict.
 */
final class SharedAttemptCasesTest extends TestCase {

	/**
	 * The shared cases.
	 *
	 * @return array<string, array>
	 */
	public function cases() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$data  = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/site-check-attempts.json' ), true );
		$cases = array();
		foreach ( $data['cases'] as $case ) {
			$cases[ $case['name'] ] = array( $case['check'], $case['facts'], $data['expected_user'], $case['verdict'], $case['reason'] );
		}

		return $cases;
	}

	/**
	 * Each case is a valid browser report and classifies as listed.
	 *
	 * @dataProvider cases
	 * @param string      $check   Check.
	 * @param array       $facts   Browser facts.
	 * @param int         $user    Session user.
	 * @param string      $verdict Expected verdict.
	 * @param string|null $reason  Expected reason.
	 */
	public function test_shared_case( $check, array $facts, $user, $verdict, $reason ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test input.
		$report   = json_encode(
			array(
				'check'    => $check,
				'attempts' => array( $facts ),
			)
		);
		$attempts = ReportValidator::parse( $report, CheckDefinition::get( $check ), $user );

		$this->assertSame( array( $verdict, $reason ), array( $attempts[0]->get_verdict(), $attempts[0]->get_reason() ) );
	}
}
