<?php
/**
 * "Let me in by ending the other device": the way out of a refused login.
 *
 * @package AryanX\Axle\Sessions
 */

namespace AryanX\Axle\Sessions;

use AryanX\Axle\Sessions\Admin\AxleUi;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a person their other devices after a login was refused over the limit.
 *
 * Refusing a login is only useful when the person can fix it. The device they
 * would sign out is rarely the one in front of them, so this page lists the
 * account's other devices and lets them end one and finish signing in, without
 * typing the password again.
 *
 * How the door stays shut for everyone else:
 *
 * - The list only appears after WordPress checked the password, and, when the
 *   account uses Two Factor, after the second step too. The decision is made on
 *   `wp_login` (late, so any plugin that takes over a login for a second step has
 *   already done so) and on `two_factor_user_authenticated`.
 * - Nothing is signed in while the person looks at the list. The new session is
 *   removed again and the login cookies are cleared first.
 * - What lets the person back to the page is a random token in a cookie, never in
 *   the address. Only a keyed hash of it is stored, with the user, a fingerprint
 *   of the browser, and a short expiry (10 minutes). There is one per account, so a
 *   newer one replaces the older.
 * - Every state-changing request needs the cookie and a form token derived from it,
 *   and uses the token up before doing anything else. If the person is still over
 *   the limit after ending one device, a fresh token is issued for the next step.
 * - Every wrong, expired or used token gets the same page, and repeated misses
 *   from one address are slowed down.
 */
final class DeviceSwap implements Service {

	public const ACTION = 'axle_sessions_devices';
	public const META   = 'axle_sessions_swap';

	private const TOKEN_BYTES = 24;

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
	 * A fresh session waiting for the decision: user ID, verifier and the session.
	 *
	 * @var array{user: \WP_User, verifier: string, session: array}|null
	 */
	private ?array $undecided = null;

