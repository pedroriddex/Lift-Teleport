<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lift_Teleport_Logger {
	const LEVEL_INFO  = 'info';
	const LEVEL_WARN  = 'warn';
	const LEVEL_ERROR = 'error';

	/**
	 * Append an info log entry.
	 *
	 * @param array  $job    Migration job.
	 * @param string $phase  Migration phase.
	 * @param string $code   Stable support code.
	 * @param string $message Human readable message.
	 * @param array  $context Technical context.
	 * @return array
	 */
	public function info( array $job, $phase, $code, $message, array $context = array() ) {
		return $this->append( $job, self::LEVEL_INFO, $phase, $code, $message, $context );
	}

	/**
	 * Append a warning log entry.
	 *
	 * @param array  $job    Migration job.
	 * @param string $phase  Migration phase.
	 * @param string $code   Stable support code.
	 * @param string $message Human readable message.
	 * @param array  $context Technical context.
	 * @return array
	 */
	public function warn( array $job, $phase, $code, $message, array $context = array() ) {
		return $this->append( $job, self::LEVEL_WARN, $phase, $code, $message, $context );
	}

	/**
	 * Append an error log entry.
	 *
	 * @param array  $job    Migration job.
	 * @param string $phase  Migration phase.
	 * @param string $code   Stable support code.
	 * @param string $message Human readable message.
	 * @param array  $context Technical context.
	 * @return array
	 */
	public function error( array $job, $phase, $code, $message, array $context = array() ) {
		return $this->append( $job, self::LEVEL_ERROR, $phase, $code, $message, $context );
	}

	/**
	 * Create and append a structured log entry for a job.
	 *
	 * @param array  $job     Migration job.
	 * @param string $level   Log level.
	 * @param string $phase   Migration phase.
	 * @param string $code    Stable support code.
	 * @param string $message Human readable message.
	 * @param array  $context Technical context.
	 * @return array
	 */
	private function append( array $job, $level, $phase, $code, $message, array $context ) {
		if ( ! isset( $job['logs'] ) || ! is_array( $job['logs'] ) ) {
			$job['logs'] = array();
		}

		$job['logs'][] = array(
			'timestamp' => current_time( 'mysql' ),
			'level'     => $level,
			'phase'     => $phase,
			'code'      => $code,
			'message'   => $message,
			'context'   => $context,
		);

		return $job;
	}
}
