<?php
/**
 * BEFORE site check baselines of a plugin update analysis.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Update;

use Throwable;
use UpdateLens\SiteCheck\AjaxEndpoints;
use UpdateLens\SiteCheck\BeforeReason;
use UpdateLens\SiteCheck\Baselines;
use UpdateLens\SiteCheck\CheckDefinition;
use UpdateLens\SiteCheck\CheckResult;
use UpdateLens\SiteCheck\ContextHasher;
use UpdateLens\SiteCheck\Settings;
use UpdateLens\Storage\SiteCheckResultCodec;

defined( 'ABSPATH' ) || exit;

/**
 * Attaches the BEFORE site check baselines to an analysis when its update
 * starts, and reads them back.
 *
 * Selection happens once, inside the update request, when the analysis is
 * created (PluginUpdateAnalyzer::begin()): it reads what is stored at that
 * moment (Baselines::for_update(), with this update's UpdateActivity
 * record), never sends a request and never waits. A baseline accepted
 * later is never attached afterwards.
 *
 * Per check two columns, written in the analysis INSERT and never changed
 * afterwards (failed, abandoned and expired analyses keep them): the
 * attached result (`SiteCheckResultCodec` JSON: facts, definition version,
 * server times, keyed target and login-session fingerprints, user ID and
 * generation; never URLs, bodies, cookies, nonces, markers or secrets) or
 * a BeforeReason code. Exactly one of them is set for analyses created
 * since schema 6; both are NULL for older analyses (read as
 * `not_captured`). When baselines were selected with a ledger record, the
 * update's start time is stored too (`site_check_update_started_at`,
 * schema 7): the AFTER side (SiteCheckAfter) and the comparison use it.
 */
final class SiteCheckObservation {

	/**
	 * Columns per check: the attached result and the reason when there is none.
	 */
	const COLUMNS = array(
		CheckDefinition::HOME => array(
			'result' => 'site_check_home_before',
			'reason' => 'site_check_home_before_reason',
		),
		CheckDefinition::REST => array(
			'result' => 'site_check_rest_before',
			'reason' => 'site_check_rest_before_reason',
		),
	);

	/**
	 * When the update began (Unix time with microseconds, from its ledger
	 * record), written with the BEFORE columns when baselines were selected.
	 */
	const UPDATE_STARTED_AT = 'site_check_update_started_at';

	/**
	 * Context of the update request: function (): array{enabled: bool,
	 * login: string|null, targets: array<string, string>, unsupported?: string[]}
	 * (`unsupported`: checks whose target is on another origin than wp-admin).
	 *
	 * @var callable
	 */
	private $context;

	/**
	 * Baselines.
	 *
	 * @var Baselines
	 */
	private $baselines;

	/**
	 * Codec.
	 *
	 * @var SiteCheckResultCodec
	 */
	private $codec;

	/**
	 * Constructor.
	 *
	 * @param callable  $context   Returns the request's context (see the property).
	 * @param Baselines $baselines Baselines.
	 */
	public function __construct( callable $context, Baselines $baselines ) {
		$this->context   = $context;
		$this->baselines = $baselines;
		$this->codec     = new SiteCheckResultCodec();
	}

	/**
	 * Observation on the running site: the opt-in, the request's WordPress
	 * login session, the check targets and which of them are on another
	 * origin than wp-admin, read when an update starts.
	 *
	 * @return self
	 */
	public static function from_wordpress() {
		return new self(
			static function () {
				if ( ! Settings::is_enabled() ) {
					return array(
						'enabled' => false,
						'login'   => null,
						'targets' => array(),
					);
				}
				$hasher = ContextHasher::from_wordpress();
				$token  = wp_get_session_token();
				$urls   = AjaxEndpoints::target_urls();

				return array(
					'enabled'     => true,
					'login'       => is_string( $token ) && '' !== $token ? $hasher->session( $token ) : null,
					'targets'     => array_map( array( $hasher, 'target' ), $urls ),
					'unsupported' => AjaxEndpoints::unsupported_checks( $urls ),
				);
			},
			Baselines::from_wordpress()
		);
	}

