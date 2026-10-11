<?php
/**
 * Stand-ins for an initialized Action Scheduler with its database store.
 *
 * @package UpdateLens
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Squiz.Commenting, Generic.Files.OneObjectStructurePerFile, PEAR.NamingConventions.ValidClassName -- Test stand-ins for a third-party library.

class ActionScheduler_DBStore {
}

class ActionScheduler {
	public static function is_initialized() {
		return true;
	}

	public static function store() {
		return new ActionScheduler_DBStore();
	}
}
