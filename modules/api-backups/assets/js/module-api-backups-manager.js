/**
 * MainWP 3rd-party API Manager JS.
 *
 * JS actions for the MainWP 3rd-party API Manager.
 *
 * @file   This files handles all js actions for the MainWP 3rd-party API Manager.
 * @author MainWP.
 * @since  4.2.7.1
 */

/* eslint complexity: ["error", 100] */

jQuery(function(){

    /********************************************************
     * Ajax Event Hooks.
     */

    /**
     * CloudWays Ajax Hooks
     */

    // Trigger action_backup.
    jQuery('.mainwp_3rd_party_api_cloudways_action_backup').on('click', function (event) {
        cloudways_action_backup(this, event);
    });

    // Trigger action_backup.
    jQuery('#mainwp_3rd_party_api_cloudways_action_individual_create_backup').on('click', function (event) {
        cloudways_action_backup(this, event);
    });

    // Trigger action_update_ids.
    jQuery('.mainwp_3rd_party_api_cloudways_action_update_ids').on('click', function (event) {
        cloudways_action_update_ids(this, event);
    });

    //Trigger action_refresh_available_backups.
    jQuery('#mainwp_3rd_party_api_cloudways_action_refresh_available_backups').on('click', function (event) {
        cloudways_action_refresh_available_backups(this, event);
    });

    // Trigger action_restore_backup.
    jQuery(document).on('click', '.mainwp_3rd_party_api_cloudways_action_restore_backup', function (event) {
        cloudways_action_restore_backup(this, event);
    });

    // Trigger action_delete_backup. This will delete the 24hr restore point allowing for a new restoration to be created.
    jQuery('#mainwp_3rd_party_api_cloudways_action_delete_backup').on('click', function (event) {
        cloudways_action_delete_backup(this, event);
    });

    /**
     * Vultr Ajax Hooks
     */

    // Trigger action_backup.
    jQuery('.mainwp_3rd_party_api_vultr_action_backup').on('click', function (event) {
        vultr_action_create_snapshot(this, event);
    });

    // Trigger action_backup.
    jQuery('#mainwp_3rd_party_api_vultr_action_individual_create_backup').on('click', function (event) {
        vultr_action_create_snapshot(this, event);
    });

    // Trigger action_update_ids.
    jQuery('.mainwp_3rd_party_api_vultr_action_update_ids').on('click', function (event) {
        vultr_action_update_ids(this, event);
    });

    //Trigger action_refresh_available_backups.
    jQuery('#mainwp_3rd_party_api_vultr_action_refresh_available_backups').on('click', function (event) {
        vultr_action_refresh_available_backups(this, event);
    });

    // Trigger action_restore_backup.
    jQuery(document).on('click', '.mainwp_3rd_party_api_vultr_action_restore_backup', function (event) {
        vultr_action_restore_backup(this, event);
    });

    // Trigger action_delete_backup.
    jQuery(document).on('click', '.mainwp_3rd_party_api_vultr_action_delete_backup', function (event) {
        vultr_action_delete_backup(this, event);
    });

    /**
     * GridPane Ajax Hooks
     */

    // Trigger action_backup.
    jQuery('.mainwp_3rd_party_api_gridpane_action_backup').on('click', function (event) {
        gridpane_action_create_backup(this, event);
    });

    // Trigger action_backup.
    jQuery('#mainwp_3rd_party_api_gridpane_action_individual_create_backup').on('click', function (event) {
        gridpane_action_create_backup(this, event);
    });

    // Trigger action_update_ids.
    jQuery('.mainwp_3rd_party_api_gridpane_action_update_ids').on('click', function (event) {
        gridpane_action_update_ids(this, event);
    });

    // Trigger action_refresh_available_backups.
    jQuery('#mainwp_3rd_party_api_gridpane_action_refresh_available_backups').on('click', function (event) {
        gridpane_action_refresh_available_backups(this, event);
    });

    // Trigger action_restore_backup.
    jQuery(document).on('click', '.mainwp_3rd_party_api_gridpane_action_restore_backup', function (event) {
        gridpane_action_restore_backup(this, event);
    });

    // Trigger action_delete_backup.
    jQuery(document).on('click', '.mainwp_3rd_party_api_gridpane_action_delete_backup', function (event) {
        gridpane_action_delete_backup(this, event);
    });

    /**
     * Linode Ajax Hooks
     */

    // Trigger action_backup.
    jQuery('.mainwp_3rd_party_api_linode_action_backup').on('click', function (event) {
        linode_action_create_backup(this, event);
    });

    // Trigger action_backup.
    jQuery('#mainwp_3rd_party_api_linode_action_individual_create_backup').on('click', function (event) {
        linode_action_create_backup(this, event);
    });

    //Trigger action_update_ids.
    jQuery('.mainwp_3rd_party_api_linode_action_update_ids').on('click', function (event) {
        linode_action_update_ids(this, event);
    });

    // Trigger action_refresh_available_backups.
    jQuery('#mainwp_3rd_party_api_linode_action_refresh_available_backups').on('click', function (event) {
        linode_action_refresh_available_backups(this, event);
    });

    // Trigger action_restore_backup.
    jQuery(document).on('click', '.mainwp_3rd_party_api_linode_action_restore_backup', function (event) {
        linode_action_restore_backup(this, event);
    });

    // Trigger action_delete_backup.
    jQuery('#mainwp_3rd_party_api_linode_action_cancel_backups').on('click', function (event) {
        let confirmMsg = __('Are you sure you want to Disable and Delete all existing backups from this Linode?');
        mainwp_confirm(confirmMsg, function () {
            linode_action_cancel_backups(this, event);
        });
    });

    /**
     * digitalocean Ajax Hooks
     */

    //Trigger action_update_ids.
    jQuery('.mainwp_3rd_party_api_digitalocean_action_update_ids').on('click', function (event) {
        digitalocean_action_update_ids(this, event);
    });

    // Trigger action_create_backup.
    jQuery('.mainwp_3rd_party_api_digitalocean_action_backup').on('click', function (event) {
        digitalocean_action_create_backup(this, event);
    });

    // Trigger action_individual_create_backup.
    jQuery('#mainwp_3rd_party_api_digitalocean_action_individual_create_backup').on('click', function (event) {
        digitalocean_action_create_backup(this, event);
    });

    // Trigger action_refresh_available_backups.
    jQuery('#mainwp_3rd_party_api_digitalocean_action_refresh_available_backups').on('click', function (event) {
        digitalocean_action_refresh_available_backups(this, event);
    });

    // Trigger action_delete_backup.
    jQuery(document).on('click', '.mainwp_3rd_party_api_digitalocean_action_delete_backup', function (event) {
        digitalocean_action_delete_backup(this, event);
    });

    // Trigger action_restore_backup.
    jQuery(document).on('click', '.mainwp_3rd_party_api_digitalocean_action_restore_backup', function (event) {
        digitalocean_action_restore_backup(this, event);
    });

    /**
     * CPanel Ajax Hooks
     */

    // Trigger action_refresh_available_backups.
    jQuery('#mainwp_3rd_party_api_cpanel_action_refresh_available_backups').on('click', function (event) {
        cpanel_action_refresh_available_backups(this, event);
    });

    // Trigger action_restore_backup.
    jQuery(document).on('click', '.mainwp_3rd_party_api_cpanel_action_restore_backup', function (event) {
        let confirmMsg = __('Are you sure you want to Restore this backup?');
        mainwp_confirm(confirmMsg, function () {
            cPanel_action_restore_backup(this, event);
        });
    });

    // Trigger action_restore_database_backup.
    jQuery(document).on('click', '.mainwp_3rd_party_api_cpanel_action_restore_database_backup', function (event) {
        let confirmMsg = __('Are you sure you want to Restore this backup?');
        let btObj = this;
        mainwp_confirm(confirmMsg, function () {
            cpanel_action_restore_database_backup(btObj, event);
        });
    });

    // Trigger action_restore_manual_backup.
    jQuery(document).on('click', '.mainwp_3rd_party_api_cpanel_action_restore_manual_backup', function (event) {
        let confirmMsg = __('Are you sure you want to Restore this backup?');
        mainwp_confirm(confirmMsg, function () {
            cpanel_action_restore_manual_backup(this, event);
        });
    });

    // Trigger action_backup.
    jQuery('#mainwp_3rd_party_api_cpanel_action_individual_create_backup').on('click', function (event) {
        cpanel_action_create_backup(this, event);
    });

    // Trigger action_create_wptk_backup.
    jQuery('#mainwp_3rd_party_api_cpanel_action_create_wptk_backup').on('click', function (event) {
        cpanel_action_create_wptk_backup(this, event);
    });

    // Trigger action_backup for bulk backups.
    jQuery('.mainwp_3rd_party_api_cpanel_action_full_backup').on('click', function (event) {
        cpanel_action_create_full_backup(this, event);
    });

    // Trigger action_database_backup.
    jQuery('#mainwp_3rd_party_api_cpanel_action_create_database_backup').on('click', function (event) {
        cpanel_action_create_database_backup(this, event);
    });

    // Trigger action_backup for full backups. Database & Files.
    jQuery('#mainwp_3rd_party_api_cpanel_action_create_full_backup').on('click', function (event) {
        cpanel_action_create_full_backup(this, event);
    });

    // Trigger action_restore_wptk_backup.
    jQuery('.mainwp_3rd_party_api_cpanel_action_restore_wptk_backup').on('click', function (event) {
        let confirmMsg = __('Are you sure you want to Restore this backup?');
        mainwp_confirm(confirmMsg, function () {
            cpanel_action_restore_wptk_backup(this, event);
        });
    });

    // Trigger action_delete_wptk_backup.
    jQuery('.mainwp_3rd_party_api_cpanel_action_delete_wptk_backup').on('click', function (event) {
        let confirmMsg = __('Are you sure you want to Delete this backup?');
        mainwp_confirm(confirmMsg, function () {
            cpanel_action_delete_wptk_backup(this, event);
        });
    });

    // Trigger action_download_wptk_backup
    jQuery('.mainwp_3rd_party_api_cpanel_action_download_wptk_backup').on('click', function (event) {
        cpanel_action_download_wptk_backup(this, event);
    });

    /**
     * Plesk Ajax Hooks
     */

    // Trigger action_refresh_available_backups.
    jQuery('#mainwp_3rd_party_api_plesk_action_refresh_available_backups').on('click', function (event) {
        plesk_action_refresh_available_backups(this, event);
    });

    //Trigger action_backup.
    jQuery('#mainwp_3rd_party_api_plesk_action_individual_create_backup').on('click', function (event) {
        plesk_action_create_backup(this, event);
    });

    // Trigger action_backup. ( bulk )
    jQuery('.mainwp_3rd_party_api_plesk_action_backup ').on('click', function (event) {
        plesk_action_create_backup(this, event);
    });

    // Trigger action_restore_backup.
    jQuery(document).on('click', '.mainwp_3rd_party_api_plesk_action_restore_backup', function (event) {
        let confirmMsg = __('Are you sure you want to Restore this backup?');
        mainwp_confirm(confirmMsg, function () {
            plesk_action_restore_backup(this, event);
        });
    });

    // Trigger action_delete_backup.
    jQuery(document).on('click', '.mainwp_3rd_party_api_plesk_action_delete_backup', function (event) {
        let confirmMsg = __('Are you sure you want to Delete this backup?');
        mainwp_confirm(confirmMsg, function () {
            plesk_action_delete_backup(this, event);
        });
    });

    // Trigger action_backup_selected_sites.
    jQuery('#action_backup_selected_sites').on('click', function (event) {
        action_backup_selected_sites(this, event);
    });

    /********************************************************
     * Fomantic-ui Tabs Event Hooks.
     */

    /**
     * Activate Fomantic-ui tabs for the 3rd-party API Manager Settings page,
     * Settings > 3rd-Party API Manager.
     *
     * @url https://fomantic-ui.com/modules/tab.html#/usage
     */
    jQuery('#3rd-party-api-manager .menu .item').tab();

    /**
     * Activate Fomantic-ui tabs for the Individual cPanel Backups page,
     * ChildSite Overview > API Backups
     *
     * @url https://fomantic-ui.com/modules/tab.html#/usage
     */
    jQuery('#mainwp_api_cpanel_backup_tabs .item').tab();

    // Trigger action_check_tab. Handle the click event on the TAB Buttons.
    jQuery('#mainwp_api_cpanel_backup_tabs .item').on('click', function (event) {
        action_check_tab(this, event);
    });

    /**
     *  Handle switching TAB Buttons.
     *  Check if cPanel or Plesk is selected TAB when page loads & display the correct content.
      */
    let ref_this = jQuery('#mainwp_api_cpanel_backup_tabs div.active');
    if (ref_this.data('tab') === 'cpanel-native') {
        jQuery('#mainwp_3rd_party_api_cpanel_action_create_full_backup').show();
        jQuery('#mainwp_3rd_party_api_cpanel_action_create_wptk_backup').hide();
    } else if (ref_this.data('tab') === 'cpanel-wp-toolkit') {
        jQuery('#mainwp_3rd_party_api_cpanel_action_create_full_backup').hide();
        jQuery('#mainwp_3rd_party_api_cpanel_action_create_wptk_backup').show();
    }
});
/********************************************************
 * Invocable Functions.
 */

