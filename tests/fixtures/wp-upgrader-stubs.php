<?php
/**
 * WordPress's upgrader classes and the functions PluginUpdateTracker's
 * upgrader hooks need, for an "Update now" request in wp-admin. Loaded only
 * in tests that run in a separate process (with wp-tracker-stubs.php and
 * wpdb-recorder.php). WP_PLUGIN_DIR is a temporary directory the test
 * writes plugin headers to.
 *
 * @package UpdateLens
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter, Squiz.Commenting, Generic.Commenting.DocComment, PEAR.NamingConventions.ValidClassName, Universal.Files.SeparateFunctionsFromOO, Generic.Files.OneObjectStructurePerFile, Generic.Classes.DuplicateClassName -- Stand-ins for WordPress core.

define( 'UPDATELENS_FILE', '/wp-content/plugins/updatelens/updatelens.php' );
define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/updatelens-upgrader-stubs-' . getmypid() );

class WP_Error {
	private $code;

	public function __construct( $code = '' ) {
		$this->code = $code;
	}

	public function get_error_code() {
		return $this->code;
	}
}

class WP_Upgrader {
	public $bulk         = false;
	public $update_count = 0;
}

class Plugin_Upgrader extends WP_Upgrader {
}

class Language_Pack_Upgrader extends WP_Upgrader {
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function is_multisite() {
	return false;
}

function plugin_basename( $file ) {
	return 'updatelens/updatelens.php';
}

function get_current_user_id() {
	return 7;
}

function validate_file( $file ) {
	return false !== strpos( $file, '..' ) ? 1 : 0;
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Stand-in.
}

function get_plugin_data( $path, $markup = true, $translate = true ) {
	$header = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture file.
	$data   = array();
	$labels = array(
		'Name'    => 'Plugin Name',
		'Version' => 'Version',
	);
	foreach ( $labels as $key => $label ) {
		$data[ $key ] = preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $header, $match ) ? trim( $match[1] ) : '';
	}

	return $data;
}
