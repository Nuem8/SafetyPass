<?php
/**
 * Local record of plugin and theme PHP files for nuem8-safety-pass.
 *
 * @package NueM8_Safety_Pass
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records PHP files under plugins, themes, must-use plugins, and core drop-ins.
 *
 * The record stays on this site. WordPress installing, updating, or deleting a
 * plugin or theme refreshes it. Any other PHP change is something to look at.
 */
class NueM8_Safety_Pass_Php_Files {

	const BASELINE   = 'nuem8_sp_php_files';
	const REFRESH    = 'nuem8_sp_php_refresh';
	const SCOPE      = 'nuem8_sp_php_scope';
	const CACHE      = 'nuem8_sp_php_cache';
	const LOCK       = 'nuem8_sp_php_lock';
	const MAX_FILES  = 8000;
	const MAX_BYTES  = 8388608;
	const TIME_LIMIT = 8;
	const SCOPE_VERSION = '2';

	/**
	 * A WordPress plugin or theme install or update replaces files on purpose.
	 *
	 * @param mixed $upgrader   Upgrader instance. Unused.
	 * @param mixed $hook_extra Upgrader context.
	 */
	public static function on_upgrade( $upgrader, $hook_extra ) {
		unset( $upgrader );

		if ( ! is_array( $hook_extra ) || empty( $hook_extra['type'] ) ) {
			return;
		}

		if ( in_array( (string) $hook_extra['type'], array( 'plugin', 'theme' ), true ) ) {
			self::request_refresh();
		}
	}

	/**
	 * Deleting a plugin from the Plugins screen removes its PHP on purpose.
	 *
	 * @param mixed $plugin_file Plugin basename. Unused.
	 * @param bool  $deleted     Whether WordPress removed the files.
	 */
	public static function on_plugin_deleted( $plugin_file, $deleted ) {
		unset( $plugin_file );

		if ( $deleted ) {
			self::request_refresh();
		}
	}

	/**
	 * Deleting a theme from the Themes screen removes its PHP on purpose.
	 *
	 * @param mixed $stylesheet Theme directory. Unused.
	 * @param bool  $deleted    Whether WordPress removed the files.
	 */
	public static function on_theme_deleted( $stylesheet, $deleted ) {
		unset( $stylesheet );

		if ( $deleted ) {
			self::request_refresh();
		}
	}

	/**
	 * Compare plugin and theme PHP files with the record on this site.
	 *
	 * @return array<string, mixed>
	 */
	public static function report() {
		$cached = self::cached_result();

		if ( null !== $cached && ! self::refresh_requested() ) {
			return self::to_finding( $cached );
		}

		if ( get_site_transient( self::LOCK ) ) {
			return self::to_finding(
				array(
					'state' => 'running',
				)
			);
		}

		set_site_transient( self::LOCK, '1', 2 * MINUTE_IN_SECONDS );
		$result = self::compare();
		delete_site_transient( self::LOCK );

		if ( 'running' !== $result['state'] ) {
			$ttl = ( 'incomplete' === $result['state'] ) ? 15 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS;
			self::store_result( $result, $ttl );
		}

		return self::to_finding( $result );
	}

