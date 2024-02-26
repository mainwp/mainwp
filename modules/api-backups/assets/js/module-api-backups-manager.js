/**
 * MainWP 3rd-party API Manager JS.
 *
 * JS actions for the MainWP 3rd-party API Manager.
 *
 * @file   This files handles all js actions for the MainWP 3rd-party API Manager.
 * @author MainWP.
 * @since  4.2.7.1
 */


jQuery(document).ready(function () {

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
    jQuery('.mainwp_3rd_party_api_cloudways_action_restore_backup').on('click', function (event) {
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
    jQuery('.mainwp_3rd_party_api_vultr_action_restore_backup').on('click', function (event) {
        vultr_action_restore_backup(this, event);
    });

    // Trigger action_delete_backup.
    jQuery('.mainwp_3rd_party_api_vultr_action_delete_backup').on('click', function (event) {
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
    jQuery('.mainwp_3rd_party_api_gridpane_action_restore_backup').on('click', function (event) {
        gridpane_action_restore_backup(this, event);
    });

    // Trigger action_delete_backup.
    jQuery('.mainwp_3rd_party_api_gridpane_action_delete_backup').on('click', function (event) {
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
    jQuery('.mainwp_3rd_party_api_linode_action_restore_backup').on('click', function (event) {
        linode_action_restore_backup(this, event);
    });

    // Trigger action_delete_backup.
    jQuery('#mainwp_3rd_party_api_linode_action_cancel_backups').on('click', function (event) {
        var confirmMsg = __('Are you sure you want to Disable and Delete all existing backups from this Linode?');
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
    jQuery('.mainwp_3rd_party_api_digitalocean_action_delete_backup').on('click', function (event) {
        digitalocean_action_delete_backup(this, event);
    });

    // Trigger action_restore_backup.
    jQuery('.mainwp_3rd_party_api_digitalocean_action_restore_backup').on('click', function (event) {
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
    jQuery('.mainwp_3rd_party_api_cpanel_action_restore_backup').on('click', function (event) {
        var confirmMsg = __('Are you sure you want to Restore this backup?');
        mainwp_confirm(confirmMsg, function () {
            cPanel_action_restore_backup(this, event);
        });
    });

    // Trigger action_restore_database_backup.
    jQuery('.mainwp_3rd_party_api_cpanel_action_restore_database_backup').on('click', function (event) {
        var confirmMsg = __('Are you sure you want to Restore this backup?');
        mainwp_confirm(confirmMsg, function () {
            cpanel_action_restore_database_backup(this, event);
        });
    });

    // Trigger action_restore_manual_backup.
    jQuery('.mainwp_3rd_party_api_cpanel_action_restore_manual_backup').on('click', function (event) {
        var confirmMsg = __('Are you sure you want to Restore this backup?');
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
        var confirmMsg = __('Are you sure you want to Restore this backup?');
        mainwp_confirm(confirmMsg, function () {
            cpanel_action_restore_wptk_backup(this, event);
        });
    });

    // Trigger action_delete_wptk_backup.
    jQuery('.mainwp_3rd_party_api_cpanel_action_delete_wptk_backup').on('click', function (event) {
        var confirmMsg = __('Are you sure you want to Delete this backup?');
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
    jQuery('.mainwp_3rd_party_api_plesk_action_restore_backup').on('click', function (event) {
        var confirmMsg = __('Are you sure you want to Restore this backup?');
        mainwp_confirm(confirmMsg, function () {
            plesk_action_restore_backup(this, event);
        });
    });

    // Trigger action_delete_backup.
    jQuery('.mainwp_3rd_party_api_plesk_action_delete_backup').on('click', function (event) {
        var confirmMsg = __('Are you sure you want to Delete this backup?');
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
    var ref_this = jQuery('#mainwp_api_cpanel_backup_tabs div.active');
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
action_check_tab = function (pObj) {

    var ref_this = jQuery('#mainwp_api_cpanel_backup_tabs div.active');
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
    var button = jQuery(pObj).closest('td');
    var lastBackup = jQuery(pObj).closest('td').prev();
    var websiteId = jQuery(pObj).attr('website_id');

    var data = mainwp_secure_data({
        action: 'cloudways_action_backup',
        website_id: websiteId,
        backup_api: 'cloudways'
    });

    jQuery(button).html('<i class="notched circle loading icon"></i>');
    jQuery(lastBackup).html('Requesting Backup...');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'A backup has been requested.',
                });
            setTimeout(function () {
                location.reload();
            }, 5000);
        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while requesting your backup. Please check that your ID and API Key are correct.',
                });
            setTimeout(function () {
                location.reload();
            }, 5000);
        }
    });

};

// Assign Apps to Child Sites.
cloudways_action_update_ids = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    var button = jQuery(pObj).closest('td');

    var data = mainwp_secure_data({
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

    var websiteId = jQuery(pObj).attr('website_id');

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    // Prepare the data to send.
    // The "action" is the name of the action hook to trigger.
    // Anything else is data that we want to pass to the PHP function.
    var data = mainwp_secure_data({
        action: 'cloudways_action_refresh_available_backups',
        website_id: websiteId,
        backup_api: 'cloudways'
    });

    // Send a POST request to the ajaxurl (WordPress variable), using the data
    // we prepared above, and running the below function when we receive the
    // response. The last parameter tells jQuery to expect a JSON response

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        console.log(response); // debugging....

        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'Available backups have been refreshed.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

            setInterval('location.reload()', 7000);        // Using .reload() method.

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while refreshing available backups.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    }, 'json');
};

// Delete backups.
cloudways_action_restore_backup = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    var button = jQuery(pObj).closest('td');

    var websiteId = jQuery(pObj).attr('website_id');
    var backupDate = jQuery(pObj).attr('backup_date');

    var data = mainwp_secure_data({
        action: 'cloudways_action_restore_backup',
        website_id: websiteId,
        backup_date: backupDate,
        backup_api: 'cloudways'
    });

    // Start button animation.
    jQuery(button).find('i.loading').css({ 'display': 'block' });
    jQuery(button).find('div').addClass('hidden');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'Your application is in the process of restoring. This will take a few minuets...',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while attempting to restore your application...',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        }
    });
};

// Delete backups.
cloudways_action_delete_backup = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    var button = jQuery(pObj).closest('td');

    var websiteId = jQuery(pObj).attr('website_id');

    var data = mainwp_secure_data({
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
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'The local backups done before last restore have been deleted.',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while deleting your backups.',
                })
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
    var button = jQuery(pObj).closest('td');

    var data = mainwp_secure_data({
        action: 'vultr_action_update_ids'
    });

    // Start button animation.
    jQuery(button).find('i.loading').css({ 'display': 'block' });
    jQuery(button).find('div').addClass('hidden');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {
            jQuery('#backups_table_toast')
                .toast({
                    class: 'green',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'Backup Stats have been refreshed.',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        } else {
            jQuery('#backups_table_toast')
                .toast({
                    class: 'red',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue refreshing the Backup Stats.',
                })
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
    var button = jQuery(pObj).closest('td');
    var lastBackup = jQuery(pObj).closest('td').prev();
    var websiteId = jQuery(pObj).attr('website_id');

    var data = mainwp_secure_data({
        action: 'vultr_action_create_snapshot',
        website_id: websiteId,
        backup_api: 'vultr'
    });

    jQuery(button).html('<i class="notched circle loading icon"></i>');
    jQuery(lastBackup).html('Requesting Backup...');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'A backup has been requested.',
                })
                ;
            setTimeout(function () {
                location.reload();
            }, 5000);
        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while requesting your backup. Please check that your ID and API Key are correct.',
                })
                ;
            setTimeout(function () {
                location.reload();
            }, 5000);
        }
    });
};

