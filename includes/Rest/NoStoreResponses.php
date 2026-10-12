<?php
/**
 * No-store headers for UpdateLens's REST responses.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Rest;

use UpdateLens\Core\Plugin;
use UpdateLens\SiteCheck\FrontendMarker;
use WP_HTTP_Response;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Marks every response of the `updatelens/v1` routes `no-store, private`:
 * reports and settings are an administrator's data, never for a cache.
 *
 * WordPress sends `no-store, private` for signed-in REST requests itself only
 * since 6.8; before, its no-cache headers let a cache store the response.
 * The REST server sends them after `rest_post_dispatch`, so the
 * `nocache_headers` filter keeps no-store there too.
 */
final class NoStoreResponses {

	/**
	 * `rest_post_dispatch` filter.
	 *
	 * @param mixed $response Response.
	 * @param mixed $server   REST server.
	 * @param mixed $request  Request.
	 * @return mixed The response.
	 */
	public static function on_rest_post_dispatch( $response, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! $response instanceof WP_HTTP_Response || ! self::is_own_route( $request->get_route() ) ) {
			return $response;
		}

		add_filter( 'nocache_headers', array( FrontendMarker::class, 'no_store_headers' ) );
		$response->header( 'Cache-Control', FrontendMarker::CACHE_CONTROL );

		return $response;
	}

	/**
	 * Whether a route belongs to UpdateLens's namespace.
	 *
	 * @param mixed $route Route.
	 * @return bool
	 */
	private static function is_own_route( $route ) {
		$base = '/' . Plugin::REST_NAMESPACE;

		return is_string( $route ) && ( $base === $route || 0 === strpos( $route, $base . '/' ) );
	}
}
