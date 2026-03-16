import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

function asMessage( error ) {
	if ( ! error ) {
		return __( 'Unknown error', 'lift-teleport' );
	}

	if ( typeof error.message === 'string' ) {
		return error.message;
	}

	if ( typeof error === 'string' ) {
		return error;
	}

	return __( 'Unknown error', 'lift-teleport' );
}

function formatDateTime( value ) {
	if ( ! value ) {
		return '—';
	}

	const parsed = new Date( value );
	if ( Number.isNaN( parsed.getTime() ) ) {
		return String( value );
	}

	return parsed.toLocaleString();
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
	const precision = sized >= 100 ? 0 : sized >= 10 ? 1 : 2;

	return `${ sized.toFixed( precision ) } ${ units[ power ] }`;
}

export default function BackupsPanel( {
	onImportBackup = () => {},
	jobBusy = false,
} ) {
	const [ loading, setLoading ] = useState( true );
	const [ items, setItems ] = useState( [] );
	const [ pagination, setPagination ] = useState( {
		page: 1,
		per_page: 20,
		total: 0,
		pages: 1,
	} );
	const [ actionBusy, setActionBusy ] = useState( '' );
	const [ errorMessage, setErrorMessage ] = useState( '' );
	const [ infoMessage, setInfoMessage ] = useState( '' );

	const load = async ( page = 1 ) => {
		setLoading( true );
		setErrorMessage( '' );
		try {
			const response = await apiFetch( {
				path: `/lift/v1/backups?page=${ page }&per_page=20`,
			} );

			setItems( Array.isArray( response?.items ) ? response.items : [] );
			setPagination( {
				page: Number( response?.pagination?.page || page ),
				per_page: Number( response?.pagination?.per_page || 20 ),
				total: Number( response?.pagination?.total || 0 ),
				pages: Number( response?.pagination?.pages || 1 ),
			} );
		} catch ( error ) {
			setErrorMessage( asMessage( error ) );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => {
		load();
	}, [] );

	const deleteBackup = async ( backup ) => {
		if ( ! backup?.id ) {
			return;
		}

		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			__(
				'Delete this backup permanently? This action cannot be undone.',
				'lift-teleport'
			)
		);
		if ( ! confirmed ) {
			return;
		}

		setActionBusy( `delete:${ backup.id }` );
		setErrorMessage( '' );
		setInfoMessage( '' );

		try {
			await apiFetch( {
				path: `/lift/v1/backups/${ window.encodeURIComponent(
					String( backup.id )
				) }`,
				method: 'DELETE',
			} );

			setInfoMessage( __( 'Backup deleted.', 'lift-teleport' ) );
			await load( pagination.page );
		} catch ( error ) {
			setErrorMessage( asMessage( error ) );
		} finally {
			setActionBusy( '' );
		}
	};

	const importBackup = async ( backup ) => {
		if ( ! backup?.id ) {
			return;
		}

		let password = '';
		if ( backup.encrypted ) {
			// eslint-disable-next-line no-alert
			const input = window.prompt(
				__(
					'This backup is encrypted. Enter password to import.',
					'lift-teleport'
				),
				''
			);
			if ( input === null ) {
				return;
			}
			password = input;
		}

		setActionBusy( `import:${ backup.id }` );
		setErrorMessage( '' );
		setInfoMessage( '' );
		try {
			const started = await onImportBackup( backup.id, password );
			if ( started !== false ) {
				setInfoMessage(
					__(
						'Import job created from backup. Monitoring progress in Export / Import.',
						'lift-teleport'
					)
				);
			}
		} catch ( error ) {
			setErrorMessage( asMessage( error ) );
		} finally {
			setActionBusy( '' );
		}
	};

	const previousDisabled = loading || pagination.page <= 1;
	const nextDisabled =
		loading || pagination.page >= Math.max( 1, Number( pagination.pages || 1 ) );

	return (
		<section className="lift-panel lift-backups-panel" aria-label={ __( 'Backups', 'lift-teleport' ) }>
			<div className="lift-backups-head">
				<p className="lift-swiss-kicker">{ __( 'Backups', 'lift-teleport' ) }</p>
				<h3>{ __( 'Stored .lift files', 'lift-teleport' ) }</h3>
				<p>
					{ __(
						'Backups are created when “Save for backup” is enabled in Settings.',
						'lift-teleport'
					) }
				</p>
			</div>

			<div className="lift-backups-table-wrap">
				<table className="lift-backups-table">
					<thead>
						<tr>
							<th>{ __( 'File', 'lift-teleport' ) }</th>
							<th>{ __( 'Date', 'lift-teleport' ) }</th>
							<th>{ __( 'Size', 'lift-teleport' ) }</th>
							<th>{ __( 'Actions', 'lift-teleport' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ ! loading && items.length === 0 ? (
							<tr>
								<td colSpan="4" className="lift-backups-empty">
									{ __(
										'No backups available. Enable “Save for backup” and run a new export.',
										'lift-teleport'
									) }
								</td>
							</tr>
						) : null }

						{ items.map( ( item ) => {
							const itemId = String( item.id || '' );
							const isDeleting = actionBusy === `delete:${ itemId }`;
							const isImporting = actionBusy === `import:${ itemId }`;
							return (
								<tr key={ itemId }>
									<td>
										<strong>{ item.filename || '—' }</strong>
									</td>
									<td>{ formatDateTime( item.created_at ) }</td>
									<td>{ formatBytes( item.size_bytes ) }</td>
									<td>
										<div className="lift-backups-actions">
											<a
												href={ item.download_path || '#' }
												className="lift-cta lift-cta-secondary"
											>
												{ __( 'Download', 'lift-teleport' ) }
											</a>
											<button
												type="button"
												className="lift-cta"
												onClick={ () => importBackup( item ) }
												disabled={
													jobBusy || isDeleting || isImporting
												}
											>
												{ isImporting
													? __( 'Importing…', 'lift-teleport' )
													: __( 'Import', 'lift-teleport' ) }
											</button>
											<button
												type="button"
												className="lift-backups-delete"
												onClick={ () => deleteBackup( item ) }
												disabled={
													jobBusy || isDeleting || isImporting
												}
											>
												{ isDeleting
													? __( 'Deleting…', 'lift-teleport' )
													: __( 'Delete', 'lift-teleport' ) }
											</button>
										</div>
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			</div>

			<div className="lift-backups-pagination">
				<button
					type="button"
					className="lift-backups-page-btn"
					onClick={ () => load( pagination.page - 1 ) }
					disabled={ previousDisabled }
				>
					{ __( 'Previous', 'lift-teleport' ) }
				</button>
				<span>
					{ `${ pagination.page } / ${ Math.max(
						1,
						Number( pagination.pages || 1 )
					) }` }
				</span>
				<button
					type="button"
					className="lift-backups-page-btn"
					onClick={ () => load( pagination.page + 1 ) }
					disabled={ nextDisabled }
				>
					{ __( 'Next', 'lift-teleport' ) }
				</button>
			</div>

			{ errorMessage ? <p className="lift-error">{ errorMessage }</p> : null }
			{ infoMessage ? <p className="lift-success">{ infoMessage }</p> : null }
		</section>
	);
}