// Refresh Available Backup.
vultr_action_refresh_available_backups = function (pObj) {

    var websiteId = jQuery(pObj).attr('website_id');

    var data = mainwp_secure_data({
        action: 'vultr_action_refresh_available_backups',
        website_id: websiteId,
        backup_api: 'vultr'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'Available backups have been refreshed.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

            setInterval('location.reload()', 7000);        // Using .reload() method.

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while refreshing available backups.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

// Restore Backup.
vultr_action_restore_backup = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    var button = jQuery(pObj).closest('td');

    var websiteId = jQuery(pObj).attr('website_id');
    var snapshotID = jQuery(pObj).attr('snapshot_id');

    var data = mainwp_secure_data({
        action: 'vultr_action_restore_backup',
        website_id: websiteId,
        backup_api: 'vultr',
        snapshot_id: snapshotID
    });

    // Start button animation.
    jQuery(button).find('i.loading').css({ 'display': 'block' });
    jQuery(button).find('div').addClass('hidden');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        console.log(response);
        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'Backup restoration process has begun. Please wait a few moments and then check the site.',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while restoring your backup.',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        }
    });
};

// Delete Backup.
vultr_action_delete_backup = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    var button = jQuery(pObj).closest('td');

    var websiteId = jQuery(pObj).attr('website_id');
    var snapshotID = jQuery(pObj).attr('snapshot_id');

    var data = mainwp_secure_data({
        action: 'vultr_action_delete_backup',
        website_id: websiteId,
        backup_api: 'vultr',
        snapshot_id: snapshotID
    });

    // Start button animation.
    jQuery(button).find('i.loading').css({ 'display': 'block' });
    jQuery(button).find('div').addClass('hidden');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        console.log(response);
        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'The selected backup has been deleted.',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while deleting the selected backup.',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        }
    });
};

