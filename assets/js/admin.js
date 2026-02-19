(function () {
	const config = window.liftTeleportAdmin || {};
	const input = document.getElementById('lift-teleport-file');
	const dropzone = document.querySelector('.lift-teleport__dropzone');
	const bar = document.querySelector('.lift-teleport__progress-bar');
	const status = document.querySelector('.lift-teleport__status');
	const exportButton = document.getElementById('lift-teleport-export-start');
	const exportStatus = document.getElementById('lift-teleport-export-status');

	if (!input || !dropzone || !bar || !status || !config.restUrl || !config.restNonce) {
		return;
	}

	const setProgress = (value) => {
		const safe = Math.max(0, Math.min(100, Number(value) || 0));
		bar.style.width = safe + '%';
		bar.setAttribute('aria-valuenow', String(Math.round(safe)));
	};

	const setStatus = (message) => {
		status.textContent = message;
	};

	const requestRest = async (baseUrl, endpoint, body, method) => {
		const response = await window.fetch(baseUrl + endpoint, {
			method: method || 'POST',
			headers: {
				'X-WP-Nonce': config.restNonce
			},
			body
		});

		const data = await response.json();
		if (!response.ok) {
			throw new Error((data && data.message) || ('HTTP ' + response.status));
		}
		return data;
	};

	const uploadFile = async (file) => {
		const formData = new window.FormData();
		formData.append('lift_file', file);
		return requestRest(config.restUrl, '/upload', formData, 'POST');
	};

	const processImport = async (jobId) => {
		const formData = new window.FormData();
		formData.append('job_id', jobId);
		const data = await requestRest(config.restUrl, '/process', formData, 'POST');

		if (typeof data.progress === 'number') {
			setProgress(data.progress);
		}
		if (data.message) {
			setStatus(data.message);
		}
		if (data.completed) {
			setProgress(100);
			setStatus('Importación completada correctamente.');
			return;
		}

		window.setTimeout(() => {
			void processImport(jobId);
		}, data.retryable ? 1200 : 250);
	};

	const beginImport = async (file) => {
		setProgress(0);
		setStatus('Subiendo ' + file.name + '…');
		try {
			const uploadData = await uploadFile(file);
			if (!uploadData || !uploadData.job_id) {
				setStatus('No se pudo iniciar la importación.');
				return;
			}
			setProgress(uploadData.progress || 5);
			setStatus(uploadData.message || 'Importación iniciada.');
			void processImport(uploadData.job_id);
		} catch (error) {
			setStatus(String(error.message || 'Error de conexión al importar.'));
		}
	};

	const runExport = async () => {
		if (!exportButton || !exportStatus || !config.exportRestUrl) {
			return;
		}

		exportButton.disabled = true;
		exportStatus.textContent = 'Iniciando exportación…';

		try {
			const start = await requestRest(config.exportRestUrl, '/start', null, 'POST');
			const exportId = start && start.export_id;
			if (!exportId) {
				throw new Error('No se recibió export_id.');
			}

			exportStatus.textContent = 'Generando paquete .lift…';
			const payload = new window.FormData();
			payload.append('export_id', exportId);
			const step = await requestRest(config.exportRestUrl, '/continue', payload, 'POST');
			const state = step && step.state ? step.state : null;
			if (!state || state.status !== 'completed' || !state.download_url) {
				throw new Error((state && state.last_error) || 'La exportación no terminó correctamente.');
			}

			exportStatus.textContent = 'Exportación completada. Descargando archivo .lift…';
			window.location.href = state.download_url;
		} catch (error) {
			exportStatus.textContent = String(error.message || 'Error durante exportación.');
		} finally {
			exportButton.disabled = false;
		}
	};

	input.addEventListener('change', () => {
		if (!input.files || !input.files[0]) {
			return;
		}
		void beginImport(input.files[0]);
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
		void beginImport(file);
	});

	if (exportButton) {
		exportButton.addEventListener('click', () => {
			void runExport();
		});
	}
})();
