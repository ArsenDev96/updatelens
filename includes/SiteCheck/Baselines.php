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
 *   older than REFRESH_AFTER_SECONDS is due for a refresh; a baseline whose
 *   run started during or before the latest updater activity is
 *   `outdated` (UpdateActivity generations). Runs still in progress have
 *   no entry and never count.
 * - Attaching (for_update()): when a plugin update starts, each check's
 *   baseline is attached to its analysis only if no other update started
 *   or ended since the baseline run began (see BeforeReason).
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
	const READY    = 'ready';
	const EXPIRED  = 'expired';
	const MISSING  = 'missing';
	const OUTDATED = 'outdated';

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
	 * A baseline whose run started during an update, or before the latest
	 * updater activity ($generation differs), is `outdated`: it can never be
	 * attached to the next update (for_update()).
	 *
	 * @param int                   $user       User ID.
	 * @param string                $login      Login-session fingerprint.
	 * @param array<string, string> $targets    Current target fingerprint per check.
	 * @param string|null           $generation Current updater activity generation
	 *                                          (UpdateActivity::quiet_generation()); null: unknown
	 *                                          or an update is running, so not compared.
	 * @return array<string, array{state: string, age: int|null, refresh_in: int}>
	 *         By check, in CheckDefinition order: `age` in whole seconds
	 *         (null when missing), `refresh_in` seconds until the baseline
	 *         is due (0 = due now).
	 */
	public function readiness( $user, $login, array $targets, $generation = null ) {
		$now       = (float) call_user_func( $this->now );
		$readiness = array();
		foreach ( CheckDefinition::all() as $definition ) {
			$check  = $definition->get_id();
			$result = $this->find( $user, $login, $check );
			if ( null !== $result && ( null === $result->get_generation() || ( null !== $generation && $result->get_generation() !== $generation ) ) ) {
				$readiness[ $check ] = array(
					'state'      => self::OUTDATED,
					'age'        => (int) floor( max( 0.0, $now - $result->get_completed_at() ) ),
					'refresh_in' => 0,
				);
				continue;
			}
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
	 * The baseline of each check to attach to an update that is starting.
	 *
	 * Decided once, in the update request, from what is stored at that
	 * moment (no request, no waiting). The order of the rules and their
	 * reasons are in BeforeReason. A baseline is attached only if it is of
	 * the current definition version and site address, was accepted before
	 * the update began (`$activity['at']`), is at most MAX_AGE_SECONDS old,
	 * and its run started at the generation the ledger had right before this
	 * update with nothing else running: then no other update started or
	 * ended in between. Inconclusive or failing baselines are attached as
	 * they are; their verdict never becomes a pass.
	 *
	 * @param int                   $user     User ID of the updating administrator.
	 * @param string                $login    Their login-session fingerprint.
	 * @param array<string, string> $targets  Current target fingerprint per check.
	 * @param array|null            $activity UpdateActivity::begin() result of this update, or null.
	 * @return array<string, array{result: CheckResult|null, reason: string|null}> By check, in CheckDefinition order.
	 */
	public function for_update( $user, $login, array $targets, $activity ) {
		$selection = array();
		foreach ( CheckDefinition::all() as $definition ) {
			$check  = $definition->get_id();
			$reason = $this->update_reason( $activity );
			$result = null;
			if ( null === $reason ) {
				$result = $this->lookup( $user, $login, $check, $reason );
			}
			if ( null !== $result ) {
				$reason = self::baseline_reason( $result, $definition, isset( $targets[ $check ] ) ? (string) $targets[ $check ] : '', $activity );
			}
			$selection[ $check ] = array(
				'result' => null === $reason ? $result : null,
				'reason' => $reason,
			);
		}

		return $selection;
	}

	/**
	 * Why no baseline can be attached whatever is stored, or null.
	 *
	 * @param mixed $activity UpdateActivity::begin() result.
	 * @return string|null
	 */
	private function update_reason( $activity ) {
		if ( ! is_array( $activity ) || ! isset( $activity['at'] ) || ! CheckResult::is_timestamp( $activity['at'] ) || ! array_key_exists( 'generation', $activity ) ) {
			return BeforeReason::UPDATE_ORDER_UNKNOWN;
		}

		return empty( $activity['others_running'] ) ? null : BeforeReason::OVERLAPPING_UPDATE;
	}

	/**
	 * The stored baseline of a check, or null with $reason set.
	 *
	 * @param int         $user   User ID.
	 * @param string      $login  Login-session fingerprint.
	 * @param string      $check  Check identifier.
	 * @param string|null $reason Set to MISSING, CORRUPT or SELECTION_FAILED.
	 * @return CheckResult|null
	 */
	private function lookup( $user, $login, $check, &$reason ) {
		try {
			$stored = $this->store->get( self::storage_key( $user, $login, $check ) );
		} catch ( Throwable $e ) {
			$reason = BeforeReason::SELECTION_FAILED;
			return null;
		}
		if ( null === $stored ) {
			$reason = BeforeReason::MISSING;
			return null;
		}
		$result = $this->decode_for( $stored, $user, $login, $check );
		if ( null === $result ) {
			$reason = BeforeReason::CORRUPT;
		}

		return $result;
	}

	/**
	 * Why a stored baseline cannot be attached, or null if it can.
	 *
	 * @param CheckResult     $result     Stored baseline.
	 * @param CheckDefinition $definition Current definition.
	 * @param string          $target     Current target fingerprint.
	 * @param array           $activity   UpdateActivity::begin() result (generation known).
	 * @return string|null
	 */
	private static function baseline_reason( CheckResult $result, CheckDefinition $definition, $target, array $activity ) {
		$started = (float) $activity['at'];
		if ( $result->get_definition_version() !== $definition->get_version() ) {
			return BeforeReason::DEFINITION_CHANGED;
		}
		if ( '' === $target || ! hash_equals( $result->get_context()->get_target(), $target ) ) {
			return BeforeReason::TARGET_CHANGED;
		}
		if ( $result->get_completed_at() >= $started ) {
			return BeforeReason::NOT_COMPLETED_BEFORE_UPDATE;
		}
		if ( $started - $result->get_completed_at() > self::MAX_AGE_SECONDS ) {
			return BeforeReason::EXPIRED;
		}
		if ( null === $result->get_generation() ) {
			return BeforeReason::TAKEN_DURING_UPDATE;
		}
		if ( null === $activity['generation'] ) {
			// The ledger was missing or unreadable before this update.
			return BeforeReason::UPDATE_ORDER_UNKNOWN;
		}
		if ( $result->get_generation() !== $activity['generation'] ) {
			return BeforeReason::INTERVENING_UPDATE;
		}

		return null;
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
				// Only baselines: the same store holds the updater activity ledger.
				if ( $key === $keep || 0 !== strpos( (string) $key, self::STORAGE_PREFIX ) ) {
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
