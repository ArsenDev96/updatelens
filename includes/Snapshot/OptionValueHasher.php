<?php
/**
 * Keyed fingerprints of raw option values.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a raw `option_value` into an HMAC-SHA256 fingerprint.
 *
 * Snapshots keep only the fingerprint, so two snapshots can be compared for
 * "did this value change" without storing the value. The key is derived from
 * a site secret, so fingerprints cannot be brute-forced offline without that
 * secret and differ between sites.
 */
final class OptionValueHasher {

	/**
	 * Domain-separation label for deriving the fingerprint key from the site secret.
	 *
	 * Changing it changes every fingerprint.
	 */
	const KEY_CONTEXT = 'updatelens:option-value:v1';

	/**
	 * Fingerprint scheme identifier. Bump when the algorithm or key derivation
	 * changes, so snapshots made under different schemes are never compared.
	 */
	const SCHEME = 'hmac-sha256-v1';

	/**
	 * Keyed HMAC for the option-value domain.
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
	 * Fingerprint of a raw option value.
	 *
	 * @param string $raw_value Raw `option_value` exactly as stored in the database.
	 * @return string 64-character lowercase hex HMAC.
	 */
	public function fingerprint( $raw_value ) {
		return $this->hasher->hash( $raw_value );
	}

	/**
	 * Non-secret identifier of the hashing context (scheme + key).
	 *
	 * Equal for the same scheme and site secret, different when the WordPress
	 * salts or the scheme change. Fingerprints are only comparable between
	 * snapshots with the same context. Safe to persist.
	 *
	 * @return string `<scheme>:<64 hex>`, e.g. `hmac-sha256-v1:3f…`.
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
