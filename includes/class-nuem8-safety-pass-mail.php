<?php
/**
 * Mail for NueM8 Safety Pass.
 *
 * Messages go out through wp_mail. Nothing is sent to NueM8.
 * Mail stays off until an administrator chooses a recipient.
 *
 * @package NueM8_Safety_Pass
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides when to email the selected administrator, and prints the setting.
 */
class NueM8_Safety_Pass_Mail {

	const CRON_HOOK         = 'nuem8_safety_pass_daily';
	const RECIPIENT_OPTION  = 'nuem8_safety_pass_mail_recipient';
	const SIGNATURE_OPTION  = 'nuem8_safety_pass_mail_signature';
	const NOTIFIED_OPTION   = 'nuem8_safety_pass_mail_held_notified';
	const LAST_ERROR_OPTION = 'nuem8_safety_pass_mail_last_error';
	const PROMPT_OPTION     = 'nuem8_safety_pass_mail_prompt';

	/**
	 * Held notices already sent during this request.
	 *
	 * @var int
	 */
	private static $held_sent = 0;

	/**
	 * True while this class is inside wp_mail.
	 *
	 * @var bool
	 */
	private static $sending = false;

	/**
	 * Copy a recipient saved by an earlier copy of this mail.
	 */
	public static function migrate_legacy_options() {
		$map = array(
			self::RECIPIENT_OPTION  => 'nuem8_safety_pass_pro_recipient',
			self::SIGNATURE_OPTION  => 'nuem8_safety_pass_pro_signature',
			self::NOTIFIED_OPTION   => 'nuem8_safety_pass_pro_held_notified',
			self::LAST_ERROR_OPTION => 'nuem8_safety_pass_pro_last_error',
		);

		foreach ( $map as $new_key => $old_key ) {
			if ( false !== get_option( $new_key, false ) ) {
				continue;
			}

			$legacy = get_option( $old_key, null );

			if ( null === $legacy || false === $legacy ) {
				continue;
			}

			update_option( $new_key, $legacy, false );
			delete_option( $old_key );
		}
	}

	/**
	 * Ask once, on the first activation, whether to email an administrator.
	 */
	public static function arm_prompt() {
		if ( 'done' === get_option( self::PROMPT_OPTION, '' ) || self::recipient() instanceof WP_User ) {
			update_option( self::PROMPT_OPTION, 'done', false );
			return;
		}

		update_option( self::PROMPT_OPTION, 'pending', false );
	}

