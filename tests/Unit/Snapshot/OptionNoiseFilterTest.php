<?php
/**
 * Tests for OptionNoiseFilter.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Snapshot;

use PHPUnit\Framework\TestCase;
use UpdateLens\Snapshot\OptionNoiseFilter;

/**
 * Option names excluded from the `wp_options` snapshot.
 */
final class OptionNoiseFilterTest extends TestCase {

	/**
	 * Excluded names.
	 *
	 * @return array<string, array{string}>
	 */
	public function provide_noise() {
		return array(
			'transient'               => array( '_transient_feed_abc123' ),
			'transient timeout'       => array( '_transient_timeout_feed_abc123' ),
			'site transient'          => array( '_site_transient_update_plugins' ),
			'site transient timeout'  => array( '_site_transient_timeout_theme_roots' ),
			'bare transient prefix'   => array( '_transient_' ),
			'UpdateLens option'       => array( 'updatelens_settings' ),
			'UpdateLens future state' => array( 'updatelens_snapshot_state' ),
			'WP-Cron storage'         => array( 'cron' ),
		);
	}

	/**
	 * Included names, including large or frequently changing persistent options.
	 *
	 * @return array<string, array{string}>
	 */
	public function provide_included() {
		return array(
			'plugin option'                => array( 'woocommerce_version' ),
			'core option'                  => array( 'siteurl' ),
			'active plugins'               => array( 'active_plugins' ),
			'rewrite rules'                => array( 'rewrite_rules' ),
			'user roles'                   => array( 'wp_user_roles' ),
			'name containing transient'    => array( 'my_plugin_transient_settings' ),
			'transient without underscore' => array( 'transient_feed' ),
			'name starting with cron'      => array( 'cron_settings' ),
			'name ending with cron'        => array( 'my_plugin_cron' ),
			'updatelens without separator' => array( 'updatelens' ),
			'updatelens not at start'      => array( 'my_updatelens_option' ),
			'uppercase transient prefix'   => array( '_TRANSIENT_foo' ),
			'empty name'                   => array( '' ),
		);
	}

	/**
	 * Noise is excluded.
	 *
	 * @dataProvider provide_noise
	 *
	 * @param string $name Option name.
	 */
	public function test_noise_is_excluded( $name ) {
		$this->assertTrue( ( new OptionNoiseFilter() )->is_noise( $name ) );
	}

	/**
	 * Persistent options are included.
	 *
	 * @dataProvider provide_included
	 *
	 * @param string $name Option name.
	 */
	public function test_persistent_options_are_included( $name ) {
		$this->assertFalse( ( new OptionNoiseFilter() )->is_noise( $name ) );
	}
}
