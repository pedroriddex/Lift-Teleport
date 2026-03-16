import { __ } from '@wordpress/i18n';
import ProgressBar from './ProgressBar';
import { getImportProgressLabel, isJobActive } from './progressLabels';

export default function ImportCard( {
	file,
	onFileChange,
	onImport,
	uploadProgress,
	job,
	busy,
} ) {
	const status = job?.status || null;
	const isRunning =
		status === 'running' || status === 'pending' || status === 'uploading';

	return (
		<section
			className="lift-card"
			aria-label={ __( 'Import', 'lift-teleport' ) }
		>
			<header className="lift-card-head">
				<div className="lift-icon lift-icon-import" aria-hidden="true">
					⇦
				</div>
				<h2>{ __( 'Import', 'lift-teleport' ) }</h2>
			</header>

			<label className="lift-dropzone" htmlFor="lift-file-input">
				<input
					id="lift-file-input"
					type="file"
					accept=".lift"
					onChange={ ( event ) =>
						onFileChange( event.target.files?.[ 0 ] || null )
					}
					hidden
					disabled={ busy || isRunning }
				/>
				<span>
					{ file
						? file.name
						: __( 'Drag your .lift file here', 'lift-teleport' ) }
				</span>
			</label>

			{ file ? (
				<p className="lift-file-meta">{ `${ (
					file.size /
					( 1024 * 1024 )
				).toFixed( 2 ) } MB` }</p>
			) : null }

			{ uploadProgress > 0 && uploadProgress < 100 ? (
				<ProgressBar
					value={ uploadProgress }
					label={ __(
						'Uploading .lift package chunks…',
						'lift-teleport'
					) }
					isLoading
				/>
			) : null }

			{ job ? (
				<ProgressBar
					value={ job.progress }
					label={ getImportProgressLabel( job, uploadProgress ) }
					isLoading={ isJobActive( job ) }
				/>
			) : null }

			<button
				type="button"
				className="lift-cta lift-cta-secondary"
				onClick={ onImport }
				disabled={ busy || isRunning || ! file }
			>
				{ isRunning
					? __( 'Importing…', 'lift-teleport' )
					: __( 'Start importing', 'lift-teleport' ) }
			</button>
		</section>
	);
}