/**
 * Extension Overview Page: #action_backup_selected_sites.
 *
 * This function is called when the "Backup Selected Sites" button is clicked.
 * mainwp_api_backups_do_backups() is defined in assets/js/mainwp-api-backups.js.
 */
action_backup_selected_sites = function (pObj) {
    mainwp_api_backups_do_backups(pObj);
}

/**
 *  Handle switching TAB Action Buttons on Individual Cpanel Backups page.
 *  Check if cPanel or Plesk is selected TAB when TAB is clicked & display the correct content.
 */
action_check_tab = function () {

    let ref_this = jQuery('#mainwp_api_cpanel_backup_tabs div.active');
    if (ref_this.data('tab') === 'cpanel-native') {
        jQuery('#mainwp_3rd_party_api_cpanel_action_create_full_backup').show();
        jQuery('#mainwp_3rd_party_api_cpanel_action_create_wptk_backup').hide();
    } else if (ref_this.data('tab') === 'cpanel-wp-toolkit') {
        jQuery('#mainwp_3rd_party_api_cpanel_action_create_full_backup').hide();
        jQuery('#mainwp_3rd_party_api_cpanel_action_create_wptk_backup').show();
    }
}

/********************************************************
 * Cloudways Functions.
 */

// Create Backup.
cloudways_action_backup = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    let button = jQuery(pObj).closest('td');
    let lastBackup = jQuery(pObj).closest('td').prev();
    let websiteId = jQuery(pObj).attr('website_id');

    let data = mainwp_secure_data({
        action: 'cloudways_action_backup',
        website_id: websiteId,
        backup_api: 'cloudways'
    });

    jQuery(button).html('<i class="notched circle loading icon"></i>');
    jQuery(lastBackup).html('Requesting Backup...');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html( 'A backup has been requested. Please allow some time for the backup to complete, then Refresh Available Backups.')
			;

			setTimeout(function () {
				location.reload();
			}, 5000);

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while requesting your backup. Please check that your ID and API Key are correct.')
			;

			setTimeout(function () {
				location.reload();
			}, 5000);

        }
    });

};

