<?php
/**
 * BEFORE baselines: the latest accepted BEFORE result per check.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use Throwable;
use UpdateLens\Storage\SiteCheckResultCodec;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps, per administrator, WordPress login session and check, the latest
 * BEFORE run whose report the server accepted, and decides per check
 * whether it is ready, due for a refresh or missing. Each check is stored
 * and judged on its own: a slow or failed REST run never touches the
 * homepage baseline, and the other way round.
 *
 * - Storage: one entry per (user, login session, check) under
 *   STORAGE_PREFIX + SHA-256 of that identity, holding the result as
 *   `SiteCheckResultCodec` JSON (versioned, strictly decoded): check,
 *   definition version, target and login-session fingerprints, user,
 *   server times and the validated attempt facts. Never URLs, cookies,
 *   bodies, headers, nonces, markers or secrets. An entry whose decoded
 *   identity does not match its key is treated as missing.
 * - Latest wins: the run with the later session start replaces the stored
 *   one, whatever either verdict (a newer failure must not hide behind an
 *   older pass); an older run never replaces a newer one, so reports that
 *   arrive out of order keep the newest. Writes are compare-and-set
 *   (BaselineStore), retried a few times under contention.
 * - Readiness (per check, from the server-accepted completion time; the
 *   stored times are never re-stamped): `ready` up to MAX_AGE_SECONDS
 *   (CheckComparator's limit) and only for the current definition version
 *   and target; `expired` after it; `missing` otherwise. A ready baseline
 *   older than REFRESH_AFTER_SECONDS is due for a refresh. Runs still in
 *   progress have no entry and never count.
 *
 * Nothing here compares baselines with an update yet.
 */
final class Baselines {

	/**
	 * Oldest usable baseline (seconds since its report was accepted).
	 */
	const MAX_AGE_SECONDS = CheckComparator::MAX_BASELINE_AGE_SECONDS;

	/**
	 * Age from which a baseline is refreshed when the page asks.
	 */
	const REFRESH_AFTER_SECONDS = 300;

	/**
	 * How long the page waits before asking again after a run that produced
	 * no baseline (failure, rate limit without a time, storage error).
	 */
	const RETRY_AFTER_FAILURE_SECONDS = 120;

	/**
	 * Storage key prefix (an UpdateLens option name, never in snapshots).
	 */
	const STORAGE_PREFIX = 'updatelens_site_check_baseline_';

	/**
	 * Readiness states.
	 */
	const READY   = 'ready';
	const EXPIRED = 'expired';
	const MISSING = 'missing';

	/**
	 * Save outcomes.
	 */
	const SAVED      = 'saved';
	const SUPERSEDED = 'superseded';
	const FAILED     = 'failed';

	/**
	 * Compare-and-set attempts per save.
	 */
	const MAX_WRITES = 3;

	/**
	 * Most entries looked at when removing expired ones.
	 */
	const CLEANUP_LIMIT = 100;

	/**
	 * Storage.
	 *
	 * @var BaselineStore
	 */
	private $store;

	/**
	 * Clock: function (): float (Unix seconds).
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Codec.
	 *
	 * @var SiteCheckResultCodec
	 */
	private $codec;

	/**
	 * Constructor.
	 *
	 * @param BaselineStore $store Storage.
	 * @param callable      $now   Clock (Unix seconds as float).
	 */
	public function __construct( BaselineStore $store, callable $now ) {
		$this->store = $store;
		$this->now   = $now;
		$this->codec = new SiteCheckResultCodec();
	}

	/**
	 * Baselines in the options table on `microtime( true )`.
	 *
	 * @return self
	 */
	public static function from_wordpress() {
		return new self(
			new OptionBaselineStore(),
			static function () {
				return microtime( true );
			}
		);
	}

	/**
	 * Storage key of a baseline.
	 *
	 * @param int    $user  User ID.
	 * @param string $login Login-session fingerprint.
	 * @param string $check Check identifier.
	 * @return string
	 */
	public static function storage_key( $user, $login, $check ) {
		return self::STORAGE_PREFIX . hash( 'sha256', (int) $user . '|' . (string) $login . '|' . (string) $check );
	}

	/**
	 * The stored baseline of a check, of any age, or null if there is none or
	 * it is unreadable or belongs to another identity.
	 *
	 * @param int    $user  User ID.
	 * @param string $login Login-session fingerprint.
	 * @param string $check Check identifier.
	 * @return CheckResult|null
	 */
	public function find( $user, $login, $check ) {
		try {
			$stored = $this->store->get( self::storage_key( $user, $login, $check ) );
		} catch ( Throwable $e ) {
			return null;
		}

		return null === $stored ? null : $this->decode_for( $stored, $user, $login, $check );
	}

