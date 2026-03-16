import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const DEFAULT_SETTINGS = {
	save_for_backup: false,
	merge_admin: false,
	updated_at: '',
	updated_by: 0,
};

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

export default function SettingsPanel() {
	const [ settings, setSettings ] = useState( DEFAULT_SETTINGS );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ errorMessage, setErrorMessage ] = useState( '' );
	const [ successMessage, setSuccessMessage ] = useState( '' );

	useEffect( () => {
		let cancelled = false;

		const load = async () => {
			setLoading( true );
			setErrorMessage( '' );
			try {
				const response = await apiFetch( {
					path: '/lift/v1/settings',
				} );
				if ( cancelled ) {
					return;
				}

				setSettings( {
					...DEFAULT_SETTINGS,
					...( response?.settings || {} ),
				} );
			} catch ( error ) {
				if ( cancelled ) {
					return;
				}
				setErrorMessage( asMessage( error ) );
			} finally {
				if ( ! cancelled ) {
					setLoading( false );
				}
			}
		};

		load();

		return () => {
			cancelled = true;
		};
	}, [] );

	const updateField = ( key ) => ( event ) => {
		const checked = Boolean( event?.target?.checked );
		setSettings( ( current ) => ( {
			...current,
			[ key ]: checked,
		} ) );
		setSuccessMessage( '' );
	};

	const saveSettings = async () => {
		setSaving( true );
		setErrorMessage( '' );
		setSuccessMessage( '' );
		try {
			const response = await apiFetch( {
				path: '/lift/v1/settings',
				method: 'POST',
				data: {
					save_for_backup: Boolean( settings.save_for_backup ),
					merge_admin: Boolean( settings.merge_admin ),
				},
			} );

			setSettings( {
				...DEFAULT_SETTINGS,
				...( response?.settings || {} ),
			} );
			setSuccessMessage(
				__(
					'Settings saved. Changes will apply to your next export/import job.',
					'lift-teleport'
				)
			);
		} catch ( error ) {
			setErrorMessage( asMessage( error ) );
		} finally {
			setSaving( false );
		}
	};

	return (
		<section className="lift-panel lift-settings-panel" aria-label={ __( 'Settings', 'lift-teleport' ) }>
			<div className="lift-settings-card">
				<header className="lift-settings-head">
					<p className="lift-swiss-kicker">{ __( 'Settings', 'lift-teleport' ) }</p>
					<h3>{ __( 'Behavior toggles', 'lift-teleport' ) }</h3>
					<p>
						{ __(
							'Los cambios se aplicarán en tu próxima exportación/importación.',
							'lift-teleport'
						) }
					</p>
				</header>

				<div className="lift-settings-grid">
					<label className="lift-toggle-row">
						<span>
							<strong>{ __( 'Save for backup', 'lift-teleport' ) }</strong>
							<small>
								{ __(
									'When enabled, exports are copied to Backups for later import.',
									'lift-teleport'
								) }
							</small>
						</span>
						<input
							type="checkbox"
							checked={ Boolean( settings.save_for_backup ) }
							onChange={ updateField( 'save_for_backup' ) }
							disabled={ loading || saving }
						/>
					</label>

					<label className="lift-toggle-row">
						<span>
							<strong>{ __( 'Merge admin', 'lift-teleport' ) }</strong>
							<small>
								{ __(
									'When enabled, the operator admin user is preserved during import.',
									'lift-teleport'
								) }
							</small>
						</span>
						<input
							type="checkbox"
							checked={ Boolean( settings.merge_admin ) }
							onChange={ updateField( 'merge_admin' ) }
							disabled={ loading || saving }
						/>
					</label>
				</div>

				<div className="lift-settings-actions">
					<button
						type="button"
						className="lift-cta"
						onClick={ saveSettings }
						disabled={ loading || saving }
					>
						{ saving
							? __( 'Saving…', 'lift-teleport' )
							: __( 'Save settings', 'lift-teleport' ) }
					</button>
				</div>

				{ errorMessage ? <p className="lift-error">{ errorMessage }</p> : null }
				{ successMessage ? (
					<p className="lift-success">{ successMessage }</p>
				) : null }
			</div>
		</section>
	);
}
