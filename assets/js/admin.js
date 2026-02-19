(function () {
	const config = window.liftTeleportAdmin || {};
	const input = document.getElementById('lift-teleport-file');
	const dropzone = document.querySelector('.lift-teleport__dropzone');
	const bar = document.querySelector('.lift-teleport__progress-bar');
	const status = document.querySelector('.lift-teleport__status');
	const result = document.querySelector('.lift-teleport__result');
	const resultMessage = document.querySelector('.lift-teleport__result-message');
	const resultTechnical = document.querySelector('.lift-teleport__result-technical');
	const historyBody = document.getElementById('lift-teleport-history-body');
	const refreshButton = document.getElementById('lift-teleport-refresh-history');

	if (!input || !dropzone || !bar || !status || !config.restUrl || !config.restNonce) {
		return;
	}

	const maxRetries = 3;

	const setProgress = (value) => {
		const safe = Math.max(0, Math.min(100, Number(value) || 0));
		bar.style.width = safe + '%';
		bar.setAttribute('aria-valuenow', String(Math.round(safe)));
	};

	const setStatus = (message) => {
		status.textContent = message;
	};

	const requestRest = async (endpoint, body) => {
		const response = await window.fetch(config.restUrl + endpoint, {
			method: 'POST',
			headers: {
				'X-WP-Nonce': config.restNonce
			},
			body
		});

		if (!response.ok) {
			throw new Error('HTTP ' + response.status);
		}

		return response.json();
	};

	const uploadFile = async (file) => {
		const formData = new window.FormData();
		formData.append('lift_file', file);
		return requestRest('/upload', formData);
	};

	const processImport = async (jobId, retry) => {
		const formData = new window.FormData();
		formData.append('job_id', jobId);
		const data = await requestRest('/process', formData);

		if (!data) {
			setStatus('No se recibió respuesta del servidor.');
			return;
		}

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

		if (data.retryable) {
			if (retry >= maxRetries) {
				setStatus('Fallo tras varios reintentos automáticos. Se ejecutó rollback.');
				return;
			}

			setStatus('Error temporal detectado. Reintentando fase…');
			window.setTimeout(() => {
				void processImport(jobId, retry + 1);
			}, 1200);
			return;
		}

		window.setTimeout(() => {
			void processImport(jobId, 0);
		}, 250);
	};

	const asFormData = (action, extra) => {
		const payload = new window.FormData();
		payload.append('action', action);
		payload.append('nonce', config.ajaxNonce || '');
		Object.keys(extra || {}).forEach((key) => payload.append(key, extra[key]));
		return payload;
	};

	const requestAjax = async (action, extra) => {
		const response = await window.fetch(config.ajaxUrl, {
			method: 'POST',
			body: asFormData(action, extra || {})
		});
		const json = await response.json();
		if (!json.success) {
			const message = json.data && json.data.message ? json.data.message : 'Error inesperado.';
			throw new Error(message);
		}
		return json.data;
	};

	const buildTechnicalDetail = (job) => {
		const errors = (job.logs || []).filter((log) => log.level === 'error');
		return [
			'Job: ' + job.id,
			'Estado: ' + job.status,
			'Fase: ' + job.phase,
			'Duración: ' + job.duration_ms + 'ms',
			'Chunks: ' + job.chunks_processed + '/' + job.chunks_total,
			'Mensaje técnico: ' + job.technical_detail,
			errors.length ? 'Errores: ' + errors.map((entry) => entry.code + ' - ' + entry.message).join(' | ') : 'Errores: ninguno'
		].join('\n');
	};

	const renderHistory = (jobs) => {
		if (!historyBody) {
			return;
		}
		if (!jobs.length) {
			historyBody.innerHTML = '<tr><td colspan="8">' + historyBody.dataset.emptyMessage + '</td></tr>';
			return;
		}

		historyBody.innerHTML = jobs
			.map((job) => {
				const errors = (job.logs || []).filter((log) => log.level === 'error').length;
				const reportUrl = new URL(config.adminPostUrl);
				reportUrl.searchParams.set('action', 'lift_teleport_download_report');
				reportUrl.searchParams.set('job_id', job.id);
				reportUrl.searchParams.set('_wpnonce', config.reportNonce || '');

				return '<tr>' +
					'<td><code>' + job.id + '</code></td>' +
					'<td>' + job.file_name + '</td>' +
					'<td>' + String(job.status).toUpperCase() + '</td>' +
					'<td>' + job.phase + '</td>' +
					'<td>' + job.duration_ms + ' ms</td>' +
					'<td>' + job.chunks_processed + '/' + job.chunks_total + '</td>' +
					'<td>' + errors + '</td>' +
					'<td><a class="button button-secondary" href="' + reportUrl.toString() + '">Descargar</a></td>' +
					'</tr>';
			})
			.join('');
	};

	const loadHistory = async () => {
		if (!historyBody || !config.ajaxUrl || !config.ajaxNonce) {
			return;
		}
		const data = await requestAjax('lift_teleport_list_jobs');
		renderHistory(data.jobs || []);
	};

	const showResult = (job) => {
		if (!result || !resultMessage || !resultTechnical) {
			return;
		}
		result.hidden = false;
		resultMessage.textContent = job.user_message || 'Importación finalizada.';
		resultTechnical.textContent = buildTechnicalDetail(job);
	};

	const simulateImport = async (fileName) => {
		const timer = window.setInterval(() => {
			const current = Number(bar.getAttribute('aria-valuenow') || 0);
			setProgress(Math.min(current + 10, 90));
		}, 120);

		try {
			const data = await requestAjax('lift_teleport_run_import', { fileName });
			window.clearInterval(timer);
			setProgress(100);
			setStatus(data.job.status === 'completed' ? 'Importación finalizada con éxito.' : 'Importación finalizada con incidencias.');
			showResult(data.job);
			await loadHistory();
		} catch (error) {
			window.clearInterval(timer);
			setStatus('No se pudo ejecutar la importación. Revisa el detalle técnico.');
			if (result && resultMessage && resultTechnical) {
				result.hidden = false;
				resultMessage.textContent = 'No se pudo completar la migración.';
				resultTechnical.textContent = String(error.message);
			}
		}
	};

	const beginImport = async (file) => {
		if (!file) {
			return;
		}

		setProgress(0);
		setStatus('Subiendo ' + file.name + '…');

		try {
			const uploadData = await uploadFile(file);
			if (!uploadData || !uploadData.job_id) {
				setStatus((uploadData && uploadData.message) || 'No se pudo iniciar la importación.');
				return;
			}

			setStatus(uploadData.message || 'Importación iniciada.');
			setProgress(uploadData.progress || 5);
			void processImport(uploadData.job_id, 0);
		} catch (error) {
			setStatus('Error de conexión al iniciar importación.');
		}

		void simulateImport(file.name);
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

	if (refreshButton) {
		refreshButton.addEventListener('click', () => {
			void loadHistory();
		});
	}

	void loadHistory();
})();
