<?php
/**
 * Tests for site check response classification.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\SiteCheck;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UpdateLens\SiteCheck\Attempt;
use UpdateLens\SiteCheck\CheckDefinition;
use UpdateLens\SiteCheck\ResponseClassifier;
use UpdateLens\SiteCheck\Verdict;
use UpdateLens\Tests\Support\SiteCheckFixture as F;

/**
 * What the browser observed (outcome) and whether WordPress was verified as
 * the origin stay separate; verdicts follow from both.
 */
final class ResponseClassifierTest extends TestCase {

	/**
	 * Homepage observations.
	 *
	 * @return array<string, array>
	 */
	public function home_cases() {
		return array(
			'verified complete page'                       => array( F::home(), 'success', 200, 'verified', 'pass', null ),
			'end marker without header (header stripped)'  => array( F::home( array( 'header' => 'absent' ) ), 'success', 200, 'verified', 'pass', null ),
			'HTTP 500 with WordPress markers'              => array(
				F::home(
					array(
						'status'     => 500,
						'end_marker' => 'absent',
					)
				),
				'server_error',
				500,
				'verified',
				'fail',
				'server_error',
			),
			'HTTP 500 without markers (fatal before init)' => array(
				F::home(
					array(
						'status'     => 500,
						'header'     => 'absent',
						'end_marker' => 'absent',
					)
				),
				'server_error',
				500,
				'unverified',
				'fail',
				'server_error',
			),
			'HTTP 200 without the end-of-page marker'      => array( F::home( array( 'end_marker' => 'absent' ) ), 'success', 200, 'verified', 'fail', 'incomplete' ),
			'HTTP 200 without any marker (cache, CDN)'     => array(
				F::home(
					array(
						'header'     => 'absent',
						'end_marker' => 'absent',
					)
				),
				'success',
				200,
				'unverified',
				'inconclusive',
				'not_verified',
			),
			'cached page replaying an old marker'          => array(
				F::home(
					array(
						'header'     => 'other',
						'end_marker' => 'other',
					)
				),
				'success',
				200,
				'unverified',
				'inconclusive',
				'stale_marker',
			),
			'old end marker only'                          => array(
				F::home(
					array(
						'header'     => 'absent',
						'end_marker' => 'other',
					)
				),
				'success',
				200,
				'unverified',
				'inconclusive',
				'stale_marker',
			),
			'current header, old end marker'               => array( F::home( array( 'end_marker' => 'other' ) ), 'success', 200, 'verified', 'inconclusive', 'stale_marker' ),
			'opaque redirect'                              => array( F::no_response( 'redirect', 80 ), 'redirect', null, 'unknown', 'redirect', 'redirected' ),
			'readable 3xx'                                 => array(
				F::home(
					array(
						'status'     => 302,
						'end_marker' => 'absent',
					)
				),
				'redirect',
				302,
				'verified',
				'redirect',
				'redirected',
			),
			'core maintenance (503 + Retry-After)'         => array(
				F::home(
					array(
						'status'      => 503,
						'retry_after' => true,
						'header'      => 'absent',
						'end_marker'  => 'absent',
					)
				),
				'unavailable',
				503,
				'unverified',
				'inconclusive',
				'unavailable_retry_after',
			),
			'maintenance plugin (verified 503 + Retry-After)' => array(
				F::home(
					array(
						'status'      => 503,
						'retry_after' => true,
						'end_marker'  => 'absent',
					)
				),
				'unavailable',
				503,
				'verified',
				'inconclusive',
				'unavailable_retry_after',
			),
			'HTTP 503 without Retry-After'                 => array(
				F::home(
					array(
						'status'     => 503,
						'end_marker' => 'absent',
					)
				),
				'server_error',
				503,
				'verified',
				'fail',
				'server_error',
			),
			'firewall challenge (403)'                     => array(
				F::home(
					array(
						'status'     => 403,
						'challenge'  => true,
						'header'     => 'absent',
						'end_marker' => 'absent',
					)
				),
				'challenge',
				403,
				'unverified',
				'inconclusive',
				'challenge',
			),
			'challenge served as 200'                      => array(
				F::home(
					array(
						'challenge'  => true,
						'header'     => 'absent',
						'end_marker' => 'absent',
					)
				),
				'challenge',
				200,
				'unverified',
				'inconclusive',
				'challenge',
			),
			'firewall 403 without markers'                 => array(
				F::home(
					array(
						'status'     => 403,
						'header'     => 'absent',
						'end_marker' => 'absent',
					)
				),
				'forbidden',
				403,
				'unverified',
				'inconclusive',
				'blocked',
			),
			'WordPress 403'                                => array(
				F::home(
					array(
						'status'     => 403,
						'end_marker' => 'absent',
					)
				),
				'forbidden',
				403,
				'verified',
				'fail',
				'refused',
			),
			'Basic Auth 401 before WordPress'              => array(
				F::home(
					array(
						'status'     => 401,
						'header'     => 'absent',
						'end_marker' => 'absent',
					)
				),
				'auth_required',
				401,
				'unverified',
				'inconclusive',
				'blocked',
			),
			'mod_security 406'                             => array(
				F::home(
					array(
						'status'     => 406,
						'header'     => 'absent',
						'end_marker' => 'absent',
					)
				),
				'client_error',
				406,
				'unverified',
				'inconclusive',
				'blocked',
			),
			'WordPress 400'                                => array(
				F::home(
					array(
						'status'     => 400,
						'end_marker' => 'absent',
					)
				),
				'client_error',
				400,
				'verified',
				'fail',
				'client_error',
			),
			'rate limited'                                 => array(
				F::home(
					array(
						'status'     => 429,
						'header'     => 'absent',
						'end_marker' => 'absent',
					)
				),
				'rate_limited',
				429,
				'unverified',
				'inconclusive',
				'rate_limited',
			),
			'HTTP 404 without markers'                     => array(
				F::home(
					array(
						'status'     => 404,
						'header'     => 'absent',
						'end_marker' => 'absent',
					)
				),
				'not_found',
				404,
				'unverified',
				'fail',
				'not_found',
			),
			'HTTP 410'                                     => array(
				F::home(
					array(
						'status'     => 410,
						'end_marker' => 'absent',
					)
				),
				'not_found',
				410,
				'verified',
				'fail',
				'not_found',
			),
			'gateway timeout 504'                          => array(
				F::home(
					array(
						'status'     => 504,
						'header'     => 'absent',
						'end_marker' => 'absent',
					)
				),
				'server_error',
				504,
				'unverified',
				'fail',
				'server_error',
			),
			'timeout'                                      => array( F::no_response( 'timeout' ), 'timeout', null, 'unknown', 'fail', 'timeout' ),
			'network error'                                => array( F::no_response( 'network_error', 12 ), 'network_error', null, 'unknown', 'inconclusive', 'network_error' ),
		);
	}

