import { __ } from '@wordpress/i18n';

export default function ProgressBar( {
	value = 0,
	label = '',
	isLoading = false,
} ) {
	const percent = Math.max( 0, Math.min( 100, Number( value || 0 ) ) );

	return (
		<div className="lift-progress-wrap" aria-live="polite">
			<div className="lift-progress-labels">
				<span className="lift-progress-label-main">
					{ isLoading ? (
						<span
							className="lift-progress-spinner"
							aria-hidden="true"
						/>
					) : null }
					<span>
						{ label || __( 'Processing', 'lift-teleport' ) }
					</span>
				</span>
				<strong>{ percent.toFixed( 0 ) }%</strong>
			</div>
			<div
				className="lift-progress-track"
				role="progressbar"
				aria-valuemin={ 0 }
				aria-valuemax={ 100 }
				aria-valuenow={ percent }
			>
				<div
					className="lift-progress-fill"
					style={ { width: `${ percent }%` } }
				/>
			</div>
		</div>
	);
}
