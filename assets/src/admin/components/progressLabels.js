import { __, sprintf } from '@wordpress/i18n';

const EXPORT_STEP_LABELS = {
	export_validate: __( 'Running export pre-checks…', 'lift-teleport' ),
	export_dump_database: __(
		'Dumping database tables safely…',
		'lift-teleport'
	),
	export_build_manifest: __(
		'Building package manifest and metadata…',
		'lift-teleport'
	),
	export_package: __( 'Packaging files into .lift…', 'lift-teleport' ),
	export_finalize: __(
		'Signing download and finalizing export…',
		'lift-teleport'
	),
};

const IMPORT_STEP_LABELS = {
	import_validate_package: __(
		'Validating selected .lift package…',
		'lift-teleport'
	),
	import_precheck_space: __(
		'Checking disk space and environment…',
		'lift-teleport'
	),
	import_capture_merge_admin: __(
		'Capturing destination admin continuity…',
		'lift-teleport'
	),
	import_snapshot: __(
		'Creating rollback snapshot before changes…',
		'lift-teleport'
	),
	import_readonly_on: __(
		'Enabling read-only mode during import…',
		'lift-teleport'
	),
	import_extract_package: __(
		'Extracting and verifying package integrity…',
		'lift-teleport'
	),
	import_restore_database: __(
		'Restoring database in resumable mode…',
		'lift-teleport'
	),
	import_sync_content: __(
		'Synchronizing wp-content files…',
		'lift-teleport'
	),
	import_finalize: __(
		'Finalizing import and flushing caches…',
		'lift-teleport'
	),
};

const UNZIPPER_STEP_LABELS = {
	unzipper_validate_package: __(
		'Validating .lift package for inspection…',
		'lift-teleport'
	),
	unzipper_quick_scan: __(
		'Running quick scan and building file tree…',
		'lift-teleport'
	),
	unzipper_full_integrity: __(
		'Running full integrity verification…',
		'lift-teleport'
	),
	unzipper_finalize: __(
		'Finalizing Unzipper diagnostics…',
		'lift-teleport'
	),
};

const TERMINAL_STATUSES = [
	'completed',
	'failed',
	'failed_rollback',
	'cancelled',
];

function asMetric( job ) {
	return job?.payload?.step_metrics?.[ job?.current_step ] || null;
}

function compactTable( tableName ) {
	if ( typeof tableName !== 'string' || ! tableName ) {
		return '';
	}

	return tableName.length > 44 ? `${ tableName.slice( 0, 42 ) }…` : tableName;
}

function restoreDatabaseLabel( job ) {
	const metric = asMetric( job );
	const message = ( job?.message || '' ).toString().trim();

	if ( message.includes( 'serialized-safe replacements' ) ) {
		if ( metric?.current_table && metric?.total_tables ) {
			const tableIndex = Number( metric.table_index || 0 );
			const tablePos = Math.min(
				Number( metric.total_tables ),
				Math.max( 1, tableIndex + 1 )
			);

			/* translators: 1: processed table index, 2: total tables, 3: current table name. */
			const replacementsLabel = __(
				'Applying serialized-safe replacements… (%1$d/%2$d, %3$s)',
				'lift-teleport'
			);
			return sprintf(
				replacementsLabel,
				tablePos,
				Number( metric.total_tables ),
				compactTable( metric.current_table )
			);
		}

		return __( 'Applying serialized-safe replacements…', 'lift-teleport' );
	}

	if ( metric?.statements_executed ) {
		/* translators: %d: executed SQL statements. */
		const sqlStatementsLabel = __(
			'Restoring SQL statements… (%d executed)',
			'lift-teleport'
		);
		return sprintf(
			sqlStatementsLabel,
			Number( metric.statements_executed )
		);
	}

	return IMPORT_STEP_LABELS.import_restore_database;
}

