<?php
/**
 * Plugin Name: NueM8 Safety Pass
 * Plugin URI: https://nuem8.com/wordpress/nuem8-safety-pass
 * Description: A central health dashboard for WordPress site owners. Instant diagnostic overview of core files, PHP, backups, and your top priority step.
 * Version: 1.1.0
 * Author: NueM8
 * Author URI: https://nuem8.com
 * Copyright: 2026 NueM8 LLC
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: safety-pass-by-nuem8
 * Requires at least: 6.2
 * Requires PHP: 7.4
 *
 * @package NueM8_Safety_Pass
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NUEM8_SAFETY_PASS_VERSION', '1.1.0' );
define( 'NUEM8_SAFETY_PASS_FILE', __FILE__ );
define( 'NUEM8_SAFETY_PASS_DIR', plugin_dir_path( __FILE__ ) );

require_once NUEM8_SAFETY_PASS_DIR . 'includes/class-nuem8-safety-pass-checks.php';
require_once NUEM8_SAFETY_PASS_DIR . 'includes/class-nuem8-safety-pass-hold.php';
require_once NUEM8_SAFETY_PASS_DIR . 'includes/class-nuem8-safety-pass-mail.php';
require_once NUEM8_SAFETY_PASS_DIR . 'includes/class-nuem8-safety-pass-admin.php';

NueM8_Safety_Pass_Mail::migrate_legacy_options();

register_activation_hook(
	__FILE__,
	array( 'NueM8_Safety_Pass_Checks', 'seed_admin_baseline' )
);

register_activation_hook(
	__FILE__,
	array( 'NueM8_Safety_Pass_Checks', 'grant_view_cap' )
);

register_activation_hook(
	__FILE__,
	array( 'NueM8_Safety_Pass_Mail', 'ensure_cron' )
);

register_activation_hook(
	__FILE__,
	array( 'NueM8_Safety_Pass_Mail', 'arm_prompt' )
);

register_deactivation_hook(
	__FILE__,
	array( 'NueM8_Safety_Pass_Mail', 'deactivate' )
);

add_action(
	'admin_init',
	array( 'NueM8_Safety_Pass_Checks', 'grant_view_cap' )
);

add_action(
	'set_user_role',
	array( 'NueM8_Safety_Pass_Hold', 'on_role_change' ),
	10,
	2
);

add_action(
	'add_user_role',
	array( 'NueM8_Safety_Pass_Hold', 'on_role_change' ),
	10,
	2
);

add_action(
	'nuem8_safety_pass_before_administrators',
	array( 'NueM8_Safety_Pass_Hold', 'hold_unapproved_admins' )
);

add_action(
	'wp_login',
	array( 'NueM8_Safety_Pass_Checks', 'record_login' ),
	10,
	2
);

add_filter(
	'authenticate',
	array( 'NueM8_Safety_Pass_Checks', 'block_disabled_login' ),
	100,
	3
);

add_filter(
	'determine_current_user',
	array( 'NueM8_Safety_Pass_Checks', 'reject_disabled_user' ),
	99
);

add_action(
	'init',
	array( 'NueM8_Safety_Pass_Checks', 'logout_if_disabled' ),
	1
);

add_action(
	'admin_menu',
	array( 'NueM8_Safety_Pass_Admin', 'register_menu' )
);

add_action(
	'admin_post_nuem8_safety_pass_trust_admin',
	array( 'NueM8_Safety_Pass_Admin', 'trust_admin' )
);

add_action(
	'admin_post_nuem8_safety_pass_approve_admin',
	array( 'NueM8_Safety_Pass_Admin', 'approve_admin' )
);

add_action(
	'admin_post_nuem8_safety_pass_set_admin_access',
	array( 'NueM8_Safety_Pass_Admin', 'set_admin_access' )
);

add_action(
	'admin_post_nuem8_safety_pass_update_plugins',
	array( 'NueM8_Safety_Pass_Admin', 'update_plugins' )
);

add_action(
	'init',
	array( 'NueM8_Safety_Pass_Mail', 'ensure_cron' )
);

add_action(
	NueM8_Safety_Pass_Mail::CRON_HOOK,
	array( 'NueM8_Safety_Pass_Mail', 'daily' )
);

add_action(
	'wp_mail_failed',
	array( 'NueM8_Safety_Pass_Mail', 'capture_failure' )
);

add_action(
	'nuem8_safety_pass_admin_held',
	array( 'NueM8_Safety_Pass_Mail', 'send_held_notice' )
);

add_action(
	'nuem8_safety_pass_report',
	array( 'NueM8_Safety_Pass_Mail', 'on_report' )
);

add_action(
	'admin_post_nuem8_safety_pass_mail_recipient',
	array( 'NueM8_Safety_Pass_Mail', 'save_recipient' )
);

add_action(
	'admin_post_nuem8_safety_pass_mail_test',
	array( 'NueM8_Safety_Pass_Mail', 'send_test_request' )
);

add_action(
	'admin_post_nuem8_safety_pass_mail_prompt_dismiss',
	array( 'NueM8_Safety_Pass_Mail', 'dismiss_prompt' )
);

add_action(
	'activated_plugin',
	array( 'NueM8_Safety_Pass_Mail', 'redirect_after_activate' )
);

add_action(
	'admin_enqueue_scripts',
	array( 'NueM8_Safety_Pass_Mail', 'prompt_assets' )
);

add_action(
	'admin_footer',
	array( 'NueM8_Safety_Pass_Mail', 'render_prompt' )
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	array( 'NueM8_Safety_Pass_Admin', 'plugin_links' )
);
