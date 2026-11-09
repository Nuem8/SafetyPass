<?php
/**
 * Disable a new administrator until a signed-in administrator approves them.
 *
 * The account you are signed in with is not disabled. The new account cannot approve itself.
 *
 * @package NueM8_Safety_Pass
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds a new administrator until approval.
 */
class NueM8_Safety_Pass_Hold {

	/**
	 * Disable a new administrator when the administrator role is set or added.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role being set or added.
	 */
	public static function on_role_change( $user_id, $role = '' ) {
		if ( 'administrator' !== $role || ! NueM8_Safety_Pass_Checks::holds_new_admins() ) {
			return;
		}

		self::hold_new_admin( (int) $user_id, true );
	}

	/**
	 * Catch administrator accounts that were added outside the role hooks.
	 */
	public static function hold_unapproved_admins() {
		static $running = false;

		if ( $running || ! NueM8_Safety_Pass_Checks::holds_new_admins() ) {
			return;
		}

		$running = true;

		foreach ( NueM8_Safety_Pass_Checks::administrator_users() as $user ) {
			self::hold_new_admin( $user->ID, false );
		}

		$running = false;
	}

	/**
	 * Disable one new administrator until a signed-in administrator approves them.
	 *
	 * @param int  $user_id     User ID.
	 * @param bool $known_admin Caller already granted the administrator role.
	 */
	public static function hold_new_admin( $user_id, $known_admin = false ) {
		$user_id = (int) $user_id;

		if ( $user_id < 1 || $user_id === (int) get_current_user_id() || ! NueM8_Safety_Pass_Checks::baseline_can_hold() ) {
			return;
		}

		if ( is_multisite() && is_super_admin( $user_id ) ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return;
		}

		$is_admin = $known_admin || in_array( 'administrator', (array) $user->roles, true );

		if ( ! $is_admin || NueM8_Safety_Pass_Checks::is_expected( $user_id ) || NueM8_Safety_Pass_Checks::is_disabled( $user_id ) ) {
			return;
		}

		if ( ! NueM8_Safety_Pass_Checks::another_manager_remains( $user_id ) ) {
			return;
		}

		update_user_meta( $user_id, NueM8_Safety_Pass_Checks::DISABLED_META, '1' );

		if ( ! NueM8_Safety_Pass_Checks::another_manager_remains( 0 ) ) {
			delete_user_meta( $user_id, NueM8_Safety_Pass_Checks::DISABLED_META );

			return;
		}

		NueM8_Safety_Pass_Checks::end_sessions( $user_id );

		/**
		 * A new administrator was disabled until a signed-in administrator approves them.
		 *
		 * @param int $user_id Held user ID.
		 */
		do_action( 'nuem8_safety_pass_admin_held', $user_id );
	}
}
