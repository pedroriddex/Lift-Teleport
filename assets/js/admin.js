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

	if (!input || !dropzone || !bar || !status || !historyBody || !config.ajaxUrl || !config.nonce) {
		return;
	}

	const setProgress = (value) => {
		bar.style.width = value + '%';
		bar.setAttribute('aria-valuenow', String(value));
	};

	const asFormData = (action, extra = {}) => {
		const payload = new window.FormData();
		payload.append('action', action);
		payload.append('nonce', config.nonce);
		Object.keys(extra).forEach((key) => payload.append(key, extra[key]));
		return payload;
	};

	const request = async (action, extra = {}) => {
		const response = await window.fetch(config.ajaxUrl, {
			method: 'POST',
			body: asFormData(action, extra),
		});
		const json = await response.json();
		if (!json.success) {
			const fallback = 'LT-IMP-UNKNOWN';
			const message = json.data && json.data.message ? json.data.message : 'Error inesperado.';
			const code = json.data && json.data.code ? json.data.code : fallback;
			throw new Error(message + ' [' + code + ']');
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
			errors.length ? 'Errores: ' + errors.map((entry) => entry.code + ' - ' + entry.message).join(' | ') : 'Errores: ninguno',
		].join('\n');
	};

	const renderHistory = (jobs) => {
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
		const data = await request('lift_teleport_list_jobs');
		renderHistory(data.jobs || []);
	};

	const showResult = (job) => {
		if (!result || !resultMessage || !resultTechnical) {
			return;
		}
		result.hidden = false;
		resultMessage.textContent = job.user_message;
		resultTechnical.textContent = buildTechnicalDetail(job);
	};

	const simulateImport = async (fileName) => {
		let progress = 0;
		status.textContent = 'Preparando importación de ' + fileName + '…';
		setProgress(0);

		const timer = window.setInterval(() => {
			progress = Math.min(progress + 10, 90);
			setProgress(progress);
		}, 120);

		try {
			const data = await request('lift_teleport_run_import', { fileName });
			window.clearInterval(timer);
			setProgress(100);
			status.textContent = data.job.status === 'completed'
				? 'Importación finalizada con éxito.'
				: 'Importación finalizada con incidencias.';
			showResult(data.job);
			await loadHistory();
		} catch (error) {
			window.clearInterval(timer);
			status.textContent = 'No se pudo ejecutar la importación. Revisa el detalle técnico.';
			if (result && resultMessage && resultTechnical) {
				result.hidden = false;
				resultMessage.textContent = 'No se pudo completar la migración.';
				resultTechnical.textContent = String(error.message);
			}
		}
	};

	input.addEventListener('change', function () {
		if (!input.files || !input.files[0]) {
			return;
		}
		simulateImport(input.files[0].name);
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
		simulateImport(file.name);
	});

	if (refreshButton) {
		refreshButton.addEventListener('click', () => {
			loadHistory();
		});
	}

	loadHistory();
})();
