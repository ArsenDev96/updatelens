<?php
/**
 * Whether a site check target is on the admin page's origin.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

defined( 'ABSPATH' ) || exit;

/**
 * The browser runs site checks from wp-admin and only requests targets on
 * the page's own origin (`src/admin/site-check/observe.ts`, `cross_origin`
 * / `mixed_content`): no cross-origin requests, so no CORS and no
 * credentials sent elsewhere. A target on another origin can never be
 * checked, so no session is opened for it (AjaxEndpoints), no baseline
 * script runs for it (BaselinePage) and an update records it as
 * BeforeReason::CROSS_ORIGIN (Update\SiteCheckObservation).
 *
 * The admin page's origin is that of `admin_url( 'admin-ajax.php' )` in the
 * same request: the runner refuses to start unless that URL is on the
 * page's origin. Origins compare scheme, host and effective port like a
 * browser (case-insensitive scheme and host, 80/443 implied); paths never
 * count, so WordPress in a subdirectory is the same origin. Pure.
 */
final class TargetOrigin {

	/**
	 * Origin of an http(s) URL: `scheme://host` plus `:port` unless it is the
	 * scheme's default.
	 *
	 * @param mixed $url URL.
	 * @return string|null Null for anything but an http(s) URL with a host and no credentials.
	 */
	public static function of( $url ) {
		$canonical = ContextHasher::canonical_target( $url );
		if ( null === $canonical ) {
			return null;
		}
		// The canonical form always has a path that starts with `/` after the authority.
		$slash = strpos( $canonical, '/', strpos( $canonical, '://' ) + 3 );

		return false === $slash ? $canonical : substr( $canonical, 0, $slash );
	}

	/**
	 * Checks whose target is not on the reference origin (an unreadable
	 * target or reference counts as another origin).
	 *
	 * @param array<string, string> $urls      Target URL per check identifier.
	 * @param mixed                 $reference A URL on the admin page's origin.
	 * @return string[] Check identifiers, in the order of $urls.
	 */
	public static function unsupported( array $urls, $reference ) {
		$origin      = self::of( $reference );
		$unsupported = array();
		foreach ( $urls as $check => $url ) {
			if ( null === $origin || self::of( $url ) !== $origin ) {
				$unsupported[] = (string) $check;
			}
		}

		return $unsupported;
	}
}
