<?php
/**
 * Plugin Name: Safety Pass by NueM8
 * Plugin URI: https://nuem8.com/wordpress/safety-pass
 * Description: A central health dashboard for WordPress site owners. Instant diagnostic overview of core files, PHP, backups, and your top priority step.
 * Version: 1.0.0
 * Author: NueM8
 * Author URI: https://nuem8.com
 * Copyright: 2026 NueM8 LLC
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nuem8-safety-pass
 * Requires at least: 6.2
 * Requires PHP: 7.4
 *
 * @package NueM8_Safety_Pass
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NUEM8_SAFETY_PASS_VERSION', '1.0.0' );
define( 'NUEM8_SAFETY_PASS_FILE', __FILE__ );
define( 'NUEM8_SAFETY_PASS_DIR', plugin_dir_path( __FILE__ ) );

require_once NUEM8_SAFETY_PASS_DIR . 'includes/class-nuem8-safety-pass-checks.php';
require_once NUEM8_SAFETY_PASS_DIR . 'includes/class-nuem8-safety-pass-admin.php';

register_activation_hook(
	__FILE__,
	array( 'NueM8_Safety_Pass_Checks', 'seed_admin_baseline' )
);

register_activation_hook(
	__FILE__,
	array( 'NueM8_Safety_Pass_Checks', 'grant_view_cap' )
);

add_action(
	'admin_init',
	array( 'NueM8_Safety_Pass_Checks', 'grant_view_cap' )
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

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	array( 'NueM8_Safety_Pass_Admin', 'plugin_links' )
);
