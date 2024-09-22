

jQuery(document).ready(function ($) {
	const successSound = new Audio(gardariki_ajax.plugins_dir + '/assets/sounds/success-notification.wav');
	const errorSound = new Audio(gardariki_ajax.plugins_dir + '/assets/sounds/falling-notification.wav');

	let isChecked = false; // letiable to track state

	$('#select-all-tables').on('click', function () {
		isChecked = !isChecked; // Toggle the state
		$('.form-table input[type="checkbox"]').prop('checked', isChecked);
	});

	$('.accordion-header--tables').on('click', function () {
		$(this).next('.accordion-content').slideToggle(300);
	});

	$('.accordion-header--download-db').on('click', function () {
		$(this).next('.accordion-content').slideToggle(300);
	});

	document.getElementById('run-backup').addEventListener('click', function () {
		const spinner = $('.manual-restore-gardariki-db-backup .spinner-gardariki-db-backup');

		spinner.show();
		let formData = new FormData(restoreForm[0]);
		formData.append('action', 'run_db_backup');
		formData.append('nonce', gardariki_ajax.nonce);

		$.ajax({
			url: gardariki_ajax.ajax_url,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			success: function (response) {
				if (response.success) {
					document.getElementById('backup-message').innerText = response.data.message;
					document.getElementById('backup-message').style.color = '#22bb33';

					spinner.hide();
					successSound.play(); // Play success sound
				} else {
					spinner.hide();
					errorSound.play(); // Play success sound
					document.getElementById('backup-message').innerText = response.data.message;
					document.getElementById('backup-message').style.color = '#bb2124';
				}
			},
			error: function (jqXHR, textStatus, errorThrown) {
				alert('Error initializing restore: ' + textStatus + ' - ' + errorThrown);
			}
		});
	});

	let restoreForm = $('#restore-form');
	let restoreButton = restoreForm.find('input[type="submit"]');
	let progressContainer = $('#restore-info');
	let progressText = progressContainer.find('.result-text');

	let restorationInProgress = false;

	restoreForm.on('submit', function (e) {
		e.preventDefault();
		startRestoration();
	});

	function startRestoration() {
		const spinner = $('#restore-form .spinner-gardariki-db-backup');
		spinner.show();

		let formData = new FormData(restoreForm[0]);
		formData.append('action', 'init_restore');
		formData.append('nonce', gardariki_ajax.nonce);

		$.ajax({
			url: gardariki_ajax.ajax_url,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			success: function (response) {
				if (response.success) {
					restoreButton.prop('disabled', true);
					restorationInProgress = true;

					spinner.show();
					processChunk();
				} else {
					errorSound.play(); // Play success sound
					alert('Error: ' + (response.data || 'Unknown error occurred'));
				}
			},
			error: function (jqXHR, textStatus, errorThrown) {
				alert('Error initializing restore: ' + textStatus + ' - ' + errorThrown);
			}
		});
	}

	function processChunk() {
		const spinner = $('#restore-form .spinner-gardariki-db-backup');

		spinner.show();

		$.ajax({
			url: gardariki_ajax.ajax_url,
			type: 'POST',
			data: {
				action: 'process_restore_chunk',
				nonce: gardariki_ajax.nonce
			},
			success: function (response) {
				if (response.success) {
					if (response.data === 'Restoration completed successfully') {
						progressText.text('Restoration completed successfully');
						restoreButton.prop('disabled', false);
						restorationInProgress = false;
						progressText.css('color', '#22bb33');

						spinner.hide();
						successSound.play(); // Play success sound
					} else {
						spinner.show();

						setTimeout(processChunk, 1000);
					}
				} else {
					// errorSound.play(); // Play success sound

					// spinner.hide();
					alert('Error during restoration: ' + (response.data || 'Unknown error occurred'));
					restoreButton.prop('disabled', false);
					progressText.text('Restoration failed: ' + (response.data || 'Unknown error'));
					progressText.css('color', '#bb2124');

					restorationInProgress = false;
				}
			},
			error: function (jqXHR, textStatus, errorThrown) {
				spinner.hide();
				errorSound.play(); // Play success sound
				if (restorationInProgress) {
					// If there's an error but restoration is in progress, it might be due to a reload
					setTimeout(processChunk, 5000); // Wait 5 seconds before trying again
				} else {
					alert('Error during restoration: ' + textStatus + ' - ' + errorThrown);
					restoreButton.prop('disabled', false);
					progressText.text('Restoration failed due to an error: ' + textStatus);
					progressText.css('color', '#bb2124');

				}
			}
		});
	}
});