	/**
	 * Homepage: outcome and origin are separate; HTTP 200 alone never passes.
	 *
	 * @dataProvider home_cases
	 * @param array       $facts   Browser facts.
	 * @param string      $outcome Expected outcome.
	 * @param int|null    $status  Expected status.
	 * @param string      $origin  Expected origin.
	 * @param string      $verdict Expected verdict.
	 * @param string|null $reason  Expected reason.
	 */
	public function test_classifies_homepage( array $facts, $outcome, $status, $origin, $verdict, $reason ) {
		$attempt = F::attempt( CheckDefinition::HOME, $facts );

		$this->assertSame( $outcome, $attempt->get_outcome() );
		$this->assertSame( $status, $attempt->get_status() );
		$this->assertSame( $origin, $attempt->get_origin() );
		$this->assertSame( $verdict, $attempt->get_verdict() );
		$this->assertSame( $reason, $attempt->get_reason() );
		$this->assertNull( $attempt->get_body() );
	}

	/**
	 * REST observations.
	 *
	 * @return array<string, array>
	 */
	public function rest_cases() {
		return array(
			'own user'                           => array( F::rest(), 'success', 'verified', 'expected_user', null, 'pass', null ),
			'another user'                       => array( F::rest( array( 'user_id' => 999 ) ), 'success', 'verified', 'other_user', null, 'fail', 'wrong_user' ),
			'JSON object without id'             => array( F::rest( array( 'user_id' => null ) ), 'success', 'verified', 'unexpected_json', null, 'fail', 'unexpected_json' ),
			'JSON that is not an object'         => array(
				F::rest(
					array(
						'body'    => 'json_other',
						'user_id' => null,
					)
				),
				'success',
				'verified',
				'unexpected_json',
				null,
				'fail',
				'unexpected_json',
			),
			'JSON after other output (notice)'   => array(
				F::rest(
					array(
						'body'    => 'json_after_output',
						'user_id' => null,
					)
				),
				'success',
				'verified',
				'extra_output',
				null,
				'fail',
				'extra_output',
			),
			'invalid JSON'                       => array(
				F::rest(
					array(
						'body'    => 'invalid_json',
						'user_id' => null,
					)
				),
				'success',
				'verified',
				'invalid_json',
				null,
				'fail',
				'invalid_json',
			),
			'empty body'                         => array(
				F::rest(
					array(
						'body'    => 'empty',
						'user_id' => null,
					)
				),
				'success',
				'verified',
				'empty',
				null,
				'fail',
				'invalid_json',
			),
			'not logged in (no nonce)'           => array(
				F::rest(
					array(
						'status'     => 401,
						'user_id'    => null,
						'error_code' => 'rest_not_logged_in',
					)
				),
				'auth_required',
				'verified',
				'wp_error',
				'rest_not_logged_in',
				'fail',
				'refused',
			),
			'invalid nonce'                      => array(
				F::rest(
					array(
						'status'     => 403,
						'user_id'    => null,
						'error_code' => 'rest_cookie_invalid_nonce',
					)
				),
				'forbidden',
				'verified',
				'wp_error',
				'rest_cookie_invalid_nonce',
				'fail',
				'refused',
			),
			'REST disabled by a plugin'          => array(
				F::rest(
					array(
						'status'     => 403,
						'user_id'    => null,
						'error_code' => 'rest_disabled',
					)
				),
				'forbidden',
				'verified',
				'wp_error',
				'other',
				'fail',
				'refused',
			),
			'route missing'                      => array(
				F::rest(
					array(
						'status'     => 404,
						'user_id'    => null,
						'error_code' => 'rest_no_route',
					)
				),
				'not_found',
				'verified',
				'wp_error',
				'rest_no_route',
				'fail',
				'not_found',
			),
			'WordPress error object with 200'    => array(
				F::rest(
					array(
						'user_id'    => null,
						'error_code' => 'rest_forbidden',
					)
				),
				'success',
				'verified',
				'wp_error',
				'rest_forbidden',
				'fail',
				'unexpected_json',
			),
			'JSON 500'                           => array(
				F::rest(
					array(
						'status'     => 500,
						'user_id'    => null,
						'error_code' => 'internal_server_error',
					)
				),
				'server_error',
				'verified',
				'wp_error',
				'other',
				'fail',
				'server_error',
			),
			'fatal error page (500, unverified)' => array(
				F::rest(
					array(
						'status'  => 500,
						'header'  => 'absent',
						'body'    => 'invalid_json',
						'user_id' => null,
					)
				),
				'server_error',
				'unverified',
				'invalid_json',
				null,
				'fail',
				'server_error',
			),
			'firewall 403 page'                  => array(
				F::rest(
					array(
						'status'  => 403,
						'header'  => 'absent',
						'body'    => 'invalid_json',
						'user_id' => null,
					)
				),
				'forbidden',
				'unverified',
				'invalid_json',
				null,
				'inconclusive',
				'blocked',
			),
			'own user but marker missing'        => array( F::rest( array( 'header' => 'absent' ) ), 'success', 'unverified', 'expected_user', null, 'inconclusive', 'not_verified' ),
			'own user but stale marker'          => array( F::rest( array( 'header' => 'other' ) ), 'success', 'unverified', 'expected_user', null, 'inconclusive', 'stale_marker' ),
			'login redirect'                     => array( F::no_response( 'redirect', 50 ), 'redirect', 'unknown', 'not_read', null, 'fail', 'redirected' ),
			'challenge'                          => array(
				F::rest(
					array(
						'status'    => 403,
						'challenge' => true,
						'header'    => 'absent',
						'body'      => 'invalid_json',
						'user_id'   => null,
					)
				),
				'challenge',
				'unverified',
				'invalid_json',
				null,
				'inconclusive',
				'challenge',
			),
			'timeout'                            => array( F::no_response( 'timeout' ), 'timeout', 'unknown', 'not_read', null, 'fail', 'timeout' ),
		);
	}

