<?php
/**
* MainWP Ping Childs Cron.
* 
* Include cron/bootstrap.php & run mainwp_cronpingchilds_action
*/

// include cron/bootstrap.php
require_once 'bootstrap.php';

// fire off mainWP->cronpingchilds_action
$mainWP->mainwp_cronpingchilds_action();
