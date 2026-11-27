<?php
/**
 * Tests for the move from the old plugin name.
 *
 * @package AryanX\Axle\Sessions
 */

namespace AryanX\Axle\Sessions\Tests\Unit;

use AryanX\Axle\Sessions\Upgrade;
use Brain\Monkey\Functions;

final class UpgradeTest extends SessionTestCase {

	/**
	 * Scheduled events: hook => time.
	 *
	 * @var array<string, int>
	 */
	private array $events = array();

	protected function setUp(): void {
		parent::setUp();

		$this->events = array();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_main_site' )->justReturn( true );
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'delete_site_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( $hook ) {
				return $this->events[ $hook ] ?? false;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) {
				unset( $this->events[ $hook ] );
				return 1;
			}
		);
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $time, $recurrence, $hook ) {
				$this->events[ $hook ] = $time;
				return true;
			}
		);
	}

	public function test_it_moves_every_old_option_user_meta_key_and_event(): void {
		$settings = array( 'policies' => array( 'author' => array( 'max_sessions' => 2 ) ) );
		$this->options['user_session_manager_settings']       = $settings;
		$this->options['user_session_manager_prune_cursor']   = 41;
		$this->options['user_session_manager_history_cursor'] = 9;
		$this->options['user_session_manager_not_me_events']  = array( array( 'user' => 7 ) );
		$this->options['user_session_manager_incidents']      = array( array( 'id' => 'x' ) );
		$this->events['user_session_manager_prune']           = 123;

		$old_keys = array( 'devices', 'shared', 'history', 'not_me', 'swap', 'hide_steps' );
		foreach ( $old_keys as $key ) {
			$this->meta[7][ 'user_session_manager_' . $key ] = array( $key => 'value' );
		}
		$this->meta[8]['user_session_manager_history'] = array( 'bob' );
		\WP_User_Query::$queue = array();
		// One batch per key that has users, in the order the upgrade asks.
		foreach ( $old_keys as $key ) {
			\WP_User_Query::$queue[] = 'history' === $key ? array( 7, 8 ) : array( 7 );
			\WP_User_Query::$queue[] = array();
		}

		( new Upgrade() )->maybe_run();

		$this->assertSame( $settings, $this->options['axle_sessions_settings'] );
		$this->assertSame( 41, $this->options['axle_sessions_prune_cursor'] );
		$this->assertSame( 9, $this->options['axle_sessions_history_cursor'] );
		$this->assertSame( array( array( 'user' => 7 ) ), $this->options['axle_sessions_not_me_events'] );
		$this->assertSame( array( array( 'id' => 'x' ) ), $this->options['axle_sessions_incidents'] );
		foreach ( array_keys( $this->options ) as $name ) {
			$this->assertStringStartsNotWith( 'user_session_manager_', $name );
		}

		foreach ( $old_keys as $key ) {
			$this->assertSame( array( $key => 'value' ), $this->meta[7][ 'axle_sessions_' . $key ] );
			$this->assertArrayNotHasKey( 'user_session_manager_' . $key, $this->meta[7] );
		}
		$this->assertSame( array( 'bob' ), $this->meta[8]['axle_sessions_history'] );

		$this->assertArrayNotHasKey( 'user_session_manager_prune', $this->events );
		$this->assertArrayHasKey( 'axle_sessions_prune', $this->events );
		$this->assertSame( Upgrade::VERSION, $this->options[ Upgrade::VERSION_OPTION ] );
	}

	public function test_a_value_already_stored_under_the_new_name_is_kept(): void {
		$this->options['user_session_manager_settings'] = array( 'old' => 1 );
		$this->options['axle_sessions_settings']        = array( 'new' => 1 );
		$this->meta[7]['user_session_manager_devices']  = array( 'old' );
		$this->meta[7]['axle_sessions_devices']         = array( 'new' );
		\WP_User_Query::$queue                          = array( array( 7 ), array() );

		( new Upgrade() )->maybe_run();

		$this->assertSame( array( 'new' => 1 ), $this->options['axle_sessions_settings'] );
		$this->assertArrayNotHasKey( 'user_session_manager_settings', $this->options );
		$this->assertSame( array( 'new' ), $this->meta[7]['axle_sessions_devices'] );
		$this->assertArrayNotHasKey( 'user_session_manager_devices', $this->meta[7] );
	}

	public function test_it_does_nothing_once_done(): void {
		$this->options[ Upgrade::VERSION_OPTION ]       = Upgrade::VERSION;
		$this->options['user_session_manager_settings'] = array( 'old' => 1 );

		( new Upgrade() )->maybe_run();

		$this->assertArrayHasKey( 'user_session_manager_settings', $this->options );
		$this->assertSame( array(), \WP_User_Query::$made );
	}

	public function test_a_fresh_install_only_records_the_version_and_schedules_nothing(): void {
		( new Upgrade() )->maybe_run();

		$this->assertSame( array(), $this->events );
		$this->assertSame( Upgrade::VERSION, $this->options[ Upgrade::VERSION_OPTION ] );
	}

	public function test_network_options_move_on_multisite(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		$this->options['user_session_manager_incidents'] = array( 'a' );

		( new Upgrade() )->move_options();

		$this->assertSame( array( 'a' ), $this->options['axle_sessions_incidents'] );
		$this->assertArrayNotHasKey( 'user_session_manager_incidents', $this->options );
	}

	public function test_the_short_prefix_transients_are_dropped(): void {
		$db = new class() {
			public $options  = 'wp_options';
			public $sitemeta = 'wp_sitemeta';
			public $queries  = array();

			public function esc_like( $text ) {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( $query, ...$args ) {
				
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			public function query( $query ) {
				$this->queries[] = $query;
				return 1;
			}
		};
		$GLOBALS['wpdb'] = $db;
		Functions\when( 'is_multisite' )->justReturn( true );

		( new Upgrade() )->maybe_run();
		unset( $GLOBALS['wpdb'] );

		$this->assertCount( 2, $db->queries );
		$this->assertStringContainsString( 'wp_options', $db->queries[0] );
		$this->assertStringContainsString( '_transient_usm_', str_replace( '\\', '', $db->queries[0] ) );
		$this->assertStringContainsString( 'wp_sitemeta', $db->queries[1] );
		$this->assertStringContainsString( '_site_transient_usm_', str_replace( '\\', '', $db->queries[1] ) );
	}
}
