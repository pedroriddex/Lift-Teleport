import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import ProgressBar from './ProgressBar';
import { getUnzipperProgressLabel, isJobActive } from './progressLabels';
import IntegritySummary from './IntegritySummary';
import UnzipperTree from './UnzipperTree';

const TRACKING_KEY = 'lift-teleport-unzipper-active-job';
const TERMINAL_STATUSES = [
	'completed',
	'failed',
	'failed_rollback',
	'cancelled',
];

export default function UnzipperPanel( { uploadChunkSize = 8 * 1024 * 1024 } ) {
	const [ file, setFile ] = useState( null );
	const [ password, setPassword ] = useState( '' );
	const [ job, setJob ] = useState( null );
	const [ jobToken, setJobToken ] = useState( '' );
	const [ uploadProgress, setUploadProgress ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );
	const [ loadingEntries, setLoadingEntries ] = useState( false );
	const [ entries, setEntries ] = useState( [] );
	const [ nextCursor, setNextCursor ] = useState( null );
	const [ hasMoreEntries, setHasMoreEntries ] = useState( false );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ prefixInput, setPrefixInput ] = useState( '' );
	const [ appliedSearch, setAppliedSearch ] = useState( '' );
	const [ appliedPrefix, setAppliedPrefix ] = useState( '' );
	const [ diagnostics, setDiagnostics ] = useState( {
		summary: {},
		quick_report: {},
		full_report: {},
	} );
	const [ errorMessage, setErrorMessage ] = useState( '' );
	const [ infoMessage, setInfoMessage ] = useState( '' );

	const pollTimer = useRef( null );
	const pollActive = useRef( false );
	const pollBackoff = useRef( 2000 );
	const pollFailures = useRef( 0 );
	const bootstrapped = useRef( false );
	const loadedEntriesOnce = useRef( false );
	const latestJobRef = useRef( null );
	const latestTokenRef = useRef( '' );

	const clearPolling = () => {
		pollActive.current = false;
		if ( pollTimer.current ) {
			window.clearTimeout( pollTimer.current );
			pollTimer.current = null;
		}
		pollBackoff.current = 2000;
		pollFailures.current = 0;
	};

	const saveTracking = ( nextJobId, token ) => {
		if (
			! nextJobId ||
			! token ||
			typeof window === 'undefined' ||
			! window.localStorage
		) {
			return;
		}

		window.localStorage.setItem(
			TRACKING_KEY,
			JSON.stringify( {
				jobId: nextJobId,
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

	const buildTokenUrl = ( route, token ) => {
		const base = `${ window.location.origin }/wp-json/lift/v1/`;
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
		body = null
	) => {
		if ( ! token ) {
			throw new Error( __( 'Authentication expired.', 'lift-teleport' ) );
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
		} );

		const data = await response.json().catch( () => ( {} ) );
		if ( ! response.ok ) {
			const error = new Error(
				typeof data?.message === 'string'
					? data.message
					: __( 'Request failed.', 'lift-teleport' )
			);
			error.status = response.status;
			error.code = data?.code || '';
			throw error;
		}

		return data;
	};

	const asMessage = ( error ) => {
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
	};

	const errorStatus = ( error ) => {
		const direct = Number( error?.status || 0 );
		if ( direct > 0 ) {
			return direct;
		}

		return Number( error?.data?.status || 0 );
	};

	const shouldTryTokenFallback = ( error ) => {
		const status = errorStatus( error );
		return status === 401 || status === 403 || status === 404;
	};

	const fetchJob = async ( jobId, token ) => {
		try {
			const response = await apiFetch( {
				path: `/lift/v1/unzipper/jobs/${ jobId }`,
			} );
			return response?.job || null;
		} catch ( error ) {
			if ( shouldTryTokenFallback( error ) && token ) {
				const response = await requestViaToken(
					`/unzipper/jobs/${ jobId }`,
					token
				);
				return response?.job || null;
			}
			throw error;
		}
	};

	const fetchDiagnostics = async ( jobId, token ) => {
		try {
			const response = await apiFetch( {
				path: `/lift/v1/unzipper/jobs/${ jobId }/diagnostics`,
			} );
			return response || null;
		} catch ( error ) {
			if ( shouldTryTokenFallback( error ) && token ) {
				return requestViaToken(
					`/unzipper/jobs/${ jobId }/diagnostics`,
					token
				);
			}
			throw error;
		}
	};

	const loadEntries = async (
		nextJobId,
		token,
		reset = false,
		prefixOverride = appliedPrefix,
		searchOverride = appliedSearch
	) => {
		if ( ! nextJobId ) {
			return;
		}

		const cursor = reset ? 0 : Number( nextCursor || 0 );
		const params = new window.URLSearchParams();
		params.set( 'cursor', String( Math.max( 0, cursor ) ) );
		params.set( 'limit', '200' );
		if ( prefixOverride ) {
			params.set( 'prefix', prefixOverride );
		}
		if ( searchOverride ) {
			params.set( 'search', searchOverride );
		}

		const path = `/lift/v1/unzipper/jobs/${ nextJobId }/entries?${ params.toString() }`;
		setLoadingEntries( true );
		try {
			let response = null;
			try {
				response = await apiFetch( { path } );
			} catch ( error ) {
				if ( shouldTryTokenFallback( error ) && token ) {
					response = await requestViaToken(
						`/unzipper/jobs/${ nextJobId }/entries?${ params.toString() }`,
						token
					);
				} else {
					throw error;
				}
			}

			setEntries( ( current ) => {
				const incoming = Array.isArray( response?.entries )
					? response.entries
					: [];
				return reset ? incoming : [ ...current, ...incoming ];
			} );
			setNextCursor( response?.next_cursor ?? null );
			setHasMoreEntries( Boolean( response?.has_more ) );
			if (
				Array.isArray( response?.entries ) &&
				response.entries.length
			) {
				loadedEntriesOnce.current = true;
			}
		} finally {
			setLoadingEntries( false );
		}
	};

	const hydrateDiagnostics = async ( nextJobId, token ) => {
		if ( ! nextJobId ) {
			return;
		}

		const response = await fetchDiagnostics( nextJobId, token ).catch(
			() => null
		);
		if ( ! response ) {
			return;
		}

		setDiagnostics( {
			summary: response?.summary || {},
			quick_report: response?.quick_report || {},
			full_report: response?.full_report || {},
		} );

		const quickStatus = String(
			response?.summary?.quick_status ||
				response?.quick_report?.status ||
				''
		);
		if ( quickStatus === 'passed' && ! loadedEntriesOnce.current ) {
			loadEntries( nextJobId, token, true ).catch( () => undefined );
		}
	};

	const schedulePoll = ( nextJobId, token, delay = 2000 ) => {
		if ( ! pollActive.current ) {
			return;
		}

		pollTimer.current = window.setTimeout( async () => {
			let shouldContinue = pollActive.current;
			try {
				const nextJob = await fetchJob( nextJobId, token );
				if ( nextJob ) {
					setJob( nextJob );
					latestJobRef.current = nextJob;
				}

				pollFailures.current = 0;
				pollBackoff.current = 2000;
				if ( infoMessage ) {
					setInfoMessage( '' );
				}

				await hydrateDiagnostics( nextJobId, token );

				if ( nextJob && ! isJobActive( nextJob ) ) {
					setBusy( false );
					shouldContinue = false;
					clearTracking();
					await loadEntries( nextJobId, token, true ).catch(
						() => undefined
					);
				}
			} catch ( error ) {
				pollFailures.current += 1;
				pollBackoff.current = Math.min(
					15000,
					pollBackoff.current * 2
				);
				setInfoMessage(
					__( 'Reconnecting to Unzipper job…', 'lift-teleport' )
				);

				if ( pollFailures.current >= 6 ) {
					setErrorMessage( asMessage( error ) );
					shouldContinue = false;
					setBusy( false );
				}
			}

			if ( shouldContinue && pollActive.current ) {
				schedulePoll( nextJobId, token, pollBackoff.current );
			}
		}, delay );
	};

	const startPolling = ( nextJobId, token ) => {
		clearPolling();
		pollActive.current = true;
		schedulePoll( nextJobId, token, 1200 );
	};

	const uploadChunks = async ( nextJobId, targetFile, chunkSize ) => {
		let uploadedBytes = 0;
		while ( uploadedBytes < targetFile.size ) {
			const chunk = targetFile.slice(
				uploadedBytes,
				uploadedBytes + chunkSize
			);
			const formData = new window.FormData();
			formData.append( 'offset', String( uploadedBytes ) );
			formData.append( 'chunk', chunk, targetFile.name );

			const response = await apiFetch( {
				path: `/lift/v1/unzipper/jobs/${ nextJobId }/upload-chunk`,
				method: 'POST',
				body: formData,
			} );

			uploadedBytes = Number( response?.uploaded_bytes || 0 );
			setUploadProgress(
				Math.min( 100, ( uploadedBytes / targetFile.size ) * 100 )
			);
		}

		await apiFetch( {
			path: `/lift/v1/unzipper/jobs/${ nextJobId }/upload-complete`,
			method: 'POST',
			data: {},
		} );
	};

	const runUnzipper = async () => {
		if ( ! file ) {
			return;
		}

		setErrorMessage( '' );
		setInfoMessage( '' );
		setBusy( true );
		setUploadProgress( 0 );
		setEntries( [] );
		setHasMoreEntries( false );
		setNextCursor( null );
		setDiagnostics( { summary: {}, quick_report: {}, full_report: {} } );
		loadedEntriesOnce.current = false;

		try {
			const created = await apiFetch( {
				path: '/lift/v1/unzipper/jobs',
				method: 'POST',
				data: {
					file_name: file.name,
					total_bytes: file.size,
					password,
				},
			} );

			const createdJob = created?.job;
			const token = String( created?.job_token || '' );
			if ( ! createdJob?.id ) {
				throw new Error(
					__( 'Unable to create Unzipper job.', 'lift-teleport' )
				);
			}

			setJob( createdJob );
			latestJobRef.current = createdJob;
			setJobToken( token );
			latestTokenRef.current = token;
			saveTracking( createdJob.id, token );

			await uploadChunks( createdJob.id, file, uploadChunkSize );

			startPolling( createdJob.id, token );

			apiFetch( {
				path: `/lift/v1/unzipper/jobs/${ createdJob.id }/start`,
				method: 'POST',
				data: {
					foreground_required: true,
				},
			} )
				.then( ( started ) => {
					if ( started?.job ) {
						setJob( started.job );
						latestJobRef.current = started.job;
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

	const resolveTrackedJob = async () => {
		if ( bootstrapped.current ) {
			return;
		}
		bootstrapped.current = true;

		if ( typeof window === 'undefined' || ! window.localStorage ) {
			return;
		}

		const raw = window.localStorage.getItem( TRACKING_KEY );
		if ( ! raw ) {
			return;
		}

		let parsed = null;
		try {
			parsed = JSON.parse( raw );
		} catch ( _error ) {
			clearTracking();
			return;
		}

		const trackedJobId = Number( parsed?.jobId || 0 );
		const trackedToken = String( parsed?.token || '' );
		if ( ! trackedJobId || ! trackedToken ) {
			clearTracking();
			return;
		}

		try {
			const resolved = await apiFetch( {
				path: `/lift/v1/jobs/resolve?type=unzipper&job_token=${ encodeURIComponent(
					trackedToken
				) }`,
			} );
			const resolvedJob = resolved?.job;
			if ( ! resolvedJob?.id ) {
				clearTracking();
				return;
			}

			setJob( resolvedJob );
			latestJobRef.current = resolvedJob;
			setJobToken( trackedToken );
			latestTokenRef.current = trackedToken;

			if ( isJobActive( resolvedJob ) ) {
				setBusy( true );
				startPolling( resolvedJob.id, trackedToken );
			} else {
				setBusy( false );
				await hydrateDiagnostics( resolvedJob.id, trackedToken );
				await loadEntries( resolvedJob.id, trackedToken, true );
			}
		} catch ( _error ) {
			clearTracking();
		}
	};

	const applyFilters = async () => {
		const nextSearch = searchInput.trim();
		const nextPrefix = prefixInput.trim();
		setAppliedSearch( nextSearch );
		setAppliedPrefix( nextPrefix );
		if ( ! job?.id ) {
			return;
		}
		loadedEntriesOnce.current = false;
		setNextCursor( null );
		await loadEntries( job.id, jobToken, true, nextPrefix, nextSearch );
	};

	const resetFilters = async () => {
		setSearchInput( '' );
		setPrefixInput( '' );
		setAppliedSearch( '' );
		setAppliedPrefix( '' );
		if ( ! job?.id ) {
			return;
		}
		loadedEntriesOnce.current = false;
		setNextCursor( null );
		await loadEntries( job.id, jobToken, true, '', '' );
	};

	useEffect( () => {
		resolveTrackedJob();
		return () => {
			clearPolling();
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useEffect( () => {
		latestJobRef.current = job;
	}, [ job ] );

	useEffect( () => {
		latestTokenRef.current = jobToken;
	}, [ jobToken ] );

	useEffect( () => {
		return () => {
			const nextJob = latestJobRef.current;
			if ( ! nextJob?.id ) {
				return;
			}

			if (
				! TERMINAL_STATUSES.includes( String( nextJob.status || '' ) )
			) {
				return;
			}

			apiFetch( {
				path: `/lift/v1/unzipper/jobs/${ nextJob.id }/cleanup`,
				method: 'POST',
				data: {},
			} ).catch( () => undefined );
		};
	}, [] );

	const status = String( job?.status || '' );

	return (
		<section
			className="lift-panel lift-panel-unzipper"
			aria-label={ __( 'Unzipper', 'lift-teleport' ) }
		>
			<div className="lift-unzipper-grid">
				<section className="lift-card">
					<header className="lift-card-head">
						<div className="lift-icon" aria-hidden="true">
							⬚
						</div>
						<h2>{ __( 'Unzipper', 'lift-teleport' ) }</h2>
					</header>

					<label
						className="lift-dropzone"
						htmlFor="lift-unzipper-input"
					>
						<input
							id="lift-unzipper-input"
							type="file"
							accept=".lift"
							onChange={ ( event ) =>
								setFile( event.target.files?.[ 0 ] || null )
							}
							hidden
							disabled={ busy && isJobActive( job ) }
						/>
						<span>
							{ file
								? file.name
								: __(
										'Add a .lift package to inspect',
										'lift-teleport'
								  ) }
						</span>
					</label>

					<label
						className="lift-unzipper-field"
						htmlFor="lift-unzipper-password"
					>
						<span>
							{ __( 'Password (optional)', 'lift-teleport' ) }
						</span>
						<input
							id="lift-unzipper-password"
							type="password"
							value={ password }
							onChange={ ( event ) =>
								setPassword( event.target.value )
							}
							placeholder={ __(
								'Only required for encrypted .lift files',
								'lift-teleport'
							) }
							disabled={ busy && isJobActive( job ) }
						/>
					</label>

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
							value={ Number( job?.progress || 0 ) }
							label={ getUnzipperProgressLabel(
								job,
								uploadProgress
							) }
							isLoading={ isJobActive( job ) }
						/>
					) : null }

					<button
						type="button"
						className="lift-cta"
						onClick={ runUnzipper }
						disabled={ ! file || ( busy && isJobActive( job ) ) }
					>
						{ busy && isJobActive( job )
							? __( 'Analyzing…', 'lift-teleport' )
							: __( 'Analyze package', 'lift-teleport' ) }
					</button>

					{ status === 'completed' ? (
						<button
							type="button"
							className="lift-cta lift-cta-secondary"
							onClick={ () => {
								if ( ! job?.id ) {
									return;
								}
								apiFetch( {
									path: `/lift/v1/unzipper/jobs/${ job.id }/cleanup`,
									method: 'POST',
									data: {},
								} )
									.then( () => {
										setInfoMessage(
											__(
												'Unzipper artifacts cleaned successfully.',
												'lift-teleport'
											)
										);
										clearTracking();
										setEntries( [] );
										setHasMoreEntries( false );
										setNextCursor( null );
									} )
									.catch( ( error ) => {
										setErrorMessage( asMessage( error ) );
									} );
							} }
						>
							{ __( 'Clean artifacts now', 'lift-teleport' ) }
						</button>
					) : null }
				</section>

				<section className="lift-unzipper-side">
					<IntegritySummary
						summary={ diagnostics.summary }
						quickReport={ diagnostics.quick_report }
						fullReport={ diagnostics.full_report }
					/>

					<div className="lift-unzipper-filters">
						<label htmlFor="lift-unzipper-search">
							<span>
								{ __( 'Search path', 'lift-teleport' ) }
							</span>
							<input
								id="lift-unzipper-search"
								type="text"
								value={ searchInput }
								onChange={ ( event ) =>
									setSearchInput( event.target.value )
								}
								placeholder={ __(
									'plugins/woocommerce',
									'lift-teleport'
								) }
							/>
						</label>
						<label htmlFor="lift-unzipper-prefix">
							<span>{ __( 'Prefix', 'lift-teleport' ) }</span>
							<input
								id="lift-unzipper-prefix"
								type="text"
								value={ prefixInput }
								onChange={ ( event ) =>
									setPrefixInput( event.target.value )
								}
								placeholder={ __(
									'content/wp-content/plugins',
									'lift-teleport'
								) }
							/>
						</label>
						<div className="lift-unzipper-filter-actions">
							<button
								type="button"
								className="lift-cta lift-cta-secondary"
								onClick={ applyFilters }
								disabled={ ! job?.id || loadingEntries }
							>
								{ __( 'Apply filters', 'lift-teleport' ) }
							</button>
							<button
								type="button"
								className="lift-cta lift-cta-secondary"
								onClick={ resetFilters }
								disabled={ ! job?.id || loadingEntries }
							>
								{ __( 'Reset', 'lift-teleport' ) }
							</button>
						</div>
					</div>

					<UnzipperTree
						entries={ entries }
						hasMore={ hasMoreEntries }
						onLoadMore={ () =>
							loadEntries( job?.id, jobToken, false ).catch(
								( error ) =>
									setErrorMessage( asMessage( error ) )
							)
						}
						loading={ loadingEntries }
					/>
				</section>
			</div>

			{ errorMessage ? (
				<p className="lift-error">{ errorMessage }</p>
			) : null }
			{ infoMessage ? (
				<p className="lift-info">{ infoMessage }</p>
			) : null }
		</section>
	);
}