	/**
	 * Ask the next check to replace the record before it compares.
	 */
	private static function request_refresh() {
		self::write_flag( '1' );
		delete_site_transient( self::CACHE );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function compare() {
		$collected = self::collect();

		if ( ! empty( $collected['incomplete'] ) ) {
			return array(
				'state'    => 'incomplete',
				'files'    => array(),
				'extra'    => 0,
				'total'    => 0,
				'recorded' => '',
			);
		}

		$current = $collected['files'];
		$stored  = self::baseline_files();
		$refresh = self::refresh_requested();

		if ( null === $stored || $refresh ) {
			if ( ! self::store_baseline( $current ) ) {
				return array(
					'state'    => 'incomplete',
					'files'    => array(),
					'extra'    => 0,
					'total'    => 0,
					'present'  => array(),
					'recorded' => '',
				);
			}

			self::write_flag( '' );
			self::write_scope();

			return array(
				'state'    => 'recorded',
				'files'    => array(),
				'extra'    => 0,
				'total'    => count( $current ),
				'present'  => self::present_paths( $current ),
				'recorded' => $refresh ? 'refresh' : 'first',
			);
		}

		if ( ! self::scope_ready() ) {
			foreach ( $current as $path => $hash ) {
				if ( self::is_extra( $path ) && ! isset( $stored[ $path ] ) ) {
					$stored[ $path ] = $hash;
				}
			}

			if ( ! self::store_baseline( $stored ) ) {
				return array(
					'state'    => 'incomplete',
					'files'    => array(),
					'extra'    => 0,
					'total'    => 0,
					'present'  => array(),
					'recorded' => '',
				);
			}

			self::write_scope();
		}

		$changed = array();

		foreach ( $current as $path => $hash ) {
			$previous = isset( $stored[ $path ] ) ? $stored[ $path ] : '';

			if ( ! is_string( $previous ) || strlen( $previous ) !== strlen( $hash ) || ! hash_equals( $previous, $hash ) ) {
				$changed[] = $path;
			}
		}

		foreach ( $stored as $path => $hash ) {
			unset( $hash );

			if ( ! isset( $current[ $path ] ) ) {
				$changed[] = $path;
			}
		}

		sort( $changed, SORT_STRING );

		$total = count( $changed );
		$shown = array_slice( $changed, 0, 12 );

		return array(
			'state'    => ( $total > 0 ) ? 'differ' : 'match',
			'files'    => $shown,
			'extra'    => max( 0, $total - count( $shown ) ),
			'total'    => $total,
			'present'  => self::present_paths( $current ),
			'recorded' => '',
		);
	}

	/**
	 * @return array{files: array<string, string>, incomplete: bool}
	 */
	private static function collect() {
		$files      = array();
		$incomplete = false;
		$started    = microtime( true );
		$seen       = array();

		foreach ( self::roots() as $bucket => $root ) {
			if ( '' === $root || ! is_dir( $root ) || ! is_readable( $root ) ) {
				$incomplete = true;
				break;
			}

			$walk = self::walk( $root, $bucket, $files, $seen, $started );

			if ( 'ok' !== $walk ) {
				$incomplete = true;
				break;
			}
		}

		if ( ! $incomplete ) {
			$mu = wp_normalize_path( untrailingslashit( WPMU_PLUGIN_DIR ) );

			if ( is_dir( $mu ) ) {
				if ( ! is_readable( $mu ) || 'ok' !== self::walk( $mu, 'mu-plugins', $files, $seen, $started ) ) {
					$incomplete = true;
				}
			}
		}

		if ( ! $incomplete && 'ok' !== self::collect_dropins( $files, $started ) ) {
			$incomplete = true;
		}

		ksort( $files, SORT_STRING );

		return array(
			'files'      => $files,
			'incomplete' => $incomplete,
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function roots() {
		return array(
			'plugins' => wp_normalize_path( untrailingslashit( WP_PLUGIN_DIR ) ),
			'themes'  => wp_normalize_path( untrailingslashit( get_theme_root() ) ),
		);
	}

	/**
	 * @param string               $root    Normalized directory without a trailing slash.
	 * @param string               $bucket  plugins or themes.
	 * @param array<string, string> $files   Path to hash, updated in place.
	 * @param array<string, bool>  $seen    Real directories already entered.
	 * @param float                $started Walk start time.
	 * @return string ok or incomplete.
	 */
	private static function walk( $root, $bucket, array &$files, array &$seen, $started ) {
		$stack = array( $root );

		while ( array() !== $stack ) {
			if ( ( microtime( true ) - $started ) > self::TIME_LIMIT || count( $files ) >= self::MAX_FILES ) {
				return 'incomplete';
			}

			$dir  = array_pop( $stack );
			$real = realpath( $dir );

			if ( false === $real ) {
				return 'incomplete';
			}

			$real = wp_normalize_path( $real );

			if ( isset( $seen[ $real ] ) ) {
				continue;
			}

			if ( ! self::inside( $real, $root ) ) {
				return 'incomplete';
			}

			$seen[ $real ] = true;
			$handle        = opendir( $real );

			if ( false === $handle ) {
				return 'incomplete';
			}

			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				if ( ! self::valid_entry( $entry ) ) {
					closedir( $handle );
					return 'incomplete';
				}

				$path = $real . '/' . $entry;

				if ( is_link( $path ) ) {
					$linked = realpath( $path );

					if ( false === $linked || ! self::inside( wp_normalize_path( $linked ), $root ) ) {
						if ( self::is_php( $entry ) ) {
							closedir( $handle );
							return 'incomplete';
						}

						continue;
					}

					$path = wp_normalize_path( $linked );
				}

				if ( is_dir( $path ) ) {
					if ( self::skipped_dir( $entry ) ) {
						continue;
					}

					$stack[] = $path;
					continue;
				}

				if ( ! self::is_php( $entry ) || ! is_file( $path ) ) {
					continue;
				}

				if ( ( microtime( true ) - $started ) > self::TIME_LIMIT || count( $files ) >= self::MAX_FILES ) {
					closedir( $handle );
					return 'incomplete';
				}

				$relative = self::relative( $root, $path );

				if ( '' === $relative ) {
					closedir( $handle );
					return 'incomplete';
				}

				$hash = self::hash_file( $path );

				if ( '' === $hash ) {
					closedir( $handle );
					return 'incomplete';
				}

				$files[ $bucket . '/' . $relative ] = $hash;
			}

			closedir( $handle );
		}

		return 'ok';
	}

	/**
	 * @param string $name Directory name.
	 * @return bool
	 */
	private static function skipped_dir( $name ) {
		return in_array( strtolower( $name ), array( 'node_modules', '.git', '.svn', 'bower_components' ), true );
	}

	/**
	 * @param string $name File name.
	 * @return bool
	 */
	private static function is_php( $name ) {
		return strlen( $name ) > 4 && '.php' === strtolower( substr( $name, -4 ) );
	}

	/**
	 * @param string $path Readable file path.
	 * @return string md5, a size token, or empty when the file cannot be read.
	 */
	private static function hash_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return '';
		}

		$size = filesize( $path );

		if ( false === $size || $size < 0 ) {
			return '';
		}

		if ( $size > self::MAX_BYTES ) {
			return 'size:' . (string) $size;
		}

		$hash = md5_file( $path );

		if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{32}$/', strtolower( $hash ) ) ) {
			return '';
		}

