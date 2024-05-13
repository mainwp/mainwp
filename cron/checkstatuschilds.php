<?php
/**
 * MainWP Check Childs Cron.
 *
 * Include cron/bootstrap.php & run mainwp_croncheckstatus_action.
 *
 * @package MainWP/Dashboard
 */

// include cron/bootstrap.php.
require_once 'bootstrap.php';

if ( isset( $mainWP ) ) {
	// fire off mainWP->mainwp_croncheckstatus_action.
	$mainWP->mainwp_croncheckstatus_action();
}
