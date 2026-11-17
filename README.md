<p align="center">
  <img src="assets/images/icon-256x256.png" alt="Safety Pass by NueM8" width="160">
</p>

# Safety Pass

A health dashboard for the WordPress site. [NueM8](https://nuem8.com) built Safety Pass to show PHP, core files, backups, and the one step to take first.

## Description

Safety Pass reads the site and puts the result on one page. It does not update WordPress, delete plugins, or run a backup. The gauge reads Stop, Look, or OK. Stop means do this before updating anything. Look means read it before the next update. OK means nothing to do on that line.

The page shows the PHP version and whether that version still receives security fixes, the WordPress version and whether an update is waiting, and a comparison of WordPress core files with the official copy from WordPress.org. A difference is something to look at. It is not a malware verdict. Plugins, themes, and uploads are not part of the core comparison. The core result is saved for 12 hours.

Plugin, theme, must-use, and drop-in PHP files are compared with a record kept on the site. Installing, updating, or deleting a plugin or theme through WordPress refreshes that record. A PHP file that changes any other way is marked Look. Must-use plugins and drop-ins are listed when they are present. The first record includes the files already on the server. Uploads are not part of that check.

## What's new in 1.2.0

The PHP file check includes must-use plugins and the drop-in files WordPress loads from wp-content, such as db.php, object-cache.php, and advanced-cache.php. They use the same record as plugin and theme PHP. A file that changes outside a WordPress update is marked Look. The files that are present are listed even when they match.

An optional Webhook URL sits under Email. Safety Pass posts a JSON message to that address when a check needs a look, and when a new administrator is waiting for approval. The site sends it. It is not sent to NueM8. Nothing is posted until an address is saved.

`wp safetypass status` prints the one thing to do first. `wp safetypass check` prints every check that is turned on, including file names that differ. `wp safetypass approve 12` approves that administrator. The commands are not loaded in wp-admin. The exit status is 0 when every check is OK, 1 when a check says Look, and 2 when a check says Stop.

The Administrators tab keeps the last 50 administrator sign-ins on the site. Each row has the time, the login, the IP address, and the user agent. Older rows are dropped. Download CSV saves that list. The list is not loaded on public pages, and it is removed when the plugin is deleted.

## What's new in 1.1.1

Safety Pass records the PHP files in plugins and themes. Look means one of those files changed outside an update WordPress installs. The record stays on the site. This is not a malware scan, and Safety Pass does not replace the file.

Each tab has a checkbox for every check on it. Uncheck one to leave it out of the page, the next step, and the email. A new administrator is still disabled until a signed-in administrator approves them.

## What's new in 1.1.0

### New administrator approval

Any account created or promoted to administrator after activation is disabled until an existing signed-in administrator clicks Approve.

- The new account cannot sign in, so it cannot approve itself.
- A daily check catches an administrator added outside the dashboard, including one added in the database.
- The account you are signed in with is not disabled. The last remaining administrator is not disabled.

### Email

Pick one administrator to receive mail. Mail stays off until you choose.

- The message goes out when a check needs a look, and when a new administrator is waiting for approval.
- A test button sends one message from this site. The message is not sent to NueM8.
- The daily check sends the message even if nobody has opened the page.

It lists plugins that are installed and turned off, every administrator as active or disabled, and the last sign-in Safety Pass has recorded.

File notes cover must-use plugins that stay on, whether the theme and plugin editor is on, and whether .htaccess loads another PHP file first. Backup status reads the last finished backup from UpdraftPlus, BackWPup, and WPvivid. If Duplicator, All-in-One WP Migration, or Solid Backups is turned on and the last run cannot be read, the page asks you to confirm the copy in that plugin.

If you think someone broke in, the page lists what to change: administrator passwords, the hosting password, the database password, the secret keys in wp-config.php, application passwords, and API keys stored by plugins. The safest replacement is a new WordPress install. Safety Pass does not change those passwords and does not delete the old site.

The core file check downloads the public WordPress.org checksum list for this site's version and language. [WordPress.org privacy](https://wordpress.org/about/privacy/) and [terms](https://wordpress.org/about/terms/). No account data, site content, or visitor information is sent to NueM8. Mail to the administrator you choose is sent by the site. A webhook URL you save receives the same kind of notice, and that post stays off until you save the address.

Safety Pass is published by [NueM8](https://nuem8.com/wordpress/safety-pass).

## Installation

1. Copy the `nuem8-safety-pass` folder into `wp-content/plugins/`.
2. Activate Safety Pass by NueM8.
3. Open Safety Pass in the admin menu.

WordPress 6.2 or newer. PHP 7.4 or newer.

Only an administrator can open the page. Activating the plugin saves the current administrator accounts. An account created or promoted to administrator after that is disabled until a signed-in administrator clicks Approve. The account you are signed in with is not disabled.

## License

Copyright [NueM8](https://nuem8.com).

Safety Pass is free software licensed under the [GNU General Public License, version 2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html). You may use, study, share, and change it under the terms of that license. The gauge script included with Safety Pass is under that same license.
