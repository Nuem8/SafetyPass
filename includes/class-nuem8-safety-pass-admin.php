<?php
/**
 * nuem8-safety-pass admin screen.
 *
 * @package NueM8_Safety_Pass
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the one admin page.
 */
class NueM8_Safety_Pass_Admin {

	/**
	 * Register the menu item.
	 */
	public static function register_menu() {
		$hook = add_menu_page(
			__( 'NueM8 Safety Pass', 'safety-pass-by-nuem8' ),
			__( 'NueM8 Safety Pass', 'safety-pass-by-nuem8' ),
			NueM8_Safety_Pass_Checks::VIEW_CAP,
			'nuem8-safety-pass',
			array( __CLASS__, 'render' ),
			'dashicons-shield',
			85
		);

		add_action( 'admin_print_styles-' . $hook, array( __CLASS__, 'styles' ) );
	}

	/**
	 * Plugins screen shortcut.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function plugin_links( $links ) {
		if ( ! NueM8_Safety_Pass_Checks::current_user_can_view() ) {
			return $links;
		}

		$url = admin_url( 'admin.php?page=nuem8-safety-pass' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Open nuem8-safety-pass', 'safety-pass-by-nuem8' ) . '</a>';

		return $links;
	}

	/**
	 * Load the admin stylesheet on this screen only.
	 */
	public static function styles() {
		$css = NUEM8_SAFETY_PASS_DIR . 'assets/admin.css';
		$ver = file_exists( $css ) ? (string) filemtime( $css ) : NUEM8_SAFETY_PASS_VERSION;

		wp_enqueue_style(
			'nuem8-safety-pass',
			plugins_url( 'assets/admin.css', NUEM8_SAFETY_PASS_FILE ),
			array(),
			$ver
		);

		$script = NUEM8_SAFETY_PASS_DIR . 'assets/steamGauge.js';
		$script_ver = file_exists( $script ) ? (string) filemtime( $script ) : NUEM8_SAFETY_PASS_VERSION;

		wp_enqueue_script(
			'nuem8-steam-gauge',
			plugins_url( 'assets/steamGauge.js', NUEM8_SAFETY_PASS_FILE ),
			array(),
			$script_ver,
			true
		);
	}

	/**
	 * Mark one administrator as expected.
	 */
	public static function trust_admin() {
		if ( ! NueM8_Safety_Pass_Checks::current_user_can_view() ) {
			self::deny( __( 'Only an administrator can change nuem8-safety-pass.', 'safety-pass-by-nuem8' ) );
		}

		$saved = NueM8_Safety_Pass_Checks::trust_admin( self::posted_user_id( 'nuem8_safety_pass_trust_admin' ) );

		wp_safe_redirect(
			self::with_screen_nonce(
				add_query_arg(
					$saved ? 'trusted' : 'trust_failed',
					'1',
					admin_url( 'admin.php?page=nuem8-safety-pass&tab=administrators' )
				)
			)
		);
		exit;
	}

	/**
	 * Approve one new administrator: allow sign-in and mark the account expected.
	 */
	public static function approve_admin() {
		if ( ! NueM8_Safety_Pass_Checks::current_user_can_view() ) {
			self::deny( __( 'Only an administrator can change nuem8-safety-pass.', 'safety-pass-by-nuem8' ) );
		}

		wp_safe_redirect(
			self::with_screen_nonce(
				add_query_arg(
					array(
						'tab'          => 'administrators',
						'admin_access' => NueM8_Safety_Pass_Checks::approve_admin( self::posted_user_id( 'nuem8_safety_pass_approve_admin' ) ),
					),
					admin_url( 'admin.php?page=nuem8-safety-pass' )
				)
			)
		);
		exit;
	}