	/**
	 * Sets up the feature.
	 *
	 * @param Settings $settings Saved settings.
	 * @param Sessions $sessions Session store.
	 */
	public function __construct( Settings $settings, Sessions $sessions ) {
		$this->settings = $settings;
		$this->sessions = $sessions;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'login_form_' . self::ACTION, array( $this, 'page' ) );
		// Late: a login that needs a second step is taken over before these run.
		add_action( 'wp_login', array( $this, 'decide' ), 99, 2 );
		add_action( 'two_factor_user_authenticated', array( $this, 'decide_after_two_factor' ), 99 );
		add_action( 'shutdown', array( $this, 'drop_undecided' ), 0 );
	}

	/**
	 * Name of the cookie that carries the token.
	 *
	 * @return string
	 */
	public static function cookie_name(): string {
		return 'axle_sessions_swap_' . ( defined( 'COOKIEHASH' ) ? COOKIEHASH : md5( (string) get_option( 'siteurl' ) ) );
	}

	/**
	 * Whether this login came from the sign-in form on wp-login.php, the only place the page can follow.
	 *
	 * @return bool
	 */
	public function is_form_login(): bool {
		$page   = isset( $GLOBALS['pagenow'] ) && is_string( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		return 'wp-login.php' === $page && 'POST' === $method && ! ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST );
	}

	/**
	 * Whether a refused login of this user should go to the device list.
	 *
	 * @param \WP_User $user The user who is signing in.
	 * @return bool
	 */
	public function applies( \WP_User $user ): bool {
		return $this->is_form_login() && $this->settings->device_swap_enabled( $user );
	}

	/**
	 * Remembers a new session that is over the limit, until the login has fully succeeded.
	 *
	 * @param \WP_User $user     The user.
	 * @param string   $verifier The new session.
	 * @param array    $session  The new session's data.
	 */
	public function hold( \WP_User $user, string $verifier, array $session ): void {
		$this->undecided = array(
			'user'     => $user,
			'verifier' => $verifier,
			'session'  => $session,
		);
	}

	/**
	 * The login succeeded all the way (wp_login): turns the held session into the device list.
	 *
	 * @param string          $login Username.
	 * @param \WP_User|string $user  The user.
	 */
	public function decide( $login = '', $user = null ): void {
		if ( null === $this->undecided || ! $user instanceof \WP_User || $user->ID !== $this->undecided['user']->ID ) {
			return;
		}

		$this->offer();
	}

	/**
	 * Two Factor finished its second step.
	 */
	public function decide_after_two_factor(): void {
		if ( null !== $this->undecided ) {
			$this->offer();
		}
	}

	/**
	 * The request ended with the session still held: a second step took over, so the session goes.
	 */
	public function drop_undecided(): void {
		if ( null === $this->undecided ) {
			return;
		}

		$held            = $this->undecided;
		$this->undecided = null;
		$this->sessions->forget( $held['user']->ID, $held['verifier'] );
		/** This action is documented in src/Limits.php */
		do_action( 'axle_sessions_login_blocked', $held['user'], $this->settings->max_sessions( $held['user'] ), count( $this->sessions->for_user( $held['user']->ID ) ) );
	}

	/**
	 * Whether the account uses a second step, so this plugin must not fire `wp_login` again.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	public static function uses_two_factor( \WP_User $user ): bool {
		$check = array( 'Two_Factor_Core', 'is_user_using_two_factor' );
		$uses  = is_callable( $check ) && (bool) call_user_func( $check, $user->ID );

		/**
		 * Filters whether an account uses a second sign-in step.
		 *
		 * Return true for a second-step plugin other than Two Factor, so finishing a login after the
		 * device list does not send the person through that step a second time.
		 *
		 * @param bool     $uses True when the user has a second step. Default: Two Factor says so.
		 * @param \WP_User $user The user.
		 */
		return (bool) apply_filters( 'axle_sessions_uses_two_factor', $uses, $user );
	}

	/**
	 * Ends the held login and sends the browser to the device list.
	 *
	 * @return void
	 */
	private function offer(): void {
		$held            = $this->undecided;
		$this->undecided = null;
		if ( null === $held ) {
			return;
		}

		$user = $held['user'];
		// Nothing stays signed in while the person chooses.
		$this->sessions->forget( $user->ID, $held['verifier'] );
		wp_clear_auth_cookie();

		$max    = $this->settings->max_sessions( $user );
		$active = count( $this->sessions->for_user( $user->ID ) );

		/**
		 * Fires when a refused login is offered the person's other devices.
		 *
		 * @param \WP_User $user   The user. Their password (and second step) were accepted.
		 * @param int      $max    Sessions allowed.
		 * @param int      $active Sessions the user has.
		 */
		do_action( 'axle_sessions_device_swap_offered', $user, $max, $active );
		/** This action is documented in src/Limits.php */
		do_action( 'axle_sessions_login_blocked', $user, $max, $active );

		// phpcs:disable WordPress.Security.NonceVerification -- The login form was verified by WordPress; these are read only to come back to the same place.
		$redirect = isset( $_REQUEST['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ), '' ) : '';
		$remember = ! empty( $_POST['rememberme'] );
		// phpcs:enable WordPress.Security.NonceVerification

		$this->issue( $user, $remember, $redirect );
		$this->go( add_query_arg( 'action', self::ACTION, network_site_url( 'wp-login.php', 'login' ) ) );
	}

	/**
	 * Makes a new token for a user, saves its hash, and sets the cookie.
	 *
	 * @param \WP_User $user     The user.
	 * @param bool     $remember Whether "Remember Me" was ticked.
	 * @param string   $redirect Where to go after signing in.
	 * @return string The raw token.
	 */
	public function issue( \WP_User $user, bool $remember, string $redirect ): string {
		$token = bin2hex( random_bytes( self::TOKEN_BYTES ) );
		$now   = time();

		update_user_meta(
			$user->ID,
			self::META,
			array(
				'hash'     => $this->hash( $token ),
				'browser'  => $this->fingerprint(),
				'created'  => $now,
				'expires'  => $now + $this->settings->device_swap_lifetime(),
				'remember' => $remember,
				'redirect' => $redirect,
			)
		);
		$this->set_cookie( $user->ID . '.' . $token, $now + $this->settings->device_swap_lifetime() );

		return $token;
	}

	/**
	 * Finds the record the request's cookie belongs to.
	 *
	 * @return array{user: \WP_User, token: string, record: array}|null Null for anything that is not a live token for this browser.
	 */
	public function lookup(): ?array {
		$name = self::cookie_name();
		if ( ! isset( $_COOKIE[ $name ] ) ) {
			return null;
		}

		$value = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		if ( ! preg_match( '/^(\d{1,10})\.([0-9a-f]{' . ( self::TOKEN_BYTES * 2 ) . '})$/', $value, $match ) ) {
			return null;
		}

		$user   = get_userdata( (int) $match[1] );
		$record = $user ? get_user_meta( $user->ID, self::META, true ) : null;
		if ( ! $user || ! is_array( $record ) || empty( $record['hash'] ) || (int) ( $record['expires'] ?? 0 ) <= time() ) {
			return null;
		}

		// Both comparisons always run, so the time taken does not say which one failed.
		$token_ok   = hash_equals( (string) $record['hash'], $this->hash( $match[2] ) );
		$browser_ok = hash_equals( (string) ( $record['browser'] ?? '' ), $this->fingerprint() );
		if ( ! $token_ok || ! $browser_ok ) {
			return null;
		}

		return array(
			'user'   => $user,
			'token'  => $match[2],
			'record' => $record,
		);
	}

	/**
	 * Uses up a user's token: nothing works with it afterwards.
	 *
	 * @param int $user_id User ID.
	 */
	public function consume( int $user_id ): void {
		delete_user_meta( $user_id, self::META );
		$this->set_cookie( '', time() - YEAR_IN_SECONDS );
	}

	/**
	 * Forgets a user's token without a login. For the privacy tools and uninstall.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether there was one.
	 */
	public function forget( int $user_id ): bool {
		$had = is_array( get_user_meta( $user_id, self::META, true ) );
		delete_user_meta( $user_id, self::META );

		return $had;
	}

	/**
	 * The token a form must send back, derived from the cookie's token.
	 *
	 * @param string $token Raw token from the cookie.
	 * @return string
	 */
	public function form_token( string $token ): string {
		return hash_hmac( 'sha256', 'form|' . $token, wp_salt( 'auth' ) );
	}

	/**
	 * Handles wp-login.php?action=axle_sessions_devices.
	 */
	public function page(): void {
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		// phpcs:disable WordPress.Security.NonceVerification -- The cookie token and the form token derived from it are the credential: a random, single-use secret bound to this browser.
		$post   = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		$form   = isset( $_POST['axle_sessions_form'] ) ? sanitize_text_field( wp_unslash( $_POST['axle_sessions_form'] ) ) : '';
		$handle = isset( $_POST['axle_sessions_handle'] ) ? sanitize_text_field( wp_unslash( $_POST['axle_sessions_handle'] ) ) : '';
		$cancel = $post && ! empty( $_POST['axle_sessions_cancel'] );
		$nonce  = isset( $_POST['axle_sessions_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['axle_sessions_nonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification

		if ( $this->is_limited() ) {
			$this->expired( 429 );
		}

		$found = $this->lookup();
		if ( null === $found || ! $this->settings->device_swap_enabled( $found['user'] ) ) {
			$this->count_failure();
			$this->expired( 403 );
		}

		$user = $found['user'];
		if ( ! $post ) {
			$this->render_list( $user, $found['token'], '' );
			exit;
		}

		// Past this point the token is used up, whatever happens.
		if ( ! hash_equals( $this->form_token( $found['token'] ), $form ) ) {
			$this->count_failure();
			$this->expired( 403 );
		}

		// A WordPress nonce for this account, on top of the cookie and form tokens. A stale page gets the list again and the token is kept.
		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $user->ID ) ) ) {
			$this->render_list( $user, $found['token'], esc_html__( 'This page was open for too long, so nothing was changed. Choose again.', 'axle-sessions' ) );
			exit;
		}
		$this->consume( $user->ID );

		if ( $cancel ) {
			AxleUi::guest_page(
				array(
					'title'   => __( 'Sign in cancelled', 'axle-sessions' ),
					'message' => esc_html__( 'Nothing was signed out and you are not signed in.', 'axle-sessions' ),
					'links'   => array( array( wp_login_url(), __( 'Back to the sign-in page', 'axle-sessions' ) ) ),
				)
			);
			exit;
		}

		$target = $this->sessions->find( $handle );
		if ( null === $target || $target['user_id'] !== $user->ID || ! $this->sessions->revoke( $user->ID, $target['verifier'], 'swap' ) ) {
			// The device was already gone, or the choice made no sense. A new token lets them look again.
			$this->issue( $user, ! empty( $found['record']['remember'] ), (string) ( $found['record']['redirect'] ?? '' ) );
			$this->go(
				add_query_arg(
					array(
						'action'             => self::ACTION,
						'axle_sessions_gone' => 1,
					),
					network_site_url( 'wp-login.php', 'login' ) 
				) 
			);
		}

		$ended = $target['session'] + array( 'handle' => $handle );

		// Still over the limit (it was lowered after people signed in): one more step.
		$max = $this->settings->max_sessions( $user );
		if ( $max > 0 && $this->active_count( $user ) >= $max ) {
			$this->issue( $user, ! empty( $found['record']['remember'] ), (string) ( $found['record']['redirect'] ?? '' ) );
			$this->go(
				add_query_arg(
					array(
						'action'             => self::ACTION,
						'axle_sessions_more' => 1,
					),
					network_site_url( 'wp-login.php', 'login' ) 
				) 
			);
		}

		$this->finish( $user, $ended, ! empty( $found['record']['remember'] ), (string) ( $found['record']['redirect'] ?? '' ) );
	}

	/**
	 * Signs the person in after they ended a device, and sends them on. Does not return.
	 *
	 * @param \WP_User $user     The user.
	 * @param array    $ended    The session that was ended, with its handle.
	 * @param bool     $remember Whether "Remember Me" was ticked.
	 * @param string   $redirect Where to go after signing in.
	 */
	private function finish( \WP_User $user, array $ended, bool $remember, string $redirect ): void {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember );

		/**
		 * Fires when a person ended one of their other devices to finish signing in.
		 *
		 * The new session was created, and `axle_sessions_session_started` fired for it. The
		 * ended session also fired `axle_sessions_session_revoked` with the context "swap".
		 *
		 * @param \WP_User $user  The user, now signed in.
		 * @param array    $ended The session that was ended: expiration, ip, ua, login, last_seen and handle.
		 */
		do_action( 'axle_sessions_device_swapped', $user, $ended );

		// A second step was already done, and firing wp_login would ask for it again.
		if ( ! self::uses_two_factor( $user ) ) {
			do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook.
		}

		$requested = $redirect;
		$to        = '' !== $redirect ? $redirect : admin_url();
		/** This filter is documented in wp-login.php */
		$to = apply_filters( 'login_redirect', $to, $requested, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$to = wp_validate_redirect( is_string( $to ) ? $to : '', admin_url() );

		$this->go( $to );
	}

	/**
	 * Prints the list of devices.
	 *
	 * @param \WP_User $user  The user.
	 * @param string   $token Raw token from the cookie.
	 * @param string   $error A message to show above the list, already escaped.
	 */
	private function render_list( \WP_User $user, string $token, string $error ): void {
		$rows    = $this->sessions->list_for_user( $user->ID );
		$minutes = $this->settings->idle_timeout( $user );
		$now     = time();
		$rows    = array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $minutes, $now ): bool {
					return ! Sessions::is_idle( array( 'last_seen' => $row['last_seen'] ), $minutes, $now );
				}
			)
		);
		$max     = $this->settings->max_sessions( $user );
		$action  = add_query_arg( 'action', self::ACTION, network_site_url( 'wp-login.php', 'login' ) );
		$form    = $this->form_token( $token );
		$nonce   = wp_create_nonce( self::nonce_action( $user->ID ) );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only flags from our own redirect.
		$gone = isset( $_GET['axle_sessions_gone'] );
		$more = isset( $_GET['axle_sessions_more'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$intro = sprintf(
			/* translators: 1: number of devices allowed, 2: number of devices signed in now. */
			_n( 'This account can be signed in on %1$s device at a time, and it is signed in on %2$s now.', 'This account can be signed in on %1$s devices at a time, and it is signed in on %2$s now.', max( 1, $max ), 'axle-sessions' ),
			number_format_i18n( $max ),
			number_format_i18n( count( $rows ) )
		);

		ob_start();
		?>
		<p><?php esc_html_e( 'Choose a device to sign out. You will be signed in here at once, without typing your password again.', 'axle-sessions' ); ?></p>
		<?php foreach ( $rows as $row ) : ?>
			<form method="post" action="<?php echo esc_url( $action ); ?>" class="axle-sessions-swap-device">
				<p>
					<strong><?php echo esc_html( '' !== $row['device'] ? $row['device'] : __( 'Unknown device', 'axle-sessions' ) ); ?></strong><br>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: IP address or "unknown address", 2: time since the device was last active, such as "5 minutes ago". */
							__( '%1$s. Last active %2$s.', 'axle-sessions' ),
							'' !== $row['ip'] ? $row['ip'] : __( 'Unknown address', 'axle-sessions' ),
							$row['last_seen'] > 0 ? sprintf( /* translators: %s: time span, such as "5 minutes". */ __( '%s ago', 'axle-sessions' ), human_time_diff( $row['last_seen'], $now ) ) : __( 'a while ago', 'axle-sessions' )
						)
					);
					?>
				</p>
				<input type="hidden" name="axle_sessions_form" value="<?php echo esc_attr( $form ); ?>">
				<input type="hidden" name="axle_sessions_nonce" value="<?php echo esc_attr( $nonce ); ?>">
				<input type="hidden" name="axle_sessions_handle" value="<?php echo esc_attr( $row['handle'] ); ?>">
				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Sign out this device and sign in here', 'axle-sessions' ); ?></button></p>
			</form>
		<?php endforeach; ?>
		<form method="post" action="<?php echo esc_url( $action ); ?>" class="axle-sessions-swap-cancel">
			<input type="hidden" name="axle_sessions_form" value="<?php echo esc_attr( $form ); ?>">
			<input type="hidden" name="axle_sessions_nonce" value="<?php echo esc_attr( $nonce ); ?>">
			<input type="hidden" name="axle_sessions_cancel" value="1">
			<p class="submit"><button type="submit" class="button"><?php esc_html_e( 'Cancel, I will sign in later', 'axle-sessions' ); ?></button></p>
		</form>
		<?php
		AxleUi::guest_page(
			array(
				'title'   => __( 'Sign out another device', 'axle-sessions' ),
				'message' => esc_html( $intro ),
				'error'   => $gone ? esc_html__( 'That device had already signed out. Choose again.', 'axle-sessions' ) : ( $more ? esc_html__( 'Signed out. This account is still over its limit, so choose one more device.', 'axle-sessions' ) : $error ),
				'body'    => (string) ob_get_clean(),
			)
		);
	}

	/**
	 * Nonce action for the device list forms, bound to the account.
	 *
	 * @param int $user_id The account being signed in.
	 * @return string
	 */
	private static function nonce_action( int $user_id ): string {
		return self::ACTION . '_' . $user_id;
	}

	/**
	 * The one answer to every wrong, expired or used token. Does not return.
	 *
	 * @param int $status HTTP status.
	 */
	private function expired( int $status ): void {
		$this->set_cookie( '', time() - YEAR_IN_SECONDS );
		AxleUi::guest_page(
			array(
				'title'  => __( 'This step has expired', 'axle-sessions' ),
				'error'  => esc_html( 429 === $status ? __( 'Too many attempts from this network. Wait a few minutes and sign in again.', 'axle-sessions' ) : __( 'This step has expired or was already used. Nothing was signed out.', 'axle-sessions' ) ),
				'body'   => '<p>' . esc_html__( 'Sign in again to see your devices.', 'axle-sessions' ) . '</p>',
				'status' => $status,
				'links'  => array( array( wp_login_url(), __( 'Back to the sign-in page', 'axle-sessions' ) ) ),
			)
		);
		exit;
	}

	/**
	 * Redirects and stops. A method of its own so tests can see where a request would go.
	 *
	 * @param string $url Where to go.
	 */
	private function go( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Sessions the user has that are not idle.
	 *
	 * @param \WP_User $user The user.
	 * @return int
	 */
	private function active_count( \WP_User $user ): int {
		$minutes = $this->settings->idle_timeout( $user );
		$now     = time();
		$count   = 0;
		foreach ( $this->sessions->for_user( $user->ID ) as $session ) {
			if ( ! Sessions::is_idle( $session, $minutes, $now ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Slows down someone guessing tokens: too many misses from one address.
	 *
	 * @return bool
	 */
	private function is_limited(): bool {
		/**
		 * Filters how many wrong device-list tokens one network address may try in 15 minutes.
		 *
		 * @param int $attempts Default 10. At least 3.
		 */
		$max = max( 3, (int) apply_filters( 'axle_sessions_device_swap_attempts', 10 ) );

		return (int) get_transient( $this->limit_key() ) >= $max;
	}

	/**
	 * Counts one miss.
	 */
	private function count_failure(): void {
		set_transient( $this->limit_key(), (int) get_transient( $this->limit_key() ) + 1, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Transient name for the caller's address. The address is hashed with a salt and never stored.
	 *
	 * @return string
	 */
	private function limit_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__

		return 'axle_sessions_sw_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * A keyed hash of the browser's user agent: the token only works in the browser it was made for.
	 *
	 * @return string
	 */
	private function fingerprint(): string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__

		return hash_hmac( 'sha256', 'browser|' . $ua, wp_salt( 'auth' ) );
	}

	/**
	 * Keyed hash of a token.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Sets or clears the cookie.
	 *
	 * @param string $value   Value, or empty to clear.
	 * @param int    $expires Expiry time.
	 */
	private function set_cookie( string $value, int $expires ): void {
		if ( '' === $value ) {
			unset( $_COOKIE[ self::cookie_name() ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
		}
		if ( headers_sent() ) {
			return;
		}

		setcookie( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
			self::cookie_name(),
			$value,
			array(
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}
