<?php
/**
 * Tests for UpdateClassifier.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use UpdateLens\Update\UpdateClassifier;

/**
 * Which updates are analysed.
 */
final class UpdateClassifierTest extends TestCase {

	const PLUGIN = 'acme/acme.php';

	/**
	 * Context for Plugin_Upgrader::upgrade() (update.php?action=upgrade-plugin) in wp-admin.
	 *
	 * @param array $overrides Overrides.
	 * @return array
	 */
	private function context( array $overrides = array() ) {
		return array_replace(
			array(
				'is_plugin_upgrader'   => true,
				'bulk'                 => false,
				'update_count'         => 0,
				'hook_extra'           => array(
					'plugin'      => self::PLUGIN,
					'type'        => 'plugin',
					'action'      => 'update',
					'temp_backup' => array( 'slug' => 'acme' ),
				),
				'is_multisite'         => false,
				'is_interactive_admin' => true,
				'self_plugin'          => 'updatelens/updatelens.php',
			),
			$overrides
		);
	}

	/**
	 * Single update via Plugin_Upgrader::upgrade() is analysed.
	 */
	public function test_single_upgrade_is_accepted() {
		$this->assertSame( self::PLUGIN, UpdateClassifier::plugin_to_analyze( $this->context() ) );
	}

	/**
	 * "Update now" (Ajax) runs bulk_upgrade() with one plugin and no type/action: accepted.
	 */
	public function test_one_plugin_bulk_upgrade_is_accepted() {
		$context = $this->context(
			array(
				'bulk'         => true,
				'update_count' => 1,
				'hook_extra'   => array(
					'plugin'      => self::PLUGIN,
					'temp_backup' => array( 'slug' => 'acme' ),
				),
			)
		);

		$this->assertSame( self::PLUGIN, UpdateClassifier::plugin_to_analyze( $context ) );
	}

	/**
	 * Updates and requests that are ignored.
	 *
	 * @return array<string, array{array}>
	 */
	public function provide_ignored() {
		return array(
			'bulk update of two plugins'   => array(
				array(
					'bulk'         => true,
					'update_count' => 2,
					'hook_extra'   => array( 'plugin' => self::PLUGIN ),
				),
			),
			'bulk with unknown count'      => array(
				array(
					'bulk'         => true,
					'update_count' => 0,
					'hook_extra'   => array( 'plugin' => self::PLUGIN ),
				),
			),
			'plugin install'               => array(
				array(
					'hook_extra' => array(
						'type'   => 'plugin',
						'action' => 'install',
					),
				),
			),
			'install action with a plugin' => array(
				array(
					'hook_extra' => array(
						'plugin' => self::PLUGIN,
						'type'   => 'plugin',
						'action' => 'install',
					),
				),
			),
			'theme update'                 => array(
				array(
					'is_plugin_upgrader' => false,
					'hook_extra'         => array(
						'theme'  => 'twentytwentyfive',
						'type'   => 'theme',
						'action' => 'update',
					),
				),
			),
			'core update'                  => array(
				array(
					'is_plugin_upgrader' => false,
					'hook_extra'         => array(),
				),
			),
			'non-plugin type'              => array(
				array(
					'hook_extra' => array(
						'plugin' => self::PLUGIN,
						'type'   => 'theme',
						'action' => 'update',
					),
				),
			),
			'UpdateLens itself'            => array( array( 'self_plugin' => self::PLUGIN ) ),
			'multisite'                    => array( array( 'is_multisite' => true ) ),
			'cron / WP-CLI / frontend'     => array( array( 'is_interactive_admin' => false ) ),
			'missing hook_extra'           => array( array( 'hook_extra' => null ) ),
		);
	}

	/**
	 * Ignored updates return null.
	 *
	 * @dataProvider provide_ignored
	 *
	 * @param array $overrides Context overrides.
	 */
	public function test_ignored( array $overrides ) {
		$this->assertNull( UpdateClassifier::plugin_to_analyze( $this->context( $overrides ) ) );
	}

	/**
	 * Completion data of both single paths maps to the plugin.
	 */
	public function test_completed_plugin_for_single_paths() {
		$this->assertSame(
			self::PLUGIN,
			UpdateClassifier::completed_plugin(
				array(
					'plugin' => self::PLUGIN,
					'type'   => 'plugin',
					'action' => 'update',
				)
			)
		);
		$this->assertSame(
			self::PLUGIN,
			UpdateClassifier::completed_plugin(
				array(
					'action'  => 'update',
					'type'    => 'plugin',
					'bulk'    => true,
					'plugins' => array( self::PLUGIN ),
				)
			)
		);
	}

	/**
	 * Completion data that is not a single plugin update.
	 *
	 * @return array<string, array{array}>
	 */
	public function provide_ignored_completions() {
		return array(
			'bulk of two'  => array(
				array(
					'action'  => 'update',
					'type'    => 'plugin',
					'bulk'    => true,
					'plugins' => array( self::PLUGIN, 'other/other.php' ),
				),
			),
			'install'      => array(
				array(
					'action' => 'install',
					'type'   => 'plugin',
				),
			),
			'theme'        => array(
				array(
					'action' => 'update',
					'type'   => 'theme',
					'themes' => array( 'twentytwentyfive' ),
				),
			),
			'translations' => array(
				array(
					'action'       => 'update',
					'type'         => 'translation',
					'translations' => array(),
				),
			),
			'core'         => array(
				array(
					'action' => 'update',
					'type'   => 'core',
				),
			),
			'empty'        => array( array() ),
		);
	}

	/**
	 * Non-single completions are ignored.
	 *
	 * @dataProvider provide_ignored_completions
	 *
	 * @param array $hook_extra Completion data.
	 */
	public function test_ignored_completions( array $hook_extra ) {
		$this->assertNull( UpdateClassifier::completed_plugin( $hook_extra ) );
	}
}