	/**
	 * Enable or disable an administrator.
	 */
	public static function set_admin_access() {
		if ( ! NueM8_Safety_Pass_Checks::current_user_can_view() ) {
			self::deny( __( 'Only an administrator can change nuem8-safety-pass.', 'safety-pass-by-nuem8' ) );
		}

		$mode   = self::posted_mode( 'nuem8_safety_pass_set_admin_access' );
		$result = ( 'disable' === $mode || 'enable' === $mode )
			? NueM8_Safety_Pass_Checks::set_admin_access( self::posted_user_id( 'nuem8_safety_pass_set_admin_access' ), 'disable' === $mode )
			: 'invalid';

		wp_safe_redirect(
			self::with_screen_nonce(
				add_query_arg(
					array(
						'tab'          => 'administrators',
						'admin_access' => $result,
					),
					admin_url( 'admin.php?page=nuem8-safety-pass' )
				)
			)
		);
		exit;
	}

	/**
	 * Print the page.
	 */
	public static function render() {
		if ( ! NueM8_Safety_Pass_Checks::current_user_can_view() ) {
			self::deny( __( 'Only an administrator can open nuem8-safety-pass.', 'safety-pass-by-nuem8' ) );
		}

		$findings = NueM8_Safety_Pass_Checks::run();
		$next     = NueM8_Safety_Pass_Checks::next_step( $findings );
		$tabs     = self::tab_definitions();
		$active   = self::current_tab( $next );

		echo '<div class="wrap safety-pass">';
		echo '<h1>' . esc_html__( 'NueM8 Safety Pass', 'safety-pass-by-nuem8' ) . '</h1>';
		$intro = NueM8_Safety_Pass_Checks::holds_new_admins()
			? __( 'A note on a tab title means that part needs a look. nuem8-safety-pass does not update, delete, or back up the site. nuem8-safety-pass Pro disables a new administrator until a signed-in administrator approves them. The account you are signed in with cannot be disabled.', 'safety-pass-by-nuem8' )
			: __( 'A note on a tab title means that part needs a look. nuem8-safety-pass does not update, delete, or back up the site. A new administrator is listed here until you approve them. nuem8-safety-pass Pro keeps that account signed out until then. The account you are signed in with cannot be disabled.', 'safety-pass-by-nuem8' );

		echo '<p class="safety-pass__intro">' . esc_html( $intro ) . '</p>';

		if ( '1' === self::query_flag( 'trusted' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'That account is now expected.', 'safety-pass-by-nuem8' ) . '</p></div>';
		}

		if ( '1' === self::query_flag( 'trust_failed' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'That account is not an administrator, so it was left off the expected list.', 'safety-pass-by-nuem8' ) . '</p></div>';
		}

		self::render_access_notice();

		self::render_next( $next );
		self::render_tabs( $findings, $active );

		echo '<h2 class="screen-reader-text">' . esc_html( $tabs[ $active ]['label'] ) . '</h2>';
		echo '<ol class="safety-pass__list">';

		foreach ( $tabs[ $active ]['ids'] as $id ) {
			if ( isset( $findings[ $id ] ) ) {
				self::render_finding( $findings[ $id ] );
			}
		}

		echo '</ol>';
		self::render_break_in();
		echo '<p class="safety-pass__foot"><a href="' . esc_url( 'https://nuem8.com/wordpress/nuem8-safety-pass' ) . '">' . esc_html__( 'NueM8 Safety Pass', 'safety-pass-by-nuem8' ) . '</a><br>' . esc_html__( '© 2026 NueM8 LLC', 'safety-pass-by-nuem8' ) . '</p>';

		/**
		 * Extra blocks on the nuem8-safety-pass screen. nuem8-safety-pass Pro prints its email settings here.
		 */
		do_action( 'nuem8_safety_pass_admin_page' );

		echo '</div>';
	}

