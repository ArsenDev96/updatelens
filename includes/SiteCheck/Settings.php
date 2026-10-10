<?php
/**
 * Opt-in for site checks.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Site checks are off unless an administrator opts in: the option
 * `updatelens_site_checks` holds `enabled` (not autoloaded). Anything else,
 * including no option, means off. There is no UI for it yet.
 *
 * Only the start and report endpoints read it. The front-end marker does
 * not (that would cost ordinary visitors an option lookup); it honors only
 * live sessions, which exist only after an opted-in start, so turning the
 * option off stops new checks at once and existing sessions expire within
 * CheckSession::TTL seconds.
 */
final class Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'updatelens_site_checks';

	/**
	 * Value that enables site checks.
	 */
	const ENABLED = 'enabled';

	/**
	 * Whether site checks are enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return self::ENABLED === get_option( self::OPTION, '' );
	}

	/**
	 * Remove the option. Used on uninstall.
	 *
	 * @return void
	 */
	public static function uninstall() {
		delete_option( self::OPTION );
	}
}