	/**
	 * Readiness of every check.
	 *
	 * @param int                   $user    User ID.
	 * @param string                $login   Login-session fingerprint.
	 * @param array<string, string> $targets Current target fingerprint per check.
	 * @return array<string, array{state: string, age: int|null, refresh_in: int}>
	 *         By check, in CheckDefinition order: `age` in whole seconds
	 *         (null when missing), `refresh_in` seconds until the baseline
	 *         is due (0 = due now).
	 */
	public function readiness( $user, $login, array $targets ) {
		$now       = (float) call_user_func( $this->now );
		$readiness = array();
		foreach ( CheckDefinition::all() as $definition ) {
			$check  = $definition->get_id();
			$result = $this->find( $user, $login, $check );
			if (
				null === $result
				|| $result->get_definition_version() !== $definition->get_version()
				|| ! isset( $targets[ $check ] )
				|| ! hash_equals( $result->get_context()->get_target(), (string) $targets[ $check ] )
			) {
				$readiness[ $check ] = array(
					'state'      => self::MISSING,
					'age'        => null,
					'refresh_in' => 0,
				);
				continue;
			}

			// Another server's clock may be slightly ahead: never a negative age.
			$age                 = max( 0.0, $now - $result->get_completed_at() );
			$expired             = $age > self::MAX_AGE_SECONDS;
			$readiness[ $check ] = array(
				'state'      => $expired ? self::EXPIRED : self::READY,
				'age'        => (int) floor( $age ),
				'refresh_in' => $expired ? 0 : max( 0, (int) ceil( self::REFRESH_AFTER_SECONDS - $age ) ),
			);
		}

		return $readiness;
	}

	/**
	 * Checks whose baseline is due (missing, expired or old enough to refresh).
	 *
	 * @param array<string, array{refresh_in: int}> $readiness readiness() output.
	 * @return string[] Check identifiers in CheckDefinition order.
	 */
	public static function due( array $readiness ) {
		$due = array();
		foreach ( $readiness as $check => $state ) {
			if ( 0 === $state['refresh_in'] ) {
				$due[] = $check;
			}
		}

		return $due;
	}

	/**
	 * Seconds until each check is due, from readiness() output.
	 *
	 * @param array<string, array{refresh_in: int}> $readiness readiness() output.
	 * @return array<string, int>
	 */
	public static function refresh_in( array $readiness ) {
		return array_map(
			static function ( $state ) {
				return $state['refresh_in'];
			},
			$readiness
		);
	}

	/**
	 * Store an accepted BEFORE result as the baseline of its check, unless a
	 * newer one is stored.
	 *
	 * @param CheckResult $result Accepted BEFORE result.
	 * @return string SAVED, SUPERSEDED (a run that started later is stored)
	 *                or FAILED (not stored: storage error or lasting contention).
	 */
	public function save( CheckResult $result ) {
		$context = $result->get_context();
		$key     = self::storage_key( $context->get_user(), $context->get_session(), $result->get_check() );
		try {
			$value = $this->codec->encode( $result );
			for ( $write = 0; $write < self::MAX_WRITES; $write++ ) {
				$stored = $this->store->get( $key );
				if ( null === $stored ) {
					if ( $this->store->add( $key, $value ) ) {
						$this->remove_expired( $key );
						return self::SAVED;
					}
					continue;
				}

				// Unreadable or foreign entries are replaced; readable ones only by a newer run.
				$current = $this->decode_for( $stored, $context->get_user(), $context->get_session(), $result->get_check() );
				if ( null !== $current && ! self::is_newer( $result, $current ) ) {
					return self::SUPERSEDED;
				}
				if ( $this->store->replace( $key, $stored, $value ) ) {
					$this->remove_expired( $key );
					return self::SAVED;
				}
			}
		} catch ( Throwable $e ) {
			return self::FAILED;
		}

		return self::FAILED;
	}

	/**
	 * Whether a run is newer than another: it started later, or at the same
	 * time and finished later.
	 *
	 * @param CheckResult $candidate Candidate.
	 * @param CheckResult $current   Stored.
	 * @return bool
	 */
	public static function is_newer( CheckResult $candidate, CheckResult $current ) {
		if ( $candidate->get_started_at() !== $current->get_started_at() ) {
			return $candidate->get_started_at() > $current->get_started_at();
		}

		return $candidate->get_completed_at() > $current->get_completed_at();
	}

	/**
	 * Remove expired or unreadable entries of every user (they can never be
	 * used again), bounded and best effort; the entry just written is kept.
	 *
	 * @param string $keep Storage key to keep.
	 * @return void
	 */
	private function remove_expired( $keep ) {
		try {
			$now = (float) call_user_func( $this->now );
			foreach ( $this->store->all( self::CLEANUP_LIMIT ) as $key => $value ) {
				if ( $key === $keep ) {
					continue;
				}
				try {
					$expired = $now - $this->codec->decode( $value )->get_completed_at() > self::MAX_AGE_SECONDS;
				} catch ( Throwable $e ) {
					$expired = true;
				}
				if ( $expired ) {
					$this->store->remove( $key, $value );
				}
			}
		} catch ( Throwable $e ) {
			// Cleanup never affects the saved baseline.
			return;
		}
	}

	/**
	 * Decode a stored entry and check it belongs to the identity of its key.
	 *
	 * @param string $stored Stored value.
	 * @param int    $user   User ID.
	 * @param string $login  Login-session fingerprint.
	 * @param string $check  Check identifier.
	 * @return CheckResult|null
	 */
	private function decode_for( $stored, $user, $login, $check ) {
		try {
			$result = $this->codec->decode( $stored );
		} catch ( Throwable $e ) {
			return null;
		}
		$context = $result->get_context();
		if ( $result->get_check() !== $check || $context->get_user() !== $user || ! hash_equals( $context->get_session(), (string) $login ) ) {
			return null;
		}

		return $result;
	}
}
