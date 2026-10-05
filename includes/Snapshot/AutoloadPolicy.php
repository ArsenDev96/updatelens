<?php
/**
 * Interpretation of the `wp_options.autoload` column.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a raw `autoload` value means the option is autoloaded.
 *
 * WordPress 6.6 added `on`, `off`, `auto`, `auto-on` and `auto-off` next to
 * the legacy `yes`/`no`, and exposes the autoloaded set through
 * wp_autoload_values_to_autoload(). Older supported versions only autoload
 * `yes`.
 */
final class AutoloadPolicy {

	/**
	 * Values autoloaded by WordPress before 6.6 (`wp_load_alloptions()` queried `autoload = 'yes'`).
	 */
	const LEGACY_AUTOLOAD_VALUES = array( 'yes' );

	/**
	 * Raw `autoload` values that mean "autoloaded".
	 *
	 * @var string[]
	 */
	private $autoload_values;

	/**
	 * Constructor.
	 *
	 * @param string[] $autoload_values Raw `autoload` values that mean "autoloaded".
	 */
	public function __construct( array $autoload_values ) {
		$this->autoload_values = array_values( array_map( 'strval', $autoload_values ) );
	}

	/**
	 * Policy matching the running WordPress version.
	 *
	 * @return self
	 */
	public static function from_wordpress() {
		if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
			return new self( wp_autoload_values_to_autoload() );
		}

		return new self( self::LEGACY_AUTOLOAD_VALUES );
	}

	/**
	 * Whether a raw `autoload` column value means the option is autoloaded.
	 *
	 * Compared strictly, as WordPress core does.
	 *
	 * @param string $autoload Raw `autoload` column value.
	 * @return bool
	 */
	public function is_autoloaded( $autoload ) {
		return in_array( (string) $autoload, $this->autoload_values, true );
	}
}
