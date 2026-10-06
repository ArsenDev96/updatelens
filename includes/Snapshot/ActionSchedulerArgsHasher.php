<?php
/**
 * Keyed fingerprints of Action Scheduler action arguments.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an action's stored arguments into an HMAC-SHA256 fingerprint.
 *
 * Action arguments can hold user or order IDs, e-mail addresses, URLs,
 * tokens or webhook details, so snapshots keep only this fingerprint. The
 * key has its own domain label: an action-args fingerprint, a cron-args
 * fingerprint and an option-value fingerprint of the same bytes all differ.
 */
final class ActionSchedulerArgsHasher {

	/**
	 * Domain-separation label for deriving the fingerprint key from the site secret.
	 *
	 * Changing it changes every fingerprint.
	 */
	const KEY_CONTEXT = 'updatelens:action-scheduler-args:v1';

	/**
	 * Fingerprint scheme identifier. Bump when the algorithm, key derivation or
	 * the canonical form of the arguments changes, so snapshots made under
	 * different schemes are never compared.
	 */
	const SCHEME = 'as-args-hmac-sha256-v1';

	/**
	 * Keyed HMAC for the action-args domain.
	 *
	 * @var KeyedHasher
	 */
	private $hasher;

	/**
	 * Constructor.
	 *
	 * @param string $secret Site-specific secret.
	 * @throws InvalidArgumentException If the secret is empty.
	 */
	public function __construct( $secret ) {
		$this->hasher = new KeyedHasher( $secret, self::KEY_CONTEXT, self::SCHEME );
	}

	/**
	 * Hasher keyed with the site's `auth` salt (AUTH_KEY . AUTH_SALT).
	 *
	 * Rotating the WordPress salts changes all fingerprints.
	 *
	 * @return self
	 */
	public static function from_wordpress() {
		return new self( wp_salt( 'auth' ) );
	}

	/**
	 * Fingerprint of an action's stored arguments.
	 *
	 * The canonical form is the JSON text Action Scheduler stores (`args`, or
	 * `extended_args` for long arguments). Action Scheduler compares exactly
	 * this text when it looks for an existing action, so two actions have
	 * equal fingerprints exactly when Action Scheduler treats their arguments
	 * as equal.
	 *
	 * @param string $json Stored JSON text of the arguments.
	 * @return string 64-character lowercase hex HMAC.
	 */
	public function fingerprint( $json ) {
		return $this->hasher->hash( (string) $json );
	}

	/**
	 * Non-secret identifier of the hashing context (scheme + key). Safe to persist.
	 *
	 * @return string `<scheme>:<64 hex>`, e.g. `as-args-hmac-sha256-v1:3f…`.
	 */
	public function get_context() {
		return $this->hasher->get_context();
	}

	/**
	 * Keep the key out of var_dump()/print_r() output.
	 *
	 * @return array
	 */
	public function __debugInfo() {
		return $this->hasher->__debugInfo();
	}
}
