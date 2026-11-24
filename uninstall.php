<?php
/**
 * Removes Axle Sessions's settings, device memory, sign-in history, reports, shared login flags and schedule, on every site.
 *
 * The `last_seen` time the plugin adds inside core's `session_tokens` user meta
 * is left alone: core ignores it, and it goes away with each session.
 *
 * @package Sessions
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Axle Sessions data from the current site.
 */
function axle_sessions_uninstall_site(): void {
	wp_clear_scheduled_hook( 'axle_sessions_prune' );
	delete_option( 'axle_sessions_settings' );
	delete_option( 'axle_sessions_prune_cursor' );
	delete_option( 'axle_sessions_history_cursor' );
	delete_option( 'axle_sessions_not_me_events' );
	delete_option( 'axle_sessions_incidents' );
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
	delete_site_option( 'axle_sessions_settings' );
	delete_site_option( 'axle_sessions_prune_cursor' );
	delete_site_option( 'axle_sessions_history_cursor' );
	delete_site_option( 'axle_sessions_not_me_events' );
	delete_site_option( 'axle_sessions_incidents' );
} else {
	axle_sessions_uninstall_site();
}

// The device memory for the new device email and the shared login flags are user meta, shared across a network.
delete_metadata( 'user', 0, 'axle_sessions_devices', '', true );
delete_metadata( 'user', 0, 'axle_sessions_shared', '', true );
// Sign-in history, "This wasn't me" links and the dismissed "Get started" card.
delete_metadata( 'user', 0, 'axle_sessions_history', '', true );
delete_metadata( 'user', 0, 'axle_sessions_not_me', '', true );
// A pending "sign out another device" step.
delete_metadata( 'user', 0, 'axle_sessions_swap', '', true );
delete_metadata( 'user', 0, 'axle_sessions_hide_steps', '', true );
