<?php
/**
 * Tests for ending another device to finish a refused login.
 *
 * @package AryanX\Axle\Sessions
 */

namespace AryanX\Axle\Sessions\Tests\Unit;

use AryanX\Axle\Sessions\DeviceSwap;
use AryanX\Axle\Sessions\Limits;
use AryanX\Axle\Sessions\Sessions;
use AryanX\Axle\Sessions\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class DeviceSwapTest extends SessionTestCase {

	private Sessions $store;

	private Settings $settings;

	/**
	 * Cookies the code under test set: name => value.
	 *
	 * @var array<string, string>
	 */
	private array $cookies = array();

	protected function setUp(): void {
		parent::setUp();
		$this->settings = new Settings();
		$this->store    = new Sessions( $this->settings );
		$this->cookies  = array();
		$_COOKIE        = array();
		$_POST          = array();
		$_REQUEST       = array();
		$_SERVER['HTTP_USER_AGENT'] = 'Firefox on Windows';
		$_SERVER['REQUEST_METHOD']  = 'POST';
		$GLOBALS['pagenow']         = 'wp-login.php';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_clear_auth_cookie' )->justReturn( null );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_validate_redirect' )->alias(
			static function ( $url, $fallback = '' ) {
				return '' !== $url ? $url : $fallback;
			}
		);
		Functions\when( 'network_site_url' )->justReturn( 'https://example.com/wp-login.php' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				if ( ! is_array( $args ) ) {
					$args = array( $args => $url );
					$url  = func_get_arg( 2 );
				}
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) {
				throw new \RuntimeException( 'redirect:' . $url );
			}
		);
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'is_super_admin' )->justReturn( false );
	}

	private function swap(): DeviceSwap {
		return new DeviceSwap( $this->settings, $this->store );
	}

	public function test_a_token_is_stored_only_as_a_hash_and_bound_to_the_user_and_browser(): void {
		$user  = $this->user( 7, 'alice' );
		$token = $this->swap()->issue( $user, true, '' );

		$record = $this->meta[7][ DeviceSwap::META ];
		$this->assertSame( 48, strlen( $token ) );
		$this->assertStringNotContainsString( $token, wp_json_encode( $record ) );
		$this->assertTrue( $record['remember'] );
		$this->assertGreaterThan( time() + 500, $record['expires'] );
		$this->assertLessThanOrEqual( time() + 600, $record['expires'] );

		$_COOKIE[ DeviceSwap::cookie_name() ] = '7.' . $token;
		$found                                = $this->swap()->lookup();
		$this->assertNotNull( $found );
		$this->assertSame( 7, $found['user']->ID );

		// Another browser with the same cookie finds nothing.
		$_SERVER['HTTP_USER_AGENT'] = 'Chrome on Mac';
		$this->assertNull( $this->swap()->lookup() );
	}

	public function test_a_wrong_expired_or_malformed_token_finds_nothing(): void {
		$user  = $this->user( 7, 'alice' );
		$token = $this->swap()->issue( $user, false, '' );
		$name  = DeviceSwap::cookie_name();

		$_COOKIE[ $name ] = '7.' . str_repeat( 'a', 48 );
		$this->assertNull( $this->swap()->lookup() );
		$_COOKIE[ $name ] = '8.' . $token;
		$this->assertNull( $this->swap()->lookup() );
		$_COOKIE[ $name ] = 'nonsense';
		$this->assertNull( $this->swap()->lookup() );
		$_COOKIE[ $name ] = '7.' . $token;
		$this->assertNotNull( $this->swap()->lookup() );

		$this->meta[7][ DeviceSwap::META ]['expires'] = time() - 1;
		$this->assertNull( $this->swap()->lookup() );
	}

	public function test_using_a_token_up_removes_it(): void {
		$user  = $this->user( 7, 'alice' );
		$token = $this->swap()->issue( $user, false, '' );
		$_COOKIE[ DeviceSwap::cookie_name() ] = '7.' . $token;

		$swap = $this->swap();
		$swap->consume( 7 );

		$this->assertArrayNotHasKey( DeviceSwap::META, $this->meta[7] );
		$this->assertNull( $swap->lookup() );
	}

	public function test_the_form_token_is_derived_from_the_cookie_token(): void {
		$swap = $this->swap();

		$this->assertSame( $swap->form_token( 'abc' ), $swap->form_token( 'abc' ) );
		$this->assertNotSame( $swap->form_token( 'abc' ), $swap->form_token( 'abd' ) );
		$this->assertNotSame( 'abc', $swap->form_token( 'abc' ) );
	}

	public function test_it_only_applies_to_the_sign_in_form_and_when_turned_on(): void {
		$user = $this->user( 7, 'alice' );
		$this->assertTrue( $this->swap()->applies( $user ) );

		$GLOBALS['pagenow'] = 'index.php';
		$this->assertFalse( $this->swap()->applies( $user ) );
		$GLOBALS['pagenow'] = 'wp-login.php';

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->assertFalse( $this->swap()->applies( $user ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->options[ Settings::OPTION ] = array( 'device_swap' => false );
		$this->assertFalse( $this->swap()->applies( $user ) );
	}

	public function test_a_stolen_password_alone_cannot_push_out_an_administrator(): void {
		$admin  = $this->user( 7, 'boss' );
		$editor = $this->user( 8, 'ed' );
		$caps   = array( 7 => array( 'manage_options', 'promote_users' ), 8 => array() );
		Functions\when( 'user_can' )->alias(
			static function ( $user, $cap ) use ( $caps ) {
				return in_array( $cap, $caps[ $user->ID ], true );
			}
		);

		$this->assertFalse( $this->settings->device_swap_enabled( $admin ) );
		$this->assertTrue( $this->settings->device_swap_enabled( $editor ) );

		// A user who can only promote users is also kept out.
		$caps[8] = array( 'promote_users' );
		Functions\when( 'user_can' )->alias(
			static function ( $user, $cap ) use ( $caps ) {
				return in_array( $cap, $caps[ $user->ID ], true );
			}
		);
		$this->assertFalse( $this->settings->device_swap_enabled( $editor ) );
	}

	public function test_an_administrator_with_two_factor_gets_the_list_and_a_filter_can_widen_it(): void {
		$admin = $this->user( 7, 'boss' );
		Functions\when( 'user_can' )->justReturn( true );
		$this->assertFalse( $this->settings->device_swap_enabled( $admin ) );

		Filters\expectApplied( 'axle_sessions_uses_two_factor' )->andReturn( true );
		$this->assertTrue( $this->settings->device_swap_enabled( $admin ) );

		$this->options[ Settings::OPTION ] = array( 'device_swap' => false );
		$this->assertFalse( $this->settings->device_swap_enabled( $admin ) );
	}

	public function test_a_super_admin_without_two_factor_never_gets_the_list_on_multisite(): void {
		$user = $this->user( 7, 'root' );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_super_admin' )->justReturn( true );
		Functions\when( 'get_site_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);

		$this->assertFalse( $this->settings->device_swap_enabled( $user ) );
	}

	public function test_the_filter_can_narrow_or_widen_the_rule(): void {
		$admin = $this->user( 7, 'boss' );
		Functions\when( 'user_can' )->justReturn( true );
		Filters\expectApplied( 'axle_sessions_device_swap_enabled' )->once()->with( false, $admin )->andReturn( true );

		$this->assertTrue( $this->settings->device_swap_enabled( $admin ) );
	}

	public function test_the_setting_and_the_lifetime_can_be_filtered_and_are_clamped(): void {
		$user = $this->user( 7, 'alice' );
		Filters\expectApplied( 'axle_sessions_device_swap_enabled' )->once()->with( true, $user )->andReturn( false );
		$this->assertFalse( $this->settings->device_swap_enabled( $user ) );

		Filters\expectApplied( 'axle_sessions_device_swap_lifetime' )->once()->andReturn( 99999 );
		$this->assertSame( 1800, $this->settings->device_swap_lifetime() );
	}

	public function test_a_held_login_becomes_the_device_list_only_when_the_login_fully_succeeded(): void {
		$user     = $this->user( 7, 'alice' );
		$this->policies( array( 'author' => array( 'max_sessions' => 1 ) ), array( 'limit_mode' => 'block' ) );
		$old      = $this->session( 7, 'phone' );
		$new      = $this->session( 7, 'laptop' );
		$swap     = $this->swap();
		$limits   = new Limits( $this->settings, $this->store, $swap );
		$session  = $this->store->get( 7, $new );

		// Over the limit on the form: the new session is kept for the moment, and the login is not announced.
		Actions\expectDone( 'axle_sessions_session_started' )->never();
		$this->assertFalse( $limits->enforce_limit( $user, $new, $session ) );
		$this->assertNotNull( $this->store->get( 7, $new ) );

		// A second step takes over the login (wp_login never reaches us), so the session is dropped at the end.
		Actions\expectDone( 'axle_sessions_login_blocked' )->once();
		$swap->drop_undecided();
		$this->assertNull( $this->store->get( 7, $new ) );
		$this->assertNotNull( $this->store->get( 7, $old ) );
	}

	public function test_deciding_removes_the_new_session_and_goes_to_the_device_list_with_a_token(): void {
		$user    = $this->user( 7, 'alice' );
		$this->policies( array( 'author' => array( 'max_sessions' => 1 ) ), array( 'limit_mode' => 'block' ) );
		$this->session( 7, 'phone' );
		$new     = $this->session( 7, 'laptop' );
		$swap    = $this->swap();
		$_POST['rememberme']   = '1';
		$_REQUEST['redirect_to'] = 'https://example.com/wp-admin/edit.php';

		$swap->hold( $user, $new, $this->store->get( 7, $new ) );
		Actions\expectDone( 'axle_sessions_device_swap_offered' )->once()->with( $user, 1, 1 );

		try {
			$swap->decide( 'alice', $user );
			$this->fail( 'The browser should have been redirected.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'action=axle_sessions_devices', $e->getMessage() );
		}

		$this->assertNull( $this->store->get( 7, $new ) );
		$record = $this->meta[7][ DeviceSwap::META ];
		$this->assertTrue( $record['remember'] );
		$this->assertSame( 'https://example.com/wp-admin/edit.php', $record['redirect'] );
	}

	public function test_a_different_user_or_nothing_held_does_nothing(): void {
		$alice = $this->user( 7, 'alice' );
		$bob   = $this->user( 8, 'bob' );
		$swap  = $this->swap();

		$swap->decide( 'alice', $alice );
		$swap->hold( $alice, 'x', array() );
		$swap->decide( 'bob', $bob );

		$this->assertArrayNotHasKey( 7, $this->meta );
		$this->assertArrayNotHasKey( 8, $this->meta );
	}

	public function test_the_second_step_filter_decides_whether_wp_login_is_fired_again(): void {
		$user = $this->user( 7, 'alice' );
		$this->assertFalse( DeviceSwap::uses_two_factor( $user ) );

		Filters\expectApplied( 'axle_sessions_uses_two_factor' )->once()->with( false, $user )->andReturn( true );
		$this->assertTrue( DeviceSwap::uses_two_factor( $user ) );
	}

	public function test_forgetting_removes_a_pending_token(): void {
		$user = $this->user( 7, 'alice' );
		$swap = $this->swap();
		$swap->issue( $user, false, '' );

		$this->assertTrue( $swap->forget( 7 ) );
		$this->assertFalse( $swap->forget( 7 ) );
	}
}