function formatCompactBytes( bytes ) {
	const value = Number( bytes || 0 );
	if ( ! Number.isFinite( value ) || value <= 0 ) {
		return '0 B';
	}

	const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	const power = Math.min(
		units.length - 1,
		Math.floor( Math.log( value ) / Math.log( 1024 ) )
	);
	const sized = value / 1024 ** power;
	let precision = 2;
	if ( sized >= 100 ) {
		precision = 0;
	} else if ( sized >= 10 ) {
		precision = 1;
	}

	return `${ sized.toFixed( precision ) } ${ units[ power ] }`;
}

function exportPackageLabel( job ) {
	const metric = asMetric( job );
	const phase = ( metric?.phase || '' ).toString();
	const metricSuffix = () => {
		const filesDone = Number( metric?.files_done || 0 );
		const filesTotal = Number( metric?.files_total || 0 );

		if ( filesTotal > 0 ) {
			return ` (${ Math.max( 0, filesDone ) }/${ Math.max(
				filesDone,
				filesTotal
			) })`;
		}

		const bytesDone = Number( metric?.bytes_done || 0 );
		const bytesTotal = Number( metric?.bytes_total || 0 );
		if ( bytesTotal > 0 ) {
			return ` (${ formatCompactBytes(
				bytesDone
			) } / ${ formatCompactBytes( bytesTotal ) })`;
		}

		return '';
	};

	switch ( phase ) {
		case 'scan_content':
			return `${ __(
				'Scanning files…',
				'lift-teleport'
			) }${ metricSuffix() }`;
		case 'checksum_payload':
			return `${ __(
				'Checksumming payload…',
				'lift-teleport'
			) }${ metricSuffix() }`;
		case 'tar_build':
			return `${ __(
				'Building tar archive…',
				'lift-teleport'
			) }${ metricSuffix() }`;
		case 'compress_package':
			const status = ( metric?.status || '' ).toString();
			const algorithm = ( metric?.algorithm || '' ).toString();
			if ( status === 'stalled' ) {
				/* translators: %s: compression algorithm. */
				const stalledLabel = __(
					'Compression stalled on %s. Switching fallback…',
					'lift-teleport'
				);
				return sprintf(
					stalledLabel,
					algorithm ? algorithm.toUpperCase() : 'AUTO'
				);
			}

			/* translators: 1: compression algorithm, 2: elapsed seconds, 3: attempt number, 4: fallback stage, 5: output bytes. */
			const compressLabel = __(
				'Compressing package… (%1$s, %2$ss, attempt %3$d, %4$s, out %5$s)',
				'lift-teleport'
			);
			return sprintf(
				compressLabel,
				algorithm ? algorithm.toUpperCase() : 'AUTO',
				Math.max(
					0,
					Math.round( Number( metric?.elapsed_seconds || 0 ) )
				),
				Math.max( 1, Number( metric?.attempt || 1 ) ),
				( metric?.fallback_stage || 'primary' ).toString(),
				formatCompactBytes( Number( metric?.output_bytes || 0 ) )
			);
		case 'encrypt_package':
			const bytesDone = Number( metric?.bytes_done || 0 );
			const bytesTotal = Number( metric?.bytes_total || 0 );
			return `${ __(
				'Encrypting package…',
				'lift-teleport'
			) } (${ formatCompactBytes( bytesDone ) } / ${ formatCompactBytes(
				bytesTotal
			) })`;
		case 'finalize_package':
			return __( 'Finalizing .lift…', 'lift-teleport' );
		default:
			return EXPORT_STEP_LABELS.export_package;
	}
}

function resolveLabelByStep( job, type ) {
	const step = ( job?.current_step || '' ).toString();
	if ( ! step ) {
		return '';
	}

	if ( type === 'import' && step === 'import_restore_database' ) {
		return restoreDatabaseLabel( job );
	}

	if ( type === 'export' ) {
		if ( step === 'export_package' ) {
			return exportPackageLabel( job );
		}
		return EXPORT_STEP_LABELS[ step ] || '';
	}

	if ( type === 'import' && step === 'import_maintenance_on' ) {
		return IMPORT_STEP_LABELS.import_readonly_on;
	}

	return IMPORT_STEP_LABELS[ step ] || '';
}

