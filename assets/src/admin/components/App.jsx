import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import Sidebar from './Sidebar';
import ExportCard from './ExportCard';
import ImportCard from './ImportCard';
import DiagnosticsPanel from './DiagnosticsPanel';
import PlaceholderPanel from './PlaceholderPanel';
import GlobalJobStatusBar from './GlobalJobStatusBar';
import UnzipperPanel from './UnzipperPanel';
import SettingsPanel from './SettingsPanel';
import BackupsPanel from './BackupsPanel';
import {
	getExportProgressLabel,
	getImportProgressLabel,
} from './progressLabels';

const TERMINAL_STATUSES = [
	'completed',
	'failed',
	'failed_rollback',
	'cancelled',
];
const ACTIVE_STATUSES = [ 'uploading', 'pending', 'running' ];
const TRACKING_KEY = 'lift-teleport-active-job';

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

function errorStatus( error ) {
	const direct = Number( error?.status || 0 );
	if ( direct > 0 ) {
		return direct;
	}

	return Number( error?.data?.status || 0 );
}

function isRecoverableLookupError( error ) {
	const status = errorStatus( error );
	if ( status === 401 || status === 404 ) {
		return true;
	}

	const primaryStatus = Number( error?.primary?.status || 0 );
	return primaryStatus === 401 || primaryStatus === 404;
}

function isDefinitiveStaleResolveError( error ) {
	const code = String(
		error?.code ||
			error?.data?.code ||
			error?.primary?.code ||
			error?.primary?.data?.code ||
			''
	);

	return code === 'lift_job_resolve_failed';
}

function bytesToHex( buffer ) {
	return Array.from( new Uint8Array( buffer ) )
		.map( ( value ) => value.toString( 16 ).padStart( 2, '0' ) )
		.join( '' );
}

async function sha256ForBlob( blob ) {
	const subtle = window?.crypto?.subtle;
	if ( ! subtle || typeof blob?.arrayBuffer !== 'function' ) {
		return '';
	}

	const buffer = await blob.arrayBuffer();
	const digest = await subtle.digest( 'SHA-256', buffer );
	return bytesToHex( digest );
}