// Assign Apps to Child Sites.
cloudways_action_update_ids = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    let button = jQuery(pObj).closest('td');

    let data = mainwp_secure_data({
        action: 'cloudways_action_update_ids'
    });

    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {
            window.location.search += '&update=true';
        } else {
            window.location.search += '&update=false';
        }

    });
};


// Refresh Available Backup.
cloudways_action_refresh_available_backups = function (pObj) {

    let websiteId = jQuery(pObj).attr('website_id');

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    // Prepare the data to send.
    // The "action" is the name of the action hook to trigger.
    // Anything else is data that we want to pass to the PHP function.
    let data = mainwp_secure_data({
        action: 'cloudways_action_refresh_available_backups',
        website_id: websiteId,
        backup_api: 'cloudways'
    });

    // Send a POST request to the ajaxurl (WordPress variable), using the data
    // we prepared above, and running the below function when we receive the
    // response. The last parameter tells jQuery to expect a JSON response

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html( 'Available backups have been refreshed.' )
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

            setInterval('location.reload()', 7000);        // Using .reload() method.

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html( 'There was an issue while refreshing available backups.' )
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    }, 'json');
};

// Restore backups.
cloudways_action_restore_backup = function (pObj) {
    let button = jQuery(pObj);
    let websiteId = jQuery(pObj).attr('website_id');
    let backupDate = jQuery(pObj).attr('backup_date');

    let data = mainwp_secure_data({
        action: 'cloudways_action_restore_backup',
        website_id: websiteId,
        backup_date: backupDate,
        backup_api: 'cloudways'
    });

    // Start button animation.
    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html( 'Your application is in the process of restoring. This will take a few minuets...' )
			;

            // Stop button animation.
            jQuery(button).html('<i class="undo icon"></i>');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html( 'There was an issue while attempting to restore your application...' )
			;

            // Stop button animation.
            jQuery(button).html('<i class="undo icon"></i>');

        }
    });
};

