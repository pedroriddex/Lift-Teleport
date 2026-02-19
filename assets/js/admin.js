(function () {
	const input = document.getElementById('lift-teleport-file');
	const dropzone = document.querySelector('.lift-teleport__dropzone');
	const bar = document.querySelector('.lift-teleport__progress-bar');
	const status = document.querySelector('.lift-teleport__status');
	const phase = document.querySelector('[data-lift-teleport-phase]');
	const speed = document.querySelector('[data-lift-teleport-speed]');
	const eta = document.querySelector('[data-lift-teleport-eta]');
	const percent = document.querySelector('[data-lift-teleport-percent]');
	const pauseButton = document.querySelector('[data-lift-teleport-action="pause"]');
	const resumeButton = document.querySelector('[data-lift-teleport-action="resume"]');
	const cancelButton = document.querySelector('[data-lift-teleport-action="cancel"]');
	const storageKey = 'liftTeleportImportJob';
	const phaseLabels = {
		validating_package: 'Validando paquete',
		restoring_db: 'Restaurando base de datos',
		restoring_files: 'Restaurando archivos',
		finalizing: 'Finalizando',
		rollback: 'Rollback',
		paused: 'Pausado',
		cancelled: 'Cancelado',
		completed: 'Completado'
	};

	const defaultSettings = {
		baseUrl: '/wp-json/lift-teleport/v1/import',
		nonce: '',
		pollInterval: 2000,
		retryBaseMs: 400,
		retryMaxAttempts: 4
	};
	const settings = Object.assign({}, defaultSettings, window.liftTeleportAdmin || {});
	const endpoint = {
		create: (settings.endpoints && settings.endpoints.create) || settings.baseUrl,
		status: (jobId) => ((settings.endpoints && settings.endpoints.status) || (settings.baseUrl + '/' + encodeURIComponent(jobId))) ,
		batch: (jobId) => ((settings.endpoints && settings.endpoints.batch) || (settings.baseUrl + '/' + encodeURIComponent(jobId) + '/batch')),
		pause: (jobId) => ((settings.endpoints && settings.endpoints.pause) || (settings.baseUrl + '/' + encodeURIComponent(jobId) + '/pause')),
		resume: (jobId) => ((settings.endpoints && settings.endpoints.resume) || (settings.baseUrl + '/' + encodeURIComponent(jobId) + '/resume')),
		cancel: (jobId) => ((settings.endpoints && settings.endpoints.cancel) || (settings.baseUrl + '/' + encodeURIComponent(jobId) + '/cancel'))
	};

	if (!input || !dropzone || !bar || !status || !phase || !speed || !eta || !percent || !pauseButton || !resumeButton || !cancelButton) {
		return;
	}

	let pollTimer = null;
	let activeJobId = null;
	let throughputWindow = [];
	let isBusy = false;

	const setProgress = (value) => {
		const safe = Math.max(0, Math.min(100, Number(value) || 0));
		bar.style.width = safe + '%';
		bar.setAttribute('aria-valuenow', String(Math.round(safe)));
		percent.textContent = Math.round(safe) + '%';
	};

	const setControls = (jobState) => {
		const inFlight = !!activeJobId;
		pauseButton.disabled = !inFlight || isBusy || jobState === 'paused' || jobState === 'completed' || jobState === 'cancelled';
		resumeButton.disabled = !inFlight || isBusy || jobState !== 'paused';
		cancelButton.disabled = !inFlight || isBusy || jobState === 'completed' || jobState === 'cancelled';
	};

	const formatEta = (seconds) => {
		const value = Number(seconds);
		if (!Number.isFinite(value) || value < 0) {
			return 'Calculando…';
		}
		if (value < 60) {
			return Math.round(value) + ' s';
		}
		const min = Math.floor(value / 60);
		const sec = Math.round(value % 60);
		return min + ' min ' + sec + ' s';
	};

	const setPhase = (phaseKey) => {
		phase.textContent = phaseLabels[phaseKey] || 'Procesando';
	};

	const computeSpeed = (completedBatches, totalBatches) => {
		const now = Date.now();
		throughputWindow.push({
			time: now,
			completed: completedBatches
		});
		throughputWindow = throughputWindow.filter((entry) => now - entry.time <= 10000);
		if (throughputWindow.length < 2) {
			speed.textContent = 'Calculando…';
			eta.textContent = 'Calculando…';
			return;
		}

		const first = throughputWindow[0];
		const last = throughputWindow[throughputWindow.length - 1];
		const elapsedSeconds = (last.time - first.time) / 1000;
		const completedDelta = last.completed - first.completed;
		const batchesPerSecond = elapsedSeconds > 0 ? completedDelta / elapsedSeconds : 0;
		if (batchesPerSecond <= 0) {
			speed.textContent = 'Calculando…';
			eta.textContent = 'Calculando…';
			return;
		}

		speed.textContent = batchesPerSecond.toFixed(2) + ' lotes/s';
		const remaining = Math.max(0, (Number(totalBatches) || 0) - (Number(completedBatches) || 0));
		eta.textContent = formatEta(remaining / batchesPerSecond);
	};

	const updateStatus = (job, fallback) => {
		const nextPhase = job.phase || fallback || 'validating_package';
		setPhase(nextPhase);
		setProgress(job.progress_percent || 0);
		status.textContent = job.message || 'Procesando importación…';
		computeSpeed(job.completed_batches || 0, job.total_batches || 0);
		setControls(job.state || 'running');
	};

	const saveJob = (jobId) => {
		activeJobId = jobId;
		window.localStorage.setItem(storageKey, jobId);
	};

	const clearJob = () => {
		activeJobId = null;
		throughputWindow = [];
		window.localStorage.removeItem(storageKey);
		setControls('idle');
	};

	const requestWithRetry = async (url, options, fallbackMessage) => {
		let attempt = 0;
		let lastError = null;

		while (attempt < settings.retryMaxAttempts) {
			try {
				const response = await window.fetch(url, options);
				if (!response.ok) {
					const text = await response.text();
					throw new Error(text || ('HTTP ' + response.status));
				}
				return response.json();
			} catch (error) {
				lastError = error;
				attempt += 1;
				if (attempt >= settings.retryMaxAttempts) {
					break;
				}
				const wait = settings.retryBaseMs * Math.pow(2, attempt - 1);
				await new Promise((resolve) => window.setTimeout(resolve, wait));
			}
		}

		status.textContent = fallbackMessage || 'Error de red. Reintenta en unos segundos.';
		throw lastError;
	};

	const stopPolling = () => {
		if (pollTimer) {
			window.clearTimeout(pollTimer);
			pollTimer = null;
		}
	};

	const finalizeJobIfDone = (job) => {
		if (job.state === 'completed') {
			setPhase('completed');
			setProgress(100);
			status.textContent = job.message || 'Importación completada correctamente.';
			clearJob();
			return true;
		}

		if (job.state === 'cancelled') {
			setPhase('cancelled');
			status.textContent = job.message || 'Importación cancelada.';
			clearJob();
			return true;
		}

		if (job.state === 'failed') {
			setPhase(job.phase || 'rollback');
			status.textContent = job.message || 'La importación falló y se inició rollback.';
			clearJob();
			return true;
		}

		return false;
	};

	const schedulePoll = () => {
		stopPolling();
		pollTimer = window.setTimeout(() => {
			void pollJob();
		}, settings.pollInterval);
	};

	const sendBatchIfNeeded = async (job) => {
		if (!activeJobId || job.state !== 'running') {
			return;
		}
		if (job.waiting_for_batch !== true) {
			return;
		}
		await requestWithRetry(
			endpoint.batch(activeJobId),
			{
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': settings.nonce
				},
				body: JSON.stringify({ job_id: activeJobId })
			},
			'No se pudo enviar el siguiente lote. Reintentando…'
		);
	};

	const pollJob = async () => {
		if (!activeJobId) {
			return;
		}
		try {
			const payload = await requestWithRetry(
				endpoint.status(activeJobId),
				{
					method: 'GET',
					headers: {
						'X-WP-Nonce': settings.nonce
					}
				},
				'No se pudo consultar el estado. Reintentando…'
			);
			const job = payload.job || payload;
			updateStatus(job);
			if (finalizeJobIfDone(job)) {
				stopPolling();
				return;
			}
			await sendBatchIfNeeded(job);
			schedulePoll();
		} catch (error) {
			schedulePoll();
		}
	};

	const createJob = async (file) => {
		const formData = new window.FormData();
		formData.append('file', file, file.name);

		const payload = await requestWithRetry(
			endpoint.create,
			{
				method: 'POST',
				headers: {
					'X-WP-Nonce': settings.nonce
				},
				body: formData
			},
			'No se pudo crear el job de importación. Comprueba la conexión.'
		);

		return payload.job || payload;
	};

	const startImport = async (file) => {
		if (!file || isBusy) {
			return;
		}

		isBusy = true;
		setPhase('validating_package');
		status.textContent = 'Validando paquete ' + file.name + '…';
		setProgress(0);
		setControls('running');

		try {
			const job = await createJob(file);
			saveJob(job.id);
			updateStatus(job, 'validating_package');
			schedulePoll();
		} catch (error) {
			setPhase('rollback');
			setControls('idle');
		} finally {
			isBusy = false;
			setControls('running');
		}
	};

	const controlJob = async (action) => {
		if (!activeJobId || isBusy) {
			return;
		}
		isBusy = true;
		setControls('running');
		try {
			const payload = await requestWithRetry(
				endpoint[action](activeJobId),
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': settings.nonce
					},
					body: JSON.stringify({ job_id: activeJobId })
				},
				'No se pudo ' + (action === 'pause' ? 'pausar' : action === 'resume' ? 'reanudar' : 'cancelar') + ' el job.'
			);
			const job = payload.job || payload;
			updateStatus(job);
			if (finalizeJobIfDone(job)) {
				stopPolling();
				return;
			}
			schedulePoll();
		} catch (error) {
			setControls('running');
		} finally {
			isBusy = false;
			setControls('running');
		}
	};

	const resumeFromStorage = () => {
		const savedJobId = window.localStorage.getItem(storageKey);
		if (!savedJobId) {
			setControls('idle');
			return;
		}
		saveJob(savedJobId);
		setPhase('validating_package');
		status.textContent = 'Recuperando importación en curso…';
		schedulePoll();
	};

	input.addEventListener('change', function () {
		if (!input.files || !input.files[0]) {
			return;
		}
		void startImport(input.files[0]);
	});

	['dragenter', 'dragover'].forEach((eventName) => {
		dropzone.addEventListener(eventName, (event) => {
			event.preventDefault();
			dropzone.classList.add('is-dragover');
		});
	});

	['dragleave', 'drop'].forEach((eventName) => {
		dropzone.addEventListener(eventName, (event) => {
			event.preventDefault();
			dropzone.classList.remove('is-dragover');
		});
	});

	dropzone.addEventListener('drop', (event) => {
		const file = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files[0] : null;
		if (!file) {
			return;
		}
		void startImport(file);
	});

	pauseButton.addEventListener('click', () => {
		void controlJob('pause');
	});

	resumeButton.addEventListener('click', () => {
		void controlJob('resume');
	});

	cancelButton.addEventListener('click', () => {
		void controlJob('cancel');
	});

	resumeFromStorage();
})();
