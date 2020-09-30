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
class MainWP_System_View {

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
	public static function get_mainwp_translations() {

		/**
		 * Method mainwpAddTranslation()
		 *
		 * Grab info needed to build array and strip chartacters "/[^A-Za-z0-9_]/".
		 *
		 * @param mixed $pArray Array of tranlatable text.
		 * @param mixed $pKey Key for each array enty.
		 * @param mixed $pText Text for each array entry.
		 */
		function mainwpAddTranslation( &$pArray, $pKey, $pText ) {
			if ( ! is_array( $pArray ) ) {
				$pArray = array();
			}

			$strippedText = str_replace( ' ', '_', $pKey );
			$strippedText = preg_replace( '/[^A-Za-z0-9_]/', '', $strippedText );

			$pArray[ $strippedText ] = $pText;
		}

		$mainwpTranslations = array();
		mainwpAddTranslation( $mainwpTranslations, 'Update settings.', __( 'Update settings.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Settings have been updated.', __( 'Settings have been updated.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'An error occured.', __( 'An error occured.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Testing login.', __( 'Testing login.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Your login is valid.', __( 'Your login is valid.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Your login is invalid.', __( 'Your login is invalid.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'An error occured, please contact us.', __( 'An error occured, please contact us.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'more', __( 'more', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'less', __( 'less', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'An error occured: ', __( 'An error occured: ', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'No data available. Connect your sites using the Settings submenu.', __( 'No data available. Connect your sites using the Settings submenu.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Undefined error.', __( 'Undefined error.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'PENDING', __( 'PENDING', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'UPDATING', __( 'UPDATING', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'UPGRADING', __( 'UPGRADING', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'FAILED', __( 'FAILED', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'DONE', __( 'DONE', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'SYNCING', __( 'SYNCING', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'DISCONNECTED', __( 'DISCONNECTED', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'TIMEOUT', __( 'TIMEOUT', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Ignored', __( 'Ignored', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'No ignored %1s', __( 'No ignored %1s', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'No ignored %1 conflicts', __( 'No ignored %1 conflicts', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'No ignored plugins', __( 'No ignored plugins', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'No ignored themes', __( 'No ignored themes', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Upgrading..', __( 'Upgrading..', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Update successful', __( 'Update successful', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Update failed', __( 'Update failed', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Show All', __( 'Show All', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Show', __( 'Show', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Hide All', __( 'Hide All', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Hide', __( 'Hide', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Testing ...', __( 'Testing ...', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Test Settings', __( 'Test Settings', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Received wrong response from the server.', __( 'Received wrong response from the server.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Untitled', __( 'Untitled', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Are you sure you want to remove this destination. This could make some of the backup tasks invalid.', __( 'Are you sure you want to remove this destination. This could make some of the backup tasks invalid.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Starting backup task.', __( 'Starting backup task.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Cancel', __( 'Cancel', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Backup task complete', __( 'Backup task complete', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'with errors', __( 'with errors', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Close', __( 'Close', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Creating backupfile.', __( 'Creating backupfile.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Backupfile created successfully.', __( 'Backupfile created successfully.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Download from child site completed.', __( 'Download from child site completed.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Uploading to remote destinations..', __( 'Uploading to remote destinations..', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Backup complete.', __( 'Backup complete.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Upload to %1 (%2) failed:', __( 'Upload to %1 (%2) failed:', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Upload to %1 (%2) succesful', __( 'Upload to %1 (%2) succesful', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please select websites or groups to add a backup task.', __( 'Please select websites or groups to add a backup task.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Adding the task to MainWP', __( 'Adding the task to MainWP', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please select websites or groups.', __( 'Please select websites or groups.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Are you sure you want to delete this backup task?', __( 'Are you sure you want to delete this backup task?', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Removing the task..', __( 'Removing the task..', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'The task has been removed', __( 'The task has been removed', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'An unspecified error occured', __( 'An unspecified error occured', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Connection test failed.', __( 'Connection test failed.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Error message:', __( 'Error message:', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Received HTTP-code:', __( 'Received HTTP-code:', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Connection test successful.', __( 'Connection test successful.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Invalid response from the server, please try again.', __( 'Invalid response from the server, please try again.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter csv file for upload.', __( 'Please enter csv file for upload.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter a name for the website', __( 'Please enter a name for the website', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter a valid URL for your site', __( 'Please enter a valid URL for your site', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter a username for the administrator', __( 'Please enter a username for the administrator', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Adding the site to MainWP', __( 'Adding the site to MainWP', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'No MainWP Child plugin detected, first install and activate the plugin and add your site to MainWP afterwards. Click <a href="%1" target="_blank">here</a> to install <a href="%2" target="_blank">MainWP</a> plugin (do not forget to activate it after installation).', __( 'No MainWP Child plugin detected, first install and activate the plugin and add your site to MainWP afterwards. Click <a href="%1" target="_blank">here</a> to install <a href="%2" target="_blank">MainWP</a> plugin (do not forget to activate it after installation).', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Testing the connection', __( 'Testing the connection', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Are you sure you want to delete this site?', __( 'Are you sure you want to delete this site?', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Removing and deactivating the MainWP Child plugin..', __( 'Removing and deactivating the MainWP Child plugin..', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'The site has been removed and the MainWP Child plugin has been disabled.', __( 'The site has been removed and the MainWP Child plugin has been disabled.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'The requested site has not been found', __( 'The requested site has not been found', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'The site has been removed but the MainWP Child plugin could not be disabled', __( 'The site has been removed but the MainWP Child plugin could not be disabled', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Paused import by user.', __( 'Paused import by user.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Continue', __( 'Continue', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Continue import.', __( 'Continue import.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Pause', __( 'Pause', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Finished', __( 'Finished', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter the Site name.', __( 'Please enter the Site name.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter the Site url.', __( 'Please enter the Site url.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter Admin name of the site.', __( 'Please enter Admin name of the site.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Number of sites to Import: %1 Created sites: %2 Failed: %3', __( 'Number of sites to Import: %1 Created sites: %2 Failed: %3', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'HTTP error - website does not exist', __( 'HTTP error - website does not exist', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'No selected Categories', __( 'No selected Categories', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please select websites or groups to add a user.', __( 'Please select websites or groups to add a user.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Number of Users to Import: %1 Created users: %2 Failed: %3', __( 'Number of Users to Import: %1 Created users: %2 Failed: %3', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter the username.', __( 'Please enter the username.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter the email.', __( 'Please enter the email.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter the password.', __( 'Please enter the password.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please select a valid role.', __( 'Please select a valid role.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Loading previous page..', __( 'Loading previous page..', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Loading next page..', __( 'Loading next page..', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Searching the WordPress repository..', __( 'Searching the WordPress repository..', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please select websites or groups on the right side to install files.', __( 'Please select websites or groups on the right side to install files.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Queued', __( 'Queued', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'In progress', __( 'In progress', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Preparing %1 installation.', __( 'Preparing %1 installation.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Installation successful', __( 'Installation successful', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Installation failed', __( 'Installation failed', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please select websites or groups to install files.', __( 'Please select websites or groups to install files.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Creating the backupfile on the child installation, this might take a while depending on the size. Please be patient.', __( 'Creating the backupfile on the child installation, this might take a while depending on the size. Please be patient.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Backupfile on child site created successfully.', __( 'Backupfile on child site created successfully.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Downloading the file.', __( 'Downloading the file.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Download from child completed.', __( 'Download from child completed.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please wait while we are saving your note', __( 'Please wait while we are saving your note', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Note saved.', __( 'Note saved.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'An error occured while saving your message.', __( 'An error occured while saving your message.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please search and select users for update password.', __( 'Please search and select users for update password.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'All', __( 'All', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Any', __( 'Any', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'HTTP error', __( 'HTTP error', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Error on your child WordPress', __( 'Error on your child WordPress', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'MainWP Child plugin not detected. First, install and activate the plugin and add your site to MainWP afterwards. If you continue experiencing this issue, please, contact MainWP Support.', __( 'MainWP Child plugin not detected. First, install and activate the plugin and add your site to MainWP afterwards. If you continue experiencing this issue, please, contact MainWP Support.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Remove', __( 'Remove', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please reconnect to Dropbox', __( 'Please reconnect to Dropbox', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please wait', __( 'Please wait', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Upgrading all', __( 'Upgrading all', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Upgrading %1', __( 'Upgrading %1', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Updating your plan...', __( 'Updating your plan...', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Updated your plan', __( 'Updated your plan', 'mainwp' ) );
		$mainwp_backup_before_upgrade_days = get_option( 'mainwp_backup_before_upgrade_days' );
		if ( empty( $mainwp_backup_before_upgrade_days ) || ! ctype_digit( $mainwp_backup_before_upgrade_days ) ) {
			$mainwp_backup_before_upgrade_days = 7;
		}
		mainwpAddTranslation( $mainwpTranslations, 'A full backup has not been taken in the last days for the following sites:', str_replace( '%1', '' . $mainwp_backup_before_upgrade_days, __( 'A full backup has not been taken in the last %1 days for the following sites:', 'mainwp' ) ) );
		mainwpAddTranslation( $mainwpTranslations, 'Starting required backup(s).', __( 'Starting required backup(s).', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Required backup(s) complete', __( 'Required backup(s) complete', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Continue update anyway', __( 'Continue update anyway', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Continue update', __( 'Continue update', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Pause', __( 'Pause', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Resume', __( 'Resume', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Checking if a backup is required for the selected updates...', __( 'Checking if a backup is required for the selected updates...', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Full backup required', __( 'Full backup required', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Checking backup settings', __( 'Checking backup settings', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Hide Shortcuts', __( 'Hide Shortcuts', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Show Shortcuts', __( 'Show Shortcuts', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Are you sure?', __( 'Are you sure?', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Bulk reconnect finished.', __( 'Bulk reconnect finished.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Note Saved', __( 'Note Saved', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'An error occured while saving your message', __( 'An error occured while saving your message', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Download from child site completed.', __( 'Download from child site completed.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please wait while we are saving your note', __( 'Please wait while we are saving your note', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Installation Successful', __( 'Installation Successful', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Upload to %1 (%2) successful.', __( 'Upload to %1 (%2) successful.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Upload to %1 (%2) failed:', __( 'Upload to %1 (%2) failed:', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Updating Themes', __( 'Updating Themes', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Updating Plugins', __( 'Updating Plugins', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Updating WordPress', __( 'Updating WordPress', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'updated', __( 'updated', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Updating', __( 'Updating', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter a valid name for your backup task', __( 'Please enter a valid name for your backup task', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'The backup task was added successfully', __( 'The backup task was added successfully', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Bulk test connection finished', __( 'Bulk test connection finished', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'To find out more about what your HTTP status code means please %1click here%2 to locate your number (%3)', __( 'To find out more about what your HTTP status code means please %1click here%2 to locate your number (%3)', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Refreshing the page for Step 3 "Grab API Keys" in 5 seconds... if refresh fails please %1click here%2.', __( 'Refreshing the page for Step 3 "Grab API Keys" in 5 seconds... if refresh fails please %1click here%2.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'No ignored abandoned plugins', __( 'No ignored abandoned plugins', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please upload plugins to install.', __( 'Please upload plugins to install.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please upload themes to install.', __( 'Please upload themes to install.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Did you know with the %1 you can control the settings of this plugin directly from your MainWP Dashboard?', __( 'Did you know with the %1 you can control the settings of this plugin directly from your MainWP Dashboard?', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Did you know with the %1 you can control the settings of these plugins directly from your MainWP Dashboard?', __( 'Did you know with the %1 you can control the settings of these plugins directly from your MainWP Dashboard?', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Did you know with the %1 you can control the settings of this theme directly from your MainWP Dashboard?', __( 'Did you know with the %1 you can control the settings of this theme directly from your MainWP Dashboard?', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Did you know with the %1 you can control the settings of these themes directly from your MainWP Dashboard?', __( 'Did you know with the %1 you can control the settings of these themes directly from your MainWP Dashboard?', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Would you like to use the Bulk Settings Manager with this plugin? Check out the %1Documentation%2.', __( 'Would you like to use the Bulk Settings Manager with this plugin? Check out the %1Documentation%2.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Would you like to use the Bulk Settings Manager with these plugin? Check out the %1Documentation%2.', __( 'Would you like to use the Bulk Settings Manager with these plugin? Check out the %1Documentation%2.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Would you like to use the Bulk Settings Manager with this theme? Check out the %1Documentation%2.', __( 'Would you like to use the Bulk Settings Manager with this theme? Check out the %1Documentation%2.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Would you like to use the Bulk Settings Manager with these themes? Check out the %1Documentation%2.', __( 'Would you like to use the Bulk Settings Manager with these themes? Check out the %1Documentation%2.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'is_activated_parent', __( '%1 could not be deleted. This theme is parent theme for the currently active theme.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'is_activated_theme', __( '%1 could not be deleted. This theme is active theme.', 'mainwp' ) );

		return $mainwpTranslations;
	}

	/**
	 * Check if MainWP Extensions are Activated or not.
	 *
	 * @param mixed $plugin_slug Plugin Slug.
	 * @param mixed $plugin_data Plugin Data.
	 * @param mixed $status Status of plugin activation.
	 *
	 * @return string Activation warning message.
	 */
	public static function after_extensions_plugin_row( $plugin_slug, $plugin_data, $status ) {
		$extensions = MainWP_Extensions_Handler::get_indexed_extensions_infor();
		if ( ! isset( $extensions[ $plugin_slug ] ) ) {
			return;
		}

		if ( ! isset( $extensions[ $plugin_slug ]['apiManager'] ) || ! $extensions[ $plugin_slug ]['apiManager'] ) {
			return;
		}

		if ( isset( $extensions[ $plugin_slug ]['activated_key'] ) && 'Activated' == $extensions[ $plugin_slug ]['activated_key'] ) {
			return;
		}

		$slug = basename( $plugin_slug, '.php' );

		$activate_notices = get_user_option( 'mainwp_hide_activate_notices' );
		if ( is_array( $activate_notices ) ) {
			if ( isset( $activate_notices[ $slug ] ) ) {
				return;
			}
		}
		?>
		<style type="text/css">
			tr[data-plugin="<?php echo esc_attr( $plugin_slug ); ?>"] {
				box-shadow: none;
			}
		</style>
		<tr class="plugin-update-tr active" slug="<?php echo esc_attr( $slug ); ?>"><td colspan="3" class="plugin-update colspanchange"><div class="update-message api-deactivate">
					<?php printf( __( 'You have a MainWP Extension that does not have an active API entered.  This means you will not receive updates or support.  Please visit the %1$sExtensions%2$s page and enter your API.', 'mainwp' ), '<a href="admin.php?page=Extensions">', '</a>' ); ?>
					<span class="mainwp-right"><a href="#" class="mainwp-activate-notice-dismiss" ><i class="times circle icon"></i> <?php esc_html_e( 'Dismiss', 'mainwp' ); ?></a></span>
				</div></td></tr>
		<?php
	}

	/** MainWP Version 4 update Notice. */
	public static function mainwp_4_update_notice() {
		if ( MainWP_Utility::show_mainwp_message( 'notice', 'upgrade_4' ) ) {
			?>
			<div class="ui icon message yellow" style="margin-bottom: 0; border-radius: 0;">
				<i class="exclamation circle icon"></i>
				<strong><?php echo esc_html__( 'Important Notice: ', 'mainwp' ); ?></strong>&nbsp;<?php printf( __( 'MainWP Version 4 is a major upgrade from MainWP Version 3. Please, read this&nbsp; %1$supdating FAQ%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/faq-on-upgrading-from-mainwp-version-3-to-mainwp-version-4/" target="_blank">', '</a>' ); ?>
				<i class="close icon mainwp-notice-dismiss" notice-id="upgrade_4"></i>
			</div>
			<?php
		}
	}

	/** Render Administration Notice. */
	public static function admin_notices() {

		$current_options = get_option( 'mainwp_showhide_events_notice' );
		if ( ! is_array( $current_options ) ) {
			$current_options = array();
		}

		self::render_notice_version();

		self::render_notice_config_warning();

		if ( is_multisite() && ( ! isset( $current_options['hide_multi_site_notice'] ) || empty( $current_options['hide_multi_site_notice'] ) ) ) {
			self::render_notice_multi_sites();
		}

		if ( ! isset( $current_options['trust_child'] ) || empty( $current_options['trust_child'] ) ) {
			if ( MainWP_System::is_mainwp_pages() ) {
				if ( ! MainWP_Plugins_Handler::check_auto_update_plugin( 'mainwp-child/mainwp-child.php' ) ) {
					self::render_notice_trust_update();
				}
			}
		}

		self::check_rating_notice( $current_options );
	}

	/** Render PHP Version Notice. */
	public static function render_notice_version() {
		$phpver = phpversion();
		if ( version_compare( $phpver, '5.5', '<' ) ) {
			if ( MainWP_Utility::show_mainwp_message( 'notice', 'phpver_5_5' ) ) {
				?>
				<div class="ui icon yellow message" style="margin-bottom: 0; border-radius: 0;">
					<i class="exclamation circle icon"></i>
					<?php printf( __( 'Your server is currently running PHP version %1$s. In the next few months your MainWP Dashboard will require PHP 5.6 as a minimum. Please upgrade your server to at least 5.6 but we recommend PHP 7 or newer. You can find a template email to send your host %2$shere%3$s.', 'mainwp' ), $phpver, '<a href="https://wordpress.org/about/requirements/" target="_blank">', '</a>' ); ?>
					<i class="close icon mainwp-notice-dismiss" notice-id="phpver_5_5"></i>
				</div>
				<?php
			}
		}
	}

	/** Render OpenSSL Error message. */
	public static function render_notice_config_warning() {
		if ( MainWP_Server_Information_Handler::is_openssl_config_warning() ) {
			if ( MainWP_Utility::show_mainwp_message( 'notice', 'ssl_warn' ) ) {
				if ( isset( $_GET['page'] ) && 'SettingsAdvanced' != $_GET['page'] ) {
					?>
					<div class="ui icon yellow message" style="margin-bottom: 0; border-radius: 0;">
						<i class="exclamation circle icon"></i>
						<?php printf( __( 'MainWP has detected that the <strong>OpenSSL.cnf</strong> file is not configured properly. It is required to configure this so you can start connecting your child sites. Please, %1$sclick here to configure it!%2$s', 'mainwp' ), '<a href="admin.php?page=SettingsAdvanced">', '</a>' ); ?>
						<i class="close icon mainwp-notice-dismiss" notice-id="ssl_warn"></i>
					</div>
					<?php
				}
			}
		}
	}

	/** Render WP Multisite Error Message. */
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
	 * Render MainWP Review Request.
	 *
	 * @param bool $current_options false|true Weather or not to display request.
	 */
	public static function check_rating_notice( $current_options ) {
		$display_request1 = false;
		$display_request2 = false;

		if ( isset( $current_options['request_reviews1'] ) ) {
			if ( 'forever' == $current_options['request_reviews1'] ) {
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
			if ( 'forever' == $current_options['request_reviews2'] ) {
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
			self::render_rating_notice_1();
		} elseif ( $display_request2 ) {
			self::render_rating_notice_2();
		}
	}

	/** Render MainWP Dashboard & Child Plugin auto update Alert. */
	public static function render_notice_trust_update() {
		?>
		<div class="ui icon yellow message" style="margin-bottom: 0; border-radius: 0;">
			<i class="info circle icon"></i>
			<div class="content">
				<?php esc_html_e( 'You have not set your MainWP Child plugins for auto updates, this is highly recommended!', 'mainwp' ); ?> <a id="mainwp_btn_autoupdate_and_trust" class="ui mini yellow button" href="#"><?php esc_html_e( 'Turn On', 'mainwp' ); ?></a>
			</div>
			<i class="close icon mainwp-events-notice-dismiss" notice="trust_child"></i>
		</div>
		<?php
	}

	/** Render MainWP 30 day review request. */
	public static function render_rating_notice_1() {
		?>
		<div class="ui green icon message" style="margin-bottom: 0; border-radius: 0;">
			<i class="star icon"></i>
			<div class="content">
				<div class="header">
					<?php esc_html_e( 'Hi, I noticed you have been using MainWP for over 30 days and that\'s awesome!', 'mainwp' ); ?>
				</div>
				<?php esc_html_e( 'Could you please do me a BIG favor and give it a 5-star rating on WordPress? Reviews from users like you really help the MainWP community grow.', 'mainwp' ); ?>
				<br /><br />
				<a href="https://wordpress.org/support/view/plugin-reviews/mainwp#postform" target="_blank" class="ui green mini button"><?php esc_html_e( 'Ok, you deserve!', 'mainwp' ); ?></a>
				<a href="" class="ui mini green basic button mainwp-events-notice-dismiss" notice="request_reviews1"><?php esc_html_e( 'Nope, maybe later.', 'mainwp' ); ?></a>
				<a href="" class="ui mini green basic button mainwp-events-notice-dismiss" notice="request_reviews1_forever"><?php esc_html_e( 'I already did.', 'mainwp' ); ?></a>
			</div>
			<i class="close icon mainwp-notice-dismiss" notice="request_reviews1"></i>
		</div>
		<?php
	}

	/** Render MainWP review notice after a few extensions have been installed. */
	public static function render_rating_notice_2() {
		?>
		<div class="ui green icon message" style="margin-bottom: 0; border-radius: 0;">
			<i class="star icon"></i>
			<div class="content">
				<div class="header">
					<?php esc_html_e( 'Hi, I noticed you have a few MainWP Extensions installed and that\'s awesome!', 'mainwp' ); ?>
				</div>
				<?php esc_html_e( 'Could you please do me a BIG favor and give it a 5-star rating on WordPress? Reviews from users like you really help the MainWP community to grow.', 'mainwp' ); ?>
				<br /><br />
				<a href="https://wordpress.org/support/view/plugin-reviews/mainwp#postform" target="_blank" class="ui green mini button"><?php esc_html_e( 'Ok, you deserve!', 'mainwp' ); ?></a>
				<a href="" class="ui mini green basic button mainwp-events-notice-dismiss" notice="request_reviews2"><?php esc_html_e( 'Nope, maybe later.', 'mainwp' ); ?></a>
				<a href="" class="ui mini green basic button mainwp-events-notice-dismiss" notice="request_reviews2_forever"><?php esc_html_e( 'I already did.', 'mainwp' ); ?></a>
			</div>
			<i class="close icon mainwp-notice-dismiss" notice="request_reviews2"></i>
		</div>
		<?php
	}

	/** Render Send Mail Function may have failed error. */
	public static function wp_admin_notices() {

		/**
		 * Current pagenow.
		 *
		 * @global string
		 */
		global $pagenow;

		$mail_failed = get_option( 'mainwp_notice_wp_mail_failed' );
		if ( 'yes' == $mail_failed ) {
			?>
			<div class="ui icon yellow message" style="margin-bottom: 0; border-radius: 0;">
				<i class="exclamation circle icon"></i>
				<?php echo esc_html__( 'Send mail function may failed.', 'mainwp' ); ?>
				<i class="close icon mainwp-notice-dismiss" notice-id="mail_failed"></i>
			</div>
			<?php
		}

		if ( 'plugins.php' !== $pagenow ) {
			return;
		}

		$deactivated_exts = get_transient( 'mainwp_transient_deactivated_incomtible_exts' );
		if ( $deactivated_exts && is_array( $deactivated_exts ) && count( $deactivated_exts ) > 0 ) {
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
	 * Render Admin Footer.
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
					var ran = Math.floor( Math.random() * 100 ) + 1;
					var site = window.open( "", "mainwp_hide_referrer_" + ran );
					var meta = site.document.createElement( 'meta' );
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

	/** Admin print styles. */
	public static function admin_print_styles() {
		?>
		<style>
		<?php
		if ( ! MainWP_System::is_mainwp_pages() ) {
			?>
				html.wp-toolbar{
					padding-top: 32px !important;
				}
			<?php
		} else {
			?>
				#wpbody-content > div.update-nag,
				#wpbody-content > div.updated {
					margin-left: 190px;
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
	 * MainWP Productions Site warning.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_websites_count()
	 */
	public static function mainwp_warning_notice() {

		if ( get_option( 'mainwp_installation_warning_hide_the_notice' ) == 'yes' ) {
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
		<div class="ui red icon message" style="margin-bottom: 0; border-radius: 0;">
			<i class="info circle icon"></i>
			<div class="content">
				<div class="header"><?php esc_html_e( 'This appears to be a production site', 'mainwp' ); ?></div>
					<?php esc_html_e( 'We HIGHLY recommend a NEW WordPress install for your MainWP Dashboard.', 'mainwp' ); ?> <?php printf( __( 'Using a new WordPress install will help to cut down on plugin conflicts and other issues that can be caused by trying to run your MainWP Dashboard off an active site. Most hosting companies provide free subdomains %s and we recommend creating one if you do not have a specific dedicated domain to run your MainWP Dashboard.', 'mainwp' ), '("<strong>demo.yourdomain.com</strong>")' ); ?>
				<br /><br />
				<a href="#" class="ui red mini button" id="remove-mainwp-installation-warning"><?php esc_html_e( 'I have read the warning and I want to proceed', 'mainwp' ); ?></a>
			</div>
		</div>
		<?php
	}

	/** Render Admin Header */
	public static function admin_head() {
		?>
		<script type="text/javascript">var mainwp_ajax_nonce = "<?php echo wp_create_nonce( 'mainwp_ajax' ); ?>"</script>
		<?php
	}

	/**
	 * MainWP Admin body CSS class attributes.
	 *
	 * @param mixed $class_string MainWP CSS Class attributes.
	 *
	 * @return string $class_string The CSS attributes to add to the page.
	 */
	public static function admin_body_class( $class_string ) {
		if ( MainWP_System::is_mainwp_pages() ) {
			$class_string .= ' mainwp-ui mainwp-ui-page ';
			$class_string .= ' mainwp-ui-leftmenu ';
		}
		return $class_string;
	}

	/**
	 * Method render_footer_content()
	 *
	 * Render footer content.
	 *
	 * @param mixed $websites The websites object.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 */
	public static function render_footer_content( $websites ) {

		$cntr = 0;
		if ( is_array( $websites ) ) {
			$count = count( $websites );
			for ( $i = 0; $i < $count; $i ++ ) {
				$website = $websites[ $i ];
				if ( '' == $website->sync_errors ) {
					$cntr ++;
					echo '<input type="hidden" name="dashboard_wp_ids[]" class="dashboard_wp_id" value="' . intval( $website->id ) . '" />';
				}
			}
		} elseif ( false !== $websites ) {
			while ( $website = MainWP_DB::fetch_object( $websites ) ) {
				if ( '' == $website->sync_errors ) {
					$cntr ++;
					echo '<input type="hidden" name="dashboard_wp_ids[]" class="dashboard_wp_id" value="' . intval( $website->id ) . '" />';
				}
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

		?>
		<div class="ui longer modal" id="mainwp-sync-sites-modal">
			<div class="header"><?php esc_html_e( 'Data Synchronization', 'mainwp' ); ?></div>
			<div class="ui green progress mainwp-modal-progress">
				<div class="bar"><div class="progress"></div></div>
				<div class="label"></div>
			</div>
			<div class="scrolling content mainwp-modal-content">
				<div class="ui middle aligned divided selection list" id="sync-sites-status">
					<?php
					if ( is_array( $websites ) ) {
						$count = count( $websites );
						for ( $i = 0; $i < $count; $i ++ ) {
							$nice_url    = MainWP_Utility::get_nice_url( $website->url );
							$website     = $websites[ $i ];
							$is_sync_err = ( '' != $website->sync_errors ) ? true : false;
							?>
							<div class="item <?php echo $is_sync_err ? 'disconnected-site' : ''; ?>">
								<div class="right floated content">
									<div class="sync-site-status" niceurl="<?php echo esc_html( $nice_url ); ?>" siteid="<?php echo intval( $website->id ); ?>"><i class="<?php echo $is_sync_err ? 'exclamation red icon' : 'clock outline icon'; ?>"></i></div>
								</div>
								<div class="content">
								<?php echo esc_html( $nice_url ); ?>
								<?php do_action( 'mainwp_sync_popup_content', $website ); ?>
								</div>
							</div>
							<?php
						}
					} else {
						MainWP_DB::data_seek( $websites, 0 );
						while ( $website = MainWP_DB::fetch_object( $websites ) ) {
							$nice_url    = MainWP_Utility::get_nice_url( $website->url );
							$is_sync_err = ( '' != $website->sync_errors ) ? true : false;
							?>
							<div class="item <?php echo $is_sync_err ? 'disconnected-site' : ''; ?>">
								<div class="right floated content">
									<div class="sync-site-status" niceurl="<?php echo esc_html( $nice_url ); ?>" siteid="<?php echo intval( $website->id ); ?>"><i class="<?php echo $is_sync_err ? 'exclamation red icon' : 'clock outline icon'; ?>"></i></div>
								</div>
								<div class="content">
								<?php echo esc_html( $nice_url ); ?>
								<?php do_action( 'mainwp_sync_popup_content', $website ); ?>
								</div>
							</div>
							<?php
						}
					}
					?>
				</div>
			</div>
			<div class="actions mainwp-modal-actions">
				<div class="mainwp-modal-close ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
			</div>
		</div>

		<input type="hidden" id="sync_selected_site_ids" value="" />

		<div class="ui tiny modal" id="mainwp-modal-confirm">
			<div class="header"><?php esc_html_e( 'Confirmation', 'mainwp' ); ?></div>
			<div class="content">
				<div class="content-massage"></div>
				<div class="ui mini yellow message hidden update-confirm-notice" ><?php printf( __( 'To disable update confirmations, go to the %1$sSettings%2$s page and disable the "Disable update confirmations" option', 'mainwp' ), '<a href="admin.php?page=Settings">', '</a>' ); ?></div>
			</div>
			<div class="actions">
				<div class="ui cancel button"><?php esc_html_e( 'Cancel', 'mainwp' ); ?></div>
				<div class="ui positive right labeled icon button"><?php esc_html_e( 'Yes', 'mainwp' ); ?><i class="checkmark icon"></i></div>
			</div>
		</div>
		<?php
	}


}