// Delete backups.
cloudways_action_delete_backup = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    let button = jQuery(pObj).closest('td');

    let websiteId = jQuery(pObj).attr('website_id');

    let data = mainwp_secure_data({
        action: 'cloudways_action_delete_backup',
        website_id: websiteId,
        backup_api: 'cloudways'
    });

    // Start button animation.
    jQuery(button).find('i.loading').css({ 'display': 'block' });
    jQuery(button).find('div').addClass('hidden');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html( 'The local backups done before last restore have been deleted.' )
			;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html( 'There was an issue while deleting your backups.' )
			;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        }
    });
};

/********************************************************
 * Vultr Functions.
 */

// Assign Instances to Child Sites.
vultr_action_update_ids = function (pObj) {
    jQuery(pObj).attr('disabled', 'true');
    let button = jQuery(pObj).closest('td');

    let data = mainwp_secure_data({
        action: 'vultr_action_update_ids'
    });

    // Start button animation.
    jQuery(button).find('i.loading').css({ 'display': 'block' });
    jQuery(button).find('div').addClass('hidden');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('Backup Stats have been refreshed.')
			;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html( 'There was an issue refreshing the Backup Stats.' )
			;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        }
    });
};

// Create Backup.
vultr_action_create_snapshot = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    let button = jQuery(pObj).closest('td');
    let lastBackup = jQuery(pObj).closest('td').prev();
    let websiteId = jQuery(pObj).attr('website_id');

    let data = mainwp_secure_data({
        action: 'vultr_action_create_snapshot',
        website_id: websiteId,
        backup_api: 'vultr'
    });

    jQuery(button).html('<i class="notched circle loading icon"></i>');
    jQuery(lastBackup).html('Requesting Backup...');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('A backup has been requested.');
			setTimeout(function () {
				location.reload();
			}, 5000);

        } else {
            let err_message = '' !== response ? response : 'There was an issue while requesting your backup. Please check that your ID and API Key are correct.';

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html( err_message );
			setTimeout(function () {
				location.reload();
			}, 5000);

        }
    });
};

// Refresh Available Backup.
vultr_action_refresh_available_backups = function (pObj) {

    let websiteId = jQuery(pObj).attr('website_id');

    let data = mainwp_secure_data({
        action: 'vultr_action_refresh_available_backups',
        website_id: websiteId,
        backup_api: 'vultr'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('Available backups have been refreshed.')
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

            setInterval('location.reload()', 7000);        // Using .reload() method.

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while refreshing available backups.' )
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};
// Restore Backup.
vultr_action_restore_backup = function (pObj) {

    let button = jQuery(pObj);
    let websiteId = jQuery(pObj).attr('website_id');
    let snapshotID = jQuery(pObj).attr('snapshot_id');

    let data = mainwp_secure_data({
        action: 'vultr_action_restore_backup',
        website_id: websiteId,
        backup_api: 'vultr',
        snapshot_id: snapshotID
    });

    // Start button animation.
    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('Backup restoration process has begun. Please wait a few moments and then check the site.')
			;

            // Stop button animation.
            jQuery(button).html('<i class="undo icon"></i>');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while restoring your backup.')
			;

            // Stop button animation.
            jQuery(button).html('<i class="undo icon"></i>');

        }
    });
};


