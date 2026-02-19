<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lift_Teleport_Exporter {
	const REST_NAMESPACE      = 'lift-teleport/v1';
	const STATE_OPTION_PREFIX = 'lift_teleport_export_state_';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

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

	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	public function start_export( WP_REST_Request $request ) {
		$export_id = gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );
		$work_dir  = trailingslashit( $this->get_exports_base_dir() ) . $export_id;

		if ( ! wp_mkdir_p( $work_dir ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'No se pudo preparar directorio de exportación.' ), 500 );
		}

		$state = array(
			'export_id'   => $export_id,
			'status'      => 'running',
			'phase'       => 'prepare',
			'work_dir'    => $work_dir,
			'created_at'  => time(),
			'updated_at'  => time(),
			'final_file'  => null,
			'last_error'  => null,
			'progress'    => 0,
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

	public function continue_export( WP_REST_Request $request ) {
		$export_id = sanitize_text_field( (string) $request->get_param( 'export_id' ) );
		$state     = $this->get_state( $export_id );

		if ( ! $state ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Export no encontrado.' ), 404 );
		}

		if ( 'completed' === $state['status'] || 'failed' === $state['status'] ) {
			return rest_ensure_response( array( 'success' => 'completed' === $state['status'], 'state' => $this->public_state( $state ) ) );
		}

		try {
			switch ( $state['phase'] ) {
				case 'prepare':
					$this->build_export_bundle( $state );
					break;
				default:
					throw new RuntimeException( 'Fase desconocida de exportación.' );
			}
		} catch ( Exception $e ) {
			$state['status']     = 'failed';
			$state['last_error'] = $e->getMessage();
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

	public function get_status( WP_REST_Request $request ) {
		$export_id = sanitize_text_field( (string) $request->get_param( 'export_id' ) );
		$state     = $this->get_state( $export_id );

		if ( ! $state ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Export no encontrado.' ), 404 );
		}

		return rest_ensure_response( array( 'success' => true, 'state' => $this->public_state( $state ) ) );
	}

	public function download_export( WP_REST_Request $request ) {
		$export_id = sanitize_text_field( (string) $request->get_param( 'export_id' ) );
		$state     = $this->get_state( $export_id );

		if ( ! $state || empty( $state['final_file'] ) || ! file_exists( $state['final_file'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Archivo no disponible.' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="lift-export-' . sanitize_file_name( $export_id ) . '.lift"' );
		header( 'Content-Length: ' . filesize( $state['final_file'] ) );
		readfile( $state['final_file'] );
		exit;
	}

	private function build_export_bundle( array &$state ) {
		global $wpdb;

		$work_dir = $state['work_dir'];
		$db_file  = trailingslashit( $work_dir ) . 'database.sql';
		$manifest = array(
			'format_version' => '1.0.0',
			'created_at'     => gmdate( 'c' ),
			'site_url'       => site_url(),
			'requirements'   => array(
				'php_min'      => PHP_VERSION,
				'wp_min'       => get_bloginfo( 'version' ),
				'table_prefix' => $wpdb->prefix,
				'collation'    => $wpdb->collate,
			),
			'database'       => array(
				'path'   => 'database.sql',
				'tables' => array(),
			),
			'files'          => array(
				'swap' => array(),
			),
			'checksums'      => array(),
		);

		$sql = "-- Lift Teleport SQL export\n";
		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $tables as $table ) {
			$manifest['database']['tables'][] = $table;
			$sql .= "DROP TABLE IF EXISTS `" . str_replace( '`', '``', $table ) . "`;\n";
			$create_row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! empty( $create_row[1] ) ) {
				$sql .= $create_row[1] . ";\n";
			}

			$rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $rows as $row ) {
				$sql .= $this->build_insert_statement( $table, $row ) . "\n";
			}
		}

		file_put_contents( $db_file, $sql );
		$manifest['checksums']['database.sql'] = hash_file( 'sha256', $db_file );
		$state['progress'] = 45;

		$root_entries = array(
			'wp-content/uploads',
			'wp-content/plugins',
			'wp-content/themes',
		);

		$zip_path = trailingslashit( $this->get_exports_base_dir() ) . 'lift-export-' . $state['export_id'] . '.lift';
		$zip      = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'No se pudo crear el archivo .lift.' );
		}

		$zip->addFile( $db_file, 'database.sql' );

		foreach ( $root_entries as $relative_root ) {
			$abs_root = trailingslashit( ABSPATH ) . $relative_root;
			if ( ! file_exists( $abs_root ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $abs_root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file_info ) {
				if ( ! $file_info->isFile() ) {
					continue;
				}

				$file_path = $file_info->getPathname();
				$relative  = ltrim( str_replace( ABSPATH, '', $file_path ), '/' );
				$zip->addFile( $file_path, $relative );
				$manifest['files']['swap'][]   = $relative;
				$manifest['checksums'][ $relative ] = hash_file( 'sha256', $file_path );
			}
		}

		$manifest_path = trailingslashit( $work_dir ) . 'manifest.json';
		file_put_contents( $manifest_path, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$zip->addFile( $manifest_path, 'manifest.json' );
		$zip->close();

		$state['status']     = 'completed';
		$state['phase']      = 'completed';
		$state['progress']   = 100;
		$state['final_file'] = $zip_path;
	}

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

	private function get_exports_base_dir() {
		$base = trailingslashit( wp_upload_dir()['basedir'] ) . 'lift-teleport-exports';
		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
		}
		return $base;
	}

	private function save_state( array $state ) {
		update_option( self::STATE_OPTION_PREFIX . $state['export_id'], $state, false );
	}

	private function get_state( $export_id ) {
		$state = get_option( self::STATE_OPTION_PREFIX . $export_id );
		return is_array( $state ) ? $state : null;
	}

	private function public_state( array $state ) {
		return array(
			'export_id'    => $state['export_id'],
			'status'       => $state['status'],
			'phase'        => $state['phase'],
			'progress'     => (int) ( $state['progress'] ?? 0 ),
			'updated_at'   => $state['updated_at'],
			'last_error'   => $state['last_error'],
			'download_url' => ! empty( $state['final_file'] ) ? rest_url( self::REST_NAMESPACE . '/export/download?export_id=' . rawurlencode( $state['export_id'] ) ) : null,
		);
	}
}
