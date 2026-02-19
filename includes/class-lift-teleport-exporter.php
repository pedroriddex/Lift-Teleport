<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lift_Teleport_Exporter {
	const REST_NAMESPACE = 'lift-teleport/v1';
	const STATE_OPTION_PREFIX = 'lift_teleport_export_state_';
	const MAX_BATCH_SECONDS = 8;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/export/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start_export' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/export/continue',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'continue_export' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/export/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/export/download',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_export' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Start a new export.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_export( WP_REST_Request $request ) {
		$chunk_size  = max( 256 * 1024, absint( $request->get_param( 'chunk_size' ) ) );
		$batch_items = max( 1, absint( $request->get_param( 'batch_items' ) ) );

		$export_id = gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );
		$work_dir  = trailingslashit( $this->get_exports_base_dir() ) . $export_id;

		wp_mkdir_p( $work_dir . '/chunks' );
		wp_mkdir_p( $work_dir . '/sql' );

		$state = array(
			'export_id'            => $export_id,
			'status'               => 'running',
			'phase'                => 'discover_files',
			'created_at'           => time(),
			'updated_at'           => time(),
			'chunk_size'           => $chunk_size,
			'batch_items'          => $batch_items,
			'work_dir'             => $work_dir,
			'file_list_path'       => $work_dir . '/file-list.ndjson',
			'file_cursor'          => 0,
			'file_total'           => 0,
			'current_file'         => null,
			'current_file_offset'  => 0,
			'fs_chunk_index'       => 0,
			'fs_chunks'            => array(),
			'db_tables'            => array_values( $this->get_database_tables() ),
			'db_table_index'       => 0,
			'db_row_offset'        => 0,
			'db_chunk_index'       => 0,
			'db_current_chunk'     => null,
			'db_current_chunk_size'=> 0,
			'db_chunks'            => array(),
			'manifest'             => null,
			'final_file'           => null,
			'global_checksum'      => null,
			'last_error'           => null,
		);

		$this->save_state( $state );

		return rest_ensure_response(
			array(
				'success'   => true,
				'export_id' => $export_id,
				'state'     => $this->public_state( $state ),
			)
		);
	}

	/**
	 * Continue processing a queued export batch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function continue_export( WP_REST_Request $request ) {
		$export_id = sanitize_text_field( (string) $request->get_param( 'export_id' ) );
		$state     = $this->get_state( $export_id );

		if ( ! $state ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Export not found.' ), 404 );
		}

		if ( 'failed' === $state['status'] || 'completed' === $state['status'] ) {
			return rest_ensure_response( array( 'success' => true, 'state' => $this->public_state( $state ) ) );
		}

		$started = microtime( true );
		$items   = 0;

		try {
			while ( $items < $state['batch_items'] && ( microtime( true ) - $started ) < self::MAX_BATCH_SECONDS ) {
				switch ( $state['phase'] ) {
					case 'discover_files':
						$this->discover_files( $state );
						break;
					case 'dump_database':
						$this->dump_database_batch( $state );
						break;
					case 'package_filesystem':
						$this->package_files_batch( $state );
						break;
					case 'finalize_manifest':
						$this->finalize_manifest( $state );
						break 2;
					default:
						throw new RuntimeException( 'Unknown phase: ' . $state['phase'] );
				}

				$items++;

				if ( 'completed' === $state['status'] ) {
					break;
				}
			}
		} catch ( Exception $exception ) {
			$state['status']     = 'failed';
			$state['last_error'] = $exception->getMessage();
		}

		$state['updated_at'] = time();
		$this->save_state( $state );

		return rest_ensure_response(
			array(
				'success' => 'failed' !== $state['status'],
				'state'   => $this->public_state( $state ),
			)
		);
	}

	/**
	 * Get export status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_status( WP_REST_Request $request ) {
		$export_id = sanitize_text_field( (string) $request->get_param( 'export_id' ) );
		$state     = $this->get_state( $export_id );

		if ( ! $state ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Export not found.' ), 404 );
		}

		return rest_ensure_response( array( 'success' => true, 'state' => $this->public_state( $state ) ) );
	}

	/**
	 * Download export file.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function download_export( WP_REST_Request $request ) {
		$export_id = sanitize_text_field( (string) $request->get_param( 'export_id' ) );
		$state     = $this->get_state( $export_id );

		if ( ! $state || empty( $state['final_file'] ) || ! file_exists( $state['final_file'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Export file not available.' ), 404 );
		}

		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $state['final_file'] ) . '"' );
		header( 'Content-Length: ' . filesize( $state['final_file'] ) );
		readfile( $state['final_file'] );
		exit;
	}

	/**
	 * Discover files and persist list.
	 *
	 * @param array<string,mixed> $state Export state.
	 */
	private function discover_files( array &$state ) {
		if ( file_exists( $state['file_list_path'] ) ) {
			$state['phase'] = 'dump_database';
			return;
		}

		$roots = array(
			WP_CONTENT_DIR,
			ABSPATH . 'wp-config.php',
		);

		$out = fopen( $state['file_list_path'], 'wb' );
		if ( ! $out ) {
			throw new RuntimeException( 'Unable to create file list.' );
		}

		$total = 0;
		foreach ( $roots as $root ) {
			if ( is_file( $root ) ) {
				$size = filesize( $root );
				fwrite( $out, wp_json_encode( array( 'path' => $this->relative_path( $root ), 'size' => $size ) ) . "\n" );
				$total++;
				continue;
			}

			if ( ! is_dir( $root ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file_info ) {
				$file_path = $file_info->getPathname();

				if ( false !== strpos( $file_path, '/lift-teleport/exports/' ) ) {
					continue;
				}

				if ( ! $file_info->isFile() || ! $file_info->isReadable() ) {
					continue;
				}

				$size = $file_info->getSize();
				fwrite( $out, wp_json_encode( array( 'path' => $this->relative_path( $file_path ), 'size' => $size ) ) . "\n" );
				$total++;
			}
		}

		fclose( $out );

		$state['file_total'] = $total;
		$state['phase']      = 'dump_database';
	}

	/**
	 * Dump database in SQL chunks.
	 *
	 * @param array<string,mixed> $state Export state.
	 */
	private function dump_database_batch( array &$state ) {
		global $wpdb;

		if ( $state['db_table_index'] >= count( $state['db_tables'] ) ) {
			if ( ! empty( $state['db_current_chunk'] ) ) {
				$this->close_db_chunk( $state );
			}
			$state['phase'] = 'package_filesystem';
			return;
		}

		$table = $state['db_tables'][ $state['db_table_index'] ];
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", 100, $state['db_row_offset'] ), ARRAY_A );

		if ( empty( $rows ) ) {
			$state['db_table_index']++;
			$state['db_row_offset'] = 0;
			return;
		}

		if ( empty( $state['db_current_chunk'] ) ) {
			$this->open_db_chunk( $state );
		}

		foreach ( $rows as $row ) {
			$statement = $this->build_insert_statement( $table, $row );
			$this->append_db_sql( $state, $statement );
		}

		$state['db_row_offset'] += count( $rows );
	}

	/**
	 * Package filesystem into chunks with streamed reads.
	 *
	 * @param array<string,mixed> $state Export state.
	 */
	private function package_files_batch( array &$state ) {
		if ( $state['file_cursor'] >= $state['file_total'] ) {
			$state['phase'] = 'finalize_manifest';
			return;
		}

		$entry = $this->get_file_entry_at_index( $state['file_list_path'], $state['file_cursor'] );
		if ( ! $entry ) {
			$state['file_cursor'] = $state['file_total'];
			$state['phase']       = 'finalize_manifest';
			return;
		}

		$absolute_path = ABSPATH . ltrim( $entry['path'], '/' );
		if ( ! file_exists( $absolute_path ) ) {
			$state['file_cursor']++;
			$state['current_file_offset'] = 0;
			return;
		}

		$size   = (int) $entry['size'];
		$offset = (int) $state['current_file_offset'];

		if ( $offset >= $size ) {
			$state['file_cursor']++;
			$state['current_file_offset'] = 0;
			return;
		}

		$read_size = min( $state['chunk_size'], $size - $offset );
		$input     = fopen( $absolute_path, 'rb' );
		if ( ! $input ) {
			throw new RuntimeException( 'Unable to open file for read: ' . $entry['path'] );
		}

		fseek( $input, $offset );
		$data = fread( $input, $read_size );
		fclose( $input );

		if ( false === $data ) {
			throw new RuntimeException( 'Unable to read file chunk: ' . $entry['path'] );
		}

		$state['fs_chunk_index']++;
		$chunk_name = sprintf( 'chunks/fs-%06d.bin', $state['fs_chunk_index'] );
		$chunk_path = trailingslashit( $state['work_dir'] ) . $chunk_name;
		file_put_contents( $chunk_path, $data );

		$hash = hash_file( 'sha256', $chunk_path );
		$state['fs_chunks'][] = array(
			'chunk'  => $chunk_name,
			'file'   => $entry['path'],
			'offset' => $offset,
			'size'   => strlen( $data ),
			'sha256' => $hash,
		);

		$state['current_file_offset'] = $offset + strlen( $data );
		if ( $state['current_file_offset'] >= $size ) {
			$state['file_cursor']++;
			$state['current_file_offset'] = 0;
		}
	}

	/**
	 * Finalize manifest and package archive.
	 *
	 * @param array<string,mixed> $state Export state.
	 */
	private function finalize_manifest( array &$state ) {
		$manifest = array(
			'format_version'   => '1.0.0',
			'created_at'       => gmdate( 'c' ),
			'site_url'         => site_url(),
			'filesystem'       => $state['fs_chunks'],
			'database_chunks'  => $state['db_chunks'],
			'totals'           => array(
				'filesystem_chunks' => count( $state['fs_chunks'] ),
				'database_chunks'   => count( $state['db_chunks'] ),
			),
		);

		$hash_input = array();
		foreach ( $state['fs_chunks'] as $chunk ) {
			$hash_input[] = $chunk['sha256'];
		}
		foreach ( $state['db_chunks'] as $chunk ) {
			$hash_input[] = $chunk['sha256'];
		}

		$manifest['global_checksum'] = hash( 'sha256', implode( '|', $hash_input ) );
		$manifest_path               = trailingslashit( $state['work_dir'] ) . 'manifest.json';
		file_put_contents( $manifest_path, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		$lift_path = trailingslashit( $this->get_exports_base_dir() ) . 'lift-export-' . $state['export_id'] . '.lift';
		$zip       = new ZipArchive();
		if ( true !== $zip->open( $lift_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Unable to create .lift archive.' );
		}

		$zip->addFile( $manifest_path, 'manifest.json' );
		foreach ( $state['fs_chunks'] as $chunk ) {
			$zip->addFile( trailingslashit( $state['work_dir'] ) . $chunk['chunk'], $chunk['chunk'] );
		}
		foreach ( $state['db_chunks'] as $chunk ) {
			$zip->addFile( trailingslashit( $state['work_dir'] ) . $chunk['chunk'], $chunk['chunk'] );
		}
		$zip->close();

		$state['manifest']        = $manifest_path;
		$state['global_checksum'] = $manifest['global_checksum'];
		$state['final_file']      = $lift_path;
		$state['status']          = 'completed';
		$state['phase']           = 'completed';
	}

	/**
	 * Open a DB chunk file.
	 *
	 * @param array<string,mixed> $state Export state.
	 */
	private function open_db_chunk( array &$state ) {
		$state['db_chunk_index']++;
		$chunk_name                   = sprintf( 'sql/db-%06d.sql', $state['db_chunk_index'] );
		$state['db_current_chunk']    = $chunk_name;
		$state['db_current_chunk_size'] = 0;
		$chunk_path                   = trailingslashit( $state['work_dir'] ) . $chunk_name;
		file_put_contents( $chunk_path, "-- Lift Teleport SQL chunk\n" );
		$state['db_current_chunk_size'] = filesize( $chunk_path );
	}

	/**
	 * Append SQL to current chunk and roll when needed.
	 *
	 * @param array<string,mixed> $state Export state.
	 * @param string              $sql SQL string.
	 */
	private function append_db_sql( array &$state, $sql ) {
		if ( empty( $state['db_current_chunk'] ) ) {
			$this->open_db_chunk( $state );
		}

		$chunk_path = trailingslashit( $state['work_dir'] ) . $state['db_current_chunk'];
		$line       = $sql . "\n";

		if ( $state['db_current_chunk_size'] + strlen( $line ) > $state['chunk_size'] ) {
			$this->close_db_chunk( $state );
			$this->open_db_chunk( $state );
			$chunk_path = trailingslashit( $state['work_dir'] ) . $state['db_current_chunk'];
		}

		file_put_contents( $chunk_path, $line, FILE_APPEND );
		$state['db_current_chunk_size'] += strlen( $line );
	}

	/**
	 * Close db chunk and register checksum.
	 *
	 * @param array<string,mixed> $state Export state.
	 */
	private function close_db_chunk( array &$state ) {
		$chunk_name = $state['db_current_chunk'];
		$chunk_path = trailingslashit( $state['work_dir'] ) . $chunk_name;
		$size       = filesize( $chunk_path );
		$hash       = hash_file( 'sha256', $chunk_path );

		$state['db_chunks'][] = array(
			'chunk'  => $chunk_name,
			'size'   => $size,
			'sha256' => $hash,
		);

		$state['db_current_chunk']      = null;
		$state['db_current_chunk_size'] = 0;
	}

	/**
	 * Build insert SQL.
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $row Row.
	 * @return string
	 */
	private function build_insert_statement( $table, array $row ) {
		global $wpdb;

		$columns = array_map(
			static function ( $column ) {
				return '`' . str_replace( '`', '``', $column ) . '`';
			},
			array_keys( $row )
		);

		$values = array_map(
			static function ( $value ) use ( $wpdb ) {
				if ( null === $value ) {
					return 'NULL';
				}
				return "'" . esc_sql( (string) $value ) . "'";
			},
			array_values( $row )
		);

		return 'INSERT INTO `' . str_replace( '`', '``', $table ) . '` (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $values ) . ');';
	}

	/**
	 * Get one file-list entry by index.
	 *
	 * @param string $path Path.
	 * @param int    $index Index.
	 * @return array<string,mixed>|null
	 */
	private function get_file_entry_at_index( $path, $index ) {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new RuntimeException( 'Unable to read file list.' );
		}

		$current = 0;
		while ( ! feof( $handle ) ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}
			if ( $current === (int) $index ) {
				fclose( $handle );
				$data = json_decode( trim( $line ), true );
				return is_array( $data ) ? $data : null;
			}
			$current++;
		}

		fclose( $handle );
		return null;
	}

	/**
	 * Get db tables.
	 *
	 * @return array<int,string>
	 */
	private function get_database_tables() {
		global $wpdb;

		$results = $wpdb->get_col( 'SHOW TABLES' );
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get exports base directory.
	 *
	 * @return string
	 */
	private function get_exports_base_dir() {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'lift-teleport/exports';
		wp_mkdir_p( $dir );
		return $dir;
	}

	/**
	 * Make path relative to ABSPATH.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private function relative_path( $path ) {
		return '/' . ltrim( str_replace( ABSPATH, '', $path ), '/' );
	}

	/**
	 * Save export state.
	 *
	 * @param array<string,mixed> $state State.
	 */
	private function save_state( array $state ) {
		update_option( self::STATE_OPTION_PREFIX . $state['export_id'], $state, false );
	}

	/**
	 * Load export state.
	 *
	 * @param string $export_id Export ID.
	 * @return array<string,mixed>|null
	 */
	private function get_state( $export_id ) {
		$state = get_option( self::STATE_OPTION_PREFIX . $export_id );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Build redacted public state.
	 *
	 * @param array<string,mixed> $state Full state.
	 * @return array<string,mixed>
	 */
	private function public_state( array $state ) {
		return array(
			'export_id'       => $state['export_id'],
			'status'          => $state['status'],
			'phase'           => $state['phase'],
			'file_total'      => $state['file_total'],
			'file_cursor'     => $state['file_cursor'],
			'fs_chunks'       => count( $state['fs_chunks'] ),
			'db_tables_total' => count( $state['db_tables'] ),
			'db_table_index'  => $state['db_table_index'],
			'db_chunks'       => count( $state['db_chunks'] ),
			'global_checksum' => $state['global_checksum'],
			'updated_at'      => $state['updated_at'],
			'last_error'      => $state['last_error'],
			'download_url'    => ! empty( $state['final_file'] ) ? rest_url( self::REST_NAMESPACE . '/export/download?export_id=' . rawurlencode( $state['export_id'] ) ) : null,
		);
	}
}
