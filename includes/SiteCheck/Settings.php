<?php
/**
 * Opt-in for site checks.
 *
 * @package UpdateLens
 */

namespace UpdateLens\SiteCheck;

defined( 'ABSPATH' ) || exit;

/**
 * Site checks are off unless an administrator opts in on the UpdateLens
 * Settings screen (Rest\SettingsController): the option
 * `updatelens_site_checks` holds `enabled` (not autoloaded). Anything else,
 * including no option, means off. Nothing turns it on automatically.
 *
 * The saved preference and whether checks may run are separate: a host or
 * site configuration can keep them off with the `updatelens_site_checks_enabled`
 * filter (return false) whatever the preference; the filter never turns them
 * on. Everything that starts or accepts checks reads is_enabled() (both).
 *
 * Only the start and report endpoints, the update screens and the update
 * request read it. The front-end marker does not (that would cost ordinary
 * visitors an option lookup); it honors only live sessions, which exist only
 * after an opted-in start, so turning site checks off stops new checks at
 * once and existing sessions expire within CheckSession::TTL seconds.
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
	 * Value stored when an administrator turns site checks off.
	 */
	const DISABLED = 'disabled';

	/**
	 * Filter that can keep site checks off (return false). is_allowed()
	 * writes the name out, so tools that index hooks find it.
	 */
	const FILTER = 'updatelens_site_checks_enabled';

	/**
	 * Whether site checks may run: the saved preference is on and no host or
	 * site configuration keeps them off.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return self::is_saved_enabled() && self::is_allowed();
	}

	/**
	 * The administrator's saved preference.
	 *
	 * @return bool
	 */
	public static function is_saved_enabled() {
		return self::ENABLED === get_option( self::OPTION, '' );
	}

	/**
	 * Whether the site's configuration allows site checks.
	 *
	 * @return bool
	 */
	public static function is_allowed() {
		/**
		 * Filters whether UpdateLens may run site checks on this site.
		 *
		 * Return false to keep site checks off whatever an administrator saved
		 * on the UpdateLens Settings screen; the screen then says they are
		 * turned off by the site's configuration. Returning true never turns
		 * them on: the saved setting must be on as well.
		 *
		 * @param bool $allowed Whether site checks may run. Default true.
		 */
		return (bool) apply_filters( 'updatelens_site_checks_enabled', true );
	}

	/**
	 * Save the preference (not autoloaded).
	 *
	 * @param bool $enabled Whether site checks should be on.
	 * @return bool Whether the stored preference now matches.
	 */
	public static function save( $enabled ) {
		$value = $enabled ? self::ENABLED : self::DISABLED;
		if ( get_option( self::OPTION, '' ) !== $value ) {
			update_option( self::OPTION, $value, false );
		}

		return self::is_saved_enabled() === (bool) $enabled;
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