	/**
	 * Fixed advice. nuem8-safety-pass does not change passwords or delete the site.
	 */
	private static function render_break_in() {
		echo '<section class="safety-pass__advice">';
		echo '<h2>' . esc_html__( 'If you think someone broke in', 'safety-pass-by-nuem8' ) . '</h2>';
		echo '<p>' . esc_html__( 'Change these before you keep using the site:', 'safety-pass-by-nuem8' ) . '</p>';
		echo '<ul>';
		echo '<li>' . esc_html__( 'Every administrator password', 'safety-pass-by-nuem8' ) . '</li>';
		echo '<li>' . esc_html__( 'The hosting account password', 'safety-pass-by-nuem8' ) . '</li>';
		echo '<li>' . esc_html__( 'The database password', 'safety-pass-by-nuem8' ) . '</li>';
		echo '<li>' . esc_html__( 'The secret keys in wp-config.php', 'safety-pass-by-nuem8' ) . '</li>';
		echo '<li>' . esc_html__( 'Application passwords under Users → Profile', 'safety-pass-by-nuem8' ) . '</li>';
		echo '<li>' . esc_html__( 'API keys stored by plugins', 'safety-pass-by-nuem8' ) . '</li>';
		echo '</ul>';
		echo '<p>' . esc_html__( 'Use new passwords that this site has not used before. The safest replacement is a new WordPress install. Move the pages, posts, and uploads you still want. Leave the old files in place until the new site is working, then delete the old install from the host. nuem8-safety-pass will not delete it.', 'safety-pass-by-nuem8' ) . '</p>';
		echo '</section>';
	}

	/**
	 * @param array<string, mixed>|null $next Most urgent finding.
	 */
	private static function render_next( $next ) {
		if ( ! is_array( $next ) ) {
			$status = 'ok';
			$action = __( 'Nothing needs you this month. Open nuem8-safety-pass again after you add a plugin or a user.', 'safety-pass-by-nuem8' );
		} else {
			$status = self::status_slug( isset( $next['status'] ) ? $next['status'] : 'look' );
			$action = isset( $next['action'] ) ? (string) $next['action'] : '';

			if ( '' === $action ) {
				$action = __( 'Open the marked tab and read the detail.', 'safety-pass-by-nuem8' );
			}
		}

		echo '<section class="safety-pass__next safety-pass__next--' . esc_attr( $status ) . '">';
		$label = ( is_array( $next ) && ! empty( $next['id'] ) ) ? self::gauge_label( (string) $next['id'] ) : __( 'nuem8-safety-pass', 'safety-pass-by-nuem8' );
		echo wp_kses(
			self::status_gauge( $status, 'safety-pass__gauge safety-pass__gauge--hero', $label ),
			self::gauge_allowed_html()
		);
		echo '<div class="safety-pass__next-copy">';
		echo '<p class="safety-pass__kicker">' . esc_html__( 'Do this first', 'safety-pass-by-nuem8' ) . '</p>';
		echo '<p class="safety-pass__action">' . esc_html( $action ) . '</p>';
		echo '</div></section>';
	}

	/**
	 * @param array<string, mixed> $finding One check.
	 */
	private static function render_finding( array $finding ) {
		if ( empty( $finding['id'] ) ) {
			return;
		}

		$status = self::status_slug( isset( $finding['status'] ) ? $finding['status'] : 'look' );
		$items  = isset( $finding['items'] ) && is_array( $finding['items'] ) ? $finding['items'] : array();

		echo '<li class="safety-pass__item safety-pass__item--' . esc_attr( $status ) . '">';
		echo wp_kses(
			self::status_gauge( $status, 'safety-pass__gauge', self::gauge_label( (string) $finding['id'] ) ),
			self::gauge_allowed_html()
		);
		echo '<div class="safety-pass__body">';
		echo '<p class="safety-pass__row"><strong>' . esc_html( isset( $finding['title'] ) ? (string) $finding['title'] : '' ) . '</strong></p>';
		echo '<p>' . esc_html( isset( $finding['detail'] ) ? (string) $finding['detail'] : '' ) . '</p>';

		if ( 'ok' !== $status && ! empty( $finding['action'] ) ) {
			echo '<p class="safety-pass__do">' . esc_html( (string) $finding['action'] ) . '</p>';
		}

		if ( in_array( (string) $finding['id'], array( 'plugins', 'core', 'files' ), true ) && array() !== $items ) {
			$names = array();

			foreach ( $items as $name ) {
				if ( is_scalar( $name ) && '' !== (string) $name ) {
					$names[] = self::plain( $name );
				}
			}

			$extra = count( $names ) - 100;
			$names = array_slice( $names, 0, 100 );

			echo '<ul class="safety-pass__names">';

			foreach ( $names as $name ) {
				echo '<li>' . esc_html( $name ) . '</li>';
			}

			if ( $extra > 0 ) {
				echo '<li>' . esc_html(
					sprintf(
						/* translators: %d: number of additional plugins not listed. */
						_n( '%d more plugin', '%d more plugins', $extra, 'safety-pass-by-nuem8' ),
						$extra
					)
				) . '</li>';
			}

			echo '</ul>';
		}

		if ( 'admins' === $finding['id'] ) {
			self::render_admins( $items );
		}

		echo '</div></li>';
	}