	/**
	 * Columns of a new analysis: per check the attached baseline or the reason.
	 *
	 * Never throws.
	 *
	 * @param int        $user_id  Updating administrator.
	 * @param array|null $activity UpdateActivity::begin() result of this update, or null.
	 * @return array<string, string|null>
	 */
	public function before( $user_id, $activity ) {
		try {
			$context = call_user_func( $this->context );
			if ( empty( $context['enabled'] ) ) {
				return self::reasons( BeforeReason::DISABLED );
			}
			if ( empty( $context['login'] ) || (int) $user_id < 1 ) {
				return self::reasons( BeforeReason::NO_LOGIN_SESSION );
			}
			$selection   = $this->baselines->for_update( (int) $user_id, (string) $context['login'], (array) $context['targets'], $activity );
			$unsupported = isset( $context['unsupported'] ) ? (array) $context['unsupported'] : array();
		} catch ( Throwable $e ) {
			return self::reasons( BeforeReason::SELECTION_FAILED );
		}
		foreach ( $unsupported as $check ) {
			// The browser cannot run this check from wp-admin: never a baseline, whatever is stored.
			$selection[ $check ] = array(
				'result' => null,
				'reason' => BeforeReason::CROSS_ORIGIN,
			);
		}

		$columns = array();
		foreach ( self::COLUMNS as $check => $names ) {
			$chosen = isset( $selection[ $check ] ) ? $selection[ $check ] : array(
				'result' => null,
				'reason' => BeforeReason::SELECTION_FAILED,
			);
			$json   = null;
			$reason = $chosen['reason'];
			if ( $chosen['result'] instanceof CheckResult ) {
				try {
					$json = $this->codec->encode( $chosen['result'] );
				} catch ( Throwable $e ) {
					$reason = BeforeReason::SELECTION_FAILED;
				}
			}
			$columns[ $names['result'] ] = $json;
			$columns[ $names['reason'] ] = null === $json ? ( in_array( $reason, BeforeReason::STORED, true ) ? $reason : BeforeReason::SELECTION_FAILED ) : null;
		}
		if ( is_array( $activity ) && isset( $activity['at'] ) && CheckResult::is_timestamp( $activity['at'] ) ) {
			// When this update began (its ledger record): the comparison's update start.
			$columns[ self::UPDATE_STARTED_AT ] = sprintf( '%.6F', (float) $activity['at'] );
		}

		return $columns;
	}

	/**
	 * The BEFORE results of a stored analysis.
	 *
	 * @param array $row Analysis row with the COLUMNS (missing columns read as NULL).
	 * @return array<string, array{result: CheckResult|null, reason: string|null}>
	 *         Per check: the decoded result (verdict and origin derived) or a
	 *         reason: stored BeforeReason codes, `not_captured` (both NULL:
	 *         created before schema 6), `data_corrupt` (unreadable result),
	 *         `unknown` (unrecognised stored reason).
	 */
	public static function read( array $row ) {
		$codec = new SiteCheckResultCodec();
		$read  = array();
		foreach ( self::COLUMNS as $check => $names ) {
			$json   = isset( $row[ $names['result'] ] ) ? $row[ $names['result'] ] : null;
			$reason = isset( $row[ $names['reason'] ] ) ? $row[ $names['reason'] ] : null;
			if ( null !== $json ) {
				try {
					$result = $codec->decode( $json );
				} catch ( Throwable $e ) {
					$result = null;
				}
				// A result of the other check in this column is as unusable as an unreadable one.
				$usable         = null !== $result && $result->get_check() === $check;
				$read[ $check ] = array(
					'result' => $usable ? $result : null,
					'reason' => $usable ? null : BeforeReason::DATA_CORRUPT,
				);
				continue;
			}
			if ( null === $reason ) {
				$reason = BeforeReason::NOT_CAPTURED;
			} elseif ( ! in_array( $reason, BeforeReason::STORED, true ) ) {
				$reason = BeforeReason::UNKNOWN;
			}
			$read[ $check ] = array(
				'result' => null,
				'reason' => $reason,
			);
		}

		return $read;
	}

	/**
	 * The same reason for every check.
	 *
	 * @param string $reason BeforeReason.
	 * @return array<string, string|null>
	 */
	private static function reasons( $reason ) {
		$columns = array();
		foreach ( self::COLUMNS as $names ) {
			$columns[ $names['result'] ] = null;
			$columns[ $names['reason'] ] = $reason;
		}

		return $columns;
	}
}
