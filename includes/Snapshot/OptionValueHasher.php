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
	 * HMAC algorithm.
	 */
	const ALGORITHM = 'sha256';

	/**
	 * Domain-separation label for deriving the fingerprint key from the site secret.
	 *
	 * Changing it changes every fingerprint.
	 */
	const KEY_CONTEXT = 'updatelens:option-value:v1';

	/**
	 * Fingerprint key derived from the site secret (binary).
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @param string $secret Site-specific secret.
	 * @throws InvalidArgumentException If the secret is empty.
	 */
	public function __construct( $secret ) {
		if ( ! is_string( $secret ) || '' === $secret ) {
			throw new InvalidArgumentException( 'OptionValueHasher requires a non-empty secret.' );
		}

		$this->key = hash_hmac( self::ALGORITHM, self::KEY_CONTEXT, $secret, true );
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
		return hash_hmac( self::ALGORITHM, (string) $raw_value, $this->key );
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