	/**
	 * REST: a pass needs a verified origin and the session's own user.
	 *
	 * @dataProvider rest_cases
	 * @param array       $facts      Browser facts.
	 * @param string      $outcome    Expected outcome.
	 * @param string      $origin     Expected origin.
	 * @param string      $body       Expected body.
	 * @param string|null $error_code Expected error code.
	 * @param string      $verdict    Expected verdict.
	 * @param string|null $reason     Expected reason.
	 */
	public function test_classifies_rest( array $facts, $outcome, $origin, $body, $error_code, $verdict, $reason ) {
		$attempt = F::attempt( CheckDefinition::REST, $facts );

		$this->assertSame( $outcome, $attempt->get_outcome() );
		$this->assertSame( $origin, $attempt->get_origin() );
		$this->assertSame( $body, $attempt->get_body() );
		$this->assertSame( $error_code, $attempt->get_error_code() );
		$this->assertSame( $verdict, $attempt->get_verdict() );
		$this->assertSame( $reason, $attempt->get_reason() );
		$this->assertNull( $attempt->get_end_marker() );
	}

	/**
	 * The user ID is compared with the session's user, never kept.
	 */
	public function test_rest_user_is_compared_not_kept() {
		$own   = ResponseClassifier::attempt( CheckDefinition::REST, F::rest( array( 'user_id' => 12 ) ), 12 );
		$other = ResponseClassifier::attempt( CheckDefinition::REST, F::rest( array( 'user_id' => 12 ) ), 13 );

		$this->assertSame( Verdict::PASS, $own->get_verdict() );
		$this->assertSame( Verdict::WRONG_USER, $other->get_reason() );
		$this->assertStringNotContainsString( '12', self::json( $own->to_report_array() ) );
	}