	/**
	 * Open Safety Pass after a single activation so the email question is on screen.
	 *
	 * @param string $plugin Plugin basename.
	 */
	public static function redirect_after_activate( $plugin ) {
		if ( plugin_basename( NUEM8_SAFETY_PASS_FILE ) !== $plugin || headers_sent() ) {
			return;
		}

		if ( wp_doing_ajax() || is_network_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || 'pending' !== get_option( self::PROMPT_OPTION, '' ) ) {
			return;
		}

		$action  = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$action2 = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';

		if ( 'activate-selected' === $action || 'activate-selected' === $action2 ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=nuem8-safety-pass' ) );
		exit;
	}

	/**
	 * Styles for the first-activation email question.
	 */
	public static function prompt_assets() {
		if ( ! self::should_prompt() ) {
			return;
		}

		$path = NUEM8_SAFETY_PASS_DIR . 'assets/admin.css';
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : NUEM8_SAFETY_PASS_VERSION;

		wp_enqueue_style(
			'nuem8-safety-pass',
			plugins_url( 'assets/admin.css', NUEM8_SAFETY_PASS_FILE ),
			array(),
			$ver
		);
	}

	/**
	 * Whether the first-activation email question is still unanswered.
	 *
	 * @return bool
	 */
	public static function should_prompt() {
		return self::can_manage() && 'pending' === get_option( self::PROMPT_OPTION, '' );
	}

	/**
	 * Leave mail off and do not ask again.
	 */
	public static function dismiss_prompt() {
		if ( ! self::can_manage() ) {
			self::deny( __( 'Only an administrator can change nuem8-safety-pass.', 'safety-pass-by-nuem8' ) );
		}

		check_admin_referer( 'nuem8_safety_pass_mail_prompt_dismiss' );
		self::complete_prompt();

		$back = wp_get_referer();

		if ( ! is_string( $back ) || '' === $back ) {
			$back = admin_url( 'admin.php?page=nuem8-safety-pass' );
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * The email question shown once after the first activation.
	 */
	public static function render_prompt() {
		if ( ! self::should_prompt() ) {
			return;
		}

		$candidates = self::candidates();
		$selected   = (int) get_current_user_id();
		$allowed    = array();

		foreach ( $candidates as $user ) {
			$allowed[] = (int) $user->ID;
		}

		if ( ! in_array( $selected, $allowed, true ) ) {
			$selected = isset( $allowed[0] ) ? (int) $allowed[0] : 0;
		}

		echo '<div class="safety-pass-prompt" role="presentation">';
		echo '<div class="safety-pass-prompt__dialog" role="dialog" aria-modal="true" aria-labelledby="nuem8-safety-pass-mail-prompt-title">';
		echo '<h2 id="nuem8-safety-pass-mail-prompt-title">' . esc_html__( 'Email one administrator?', 'safety-pass-by-nuem8' ) . '</h2>';
		echo '<p>' . esc_html__( 'nuem8-safety-pass can email one administrator on this site when a check needs a look, and when a new administrator is waiting for approval. WordPress sends the message. The message is not sent to NueM8. Mail stays off until you choose someone.', 'safety-pass-by-nuem8' ) . '</p>';

		if ( array() === $candidates ) {
			echo '<p>' . esc_html__( 'No administrator on this site can receive mail yet. You can choose one later on the NueM8 Safety Pass page.', 'safety-pass-by-nuem8' ) . '</p>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'nuem8_safety_pass_mail_recipient' );
			echo '<input type="hidden" name="action" value="nuem8_safety_pass_mail_recipient" />';
			echo '<label for="nuem8-safety-pass-mail-prompt-recipient">' . esc_html__( 'Email this administrator', 'safety-pass-by-nuem8' ) . '</label>';
			echo '<select id="nuem8-safety-pass-mail-prompt-recipient" name="user_id" autofocus>';

			foreach ( $candidates as $user ) {
				$label = self::plain( $user->display_name ) . ' (' . self::plain( $user->user_login ) . ')';
				echo wp_kses(
					'<option value="' . esc_attr( (string) $user->ID ) . '"' . selected( $selected, (int) $user->ID, false ) . '>' . esc_html( $label ) . '</option>',
					array(
						'option' => array(
							'value'    => true,
							'selected' => true,
						),
					)
				);
			}

			echo '</select>';
			submit_button( __( 'Enable email', 'safety-pass-by-nuem8' ), 'primary', 'submit', false );
			echo '</form>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="safety-pass-prompt__dismiss">';
		wp_nonce_field( 'nuem8_safety_pass_mail_prompt_dismiss' );
		echo '<input type="hidden" name="action" value="nuem8_safety_pass_mail_prompt_dismiss" />';
		submit_button( __( 'Not now', 'safety-pass-by-nuem8' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div></div>';
	}

	/**
	 * Keep one daily event on the site's own scheduler.
	 */
	public static function ensure_cron() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
	}

	/**
	 * Stop the daily check.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Daily check. Runs the report so mail can go out when nobody has opened the page.
	 */
	public static function daily() {
		if ( ! class_exists( 'NueM8_Safety_Pass_Checks' ) ) {
			return;
		}

		if ( ! self::can_send() && ! NueM8_Safety_Pass_Checks::holds_new_admins() ) {
			return;
		}

		NueM8_Safety_Pass_Checks::publish_report();
	}

	/**
	 * Email about Look and Stop checks, and about new administrators the immediate notice missed.
	 *
	 * @param mixed $findings Findings from the report.
	 */
	public static function on_report( $findings ) {
		if ( ! is_array( $findings ) ) {
			return;
		}

		self::notify_held_from_report( $findings );
		self::notify_attention( $findings );
	}

	/**
	 * Email as soon as a new administrator is held.
	 *
	 * @param int $user_id Held user ID.
	 */
	public static function send_held_notice( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id < 1 || self::$held_sent >= 5 || ! self::can_send() ) {
			return;
		}

		if ( in_array( $user_id, self::notified_ids(), true ) || get_transient( 'nuem8_sp_held_' . $user_id ) ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return;
		}

		set_transient( 'nuem8_sp_held_' . $user_id, '1', 10 * MINUTE_IN_SECONDS );

		$disabled = class_exists( 'NueM8_Safety_Pass_Checks' ) && NueM8_Safety_Pass_Checks::is_disabled( $user_id );
		$sent     = self::deliver(
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Safety Pass: new administrator', 'safety-pass-by-nuem8' ),
				self::site_name()
			),
			$disabled ? self::held_body( $user ) : self::open_admin_body( $user )
		);

		if ( ! $sent ) {
			delete_transient( 'nuem8_sp_held_' . $user_id );
			return;
		}

		self::mark_notified( $user_id );
		++self::$held_sent;
	}

	/**
	 * Send one message so the selected administrator can confirm the address.
	 *
	 * @return bool
	 */
	public static function send_test() {
		if ( ! self::can_send() ) {
			return false;
		}

		return self::deliver(
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Safety Pass test', 'safety-pass-by-nuem8' ),
				self::site_name()
			),
			__( 'This is a test from nuem8-safety-pass. This address will be notified when a check needs a look, and when a new administrator is waiting for approval. The site sends this mail. It is not sent to NueM8.', 'safety-pass-by-nuem8' )
		);
	}

	/**
	 * Forget the last report so the next check can mail the current one.
	 */
	public static function forget_signature() {
		delete_option( self::SIGNATURE_OPTION );
	}

	/**
	 * Store a mail error from this plugin's own send.
	 *
	 * @param WP_Error|mixed $error Mail error.
	 */
	public static function capture_failure( $error ) {
		if ( ! self::$sending || ! $error instanceof WP_Error ) {
			return;
		}

		$message = self::plain( $error->get_error_message() );

		if ( function_exists( 'mb_substr' ) ) {
			$message = mb_substr( $message, 0, 300 );
		} else {
			$message = substr( $message, 0, 300 );
		}

		update_option( self::LAST_ERROR_OPTION, $message, false );
	}

	/**
	 * The administrator who receives mail, when one is selected and can still be reached.
	 *
	 * @return WP_User|null
	 */
	public static function recipient() {
		$user_id = (int) get_option( self::RECIPIENT_OPTION, 0 );

		if ( $user_id < 1 ) {
			return null;
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User || ! is_email( $user->user_email ) || ! self::is_mail_admin( $user ) ) {
			return null;
		}

		if ( class_exists( 'NueM8_Safety_Pass_Checks' ) && NueM8_Safety_Pass_Checks::is_disabled( $user_id ) ) {
			return null;
		}

		return $user;
	}

	/**
	 * Save the administrator who should receive mail.
	 */
	public static function save_recipient() {
		if ( ! self::can_manage() ) {
			self::deny( __( 'Only an administrator can change nuem8-safety-pass.', 'safety-pass-by-nuem8' ) );
		}

		$user_id = self::posted_user_id( 'nuem8_safety_pass_mail_recipient' );
		$allowed = array();

		foreach ( self::candidates() as $user ) {
			$allowed[] = (int) $user->ID;
		}

		if ( 0 !== $user_id && ! in_array( $user_id, $allowed, true ) ) {
			self::redirect( 'invalid' );
		}

		self::complete_prompt();

		$previous = (int) get_option( self::RECIPIENT_OPTION, 0 );

		if ( $previous !== $user_id ) {
			self::forget_signature();
		}

		if ( $user_id < 1 ) {
			delete_option( self::RECIPIENT_OPTION );
			self::redirect( 'cleared' );
		}

		update_option( self::RECIPIENT_OPTION, $user_id, false );
		self::redirect( 'saved' );
	}

	/**
	 * Send one test message to the selected administrator.
	 */
	public static function send_test_request() {
		if ( ! self::can_manage() ) {
			self::deny( __( 'Only an administrator can change nuem8-safety-pass.', 'safety-pass-by-nuem8' ) );
		}

		check_admin_referer( 'nuem8_safety_pass_mail_test' );
		self::redirect( self::send_test() ? 'test_sent' : 'test_failed' );
	}

	/**
	 * Email settings under the report.
	 */
	public static function render() {
		if ( ! self::can_manage() ) {
			return;
		}

		$selected   = (int) get_option( self::RECIPIENT_OPTION, 0 );
		$recipient  = self::recipient();
		$candidates = self::candidates();
		$last_error = get_option( self::LAST_ERROR_OPTION, '' );

		echo '<section class="safety-pass-mail">';
		echo '<h2>' . esc_html__( 'Email', 'safety-pass-by-nuem8' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose one administrator on this site. nuem8-safety-pass emails that person when a check needs a look, and when a new administrator is waiting for approval. WordPress sends the message. The message is not sent to NueM8. Mail stays off until you choose someone.', 'safety-pass-by-nuem8' ) . '</p>';

		self::render_notice( $recipient );

		if ( $selected > 0 && ! $recipient instanceof WP_User ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The selected administrator cannot receive mail. Choose an active administrator.', 'safety-pass-by-nuem8' ) . '</p></div>';
		}

		if ( is_string( $last_error ) && '' !== $last_error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html(
				sprintf(
					/* translators: %s: mailer error from WordPress. */
					__( 'The administrator is saved. The message did not send. %s', 'safety-pass-by-nuem8' ),
					$last_error
				)
			) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'nuem8_safety_pass_mail_recipient' );
		echo '<input type="hidden" name="action" value="nuem8_safety_pass_mail_recipient" />';
		echo '<label for="nuem8-safety-pass-mail-recipient">' . esc_html__( 'Email this administrator', 'safety-pass-by-nuem8' ) . '</label>';
		echo '<select id="nuem8-safety-pass-mail-recipient" name="user_id">';
		echo '<option value="0">' . esc_html__( 'Do not send email', 'safety-pass-by-nuem8' ) . '</option>';

		foreach ( $candidates as $user ) {
			$label = self::plain( $user->display_name ) . ' (' . self::plain( $user->user_login ) . ')';
			echo wp_kses(
				'<option value="' . esc_attr( (string) $user->ID ) . '"' . selected( $selected, (int) $user->ID, false ) . '>' . esc_html( $label ) . '</option>',
				array(
					'option' => array(
						'value'    => true,
						'selected' => true,
					),
				)
			);
		}

		echo '</select>';
		submit_button( __( 'Save', 'safety-pass-by-nuem8' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( $recipient instanceof WP_User ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="safety-pass-mail__test">';
			wp_nonce_field( 'nuem8_safety_pass_mail_test' );
			echo '<input type="hidden" name="action" value="nuem8_safety_pass_mail_test" />';
			submit_button( __( 'Send a test email', 'safety-pass-by-nuem8' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		echo '</section>';
	}

	/**
	 * @param array<string, mixed> $findings Site checks.
	 */
	private static function notify_held_from_report( array $findings ) {
		if ( empty( $findings['admins']['items'] ) || ! is_array( $findings['admins']['items'] ) ) {
			return;
		}

		foreach ( $findings['admins']['items'] as $account ) {
			if ( ! is_array( $account ) || empty( $account['is_new'] ) || empty( $account['id'] ) ) {
				continue;
			}

			if ( empty( $account['disabled'] ) && (int) $account['id'] === (int) get_current_user_id() ) {
				continue;
			}

			self::send_held_notice( (int) $account['id'] );
		}
	}

	/**
	 * One email when the Look and Stop checks change. A new administrator has its own email.
	 *
	 * @param array<string, mixed> $findings Site checks.
	 */
	private static function notify_attention( array $findings ) {
		if ( ! self::can_send() ) {
			return;
		}

		$signature = self::attention_signature( $findings );
		$stored    = get_option( self::SIGNATURE_OPTION, null );

		if ( is_string( $stored ) && $signature === $stored ) {
			return;
		}

		if ( '' === $signature ) {
			update_option( self::SIGNATURE_OPTION, '', false );
			return;
		}

		$sent = self::deliver(
			sprintf(
				/* translators: %s: site name. */
				__( '[%s] Safety Pass: needs a look', 'safety-pass-by-nuem8' ),
				self::site_name()
			),
			self::attention_body( $findings )
		);

		if ( $sent ) {
			update_option( self::SIGNATURE_OPTION, $signature, false );
		}
	}

	/**
	 * @param array<string, mixed> $findings Site checks.
	 * @return string
	 */
	private static function attention_signature( array $findings ) {
		$parts = array();

		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) || empty( $finding['status'] ) || 'ok' === $finding['status'] ) {
				continue;
			}

			$id = isset( $finding['id'] ) ? (string) $finding['id'] : '';

			if ( '' === $id || 'admins' === $id ) {
				continue;
			}

			$parts[] = $id . '|' . (string) $finding['status'] . '|' . ( isset( $finding['rank'] ) ? (int) $finding['rank'] : 0 ) . '|' . count( (array) ( isset( $finding['items'] ) ? $finding['items'] : array() ) );
		}

		sort( $parts );

		return implode( "\n", $parts );
	}

	/**
	 * @param array<string, mixed> $findings Site checks.
	 * @return string
	 */
	private static function attention_body( array $findings ) {
		$problems = array();

		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) || empty( $finding['status'] ) || 'ok' === $finding['status'] ) {
				continue;
			}

			$id = isset( $finding['id'] ) ? (string) $finding['id'] : '';

			if ( '' === $id || 'admins' === $id ) {
				continue;
			}

			$problems[] = $finding;
		}

		usort(
			$problems,
			static function ( $left, $right ) {
				$left_rank  = isset( $left['rank'] ) ? (int) $left['rank'] : 1000;
				$right_rank = isset( $right['rank'] ) ? (int) $right['rank'] : 1000;

				if ( $left_rank === $right_rank ) {
					return 0;
				}

				return ( $left_rank < $right_rank ) ? -1 : 1;
			}
		);

		$lines = array(
			sprintf(
				/* translators: %s: site name. */
				__( 'Safety Pass found something to look at on %s.', 'safety-pass-by-nuem8' ),
				self::site_name()
			),
			'',
		);

		foreach ( $problems as $finding ) {
			$status   = ( 'stop' === $finding['status'] ) ? __( 'Stop', 'safety-pass-by-nuem8' ) : __( 'Look', 'safety-pass-by-nuem8' );
			$title    = isset( $finding['title'] ) ? self::plain( $finding['title'] ) : '';
			$is_files = isset( $finding['id'] ) && 'files' === $finding['id'];
			$lines[]  = $status . ' — ' . $title;

			if ( $is_files ) {
				$lines[] = __( 'These notes are not a malware verdict.', 'safety-pass-by-nuem8' );
			} elseif ( ! empty( $finding['detail'] ) ) {
				$lines[] = self::plain( $finding['detail'] );
			}

			if ( ! $is_files && ! empty( $finding['action'] ) ) {
				$lines[] = self::plain( $finding['action'] );
			}

			if ( ! empty( $finding['items'] ) && is_array( $finding['items'] ) ) {
				foreach ( $finding['items'] as $item ) {
					if ( is_string( $item ) && '' !== $item ) {
						$lines[] = self::plain( $item );
					}
				}
			}

			$lines[] = '';
		}

		$lines[] = __( 'Open Safety Pass:', 'safety-pass-by-nuem8' );
		$lines[] = admin_url( 'admin.php?page=nuem8-safety-pass' );

		return implode( "\n", $lines );
	}

	/**
	 * @param WP_User $user Held administrator.
	 * @return string
	 */
	private static function held_body( WP_User $user ) {
		$lines = array(
			sprintf(
				/* translators: %s: site name. */
				__( 'A new administrator was added on %s and cannot sign in yet.', 'safety-pass-by-nuem8' ),
				self::site_name()
			),
			'',
			__( 'Name:', 'safety-pass-by-nuem8' ) . ' ' . self::plain( $user->display_name ),
			__( 'Login:', 'safety-pass-by-nuem8' ) . ' ' . self::plain( $user->user_login ),
			__( 'Email:', 'safety-pass-by-nuem8' ) . ' ' . self::plain( $user->user_email ),
			'',
			__( 'Approve the account in Safety Pass if you added it. If you did not, leave it disabled.', 'safety-pass-by-nuem8' ),
			'',
			admin_url( 'admin.php?page=nuem8-safety-pass&tab=administrators' ),
		);

		return implode( "\n", $lines );
	}

	/**
	 * A new administrator the report could not disable.
	 *
	 * @param WP_User $user New administrator.
	 * @return string
	 */
	private static function open_admin_body( WP_User $user ) {
		$lines = array(
			sprintf(
				/* translators: %s: site name. */
				__( 'A new administrator was added on %s. Safety Pass did not disable the account.', 'safety-pass-by-nuem8' ),
				self::site_name()
			),
			'',
			__( 'Name:', 'safety-pass-by-nuem8' ) . ' ' . self::plain( $user->display_name ),
			__( 'Login:', 'safety-pass-by-nuem8' ) . ' ' . self::plain( $user->user_login ),
			__( 'Email:', 'safety-pass-by-nuem8' ) . ' ' . self::plain( $user->user_email ),
			'',
			__( 'Mark the account expected in Safety Pass if you added it. If you did not, disable it there.', 'safety-pass-by-nuem8' ),
			'',
			admin_url( 'admin.php?page=nuem8-safety-pass&tab=administrators' ),
		);

		return implode( "\n", $lines );
	}

	/**
	 * @param WP_User|null $recipient Selected administrator, when mail can be sent.
	 */
	private static function render_notice( $recipient ) {
		$flag     = self::query_flag( 'mail' );
		$messages = array(
			'saved'       => array( 'success', '' ),
			'cleared'     => array( 'success', __( 'Email is off.', 'safety-pass-by-nuem8' ) ),
			'invalid'     => array( 'error', __( 'That account cannot receive Safety Pass email.', 'safety-pass-by-nuem8' ) ),
			'test_sent'   => array( 'success', __( 'Test email sent.', 'safety-pass-by-nuem8' ) ),
			'test_failed' => array( 'error', __( 'The test email did not send.', 'safety-pass-by-nuem8' ) ),
		);

		if ( ! isset( $messages[ $flag ] ) ) {
			return;
		}

		$message = $messages[ $flag ][1];

		if ( 'saved' === $flag && $recipient instanceof WP_User ) {
			$message = sprintf(
				/* translators: %s: administrator name. */
				__( 'Email will go to %s.', 'safety-pass-by-nuem8' ),
				self::plain( $recipient->display_name )
			);
		}

		if ( '' === $message ) {
			return;
		}

		echo '<div class="notice notice-' . esc_attr( $messages[ $flag ][0] ) . ' inline is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Active administrators with an email address.
	 *
	 * @return WP_User[]
	 */
	private static function candidates() {
		$users = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);
		$list  = array();
		$seen  = array();

		foreach ( (array) $users as $user ) {
			if ( ! self::can_receive( $user ) ) {
				continue;
			}

			$seen[ (int) $user->ID ] = true;
			$list[]                  = $user;
		}

		if ( ! is_multisite() ) {
			return $list;
		}

		foreach ( get_super_admins() as $login ) {
			if ( ! is_string( $login ) || '' === $login ) {
				continue;
			}

			$super = get_user_by( 'login', $login );

			if ( ! self::can_receive( $super ) || isset( $seen[ (int) $super->ID ] ) ) {
				continue;
			}

			$list[] = $super;
		}

		return $list;
	}

	/**
	 * @param mixed $user Candidate.
	 * @return bool
	 */
	private static function can_receive( $user ) {
		if ( ! $user instanceof WP_User || (int) $user->ID < 1 || ! is_email( $user->user_email ) ) {
			return false;
		}

		if ( ! in_array( 'administrator', (array) $user->roles, true ) && ! ( is_multisite() && is_super_admin( $user->ID ) ) ) {
			return false;
		}

		return ! ( class_exists( 'NueM8_Safety_Pass_Checks' ) && NueM8_Safety_Pass_Checks::is_disabled( $user->ID ) );
	}

	/**
	 * Keep line breaks when the site's mailer sends HTML.
	 *
	 * @return string
	 */
	public static function html_content_type() {
		return 'text/html';
	}

	/**
	 * @param string $subject Mail subject.
	 * @param string $body    Plain text body.
	 * @return bool
	 */
	private static function deliver( $subject, $body ) {
		$user = self::recipient();

		if ( ! $user ) {
			return false;
		}

		$html = nl2br( esc_html( str_replace( array( "\r\n", "\r" ), "\n", $body ) ), false );

		self::$sending = true;
		add_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );
		$sent = wp_mail( $user->user_email, self::plain( $subject ), $html );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );
		self::$sending = false;

		if ( $sent ) {
			delete_option( self::LAST_ERROR_OPTION );
		}

		return (bool) $sent;
	}