// Delete Backup.
vultr_action_delete_backup = function (pObj) {

    let button = jQuery(pObj);
    let websiteId = jQuery(pObj).attr('website_id');
    let snapshotID = jQuery(pObj).attr('snapshot_id');

    let data = mainwp_secure_data({
        action: 'vultr_action_delete_backup',
        website_id: websiteId,
        backup_api: 'vultr',
        snapshot_id: snapshotID
    });

    // Start button animation.
    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('The selected backup has been deleted.')
			;

            // Stop button animation.
            jQuery(button).html('<i class="undo icon"></i>');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while deleting the selected backup.')
			;

            // Stop button animation.
            jQuery(button).html('<i class="undo icon"></i>');

        }
    });
};


/********************************************************
 * Gridpane Functions.
 */

// Assign Site ID's to Child Sites.
gridpane_action_update_ids = function (pObj) {
    jQuery(pObj).attr('disabled', 'true');
    let button = jQuery(pObj).closest('td');

    let data = mainwp_secure_data({
        action: 'gridpane_action_update_ids'
    });

    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {
            window.location.search += '&update=true';
        } else {
            window.location.search += '&update=false';
        }

    });
};

// Create Backup.
gridpane_action_create_backup = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    let button = jQuery(pObj).closest('td');
    let lastBackup = jQuery(pObj).closest('td').prev();
    let websiteId = jQuery(pObj).attr('website_id');

    let data = mainwp_secure_data({
        action: 'gridpane_action_create_backup',
        website_id: websiteId,
        backup_api: 'gridpane'
    });

    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery(lastBackup).html('Requesting Backup...');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('A backup has been requested.')
			;

            setTimeout(function () {
                location.reload();
            }, 5000);

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while requesting your backup. Please check that your ID and API Key are correct.' )
			;

            setTimeout(function () {
                location.reload();
            }, 5000);

        }
    });
};

// Refresh Available Backup.
gridpane_action_refresh_available_backups = function (pObj) {

    let websiteId = jQuery(pObj).attr('website_id');

    let data = mainwp_secure_data({
        action: 'gridpane_action_refresh_available_backups',
        website_id: websiteId,
        backup_api: 'gridpane'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('Available backups have been refreshed.')
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

            setInterval('location.reload()', 7000);        // Using .reload() method.

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while refreshing available backups.' )
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

// Restore Backup.
gridpane_action_restore_backup = function (pObj) {

    let button = jQuery(pObj);
    let websiteId = jQuery(pObj).attr('website_id');
    let backupType = jQuery(pObj).attr('backup_type');
    let backupName = jQuery(pObj).attr('backup_name');

    let data = mainwp_secure_data({
        action: 'gridpane_action_restore_backup',
        website_id: websiteId,
        backup_api: 'gridpane',
        backup_type: backupType,
        backup_name: backupName
    });

    // Start button animation.
    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('Backup restoration has begun. Please wait a few moments and then check the site.')
			;

            // Stop button animation.
            jQuery(button).html('<i class="undo icon"></i>');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while restoring your backup.' )
			;

            // Stop button animation.
            jQuery(button).html('<i class="undo icon"></i>');

        }
    });
};

// Delete Backup.
gridpane_action_delete_backup = function (pObj) {

    let button = jQuery(pObj);

    let websiteId = jQuery(pObj).attr('website_id');
    let backupType = jQuery(pObj).attr('backup_type');
    let backupName = jQuery(pObj).attr('backup_name');

    let data = mainwp_secure_data({
        action: 'gridpane_action_delete_backup',
        website_id: websiteId,
        backup_api: 'gridpane',
        backup_type: backupType,
        backup_name: backupName
    });

    // Start button animation.
    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('The selected backup has been deleted.')
			;

            // Stop button animation.
            jQuery(button).html('<i class="trash icon"></i>');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while deleting the selected backup.')
			;

            // Stop button animation.
            jQuery(button).html('<i class="trash icon"></i>');

        }
    });
};

/********************************************************
 * Linode Functions.
 */

// Assign Site ID's to Child Sites.
linode_action_update_ids = function (pObj) {
    jQuery(pObj).attr('disabled', 'true');
    let button = jQuery(pObj).closest('td');

    let data = mainwp_secure_data({
        action: 'linode_action_update_ids'
    });

    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {
            window.location.search += '&update=true';
        } else {
            window.location.search += '&update=false';
        }
    });
};

// Create Backup.
linode_action_create_backup = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    let button = jQuery(pObj).closest('td');
    let lastBackup = jQuery(pObj).closest('td').prev();
    let websiteId = jQuery(pObj).attr('website_id');

    let data = mainwp_secure_data({
        action: 'linode_action_create_backup',
        website_id: websiteId,
        backup_api: 'linode'
    });

    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery(lastBackup).html('Requesting Backup...');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('A backup has been requested.')
			;

            setTimeout(function () {
                location.reload();
            }, 5000);

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while requesting your backup. Please check that your backups are enabled & ID\'s are correct.')
			;

            setTimeout(function () {
                location.reload();
            }, 5000);
        }
    });
};