/********************************************************
 * Gridpane Functions.
 */

// Assign Site ID's to Child Sites.
gridpane_action_update_ids = function (pObj) {
    jQuery(pObj).attr('disabled', 'true');
    var button = jQuery(pObj).closest('td');

    var data = mainwp_secure_data({
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
    var button = jQuery(pObj).closest('td');
    var lastBackup = jQuery(pObj).closest('td').prev();
    var websiteId = jQuery(pObj).attr('website_id');

    var data = mainwp_secure_data({
        action: 'gridpane_action_create_backup',
        website_id: websiteId,
        backup_api: 'gridpane'
    });

    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery(lastBackup).html('Requesting Backup...');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'A backup has been requested.',
                })
                ;
            setTimeout(function () {
                location.reload();
            }, 5000);
        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while requesting your backup. Please check that your ID and API Key are correct.',
                })
                ;
            setTimeout(function () {
                location.reload();
            }, 5000);
        }
    });
};

// Refresh Available Backup.
gridpane_action_refresh_available_backups = function (pObj) {

    var websiteId = jQuery(pObj).attr('website_id');

    var data = mainwp_secure_data({
        action: 'gridpane_action_refresh_available_backups',
        website_id: websiteId,
        backup_api: 'gridpane'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'Available backups have been refreshed.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

            setInterval('location.reload()', 7000);        // Using .reload() method.

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while refreshing available backups.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

// Restore Backup.
gridpane_action_restore_backup = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    var button = jQuery(pObj).closest('td');

    var websiteId = jQuery(pObj).attr('website_id');
    var backupType = jQuery(pObj).attr('backup_type');
    var backupName = jQuery(pObj).attr('backup_name');

    var data = mainwp_secure_data({
        action: 'gridpane_action_restore_backup',
        website_id: websiteId,
        backup_api: 'gridpane',
        backup_type: backupType,
        backup_name: backupName
    });

    // Start button animation.
    jQuery(button).find('i.loading').css({ 'display': 'block' });
    jQuery(button).find('div').addClass('hidden');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        console.log(response);
        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'Backup restoration has begun. Please wait a few moments and then check the site.',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while restoring your backup.',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        }
    });
};

// Delete Backup.
gridpane_action_delete_backup = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    var button = jQuery(pObj).closest('td');

    var websiteId = jQuery(pObj).attr('website_id');
    var backupType = jQuery(pObj).attr('backup_type');
    var backupName = jQuery(pObj).attr('backup_name');

    var data = mainwp_secure_data({
        action: 'gridpane_action_delete_backup',
        website_id: websiteId,
        backup_api: 'gridpane',
        backup_type: backupType,
        backup_name: backupName
    });

    // Start button animation.
    jQuery(button).find('i.loading').css({ 'display': 'block' });
    jQuery(button).find('div').addClass('hidden');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        console.log(response);
        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'The selected backup has been deleted.',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while deleting the selected backup.',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        }
    });
};

