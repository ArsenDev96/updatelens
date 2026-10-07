<?php
/**
 * Installed plugin inventory for the monitoring baseline.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Baseline;

defined( 'ABSPATH' ) || exit;

/**
 * Turns `get_plugins()` data into the minimal inventory kept by the
 * monitoring baseline: plugin file, name, version and active state.
 *
 * Pure: takes plain data, never calls WordPress. Every other plugin header
 * (author, URIs, update URI, descriptions …) is dropped.
 */
final class PluginInventory {

	/**
	 * Maximum stored name length in characters.
	 */
	const MAX_NAME_LENGTH = 255;

	/**
	 * Maximum stored version length in characters.
	 */
	const MAX_VERSION_LENGTH = 64;

	/**
	 * Inventory records, sorted by name (natural, case-insensitive), then file.
	 *
	 * UpdateLens itself is left out: the baseline lists the plugins UpdateLens
	 * observes, and its own updates are never analyzed.
	 *
	 * @param array<string, mixed> $plugins      Plugin file => header data, as returned by get_plugins().
	 * @param array<int, mixed>    $active_files Files of active plugins.
	 * @param string               $self_file    UpdateLens's own plugin file.
	 * @return array<int, array{file: string, name: string, version: string, active: bool}>
	 */
	public static function build( array $plugins, array $active_files, $self_file ) {
		$active  = array_fill_keys( array_filter( $active_files, 'is_string' ), true );
		$records = array();

		foreach ( $plugins as $file => $headers ) {
			$file = (string) $file;
			if ( $file === $self_file || ! self::is_plugin_file( $file ) || ! is_array( $headers ) ) {
				continue;
			}

			$name = self::text( isset( $headers['Name'] ) ? $headers['Name'] : '', self::MAX_NAME_LENGTH );

			$records[] = array(
				'file'    => $file,
				'name'    => '' === $name ? $file : $name,
				'version' => self::text( isset( $headers['Version'] ) ? $headers['Version'] : '', self::MAX_VERSION_LENGTH ),
				'active'  => isset( $active[ $file ] ),
			);
		}

		usort(
			$records,
			static function ( array $a, array $b ) {
				$order = strnatcasecmp( $a['name'], $b['name'] );

				return 0 !== $order ? $order : strcmp( $a['file'], $b['file'] );
			}
		);

		return $records;
	}

	/**
	 * Whether a value is a plugin basename relative to the plugins directory
	 * (`dir/file.php` or `file.php`), without traversal or control characters.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_plugin_file( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 255 || 1 !== preg_match( '#^(?:[^/\\\\:\x00-\x1f\x7f]+/)?[^/\\\\:\x00-\x1f\x7f]+\.php$#Du', $value ) ) {
			return false;
		}
		foreach ( explode( '/', $value ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Header text without tags or control characters, trimmed and shortened;
	 * empty if not a valid UTF-8 string.
	 *
	 * @param mixed $value      Header value.
	 * @param int   $max_length Maximum length in characters.
	 * @return string
	 */
	private static function text( $value, $max_length ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Pure code, unit-tested without WordPress.
		$text = trim( (string) preg_replace( '/[\x00-\x1f\x7f]+/u', ' ', strip_tags( $value ) ) );
		if ( 1 === preg_match( '/^.{' . (int) $max_length . '}/su', $text, $match ) ) {
			$text = rtrim( $match[0] );
		}

		return $text;
	}
}
