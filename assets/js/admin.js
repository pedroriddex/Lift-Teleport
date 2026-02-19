(function () {
	const input = document.getElementById('lift-teleport-file');
	const dropzone = document.querySelector('.lift-teleport__dropzone');
	const bar = document.querySelector('.lift-teleport__progress-bar');
	const status = document.querySelector('.lift-teleport__status');

	if (!input || !dropzone || !bar || !status) {
		return;
	}

	const setProgress = (value) => {
		bar.style.width = value + '%';
		bar.setAttribute('aria-valuenow', String(value));
	};

	const simulateImport = (fileName) => {
		let progress = 0;
		status.textContent = 'Preparando importación de ' + fileName + '…';
		setProgress(0);

		const timer = window.setInterval(() => {
			progress += 10;
			setProgress(progress);

			if (progress >= 100) {
				window.clearInterval(timer);
				status.textContent = 'Prototipo completado. La importación real llegará en próximas versiones.';
			}
		}, 120);
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
})();