	/**
	 * @param array<int, array<string, mixed>> $accounts Administrator rows.
	 */
	private static function render_admins( array $accounts ) {
		$columns = array(
			__( 'Name', 'safety-pass-by-nuem8' ),
			__( 'Login', 'safety-pass-by-nuem8' ),
			__( 'Status', 'safety-pass-by-nuem8' ),
			__( 'On file', 'safety-pass-by-nuem8' ),
			__( 'Email', 'safety-pass-by-nuem8' ),
			__( 'Registered', 'safety-pass-by-nuem8' ),
			__( 'Last sign-in', 'safety-pass-by-nuem8' ),
			__( 'Actions', 'safety-pass-by-nuem8' ),
		);

		echo '<div class="safety-pass__table-wrap">';
		echo '<table class="safety-pass__table">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Administrators', 'safety-pass-by-nuem8' ) . '</caption>';
		echo '<thead><tr>';

		foreach ( $columns as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		$shown = 0;

		foreach ( $accounts as $account ) {
			if ( ! is_array( $account ) || empty( $account['id'] ) ) {
				continue;
			}

			++$shown;
			$on_file = ! empty( $account['is_new'] ) ? __( 'New', 'safety-pass-by-nuem8' ) : __( 'On file', 'safety-pass-by-nuem8' );

			echo '<tr>';
			echo '<th scope="row">' . esc_html( self::plain( isset( $account['name'] ) ? $account['name'] : '' ) ) . '</th>';
			echo '<td>' . esc_html( self::plain( isset( $account['login'] ) ? $account['login'] : '' ) ) . '</td>';
			echo '<td><span class="safety-pass__state safety-pass__state--' . esc_attr( self::state_slug( $account ) ) . '">' . esc_html( self::state_label( $account ) ) . '</span></td>';
			echo '<td>' . esc_html( $on_file ) . '</td>';
			echo '<td>' . esc_html( self::plain( isset( $account['email'] ) ? $account['email'] : '' ) ) . '</td>';
			echo '<td>' . esc_html( self::plain( isset( $account['registered'] ) ? $account['registered'] : '' ) ) . '</td>';
			echo '<td>' . esc_html( self::last_login_label( $account ) ) . '</td>';
			echo '<td>';

			$is_self = (int) $account['id'] === (int) get_current_user_id();

			if ( ! empty( $account['is_new'] ) && ! $is_self ) {
				self::render_approve_form( $account );
			} elseif ( ! empty( $account['is_new'] ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="safety-pass__access">';
				wp_nonce_field( 'nuem8_safety_pass_trust_admin' );
				echo '<input type="hidden" name="action" value="nuem8_safety_pass_trust_admin" />';
				echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $account['id'] ) . '" />';
				submit_button(
					__( 'This account is expected', 'safety-pass-by-nuem8' ),
					'secondary',
					'submit',
					false
				);
				echo '</form>';
			}

			if ( empty( $account['is_new'] ) || $is_self || empty( $account['disabled'] ) ) {
				self::render_access_form( $account );
			}

			echo '</td></tr>';
		}

		if ( 0 === $shown ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No administrators were found.', 'safety-pass-by-nuem8' ) . '</td></tr>';
		}

		echo '</tbody></table></div>';
		$admin_note = NueM8_Safety_Pass_Checks::holds_new_admins()
			? __( 'Sign-in times start while nuem8-safety-pass is active. An administrator who is already signed in keeps that session time. nuem8-safety-pass Pro keeps a new administrator signed out until Approve is clicked.', 'safety-pass-by-nuem8' )
			: __( 'Sign-in times start while nuem8-safety-pass is active. An administrator who is already signed in keeps that session time. Approve adds a new administrator to the expected list. nuem8-safety-pass Pro keeps that account signed out until you approve it.', 'safety-pass-by-nuem8' );

		echo '<p class="safety-pass__meta">' . esc_html( $admin_note ) . '</p>';
	}

