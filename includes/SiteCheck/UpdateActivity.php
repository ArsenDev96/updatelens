<?php
/**
 * Site-wide order of WordPress updater activity.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

use Throwable;
use UpdateLens\Storage\UpdateActivityCodec;

defined( 'ABSPATH' ) || exit;

/**
 * A small ledger that orders updater activity against site check runs, so
 * a BEFORE baseline is only ever evidence for the update that directly
 * follows it.
 *
 * One option row (STORAGE_KEY, UpdateLens prefix: never in snapshots) holds
 * a random `epoch`, a `sequence` number and the requests whose updater
 * activity is running (random token => start time). Every change is a
 * compare-and-set on the exact stored bytes (BaselineStore), so concurrent
 * requests are strictly ordered and never lose each other's entries; no
 * timestamp decides the order.
 *
 * - begin(): every WordPress upgrader download in a request (plugins,
 *   themes, core, translations; any user, also cron and WP-CLI) adds the
 *   request and increments the sequence. It returns the state just before:
 *   the generation (`epoch:sequence`) and whether other updates were
 *   running.
 * - end(): at the end of that request, removes it and increments again.
 * - quiet_generation(): when a site check session starts, the current
 *   generation, or null while any update is running.
 *
 * A baseline whose session recorded generation G is usable for an update
 * only if that update's begin() saw exactly G with nothing else running:
 * any update that started or ended in between (another plugin, another
 * administrator, a queued update) moved the sequence. A missing or
 * unreadable ledger starts a new random epoch, so no earlier generation can
 * match by accident. Entries of requests that never reached their end
 * (killed processes) expire after STALE_AFTER_SECONDS.
 *
 * Updates that bypass the WordPress upgrader (files copied by FTP, git or a
 * host's deploy tool) are invisible here.
 */
final class UpdateActivity {

	/**
	 * Option name of the ledger.
	 */
	const STORAGE_KEY = 'updatelens_update_activity';

	/**
	 * A running entry older than this is ignored and dropped (as an analysis
	 * whose update never reported back, PluginUpdateAnalyzer::STALE_AFTER_SECONDS).
	 */
	const STALE_AFTER_SECONDS = 900;

	/**
	 * Compare-and-set attempts per change.
	 */
	const MAX_WRITES = 5;

	/**
	 * Random bytes of the epoch and of a request token.
	 */
	const EPOCH_BYTES = 8;
	const TOKEN_BYTES = 16;

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
	 * Random source: function ( int $length ): string (bytes).
	 *
	 * @var callable
	 */
	private $random;

	/**
	 * Codec.
	 *
	 * @var UpdateActivityCodec
	 */
	private $codec;

	/**
	 * Constructor.
	 *
	 * @param BaselineStore $store  Atomic storage.
	 * @param callable      $now    Clock (Unix seconds as float).
	 * @param callable      $random Cryptographically secure random bytes (`random_bytes`).
	 */
	public function __construct( BaselineStore $store, callable $now, callable $random ) {
		$this->store  = $store;
		$this->now    = $now;
		$this->random = $random;
		$this->codec  = new UpdateActivityCodec();
	}

	/**
	 * Ledger in the options table on `microtime( true )` and `random_bytes()`.
	 *
	 * @return self
	 */
	public static function from_wordpress() {
		return new self(
			new OptionBaselineStore(),
			static function () {
				return microtime( true );
			},
			'random_bytes'
		);
	}

	/**
	 * Whether a value is a generation (`<16 hex epoch>:<sequence>`).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_generation( $value ) {
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{16}:(0|[1-9][0-9]{0,16})\z/', $value );
	}

	/**
	 * A new request token.
	 *
	 * @return string 32 lowercase hex characters.
	 * @throws \Exception If no random bytes are available.
	 */
	public function new_token() {
		return bin2hex( call_user_func( $this->random, self::TOKEN_BYTES ) );
	}

	/**
	 * The current generation if no update is running; creates the ledger if
	 * it is missing or unreadable (with a new epoch).
	 *
	 * @return string|null Null while an update runs, or if storage failed.
	 */
	public function quiet_generation() {
		try {
			for ( $write = 0; $write < self::MAX_WRITES; $write++ ) {
				$stored = $this->store->get( self::STORAGE_KEY );
				$state  = $this->decode( $stored );
				if ( null === $state ) {
					$state = $this->new_state( 0 );
					if ( ! $this->write( $stored, $state ) ) {
						continue;
					}
				}

				return array() === $this->live( $state['running'] ) ? self::generation( $state ) : null;
			}
		} catch ( Throwable $e ) {
			return null;
		}

		return null;
	}