// Refresh Available Backup.
linode_action_refresh_available_backups = function (pObj) {

    let websiteId = jQuery(pObj).attr('website_id');

    let data = mainwp_secure_data({
        action: 'linode_action_refresh_available_backups',
        website_id: websiteId,
        backup_api: 'linode'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('Available backups have been refreshed.')
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

            setInterval('location.reload()', 7000);        // Using .reload() method.

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while refreshing available backups.')
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

// restore Backup.
linode_action_restore_backup = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    let button = jQuery(pObj).closest('td');
    let backupStatus = jQuery(pObj).closest('td').prev();
    let websiteId = jQuery(pObj).attr('website_id');
    let backupId = jQuery(pObj).attr('backup_id');

    let data = mainwp_secure_data({
        action: 'linode_action_restore_backup',
        website_id: websiteId,
        backup_api: 'linode',
        backup_id: backupId
    });

    // Start button animation.
    jQuery(button).html('<i class="notched circle loading icon"></i>');
    jQuery(backupStatus).html('Restoring Backup...');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('The backup restoration was successful. Please check your Linode Server Status...')
			;

            jQuery(backupStatus).html('Rebooting Server...');
            setInterval('location.reload()', 7000);// Using .reload() method.

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while restoring your backup.')
			;

            // Stop button animation.
            setInterval('location.reload()', 7000); // Using .reload() method.

        }
    });
};

// Cancel Backup.
linode_action_cancel_backups = function (pObj) {

    let websiteId = jQuery(pObj).attr('website_id');

    let data = mainwp_secure_data({
        action: 'linode_action_cancel_backups',
        website_id: websiteId,
        backup_api: 'linode'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('The backups associated with this Linode have been removed.')
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while canceling your backup.')
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

/********************************************************
 * Digital Ocean Functions.
 */

// Assign Site ID's to Child Sites.
digitalocean_action_update_ids = function (pObj) {
    jQuery(pObj).attr('disabled', 'true');
    let button = jQuery(pObj).closest('td');

    let data = mainwp_secure_data({
        action: 'digitalocean_action_update_ids'
    });

    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {
            window.location.search += '&update=true';
        } else {
            window.location.search += '&update=false';
        }

    });
};

// Create Backup.
digitalocean_action_create_backup = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    let button = jQuery(pObj).closest('td');
    let lastBackup = jQuery(pObj).closest('td').prev();
    let websiteId = jQuery(pObj).attr('website_id');

    let data = mainwp_secure_data({
        action: 'digitalocean_action_create_backup',
        website_id: websiteId,
        backup_api: 'digitalocean'
    });

    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery(lastBackup).html('Requesting Backup...');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('A backup has been requested.')
			;

            setTimeout(function () {
                location.reload();
            }, 5000);
        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while requesting your backup. Please check that your ID and API Key are correct.')
			;

            setTimeout(function () {
                location.reload();
            }, 5000);
        }
    });
};

// Refresh Available Backups.
digitalocean_action_refresh_available_backups = function (pObj) {

    let websiteId = jQuery(pObj).attr('website_id');

    let data = mainwp_secure_data({
        action: 'digitalocean_action_refresh_available_backups',
        website_id: websiteId,
        backup_api: 'digitalocean'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('Available backups have been refreshed.')
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

            setInterval('location.reload()', 7000);// Using .reload() method.

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while refreshing available backups.')
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

// restore Backup.
digitalocean_action_restore_backup = function (pObj) {

    let button = jQuery(pObj);
    let lastBackup = jQuery(pObj).closest('td').prev();
    let websiteId = jQuery(pObj).attr('website_id');
    let snapshotId = jQuery(pObj).attr('snapshot_id');

    let data = mainwp_secure_data({
        action: 'digitalocean_action_restore_backup',
        website_id: websiteId,
        backup_api: 'digitalocean',
        snapshot_id: snapshotId
    });

    // Start button animation.
    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        jQuery(lastBackup).html('');

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('Backup restoration has begun. Please wait a few moments and then check the site.')
			;

            // Stop button animation.
            jQuery(button).html('<i class="undo icon"></i>');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while restoring your backup.')
			;

            // Stop button animation.
            jQuery(button).html('<i class="undo icon"></i>');
        }

    });
};

// Delete Backup.
digitalocean_action_delete_backup = function (pObj) {

    let button = jQuery(pObj);

    let snapshotId = jQuery(pObj).attr('snapshot_id');

    let data = mainwp_secure_data({
        action: 'digitalocean_action_delete_backup',
        snapshot_id: snapshotId
    });

    // Start button animation.
    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('The selected backup has been deleted.')
			;

            // Stop button animation.
            jQuery(button).html('<i class="trash icon"></i>');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while deleting the selected backup.')
			;

            // Stop button animation.
            jQuery(button).html('<i class="trash icon"></i>');

        }
    });
};

/********************************************************
 * Cpanel Functions.
 */

