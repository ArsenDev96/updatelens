<?php
/**
 * Site check facts, results and windows for tests.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Support;

use UpdateLens\SiteCheck\Attempt;
use UpdateLens\SiteCheck\CheckContext;
use UpdateLens\SiteCheck\CheckDefinition;
use UpdateLens\SiteCheck\CheckResult;
use UpdateLens\SiteCheck\ResponseClassifier;
use UpdateLens\SiteCheck\UpdateWindow;

/**
 * Builds browser facts the way the browser reports them, and results around
 * one update: BEFORE completes 1.5 s before the update starts, the update
 * takes 2 s, AFTER starts 0.5 s after it ended.
 */
final class SiteCheckFixture {

	/**
	 * Session user.
	 */
	const USER = 7;

	/**
	 * Update start (server time).
	 */
	const UPDATE_START = 1760090000.25;

	/**
	 * Update end (settled).
	 */
	const UPDATE_END = 1760090002.25;

	/**
	 * Homepage response facts: verified, complete page.
	 *
	 * @param array $overrides Facts to replace.
	 * @return array
	 */
	public static function home( array $overrides = array() ) {
		return array_merge(
			array(
				'kind'        => 'response',
				'status'      => 200,
				'duration_ms' => 912,
				'header'      => 'current',
				'end_marker'  => 'current',
				'retry_after' => false,
				'challenge'   => false,
			),
			$overrides
		);
	}

	/**
	 * REST response facts: verified, JSON naming the session user.
	 *
	 * @param array $overrides Facts to replace.
	 * @return array
	 */
	public static function rest( array $overrides = array() ) {
		return array_merge(
			array(
				'kind'        => 'response',
				'status'      => 200,
				'duration_ms' => 640,
				'header'      => 'current',
				'retry_after' => false,
				'challenge'   => false,
				'body'        => 'json_object',
				'user_id'     => self::USER,
				'error_code'  => null,
			),
			$overrides
		);
	}

	/**
	 * Facts of an attempt without a readable response.
	 *
	 * @param string $kind        `redirect`, `timeout` or `network_error`.
	 * @param int    $duration_ms Duration.
	 * @return array
	 */
	public static function no_response( $kind, $duration_ms = 10000 ) {
		return array(
			'kind'        => $kind,
			'duration_ms' => $duration_ms,
		);
	}

	/**
	 * One attempt.
	 *
	 * @param string $check Check identifier.
	 * @param array  $facts Facts.
	 * @return Attempt
	 */
	public static function attempt( $check, array $facts ) {
		return ResponseClassifier::attempt( $check, $facts, self::USER );
	}

	/**
	 * Context of a run.
	 *
	 * @param string $check   Check identifier.
	 * @param array  $options `user`, `session`, `target`.
	 * @return CheckContext
	 */
	public static function context( $check, array $options = array() ) {
		$options += array(
			'user'    => self::USER,
			'session' => str_repeat( 'b', 64 ),
			'target'  => CheckDefinition::HOME === $check ? str_repeat( 'a', 64 ) : str_repeat( 'c', 64 ),
		);

		return new CheckContext( $options['target'], CheckDefinition::get( $check )->get_auth(), $options['user'], $options['session'] );
	}

	/**
	 * BEFORE run.
	 *
	 * @param string  $check   Check identifier.
	 * @param array[] $facts   Facts of each attempt.
	 * @param array   $options Context options, `version`, `started_at`, `completed_at`.
	 * @return CheckResult
	 */
	public static function before( $check, array $facts, array $options = array() ) {
		return self::result(
			$check,
			$facts,
			$options + array(
				'started_at'   => self::UPDATE_START - 3.0,
				'completed_at' => self::UPDATE_START - 1.5,
			)
		);
	}

	/**
	 * AFTER run.
	 *
	 * @param string  $check   Check identifier.
	 * @param array[] $facts   Facts of each attempt.
	 * @param array   $options Context options, `version`, `started_at`, `completed_at`.
	 * @return CheckResult
	 */
	public static function after( $check, array $facts, array $options = array() ) {
		return self::result(
			$check,
			$facts,
			$options + array(
				'started_at'   => self::UPDATE_END + 0.5,
				'completed_at' => self::UPDATE_END + 1.75,
			)
		);
	}

	/**
	 * A run.
	 *
	 * @param string  $check   Check identifier.
	 * @param array[] $facts   Facts of each attempt.
	 * @param array   $options Context options, `version`, `started_at`, `completed_at`.
	 * @return CheckResult
	 */
	public static function result( $check, array $facts, array $options ) {
		$attempts = array();
		foreach ( $facts as $attempt ) {
			$attempts[] = self::attempt( $check, $attempt );
		}
		$options += array( 'version' => CheckDefinition::get( $check )->get_version() );

		return new CheckResult( $check, $options['version'], self::context( $check, $options ), $options['started_at'], $options['completed_at'], $attempts );
	}

	/**
	 * The update.
	 *
	 * @param bool $queued      Part of a queue.
	 * @param bool $overlapping Another update overlapped.
	 * @return UpdateWindow
	 */
	public static function window( $queued = false, $overlapping = false ) {
		return new UpdateWindow( self::UPDATE_START, self::UPDATE_END, $queued, $overlapping );
	}
}
