<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lift_Teleport_Importer {
	const OPTION_PREFIX     = 'lift_teleport_job_';
	const FORMAT_VERSION    = '1.0';
	const MAX_DB_BATCH_SIZE = 75;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST endpoints for import workflow.
	 */
	public function register_routes() {
		register_rest_route(
			'lift-teleport/v1',
			'/import/upload',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage_import' ),
				'callback'            => array( $this, 'handle_upload' ),
			)
		);

		register_rest_route(
			'lift-teleport/v1',
			'/import/process',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage_import' ),
				'callback'            => array( $this, 'process_next_phase' ),
			)
		);
	}

	/**
	 * Check import capabilities.
	 *
	 * @return bool
	 */
	public function can_manage_import() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Upload endpoint for .lift package.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_upload() {
		if ( empty( $_FILES['lift_file'] ) || UPLOAD_ERR_OK !== (int) $_FILES['lift_file']['error'] ) {
			return $this->error_response( 'upload_failed', __( 'No se pudo subir el archivo .lift.', 'lift-teleport' ) );
		}

		$file_name = sanitize_file_name( wp_unslash( $_FILES['lift_file']['name'] ) );
		if ( 'lift' !== strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) ) ) {
			return $this->error_response( 'invalid_extension', __( 'El archivo debe tener extensión .lift.', 'lift-teleport' ) );
		}

		$job_id   = wp_generate_uuid4();
		$job_dir  = trailingslashit( $this->get_import_base_dir() ) . $job_id;
		$tmp_path = sanitize_text_field( wp_unslash( $_FILES['lift_file']['tmp_name'] ) );

		if ( ! wp_mkdir_p( $job_dir ) ) {
			return $this->error_response( 'cannot_prepare_dir', __( 'No se pudo preparar el directorio temporal.', 'lift-teleport' ) );
		}

		$archive_path = trailingslashit( $job_dir ) . 'package.lift';
		if ( ! move_uploaded_file( $tmp_path, $archive_path ) ) {
			return $this->error_response( 'cannot_store_upload', __( 'No se pudo guardar el archivo subido.', 'lift-teleport' ) );
		}

		$state = array(
			'id'                  => $job_id,
			'archive_path'        => $archive_path,
			'job_dir'             => $job_dir,
			'staging_dir'         => trailingslashit( $job_dir ) . 'staging',
			'phase_index'         => 0,
			'checkpoints'         => array(),
			'manifest'            => array(),
			'retry_count'         => 0,
			'max_retries'         => 3,
			'backup_tables'       => array(),
			'backup_paths'        => array(),
			'critical_phase_done' => false,
			'phases'              => array(
				'parse_manifest',
				'stage_files',
				'validate_environment',
				'restore_database',
				'swap_files',
				'finalize',
			),
		);

		$this->save_state( $state );

		return rest_ensure_response(
			array(
				'job_id'    => $job_id,
				'phase'     => 'uploaded',
				'progress'  => 5,
				'message'   => __( 'Archivo recibido. Iniciando validaciones...', 'lift-teleport' ),
				'completed' => false,
			)
		);
	}

	/**
	 * Process one phase per request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function process_next_phase( WP_REST_Request $request ) {
		$job_id = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
		$state  = $this->get_state( $job_id );

		if ( empty( $state ) ) {
			return $this->error_response( 'job_not_found', __( 'No se encontró el proceso de importación.', 'lift-teleport' ) );
		}

		if ( ! empty( $state['completed'] ) ) {
			return rest_ensure_response(
				array(
					'job_id'     => $job_id,
					'phase'      => 'done',
					'progress'   => 100,
					'message'    => __( 'Importación completada.', 'lift-teleport' ),
					'completed'  => true,
					'retryable'  => false,
					'can_resume' => false,
				)
			);
		}

		$phase = $state['phases'][ $state['phase_index'] ] ?? null;
		if ( ! $phase ) {
			$state['completed'] = true;
			$this->save_state( $state );
			return rest_ensure_response(
				array(
					'job_id'     => $job_id,
					'phase'      => 'done',
					'progress'   => 100,
					'message'    => __( 'Importación completada.', 'lift-teleport' ),
					'completed'  => true,
					'retryable'  => false,
					'can_resume' => false,
				)
			);
		}

		try {
			$result = call_user_func( array( $this, 'phase_' . $phase ), $state );
			$state  = $result['state'];

			if ( ! empty( $result['done'] ) ) {
				$state['phase_index']++;
				$state['retry_count'] = 0;
			}

			if ( $state['phase_index'] >= count( $state['phases'] ) ) {
				$state['completed'] = true;
			}

			$this->save_state( $state );

			return rest_ensure_response(
				array(
					'job_id'     => $job_id,
					'phase'      => $phase,
					'progress'   => $this->get_progress_percentage( $state ),
					'message'    => $result['message'] ?? __( 'Procesando...', 'lift-teleport' ),
					'completed'  => ! empty( $state['completed'] ),
					'retryable'  => false,
					'can_resume' => true,
				)
			);
		} catch ( Exception $exception ) {
			$state['retry_count'] = isset( $state['retry_count'] ) ? (int) $state['retry_count'] + 1 : 1;
			$retryable            = $state['retry_count'] <= (int) $state['max_retries'];

			if ( ! $retryable ) {
				$this->run_rollback( $state );
			}

			$this->save_state( $state );

			return rest_ensure_response(
				array(
					'job_id'     => $job_id,
					'phase'      => $phase,
					'progress'   => $this->get_progress_percentage( $state ),
					'message'    => $exception->getMessage(),
					'completed'  => false,
					'retryable'  => $retryable,
					'can_resume' => $retryable,
				)
			);
		}
	}

	/**
	 * Parse and validate manifest + checksums before site changes.
	 */
	private function phase_parse_manifest( array $state ) {
		$manifest = $this->read_manifest( $state['archive_path'] );
		$this->validate_manifest_compatibility( $manifest );
		$this->validate_manifest_checksums( $state['archive_path'], $manifest );

		$state['manifest']                        = $manifest;
		$state['checkpoints']['parse_manifest']   = array( 'validated' => true, 'timestamp' => time() );
		$state['checkpoints']['site_touched']     = false;

		return array(
			'done'    => true,
			'state'   => $state,
			'message' => __( 'Manifest validado correctamente.', 'lift-teleport' ),
		);
	}

	/**
	 * Extract package contents into staging directory.
	 */
	private function phase_stage_files( array $state ) {
		if ( ! empty( $state['checkpoints']['stage_files']['complete'] ) ) {
			return array(
				'done'    => true,
				'state'   => $state,
				'message' => __( 'Staging ya preparado.', 'lift-teleport' ),
			);
		}

		if ( ! wp_mkdir_p( $state['staging_dir'] ) ) {
			throw new Exception( __( 'No se pudo crear el staging temporal.', 'lift-teleport' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $state['archive_path'] ) ) {
			throw new Exception( __( 'No se pudo abrir el paquete .lift.', 'lift-teleport' ) );
		}

		if ( ! $zip->extractTo( $state['staging_dir'] ) ) {
			$zip->close();
			throw new Exception( __( 'No se pudieron extraer los archivos al staging.', 'lift-teleport' ) );
		}

		$zip->close();

		$state['checkpoints']['stage_files'] = array(
			'complete'  => true,
			'timestamp' => time(),
		);

		return array(
			'done'    => true,
			'state'   => $state,
			'message' => __( 'Archivos preparados en staging.', 'lift-teleport' ),
		);
	}

	/**
	 * Validate runtime compatibility and resource constraints.
	 */
	private function phase_validate_environment( array $state ) {
		$manifest     = $state['manifest'];
		$requirements = $manifest['requirements'] ?? array();
		$checks       = array();

		$checks[] = $this->assert_php_version( $requirements['php_min'] ?? '7.4' );
		$checks[] = $this->assert_wp_version( $requirements['wp_min'] ?? '6.0' );
		$checks[] = $this->assert_table_prefix_compatible( $requirements['table_prefix'] ?? $GLOBALS['wpdb']->prefix );
		$checks[] = $this->assert_collation_compatible( $requirements['collation'] ?? $GLOBALS['wpdb']->collate );
		$checks[] = $this->assert_absolute_paths_safe( $manifest );
		$checks[] = $this->assert_staging_permissions( $state['staging_dir'] );
		$checks[] = $this->assert_disk_space( $state['staging_dir'] );

		$state['checkpoints']['validate_environment'] = array(
			'complete'  => true,
			'timestamp' => time(),
			'checks'    => $checks,
		);

		return array(
			'done'    => true,
			'state'   => $state,
			'message' => __( 'Compatibilidad validada.', 'lift-teleport' ),
		);
	}

	/**
	 * Restore database in batches and keep checkpointed progress.
	 */
	private function phase_restore_database( array $state ) {
		global $wpdb;

		$db_rel_path = $state['manifest']['database']['path'] ?? 'database.sql';
		$db_path     = trailingslashit( $state['staging_dir'] ) . ltrim( $db_rel_path, '/' );
		if ( ! file_exists( $db_path ) ) {
			throw new Exception( __( 'No se encontró el volcado de base de datos.', 'lift-teleport' ) );
		}

		if ( empty( $state['checkpoints']['restore_database']['snapshot_done'] ) ) {
			$tables = $state['manifest']['database']['tables'] ?? array();
			if ( empty( $tables ) ) {
				$tables = $wpdb->get_col( 'SHOW TABLES' );
			}
			$state = $this->snapshot_tables( $state, $tables );
		}

		$line = (int) ( $state['checkpoints']['restore_database']['line'] ?? 0 );
		$fh   = fopen( $db_path, 'r' );
		if ( ! $fh ) {
			throw new Exception( __( 'No se pudo leer el archivo SQL.', 'lift-teleport' ) );
		}

		$current_line = 0;
		$executed     = 0;
		$statement    = '';

		while ( ! feof( $fh ) ) {
			$sql_line = fgets( $fh );
			if ( false === $sql_line ) {
				break;
			}

			$current_line++;
			if ( $current_line <= $line ) {
				continue;
			}

			$trimmed = trim( $sql_line );
			if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) ) {
				continue;
			}

			$statement .= $sql_line;
			if ( ';' === substr( rtrim( $trimmed ), -1 ) ) {
				$wpdb->query( $statement ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$statement = '';
				$executed++;
			}

			if ( $executed >= self::MAX_DB_BATCH_SIZE ) {
				break;
			}
		}

		$done = feof( $fh );
		fclose( $fh );

		$state['critical_phase_done']                 = true;
		$state['checkpoints']['restore_database']     = array(
			'snapshot_done' => true,
			'line'          => $current_line,
			'done'          => $done,
			'timestamp'     => time(),
		);

		return array(
			'done'    => $done,
			'state'   => $state,
			'message' => $done ? __( 'Base de datos restaurada.', 'lift-teleport' ) : __( 'Restaurando base de datos por lotes...', 'lift-teleport' ),
		);
	}

	/**
	 * Atomically swap staged files into live paths when configured.
	 */
	private function phase_swap_files( array $state ) {
		$items = $state['manifest']['files']['swap'] ?? array();
		if ( empty( $items ) ) {
			return array(
				'done'    => true,
				'state'   => $state,
				'message' => __( 'No hay rutas para intercambio atómico.', 'lift-teleport' ),
			);
		}

		$backup_root = trailingslashit( $state['job_dir'] ) . 'path-backups';
		wp_mkdir_p( $backup_root );

		foreach ( $items as $relative_path ) {
			$clean_relative = ltrim( (string) $relative_path, '/' );
			$source         = trailingslashit( $state['staging_dir'] ) . $clean_relative;
			$target         = trailingslashit( ABSPATH ) . $clean_relative;
			$backup         = trailingslashit( $backup_root ) . str_replace( '/', '__', $clean_relative ) . '.bak';

			if ( ! file_exists( $source ) ) {
				continue;
			}

			if ( file_exists( $target ) && ! rename( $target, $backup ) ) {
				throw new Exception( sprintf( __( 'No se pudo crear backup temporal de %s.', 'lift-teleport' ), $clean_relative ) );
			}

			if ( ! rename( $source, $target ) ) {
				throw new Exception( sprintf( __( 'No se pudo reemplazar %s.', 'lift-teleport' ), $clean_relative ) );
			}

			$state['backup_paths'][ $target ] = $backup;
		}

		$state['checkpoints']['swap_files'] = array(
			'complete'  => true,
			'timestamp' => time(),
		);

		return array(
			'done'    => true,
			'state'   => $state,
			'message' => __( 'Intercambio atómico de archivos completado.', 'lift-teleport' ),
		);
	}

	/**
	 * Mark completion and cleanup transient assets.
	 */
	private function phase_finalize( array $state ) {
		$state['checkpoints']['finalize'] = array(
			'complete'  => true,
			'timestamp' => time(),
		);

		return array(
			'done'    => true,
			'state'   => $state,
			'message' => __( 'Proceso finalizado.', 'lift-teleport' ),
		);
	}

	/**
	 * Rollback database and filesystem backups.
	 */
	private function run_rollback( array $state ) {
		global $wpdb;

		foreach ( $state['backup_tables'] as $table => $backup_table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "RENAME TABLE `{$backup_table}` TO `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		foreach ( $state['backup_paths'] as $target => $backup ) {
			if ( file_exists( $backup ) ) {
				if ( file_exists( $target ) ) {
					$this->delete_path( $target );
				}
				rename( $backup, $target );
			}
		}
	}

	/**
	 * Snapshot target tables before mutation.
	 */
	private function snapshot_tables( array $state, array $tables ) {
		global $wpdb;

		$job_suffix = str_replace( '-', '_', $state['id'] );
		foreach ( $tables as $table ) {
			$clean_table = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $table );
			$backup      = $clean_table . '_lt_backup_' . $job_suffix;
			$wpdb->query( "DROP TABLE IF EXISTS `{$backup}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "CREATE TABLE `{$backup}` LIKE `{$clean_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "INSERT INTO `{$backup}` SELECT * FROM `{$clean_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$state['backup_tables'][ $clean_table ] = $backup;
		}

		$state['checkpoints']['restore_database']['snapshot_done'] = true;
		return $state;
	}

	/**
	 * Read manifest from archive.
	 */
	private function read_manifest( $archive_path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			throw new Exception( __( 'No se pudo abrir el paquete para leer manifest.', 'lift-teleport' ) );
		}

		$manifest_raw = $zip->getFromName( 'manifest.json' );
		$zip->close();

		if ( false === $manifest_raw ) {
			throw new Exception( __( 'El paquete no contiene manifest.json.', 'lift-teleport' ) );
		}

		$manifest = json_decode( $manifest_raw, true );
		if ( ! is_array( $manifest ) ) {
			throw new Exception( __( 'manifest.json inválido.', 'lift-teleport' ) );
		}

		return $manifest;
	}

	/**
	 * Manifest compatibility validation.
	 */
	private function validate_manifest_compatibility( array $manifest ) {
		$version = (string) ( $manifest['format_version'] ?? '' );
		if ( self::FORMAT_VERSION !== $version ) {
			throw new Exception( sprintf( __( 'Formato .lift no compatible. Esperado %s.', 'lift-teleport' ), self::FORMAT_VERSION ) );
		}
	}

	/**
	 * Validate archive checksums from manifest.
	 */
	private function validate_manifest_checksums( $archive_path, array $manifest ) {
		$checksums = $manifest['checksums'] ?? array();
		if ( empty( $checksums ) || ! is_array( $checksums ) ) {
			throw new Exception( __( 'El manifest no define checksums.', 'lift-teleport' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			throw new Exception( __( 'No se pudo abrir el paquete para validar checksums.', 'lift-teleport' ) );
		}

		foreach ( $checksums as $path => $expected_hash ) {
			$content = $zip->getFromName( $path );
			if ( false === $content ) {
				$zip->close();
				throw new Exception( sprintf( __( 'Falta archivo requerido para checksum: %s', 'lift-teleport' ), $path ) );
			}

			$actual_hash = hash( 'sha256', $content );
			if ( ! hash_equals( (string) $expected_hash, $actual_hash ) ) {
				$zip->close();
				throw new Exception( sprintf( __( 'Checksum inválido para %s.', 'lift-teleport' ), $path ) );
			}
		}

		$zip->close();
	}

	private function assert_php_version( $required ) {
		if ( version_compare( PHP_VERSION, (string) $required, '<' ) ) {
			throw new Exception( sprintf( __( 'Versión de PHP insuficiente. Requerida: %s', 'lift-teleport' ), $required ) );
		}

		return 'php_ok';
	}

	private function assert_wp_version( $required ) {
		global $wp_version;
		if ( version_compare( $wp_version, (string) $required, '<' ) ) {
			throw new Exception( sprintf( __( 'Versión de WordPress insuficiente. Requerida: %s', 'lift-teleport' ), $required ) );
		}

		return 'wp_ok';
	}

	private function assert_table_prefix_compatible( $required_prefix ) {
		global $wpdb;
		if ( $wpdb->prefix !== (string) $required_prefix ) {
			throw new Exception( __( 'Prefijo de tablas incompatible.', 'lift-teleport' ) );
		}

		return 'table_prefix_ok';
	}

	private function assert_collation_compatible( $required_collation ) {
		global $wpdb;
		$current = (string) $wpdb->collate;
		if ( ! empty( $required_collation ) && $current !== (string) $required_collation ) {
			throw new Exception( __( 'Collation incompatible con el paquete.', 'lift-teleport' ) );
		}

		return 'collation_ok';
	}

	private function assert_absolute_paths_safe( array $manifest ) {
		$paths = $manifest['files']['swap'] ?? array();
		foreach ( $paths as $path ) {
			if ( 0 === strpos( (string) $path, '/' ) ) {
				throw new Exception( __( 'El manifest contiene rutas absolutas no permitidas.', 'lift-teleport' ) );
			}
		}

		return 'paths_ok';
	}

	private function assert_staging_permissions( $staging_dir ) {
		if ( ! is_dir( $staging_dir ) || ! is_readable( $staging_dir ) || ! is_writable( $staging_dir ) ) {
			throw new Exception( __( 'Permisos insuficientes para staging.', 'lift-teleport' ) );
		}

		return 'permissions_ok';
	}

	private function assert_disk_space( $staging_dir ) {
		$free = @disk_free_space( $staging_dir );
		if ( false === $free || $free < 50 * 1024 * 1024 ) {
			throw new Exception( __( 'Espacio en disco insuficiente para importar.', 'lift-teleport' ) );
		}

		return 'disk_ok';
	}

	private function get_import_base_dir() {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'lift-teleport/imports';
	}

	private function get_state( $job_id ) {
		if ( empty( $job_id ) ) {
			return array();
		}
		$state = get_option( self::OPTION_PREFIX . $job_id, array() );
		return is_array( $state ) ? $state : array();
	}

	private function save_state( array $state ) {
		update_option( self::OPTION_PREFIX . $state['id'], $state, false );
	}

	private function get_progress_percentage( array $state ) {
		$total = max( 1, count( $state['phases'] ) );
		$done  = (int) $state['phase_index'];
		if ( ! empty( $state['completed'] ) ) {
			$done = $total;
		}
		return (int) floor( ( $done / $total ) * 100 );
	}

	private function error_response( $code, $message ) {
		return new WP_REST_Response(
			array(
				'code'      => $code,
				'message'   => $message,
				'completed' => false,
			),
			400
		);
	}

	private function delete_path( $path ) {
		if ( is_file( $path ) ) {
			unlink( $path );
			return;
		}

		if ( is_dir( $path ) ) {
			$items = scandir( $path );
			if ( ! is_array( $items ) ) {
				return;
			}
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$this->delete_path( trailingslashit( $path ) . $item );
			}
			rmdir( $path );
		}
	/**
	 * @var Lift_Teleport_Package_Validator
	 */
	private $validator;

	/**
	 * @param Lift_Teleport_Package_Validator $validator Package validator.
	 */
	public function __construct( Lift_Teleport_Package_Validator $validator ) {
		$this->validator = $validator;
	}

	/**
	 * Validates package header + manifest before import starts.
	 *
	 * @param array $header Header data.
	 * @param array $manifest Manifest data.
	 *
	 * @return true|WP_Error
	 */
	public function validate_import_payload( $header, $manifest ) {
		$header_validation = $this->validator->validate_header( $header );
		if ( is_wp_error( $header_validation ) ) {
			return $header_validation;
		}

		$manifest_validation = $this->validator->validate_manifest( $manifest );
		if ( is_wp_error( $manifest_validation ) ) {
			return $manifest_validation;
		}

		$compatibility_validation = $this->validator->validate_importer_compatibility( $manifest, LIFT_TELEPORT_VERSION );
		if ( is_wp_error( $compatibility_validation ) ) {
			return $compatibility_validation;
		}

		return true;
	}
}
