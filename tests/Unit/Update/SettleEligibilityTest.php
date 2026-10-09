<?php
/**
 * Tests for SettleEligibility.
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use UpdateLens\Update\SettleEligibility;

/**
 * Which requests may settle at shutdown.
 */
final class SettleEligibilityTest extends TestCase {

	/**
	 * A wp-admin page request by an administrator that reached `admin_init`.
	 *
	 * @param array $overrides Changed keys.
	 * @return array
	 */
	private static function context( array $overrides = array() ) {
		return array_merge(
			array(
				'is_admin'           => true,
				'doing_ajax'         => false,
				'doing_cron'         => false,
				'is_cli'             => false,
				'is_rest'            => false,
				'logged_in'          => true,
				'can_update_plugins' => true,
				'admin_init_done'    => true,
			),
			$overrides
		);
	}

	/**
	 * Requests that may not settle.
	 *
	 * @return array<string, array{array}>
	 */
	public function provide_ineligible() {
		return array(
			'anonymous wp-admin request (login redirect)' => array(
				array(
					'logged_in'          => false,
					'can_update_plugins' => false,
					'admin_init_done'    => false,
				),
			),
			'anonymous admin-post.php (admin_init ran)'   => array(
				array(
					'logged_in'          => false,
					'can_update_plugins' => false,
				),
			),
			'logged in without update_plugins (e.g. subscriber profile)' => array( array( 'can_update_plugins' => false ) ),
			'stopped before admin_init'                   => array( array( 'admin_init_done' => false ) ),
			'Ajax (Heartbeat)'                            => array( array( 'doing_ajax' => true ) ),
			'cron'                                        => array( array( 'doing_cron' => true ) ),
			'WP-CLI'                                      => array( array( 'is_cli' => true ) ),
			'REST'                                        => array( array( 'is_rest' => true ) ),
			'frontend'                                    => array( array( 'is_admin' => false ) ),
		);
	}

	/**
	 * An administrator's wp-admin page request may settle.
	 */
	public function test_admin_page_request() {
		$this->assertTrue( SettleEligibility::may_settle_at_shutdown( self::context() ) );
	}

	/**
	 * Every other request may not.
	 *
	 * @dataProvider provide_ineligible
	 *
	 * @param array $overrides Changed keys.
	 */
	public function test_ineligible( array $overrides ) {
		$this->assertFalse( SettleEligibility::may_settle_at_shutdown( self::context( $overrides ) ) );
	}

	/**
	 * Missing facts never make a request eligible.
	 */
	public function test_missing_context() {
		$this->assertFalse( SettleEligibility::may_settle_at_shutdown( array() ) );
		$this->assertFalse( SettleEligibility::may_settle_at_shutdown( array( 'is_admin' => true ) ) );
	}
}