export default function App( { props } ) {
	const [ activePanel, setActivePanel ] = useState( 'export-import' );
	const [ exportJob, setExportJob ] = useState( null );
	const [ importJob, setImportJob ] = useState( null );
	const [ events, setEvents ] = useState( [] );
	const [ file, setFile ] = useState( null );
	const [ uploadProgress, setUploadProgress ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );
	const [ errorMessage, setErrorMessage ] = useState( '' );
	const [ connectionState, setConnectionState ] = useState( 'idle' );
	const [ diagnosticsSnapshot, setDiagnosticsSnapshot ] = useState( null );
	const [ apiUnavailable, setApiUnavailable ] = useState( false );
	const [ jobTokens, setJobTokens ] = useState( {
		export: '',
		import: '',
	} );
	const [ usedTokenPolling, setUsedTokenPolling ] = useState( false );

	const pollTimer = useRef( null );
	const pollActive = useRef( false );
	const pollBackoff = useRef( 2000 );
	const pollFailures = useRef( 0 );
	const recoveryTimer = useRef( null );

	const applyJobState = ( job, typeHint = '', token = '' ) => {
		if ( ! job || ! job.id ) {
			return;
		}

		const resolvedType = job.type || typeHint;
		if ( resolvedType === 'export' ) {
			setExportJob( job );
		} else if ( resolvedType === 'import' ) {
			setImportJob( job );
		}

		if ( resolvedType ) {
			setJobTokens( ( current ) => ( {
				...current,
				[ resolvedType ]: token || current[ resolvedType ] || '',
			} ) );
		}

		if ( token && resolvedType ) {
			saveTracking( job.id, resolvedType, token );
		}
	};

	const activeJob =
		importJob?.status && ! TERMINAL_STATUSES.includes( importJob.status )
			? importJob
			: exportJob;

	const stopPolling = () => {
		pollActive.current = false;
		if ( pollTimer.current ) {
			window.clearTimeout( pollTimer.current );
			pollTimer.current = null;
		}
		if ( recoveryTimer.current ) {
			window.clearTimeout( recoveryTimer.current );
			recoveryTimer.current = null;
		}
		pollBackoff.current = 2000;
		pollFailures.current = 0;
		setConnectionState( 'idle' );
	};

	const saveTracking = ( jobId, type, token ) => {
		if (
			! jobId ||
			! type ||
			! token ||
			typeof window === 'undefined' ||
			! window.localStorage
		) {
			return;
		}

		window.localStorage.setItem(
			TRACKING_KEY,
			JSON.stringify( {
				jobId,
				type,
				token,
				ts: Date.now(),
			} )
		);
	};

	const clearTracking = () => {
		if ( typeof window === 'undefined' || ! window.localStorage ) {
			return;
		}
		window.localStorage.removeItem( TRACKING_KEY );
	};

	const getTokenForType = ( type ) => {
		return ( type && jobTokens[ type ] ) || '';
	};

	const buildTokenUrl = ( route, token ) => {
		const base =
			props?.restRoot || `${ window.location.origin }/wp-json/lift/v1/`;
		const url = new window.URL(
			route.replace( /^\/+/, '' ),
			base.endsWith( '/' ) ? base : `${ base }/`
		);

		if ( token ) {
			url.searchParams.set( 'job_token', token );
		}

		return url.toString();
	};

	const requestViaToken = async (
		route,
		token,
		method = 'GET',
		body = null,
		keepalive = false
	) => {
		if ( ! token ) {
			const tokenError = new Error(
				__( 'Authentication expired.', 'lift-teleport' )
			);
			tokenError.status = 401;
			throw tokenError;
		}

		const response = await window.fetch( buildTokenUrl( route, token ), {
			method,
			headers: {
				Accept: 'application/json',
				...( body
					? {
							'Content-Type': 'application/json',
					  }
					: {} ),
			},
			credentials: 'omit',
			...( body ? { body: JSON.stringify( body ) } : {} ),
			...( keepalive ? { keepalive: true } : {} ),
		} );

		const data = await response.json().catch( () => ( {} ) );
		if ( ! response.ok ) {
			const requestError = new Error(
				typeof data?.message === 'string'
					? data.message
					: __( 'Unable to fetch job status.', 'lift-teleport' )
			);
			requestError.status = response.status;
			requestError.code = data?.code || '';
			requestError.data = data;
			throw requestError;
		}

		if ( method === 'GET' ) {
			setUsedTokenPolling( true );
		}
		return data;
	};

	const fetchViaToken = ( route, token ) =>
		requestViaToken( route, token, 'GET' );

	const fetchDiagnosticsSnapshot = async ( forceRefresh = false ) => {
		const path = forceRefresh
			? '/lift/v1/diagnostics?refresh_capabilities=1'
			: '/lift/v1/diagnostics';
		const diagnostics = await apiFetch( {
			path,
		} ).catch( ( error ) => {
			const status = errorStatus( error );
			const code = String(
				error?.code || error?.data?.code || ''
			).toLowerCase();
			if ( status === 404 && code === 'rest_no_route' ) {
				setApiUnavailable( true );
			}
			return null;
		} );
		if ( diagnostics ) {
			setApiUnavailable( false );
			setDiagnosticsSnapshot( diagnostics );
		}
		return diagnostics;
	};

	const resolveJobByToken = async ( token, expectedType = '' ) => {
		if ( ! token ) {
			return null;
		}

		const query = expectedType
			? `?type=${ window.encodeURIComponent( expectedType ) }`
			: '';
		const response = await fetchViaToken( `jobs/resolve${ query }`, token );
		const job = response?.job || null;
		if ( ! job || ! job.id ) {
			return null;
		}

		if ( expectedType && job.type && job.type !== expectedType ) {
			return null;
		}

		return {
			job,
			token,
		};
	};

	const fetchJob = async ( jobId, token, tokenFirst = false ) => {
		if ( tokenFirst && token ) {
			try {
				return await fetchViaToken( `jobs/${ jobId }`, token );
			} catch ( error ) {
				const apiError = await apiFetch( {
					path: `/lift/v1/jobs/${ jobId }`,
				} ).catch( ( fallbackError ) => {
					fallbackError.primary = error;
					throw fallbackError;
				} );

				return apiError;
			}
		}

		try {
			return await apiFetch( { path: `/lift/v1/jobs/${ jobId }` } );
		} catch ( error ) {
			const tokenError = await fetchViaToken(
				`jobs/${ jobId }`,
				token
			).catch( ( fallbackError ) => {
				fallbackError.primary = error;
				throw fallbackError;
			} );

			return tokenError;
		}
	};

	const fetchEvents = async ( jobId, token, tokenFirst = false ) => {
		const route = `jobs/${ jobId }/events?per_page=20`;
		if ( tokenFirst && token ) {
			try {
				return await fetchViaToken( route, token );
			} catch ( error ) {
				return apiFetch( {
					path: `/lift/v1/jobs/${ jobId }/events?per_page=20`,
				} );
			}
		}

		try {
			return await apiFetch( {
				path: `/lift/v1/jobs/${ jobId }/events?per_page=20`,
			} );
		} catch ( error ) {
			return fetchViaToken( route, token );
		}
	};

	const sendHeartbeat = async ( jobId, type, token = '' ) => {
		if ( ! jobId ) {
			return;
		}

		const payload = {
			foreground_required: true,
			type,
		};

		if ( token ) {
			await requestViaToken(
				`jobs/${ jobId }/heartbeat`,
				token,
				'POST',
				payload
			);
			return;
		}

		await apiFetch( {
			path: `/lift/v1/jobs/${ jobId }/heartbeat`,
			method: 'POST',
			data: payload,
		} );
	};

	const tryRecoverFromDiagnostics = async (
		expectedType,
		currentJobId,
		token = ''
	) => {
		let tokenResolveError = null;

		if ( token ) {
			const resolved = await resolveJobByToken(
				token,
				expectedType
			).catch( ( error ) => {
				tokenResolveError = error;
				return null;
			} );
			if (
				resolved?.job &&
				! TERMINAL_STATUSES.includes( resolved.job.status )
			) {
				return resolved;
			}
		}

		const diagnostics = await fetchDiagnosticsSnapshot().catch(
			() => null
		);

		const active = diagnostics?.active_job || null;
		if (
			! active ||
			! active.id ||
			TERMINAL_STATUSES.includes( active.status )
		) {
			if (
				token &&
				tokenResolveError &&
				isDefinitiveStaleResolveError( tokenResolveError )
			) {
				return { stale: true };
			}

			return null;
		}

		if ( expectedType && active.type && active.type !== expectedType ) {
			return null;
		}

		if ( Number( active.id ) === Number( currentJobId ) ) {
			return {
				job: active,
				token:
					getTokenForType( expectedType ) ||
					active?.payload?.job_token ||
					'',
			};
		}

		return {
			job: active,
			token:
				active?.payload?.job_token ||
				getTokenForType( active.type ) ||
				'',
		};
	};

	const refreshJob = async ( jobId, type, explicitToken = '' ) => {
		const token = explicitToken || getTokenForType( type );
		const tokenFirst = true;
		const response = await fetchJob( jobId, token, tokenFirst );
		const job = response?.job || null;

		if ( ! job ) {
			const missing = new Error(
				__( 'Unable to fetch job status.', 'lift-teleport' )
			);
			missing.status = 404;
			throw missing;
		}

		applyJobState( job, type, token );

		setConnectionState( 'idle' );

		try {
			const eventResponse = await fetchEvents( jobId, token, tokenFirst );
			setEvents( eventResponse?.events || [] );
		} catch {
			// Keep the latest known progress even if event polling fails transiently.
		}

		if ( TERMINAL_STATUSES.includes( job.status ) ) {
			stopPolling();
			setBusy( false );
			clearTracking();
			if ( job.status !== 'completed' ) {
				setErrorMessage(
					job.message || __( 'Job failed', 'lift-teleport' )
				);
			}
		}
	};

	const schedulePoll = ( jobId, type, jobToken, delay ) => {
		if ( ! pollActive.current ) {
			return;
		}

		pollTimer.current = window.setTimeout( async () => {
			let shouldReschedule = true;
			try {
				await sendHeartbeat(
					jobId,
					type,
					jobToken || getTokenForType( type )
				).catch( () => null );
				await refreshJob( jobId, type, jobToken );

				if ( pollFailures.current > 0 ) {
					setConnectionState( 'recovered' );
					if ( recoveryTimer.current ) {
						window.clearTimeout( recoveryTimer.current );
					}
					recoveryTimer.current = window.setTimeout( () => {
						setConnectionState( 'idle' );
					}, 3500 );
				}

				pollFailures.current = 0;
				pollBackoff.current = 2000;
			} catch ( error ) {
				pollFailures.current += 1;
				pollBackoff.current = Math.min(
					15000,
					pollBackoff.current * 2
				);

				if (
					pollFailures.current >= 2 &&
					isRecoverableLookupError( error )
				) {
					const recovered = await tryRecoverFromDiagnostics(
						type,
						jobId,
						jobToken || getTokenForType( type )
					);

					if ( recovered?.job ) {
						const recoveredJob = recovered.job;
						const recoveredType = recoveredJob.type || type;
						const recoveredToken =
							recovered.token || getTokenForType( recoveredType );

						applyJobState(
							recoveredJob,
							recoveredType,
							recoveredToken
						);

						pollFailures.current = 0;
						pollBackoff.current = 2000;
						setConnectionState( 'recovered' );

						shouldReschedule = false;
						startPolling(
							recoveredJob.id,
							recoveredType,
							recoveredToken
						);
						return;
					}

					if ( recovered?.stale ) {
						stopPolling();
						setBusy( false );
						setExportJob( null );
						setImportJob( null );
						setEvents( [] );
						clearTracking();
						setConnectionState( 'stale_cleared' );
						setErrorMessage(
							__(
								'The migration job is no longer available. Start a new export/import.',
								'lift-teleport'
							)
						);
						shouldReschedule = false;
						return;
					}
				}

				if (
					pollFailures.current >= 5 &&
					isRecoverableLookupError( error )
				) {
					stopPolling();
					setBusy( false );
					clearTracking();
					setErrorMessage(
						__(
							'The migration job is no longer available. Start a new export/import.',
							'lift-teleport'
						)
					);
					setConnectionState( 'stale_cleared' );
					shouldReschedule = false;
					return;
				}

				setConnectionState(
					pollFailures.current >= 3 ? 'degraded' : 'reconnecting'
				);
			} finally {
				if ( shouldReschedule && pollActive.current ) {
					schedulePoll( jobId, type, jobToken, pollBackoff.current );
				}
			}
		}, delay );
	};

	const startPolling = ( jobId, type, jobToken = '' ) => {
		stopPolling();
		pollActive.current = true;
		setConnectionState( 'idle' );
		schedulePoll( jobId, type, jobToken, 300 );
	};

	useEffect( () => {
		if ( typeof window === 'undefined' || ! window.localStorage ) {
			return () => stopPolling();
		}

		let cancelled = false;

		const bootstrapFromTracking = async () => {
			const raw = window.localStorage.getItem( TRACKING_KEY );
			if ( ! raw ) {
				return;
			}

			try {
				const tracking = JSON.parse( raw );
				const jobId = Number( tracking?.jobId || 0 );
				const type = tracking?.type;
				const token = tracking?.token || '';

				if ( ! ( type === 'import' || type === 'export' ) ) {
					clearTracking();
					return;
				}

				if ( token ) {
					setJobTokens( ( current ) => ( {
						...current,
						[ type ]: token,
					} ) );
				}

				const resolved = await tryRecoverFromDiagnostics(
					type,
					jobId,
					token
				);

				if ( cancelled ) {
					return;
				}

				if ( resolved?.job?.id ) {
					const resolvedType = resolved.job.type || type;
					const resolvedToken = resolved.token || token;
					applyJobState( resolved.job, resolvedType, resolvedToken );
					startPolling(
						resolved.job.id,
						resolvedType,
						resolvedToken
					);
					return;
				}

				if ( resolved?.stale ) {
					clearTracking();
					setExportJob( null );
					setImportJob( null );
					setEvents( [] );
					setBusy( false );
					setConnectionState( 'stale_cleared' );
					return;
				}

				if ( jobId > 0 ) {
					await refreshJob( jobId, type, token );
					if ( cancelled ) {
						return;
					}
					startPolling( jobId, type, token );
					return;
				}

				clearTracking();
				setConnectionState( 'stale_cleared' );
			} catch ( error ) {
				if ( cancelled ) {
					return;
				}
				clearTracking();
				setConnectionState( 'stale_cleared' );
			}
		};

		bootstrapFromTracking().catch( () => {
			if ( cancelled ) {
				return;
			}
			clearTracking();
			setConnectionState( 'stale_cleared' );
		} );

		return () => {
			cancelled = true;
			stopPolling();
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useEffect( () => {
		let cancelled = false;

		const refreshDiagnostics = async () => {
			if ( cancelled ) {
				return;
			}

			await fetchDiagnosticsSnapshot().catch( () => null );
		};

		refreshDiagnostics();
		if ( activePanel !== 'diagnostics' ) {
			return () => {
				cancelled = true;
			};
		}

		const timer = window.setInterval( refreshDiagnostics, 5000 );
		return () => {
			cancelled = true;
			window.clearInterval( timer );
		};
	}, [ activePanel ] );

	const startExport = async () => {
		try {
			if ( apiUnavailable ) {
				throw new Error(
					__(
						'Lift REST API is not available on this site. Ensure the plugin is active in the current WordPress instance.',
						'lift-teleport'
					)
				);
			}

			setBusy( true );
			setErrorMessage( '' );
			setConnectionState( 'idle' );

			const create = await apiFetch( {
				path: '/lift/v1/jobs/export',
				method: 'POST',
				data: {},
			} );
			const createdJob = create?.job;
			const createdJobToken = create?.job_token || '';
			if ( ! createdJob ) {
				throw new Error(
					__( 'Unable to create export job.', 'lift-teleport' )
				);
			}

			setExportJob( createdJob );
			setJobTokens( ( current ) => ( {
				...current,
				export: createdJobToken,
			} ) );
			saveTracking( createdJob.id, 'export', createdJobToken );
			startPolling( createdJob.id, 'export', createdJobToken );

			apiFetch( {
				path: `/lift/v1/jobs/${ createdJob.id }/start`,
				method: 'POST',
				data: {
					foreground_required: true,
				},
			} )
				.then( ( started ) => {
					if ( started?.job ) {
						setExportJob( started.job );
					}
				} )
				.catch( ( error ) => {
					setErrorMessage( asMessage( error ) );
				} );
		} catch ( error ) {
			setBusy( false );
			setErrorMessage( asMessage( error ) );
			throw error;
		}
	};

	const uploadImportFile = async ( jobId, targetFile, chunkSize ) => {
		let uploadedBytes = 0;

		while ( uploadedBytes < targetFile.size ) {
			const chunk = targetFile.slice(
				uploadedBytes,
				uploadedBytes + chunkSize
			);
			const formData = new window.FormData();
			formData.append( 'offset', String( uploadedBytes ) );
			formData.append( 'chunk', chunk, targetFile.name );
			const chunkSha = await sha256ForBlob( chunk ).catch( () => '' );
			if ( chunkSha ) {
				formData.append( 'chunk_sha256', chunkSha );
			}

			const response = await apiFetch( {
				path: `/lift/v1/jobs/${ jobId }/upload-chunk`,
				method: 'POST',
				body: formData,
			} );

			uploadedBytes = Number( response?.uploaded_bytes || 0 );
			setUploadProgress(
				Math.min( 100, ( uploadedBytes / targetFile.size ) * 100 )
			);
		}

		await apiFetch( {
			path: `/lift/v1/jobs/${ jobId }/upload-complete`,
			method: 'POST',
			data: {},
		} );
	};

	const startImport = async () => {
		if ( ! file ) {
			return;
		}

		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			props?.i18n?.confirmImport ||
				__(
					'This action will overwrite the current site. Continue?',
					'lift-teleport'
				)
		);

		if ( ! confirmed ) {
			return;
		}

		try {
			if ( apiUnavailable ) {
				throw new Error(
					__(
						'Lift REST API is not available on this site. Ensure the plugin is active in the current WordPress instance.',
						'lift-teleport'
					)
				);
			}

			setBusy( true );
			setUploadProgress( 0 );
			setErrorMessage( '' );
			setConnectionState( 'idle' );

			const fileHashLimitBytes = Number(
				props?.fileHashMaxBytes || 128 * 1024 * 1024
			);
			let fileSha256 = '';
			if ( file.size > 0 && file.size <= fileHashLimitBytes ) {
				fileSha256 = await sha256ForBlob( file ).catch( () => '' );
			}

			const create = await apiFetch( {
				path: '/lift/v1/jobs/import',
				method: 'POST',
				data: {
					file_name: file.name,
					total_bytes: file.size,
					...( fileSha256 ? { file_sha256: fileSha256 } : {} ),
				},
			} );

			const createdJob = create?.job;
			const createdJobToken = create?.job_token || '';
			if ( ! createdJob ) {
				throw new Error(
					__( 'Unable to create import job.', 'lift-teleport' )
				);
			}

			setImportJob( createdJob );
			setJobTokens( ( current ) => ( {
				...current,
				import: createdJobToken,
			} ) );
			saveTracking( createdJob.id, 'import', createdJobToken );

			await uploadImportFile(
				createdJob.id,
				file,
				props?.uploadChunkSize || 8 * 1024 * 1024
			);

			startPolling( createdJob.id, 'import', createdJobToken );

			apiFetch( {
				path: `/lift/v1/jobs/${ createdJob.id }/start`,
				method: 'POST',
				data: {
					foreground_required: true,
				},
			} )
				.then( ( started ) => {
					if ( started?.job ) {
						setImportJob( started.job );
					}
				} )
				.catch( ( error ) => {
					setErrorMessage( asMessage( error ) );
				} );
		} catch ( error ) {
			setBusy( false );
			setErrorMessage( asMessage( error ) );
		}
	};

	const startImportFromBackup = async ( backupId, password = '' ) => {
		if ( ! backupId ) {
			return false;
		}

		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			props?.i18n?.confirmImport ||
				__(
					'This action will overwrite the current site. Continue?',
					'lift-teleport'
				)
		);

		if ( ! confirmed ) {
			return false;
		}

		try {
			if ( apiUnavailable ) {
				throw new Error(
					__(
						'Lift REST API is not available on this site. Ensure the plugin is active in the current WordPress instance.',
						'lift-teleport'
					)
				);
			}

			setBusy( true );
			setUploadProgress( 0 );
			setErrorMessage( '' );
			setConnectionState( 'idle' );

			const create = await apiFetch( {
				path: `/lift/v1/backups/${ window.encodeURIComponent(
					String( backupId )
				) }/import`,
				method: 'POST',
				data: {
					password,
				},
			} );

			const createdJob = create?.job;
			const createdJobToken = create?.job_token || '';
			if ( ! createdJob ) {
				throw new Error(
					__(
						'Unable to create import job from backup.',
						'lift-teleport'
					)
				);
			}

			setFile( null );
			setImportJob( createdJob );
			setJobTokens( ( current ) => ( {
				...current,
				import: createdJobToken,
			} ) );
			saveTracking( createdJob.id, 'import', createdJobToken );
			startPolling( createdJob.id, 'import', createdJobToken );

			apiFetch( {
				path: `/lift/v1/jobs/${ createdJob.id }/start`,
				method: 'POST',
				data: {
					foreground_required: true,
				},
			} )
				.then( ( started ) => {
					if ( started?.job ) {
						setImportJob( started.job );
					}
				} )
				.catch( ( error ) => {
					setErrorMessage( asMessage( error ) );
				} );

			return true;
		} catch ( error ) {
			setBusy( false );
			setErrorMessage( asMessage( error ) );
			throw error;
		}
	};

	const activeDiagnostics = activeJob?.payload?.diagnostics || {};
	const activeMetrics = activeJob?.payload?.step_metrics || {};
	const schemaDiagnostics = diagnosticsSnapshot?.schema || null;
	const environmentDiagnostics = diagnosticsSnapshot?.environment || null;
	const capabilityDiagnostics = environmentDiagnostics?.capabilities || null;
	const recommendedExecution =
		environmentDiagnostics?.recommended_execution || null;
	const activeExecutionPlan = activeJob?.payload?.execution_plan || null;
	const activeExecutionMode = (
		activeJob?.payload?.execution_mode_used || ''
	).toString();
	const activeExecutionFallback = (
		activeJob?.payload?.execution_fallback_reason || ''
	).toString();
	const latestMetric =
		activeDiagnostics?.last_step &&
		activeMetrics?.[ activeDiagnostics.last_step ]
			? activeMetrics[ activeDiagnostics.last_step ]
			: null;
	const activeJobIsRunning = Boolean(
		activeJob && ACTIVE_STATUSES.includes( activeJob.status )
	);
	let activeJobLabel = '';
	if ( activeJob ) {
		activeJobLabel =
			activeJob.type === 'import'
				? getImportProgressLabel( activeJob, uploadProgress )
				: getExportProgressLabel( activeJob );
	}

	let connectionLabel = __(
		'Reconnecting to the migration job…',
		'lift-teleport'
	);
	if ( connectionState === 'degraded' ) {
		connectionLabel = __(
			'Connection is unstable. Retrying with backoff…',
			'lift-teleport'
		);
	} else if ( connectionState === 'recovered' ) {
		connectionLabel = __(
			'Connection recovered. Migration is still running.',
			'lift-teleport'
		);
	} else if ( connectionState === 'stale_cleared' ) {
		connectionLabel = __(
			'Stale job reference cleared. You can start a new export/import.',
			'lift-teleport'
		);
	}

	const panelTitles = {
		automation: __( 'Automation', 'lift-teleport' ),
		backup: __( 'Backups', 'lift-teleport' ),
		unzipper: __( 'Unzipper', 'lift-teleport' ),
		settings: __( 'Settings', 'lift-teleport' ),
	};

	const renderPanel = () => {
		if ( activePanel === 'export-import' ) {
			return (
				<section
					className="lift-panel lift-panel-export-import"
					aria-label={ __( 'Export and import', 'lift-teleport' ) }
				>
					<div className="lift-cards">
						<ExportCard
							job={ exportJob }
							onStart={ startExport }
							busy={ busy }
						/>
						<ImportCard
							file={ file }
							onFileChange={ setFile }
							onImport={ startImport }
							uploadProgress={ uploadProgress }
							job={ importJob }
							busy={ busy }
						/>
					</div>
				</section>
			);
		}

		if ( activePanel === 'diagnostics' ) {
			return (
				<DiagnosticsPanel
					events={ events }
					activeJob={ activeJob }
					activeDiagnostics={ activeDiagnostics }
					latestMetric={ latestMetric }
					schema={ schemaDiagnostics }
					environment={ environmentDiagnostics }
					capabilities={ capabilityDiagnostics }
					recommendedExecution={ recommendedExecution }
					activeExecutionPlan={ activeExecutionPlan }
					activeExecutionMode={ activeExecutionMode }
					activeExecutionFallback={ activeExecutionFallback }
					onRefreshCapabilities={ () =>
						fetchDiagnosticsSnapshot( true )
					}
				/>
			);
		}

		if ( activePanel === 'unzipper' ) {
			return (
				<UnzipperPanel
					uploadChunkSize={
						props?.uploadChunkSize || 8 * 1024 * 1024
					}
				/>
			);
		}

		if ( activePanel === 'settings' ) {
			return <SettingsPanel />;
		}

		if ( activePanel === 'backup' ) {
			return (
				<BackupsPanel
					onImportBackup={ startImportFromBackup }
					jobBusy={ busy }
				/>
			);
		}

		return <PlaceholderPanel title={ panelTitles[ activePanel ] || '' } />;
	};

	return (
		<div className="lift-shell lift-bg-crosslines">
			<header className="lift-brand">
				<span className="lift-brand-icon">↔</span>
				<strong>Lift.</strong>
			</header>

			<main className="lift-layout">
				<Sidebar
					activePanel={ activePanel }
					onSelect={ setActivePanel }
				/>

				<section className="lift-content">
					{ activePanel !== 'export-import' && activeJobIsRunning ? (
						<GlobalJobStatusBar
							job={ activeJob }
							label={ activeJobLabel }
							onGoToMain={ () =>
								setActivePanel( 'export-import' )
							}
						/>
					) : null }

					{ renderPanel() }

					{ errorMessage ? (
						<p className="lift-error">{ errorMessage }</p>
					) : null }
					{ connectionState !== 'idle' &&
					( connectionState === 'stale_cleared' ||
						( activeJob &&
							! TERMINAL_STATUSES.includes(
								activeJob.status
							) ) ) ? (
						<p className="lift-info">{ connectionLabel }</p>
					) : null }
					{ apiUnavailable ? (
						<p className="lift-error">
							{ __(
								'Lift API namespace is not available in this WordPress runtime.',
								'lift-teleport'
							) }
						</p>
					) : null }
					{ activeJob &&
					ACTIVE_STATUSES.includes( activeJob.status ) ? (
						<p className="lift-info">
							{ __(
								'Si sales de esta página, la tarea activa se cancelará.',
								'lift-teleport'
							) }
						</p>
					) : null }
					{ activeJob?.status === 'completed' ? (
						<p className="lift-success">
							{ __(
								'Operation completed successfully.',
								'lift-teleport'
							) }
						</p>
					) : null }
					{ usedTokenPolling && importJob?.status === 'completed' ? (
						<p className="lift-success">
							{ __(
								'Import completed with resilient connection mode.',
								'lift-teleport'
							) }
						</p>
					) : null }
				</section>
			</main>
		</div>
	);
}
