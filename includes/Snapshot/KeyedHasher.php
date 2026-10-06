<?php
/**
 * Site-keyed HMAC used by the snapshot fingerprints.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC-SHA256 under a key derived from a site secret and a domain label.
 *
 * Each kind of fingerprint (option values, cron arguments) derives its own
 * key from its own label, so fingerprints of identical bytes in different
 * domains are unrelated and never interchangeable. Keys cannot be recovered
 * from fingerprints or contexts, and differ between sites.
 */
final class KeyedHasher {

	/**
	 * HMAC algorithm.
	 */
	const ALGORITHM = 'sha256';

	/**
	 * Label for the fingerprint-context identifier.
	 */
	const CONTEXT_LABEL = 'updatelens:fingerprint-context:v1';

	/**
	 * Key derived from the site secret and the domain label (binary).
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Fingerprint scheme identifier, the prefix of get_context().
	 *
	 * @var string
	 */
	private $scheme;

	/**
	 * Constructor.
	 *
	 * @param string $secret      Site-specific secret.
	 * @param string $key_context Domain-separation label; changing it changes every fingerprint.
	 * @param string $scheme      Scheme identifier for the context.
	 * @throws InvalidArgumentException If the secret is empty.
	 */
	public function __construct( $secret, $key_context, $scheme ) {
		if ( ! is_string( $secret ) || '' === $secret ) {
			throw new InvalidArgumentException( 'UpdateLens fingerprints require a non-empty secret.' );
		}

		$this->key    = hash_hmac( self::ALGORITHM, (string) $key_context, $secret, true );
		$this->scheme = (string) $scheme;
	}

	/**
	 * Keyed fingerprint of a byte string.
	 *
	 * @param string $data Bytes to fingerprint.
	 * @return string 64-character lowercase hex HMAC.
	 */
	public function hash( $data ) {
		return hash_hmac( self::ALGORITHM, (string) $data, $this->key );
	}

	/**
	 * Non-secret identifier of the hashing context (scheme + key).
	 *
	 * Equal for the same scheme, label and secret; different when any of them
	 * changes. An HMAC of a fixed label, so it reveals nothing about the key
	 * and is safe to persist.
	 *
	 * @return string `<scheme>:<64 hex>`.
	 */
	public function get_context() {
		return $this->scheme . ':' . hash_hmac( self::ALGORITHM, self::CONTEXT_LABEL, $this->key );
	}

	/**
	 * Keep the key out of var_dump()/print_r() output.
	 *
	 * @return array
	 */
	public function __debugInfo() {
		return array( 'algorithm' => self::ALGORITHM );
	}
}