	/**
	 * Record that this request starts updater activity.
	 *
	 * @param string $token This request's token (new_token()); repeated calls add nothing new.
	 * @return array{generation: string|null, others_running: bool, at: float}|null The
	 *         state just before: `generation` null if the ledger was missing or
	 *         unreadable; `others_running` whether other requests' updates were
	 *         running; `at` the time of the record. Null if it could not be recorded.
	 */
	public function begin( $token ) {
		try {
			$now = (float) call_user_func( $this->now );
			for ( $write = 0; $write < self::MAX_WRITES; $write++ ) {
				$stored  = $this->store->get( self::STORAGE_KEY );
				$state   = $this->decode( $stored );
				$known   = null !== $state;
				$state   = $known ? $state : $this->new_state( 0 );
				$running = $this->live( $state['running'] );
				$others  = array_diff_key( $running, array( $token => true ) );

				$running[ $token ] = isset( $running[ $token ] ) ? $running[ $token ] : $now;
				if ( count( $running ) > UpdateActivityCodec::MAX_RUNNING ) {
					asort( $running );
					$running = array_slice( $running, -UpdateActivityCodec::MAX_RUNNING, null, true );
				}
				$next = $this->next( $state, $running );

				if ( $this->write( $stored, $next ) ) {
					return array(
						'generation'     => $known ? self::generation( $state ) : null,
						'others_running' => array() !== $others,
						'at'             => $now,
					);
				}
			}
		} catch ( Throwable $e ) {
			return null;
		}

		return null;
	}

	/**
	 * Record that this request's updater activity ended.
	 *
	 * @param string $token This request's token.
	 * @return bool Whether the end was recorded (false if it was not running).
	 */
	public function end( $token ) {
		try {
			for ( $write = 0; $write < self::MAX_WRITES; $write++ ) {
				$stored = $this->store->get( self::STORAGE_KEY );
				$state  = $this->decode( $stored );
				if ( null === $state || ! isset( $state['running'][ $token ] ) ) {
					return false;
				}
				$running = $this->live( $state['running'] );
				unset( $running[ $token ] );
				if ( $this->write( $stored, $this->next( $state, $running ) ) ) {
					return true;
				}
			}
		} catch ( Throwable $e ) {
			return false;
		}

		return false;
	}

	/**
	 * Generation of a state.
	 *
	 * @param array $state State.
	 * @return string
	 */
	private static function generation( array $state ) {
		return $state['epoch'] . ':' . $state['sequence'];
	}

	/**
	 * The next state: sequence + 1 (a new epoch when the sequence is exhausted).
	 *
	 * @param array                $state   Current state.
	 * @param array<string, float> $running Running entries of the next state.
	 * @return array
	 */
	private function next( array $state, array $running ) {
		$next = $state['sequence'] >= UpdateActivityCodec::MAX_SEQUENCE ? $this->new_state( 0 ) : $state;
		++$next['sequence'];
		$next['running'] = $running;

		return $next;
	}

	/**
	 * Running entries that are not stale.
	 *
	 * @param array<string, float> $running Entries.
	 * @return array<string, float>
	 */
	private function live( array $running ) {
		$cutoff = (float) call_user_func( $this->now ) - self::STALE_AFTER_SECONDS;

		return array_filter(
			$running,
			static function ( $started ) use ( $cutoff ) {
				return $started >= $cutoff;
			}
		);
	}

	/**
	 * A state with a new random epoch.
	 *
	 * @param int $sequence Sequence.
	 * @return array
	 */
	private function new_state( $sequence ) {
		return array(
			'epoch'    => bin2hex( call_user_func( $this->random, self::EPOCH_BYTES ) ),
			'sequence' => $sequence,
			'running'  => array(),
		);
	}

	/**
	 * Decode a stored value; null if missing or unreadable.
	 *
	 * @param string|null $stored Stored value.
	 * @return array|null
	 */
	private function decode( $stored ) {
		if ( null === $stored ) {
			return null;
		}
		try {
			return $this->codec->decode( $stored );
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Write a state over the value read before (compare-and-set).
	 *
	 * @param string|null $stored Value read before (null: none).
	 * @param array       $state  New state.
	 * @return bool Whether it was written.
	 */
	private function write( $stored, array $state ) {
		$value = $this->codec->encode( $state );

		return null === $stored ? $this->store->add( self::STORAGE_KEY, $value ) : $this->store->replace( self::STORAGE_KEY, $stored, $value );
	}
}