	/**
	 * Result of a disable or enable click.
	 */
	private static function render_access_notice() {
		$result   = self::query_flag( 'admin_access' );
		$messages = array(
			'disabled'     => array( 'success', __( 'That account is disabled. It cannot sign in.', 'safety-pass-by-nuem8' ) ),
			'enabled'      => array( 'success', __( 'That account is enabled again.', 'safety-pass-by-nuem8' ) ),
			'approved'     => array( 'success', __( 'That account is approved. It can sign in.', 'safety-pass-by-nuem8' ) ),
			'self'         => array( 'error', __( 'The account you are signed in with cannot be disabled.', 'safety-pass-by-nuem8' ) ),
			'approve_self' => array( 'error', __( 'The account you are signed in with cannot approve itself.', 'safety-pass-by-nuem8' ) ),
			'last'         => array( 'error', __( 'The last administrator who can sign in cannot be disabled.', 'safety-pass-by-nuem8' ) ),
			'protected'    => array( 'error', __( 'A network administrator cannot be disabled here.', 'safety-pass-by-nuem8' ) ),
			'held'         => array( 'error', __( 'That account is not on the expected list yet. Approve it first.', 'safety-pass-by-nuem8' ) ),
			'unchanged'    => array( 'error', __( 'That account is already expected.', 'safety-pass-by-nuem8' ) ),
			'invalid'      => array( 'error', __( 'That account is not an administrator, so it was left unchanged.', 'safety-pass-by-nuem8' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		echo '<div class="notice notice-' . esc_attr( $messages[ $result ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $result ][1] ) . '</p></div>';
	}

	/**
	 * @param array<string, mixed> $account Administrator row.
	 * @return string
	 */
	private static function state_slug( array $account ) {
		return ! empty( $account['disabled'] ) ? 'disabled' : 'active';
	}

	/**
	 * @param array<string, mixed> $account Administrator row.
	 * @return string
	 */
	private static function state_label( array $account ) {
		return ! empty( $account['disabled'] ) ? __( 'Disabled', 'safety-pass-by-nuem8' ) : __( 'Active', 'safety-pass-by-nuem8' );
	}

	/**
	 * @param array<string, mixed> $account Administrator row.
	 */
	private static function render_approve_form( array $account ) {
		$confirm = __( 'Approve this administrator? They will be able to sign in.', 'safety-pass-by-nuem8' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="safety-pass__access">';
		wp_nonce_field( 'nuem8_safety_pass_approve_admin' );
		echo '<input type="hidden" name="action" value="nuem8_safety_pass_approve_admin" />';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $account['id'] ) . '" />';
		echo '<button type="submit" class="button button-primary" onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');">' . esc_html__( 'Approve', 'safety-pass-by-nuem8' ) . '</button>';
		echo '</form>';
	}

	/**
	 * @param array<string, mixed> $account Administrator row.
	 */
	private static function render_access_form( array $account ) {
		$disabled = ! empty( $account['disabled'] );
		$is_self  = (int) $account['id'] === (int) get_current_user_id();

		if ( ! empty( $account['is_super'] ) && ! $disabled ) {
			echo '<span class="safety-pass__meta">' . esc_html__( 'Network administrator. This account cannot be disabled here.', 'safety-pass-by-nuem8' ) . '</span>';
			return;
		}

		if ( ! $disabled && $is_self ) {
			echo '<span class="safety-pass__meta">' . esc_html__( 'Signed in now. This account cannot be disabled.', 'safety-pass-by-nuem8' ) . '</span>';
			return;
		}

		$mode = $disabled ? 'enable' : 'disable';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="safety-pass__access">';
		wp_nonce_field( 'nuem8_safety_pass_set_admin_access' );
		echo '<input type="hidden" name="action" value="nuem8_safety_pass_set_admin_access" />';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $account['id'] ) . '" />';
		echo '<input type="hidden" name="mode" value="' . esc_attr( $mode ) . '" />';

		if ( 'disable' === $mode ) {
			$confirm = __( 'Disable this administrator? They will be signed out and cannot sign in until you enable them.', 'safety-pass-by-nuem8' );
			echo '<button type="submit" class="button button-secondary" onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');">' . esc_html__( 'Disable', 'safety-pass-by-nuem8' ) . '</button>';
		} else {
			submit_button(
				__( 'Enable', 'safety-pass-by-nuem8' ),
				'secondary',
				'submit',
				false
			);
		}

		echo '</form>';
	}

