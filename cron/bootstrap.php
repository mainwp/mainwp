<?php
@ignore_user_abort(true);
@set_time_limit(0);
$mem =  '512M';
@ini_set('memory_limit', $mem);
@ini_set('max_execution_time', 0);

define('DOING_CRON', true);

include_once '../../../../wp-load.php';