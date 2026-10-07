<?php
/**
 * Minimal WordPress stand-ins for monitoring baseline wiring, uninstall and
 * REST tests. Loaded only in tests that run in a separate process, so the
 * global functions and classes never leak into other tests.
 *
 * @package UpdateLens
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride, Generic.CodeAnalysis.UnusedFunctionParameter, Universal.Files.SeparateFunctionsFromOO, Generic.Files.OneObjectStructurePerFile, Squiz.Commenting, Generic.Commenting.DocComment -- Test stand-ins for WordPress core.

define( 'UPDATELENS_FILE', '/srv/wp-content/plugins/updatelens/updatelens.php' );

$GLOBALS['updatelens_test'] = array(
	'options'  => array(),
	'autoload' => array(),
	'plugins'  => array(),
	'active'   => array(),
	'caps'     => array( 'manage_options' ),
	'queries'  => array(),
	'routes'   => array(),
);

function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['updatelens_test']['options'] ) ? $GLOBALS['updatelens_test']['options'][ $name ] : $default_value;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['updatelens_test']['options'] ) ) {
		return false;
	}
	$GLOBALS['updatelens_test']['options'][ $name ]  = $value;
	$GLOBALS['updatelens_test']['autoload'][ $name ] = $autoload;

	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['updatelens_test']['options'][ $name ] );

	return true;
}

function get_plugins() {
	if ( $GLOBALS['updatelens_test']['plugins'] instanceof Throwable ) {
		throw $GLOBALS['updatelens_test']['plugins'];
	}

	return $GLOBALS['updatelens_test']['plugins'];
}

function is_plugin_active( $plugin ) {
	return in_array( $plugin, $GLOBALS['updatelens_test']['active'], true );
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function current_user_can( $capability ) {
	return in_array( $capability, $GLOBALS['updatelens_test']['caps'], true );
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function rest_authorization_required_code() {
	return 403;
}

function register_rest_route( $route_namespace, $route, $args = array() ) {
	$GLOBALS['updatelens_test']['routes'][ $route_namespace . $route ] = $args;

	return true;
}

class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_data() {
		return $this->data;
	}
}

class WP_REST_Server {
	const READABLE = 'GET';
}

class WP_REST_Request implements ArrayAccess {
	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ) {
		return false;
	}

	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return null;
	}

	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ) {
	}

	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ) {
	}
}

class WP_REST_Response {
	public $data;

	public function __construct( $data = null ) {
		$this->data = $data;
	}

	public function get_data() {
		return $this->data;
	}
}

class WP_REST_Controller {
	protected $namespace;
	protected $rest_base;
	protected $schema;

	public function add_additional_fields_schema( $schema ) {
		return $schema;
	}

	public function get_public_item_schema() {
		return $this->get_item_schema();
	}
}

class UpdateLens_Test_Wpdb {
	public $prefix = 'wp_';

	public function prepare( $query, ...$args ) {
		return vsprintf( str_replace( array( '%i', '%s', '%d' ), '%s', $query ), $args );
	}

	public function query( $sql ) {
		$GLOBALS['updatelens_test']['queries'][] = $sql;

		return true;
	}

	public function hide_errors() {
		return true;
	}

	public function show_errors( $show = true ) {
		return true;
	}
}

$GLOBALS['wpdb'] = new UpdateLens_Test_Wpdb();
