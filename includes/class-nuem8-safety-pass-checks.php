<?php
/**
 * Site checks for nuem8-safety-pass by NueM8.
 *
 * @package NueM8_Safety_Pass
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the checks on the nuem8-safety-pass page.
 */
class NueM8_Safety_Pass_Checks {

	const BASELINE_OPTION = 'nuem8_safety_pass_admins';
	const LAST_LOGIN_META = 'nuem8_safety_pass_last_login';
	const DISABLED_META   = 'nuem8_safety_pass_disabled';
	const VIEW_CAP        = 'nuem8_safety_pass';
	const CORE_CACHE      = 'nuem8_sp_core';
	const CORE_LOCK       = 'nuem8_sp_core_lock';

	/**
	 * Give the view capability to the administrator role only.
	 */
	public static function grant_view_cap() {
		$role  = get_role( 'administrator' );
		$added = false;

		if ( $role && ! $role->has_cap( self::VIEW_CAP ) ) {
			$role->add_cap( self::VIEW_CAP );
			$added = true;
		}

		$user = wp_get_current_user();

		if ( $added && $user->exists() && in_array( 'administrator', (array) $user->roles, true ) ) {
			$user->get_role_caps();
		}
	}

	/**
	 * nuem8-safety-pass data is visible to administrators.
	 *
	 * A copied capability on a lesser role does not open the report.
	 *
	 * @return bool
	 */
	public static function current_user_can_view() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( is_multisite() && is_super_admin() ) {
			return true;
		}

		$user = wp_get_current_user();

		if ( ! in_array( 'administrator', (array) $user->roles, true ) ) {
			return false;
		}

