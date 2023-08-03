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
		mainwpAddTranslation( $mainwpTranslations, 'Update settings.', esc_html__( 'Update settings.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Settings have been updated.', esc_html__( 'Settings have been updated.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'An error occured.', esc_html__( 'An error occured.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Testing login.', esc_html__( 'Testing login.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Your login is valid.', esc_html__( 'Your login is valid.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Your login is invalid.', esc_html__( 'Your login is invalid.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'An error occured, please contact us.', esc_html__( 'An error occured, please contact us.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'more', esc_html__( 'more', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'less', esc_html__( 'less', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'An error occured: ', esc_html__( 'An error occured: ', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'No data available. Connect your sites using the Settings submenu.', esc_html__( 'No data available. Connect your sites using the Settings submenu.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Undefined error.', esc_html__( 'Undefined error.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'PENDING', esc_html__( 'PENDING', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'UPDATING', esc_html__( 'UPDATING', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'UPGRADING', esc_html__( 'UPGRADING', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'FAILED', esc_html__( 'FAILED', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'DONE', esc_html__( 'DONE', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'SYNCING', esc_html__( 'SYNCING', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'DISCONNECTED', esc_html__( 'DISCONNECTED', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'TIMEOUT', esc_html__( 'TIMEOUT', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Ignored', esc_html__( 'Ignored', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'No ignored %1s', esc_html__( 'No ignored %1s', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'No ignored %1 conflicts', esc_html__( 'No ignored %1 conflicts', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'No ignored plugins', esc_html__( 'No ignored plugins', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'No ignored themes', esc_html__( 'No ignored themes', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Upgrading..', esc_html__( 'Upgrading..', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Update successful', esc_html__( 'Update successful', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Update failed', esc_html__( 'Update failed', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Show All', esc_html__( 'Show All', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Show', esc_html__( 'Show', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Hide All', esc_html__( 'Hide All', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Hide', esc_html__( 'Hide', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Testing ...', esc_html__( 'Testing ...', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Test Settings', esc_html__( 'Test Settings', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Received wrong response from the server.', esc_html__( 'Received wrong response from the server.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Untitled', esc_html__( 'Untitled', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Are you sure you want to remove this destination. This could make some of the backup tasks invalid.', esc_html__( 'Are you sure you want to remove this destination. This could make some of the backup tasks invalid.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Starting backup task.', esc_html__( 'Starting backup task.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Cancel', esc_html__( 'Cancel', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Backup task complete', esc_html__( 'Backup task complete', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'with errors', esc_html__( 'with errors', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Close', esc_html__( 'Close', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Creating backupfile.', esc_html__( 'Creating backupfile.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Backupfile created successfully.', esc_html__( 'Backupfile created successfully.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Download from child site completed.', esc_html__( 'Download from child site completed.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Uploading to remote destinations..', esc_html__( 'Uploading to remote destinations..', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Backup complete.', esc_html__( 'Backup complete.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Upload to %1 (%2) failed:', esc_html__( 'Upload to %1 (%2) failed:', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Upload to %1 (%2) succesful', esc_html__( 'Upload to %1 (%2) succesful', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please select websites or tags to add a backup task.', esc_html__( 'Please select websites or tags to add a backup task.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Adding the task to MainWP', esc_html__( 'Adding the task to MainWP', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please select websites or tags.', esc_html__( 'Please select websites or tags.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Are you sure you want to delete this backup task?', esc_html__( 'Are you sure you want to delete this backup task?', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Removing the task..', esc_html__( 'Removing the task..', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'The task has been removed', esc_html__( 'The task has been removed', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'An unspecified error occured', esc_html__( 'An unspecified error occured', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Connection test failed.', esc_html__( 'Connection test failed.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Error message:', esc_html__( 'Error message:', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Received HTTP-code:', esc_html__( 'Received HTTP-code:', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Connection test successful.', esc_html__( 'Connection test successful.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Invalid response from the server, please try again.', esc_html__( 'Invalid response from the server, please try again.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter csv file for upload.', esc_html__( 'Please enter csv file for upload.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter a name for the website', esc_html__( 'Please enter a name for the website', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter a valid URL for your site', esc_html__( 'Please enter a valid URL for your site', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter a username for the administrator', esc_html__( 'Please enter a username for the administrator', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Adding the site to MainWP', esc_html__( 'Adding the site to MainWP', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'No MainWP Child plugin detected, first install and activate the plugin and add your site to MainWP afterwards. Click <a href="%1" target="_blank">here</a> to install <a href="%2" target="_blank">MainWP</a> plugin (do not forget to activate it after installation).', esc_html__( 'No MainWP Child plugin detected, first install and activate the plugin and add your site to MainWP afterwards. Click <a href="%1" target="_blank">here</a> to install <a href="%2" target="_blank">MainWP</a> plugin (do not forget to activate it after installation).', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Testing the connection', esc_html__( 'Testing the connection', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Are you sure you want to delete this site?', esc_html__( 'Are you sure you want to delete this site?', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Removing and deactivating the MainWP Child plugin..', esc_html__( 'Removing and deactivating the MainWP Child plugin..', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'The site has been removed and the MainWP Child plugin has been disabled.', esc_html__( 'The site has been removed and the MainWP Child plugin has been disabled.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'The requested site has not been found', esc_html__( 'The requested site has not been found', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'The site has been removed but the MainWP Child plugin could not be disabled', esc_html__( 'The site has been removed but the MainWP Child plugin could not be disabled', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Paused import by user.', esc_html__( 'Paused import by user.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Continue', esc_html__( 'Continue', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Continue import.', esc_html__( 'Continue import.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Pause', esc_html__( 'Pause', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Finished', esc_html__( 'Finished', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter the Site name.', esc_html__( 'Please enter the Site name.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter the Site url.', esc_html__( 'Please enter the Site url.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter Admin name of the site.', esc_html__( 'Please enter Admin name of the site.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Number of sites to Import: %1 Created sites: %2 Failed: %3', esc_html__( 'Number of sites to Import: %1 Created sites: %2 Failed: %3', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'HTTP error - website does not exist', esc_html__( 'HTTP error - website does not exist', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'No selected Categories', esc_html__( 'No selected Categories', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please select websites or tags to add a user.', esc_html__( 'Please select websites or tags to add a user.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Number of Users to Import: %1 Created users: %2 Failed: %3', esc_html__( 'Number of Users to Import: %1 Created users: %2 Failed: %3', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter the username.', esc_html__( 'Please enter the username.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter the email.', esc_html__( 'Please enter the email.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter the password.', esc_html__( 'Please enter the password.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please select a valid role.', esc_html__( 'Please select a valid role.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Loading previous page..', esc_html__( 'Loading previous page..', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Loading next page..', esc_html__( 'Loading next page..', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Searching the WordPress repository..', esc_html__( 'Searching the WordPress repository..', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please select websites or tags on the right side to install files.', esc_html__( 'Please select websites or tags on the right side to install files.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Queued', esc_html__( 'Queued', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'In progress', esc_html__( 'In progress', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Preparing %1 installation.', esc_html__( 'Preparing %1 installation.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Installation successful', esc_html__( 'Installation successful', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Installation failed', esc_html__( 'Installation failed', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please select websites or tags to install files.', esc_html__( 'Please select websites or tags to install files.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Creating the backupfile on the child installation, this might take a while depending on the size. Please be patient.', esc_html__( 'Creating the backupfile on the child installation, this might take a while depending on the size. Please be patient.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Backupfile on child site created successfully.', esc_html__( 'Backupfile on child site created successfully.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Downloading the file.', esc_html__( 'Downloading the file.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Download from child completed.', esc_html__( 'Download from child completed.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please wait while we are saving your note', esc_html__( 'Please wait while we are saving your note', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Note saved.', esc_html__( 'Note saved.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'An error occured while saving your message.', esc_html__( 'An error occured while saving your message.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please search and select users for update password.', esc_html__( 'Please search and select users for update password.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'All', esc_html__( 'All', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Any', esc_html__( 'Any', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'HTTP error', esc_html__( 'HTTP error', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Error on your child WordPress', esc_html__( 'Error on your child WordPress', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'MainWP Child plugin not detected. First, install and activate the plugin and add your site to MainWP afterwards. If you continue experiencing this issue, Please review MainWP Knowledgebase, and if you still have issues, please let us know in the MainWP Community.', esc_html__( 'MainWP Child plugin not detected. First, install and activate the plugin and add your site to MainWP afterwards. If you continue experiencing this issue, Please review MainWP Knowledgebase, and if you still have issues, please let us know in the MainWP Community.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Remove', esc_html__( 'Remove', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please reconnect to Dropbox', esc_html__( 'Please reconnect to Dropbox', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please wait', esc_html__( 'Please wait', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Upgrading all', esc_html__( 'Upgrading all', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Upgrading %1', esc_html__( 'Upgrading %1', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Updating your plan...', esc_html__( 'Updating your plan...', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Updated your plan', esc_html__( 'Updated your plan', 'mainwp' ) );
		$mainwp_backup_before_upgrade_days = get_option( 'mainwp_backup_before_upgrade_days' );
		if ( empty( $mainwp_backup_before_upgrade_days ) || ! ctype_digit( $mainwp_backup_before_upgrade_days ) ) {
			$mainwp_backup_before_upgrade_days = 7;
		}
		mainwpAddTranslation( $mainwpTranslations, 'A full backup has not been taken in the last days for the following sites:', str_replace( '%1', '' . $mainwp_backup_before_upgrade_days, esc_html__( 'A full backup has not been taken in the last %1 days for the following sites:', 'mainwp' ) ) );
		mainwpAddTranslation( $mainwpTranslations, 'Starting required backup(s).', esc_html__( 'Starting required backup(s).', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Required backup(s) complete', esc_html__( 'Required backup(s) complete', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Continue update anyway', esc_html__( 'Continue update anyway', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Continue update', esc_html__( 'Continue update', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Pause', esc_html__( 'Pause', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Resume', esc_html__( 'Resume', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Checking if a backup is required for the selected updates...', esc_html__( 'Checking if a backup is required for the selected updates...', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Full backup required', esc_html__( 'Full backup required', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Checking backup settings', esc_html__( 'Checking backup settings', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Hide Shortcuts', esc_html__( 'Hide Shortcuts', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Show Shortcuts', esc_html__( 'Show Shortcuts', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Are you sure?', esc_html__( 'Are you sure?', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Bulk reconnect finished.', esc_html__( 'Bulk reconnect finished.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Note Saved', esc_html__( 'Note Saved', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'An error occured while saving your message', esc_html__( 'An error occured while saving your message', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Download from child site completed.', esc_html__( 'Download from child site completed.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please wait while we are saving your note', esc_html__( 'Please wait while we are saving your note', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Installation Successful', esc_html__( 'Installation Successful', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Upload to %1 (%2) successful.', esc_html__( 'Upload to %1 (%2) successful.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Upload to %1 (%2) failed:', esc_html__( 'Upload to %1 (%2) failed:', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Updating Themes', esc_html__( 'Updating Themes', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Updating Plugins', esc_html__( 'Updating Plugins', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Updating WordPress', esc_html__( 'Updating WordPress', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'updated', esc_html__( 'updated', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Updating', esc_html__( 'Updating', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please enter a valid name for your backup task', esc_html__( 'Please enter a valid name for your backup task', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'The backup task was added successfully', esc_html__( 'The backup task was added successfully', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Bulk test connection finished', esc_html__( 'Bulk test connection finished', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'To find out more about what your HTTP status code means please %1click here%2 to locate your number (%3)', esc_html__( 'To find out more about what your HTTP status code means please %1click here%2 to locate your number (%3)', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Refreshing the page for Step 3 "Grab API Keys" in 5 seconds... if refresh fails please %1click here%2.', esc_html__( 'Refreshing the page for Step 3 "Grab API Keys" in 5 seconds... if refresh fails please %1click here%2.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'No ignored abandoned plugins', esc_html__( 'No ignored abandoned plugins', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please upload plugins to install.', esc_html__( 'Please upload plugins to install.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Please upload themes to install.', esc_html__( 'Please upload themes to install.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Did you know with the %1 you can control the settings of this plugin directly from your MainWP Dashboard?', esc_html__( 'Did you know with the %1 you can control the settings of this plugin directly from your MainWP Dashboard?', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Did you know with the %1 you can control the settings of these plugins directly from your MainWP Dashboard?', esc_html__( 'Did you know with the %1 you can control the settings of these plugins directly from your MainWP Dashboard?', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Did you know with the %1 you can control the settings of this theme directly from your MainWP Dashboard?', esc_html__( 'Did you know with the %1 you can control the settings of this theme directly from your MainWP Dashboard?', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Did you know with the %1 you can control the settings of these themes directly from your MainWP Dashboard?', esc_html__( 'Did you know with the %1 you can control the settings of these themes directly from your MainWP Dashboard?', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Would you like to use the Bulk Settings Manager with this plugin? Check out the %1Documentation%2.', esc_html__( 'Would you like to use the Bulk Settings Manager with this plugin? Check out the %1Documentation%2.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Would you like to use the Bulk Settings Manager with these plugin? Check out the %1Documentation%2.', esc_html__( 'Would you like to use the Bulk Settings Manager with these plugin? Check out the %1Documentation%2.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Would you like to use the Bulk Settings Manager with this theme? Check out the %1Documentation%2.', esc_html__( 'Would you like to use the Bulk Settings Manager with this theme? Check out the %1Documentation%2.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'Would you like to use the Bulk Settings Manager with these themes? Check out the %1Documentation%2.', esc_html__( 'Would you like to use the Bulk Settings Manager with these themes? Check out the %1Documentation%2.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'is_activated_parent', esc_html__( '%1 could not be deleted. This theme is parent theme for the currently active theme.', 'mainwp' ) );
		mainwpAddTranslation( $mainwpTranslations, 'is_activated_theme', esc_html__( '%1 could not be deleted. This theme is active theme.', 'mainwp' ) );

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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_indexed_extensions_infor()
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
				<strong><?php echo esc_html__( 'Important Notice: ', 'mainwp' ); ?></strong>&nbsp;<?php printf( esc_html__( 'MainWP Version 4 is a major upgrade from MainWP Version 3. Please, read this&nbsp; %1$supdating FAQ%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/faq-on-upgrading-from-mainwp-version-3-to-mainwp-version-4/" target="_blank">', '</a>' ); ?>
				<i class="close icon mainwp-notice-dismiss" notice-id="upgrade_4"></i>
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

		self::render_trours_notice();

		self::check_rating_notice( $current_options );

		self::render_wp_mail_warning();
	}

	/**
	 * Renders wp_mail warning.
	 */
	public static function render_wp_mail_warning() {
		$mail_failed = get_option( 'mainwp_notice_wp_mail_failed' );
		if ( 'yes' == $mail_failed ) {
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
		if ( version_compare( $phpver, '5.5', '<' ) ) {
			if ( MainWP_Utility::show_mainwp_message( 'notice', 'phpver_5_5' ) ) {
				?>
				<div class="ui icon yellow message" style="margin-bottom: 0; border-radius: 0;">
					<i class="exclamation circle icon"></i>
					<?php printf( esc_html__( 'Your server is currently running PHP version %1$s. In the next few months your MainWP Dashboard will require PHP 5.6 as a minimum. Please upgrade your server to at least 5.6 but we recommend PHP 7 or newer. You can find a template email to send your host %2$shere%3$s.', 'mainwp' ), esc_html( $phpver ), '<a href="https://wordpress.org/about/requirements/" target="_blank">', '</a>' ); ?>
					<i class="close icon mainwp-notice-dismiss" notice-id="phpver_5_5"></i>
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
		if ( 0 == get_option( 'mainwp_enable_guided_tours', 0 ) ) {
			if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp_guided_tours_notice' ) ) {
				?>
				<div class="ui info message" style="margin-bottom: 0; border-radius: 0;">
					<h3><?php esc_html_e( 'Would you like to turn on guided tours?', 'mainwp' ); ?> <span class="ui green mini label"><?php esc_html_e( 'RECOMMENDED', 'mainwp' ); ?></span></h3>
					<div><?php esc_html_e( 'MainWP guided tours are designed to provide information about all essential features on each MainWP Dashboard page.', 'mainwp' ); ?></div>
					<div class="ui form">
				<div class="field">
				<div class="ui hidden divider"></div>
					<div class="ui toggle checkbox">
						<input type="checkbox" name="mainwp-select-guided-tours-option" onchange="mainwp_guidedtours_onchange(this);" id="mainwp-select-guided-tours-option" <?php echo ( ( 1 == get_option( 'mainwp_enable_guided_tours', 0 ) ) ? 'checked="true"' : '' ); ?>>
						<label for="mainwp-select-guided-tours-option"><?php esc_html_e( 'Select to enable the MainWP Guided Tours.', 'mainwp' ); ?></label>
						</div>
				</div>
					</div>
					<i class="close icon mainwp-notice-dismiss" notice-id="mainwp_guided_tours_notice"></i>
				</div>
				<?php
			}
		}
	}

	/**
	 * Renders OpenSSL Error message.
	 *
	 * @uses  \MainWP\Dashboard\MainWP_Utility::show_mainwp_message()
	 */
	public static function render_notice_config_warning() {
		if ( MainWP_Server_Information_Handler::is_openssl_config_warning() ) {
			if ( isset( $_GET['page'] ) && 'SettingsAdvanced' != $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
				if ( MainWP_Utility::show_mainwp_message( 'notice', 'ssl_warn' ) ) {
					?>
					<div class="ui yellow message" style="margin-bottom: 0; border-radius: 0;">
						<button class="ui mini button yellow close icon mainwp-notice-dismiss" style="top:30%;margin-right:10px !important" notice-id="ssl_warn"><?php echo esc_html__( 'Error Fixed', 'mainwp' ); ?></button>
						<div><?php echo sprintf( esc_html__( 'MainWP has detected that the&nbsp;%1$sOpenSSL.cnf%2$s&nbsp;file is not configured properly.  It is required to configure this so you can start connecting your child sites.  Please,&nbsp;%3$sclick here to configure it!%4$s', 'mainwp' ), '<strong>', '</strong>', '<a href="admin.php?page=SettingsAdvanced">', '</a>' ); ?></div>
						<div><?php echo esc_html__( 'If your MainWP Dashboard has no issues with connecting child sites, you can dismiss this warning by clicking the Error Fixed button.', 'mainwp' ); ?></div>
				</div>
					<?php
				}
			}
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

	/** Renders MainWP Dashboard & Child Plugin auto update Alert. */
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

	/** Renders MainWP 30 day review request. */
	public static function render_rating_notice_1() {
		?>
		<div class="ui green icon message" style="margin-bottom: 0; border-radius: 0;">
			<i class="star icon"></i>
			<div class="content">
				<p><?php esc_html_e( 'Hi, I noticed you have been using MainWP for over 30 days and that\'s awesome!', 'mainwp' ); ?></p>
				<p><?php esc_html_e( 'Could you please do me a BIG favor and give it a 5-star rating? Reviews from users like YOU really help the MainWP community to grow.', 'mainwp' ); ?></p>
				<div class="ui green floating mini labeled icon dropdown button">
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
				<a href="" class="ui mini green basic button mainwp-events-notice-dismiss" notice="request_reviews1"><?php esc_html_e( 'Nope, maybe later.', 'mainwp' ); ?></a>
				<a href="" class="ui mini green basic button mainwp-events-notice-dismiss" notice="request_reviews1_forever"><?php esc_html_e( 'I already did.', 'mainwp' ); ?></a>
			</div>
			<i class="close icon mainwp-events-notice-dismiss" notice="request_reviews1_forever"></i>
		</div>
		<?php
	}

	/** Renders MainWP review notice after a few extensions have been installed. */
	public static function render_rating_notice_2() {
		?>
		<div class="ui green icon message" style="margin-bottom: 0; border-radius: 0;">
			<i class="star icon"></i>
			<div class="content">
				<p><?php esc_html_e( 'Hi, I noticed you have a few MainWP Extensions installed and that\'s awesome!', 'mainwp' ); ?></p>
				<p><?php esc_html_e( 'Could you please do me a BIG favor and give it a 5-star rating? Reviews from users like YOU really help the MainWP community to grow.', 'mainwp' ); ?></p>
				<div class="ui green floating mini labeled icon dropdown button">
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
				<a href="" class="ui mini green basic button mainwp-events-notice-dismiss" notice="request_reviews2"><?php esc_html_e( 'Nope, maybe later.', 'mainwp' ); ?></a>
				<a href="" class="ui mini green basic button mainwp-events-notice-dismiss" notice="request_reviews2_forever"><?php esc_html_e( 'I already did.', 'mainwp' ); ?></a>
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
		<div class="ui red message" style="margin-bottom: 0; border-radius: 0;">
			<h4><?php esc_html_e( 'This appears to be a production site', 'mainwp' ); ?></h4>
					<?php esc_html_e( 'We HIGHLY recommend a NEW WordPress install for your MainWP Dashboard.', 'mainwp' ); ?> <?php printf( esc_html__( 'Using a new WordPress install will help to cut down on plugin conflicts and other issues that can be caused by trying to run your MainWP Dashboard off an active site. Most hosting companies provide free subdomains %s and we recommend creating one if you do not have a specific dedicated domain to run your MainWP Dashboard.', 'mainwp' ), '("<strong>demo.yourdomain.com</strong>")' ); ?>
				<br /><br />
				<a href="#" class="ui red mini button" id="remove-mainwp-installation-warning"><?php esc_html_e( 'I have read the warning and I want to proceed', 'mainwp' ); ?></a>
		</div>
		<?php
	}

	/** Render Admin Header */
	public static function admin_head() {
		?>
		<script type="text/javascript">var mainwp_ajax_nonce = "<?php echo esc_js( wp_create_nonce( 'mainwp_ajax' ) ); ?>", mainwp_js_nonce = "<?php echo esc_js( wp_create_nonce( 'mainwp_nonce' ) ); ?>";</script>
		<?php
		if ( MainWP_System::is_mainwp_pages() || ( isset( $_GET['page'] ) && 'mainwp-setup' == $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( get_option( 'mainwp_enable_guided_tours', 0 ) ) {
				self::mainwp_usetiful_tours();
			}
		}
	}

	/**
	 * Render usetiful tours.
	 */
	public static function mainwp_usetiful_tours() {
		echo "
		<script>
	(function (w, d, s) {
		var a = d.getElementsByTagName('head')[0];
		var r = d.createElement('script');
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
	public static function admin_body_class( $class_string ) {
		if ( MainWP_System::is_mainwp_pages() ) {
			$class_string .= ' mainwp-ui mainwp-ui-page ';
			$class_string .= ' mainwp-ui-leftmenu ';

			$selected_theme = MainWP_Settings::get_instance()->get_selected_theme();
			if ( ! empty( $selected_theme ) ) {
				$class_string .= ' mainwp-custom-theme ';
			}

			$siteViewMode = MainWP_Utility::get_siteview_mode();
			if ( 'grid' == $siteViewMode ) {
				$class_string .= ' mainwp-sites-grid-view ';
			} else {
				$class_string .= ' mainwp-sites-table-view ';
			}

			if ( isset( $_GET['page'] ) && 'managesites' == $_GET['page'] )  {
				if ( isset( $_GET['dashboard'] ) && '' != $_GET['dashboard'] ) {
					$class_string .= ' mainwp-individual-site-overview ';
				}
			}

			if ( isset( $_GET['page'] ) && 'ManageClients' == $_GET['page'] )  {
				if ( isset( $_GET['client_id'] ) && '' != $_GET['client_id'] ) {
					$class_string .= ' mainwp-individual-client-overview ';
				}
			}
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
	public static function render_footer_content( $websites, $current_wpid = false ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
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
		MainWP_UI::render_select_mainwp_themes_modal();
		?>
		<div class="ui longer modal" id="mainwp-sync-sites-modal" current-wpid="<?php echo intval( $current_wpid ); ?>">
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
							$site_name   = $website->name;
							$website     = $websites[ $i ];
							$is_sync_err = ( '' != $website->sync_errors ) ? true : false;
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
							$is_sync_err = ( '' != $website->sync_errors ) ? true : false;
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
			<div class="actions mainwp-modal-actions">
				<div class="mainwp-modal-close ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
			</div>
		</div>
		<input type="hidden" id="sync_selected_site_ids" value="" />
		<div class="ui tiny modal" id="mainwp-modal-confirm">
			<div class="header"><?php esc_html_e( 'Confirmation', 'mainwp' ); ?></div>
			<div class="content">
				<div class="content-massage"></div>
				<div class="ui mini yellow message hidden update-confirm-notice" ><?php printf( esc_html__( 'To disable update confirmations, go to the %1$sSettings%2$s page and disable the "Disable update confirmations" option', 'mainwp' ), '<a href="admin.php?page=Settings">', '</a>' ); ?></div>
				<div class="ui form hidden" id="mainwp-confirm-form">
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
						<div class="ui green positive button"><?php esc_html_e( 'Yes, proceed!', 'mainwp' ); ?></div>
					</div>
					<div class="ui right aligned column">
				<div class="ui cancel button"><?php esc_html_e( 'Cancel', 'mainwp' ); ?></div>
					</div>
				</div>
			</div>
		</div>
		<div class="ui tiny modal" id="mainwp-modal-confirm-select">
			<div class="header"><?php esc_html_e( 'Confirmation', 'mainwp' ); ?></div>
			<div class="content">
				<div class="content-massage"></div>
			</div>
			<div class="actions">
				<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
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
	public static function render_plugins_install_check() { // phpcs:ignore -- complex function.

		$install_check = get_option( 'mainwp_hide_plugins_install_check_notice', 0 );

		$plugins_to_checks = self::get_plugins_install_check();

		$page = sanitize_text_field( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		$plugin_check = MainWP_Utility::get_sub_array_having( $plugins_to_checks, 'page', $page );

		if ( ! empty( $plugin_check ) ) {
			$plugin_check = current( $plugin_check );
		}

		if ( empty( $plugin_check ) ) {
			return;
		}

		// if is not overview extension page return.
		if ( isset( $_GET['tab'] ) && 'overview' !== $_GET['tab'] && 'dashboard' !== $_GET['tab'] ) { // phpcs:ignore WordPress.Security.NonceVerification
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
			if ( '' != $website->sync_errors ) {
				continue;
			}
			$not_found = true;
			if ( '' != $website->plugins ) {
				$plugins = json_decode( $website->plugins, 1 );
				if ( is_array( $plugins ) && count( $plugins ) > 0 ) {
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
			<div class="header"><?php esc_html_e( 'Plugin Install Check', 'mainwp' ); ?></div>
			<div class="scrolling content mainwp-modal-content">
				<div class="ui message" id="mainwp-message-zone-install" style="display:none;"></div>
				<div class="ui message blue"><?php printf( esc_html__( 'We have detected the following sites do not have the %s plugin installed. This plugin is required to be installed on your Child Sites for the Extension to work on those sites. Please select sites where you want to install it and click the Install Plugin button. Uncheck any site you don\'t want to add the plugin to or cancel to skip this step. After the installation process, resync your sites to see sites with the newly installed plugin.', 'mainwp' ), esc_html( $plugin_name ) ); ?></div>
				<div class="ui middle aligned divided selection list" id="sync-sites-status">
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
						<input type="button" class="ui green button" id="mainwp-install-check-btn" value="<?php esc_html_e( 'Install Plugin', 'mainwp' ); ?>">	
					</div>
					<div class="ui right aligned column">
						<div class="mainwp-modal-close ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
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
						var noti_id = jQuery('#mainwp-install-check-modal').attr('noti-slug');
						mainwp_notice_dismiss(noti_id, 1);
						setTimeout(function () {
							window.location.href = location.href;
						}, 1000);					
					},
				}).modal('show');
				jQuery(document).on('click', '#mainwp-install-check-btn', function () {
					mainwp_install_check_plugin_prepare( '<?php echo rawurlencode( dirname( $plugin_slug ) ); ?>' );
					return false;
				});
			});
		</script>
		<?php
	}
}
