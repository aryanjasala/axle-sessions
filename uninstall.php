<?php
/**
 * Removes the settings, device memory, sign-in history, reports, shared login flags and schedule of Axle Sessions, on every site.
 *
 * The `last_seen` time the plugin adds inside core's `session_tokens` user meta
 * is left alone: core ignores it, and it goes away with each session.
 *
 * @package AryanX\Axle\Sessions
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Names the plugin has stored, under its current prefix and the one it had before it was renamed.
 *
 * @return array{options: string[], user_meta: string[], events: string[]}
 */
function axle_sessions_stored_names(): array {
	$options   = array( 'settings', 'prune_cursor', 'history_cursor', 'not_me_events', 'incidents', 'db_version' );
	$user_meta = array( 'devices', 'shared', 'history', 'not_me', 'swap', 'hide_steps' );
	$events    = array( 'prune' );
	$names     = array(
		'options'   => array(),
		'user_meta' => array(),
		'events'    => array(),
	);

	foreach ( array( 'axle_sessions_', 'user_session_manager_' ) as $prefix ) {
		foreach ( $options as $name ) {
			$names['options'][] = $prefix . $name;
		}
		foreach ( $user_meta as $name ) {
			$names['user_meta'][] = $prefix . $name;
		}
		foreach ( $events as $name ) {
			$names['events'][] = $prefix . $name;
		}
	}

	return $names;
}

/**
 * Removes Axle Sessions data from the current site.
 */
function axle_sessions_uninstall_site(): void {
	$names = axle_sessions_stored_names();

	foreach ( $names['events'] as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
	foreach ( $names['options'] as $option ) {
		delete_option( $option );
	}
}

if ( is_multisite() ) {
	$axle_sessions_offset = 0;
	do {
		$axle_sessions_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $axle_sessions_offset,
			)
		);

		foreach ( $axle_sessions_sites as $axle_sessions_site ) {
			switch_to_blog( $axle_sessions_site );
			axle_sessions_uninstall_site();
			restore_current_blog();
		}

		$axle_sessions_offset += 100;
	} while ( $axle_sessions_sites );

	// Settings are network-wide on multisite.
	foreach ( axle_sessions_stored_names()['options'] as $axle_sessions_option ) {
		delete_site_option( $axle_sessions_option );
	}
} else {
	axle_sessions_uninstall_site();
}

// Device memory for the new device email, shared login flags, sign-in history, "This wasn't me" links,
// a pending "sign out another device" step and the dismissed "Get started" card are user meta, shared across a network.
foreach ( axle_sessions_stored_names()['user_meta'] as $axle_sessions_meta_key ) {
	delete_metadata( 'user', 0, $axle_sessions_meta_key, '', true );
}
