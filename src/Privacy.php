<?php
/**
 * Personal data export, erase and the privacy policy text.
 *
 * @package AryanX\Axle\Sessions
 */

namespace AryanX\Axle\Sessions;

defined( 'ABSPATH' ) || exit;

/**
 * Sessions hold an IP address and a browser string, which are personal data.
 *
 * The exporter lists a user's sessions, remembered devices and any shared login
 * flag. The eraser signs the user out everywhere and forgets both.
 */
final class Privacy implements Service {

	public const ID = 'axle-sessions';

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Session store.
	 *
	 * @var Sessions
	 */
	private Sessions $sessions;

	/**
	 * Device memory.
	 *
	 * @var NewDevice
	 */
	private NewDevice $devices;

	/**
	 * Shared login flags.
	 *
	 * @var SharedLogins
	 */
	private SharedLogins $shared;

	/**
	 * Sign-in history.
	 *
	 * @var History|null
	 */
	private ?History $history;

	/**
	 * "This wasn't me" links and reports.
	 *
	 * @var NotMe|null
	 */
	private ?NotMe $not_me;

	/**
	 * Sets up the privacy tools.
	 *
	 * @param Settings     $settings Saved settings.
	 * @param Sessions     $sessions Session store.
	 * @param NewDevice    $devices  Device memory.
	 * @param SharedLogins $shared   Shared login flags.
	 * @param History      $history  Sign-in history.
	 * @param NotMe        $not_me   "This wasn't me" links and reports.
	 */
	public function __construct( Settings $settings, Sessions $sessions, NewDevice $devices, SharedLogins $shared, ?History $history = null, ?NotMe $not_me = null ) {
		$this->settings = $settings;
		$this->sessions = $sessions;
		$this->devices  = $devices;
		$this->shared   = $shared;
		$this->history  = $history;
		$this->not_me   = $not_me;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function add_exporter( array $exporters ): array {
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Login sessions', 'axle-sessions' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function add_eraser( array $erasers ): array {
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Login sessions', 'axle-sessions' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports the sessions and remembered devices of the user with an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1. Everything fits on one.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		$data = array();

		if ( $user ) {
			$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			foreach ( $this->sessions->list_for_user( $user->ID ) as $index => $row ) {
				$data[] = array(
					'group_id'    => 'axle-sessions-sessions',
					'group_label' => __( 'Login sessions', 'axle-sessions' ),
					'item_id'     => 'axle-sessions-session-' . $index,
					'data'        => array(
						array(
							'name'  => __( 'Device', 'axle-sessions' ),
							'value' => $row['device'],
						),
						array(
							'name'  => __( 'Browser string', 'axle-sessions' ),
							'value' => $row['ua'],
						),
						array(
							'name'  => __( 'IP address', 'axle-sessions' ),
							'value' => $row['ip'],
						),
						array(
							'name'  => __( 'Signed in', 'axle-sessions' ),
							'value' => $row['login'] ? wp_date( $format, $row['login'] ) : '',
						),
						array(
							'name'  => __( 'Last active', 'axle-sessions' ),
							'value' => $row['last_seen'] ? wp_date( $format, $row['last_seen'] ) : '',
						),
						array(
							'name'  => __( 'Expires', 'axle-sessions' ),
							'value' => wp_date( $format, $row['expiration'] ),
						),
					),
				);
			}

			foreach ( $this->devices->devices( $user->ID ) as $index => $device ) {
				$data[] = array(
					'group_id'    => 'axle-sessions-devices',
					'group_label' => __( 'Remembered devices', 'axle-sessions' ),
					'item_id'     => 'axle-sessions-device-' . $index,
					'data'        => array(
						array(
							'name'  => __( 'Device', 'axle-sessions' ),
							'value' => (string) $device['label'],
						),
						array(
							'name'  => __( 'Last seen', 'axle-sessions' ),
							'value' => wp_date( $format, (int) $device['seen'] ),
						),
					),
				);
			}

			foreach ( null === $this->history ? array() : $this->history->rows( $user->ID ) as $index => $row ) {
				$data[] = array(
					'group_id'    => 'axle-sessions-history',
					'group_label' => __( 'Sign-in history', 'axle-sessions' ),
					'item_id'     => 'axle-sessions-history-' . $index,
					'data'        => array(
						array(
							'name'  => __( 'Device', 'axle-sessions' ),
							'value' => $row['device'],
						),
						array(
							'name'  => __( 'IP address', 'axle-sessions' ),
							'value' => $row['ip'],
						),
						array(
							'name'  => __( 'Signed in', 'axle-sessions' ),
							'value' => $row['start'] ? wp_date( $format, $row['start'] ) : '',
						),
						array(
							'name'  => __( 'Last active', 'axle-sessions' ),
							'value' => $row['seen'] ? wp_date( $format, $row['seen'] ) : '',
						),
						array(
							'name'  => __( 'How it ended', 'axle-sessions' ),
							'value' => 'active' === $row['state'] ? __( 'Still signed in', 'axle-sessions' ) : History::reason_label( '' !== $row['reason'] ? $row['reason'] : 'unknown' ),
						),
					),
				);
			}

			foreach ( null === $this->not_me ? array() : $this->not_me->events( $user->ID ) as $index => $event ) {
				$data[] = array(
					'group_id'    => 'axle-sessions-not-me',
					'group_label' => __( '"This wasn\'t me" reports', 'axle-sessions' ),
					'item_id'     => 'axle-sessions-not-me-' . $index,
					'data'        => array(
						array(
							'name'  => __( 'Reported', 'axle-sessions' ),
							'value' => wp_date( $format, (int) $event['time'] ),
						),
						array(
							'name'  => __( 'Device', 'axle-sessions' ),
							'value' => (string) $event['device'],
						),
						array(
							'name'  => __( 'IP address', 'axle-sessions' ),
							'value' => (string) $event['ip'],
						),
						array(
							'name'  => __( 'Application passwords revoked', 'axle-sessions' ),
							'value' => NotMe::app_password_names( $event ),
						),
					),
				);
			}

			$flag = $this->shared->get( $user->ID );
			foreach ( $flag ? $flag['sessions'] : array() as $handle => $session ) {
				$data[] = array(
					'group_id'    => 'axle-sessions-shared',
					'group_label' => __( 'Sessions that looked shared', 'axle-sessions' ),
					'item_id'     => 'axle-sessions-shared-' . $handle,
					'data'        => array(
						array(
							'name'  => __( 'Reason', 'axle-sessions' ),
							'value' => implode( ' ', array_map( array( SharedLogins::class, 'evidence_text' ), $flag['evidence'] ) ),
						),
						array(
							'name'  => __( 'Device', 'axle-sessions' ),
							'value' => (string) ( $session['device'] ?? '' ),
						),
						array(
							'name'  => __( 'IP address', 'axle-sessions' ),
							'value' => (string) ( $session['ip'] ?? '' ),
						),
						array(
							'name'  => __( 'Country', 'axle-sessions' ),
							'value' => (string) ( $session['country'] ?? '' ),
						),
						array(
							'name'  => __( 'Last active', 'axle-sessions' ),
							'value' => ! empty( $session['last_seen'] ) ? wp_date( $format, (int) $session['last_seen'] ) : '',
						),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Signs the user with an email address out everywhere and forgets their devices.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1. Everything is done on the first.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		$user     = get_user_by( 'email', (string) $email );
		$messages = array();
		$removed  = false;

		if ( $user ) {
			$ended   = $this->sessions->count( $user->ID ) > 0 ? $this->sessions->sign_out_everywhere( $user->ID, 'privacy' ) : 0;
			$forgot  = $this->devices->forget( $user->ID );
			$flagged = $this->shared->forget( $user->ID );
			$history = null !== $this->history && $this->history->forget( $user->ID );
			$reports = null !== $this->not_me && ( $this->not_me->forget_events( $user->ID ) | $this->not_me->forget_links( $user->ID ) );
			delete_user_meta( $user->ID, DeviceSwap::META );
			$removed = $ended > 0 || $forgot || $flagged || $history || $reports;
			if ( $ended > 0 ) {
				$messages[] = sprintf(
					/* translators: %d: number of sessions. */
					_n( 'Signed the user out of %d session.', 'Signed the user out of %d sessions.', $ended, 'axle-sessions' ),
					$ended
				);
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Suggested text for Settings > Privacy.
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$ip = $this->settings->get( 'mask_ip' )
			? __( 'a shortened IP address (the last part set to zero)', 'axle-sessions' )
			: __( 'the IP address', 'axle-sessions' );

		$text = sprintf(
			/* translators: 1: "the IP address" or "a shortened IP address", 2: number of days, 3: number of days, 4: number of days. */
			__( 'When you sign in, WordPress keeps a record of the session: %1$s and browser it came from, when it started, when it was last used and when it ends. The record is deleted when you sign out or the session ends. You can see your sessions on your profile and sign any of them out. If new sign-in emails are on, a scrambled fingerprint of each browser and IP address you sign in from is kept for %2$d days, so we can tell you about a sign-in from a new device. If your sessions look like more than one person uses your account, for example two computers on different networks at the same time, a copy of those sessions (browser, IP address and times) is kept with the reason for %3$d days after the last time it happened. A sign-in history keeps the device, the same address, the times and how each session ended for %4$d days. If you answer a new sign-in email with "This wasn\'t me", the device, address and time of that report are kept for the site administrators until your data is erased, or until newer reports push it out of the latest 50. When an administrator signs everyone out, the log of that action keeps the time, the administrator who did it and the counts, not who was signed out, for the latest 20 times. No data is sent to any outside service.', 'axle-sessions' ),
			$ip,
			$this->settings->device_days(),
			SharedLoginDetector::RULES['keep_days'],
			$this->settings->history_days()
		);

		wp_add_privacy_policy_content( __( 'Axle Sessions', 'axle-sessions' ), '<p>' . esc_html( $text ) . '</p>' );
	}
}
