<?php
/**
 * MainWP Backups Slice Cron.
 *
 * Include cron/bootstrap.php & run mainwp_cronbackups_slice_action.
 *
 * @package MainWP/Backups_Slice
 */

// Bootstrap supplies WordPress and the MainWP instance for direct cron requests.
require_once 'bootstrap.php'; // NOSONAR - WP compatible.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$mainWP->mainwp_cronbackups_slice_action();
