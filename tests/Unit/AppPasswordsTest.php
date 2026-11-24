<?php
/**
 * Tests for revoking application passwords after an incident.
 *
 * @package AryanX\Axle\Sessions
 */

namespace AryanX\Axle\Sessions\Tests\Unit;

use AryanX\Axle\Sessions\AppPasswords;
use AryanX\Axle\Sessions\Incident;
use AryanX\Axle\Sessions\Sessions;
use AryanX\Axle\Sessions\Settings;
use Brain\Monkey\Actions;

require_once dirname( __DIR__ ) . '/Stubs/app-passwords.php';

final class AppPasswordsTest extends SessionTestCase {

	protected function setUp(): void {
		parent::setUp();
		\WP_Application_Passwords::$store = array(
			7 => array(
				array(
					'uuid'      => 'u-old',
					'app_id'    => '',
					'name'      => 'Old key',
					'password'  => 'secret-hash',
					'created'   => 1000,
					'last_used' => null,
					'last_ip'   => null,
				),
				array(
					'uuid'      => 'u-new',
					'app_id'    => '',
					'name'      => 'New key',
					'password'  => 'secret-hash-2',
					'created'   => 5000,
					'last_used' => 6000,
					'last_ip'   => '203.0.113.5',
				),
			),
		);
	}

	public function test_the_list_has_no_secrets_and_can_start_at_a_time(): void {
		$all = AppPasswords::list_for_user( 7 );
		$this->assertCount( 2, $all );
		$this->assertSame( array( 'uuid', 'name', 'created', 'last_used' ), array_keys( $all[0] ) );
		$this->assertCount( 1, AppPasswords::list_for_user( 7, 3000 ) );
		$this->assertSame( array(), AppPasswords::list_for_user( 0 ) );
	}

	public function test_revoking_removes_each_key_and_announces_it_once(): void {
		Actions\expectDone( 'axle_sessions_app_passwords_revoked' )->once()->with(
			7,
			\Mockery::on(
				static function ( array $rows ): bool {
					return 2 === count( $rows ) && ! isset( $rows[0]['password'] );
				}
			),
			'not_me'
		);

		$revoked = AppPasswords::revoke_for_user( 7, 'not_me' );

		$this->assertSame( array( 'Old key', 'New key' ), array_column( $revoked, 'name' ) );
		$this->assertSame( array(), AppPasswords::list_for_user( 7 ) );
	}

	public function test_nothing_is_announced_when_there_was_nothing_to_revoke(): void {
		Actions\expectDone( 'axle_sessions_app_passwords_revoked' )->never();

		$this->assertSame( array(), AppPasswords::revoke_for_user( 8, 'incident' ) );
	}

	public function test_the_incident_revokes_for_everyone_but_the_person_running_it(): void {
		$this->user( 7, 'alice' );
		$this->user( 9, 'admin' );
		$this->current_user = 9;
		\WP_Application_Passwords::$store[9] = array(
			array(
				'uuid'      => 'u-mine',
				'app_id'    => '',
				'name'      => 'Mine',
				'password'  => 'x',
				'created'   => 1,
				'last_used' => null,
				'last_ip'   => null,
			),
		);
		\WP_User_Query::$queue = array( array( 7, 9 ) );
		\Brain\Monkey\Functions\when( 'current_user_can' )->justReturn( true );

		$settings = new Settings();
		$job      = ( new Incident( new Sessions( $settings ) ) )->run_all(
			array(
				'check_caps' => false,
			)
		);

		$this->assertSame( 2, $job['app_revoked'] );
		$this->assertSame( array(), AppPasswords::list_for_user( 7 ) );
		$this->assertCount( 1, AppPasswords::list_for_user( 9 ) );
	}

	public function test_the_incident_leaves_them_alone_when_told_to(): void {
		$this->user( 7, 'alice' );
		\WP_User_Query::$queue = array( array( 7 ) );

		$settings = new Settings();
		$job      = ( new Incident( new Sessions( $settings ) ) )->run_all(
			array(
				'check_caps'    => false,
				'app_passwords' => false,
			)
		);

		$this->assertSame( 0, $job['app_revoked'] );
		$this->assertCount( 2, AppPasswords::list_for_user( 7 ) );
	}
}
