<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lift_Teleport_Migration_Manager {
	const OPTION_KEY = 'lift_teleport_migration_jobs';

	/**
	 * @var Lift_Teleport_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Lift_Teleport_Logger $logger Logger service.
	 */
	public function __construct( Lift_Teleport_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Simulate an import process and persist full telemetry per job.
	 *
	 * @param string $file_name Imported file name.
	 * @return array
	 */
	public function run_import( $file_name ) {
		$job_id     = wp_generate_uuid4();
		$started_at = microtime( true );
		$chunks     = wp_rand( 4, 8 );
		$job        = array(
			'id'               => $job_id,
			'file_name'        => sanitize_file_name( $file_name ),
			'status'           => 'running',
			'started_at'       => current_time( 'mysql' ),
			'ended_at'         => '',
			'duration_ms'      => 0,
			'chunks_total'     => $chunks,
			'chunks_processed' => 0,
			'phase'            => 'bootstrap',
			'user_message'     => __( 'Importación en curso…', 'lift-teleport' ),
			'technical_detail' => '',
			'logs'             => array(),
		);

		$job = $this->logger->info( $job, 'bootstrap', 'LT-IMP-START', __( 'Se inicia la migración.', 'lift-teleport' ), array( 'file' => $job['file_name'] ) );

		$phases = array(
			'validate-package' => __( 'Validando paquete .lift', 'lift-teleport' ),
			'import-database'  => __( 'Importando base de datos', 'lift-teleport' ),
			'rebuild-assets'   => __( 'Reconstruyendo recursos', 'lift-teleport' ),
		);

		foreach ( $phases as $phase_key => $phase_label ) {
			$job['phase'] = $phase_key;
			$job          = $this->logger->info( $job, $phase_key, 'LT-IMP-PHASE-START', sprintf( __( 'Fase iniciada: %s', 'lift-teleport' ), $phase_label ) );

			for ( $chunk = 1; $chunk <= $chunks; $chunk++ ) {
				$job['chunks_processed']++;
				$job = $this->logger->info(
					$job,
					$phase_key,
					'LT-IMP-CHUNK-PROCESSED',
					sprintf( __( 'Chunk %1$d/%2$d procesado.', 'lift-teleport' ), $chunk, $chunks ),
					array( 'chunk' => $chunk, 'chunks_total' => $chunks )
				);

				if ( 'import-database' === $phase_key && $chunk === $chunks && wp_rand( 1, 10 ) <= 2 ) {
					$job['status']           = 'failed';
					$job['phase']            = 'import-database';
					$job['user_message']     = __( 'No pudimos completar la importación de la base de datos.', 'lift-teleport' );
					$job['technical_detail'] = __( 'Error en escritura de chunk final SQL. Revisa el reporte técnico y comparte el código con soporte.', 'lift-teleport' );
					$job                    = $this->logger->error(
						$job,
						'import-database',
						'LT-IMP-DB-CHUNK-FAIL',
						__( 'Falló la escritura del chunk SQL.', 'lift-teleport' ),
						array(
							'chunk'       => $chunk,
							'total_chunks'=> $chunks,
							'sql_file'    => 'database.sql',
						)
					);
					return $this->complete_job( $job, $started_at );
				}
			}

			$job = $this->logger->info( $job, $phase_key, 'LT-IMP-PHASE-END', sprintf( __( 'Fase completada: %s', 'lift-teleport' ), $phase_label ) );
		}

		$job['status']           = 'completed';
		$job['phase']            = 'done';
		$job['user_message']     = __( 'Importación completada correctamente.', 'lift-teleport' );
		$job['technical_detail'] = __( 'Todos los chunks fueron procesados y verificados.', 'lift-teleport' );
		$job                    = $this->logger->info( $job, 'done', 'LT-IMP-SUCCESS', __( 'Migración completada.', 'lift-teleport' ) );

		return $this->complete_job( $job, $started_at );
	}

	/**
	 * Persist and return the current job with end metrics.
	 *
	 * @param array $job Migration job.
	 * @param float $started_at Start timestamp with microseconds.
	 * @return array
	 */
	private function complete_job( array $job, $started_at ) {
		$job['ended_at']    = current_time( 'mysql' );
		$job['duration_ms'] = (int) round( ( microtime( true ) - $started_at ) * 1000 );
		$job                = $this->logger->info( $job, $job['phase'], 'LT-IMP-METRICS', __( 'Métricas de ejecución registradas.', 'lift-teleport' ), array(
			'duration_ms'      => $job['duration_ms'],
			'chunks_processed' => $job['chunks_processed'],
			'chunks_total'     => $job['chunks_total'],
		) );

		$jobs = $this->get_jobs();
		array_unshift( $jobs, $job );
		update_option( self::OPTION_KEY, array_slice( $jobs, 0, 50 ), false );

		return $job;
	}

	/**
	 * Retrieve all jobs.
	 *
	 * @return array
	 */
	public function get_jobs() {
		$jobs = get_option( self::OPTION_KEY, array() );
		return is_array( $jobs ) ? $jobs : array();
	}

	/**
	 * Find one job by ID.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null
	 */
	public function get_job( $job_id ) {
		foreach ( $this->get_jobs() as $job ) {
			if ( isset( $job['id'] ) && $job_id === $job['id'] ) {
				return $job;
			}
		}

		return null;
	}
}
