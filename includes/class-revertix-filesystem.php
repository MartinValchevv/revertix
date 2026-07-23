<?php
/**
 * Filesystem helpers: a *resumable* directory walker and exclusion rules.
 *
 * For large sites we cannot list every file in one request, so the scanner is
 * driven by a directory stack stored in job state. Each call processes entries
 * until a time budget runs out, appending discovered files to an index file and
 * returning the updated stack so the next request can continue.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- direct filesystem calls are required for resumable, chunked backup/restore/import; WP_Filesystem cannot stream work across time-budgeted ticks.

/**
 * Stateless filesystem utilities operating relative to the web root (ABSPATH).
 */
class Revertix_Filesystem {

	/**
	 * The root of the site we export/clone - the WordPress install root.
	 *
	 * The single source of truth for the install root across the plugin. ABSPATH
	 * is the constant WordPress itself provides for this location (there is no
	 * function equivalent for the install root); everything else derives its
	 * paths from here or from plugin_dir_path()/wp_upload_dir().
	 *
	 * @return string Trailing-slashed absolute path.
	 */
	public static function root() {
		return trailingslashit( ABSPATH );
	}

	/**
	 * The plugins directory (with trailing slash), derived from this plugin's
	 * own location via plugin_dir_path() - our plugin lives directly inside
	 * it - so no WordPress internal constants are needed.
	 *
	 * @return string
	 */
	public static function plugins_dir() {
		return trailingslashit( dirname( untrailingslashit( REVERTIX_PLUGIN_DIR ) ) );
	}

	/**
	 * The content directory (with trailing slash), derived the same way: the
	 * plugins directory lives directly inside it.
	 *
	 * @return string
	 */
	public static function content_dir() {
		return trailingslashit( dirname( untrailingslashit( self::plugins_dir() ) ) );
	}

	/**
	 * Absolute paths that must never be packaged or copied. Returning real,
	 * normalised prefixes (trailing slash) lets is_excluded() do a fast compare.
	 *
	 * @return string[]
	 */
	public static function excluded_prefixes() {
		$root = self::root();

		$excludes = array(
			// Revertix's own working storage (prevents packaging packages).
			trailingslashit( Revertix_Storage::base_dir() ),
			// Common throwaway dirs.
			$root . 'wp-content/cache/',
			$root . 'wp-content/upgrade/',
			$root . 'wp-content/uploads/cache/',
		);

		/**
		 * Filter the list of excluded absolute path prefixes.
		 *
		 * @param string[] $excludes Trailing-slashed absolute prefixes.
		 */
		return apply_filters( 'revertix_excluded_prefixes', $excludes );
	}

	/**
	 * Directory/file names skipped wherever they appear in the tree: version
	 * control metadata and dependency/build folders. These are dev artifacts -
	 * large, full of read-only files, and useless on a restored/migrated site.
	 *
	 * @return string[] Lower-cased names compared against each path segment.
	 */
	public static function excluded_names() {
		/**
		 * Filter the path-segment names excluded everywhere (export + restore).
		 *
		 * @param string[] $names e.g. '.git', 'node_modules'.
		 */
		return apply_filters(
			'revertix_excluded_names',
			array(
				'.git',
				'.svn',
				'.hg',
				'.bzr',
				'node_modules',
				'.DS_Store',
				// Staging's login-protection mu-plugin: never package, copy, compare
				// or sync it - it must stay local to the staging site so it can't lock
				// the live site and isn't wiped by a pull.
				Revertix_Staging::PROTECT_MU,
			)
		);
	}

