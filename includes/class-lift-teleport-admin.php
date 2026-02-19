<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lift_Teleport_Admin {
	/**
	 * @var Lift_Teleport_Migration_Manager
	 */
	private $migration_manager;

	/**
	 * Constructor.
	 *
	 * @param Lift_Teleport_Migration_Manager $migration_manager Migration manager.
	 */
	public function __construct( Lift_Teleport_Migration_Manager $migration_manager ) {
		$this->migration_manager = $migration_manager;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_lift_teleport_run_import', array( $this, 'ajax_run_import' ) );
		add_action( 'wp_ajax_lift_teleport_list_jobs', array( $this, 'ajax_list_jobs' ) );
		add_action( 'admin_post_lift_teleport_download_report', array( $this, 'download_report' ) );
	}

	/**
	 * Register plugin menu page.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Lift Teleport', 'lift-teleport' ),
			__( 'Lift Teleport', 'lift-teleport' ),
			'manage_options',
			'lift-teleport',
			array( $this, 'render_page' ),
			'dashicons-migrate',
			58
		);
	}

	/**
	 * Load admin scripts/styles only on the plugin page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_lift-teleport' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'lift-teleport-admin',
			LIFT_TELEPORT_URL . 'assets/css/admin.css',
			array(),
			LIFT_TELEPORT_VERSION
		);

		wp_enqueue_script(
			'lift-teleport-admin',
			LIFT_TELEPORT_URL . 'assets/js/admin.js',
			array(),
			LIFT_TELEPORT_VERSION,
			true
		);

		wp_localize_script(
			'lift-teleport-admin',
			'liftTeleportAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'lift_teleport_admin_nonce' ),
				'reportNonce' => wp_create_nonce( 'lift_teleport_download_report' ),
				'adminPostUrl' => admin_url( 'admin-post.php' ),
			)
		);
	}

	/**
	 * Ajax: run import.
	 */
	public function ajax_run_import() {
		$this->assert_request_permissions();

		$file_name = isset( $_POST['fileName'] ) ? sanitize_text_field( wp_unslash( $_POST['fileName'] ) ) : '';
		if ( '' === $file_name ) {
			wp_send_json_error(
				array(
					'message' => __( 'No se detectó un archivo .lift válido.', 'lift-teleport' ),
					'code'    => 'LT-IMP-INPUT-MISSING',
				),
				400
			);
		}

		$job = $this->migration_manager->run_import( $file_name );
		wp_send_json_success( array( 'job' => $job ) );
	}

	/**
	 * Ajax: list all jobs.
	 */
	public function ajax_list_jobs() {
		$this->assert_request_permissions();
		wp_send_json_success( array( 'jobs' => $this->migration_manager->get_jobs() ) );
	}

	/**
	 * Download one technical report as JSON.
	 */
	public function download_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para descargar este reporte.', 'lift-teleport' ) );
		}

		check_admin_referer( 'lift_teleport_download_report' );
		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
		$job    = $this->migration_manager->get_job( $job_id );

		if ( ! $job ) {
			wp_die( esc_html__( 'No encontramos el reporte solicitado.', 'lift-teleport' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="lift-teleport-report-' . sanitize_file_name( $job['id'] ) . '.json"' );
		echo wp_json_encode( $job, JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Render admin interface.
	 */
	public function render_page() {
		$jobs = $this->migration_manager->get_jobs();
		?>
		<div class="wrap lift-teleport">
			<div class="lift-teleport__hero">
				<div>
					<h1><?php esc_html_e( 'Teleport', 'lift-teleport' ); ?></h1>
					<p><?php esc_html_e( 'Migra cualquier web WordPress con un solo archivo .lift.', 'lift-teleport' ); ?></p>
				</div>
				<span class="lift-teleport__badge"><?php esc_html_e( 'Prototype', 'lift-teleport' ); ?></span>
			</div>

			<div class="lift-teleport__cards">
				<section class="lift-teleport__card">
					<h2><?php esc_html_e( 'Exportar sitio', 'lift-teleport' ); ?></h2>
					<p><?php esc_html_e( 'Empaqueta base de datos, media, plugins y temas en un archivo .lift.', 'lift-teleport' ); ?></p>
					<button class="button button-primary button-hero" type="button" disabled>
						<?php esc_html_e( 'Iniciar exportación (próximamente)', 'lift-teleport' ); ?>
					</button>
				</section>

				<section class="lift-teleport__card">
					<h2><?php esc_html_e( 'Importación automática', 'lift-teleport' ); ?></h2>
					<p><?php esc_html_e( 'Arrastra tu archivo .lift y Teleport se encarga de todo.', 'lift-teleport' ); ?></p>

					<label class="lift-teleport__dropzone" for="lift-teleport-file">
						<input id="lift-teleport-file" type="file" accept=".lift" />
						<strong><?php esc_html_e( 'Arrastra aquí tu archivo .lift', 'lift-teleport' ); ?></strong>
						<span><?php esc_html_e( 'o haz click para seleccionarlo', 'lift-teleport' ); ?></span>
					</label>

					<div class="lift-teleport__progress" aria-live="polite" aria-atomic="true">
						<div class="lift-teleport__progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
					</div>
					<p class="lift-teleport__status"><?php esc_html_e( 'Esperando archivo .lift…', 'lift-teleport' ); ?></p>
					<div class="lift-teleport__result" hidden>
						<p class="lift-teleport__result-message"></p>
						<details>
							<summary><?php esc_html_e( 'Ver detalle técnico', 'lift-teleport' ); ?></summary>
							<pre class="lift-teleport__result-technical"></pre>
						</details>
					</div>
				</section>
			</div>

			<section class="lift-teleport__history">
				<div class="lift-teleport__history-head">
					<h2><?php esc_html_e( 'Historial de migraciones', 'lift-teleport' ); ?></h2>
					<button type="button" class="button" id="lift-teleport-refresh-history"><?php esc_html_e( 'Actualizar', 'lift-teleport' ); ?></button>
				</div>
				<div class="lift-teleport__table-wrap">
					<table class="widefat striped" id="lift-teleport-history-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Job ID', 'lift-teleport' ); ?></th>
								<th><?php esc_html_e( 'Archivo', 'lift-teleport' ); ?></th>
								<th><?php esc_html_e( 'Estado', 'lift-teleport' ); ?></th>
								<th><?php esc_html_e( 'Fase', 'lift-teleport' ); ?></th>
								<th><?php esc_html_e( 'Duración', 'lift-teleport' ); ?></th>
								<th><?php esc_html_e( 'Chunks', 'lift-teleport' ); ?></th>
								<th><?php esc_html_e( 'Errores', 'lift-teleport' ); ?></th>
								<th><?php esc_html_e( 'Reporte', 'lift-teleport' ); ?></th>
							</tr>
						</thead>
						<tbody id="lift-teleport-history-body" data-empty-message="<?php echo esc_attr__( 'Aún no hay migraciones ejecutadas.', 'lift-teleport' ); ?>">
							<?php $this->render_jobs_rows( $jobs ); ?>
						</tbody>
					</table>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Render migration rows.
	 *
	 * @param array $jobs Jobs list.
	 */
	private function render_jobs_rows( array $jobs ) {
		if ( empty( $jobs ) ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Aún no hay migraciones ejecutadas.', 'lift-teleport' ) . '</td></tr>';
			return;
		}

		foreach ( $jobs as $job ) {
			$error_count = 0;
			if ( isset( $job['logs'] ) && is_array( $job['logs'] ) ) {
				foreach ( $job['logs'] as $log ) {
					if ( isset( $log['level'] ) && Lift_Teleport_Logger::LEVEL_ERROR === $log['level'] ) {
						$error_count++;
					}
				}
			}
			$report_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=lift_teleport_download_report&job_id=' . rawurlencode( $job['id'] ) ),
				'lift_teleport_download_report'
			);
			?>
			<tr>
				<td><code><?php echo esc_html( $job['id'] ); ?></code></td>
				<td><?php echo esc_html( $job['file_name'] ); ?></td>
				<td><?php echo esc_html( strtoupper( $job['status'] ) ); ?></td>
				<td><?php echo esc_html( $job['phase'] ); ?></td>
				<td><?php echo esc_html( intval( $job['duration_ms'] ) ); ?> ms</td>
				<td><?php echo esc_html( intval( $job['chunks_processed'] ) . '/' . intval( $job['chunks_total'] ) ); ?></td>
				<td><?php echo esc_html( (string) $error_count ); ?></td>
				<td><a class="button button-secondary" href="<?php echo esc_url( $report_url ); ?>"><?php esc_html_e( 'Descargar', 'lift-teleport' ); ?></a></td>
			</tr>
			<?php
		}
	}

	/**
	 * Validate nonce and capability for AJAX actions.
	 */
	private function assert_request_permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'lift-teleport' ) ), 403 );
		}

		check_ajax_referer( 'lift_teleport_admin_nonce', 'nonce' );
	}
}