/********************************************************
 * Linode Functions.
 */

// Assign Site ID's to Child Sites.
linode_action_update_ids = function (pObj) {
    jQuery(pObj).attr('disabled', 'true');
    var button = jQuery(pObj).closest('td');

    var data = mainwp_secure_data({
        action: 'linode_action_update_ids'
    });

    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        //console.log( response ); // debugging....


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
    var button = jQuery(pObj).closest('td');
    var lastBackup = jQuery(pObj).closest('td').prev();
    var websiteId = jQuery(pObj).attr('website_id');

    var data = mainwp_secure_data({
        action: 'linode_action_create_backup',
        website_id: websiteId,
        backup_api: 'linode'
    });

    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery(lastBackup).html('Requesting Backup...');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'A backup has been requested.',
                })
                ;
            setTimeout(function () {
                location.reload();
            }, 5000);
        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while requesting your backup. Please check that your backups are enabled & ID\'s are correct.',
                })
                ;
            setTimeout(function () {
                location.reload();
            }, 5000);
        }
    });
};

// Refresh Available Backup.
linode_action_refresh_available_backups = function (pObj) {

    var websiteId = jQuery(pObj).attr('website_id');

    var data = mainwp_secure_data({
        action: 'linode_action_refresh_available_backups',
        website_id: websiteId,
        backup_api: 'linode'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'Available backups have been refreshed.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

            setInterval('location.reload()', 7000);        // Using .reload() method.

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while refreshing available backups.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

// restore Backup.
linode_action_restore_backup = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    var button = jQuery(pObj).closest('td');
    var backupStatus = jQuery(pObj).closest('td').prev();
    var websiteId = jQuery(pObj).attr('website_id');
    var backupId = jQuery(pObj).attr('backup_id');

    var data = mainwp_secure_data({
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

        console.log(response);


        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'The backup restoration was successful. Please check your Linode Server Status...',
                })
                ;
            jQuery(backupStatus).html('Rebooting Server...');
            setInterval('location.reload()', 7000);// Using .reload() method.

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while restoring your backup.',
                })
                ;

            // Stop button animation.
            setInterval('location.reload()', 7000); // Using .reload() method.

        }
    });
};

// Cancel Backup.
linode_action_cancel_backups = function (pObj) {

    var websiteId = jQuery(pObj).attr('website_id');

    var data = mainwp_secure_data({
        action: 'linode_action_cancel_backups',
        website_id: websiteId,
        backup_api: 'linode'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'The backups associated with this Linode have been removed.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while canceling your backup.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

// Check Linode Status.
// linode_action_linode_status = function ( pObj ) {
//     var websiteId = jQuery( pObj ).attr( 'website_id' );
//
//     var data = mainwp_secure_data({
//         action:'linode_action_linode_status',
//         website_id: '8',
//         backup_api: 'linode'
//     });
//
//     jQuery.post( ajaxurl, data, function ( response ) {
//         response = jQuery.trim( response );
//
//         console.log( response );
//
//         if ( response === 'true' ) {
//             jQuery('#backups_site_toast').addClass( 'green' )
//                 .toast({
//                     class: 'success',
//                     position: 'top right',
//                     displayTime: 9000,
//                     message: 'The Linode has been restored & the server will re-boot in 15 seconds...',
//                 })
//             ;
//
//             // Stop button animation.
//             //jQuery( pObj ).removeClass('disabled loading' );
//
//         } else {
//             jQuery('#backups_site_toast').addClass( 'red' )
//                 .toast({
//                     class: 'warning',
//                     position: 'top right',
//                     displayTime: 9000,
//                     message: 'There was an issue while restoring your backup...',
//                 })
//             ;
//
//             // Stop button animation.
//             //jQuery( pObj ).removeClass('disabled loading' );
//
//         }
//     } );
// };

/********************************************************
 * Digital Ocean Functions.
 */

// Assign Site ID's to Child Sites.
digitalocean_action_update_ids = function (pObj) {
    jQuery(pObj).attr('disabled', 'true');
    var button = jQuery(pObj).closest('td');

    var data = mainwp_secure_data({
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
    var button = jQuery(pObj).closest('td');
    var lastBackup = jQuery(pObj).closest('td').prev();
    var websiteId = jQuery(pObj).attr('website_id');

    var data = mainwp_secure_data({
        action: 'digitalocean_action_create_backup',
        website_id: websiteId,
        backup_api: 'digitalocean'
    });

    console.log(data);

    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery(lastBackup).html('Requesting Backup...');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {

            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'A backup has been requested.',
                })
                ;
            setTimeout(function () {
                location.reload();
            }, 5000);
        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while requesting your backup. Please check that your ID and API Key are correct.',
                })
                ;
            setTimeout(function () {
                location.reload();
            }, 5000);
        }
    });
};

