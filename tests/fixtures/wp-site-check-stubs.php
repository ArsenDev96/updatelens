<?php
/**
 * Minimal WordPress stand-ins for the site check endpoints and markers.
 * Loaded only in tests that run in a separate process.
 *
 * @package UpdateLens
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride, Generic.CodeAnalysis.UnusedFunctionParameter, Universal.Files.SeparateFunctionsFromOO, Generic.Files.OneObjectStructurePerFile, Squiz.Commenting, Generic.Commenting.DocComment, WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped, Generic.Classes.DuplicateClassName.Found, PEAR.NamingConventions.ValidClassName -- Test stand-ins for WordPress core.

$GLOBALS['updatelens_test'] = array(
	'options'     => array( 'updatelens_site_checks' => 'enabled' ),
	'caps'        => array( 'update_plugins' ),
	'user'        => 7,
	'token'       => 'login-session-token-of-user-7',
	'nonce_valid' => true,
	'actions'     => array(),
	'nocache'     => 0,
	'admin'       => false,
	'multisite'   => false,
	'logged_in'   => false,
);

/**
 * A wp_send_json_*() call, thrown so the request "ends" like in WordPress.
 */
final class UpdateLensTestJsonResponse extends Exception {
	public $success;
	public $data;
	public $status;

	public function __construct( $success, $data, $status ) {
		parent::__construct( 'JSON response' );
		$this->success = $success;
		$this->data    = $data;
		$this->status  = $status;
	}
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['updatelens_test']['actions'][] = array( $hook, $callback, $priority );
}

function is_admin() {
	return $GLOBALS['updatelens_test']['admin'];
}

function is_user_logged_in() {
	return $GLOBALS['updatelens_test']['logged_in'];
}

function is_multisite() {
	return $GLOBALS['updatelens_test']['multisite'];
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	add_action( $hook, $callback, $priority, $args );
}

function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
	return $GLOBALS['updatelens_test']['nonce_valid'] && 'updatelens_site_check' === $action ? 1 : false;
}

function current_user_can( $capability ) {
	return in_array( $capability, $GLOBALS['updatelens_test']['caps'], true );
}

function get_current_user_id() {
	return $GLOBALS['updatelens_test']['user'];
}

function wp_get_session_token() {
	return $GLOBALS['updatelens_test']['token'];
}

function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['updatelens_test']['options'] ) ? $GLOBALS['updatelens_test']['options'][ $name ] : $default_value;
}

function wp_send_json_success( $data = null, $status = null ) {
	throw new UpdateLensTestJsonResponse( true, $data, $status );
}

function wp_send_json_error( $data = null, $status = null ) {
	throw new UpdateLensTestJsonResponse( false, $data, $status );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function home_url( $path = '' ) {
	return 'https://example.com' . $path;
}

function rest_url( $path = '' ) {
	return 'https://example.com/wp-json/' . $path;
}

function add_query_arg( $key, $value, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
}

function wp_create_nonce( $action = -1 ) {
	return 'wp_rest' === $action ? 'rest-nonce-0123' : 'other-nonce';
}

function wp_salt( $scheme = 'auth' ) {
	return 'test-site-salt';
}

function nocache_headers() {
	++$GLOBALS['updatelens_test']['nocache'];
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

class WP_HTTP_Response {
	public $headers = array();

	public function header( $key, $value, $replace = true ) {
		$this->headers[ $key ] = $value;
	}
}

class WP_REST_Response extends WP_HTTP_Response {
}

class WP_REST_Request {
	private $route;
	private $headers;

	public function __construct( $route, array $headers = array() ) {
		$this->route   = $route;
		$this->headers = $headers;
	}

	public function get_route() {
		return $this->route;
	}

	public function get_header( $key ) {
		$key = strtolower( str_replace( '-', '_', $key ) );
		return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : null;
	}
}

require_once __DIR__ . '/wpdb-recorder.php';

$GLOBALS['wpdb'] = new wpdb();
