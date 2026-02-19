(function () {
	const input = document.getElementById('lift-teleport-file');
	const dropzone = document.querySelector('.lift-teleport__dropzone');
	const bar = document.querySelector('.lift-teleport__progress-bar');
	const status = document.querySelector('.lift-teleport__status');
	const settings = window.liftTeleportAdmin || {};

	if (!input || !dropzone || !bar || !status || !settings.restUrl || !settings.nonce) {
		return;
	}

	const maxRetries = 3;

	const setProgress = (value) => {
		bar.style.width = value + '%';
		bar.setAttribute('aria-valuenow', String(value));
	};

	const setStatus = (message) => {
		status.textContent = message;
	};

	const request = async (endpoint, body) => {
		const response = await fetch(settings.restUrl + endpoint, {
			method: 'POST',
			headers: {
				'X-WP-Nonce': settings.nonce,
			},
			body,
		});
		return response.json();
	};

	const uploadFile = async (file) => {
		const formData = new FormData();
		formData.append('lift_file', file);
		return request('/upload', formData);
	};

	const processImport = async (jobId, retry = 0) => {
		const formData = new FormData();
		formData.append('job_id', jobId);
		const data = await request('/process', formData);

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
				processImport(jobId, retry + 1);
			}, 1200);
			return;
		}

		window.setTimeout(() => {
			processImport(jobId, 0);
		}, 250);
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
			processImport(uploadData.job_id, 0);
		} catch (error) {
			setStatus('Error de conexión al iniciar importación.');
		}
	};

	input.addEventListener('change', function () {
		if (!input.files || !input.files[0]) {
			return;
		}
		beginImport(input.files[0]);
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
		beginImport(file);
	});
})();
