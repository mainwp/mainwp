<?php

class MainWPServerInformation
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function initMenu()
    {
        add_submenu_page('mainwp_tab', __('Server Information','mainwp'), __('Server Information','mainwp'), 'read', 'ServerInformation', array(MainWPServerInformation::getClassName(), 'render'));
        add_submenu_page('mainwp_tab', __('Cron Schedules','mainwp'), '<div class="mainwp-hidden">' . __('Cron Schedules','mainwp') . '</div>', 'read', 'ServerInformationCron', array(MainWPServerInformation::getClassName(), 'renderCron'));
        add_submenu_page('mainwp_tab', __('Child Site Information','mainwp'), '<div class="mainwp-hidden">' . __('Child Site Information','mainwp') . '</div>', 'read', 'ServerInformationChild', array(MainWPServerInformation::getClassName(), 'renderChild'));
        add_submenu_page('mainwp_tab', __('Error Log','mainwp'), '<div class="mainwp-hidden">' . __('Error Log','mainwp') . '</div>', 'read', 'ErrorLog', array(MainWPServerInformation::getClassName(), 'renderErrorLogPage'));
        add_submenu_page('mainwp_tab', __('WP-Config File','mainwp'), '<div class="mainwp-hidden">' . __('WP-Config File','mainwp') . '</div>', 'read', 'WPConfig', array(MainWPServerInformation::getClassName(), 'renderWPConfig'));
        add_submenu_page('mainwp_tab', __('.htaccess File','mainwp'), '<div class="mainwp-hidden">' . __('.htaccess File','mainwp') . '</div>', 'read', '.htaccess', array(MainWPServerInformation::getClassName(), 'renderhtaccess'));
    }

    public static function renderHeader($shownPage)
    {
        ?>
    <div class="wrap"><a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img
            src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50" alt="MainWP"/></a>
        <img src="<?php echo plugins_url('images/icons/mainwp-serverinfo.png', dirname(__FILE__)); ?>"
             style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Server Information"
             height="32"/>

        <h2><?php _e('Server Information','mainwp'); ?></h2><div style="clear: both;"></div><br/>

        <div class="clear"></div>
        <div class="wrap">
            <div class="mainwp-tabs" id="mainwp-tabs">
                <a class="nav-tab pos-nav-tab <?php if ($shownPage === '') { echo "nav-tab-active"; } ?>" href="admin.php?page=ServerInformation"><?php _e('Server','mainwp'); ?></a>
                <a class="nav-tab pos-nav-tab <?php if ($shownPage === 'ServerInformationCron') { echo "nav-tab-active"; } ?>" href="admin.php?page=ServerInformationCron"><?php _e('Cron Schedules','mainwp'); ?></a>
                <a style="float: right;" class="nav-tab pos-nav-tab <?php if ($shownPage === 'ServerInformationChild') { echo "nav-tab-active"; } ?>" href="admin.php?page=ServerInformationChild"><?php _e('Child Site Information','mainwp'); ?></a>
                <a class="nav-tab pos-nav-tab <?php if ($shownPage === 'ErrorLog') { echo "nav-tab-active"; } ?>" href="admin.php?page=ErrorLog"><?php _e('Error Log','mainwp'); ?></a>
                <a class="nav-tab pos-nav-tab <?php if ($shownPage === 'WPConfig') { echo "nav-tab-active"; } ?>" href="admin.php?page=WPConfig"><?php _e('WP-Config File','mainwp'); ?></a>
                <a class="nav-tab pos-nav-tab <?php if ($shownPage === '.htaccess') { echo "nav-tab-active"; } ?>" href="admin.php?page=.htaccess"><?php _e('.htaccess File','mainwp'); ?></a>
            </div>
            <div id="mainwp_wrap-inside">
        <?php
    }

    public static function renderFooter($shownPage)
    {
        ?>
        </div>
    </div>
        <?php
    }

    public static function render()
    {        
        if (!mainwp_current_user_can("dashboard", "see_server_information")) {
            mainwp_do_not_have_permissions("server information");
            return;
        }
        
        self::renderHeader('');
        ?>
        <div class="updated below-h2">
            <p><?php _e("Please include this information when requesting support:", "mainwp"); ?></p><span class="mwp_close_srv_info"><a href="#" id="mwp_download_srv_info"><?php _e("Download", "mainwp");?></a> | <a href="#" id="mwp_close_srv_info"><?php _e("Hide", "mainwp");?></a></span>
            <p class="submit"><a class="button-primary mwp-get-system-report-btn" href="#"><?php _e("Get System Report", "mainwp"); ?></a></p>
            <div id="mwp-server-information"><textarea readonly="readonly"  wrap="off"></textarea></div>
        </div>
        <br />
        <div class="mwp_server_info_box">
                <table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
                    <thead>
                    <tr>
                        <th scope="col" class="manage-column column-posts mwp-not-generate-row" style="width: 1px;"><?php _e('','mainwp'); ?></th>
                        <th scope="col" class="manage-column sorted" style=""><span><?php _e('Server Configuration','mainwp'); ?></span></th>
                        <th scope="col" class="manage-column column-posts" style=""><?php _e('Suggested Value','mainwp'); ?></th>
                        <th scope="col" class="manage-column column-posts" style=""><?php _e('Value','mainwp'); ?></th>
                        <th scope="col" class="manage-column column-posts" style=""><?php _e('Status','mainwp'); ?></th>
                    </tr>
                    </thead>

                    <tbody id="the-sites-list" class="list:sites">
                        <?php
                        self::renderRow('WordPress Version', '>=', '3.6', 'getWordpressVersion', '', '', null, 'MainWP requires the WordPress version 3.6 or higher. If the condition is not met, please update your Website. Click the help icon to read more.');
                        self::renderRow('PHP Version', '>=', '5.2.4', 'getPHPVersion', '', '', null, 'MainWP requires the PHP version 5.24 or higher. If the condition is not met, PHP version needs to be updated on your server. Before doing anything by yourself, we highly recommend contacting your hosting support department and asking them to do it for you. Click the help icon to read more.');
                        self::renderRow('MySQL Version', '>=', '5.0', 'getMySQLVersion', '', '', null, 'MainWP requires the MySQL version 5.0 or higher. If the condition is not met, MySQL version needs to be updated on your server. Before doing anything by yourself, we highly recommend contacting your hosting support department and asking them to do it for you. Click the help icon to read more.');
                        self::renderRow('PHP Max Execution Time', '>=', '30', 'getMaxExecutionTime', 'seconds', '=', '0', 'Changed by modifying the value max_execution_time in your php.ini file. Click the help icon to read more.');
                        self::renderRow('PHP Upload Max Filesize', '>=', '2M', 'getUploadMaxFilesize', '(2MB+ best for upload of big plugins)', '', null, 'Changed by modifying the value upload_max_filesize in your php.ini file. Click the help icon to read more.');
                        self::renderRow('PHP Post Max Size', '>=', '2M', 'getPostMaxSize', '(2MB+ best for upload of big plugins)', '', null, 'Changed by modifying the value post_max_size in your php.ini file. Click the help icon to read more.');
//                            self::renderRow('PHP Memory Limit', '>=', '128M', 'getPHPMemoryLimit', '(256M+ best for big backups)');
                        self::renderRow('PCRE Backtracking Limit', '>=', '10000', 'getOutputBufferSize', '', '', null, 'Changed by modifying the value pcre.backtrack_limit in your php.ini file. Click the help icon to read more.');
                        self::renderRow('SSL Extension Enabled', '=', true, 'getSSLSupport', '', '', null, 'Changed by uncommenting the ;extension=php_openssl.dll line in your php.ini file by removing the ";" character. Click the help icon to read more.');
                        self::renderRow('SSL Warnings', '=', '', 'getSSLWarning', 'empty', '', null, 'If your SSL Warnings has any errors we suggest speaking with your web host so they can help troubleshoot the specific error you are getting. Click the help icon to read more.');
                        self::renderRow('Curl Extension Enabled', '=', true, 'getCurlSupport', '', '', null, 'Changed by uncommenting the ;extension=php_curl.dll line in your php.ini file by removing the ";" character. Click the help icon to read more.');
                        self::renderRow('Curl Timeout', '>=', '300', 'getCurlTimeout', 'seconds', '=', '0', 'Changed by modifying the value default_socket_timeout in your php.ini file. Click the help icon to read more.');
                        ?>
                    </tbody>
                </table>
                <br />
                <table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
                    <thead>
                    <tr>
                        <th scope="col" class="manage-column sorted mwp-not-generate-row" style="width: 1px;"></th>
                        <th scope="col" class="manage-column sorted" style=""><span><?php _e('Directory name','mainwp'); ?></span></th>
                        <th scope="col" class="manage-column sorted" style=""><span><?php _e('Path','mainwp'); ?></span></th>
                        <th scope="col" class="manage-column column-posts" style=""><?php _e('Check','mainwp'); ?></th>
                        <th scope="col" class="manage-column column-posts" style=""><?php _e('Result','mainwp'); ?></th>
                        <th scope="col" class="manage-column column-posts" style=""><?php _e('Status','mainwp'); ?></th>
                    </tr>
                    </thead>

                    <tbody id="the-sites-list" class="list:sites">
                        <?php
                        self::checkDirectoryMainWPDirectory();
                        ?>
                    </tbody>
                </table>
                <br/>
                <table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column sorted" style=""><span><?php _e('Server Info','mainwp'); ?></span></th>
                        <th scope="col" class="manage-column column-posts" style=""><span><?php _e('Value','mainwp'); ?></span></th>
                    </tr>
                </thead>
                    <tbody id="the-sites-list" class="list:sites">
                      <tr><td><?php _e('WordPress Root Directory','mainwp'); ?></td><td><?php self::getWPRoot(); ?></td></tr>
                      <tr><td><?php _e('Server Name','mainwp'); ?></td><td><?php self::getSeverName(); ?></td></tr>
                      <tr><td><?php _e('Server Sofware','mainwp'); ?></td><td><?php self::getServerSoftware(); ?></td></tr>
                      <tr><td><?php _e('Operating System','mainwp'); ?></td><td><?php self::getOS(); ?></td></tr>
                      <tr><td><?php _e('Architecture','mainwp'); ?></td><td><?php self::getArchitecture(); ?></td></tr>
                      <tr><td><?php _e('Server IP','mainwp'); ?></td><td><?php self::getServerIP(); ?></td></tr>
                      <tr><td><?php _e('Server Protocol','mainwp'); ?></td><td><?php self::getServerProtocol(); ?></td></tr>
                      <tr><td><?php _e('HTTP Host','mainwp'); ?></td><td><?php self::getHTTPHost(); ?></td></tr>
                      <tr><td><?php _e('Server Admin','mainwp'); ?></td><td><?php self::getServerAdmin(); ?></td></tr>
                      <tr><td><?php _e('Server Port','mainwp'); ?></td><td><?php self::getServerPort(); ?></td></tr>
                      <tr><td><?php _e('Getaway Interface','mainwp'); ?></td><td><?php self::getServerGetawayInterface(); ?></td></tr>
                      <tr><td><?php _e('Memory Usage','mainwp'); ?></td><td><?php self::memoryUsage(); ?></td></tr>
                      <tr><td><?php _e('HTTPS','mainwp'); ?></td><td><?php self::getHTTPS(); ?></td></tr>
                      <tr><td><?php _e('User Agent','mainwp'); ?></td><td><?php self::getUserAgent(); ?></td></tr>
                      <tr><td><?php _e('Complete URL','mainwp'); ?></td><td><?php self::getCompleteURL(); ?></td></tr>
                      <tr><td><?php _e('Request Method','mainwp'); ?></td><td><?php self::getServerRequestMethod(); ?></td></tr>
                      <tr><td><?php _e('Request Time','mainwp'); ?></td><td><?php self::getServerRequestTime(); ?></td></tr>
                      <tr><td><?php _e('Query String','mainwp'); ?></td><td><?php self::getServerQueryString(); ?></td></tr>
                      <tr><td><?php _e('Accept Content','mainwp'); ?></td><td><?php self::getServerHTTPAccept(); ?></td></tr>
                      <tr><td><?php _e('Accept-Charset Content','mainwp'); ?></td><td><?php self::getServerAcceptCharset(); ?></td></tr>
                      <tr class="mwp-not-generate-row"><td><?php _e('Currently Executing Script Pathname','mainwp'); ?></td><td><?php self::getScriptFileName(); ?></td></tr>
                      <tr><td><?php _e('Server Signature','mainwp'); ?></td><td><?php self::getServerSignature(); ?></td></tr>
                      <tr><td><?php _e('Currently Executing Script','mainwp'); ?></td><td><?php self::getCurrentlyExecutingScript(); ?></td></tr>
                      <tr><td><?php _e('Path Translated','mainwp'); ?></td><td><?php self::getServerPathTranslated(); ?></td></tr>
                      <tr><td><?php _e('Current Script Path','mainwp'); ?></td><td><?php self::getScriptName(); ?></td></tr>
                      <tr><td><?php _e('Current Page URI','mainwp'); ?></td><td><?php self::getCurrentPageURI(); ?></td></tr>
                      <tr class="mwp-not-generate-row"><td><?php _e('Remote Address','mainwp'); ?></td><td><?php self::getRemoteAddress(); ?></td></tr>
                      <tr><td><?php _e('Remote Host','mainwp'); ?></td><td><?php self::getRemoteHost(); ?></td></tr>
                      <tr><td><?php _e('Remote Port','mainwp'); ?></td><td><?php self::getRemotePort(); ?></td></tr>
                      <tr><td><?php _e('PHP Safe Mode','mainwp'); ?></td><td><?php self::getPHPSafeMode(); ?></td></tr>
                      <tr><td><?php _e('PHP Allow URL fopen','mainwp'); ?></td><td><?php self::getPHPAllowUrlFopen(); ?></td></tr>
                      <tr><td><?php _e('PHP Exif Support','mainwp'); ?></td><td><?php self::getPHPExif(); ?></td></tr>
                      <tr><td><?php _e('PHP IPTC Support','mainwp'); ?></td><td><?php self::getPHPIPTC(); ?></td></tr>
                      <tr><td><?php _e('PHP XML Support','mainwp'); ?></td><td><?php self::getPHPXML(); ?></td></tr>
                      <tr><td><?php _e('SQL Mode','mainwp'); ?></td><td><?php self::getSQLMode(); ?></td></tr>
                    </tbody>
                </table>
                </div>
                <br />
            </div>
    <?php
      self::renderFooter('');
    }

    public static function fetchChildServerInformation($siteId)
    {
        try
        {
            $website = MainWPDB::Instance()->getWebsiteById($siteId);

            if (!MainWPUtility::can_edit_website($website))
            {
                return 'This is not your website.';
            }

            $serverInformation = MainWPUtility::fetchUrlAuthed($website, 'serverInformation');
            ?>

        <img src="<?php echo plugins_url('images/icons/mainwp-serverinfo.png', dirname(__FILE__)); ?>" style="float: left; margin-right: 8px; margin-top: 7px;" /> <h2><strong><?php echo $website->name; ?></strong>&nbsp;<?php _e('Server Information'); ?></h2>
        <?php echo $serverInformation['information']; ?>
        <img src="<?php echo plugins_url('images/icons/mainwp-serverinfo.png', dirname(__FILE__)); ?>" style="float: left; margin-right: 8px; margin-top: 7px;" /> <h2><strong><?php echo $website->name; ?></strong>&nbsp;<?php _e('Cron Schedules'); ?></h2>
        <?php echo $serverInformation['cron']; ?>
        <?php if (isset($serverInformation['wpconfig'])) { ?>
        <img src="<?php echo plugins_url('images/icons/mainwp-serverinfo.png', dirname(__FILE__)); ?>" style="float: left; margin-right: 8px; margin-top: 7px;" /> <h2><strong><?php echo $website->name; ?></strong>&nbsp;<?php _e('WP-Config File'); ?></h2>
        <?php echo $serverInformation['wpconfig']; ?>
        <img src="<?php echo plugins_url('images/icons/mainwp-serverinfo.png', dirname(__FILE__)); ?>" style="float: left; margin-right: 8px; margin-top: 7px;" /> <h2><strong><?php echo $website->name; ?></strong>&nbsp;<?php _e('Error Log'); ?></h2>
        <?php echo $serverInformation['error']; ?>
        <?php } ?>
            <?php
        }
        catch (MainWPException $e)
        {
            die(MainWPErrorHelper::getErrorMessage($e));
        }
        catch (Exception $e)
        {
            die('Something went wrong processing your request.');
        }

        die();
    }

    public static function renderChild()
    {
        self::renderHeader('ServerInformationChild');

        $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());

        echo 'Child Site: <select name="" id="mainwp_serverInformation_child"><option value="-1">-- Select site</option>';

        while ($websites && ($website = @MainWPDB::fetch_object($websites)))
        {
            echo '<option value="'.$website->id.'">' . $website->name . '</option>';
        }
        @MainWPDB::free_result($websites);


        echo '</select><br /><br /><div id="mainwp_serverInformation_child_loading"><img src="' . plugins_url('images/loader.gif', dirname(__FILE__)) . '"/> ' . __('Loading server information..', 'mainwp') . '</div><div id="mainwp_serverInformation_child_resp"></div>';

        self::renderFooter('ServerInformationChild');
    }

    public static function renderCron()
    {
        self::renderHeader('ServerInformationCron');

        $schedules = array(
            'Backups' => 'mainwp_cron_last_backups',
            'Backups continue' => 'mainwp_cron_last_backups_continue',
            'Updates check' => 'mainwp_cron_last_updatescheck',
            'Stats' => 'mainwp_cron_last_stats',
            'Ping childs' => 'mainwp_cron_last_ping',
            'Offline checks' => 'mainwp_cron_last_offlinecheck',
            'Conflicts update' => 'mainwp_cron_last_cronconflicts'
        );
?>
    <table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
        <thead>
            <tr>
                <th scope="col" class="manage-column sorted" style=""><span><?php _e('Schedule','mainwp'); ?></span></th>
                <th scope="col" class="manage-column column-posts" style=""><span><?php _e('Last run','mainwp'); ?></span></th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ($schedules as $schedule => $option)
            {   
            ?>
            <tr><td><?php echo $schedule; ?></td><td><?php echo (get_option($option) === false || get_option($option) == 0) ? 'Never run' : MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp(get_option($option))); ?></td></tr>
            <?php
            }
            ?>
        </tbody>
    </table>
        <br />
            <?php
        $cron_array = _get_cron_array();
        $schedules = wp_get_schedules();
        ?>
    <table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
        <thead>
            <tr>
                <th scope="col" class="manage-column sorted" style=""><span><?php _e('Next due','mainwp'); ?></span></th>
                <th scope="col" class="manage-column column-posts" style=""><span><?php _e('Schedule','mainwp'); ?></span></th>
                <th scope="col" class="manage-column column-posts" style=""><span><?php _e('Hook','mainwp'); ?></span></th>
            </tr>
        </thead>
        <tbody id="the-sites-list" class="list:sites">
        <?php
        foreach ($cron_array as $time => $cron)
        {
            foreach ($cron as $hook => $cron_info)
            {
                foreach ($cron_info as $key => $schedule )
                {
                    ?>
                    <tr><td><?php echo MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($time)); ?></td><td><?php echo $schedules[$schedule['schedule']]['display'];?> </td><td><?php echo $hook; ?></td></tr>
                    <?php
                }
            }
        }
        ?>
        </tbody>
    </table>
        <?php
        self::renderFooter('ServerInformationCron');
    }

    protected static function checkDirectoryMainWPDirectory()
    {
        $dirs = MainWPUtility::getMainWPDir();
        $path = $dirs[0];

        if (!is_dir(dirname($path)))
        {
            return self::renderDirectoryRow('MainWP upload directory', $path, 'Writable', 'Directory not found', false);
        }

        $hasWPFileSystem = MainWPUtility::getWPFilesystem();
        global $wp_filesystem;

        if ($hasWPFileSystem && !empty($wp_filesystem))
        {
            if (!$wp_filesystem->is_writable($path))
            {
                return self::renderDirectoryRow('MainWP upload directory', $path, 'Writable', 'Directory not writable', false);
            }
        }
        else
        {
            if (!is_writable($path))
            {
                return self::renderDirectoryRow('MainWP upload directory', $path, 'Writable', 'Directory not writable', false);
            }
        }

        return self::renderDirectoryRow('MainWP upload directory', $path, 'Writable', '/', true);
    }

    protected static function renderDirectoryRow($pName, $pDirectory, $pCheck, $pResult, $pPassed)
    {
        ?>
    <tr>
        <td class="mwp-not-generate-row"><a href="http://docs.mainwp.com/child-site-issues/" target="_blank"><?php MainWPUtility::renderToolTip('MainWP requires the ../wp-content/uploads/mainwp/ directory to be writable. If the condition is not met, you need to set permissions for the directory. You can do that by using an FTP program like FileZilla and connecting to your site. Go through the directory tree mentioned above and make sure the folders exist /wp-content/uploads/mainwp/. If they do not exist you can right click and create directory. Then name the folder to match the structure above. The permissions should be 755 or 777 depending on your host. We suggest trying 755 first. To check this right click the folder and go to permissions or chmod. Click the help icon to read more.'); ?></a></td>
        <td><?php echo $pName; ?></td>
        <td class="mwp-hide-generate-row"><?php echo $pDirectory; ?></td>
        <td><?php echo $pCheck; ?></td>
        <td><?php echo $pResult; ?></td>
        <td><?php echo ($pPassed ? '<span class="mainwp-pass">Pass</span>' : '<span class="mainwp-warning">Warning</span>'); ?></td>
    </tr>
    <?php
      return true;
    }

    protected static function renderRow($pConfig, $pCompare, $pVersion, $pGetter, $pExtraText = '', $pExtraCompare = null, $pExtraVersion = null, $toolTip = null)
    {
        $currentVersion = call_user_func(array(MainWPServerInformation::getClassName(), $pGetter));

        ?>
    <tr>
        <td class="mwp-not-generate-row"><?php if ($toolTip != null) { ?> <a href="http://docs.mainwp.com/child-site-issues/" target="_blank"><?php MainWPUtility::renderToolTip($toolTip); ?></a><?php } ?></td>
        <td><?php echo $pConfig; ?></td>
        <td><?php echo $pCompare; ?>  <?php echo ($pVersion === true ? 'true' : $pVersion) . ' ' . $pExtraText; ?></td>
        <td><?php echo ($currentVersion === true ? 'true' : $currentVersion); ?></td>
        <td><?php echo (version_compare($currentVersion, $pVersion, $pCompare) || (($pExtraCompare != null) && version_compare($currentVersion, $pExtraVersion, $pExtraCompare)) ? '<span class="mainwp-pass">Pass</span>' : '<span class="mainwp-warning">Warning</span>'); ?></td>
    </tr>
    <?php
    }

    protected static function getWordpressVersion()
    {
        global $wp_version;
        return $wp_version;
    }

    protected static function getSSLSupport()
    {
        return extension_loaded('openssl');
    }

    protected static function getSSLWarning()
    {
        $conf = array('private_key_bits' => 384);
        $res = @openssl_pkey_new($conf);
        @openssl_pkey_export($res, $privkey);

    	$str = openssl_error_string();
        return (stristr($str, 'NCONF_get_string:no value') ? '' : $str);
    }

    protected static function getCurlSupport()
    {
        return function_exists('curl_version');
    }

    protected static function getCurlTimeout()
    {
        return ini_get('default_socket_timeout');
    }

    protected static function getPHPVersion()
    {
        return phpversion();
    }

    protected static function getMaxExecutionTime()
    {
        return ini_get('max_execution_time');
    }

    protected static function getUploadMaxFilesize()
    {
        return ini_get('upload_max_filesize');
    }

    protected static function getPostMaxSize()
    {
        return ini_get('post_max_size');
    }

    protected static function getMySQLVersion()
    {
        return MainWPDB::Instance()->getMySQLVersion();
    }

    protected static function getPHPMemoryLimit()
    {
        return ini_get('memory_limit');
    }
    protected static function getOS()
    {
        echo PHP_OS;
    }
    protected static function getArchitecture()
    {
        echo (PHP_INT_SIZE * 8)?>&nbsp;bit <?php
    }
    protected static function memoryUsage()
    {
       if (function_exists('memory_get_usage')) $memory_usage = round(memory_get_usage() / 1024 / 1024, 2) . __(' MB');
       else $memory_usage = __('N/A');
       echo $memory_usage;
    }
    protected static function getOutputBufferSize()
    {
       return ini_get('pcre.backtrack_limit');
    }
    protected static function getPHPSafeMode()
    {
       if(ini_get('safe_mode')) $safe_mode = __('ON');
       else $safe_mode = __('OFF');
       echo $safe_mode;
    }
    protected static function getSQLMode()
    {
        global $wpdb;
        $mysqlinfo = $wpdb->get_results("SHOW VARIABLES LIKE 'sql_mode'");
        if (is_array($mysqlinfo)) $sql_mode = $mysqlinfo[0]->Value;
        if (empty($sql_mode)) $sql_mode = __('NOT SET');
        echo $sql_mode;
    }
    protected static function getPHPAllowUrlFopen()
    {
        if(ini_get('allow_url_fopen')) $allow_url_fopen = __('ON');
        else $allow_url_fopen = __('OFF');
        echo $allow_url_fopen;
    }
    protected static function getPHPExif()
    {
        if (is_callable('exif_read_data')) $exif = __('YES'). " ( V" . substr(phpversion('exif'),0,4) . ")" ;
        else $exif = __('NO');
        echo $exif;
    }
    protected static function getPHPIPTC()
    {
        if (is_callable('iptcparse')) $iptc = __('YES');
        else $iptc = __('NO');
        echo $iptc;
    }
     protected static function getPHPXML()
    {
        if (is_callable('xml_parser_create')) $xml = __('YES');
        else $xml = __('NO');
        echo $xml;
    }

    // new

    protected static function getCurrentlyExecutingScript() {
        echo $_SERVER['PHP_SELF'];
    }

    protected static function getServerGetawayInterface() {
        echo $_SERVER['GATEWAY_INTERFACE'];
    }

    protected static function getServerIP() {
        echo $_SERVER['SERVER_ADDR'];
    }

    protected static function getSeverName() {
        echo $_SERVER['SERVER_NAME'];
    }

    protected static function getServerSoftware() {
        echo $_SERVER['SERVER_SOFTWARE'];
    }

    protected static function getServerProtocol() {
        echo $_SERVER['SERVER_PROTOCOL'];
    }

    protected static function getServerRequestMethod() {
        echo $_SERVER['REQUEST_METHOD'];
    }

    protected static function getServerRequestTime(){
        echo $_SERVER['REQUEST_TIME'];
    }

    protected static function getServerQueryString() {
        echo $_SERVER['QUERY_STRING'];
    }

    protected static function getServerHTTPAccept() {
        echo $_SERVER['HTTP_ACCEPT'];
    }

    protected static function getServerAcceptCharset() {
        if (!isset($_SERVER['HTTP_ACCEPT_CHARSET']) || ($_SERVER['HTTP_ACCEPT_CHARSET'] == '')) {
            echo __('N/A','mainwp');
        }
        else
        {
            echo $_SERVER['HTTP_ACCEPT_CHARSET'];
        }
    }

    protected static function getHTTPHost() {
        echo $_SERVER['HTTP_HOST'];
    }

    protected static function getCompleteURL() {
        echo $_SERVER['HTTP_REFERER'];
    }

    protected static function getUserAgent() {
        echo $_SERVER['HTTP_USER_AGENT'];
    }

    protected static function getHTTPS() {
        if ( isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != '' ) {
            echo __('ON','mainwp') . ' - ' . $_SERVER['HTTPS'] ;
        }
        else {
            echo __('OFF','mainwp') ;
        }
    }

    protected static function getRemoteAddress() {
        echo $_SERVER['REMOTE_ADDR'];
    }

    protected static function getRemoteHost() {
        if (!isset($_SERVER['REMOTE_HOST']) || ($_SERVER['REMOTE_HOST'] == '')) {
            echo __('N/A','mainwp');
        }
        else {
            echo $_SERVER['REMOTE_HOST'] ;
        }
    }

    protected static function getRemotePort() {
        echo $_SERVER['REMOTE_PORT'];
    }

    protected static function getScriptFileName() {
        echo $_SERVER['SCRIPT_FILENAME'];
    }

    protected static function getServerAdmin() {
        echo $_SERVER['SERVER_ADMIN'];
    }

    protected static function getServerPort() {
        echo $_SERVER['SERVER_PORT'];
    }

    protected static function getServerSignature() {
        echo $_SERVER['SERVER_SIGNATURE'];
    }

    protected static function getServerPathTranslated() {
        if (!isset($_SERVER['PATH_TRANSLATED']) || ($_SERVER['PATH_TRANSLATED'] == '')) {
            echo __('N/A','mainwp') ;
        }
        else {
            echo $_SERVER['PATH_TRANSLATED'] ;
        }
    }

    protected static function getScriptName() {
        echo $_SERVER['SCRIPT_NAME'];
    }

    protected static function getCurrentPageURI() {
        echo $_SERVER['REQUEST_URI'];
    }
    protected static function getWPRoot() {
        echo ABSPATH ;
    }

    function formatSizeUnits($bytes)
    {
        if ($bytes >= 1073741824)
        {
            $bytes = number_format($bytes / 1073741824, 2) . ' GB';
        }
        elseif ($bytes >= 1048576)
        {
            $bytes = number_format($bytes / 1048576, 2) . ' MB';
        }
        elseif ($bytes >= 1024)
        {
            $bytes = number_format($bytes / 1024, 2) . ' KB';
        }
        elseif ($bytes > 1)
        {
            $bytes = $bytes . ' bytes';
        }
        elseif ($bytes == 1)
        {
            $bytes = $bytes . ' byte';
        }
        else
        {
            $bytes = '0 bytes';
        }

        return $bytes;

     }

     /*
      *Plugin Name: Error Log Dashboard Widget
      *Plugin URI: http://wordpress.org/extend/plugins/error-log-dashboard-widget/
      *Description: Robust zero-configuration and low-memory way to keep an eye on error log.
      *Author: Andrey "Rarst" Savchenko
      *Author URI: http://www.rarst.net/
      *Version: 1.0.2
      *License: GPLv2 or later

      *Includes last_lines() function by phant0m, licensed under cc-wiki and GPLv2+
    */

     public static function renderErrorLogPage() {

        self::renderHeader('ErrorLog');
        ?>
        <table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
                <thead title="Click to Toggle" style="cursor: pointer;">
                    <tr>
                        <th scope="col" class="manage-column column-posts" style="width: 10%"><span><?php _e('Time','mainwp'); ?></span></th>
                        <th scope="col" class="manage-column column-posts" style=""><span><?php _e('Error','mainwp'); ?></span></th>
                    </tr>
                </thead>
                    <tbody class="list:sites" id="mainwp-error-log-table">
                        <?php self::renderErrorLog(); ?>
                    </tbody>
                </table>
        <?php
        self::renderFooter('ErrorLog');
     }

     public static function renderErrorLog() {
        $log_errors = ini_get( 'log_errors' );
        if ( ! $log_errors )
         echo '<tr><td colspan="2">' . __( 'Error logging disabled.', 'mainwp' ) . '</td></tr>';

        $error_log = ini_get( 'error_log' );
        $logs      = apply_filters( 'error_log_mainwp_logs', array( $error_log ) );
        $count     = apply_filters( 'error_log_mainwp_lines', 10 );
        $lines     = array();

        foreach ( $logs as $log ) {

            if ( is_readable( $log ) )
                $lines = array_merge( $lines, self::last_lines( $log, $count ) );
        }

        $lines = array_map( 'trim', $lines );
        $lines = array_filter( $lines );

        if ( empty( $lines ) ) {

            echo '<tr><td colspan="2">' . __( 'No errors found... Yet.', 'mainwp' ) . '</td></tr>';

            return;
        }

        foreach ( $lines as $key => $line ) {

            if ( false != strpos( $line, ']' ) )
                list( $time, $error ) = explode( ']', $line, 2 );
            else
                list( $time, $error ) = array( '', $line );

            $time        = trim( $time, '[]' );
            $error       = trim( $error );
            $lines[$key] = compact( 'time', 'error' );
        }

        if ( count( $error_log ) > 1 ) {

            uasort( $lines, array( __CLASS__, 'time_compare' ) );
            $lines = array_slice( $lines, 0, $count );
        }

        foreach ( $lines as $line ) {

            $error = esc_html( $line['error'] );
            $time  = esc_html( $line['time'] );

            if ( ! empty( $error ) )
                echo( "<tr><td>{$time}</td><td>{$error}</td></tr>" );
        }

     }

     static function time_compare( $a, $b ) {

        if ( $a == $b )
            return 0;

        return ( strtotime( $a['time'] ) > strtotime( $b['time'] ) ) ? - 1 : 1;
    }

    static function last_lines( $path, $line_count, $block_size = 512 ) {
        $lines = array();

        // we will always have a fragment of a non-complete line
        // keep this in here till we have our next entire line.
        $leftover = '';

        $fh = fopen( $path, 'r' );
        // go to the end of the file
        fseek( $fh, 0, SEEK_END );

        do {
            // need to know whether we can actually go back
            // $block_size bytes
            $can_read = $block_size;

            if ( ftell( $fh ) <= $block_size )
                $can_read = ftell( $fh );

            if ( empty( $can_read ) )
                break;

            // go back as many bytes as we can
            // read them to $data and then move the file pointer
            // back to where we were.
            fseek( $fh, - $can_read, SEEK_CUR );
            $data  = fread( $fh, $can_read );
            $data .= $leftover;
            fseek( $fh, - $can_read, SEEK_CUR );

            // split lines by \n. Then reverse them,
            // now the last line is most likely not a complete
            // line which is why we do not directly add it, but
            // append it to the data read the next time.
            $split_data = array_reverse( explode( "\n", $data ) );
            $new_lines  = array_slice( $split_data, 0, - 1 );
            $lines      = array_merge( $lines, $new_lines );
            $leftover   = $split_data[count( $split_data ) - 1];
        } while ( count( $lines ) < $line_count && ftell( $fh ) != 0 );

        if ( ftell( $fh ) == 0 )
            $lines[] = $leftover;

        fclose( $fh );
        // Usually, we will read too many lines, correct that here.
        return array_slice( $lines, 0, $line_count );
    }

    public static function renderWPConfig() {
        self::renderHeader('WPConfig');
        ?>
        <div class="postbox" id="mainwp-code-display">
            <h3 class="hndle" style="padding: 8px 12px; font-size: 14px;"><span>WP-Config.php</span></h3>
            <div style="padding: 1em;">
            <?php
                show_source( ABSPATH . 'wp-config.php');
            ?>
            </div>
        </div>
        <?php
        self::renderFooter('WPConfig');
    }

    public static function renderhtaccess() {
        self::renderHeader('.htaccess');
        ?>
        <div class="postbox" id="mainwp-code-display">
            <h3 class="hndle" style="padding: 8px 12px; font-size: 14px;"><span>.htaccess</span></h3>
            <div style="padding: 1em;">
            <?php
                show_source( ABSPATH . '.htaccess');
            ?>
            </div>
        </div>
        <?php
        self::renderFooter('.htaccess');
    }

}