	/**
	 * Every status a browser exposes maps to exactly one outcome without a
	 * challenge, and challenges win over the status.
	 */
	public function test_outcome_by_status() {
		$this->assertSame( 'success', ResponseClassifier::outcome( 204, false, false ) );
		$this->assertSame( 'redirect', ResponseClassifier::outcome( 301, false, false ) );
		$this->assertSame( 'server_error', ResponseClassifier::outcome( 503, false, false ) );
		$this->assertSame( 'unavailable', ResponseClassifier::outcome( 503, true, false ) );
		$this->assertSame( 'server_error', ResponseClassifier::outcome( 500, true, false ) );
		$this->assertSame( 'rate_limited', ResponseClassifier::outcome( 429, true, false ) );
		$this->assertSame( 'challenge', ResponseClassifier::outcome( 503, true, true ) );
		$this->assertSame( 'client_error', ResponseClassifier::outcome( 418, false, false ) );
		$this->assertSame( array(), ResponseClassifier::outcomes_for_status( 199 ) );
		$this->assertSame( array(), ResponseClassifier::outcomes_for_status( 600 ) );
		$this->assertSame( array( 'server_error', 'challenge', 'unavailable' ), ResponseClassifier::outcomes_for_status( 503 ) );
	}

	/**
	 * Run verdicts from attempt pairs.
	 *
	 * @return array<string, array>
	 */
	public function run_cases() {
		$pass     = F::home();
		$fail500  = F::home(
			array(
				'status'     => 500,
				'end_marker' => 'absent',
			)
		);
		$timeout  = F::no_response( 'timeout' );
		$network  = F::no_response( 'network_error', 20 );
		$redirect = F::no_response( 'redirect', 30 );
		$cache    = F::home(
			array(
				'header'     => 'absent',
				'end_marker' => 'absent',
			)
		);

		return array(
			'one pass'                          => array( array( $pass ), 'pass', null ),
			'one failure (retry never ran)'     => array( array( $fail500 ), 'inconclusive', 'unconfirmed' ),
			'one redirect'                      => array( array( $redirect ), 'inconclusive', 'unconfirmed' ),
			'one inconclusive keeps its reason' => array( array( $cache ), 'inconclusive', 'not_verified' ),
			'failure confirmed'                 => array( array( $fail500, $fail500 ), 'fail', 'server_error' ),
			'two timeouts'                      => array( array( $timeout, $timeout ), 'fail', 'timeout' ),
			'two different failures'            => array( array( $fail500, $timeout ), 'fail', 'timeout' ),
			'failure then pass (flaky)'         => array( array( $fail500, $pass ), 'inconclusive', 'unconfirmed' ),
			'failure then network error'        => array( array( $fail500, $network ), 'inconclusive', 'unconfirmed' ),
			'network error then failure'        => array( array( $network, $fail500 ), 'inconclusive', 'unconfirmed' ),
			'network error then pass'           => array( array( $network, $pass ), 'pass', null ),
			'two inconclusive'                  => array( array( $network, $cache ), 'inconclusive', 'not_verified' ),
			'redirect confirmed'                => array( array( $redirect, $redirect ), 'redirect', 'redirected' ),
			'redirect then failure'             => array( array( $redirect, $fail500 ), 'inconclusive', 'unconfirmed' ),
		);
	}

