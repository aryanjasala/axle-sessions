<?php
/**
 * Small formatting helpers for the screens.
 *
 * @package AryanX\Axle\Sessions
 */

namespace AryanX\Axle\Sessions\Admin;

use AryanX\Axle\Sessions\History;
use AryanX\Axle\Sessions\SharedLogins;
use const AryanX\Axle\Sessions\PLUGIN_FILE;
use const AryanX\Axle\Sessions\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Relative times and device icons, already escaped.
 */
final class Display {

	/**
	 * Tags these helpers print, for wp_kses().
	 */
	public const ALLOWED = array(
		'time'   => array(
			'class'    => true,
			'datetime' => true,
			'title'    => true,
		),
		'span'   => array(
			'class'       => true,
			'aria-hidden' => true,
		),
		'strong' => array( 'title' => true ),
		'small'  => array(),
		'br'     => array(),
		'div'    => array(
			'class' => true,
			'id'    => true,
		),
		'p'      => array( 'class' => true ),
		'ul'     => array( 'class' => true ),
		'li'     => array( 'class' => true ),
	);

	/**
	 * Anchor of the shared login evidence.
	 */
	public const SHARED_ANCHOR = 'axle-sessions-shared';

	/**
	 * "3 hours ago" or "in 2 days", with the exact time on hover.
	 *
	 * @param int    $timestamp Unix time. 0 prints $fallback.
	 * @param string $fallback  Text when there is no time.
	 * @return string HTML.
	 */
	public static function time( int $timestamp, string $fallback = '' ): string {
		return AxleUi::relative_time( $timestamp, '' !== $fallback ? $fallback : __( 'Not recorded', 'axle-sessions' ) );
	}