export function isJobActive( job ) {
	const status = ( job?.status || '' ).toString();
	return status && ! TERMINAL_STATUSES.includes( status );
}

export function getExportProgressLabel( job ) {
	const status = ( job?.status || '' ).toString();

	if ( status === 'completed' ) {
		return __(
			'Export completed. Your .lift file is ready.',
			'lift-teleport'
		);
	}

	if ( status === 'cancelled' ) {
		return __( 'Export cancelled by user.', 'lift-teleport' );
	}

	if ( status === 'failed' || status === 'failed_rollback' ) {
		const message = ( job?.message || '' ).toString().trim();
		return message || __( 'Export failed.', 'lift-teleport' );
	}

	if ( status === 'pending' ) {
		return __( 'Export queued. Worker is starting…', 'lift-teleport' );
	}

	const message = ( job?.message || '' ).toString().trim();
	return (
		resolveLabelByStep( job, 'export' ) ||
		message ||
		__( 'Preparing export…', 'lift-teleport' )
	);
}

export function getImportProgressLabel( job, uploadProgress = 0 ) {
	const status = ( job?.status || '' ).toString();

	if ( status === 'uploading' ) {
		const percent = Math.max(
			0,
			Math.min( 100, Math.round( Number( uploadProgress ) ) )
		);

		if ( percent > 0 && percent < 100 ) {
			return __( 'Waiting for upload to finish…', 'lift-teleport' );
		}

		return __( 'Waiting for upload to finish…', 'lift-teleport' );
	}

	if ( status === 'completed' ) {
		return __( 'Import completed successfully.', 'lift-teleport' );
	}

	if ( status === 'cancelled' ) {
		return __( 'Import cancelled by user.', 'lift-teleport' );
	}

	if ( status === 'failed' || status === 'failed_rollback' ) {
		const message = ( job?.message || '' ).toString().trim();
		return message || __( 'Import failed.', 'lift-teleport' );
	}

	if ( status === 'pending' ) {
		return __( 'Import queued. Worker is starting…', 'lift-teleport' );
	}

	const message = ( job?.message || '' ).toString().trim();
	return (
		resolveLabelByStep( job, 'import' ) ||
		message ||
		__( 'Processing import…', 'lift-teleport' )
	);
}

export function getUnzipperProgressLabel( job, uploadProgress = 0 ) {
	const status = ( job?.status || '' ).toString();

	if ( status === 'uploading' ) {
		/* translators: %d: upload percentage. */
		const uploadChunksLabel = __(
			'Uploading .lift package chunks… (%d%%)',
			'lift-teleport'
		);
		return sprintf(
			uploadChunksLabel,
			Math.max(
				0,
				Math.min( 100, Math.round( Number( uploadProgress ) ) )
			)
		);
	}

	if ( status === 'completed' ) {
		const fullStatus = ( job?.result?.full_status || '' ).toString();
		if ( fullStatus === 'skipped_low_disk' ) {
			return __(
				'Quick scan completed. Full integrity skipped (low disk).',
				'lift-teleport'
			);
		}

		if ( fullStatus === 'failed' ) {
			return __(
				'Quick scan completed. Full integrity failed.',
				'lift-teleport'
			);
		}

		return __( 'Unzipper analysis completed.', 'lift-teleport' );
	}

	if ( status === 'cancelled' ) {
		return __( 'Unzipper cancelled by user.', 'lift-teleport' );
	}

	if ( status === 'failed' || status === 'failed_rollback' ) {
		const message = ( job?.message || '' ).toString().trim();
		return message || __( 'Unzipper failed.', 'lift-teleport' );
	}

	if ( status === 'pending' ) {
		return __( 'Unzipper queued. Worker is starting…', 'lift-teleport' );
	}

	const step = ( job?.current_step || '' ).toString();
	if ( step && UNZIPPER_STEP_LABELS[ step ] ) {
		return UNZIPPER_STEP_LABELS[ step ];
	}

	const message = ( job?.message || '' ).toString().trim();
	return message || __( 'Processing Unzipper…', 'lift-teleport' );
}
