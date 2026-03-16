import { __, sprintf } from '@wordpress/i18n';
import ProgressBar from './ProgressBar';
import { getExportProgressLabel, isJobActive } from './progressLabels';

export default function ExportCard( { job, onStart, busy } ) {
	const status = job?.status || null;
	const isRunning = status === 'running' || status === 'pending';
	const isDone = status === 'completed';
	const downloadUrlRaw =
		job?.result?.download_path || job?.result?.download_url;
	const outputMetricBytes = Number(
		job?.payload?.step_metrics?.export_package?.output_bytes || 0
	);
	const packageSizeBytes = Number(
		job?.result?.package_size_bytes ||
			job?.payload?.package?.size ||
			outputMetricBytes ||
			0
	);
	const showPackageSize = packageSizeBytes > 0;
	const packageSizeLabel = showPackageSize
		? sprintf(
				/* translators: %s: package size (MB/GB). */
				__( 'Package size: %s', 'lift-teleport' ),
				formatBytes( packageSizeBytes )
		  )
		: '';

	let downloadUrl = downloadUrlRaw;
	if ( downloadUrlRaw && typeof window !== 'undefined' ) {
		try {
			const normalized = new window.URL(
				downloadUrlRaw,
				window.location.origin
			);
			downloadUrl = `${ window.location.origin }${ normalized.pathname }${ normalized.search }`;
		} catch ( _error ) {
			downloadUrl = downloadUrlRaw;
		}
	}

	return (
		<section
			className="lift-card"
			aria-label={ __( 'Export', 'lift-teleport' ) }
		>
			<header className="lift-card-head">
				<div className="lift-icon lift-icon-export" aria-hidden="true">
					⇪
				</div>
				<h2>{ __( 'Export', 'lift-teleport' ) }</h2>
			</header>

			<p className="lift-card-copy">
				{ __(
					'Export your website in a .lift file to import it anywhere, keeping your pages, posts, plugins and themes.',
					'lift-teleport'
				) }
			</p>

			{ job ? (
				<ProgressBar
					value={ job.progress }
					label={ getExportProgressLabel( job ) }
					isLoading={ isJobActive( job ) }
				/>
			) : null }

			{ isDone && downloadUrl ? (
				<>
					{ showPackageSize ? (
						<p className="lift-export-size">{ packageSizeLabel }</p>
					) : null }
					<a className="lift-download-link" href={ downloadUrl }>
						{ __( 'Download .lift file', 'lift-teleport' ) }
					</a>
				</>
			) : null }

			<button
				type="button"
				className="lift-cta"
				onClick={ onStart }
				disabled={ busy || isRunning }
			>
				{ isRunning
					? __( 'Exporting…', 'lift-teleport' )
					: __( 'Start exporting', 'lift-teleport' ) }
			</button>
		</section>
	);
}

function formatBytes( bytes ) {
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
