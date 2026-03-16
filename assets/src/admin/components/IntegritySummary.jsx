import { __ } from '@wordpress/i18n';

export default function IntegritySummary( {
	summary = {},
	quickReport = {},
	fullReport = {},
} ) {
	const quickStatus = String(
		summary?.quick_status || quickReport?.status || 'pending'
	);
	const fullStatus = String(
		summary?.full_status || fullReport?.status || 'pending'
	);
	const counts = summary?.entry_counts || quickReport?.entry_counts || {};

	return (
		<section className="lift-unzipper-summary" aria-live="polite">
			<header className="lift-unzipper-summary-head">
				<h3>{ __( 'Integrity diagnostics', 'lift-teleport' ) }</h3>
				<div className="lift-unzipper-badges">
					<StatusBadge
						label={ __( 'Quick scan', 'lift-teleport' ) }
						status={ quickStatus }
					/>
					<StatusBadge
						label={ __( 'Full integrity', 'lift-teleport' ) }
						status={ fullStatus }
					/>
				</div>
			</header>

			<ul className="lift-unzipper-summary-grid">
				<li>
					<strong>{ __( 'Entries', 'lift-teleport' ) }</strong>
					<span>{ Number( counts?.total || 0 ) }</span>
				</li>
				<li>
					<strong>{ __( 'Files', 'lift-teleport' ) }</strong>
					<span>{ Number( counts?.file || 0 ) }</span>
				</li>
				<li>
					<strong>{ __( 'Directories', 'lift-teleport' ) }</strong>
					<span>{ Number( counts?.dir || 0 ) }</span>
				</li>
				<li>
					<strong>{ __( 'Encrypted', 'lift-teleport' ) }</strong>
					<span>
						{ summary?.encrypted
							? __( 'Yes', 'lift-teleport' )
							: __( 'No', 'lift-teleport' ) }
					</span>
				</li>
			</ul>

			{ fullStatus === 'failed' && fullReport?.message ? (
				<p className="lift-error">{ fullReport.message }</p>
			) : null }
			{ fullStatus === 'skipped_low_disk' ? (
				<p className="lift-info">
					{ __(
						'Full integrity was skipped due to low disk space. Quick scan results are still available.',
						'lift-teleport'
					) }
				</p>
			) : null }
		</section>
	);
}

function StatusBadge( { label, status } ) {
	const normalized = String( status || 'pending' ).toLowerCase();
	let tone = 'is-pending';
	if ( normalized === 'passed' ) {
		tone = 'is-success';
	} else if ( normalized === 'failed' ) {
		tone = 'is-error';
	} else if ( normalized === 'skipped_low_disk' ) {
		tone = 'is-warning';
	}

	return (
		<span className={ `lift-unzipper-badge ${ tone }` }>
			<small>{ label }</small>
			<strong>{ normalized.replace( /_/g, ' ' ) }</strong>
		</span>
	);
}
