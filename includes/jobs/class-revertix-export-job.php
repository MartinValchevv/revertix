<?php
/**
 * Export job: package the whole site (DB + files) into a single zip.
 *
 * Stages (each resumable):
 *   1. index_files  - resumable walk of the web root → files-index.txt
 *   2. dump_db      - row-chunked SQL dump of all site tables → database.sql
 *   3. manifest     - write manifest.json describing the package
 *   4. archive      - incrementally add metadata + every file into the zip
 *   5. finalize     - move the finished zip into the protected packages folder
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- direct filesystem calls are required for resumable, chunked backup/restore/import; WP_Filesystem cannot stream work across time-budgeted ticks.

/**
 * Builds a downloadable Revertix package.
 */
class Revertix_Export_Job extends Revertix_Job {

	/**
	 * {@inheritDoc}
	 */
	public static function type_slug() {
		return 'export';
	}

	/**
	 * {@inheritDoc}
	 */
	public function stages() {
		return array( 'index_files', 'dump_db', 'manifest', 'archive', 'finalize' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function step_labels() {
		return array(
			'index_files' => __( 'Scanning files', 'revertix' ),
			'dump_db'     => __( 'Exporting database', 'revertix' ),
			'manifest'    => __( 'Writing manifest', 'revertix' ),
			'archive'     => __( 'Building archive', 'revertix' ),
			'finalize'    => __( 'Finishing up', 'revertix' ),
		);
	}

	/**
	 * Files added to the zip per chunk. Filterable.
	 *
	 * @return int
	 */
	protected function files_per_chunk() {
		return (int) apply_filters( 'revertix_export_files_per_chunk', 200 );
	}

	/**
	 * Prepare initial data when the job is created.
	 *
	 * Reads the (already sanitized) options from state['args']:
	 *   - include_db    bool
	 *   - tables        string[]  (subset of site tables; empty = all)
	 *   - include_files bool
	 *   - file_areas    string[]  (area keys; empty = all)
	 */
	protected function init() {
		$args = wp_parse_args(
			$this->state['args'],
			array(
				'include_db'    => true,
				'tables'        => array(),
				'include_files' => true,
				'file_areas'    => array_keys( Revertix_Filesystem::file_areas() ),
				'exclude_names' => array(),
				'plugins_mode'  => 'all',
				'plugins'       => array(),
				'themes_mode'   => 'all',
				'themes'        => array(),
				// Where the finished zip is stored: 'package' (default) or 'backup'.
				'store'         => 'package',
				'label'         => '',
				'safety'        => false,
			)
		);

		// Resolve the database table set.
		$tables = array();
		if ( $args['include_db'] ) {
			$site_tables = Revertix_Database::site_tables();
			if ( ! empty( $args['tables'] ) ) {
				$tables = array_values( array_intersect( $site_tables, $args['tables'] ) );
			} else {
				$tables = $site_tables;
			}
		}

		// Resolve the file scan roots + carve-out exclusions.
		$roots    = array();
		$excludes = array();
		if ( $args['include_files'] ) {
			$resolved = Revertix_Filesystem::resolve_areas( $args['file_areas'] );
			$roots    = $resolved['roots'];
			$excludes = $resolved['excludes'];

			// Per-item selection: when an area is included but only some of its
			// plugins/themes are chosen, exclude the rest.
			if ( 'selected' === $args['plugins_mode'] && in_array( 'plugins', (array) $args['file_areas'], true ) ) {
				$excludes = array_merge( $excludes, Revertix_Filesystem::area_item_excludes( 'plugins', (array) $args['plugins'] ) );
			}
			if ( 'selected' === $args['themes_mode'] && in_array( 'themes', (array) $args['file_areas'], true ) ) {
				$excludes = array_merge( $excludes, Revertix_Filesystem::area_item_excludes( 'themes', (array) $args['themes'] ) );
			}
			// Never package an existing staging site into a live export.
			$excludes = array_merge( $excludes, Revertix_Staging::all_roots() );

			$excludes = array_values( array_unique( $excludes ) );
		}

		// The protective defaults (.git, node_modules, …) are ALWAYS enforced;
		// any client-supplied names can only add to them, never remove them. This
		// guarantees a removed default can't reintroduce read-only restore errors.
		$exclude_names = array_values(
			array_unique(
				array_merge( Revertix_Filesystem::excluded_names(), (array) $args['exclude_names'] )
			)
		);

		$this->state['data'] = array(
			// options
			'include_db'     => (bool) $args['include_db'],
			'include_files'  => (bool) $args['include_files'],
			'file_areas'     => array_values( (array) $args['file_areas'] ),
			'extra_excludes' => $excludes,
			'exclude_names'  => $exclude_names,
			// index_files
			'scan_stack'     => $roots,
			'files_total'    => 0,
			'bytes_total'    => 0,
			// dump_db
			'tables'         => $tables,
			'table_idx'      => 0,
			'row_offset'     => 0,
			'db_header'      => false,
			// archive
			'meta_added'     => false,
			'index_bytes'    => 0,
			'files_added'    => 0,
			// finalize / storage
			'store'          => ( 'backup' === $args['store'] ) ? 'backup' : 'package',
			'label'          => (string) $args['label'],
			'safety'         => (bool) $args['safety'],
			'package_path'   => '',
			'package_name'   => '',
			'package_size'   => 0,
		);

		Revertix_Log::info(
			'Export configured.',
			array(
				'include_db'    => $this->state['data']['include_db'] ? 'yes' : 'no',
				'include_files' => $this->state['data']['include_files'] ? 'yes' : 'no',
				'tables'        => count( $tables ),
				'file_areas'    => implode( ',', $this->state['data']['file_areas'] ),
				'plugins'       => 'selected' === $args['plugins_mode'] ? implode( ',', (array) $args['plugins'] ) : 'all',
				'themes'        => 'selected' === $args['themes_mode'] ? implode( ',', (array) $args['themes'] ) : 'all',
				'exclude_names' => implode( ',', $exclude_names ),
				'extra_excludes' => count( $excludes ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function step() {
		switch ( $this->state['stage'] ) {
			case 'index_files':
				$this->step_index_files();
				break;
			case 'dump_db':
				$this->step_dump_db();
				break;
			case 'manifest':
				$this->step_manifest();
				break;
			case 'archive':
				$this->step_archive();
				break;
			case 'finalize':
				$this->step_finalize();
				break;
			default:
				$this->complete();
		}
	}

	/* ---------------------------------------------------------------------
	 * Stage 1: resumable file index
	 * ------------------------------------------------------------------- */

	/**
	 * Walk the tree in chunks, appending discovered files to the index file.
	 */
	protected function step_index_files() {
		// Files excluded (or no roots to scan) → nothing to index.
		if ( empty( $this->state['data']['include_files'] ) || empty( $this->state['data']['scan_stack'] ) ) {
			$this->advance_stage();
			return;
		}

		$index_path = $this->dir() . 'files-index.txt';
		$fh         = fopen( $index_path, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $fh ) {
			$this->fail( __( 'Could not write the file index.', 'revertix' ) );
			return;
		}

		$result = Revertix_Filesystem::scan_step(
			$this->state['data']['scan_stack'],
			$fh,
			function () {
				return $this->time_left();
			},
			$this->state['data']['extra_excludes'],
			isset( $this->state['data']['exclude_names'] ) ? $this->state['data']['exclude_names'] : null
		);

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->state['data']['scan_stack']   = $result['stack'];
		$this->state['data']['files_total'] += $result['files'];
		$this->state['data']['bytes_total'] += $result['bytes'];

		$this->state['message'] = sprintf(
			/* translators: %s: number of files. */
			__( 'Scanning files… %s found', 'revertix' ),
			number_format_i18n( $this->state['data']['files_total'] )
		);

		if ( $result['done'] ) {
			$this->advance_stage();
		}
	}

	/* ---------------------------------------------------------------------
	 * Stage 2: database dump
	 * ------------------------------------------------------------------- */

	/**
	 * Dump tables in row-sized chunks into database.sql.
	 */
	protected function step_dump_db() {
		// Database excluded (or no tables) → skip this stage entirely.
		if ( empty( $this->state['data']['include_db'] ) || empty( $this->state['data']['tables'] ) ) {
			$this->advance_stage();
			return;
		}

		$sql_path = $this->dir() . 'database.sql';
		$fh       = fopen( $sql_path, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $fh ) {
			$this->fail( __( 'Could not write the database dump.', 'revertix' ) );
			return;
		}

		if ( ! $this->state['data']['db_header'] ) {
			fwrite( $fh, Revertix_Database::dump_header() );
			$this->state['data']['db_header'] = true;
		}

		$tables = $this->state['data']['tables'];
		$total  = count( $tables );

		// Work until time runs low or the dump is finished.
		while ( $this->time_left() > 2.0 ) {
			$idx = $this->state['data']['table_idx'];

			if ( $idx >= $total ) {
				fwrite( $fh, Revertix_Database::dump_footer() );
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$this->advance_stage();
				return;
			}

			$table = $tables[ $idx ];

			// Write the CREATE statement once, at the start of each table.
			if ( 0 === $this->state['data']['row_offset'] ) {
				fwrite( $fh, Revertix_Database::create_statement( $table ) );
			}

			$chunk = Revertix_Database::dump_rows(
				$table,
				$this->state['data']['row_offset'],
				Revertix_Database::rows_per_chunk()
			);

			if ( '' !== $chunk['sql'] ) {
				fwrite( $fh, $chunk['sql'] );
			}

			if ( $chunk['rows'] < Revertix_Database::rows_per_chunk() ) {
				// Table finished - move to the next one.
				$this->state['data']['table_idx']  = $idx + 1;
				$this->state['data']['row_offset'] = 0;
			} else {
				$this->state['data']['row_offset'] += $chunk['rows'];
			}

			$this->set_progress( $this->state['data']['table_idx'], $total );
			$this->state['message'] = sprintf(
				/* translators: 1: current table number, 2: total tables. */
				__( 'Exporting database… table %1$d of %2$d', 'revertix' ),
				min( $this->state['data']['table_idx'] + 1, $total ),
				$total
			);
		}

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/* ---------------------------------------------------------------------
	 * Stage 3: manifest
	 * ------------------------------------------------------------------- */

	/**
	 * Write manifest.json describing the package.
	 */
	protected function step_manifest() {
		$manifest = Revertix_Manifest::build( $this->state['data']['tables'] );
		file_put_contents( $this->dir() . 'manifest.json', Revertix_Manifest::to_json( $manifest ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->state['message'] = __( 'Building package…', 'revertix' );
		$this->advance_stage();
	}

	/* ---------------------------------------------------------------------
	 * Stage 4: archive
	 * ------------------------------------------------------------------- */

	/**
	 * Add metadata files, then every site file, into the zip in batches.
	 */
	protected function step_archive() {
		$zip_path = $this->dir() . 'package.zip';

		// First pass: add the small metadata files at the archive root.
		if ( ! $this->state['data']['meta_added'] ) {
			$meta = array();
			foreach ( array( 'database.sql', 'manifest.json', 'files-index.txt' ) as $name ) {
				$abs = $this->dir() . $name;
				if ( is_file( $abs ) ) {
					$meta[] = array(
						'abs'   => $abs,
						'entry' => $name,
					);
				}
			}
			Revertix_Zip::add_files( $zip_path, $meta );
			$this->state['data']['meta_added'] = true;
		}

		$index_path = $this->dir() . 'files-index.txt';

		// No file index means files were excluded - the archive is metadata-only.
		if ( ! is_file( $index_path ) ) {
			$this->advance_stage();
			return;
		}

		$fh = fopen( $index_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $fh ) {
			$this->fail( __( 'Could not read the file index.', 'revertix' ) );
			return;
		}

		fseek( $fh, (int) $this->state['data']['index_bytes'] );

		$root          = trailingslashit( Revertix_Filesystem::root() );
		$batch         = array();
		$per_chunk     = $this->files_per_chunk();
		$files_total   = max( 1, (int) $this->state['data']['files_total'] );

		while ( $this->time_left() > 3.0 ) {
			$line = fgets( $fh );

			if ( false === $line ) {
				// End of index - flush remaining batch and finish the stage.
				if ( ! empty( $batch ) ) {
					Revertix_Zip::add_files( $zip_path, $batch );
					$this->state['data']['files_added'] += count( $batch );
				}
				$this->state['data']['index_bytes'] = ftell( $fh );
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$this->advance_stage();
				return;
			}

			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}

			$parts = explode( "\t", $line );
			$rel   = $parts[0];

			$batch[] = array(
				'abs'   => $root . $rel,
				'entry' => 'files/' . $rel,
			);

			if ( count( $batch ) >= $per_chunk ) {
				Revertix_Zip::add_files( $zip_path, $batch );
				$this->state['data']['files_added'] += count( $batch );
				$this->state['data']['index_bytes']  = ftell( $fh );
				$batch                                = array();

				$this->set_progress( $this->state['data']['files_added'], $files_total );
				$this->state['message'] = sprintf(
					/* translators: 1: files archived, 2: total files. */
					__( 'Archiving files… %1$s of %2$s', 'revertix' ),
					number_format_i18n( $this->state['data']['files_added'] ),
					number_format_i18n( $this->state['data']['files_total'] )
				);
			}
		}

		// Time budget hit mid-batch: persist what we have and record position.
		if ( ! empty( $batch ) ) {
			Revertix_Zip::add_files( $zip_path, $batch );
			$this->state['data']['files_added'] += count( $batch );
			$this->state['data']['index_bytes']  = ftell( $fh );
		}

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->set_progress( $this->state['data']['files_added'], $files_total );
	}

	/* ---------------------------------------------------------------------
	 * Stage 5: finalize
	 * ------------------------------------------------------------------- */

	/**
	 * Move the finished zip into the protected packages directory.
	 */
	protected function step_finalize() {
		$zip_path = $this->dir() . 'package.zip';

		if ( ! is_file( $zip_path ) ) {
			$this->fail( __( 'The package could not be created.', 'revertix' ) );
			return;
		}

		$dest_dir = $this->package_dir();
		wp_mkdir_p( $dest_dir );
		Revertix_Storage::harden( Revertix_Storage::sub_dir( $this->store_sub() ) );

		$name = $this->package_filename();
		$dest = $dest_dir . $name;

		if ( ! @rename( $zip_path, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$this->fail( __( 'Could not move the finished package.', 'revertix' ) );
			return;
		}

		$this->state['data']['package_path'] = $dest;
		$this->state['data']['package_name'] = $name;
		$this->state['data']['package_size'] = (int) filesize( $dest );

		$parts = array(
			'database' => (bool) $this->state['data']['include_db'],
			'files'    => (bool) $this->state['data']['include_files'],
			'tables'   => count( $this->state['data']['tables'] ),
			'areas'    => $this->state['data']['include_files'] ? $this->state['data']['file_areas'] : array(),
		);

		// Record in the right registry so the list survives page reloads.
		if ( 'backups' === $this->store_sub() ) {
			$is_safety = (bool) $this->state['data']['safety'];

			Revertix_Backup::add(
				array(
					'id'      => $this->id,
					'name'    => '' !== $this->state['data']['label'] ? $this->state['data']['label'] : $name,
					'file'    => $name,
					'path'    => $dest,
					'size'    => (int) filesize( $dest ),
					'created' => time(),
					'safety'  => $is_safety,
					'parts'   => $parts,
				)
			);

			// Enforce the safety-backup retention limit once the new one is recorded.
			if ( $is_safety ) {
				Revertix_Backup::prune_safety();
			}

			// Routine "backup finished" alert (opt-in, Pro). Safety snapshots are
			// part of other operations, so they don't trigger a status email.
			if ( ! $is_safety ) {
				Revertix_Notify::send(
					'status',
					__( 'Backup completed', 'revertix' ),
					sprintf(
						/* translators: 1: backup name, 2: human-readable size. */
						__( 'The backup “%1$s” finished successfully (%2$s).', 'revertix' ),
						$name,
						size_format( (int) $this->state['data']['package_size'] )
					)
				);
			}
		} else {
			Revertix_Package_Registry::add(
				array(
					'id'      => $this->id,
					'name'    => $name,
					'path'    => $dest,
					'size'    => (int) filesize( $dest ),
					'created' => time(),
					'parts'   => $parts,
				)
			);
		}

		Revertix_Log::info(
			'Export finished.',
			array(
				'package'    => $name,
				'size_bytes' => (int) $this->state['data']['package_size'],
				'files'      => (int) $this->state['data']['files_added'],
			)
		);

		$this->complete( __( 'Export complete.', 'revertix' ) );
	}

	/**
	 * Destination directory for finished packages (token subfolder).
	 *
	 * @return string
	 */
	protected function package_dir() {
		return Revertix_Storage::sub_dir( $this->store_sub() ) . $this->id . '/';
	}

	/**
	 * Alert (opt-in, Pro) when a backup job fails, on top of the base logging.
	 *
	 * @param string $message Error message.
	 */
	protected function fail( $message ) {
		parent::fail( $message );

		if ( 'backups' === $this->store_sub() ) {
			Revertix_Notify::send(
				'error',
				__( 'Backup failed', 'revertix' ),
				sprintf(
					/* translators: %s: error detail. */
					__( 'A Revertix backup failed: %s', 'revertix' ),
					$message
				)
			);
		}
	}

	/**
	 * The storage subdirectory for this job's output ('packages' or 'backups').
	 *
	 * @return string
	 */
	protected function store_sub() {
		return ( isset( $this->state['data']['store'] ) && 'backup' === $this->state['data']['store'] ) ? 'backups' : 'packages';
	}

	/**
	 * Build a human-friendly package filename.
	 *
	 * @return string
	 */
	protected function package_filename() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? preg_replace( '/[^a-z0-9\-]/i', '-', $host ) : 'site';
		return 'revertix-' . $host . '-' . gmdate( 'Ymd-His' ) . '.zip';
	}

	/**
	 * Expose the download link + size once complete.
	 *
	 * @return array
	 */
	protected function result() {
		if ( self::STATUS_COMPLETE !== $this->state['status'] ) {
			return array();
		}

		// Build the URL with add_query_arg (raw "&"), NOT wp_nonce_url - the
		// latter HTML-escapes ampersands to "&amp;", which break the query
		// string when the URL is assigned to an element's href in JavaScript.
		$download_url = add_query_arg(
			array(
				'action'   => 'revertix_download',
				'job'      => $this->id,
				'_wpnonce' => wp_create_nonce( 'revertix_download' ),
			),
			admin_url( 'admin-post.php' )
		);

		$parts = array();
		if ( $this->state['data']['include_db'] ) {
			$parts[] = __( 'Database', 'revertix' );
		}
		if ( $this->state['data']['include_files'] ) {
			$parts[] = __( 'Files', 'revertix' );
		}

		return array(
			'id'           => $this->id,
			'download_url' => esc_url_raw( $download_url ),
			'filename'     => $this->state['data']['package_name'],
			'size'         => size_format( $this->state['data']['package_size'] ),
			'created'      => __( 'Just now', 'revertix' ),
			'parts_label'  => implode( ' + ', $parts ),
		);
	}

	/**
	 * Absolute path of the finished package (used by the download handler).
	 *
	 * @return string
	 */
	public function package_path() {
		return (string) $this->state['data']['package_path'];
	}
}
