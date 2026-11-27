<?php
/**
 * The Policies tab on Users > Sessions (Network Admin > Users on multisite).
 *
 * @package AryanX\Axle\Sessions
 */

namespace AryanX\Axle\Sessions\Admin;

use AryanX\Axle\Sessions\Access;
use AryanX\Axle\Sessions\Service;
use AryanX\Axle\Sessions\Settings;
use const AryanX\Axle\Sessions\PLUGIN_FILE;
use const AryanX\Axle\Sessions\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Per-role session policies, what happens over the limit, the new device email and IP shortening.
 */
final class SettingsPage implements Service {

	public const SLUG = 'axle-sessions';

	private const ACTION = 'axle_sessions_settings';

	/**
	 * Saved settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the screen.
	 *
	 * @param Settings $settings Saved settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Adds the menu entry, form handler and plugin link.
	 */
	public function register(): void {
		// The menu is built before the "not allowed" check for an unknown page, so redirect from there.
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'redirect_old_url' ), 999 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_form' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * URL of the screen.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return SessionsPage::url( array_merge( array( 'tab' => 'policies' ), $args ) );
	}

	/**
	 * Sends the old Settings > User Sessions address to the Policies tab.
	 */
	public function redirect_old_url(): void {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads the page name to redirect a link.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SLUG !== $page || ! in_array( $pagenow, array( 'options-general.php', 'settings.php' ), true ) || ! current_user_can( Access::settings_capability() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag carried over.
		wp_safe_redirect( self::url( isset( $_GET['axle_sessions_saved'] ) ? array( 'axle_sessions_saved' => 1 ) : array() ) );
		exit;
	}

	/**
	 * The Help tabs for the Policies tab.
	 *
	 * @return array<int, array{id: string, title: string, content: string}>
	 */
	public static function help_tabs_data(): array {
		return array(
			array(
				'id'      => 'policies-overview',
				'title'   => __( 'Policies', 'axle-sessions' ),
				'content' => '<p>' . esc_html__( 'Choose how long sessions last and how many a person may have, what happens when an account looks shared, and what the plugin remembers. Changes apply the next time someone signs in, except the idle timeout, which applies at once.', 'axle-sessions' ) . '</p><p>' . esc_html__( 'Each role has its own limits. Leave a field at 0 for no limit. A person with several roles gets the most generous value of each.', 'axle-sessions' ) . '</p>',
			),
			array(
				'id'      => 'over-the-limit',
				'title'   => __( 'Over the limit', 'axle-sessions' ),
				'content' => '<p>' . esc_html__( 'Signing out the oldest session lets everyone in. Refusing the new login is stricter. When a login is refused, the person can sign out one of their other devices to get in, after their password (and Two Factor, when they use it) was checked. Turn that off if you want a refusal to be final.', 'axle-sessions' ) . '</p>',
			),
			array(
				'id'      => 'emails',
				'title'   => __( 'New sign-in email', 'axle-sessions' ),
				'content' => '<p>' . esc_html__( 'When on, a person gets an email when they sign in from a browser and network they have not used lately. The email has a "This wasn\'t me" link. Opening the link shows a page, and nothing changes until the person presses the button on it. Then the device is signed out, the password stops working, application passwords are revoked, a reset link is emailed to them and the site administrator is told.', 'axle-sessions' ) . '</p>',
			),
			array(
				'id'      => 'history',
				'title'   => __( 'Sign-in history', 'axle-sessions' ),
				'content' => '<p>' . esc_html__( 'The history shows when and where an account signed in and how each session ended. It is kept for the number of days you set, and it is removed with the account\'s personal data. Set it to 0 to keep no history.', 'axle-sessions' ) . '</p>',
			),
		);
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public function add_settings_link( $links ): array {
		$links = (array) $links;
		if ( current_user_can( Access::settings_capability() ) ) {
			array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'axle-sessions' ) ) );
		}

		return $links;
	}

	/**
	 * Prints the Policies tab, inside the Sessions screen's wrap.
	 */
	public function render_panel(): void {
		$settings = $this->settings->all();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only value set by our own redirect.
		$saved = isset( $_GET['axle_sessions_saved'] );
		?>
		<div class="axle-sessions-settings">
			<p class="axle-intro"><?php esc_html_e( 'Set how many sessions each role may have and how long they last, and choose what happens when an account looks shared.', 'axle-sessions' ); ?></p>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible axle-sessions-notice"><p><?php esc_html_e( 'Settings saved.', 'axle-sessions' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">

				<section class="axle-card">
				<h2 class="axle-card__title"><?php esc_html_e( 'Policies per role', 'axle-sessions' ); ?></h2>
				<p class="description">
					<?php
					esc_html_e( 'Leave a field at 0 for no limit, or for the WordPress default session length (2 days, 14 with "Remember Me"). A user with several roles gets the most generous value of each. Users with no role on this site have no limits.', 'axle-sessions' );
					?>
				</p>
				<div class="axle-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Policies per role', 'axle-sessions' ); ?>">
				<table class="widefat striped axle-sessions-policies">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Role', 'axle-sessions' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Sessions at once', 'axle-sessions' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Sign out after idle (minutes)', 'axle-sessions' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Session length (hours)', 'axle-sessions' ); ?></th>
							<th scope="col"><?php esc_html_e( 'With "Remember Me" (hours)', 'axle-sessions' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Account looks shared', 'axle-sessions' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( wp_roles()->get_names() as $role => $name ) : ?>
							<?php $policy = $this->settings->policy( (string) $role ); ?>
							<tr data-role="<?php echo esc_attr( $role ); ?>">
								<th scope="row"><?php echo esc_html( translate_user_role( $name ) ); ?></th>
								<?php foreach ( Settings::POLICY_DEFAULTS as $key => $default ) : ?>
									<td data-colname="<?php echo esc_attr( $this->field_label( $key ) ); ?>">
										<label class="screen-reader-text" for="<?php echo esc_attr( "axle-sessions-{$role}-{$key}" ); ?>"><?php echo esc_html( translate_user_role( $name ) . ': ' . $this->field_label( $key ) ); ?></label>
										<input type="number" class="small-text" min="0" max="<?php echo esc_attr( (string) Settings::POLICY_MAX[ $key ] ); ?>" step="1" id="<?php echo esc_attr( "axle-sessions-{$role}-{$key}" ); ?>" name="<?php echo esc_attr( "policies[{$role}][{$key}]" ); ?>" value="<?php echo esc_attr( (string) $policy[ $key ] ); ?>">
									</td>
								<?php endforeach; ?>
								<td data-colname="<?php esc_attr_e( 'Account looks shared', 'axle-sessions' ); ?>">
									<label class="screen-reader-text" for="<?php echo esc_attr( "axle-sessions-{$role}-shared" ); ?>"><?php echo esc_html( translate_user_role( $name ) . ': ' . __( 'Account looks shared', 'axle-sessions' ) ); ?></label>
									<select id="<?php echo esc_attr( "axle-sessions-{$role}-shared" ); ?>" name="<?php echo esc_attr( "shared[{$role}]" ); ?>">
										<?php foreach ( $this->shared_labels() as $mode => $label ) : ?>
											<option value="<?php echo esc_attr( $mode ); ?>" <?php selected( $this->settings->shared_mode( (string) $role ), $mode ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<p class="description axle-sessions-shared-help">
					<?php esc_html_e( '"Account looks shared" decides what happens when the sessions of an account look like more than one person: more than two networks at the same time, two computers or two phones on different networks at the same time, or more than four devices in a day. The account always gets a "Possibly shared" badge with the reasons. A login is never refused on this guess. Choose "Do not check" for accounts that are meant to be shared, such as a kiosk.', 'axle-sessions' ); ?>
				</p>
				</section>

				<section class="axle-card">
				<h2 class="axle-card__title"><?php esc_html_e( 'Limits, emails and privacy', 'axle-sessions' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Over the limit', 'axle-sessions' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Over the limit', 'axle-sessions' ); ?></legend>
								<label><input type="radio" name="limit_mode" value="evict_oldest" <?php checked( 'evict_oldest', $settings['limit_mode'] ); ?>> <?php esc_html_e( 'Sign out the oldest session (recommended)', 'axle-sessions' ); ?></label><br>
								<label><input type="radio" name="limit_mode" value="block" <?php checked( 'block', $settings['limit_mode'] ); ?>> <?php esc_html_e( 'Refuse the new login', 'axle-sessions' ); ?></label>
								<p class="description"><?php esc_html_e( 'What happens when someone signs in and already has as many sessions as their role allows. Refusing is stricter.', 'axle-sessions' ); ?></p>
								<p>
									<label><input type="checkbox" name="device_swap" value="1" <?php checked( $settings['device_swap'] ); ?>> <?php esc_html_e( 'Let a refused person sign out one of their other devices to get in', 'axle-sessions' ); ?></label>
								</p>
								<p class="description"><?php esc_html_e( 'Only used when refusing, and only for accounts that use Two Factor or cannot manage options or promote users (never for a super admin without Two Factor), so a stolen password alone cannot push out an administrator. The list of devices appears after the password (and Two Factor, when the person uses it) was accepted, and works once for 10 minutes in the same browser. The person is not asked for the password again. Turn this off if a refusal must be final for everyone.', 'axle-sessions' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'New device email', 'axle-sessions' ); ?></th>
						<td>
							<label><input type="checkbox" name="new_device_email" value="1" <?php checked( $settings['new_device_email'] ); ?>> <?php esc_html_e( 'Email users when they sign in from a new browser or network', 'axle-sessions' ); ?></label>
							<p>
								<label for="axle-sessions-device-days"><?php esc_html_e( 'Remember browsers and networks for', 'axle-sessions' ); ?></label>
								<input type="number" class="small-text" min="1" max="365" id="axle-sessions-device-days" name="device_days" value="<?php echo esc_attr( (string) $settings['device_days'] ); ?>">
								<?php esc_html_e( 'days', 'axle-sessions' ); ?>
							</p>
							<p class="description"><?php esc_html_e( 'A browser and IP address pair not seen on the account for that long counts as new. Only a scrambled fingerprint is kept, not the address. The first login after this is turned on is remembered without an email.', 'axle-sessions' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '"This wasn\'t me" link', 'axle-sessions' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( '"This wasn\'t me" link', 'axle-sessions' ); ?></legend>
								<label><input type="checkbox" name="not_me" value="1" <?php checked( $settings['not_me'] ); ?>> <?php esc_html_e( 'Add a "This wasn\'t me" link to the new sign-in email', 'axle-sessions' ); ?></label>
								<p>
									<label><input type="radio" name="not_me_scope" value="all" <?php checked( 'all', $settings['not_me_scope'] ); ?>> <?php esc_html_e( 'Sign the account out everywhere (recommended)', 'axle-sessions' ); ?></label><br>
									<label><input type="radio" name="not_me_scope" value="session" <?php checked( 'session', $settings['not_me_scope'] ); ?>> <?php esc_html_e( 'Sign out only the session the email was about', 'axle-sessions' ); ?></label>
								</p>
								<p class="description"><?php esc_html_e( 'The link works once and expires after 7 days. Opening it changes nothing until the person presses the button on the page. Pressing it also makes the password stop working, emails a reset link to the person, and tells the site administrator. Turn this off to remove the link and stop old links from working.', 'axle-sessions' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sign-in history', 'axle-sessions' ); ?></th>
						<td>
							<label for="axle-sessions-history-days"><?php esc_html_e( 'Keep sign-in history for', 'axle-sessions' ); ?></label>
							<input type="number" class="small-text" min="0" max="365" id="axle-sessions-history-days" name="history_days" value="<?php echo esc_attr( (string) $settings['history_days'] ); ?>">
							<?php esc_html_e( 'days', 'axle-sessions' ); ?>
							<p class="description"><?php esc_html_e( 'The history shows on each profile: where and when an account signed in, and how each session ended. It uses the same IP address setting as sessions. Set it to 0 to keep none. Entries older than this are deleted.', 'axle-sessions' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'IP addresses', 'axle-sessions' ); ?></th>
						<td>
							<label><input type="checkbox" name="mask_ip" value="1" <?php checked( $settings['mask_ip'] ); ?>> <?php esc_html_e( 'Store shortened IP addresses (the last part set to zero)', 'axle-sessions' ); ?></label>
							<p class="description"><?php esc_html_e( 'For privacy. Sessions still show roughly where a login came from, but not the exact address. Existing sessions are shortened the next time they are used.', 'axle-sessions' ); ?></p>
						</td>
					</tr>
				</table>
				</section>
				<?php submit_button( __( 'Save settings', 'axle-sessions' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Label of a policy field, for screen readers.
	 *
	 * @param string $key Policy setting.
	 * @return string
	 */
	private function field_label( string $key ): string {
		$labels = array(
			'max_sessions'   => __( 'Sessions at once', 'axle-sessions' ),
			'idle_minutes'   => __( 'Sign out after idle (minutes)', 'axle-sessions' ),
			'lifetime_hours' => __( 'Session length (hours)', 'axle-sessions' ),
			'remember_hours' => __( 'With "Remember Me" (hours)', 'axle-sessions' ),
		);

		return $labels[ $key ] ?? $key;
	}

	/**
	 * The choices for an account that looks shared.
	 *
	 * @return array<string, string>
	 */
	private function shared_labels(): array {
		return array(
			'off'             => __( 'Do not check', 'axle-sessions' ),
			'flag'            => __( 'Show a badge', 'axle-sessions' ),
			'notify'          => __( 'Badge and email', 'axle-sessions' ),
			'sign_out_others' => __( 'Keep only the newest session', 'axle-sessions' ),
		);
	}

	/**
	 * Saves the form.
	 */
	public function handle_form(): void {
		if ( ! current_user_can( Access::settings_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'axle-sessions' ), 403 );
		}

		check_admin_referer( self::ACTION );

		// Each value is cast to an integer and clamped in Settings::sanitize_policy().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$submitted = isset( $_POST['policies'] ) && is_array( $_POST['policies'] ) ? wp_unslash( $_POST['policies'] ) : array();
		$policies  = array();
		foreach ( array_keys( wp_roles()->get_names() ) as $role ) {
			if ( isset( $submitted[ $role ] ) && is_array( $submitted[ $role ] ) ) {
				$policies[ $role ] = Settings::sanitize_policy( array_map( 'absint', $submitted[ $role ] ) );
			}
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is checked against Settings::SHARED_MODES below.
		$submitted_shared = isset( $_POST['shared'] ) && is_array( $_POST['shared'] ) ? wp_unslash( $_POST['shared'] ) : array();
		$shared           = array();
		foreach ( array_keys( wp_roles()->get_names() ) as $role ) {
			$value = isset( $submitted_shared[ $role ] ) ? sanitize_key( (string) $submitted_shared[ $role ] ) : '';
			if ( in_array( $value, Settings::SHARED_MODES, true ) ) {
				$shared[ $role ] = $value;
			}
		}

		$mode  = isset( $_POST['limit_mode'] ) ? sanitize_key( wp_unslash( $_POST['limit_mode'] ) ) : 'evict_oldest';
		$scope = isset( $_POST['not_me_scope'] ) ? sanitize_key( wp_unslash( $_POST['not_me_scope'] ) ) : 'all';
		$scope = in_array( $scope, Settings::NOT_ME_SCOPES, true ) ? $scope : 'all';

		$this->settings->save(
			array(
				'policies'         => $policies,
				'shared'           => $shared,
				'limit_mode'       => in_array( $mode, Settings::LIMIT_MODES, true ) ? $mode : 'evict_oldest',
				'device_swap'      => ! empty( $_POST['device_swap'] ),
				'new_device_email' => ! empty( $_POST['new_device_email'] ),
				'device_days'      => isset( $_POST['device_days'] ) ? max( 1, min( 365, absint( $_POST['device_days'] ) ) ) : 30,
				'mask_ip'          => ! empty( $_POST['mask_ip'] ),
				'not_me'           => ! empty( $_POST['not_me'] ),
				'not_me_scope'     => $scope,
				'history_days'     => isset( $_POST['history_days'] ) ? max( 0, min( 365, absint( $_POST['history_days'] ) ) ) : 90,
			)
		);

		wp_safe_redirect( self::url( array( 'axle_sessions_saved' => 1 ) ) );
		exit;
	}
}