	/**
	 * A failure counts only when a second attempt confirms it; a single
	 * failure is inconclusive, never a pass.
	 *
	 * @dataProvider run_cases
	 * @param array[]     $facts   Attempt facts.
	 * @param string      $verdict Expected verdict.
	 * @param string|null $reason  Expected reason.
	 */
	public function test_run_verdict( array $facts, $verdict, $reason ) {
		$attempts = array_map(
			function ( $attempt ) {
				return F::attempt( CheckDefinition::HOME, $attempt );
			},
			$facts
		);

		$this->assertSame( array( $verdict, $reason ), ResponseClassifier::result_verdict( $attempts ) );
	}

	/**
	 * No retry after a pass, no empty or longer runs.
	 */
	public function test_rejects_invalid_runs() {
		$pass = F::attempt( CheckDefinition::HOME, F::home() );
		foreach ( array( array(), array( $pass, $pass ), array( $pass, $pass, $pass ), array( 1 => $pass ) ) as $attempts ) {
			try {
				ResponseClassifier::result_verdict( $attempts );
				$this->fail( 'Invalid run accepted.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Invalid UpdateLens site check attempts.', $e->getMessage() );
			}
		}
	}

	/**
	 * Facts that do not fit the check are rejected.
	 */
	public function test_rejects_invalid_facts() {
		$cases = array(
			array( CheckDefinition::HOME, array( 'kind' => 'unknown' ) ),
			array( CheckDefinition::HOME, F::home( array( 'header' => 'yes' ) ) ),
			array( CheckDefinition::HOME, F::home( array( 'status' => '200' ) ) ),
			array( CheckDefinition::HOME, F::home( array( 'retry_after' => 1 ) ) ),
			array( CheckDefinition::REST, F::rest( array( 'user_id' => '7' ) ) ),
			array( CheckDefinition::REST, F::rest( array( 'user_id' => 0 ) ) ),
			array( CheckDefinition::REST, F::rest( array( 'body' => 'invalid_json' ) ) ),
			array( CheckDefinition::REST, F::rest( array( 'error_code' => 5 ) ) ),
		);
		foreach ( $cases as $case ) {
			try {
				F::attempt( $case[0], $case[1] );
				$this->fail( 'Invalid facts accepted.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'Invalid UpdateLens site check attempt.', $e->getMessage() );
			}
		}

		$this->expectException( InvalidArgumentException::class );
		ResponseClassifier::attempt( CheckDefinition::REST, F::rest(), 0 );
	}

	/**
	 * Stored attempts are validated for consistency.
	 */
	public function test_stored_attempts_are_validated() {
		$home = F::attempt( CheckDefinition::HOME, F::home() )->to_array();
		$rest = F::attempt(
			CheckDefinition::REST,
			F::rest(
				array(
					'status'     => 401,
					'user_id'    => null,
					'error_code' => 'rest_not_logged_in',
				)
			)
		)->to_array();
		$this->assertSame( array( 'outcome', 'status', 'header', 'end_marker', 'duration_ms' ), array_keys( $home ) );
		$this->assertSame( $home, Attempt::from_array( CheckDefinition::HOME, $home )->to_array() );
		$this->assertSame( $rest, Attempt::from_array( CheckDefinition::REST, $rest )->to_array() );

		$invalid = array(
			array( CheckDefinition::HOME, array_merge( $home, array( 'outcome' => 'unavailable' ) ) ),
			array( CheckDefinition::HOME, array_merge( $home, array( 'status' => 500 ) ) ),
			array( CheckDefinition::HOME, array_merge( $home, array( 'status' => null ) ) ),
			array( CheckDefinition::HOME, array_merge( $home, array( 'header' => 'not_read' ) ) ),
			array( CheckDefinition::HOME, array_merge( $home, array( 'duration_ms' => 60001 ) ) ),
			array( CheckDefinition::HOME, array_merge( $home, array( 'origin' => 'verified' ) ) ),
			array( CheckDefinition::HOME, array_reverse( $home, true ) ),
			array( CheckDefinition::HOME, $rest ),
			array( CheckDefinition::REST, array_merge( $rest, array( 'error_code' => 'rest_disabled' ) ) ),
			array( CheckDefinition::REST, array_merge( $rest, array( 'body' => 'expected_user' ) ) ),
			array( CheckDefinition::REST, array_merge( $rest, array( 'body' => 'not_read' ) ) ),
			array(
				CheckDefinition::REST,
				array_merge(
					$rest,
					array(
						'outcome' => 'timeout',
						'status'  => null,
						'header'  => 'not_read',
						'body'    => 'not_read',
					)
				),
			),
			array( 'other', $home ),
			array( CheckDefinition::HOME, 'not an array' ),
		);
		foreach ( $invalid as $case ) {
			try {
				Attempt::from_array( $case[0], $case[1] );
				$this->fail( 'Invalid stored attempt accepted.' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertContains( $e->getMessage(), array( 'Invalid UpdateLens site check attempt.', 'Unknown UpdateLens site check.' ) );
			}
		}
	}

	/**
	 * Report form adds origin and verdict to the stored facts.
	 */
	public function test_report_form() {
		$attempt = F::attempt(
			CheckDefinition::HOME,
			F::home(
				array(
					'status'     => 500,
					'header'     => 'absent',
					'end_marker' => 'absent',
				)
			)
		);

		$this->assertSame(
			array(
				'outcome'     => 'server_error',
				'status'      => 500,
				'header'      => 'absent',
				'end_marker'  => 'absent',
				'duration_ms' => 912,
				'origin'      => 'unverified',
				'verdict'     => 'fail',
				'reason'      => 'server_error',
			),
			$attempt->to_report_array()
		);
	}

	/**
	 * JSON for assertions.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	private static function json( $data ) {
		return (string) json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test helper.
	}
}