	/**
	 * Loads the script for the confirm steps and spoken results.
	 */
	public static function enqueue_script(): void {
		wp_enqueue_script( 'axle-sessions', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-a11y', 'wp-hooks' ), VERSION, true );
		wp_localize_script(
			'axle-sessions',
			'axleSessionsAdmin',
			array(
				'title'   => __( 'Sign this session out?', 'axle-sessions' ),
				'confirm' => __( 'Sign out', 'axle-sessions' ),
				'cancel'  => __( 'Cancel', 'axle-sessions' ),
			)
		);
	}

	/**
	 * The badge that says how a session ended, or that it is still signed in.
	 *
	 * @param array $row Row from History::rows().
	 * @return string HTML.
	 */
	public static function ending( array $row ): string {
		if ( 'active' === $row['state'] ) {
			return '<span class="axle-badge axle-badge--good">' . esc_html__( 'Still signed in', 'axle-sessions' ) . '</span>';
		}

		$reason = '' !== $row['reason'] ? $row['reason'] : 'unknown';
		$html   = sprintf( '<span class="axle-badge axle-badge--%1$s">%2$s</span>', esc_attr( History::reason_tone( $reason ) ), esc_html( History::reason_label( $reason ) ) );
		if ( $row['end'] > 0 ) {
			$html .= '<br><small>' . AxleUi::relative_time( (int) $row['end'] ) . '</small>';
		}

		return $html;
	}

	/**
	 * The device icon and name.
	 *
	 * @param array $row    Row from Sessions::describe().
	 * @param bool  $shared Whether the session is part of the evidence that the account is shared.
	 * @return string HTML.
	 */
	public static function device( array $row, bool $shared = false ): string {
		$icons = array(
			'desktop' => 'dashicons-desktop',
			'mobile'  => 'dashicons-smartphone',
			'tablet'  => 'dashicons-tablet',
			'bot'     => 'dashicons-admin-generic',
			'unknown' => 'dashicons-admin-site-alt3',
		);
		$types = array(
			'desktop' => __( 'Computer', 'axle-sessions' ),
			'mobile'  => __( 'Phone', 'axle-sessions' ),
			'tablet'  => __( 'Tablet', 'axle-sessions' ),
			'bot'     => __( 'Script or bot', 'axle-sessions' ),
			'unknown' => __( 'Unknown device', 'axle-sessions' ),
		);
		$type  = isset( $icons[ $row['device_type'] ] ) ? $row['device_type'] : 'unknown';

		return sprintf(
			'<span class="axle-sessions-device"><span class="dashicons %1$s" aria-hidden="true"></span><span><strong title="%2$s">%3$s</strong>%4$s%5$s<br><small>%6$s</small></span></span>',
			esc_attr( $icons[ $type ] ),
			esc_attr( $row['ua'] ),
			esc_html( $row['device'] ),
			$row['current'] ? ' <span class="axle-sessions-badge axle-badge axle-badge--info">' . esc_html__( 'This device', 'axle-sessions' ) . '</span>' : '',
			$shared ? ' ' . self::shared_badge() : '',
			esc_html( $types[ $type ] )
		);
	}

	/**
	 * The "Possibly shared" badge.
	 *
	 * @return string HTML.
	 */
	public static function shared_badge(): string {
		return '<span class="axle-sessions-badge axle-sessions-badge-shared axle-badge axle-badge--warning">' . esc_html__( 'Possibly shared', 'axle-sessions' ) . '</span>';
	}

	/**
	 * Why an account looks shared: each piece of evidence with the sessions behind it.
	 *
	 * @param array $flag Flag from SharedLogins::get().
	 * @return string HTML.
	 */
	public static function shared_evidence( array $flag ): string {
		$items = '';
		foreach ( $flag['evidence'] as $evidence ) {
			$sessions = '';
			foreach ( (array) ( $evidence['handles'] ?? array() ) as $handle ) {
				$session = $flag['sessions'][ $handle ] ?? null;
				if ( ! is_array( $session ) ) {
					continue;
				}
				$where = array_filter( array( (string) ( $session['ip'] ?? '' ), (string) ( $session['country'] ?? '' ) ) );
				$seen  = (int) ( $session['last_seen'] ?? 0 ) ? (int) $session['last_seen'] : (int) ( $session['login'] ?? 0 );

				$sessions .= sprintf(
					'<li><strong>%1$s</strong>, %2$s, %3$s</li>',
					esc_html( (string) ( $session['device'] ?? '' ) ),
					esc_html( $where ? implode( ', ', $where ) : __( 'IP address unknown', 'axle-sessions' ) ),
					/* translators: %s: relative time, such as "3 minutes ago". */
					sprintf( esc_html__( 'last active %s', 'axle-sessions' ), self::time( $seen ) )
				);
			}

			$items .= sprintf(
				'<li>%1$s%2$s</li>',
				esc_html( SharedLogins::evidence_text( $evidence ) ),
				'' !== $sessions ? '<ul class="axle-sessions-shared-sessions">' . $sessions . '</ul>' : ''
			);
		}

		if ( 'sign_out_others' === $flag['action'] && $flag['signed_out'] > 0 ) {
			/* translators: %s: number of sessions. */
			$outcome = sprintf( _n( '%s session was signed out. The newest one was kept.', '%s sessions were signed out. The newest one was kept.', $flag['signed_out'], 'axle-sessions' ), number_format_i18n( $flag['signed_out'] ) );
		} elseif ( 'notify' === $flag['action'] ) {
			$outcome = __( 'The user and the site administrator were emailed. Nothing was blocked.', 'axle-sessions' );
		} else {
			$outcome = __( 'Nothing was blocked.', 'axle-sessions' );
		}

		return sprintf(
			'<div class="axle-sessions-shared notice notice-warning inline" id="%1$s"><p>%2$s <strong>%3$s</strong></p><p>%4$s</p><ul class="axle-sessions-shared-evidence">%5$s</ul><p class="description">%6$s %7$s</p></div>',
			esc_attr( self::SHARED_ANCHOR ),
			self::shared_badge(),
			esc_html__( 'This account looks shared by more than one person.', 'axle-sessions' ),
			sprintf(
				/* translators: 1: when it was first noticed, 2: when it was last seen, both relative such as "2 hours ago". */
				esc_html__( 'First noticed %1$s, last seen %2$s.', 'axle-sessions' ),
				self::time( $flag['detected'] ),
				self::time( $flag['updated'] )
			),
			$items,
			esc_html__( 'This is a guess from the browsers, networks and times of the sessions.', 'axle-sessions' ),
			esc_html( $outcome )
		);
	}
}
