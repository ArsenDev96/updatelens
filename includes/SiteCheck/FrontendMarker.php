<?php
/**
 * Verification markers on homepage and REST responses.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Marks responses that WordPress generated for a live check session, so the
 * browser can tell them from cached, proxied or error pages:
 *
 * - Homepage (`home_url( '/' )` with `?updatelens_check=<marker>`): at
 *   `send_headers`, if the marker is well-formed, the request is the
 *   homepage itself (no path, no query variables) and a live `home` session exists, sends the verification header
 *   and `Cache-Control: no-store, …, private` (explicitly: WordPress 6.2's
 *   nocache_headers() has no `no-store`), defines DONOTCACHEPAGE for page
 *   caches that honor it, and prints a hidden end-of-page marker at the very
 *   end of `wp_footer`.
 * - REST (`/wp/v2/users/me` with the `X-UpdateLens-Check` request header):
 *   adds the same two headers to the response, success or error.
 *
 * Ordinary requests (no marker) cost one isset() at `send_headers`; a
 * malformed marker costs one regular expression; only a well-formed marker
 * reads the session (one transient). The marker is public: it is the only
 * value echoed, after validation as lowercase hex, and never the secret.
 *
 * A full-page cache that answers before WordPress loads cannot be bypassed:
 * such responses have no or a stale marker and stay inconclusive.
 */
final class FrontendMarker {

	/**
	 * Homepage query argument carrying the marker.
	 */
	const QUERY_ARG = 'updatelens_check';

	/**
	 * Verification header (response) and REST request header.
	 */
	const HEADER = 'X-UpdateLens-Check';

	/**
	 * Attribute of the hidden end-of-page marker element.
	 */
	const END_MARKER_ATTRIBUTE = 'data-updatelens-check';

	/**
	 * Cache-Control of marked responses (and of the Ajax endpoints).
	 */
	const CACHE_CONTROL = 'no-store, no-cache, must-revalidate, max-age=0, private';

	/**
	 * Sessions.
	 *
	 * @var SessionManager
	 */
	private $manager;

	/**
	 * Sends one raw header line: function ( string $line ): void.
	 *
	 * @var callable
	 */
	private $send_header;

	/**
	 * Marker of this homepage request's live session.
	 *
	 * @var string|null
	 */
	private $marker = null;

	/**
	 * Constructor.
	 *
	 * @param SessionManager $manager     Sessions.
	 * @param callable|null  $send_header Header sender (default `header()`).
	 */
	public function __construct( SessionManager $manager, ?callable $send_header = null ) {
		$this->manager     = $manager;
		$this->send_header = null === $send_header ? 'header' : $send_header;
	}

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'send_headers', array( $this, 'on_send_headers' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'on_rest_post_dispatch' ), 10, 3 );
	}

	/**
	 * Mark a homepage response for a live session.
	 *
	 * @param mixed $wp The WP object (parsed request).
	 * @return void
	 */
	public function on_send_headers( $wp ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only public marker; validated as hex before use.
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) ) {
			return;
		}
		$marker = wp_unslash( $_GET[ self::QUERY_ARG ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by CheckSession::is_marker() (lowercase hex only).
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! CheckSession::is_marker( $marker ) || ! self::is_homepage_request( $wp ) ) {
			return;
		}
		if ( null === $this->manager->find_live( CheckDefinition::HOME, $marker ) ) {
			return;
		}
		// The homepage check observes what anonymous visitors get. A browser
		// that sends cookies despite `credentials: 'omit'` would get the
		// signed-in render (admin bar, no page cache): leave it unverified, so
		// the check is inconclusive instead of passing for the wrong page.
		if ( is_user_logged_in() ) {
			return;
		}

		$this->marker = $marker;
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Page-cache convention.
		}
		add_filter( 'nocache_headers', array( self::class, 'no_store_headers' ) );
		nocache_headers();
		call_user_func( $this->send_header, 'Cache-Control: ' . self::CACHE_CONTROL );
		call_user_func( $this->send_header, self::HEADER . ': ' . $marker );
		add_action( 'wp_footer', array( $this, 'print_end_marker' ), PHP_INT_MAX );
	}

	/**
	 * Whether the parsed request is the homepage itself: no path below home and
	 * no query variables (with plain permalinks every page has an empty path,
	 * e.g. `/?p=1`). A plugin that adds query variables to the homepage turns
	 * the marker off there, which leaves the check inconclusive, never passed.
	 *
	 * @param mixed $wp The WP object.
	 * @return bool
	 */
	private static function is_homepage_request( $wp ) {
		return is_object( $wp ) && isset( $wp->request, $wp->query_vars ) && '' === $wp->request && array() === $wp->query_vars;
	}

	/**
	 * `nocache_headers` filter for site check responses: WordPress 6.2's
	 * no-cache headers lack `no-store, private`, and WordPress may send them
	 * again after UpdateLens has (the Ajax die handler, the REST server).
	 *
	 * @param mixed $headers Header names and values.
	 * @return array
	 */
	public static function no_store_headers( $headers ) {
		$headers                  = is_array( $headers ) ? $headers : array();
		$headers['Cache-Control'] = self::CACHE_CONTROL;

		return $headers;
	}

	/**
	 * Print the end-of-page marker (last `wp_footer` output).
	 *
	 * @return void
	 */
	public function print_end_marker() {
		if ( null === $this->marker ) {
			return;
		}
		// Attribute name: END_MARKER_ATTRIBUTE.
		echo '<span hidden data-updatelens-check="' . esc_attr( $this->marker ) . '"></span>';
	}

	/**
	 * Mark a `users/me` REST response for a live session.
	 *
	 * @param mixed $response Response.
	 * @param mixed $server   REST server.
	 * @param mixed $request  Request.
	 * @return mixed The response.
	 */
	public function on_rest_post_dispatch( $response, $server, $request ) {
		if ( ! $request instanceof \WP_REST_Request || ! $response instanceof \WP_HTTP_Response || CheckDefinition::REST_ROUTE !== $request->get_route() ) {
			return $response;
		}
		$marker = $request->get_header( self::HEADER );
		if ( null === $this->manager->find_live( CheckDefinition::REST, $marker ) ) {
			return $response;
		}

		// The REST server sends wp_get_nocache_headers() after this filter for
		// signed-in users; the filter keeps no-store there too.
		add_filter( 'nocache_headers', array( self::class, 'no_store_headers' ) );
		$response->header( 'Cache-Control', self::CACHE_CONTROL );
		$response->header( self::HEADER, $marker );

		return $response;
	}
}
