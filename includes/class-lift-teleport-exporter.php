<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lift_Teleport_Exporter {
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
	 * Validates package payload before persisting export artifacts.
	 *
	 * @param array $header Header data.
	 * @param array $manifest Manifest data.
	 *
	 * @return true|WP_Error
	 */
	public function validate_export_payload( $header, $manifest ) {
		$header_validation = $this->validator->validate_header( $header );
		if ( is_wp_error( $header_validation ) ) {
			return $header_validation;
		}

		$manifest_validation = $this->validator->validate_manifest( $manifest );
		if ( is_wp_error( $manifest_validation ) ) {
			return $manifest_validation;
		}

		return true;
	}
}