	/**
	 * @param array<string, mixed> $account Administrator row.
	 * @return string
	 */
	private static function last_login_label( array $account ) {
		if ( ! isset( $account['last_login'] ) || '' === (string) $account['last_login'] ) {
			return __( 'No sign-in recorded yet', 'safety-pass-by-nuem8' );
		}

		return (string) $account['last_login'];
	}

	/**
	 * @return array<string, array{label: string, ids: string[]}>
	 */
	private static function tab_definitions() {
		return array(
			'wordpress'      => array(
				'label' => __( 'WordPress', 'safety-pass-by-nuem8' ),
				'ids'   => array( 'backup', 'php', 'wordpress', 'core', 'files' ),
			),
			'plugins'        => array(
				'label' => __( 'Plugins', 'safety-pass-by-nuem8' ),
				'ids'   => array( 'plugins' ),
			),
			'administrators' => array(
				'label' => __( 'Administrators', 'safety-pass-by-nuem8' ),
				'ids'   => array( 'admins' ),
			),
		);
	}

	/**
	 * Open the requested tab, or the tab that holds the next step.
	 *
	 * @param array<string, mixed>|null $next Most urgent finding.
	 * @return string
	 */
	private static function current_tab( $next ) {
		$requested = self::query_flag( 'tab' );

		$tabs = self::tab_definitions();

		if ( isset( $tabs[ $requested ] ) ) {
			return $requested;
		}

		if ( is_array( $next ) && ! empty( $next['id'] ) ) {
			return self::tab_for_finding( (string) $next['id'] );
		}

		return 'wordpress';
	}

	/**
	 * @param string $finding_id Finding id.
	 * @return string
	 */
	private static function tab_for_finding( $finding_id ) {
		foreach ( self::tab_definitions() as $slug => $tab ) {
			if ( in_array( $finding_id, $tab['ids'], true ) ) {
				return $slug;
			}
		}

		return 'wordpress';
	}

	/**
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private static function tab_url( $tab ) {
		return self::with_screen_nonce(
			add_query_arg(
				array(
					'page' => 'nuem8-safety-pass',
					'tab'  => $tab,
				),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Add the screen nonce used to read display flags.
	 *
	 * @param string $url Admin URL.
	 * @return string
	 */
	private static function with_screen_nonce( $url ) {
		return wp_nonce_url( $url, 'nuem8_safety_pass_screen' );
	}