	/**
	 * Whether any segment of a relative path matches an excluded name.
	 *
	 * @param string $rel Relative path (forward slashes).
	 * @return bool
	 */
	public static function has_excluded_segment( $rel ) {
		$names = self::excluded_names();
		foreach ( explode( '/', trim( str_replace( '\\', '/', $rel ), '/' ) ) as $segment ) {
			if ( '' !== $segment && in_array( $segment, $names, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a path should be skipped.
	 *
	 * Skips Revertix storage, declared excludes, and any staging copy directory
	 * (revertix-staging-*) so a staging site is never packaged into itself.
	 *
	 * @param string   $abs_path Absolute filesystem path.
	 * @param string[] $extra    Additional absolute prefixes to exclude (e.g. file
	 *                           areas the user de-selected in Advanced export).
	 * @return bool
	 */
	public static function is_excluded( $abs_path, array $extra = array() ) {
		$abs_path = self::normalize( $abs_path );

		// Any Revertix staging directory at the web root, e.g. revertix-staging-ab12.
		$root = self::normalize( self::root() );
		if ( 0 === strpos( $abs_path, $root ) ) {
			$relative = ltrim( substr( $abs_path, strlen( $root ) ), '/' );
			if ( preg_match( '#^revertix-staging-[^/]+#', $relative ) ) {
				return true;
			}
		}

		$prefixes = array_merge( self::excluded_prefixes(), $extra );

		foreach ( $prefixes as $prefix ) {
			$prefix = self::normalize( $prefix );
			if ( '' === $prefix ) {
				continue;
			}
			if ( $abs_path === $prefix || 0 === strpos( $abs_path . '/', $prefix . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalise a path to forward slashes without a trailing slash (except root).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function normalize( $path ) {
		$path = str_replace( '\\', '/', $path );
		return rtrim( $path, '/' );
	}

	/**
	 * Process one chunk of a directory scan.
	 *
	 * @param array    $stack     Directory stack (absolute paths) yet to visit.
	 * @param resource $index_fh  Open file handle to append "relpath\tsize\n" lines.
	 * @param callable $time_left A callback returning remaining seconds (float).
	 * @param string[]      $extra     Additional absolute prefixes to exclude.
	 * @param string[]|null $names     Path-segment names to skip (null = defaults).
	 * @return array{stack:string[],files:int,bytes:int,done:bool}
	 *               Updated stack and counters for this chunk.
	 */
	public static function scan_step( array $stack, $index_fh, callable $time_left, array $extra = array(), $names = null ) {
		$root   = self::root();
		$files  = 0;
		$bytes  = 0;
		$names  = ( null === $names ) ? self::excluded_names() : $names;

		while ( ! empty( $stack ) && call_user_func( $time_left ) > 1.0 ) {
			$dir = array_pop( $stack );

			$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $handle ) {
				continue;
			}

			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				// Skip VCS/dependency folders (.git, node_modules, …) by name.
				if ( in_array( $entry, $names, true ) ) {
					continue;
				}

				$abs = trailingslashit( $dir ) . $entry;

				if ( self::is_excluded( $abs, $extra ) ) {
					continue;
				}

				if ( is_link( $abs ) ) {
					// Skip symlinks to avoid escaping the tree / loops.
					continue;
				}

				if ( is_dir( $abs ) ) {
					$stack[] = $abs;
					continue;
				}

				if ( is_file( $abs ) && is_readable( $abs ) ) {
					$relative = ltrim( substr( self::normalize( $abs ), strlen( self::normalize( $root ) ) ), '/' );
					$size     = (int) filesize( $abs );
					fwrite( $index_fh, $relative . "\t" . $size . "\n" );
					++$files;
					$bytes += $size;
				}
			}

			closedir( $handle );
		}

		return array(
			'stack' => array_values( $stack ),
			'files' => $files,
			'bytes' => $bytes,
			'done'  => empty( $stack ),
		);
	}

	/**
	 * The selectable file "areas" for Advanced export: key => [label, dir].
	 *
	 * This plugin packages everything inside wp-content (uploads, themes,
	 * plugins, mu-plugins and the rest of wp-content). Packaging files that
	 * live OUTSIDE wp-content, in the WordPress root, is provided by the
	 * separate Revertix PRO add-on, which adds its own area via the filter.
	 *
	 * @return array<string,array{label:string,dir:string}>
	 */
	public static function file_areas() {
		$content = Revertix_Filesystem::content_dir();
		$uploads = wp_upload_dir();

		$areas = array(
			'uploads'    => array(
				'label' => __( 'Media library (uploads)', 'revertix' ),
				'dir'   => self::normalize( $uploads['basedir'] ),
			),
			'themes'     => array(
				'label' => __( 'Themes', 'revertix' ),
				'dir'   => self::normalize( $content . 'themes' ),
			),
			'mu-plugins' => array(
				'label' => __( 'Must-use plugins', 'revertix' ),
				'dir'   => self::normalize( $content . 'mu-plugins' ),
			),
			'plugins'    => array(
				'label' => __( 'Plugins', 'revertix' ),
				'dir'   => self::normalize( $content . 'plugins' ),
			),
			'wpcontent'  => array(
				'label' => __( 'Other files in wp-content', 'revertix' ),
				'dir'   => self::normalize( $content ),
			),
		);

		/**
		 * Filter the selectable backup file areas. Add-ons may add areas
		 * (e.g. the Revertix PRO add-on adds the whole-root area).
		 *
		 * @param array $areas key => array{label:string,dir:string}.
		 */
		return (array) apply_filters( 'revertix_backup_file_areas', $areas );
	}

	/**
	 * Resolve selected area keys into concrete scan roots + extra exclusions.
	 *
	 * @param string[] $selected Area keys the user wants included.
	 * @return array{roots:string[],excludes:string[]}
	 */
	public static function resolve_areas( array $selected ) {
		$areas    = self::file_areas();
		$selected = array_values( array_intersect( array_keys( $areas ), $selected ) );

		$roots    = array();
		$excludes = array();

		// The four discrete directories that have their own area.
		$subareas = array( 'uploads', 'themes', 'mu-plugins', 'plugins' );
		$content  = self::normalize( self::content_dir() );

		if ( in_array( 'wpcontent', $selected, true ) ) {
			// All of wp-content, minus any discrete sub-area not selected.
			$roots[] = $content;
			foreach ( $subareas as $key ) {
				if ( ! in_array( $key, $selected, true ) && is_dir( $areas[ $key ]['dir'] ) ) {
					$excludes[] = $areas[ $key ]['dir'];
				}
			}
		} else {
			// Scan only the selected discrete sub-areas as their own roots.
			foreach ( $subareas as $key ) {
				if ( in_array( $key, $selected, true ) && is_dir( $areas[ $key ]['dir'] ) ) {
					$roots[] = $areas[ $key ]['dir'];
				}
			}
		}

		$result = array(
			'roots'    => array_values( array_unique( $roots ) ),
			'excludes' => array_values( array_unique( $excludes ) ),
		);

		/**
		 * Filter the resolved scan roots/excludes. Add-ons that register extra
		 * areas via revertix_backup_file_areas resolve them here (e.g. the
		 * Revertix PRO add-on resolves its whole-root area).
		 *
		 * @param array    $result   array{roots:string[],excludes:string[]}.
		 * @param string[] $selected Selected area keys.
		 * @param array    $areas    All known areas.
		 */
		return (array) apply_filters( 'revertix_resolve_areas', $result, $selected, $areas );
	}

	/**
	 * Base directory of an item-pickable area ('plugins'|'themes'|'mu-plugins').
	 *
	 * @param string $area_key Area key.
	 * @return string Absolute path (no trailing slash), or '' if not pickable.
	 */
	public static function area_base_dir( $area_key ) {
		$content = Revertix_Filesystem::content_dir();

		switch ( $area_key ) {
			case 'plugins':
				return self::normalize( $content . 'plugins' );
			case 'themes':
				return self::normalize( $content . 'themes' );
			case 'mu-plugins':
				return self::normalize( $content . 'mu-plugins' );
		}

		return '';
	}

	/**
	 * List the individual items (plugins/themes) inside a pickable area.
	 *
	 * Plugins are top-level directories *and* single-file plugins (e.g. hello.php);
	 * themes are top-level directories. Returns slug => human label, sorted by
	 * label. Labels fall back to the slug when no metadata is available.
	 *
	 * @param string $area_key 'plugins' or 'themes'.
	 * @return array<string,string>
	 */
	public static function list_area_items( $area_key ) {
		$base = self::area_base_dir( $area_key );
		if ( '' === $base || ! is_dir( $base ) ) {
			return array();
		}

		$names = self::excluded_names();
		$items = array();

		$dh = @opendir( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $dh ) {
			return array();
		}

		while ( false !== ( $entry = readdir( $dh ) ) ) {
			if ( '.' === $entry || '..' === $entry || in_array( $entry, $names, true ) ) {
				continue;
			}

			$abs = $base . '/' . $entry;

			if ( 'plugins' === $area_key ) {
				if ( is_dir( $abs ) || preg_match( '/\.php$/i', $entry ) ) {
					$items[ $entry ] = $entry;
				}
			} elseif ( is_dir( $abs ) ) {
				$items[ $entry ] = $entry;
			}
		}
		closedir( $dh );

		$items = self::label_area_items( $area_key, $items );

		asort( $items, SORT_NATURAL | SORT_FLAG_CASE );
		return $items;
	}

	/**
	 * Replace item slugs with friendly names where WordPress can provide them.
	 *
	 * @param string                $area_key 'plugins' or 'themes'.
	 * @param array<string,string> $items    slug => slug.
	 * @return array<string,string> slug => label.
	 */
	protected static function label_area_items( $area_key, array $items ) {
		if ( 'themes' === $area_key && function_exists( 'wp_get_themes' ) ) {
			foreach ( wp_get_themes() as $slug => $theme ) {
				if ( isset( $items[ $slug ] ) ) {
					$items[ $slug ] = $theme->get( 'Name' ) ? $theme->get( 'Name' ) : $slug;
				}
			}
			return $items;
		}

		if ( 'plugins' === $area_key ) {
			if ( ! function_exists( 'get_plugins' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			if ( function_exists( 'get_plugins' ) ) {
				foreach ( get_plugins() as $file => $data ) {
					$top = explode( '/', $file )[0];
					if ( isset( $items[ $top ] ) && ! empty( $data['Name'] ) ) {
						$items[ $top ] = $data['Name'];
					}
				}
			}
		}

		return $items;
	}

	/**
	 * Absolute exclude prefixes for the items of an area that were NOT selected.
	 *
	 * @param string   $area_key 'plugins' or 'themes'.
	 * @param string[] $selected Slugs the user chose to keep.
	 * @return string[] Normalised absolute paths to exclude.
	 */
	public static function area_item_excludes( $area_key, array $selected ) {
		$base = self::area_base_dir( $area_key );
		if ( '' === $base ) {
			return array();
		}

		$excludes = array();
		foreach ( array_keys( self::list_area_items( $area_key ) ) as $slug ) {
			if ( ! in_array( $slug, $selected, true ) ) {
				$excludes[] = self::normalize( $base . '/' . $slug );
			}
		}

		return $excludes;
	}

	/**
	 * Whether a relative path should be skipped when restoring files on import.
	 *
	 * Protects the things that must not be overwritten from another site:
	 * wp-config.php (DB credentials), the running Revertix plugins, and Revertix's
	 * own working storage.
	 *
	 * @param string $rel Relative path (from the site root).
	 * @return bool
	 */
	/**
	 * Normalise a package-relative path to a safe, traversal-free form.
	 *
	 * Collapses "." and empty segments and REJECTS anything that tries to climb
	 * out with "..", or uses an absolute path, a Windows drive letter, a stream
	 * wrapper, or a null byte. Package metadata (files-index.txt) and archive
	 * entry names are attacker-controlled, so every path derived from a package
	 * must pass through here before it is turned into a real path on disk.
	 *
	 * @param string $rel Raw relative path from an untrusted package.
	 * @return string|false Clean relative path, or false when unsafe.
	 */
	public static function safe_relative( $rel ) {
		$rel = str_replace( '\\', '/', (string) $rel );

		if ( '' === $rel
			|| false !== strpos( $rel, "\0" )
			|| false !== strpos( $rel, '://' )
			|| preg_match( '#^[a-zA-Z]:#', $rel )
		) {
			return false;
		}

		$out = array();
		foreach ( explode( '/', ltrim( $rel, '/' ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return false; // Any parent-directory climb is rejected outright.
			}
			$out[] = $segment;
		}

		return empty( $out ) ? false : implode( '/', $out );
	}

	public static function is_restore_excluded( $rel ) {
		// Unsafe / traversal paths are never restored (defense in depth: callers
		// should already have run the path through safe_relative()).
		$safe = self::safe_relative( $rel );
		if ( false === $safe ) {
			return true;
		}
		$rel = $safe;

		// VCS/dependency folders (.git, node_modules, …) - never restore them,
		// even if an older package still contains them.
		if ( self::has_excluded_segment( $rel ) ) {
			return true;
		}

		/**
		 * Filter the paths skipped during an import restore.
		 *
		 * @param string[] $skip Files (exact) and directory prefixes (trailing /).
		 */
		$skip = apply_filters(
			'revertix_restore_excludes',
			array(
				'wp-config.php',
				'wp-content/plugins/revertix/',
				'wp-content/plugins/revertix-pro/',
				'wp-content/uploads/revertix/',
			)
		);

		foreach ( $skip as $pattern ) {
			if ( '/' === substr( $pattern, -1 ) ) {
				if ( 0 === strpos( $rel . '/', $pattern ) ) {
					return true;
				}
			} elseif ( $rel === $pattern ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Copy a single file, creating its parent directory. Overwrites the target.
	 *
	 * @param string $source Absolute source path.
	 * @param string $dest   Absolute destination path.
	 * @return bool
	 */
	public static function copy_file( $source, $dest ) {
		$dir = dirname( $dest );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( @copy( $source, $dest ) ) {
			return true;
		}

		// The target may already exist and be read-only (e.g. an existing file or
		// a leftover .git object at 0444). Make it writable and retry.
		if ( file_exists( $dest ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors
			@chmod( $dest, 0644 );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( @copy( $source, $dest ) ) {
				return true;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $dest );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors
			return @copy( $source, $dest );
		}

		return false;
	}

	/**
	 * Recursively delete a directory. Guarded so it refuses to operate outside a
	 * required base path - callers must pass the base the target must live under.
	 *
	 * @param string $target    Absolute path to delete.
	 * @param string $must_be_in Absolute base path the target must be contained in.
	 * @return bool True on success.
	 */
	public static function delete_tree( $target, $must_be_in ) {
		$target_real = realpath( $target );
		$base_real   = realpath( $must_be_in );

		if ( ! $target_real || ! $base_real ) {
			return false;
		}

		$target_real = self::normalize( $target_real );
		$base_real   = self::normalize( $base_real );

		// Containment guard: target must be strictly inside the base.
		if ( 0 !== strpos( $target_real . '/', $base_real . '/' ) || $target_real === $base_real ) {
			return false;
		}

		try {
			$items = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $target_real, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $items as $item ) {
				$path = $item->getPathname();
				if ( $item->isDir() ) {
					@chmod( $path, 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				} else {
					// Make read-only files (e.g. cloned .git objects) deletable.
					@chmod( $path, 0666 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
		} catch ( Exception $e ) {
			Revertix_Log::warning(
				'Could not fully remove a directory.',
				array(
					'dir'    => $target_real,
					'reason' => $e->getMessage(),
				)
			);
		}

		@chmod( $target_real, 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return @rmdir( $target_real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