cpanel_action_create_wptk_backup = function (pObj) {

    let websiteId = jQuery(pObj).attr('website_id');

    let data = mainwp_secure_data({
        action: 'cpanel_action_create_wptk_backup',
        website_id: websiteId,
        backup_api: 'cpanel',
		backup: 'true'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html( 'A backup has been requested. Please allow some time for the backup to complete, then Refresh Available Backups.');

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html( response )
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

// restore Backup.

cpanel_action_restore_wptk_backup = function (pObj) {

    // Disable link.
    jQuery(pObj).attr('disabled', 'true');

    let backupName = jQuery('.mainwp_3rd_party_api_cpanel_action_restore_wptk_backup').attr('backup_name');
    let websiteId = jQuery('.mainwp_3rd_party_api_cpanel_action_restore_wptk_backup').attr('website_id');

    let data = mainwp_secure_data({
        action: 'cpanel_action_restore_wptk_backup',
        website_id: websiteId,
        backup_api: 'cpanel',
        backup_name: backupName,
    });

    // Start button animation. ( Using class so ALL buttons are animated not just the one clicked ).
    jQuery('.mainwp_3rd_party_api_cpanel_action_restore_wptk_backup').addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('The backup restoration process has finished. Please wait a few moments and then check the site.')
			;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_restore_wptk_backup').removeClass('disabled loading');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while restoring your backup.')
			;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_restore_wptk_backup').removeClass('disabled loading');

        }
    });
};

// Delete Backup.
cpanel_action_delete_wptk_backup = function (pObj) {

    // Disable link.
    jQuery(pObj).attr('disabled', 'true');

    let backupName = jQuery('.mainwp_3rd_party_api_cpanel_action_delete_wptk_backup').attr('backup_name');
    let websiteId = jQuery('.mainwp_3rd_party_api_cpanel_action_delete_wptk_backup').attr('website_id');

    let data = mainwp_secure_data({
        action: 'cpanel_action_delete_wptk_backup',
        website_id: websiteId,
        backup_api: 'cpanel',
        backup_name: backupName,
    });

    // Start button animation. ( Using class so ALL buttons are animated not just the one clicked ).
    jQuery('.mainwp_3rd_party_api_cpanel_action_delete_wptk_backup').addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('The selected backup has been deleted.')
			;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_delete_wptk_backup').removeClass('disabled loading');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while deleting your backup.')
			;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_delete_wptk_backup').removeClass('disabled loading');

        }
    });
};

// Download Backup.
cpanel_action_download_wptk_backup = function () {

    let href = jQuery('.mainwp_3rd_party_api_cpanel_action_download_wptk_backup').attr('href');
    window.open(href, '_blank');

};

cpanel_action_create_backup = function (pObj) {

    let websiteId = jQuery(pObj).attr('website_id');

    let data = mainwp_secure_data({
        action: 'cpanel_action_create_manual_backup',
        website_id: websiteId,
        backup_api: 'cpanel'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('A backup has been requested.')
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html( response )
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

// Refresh Available Backups.
cpanel_action_refresh_available_backups = function (pObj) {

    let websiteId = jQuery(pObj).attr('website_id');

    let data = mainwp_secure_data({
        action: 'cpanel_action_refresh_available_backups',
        website_id: websiteId,
        backup_api: 'cPanel'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('Available backups have been refreshed.')
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

            setInterval('location.reload()', 7000);        // Using .reload() method.

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while refreshing available backups.')
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

// restore Backup.
cPanel_action_restore_backup = function (pObj) {

    // Disable link.
    jQuery(pObj).attr('disabled', 'true');

    let websiteId = jQuery('#cpanel_automatic_backup_button').attr('website_id');
    let backupID = jQuery('#cpanel_automatic_backup_button').attr('backup_name');
    let backupPath = jQuery('#cpanel_automatic_backup_button').attr('backup_path');

    let data = mainwp_secure_data({
        action: 'cpanel_action_restore_backup',
        website_id: websiteId,
        backup_api: 'cpanel',
        backup_id: backupID,
        backup_path: backupPath
    });

    // Start button animation. ( Using class so ALL buttons are animated not just the one clicked ).
    jQuery('.mainwp_3rd_party_api_cpanel_action_restore_backup button').addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('The backup restoration process has finished. Please wait a few moments and then check the site.')
			;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_restore_backup button').removeClass('disabled loading');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while restoring your backup.')
			;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_restore_backup button').removeClass('disabled loading');

        }
    });
};

// restore Backup.
cpanel_action_restore_database_backup = function (btObj) {

    let websiteId = jQuery(btObj).attr('website_id');
    let backupID = jQuery(btObj).attr('backup_name');
    let backupPath = jQuery(btObj).attr('backup_path');

    let data = mainwp_secure_data({
        action: 'cpanel_action_restore_database_backup',
        website_id: websiteId,
        backup_api: 'cpanel',
        backup_id: backupID,
        backup_path: backupPath
    });

    // Start button animation. ( Using class so ALL buttons are animated not just the one clicked ).
    jQuery('.mainwp_3rd_party_api_cpanel_action_restore_database_backup button').addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('The backup restoration process has finished. Please wait a few moments and then check the site.')
			;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_restore_database_backup button').removeClass('disabled loading');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while restoring your backup.')
			;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_restore_database_backup button').removeClass('disabled loading');

        }
    });
};

