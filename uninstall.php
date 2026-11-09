<?php
/**
 * Remove nuem8-safety-pass options when the plugin is deleted.
 *
 * @package NueM8_Safety_Pass
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove the per-site option and the administrator capability.
 */
function nuem8_safety_pass_uninstall_site() {
	delete_option( 'nuem8_safety_pass_admins' );
	delete_option( 'nuem8_safety_pass_mail_recipient' );
	delete_option( 'nuem8_safety_pass_mail_signature' );
	delete_option( 'nuem8_safety_pass_mail_held_notified' );
	delete_option( 'nuem8_safety_pass_mail_last_error' );
	delete_option( 'nuem8_safety_pass_mail_prompt' );
	delete_option( 'nuem8_safety_pass_pro_recipient' );
	delete_option( 'nuem8_safety_pass_pro_signature' );
	delete_option( 'nuem8_safety_pass_pro_held_notified' );
	delete_option( 'nuem8_safety_pass_pro_last_error' );
	delete_transient( 'nuem8_sp_core' );
	delete_transient( 'nuem8_sp_core_lock' );
	wp_clear_scheduled_hook( 'nuem8_safety_pass_daily' );
	wp_clear_scheduled_hook( 'nuem8_safety_pass_pro_daily' );

	$role = get_role( 'administrator' );

	if ( $role ) {
		$role->remove_cap( 'nuem8_safety_pass' );
	}
}

if ( is_multisite() ) {
	$nuem8_safety_pass_offset = 0;

	do {
		$nuem8_safety_pass_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $nuem8_safety_pass_offset,
			)
		);

		if ( ! is_array( $nuem8_safety_pass_site_ids ) ) {
			break;
		}

		foreach ( $nuem8_safety_pass_site_ids as $nuem8_safety_pass_site_id ) {
			switch_to_blog( (int) $nuem8_safety_pass_site_id );
			nuem8_safety_pass_uninstall_site();
			restore_current_blog();
		}

		$nuem8_safety_pass_offset += 100;
	} while ( 100 === count( $nuem8_safety_pass_site_ids ) && $nuem8_safety_pass_offset < 5000 );
} else {
	nuem8_safety_pass_uninstall_site();
}

delete_metadata( 'user', 0, 'nuem8_safety_pass_last_login', '', true );
delete_metadata( 'user', 0, 'nuem8_safety_pass_disabled', '', true );
