<?php
/**
* MainWP Stats Cron.
* 
* Include cron/bootstrap.php & run mainwp_cronstats_action
* 
*/

// include cron/bootstrap.php
include_once('bootstrap.php');

// fire off mainWP->mainwp_cronstats_action
$mainWP->mainwp_cronstats_action();