// Refresh Available Backups.
digitalocean_action_refresh_available_backups = function (pObj) {

    var websiteId = jQuery(pObj).attr('website_id');

    var data = mainwp_secure_data({
        action: 'digitalocean_action_refresh_available_backups',
        website_id: websiteId,
        backup_api: 'digitalocean'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'Available backups have been refreshed.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

            setInterval('location.reload()', 7000);// Using .reload() method.

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while refreshing available backups.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

// restore Backup.
digitalocean_action_restore_backup = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    var button = jQuery(pObj).closest('td');
    var lastBackup = jQuery(pObj).closest('td').prev();
    var websiteId = jQuery(pObj).attr('website_id');
    var snapshotId = jQuery(pObj).attr('snapshot_id');

    var data = mainwp_secure_data({
        action: 'digitalocean_action_restore_backup',
        website_id: websiteId,
        backup_api: 'digitalocean',
        snapshot_id: snapshotId
    });

    jQuery(button).html('<i class="notched circle loading icon"></i>');

    jQuery(lastBackup).html('Requesting Restore...');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        jQuery(lastBackup).html('');

        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'Backup restoration has begun. Please wait a few moments and then check the site.',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while restoring your backup.',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');
        }


    });
};

// Delete Backup.
digitalocean_action_delete_backup = function (pObj) {

    jQuery(pObj).attr('disabled', 'true');
    var button = jQuery(pObj).closest('td');

    var snapshotId = jQuery(pObj).attr('snapshot_id');

    var data = mainwp_secure_data({
        action: 'digitalocean_action_delete_backup',
        snapshot_id: snapshotId
    });

    // Start button animation.
    jQuery(button).find('i.loading').css({ 'display': 'block' });
    jQuery(button).find('div').addClass('hidden');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        console.log(response);
        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'The selected backup has been deleted.',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while deleting the selected backup.',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        }
    });
};

/********************************************************
 * Cpanel Functions.
 */

cpanel_action_create_wptk_backup = function (pObj) {

    var websiteId = jQuery(pObj).attr('website_id');

    var data = mainwp_secure_data({
        action: 'cpanel_action_create_wptk_backup',
        website_id: websiteId,
        backup_api: 'cpanel'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 9000,
                    message: 'A backup has been requested. Please allow some time for the backup to complete, then Refresh Available Backups.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        } else {
            jQueryjQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'error',
                    position: 'top right',
                    showIcon: 'exclamation circle',
                    displayTime: 9000,
                    message: response,
                })
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

    var backupName = jQuery('.mainwp_3rd_party_api_cpanel_action_restore_wptk_backup').attr('backup_name');
    var websiteId = jQuery('.mainwp_3rd_party_api_cpanel_action_restore_wptk_backup').attr('website_id');

    var data = mainwp_secure_data({
        action: 'cpanel_action_restore_wptk_backup',
        website_id: websiteId,
        backup_api: 'cpanel',
        backup_name: backupName,
    });

    // Start button animation. ( Using class so ALL buttons are animated not just the one clicked ).
    jQuery('.mainwp_3rd_party_api_cpanel_action_restore_wptk_backup').addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        console.log(response);
        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'The backup restoration process has finished. Please wait a few moments and then check the site.',
                })
                ;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_restore_wptk_backup').removeClass('disabled loading');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while restoring your backup.',
                })
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

    var backupName = jQuery('.mainwp_3rd_party_api_cpanel_action_delete_wptk_backup').attr('backup_name');
    var websiteId = jQuery('.mainwp_3rd_party_api_cpanel_action_delete_wptk_backup').attr('website_id');

    var data = mainwp_secure_data({
        action: 'cpanel_action_delete_wptk_backup',
        website_id: websiteId,
        backup_api: 'cpanel',
        backup_name: backupName,
    });

    // Start button animation. ( Using class so ALL buttons are animated not just the one clicked ).
    jQuery('.mainwp_3rd_party_api_cpanel_action_delete_wptk_backup').addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        console.log(response);
        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'The selected backup has been deleted.',
                })
                ;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_delete_wptk_backup').removeClass('disabled loading');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while deleting your backup.',
                })
                ;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_delete_wptk_backup').removeClass('disabled loading');

        }
    });
};

