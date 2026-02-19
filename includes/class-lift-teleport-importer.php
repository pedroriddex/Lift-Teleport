<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lift_Teleport_Importer {
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
