/**
 * MainWP Password Policy Settings - AJAX Update Handler
 *
 * Handles progressive AJAX updates of password policy settings across sites.
 * Matches the sync sites functionality pattern.
 */

jQuery(document).ready(function($) {
	$('#mainwp_password_policy_window').on('change', function() {
		if ($(this).val() === '0') {
			$('#mainwp-password-policy-additional-settings').slideUp();
		} else {
			$('#mainwp-password-policy-additional-settings').slideDown();
		}
	});

	setTimeout(function() {
		if ($('#overwrite-enabled-checkbox').length) {
			$('#overwrite-enabled-checkbox').checkbox({
				onChecked: function() {
					$('#individual-settings-fields').slideDown(300);
					$('#save-individual-password-policy').slideDown(300);
				},
				onUnchecked: function() {
					$('#individual-settings-fields').slideUp(300);
					$('#save-individual-password-policy').slideUp(300);
				}
			});
		}

		if ($('#individual_max_age_days').length) {
			let initialValue = $('#individual_max_age_days').val();

			if (initialValue === '0') {
				$('#individual-additional-settings').hide();
			}

			$('#individual_max_age_days').dropdown({
				onChange: function(value) {
					if (value === '0') {
						$('#individual-additional-settings').slideUp(300);
					} else {
						$('#individual-additional-settings').slideDown(300);
					}
				}
			});
		}

		if ($('#individual_show_notices_to').length) {
			$('#individual_show_notices_to').dropdown();
		}
	}, 300);

	$(document).on('click', '#save-individual-password-policy', function(e) {
		e.preventDefault();

		let button = $(this);
		let form = $('#mainwp-individual-password-policy-form');
		let statusDiv = $('#individual-password-policy-status');

		if (!form.length) {
			return;
		}

		button.addClass('loading disabled');
		statusDiv.hide();

		let formData = {
			action: 'mainwp_password_policy_save_individual',
			site_id: form.find('input[name="site_id"]').val(),
			overwrite_enabled: $('#overwrite_enabled').is(':checked') ? '1' : '0',
			max_age_days: form.find('select[name="max_age_days"]').val(),
			due_soon_message: form.find('input[name="due_soon_message"]').val(),
			overdue_message: form.find('input[name="overdue_message"]').val(),
			show_notices_to: form.find('select[name="show_notices_to"]').val()
		};

		let data = mainwp_secure_data(formData);

		$.ajax({
			type: 'POST',
			url: ajaxurl,
			data: data,
			success: function(response) {
				button.removeClass('loading disabled');

				if (response.success) {
					statusDiv
						.removeClass('red')
						.addClass('green icon')
						.html(response.data.message)
						.show();

					setTimeout(function() {
						location.reload();
					}, 1500);
				} else {
					statusDiv
						.removeClass('green icon')
						.addClass('red icon')
						.html(response?.data?.message ?? MainWP.I18n.t('An error occurred', 'mainwp'))
						.show();

					$('html, body').animate({
						scrollTop: statusDiv.offset().top - 100
					}, 500);
				}
			},
			error: function(xhr, status, error) {
				button.removeClass('loading disabled');
				statusDiv
					.removeClass('green icon')
					.addClass('red icon')
					.html(MainWP.I18n.t('AJAX request failed', 'mainwp'))
					.show();

				$('html, body').animate({
					scrollTop: statusDiv.offset().top - 100
				}, 500);
			},
			dataType: 'json'
		});
	});
});

globalThis.mainwp_password_policy_vars = {
	sitesToUpdate: [],
	sitesTotal: 0,
	sitesLeft: 0,
	sitesDone: 0,
	currentSite: 0,
	updateRunning: false,
	currentThreads: 0,
	maxThreads: 3,
	settings: {},
	sitesData: {}
};

/**
 * Start the password policy update process
 *
 * @param {array} siteIds Array of site IDs to update
 * @param {object} sitesData Object containing site data keyed by site ID
 * @param {object} settings Password policy settings to apply
 */
globalThis.mainwp_password_policy_start_update = function(siteIds, sitesData, settings) {
	mainwp_password_policy_vars.sitesToUpdate = siteIds;
	mainwp_password_policy_vars.sitesData = sitesData;
	mainwp_password_policy_vars.settings = settings;
	mainwp_password_policy_vars.currentSite = 0;
	mainwp_password_policy_vars.sitesDone = 0;
	mainwp_password_policy_vars.sitesTotal = siteIds.length;
	mainwp_password_policy_vars.sitesLeft = siteIds.length;
	mainwp_password_policy_vars.updateRunning = true;

	jQuery('#mainwp-password-policy-sites-list').html('');

    for (const siteId of siteIds) {
		let siteName = sitesData[siteId].name;
		jQuery('#mainwp-password-policy-sites-list').append(
			'<div class="item">' +
			'<div class="right floated content">' +
			'<div class="password-policy-site-status" siteid="' + siteId + '">' +
			'<span data-position="left center" data-inverted="" data-tooltip="' + MainWP.I18n.t('Pending', 'mainwp') + '">' +
			'<i class="clock outline icon"></i>' +
			'</span>' +
			'</div>' +
			'</div>' +
			'<div class="content">' +
			jQuery('<div>').text(siteName).html() +
			'</div>' +
			'</div>'
		);
	}

	jQuery('#mainwp-password-policy-progress-modal .mainwp-modal-progress').progress({
		value: 0,
		total: siteIds.length
	});

	mainwp_password_policy_update_progress_status();

	jQuery('#mainwp-password-policy-progress-modal').modal({
		closable: false
	}).modal('show');

	mainwp_password_policy_loop_next();
};

/**
 * Process next batch of sites
 */
