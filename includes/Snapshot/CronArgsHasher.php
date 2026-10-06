<?php
/**
 * Keyed fingerprints of WP-Cron event arguments.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a cron event's `args` array into an HMAC-SHA256 fingerprint.
 *
 * Cron arguments can hold IDs, URLs, e-mail addresses or tokens, so snapshots
 * keep only this fingerprint. The key has its own domain label: a cron-args
 * fingerprint and an option-value fingerprint of the same bytes differ.
 */
final class CronArgsHasher {

	/**
	 * Domain-separation label for deriving the fingerprint key from the site secret.
	 *
	 * Changing it changes every fingerprint.
	 */
	const KEY_CONTEXT = 'updatelens:cron-args:v1';

	/**
	 * Fingerprint scheme identifier. Bump when the algorithm, key derivation or
	 * the canonical form of the arguments changes, so snapshots made under
	 * different schemes are never compared.
	 */
	const SCHEME = 'cron-args-hmac-sha256-v1';

	/**
	 * Keyed HMAC for the cron-args domain.
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
	 * Fingerprint of an event's arguments.
	 *
	 * The canonical form is `serialize( $args )`, the same bytes WordPress
	 * hashes for its own event key (`md5( serialize( $args ) )`), so two
	 * argument lists have equal fingerprints exactly when WordPress treats them
	 * as the same event: order, keys and types all count.
	 *
	 * @param array $args Event arguments.
	 * @return string 64-character lowercase hex HMAC.
	 * @throws \Exception If the arguments cannot be serialized.
	 */
	public function fingerprint( array $args ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Same canonical form as WordPress's cron event key; only hashed, never stored.
		return $this->hasher->hash( serialize( $args ) );
	}

	/**
	 * Non-secret identifier of the hashing context (scheme + key). Safe to persist.
	 *
	 * @return string `<scheme>:<64 hex>`, e.g. `cron-args-hmac-sha256-v1:3f…`.
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