		return strtolower( $hash );
	}

	/**
	 * @param string $root Normalized directory without a trailing slash.
	 * @param string $path File path inside that directory.
	 * @return string
	 */
	private static function relative( $root, $path ) {
		$path = wp_normalize_path( $path );

		if ( ! self::inside( $path, $root ) || $path === $root ) {
			return '';
		}

		$relative = substr( $path, strlen( $root ) + 1 );

		if ( ! is_string( $relative ) || ! self::valid_relative( $relative ) ) {
			return '';
		}

		return $relative;
	}

	/**
	 * @param string $entry Name returned by readdir().
	 * @return bool
	 */
	private static function valid_entry( $entry ) {
		if ( ! is_string( $entry ) || '' === $entry || strlen( $entry ) > 180 ) {
			return false;
		}

		if ( false !== strpos( $entry, '/' ) || false !== strpos( $entry, '\\' ) ) {
			return false;
		}

		return 1 !== preg_match( '/[\x00-\x1F\x7F]/', $entry );
	}

	/**
	 * @param string $relative Path under a plugin or theme directory.
	 * @return bool
	 */
	private static function valid_relative( $relative ) {
		if ( strlen( $relative ) > 180 || preg_match( '/[\x00-\x1F\x7F\\\\]/', $relative ) ) {
			return false;
		}

		$parts = explode( '/', $relative );

		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string $path Normalized path.
	 * @param string $root Normalized directory without a trailing slash.
	 * @return bool
	 */
	private static function inside( $path, $root ) {
		$path = wp_normalize_path( $path );
		$root = wp_normalize_path( untrailingslashit( $root ) );

		if ( $path === $root ) {
			return true;
		}

		$prefix = $root . '/';

		if ( 0 === strpos( $path, $prefix ) ) {
			return true;
		}

		return 0 === strpos( PHP_OS, 'WIN' ) && 0 === stripos( $path, $prefix );
	}

	/**
	 * @return array<string, string>|null Null when no valid record is stored.
	 */
	private static function baseline_files() {
		$stored = is_multisite() ? get_site_option( self::BASELINE, null ) : get_option( self::BASELINE, null );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		$files = array();

		foreach ( $stored as $path => $hash ) {
			if ( ! is_string( $path ) || ! self::valid_key( $path ) || ! is_string( $hash ) || ! self::valid_hash( $hash ) ) {
				return null;
			}

			$files[ $path ] = $hash;
		}

		return $files;
	}

	/**
	 * @param array<string, string> $files Current PHP hashes.
	 * @return bool
	 */
	private static function store_baseline( array $files ) {
		if ( is_multisite() ) {
			$written = update_site_option( self::BASELINE, $files );
			$saved   = get_site_option( self::BASELINE, null );

			return $written || ( is_array( $saved ) && $saved === $files );
		}

		return self::stored( self::BASELINE, $files, update_option( self::BASELINE, $files, false ) );
	}

	/**
	 * @param string $value 1 to request a refresh, or empty to clear it.
	 */
	private static function write_flag( $value ) {
		if ( is_multisite() ) {
			if ( '' === $value ) {
				delete_site_option( self::REFRESH );
				return;
			}

			update_site_option( self::REFRESH, $value );
			return;
		}

		if ( '' === $value ) {
			delete_option( self::REFRESH );
			return;
		}

		update_option( self::REFRESH, $value, false );
	}

	/**
	 * @return bool
	 */
	private static function refresh_requested() {
		$value = is_multisite() ? get_site_option( self::REFRESH, '' ) : get_option( self::REFRESH, '' );

		return '1' === $value;
	}

	/**
	 * update_option() is false both when the write fails and when the value is unchanged.
	 *
	 * @param string               $name    Option name.
	 * @param array<string, string> $files   Value that should now be stored.
	 * @param bool                 $written Return value from update_option().
	 * @return bool
	 */
	private static function stored( $name, array $files, $written ) {
		if ( $written ) {
			return true;
		}

		$saved = get_option( $name, null );

		return is_array( $saved ) && $saved === $files;
	}

	/**
	 * @param string $path Record key.
	 * @return bool
	 */
	private static function valid_key( $path ) {
		if ( strlen( $path ) > 200 || ! preg_match( '/^(plugins|themes|mu-plugins|dropins)\//', $path ) ) {
			return false;
		}

		return self::valid_relative( substr( $path, strpos( $path, '/' ) + 1 ) );
	}

	/**
	 * @param string $hash Stored hash or size token.
	 * @return bool
	 */
	private static function valid_hash( $hash ) {
		return 1 === preg_match( '/^(?:[a-f0-9]{32}|size:[0-9]{1,12})$/', $hash );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function cached_result() {
		$stored = get_site_transient( self::CACHE );

		if ( ! is_array( $stored ) || empty( $stored['state'] ) ) {
			return null;
		}

		$state = (string) $stored['state'];

		if ( ! in_array( $state, array( 'match', 'recorded', 'differ', 'incomplete' ), true ) ) {
			return null;
		}

		if ( ! isset( $stored['scope'] ) || self::SCOPE_VERSION !== (string) $stored['scope'] ) {
			return null;
		}

		return $stored;
	}

	/**
	 * @param array<string, mixed> $result Comparison result.
	 * @param int                  $ttl    Seconds to keep the result.
	 */
	private static function store_result( array $result, $ttl ) {
		$files = array();

		if ( isset( $result['files'] ) && is_array( $result['files'] ) ) {
			foreach ( $result['files'] as $file ) {
				if ( is_string( $file ) && self::valid_key( $file ) ) {
					$files[] = $file;
				}
			}
		}

		$present = array();

		if ( isset( $result['present'] ) && is_array( $result['present'] ) ) {
			foreach ( $result['present'] as $file ) {
				if ( is_string( $file ) && self::valid_key( $file ) && self::is_extra( $file ) ) {
					$present[] = $file;
				}
			}
		}

		set_site_transient(
			self::CACHE,
			array(
				'scope'    => self::SCOPE_VERSION,
				'state'    => isset( $result['state'] ) ? (string) $result['state'] : 'incomplete',
				'files'    => $files,
				'extra'    => isset( $result['extra'] ) ? (int) $result['extra'] : 0,
				'total'    => isset( $result['total'] ) ? (int) $result['total'] : 0,
				'present'  => $present,
				'recorded' => isset( $result['recorded'] ) ? (string) $result['recorded'] : '',
			),
			(int) $ttl
		);
	}

	/**
	 * @param array<string, mixed> $result Comparison result.
	 * @return array<string, mixed>
	 */
	private static function to_finding( array $result ) {
		$state   = isset( $result['state'] ) ? (string) $result['state'] : 'incomplete';
		$title   = __( 'PHP files', 'safety-pass-by-nuem8' );
		$present = self::present_lines( $result );

		if ( 'running' === $state ) {
			return self::finding(
				'look',
				57,
				$title,
				__( 'nuem8-safety-pass is reading plugin, theme, must-use, and drop-in PHP files.', 'safety-pass-by-nuem8' ),
				__( 'Open nuem8-safety-pass again in a minute and read the result.', 'safety-pass-by-nuem8' )
			);
		}

		if ( 'recorded' === $state && 'refresh' === ( isset( $result['recorded'] ) ? (string) $result['recorded'] : '' ) ) {
			return self::finding(
				'ok',
				0,
				$title,
				self::with_present(
					__( 'Plugin, theme, must-use, and drop-in PHP files match the record. WordPress installed or removed a plugin or theme, so nuem8-safety-pass updated the record. The result is saved for 12 hours.', 'safety-pass-by-nuem8' ),
					$present
				),
				'',
				$present
			);
		}

		if ( 'recorded' === $state ) {
			return self::finding(
				'ok',
				0,
				$title,
				self::with_present(
					__( 'nuem8-safety-pass recorded the PHP files in plugins, themes, must-use plugins, and drop-ins. This first record includes whatever is already on the server. Later checks look for a PHP file that changes outside a WordPress update. The result is saved for 12 hours.', 'safety-pass-by-nuem8' ),
					$present
				),
				'',
				$present
			);
		}

		if ( 'match' === $state ) {
			return self::finding(
				'ok',
				0,
				$title,
				self::with_present(
					__( 'Plugin, theme, must-use, and drop-in PHP files match the record on this site. A WordPress update refreshes the record. Uploads are not part of this check. The result is saved for 12 hours.', 'safety-pass-by-nuem8' ),
					$present
				),
				'',
				$present
			);
		}

		if ( 'differ' === $state ) {
			$items = array();
			$skip  = array();

			if ( isset( $result['files'] ) && is_array( $result['files'] ) ) {
				foreach ( $result['files'] as $file ) {
					if ( is_string( $file ) && self::valid_key( $file ) ) {
						$items[]        = $file;
						$skip[ $file ] = true;
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

			foreach ( self::present_lines( $result, $skip ) as $line ) {
				$items[] = $line;
			}

			$total = isset( $result['total'] ) ? (int) $result['total'] : count( $items );

			return self::finding(
				'look',
				54,
				$title,
				sprintf(
					/* translators: %d: number of PHP files that changed. */
					_n(
						'%d PHP file in a plugin, theme, must-use plugin, or drop-in has changed since the record. A difference is something to look at. It is not a malware verdict. Uploads are not part of this check.',
						'%d PHP files in plugins, themes, must-use plugins, or drop-ins have changed since the record. A difference is something to look at. It is not a malware verdict. Uploads are not part of this check.',
						max( 1, $total ),
						'safety-pass-by-nuem8'
					),
					max( 1, $total )
				),
				__( 'Compare the listed file with the copy you expect. nuem8-safety-pass will not replace it.', 'safety-pass-by-nuem8' ),
				$items
			);
		}

		return self::finding(
			'look',
			60,
			$title,
			__( 'nuem8-safety-pass could not finish reading plugin, theme, must-use, and drop-in PHP files.', 'safety-pass-by-nuem8' ),
			__( 'Open nuem8-safety-pass again in a few minutes.', 'safety-pass-by-nuem8' )
		);
	}

	/**
	 * @param string   $detail  Finding detail.
	 * @param string[] $present Labeled must-use plugins and drop-ins.
	 * @return string
	 */
	private static function with_present( $detail, array $present ) {
		if ( array() === $present ) {
			return $detail;
		}

		return $detail . ' ' . __( 'Must-use plugins and drop-ins on this site are listed below.', 'safety-pass-by-nuem8' );
	}

	/**
	 * @param array<string, mixed> $result Comparison result.
	 * @param array<string, bool>  $skip   Paths already listed as changed.
	 * @return string[]
	 */
	private static function present_lines( array $result, array $skip = array() ) {
		$lines = array();

		if ( empty( $result['present'] ) || ! is_array( $result['present'] ) ) {
			return $lines;
		}

		foreach ( $result['present'] as $file ) {
			if ( ! is_string( $file ) || ! self::valid_key( $file ) || isset( $skip[ $file ] ) || ! self::is_extra( $file ) ) {
				continue;
			}

			if ( 0 === strpos( $file, 'mu-plugins/' ) ) {
				$lines[] = sprintf(
					/* translators: %s: path of a must-use plugin. */
					__( 'Must-use plugin: %s', 'safety-pass-by-nuem8' ),
					$file
				);
				continue;
			}

			$lines[] = sprintf(
				/* translators: %s: path of a drop-in file. */
				__( 'Drop-in: %s', 'safety-pass-by-nuem8' ),
				$file
			);
		}

		return $lines;
	}

	/**
	 * @param array<string, string> $files Current PHP hashes.
	 * @return string[]
	 */
	private static function present_paths( array $files ) {
		$paths = array();

		foreach ( $files as $path => $hash ) {
			unset( $hash );

			if ( is_string( $path ) && self::is_extra( $path ) ) {
				$paths[] = $path;
			}
		}

		sort( $paths, SORT_STRING );

		return $paths;
	}

	/**
	 * @param string $path Record key.
	 * @return bool
	 */
	private static function is_extra( $path ) {
		return 0 === strpos( $path, 'mu-plugins/' ) || 0 === strpos( $path, 'dropins/' );
	}

	/**
	 * Hash the drop-in files WordPress loads from wp-content when the file exists.
	 *
	 * @param array<string, string> $files   Path to hash, updated in place.
	 * @param float                 $started Walk start time.
	 * @return string ok or incomplete.
	 */
	private static function collect_dropins( array &$files, $started ) {
		$root = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );

		if ( '' === $root || ! is_dir( $root ) || ! is_readable( $root ) ) {
			return 'incomplete';
		}

		foreach ( self::dropin_names() as $name ) {
			if ( ( microtime( true ) - $started ) > self::TIME_LIMIT || count( $files ) >= self::MAX_FILES ) {
				return 'incomplete';
			}

			$path = $root . '/' . $name;

			if ( ! file_exists( $path ) ) {
				continue;
			}

			if ( is_link( $path ) ) {
				$linked = realpath( $path );

				if ( false === $linked || ! self::inside( wp_normalize_path( $linked ), $root ) ) {
					return 'incomplete';
				}

				$path = wp_normalize_path( $linked );
			}

			if ( ! is_file( $path ) ) {
				continue;
			}

			$hash = self::hash_file( $path );

			if ( '' === $hash ) {
				return 'incomplete';
			}

			$files[ 'dropins/' . $name ] = $hash;
		}

		return 'ok';
	}

	/**
	 * @return string[]
	 */
	private static function dropin_names() {
		return array(
			'advanced-cache.php',
			'db.php',
			'db-error.php',
			'install.php',
			'maintenance.php',
			'object-cache.php',
			'php-error.php',
			'fatal-error-handler.php',
			'sunrise.php',
			'blog-deleted.php',
			'blog-inactive.php',
			'blog-suspended.php',
		);
	}

	/**
	 * @return bool
	 */
	private static function scope_ready() {
		$value = is_multisite() ? get_site_option( self::SCOPE, '' ) : get_option( self::SCOPE, '' );

		return self::SCOPE_VERSION === (string) $value;
	}

	/**
	 * Remember that this record includes must-use plugins and drop-ins.
	 */
	private static function write_scope() {
		if ( is_multisite() ) {
			update_site_option( self::SCOPE, self::SCOPE_VERSION );
			return;
		}

		update_option( self::SCOPE, self::SCOPE_VERSION, false );
	}

	/**
	 * @param string $status ok or look.
	 * @param int    $rank   Lower numbers are more urgent.
	 * @param string $title  Short label.
	 * @param string $detail What nuem8-safety-pass found.
	 * @param string $action The one thing to do. Empty when status is ok.
	 * @param array  $items  Extra rows for the admin screen.
	 * @return array<string, mixed>
	 */
	private static function finding( $status, $rank, $title, $detail, $action, array $items = array() ) {
		return array(
			'id'     => 'php_files',
			'status' => $status,
			'rank'   => $rank,
			'title'  => $title,
			'detail' => $detail,
			'action' => $action,
			'items'  => $items,
		);
	}
}
