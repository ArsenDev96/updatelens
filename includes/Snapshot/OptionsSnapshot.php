<?php
/**
 * Safe representation of the `wp_options` state.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Snapshot;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Option records (sorted by name) plus their summary and fingerprint context.
 *
 * Contains no raw option values and no capture time, so two snapshots of an
 * unchanged database are equal.
 */
final class OptionsSnapshot {

	/**
	 * Fingerprint context the records were hashed under (OptionValueHasher::get_context()).
	 *
	 * @var string
	 */
	private $fingerprint_context;

	/**
	 * Records keyed by option name, sorted by byte-wise name order.
	 *
	 * @var array<string, OptionRecord>
	 */
	private $records = array();

	/**
	 * Summary over the records.
	 *
	 * @var OptionsSummary
	 */
	private $summary;

	/**
	 * Constructor.
	 *
	 * @param OptionRecord[] $records             Records in any order.
	 * @param string         $fingerprint_context Context the fingerprints were made under.
	 * @throws InvalidArgumentException If two records share a name or the context is empty.
	 */
	public function __construct( array $records, $fingerprint_context ) {
		if ( ! is_string( $fingerprint_context ) || '' === $fingerprint_context ) {
			throw new InvalidArgumentException( 'OptionsSnapshot requires a fingerprint context.' );
		}
		$this->fingerprint_context = $fingerprint_context;

		foreach ( $records as $record ) {
			$name = $record->get_name();
			if ( isset( $this->records[ $name ] ) ) {
				throw new InvalidArgumentException( 'Duplicate option name in snapshot.' );
			}
			$this->records[ $name ] = $record;
		}

		// Byte-wise order, independent of input order and database collation.
		ksort( $this->records, SORT_STRING );

		$this->summary = new OptionsSummary( $this->records );
	}

	/**
	 * All records, keyed by option name.
	 *
	 * @return array<string, OptionRecord>
	 */
	public function get_records() {
		return $this->records;
	}

	/**
	 * Record for one option, or null if it is not in the snapshot.
	 *
	 * @param string $name Option name.
	 * @return OptionRecord|null
	 */
	public function get_record( $name ) {
		$name = (string) $name;

		return isset( $this->records[ $name ] ) ? $this->records[ $name ] : null;
	}

	/**
	 * Summary over the records.
	 *
	 * @return OptionsSummary
	 */
	public function get_summary() {
		return $this->summary;
	}

	/**
	 * Fingerprint context the records were hashed under.
	 *
	 * @return string
	 */
	public function get_fingerprint_context() {
		return $this->fingerprint_context;
	}

	/**
	 * Array form.
	 *
	 * @return array{fingerprint_context: string, options: array<int, array>, summary: array}
	 */
	public function to_array() {
		$options = array();
		foreach ( $this->records as $record ) {
			$options[] = $record->to_array();
		}

		return array(
			'fingerprint_context' => $this->fingerprint_context,
			'options'             => $options,
			'summary'             => $this->summary->to_array(),
		);
	}
}