	/**
	 * @param array<string, array<string, mixed>> $findings All checks.
	 * @param string                               $active  Active tab slug.
	 */
	private static function render_tabs( array $findings, $active ) {
		echo '<nav class="nav-tab-wrapper safety-pass__tabs" aria-label="' . esc_attr__( 'nuem8-safety-pass sections', 'safety-pass-by-nuem8' ) . '">';

		foreach ( self::tab_definitions() as $slug => $tab ) {
			$attention = self::tab_attention( $findings, $tab['ids'] );
			$classes   = array( 'nav-tab', 'safety-pass__tab' );

			if ( $slug === $active ) {
				$classes[] = 'nav-tab-active';
			}

			if ( 'ok' !== $attention['status'] ) {
				$classes[] = 'safety-pass__tab--' . self::status_slug( $attention['status'] );
			}

			echo '<a class="' . esc_attr( implode( ' ', $classes ) ) . '" href="' . esc_url( self::tab_url( $slug ) ) . '"';

			if ( $slug === $active ) {
				echo ' aria-current="page"';
			}

			echo '>';

			if ( 'ok' !== $attention['status'] ) {
				echo wp_kses(
					self::status_gauge( $attention['status'], 'safety-pass__gauge safety-pass__gauge--tab', self::gauge_label( $attention['id'] ) ),
					self::gauge_allowed_html()
				);
			}

			echo esc_html( $tab['label'] );

			if ( '' !== $attention['text'] && self::status_label( $attention['status'] ) !== $attention['text'] ) {
				echo ' <span class="safety-pass__tab-note">' . esc_html( $attention['text'] ) . '</span>';
			}

			echo '</a>';
		}

		echo '</nav>';
	}

	/**
	 * @param array<string, array<string, mixed>> $findings All checks.
	 * @param string[]                             $ids      Finding ids in this tab.
	 * @return array{status: string, text: string, id: string}
	 */
	private static function tab_attention( array $findings, array $ids ) {
		$open = array();

		foreach ( $ids as $id ) {
			if ( ! isset( $findings[ $id ] ) || 'ok' === $findings[ $id ]['status'] ) {
				continue;
			}

			$open[] = $findings[ $id ];
		}

		if ( array() === $open ) {
			return array(
				'status' => 'ok',
				'text'   => '',
				'id'     => '',
			);
		}

		usort(
			$open,
			static function ( $left, $right ) {
				return (int) $left['rank'] <=> (int) $right['rank'];
			}
		);

		$notes = array();
		$worst = 'look';

		foreach ( $open as $finding ) {
			if ( 'stop' === $finding['status'] ) {
				$worst = 'stop';
			}

			$note = self::attention_text( $finding );

			if ( '' !== $note ) {
				$notes[] = $note;
			}
		}

		return array(
			'status' => $worst,
			'text'   => implode( ' · ', $notes ),
			'id'     => isset( $open[0]['id'] ) ? (string) $open[0]['id'] : '',
		);
	}

	/**
	 * Short note for a tab title. Empty when the check is OK.
	 *
	 * @param array<string, mixed> $finding One check.
	 * @return string
	 */
	private static function attention_text( array $finding ) {
		$id = (string) $finding['id'];

		if ( 'plugins' === $id ) {
			$count = count( (array) $finding['items'] );

			if ( $count > 0 ) {
				return sprintf(
					/* translators: %d: number of inactive plugins. */
					_n( '%d unused', '%d unused', $count, 'safety-pass-by-nuem8' ),
					$count
				);
			}
		}

		if ( 'admins' === $id ) {
			$count = 0;

			foreach ( (array) $finding['items'] as $account ) {
				if ( is_array( $account ) && ! empty( $account['is_new'] ) ) {
					++$count;
				}
			}

			if ( $count > 0 ) {
				return sprintf(
					/* translators: %d: number of new administrators. */
					_n( '%d new', '%d new', $count, 'safety-pass-by-nuem8' ),
					$count
				);
			}
		}

		if ( 'php' === $id ) {
			return (string) $finding['title'];
		}

		if ( 'wordpress' === $id ) {
			return 65 === (int) $finding['rank']
				? __( 'Update check', 'safety-pass-by-nuem8' )
				: __( 'Core update', 'safety-pass-by-nuem8' );
		}

		if ( 'core' === $id ) {
			return 55 === (int) $finding['rank']
				? __( 'Core files', 'safety-pass-by-nuem8' )
				: __( 'Core check', 'safety-pass-by-nuem8' );
		}

		if ( 'files' === $id ) {
			return __( 'File notes', 'safety-pass-by-nuem8' );
		}

		if ( 'backup' === $id ) {
			$rank = (int) $finding['rank'];

			if ( 20 === $rank ) {
				return __( 'No backup', 'safety-pass-by-nuem8' );
			}

			if ( 40 === $rank ) {
				return __( 'Backup overdue', 'safety-pass-by-nuem8' );
			}

			if ( 70 === $rank ) {
				return __( 'Backup this week', 'safety-pass-by-nuem8' );
			}

			return __( 'Backup', 'safety-pass-by-nuem8' );
		}

		return self::status_label( (string) $finding['status'] );
	}

