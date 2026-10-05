<?php
/**
 * Builds an options snapshot from raw database rows.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Turns raw `wp_options` rows into an OptionsSnapshot.
 *
 * Pure: no WordPress or database access, so it is unit-tested directly. Raw
 * values are only fingerprinted and measured here; they never leave this
 * class.
 */
final class OptionsSnapshotBuilder {

	/**
	 * Value fingerprinting.
	 *
	 * @var OptionValueHasher
	 */
	private $hasher;

	/**
	 * Noise filter.
	 *
	 * @var OptionNoiseFilter
	 */
	private $filter;

	/**
	 * Autoload interpretation.
	 *
	 * @var AutoloadPolicy
	 */
	private $autoload_policy;

	/**
	 * Constructor.
	 *
	 * @param OptionValueHasher $hasher          Value fingerprinting.
	 * @param OptionNoiseFilter $filter          Noise filter.
	 * @param AutoloadPolicy    $autoload_policy Autoload interpretation.
	 */
	public function __construct( OptionValueHasher $hasher, OptionNoiseFilter $filter, AutoloadPolicy $autoload_policy ) {
		$this->hasher          = $hasher;
		$this->filter          = $filter;
		$this->autoload_policy = $autoload_policy;
	}

	/**
	 * Build a snapshot.
	 *
	 * @param iterable<array{option_name: string, option_value: string, autoload: string}> $rows Raw `wp_options` rows.
	 * @return OptionsSnapshot
	 */
	public function build( $rows ) {
		$records = array();

		foreach ( $rows as $row ) {
			$name = (string) $row['option_name'];
			if ( $this->filter->is_noise( $name ) ) {
				continue;
			}

			$value    = (string) $row['option_value'];
			$autoload = (string) $row['autoload'];

			$records[] = new OptionRecord(
				$name,
				$this->hasher->fingerprint( $value ),
				strlen( $value ), // Bytes, not characters.
				$autoload,
				$this->autoload_policy->is_autoloaded( $autoload )
			);
		}

		return new OptionsSnapshot( $records, $this->hasher->get_context() );
	}
}
