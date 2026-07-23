<?php
/**
 * Protected working storage for Revertix.
 *
 * All scratch state, export packages and backups live under
 * wp-content/uploads/revertix/, hardened so they are not web-guessable:
 *   - an .htaccess "Deny from all" (for Apache)
 *   - an empty index.html (directory listing guard)
 *   - a random token kept in an option, used to name the packages subfolder.
 *
 * Layout:
 *   uploads/revertix/
 *     .htaccess, index.html
 *     tmp/        in-progress job state + scratch
 *     backups/    saved backup packages (manual + scheduled)
 *     packages/   export packages awaiting download
 *     logs/       persistent diagnostic log (see Revertix_Log)
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- direct filesystem calls are required for resumable, chunked backup/restore/import; WP_Filesystem cannot stream work across time-budgeted ticks.

/**
 * Creates and locates Revertix's working directories.
 */
class Revertix_Storage {

	const OPTION_TOKEN = 'revertix_storage_token';

	/**
	 * Absolute path to the base working directory (trailing slash).
	 *
	 * @return string
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'revertix/';
	}

	/**
	 * Absolute path to a named subdirectory (tmp|backups|packages), trailing slash.
	 *
	 * @param string $sub Subdirectory name.
	 * @return string
	 */
	public static function sub_dir( $sub ) {
		return self::base_dir() . trim( $sub, '/' ) . '/';
	}

	/**
	 * Whether the base directory exists and is writable (creating it if missing).
	 *
	 * @return bool
	 */
	public static function is_writable() {
		$base = self::base_dir();

		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
		}

		return file_exists( $base ) && wp_is_writable( $base );
	}

	/**
	 * Create and harden all working directories. Runs on activation, and is
	 * idempotent so it can be called defensively before any operation.
	 */
	public static function ensure() {
		$base = self::base_dir();
		wp_mkdir_p( $base );

		foreach ( array( 'tmp', 'backups', 'packages', 'logs' ) as $sub ) {
			wp_mkdir_p( self::sub_dir( $sub ) );
		}

		self::harden( $base );

		// Stable random token used when building public-facing package paths.
		if ( ! get_option( self::OPTION_TOKEN ) ) {
			update_option( self::OPTION_TOKEN, wp_generate_password( 20, false, false ), false );
		}
	}

	/**
	 * Drop the protective files into a directory.
	 *
	 * @param string $dir Absolute directory path.
	 */
	public static function harden( $dir ) {
		$dir = trailingslashit( $dir );

		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// "Deny from all" (2.x) + Require all denied (2.4+) for portability.
			$rules = "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
			@file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}

		$index = $dir . 'index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Activation callback. Creates storage and stores the install version.
	 */
	public static function on_activation() {
		self::ensure();
		update_option( 'revertix_version', REVERTIX_VERSION, false );
	}

	/**
	 * Remove stale scratch so finished/abandoned jobs don't pile up on the site.
	 *
	 * Deletes job working dirs under tmp/ and leftover uploaded packages under
	 * imports/ that are older than the cutoff (default 6h, so a long in-progress
	 * job is never touched). Hooked to the daily cron.
	 *
	 * @param int $max_age_seconds Age threshold in seconds.
	 */
	public static function cleanup_tmp( $max_age_seconds = 21600 ) {
		$cutoff = time() - (int) $max_age_seconds;

		// Old job working directories.
		$tmp = self::sub_dir( 'tmp' );
		if ( is_dir( $tmp ) ) {
			foreach ( (array) glob( $tmp . '*', GLOB_ONLYDIR ) as $dir ) {
				if ( (int) filemtime( $dir ) < $cutoff ) {
					Revertix_Filesystem::delete_tree( $dir, rtrim( $tmp, '/' ) );
				}
			}
		}

		// Old uploaded import payloads (.zip / .part).
		$imports = self::sub_dir( 'imports' );
		if ( is_dir( $imports ) ) {
			foreach ( (array) glob( $imports . '*.{zip,part}', GLOB_BRACE ) as $file ) {
				if ( is_file( $file ) && (int) filemtime( $file ) < $cutoff ) {
					@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
		}
	}
}