		return current_user_can( self::VIEW_CAP );
	}

	/**
	 * Save the current administrators the first time the plugin is activated.
	 */
	public static function seed_admin_baseline() {
		self::baseline();
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function run() {
		if ( ! self::current_user_can_view() ) {
			return array();
		}

		return self::publish_report();
	}

	/**
	 * Run the site checks.
	 *
	 * nuem8-safety-pass Pro listens for the report. The admin screen uses run(), which requires an administrator.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function publish_report() {
		$findings = array(
			'backup'    => self::backup(),
			'php'       => self::php_version(),
			'wordpress' => self::wordpress_version(),
			'core'      => self::core_files(),
			'files'     => self::file_notes(),
			'plugins'   => self::unused_plugins(),
			'admins'    => self::administrators(),
		);

		/**
		 * Findings for the current site.
		 *
		 * @param array<string, array<string, mixed>> $findings Site checks.
		 */
		do_action( 'nuem8_safety_pass_report', $findings );

		return $findings;
	}

	/**
	 * The single most urgent item that is not OK.
	 *
	 * @param array<string, array<string, mixed>> $findings Findings from run().
	 * @return array<string, mixed>|null
	 */
	public static function next_step( array $findings ) {
		$next = null;

		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) || empty( $finding['status'] ) || 'ok' === $finding['status'] ) {
				continue;
			}

			$rank = isset( $finding['rank'] ) ? (int) $finding['rank'] : 1000;

			if ( null === $next || $rank < (int) $next['rank'] ) {
				$finding['rank'] = $rank;
				$next            = $finding;
			}
		}

		return $next;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function php_version() {
		$version = PHP_VERSION;

		if ( version_compare( $version, '8.2', '<' ) ) {
			return self::finding(
				'php',
				'stop',
				30,
				sprintf(
					/* translators: %s: PHP version number. */
					__( 'PHP %s', 'safety-pass-by-nuem8' ),
					$version
				),
				__( 'This PHP version no longer receives security fixes.', 'safety-pass-by-nuem8' ),
				__( 'Ask the host to move this site to PHP 8.3 or newer before you update plugins.', 'safety-pass-by-nuem8' )
			);
		}

		if ( version_compare( $version, '8.3', '<' ) ) {
			return self::finding(
				'php',
				'look',
				80,
				sprintf(
					/* translators: %s: PHP version number. */
					__( 'PHP %s', 'safety-pass-by-nuem8' ),
					$version
				),
				__( 'PHP 8.2 still receives security fixes through December 2026.', 'safety-pass-by-nuem8' ),
				__( 'Plan a move to PHP 8.3 or newer with the host.', 'safety-pass-by-nuem8' )
			);
		}

		return self::finding(
			'php',
			'ok',
			0,
			sprintf(
				/* translators: %s: PHP version number. */
				__( 'PHP %s', 'safety-pass-by-nuem8' ),
				$version
			),
			__( 'This PHP version still receives security fixes.', 'safety-pass-by-nuem8' ),
			''
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function wordpress_version() {
		$current  = get_bloginfo( 'version' );
		$offered  = self::offered_wordpress_version();
		$title    = sprintf(
			/* translators: %s: WordPress version number. */
			__( 'WordPress %s', 'safety-pass-by-nuem8' ),
			$current
		);

		if ( null === $offered ) {
			return self::finding(
				'wordpress',
				'look',
				65,
				$title,
				__( 'nuem8-safety-pass could not see an update check yet.', 'safety-pass-by-nuem8' ),
				__( 'Open Dashboard → Updates and confirm WordPress is current.', 'safety-pass-by-nuem8' )
			);
		}

		if ( '' === $offered ) {
			return self::finding(
				'wordpress',
				'ok',
				0,
				$title,
				__( 'No WordPress update is waiting.', 'safety-pass-by-nuem8' ),
				''
			);
		}

		return self::finding(
			'wordpress',
			'look',
			60,
			$title,
			sprintf(
				/* translators: %s: newer WordPress version number. */
				__( 'WordPress %s is ready to install.', 'safety-pass-by-nuem8' ),
				$offered
			),
			__( 'Back up the site, then open Dashboard → Updates and update WordPress.', 'safety-pass-by-nuem8' )
		);
	}

	/**
	 * Empty string means WordPress is current. Null means the check has not run.
	 *
	 * @return string|null
	 */
	private static function offered_wordpress_version() {
		$transient = get_site_transient( 'update_core' );

		if ( ! is_object( $transient ) || empty( $transient->updates ) || ! is_array( $transient->updates ) ) {
			return null;
		}

		foreach ( $transient->updates as $update ) {
			if ( ! is_object( $update ) || empty( $update->response ) || 'upgrade' !== $update->response ) {
				continue;
			}

			if ( ! empty( $update->current ) ) {
				$version = self::version_string( $update->current );

				if ( '' !== $version ) {
					return $version;
				}
			}

			if ( ! empty( $update->version ) ) {
				$version = self::version_string( $update->version );

				if ( '' !== $version ) {
					return $version;
				}
			}
		}

		return '';
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function unused_plugins() {
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();

		if ( ! is_array( $installed ) ) {
			$installed = array();
		}
		$unused    = array();

		foreach ( $installed as $file => $data ) {
			if ( is_plugin_active( $file ) ) {
				continue;
			}

			$name     = isset( $data['Name'] ) ? (string) $data['Name'] : $file;
			$unused[] = $name;
		}

		natcasesort( $unused );
		$unused = array_values( $unused );

		if ( array() === $unused ) {
			return self::finding(
				'plugins',
				'ok',
				0,
				__( 'Unused plugins', 'safety-pass-by-nuem8' ),
				__( 'Every installed plugin is turned on.', 'safety-pass-by-nuem8' ),
				'',
				array()
			);
		}

		return self::finding(
			'plugins',
			'look',
			90,
			__( 'Unused plugins', 'safety-pass-by-nuem8' ),
			sprintf(
				/* translators: %d: number of inactive plugins. */
				_n(
					'%d plugin is installed and turned off.',
					'%d plugins are installed and turned off.',
					count( $unused ),
					'safety-pass-by-nuem8'
				),
				count( $unused )
			),
			__( 'Delete plugins you are not using. They stay on the server until you delete them.', 'safety-pass-by-nuem8' ),
			$unused
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function administrators() {
		/**
		 * nuem8-safety-pass Pro disables a new administrator before this list is read.
		 *
		 * That disable-until-approval step is part of nuem8-safety-pass Pro. This plugin only reports the account.
		 */
		do_action( 'nuem8_safety_pass_before_administrators' );

		$baseline = self::baseline();
		$known = array();

		foreach ( (array) $baseline['user_ids'] as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$known[] = $id;
			}
		}

		$users    = self::administrator_users();
		$accounts = array();
		$new      = 0;

		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User || (int) $user->ID < 1 ) {
				continue;
			}
			$is_new = ! in_array( (int) $user->ID, $known, true );

			if ( $is_new ) {
				++$new;
			}

			$accounts[] = array(
				'id'         => (int) $user->ID,
				'name'       => $user->display_name,
				'login'      => $user->user_login,
				'email'      => $user->user_email,
				'registered' => mysql2date( get_option( 'date_format' ), $user->user_registered ),
				'last_login' => self::format_last_login( $user->ID ),
				'is_new'     => $is_new,
				'disabled'   => self::is_disabled( $user->ID ),
				'is_super'   => is_multisite() && is_super_admin( $user->ID ),
			);
		}

		$since = wp_date( get_option( 'date_format' ), (int) $baseline['saved_at'] );

		if ( $new > 0 ) {
			$held = 0;

			foreach ( $accounts as $account ) {
				if ( ! empty( $account['is_new'] ) && ! empty( $account['disabled'] ) ) {
					++$held;
				}
			}

			$action = $held > 0
				? __( 'A new administrator is disabled until a signed-in administrator approves them. If you added the account, approve it. If you did not, leave it disabled or remove it under Users.', 'safety-pass-by-nuem8' )
				: __( 'Approve the account if you added it. If you did not, remove it under Users. nuem8-safety-pass Pro keeps a new administrator signed out until you approve them.', 'safety-pass-by-nuem8' );

			return self::finding(
				'admins',
				'stop',
				10,
				__( 'Administrators', 'safety-pass-by-nuem8' ),
				sprintf(
					/* translators: 1: number of new administrators, 2: date the baseline was saved. */
					_n(
						'%1$d administrator was added after %2$s.',
						'%1$d administrators were added after %2$s.',
						$new,
						'safety-pass-by-nuem8'
					),
					$new,
					$since
				),
				$action,
				$accounts
			);
		}

		return self::finding(
			'admins',
			'ok',
			0,
			__( 'Administrators', 'safety-pass-by-nuem8' ),
			sprintf(
				/* translators: %s: date the administrator list was saved. */
				__( 'Every administrator is on the expected list, saved on %s.', 'safety-pass-by-nuem8' ),
				$since
			),
			'',
			$accounts
		);
	}

	/**
	 * @return array{saved_at: int, user_ids: int[]}
	 */
	private static function baseline() {
		$stored = get_option( self::BASELINE_OPTION, null );
		$parsed = self::parse_baseline( $stored );

		if ( null !== $parsed ) {
			return $parsed;
		}

		if ( null === $stored || false === $stored ) {
			$fresh = array(
				'saved_at' => time(),
				'user_ids' => self::current_admin_ids(),
			);

			add_option( self::BASELINE_OPTION, $fresh, '', false );

			return $fresh;
		}

		return array(
			'saved_at' => time(),
			'user_ids' => array(),
		);
	}

	/**
	 * @param mixed $baseline Stored option.
	 * @return array{saved_at: int, user_ids: int[]}|null
	 */
	private static function parse_baseline( $baseline ) {
		if ( ! is_array( $baseline ) || empty( $baseline['saved_at'] ) || ! isset( $baseline['user_ids'] ) || ! is_array( $baseline['user_ids'] ) ) {
			return null;
		}

		$ids = array();

		foreach ( $baseline['user_ids'] as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array(
			'saved_at' => (int) $baseline['saved_at'],
			'user_ids' => array_values( array_unique( $ids ) ),
		);
	}

	/**
	 * Site administrators, plus network administrators on multisite.
	 *
	 * nuem8-safety-pass Pro reads this list when it disables a new administrator.
	 *
	 * @return WP_User[]
	 */
	public static function administrator_users() {
		$users = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'registered',
				'order'   => 'ASC',
			)
		);
		$list  = array();
		$seen  = array();

		foreach ( (array) $users as $user ) {
			if ( ! $user instanceof WP_User || (int) $user->ID < 1 ) {
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

			if ( ! $super instanceof WP_User || isset( $seen[ (int) $super->ID ] ) ) {
				continue;
			}

			$seen[ (int) $super->ID ] = true;
			$list[]                   = $super;
		}

		return $list;
	}

	/**
	 * @return int[]
	 */
	private static function current_admin_ids() {
		$ids = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		);

		$clean = array();

		foreach ( (array) $ids as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$clean[] = $id;
			}
		}

		return $clean;
	}

	/**
	 * Save the sign-in time when an administrator logs in.
	 *
	 * @param string       $user_login User login.
	 * @param WP_User|null $user       Logged-in user.
	 */
	public static function record_login( $user_login, $user = null ) {
		if ( ! is_string( $user_login ) ) {
			$user_login = '';
		}

		if ( ! $user instanceof WP_User ) {
			if ( '' === $user_login ) {
				return;
			}

			$user = get_user_by( 'login', $user_login );
		}

		if ( ! $user instanceof WP_User || (int) $user->ID < 1 || self::is_disabled( $user->ID ) ) {
			return;
		}

		$is_admin = in_array( 'administrator', (array) $user->roles, true );
		$is_super = is_multisite() && is_super_admin( $user->ID );

		if ( ! $is_admin && ! $is_super ) {
			return;
		}

		update_user_meta( $user->ID, self::LAST_LOGIN_META, time() );
	}

	/**
	 * Whether nuem8-safety-pass Pro is active and will disable a new administrator until approval.
	 *
	 * This plugin does not disable that account. The screen uses the flag to choose its wording.
	 *
	 * @return bool
	 */
	public static function holds_new_admins() {
		/**
		 * Whether nuem8-safety-pass Pro is disabling a new administrator until approval.
		 *
		 * @param bool $hold Default false. nuem8-safety-pass Pro sets this to true.
		 */
		return (bool) apply_filters( 'nuem8_safety_pass_hold_new_admins', false );
	}

	/**
	 * Mark a new administrator as expected. When nuem8-safety-pass Pro disabled the account, this lets them sign in.
	 *
	 * The new account cannot approve itself.
	 *
	 * @param int $user_id User ID.
	 * @return string approved, approve_self, unchanged, or invalid.
	 */
	public static function approve_admin( $user_id ) {
		if ( ! self::current_user_can_view() ) {
			return 'invalid';
		}

		$user_id = (int) $user_id;

		if ( $user_id < 1 ) {
			return 'invalid';
		}

		if ( $user_id === (int) get_current_user_id() ) {
			return 'approve_self';
		}

		if ( self::is_expected( $user_id ) ) {
			return 'unchanged';
		}

		if ( ! self::trust_admin( $user_id ) ) {
			return 'invalid';
		}

		delete_user_meta( $user_id, self::DISABLED_META );

		return 'approved';
	}

	/**
	 * A saved expected list is required before nuem8-safety-pass Pro can disable a new administrator.
	 *
	 * A missing list is created from the current administrators. An unreadable list is left alone.
	 *
	 * @return bool
	 */
	public static function baseline_can_hold() {
		$stored = get_option( self::BASELINE_OPTION, null );

		if ( null === $stored || false === $stored ) {
			return false;
		}

		return null !== self::parse_baseline( $stored );
	}

	/**
	 * Whether this administrator is on the expected list.
	 *
	 * nuem8-safety-pass Pro leaves an expected account alone.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_expected( $user_id ) {
		$baseline = self::baseline();

		return in_array( (int) $user_id, $baseline['user_ids'], true );
	}

	/**
	 * Whether nuem8-safety-pass has disabled this account.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_disabled( $user_id ) {
		return '1' === (string) get_user_meta( $user_id, self::DISABLED_META, true );
	}

	/**
	 * Enable or disable an administrator.
	 *
	 * The signed-in account cannot be disabled. The last administrator who can still sign in cannot be disabled.
	 *
	 * @param int  $user_id User ID.
	 * @param bool $disable True to disable, false to enable.
	 * @return string disabled, enabled, self, last, protected, held, or invalid.
	 */
	public static function set_admin_access( $user_id, $disable ) {
		if ( ! self::current_user_can_view() ) {
			return 'invalid';
		}

		$user_id = (int) $user_id;

		if ( $user_id < 1 ) {
			return 'invalid';
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return 'invalid';
		}

		$is_admin = in_array( 'administrator', (array) $user->roles, true );
		$is_super = is_multisite() && is_super_admin( $user_id );

		if ( ! $is_admin && ! $is_super ) {
			return 'invalid';
		}

		if ( $disable ) {
			if ( $is_super ) {
				return 'protected';
			}

			if ( $user_id === (int) get_current_user_id() ) {
				return 'self';
			}

			if ( ! self::another_manager_remains( $user_id ) ) {
				return 'last';
			}

			update_user_meta( $user_id, self::DISABLED_META, '1' );

			if ( ! self::another_manager_remains( 0 ) ) {
				delete_user_meta( $user_id, self::DISABLED_META );

				return 'last';
			}

			self::end_sessions( $user_id );

			return 'disabled';
		}

		if ( ! $is_super && ! self::is_expected( $user_id ) ) {
			return 'held';
		}

		delete_user_meta( $user_id, self::DISABLED_META );

		return 'enabled';
	}

	/**
	 * Stop a disabled administrator from signing in.
	 *
	 * @param WP_User|WP_Error|null $user     User being authenticated.
	 * @param string                $username Unused login name.
	 * @param string                $password Unused password.
	 * @return WP_User|WP_Error|null
	 */
	public static function block_disabled_login( $user, $username, $password ) {
		unset( $username, $password );

		if ( $user instanceof WP_User && self::is_disabled( $user->ID ) ) {
			return new WP_Error(
				'nuem8_safety_pass_disabled',
				__( 'This account is disabled. An administrator can enable it in nuem8-safety-pass.', 'safety-pass-by-nuem8' )
			);
		}

		return $user;
	}

	/**
	 * Sign out a disabled administrator who still has a session.
	 */
	public static function reject_disabled_user( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id < 1 ) {
			return $user_id;
		}

		static $checking = false;

		if ( $checking ) {
			return 0;
		}

		$checking = true;
		$disabled = self::is_disabled( $user_id );

		if ( ! $disabled ) {
			$checking = false;

			return $user_id;
		}

		self::end_sessions( $user_id );

		if ( function_exists( 'wp_clear_auth_cookie' ) ) {
			wp_clear_auth_cookie();
		}

		$checking = false;

		return 0;
	}

	/**
	 * Sign out a disabled administrator who still has a session.
	 */
	public static function logout_if_disabled() {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return;
		}

		$user_id = (int) get_current_user_id();

		if ( $user_id < 1 || ! self::is_disabled( $user_id ) ) {
			return;
		}

		wp_logout();

		$during_ajax = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX );
		$during_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$on_login    = isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'];

		if ( $during_ajax || $during_rest || $on_login || headers_sent() ) {
			return;
		}

		wp_safe_redirect( wp_login_url() );
		exit;
	}

	/**
	 * Whether someone who can still sign in would remain.
	 *
	 * nuem8-safety-pass Pro uses this so a new administrator is not disabled when they are the last one who can sign in.
	 *
	 * @param int $except_user_id Account to ignore. Zero counts every account.
	 * @return bool
	 */
	public static function another_manager_remains( $except_user_id ) {
		$except_user_id = (int) $except_user_id;

		foreach ( self::current_admin_ids() as $id ) {
			if ( $id === $except_user_id || self::is_disabled( $id ) ) {
				continue;
			}

			return true;
		}

		if ( ! is_multisite() ) {
			return false;
		}

		foreach ( get_super_admins() as $login ) {
			if ( ! is_string( $login ) || '' === $login ) {
				continue;
			}

			$super = get_user_by( 'login', $login );

			if ( ! $super instanceof WP_User ) {
				continue;
			}

			$id = (int) $super->ID;

			if ( $id < 1 || $id === $except_user_id || self::is_disabled( $id ) ) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * End every login session for one user.
	 *
	 * nuem8-safety-pass Pro calls this after it disables a new administrator.
	 *
	 * @param int $user_id User ID.
	 */
	public static function end_sessions( $user_id ) {
		if ( ! class_exists( 'WP_Session_Tokens' ) ) {
			require_once ABSPATH . 'wp-includes/class-wp-session-tokens.php';
		}

		WP_Session_Tokens::get_instance( $user_id )->destroy_all();
	}

	/**
	 * @param int $user_id User ID.
	 * @return string Localized date and time, or an empty string when none is recorded.
	 */
	private static function format_last_login( $user_id ) {
		$timestamp = self::last_login_timestamp( $user_id );

		if ( $timestamp < 1 ) {
			return '';
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}

	/**
	 * Recorded sign-in time. An open session is kept so it remains after logout.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	private static function last_login_timestamp( $user_id ) {
		$stored  = self::unix_time( get_user_meta( $user_id, self::LAST_LOGIN_META, true ) );
		$session = self::newest_session_login( $user_id );
		$newest  = max( $stored, $session );

		if ( $newest > $stored ) {
			update_user_meta( $user_id, self::LAST_LOGIN_META, $newest );
		}

		return $newest;
	}

	/**
	 * @param int $user_id User ID.
	 * @return int
	 */
	private static function newest_session_login( $user_id ) {
		$sessions = get_user_meta( $user_id, 'session_tokens', true );

		if ( ! is_array( $sessions ) ) {
			return 0;
		}

		$newest = 0;

		foreach ( $sessions as $session ) {
			if ( ! is_array( $session ) || empty( $session['login'] ) ) {
				continue;
			}

			$newest = max( $newest, self::unix_time( $session['login'] ) );
		}

		return $newest;
	}

	/**
	 * Add one administrator to the expected list.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function trust_admin( $user_id ) {
		if ( ! self::current_user_can_view() ) {
			return false;
		}

		$user_id = (int) $user_id;

		if ( $user_id < 1 ) {
			return false;
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return false;
		}

		$is_admin = in_array( 'administrator', (array) $user->roles, true );
		$is_super = is_multisite() && is_super_admin( $user_id );

		if ( ! $is_admin && ! $is_super ) {
			return false;
		}

		$baseline = self::baseline();
		$ids      = $baseline['user_ids'];

		if ( in_array( (int) $user_id, $ids, true ) ) {
			return true;
		}

		$ids[] = (int) $user_id;
		$ids      = array_values( array_unique( array_map( 'intval', $ids ) ) );

		return update_option(
			self::BASELINE_OPTION,
			array(
				'saved_at' => $baseline['saved_at'],
				'user_ids' => $ids,
			),
			false
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function backup() {
		$readable = array();

		if ( self::plugin_active( array( 'updraftplus/updraftplus.php' ), 'UpdraftPlus' ) ) {
			$readable['UpdraftPlus'] = self::updraft_last_run();
		}

		if ( self::plugin_active( array( 'backwpup/backwpup.php', 'backwpup-pro/backwpup.php' ), 'BackWPup' ) ) {
			$readable['BackWPup'] = self::backwpup_last_run();
		}

		if ( self::plugin_active( array( 'wpvivid-backuprestore/wpvivid-backuprestore.php', 'wpvivid-backup-pro/wpvivid-backup-pro.php' ), 'WPvivid_Setting' ) ) {
			$readable['WPvivid'] = self::wpvivid_last_run();
		}

		if ( array() !== $readable ) {
			$best_name = '';
			$best_time = 0;

			foreach ( $readable as $name => $time ) {
				if ( $time > $best_time ) {
					$best_time = $time;
					$best_name = $name;
				}
			}

			if ( $best_time > 0 ) {
				return self::backup_age( $best_name, $best_time );
			}

			$name = (string) array_key_first( $readable );

			return self::finding(
				'backup',
				'look',
				50,
				__( 'Backup', 'safety-pass-by-nuem8' ),
				sprintf(
					/* translators: %s: backup plugin name. */
					__( '%s is on, and it has not recorded a backup yet.', 'safety-pass-by-nuem8' ),
					$name
				),
				__( 'Run one backup and keep the copy somewhere other than this server. Then open nuem8-safety-pass again.', 'safety-pass-by-nuem8' )
			);
		}

		$unread = self::unread_backup_plugins();

		if ( array() !== $unread ) {
			return self::finding(
				'backup',
				'look',
				50,
				__( 'Backup', 'safety-pass-by-nuem8' ),
				sprintf(
					/* translators: %s: comma-separated backup plugin names. */
					__( '%s is active. nuem8-safety-pass cannot read when it last finished.', 'safety-pass-by-nuem8' ),
					implode( ', ', $unread )
				),
				__( 'Open that backup plugin and confirm the last copy is recent before you update anything.', 'safety-pass-by-nuem8' )
			);
		}

		return self::finding(
			'backup',
			'stop',
			20,
			__( 'Backup', 'safety-pass-by-nuem8' ),
			__( 'No backup plugin nuem8-safety-pass recognizes is turned on.', 'safety-pass-by-nuem8' ),
			__( 'Install a backup plugin, run one backup, and keep the copy off this server. UpdraftPlus, BackWPup, and WPvivid are the ones this page can read.', 'safety-pass-by-nuem8' )
		);
	}

	/**
	 * @param string $name Plugin name.
	 * @param int    $timestamp Unix time of the last backup.
	 * @return array<string, mixed>
	 */
	private static function backup_age( $name, $timestamp ) {
		$when = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
		$days = (int) floor( max( 0, time() - $timestamp ) / DAY_IN_SECONDS );
		$detail = sprintf(
			/* translators: 1: backup plugin name, 2: localized date and time. */
			__( '%1$s last finished a backup on %2$s.', 'safety-pass-by-nuem8' ),
			$name,
			$when
		);

		if ( $days > 30 ) {
			return self::finding(
				'backup',
				'stop',
				40,
				__( 'Backup', 'safety-pass-by-nuem8' ),
				$detail,
				__( 'Run a new backup before you update anything. The last one is more than 30 days old.', 'safety-pass-by-nuem8' )
			);
		}

		if ( $days > 7 ) {
			return self::finding(
				'backup',
				'look',
				70,
				__( 'Backup', 'safety-pass-by-nuem8' ),
				$detail,
				__( 'Run a backup this week, before the next plugin or WordPress update.', 'safety-pass-by-nuem8' )
			);
		}

		return self::finding(
			'backup',
			'ok',
			0,
			__( 'Backup', 'safety-pass-by-nuem8' ),
			$detail,
			''
		);
	}

	/**
	 * @return string[]
	 */
	private static function unread_backup_plugins() {
		$found = array();
		$map   = array(
			'duplicator/duplicator.php'                           => 'Duplicator',
			'duplicator-pro/duplicator-pro.php'                   => 'Duplicator Pro',
			'all-in-one-wp-migration/all-in-one-wp-migration.php' => 'All-in-One WP Migration',
			'backupbuddy/backupbuddy.php'                         => 'Solid Backups',
			'solid-backups/solid-backups.php'                     => 'Solid Backups',
		);

		foreach ( $map as $file => $name ) {
			if ( self::plugin_active( array( $file ) ) ) {
				$found[] = $name;
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * @return int
	 */
	private static function updraft_last_run() {
		$last = get_option( 'updraft_last_backup' );

		if ( is_array( $last ) && ! empty( $last['backup_time'] ) ) {
			return self::unix_time( $last['backup_time'] );
		}

		return self::newest_marked_time( get_option( 'updraft_backup_history' ) );
	}

	/**
	 * @return int
	 */
	private static function backwpup_last_run() {
		return self::newest_marked_time( get_option( 'backwpup_jobs' ) );
	}

	/**
	 * @return int
	 */
	private static function wpvivid_last_run() {
		return self::newest_marked_time( get_option( 'wpvivid_backup_list' ) );
	}

	/**
	 * @param mixed $value Option value.
	 * @return int
	 */
	private static function newest_marked_time( $value ) {
		return self::walk_times( $value, 0 );
	}

	/**
	 * @param mixed $value Nested option value.
	 * @param int   $depth Recursion depth.
	 * @return int
	 */
	private static function walk_times( $value, $depth ) {
		if ( $depth > 6 || ! is_array( $value ) ) {
			return 0;
		}

		$newest = 0;

		foreach ( array( 'create_time', 'backup_time', 'timestamp', 'lastrun' ) as $key ) {
			if ( ! empty( $value[ $key ] ) && is_numeric( $value[ $key ] ) ) {
				$newest = max( $newest, self::unix_time( $value[ $key ] ) );
			}
		}

		foreach ( $value as $child ) {
			if ( is_array( $child ) ) {
				$newest = max( $newest, self::walk_times( $child, $depth + 1 ) );
			}
		}

		return $newest;
	}

	/**
	 * @param mixed $value Raw timestamp.
	 * @return int
	 */
	private static function unix_time( $value ) {
		$time = (int) $value;

		if ( $time > 20000000000 ) {
			$time = (int) floor( $time / 1000 );
		}

		if ( $time < 946684800 || $time > time() + DAY_IN_SECONDS ) {
			return 0;
		}

		return $time;
	}

	/**
	 * @param string[] $files Plugin basenames.
	 * @param string   $class Optional class that exists only when the plugin is loaded.
	 * @return bool
	 */
	private static function plugin_active( array $files, $class = '' ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( $files as $file ) {
			if ( is_plugin_active( $file ) ) {
				return true;
			}
		}

		return '' !== $class && class_exists( $class, false );
	}

	/**
	 * @param mixed $value Raw version from the update check.
	 * @return string
	 */
	private static function version_string( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = (string) $value;

		if ( preg_match( '/^[0-9][0-9A-Za-z._+-]{0,31}$/', $value ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * Compare WordPress core files with the official checksum list.
	 *
	 * The result is saved for 12 hours. Plugins, themes, and uploads are not compared.
	 *
	 * @return array<string, mixed>
	 */
	private static function core_files() {
		$cached = self::cached_core_result();

		if ( null === $cached ) {
			$cached = self::compare_core_files();
		}

		return self::core_finding( $cached );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function cached_core_result() {
		$stored = get_transient( self::CORE_CACHE );

		if ( ! is_array( $stored ) || empty( $stored['state'] ) ) {
			return null;
		}

		$state = (string) $stored['state'];

		if ( ! in_array( $state, array( 'match', 'differ', 'unreachable', 'unreadable' ), true ) ) {
			return null;
		}

		if ( ( isset( $stored['version'] ) ? (string) $stored['version'] : '' ) !== self::version_string( get_bloginfo( 'version' ) ) ) {
			return null;
		}

		if ( ( isset( $stored['locale'] ) ? (string) $stored['locale'] : '' ) !== self::core_locale() ) {
			return null;
		}

		return $stored;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function compare_core_files() {
		$version = self::version_string( get_bloginfo( 'version' ) );
		$locale  = self::core_locale();
		$result  = array(
			'version' => $version,
			'locale'  => $locale,
			'state'   => 'unreachable',
			'files'   => array(),
			'extra'   => 0,
			'total'   => 0,
		);

		if ( '' === $version ) {
			self::store_core_result( $result, HOUR_IN_SECONDS );
			return $result;
		}

		if ( get_transient( self::CORE_LOCK ) ) {
			$result['state'] = 'running';
			return $result;
		}

		set_transient( self::CORE_LOCK, '1', 2 * MINUTE_IN_SECONDS );

		$checksums = self::fetch_core_checksums( $version, $locale );

		if ( ! is_array( $checksums ) ) {
			delete_transient( self::CORE_LOCK );
			self::store_core_result( $result, HOUR_IN_SECONDS );
			return $result;
		}

		$root       = wp_normalize_path( untrailingslashit( ABSPATH ) );
		$differ     = array();
		$unreadable = array();
		$checked    = 0;

		foreach ( $checksums as $file => $checksum ) {
			$path = self::core_absolute_path( $file, $root );

			if ( '' === $path || ! is_string( $checksum ) || ! preg_match( '/^[a-f0-9]{32}$/', $checksum ) ) {
				continue;
			}

			++$checked;

			$real = realpath( $path );

			if ( false === $real ) {
				$differ[] = $file;
				continue;
			}

			$real = wp_normalize_path( $real );

			if ( ! self::path_inside( $real, $root ) ) {
				$differ[] = $file;
				continue;
			}

			if ( ! is_readable( $real ) ) {
				$unreadable[] = $file;
				continue;
			}

			$hash = md5_file( $real );

			if ( ! is_string( $hash ) || ! hash_equals( $checksum, strtolower( $hash ) ) ) {
				$differ[] = $file;
			}
		}

		delete_transient( self::CORE_LOCK );

		if ( array() !== $differ ) {
			$result['state'] = 'differ';
			$result['total'] = count( $differ );
			$result['files'] = array_slice( $differ, 0, 12 );
			$result['extra'] = max( 0, $result['total'] - count( $result['files'] ) );
		} elseif ( array() !== $unreadable ) {
			$result['state'] = 'unreadable';
			$result['total'] = count( $unreadable );
			$result['files'] = array_slice( $unreadable, 0, 12 );
			$result['extra'] = max( 0, $result['total'] - count( $result['files'] ) );
		} elseif ( $checked > 0 ) {
			$result['state'] = 'match';
		}

		$ttl = in_array( $result['state'], array( 'match', 'differ', 'unreadable' ), true ) ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS;
		self::store_core_result( $result, $ttl );

		return $result;
	}

	/**
	 * Download the public core file list from WordPress.org.
	 *
	 * The request sends the WordPress version and language only. It does not send accounts, content, or the site address.
	 *
	 * @param string $version Official WordPress version.
	 * @param string $locale  Locale package, or en_US.
	 * @return array<string, string>|null
	 */
	private static function fetch_core_checksums( $version, $locale ) {
		$response = wp_remote_get(
			'https://api.wordpress.org/core/checksums/1.0/?version=' . rawurlencode( $version ) . '&locale=' . rawurlencode( $locale ),
			array(
				'timeout'     => 15,
				'redirection' => 2,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( ! is_string( $body ) || strlen( $body ) < 20 || strlen( $body ) > 2000000 ) {
			return null;
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data['checksums'] ) || ! is_array( $data['checksums'] ) || count( $data['checksums'] ) > 20000 ) {
			return null;
		}

		return $data['checksums'];
	}

	/**
	 * @param array<string, mixed> $result Comparison result.
	 * @param int                  $ttl    Seconds to keep the result.
	 */
	private static function store_core_result( array $result, $ttl ) {
		$files = array();

		if ( isset( $result['files'] ) && is_array( $result['files'] ) ) {
			foreach ( $result['files'] as $file ) {
				if ( is_string( $file ) && '' !== $file ) {
					$files[] = $file;
				}
			}
		}

		set_transient(
			self::CORE_CACHE,
			array(
				'version' => isset( $result['version'] ) ? (string) $result['version'] : '',
				'locale'  => isset( $result['locale'] ) ? (string) $result['locale'] : '',
				'state'   => isset( $result['state'] ) ? (string) $result['state'] : 'unreachable',
				'files'   => $files,
				'extra'   => isset( $result['extra'] ) ? (int) $result['extra'] : 0,
				'total'   => isset( $result['total'] ) ? (int) $result['total'] : 0,
			),
			(int) $ttl
		);
	}

	/**
	 * @param array<string, mixed> $result Comparison result.
	 * @return array<string, mixed>
	 */
	private static function core_finding( array $result ) {
		$state = isset( $result['state'] ) ? (string) $result['state'] : 'unreachable';
		$title = __( 'Core files', 'safety-pass-by-nuem8' );
		$scope = __( 'Plugins, themes, and uploads are not part of this check.', 'safety-pass-by-nuem8' );

		if ( 'running' === $state ) {
			return self::finding(
				'core',
				'look',
				96,
				$title,
				__( 'nuem8-safety-pass is comparing core files with WordPress.org.', 'safety-pass-by-nuem8' ),
				__( 'Open nuem8-safety-pass again in a minute and read the result.', 'safety-pass-by-nuem8' )
			);
		}

		if ( 'match' === $state ) {
			return self::finding(
				'core',
				'ok',
				0,
				$title,
				__( 'Core files match the official WordPress copy. nuem8-safety-pass saves this result for 12 hours.', 'safety-pass-by-nuem8' ) . ' ' . $scope,
				''
			);
		}

		$items = array();

		if ( isset( $result['files'] ) && is_array( $result['files'] ) ) {
			foreach ( $result['files'] as $file ) {
				if ( is_string( $file ) && '' !== $file ) {
					$items[] = $file;
				}
			}
		}

		$extra = isset( $result['extra'] ) ? (int) $result['extra'] : 0;

		if ( $extra > 0 ) {
			$items[] = sprintf(
				/* translators: %d: number of additional files not listed. */
				_n( '%d more file', '%d more files', $extra, 'safety-pass-by-nuem8' ),
				$extra
			);
		}

		$total = isset( $result['total'] ) ? (int) $result['total'] : count( $items );

		if ( 'differ' === $state && $total > 0 ) {
			return self::finding(
				'core',
				'look',
				55,
				$title,
				sprintf(
					/* translators: %d: number of core files that differ. */
					_n(
						'%d core file does not match the official WordPress copy. A host or a plugin may have changed it. This is not a malware verdict.',
						'%d core files do not match the official WordPress copy. A host or a plugin may have changed them. This is not a malware verdict.',
						$total,
						'safety-pass-by-nuem8'
					),
					$total
				) . ' ' . $scope,
				__( 'Compare the listed files with a fresh copy of this same WordPress version. nuem8-safety-pass will not replace them.', 'safety-pass-by-nuem8' ),
				$items
			);
		}

		if ( 'unreadable' === $state && $total > 0 ) {
			return self::finding(
				'core',
				'look',
				58,
				$title,
				sprintf(
					/* translators: %d: number of core files that could not be read. */
					_n(
						'nuem8-safety-pass could not read %d core file.',
						'nuem8-safety-pass could not read %d core files.',
						$total,
						'safety-pass-by-nuem8'
					),
					$total
				) . ' ' . $scope,
				__( 'Ask the host to confirm WordPress can read its own core files.', 'safety-pass-by-nuem8' ),
				$items
			);
		}

		return self::finding(
			'core',
			'look',
			66,
			$title,
			__( 'nuem8-safety-pass could not download the official file list from WordPress.org.', 'safety-pass-by-nuem8' ) . ' ' . $scope,
			__( 'Open nuem8-safety-pass again later. The update screen under Dashboard still shows whether WordPress itself is current.', 'safety-pass-by-nuem8' )
		);
	}

	/**
	 * @return string
	 */
	private static function core_locale() {
		global $wp_local_package;

		if ( is_string( $wp_local_package ) && preg_match( '/^[A-Za-z]{2,3}_[A-Za-z]{2}$/', $wp_local_package ) ) {
			return $wp_local_package;
		}

		return 'en_US';
	}

	/**
	 * Relative core path that stays inside the WordPress folder.
	 *
	 * @param mixed  $relative Checksum path.
	 * @param string $root     Normalized WordPress folder without a trailing slash.
	 * @return string
	 */
	private static function core_absolute_path( $relative, $root ) {
		if ( ! is_string( $relative ) || strlen( $relative ) > 180 ) {
			return '';
		}

		if ( ! preg_match( '/^[A-Za-z0-9._\/-]+$/', $relative ) ) {
			return '';
		}

		if ( false !== strpos( $relative, '..' ) || 0 === strpos( $relative, '/' ) || 0 === strpos( $relative, 'wp-content/' ) ) {
			return '';
		}

		$path = wp_normalize_path( $root . '/' . $relative );

		if ( ! self::path_inside( $path, $root ) ) {
			return '';
		}

		return $path;
	}

	/**
	 * @param string $path Normalized file path.
	 * @param string $root Normalized WordPress folder without a trailing slash.
	 * @return bool
	 */
	private static function path_inside( $path, $root ) {
		$prefix = $root . '/';

		if ( 0 === strpos( $path, $prefix ) ) {
			return true;
		}

		return 0 === strpos( PHP_OS, 'WIN' ) && 0 === stripos( $path, $prefix );
	}

	/**
	 * Must-use plugins, the file editor, and a root .htaccess PHP prepend.
	 *
	 * @return array<string, mixed>
	 */
	private static function file_notes() {
		$items    = array();
		$mu       = self::must_use_names();
		$htaccess = self::htaccess_state();

		if ( array() !== $mu ) {
			$items[] = sprintf(
				/* translators: %s: comma-separated must-use plugin names. */
				__( 'Always on: %s. These must-use plugins stay active.', 'safety-pass-by-nuem8' ),
				implode( ', ', $mu )
			);
		}

		if ( ! self::file_editor_disabled() ) {
			$items[] = __( 'The theme and plugin editor is turned on.', 'safety-pass-by-nuem8' );
		}

		if ( 'prepend' === $htaccess ) {
			$items[] = __( '.htaccess tells PHP to load another file first.', 'safety-pass-by-nuem8' );
		} elseif ( 'unreadable' === $htaccess ) {
			$items[] = __( 'nuem8-safety-pass could not read .htaccess.', 'safety-pass-by-nuem8' );
		} elseif ( 'large' === $htaccess ) {
			$items[] = __( '.htaccess is too large for nuem8-safety-pass to read.', 'safety-pass-by-nuem8' );
		}

		if ( array() === $items ) {
			$detail = ( 'absent' === $htaccess )
				? __( 'No must-use plugins are installed, and the theme and plugin editor is off. This site has no .htaccess file in the WordPress folder.', 'safety-pass-by-nuem8' )
				: __( 'No must-use plugins are installed, the theme and plugin editor is off, and .htaccess does not load another PHP file first.', 'safety-pass-by-nuem8' );

			return self::finding(
				'files',
				'ok',
				0,
				__( 'File notes', 'safety-pass-by-nuem8' ),
				$detail,
				''
			);
		}

		return self::finding(
			'files',
			'look',
			95,
			__( 'File notes', 'safety-pass-by-nuem8' ),
			__( 'These are notes for you to confirm. They are not a malware verdict.', 'safety-pass-by-nuem8' ),
			__( 'Confirm each note. nuem8-safety-pass will not change these files.', 'safety-pass-by-nuem8' ),
			$items
		);
	}

	/**
	 * @return string[]
	 */
	private static function must_use_names() {
		if ( ! function_exists( 'get_mu_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_mu_plugins();

		if ( ! is_array( $plugins ) ) {
			return array();
		}

		$names = array();

		foreach ( $plugins as $file => $data ) {
			$name = '';

			if ( is_array( $data ) && ! empty( $data['Name'] ) && is_string( $data['Name'] ) ) {
				$name = $data['Name'];
			} elseif ( is_string( $file ) ) {
				$name = wp_basename( $file );
			}

			$name = self::short_label( $name );

			if ( '' !== $name ) {
				$names[] = $name;
			}

			if ( count( $names ) >= 20 ) {
				break;
			}
		}

		return $names;
	}

	/**
	 * @return bool
	 */
	private static function file_editor_disabled() {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return true;
		}

		return defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
	}

	/**
	 * @return string absent, clean, prepend, unreadable, or large.
	 */
	private static function htaccess_state() {
		$root = wp_normalize_path( untrailingslashit( ABSPATH ) );
		$path = $root . '/.htaccess';

		if ( ! is_file( $path ) ) {
			return 'absent';
		}

		if ( ! is_readable( $path ) ) {
			return 'unreadable';
		}

		$size = filesize( $path );

		if ( false === $size || $size > 262144 ) {
			return 'large';
		}

		$contents = file_get_contents( $path );

		if ( ! is_string( $contents ) ) {
			return 'unreadable';
		}

		if ( false !== stripos( $contents, 'auto_prepend_file' ) ) {
			return 'prepend';
		}

		return 'clean';
	}

	/**
	 * @param string $value Plugin name or other short label.
	 * @return string
	 */
	private static function short_label( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$clean = preg_replace( '/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}]/u', '', $value );

		if ( ! is_string( $clean ) ) {
			$clean = $value;
		}

		$clean = preg_replace( '/\s+/', ' ', trim( $clean ) );

		if ( ! is_string( $clean ) ) {
			return '';
		}

		if ( strlen( $clean ) > 80 ) {
			$clean = substr( $clean, 0, 80 );
		}

		return $clean;
	}

	/**
	 * @param string   $id Finding id.
	 * @param string   $status ok, look, or stop.
	 * @param int      $rank Lower numbers are more urgent.
	 * @param string   $title Short label.
	 * @param string   $detail What nuem8-safety-pass found.
	 * @param string   $action The one thing to do. Empty when status is ok.
	 * @param array    $items Extra rows for the admin screen.
	 * @return array<string, mixed>
	 */
	private static function finding( $id, $status, $rank, $title, $detail, $action, array $items = array() ) {
		return array(
			'id'     => $id,
			'status' => $status,
			'rank'   => $rank,
			'title'  => $title,
			'detail' => $detail,
			'action' => $action,
			'items'  => $items,
		);
	}
}