// Download Backup.
cpanel_action_download_wptk_backup = function (pObj) {

    var href = jQuery('.mainwp_3rd_party_api_cpanel_action_download_wptk_backup').attr('href');
    window.open(href, '_blank');

};

cpanel_action_create_backup = function (pObj) {

    var websiteId = jQuery(pObj).attr('website_id');

    var data = mainwp_secure_data({
        action: 'cpanel_action_create_manual_backup',
        website_id: websiteId,
        backup_api: 'cpanel'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        console.log(response);
        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 9000,
                    message: 'A backup has been requested.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        } else {
            jQuery.toast({
                class: 'error',
                position: 'top right',
                showIcon: 'exclamation circle',
                displayTime: 9000,
                message: response,
            })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

// Refresh Available Backups.
cpanel_action_refresh_available_backups = function (pObj) {

    var websiteId = jQuery(pObj).attr('website_id');

    var data = mainwp_secure_data({
        action: 'cpanel_action_refresh_available_backups',
        website_id: websiteId,
        backup_api: 'cPanel'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'Available backups have been refreshed.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

            setInterval('location.reload()', 7000);        // Using .reload() method.

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while refreshing available backups.',
                })
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

    var websiteId = jQuery('#cpanel_automatic_backup_button').attr('website_id');
    var backupID = jQuery('#cpanel_automatic_backup_button').attr('backup_name');
    var backupPath = jQuery('#cpanel_automatic_backup_button').attr('backup_path');

    var data = mainwp_secure_data({
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
        console.log(response);
        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'The backup restoration process has finished. Please wait a few moments and then check the site.',
                })
                ;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_restore_backup button').removeClass('disabled loading');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while restoring your backup.',
                })
                ;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_restore_backup button').removeClass('disabled loading');

        }
    });
};

// restore Backup.
cpanel_action_restore_database_backup = function (pObj) {

    var websiteId = jQuery('#cpanel_automatic_backup_button').attr('website_id');
    var backupID = jQuery('#database_backup_button').attr('backup_name');
    var backupPath = jQuery('#database_backup_button').attr('backup_path');

    var data = mainwp_secure_data({
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
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'The backup restoration process has finished. Please wait a few moments and then check the site.',
                })
                ;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_restore_database_backup button').removeClass('disabled loading');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while restoring your backup.',
                })
                ;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_restore_database_backup button').removeClass('disabled loading');

        }
    });
};

// restore Backup.
cpanel_action_restore_manual_backup = function (pObj) {

    var websiteId = jQuery('#cpanel_automatic_backup_button').attr('website_id');
    var backupID = jQuery('#database_backup_button').attr('backup_name');
    var backupPath = jQuery('#database_backup_button').attr('backup_path');

    var data = mainwp_secure_data({
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
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'The backup restoration process has finished. Please wait a few moments and then check the site.',
                })
                ;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_restore_manual_backup button').removeClass('disabled loading');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while restoring your backup.',
                })
                ;

            // Stop button animation. ( Using class so ALL buttons are animated not just the one clicked ).
            jQuery('.mainwp_3rd_party_api_cpanel_action_restore_database_backup button').removeClass('disabled loading');

        }
    });
};

cpanel_action_create_database_backup = function (pObj) {

    // Grab the website ID.
    var websiteId = jQuery(pObj).attr('website_id');

    // Build the data object.
    var data = mainwp_secure_data({
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
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'The current database backup has finished. Please wait a few moments and then Refresh Available Backups.',
                })
                ;

            // Start button animation.
            jQuery(pObj).removeClass('disabled loading');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while creating your backup.',
                })
                ;

            // Start button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
}

