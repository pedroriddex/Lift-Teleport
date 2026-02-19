<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lift_Teleport_Package_Validator {
	/**
	 * Validate header payload.
	 *
	 * @param array $header Header data.
	 *
	 * @return true|WP_Error
	 */
	public function validate_header( $header ) {
		if ( ! is_array( $header ) ) {
			return $this->error( 'invalid_header', 'La cabecera del paquete debe ser un objeto JSON.' );
		}

		$required_keys = array( 'format', 'spec_version', 'created_at', 'plugin_version' );
		foreach ( $required_keys as $key ) {
			if ( empty( $header[ $key ] ) || ! is_string( $header[ $key ] ) ) {
				return $this->error( 'invalid_header', sprintf( 'La cabecera requiere el campo "%s".', $key ) );
			}
		}

		if ( 'lift' !== $header['format'] ) {
			return $this->error( 'invalid_format', 'El formato de paquete no es lift.' );
		}

		if ( ! $this->is_valid_semver( $header['spec_version'] ) ) {
			return $this->error( 'invalid_spec_version', 'spec_version debe usar semver (MAJOR.MINOR.PATCH).' );
		}

		if ( ! $this->is_valid_semver( $header['plugin_version'] ) ) {
			return $this->error( 'invalid_plugin_version', 'plugin_version debe usar semver (MAJOR.MINOR.PATCH).' );
		}

		return true;
	}

	/**
	 * Validate manifest payload.
	 *
	 * @param array $manifest Manifest data.
	 *
	 * @return true|WP_Error
	 */
	public function validate_manifest( $manifest ) {
		if ( ! is_array( $manifest ) ) {
			return $this->error( 'invalid_manifest', 'El manifiesto del paquete debe ser un objeto JSON.' );
		}

		$required_keys = array( 'spec_version', 'compatibility', 'environment', 'chunks', 'integrity', 'signature' );
		foreach ( $required_keys as $key ) {
			if ( ! array_key_exists( $key, $manifest ) ) {
				return $this->error( 'invalid_manifest', sprintf( 'El manifiesto requiere el campo "%s".', $key ) );
			}
		}

		if ( ! $this->is_valid_semver( $manifest['spec_version'] ) ) {
			return $this->error( 'invalid_manifest', 'spec_version del manifiesto es inválido.' );
		}

		$compatibility = $manifest['compatibility'];
		if ( ! is_array( $compatibility ) || empty( $compatibility['min_importer_plugin_version'] ) || empty( $compatibility['max_importer_plugin_version'] ) ) {
			return $this->error( 'invalid_manifest', 'compatibility debe incluir min_importer_plugin_version y max_importer_plugin_version.' );
		}

		if ( ! $this->is_valid_semver( $compatibility['min_importer_plugin_version'] ) ) {
			return $this->error( 'invalid_manifest', 'min_importer_plugin_version no es semver válido.' );
		}

		if ( ! $this->is_valid_semver_or_major_wildcard( $compatibility['max_importer_plugin_version'] ) ) {
			return $this->error( 'invalid_manifest', 'max_importer_plugin_version debe ser semver o <major>.x.' );
		}

		$environment = $manifest['environment'];
		$required_environment = array( 'wordpress_version', 'php_version', 'charset', 'active_plugins' );
		if ( ! is_array( $environment ) ) {
			return $this->error( 'invalid_manifest', 'environment debe ser un objeto.' );
		}

		foreach ( $required_environment as $key ) {
			if ( ! array_key_exists( $key, $environment ) ) {
				return $this->error( 'invalid_manifest', sprintf( 'environment requiere "%s".', $key ) );
			}
		}

		if ( ! is_array( $environment['active_plugins'] ) ) {
			return $this->error( 'invalid_manifest', 'environment.active_plugins debe ser una lista.' );
		}

		$chunks_validation = $this->validate_chunks( $manifest['chunks'] );
		if ( is_wp_error( $chunks_validation ) ) {
			return $chunks_validation;
		}

		$integrity = $manifest['integrity'];
		if ( ! is_array( $integrity ) || empty( $integrity['algorithm'] ) || empty( $integrity['global_hash'] ) ) {
			return $this->error( 'invalid_manifest', 'integrity debe incluir algorithm y global_hash.' );
		}

		if ( ! $this->is_prefixed_hash( $integrity['global_hash'] ) ) {
			return $this->error( 'invalid_manifest', 'integrity.global_hash debe tener formato "sha256:<hex>".' );
		}

		$signature = $manifest['signature'];
		if ( ! is_array( $signature ) || ! array_key_exists( 'enabled', $signature ) ) {
			return $this->error( 'invalid_manifest', 'signature debe existir e incluir enabled.' );
		}

		if ( ! empty( $signature['enabled'] ) && empty( $signature['manifest_signature'] ) ) {
			return $this->error( 'invalid_manifest', 'signature.manifest_signature es obligatoria cuando signature.enabled=true.' );
		}

		return true;
	}

	/**
	 * Validate chunks list.
	 *
	 * @param mixed $chunks Chunk list.
	 *
	 * @return true|WP_Error
	 */
	public function validate_chunks( $chunks ) {
		if ( ! is_array( $chunks ) || empty( $chunks ) ) {
			return $this->error( 'invalid_manifest', 'El manifiesto debe incluir al menos un chunk.' );
		}

		foreach ( $chunks as $index => $chunk ) {
			if ( ! is_array( $chunk ) ) {
				return $this->error( 'invalid_chunk', sprintf( 'El chunk #%d no es un objeto válido.', $index ) );
			}

			$required = array( 'id', 'type', 'path', 'size', 'hash' );
			foreach ( $required as $key ) {
				if ( ! array_key_exists( $key, $chunk ) || '' === (string) $chunk[ $key ] ) {
					return $this->error( 'invalid_chunk', sprintf( 'El chunk #%d requiere "%s".', $index, $key ) );
				}
			}

			if ( ! is_numeric( $chunk['size'] ) || (int) $chunk['size'] < 0 ) {
				return $this->error( 'invalid_chunk', sprintf( 'El chunk %s tiene tamaño inválido.', $chunk['id'] ) );
			}

			if ( ! $this->is_prefixed_hash( $chunk['hash'] ) ) {
				return $this->error( 'invalid_chunk', sprintf( 'El chunk %s tiene hash inválido. Formato esperado: "sha256:<hex>".', $chunk['id'] ) );
			}
		}

		return true;
	}

	/**
	 * Validate plugin compatibility for importer.
	 *
	 * @param array  $manifest Manifest data.
	 * @param string $importer_plugin_version Current importer plugin version.
	 *
	 * @return true|WP_Error
	 */
	public function validate_importer_compatibility( $manifest, $importer_plugin_version ) {
		if ( empty( $manifest['compatibility'] ) || ! is_array( $manifest['compatibility'] ) ) {
			return $this->error( 'invalid_manifest', 'Falta bloque compatibility en el manifiesto.' );
		}

		$min_version = $manifest['compatibility']['min_importer_plugin_version'];
		$max_version = $manifest['compatibility']['max_importer_plugin_version'];

		if ( version_compare( $importer_plugin_version, $min_version, '<' ) ) {
			return $this->error(
				'unsupported_importer_version',
				sprintf( 'La versión del plugin (%1$s) es menor que el mínimo requerido (%2$s).', $importer_plugin_version, $min_version ),
				array(
					'importer_version' => $importer_plugin_version,
					'minimum_required' => $min_version,
				)
			);
		}

		if ( $this->is_major_wildcard( $max_version ) ) {
			$max_major      = (int) strtok( $max_version, '.' );
			$importer_major = (int) strtok( $importer_plugin_version, '.' );
			if ( $importer_major > $max_major ) {
				return $this->error(
					'unsupported_importer_version',
					sprintf( 'La versión del plugin (%1$s) excede el major compatible (%2$s).', $importer_plugin_version, $max_version ),
					array(
						'importer_version' => $importer_plugin_version,
						'maximum_supported' => $max_version,
					)
				);
			}
		} elseif ( version_compare( $importer_plugin_version, $max_version, '>' ) ) {
			return $this->error(
				'unsupported_importer_version',
				sprintf( 'La versión del plugin (%1$s) es mayor que el máximo compatible (%2$s).', $importer_plugin_version, $max_version ),
				array(
					'importer_version' => $importer_plugin_version,
					'maximum_supported' => $max_version,
				)
			);
		}

		return true;
	}

	/**
	 * Build a standard WP_Error with optional context.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @param array  $context Extra context.
	 *
	 * @return WP_Error
	 */
	private function error( $code, $message, $context = array() ) {
		return new WP_Error(
			$code,
			$message,
			array(
				'context' => $context,
			)
		);
	}

	/**
	 * Semver validator.
	 *
	 * @param string $value Version.
	 *
	 * @return bool
	 */
	private function is_valid_semver( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^\d+\.\d+\.\d+$/', $value );
	}

	/**
	 * Semver or major wildcard validator.
	 *
	 * @param string $value Version.
	 *
	 * @return bool
	 */
	private function is_valid_semver_or_major_wildcard( $value ) {
		return $this->is_valid_semver( $value ) || $this->is_major_wildcard( $value );
	}

	/**
	 * Check wildcard pattern like 1.x.
	 *
	 * @param string $value Version.
	 *
	 * @return bool
	 */
	private function is_major_wildcard( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^\d+\.x$/', $value );
	}

	/**
	 * Check prefixed hash format algorithm:hex.
	 *
	 * @param string $value Hash string.
	 *
	 * @return bool
	 */
	private function is_prefixed_hash( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-z0-9]+:[a-f0-9]{32,128}$/', strtolower( $value ) );
	}
}
