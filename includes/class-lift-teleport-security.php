<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lift_Teleport_Security {
	const NONCE_IMPORT_INIT  = 'lift_teleport_import_init';
	const NONCE_IMPORT_BATCH = 'lift_teleport_import_batch';

	/**
	 * Register protected endpoints.
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_lift_teleport_import_init', array( $this, 'ajax_import_init' ) );
		add_action( 'wp_ajax_lift_teleport_import_batch', array( $this, 'ajax_import_batch' ) );
		add_action( 'admin_post_lift_teleport_export', array( $this, 'admin_export' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST routes with strict permissions.
	 */
	public function register_rest_routes() {
		register_rest_route(
			'lift-teleport/v1',
			'/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_import' ),
				'permission_callback' => array( $this, 'rest_permissions' ),
			)
		);
	}

	/**
	 * REST permissions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function rest_permissions( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'lift_teleport_forbidden', __( 'Permisos insuficientes.', 'lift-teleport' ), array( 'status' => 403 ) );
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $nonce ) || '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'lift_teleport_invalid_nonce', __( 'Nonce inválido.', 'lift-teleport' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Handle import init via AJAX.
	 */
	public function ajax_import_init() {
		if ( ! $this->verify_nonce( self::NONCE_IMPORT_INIT, 'nonce' ) ) {
			$this->log_event( 'warning', 'Init import rejected: invalid nonce.' );
			wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lift-teleport' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->log_event( 'warning', 'Init import rejected: insufficient permissions.' );
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'lift-teleport' ) ), 403 );
		}

		if ( empty( $_FILES['package'] ) || ! is_array( $_FILES['package'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No se recibió archivo.', 'lift-teleport' ) ), 400 );
		}

		$file = $this->sanitize_file_array( $_FILES['package'] );
		if ( is_wp_error( $file ) ) {
			$this->log_event( 'error', 'Rejected upload: malformed file array.' );
			wp_send_json_error( array( 'message' => $file->get_error_message() ), 400 );
		}

		$validation = $this->validate_lift_file( $file );
		if ( is_wp_error( $validation ) ) {
			$this->log_event( 'error', sprintf( 'Rejected upload %s: %s', $file['name'], $validation->get_error_message() ) );
			wp_send_json_error( array( 'message' => $validation->get_error_message() ), 400 );
		}

		$batch_id = wp_generate_uuid4();
		set_transient( 'lift_teleport_batch_' . $batch_id, array( 'created' => time() ), MINUTE_IN_SECONDS * 30 );

		$this->log_event( 'info', sprintf( 'Import initialized for %s (batch %s)', $file['name'], $batch_id ) );

		wp_send_json_success(
			array(
				'batchId' => $batch_id,
				'limits'  => $this->get_resource_limits(),
			)
		);
	}

	/**
	 * Handle import batch execution.
	 */
	public function ajax_import_batch() {
		if ( ! $this->verify_nonce( self::NONCE_IMPORT_BATCH, 'nonce' ) ) {
			$this->log_event( 'warning', 'Batch rejected: invalid nonce.' );
			wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'lift-teleport' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->log_event( 'warning', 'Batch rejected: insufficient permissions.' );
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'lift-teleport' ) ), 403 );
		}

		$params = $this->sanitize_batch_params( $_POST );
		if ( is_wp_error( $params ) ) {
			$this->log_event( 'error', 'Batch rejected: invalid parameters.' );
			wp_send_json_error( array( 'message' => $params->get_error_message() ), 400 );
		}

		$batch_state = get_transient( 'lift_teleport_batch_' . $params['batch_id'] );
		if ( false === $batch_state ) {
			wp_send_json_error( array( 'message' => __( 'Lote inválido o expirado.', 'lift-teleport' ) ), 404 );
		}

		$this->log_event( 'info', sprintf( 'Batch %s: offset %d of %d', $params['batch_id'], $params['offset'], $params['total'] ) );

		wp_send_json_success(
			array(
				'done'    => $params['offset'] >= $params['total'],
				'offset'  => min( $params['total'], $params['offset'] + $params['chunk_size'] ),
				'limits'  => $this->get_resource_limits(),
			)
		);
	}

	/**
	 * Handle admin export endpoint.
	 */
	public function admin_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'lift-teleport' ), 403 );
		}

		if ( ! $this->verify_nonce( 'lift_teleport_export', '_wpnonce' ) ) {
			$this->log_event( 'warning', 'Export rejected: invalid nonce.' );
			wp_die( esc_html__( 'Nonce inválido.', 'lift-teleport' ), 403 );
		}

		$this->log_event( 'info', 'Export requested.' );
		wp_safe_redirect( admin_url( 'admin.php?page=lift-teleport' ) );
		exit;
	}

	/**
	 * REST import callback.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function rest_import( $request ) {
		$params = $this->sanitize_batch_params( $request->get_params() );
		if ( is_wp_error( $params ) ) {
			return new WP_REST_Response( array( 'message' => $params->get_error_message() ), 400 );
		}

		$this->log_event( 'info', sprintf( 'REST import batch %s accepted.', $params['batch_id'] ) );

		return new WP_REST_Response(
			array(
				'status' => 'accepted',
				'limits' => $this->get_resource_limits(),
			),
			200
		);
	}

	/**
	 * Validate and normalize uploaded file payload.
	 *
	 * @param array $file File array.
	 * @return array|WP_Error
	 */
	private function sanitize_file_array( $file ) {
		$sanitized_name = sanitize_file_name( wp_unslash( (string) ( $file['name'] ?? '' ) ) );
		$sanitized_tmp  = wp_normalize_path( (string) ( $file['tmp_name'] ?? '' ) );
		$error_code     = isset( $file['error'] ) ? absint( $file['error'] ) : UPLOAD_ERR_NO_FILE;

		if ( '' === $sanitized_name || '' === $sanitized_tmp || UPLOAD_ERR_OK !== $error_code ) {
			return new WP_Error( 'lift_teleport_invalid_upload', __( 'Archivo de subida inválido.', 'lift-teleport' ) );
		}

		return array(
			'name'     => $sanitized_name,
			'tmp_name' => $sanitized_tmp,
			'size'     => isset( $file['size'] ) ? absint( $file['size'] ) : 0,
		);
	}

	/**
	 * Validate file extension, MIME and archive content.
	 *
	 * @param array $file File array.
	 * @return true|WP_Error
	 */
	private function validate_lift_file( $file ) {
		if ( ! preg_match( '/\.lift$/i', $file['name'] ) ) {
			return new WP_Error( 'lift_teleport_invalid_extension', __( 'Solo se permiten archivos .lift.', 'lift-teleport' ) );
		}

		$detected = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], array( 'lift' => 'application/zip' ) );
		if ( empty( $detected['ext'] ) || 'lift' !== $detected['ext'] ) {
			return new WP_Error( 'lift_teleport_invalid_mime', __( 'El archivo no coincide con un paquete .lift válido.', 'lift-teleport' ) );
		}

		$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $file['tmp_name'] ) : '';
		$allowed_mimes = array( 'application/zip', 'application/x-zip', 'application/x-zip-compressed' );
		if ( ! in_array( $mime, $allowed_mimes, true ) ) {
			return new WP_Error( 'lift_teleport_invalid_content', __( 'Contenido de archivo no permitido.', 'lift-teleport' ) );
		}

		$archive_check = $this->inspect_archive( $file['tmp_name'] );
		if ( is_wp_error( $archive_check ) ) {
			return $archive_check;
		}

		return true;
	}

	/**
	 * Inspect archive to ensure expected structure and safe paths.
	 *
	 * @param string $archive_path Archive path.
	 * @return true|WP_Error
	 */
	private function inspect_archive( $archive_path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			return new WP_Error( 'lift_teleport_open_archive', __( 'No se pudo abrir el paquete .lift.', 'lift-teleport' ) );
		}

		$has_manifest = false;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			$normalized = $this->normalize_archive_path( $name );
			if ( '' === $normalized ) {
				$zip->close();
				return new WP_Error( 'lift_teleport_invalid_path', __( 'El paquete contiene rutas inseguras.', 'lift-teleport' ) );
			}
			if ( 'lift-manifest.json' === $normalized ) {
				$has_manifest = true;
			}
		}
		$zip->close();

		if ( ! $has_manifest ) {
			return new WP_Error( 'lift_teleport_missing_manifest', __( 'El paquete .lift no contiene lift-manifest.json.', 'lift-teleport' ) );
		}

		return true;
	}

	/**
	 * Normalize archive paths and prevent traversal.
	 *
	 * @param string $path Archive path.
	 * @return string
	 */
	private function normalize_archive_path( $path ) {
		$path = wp_normalize_path( trim( $path ) );
		if ( '' === $path || 0 === strpos( $path, '/' ) || preg_match( '/^[A-Za-z]:\//', $path ) ) {
			return '';
		}

		$parts = array();
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				return '';
			}
			$parts[] = sanitize_file_name( $part );
		}

		return implode( '/', $parts );
	}

	/**
	 * Validate batch parameters.
	 *
	 * @param array $raw_params Raw request parameters.
	 * @return array|WP_Error
	 */
	private function sanitize_batch_params( $raw_params ) {
		$raw_params = wp_unslash( $raw_params );
		$batch_id   = isset( $raw_params['batch_id'] ) ? sanitize_text_field( (string) $raw_params['batch_id'] ) : '';
		$offset     = isset( $raw_params['offset'] ) ? absint( $raw_params['offset'] ) : 0;
		$total      = isset( $raw_params['total'] ) ? absint( $raw_params['total'] ) : 0;
		$chunk_size = isset( $raw_params['chunk_size'] ) ? absint( $raw_params['chunk_size'] ) : 0;

		if ( '' === $batch_id || ! preg_match( '/^[a-f0-9\-]{36}$/', $batch_id ) ) {
			return new WP_Error( 'lift_teleport_invalid_batch_id', __( 'batch_id inválido.', 'lift-teleport' ) );
		}

		$limits = $this->get_resource_limits();
		if ( $total < $offset || $chunk_size < 1 || $chunk_size > $limits['chunk_size'] ) {
			return new WP_Error( 'lift_teleport_invalid_batch_args', __( 'Parámetros de lote inválidos.', 'lift-teleport' ) );
		}

		return array(
			'batch_id'   => $batch_id,
			'offset'     => $offset,
			'total'      => $total,
			'chunk_size' => $chunk_size,
		);
	}

	/**
	 * Verify nonce with strict presence check.
	 *
	 * @param string $action Nonce action.
	 * @param string $field Input field.
	 * @return bool
	 */
	private function verify_nonce( $action, $field ) {
		$nonce = isset( $_REQUEST[ $field ] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST[ $field ] ) ) : '';
		return '' !== $nonce && (bool) wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Resource limits with safe defaults.
	 *
	 * @return array
	 */
	private function get_resource_limits() {
		$configured = get_option( 'lift_teleport_resource_limits', array() );
		$configured = is_array( $configured ) ? $configured : array();

		$time_per_batch = isset( $configured['time_per_batch'] ) ? absint( $configured['time_per_batch'] ) : 20;
		$memory_target  = isset( $configured['memory_target_mb'] ) ? absint( $configured['memory_target_mb'] ) : 256;
		$chunk_size     = isset( $configured['chunk_size'] ) ? absint( $configured['chunk_size'] ) : 100;

		return array(
			'time_per_batch'   => min( 120, max( 5, $time_per_batch ) ),
			'memory_target_mb' => min( 2048, max( 64, $memory_target ) ),
			'chunk_size'       => min( 1000, max( 10, $chunk_size ) ),
		);
	}

	/**
	 * Store security and error events in plugin log.
	 *
	 * @param string $level Log level.
	 * @param string $message Message.
	 */
	private function log_event( $level, $message ) {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'lift-teleport';

		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$entry = sprintf(
			"[%s] [%s] user:%d ip:%s %s\n",
			gmdate( 'c' ),
			strtoupper( sanitize_text_field( $level ) ),
			get_current_user_id(),
			sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '-' ) ),
			sanitize_text_field( $message )
		);

		error_log( $entry, 3, trailingslashit( $dir ) . 'security.log' );
	}
}
