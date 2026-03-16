import { __ } from '@wordpress/i18n';

function statusLabel( status ) {
	switch ( status ) {
		case 'pass':
			return __( 'Pass', 'lift-teleport' );
		case 'degraded':
			return __( 'Degraded', 'lift-teleport' );
		case 'fail':
		default:
			return __( 'Fail', 'lift-teleport' );
	}
}

function statusClass( status ) {
	switch ( status ) {
		case 'pass':
			return 'is-pass';
		case 'degraded':
			return 'is-degraded';
		case 'fail':
		default:
			return 'is-fail';
	}
}

export default function DiagnosticsPanel( {
	events = [],
	activeJob = null,
	activeDiagnostics = {},
	latestMetric = null,
	schema = null,
	environment = null,
	capabilities = null,
	recommendedExecution = null,
	activeExecutionPlan = null,
	activeExecutionMode = '',
	activeExecutionFallback = '',
	onRefreshCapabilities = null,
} ) {
	const schemaHealthy = Boolean( schema?.health );
	const missingColumns = Object.entries( schema?.missing_columns || {} )
		.map( ( [ table, columns ] ) => {
			if ( ! Array.isArray( columns ) || ! columns.length ) {
				return null;
			}
			return `${ table }: ${ columns.join( ', ' ) }`;
		} )
		.filter( Boolean );
	const tests = capabilities?.tests || {};
	const tarStatus = ( tests?.tar_gzip?.status || 'fail' ).toString();
	const procStatus = ( tests?.proc_open?.status || 'fail' ).toString();
	const cliStatus = ( tests?.cli?.status || 'fail' ).toString();
	const warnings = Array.isArray( capabilities?.decisions?.warnings )
		? capabilities.decisions.warnings
		: [];
	const recommendedRunner = (
		recommendedExecution?.runner ||
		capabilities?.decisions?.runner ||
		''
	).toString();
	const recommendedArchive = (
		recommendedExecution?.archive_engine ||
		capabilities?.decisions?.archive_engine ||
		''
	).toString();
	const preflightSource = ( capabilities?.source || '' ).toString();
	const preflightCheckedAt = (
		environment?.preflight_checked_at ||
		capabilities?.checked_at ||
		''
	).toString();
	const hostProfile = ( capabilities?.host_profile || '' ).toString();
	const activePlanRunner = (
		activeExecutionPlan?.runner || ''
	).toString();
	const activePlanRisk = (
		activeExecutionPlan?.risk_level || ''
	).toString();
	const showCapabilities = Boolean( capabilities && Object.keys( tests ).length );

	return (
		<section
			className="lift-panel lift-panel-diagnostics"
			aria-label={ __( 'Diagnostics', 'lift-teleport' ) }
		>
			<div className="lift-panel-grid">
				<section
					className="lift-events"
					aria-label={ __( 'Job events', 'lift-teleport' ) }
				>
					<h3>{ __( 'Latest events', 'lift-teleport' ) }</h3>
					{ events.length ? (
						<ul>
							{ events.slice( 0, 12 ).map( ( event ) => (
								<li key={ event.id }>
									<strong>{ event.level }</strong>
									<span>{ event.message }</span>
								</li>
							) ) }
						</ul>
					) : (
						<p className="lift-empty-copy">
							{ __( 'No events recorded yet.', 'lift-teleport' ) }
						</p>
					) }
				</section>

				<section
					className="lift-events lift-diagnostics"
					aria-label={ __( 'Runtime diagnostics', 'lift-teleport' ) }
				>
					<h3>{ __( 'Diagnostics', 'lift-teleport' ) }</h3>
					{ activeJob ? (
						<ul>
							<li>
								<strong>
									{ __( 'Status', 'lift-teleport' ) }
								</strong>
								<span>{ activeJob.status || 'n/a' }</span>
							</li>
							<li>
								<strong>
									{ __( 'Step', 'lift-teleport' ) }
								</strong>
								<span>{ activeJob.current_step || 'n/a' }</span>
							</li>
							<li>
								<strong>
									{ __( 'Attempts', 'lift-teleport' ) }
								</strong>
								<span>{ activeJob.attempts || 0 }</span>
							</li>
							<li>
								<strong>
									{ __( 'Progress', 'lift-teleport' ) }
								</strong>
								<span>
									{ Math.max(
										0,
										Math.min(
											100,
											Number( activeJob.progress || 0 )
										)
									).toFixed( 0 ) }
									%
								</span>
							</li>
							{ activeDiagnostics?.last_step ? (
								<li>
									<strong>
										{ __( 'Last step', 'lift-teleport' ) }
									</strong>
									<span>{ activeDiagnostics.last_step }</span>
								</li>
							) : null }
							<li>
								<strong>
									{ __( 'Schema', 'lift-teleport' ) }
								</strong>
								<span
									className={
										schemaHealthy
											? 'lift-schema-health is-healthy'
											: 'lift-schema-health is-unhealthy'
									}
								>
									{ schemaHealthy
										? __( 'Healthy', 'lift-teleport' )
										: __(
												'Drift detected',
												'lift-teleport'
										  ) }
								</span>
							</li>
							{ schema?.last_repair_at ? (
								<li>
									<strong>
										{ __( 'Last repair', 'lift-teleport' ) }
									</strong>
									<span>{ schema.last_repair_at }</span>
								</li>
							) : null }
						</ul>
					) : (
						<p className="lift-empty-copy">
							{ __(
								'No active job diagnostics available.',
								'lift-teleport'
							) }
						</p>
					) }

					{ latestMetric ? (
						<div className="lift-metric-block">
							<p className="lift-metric-label">
								{ __( 'Last metric', 'lift-teleport' ) }
							</p>
							<pre className="lift-metric-json">
								{ JSON.stringify( latestMetric, null, 2 ) }
							</pre>
						</div>
					) : null }

					{ ! schemaHealthy && missingColumns.length ? (
						<div className="lift-metric-block">
							<p className="lift-metric-label">
								{ __(
									'Missing schema columns',
									'lift-teleport'
								) }
							</p>
							<ul className="lift-schema-missing">
								{ missingColumns.map( ( row ) => (
									<li key={ row }>{ row }</li>
								) ) }
							</ul>
						</div>
					) : null }

					<div className="lift-metric-block">
						<div className="lift-capabilities-head">
							<p className="lift-metric-label">
								{ __(
									'Capability checks',
									'lift-teleport'
								) }
							</p>
							{ typeof onRefreshCapabilities === 'function' ? (
								<button
									type="button"
									className="lift-capabilities-refresh"
									onClick={ () =>
										onRefreshCapabilities()
									}
								>
									{ __(
										'Refresh checks',
										'lift-teleport'
									) }
								</button>
							) : null }
						</div>

						{ showCapabilities ? (
							<>
								<ul className="lift-capability-list">
									<li>
										<strong>tar/gzip</strong>
										<span
											className={ `lift-capability-status ${ statusClass(
												tarStatus
											) }` }
										>
											{ statusLabel( tarStatus ) }
										</span>
									</li>
									<li>
										<strong>proc_open</strong>
										<span
											className={ `lift-capability-status ${ statusClass(
												procStatus
											) }` }
										>
											{ statusLabel( procStatus ) }
										</span>
									</li>
									<li>
										<strong>CLI</strong>
										<span
											className={ `lift-capability-status ${ statusClass(
												cliStatus
											) }` }
										>
											{ statusLabel( cliStatus ) }
										</span>
									</li>
									<li>
										<strong>
											{ __(
												'Host profile',
												'lift-teleport'
											) }
										</strong>
										<span>{ hostProfile || 'n/a' }</span>
									</li>
									<li>
										<strong>
											{ __(
												'Recommended runner',
												'lift-teleport'
											) }
										</strong>
										<span>
											{ recommendedRunner || 'n/a' }
										</span>
									</li>
									<li>
										<strong>
											{ __(
												'Archive engine',
												'lift-teleport'
											) }
										</strong>
										<span>
											{ recommendedArchive || 'n/a' }
										</span>
									</li>
									<li>
										<strong>
											{ __(
												'Checked at',
												'lift-teleport'
											) }
										</strong>
										<span>
											{ preflightCheckedAt || 'n/a' }
										</span>
									</li>
									<li>
										<strong>
											{ __(
												'Source',
												'lift-teleport'
											) }
										</strong>
										<span>{ preflightSource || 'n/a' }</span>
									</li>
								</ul>

								{ warnings.length ? (
									<div className="lift-capability-warnings">
										<p className="lift-metric-label">
											{ __(
												'Operational warnings',
												'lift-teleport'
											) }
										</p>
										<ul className="lift-schema-missing">
											{ warnings.map( ( warning, index ) => (
												<li key={ `${ warning }-${ index }` }>
													{ warning }
												</li>
											) ) }
										</ul>
									</div>
								) : null }
							</>
						) : (
							<p className="lift-empty-copy">
								{ __(
									'Capability checks are not available yet.',
									'lift-teleport'
								) }
							</p>
						) }
					</div>

					{ activeJob ? (
						<div className="lift-metric-block">
							<p className="lift-metric-label">
								{ __(
									'Execution mode for this job',
									'lift-teleport'
								) }
							</p>
							<ul className="lift-capability-list">
								<li>
									<strong>
										{ __( 'Mode', 'lift-teleport' ) }
									</strong>
									<span>{ activeExecutionMode || 'n/a' }</span>
								</li>
								<li>
									<strong>
										{ __(
											'Planned runner',
											'lift-teleport'
										) }
									</strong>
									<span>{ activePlanRunner || 'n/a' }</span>
								</li>
								<li>
									<strong>
										{ __( 'Risk', 'lift-teleport' ) }
									</strong>
									<span>{ activePlanRisk || 'n/a' }</span>
								</li>
								<li>
									<strong>
										{ __(
											'Fallback reason',
											'lift-teleport'
										) }
									</strong>
									<span>
										{ activeExecutionFallback || 'n/a' }
									</span>
								</li>
							</ul>
						</div>
					) : null }
				</section>
			</div>
		</section>
	);
}
