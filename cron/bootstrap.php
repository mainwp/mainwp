<?php

@ignore_user_abort(true);
@set_time_limit(0);
$mem = '512M';
@ini_set('memory_limit', $mem);
@ini_set('max_execution_time', 0);

define('DOING_CRON', true);
$included = false;

if (file_exists(__DIR__ . '/../../../../wp-load.php')) {
    include_once __DIR__ . '/../../../../wp-load.php';
    $included = true;
} else {
    foreach (glob(__DIR__ . '/../../../../*/wp-load.php', GLOB_NOSORT) as $filepath) {

        if (!empty($filepath)) {
            include_once $filepath;
            $included = true;
            break;
        }
    }
}

if (!$included) {
    exit('Unsupported wordpress setup');
}