cpanel_action_create_full_backup = function (pObj) {

    // Grab the website ID.
    var websiteId = jQuery(pObj).attr('website_id');

    // Build the data object.
    var data = mainwp_secure_data({
        action: 'cpanel_action_create_full_backup',
        website_id: websiteId,
        backup_api: 'cpanel'
    });
    console.log(data);

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    // Send the data to the server.
    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        // Check & return the response.
        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'The current File & Database backup has finished. Please wait a few moments and then Refresh Available Backups.',
                })
                ;

            // Start button animation.
            jQuery(pObj).removeClass('disabled loading');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while creating your backups.',
                })
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

    var websiteId = jQuery(pObj).attr('website_id');

    var data = mainwp_secure_data({
        action: 'plesk_action_refresh_available_backups',
        website_id: websiteId,
        backup_api: 'Plesk'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);

        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'Available backups have been refreshed.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

            setInterval('location.reload()', 7000);// Using .reload() method.

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while refreshing available backups.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

plesk_action_create_backup = function (pObj) {

    var websiteId = jQuery(pObj).attr('website_id');

    var data = mainwp_secure_data({
        action: 'plesk_action_create_backup',
        website_id: websiteId,
        backup_api: 'plesk'
    });

    // Start button animation.
    jQuery(pObj).addClass('disabled loading');

    jQuery.post(ajaxurl, data, function (response) {
        response = jQuery.trim(response);
        console.log(response);
        if (response === 'true') {
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 9000,
                    message: 'A backup has been requested.',
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'error',
                    position: 'top right',
                    showIcon: 'exclamation circle',
                    displayTime: 9000,
                    message: response,
                })
                ;

            // Stop button animation.
            jQuery(pObj).removeClass('disabled loading');

        }
    });
};

// Restore backups.
plesk_action_restore_backup = function (pObj) {

    //jQuery('.mainwp_3rd_party_api_plesk_action_restore_backup').attr('disabled', 'true');
    var button = jQuery('.mainwp_3rd_party_api_plesk_action_restore_backup').closest('td');

    var installationId = jQuery('.mainwp_3rd_party_api_plesk_action_restore_backup').attr('installation_id');
    var backupName = jQuery('.mainwp_3rd_party_api_plesk_action_restore_backup').attr('backup_name');
    var websiteId = jQuery('.mainwp_3rd_party_api_plesk_action_restore_backup').attr('website_id');

    var data = mainwp_secure_data({
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
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'Your application is in the process of restoring. This will take a few minuets...',
                })
                ;

           // Stop button animation.
			jQuery(button).removeClass('disabled loading');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while attempting to restore your application...',
                })
                ;

           // Stop button animation.
            jQuery(button).removeClass('disabled loading');
        }
    });
};

// Delete backups.
plesk_action_delete_backup = function (pObj) {

    jQuery('.mainwp_3rd_party_api_plesk_action_delete_backup').attr('disabled', 'true');
    var button = jQuery('.mainwp_3rd_party_api_plesk_action_delete_backup').closest('td');

    var installationId = jQuery('.mainwp_3rd_party_api_plesk_action_delete_backup').attr('installation_id');
    var backupName = jQuery('.mainwp_3rd_party_api_plesk_action_delete_backup').attr('backup_name');
    var websiteId = jQuery('.mainwp_3rd_party_api_plesk_action_delete_backup').attr('website_id');

    var data = mainwp_secure_data({
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
            jQuery('#backups_site_toast').addClass('green')
                .toast({
                    class: 'success',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'The selected backup has been deleted.',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        } else {
            jQuery('#backups_site_toast').addClass('red')
                .toast({
                    class: 'warning',
                    position: 'top right',
                    displayTime: 5000,
                    message: 'There was an issue while deleting your backup.',
                })
                ;

            // Stop button animation.
            jQuery(button).find('i.loading').css({ 'display': 'none' });
            jQuery(button).find('div').removeClass('hidden');

        }
    });
};


