<?php
/**
 * Conservative before/after comparison of site checks.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Compares each check's BEFORE and AFTER results independently: one check's
 * missing, late or inconclusive result never affects the other's
 * comparison. Pure and deterministic.
 *
 * Requirements, checked in this order (the first unmet one is the reason):
 *
 * 1. Not one of several queued updates (`queued_update`), and no known
 *    overlapping update (`overlapping_update`).
 * 2. Both results exist, else `not_run` (`baseline_missing`,
 *    `after_missing`, `both_missing`).
 * 3. Both used the current definition version (`definition_changed`). The
 *    authentication context is fixed per check and validated with each
 *    result.
 * 4. Same target (`target_changed`), same administrator (`different_user`)
 *    and same WordPress login session (`different_login_session`; all tabs
 *    of one login share it, another browser or a new login does not). This holds for
 *    the anonymous homepage check too: a baseline from another
 *    administrator or browser is never assumed comparable.
 * 5. The baseline completed before the update began
 *    (`baseline_after_update_start`) and at most MAX_BASELINE_AGE_SECONDS
 *    earlier (`baseline_expired`); the AFTER run started once the update
 *    had ended (`after_during_update`).
 * 6. Both runs conclusive (`before_inconclusive`, `after_inconclusive`),
 *    and not both redirects (`redirected_both`: the browser cannot see
 *    where either went).
 *
 * Then: pass → pass `passed_both`, pass → fail `newly_failing`, fail →
 * fail `failing_both`, fail → pass `recovered`; any pair with one redirect
 * `changed`.
 */
final class CheckComparator {

	/**
	 * Oldest usable baseline at the update start, in seconds (inclusive).
	 */
	const MAX_BASELINE_AGE_SECONDS = 600;

	/**
	 * Compare both checks.
	 *
	 * @param array<string, CheckResult|null> $before BEFORE results by check identifier (missing = none).
	 * @param array<string, CheckResult|null> $after  AFTER results by check identifier (missing = none).
	 * @param UpdateWindow                    $window The update.
	 * @return CheckComparison[] One per check, always both, in definition order (home, rest).
	 * @throws InvalidArgumentException If a key is unknown or a value is not a result of that check.
	 */
	public static function compare_all( array $before, array $after, UpdateWindow $window ) {
		foreach ( array( $before, $after ) as $results ) {
			foreach ( $results as $check => $result ) {
				if ( ! CheckDefinition::is_id( $check ) || ( null !== $result && ! $result instanceof CheckResult ) ) {
					throw new InvalidArgumentException( 'Invalid UpdateLens site check results.' );
				}
			}
		}

		$comparisons = array();
		foreach ( CheckDefinition::all() as $definition ) {
			$id            = $definition->get_id();
			$comparisons[] = self::compare(
				$definition,
				isset( $before[ $id ] ) ? $before[ $id ] : null,
				isset( $after[ $id ] ) ? $after[ $id ] : null,
				$window
			);
		}

		return $comparisons;
	}

	/**
	 * Compare one check.
	 *
	 * @param CheckDefinition  $definition Current definition of the check.
	 * @param CheckResult|null $before     BEFORE result.
	 * @param CheckResult|null $after      AFTER result.
	 * @param UpdateWindow     $window     The update.
	 * @return CheckComparison
	 * @throws InvalidArgumentException If a result belongs to another check.
	 */
	public static function compare( CheckDefinition $definition, ?CheckResult $before, ?CheckResult $after, UpdateWindow $window ) {
		$check = $definition->get_id();
		foreach ( array( $before, $after ) as $result ) {
			if ( null !== $result && $result->get_check() !== $check ) {
				throw new InvalidArgumentException( 'Invalid UpdateLens site check results.' );
			}
		}

		$age = null;
		if ( null !== $before && $before->get_completed_at() <= $window->get_started_at() ) {
			$age = (int) floor( $window->get_started_at() - $before->get_completed_at() );
		}
		$result = function ( $state, $reason = null ) use ( $check, $age, $before, $after ) {
			return new CheckComparison( $check, $state, $reason, $age, $before, $after );
		};

		$reason = self::unmet_requirement( $definition, $before, $after, $window );
		if ( null !== $reason ) {
			$missing = array( ComparisonState::BASELINE_MISSING, ComparisonState::AFTER_MISSING, ComparisonState::BOTH_MISSING );
			return $result( in_array( $reason, $missing, true ) ? ComparisonState::NOT_RUN : ComparisonState::NOT_COMPARABLE, $reason );
		}

		$from = $before->get_verdict();
		$to   = $after->get_verdict();
		if ( Verdict::REDIRECT === $from || Verdict::REDIRECT === $to ) {
			return $result( ComparisonState::CHANGED );
		}
		if ( Verdict::PASS === $from ) {
			return $result( Verdict::PASS === $to ? ComparisonState::PASSED_BOTH : ComparisonState::NEWLY_FAILING );
		}
		return $result( Verdict::PASS === $to ? ComparisonState::RECOVERED : ComparisonState::FAILING_BOTH );
	}

	/**
	 * The first comparability requirement that is not met.
	 *
	 * @param CheckDefinition  $definition Current definition.
	 * @param CheckResult|null $before     BEFORE result.
	 * @param CheckResult|null $after      AFTER result.
	 * @param UpdateWindow     $window     The update.
	 * @return string|null ComparisonState reason, null when comparable.
	 */
	private static function unmet_requirement( CheckDefinition $definition, ?CheckResult $before, ?CheckResult $after, UpdateWindow $window ) {
		if ( $window->is_queued() ) {
			return ComparisonState::QUEUED_UPDATE;
		}
		if ( $window->is_overlapping() ) {
			return ComparisonState::OVERLAPPING_UPDATE;
		}
		if ( null === $before || null === $after ) {
			if ( null === $before && null === $after ) {
				return ComparisonState::BOTH_MISSING;
			}
			return null === $before ? ComparisonState::BASELINE_MISSING : ComparisonState::AFTER_MISSING;
		}

		$version = $definition->get_version();
		if ( $before->get_definition_version() !== $version || $after->get_definition_version() !== $version ) {
			return ComparisonState::DEFINITION_CHANGED;
		}

		$from = $before->get_context();
		$to   = $after->get_context();
		if ( $from->get_target() !== $to->get_target() ) {
			return ComparisonState::TARGET_CHANGED;
		}
		if ( $from->get_user() !== $to->get_user() ) {
			return ComparisonState::DIFFERENT_USER;
		}
		if ( $from->get_session() !== $to->get_session() ) {
			return ComparisonState::DIFFERENT_LOGIN_SESSION;
		}

		if ( $before->get_completed_at() >= $window->get_started_at() ) {
			return ComparisonState::BASELINE_AFTER_UPDATE_START;
		}
		if ( $window->get_started_at() - $before->get_completed_at() > self::MAX_BASELINE_AGE_SECONDS ) {
			return ComparisonState::BASELINE_EXPIRED;
		}
		if ( $after->get_started_at() < $window->get_ended_at() ) {
			return ComparisonState::AFTER_DURING_UPDATE;
		}

		if ( Verdict::INCONCLUSIVE === $before->get_verdict() ) {
			return ComparisonState::BEFORE_INCONCLUSIVE;
		}
		if ( Verdict::INCONCLUSIVE === $after->get_verdict() ) {
			return ComparisonState::AFTER_INCONCLUSIVE;
		}
		if ( Verdict::REDIRECT === $before->get_verdict() && Verdict::REDIRECT === $after->get_verdict() ) {
			return ComparisonState::REDIRECTED_BOTH;
		}

		return null;
	}
}
