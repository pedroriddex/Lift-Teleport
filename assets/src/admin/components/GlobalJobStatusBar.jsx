import { __ } from '@wordpress/i18n';

export default function GlobalJobStatusBar( { job, label = '', onGoToMain } ) {
	if ( ! job ) {
		return null;
	}

	const progress = Math.max(
		0,
		Math.min( 100, Number( job.progress || 0 ) )
	);
	const typeLabel =
		job.type === 'import'
			? __( 'Import', 'lift-teleport' )
			: __( 'Export', 'lift-teleport' );

	return (
		<section className="lift-global-status" aria-live="polite">
			<div className="lift-global-status-head">
				<span className="lift-global-status-type">{ typeLabel }</span>
				<strong>{ progress.toFixed( 0 ) }%</strong>
			</div>
			<p className="lift-global-status-copy">
				{ label || __( 'Migration in progress…', 'lift-teleport' ) }
			</p>
			<div className="lift-global-status-track" role="presentation">
				<div
					className="lift-global-status-fill"
					style={ { width: `${ progress }%` } }
				/>
			</div>
			<button
				type="button"
				className="lift-global-status-link"
				onClick={ onGoToMain }
			>
				{ __( 'Go to Export / Import', 'lift-teleport' ) }
			</button>
		</section>
	);
}