	/**
	 * @return bool
	 */
	private static function can_send() {
		return self::recipient() instanceof WP_User;
	}

	/**
	 * @param WP_User $user Candidate recipient.
	 * @return bool
	 */
	private static function is_mail_admin( WP_User $user ) {
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return true;
		}

		return is_multisite() && is_super_admin( $user->ID );
	}

	/**
	 * @return int[]
	 */
	private static function notified_ids() {
		$stored = get_option( self::NOTIFIED_OPTION, array() );
		$ids    = array();

		foreach ( (array) $stored as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @param int $user_id Held user ID.
	 */
	private static function mark_notified( $user_id ) {
		$ids   = self::notified_ids();
		$ids[] = (int) $user_id;
		$ids   = array_values( array_unique( $ids ) );

		if ( count( $ids ) > 100 ) {
			$ids = array_slice( $ids, -100 );
		}

		update_option( self::NOTIFIED_OPTION, $ids, false );
	}

	/**
	 * The first-activation question has been answered.
	 */
	private static function complete_prompt() {
		update_option( self::PROMPT_OPTION, 'done', false );
	}

	/**
	 * @return bool
	 */
	private static function can_manage() {
		return class_exists( 'NueM8_Safety_Pass_Checks' ) && NueM8_Safety_Pass_Checks::current_user_can_view();
	}

	/**
	 * @param string $flag Redirect result.
	 */
	private static function redirect( $flag ) {
		wp_safe_redirect(
			wp_nonce_url(
				add_query_arg(
					array(
						'mail' => $flag,
					),
					admin_url( 'admin.php?page=nuem8-safety-pass' )
				),
				'nuem8_safety_pass_screen'
			)
		);
		exit;
	}

	/**
	 * @param string $message Already translated message.
	 */
	private static function deny( $message ) {
		wp_die(
			esc_html( $message ),
			'',
			array(
				'response' => 403,
			)
		);
	}

	/**
	 * @param string $key Query argument.
	 * @return string
	 */
	private static function query_flag( $key ) {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'nuem8_safety_pass_screen' ) ) {
			return '';
		}

		if ( ! isset( $_GET[ $key ] ) || is_array( $_GET[ $key ] ) ) {
			return '';
		}

		return sanitize_key( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) );
	}

	/**
	 * Read the posted account id after the form nonce is checked.
	 *
	 * @param string $nonce_action Nonce action for this form.
	 * @return int
	 */
	private static function posted_user_id( $nonce_action ) {
		check_admin_referer( $nonce_action );

		if ( ! isset( $_POST['user_id'] ) || is_array( $_POST['user_id'] ) ) {
			return 0;
		}

		return absint( sanitize_text_field( wp_unslash( $_POST['user_id'] ) ) );
	}

	/**
	 * @return string
	 */
	private static function site_name() {
		$name = self::plain( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );

		if ( '' === $name ) {
			$name = self::plain( wp_parse_url( home_url(), PHP_URL_HOST ) );
		}

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $name, 0, 80 );
		}

		return substr( $name, 0, 80 );
	}

	/**
	 * @param mixed $value Display value.
	 * @return string
	 */
	private static function plain( $value ) {
		$text = wp_strip_all_tags( (string) $value );
		$text = preg_replace( '/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}]/u', '', $text );

		if ( ! is_string( $text ) ) {
			$text = '';
		}

		$text = str_replace( array( "\r", "\n" ), ' ', $text );

		return trim( $text );
	}
}
