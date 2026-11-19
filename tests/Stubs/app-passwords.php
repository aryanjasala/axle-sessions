<?php
/**
 * A stand-in for core's application passwords, keeping them in an array.
 *
 * @package AryanX\Axle\UserSessionManager
 */

if ( ! class_exists( 'WP_Application_Passwords' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile
	class WP_Application_Passwords {
		const USERMETA_KEY_APPLICATION_PASSWORDS = '_application_passwords';

		/**
		 * User ID => list of passwords.
		 *
		 * @var array<int, array[]>
		 */
		public static $store = array();

		public static function get_user_application_passwords( $user_id ) {
			return self::$store[ $user_id ] ?? array();
		}

		public static function delete_application_password( $user_id, $uuid ) {
			foreach ( self::$store[ $user_id ] ?? array() as $index => $item ) {
				if ( $item['uuid'] === $uuid ) {
					unset( self::$store[ $user_id ][ $index ] );
					self::$store[ $user_id ] = array_values( self::$store[ $user_id ] );
					return true;
				}
			}
			return new WP_Error( 'application_password_not_found', 'Not found' );
		}
	}
}
