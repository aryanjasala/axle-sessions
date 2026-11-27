<?php
/**
 * Tests that listing and ending sessions checks the person they belong to, not only a global capability.
 *
 * @package AryanX\Axle\Sessions
 */

namespace AryanX\Axle\Sessions\Tests\Unit;

use AryanX\Axle\Sessions\Access;
use AryanX\Axle\Sessions\Admin\SessionsPage;
use AryanX\Axle\Sessions\Sessions;
use AryanX\Axle\Sessions\Settings;
use AryanX\Axle\Sessions\SharedLogins;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class PermissionsTest extends SessionTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->current_user = 1;
		$this->user( 1, 'admin', array( 'administrator' ) );
		$this->user( 2, 'bob', array( 'editor' ) );
		$this->user( 3, 'root', array( 'administrator' ) );
		$this->session( 2, 'b1' );
		$this->session( 3, 'r1' );
		// A site administrator may edit bob but not root, who is a super admin.
		Functions\when( 'current_user_can' )->alias(
			static function ( $capability, $user_id = 0 ) {
				return 'edit_user' !== $capability || 3 !== $user_id;
			}
		);
		Functions\when( 'is_email' )->justReturn( false );
	}

	public function test_the_session_query_can_leave_out_people_the_caller_may_not_edit(): void {
		$sessions = new Sessions( new Settings() );

		\WP_User_Query::$queue = array( array( 2, 3 ) );
		$everyone = $sessions->query();
		\WP_User_Query::$queue = array( array( 2, 3 ) );
		$editable = $sessions->query( array( 'editable_only' => true ) );

		$this->assertSame( 2, $everyone['total'], 'WP-CLI has no current user and sees everyone.' );
		$this->assertSame( 1, $editable['total'] );
		$this->assertSame( 'bob', $editable['rows'][0]['user_login'] );
	}

	public function test_ending_sessions_checks_the_person(): void {
		$this->assertFalse( Access::can_manage( 3 ) );
		$this->assertTrue( Access::can_manage( 2 ) );
	}

	public function test_sign_everyone_out_needs_the_capability(): void {
		$this->assertTrue( Access::can_sign_out_everyone() );
		Filters\expectApplied( 'axle_sessions_incident_capability' )->once()->andReturn( 'manage_network' );
		Functions\when( 'current_user_can' )->alias(
			static function ( $capability ) {
				return 'manage_network' !== $capability;
			}
		);
		$this->assertFalse( Access::can_sign_out_everyone() );
	}

	public function test_the_new_settings_have_safe_defaults_and_limits(): void {
		$settings = new Settings();
		$this->assertTrue( $settings->get( 'not_me' ) );
		$this->assertSame( 'all', $settings->get( 'not_me_scope' ) );
		$this->assertSame( 90, $settings->history_days() );

		$this->policies( array(), array( 'not_me_scope' => 'nonsense', 'history_days' => 9999 ) );
		$settings = new Settings();
		$this->assertSame( 'all', $settings->get( 'not_me_scope' ) );
		$this->assertSame( 365, $settings->history_days() );

		Filters\expectApplied( 'axle_sessions_history_days' )->once()->andReturn( -4 );
		$this->assertSame( 0, ( new Settings() )->history_days() );
	}

	/**
	 * Runs the Users > Sessions handler with a request, a nonce check that records what it was asked and a result.
	 *
	 * @param array $request  $_REQUEST values.
	 * @param array $post     $_POST values.
	 * @param bool  $nonce_ok Whether the nonce is valid.
	 * @return string[] The nonce actions that were checked.
	 */
	private function run_page( array $request, array $post, bool $nonce_ok ): array {
		$checked = array();
		$_REQUEST = $request;
		$_POST    = $post;
		$_GET     = array();
		$_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( 'intval' );
		Functions\when( 'get_userdata' )->alias(
			function ( $id ) {
				return $this->users[ $id ] ?? false;
			}
		);
		Functions\when( 'check_admin_referer' )->alias(
			static function ( $action ) use ( &$checked, $nonce_ok ) {
				$checked[] = $action;
				if ( ! $nonce_ok ) {
					throw new \RuntimeException( 'nonce' );
				}
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'die' );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function () {
				throw new \RuntimeException( 'redirect' );
			}
		);
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.test/' );
		Functions\when( 'admin_url' )->justReturn( 'https://example.test/' );

		$settings = new Settings();
		$sessions = new Sessions( $settings );
		try {
			$this->page( $settings, $sessions, new SharedLogins( $settings, $sessions ) )->handle();
		} catch ( \RuntimeException $e ) {
			$checked[] = 'stopped:' . $e->getMessage();
		}

		return $checked;
	}

	public function test_signing_a_user_out_needs_a_nonce_at_every_step(): void {
		$ask = $this->run_page( array( 'action' => 'sign_out', 'user' => '2' ), array(), false );
		$this->assertSame( array( 'axle_sessions_sign_out_2', 'stopped:nonce' ), $ask, 'Even the question needs the row link nonce.' );

		$confirm = $this->run_page( array( 'action' => 'sign_out', 'user' => '2', 'axle_sessions_confirmed' => '1' ), array( 'axle_sessions_confirmed' => '1' ), false );
		$this->assertSame( array( SessionsPage::confirm_action( array( '2' ) ), 'stopped:nonce' ), $confirm );
		$this->assertNotEmpty( $this->stored( 2 ), 'Nothing ended without a nonce.' );
	}

	public function test_a_confirmation_nonce_is_tied_to_what_it_signs_out(): void {
		$this->assertNotSame( SessionsPage::confirm_action( array( '2' ) ), SessionsPage::confirm_action( array( '3' ) ) );
		$this->assertSame( SessionsPage::confirm_action( array( '2-a', '3-b' ) ), SessionsPage::confirm_action( array( '3-b', '2-a' ) ) );
	}

	public function test_nobody_is_signed_out_when_the_caller_may_not_edit_them(): void {
		$result = $this->run_page( array( 'action' => 'sign_out', 'user' => '3', 'axle_sessions_confirmed' => '1' ), array( 'axle_sessions_confirmed' => '1' ), true );

		$this->assertSame( array( 'stopped:die' ), $result, 'The capability for that user is checked first.' );
		$this->assertNotEmpty( $this->stored( 3 ) );
	}

	public function test_a_row_revoke_and_a_bulk_action_need_their_nonces(): void {
		$handle = ( new Sessions( new Settings() ) )->handle( 2, Sessions::verifier( 'b1' ) );

		$this->assertSame( array( 'axle_sessions_revoke_' . $handle, 'stopped:nonce' ), $this->run_page( array( 'action' => 'revoke', 'handle' => $handle ), array(), false ) );
		$this->assertSame( array( 'bulk-sessions', 'stopped:nonce' ), $this->run_page( array( 'action' => 'revoke', 'handles' => array( $handle ) ), array(), false ) );
		$this->assertNotEmpty( $this->stored( 2 ) );
	}
}
