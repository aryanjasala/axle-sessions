<?php
/**
 * One-time move of data stored under the plugin's earlier name.
 *
 * @package AryanX\Axle\Sessions
 */

namespace AryanX\Axle\Sessions;

defined( 'ABSPATH' ) || exit;

/**
 * Before version 1.0.0 was renamed to Axle Sessions, everything this plugin stores
 * began with `user_session_manager_`. This moves it to `axle_sessions_`, once:
 * options, network options, user meta and the hourly clean-up event.
 *
 * Nothing is lost if it stops half way. The moves can be repeated, an old value
 * never replaces a new one, and the work carries on at the next request until
 * the stored version says it is done.
 *
 * The short-lived `usm_` transients and cookies are not renamed. They expire on their own.
 */
final class Upgrade {

	public const VERSION_OPTION = 'axle_sessions_db_version';
	public const VERSION        = 1;

	private const OLD_PREFIX = 'user_session_manager_';
	private const NEW_PREFIX = 'axle_sessions_';

	/**
	 * Options (and network options) to move, with whether WordPress loads them on every request.
	 *
	 * @var array<string, bool>
	 */
	public const OPTIONS = array(
		'settings'       => true,
		'prune_cursor'   => false,
		'history_cursor' => false,
		'not_me_events'  => false,
		'incidents'      => false,
	);

	/**
	 * User meta keys to move.
	 *
	 * @var string[]
	 */
	public const USER_META = array( 'devices', 'shared', 'history', 'not_me', 'swap', 'hide_steps' );

	/**
	 * Cron hooks to move.
	 *
	 * @var string[]
	 */
	public const EVENTS = array( 'prune' );

	/**
	 * Users handled per query.
	 */
	private const BATCH = 200;

	/**
	 * Seconds one request may spend on user meta.
	 */
	private const BUDGET = 5;

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		$this->maybe_run();
	}

	/**
	 * Runs the move when the stored version is behind.
	 */
	public function maybe_run(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		$this->move_options();
		$this->move_events();

		if ( $this->move_user_meta() ) {
			update_option( self::VERSION_OPTION, self::VERSION );
		}
	}

	/**
	 * Moves the options and network options.
	 */
	public function move_options(): void {
		$missing = new \stdClass();

		foreach ( self::OPTIONS as $name => $autoload ) {
			$old = self::OLD_PREFIX . $name;
			$new = self::NEW_PREFIX . $name;

			$value = get_option( $old, $missing );
			if ( $missing !== $value ) {
				if ( get_option( $new, $missing ) === $missing ) {
					update_option( $new, $value, $autoload );
				}
				delete_option( $old );
			}

			if ( is_multisite() ) {
				$value = get_site_option( $old, $missing );
				if ( $missing !== $value ) {
					if ( get_site_option( $new, $missing ) === $missing ) {
						update_site_option( $new, $value );
					}
					delete_site_option( $old );
				}
			}
		}
	}

	/**
	 * Clears the old cron events and schedules the new ones.
	 */
	public function move_events(): void {
		foreach ( self::EVENTS as $name ) {
			$old = self::OLD_PREFIX . $name;
			$new = self::NEW_PREFIX . $name;

			if ( false === wp_next_scheduled( $old ) ) {
				continue;
			}

			wp_clear_scheduled_hook( $old );

			// Sessions are network-wide, so on multisite only the main site runs the clean-up.
			if ( is_main_site() && ! wp_next_scheduled( $new ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', $new );
			}
		}
	}

	/**
	 * Moves user meta for every user on the network, in batches.
	 *
	 * @return bool True when no user has an old key left.
	 */
	public function move_user_meta(): bool {
		$started = microtime( true );

		foreach ( self::USER_META as $name ) {
			$old = self::OLD_PREFIX . $name;
			$new = self::NEW_PREFIX . $name;

			do {
				$query = new \WP_User_Query(
					array(
						'blog_id'  => 0,
						'fields'   => 'ID',
						'number'   => self::BATCH,
						'meta_key' => $old, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs once, to find the users that still have the old key.
					)
				);
				$ids   = array_map( 'intval', (array) $query->get_results() );

				foreach ( $ids as $user_id ) {
					$value = get_user_meta( $user_id, $old, true );
					if ( '' === get_user_meta( $user_id, $new, true ) && '' !== $value ) {
						// update_user_meta() removes slashes, so add them back to store the value as it was.
						update_user_meta( $user_id, $new, wp_slash( $value ) );
					}
					delete_user_meta( $user_id, $old );
				}

				if ( $ids && microtime( true ) - $started > self::BUDGET ) {
					return false;
				}
			} while ( $ids );
		}

		return true;
	}
}
