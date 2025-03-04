<?php
/**
 * MainWP System View.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_System_View
 *
 * @package MainWP\Dashboard
 */
class MainWP_System_View { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Get Class Name
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method get_mainwp_translations()
     * Build Translations Array.
     *
     * @return array $mainwpTranslations.
     */
    public static function get_mainwp_translations() { // phpcs:ignore -- NOSONAR - complex.

        /**
         * Method mainwp_add_translation()
         *
         * Grab info needed to build array and strip chartacters "/[^A-Za-z0-9_]/".
         *
         * @param mixed $pArray Array of tranlatable text.
         * @param mixed $pKey Key for each array enty.
         * @param mixed $pText Text for each array entry.
         */
        function mainwp_add_translation( &$pArray, $pKey, $pText ) {
            if ( ! is_array( $pArray ) ) {
                $pArray = array();
            }

            $strippedText = str_replace( ' ', '_', $pKey );
            $strippedText = preg_replace( '/[\W]/', '', $strippedText );

            $pArray[ $strippedText ] = $pText;
        }

        $mainwpTranslations = array();
        mainwp_add_translation( $mainwpTranslations, 'Update settings.', esc_html__( 'Update settings.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Settings have been updated.', esc_html__( 'Settings have been updated.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'An error occured.', esc_html__( 'An error occured.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Testing login.', esc_html__( 'Testing login.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Your login is valid.', esc_html__( 'Your login is valid.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Your login is invalid.', esc_html__( 'Your login is invalid.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'An error occured, please contact us.', esc_html__( 'An error occured, please contact us.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'more', esc_html__( 'more', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'less', esc_html__( 'less', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'An error occured: ', esc_html__( 'An error occured: ', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'No data available. Connect your sites using the Settings submenu.', esc_html__( 'No data available. Connect your sites using the Settings submenu.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Undefined error.', esc_html__( 'Undefined error.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'PENDING', esc_html__( 'PENDING', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'UPDATING', esc_html__( 'UPDATING', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'UPGRADING', esc_html__( 'UPGRADING', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'FAILED', esc_html__( 'FAILED', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'DONE', esc_html__( 'DONE', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'SYNCING', esc_html__( 'SYNCING', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'DISCONNECTED', esc_html__( 'DISCONNECTED', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'TIMEOUT', esc_html__( 'TIMEOUT', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Ignored', esc_html__( 'Ignored', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'No ignored %1s', esc_html__( 'No ignored %1s', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'No ignored %1 conflicts', esc_html__( 'No ignored %1 conflicts', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'No ignored plugins', esc_html__( 'No ignored plugins', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'No ignored themes', esc_html__( 'No ignored themes', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Upgrading..', esc_html__( 'Upgrading..', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Update successful', esc_html__( 'Update successful', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Update failed', esc_html__( 'Update failed', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Show All', esc_html__( 'Show All', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Show', esc_html__( 'Show', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Hide All', esc_html__( 'Hide All', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Hide', esc_html__( 'Hide', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Testing ...', esc_html__( 'Testing ...', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Test Settings', esc_html__( 'Test Settings', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Received wrong response from the server.', esc_html__( 'Received wrong response from the server.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Untitled', esc_html__( 'Untitled', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Are you sure you want to remove this destination. This could make some of the backup tasks invalid.', esc_html__( 'Are you sure you want to remove this destination. This could make some of the backup tasks invalid.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Starting backup task.', esc_html__( 'Starting backup task.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Cancel', esc_html__( 'Cancel', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Backup task complete', esc_html__( 'Backup task complete', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'with errors', esc_html__( 'with errors', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Close', esc_html__( 'Close', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Creating backupfile.', esc_html__( 'Creating backupfile.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Backupfile created successfully.', esc_html__( 'Backupfile created successfully.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Download from child site completed.', esc_html__( 'Download from child site completed.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Uploading to remote destinations..', esc_html__( 'Uploading to remote destinations..', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Backup complete.', esc_html__( 'Backup complete.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Upload to %1 (%2) failed:', esc_html__( 'Upload to %1 (%2) failed:', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Upload to %1 (%2) succesful', esc_html__( 'Upload to %1 (%2) succesful', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please select websites or tags to add a backup task.', esc_html__( 'Please select websites or tags to add a backup task.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Adding the task to MainWP', esc_html__( 'Adding the task to MainWP', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please select websites or tags.', esc_html__( 'Please select websites or tags.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Are you sure you want to delete this backup task?', esc_html__( 'Are you sure you want to delete this backup task?', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Removing the task..', esc_html__( 'Removing the task..', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'The task has been removed', esc_html__( 'The task has been removed', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'An unspecified error occured', esc_html__( 'An unspecified error occured', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Connection test failed.', esc_html__( 'Connection test failed.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Error message:', esc_html__( 'Error message:', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Received HTTP-code:', esc_html__( 'Received HTTP-code:', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Connection test successful.', esc_html__( 'Connection test successful.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Invalid response from the server, please try again.', esc_html__( 'Invalid response from the server, please try again.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please enter csv file for upload.', esc_html__( 'Please enter csv file for upload.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please enter a name for the website', esc_html__( 'Please enter a name for the website', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please enter a valid URL for your site', esc_html__( 'Please enter a valid URL for your site', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please enter a username for the administrator', esc_html__( 'Please enter a username for the administrator', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Adding the site to MainWP', esc_html__( 'Adding the site to MainWP', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'No MainWP Child plugin detected, first install and activate the plugin and add your site to MainWP afterwards. Click <a href="%1" target="_blank">here</a> to install <a href="%2" target="_blank">MainWP</a> plugin (do not forget to activate it after installation).', esc_html__( 'No MainWP Child plugin detected, first install and activate the plugin and add your site to MainWP afterwards. Click <a href="%1" target="_blank">here</a> to install <a href="%2" target="_blank">MainWP</a> <i class="external alternate icon"></i> plugin (do not forget to activate it after installation).', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Testing the connection', esc_html__( 'Testing the connection', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Are you sure you want to delete this site?', esc_html__( 'Are you sure you want to delete this site?', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Removing and deactivating the MainWP Child plugin..', esc_html__( 'Removing and deactivating the MainWP Child plugin..', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'The site has been removed and the MainWP Child plugin has been disabled.', esc_html__( 'The site has been removed and the MainWP Child plugin has been disabled.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'The requested site has not been found', esc_html__( 'The requested site has not been found', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'The site has been removed but the MainWP Child plugin could not be disabled', esc_html__( 'The site has been removed but the MainWP Child plugin could not be disabled', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Paused import by user.', esc_html__( 'Paused import by user.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Continue', esc_html__( 'Continue', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Continue import.', esc_html__( 'Continue import.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Pause', esc_html__( 'Pause', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Finished', esc_html__( 'Finished', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please enter the Site name.', esc_html__( 'Please enter the Site name.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please enter the Site url.', esc_html__( 'Please enter the Site url.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please enter Admin name of the site.', esc_html__( 'Please enter Admin name of the site.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Number of sites to Import: %1 Created sites: %2 Failed: %3', esc_html__( 'Number of sites to Import: %1 Created sites: %2 Failed: %3', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'HTTP error - website does not exist', esc_html__( 'HTTP error - website does not exist', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'No selected Categories', esc_html__( 'No selected Categories', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please select websites or tags to add a user.', esc_html__( 'Please select websites or tags to add a user.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Number of Users to Import: %1 Created users: %2 Failed: %3', esc_html__( 'Number of Users to Import: %1 Created users: %2 Failed: %3', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please enter the username.', esc_html__( 'Please enter the username.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please enter the email.', esc_html__( 'Please enter the email.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please enter the password.', esc_html__( 'Please enter the password.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please select a valid role.', esc_html__( 'Please select a valid role.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Loading previous page..', esc_html__( 'Loading previous page..', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Loading next page..', esc_html__( 'Loading next page..', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Searching the WordPress repository..', esc_html__( 'Searching the WordPress repository..', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please select websites or tags on the right side to install files.', esc_html__( 'Please select websites or tags on the right side to install files.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Queued', esc_html__( 'Queued', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'In progress', esc_html__( 'In progress', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Preparing %1 installation.', esc_html__( 'Preparing %1 installation.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Installation successful', esc_html__( 'Installation successful', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Installation failed', esc_html__( 'Installation failed', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please select websites or tags to install files.', esc_html__( 'Please select websites or tags to install files.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Creating the backupfile on the child installation, this might take a while depending on the size. Please be patient.', esc_html__( 'Creating the backupfile on the child installation, this might take a while depending on the size. Please be patient.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Backupfile on child site created successfully.', esc_html__( 'Backupfile on child site created successfully.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Downloading the file.', esc_html__( 'Downloading the file.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Download from child completed.', esc_html__( 'Download from child completed.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please wait while we are saving your note', esc_html__( 'Please wait while we are saving your note', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Note saved.', esc_html__( 'Note saved.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'An error occured while saving your message.', esc_html__( 'An error occured while saving your message.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please search and select users for update password.', esc_html__( 'Please search and select users for update password.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'All', esc_html__( 'All', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Any', esc_html__( 'Any', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'HTTP error', esc_html__( 'HTTP error', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Error on your child WordPress', esc_html__( 'Error on your child WordPress', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'MainWP Child plugin not detected. First, install and activate the plugin and add your site to MainWP afterwards. If you continue experiencing this issue, Please review MainWP Knowledgebase, and if you still have issues, please let us know in the MainWP Community.', esc_html__( 'MainWP Child plugin not detected. First, install and activate the plugin and add your site to MainWP afterwards. If you continue experiencing this issue, Please review MainWP Knowledgebase, and if you still have issues, please let us know in the MainWP Community.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Remove', esc_html__( 'Remove', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please reconnect to Dropbox', esc_html__( 'Please reconnect to Dropbox', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please wait', esc_html__( 'Please wait', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Upgrading all', esc_html__( 'Upgrading all', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Upgrading %1', esc_html__( 'Upgrading %1', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Updating your plan...', esc_html__( 'Updating your plan...', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Updated your plan', esc_html__( 'Updated your plan', 'mainwp' ) );
        $mainwp_backup_before_upgrade_days = get_option( 'mainwp_backup_before_upgrade_days' );
        if ( empty( $mainwp_backup_before_upgrade_days ) || ! ctype_digit( $mainwp_backup_before_upgrade_days ) ) {
            $mainwp_backup_before_upgrade_days = 7;
        }
        mainwp_add_translation( $mainwpTranslations, 'A full backup has not been taken in the last days for the following sites:', str_replace( '%1', '' . $mainwp_backup_before_upgrade_days, esc_html__( 'A full backup has not been taken in the last %1 days for the following sites:', 'mainwp' ) ) );
        mainwp_add_translation( $mainwpTranslations, 'Starting required backup(s).', esc_html__( 'Starting required backup(s).', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Required backup(s) complete', esc_html__( 'Required backup(s) complete', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Continue update anyway', esc_html__( 'Continue update anyway', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Continue update', esc_html__( 'Continue update', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Pause', esc_html__( 'Pause', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Resume', esc_html__( 'Resume', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Checking if a backup is required for the selected updates...', esc_html__( 'Checking if a backup is required for the selected updates...', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Full backup required', esc_html__( 'Full backup required', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Checking backup settings', esc_html__( 'Checking backup settings', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Hide Shortcuts', esc_html__( 'Hide Shortcuts', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Show Shortcuts', esc_html__( 'Show Shortcuts', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Are you sure?', esc_html__( 'Are you sure?', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Bulk reconnect finished.', esc_html__( 'Bulk reconnect finished.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Note Saved', esc_html__( 'Note Saved', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'An error occured while saving your message', esc_html__( 'An error occured while saving your message', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Download from child site completed.', esc_html__( 'Download from child site completed.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please wait while we are saving your note', esc_html__( 'Please wait while we are saving your note', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Installation Successful', esc_html__( 'Installation Successful', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Upload to %1 (%2) successful.', esc_html__( 'Upload to %1 (%2) successful.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Upload to %1 (%2) failed:', esc_html__( 'Upload to %1 (%2) failed:', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Updating Themes', esc_html__( 'Updating Themes', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Updating Plugins', esc_html__( 'Updating Plugins', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Updating WordPress', esc_html__( 'Updating WordPress', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'updated', esc_html__( 'updated', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Updating', esc_html__( 'Updating', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please enter a valid name for your backup task', esc_html__( 'Please enter a valid name for your backup task', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'The backup task was added successfully', esc_html__( 'The backup task was added successfully', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Bulk test connection finished', esc_html__( 'Bulk test connection finished', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'To find out more about what your HTTP status code means please %1click here%2 to locate your number (%3)', esc_html__( 'To find out more about what your HTTP status code means please %1click here%2 to locate your number (%3)', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Refreshing the page for Step 3 "Grab API Keys" in 5 seconds... if refresh fails please %1click here%2.', esc_html__( 'Refreshing the page for Step 3 "Grab API Keys" in 5 seconds... if refresh fails please %1click here%2.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'No ignored abandoned plugins', esc_html__( 'No ignored abandoned plugins', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please upload plugins to install.', esc_html__( 'Please upload plugins to install.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Please upload themes to install.', esc_html__( 'Please upload themes to install.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Did you know with the %1 you can control the settings of this plugin directly from your MainWP Dashboard?', esc_html__( 'Did you know with the %1 you can control the settings of this plugin directly from your MainWP Dashboard?', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Did you know with the %1 you can control the settings of these plugins directly from your MainWP Dashboard?', esc_html__( 'Did you know with the %1 you can control the settings of these plugins directly from your MainWP Dashboard?', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Did you know with the %1 you can control the settings of this theme directly from your MainWP Dashboard?', esc_html__( 'Did you know with the %1 you can control the settings of this theme directly from your MainWP Dashboard?', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Did you know with the %1 you can control the settings of these themes directly from your MainWP Dashboard?', esc_html__( 'Did you know with the %1 you can control the settings of these themes directly from your MainWP Dashboard?', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Would you like to use the Bulk Settings Manager with this plugin? Check out the %1Documentation%2.', esc_html__( 'Would you like to use the Bulk Settings Manager with this plugin? Check out the %1Documentation%2.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Would you like to use the Bulk Settings Manager with these plugin? Check out the %1Documentation%2.', esc_html__( 'Would you like to use the Bulk Settings Manager with these plugin? Check out the %1Documentation%2.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Would you like to use the Bulk Settings Manager with this theme? Check out the %1Documentation%2.', esc_html__( 'Would you like to use the Bulk Settings Manager with this theme? Check out the %1Documentation%2.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Would you like to use the Bulk Settings Manager with these themes? Check out the %1Documentation%2.', esc_html__( 'Would you like to use the Bulk Settings Manager with these themes? Check out the %1Documentation%2.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'is_activated_parent', esc_html__( '%1 could not be deleted. This theme is parent theme for the currently active theme.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'is_activated_theme', esc_html__( '%1 could not be deleted. This theme is active theme.', 'mainwp' ) );
        mainwp_add_translation( $mainwpTranslations, 'Change score changed. Click to review changes.', esc_html__( 'Change score changed. Click to review changes.', 'mainwp' ) );

        return $mainwpTranslations;
    }

    /**
     * Check if MainWP Extensions are Activated or not.
     *
     * @param mixed $plugin_slug Plugin Slug.
     *
     * @return string Activation warning message.
     *
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_indexed_extensions_infor()
     */
    public static function after_extensions_plugin_row( $plugin_slug ) {
        $extensions = MainWP_Extensions_Handler::get_indexed_extensions_infor();
        if ( ! isset( $extensions[ $plugin_slug ] ) ) {
            return;
        }

        if ( ! isset( $extensions[ $plugin_slug ]['apiManager'] ) || ! $extensions[ $plugin_slug ]['apiManager'] ) {
            return;
        }

        if ( isset( $extensions[ $plugin_slug ]['activated_key'] ) && 'Activated' === $extensions[ $plugin_slug ]['activated_key'] ) {
            return;
        }

        $slug = basename( $plugin_slug, '.php' );

        $activate_notices = get_user_option( 'mainwp_hide_activate_notices' );
        if ( is_array( $activate_notices ) && isset( $activate_notices[ $slug ] ) ) {
            return;
        }
        ?>
        <style type="text/css">
            tr[data-plugin="<?php echo esc_attr( $plugin_slug ); ?>"] {
                box-shadow: none;
            }
        </style>
        <tr class="plugin-update-tr active" slug="<?php echo esc_attr( $slug ); ?>"><td colspan="3" class="plugin-update colspanchange"><div class="update-message api-deactivate">
                <?php printf( esc_html__( 'You have a MainWP Extension that does not have an active API entered.  This means you will not receive updates or support.  Please visit the %1$sExtensions%2$s page and enter your API.', 'mainwp' ), '<a href="admin.php?page=Extensions">', '</a>' ); ?>
                    <span class="mainwp-right"><a href="#" class="mainwp-activate-notice-dismiss" ><i class="times circle icon"></i> <?php esc_html_e( 'Dismiss', 'mainwp' ); ?></a></span>
                </div></td></tr>
        <?php
    }

    /**
     * MainWP Version 4 update Notice.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::show_mainwp_message()
     */
    public static function mainwp_4_update_notice() {
        if ( MainWP_Utility::show_mainwp_message( 'notice', 'upgrade_4' ) ) {
            ?>
            <div class="ui icon message yellow" style="margin-bottom: 0; border-radius: 0;">
                <i class="exclamation circle icon"></i>
                <strong><?php echo esc_html__( 'Important Notice: ', 'mainwp' ); ?></strong>&nbsp;<?php printf( esc_html__( 'MainWP Version 4 is a major upgrade from MainWP Version 3. Please, read this&nbsp; %1$supdating FAQ%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/faq-on-upgrading-from-mainwp-version-3-to-mainwp-version-4/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); // NOSONAR - noopener - open safe. ?>
                <i class="close icon mainwp-notice-dismiss" notice-id="upgrade_4"></i>
            </div>
            <?php
        }
    }

    /**
     * MainWP Version ver 5 update Notice.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::show_mainwp_message()
     */
    public static function mainwp_ver5_update_notice() {
        if ( MainWP_Utility::show_mainwp_message( 'notice', 'upgrade_version5' ) ) {
            ?>
            <div class="ui modal" id="mainwp-v5-update-notice-modal">
                <i class="close icon mainwp-notice-dismiss" notice-id="upgrade_version5"></i>
                <div class="header">
                <?php echo esc_html__( 'MainWP 5.0 Update Notice', 'mainwp' ); ?>
                </div>
                <div class="content">
                    <h3 class="ui header">
                        <i class="sync alternate icon"></i>
                        <div class="content">
                        <?php echo esc_html__( 'Hard Refresh Required', 'mainwp' ); ?>

                        </div>
                    </h3>
                    <div class="ui hidden divider"></div>
                    <div><?php echo esc_html__( 'Please perform a hard refresh of your browser to ensure optimal performance and access to all new features.', 'mainwp' ); ?></div>
                    <br/>
                    <div><?php echo esc_html__( 'This step is crucial for loading the latest updates effectively.', 'mainwp' ); ?></div>
                    <div class="ui list">
                        <div class="item"><i class="windows icon"></i> <?php echo esc_html__( 'Windows users: Press `Ctrl + F5`', 'mainwp' ); ?></div>
                        <div class="item"><i class="apple icon"></i> <?php echo esc_html__( 'Mac users: Press `Command + R`', 'mainwp' ); ?></div>
                        <div class="item"><i class="linux icon"></i> <?php echo esc_html__( 'Linux users: Press `F5`', 'mainwp' ); ?></div>
                    </div>

                    <div class="ui divider"></div>

                    <h3 class="ui header">
                        <i class="linkify icon"></i>
                        <div class="content">
                        <?php echo esc_html__( 'Extensions License Reactivation Required', 'mainwp' ); ?>

                        </div>
                    </h3>
                    <div class="ui hidden divider"></div>
                    <div><?php echo esc_html__( 'As part of our upgrade to MainWP Dashboard version 5, we\'ve deactivated extension licenses to verify their validity and ensure everything is up to date.', 'mainwp' ); ?></div>
                    <br/>
                    <div><?php echo esc_html__( 'Simply click the "Activate Extensions" button on the Extensions page to reactivate all your Pro extension licenses in one go. It\'s quick and easy!', 'mainwp' ); ?></div>

                    <div class="ui divider"></div>

                    <div><?php echo esc_html__( 'We appreciate your understanding and cooperation in keeping your MainWP ecosystem secure and efficient. Need help? Reach out to our support team', 'mainwp' ); ?></div>
                    <div class="ui hidden divider"></div>
                    <div><?php echo esc_html__( 'Thank you for using MainWP!', 'mainwp' ); ?></div>
                </div>
            </div>
            <script type="text/javascript">
            jQuery( document ).ready( function() {
                jQuery( '#mainwp-v5-update-notice-modal' ).modal({
                    onHidden: function () {
                        let notice_id = jQuery( '#mainwp-v5-update-notice-modal' ).find('.mainwp-notice-dismiss').attr('notice-id');
                        let data = {
                            action: 'mainwp_notice_status_update'
                        };
                        data['notice_id'] = notice_id;
                        jQuery.post(ajaxurl, mainwp_secure_data(data), function () { });
                    },
                }).modal('show');
            });
            </script>
            <?php
        }
    }

    /**
     * MainWP Version ver 5 update Notice.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::show_mainwp_message()
     */
    public static function mainwp_ver502_update_notice() {
        if ( MainWP_Utility::show_mainwp_message( 'notice', 'upgrade_version502' ) ) {
            ?>
            <div class="ui modal" id="mainwp-v502-update-notice-modal">
                <i class="close icon mainwp-notice-dismiss" notice-id="upgrade_version502"></i>
                <div class="header">
                <?php echo esc_html__( 'MainWP 5.0.2 Update Notice', 'mainwp' ); ?>
                </div>
                <div class="content">
                    <div><?php echo esc_html__( 'Please perform a hard refresh of your browser to ensure optimal performance and access to all new features.', 'mainwp' ); ?></div>
                    <br/>
                    <div><?php echo esc_html__( 'This step is crucial for loading the latest updates effectively.', 'mainwp' ); ?></div>
                    <div class="ui list">
                        <div class="item"><i class="windows icon"></i> <?php echo esc_html__( 'Windows users: Press `Ctrl + F5`', 'mainwp' ); ?></div>
                        <div class="item"><i class="apple icon"></i> <?php echo esc_html__( 'Mac users: Press `Command + R`', 'mainwp' ); ?></div>
                        <div class="item"><i class="linux icon"></i> <?php echo esc_html__( 'Linux users: Press `F5`', 'mainwp' ); ?></div>
                    </div>
                </div>
            </div>
            <script type="text/javascript">
            jQuery( document ).ready( function() {
                jQuery( '#mainwp-v502-update-notice-modal' ).modal({
                    onHidden: function () {
                        let notice_id = jQuery( '#mainwp-v502-update-notice-modal' ).find('.mainwp-notice-dismiss').attr('notice-id');
                        let data = {
                            action: 'mainwp_notice_status_update'
                        };
                        data['notice_id'] = notice_id;
                        jQuery.post(ajaxurl, mainwp_secure_data(data), function () { });
                    },
                }).modal('show');
            });
            </script>
            <?php
        }
    }

    /**
     * MainWP Version ver 5 update Notice.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::show_mainwp_message()
     */
    public static function mainwp_ver5_update_clear_cache_notice() {
        if ( MainWP_Utility::show_mainwp_message( 'notice', 'upgrade_ver5_clear_cache' ) ) {
            ?>
            <div class="ui message info" style="margin-bottom: 0; border-radius: 0;">
                <div><?php echo esc_html__( 'Please perform a hard refresh of your browser to ensure optimal performance and access to all new features. This step is crucial for loading the latest updates effectively.', 'mainwp' ); ?></div>
                <div class="ui list">
                    <div class="item"><?php echo esc_html__( 'Windows users: Press `Ctrl + F5`', 'mainwp' ); ?></div>
                    <div class="item"><?php echo esc_html__( 'Mac users: Press `Command + R`', 'mainwp' ); ?></div>
                    <div class="item"><?php echo esc_html__( 'Linux users: Press `F5`', 'mainwp' ); ?></div>
                </div>
                <i class="close icon mainwp-notice-dismiss" notice-id="upgrade_ver5_clear_cache"></i>
            </div>
            <?php
        }
    }


    /**
     * Render Administration Notice.
     *
     * @uses \MainWP\Dashboard\MainWP_System::is_mainwp_pages()
     * @uses \MainWP\Dashboard\MainWP_Plugins_Handler::check_auto_update_plugin()
     * @uses \MainWP\Dashboard\MainWP_Server_Information_Handler::is_openssl_config_warning()
     */
    public static function admin_notices() {

        $current_options = get_option( 'mainwp_showhide_events_notice' );
        if ( ! is_array( $current_options ) ) {
            $current_options = array();
        }

        static::render_notice_version();
        static::render_notice_php_version8();

        static::render_notice_config_warning();

        if ( is_multisite() && ( ! isset( $current_options['hide_multi_site_notice'] ) || empty( $current_options['hide_multi_site_notice'] ) ) ) {
            static::render_notice_multi_sites();
        }

        if ( ( ! isset( $current_options['trust_child'] ) || empty( $current_options['trust_child'] ) ) && MainWP_System::is_mainwp_pages() && ! MainWP_Plugins_Handler::check_auto_update_plugin( 'mainwp-child/mainwp-child.php' ) ) {
            static::render_notice_trust_update();
        }

        static::render_trours_notice();

        static::check_rating_notice( $current_options );

        static::render_wp_mail_warning();

        static::render_browser_extensions_notice();

        static::render_secure_priv_key_connection();

        static::mainwp_tmpfile_check();
    }

    /**
     * Method mainwp_tmpfile_check()
     *
     * Checks if the `tmpfile()` PHP function is disabled.
     */
    public static function mainwp_tmpfile_check() {
        if ( MainWP_Demo_Handle::is_instawp_site() ) {
            return;
        }
        if ( ! static::is_tmpfile_enable() && MainWP_Utility::show_mainwp_message( 'notice', 'tmpfile_notice' ) ) {
            ?>
            <div class="ui red message" style="margin-bottom: 0; border-radius: 0;">
                <i class="close icon mainwp-notice-dismiss" notice-id="tmpfile_notice"></i>
                <div><?php esc_html_e( 'tmpfile() function is currently disabled on your server.', 'mainwp' ); ?></div>
                <div><?php esc_html_e( 'This function is essential for creating temporary files, and its unavailability may affect certain functionalities of your MainWP Dashboard.', 'mainwp' ); ?></div>
                <div><?php esc_html_e( 'If you are unsure how to enable it, please contact your host support and have them do it for you.', 'mainwp' ); ?></div>
            </div>
            <?php
        }
    }

    /**
     * Method is_tmpfile_enable()
     *
     * Checks if the `tmpfile()` PHP function is enable.
     */
    public static function is_tmpfile_enable() {
        if ( ! function_exists( '\tmpfile' ) ) {
            return false;
        }
        $disabled_functions = ini_get( 'disable_functions' );
        return '' !== $disabled_functions && false !== stripos( $disabled_functions, 'tmpfile' ) ? false : true;
    }

    /**
     * Renders Browsers extensions notice.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::show_mainwp_message()
     */
    public static function render_browser_extensions_notice() {
        $is_demo = MainWP_Demo_Handle::is_demo_mode();
        if ( MainWP_DB::instance()->get_websites_count() > 4 && ! $is_demo && MainWP_Utility::show_mainwp_message( 'notice', 'mainwp_browser_extensions_notice' ) ) {
            ?>
            <div class="ui info message" style="margin-bottom: 0; border-radius: 0;">
                <h3><?php esc_html_e( 'Track Updates and Non-MainWP Changes from Your Browser!', 'mainwp' ); ?></h3>
                <div><?php esc_html_e( 'The MainWP Browser Extension helps you easily track available updates across all your connected Child Sites, including changes to your plugins and themes status made outside your MainWP Dashboard.', 'mainwp' ); ?></div>
                <div><?php esc_html_e( 'The extension quickly connects to your MainWP Dashboard via MainWP REST API, eliminating the need to log in to your MainWP Dashboard repeatedly to check available updates and non-MainWP changes.', 'mainwp' ); ?></div>
                <br/>
                <div>
                    <a href="https://chrome.google.com/webstore/detail/mainwp-browser-extension/kjlehednpnfgplekjminjpocdechbnge" target="_blank" class="ui green tiny button"><i class="chrome icon"></i> <?php echo esc_html__( 'Get Chrome Extension', 'mainwp' ); ?></a>
                    <a href="https://addons.mozilla.org/en-US/firefox/addon/mainwp-browser-extension/" target="_blank" class="ui green tiny button"><i class="firefox icon"></i> <?php echo esc_html__( 'Get Firefox Extension', 'mainwp' ); ?></a>
                    <a href="https://mainwp.com/mainwp-browser-extension/" target="_blank" class="ui tiny button"><?php echo esc_html__( 'Read More', 'mainwp' ); // NOSONAR - noopener - open safe. ?></a>
                </div>
                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp_browser_extensions_notice"></i>
            </div>
            <?php
        }
    }

    /**
     * Renders Browsers extensions notice.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::show_mainwp_message()
     */
    public static function render_secure_priv_key_connection() {
        if ( MainWP_DB::instance()->get_websites_count() > 0 && MainWP_Utility::show_mainwp_message( 'notice', 'mainwp_secure_priv_key_notice' ) ) {
            ?>
            <div class="ui attention message">
                <h3><?php esc_html_e( 'New Security Feature: OpenSSL Key Encryption', 'mainwp' ); ?></h3>
                <div><?php esc_html_e( 'To enhance security, we\'ve added a feature to encrypt your private keys stored in the database. This provides an extra layer of protection in the unlikely event your database is compromised.', 'mainwp' ); ?> <a href="https://mainwp.com/kb/openssl-keys-encryption/" target="_blank"><?php esc_html_e( 'Learn more here.', 'mainwp' ); ?></a></div>
                <p><button class="ui green mini button" id="increase-connection-security-btn"><?php echo esc_html__( 'Encrypt Keys Now', 'mainwp' ); ?></button></p>
                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp_secure_priv_key_notice"></i>
            </div>
            <?php
        }
    }

    /**
     * Renders wp_mail warning.
     */
    public static function render_wp_mail_warning() {
        $mail_failed = get_option( 'mainwp_notice_wp_mail_failed' );
        if ( 'yes' === $mail_failed ) {
            ?>
            <div class="ui yellow message" style="margin-bottom: 0; border-radius: 0;">
                <?php echo esc_html__( 'wp_mail() error detected! It is more than likely that your MainWP Dashboard will fail to send any email. Please check for possible plugin conflicts with your Dashboard or contact your host support to determine why the wp_mail() function fails.', 'mainwp' ); ?>
                <i class="close icon mainwp-notice-dismiss" notice-id="mail_failed"></i>
            </div>
            <?php
        }
    }

    /**
     * Renders PHP Version Notice.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::show_mainwp_message()
     */
    public static function render_notice_version() {
        $phpver = phpversion();
        if ( version_compare( $phpver, '7.0', '<' ) && MainWP_Utility::show_mainwp_message( 'notice', 'phpver_5_5' ) ) {
            ?>
            <div class="ui icon yellow message" style="margin-bottom: 0; border-radius: 0;display:block;">
                <i class="exclamation circle icon" style="float:left;"></i>
                <?php printf( esc_html__( 'Your server is currently running PHP version %1$s. In the next few months your MainWP Dashboard will require PHP 7.4 as a minimum. Please upgrade your server to at least 7.4 but we recommend PHP 8 or newer. You can find a template email to send your host %2$shere%3$s.', 'mainwp' ), esc_html( $phpver ), '<a href="https://wordpress.org/about/requirements/" target="_blank">', '</a>' ); ?>
                <i class="close icon mainwp-notice-dismiss" notice-id="phpver_5_5"></i>
            </div>
            <?php
        }
    }

    /**
     * Renders PHP 7 Version Notice.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::show_mainwp_message()
     */
    public static function render_notice_php_version8() {
        $phpver = phpversion();
        if ( version_compare( $phpver, '8.0', '<' ) ) {
            $last_hidden = (int) get_user_option( 'lasttime_hidden_phpver_8_0' );
            if ( time() > $last_hidden + 30 * DAY_IN_SECONDS || MainWP_Utility::show_mainwp_message( 'notice', 'phpver_8_0' ) ) {
                ?>
                <div class="ui icon yellow message" style="margin-bottom: 0; border-radius: 0;display:block;">
                    <i class="exclamation circle icon" style="float:left;"></i>
                    <?php esc_html_e( 'Important Notice: In the coming months, support for PHP versions earlier than 8 will be discontinued on your MainWP Dashboard. To ensure continued functionality and security, please update your PHP version to a currently supported release, such as PHP 8.3.', 'mainwp' ); ?>
                    <?php printf( esc_html__( 'You can check the list of actively maintained PHP versions %shere%s.', 'mainwp' ), '<a href="https://www.php.net/supported-versions.php" target="_blank">', '</a>' ); ?>
                    <?php esc_html_e( 'This upcoming change does not affect your child sites, only your MainWP Dashboard.', 'mainwp' ); ?>
                    <i class="close icon mainwp-notice-dismiss" notice-id="phpver_8_0"></i>
                </div>
                <?php
            }
        }
    }

    /**
     * Renders Guided Tours Notice.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::show_mainwp_message()
     */
    public static function render_trours_notice() {
        if ( 0 === (int) get_option( 'mainwp_enable_guided_tours', 0 ) && MainWP_Utility::show_mainwp_message( 'notice', 'mainwp_guided_tours_notice' ) ) {
            ?>
            <div class="ui info message" style="margin-bottom: 0; border-radius: 0;">
                <h3><?php esc_html_e( 'Would you like to turn on guided tours?', 'mainwp' ); ?> <span class="ui green mini label"><?php esc_html_e( 'RECOMMENDED', 'mainwp' ); ?></span></h3>
                <div><?php esc_html_e( 'MainWP guided tours are designed to provide information about all essential features on each MainWP Dashboard page.', 'mainwp' ); ?></div>
                <div class="ui form">
            <div class="field">
            <div class="ui hidden divider"></div>
                <div class="ui toggle checkbox">
                    <input type="checkbox" name="mainwp-select-guided-tours-option" onchange="mainwp_guidedtours_onchange(this);" id="mainwp-select-guided-tours-option" <?php echo 1 === (int) get_option( 'mainwp_enable_guided_tours', 0 ) ? 'checked="true"' : ''; ?>>
                    <label for="mainwp-select-guided-tours-option"><?php esc_html_e( 'Select to enable the MainWP Guided Tours.', 'mainwp' ); ?></label>
                    </div>
            </div>
                </div>
                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp_guided_tours_notice"></i>
            </div>
            <?php
        }
    }

    /**
     * Renders OpenSSL Error message.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::show_mainwp_message()
     */
    public static function render_notice_config_warning() {
        if ( MainWP_Server_Information_Handler::is_openssl_config_warning() && isset( $_GET['page'] ) && 'SettingsAdvanced' !== $_GET['page'] && MainWP_Utility::show_mainwp_message( 'notice', 'ssl_warn' ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ?>
            <div class="ui yellow message" style="margin-bottom: 0; border-radius: 0;">
                <button class="ui mini button yellow close icon mainwp-notice-dismiss" style="top:30%;margin-right:10px !important" notice-id="ssl_warn"><?php echo esc_html__( 'Error Fixed', 'mainwp' ); ?></button>
                <div><?php printf( esc_html__( 'MainWP has detected that the&nbsp;%1$sOpenSSL.cnf%2$s&nbsp;file is not configured properly.  It is required to configure this so you can start connecting your child sites.  Please,&nbsp;%3$sclick here to configure it!%4$s', 'mainwp' ), '<strong>', '</strong>', '<a href="admin.php?page=SettingsAdvanced">', '</a>' ); ?></div>
                <div><?php echo esc_html__( 'If your MainWP Dashboard has no issues with connecting child sites, you can dismiss this warning by clicking the Error Fixed button.', 'mainwp' ); ?></div>
                </div>
            <?php
        }
    }

    /** Renders WP Multisite Error Message. */
    public static function render_notice_multi_sites() {
        ?>
        <div class="ui icon red message" style="margin-bottom: 0; border-radius: 0;">
            <i class="exclamation circle icon"></i>
            <?php esc_html_e( 'MainWP plugin is not designed nor fully tested on WordPress Multisite installations. Various features may not work properly. We highly recommend installing it on a single site installation!', 'mainwp' ); ?>
            <i class="close icon mainwp-notice-dismiss" notice-id="multi_site"></i>
        </div>
        <?php
    }

    /**
     * Renders MainWP Review Request.
     *
     * @param bool $current_options false|true Weather or not to display request.
     *
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_extensions()
     */
    public static function check_rating_notice( $current_options ) { // phpcs:ignore -- NOSONAR - complex.
        $display_request1 = false;
        $display_request2 = false;

        if ( isset( $current_options['request_reviews1'] ) ) {
            if ( 'forever' === $current_options['request_reviews1'] ) {
                $display_request1 = false;
            } else {
                $days             = intval( $current_options['request_reviews1'] );
                $start_time       = $current_options['request_reviews1_starttime'];
                $display_request1 = ( ( time() - $start_time ) > $days * 24 * 3600 ) ? true : false;
            }
        } else {
            $current_options['request_reviews1']           = 30;
            $current_options['request_reviews1_starttime'] = time();
            update_option( 'mainwp_showhide_events_notice', $current_options );
        }

        if ( isset( $current_options['request_reviews2'] ) ) {
            if ( 'forever' === $current_options['request_reviews2'] ) {
                $display_request2 = false;
            } else {
                $days             = intval( $current_options['request_reviews2'] );
                $start_time       = $current_options['request_reviews2_starttime'];
                $display_request2 = ( ( time() - $start_time ) > $days * 24 * 3600 ) ? true : false;
            }
        } else {
            $currentExtensions = MainWP_Extensions_Handler::get_extensions();
            if ( is_array( $currentExtensions ) && count( $currentExtensions ) > 10 ) {
                $display_request2 = true;
            }
        }

        if ( $display_request1 ) {
            static::render_rating_notice_1();
        } elseif ( $display_request2 ) {
            static::render_rating_notice_2();
        }
    }

    /**
     * Renders MainWP Dashboard & Child Plugin auto update Alert.
     */
    public static function render_notice_trust_update() {
        $is_demo = MainWP_Demo_Handle::is_demo_mode();
        if ( ! $is_demo ) {
            ?>
        <div class="ui blue message" style="margin-bottom: 0; border-radius: 0;">
            <?php esc_html_e( 'Do you want MainWP Child to be updated automatically on your websites? This is highly recommended!', 'mainwp' ); ?>
            <div class="ui hidden divider"></div>
            <a id="mainwp_btn_autoupdate_and_trust" class="ui mini green button" href="#"><?php esc_html_e( 'Update MainWP Child Plugin Automatically', 'mainwp' ); ?></a>
            <i class="close icon mainwp-events-notice-dismiss" notice="trust_child"></i>
        </div>
            <?php
        }
    }

    /**
     * Renders MainWP 30 day review request.
     */
    public static function render_rating_notice_1() {
        ?>
        <div class="ui huge icon message" style="margin-bottom: 0; border-radius: 0;">
            <i class="star yellow icon"></i>
            <div>
                <p><?php esc_html_e( 'Hi, I noticed you have been using MainWP for over 30 days and that\'s awesome!', 'mainwp' ); ?></p>
                <p><?php esc_html_e( 'Could you please do me a BIG favor and give it a 5-star rating? Reviews from users like YOU really help the MainWP community to grow.', 'mainwp' ); ?></p>
                <p><?php esc_html_e( 'Thanks, Dennis.', 'mainwp' ); ?></p>
                <div class="ui green floating mini labeled icon top pointing dropdown button">
                    <i class="star icon"></i>
                    <span class="text">Rate MainWP</span>
                    <div class="menu">
                    <a href="https://wordpress.org/support/plugin/mainwp/reviews/#new-post" class="item" target="_blank">
                        <span class="text">WordPress.org</span>
                    </a>
                    <a href="https://www.trustpilot.com/review/mainwp.com" class="item" target="_blank">
                        <span class="text">Trustpilot</span>
                    </a>
                    <a href="https://www.g2.com/products/mainwp/reviews" class="item" target="_blank">
                        <span class="text">G2</span>
                    </a>
                    </div>
                </div>
                <a href="" class="ui mini button mainwp-events-notice-dismiss" notice="request_reviews1"><?php esc_html_e( 'Nope, maybe later', 'mainwp' ); ?></a>
                <a href="" class="ui mini button mainwp-events-notice-dismiss" notice="request_reviews1_forever"><?php esc_html_e( 'I already did', 'mainwp' ); ?></a>
            </div>
            <i class="close icon mainwp-events-notice-dismiss" notice="request_reviews1_forever"></i>
        </div>
        <?php
    }

    /**
     * Renders MainWP review notice after a few extensions have been installed.
     */
    public static function render_rating_notice_2() {
        ?>
        <div class="ui huge icon message" style="margin-bottom: 0; border-radius: 0;">
            <i class="star yellow icon"></i>
            <div>
                <p><?php esc_html_e( 'Hi, I noticed you have a few MainWP Extensions installed and that\'s awesome!', 'mainwp' ); ?></p>
                <p><?php esc_html_e( 'Could you please do me a BIG favor and give it a 5-star rating? Reviews from users like YOU really help the MainWP community to grow.', 'mainwp' ); ?></p>
                <p><?php esc_html_e( 'Thanks, Dennis.', 'mainwp' ); ?></p>
                <div class="ui green floating mini labeled icon top pointing dropdown button">
                    <i class="star icon"></i>
                    <span class="text">Rate MainWP</span>
                    <div class="menu">
                    <a href="https://wordpress.org/support/plugin/mainwp/reviews/#new-post" class="item" target="_blank">
                        <span class="text">WordPress.org</span>
                    </a>
                    <a href="https://www.trustpilot.com/review/mainwp.com" class="item" target="_blank">
                        <span class="text">Trustpilot</span>
                    </a>
                    <a href="https://www.g2.com/products/mainwp/reviews" class="item" target="_blank">
                        <span class="text">G2</span>
                    </a>
                    </div>
                </div>
                <a href="" class="ui mini button mainwp-events-notice-dismiss" notice="request_reviews2"><?php esc_html_e( 'Nope, maybe later', 'mainwp' ); ?></a>
                <a href="" class="ui mini button mainwp-events-notice-dismiss" notice="request_reviews2_forever"><?php esc_html_e( 'I already did', 'mainwp' ); ?></a>
            </div>
            <i class="close icon mainwp-events-notice-dismiss" notice="request_reviews2_forever"></i>
        </div>
        <?php
    }

    /** Renders Send Mail Function may have failed error. */
    public static function wp_admin_notices() {

        /**
         * Current pagenow.
         *
         * @global string
         */
        global $pagenow;

        if ( 'plugins.php' !== $pagenow ) {
            return;
        }

        $deactivated_exts = get_transient( 'mainwp_transient_deactivated_incomtible_exts' );
        if ( $deactivated_exts && is_array( $deactivated_exts ) && ! empty( $deactivated_exts ) ) {
            ?>
            <div class='notice notice-error my-dismiss-notice is-dismissible'>
                <p><?php echo esc_html__( 'MainWP Dashboard 4.0 or newer requires Extensions 4.0 or newer. MainWP will automatically deactivate older versions of MainWP Extensions in order to prevent compatibility problems.', 'mainwp' ); ?></p>
            </div>
            <?php
        }
    }

    /**
     * Method admin_footer()
     *
     * Renders admin footer.
     */
    public static function admin_footer() {
        $disabled_confirm = get_option( 'mainwp_disable_update_confirmations', 0 );
        ?>
        <input type="hidden" id="mainwp-disable-update-confirmations" value="<?php echo intval( $disabled_confirm ); ?>">

        <script type="text/javascript">
            jQuery( document ).ready(
                function ()
                {
                    jQuery( '#adminmenu #collapse-menu' ).hide();
                }
            );
        </script>
        <?php
        /**
         * Filter: mainwp_open_hide_referrer
         *
         * Filters whether the MainWP should hide referrer when going to child site.
         *
         * @since Unknown
         */
        $hide_ref = apply_filters( 'mainwp_open_hide_referrer', false );
        if ( $hide_ref ) {
            ?>
            <script type="text/javascript">
                jQuery( document ).on( 'click', 'a.mainwp-may-hide-referrer', function ( e ) {
                    e.preventDefault();
                    mainwp_open_hide_referrer( e.target.href );
                } );

                function mainwp_open_hide_referrer( url ) {
                    let ran = Math.floor( Math.random() * 100 ) + 1;
                    let site = window.open( "", "mainwp_hide_referrer_" + ran );
                    let meta = site.document.createElement( 'meta' );
                    meta.name = "referrer";
                    meta.content = "no-referrer";
                    site.document.getElementsByTagName( 'head' )[0].appendChild( meta );
                    site.document.open();
                    site.document.writeln( '<script type="text/javascript">window.location = "' + url + '";<\/script>' );
                    site.document.close();
                }
            </script>
            <?php
        }
    }

    /**
     * Admin print styles.
     *
     * @uses \MainWP\Dashboard\MainWP_System::is_mainwp_pages()
     */
    public static function admin_print_styles() {
        ?>
        <style>
            <?php
            if ( MainWP_System::is_mainwp_pages() ) {
                ?>
                    #wpbody-content > div.update-nag,
                    #wpbody-content > div.updated {
                        margin-left: 190px;
                    }
                    html.wp-toolbar{
                        padding-top: 0 !important;
                    }
                <?php
            }
            ?>
            .mainwp-checkbox:before {
                content: '<?php esc_html_e( 'YES', 'mainwp' ); ?>';
            }
            .mainwp-checkbox:after {
                content: '<?php esc_html_e( 'NO', 'mainwp' ); ?>';
            }
        </style>
        <?php
    }

    /**
     * Productions site warning.
     *
     * Renders the warning if the production site is detected.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_websites_count()
     */
    public static function mainwp_warning_notice() {
        if ( 'yes' === get_option( 'mainwp_installation_warning_hide_the_notice' ) ) {
            return;
        }
        if ( MainWP_DB::instance()->get_websites_count() > 0 ) {
            return;
        } else {
            $plugins = get_plugins();
            if ( ! is_array( $plugins ) || count( $plugins ) <= 4 ) {
                return;
            }
        }
        ?>
        <div class="ui blue message" style="margin-bottom: 0; border-radius: 0;">
            <h4><?php esc_html_e( 'This appears to be a production site', 'mainwp' ); ?></h4>
            <?php esc_html_e( 'We HIGHLY recommend a NEW WordPress install for your MainWP Dashboard.', 'mainwp' ); ?> <?php printf( esc_html__( 'Using a new WordPress install will help to cut down on plugin conflicts and other issues that can be caused by trying to run your MainWP Dashboard off an active site. Most hosting companies provide free subdomains %s and we recommend creating one if you do not have a specific dedicated domain to run your MainWP Dashboard.', 'mainwp' ), '("<strong>demo.yourdomain.com</strong>")' ); ?>
            <br /><br />
            <a href="#" class="ui green mini button" id="remove-mainwp-installation-warning"><?php esc_html_e( 'I have read the warning and I want to proceed', 'mainwp' ); ?></a>
        </div>
        <?php
    }

    /** Render Admin Header */
    public static function admin_head() {
        ?>
        <script type="text/javascript">let mainwp_ajax_nonce = "<?php echo esc_js( wp_create_nonce( 'mainwp_ajax' ) ); ?>", mainwp_js_nonce = "<?php echo esc_js( wp_create_nonce( 'mainwp_nonce' ) ); ?>";</script>
        <?php
        if ( ( MainWP_System::is_mainwp_pages() || ( isset( $_GET['page'] ) && 'mainwp-setup' === $_GET['page'] ) ) && get_option( 'mainwp_enable_guided_tours', 0 ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            static::mainwp_usetiful_tours();
        }
    }

    /**
     * Render usetiful tours.
     */
    public static function mainwp_usetiful_tours() {
        echo "
        <script>
    (function (w, d, s) {
        console.log('init usetifulScript');
        let a = d.getElementsByTagName('head')[0];
        let r = d.createElement('script');
        r.async = 1;
        r.src = s;
        r.setAttribute('id', 'usetifulScript');
        r.dataset.token = '480fa17b0507a1c60abba94bfdadd0a7';
        a.appendChild(r);
      })(window, document, 'https://www.usetiful.com/dist/usetiful.js');</script>
        ";
    }

    /**
     * MainWP Admin body CSS class attributes.
     *
     * @param mixed $class_string MainWP CSS Class attributes.
     *
     * @return string $class_string The CSS attributes to add to the page.
     *
     * @uses \MainWP\Dashboard\MainWP_System::is_mainwp_pages()
     */
    public static function admin_body_class( $class_string ) { // phpcs:ignore -- NOSONAR - complex.
        if ( MainWP_System::is_mainwp_pages() ) {
            $class_string .= ' mainwp-ui mainwp-ui-page ';
            $class_string .= ' mainwp-ui-leftmenu ';

            $selected_theme = MainWP_Settings::get_instance()->get_selected_theme();
            if ( ! empty( $selected_theme ) ) {
                $class_string .= ' mainwp-custom-theme ';
            }

            if ( ! empty( $selected_theme ) && is_string( $selected_theme ) ) {
                $class_string .= ' mainwp-' . $selected_theme . '-theme ';
            } else {
                $class_string .= ' mainwp-classic-theme ';
            }

            $siteViewMode = MainWP_Utility::get_siteview_mode();
            if ( 'grid' === $siteViewMode ) {
                $class_string .= ' mainwp-sites-grid-view ';
            } else {
                $class_string .= ' mainwp-sites-table-view ';
            }

            // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if ( isset( $_GET['page'] ) && 'managesites' === $_GET['page'] && isset( $_GET['dashboard'] ) && ! empty( $_GET['dashboard'] ) ) {
                $class_string .= ' mainwp-individual-site-overview ';
            }

            if ( isset( $_GET['page'] ) && 'ManageClients' === $_GET['page'] && isset( $_GET['client_id'] ) && ! empty( $_GET['client_id'] ) ) {
                $class_string .= ' mainwp-individual-client-overview ';
            }
            if ( isset( $_GET['page'] ) && ( 'CostTrackerSettings' === $_GET['page'] || 'ServerInformation' === $_GET['page'] || 'ServerInformationCron' === $_GET['page'] || 'ErrorLog' === $_GET['page'] || 'ActionLogs' === $_GET['page'] || 'PluginPrivacy' === $_GET['page'] || 'Settings' === $_GET['page'] || 'SettingsAdvanced' === $_GET['page'] || 'SettingsMonitors' === $_GET['page'] || 'SettingsEmail' === $_GET['page'] || 'MainWPTools' === $_GET['page'] || 'SettingsInsights' === $_GET['page'] || 'SettingsApiBackups' === $_GET['page'] ) ) {
                $class_string .= ' mainwp-individual-site-view ';
            }
            if ( isset( $_GET['page'] ) && 'CostTrackerAdd' !== $_GET['page'] && ( ( isset( $_GET['id'] ) && ! empty( $_GET['id'] ) ) || ( isset( $_GET['dashboard'] ) && ! empty( $_GET['dashboard'] ) ) || ( isset( $_GET['updateid'] ) && ! empty( $_GET['updateid'] ) ) || ( isset( $_GET['monitor_wpid'] ) && ! empty( $_GET['monitor_wpid'] ) ) || ( isset( $_GET['emailsettingsid'] ) && ! empty( $_GET['emailsettingsid'] ) ) || ( isset( $_GET['scanid'] ) && ! empty( $_GET['scanid'] ) ) ) ) {
                $class_string .= ' mainwp-individual-site-view ';
            }
            // phpcs:enable
            $class_string = apply_filters( 'mainwp_page_admin_body_class', $class_string );
        }
        return $class_string;
    }

    /**
     * Method render_footer_content()
     *
     * Render footer content.
     *
     * @param mixed $websites The websites object.
     * @param int   $current_wpid The current website id.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_nice_url()
     */
    public static function render_footer_content( $websites, $current_wpid = false ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        if ( is_array( $websites ) ) {
            $count = count( $websites );
            for ( $i = 0; $i < $count; $i++ ) {
                $website = $websites[ $i ];
                echo '<input type="hidden" name="dashboard_wp_ids[]" class="dashboard_wp_id" error-status="' . ( empty( $website->sync_errors ) ? 0 : 1 ) . '" value="' . intval( $website->id ) . '" />';
            }
        } elseif ( false !== $websites ) {
            while ( $website = MainWP_DB::fetch_object( $websites ) ) {
                echo '<input type="hidden" name="dashboard_wp_ids[]" class="dashboard_wp_id" error-status="' . ( empty( $website->sync_errors ) ? 0 : 1 ) . '" value="' . intval( $website->id ) . '" />';
            }
        }
        /**
         * Action: mainwp_admin_footer
         *
         * Fires at the bottom of MainWP content.
         *
         * @since Unknown
         */
        do_action( 'mainwp_admin_footer' );
        MainWP_UI::render_select_mainwp_themes_modal();
        MainWP_UI::render_install_extensions_promo_modal();
        ?>
        <div class="ui modal" id="mainwp-sync-sites-modal" current-wpid="<?php echo intval( $current_wpid ); ?>">
            <i class="mainwp-modal-close close icon"></i>
            <div class="header"><?php esc_html_e( 'Data Synchronization', 'mainwp' ); ?></div>
            <div class="ui green progress mainwp-modal-progress">
                <div class="bar"><div class="progress"></div></div>
                <div class="label"></div>
            </div>
            <div class="scrolling content mainwp-modal-content">
                <div class="ui middle aligned divided list" id="sync-sites-status">
                    <?php
                    if ( is_array( $websites ) ) {
                        $count = count( $websites );
                        for ( $i = 0; $i < $count; $i++ ) {
                            $site_name   = $website->name;
                            $website     = $websites[ $i ];
                            $is_sync_err = ( '' !== $website->sync_errors ) ? true : false;
                            ?>
                            <div class="item <?php echo $is_sync_err ? 'disconnected-site' : ''; ?>">
                                <div class="right floated content">
                                    <div class="sync-site-status" niceurl="<?php echo esc_html( $site_name ); ?>" siteid="<?php echo intval( $website->id ); ?>"><span data-position="left center" data-inverted="" data-tooltip="<?php echo $is_sync_err ? esc_html__( 'Site disconnected', 'mainwp' ) : esc_html__( 'Pending', 'mainwp' ); ?>"><i class="<?php echo $is_sync_err ? 'exclamation red icon' : 'clock outline icon'; ?>"></i></span></div>
                                </div>
                                <div class="content">
                                <?php echo esc_html( $site_name ); ?>
                                <?php do_action( 'mainwp_sync_popup_content', $website ); ?>
                                </div>
                            </div>
                            <?php
                        }
                    } else {
                        MainWP_DB::data_seek( $websites, 0 );
                        while ( $website = MainWP_DB::fetch_object( $websites ) ) {
                            $site_name   = $website->name;
                            $is_sync_err = ( '' !== $website->sync_errors ) ? true : false;
                            ?>
                            <div class="item <?php echo $is_sync_err ? 'disconnected-site' : ''; ?>">
                                <div class="right floated content">
                                    <div class="sync-site-status" niceurl="<?php echo esc_html( $site_name ); ?>" siteid="<?php echo intval( $website->id ); ?>"><span data-position="left center" data-inverted="" data-tooltip="<?php echo $is_sync_err ? esc_html__( 'Site disconnected', 'mainwp' ) : esc_html__( 'Pending', 'mainwp' ); ?>"><i class="<?php echo $is_sync_err ? 'exclamation red icon' : 'clock outline icon'; ?>"></i></span></div>
                                </div>
                                <div class="content">
                                <?php echo esc_html( $site_name ); ?>
                                <?php do_action( 'mainwp_sync_popup_content', $website ); ?>
                                </div>
                            </div>
                            <?php
                        }
                    }
                    ?>
                </div>
            </div>
        </div>
        <input type="hidden" id="sync_selected_site_ids" value="" />
        <div class="ui tiny modal" id="mainwp-modal-confirm-select">
        <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Confirmation', 'mainwp' ); ?></div>
            <div class="content">
                <div class="content-massage"></div>
            </div>
            <div class="actions">
            </div>
        </div>
        <?php
        static::render_comfirm_modal();
    }

    /**
     * Method render_comfirm_modal()
     *
     * Render comfirm modal box.
     */
    public static function render_comfirm_modal() {
        ?>
        <div class="ui tiny modal" id="mainwp-modal-confirm">
        <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Confirmation', 'mainwp' ); ?></div>
            <div class="scrolling content">
                <div class="content-massage"></div>
                <div class="ui mini yellow message hidden update-confirm-notice" ><?php printf( esc_html__( 'To disable update confirmations, go to the %1$sSettings%2$s page and disable the "Disable update confirmations" option', 'mainwp' ), '<a href="admin.php?page=Settings">', '</a>' ); ?></div>
                <div class="ui form hidden" id="mainwp-confirm-form" style="display:none;">
                    <div class="ui divider"></div>
                    <div class="field">
                        <label></label>
                        <input type="text" id="mainwp-confirm-input" name="mainwp-confirm-input">
                    </div>
                </div>
            </div>
            <div class="actions">
                <div class="ui two columns grid">
                    <div class="ui left aligned column">
                    </div>
                    <div class="ui right aligned column">
                    <div class="ui green positive button"><?php esc_html_e( 'Yes, proceed!', 'mainwp' ); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Method get_plugins_install_check()
     *
     * Get plugins for install checking.
     */
    public static function get_plugins_install_check() {
        $plugins = array(
            array(
                'page' => 'Extensions-Mainwp-Pro-Reports-Extension',
                'slug' => 'mainwp-child-reports/mainwp-child-reports.php',
                'name' => 'MainWP Child Reports',
            ),
            array(
                'page' => 'Extensions-Mainwp-Client-Reports-Extension',
                'slug' => 'mainwp-child-reports/mainwp-child-reports.php',
                'name' => 'MainWP Child Reports',
            ),
            array(
                'page' => 'Extensions-Mainwp-Backwpup-Extension',
                'slug' => 'backwpup/backwpup.php',
                'name' => 'BackWPup',
            ),
            array(
                'page'     => 'Extensions-Mainwp-Ithemes-Security-Extension',
                'slug'     => 'better-wp-security/better-wp-security.php',
                'slug_pro' => 'ithemes-security-pro/ithemes-security-pro.php',
                'name'     => 'iThemes Security',
            ),
            array(
                'page' => 'Extensions-Mainwp-Updraftplus-Extension',
                'slug' => 'updraftplus/updraftplus.php',
                'name' => 'UpdraftPlus',
            ),
            array(
                'page' => 'Extensions-Mainwp-Wordfence-Extension',
                'slug' => 'wordfence/wordfence.php',
                'name' => 'Wordfence',
            ),
            array(
                'page' => 'Extensions-Wordpress-Seo-Extension',
                'slug' => 'wordpress-seo/wp-seo.php',
                'name' => 'Yoast SEO',
            ),
            array(
                'page' => 'Extensions-Mainwp-Jetpack-Protect-Extension',
                'slug' => 'jetpack-protect/jetpack-protect.php',
                'name' => 'Jetpack Protect',
            ),
            array(
                'page'     => 'Extensions-Mainwp-Jetpack-Scan-Extension',
                'slug'     => 'jetpack-protect/jetpack-protect.php', // tweaked: to requires install the JP protect plugin.
                'slug_pro' => 'jetpack/jetpack.php',
                'name'     => 'Jetpack Protect',
            ),
        );
        return apply_filters( 'mainwp_plugins_install_checks', $plugins );
    }

    /**
     * Render plugins install check modal.
     * for the exntesion overview page with the missing install plugin only.
     */
    public static function render_plugins_install_check() { // phpcs:ignore -- NOSONAR - complex function.

        $plugins_to_checks = static::get_plugins_install_check();

        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $plugin_check = MainWP_Utility::get_sub_array_having( $plugins_to_checks, 'page', $page );

        if ( ! empty( $plugin_check ) ) {
            $plugin_check = current( $plugin_check );
        }

        if ( empty( $plugin_check ) ) {
            return;
        }

        // if is not overview extension page return.
        if ( isset( $_GET['tab'] ) && 'overview' !== $_GET['tab'] && 'dashboard' !== $_GET['tab'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return;
        }

        $plugin_slug = isset( $plugin_check['slug'] ) ? $plugin_check['slug'] : '';
        $slug_pro    = isset( $plugin_check['slug_pro'] ) ? $plugin_check['slug_pro'] : '';
        $plugin_name = isset( $plugin_check['name'] ) ? $plugin_check['name'] : '';

        if ( empty( $plugin_slug ) || empty( $plugin_name ) ) {
            return;
        }

        $check_slug     = 'install_check_' . sanitize_text_field( wp_unslash( dirname( $plugin_slug ) ) );
        $check_hidetime = MainWP_Utility::get_hide_notice_status( $check_slug );

        if ( $check_hidetime && time() < $check_hidetime + 30 * DAY_IN_SECONDS ) {
            return;
        }

        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );

        if ( empty( $websites ) ) {
            return;
        }

        $missing_installed = array();

        while ( $websites && $website = MainWP_DB::fetch_object( $websites ) ) {
            $site_name = $website->name;
            if ( '' !== $website->sync_errors ) {
                continue;
            }
            $not_found = true;
            if ( '' !== $website->plugins ) {
                $plugins = json_decode( $website->plugins, 1 );
                if ( is_array( $plugins ) && ! empty( $plugins ) ) {
                    foreach ( $plugins as $plugin ) {
                        if ( isset( $plugin['slug'] ) && ( $plugin_slug === $plugin['slug'] || ( '' !== $slug_pro && $slug_pro === $plugin['slug'] ) ) ) {
                            $not_found = false;
                            break; // foreach.
                        }
                    }
                }
            }

            if ( $not_found ) {
                $missing_installed[ $website->id ] = $website->name;
            }
        }

        if ( empty( $missing_installed ) ) {
            return;
        }

        ?>
        <div class="ui modal" id="mainwp-install-check-modal" noti-slug="<?php echo esc_html( $check_slug ); ?>">
        <i class="mainwp-modal-close close icon"></i>
            <div class="header"><?php esc_html_e( 'Plugin Install Check', 'mainwp' ); ?></div>
            <div class="scrolling content mainwp-modal-content">
                <div class="ui message" id="mainwp-message-zone-install" style="display:none;"></div>
                <div class="ui message blue"><?php printf( esc_html__( 'We have detected the following sites do not have the %s plugin installed. This plugin is required to be installed on your Child Sites for the Extension to work on those sites. Please select sites where you want to install it and click the Install Plugin button. Uncheck any site you don\'t want to add the plugin to or cancel to skip this step. After the installation process, resync your sites to see sites with the newly installed plugin.', 'mainwp' ), esc_html( $plugin_name ) ); ?></div>
                <div class="ui middle aligned divided list" id="sync-sites-status">
                    <?php foreach ( $missing_installed as $siteid => $site_name ) : ?>
                        <div class="item siteBulkInstall" siteid="<?php echo intval( $siteid ); ?>" status="">
                            <div class="right floated content">
                                <span class="queue" data-inverted="" data-position="left center" data-tooltip="<?php echo esc_html__( 'Queued', 'mainwp' ); ?>"><i class="clock outline icon"></i></span>
                                <span class="progress" data-inverted="" data-position="left center" data-tooltip="<?php echo esc_html__( 'Installing...', 'mainwp' ); ?>" style="display:none"><i class="notched circle loading icon"></i></span>
                                <span class="status"></span>
                            </div>
                            <div class="content">
                            <div class="ui checkbox checked">
                                <input type="checkbox" checked="" id="install-check-<?php echo intval( $siteid ); ?>" name="install_checker[]"/>
                            </div>
                            <?php echo esc_html( $site_name ); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="actions mainwp-modal-actions">
                <div class="ui two columns grid">
                    <div class="left aligned column">

                    </div>
                    <div class="ui right aligned column">
                        <input type="button" class="ui green button" id="mainwp-install-check-btn" value="<?php esc_html_e( 'Install Plugin', 'mainwp' ); ?>">
                    </div>
                </div>

            </div>
        </div>
        <script type="text/javascript">
            jQuery( document ).ready( function () {
                jQuery('#mainwp-install-check-modal').modal({
                    allowMultiple: false,
                    closable: false,
                    onHide: function () {
                        let noti_id = jQuery('#mainwp-install-check-modal').attr('noti-slug');
                        mainwp_notice_dismiss(noti_id, 1);
                        setTimeout(function () {
                            window.location.href = location.href;
                        }, 1000);
                    },
                }).modal('show');
                jQuery(document).on('click', '#mainwp-install-check-btn', function () {
                    mainwp_install_check_plugin_prepare( '<?php echo esc_js( rawurlencode( dirname( $plugin_slug ) ) ); ?>' );
                    return false;
                });
            });
        </script>
        <?php
    }
}