	/**
	 * Face name for the check. Two words stack on the gauge.
	 *
	 * @param string $finding_id Finding id.
	 * @return string
	 */
	private static function gauge_label( $finding_id ) {
		$labels = array(
			'php'       => __( 'PHP Version', 'safety-pass-by-nuem8' ),
			'wordpress' => __( 'WordPress Version', 'safety-pass-by-nuem8' ),
			'plugins'   => __( 'Unused Plugins', 'safety-pass-by-nuem8' ),
			'admins'    => __( 'Admin Accounts', 'safety-pass-by-nuem8' ),
			'backup'    => __( 'Site Backup', 'safety-pass-by-nuem8' ),
			'core'      => __( 'Core Files', 'safety-pass-by-nuem8' ),
			'files'     => __( 'File Notes', 'safety-pass-by-nuem8' ),
		);

		if ( isset( $labels[ $finding_id ] ) ) {
			return $labels[ $finding_id ];
		}

		return __( 'nuem8-safety-pass', 'safety-pass-by-nuem8' );
	}

	/**
	 * Tags allowed in the gauge mount.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function gauge_allowed_html() {
		return array(
			'div' => array(
				'class'        => true,
				'data-label'   => true,
				'data-value'   => true,
				'data-size'    => true,
				'data-readout' => true,
			),
		);
	}

	/**
	 * Live gauge for a check. Stop is red, Look is yellow, OK is green.
	 *
	 * @param string $status ok, look, or stop.
	 * @param string $class  Mount classes.
	 * @param string $label  Text drawn on the face.
	 * @return string
	 */
	private static function status_gauge( $status, $class = 'safety-pass__gauge', $label = '' ) {
		$status = self::status_slug( $status );
		$values = array(
			'stop' => '18',
			'look' => '50',
			'ok'   => '88',
		);

		if ( '' === $label ) {
			$label = self::status_label( $status );
		}

		return '<div class="steam-gauge ' . esc_attr( $class ) . '" data-label="' . esc_attr( $label ) . '" data-value="' . esc_attr( $values[ $status ] ) . '" data-size="280" data-readout="warning"></div>';
	}

	/**
	 * @param string $status ok, look, or stop.
	 * @return string
	 */
	private static function status_label( $status ) {
		if ( 'stop' === $status ) {
			return __( 'Stop', 'safety-pass-by-nuem8' );
		}

		if ( 'look' === $status ) {
			return __( 'Look', 'safety-pass-by-nuem8' );
		}

		return __( 'OK', 'safety-pass-by-nuem8' );
	}

	/**
	 * @param string $status Requested status.
	 * @return string
	 */
	private static function status_slug( $status ) {
		$status = (string) $status;

		if ( in_array( $status, array( 'ok', 'look', 'stop' ), true ) ) {
			return $status;
		}

		return 'look';
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
	 * Read one display flag from the query string. These flags do not change the site.
	 *
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
	 * Read the posted enable or disable mode after the form nonce is checked.
	 *
	 * @param string $nonce_action Nonce action for this form.
	 * @return string
	 */
	private static function posted_mode( $nonce_action ) {
		check_admin_referer( $nonce_action );

		if ( ! isset( $_POST['mode'] ) || is_array( $_POST['mode'] ) ) {
			return '';
		}

		return sanitize_key( sanitize_text_field( wp_unslash( $_POST['mode'] ) ) );
	}

	/**
	 * Remove characters that can disguise a name in the table.
	 *
	 * @param mixed $value Display value.
	 * @return string
	 */
	private static function plain( $value ) {
		$value = (string) $value;
		$clean = preg_replace( '/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}]/u', '', $value );

		return is_string( $clean ) ? $clean : $value;
	}
}
