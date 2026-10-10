<?php
/**
 * Tests for the WordPress side of site checks (endpoints and markers).
 *
 * @package UpdateLens
 */

namespace UpdateLens\Tests\Unit\SiteCheck;

use PHPUnit\Framework\TestCase;
use UpdateLens\Core\Plugin;
use UpdateLens\SiteCheck\AjaxEndpoints;
use UpdateLens\SiteCheck\ContextHasher;
use UpdateLens\SiteCheck\FrontendMarker;
use UpdateLens\SiteCheck\RateLimiter;
use UpdateLens\SiteCheck\SessionManager;
use UpdateLens\Storage\Schema;
use UpdateLens\Tests\Support\InMemorySessionStore;
use UpdateLens\Tests\Support\SiteCheckFixture as F;

/**
 * Admin-ajax endpoints (nonce, capability, opt-in, login session, contract)
 * and the homepage/REST verification markers, with WordPress stand-ins.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SiteCheckWordPressTest extends TestCase {

	const SECRET = 'sk_live_fake_secret_4f2a9c';

	/**
	 * Session store.
	 *
	 * @var InMemorySessionStore
	 */
	private $store;

	/**
	 * Fake time.
	 *
	 * @var float
	 */
	private $time = 1760090000.25;

	/**
	 * Sessions.
	 *
	 * @var SessionManager
	 */
	private $manager;

	/**
	 * Load the stand-ins and build the manager.
	 *
	 * @before
	 */
	public function load_wordpress_stubs() {
		require dirname( __DIR__, 2 ) . '/fixtures/wp-site-check-stubs.php';
		$_POST         = array();
		$_GET          = array();
		$this->store   = new InMemorySessionStore();
		$meta          = array();
		$this->manager = new SessionManager(
			$this->store,
			function () {
				return $this->time;
			},
			'random_bytes',
			new RateLimiter(
				function ( $user ) use ( &$meta ) {
					return isset( $meta[ $user ] ) ? $meta[ $user ] : '';
				},
				function ( $user, $value ) use ( &$meta ) {
					$meta[ $user ] = $value;
				}
			)
		);
	}

	/**
	 * Run an endpoint and return its JSON response.
	 *
	 * @param string $action `start` or `report`.
	 * @param array  $post   POST fields (slashed like WordPress's).
	 * @return array{success: bool, data: array, status: int|null}
	 */
	private function call( $action, array $post ) {
		$_POST     = $post;
		$endpoints = new AjaxEndpoints( $this->manager, new ContextHasher( 'test-site-salt' ) );
		try {
			'start' === $action ? $endpoints->on_start() : $endpoints->on_report();
		} catch ( \UpdateLensTestJsonResponse $response ) {
			return array(
				'success' => $response->success,
				'data'    => $response->data,
				'status'  => $response->status,
			);
		}
		$this->fail( 'The endpoint did not respond.' );
	}

	/**
	 * Start a run as the current stand-in user.
	 *
	 * @return array Checks by identifier.
	 */
	private function start() {
		$response = $this->call( 'start', array( 'purpose' => 'before' ) );
		$this->assertTrue( $response['success'] );

		return array_column( $response['data']['checks'], null, 'check' );
	}

	/**
	 * Only logged-in admin-ajax actions are registered.
	 */
	public function test_registers_logged_in_actions_only() {
		( new AjaxEndpoints( $this->manager ) )->register();
		( new FrontendMarker( $this->manager ) )->register();

		$this->assertSame(
			array( 'wp_ajax_updatelens_site_check_start', 'wp_ajax_updatelens_site_check_report', 'send_headers', 'rest_post_dispatch' ),
			array_column( $GLOBALS['updatelens_test']['actions'], 0 )
		);
	}

	/**
	 * The start contract: one entry per check with URLs built by PHP.
	 */
	public function test_start_contract() {
		$response = $this->call( 'start', array( 'purpose' => 'before' ) );

		$this->assertTrue( $response['success'] );
		$this->assertNull( $response['status'] );
		$data = $response['data'];
		$this->assertSame( array( 'code', 'purpose', 'expires_in', 'timeout_ms', 'retry_delay_ms', 'max_attempts', 'header', 'end_marker_attribute', 'checks' ), array_keys( $data ) );
		$this->assertSame( array( 'started', 'before', 120, 10000, 5000, 2, 'X-UpdateLens-Check', 'data-updatelens-check' ), array_slice( array_values( $data ), 0, 8 ) );

		list( $home, $rest ) = $data['checks'];
		$this->assertSame( array( 'check', 'definition', 'session', 'secret', 'url' ), array_keys( $home ) );
		$this->assertSame( 'https://example.com/?updatelens_check=' . $home['session'], $home['url'] );
		$this->assertSame( array( 'check', 'definition', 'session', 'secret', 'url', 'rest_nonce', 'user' ), array_keys( $rest ) );
		$this->assertSame( 7, $rest['user'], 'The signed-in user, never a request value.' );
		$this->assertSame( 'https://example.com/wp-json/wp/v2/users/me?_fields=id', $rest['url'] );
		$this->assertSame( 'rest-nonce-0123', $rest['rest_nonce'] );

		$hasher  = new ContextHasher( 'test-site-salt' );
		$session = $this->manager->find_live( 'home', $home['session'] );
		$this->assertSame( $hasher->target( 'https://example.com/' ), $session->get_target() );
		$this->assertSame( $hasher->session( 'login-session-token-of-user-7' ), $session->get_login() );
		$this->assertSame( $hasher->target( $rest['url'] ), $this->manager->find_live( 'rest', $rest['session'] )->get_target() );
	}

	/**
	 * Nonce, capability, opt-in and login session are required, in that
	 * order, before any input is used.
	 */
	public function test_authorization() {
		$cases = array(
			'invalid nonce'      => array( 'nonce_valid', false, 'invalid_nonce' ),
			'no capability'      => array( 'caps', array( 'read' ), 'forbidden' ),
			'opt-in missing'     => array( 'options', array(), 'disabled' ),
			'opt-in not enabled' => array( 'options', array( 'updatelens_site_checks' => 'yes' ), 'disabled' ),
			'no login session'   => array( 'token', '', 'forbidden' ),
		);
		foreach ( $cases as $name => $case ) {
			$saved                                  = $GLOBALS['updatelens_test'][ $case[0] ];
			$GLOBALS['updatelens_test'][ $case[0] ] = $case[1];
			foreach ( array( 'start', 'report' ) as $action ) {
				$response = $this->call( $action, array( 'purpose' => 'before' ) );
				$this->assertSame( array( false, array( 'code' => $case[2] ), 403 ), array_values( $response ), "$name ($action)" );
			}
			$GLOBALS['updatelens_test'][ $case[0] ] = $saved;
		}
		$this->assertSame( array(), $this->store->values, 'No session was started.' );
	}

	/**
	 * Start errors and their statuses.
	 */
	public function test_start_errors() {
		$this->assertSame( array( false, array( 'code' => 'invalid_request' ), 400 ), array_values( $this->call( 'start', array( 'purpose' => 'probe' ) ) ) );
		$this->assertSame( array( false, array( 'code' => 'invalid_request' ), 400 ), array_values( $this->call( 'start', array( 'purpose' => array( 'before' ) ) ) ) );
		for ( $i = 0; $i < 12; $i++ ) {
			$this->start();
		}
		$this->assertSame(
			array(
				false,
				array(
					'code'        => 'rate_limited',
					'retry_after' => 600,
				),
				429,
			),
			array_values( $this->call( 'start', array( 'purpose' => 'after' ) ) )
		);

		$this->store->puts_before_failure   = 0;
		$GLOBALS['updatelens_test']['user'] = 8;
		$this->assertSame( array( false, array( 'code' => 'unavailable' ), 503 ), array_values( $this->call( 'start', array( 'purpose' => 'after' ) ) ) );
	}

	/**
	 * Report: accepted once (slashed input), then the fixed error codes.
	 */
	public function test_report() {
		$checks = $this->start();
		$home   = $checks['home'];
		$report = function ( $check, $attempts ) {
			return addslashes(
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test input.
				json_encode(
					array(
						'check'    => $check,
						'attempts' => $attempts,
					)
				)
			);
		};
		$post   = array(
			'session' => $home['session'],
			'secret'  => $home['secret'],
			'report'  => $report(
				'home',
				array(
					F::home(
						array(
							'status'     => 500,
							'header'     => 'absent',
							'end_marker' => 'absent',
						)
					),
					F::home(
						array(
							'status'     => 500,
							'header'     => 'absent',
							'end_marker' => 'absent',
						)
					),
				)
			),
		);

		$invalid = array_merge( $post, array( 'report' => $report( 'rest', array( F::rest() ) ) ) );
		$this->assertSame(
			array(
				false,
				array(
					'code'   => 'invalid_report',
					'detail' => 'check_mismatch',
				),
				400,
			),
			array_values( $this->call( 'report', $invalid ) )
		);

		$GLOBALS['updatelens_test']['user'] = 8;
		$this->assertSame( array( false, array( 'code' => 'not_owner' ), 403 ), array_values( $this->call( 'report', $post ) ) );
		$GLOBALS['updatelens_test']['user']  = 7;
		$GLOBALS['updatelens_test']['token'] = 'another-login-of-user-7';
		$this->assertSame( array( false, array( 'code' => 'not_owner' ), 403 ), array_values( $this->call( 'report', $post ) ) );
		$GLOBALS['updatelens_test']['token'] = 'login-session-token-of-user-7';

		$this->assertSame(
			array(
				true,
				array(
					'code'    => 'accepted',
					'check'   => 'home',
					'purpose' => 'before',
					'verdict' => 'fail',
					'reason'  => 'server_error',
				),
				null,
			),
			array_values( $this->call( 'report', $post ) )
		);
		$this->assertSame( array( false, array( 'code' => 'session_unavailable' ), 410 ), array_values( $this->call( 'report', $post ) ), 'Replay.' );
		$this->assertSame(
			array( false, array( 'code' => 'invalid_request' ), 400 ),
			array_values(
				$this->call(
					'report',
					array(
						'session' => array( $home['session'] ),
						'secret'  => $home['secret'],
					)
				)
			)
		);

		$rest                      = $checks['rest'];
		$this->store->before_claim = function () use ( $rest, $report ) {
			$this->call(
				'report',
				array(
					'session' => $rest['session'],
					'secret'  => $rest['secret'],
					'report'  => $report( 'rest', array( F::rest() ) ),
				)
			);
		};
		$this->assertSame(
			array( false, array( 'code' => 'already_reported' ), 409 ),
			array_values(
				$this->call(
					'report',
					array(
						'session' => $rest['session'],
						'secret'  => $rest['secret'],
						'report'  => $report( 'rest', array( F::rest() ) ),
					)
				)
			)
		);
	}

	/**
	 * Error responses never contain submitted values.
	 */
	public function test_errors_do_not_echo_input() {
		$checks    = $this->start();
		$responses = array(
			$this->call( 'start', array( 'purpose' => self::SECRET ) ),
			$this->call(
				'report',
				array(
					'session' => self::SECRET,
					'secret'  => self::SECRET,
					'report'  => self::SECRET,
				)
			),
			$this->call(
				'report',
				array(
					'session' => $checks['home']['session'],
					'secret'  => $checks['home']['secret'],
					'report'  => '{"check":"' . self::SECRET . '","attempts":[]}',
				)
			),
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Asserts the response form.
		$this->assertStringNotContainsString( self::SECRET, json_encode( $responses ) );
	}

	/**
	 * Homepage marker: only a live home session, only on the homepage.
	 */
	public function test_homepage_marker() {
		$checks  = $this->start();
		$headers = array();
		$marker  = new FrontendMarker(
			$this->manager,
			function ( $line ) use ( &$headers ) {
				$headers[] = $line;
			}
		);
		$wp      = (object) array(
			'request'    => '',
			'query_vars' => array(),
		);
		$reads   = $this->store->reads;

		$marker->on_send_headers( $wp );
		foreach ( array( 'ABC', str_repeat( 'z', 32 ), array( 'x' ), $checks['home']['secret'] ) as $malformed ) {
			$_GET['updatelens_check'] = $malformed;
			$marker->on_send_headers( $wp );
		}
		$this->assertSame( $reads, $this->store->reads, 'No lookup without a well-formed marker.' );

		foreach ( array( str_repeat( 'a', 32 ), $checks['rest']['session'] ) as $not_live ) {
			$_GET['updatelens_check'] = $not_live;
			$marker->on_send_headers( $wp );
		}
		$_GET['updatelens_check'] = $checks['home']['session'];
		$marker->on_send_headers(
			(object) array(
				'request'    => 'sample-page',
				'query_vars' => array( 'pagename' => 'sample-page' ),
			)
		);
		$marker->on_send_headers(
			(object) array(
				'request'    => '',
				'query_vars' => array( 'p' => '1' ),
			)
		);
		$marker->on_send_headers( (object) array( 'request' => '' ) );
		$this->time = 1760090120.25;
		$marker->on_send_headers( $wp );
		$this->time = 1760090000.25;

		$this->assertSame( array(), $headers );
		$this->assertSame( 0, $GLOBALS['updatelens_test']['nocache'] );
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );
		$this->assertNotContains( 'wp_footer', array_column( $GLOBALS['updatelens_test']['actions'], 0 ) );
		ob_start();
		$marker->print_end_marker();
		$this->assertSame( '', ob_get_clean() );

		$marker->on_send_headers( $wp );
		$this->assertSame( array( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', 'X-UpdateLens-Check: ' . $checks['home']['session'] ), $headers );
		$this->assertSame( 1, $GLOBALS['updatelens_test']['nocache'] );
		$this->assertTrue( DONOTCACHEPAGE );
		$this->assertContains( array( 'wp_footer', array( $marker, 'print_end_marker' ), PHP_INT_MAX ), $GLOBALS['updatelens_test']['actions'] );
		ob_start();
		$marker->print_end_marker();
		$this->assertSame( '<span hidden data-updatelens-check="' . $checks['home']['session'] . '"></span>', ob_get_clean() );
		$this->assertSame( FrontendMarker::END_MARKER_ATTRIBUTE, 'data-updatelens-check' );
	}

	/**
	 * REST marker: only `users/me` with a live REST session's marker, on
	 * errors too.
	 */
	public function test_rest_marker() {
		$checks = $this->start();
		$marker = new FrontendMarker( $this->manager );
		$header = array( 'x_updatelens_check' => $checks['rest']['session'] );

		$cases = array(
			'other route'       => array( new \WP_REST_Request( '/wp/v2/users', $header ), array() ),
			'no header'         => array( new \WP_REST_Request( '/wp/v2/users/me' ), array() ),
			'home marker'       => array( new \WP_REST_Request( '/wp/v2/users/me', array( 'x_updatelens_check' => $checks['home']['session'] ) ), array() ),
			'malformed'         => array( new \WP_REST_Request( '/wp/v2/users/me', array( 'x_updatelens_check' => 'x' ) ), array() ),
			'live REST session' => array(
				new \WP_REST_Request( '/wp/v2/users/me', $header ),
				array(
					'Cache-Control'      => 'no-store, no-cache, must-revalidate, max-age=0, private',
					'X-UpdateLens-Check' => $checks['rest']['session'],
				),
			),
		);
		foreach ( $cases as $name => $case ) {
			$response = new \WP_REST_Response();
			$this->assertSame( $response, $marker->on_rest_post_dispatch( $response, null, $case[0] ), $name );
			$this->assertSame( $case[1], $response->headers, $name );
		}
		$this->assertSame( 'not a response', $marker->on_rest_post_dispatch( 'not a response', null, new \WP_REST_Request( '/wp/v2/users/me', $header ) ) );

		$this->time = 1760090120.25;
		$response   = new \WP_REST_Response();
		$marker->on_rest_post_dispatch( $response, null, new \WP_REST_Request( '/wp/v2/users/me', $header ) );
		$this->assertSame( array(), $response->headers, 'Expired.' );
	}

	/**
	 * The composition root registers the site check hooks on single sites
	 * (Ajax actions only in admin requests) and none on multisite.
	 */
	public function test_plugin_registers_site_checks() {
		$GLOBALS['updatelens_test']['options']['updatelens_db_version'] = Schema::VERSION;
		$GLOBALS['updatelens_test']['admin']                            = true;
		Plugin::boot();
		$hooks = array_column( $GLOBALS['updatelens_test']['actions'], 0 );

		foreach ( array( 'send_headers', 'rest_post_dispatch', 'wp_ajax_updatelens_site_check_start', 'wp_ajax_updatelens_site_check_report' ) as $hook ) {
			$this->assertContains( $hook, $hooks );
		}
		$this->assertNotContains( 'wp_ajax_nopriv_updatelens_site_check_start', $hooks );
		$this->assertNotContains( 'wp_ajax_nopriv_updatelens_site_check_report', $hooks );
	}

	/**
	 * No site check hooks on multisite.
	 */
	public function test_plugin_skips_site_checks_on_multisite() {
		$GLOBALS['updatelens_test']['multisite'] = true;
		Plugin::boot();
		$hooks = array_column( $GLOBALS['updatelens_test']['actions'], 0 );

		$this->assertNotContains( 'send_headers', $hooks );
		$this->assertNotContains( 'rest_post_dispatch', $hooks );
	}

	/**
	 * Responses for site checks keep no-store, private even when WordPress
	 * sends its no-cache headers again later (WordPress 6.2 omits both).
	 */
	public function test_no_store_survives_later_nocache_headers() {
		$filter = array( 'nocache_headers', array( FrontendMarker::class, 'no_store_headers' ), 10 );
		$this->assertSame(
			array(
				'Expires'       => 'Wed, 11 Jan 1984 05:00:00 GMT',
				'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, private',
			),
			FrontendMarker::no_store_headers(
				array(
					'Expires'       => 'Wed, 11 Jan 1984 05:00:00 GMT',
					'Cache-Control' => 'no-cache, must-revalidate, max-age=0',
				)
			)
		);
		$this->assertSame( array( 'Cache-Control' => FrontendMarker::CACHE_CONTROL ), FrontendMarker::no_store_headers( null ) );

		$checks = $this->start();
		$this->assertContains( $filter, $GLOBALS['updatelens_test']['actions'], 'Ajax endpoints.' );

		foreach ( array( 'home', 'rest' ) as $check ) {
			$GLOBALS['updatelens_test']['actions'] = array();
			$marker                                = new FrontendMarker(
				$this->manager,
				function () {}
			);
			if ( 'home' === $check ) {
				$_GET['updatelens_check'] = $checks['home']['session'];
				$marker->on_send_headers(
					(object) array(
						'request'    => '',
						'query_vars' => array(),
					)
				);
			} else {
				$marker->on_rest_post_dispatch( new \WP_REST_Response(), null, new \WP_REST_Request( '/wp/v2/users/me', array( 'x_updatelens_check' => $checks['rest']['session'] ) ) );
			}
			$this->assertContains( $filter, $GLOBALS['updatelens_test']['actions'], $check );
		}

		$GLOBALS['updatelens_test']['actions'] = array();
		$_GET['updatelens_check']              = str_repeat( 'a', 32 );
		( new FrontendMarker( $this->manager ) )->on_send_headers(
			(object) array(
				'request'    => '',
				'query_vars' => array(),
			)
		);
		$this->assertSame( array(), $GLOBALS['updatelens_test']['actions'], 'Nothing for an unknown marker.' );
	}

	/**
	 * The homepage marker is only for anonymous requests: a signed-in render
	 * (a browser ignoring credentials: omit) stays unverified.
	 */
	public function test_homepage_marker_is_anonymous_only() {
		$checks                   = $this->start();
		$headers                  = array();
		$marker                   = new FrontendMarker(
			$this->manager,
			function ( $line ) use ( &$headers ) {
				$headers[] = $line;
			}
		);
		$wp                       = (object) array(
			'request'    => '',
			'query_vars' => array(),
		);
		$_GET['updatelens_check'] = $checks['home']['session'];

		$GLOBALS['updatelens_test']['logged_in'] = true;
		$marker->on_send_headers( $wp );
		$this->assertSame( array(), $headers );
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );
		ob_start();
		$marker->print_end_marker();
		$this->assertSame( '', ob_get_clean() );

		$GLOBALS['updatelens_test']['logged_in'] = false;
		$marker->on_send_headers( $wp );
		$this->assertCount( 2, $headers );
	}
}