let mainwp_password_policy_loop_next = function() {
	while (mainwp_password_policy_vars.updateRunning &&
	       (mainwp_password_policy_vars.currentThreads < mainwp_password_policy_vars.maxThreads) &&
	       (mainwp_password_policy_vars.sitesLeft > 0)) {
		mainwp_password_policy_update_next();
	}
};

/**
 * Update next site in queue
 */
let mainwp_password_policy_update_next = function() {
	mainwp_password_policy_vars.currentThreads++;
	mainwp_password_policy_vars.sitesLeft--;

	let siteId = mainwp_password_policy_vars.sitesToUpdate[mainwp_password_policy_vars.currentSite++];

	mainwp_password_policy_update_site_status(
		siteId,
		'<span data-inverted="" data-position="left center" data-tooltip="' + MainWP.I18n.t('Updating...', 'mainwp') + '">' +
		'<i class="sync alternate loading icon"></i>' +
		'</span>'
	);

	let data = mainwp_secure_data({
		action: 'mainwp_password_policy_update_site',
		site_id: siteId,
		max_age_days: mainwp_password_policy_vars.settings.max_age_days,
		due_soon_days: 7,
		due_soon_message: mainwp_password_policy_vars.settings.due_soon_message,
		overdue_message: mainwp_password_policy_vars.settings.overdue_message,
		show_notices_to: mainwp_password_policy_vars.settings.show_notices_to
	});

	mainwp_password_policy_update_next_int(siteId, data, 0);
};

/**
 * Execute AJAX request with retry logic
 */
let mainwp_password_policy_update_next_int = function(siteId, data, errors) {
	jQuery.ajax({
		type: 'POST',
		url: ajaxurl,
		data: data,
		success: function(pSiteId) {
			return function(response) {
				mainwp_password_policy_vars.currentThreads--;

				if (response?.success) {
					mainwp_password_policy_update_site_status(
						pSiteId,
						'<span data-inverted="" data-position="left center" data-tooltip="' + MainWP.I18n.t('Updated successfully', 'mainwp') + '">' +
						'<i class="check green icon"></i>' +
						'</span>',
						true
					);
				} else {
					let errorMsg = response?.error ? response.error : MainWP.I18n.t('Unknown error', 'mainwp');
					if ( response?.debug) {
						console.log('Password policy update debug (Site ' + pSiteId + '):', response.debug);
						errorMsg += ' (See console for details)';
					}
					mainwp_password_policy_update_site_status(
						pSiteId,
						'<span data-inverted="" data-position="left center" data-tooltip="' + errorMsg + '">' +
						'<i class="exclamation red icon"></i>' +
						'</span>'
					);
				}

				mainwp_password_policy_update_done();
			}
		}(siteId),
		error: function(pSiteId, pData, pErrors) {
			return function() {
				if (pErrors > 5) {
					mainwp_password_policy_vars.currentThreads--;
					mainwp_password_policy_update_site_status(
						pSiteId,
						'<span data-inverted="" data-position="left center" data-tooltip="' + MainWP.I18n.t('Process timed out. Please try again.', 'mainwp') + '">' +
						'<i class="exclamation yellow icon"></i>' +
						'</span>'
					);
					mainwp_password_policy_update_done();
				} else {
					pErrors++;
					mainwp_password_policy_update_next_int(pSiteId, pData, pErrors);
				}
			}
		}(siteId, data, errors),
		dataType: 'json'
	});
};

/**
 * Update visual status for a site
 *
 * @param {int} siteId Site ID
 * @param {string} statusHtml HTML content for status
 * @param {bool} isSuccess Whether update was successful
 */
let mainwp_password_policy_update_site_status = function(siteId, statusHtml, isSuccess) {
	jQuery('.password-policy-site-status[siteid="' + siteId + '"]').html(statusHtml);
	if ( isSuccess !== undefined && isSuccess) {
		let row = jQuery('.password-policy-site-status[siteid="' + siteId + '"]').closest('.item');
		jQuery(row).insertAfter(jQuery('#mainwp-password-policy-sites-list .item').last());
	}
};

/**
 * Update overall progress status
 */
let mainwp_password_policy_update_progress_status = function() {
	let statusText = mainwp_password_policy_vars.sitesDone + ' / ' + mainwp_password_policy_vars.sitesTotal + ' ' + MainWP.I18n.t('updated', 'mainwp');
	jQuery('#mainwp-password-policy-progress-modal .mainwp-modal-progress').find('.label').html(statusText);
	jQuery('#mainwp-password-policy-progress-modal .mainwp-modal-progress').progress('set progress', mainwp_password_policy_vars.sitesDone);
};

/**
 * Handle completion of a site update
 */
let mainwp_password_policy_update_done = function() {
	if (!mainwp_password_policy_vars.updateRunning) {
		return;
	}

	mainwp_password_policy_vars.sitesDone++;

	if (mainwp_password_policy_vars.sitesDone > mainwp_password_policy_vars.sitesTotal) {
		mainwp_password_policy_vars.sitesDone = mainwp_password_policy_vars.sitesTotal;
	}

	mainwp_password_policy_update_progress_status();

	if (mainwp_password_policy_vars.sitesDone === mainwp_password_policy_vars.sitesTotal) {
		let successSites = jQuery('#mainwp-password-policy-progress-modal .check.green.icon').length;
		if (mainwp_password_policy_vars.sitesDone === successSites) {
			mainwp_password_policy_vars.updateRunning = false;
			setTimeout(function() {
				jQuery('#mainwp-password-policy-progress-modal').modal('hide');
				location.reload();
			}, 3000);
		} else {
			mainwp_password_policy_vars.updateRunning = false;
		}
		return;
	}

	mainwp_password_policy_loop_next();
};