// restore Backup.
cpanel_action_restore_manual_backup = function () {

    let websiteId = jQuery('#cpanel_automatic_backup_button').attr('website_id');
    let backupID = jQuery('#database_backup_button').attr('backup_name');
    let backupPath = jQuery('#database_backup_button').attr('backup_path');

    let data = mainwp_secure_data({
        action: 'cpanel_action_restore_manual_backup',
        website_id: websiteId,
        backup_api: 'cpanel',
        backup_id: backupID,
        backup_path: backupPath
    });

    // Start button animation. ( Using class so ALL buttons are animated not just the one clicked ).
    jQuery('.mainwp_3rd_party_api_cpanel_action_restore_manual_backup button').addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('The backup restoration process has finished. Please wait a few moments and then check the site.')
			;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_restore_manual_backup button').removeClass('disabled loading');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while restoring your backup.')
			;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_restore_database_backup button').removeClass('disabled loading');

        }
    });
};

cpanel_action_create_database_backup = function (pObj) {

    // Grab the website ID.
    let websiteId = jQuery(pObj).attr('website_id');

    // Build the data object.
    let data = mainwp_secure_data({
        action: 'cpanel_action_create_database_backup',
        website_id: websiteId,
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    // Send the data to the server.
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        // Check & return the response.
        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('The current database backup has finished. Please wait a few moments and then Refresh Available Backups.')
			;

            // Start button animation.
            jQuery(pObj).removeClass('disabled loading');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while creating your backup.')
			;

            // Start button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
}

cpanel_action_create_full_backup = function (pObj) {

    // Grab the website ID.
    let websiteId = jQuery(pObj).attr('website_id');

    // Build the data object.
    let data = mainwp_secure_data({
        action: 'cpanel_action_create_full_backup',
        website_id: websiteId,
        backup_api: 'cpanel',
		backup: 'true'
    });


    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    // Send the data to the server.
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        // Check & return the response.
        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('The current File & Database backup has finished. Please wait a few moments and then Refresh Available Backups.')
			;

            // Start button animation.
            jQuery(pObj).removeClass('disabled loading');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while creating your backups.')
			;

            // Start button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
}

/********************************************************
 * Plesk Functions.
 */

// Refresh Available Backups.
plesk_action_refresh_available_backups = function (pObj) {

    let websiteId = jQuery(pObj).attr('website_id');

    let data = mainwp_secure_data({
        action: 'plesk_action_refresh_available_backups',
        website_id: websiteId,
        backup_api: 'Plesk'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('Available backups have been refreshed.')
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

            setInterval('location.reload()', 7000);// Using .reload() method.

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while refreshing available backups.')
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

plesk_action_create_backup = function (pObj) {

    let websiteId = jQuery(pObj).attr('website_id');

    let data = mainwp_secure_data({
        action: 'plesk_action_create_backup',
        website_id: websiteId,
        backup_api: 'plesk'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('A backup has been requested.')
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html( response )
			;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

// Restore backups.
plesk_action_restore_backup = function () {

    //jQuery('.mainwp_3rd_party_api_plesk_action_restore_backup').attr('disabled', 'true');
    let button = jQuery('.mainwp_3rd_party_api_plesk_action_restore_backup').closest('td');

    let installationId = jQuery('.mainwp_3rd_party_api_plesk_action_restore_backup').attr('installation_id');
    let backupName = jQuery('.mainwp_3rd_party_api_plesk_action_restore_backup').attr('backup_name');
    let websiteId = jQuery('.mainwp_3rd_party_api_plesk_action_restore_backup').attr('website_id');

    let data = mainwp_secure_data({
        action: 'plesk_action_restore_backup',
        installation_id: installationId,
        backup_name: backupName,
        website_id: websiteId,
        backup_api: 'plesk'
    });

    // Start button animation.
    jQuery(button).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('Your application is in the process of restoring. This will take a few minuets...')
			;

            // Stop button animation.
            jQuery(button).removeClass('disabled loading');

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while attempting to restore your application...')
			;

            // Stop button animation.
            jQuery(button).removeClass('disabled loading');
        }
    });
};

// Delete backups.
plesk_action_delete_backup = function () {
    jQuery('.mainwp_3rd_party_api_plesk_action_delete_backup').attr('disabled', 'true');
    let button = jQuery('.mainwp_3rd_party_api_plesk_action_delete_backup').closest('td');

    let installationId = jQuery('.mainwp_3rd_party_api_plesk_action_delete_backup').attr('installation_id');
    let backupName = jQuery('.mainwp_3rd_party_api_plesk_action_delete_backup').attr('backup_name');
    let websiteId = jQuery('.mainwp_3rd_party_api_plesk_action_delete_backup').attr('website_id');

    let data = mainwp_secure_data({
        action: 'plesk_action_delete_backup',
        installation_id: installationId,
        backup_name: backupName,
        website_id: websiteId,
        backup_api: 'plesk'
    });

    // Start button animation.
    jQuery(button).find('i.loading').css({ 'display': 'block' });
    jQuery(button).find('div').addClass('hidden');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('green').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('The selected backup has been deleted.')
			;

			setInterval('location.reload()', 5000); // Using .reload() method.

        } else {

			// Show message.
			jQuery('#mainwp-api-backups-message-zone').addClass('red').show();
			jQuery('#mainwp-api-backups-message-zone .content .message')
				.html('There was an issue while deleting your backup.')
			;

			setInterval('location.reload()', 5000); // Using .reload() method.

        }
    });
};


