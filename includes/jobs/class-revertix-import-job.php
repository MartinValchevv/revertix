<?php
/**
 * Import job: restore a Revertix package onto this installation.
 *
 * Stages (each resumable):
 *   1. unzip         - extract the package into a scratch folder, in batches
 *   2. read_manifest - load manifest.json; build the URL/path replacement map
 *   3. import_db     - replay database.sql, rewriting the table prefix
 *   4. restore_files - copy package files back into the site (protected paths
 *                      such as wp-config.php and Revertix itself are skipped)
 *   5. search_replace- serialize-safe rewrite of old URLs/paths across the DB
 *   6. finalize      - clean up scratch and flush caches
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restores a package built by Revertix_Export_Job.
 */
class Revertix_Import_Job extends Revertix_Job {

	/**
	 * {@inheritDoc}
	 */
	public static function type_slug() {
		return 'import';
	}

	/**
	 * {@inheritDoc}
	 */
	public function stages() {
		return array( 'unzip', 'read_manifest', 'import_db', 'restore_files', 'search_replace', 'finalize' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function step_labels() {
		return array(
			'unzip'          => __( 'Extracting package', 'revertix' ),
			'read_manifest'  => __( 'Reading package', 'revertix' ),
			'import_db'      => __( 'Importing database', 'revertix' ),
			'restore_files'  => __( 'Restoring files', 'revertix' ),
			'search_replace' => __( 'Updating URLs', 'revertix' ),
			'finalize'       => __( 'Finishing up', 'revertix' ),
		);
	}

	/**
	 * Entries extracted per chunk.
	 *
	 * @return int
	 */
	protected function unzip_per_chunk() {
		return (int) apply_filters( 'revertix_import_unzip_per_chunk', 300 );
	}

	/**
	 * Files restored per chunk.
	 *
	 * @return int
	 */
	protected function restore_per_chunk() {
		return (int) apply_filters( 'revertix_import_restore_per_chunk', 200 );
	}

	/**
	 * Rows scanned per search-replace chunk (CPU-heavy, so smaller).
	 *
	 * @return int
	 */
	protected function sr_rows_per_chunk() {
		return (int) apply_filters( 'revertix_import_sr_rows_per_chunk', 150 );
	}

	/**
	 * Prepare state from args: state['args']['source_path'] is the package zip.
	 */
	protected function init() {
		$this->state['data'] = array(
			'source_path'   => isset( $this->state['args']['source_path'] ) ? $this->state['args']['source_path'] : '',
			'extract_dir'   => $this->dir() . 'extract/',
			// unzip
			'unzip_idx'     => 0,
			'unzip_total'   => 0,
			// manifest-derived
			'old_prefix'    => '',
			'new_prefix'    => '',
			'has_db'        => false,
			'has_files'     => false,
			'search'        => array(),
			'replace'       => array(),
			'sr_tables'     => array(),
			// import_db
			'db_offset'     => 0,
			// restore_files
			'restore_bytes' => 0,
			'restored'      => 0,
			'restore_failed' => 0,
			// search_replace
			'sr_table_idx'  => 0,
			'sr_row_offset' => 0,
			'sr_pk'         => array(),
			'sr_changed'    => 0,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function step() {
		switch ( $this->state['stage'] ) {
			case 'unzip':
				$this->step_unzip();
				break;
			case 'read_manifest':
				$this->step_read_manifest();
				break;
			case 'import_db':
				$this->step_import_db();
				break;
			case 'restore_files':
				$this->step_restore_files();
				break;
			case 'search_replace':
				$this->step_search_replace();
				break;
			case 'finalize':
				$this->step_finalize();
				break;
			default:
				$this->complete();
		}
	}

	/* ---------------------------------------------------------------------
	 * Stage 1: unzip
	 * ------------------------------------------------------------------- */

	/**
	 * Extract the package in batches.
	 */
	protected function step_unzip() {
		$source = $this->state['data']['source_path'];

		if ( '' === $source || ! is_file( $source ) ) {
			$this->fail( __( 'The package file could not be found.', 'revertix' ) );
			return;
		}

		$dest = $this->state['data']['extract_dir'];
		wp_mkdir_p( $dest );

		while ( $this->time_left() > 3.0 ) {
			$result = Revertix_Zip::extract_batch(
				$source,
				$dest,
				$this->state['data']['unzip_idx'],
				$this->unzip_per_chunk()
			);

			$this->state['data']['unzip_total'] = $result['total'];
			$this->state['data']['unzip_idx']   = $result['next'];

			$this->set_progress( $result['next'], max( 1, $result['total'] ) );
			$this->state['message'] = sprintf(
				/* translators: 1: extracted entries, 2: total entries. */
				__( 'Extracting package… %1$s of %2$s', 'revertix' ),
				number_format_i18n( $result['next'] ),
				number_format_i18n( $result['total'] )
			);

			if ( $result['next'] >= $result['total'] ) {
				$this->advance_stage();
				return;
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Stage 2: read manifest, build replacement map
	 * ------------------------------------------------------------------- */

	/**
	 * Load the manifest and compute the URL/path replacements.
	 */
	protected function step_read_manifest() {
		$manifest_path = $this->state['data']['extract_dir'] . 'manifest.json';

		if ( ! is_file( $manifest_path ) ) {
			$this->fail( __( 'This package is missing its manifest and cannot be imported.', 'revertix' ) );
			return;
		}

		$manifest = Revertix_Manifest::from_json( (string) file_get_contents( $manifest_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( null === $manifest ) {
			$this->fail( __( 'The package manifest is invalid.', 'revertix' ) );
			return;
		}

		global $wpdb;
		$old = isset( $manifest['site'] ) ? $manifest['site'] : array();

		$this->state['data']['old_prefix'] = isset( $manifest['database']['table_prefix'] ) ? $manifest['database']['table_prefix'] : $wpdb->prefix;
		$this->state['data']['new_prefix'] = $wpdb->prefix;
		$this->state['data']['has_db']     = is_file( $this->state['data']['extract_dir'] . 'database.sql' );
		$this->state['data']['has_files']  = is_file( $this->state['data']['extract_dir'] . 'files-index.txt' );

		// Build the from→to map: URLs first, then filesystem paths.
		$uploads = wp_upload_dir();
		$map     = array();

		if ( ! empty( $old['home_url'] ) ) {
			$map[ $old['home_url'] ] = home_url();
		}
		if ( ! empty( $old['site_url'] ) ) {
			$map[ $old['site_url'] ] = site_url();
		}
		if ( ! empty( $old['uploads_url'] ) ) {
			$map[ $old['uploads_url'] ] = $uploads['baseurl'];
		}
		if ( ! empty( $old['abspath'] ) ) {
			$map[ $old['abspath'] ] = Revertix_Filesystem::normalize( Revertix_Filesystem::root() );
		}
		if ( ! empty( $old['content_dir'] ) ) {
			$map[ $old['content_dir'] ] = Revertix_Filesystem::normalize( Revertix_Filesystem::content_dir() );
		}
		if ( ! empty( $old['uploads_dir'] ) ) {
			$map[ $old['uploads_dir'] ] = Revertix_Filesystem::normalize( $uploads['basedir'] );
		}

		list( $search, $replace )         = Revertix_Search_Replace::prepare_map( $map );
		$this->state['data']['search']    = $search;
		$this->state['data']['replace']   = $replace;

		// Limit the search-replace to the tables we actually imported.
		$tables = isset( $manifest['database']['tables'] ) ? (array) $manifest['database']['tables'] : array();
		$mapped = array();
		foreach ( $tables as $t ) {
			$mapped[] = $this->state['data']['old_prefix'] === $this->state['data']['new_prefix']
				? $t
				: preg_replace( '/^' . preg_quote( $this->state['data']['old_prefix'], '/' ) . '/', $this->state['data']['new_prefix'], $t, 1 );
		}
		$this->state['data']['sr_tables'] = array_values( array_intersect( $mapped, Revertix_Database::site_tables() ) );

		$db_path = $this->state['data']['extract_dir'] . 'database.sql';

		$versions = isset( $manifest['versions'] ) ? (array) $manifest['versions'] : array();

		Revertix_Log::info(
			'Import package read.',
			array(
				'package'      => basename( (string) $this->state['data']['source_path'] ),
				'format'       => isset( $manifest['format'] ) ? $manifest['format'] : '?',
				'created'      => isset( $manifest['created'] ) ? $manifest['created'] : '?',
				'from_revertix' => isset( $versions['revertix'] ) ? $versions['revertix'] : '?',
				'from_wp'      => isset( $versions['wp'] ) ? $versions['wp'] : '?',
				'from_php'     => isset( $versions['php'] ) ? $versions['php'] : '?',
				'old_prefix'   => $this->state['data']['old_prefix'],
				'new_prefix'   => $this->state['data']['new_prefix'],
				'has_db'       => $this->state['data']['has_db'] ? 'yes' : 'no',
				'has_files'    => $this->state['data']['has_files'] ? 'yes' : 'no',
				'db_bytes'     => $this->state['data']['has_db'] && is_file( $db_path ) ? (int) filesize( $db_path ) : 0,
				'sr_tables'    => count( $this->state['data']['sr_tables'] ),
				'replacements' => count( $this->state['data']['search'] ),
			)
		);

		$this->state['message'] = __( 'Reading package…', 'revertix' );
		$this->advance_stage();
	}

	/* ---------------------------------------------------------------------
	 * Stage 3: import database
	 * ------------------------------------------------------------------- */

	/**
	 * Replay database.sql in resumable chunks.
	 */
	protected function step_import_db() {
		if ( empty( $this->state['data']['has_db'] ) ) {
			$this->advance_stage();
			return;
		}

		$sql_path = $this->state['data']['extract_dir'] . 'database.sql';
		$fh       = fopen( $sql_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $fh ) {
			$this->fail( __( 'Could not read the database dump from the package.', 'revertix' ) );
			return;
		}

		fseek( $fh, (int) $this->state['data']['db_offset'] );

		$result = Revertix_Database::import_sql(
			$fh,
			function () {
				return $this->time_left();
			},
			$this->state['data']['old_prefix'],
			$this->state['data']['new_prefix']
		);

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->state['data']['db_offset'] = $result['offset'];

		$size = (int) filesize( $sql_path );
		$this->set_progress( $result['offset'], max( 1, $size ) );
		$this->state['message'] = __( 'Importing database…', 'revertix' );

		if ( $result['done'] ) {
			$this->advance_stage();
		}
	}

	/* ---------------------------------------------------------------------
	 * Stage 4: restore files
	 * ------------------------------------------------------------------- */

	/**
	 * Copy extracted files back into the site root, skipping protected paths.
	 */
	protected function step_restore_files() {
		if ( empty( $this->state['data']['has_files'] ) ) {
			$this->advance_stage();
			return;
		}

		$index_path = $this->state['data']['extract_dir'] . 'files-index.txt';
		$files_root = $this->state['data']['extract_dir'] . 'files/';
		$site_root  = trailingslashit( Revertix_Filesystem::root() );

		$fh = fopen( $index_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $fh ) {
			$this->fail( __( 'Could not read the file list from the package.', 'revertix' ) );
			return;
		}

		fseek( $fh, (int) $this->state['data']['restore_bytes'] );

		$processed = 0;
		$per_chunk = $this->restore_per_chunk();

		while ( $this->time_left() > 3.0 ) {
			$line = fgets( $fh );

			if ( false === $line ) {
				$this->state['data']['restore_bytes'] = ftell( $fh );
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$this->advance_stage();
				return;
			}

			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}

			$parts = explode( "\t", $line );
			$rel   = Revertix_Filesystem::safe_relative( $parts[0] );

			// Reject traversal / absolute / unsafe entries from the (untrusted)
			// package index before they become a source or destination path.
			if ( false === $rel ) {
				continue;
			}

			if ( ! Revertix_Filesystem::is_restore_excluded( $rel ) ) {
				$source = $files_root . $rel;
				if ( is_file( $source ) ) {
					if ( Revertix_Filesystem::copy_file( $source, $site_root . $rel ) ) {
						++$this->state['data']['restored'];
					} else {
						++$this->state['data']['restore_failed'];
						// Log the first handful so a permissions/disk problem is
						// visible without flooding the log on a large site.
						if ( $this->state['data']['restore_failed'] <= 20 ) {
							$last  = error_get_last();
							$why   = ( $last && ! empty( $last['message'] ) ) ? $last['message'] : 'unknown error';
							Revertix_Log::warning(
								'Could not restore a file.',
								array(
									'file'   => $rel,
									'reason' => $why,
								)
							);
						}
					}
				}
			}

			++$processed;

			if ( $processed >= $per_chunk ) {
				$this->state['data']['restore_bytes'] = ftell( $fh );
				$this->state['message']               = sprintf(
					/* translators: %s: number of files restored. */
					__( 'Restoring files… %s copied', 'revertix' ),
					number_format_i18n( $this->state['data']['restored'] )
				);
				$processed = 0;
			}
		}

		$this->state['data']['restore_bytes'] = ftell( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/* ---------------------------------------------------------------------
	 * Stage 5: serialize-safe search & replace
	 * ------------------------------------------------------------------- */

	/**
	 * Rewrite old URLs/paths across the imported tables.
	 */
	protected function step_search_replace() {
		$tables = $this->state['data']['sr_tables'];

		// Nothing to replace (e.g. same site/paths) or no tables → skip.
		if ( empty( $this->state['data']['search'] ) || empty( $tables ) ) {
			$this->advance_stage();
			return;
		}

		global $wpdb;
		$search  = $this->state['data']['search'];
		$replace = $this->state['data']['replace'];
		$total   = count( $tables );

		while ( $this->time_left() > 2.5 ) {
			$idx = $this->state['data']['sr_table_idx'];

			if ( $idx >= $total ) {
				$this->advance_stage();
				return;
			}

			$table = $tables[ $idx ];

			// Resolve the primary key once per table.
			if ( 0 === $this->state['data']['sr_row_offset'] || ! isset( $this->state['data']['sr_pk'][ $table ] ) ) {
				$this->state['data']['sr_pk'][ $table ] = Revertix_Database::primary_key( $table );
			}
			$pk = $this->state['data']['sr_pk'][ $table ];

			$rows = Revertix_Database::fetch_rows(
				$table,
				$this->state['data']['sr_row_offset'],
				$this->sr_rows_per_chunk()
			);

			foreach ( $rows as $row ) {
				$set = array();
				foreach ( $row as $col => $value ) {
					if ( ! is_string( $value ) || '' === $value ) {
						continue;
					}
					$new = Revertix_Search_Replace::apply( $value, $search, $replace, false );
					if ( $new !== $value ) {
						$set[ $col ] = $new;
					}
				}

				if ( ! empty( $set ) ) {
					$where = $this->row_where( $row, $pk );
					if ( ! empty( $where ) ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->update( $table, $set, $where );
						++$this->state['data']['sr_changed'];
					}
				}
			}

			if ( count( $rows ) < $this->sr_rows_per_chunk() ) {
				// Table finished.
				$this->state['data']['sr_table_idx']  = $idx + 1;
				$this->state['data']['sr_row_offset'] = 0;
			} else {
				$this->state['data']['sr_row_offset'] += count( $rows );
			}

			$this->set_progress( $this->state['data']['sr_table_idx'], $total );
			$this->state['message'] = sprintf(
				/* translators: 1: current table number, 2: total tables. */
				__( 'Updating URLs and paths… table %1$d of %2$d', 'revertix' ),
				min( $this->state['data']['sr_table_idx'] + 1, $total ),
				$total
			);
		}
	}

	/**
	 * Build a WHERE clause for a row: prefer the primary key, otherwise fall
	 * back to all non-null columns.
	 *
	 * @param array<string,mixed> $row Row data.
	 * @param string[]            $pk  Primary-key columns.
	 * @return array<string,mixed>
	 */
	protected function row_where( array $row, array $pk ) {
		if ( ! empty( $pk ) ) {
			$where = array();
			foreach ( $pk as $col ) {
				if ( array_key_exists( $col, $row ) ) {
					$where[ $col ] = $row[ $col ];
				}
			}
			return $where;
		}

		// No primary key: match on all non-null columns (best effort).
		return array_filter(
			$row,
			static function ( $value ) {
				return null !== $value;
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * Stage 6: finalize
	 * ------------------------------------------------------------------- */

	/**
	 * Clean up scratch files and flush caches.
	 */
	protected function step_finalize() {
		// Remove the extracted copy (guarded to this job's tmp dir).
		Revertix_Filesystem::delete_tree(
			rtrim( $this->state['data']['extract_dir'], '/' ),
			rtrim( $this->dir(), '/' )
		);

		wp_cache_flush();

		Revertix_Log::info(
			'Import finished.',
			array(
				'files_restored' => (int) $this->state['data']['restored'],
				'files_failed'   => (int) $this->state['data']['restore_failed'],
				'rows_rewritten' => (int) $this->state['data']['sr_changed'],
			)
		);

		$this->complete( __( 'Import complete.', 'revertix' ) );
	}

	/**
	 * Final summary for the UI.
	 *
	 * @return array
	 */
	protected function result() {
		if ( self::STATUS_COMPLETE !== $this->state['status'] ) {
			return array();
		}

		return array(
			'restored'   => (int) $this->state['data']['restored'],
			'sr_changed' => (int) $this->state['data']['sr_changed'],
		);
	}
}
