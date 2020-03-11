<?php
/**
* MainWP Ping Childs Cron.
* 
* Include cron/bootstrap.php & run mainwp_cronpingchilds_action
* 
*/

// include cron/bootstrap.php
include_once('bootstrap.php');

// fire off mainWP->mainwp_cronpingchilds_action
$mainWP->mainwp_cronpingchilds_action();
