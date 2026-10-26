<p align="center">
  <img src="assets/images/icon-256x256.png" alt="Safety Pass by NueM8" width="160">
</p>

# Safety Pass

A health dashboard for the WordPress site. [NueM8](https://nuem8.com) built Safety Pass to show PHP, core files, backups, and the one step to take first.

## Description

Safety Pass reads the site and puts the result on one page. It does not update WordPress, delete plugins, or run a backup. The gauge reads Stop, Look, or OK. Stop means do this before updating anything. Look means read it before the next update. OK means nothing to do on that line.

The page shows the PHP version and whether that version still receives security fixes, the WordPress version and whether an update is waiting, and a comparison of WordPress core files with the official copy from WordPress.org. A difference is something to look at. It is not a malware verdict. Plugins, themes, and uploads are not compared. The core result is saved for 12 hours.

It lists plugins that are installed and turned off, every administrator as active or disabled, and the last sign-in Safety Pass has recorded. An administrator added after Safety Pass was activated is listed as new. Disabling that account until approval is a feature of Safety Pass Pro. This plugin lists the account and does not block the sign-in. The account you are signed in with cannot be disabled.

File notes cover must-use plugins that stay on, whether the theme and plugin editor is on, and whether .htaccess loads another PHP file first. Backup status reads the last finished backup from UpdraftPlus, BackWPup, and WPvivid. If Duplicator, All-in-One WP Migration, or Solid Backups is turned on and the last run cannot be read, the page asks you to confirm the copy in that plugin.

If you think someone broke in, the page lists what to change: administrator passwords, the hosting password, the database password, the secret keys in wp-config.php, application passwords, and API keys stored by plugins. The safest replacement is a new WordPress install. Safety Pass does not change those passwords and does not delete the old site.

The only external request is the public WordPress.org checksum list for this site's version and language. [WordPress.org privacy](https://wordpress.org/about/privacy/) and [terms](https://wordpress.org/about/terms/). No account data, site content, or visitor information is sent to NueM8.

Safety Pass is published by [NueM8](https://nuem8.com/wordpress/safety-pass).

## Installation

1. Copy the `nuem8-safety-pass` folder into `wp-content/plugins/`.
2. Activate Safety Pass by NueM8.
3. Open Safety Pass in the admin menu.

WordPress 6.2 or newer. PHP 7.4 or newer.

Only an administrator can open the page. Activating the plugin saves the current administrator accounts. An account created or promoted to administrator after that is shown as new.

## License

Copyright [NueM8](https://nuem8.com).

Safety Pass is free software licensed under the [GNU General Public License, version 2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html). You may use, study, share, and change it under the terms of that license. The gauge script included with Safety Pass is under that same license.
