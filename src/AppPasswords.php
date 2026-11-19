<?php
/**
 * Application passwords: the door that stays open after a sign out.
 *
 * @package AryanX\Axle\UserSessionManager
 */

namespace AryanX\Axle\UserSessionManager;

defined( 'ABSPATH' ) || exit;

/**
 * Lists and revokes application passwords for "This wasn't me" and "Sign everyone out".
 *
 * Signing a session out, or changing a password, does nothing to an
 * application password. Someone who made one in a hijacked session keeps REST
 * and XML-RPC access until it is revoked. So every incident revokes them too.
 *
 * Passwords are removed one by one through core's own delete call, so core's
 * `wp_delete_application_password` action fires for each, and other plugins
 * (Elevate, Agent Governance, audit logs) can listen to either that action or
 * this plugin's `user_session_manager_app_passwords_revoked`.
 */
final class AppPasswords {

	/**
	 * Whether this site can have application passwords at all.
	 *
	 * @return bool
	 */
	public static function is_supported(): bool {
		return class_exists( 'WP_Application_Passwords' );
	}

	/**
	 * A user's application passwords, described without their secrets.
	 *
	 * @param int $user_id User ID.
	 * @param int $since   Only passwords created at or after this time. 0 for all.
	 * @return array<int, array{uuid: string, name: string, created: int, last_used: int}>
	 */
	public static function list_for_user( int $user_id, int $since = 0 ): array {
		if ( $user_id < 1 || ! self::is_supported() ) {
			return array();
		}

		$rows = array();
		foreach ( (array) \WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( '' === $item['uuid'] || $item['created'] < $since ) {
				continue;
			}
			$rows[] = array(
				'uuid'      => $item['uuid'],
				'name'      => $item['name'],
				'created'   => $item['created'],
				'last_used' => (int) $item['last_used'],
			);
		}

		return $rows;
	}

	/**
	 * Revokes a user's application passwords.
	 *
	 * @param int    $user_id User ID.
	 * @param string $context not_me, incident, cli or ability, or a caller's own.
	 * @param int    $since   Only passwords created at or after this time. 0 revokes all.
	 * @return array<int, array{uuid: string, name: string, created: int, last_used: int}> The passwords that were revoked.
	 */
	public static function revoke_for_user( int $user_id, string $context, int $since = 0 ): array {
		$revoked = array();
		foreach ( self::list_for_user( $user_id, $since ) as $row ) {
			if ( true === \WP_Application_Passwords::delete_application_password( $user_id, $row['uuid'] ) ) {
				$revoked[] = $row;
			}
		}

		if ( $revoked ) {
			/**
			 * Fires after application passwords were revoked because of an incident.
			 *
			 * Listen to this to close other doors that depend on the same account, such as an
			 * elevation or requests an agent has waiting.
			 *
			 * @param int      $user_id The account.
			 * @param array[]  $revoked Each revoked password: uuid, name, created and last_used (Unix times). The secret is never included.
			 * @param string   $context not_me, incident, cli or ability.
			 */
			do_action( 'user_session_manager_app_passwords_revoked', $user_id, $revoked, $context );
		}

		return $revoked;
	}

	/**
	 * How many application passwords there are, for the incident preview.
	 *
	 * Counts at most the first 500 accounts that have any, and says when it stopped there.
	 *
	 * @param int[] $skip User IDs the incident leaves alone.
	 * @return array{users: int, passwords: int, truncated: bool}
	 */
	public static function totals( array $skip = array() ): array {
		$out = array(
			'users'     => 0,
			'passwords' => 0,
			'truncated' => false,
		);
		if ( ! self::is_supported() ) {
			return $out;
		}

		/**
		 * Filters how many accounts the incident preview counts application passwords for.
		 *
		 * @param int $limit Default 500. Kept between 50 and 5000.
		 */
		$limit = max( 50, min( 5000, (int) apply_filters( 'user_session_manager_app_password_preview_limit', 500 ) ) );
		$ids   = get_users(
			array(
				'fields'       => 'ID',
				'number'       => $limit + 1,
				'meta_key'     => \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
				'orderby'      => 'ID',
				'blog_id'      => 0,
			)
		);

		$out['truncated'] = count( $ids ) > $limit;
		foreach ( array_slice( array_map( 'intval', $ids ), 0, $limit ) as $user_id ) {
			if ( in_array( $user_id, $skip, true ) ) {
				continue;
			}
			$count = count( self::list_for_user( $user_id ) );
			if ( $count > 0 ) {
				++$out['users'];
				$out['passwords'] += $count;
			}
		}

		return $out;
	}
}
