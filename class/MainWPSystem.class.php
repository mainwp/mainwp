<?php
if (session_id() == '') session_start();
//ini_set('display_errors', true);
//error_reporting(E_ALL | E_STRICT);

@ini_set('display_errors', false);
@error_reporting(0);
define('MAINWP_API_VALID', "VALID");
define('MAINWP_API_INVALID', "INVALID");

class MainWPSystem
{
    //Singleton
    private static $instance = null;
    private $apiStatus;

    private $upgradeVersionInfo;
    private $posthandler;
    public $metaboxes;

    /**
     * The plugin current version
     * @var string
     */
    private $current_version = null;

    /**
     * Plugin Slug (plugin_directory/plugin_file.php)
     * @var string
     */
    private $plugin_slug;

    /**
     * Plugin name (plugin_file)
     * @var string
     */
    public $slug;

    /**
     * @static
     * @return MainWPSystem
     */
    static function Instance()
    {
        return MainWPSystem::$instance;
    }

    public function getAPIStatus()
    {
        return $this->apiStatus;
    }

    public function isAPIValid()
    {
        return $this->apiStatus == MAINWP_API_VALID;
    }

    public function __construct($mainwp_plugin_file)
    {
        MainWPSystem::$instance = $this;
        $this->update();
        $this->plugin_slug = plugin_basename($mainwp_plugin_file);
        list ($t1, $t2) = explode('/', $this->plugin_slug);
        $this->slug = str_replace('.php', '', $t2);

        $this->apiStatus = MainWPAPISettings::testAPIs('main');

        if (is_admin()) {
            include_once(ABSPATH . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'plugin.php'); //Version information from wordpress
            $pluginData = get_plugin_data($mainwp_plugin_file);
            $this->current_version = $pluginData['Version'];
            MainWPUtility::update_option('mainwp_plugin_version', $this->current_version);
        }

        if ((get_option("mainwp_upgradeVersionInfo") != "") && (get_option("mainwp_upgradeVersionInfo") != null)) {
            $this->upgradeVersionInfo = unserialize(get_option("mainwp_upgradeVersionInfo"));
        }
        else {
            $this->upgradeVersionInfo = null;
        }


        MainWPExtensions::init();

        add_action('in_plugin_update_message-'.$this->plugin_slug, array($this, 'in_plugin_update_message'), 10, 2);
        add_action('init', array(&$this, 'localization'));

        // define the alternative API for updating checking
        add_filter('site_transient_update_plugins', array(&$this, 'check_update_custom'));

        // Define the alternative response for information checking
        add_filter('pre_set_site_transient_update_plugins', array(&$this, 'pre_check_update_custom'));
        add_filter('plugins_api', array(&$this, 'check_info'), 10, 3);


        $this->metaboxes = new MainWPMetaBoxes();

        MainWPMain::get(); //init main dashboard

        MainWPManageSites::init();
        new MainWPHooks(); //Init the hooks

        //Change menu & widgets
        add_action('admin_menu', array(&$this, 'new_menus'));

        //Change footer
        add_filter('update_footer', array(&$this, 'update_footer'), 15);

        //Add js
        add_action('admin_head', array(&$this, 'admin_head'));
        
        //Add body class
        add_action('admin_body_class', array(&$this, 'admin_body_class'));

        //Handle the bulkpost
        add_action('publish_bulkpost', array(&$this, 'publish_bulkpost'));
        add_action('save_post', array(&$this, 'save_bulkpost'));
        add_action('save_post', array(&$this, 'save_bulkpage'));
        add_action('add_meta_boxes_bulkpost', array('MainWPPost', 'addStickyOption'));

        //Handle the bulkpage
        add_action('publish_bulkpage', array(&$this, 'publish_bulkpage'));

        //Add meta boxes for the bulkpost
        add_action('admin_init', array(&$this, 'admin_init'));

        //Create the post types for bulkpost/...
        add_action('init', array(&$this, 'create_post_type'));
        add_action('init', array(&$this, 'parse_init'));
        add_action('init', array(&$this, 'init'), 9999);

        //Remove the pages from the menu which I use in AJAX
        add_action('admin_menu', array(&$this, 'admin_menu'));

        //Add custom error messages
        add_filter('post_updated_messages', array(&$this, 'post_updated_messages'));

        add_action('login_form', array(&$this, 'login_form'));

        add_filter('login_redirect', array(&$this, 'login_redirect'), 10, 3);

        add_action('admin_print_styles', array(&$this, 'admin_print_styles'));

        add_filter('admin_footer', array($this, 'admin_footer'));

        // AJAX functionality
        //MainWPAjax::Instance();

        MainWPInstallBulk::init();
    
        do_action('mainwp_cronload_action');      
        
        //Cron every 5 minutes
        add_action('mainwp_cronofflinecheck_action', array($this, 'mainwp_cronofflinecheck_action'));
        add_action('mainwp_cronstats_action', array($this, 'mainwp_cronstats_action'));
        add_action('mainwp_cronbackups_action', array($this, 'mainwp_cronbackups_action'));
        add_action('mainwp_cronbackups_continue_action', array($this, 'mainwp_cronbackups_continue_action'));
        add_action('mainwp_cronconflicts_action', array($this, 'mainwp_cronconflicts_action'));
        add_action('mainwp_cronupdatescheck_action', array($this, 'mainwp_cronupdatescheck_action'));
        add_action('mainwp_cronpingchilds_action', array($this, 'mainwp_cronpingchilds_action'));

        add_filter('cron_schedules', array('MainWPUtility', 'getCronSchedules'));

        $useWPCron = (get_option('mainwp_wp_cron') === false) || (get_option('mainwp_wp_cron') == 1);

        if (($sched = wp_next_scheduled('mainwp_cronofflinecheck_action')) == false)
        {
            if ($useWPCron) wp_schedule_event(time(), '5minutely', 'mainwp_cronofflinecheck_action');
        }
        else
        {
            if (!$useWPCron) wp_unschedule_event($sched, 'mainwp_cronofflinecheck_action');
        }

        if (($sched = wp_next_scheduled('mainwp_cronstats_action')) == false)
        {
            if ($useWPCron) wp_schedule_event(time(), 'hourly', 'mainwp_cronstats_action');
        }
        else
        {
            if (!$useWPCron) wp_unschedule_event($sched, 'mainwp_cronstats_action');
        }

        if (($sched = wp_next_scheduled('mainwp_cronbackups_action')) == false)
        {
            if ($useWPCron) wp_schedule_event(time(), 'hourly', 'mainwp_cronbackups_action');
        }
        else
        {
            if (!$useWPCron) wp_unschedule_event($sched, 'mainwp_cronbackups_action');
        }

        if (($sched = wp_next_scheduled('mainwp_cronbackups_continue_action')) == false)
        {
            if ($useWPCron) wp_schedule_event(time(), '5minutely', 'mainwp_cronbackups_continue_action');
        }
        else
        {
            if (!$useWPCron) wp_unschedule_event($sched, 'mainwp_cronbackups_continue_action');
        }

        if (($sched = wp_next_scheduled('mainwp_cronconflicts_action')) == false)
        {
            if ($useWPCron) wp_schedule_event(time(), 'twicedaily', 'mainwp_cronconflicts_action');
        }
        else
        {
            if (!$useWPCron) wp_unschedule_event($sched, 'mainwp_cronconflicts_action');
        }

        if (($sched = wp_next_scheduled('mainwp_cronremotedestinationcheck_action')) != false) {
            wp_unschedule_event($sched, 'mainwp_cronremotedestinationcheck_action');
        }

        if (($sched = wp_next_scheduled('mainwp_cronpingchilds_action')) == false)
        {
            if ($useWPCron) wp_schedule_event(time(), 'daily', 'mainwp_cronpingchilds_action');
        }
        else
        {
            if (!$useWPCron) wp_unschedule_event($sched, 'mainwp_cronpingchilds_action');
        }

        if (($sched = wp_next_scheduled('mainwp_cronupdatescheck_action')) == false)
        {
            if ($useWPCron) wp_schedule_event(time(), 'minutely', 'mainwp_cronupdatescheck_action');
        }
        else
        {
            if (!$useWPCron) wp_unschedule_event($sched, 'mainwp_cronupdatescheck_action');
        }

        add_action('plugin_action_links_' . $this->plugin_slug, array(&$this, 'plugin_action_links'));
        add_action('admin_notices', array(&$this, 'admin_notices'));

        add_filter('mainwp-activated-check', array(&$this, 'activated_check'));
        add_filter('mainwp-activated-sub-check', array(&$this, 'activated_sub_check'));
        add_filter('mainwp-extension-enabled-check', array(MainWPExtensions::getClassName(), 'isExtensionEnabled'));
        add_filter('mainwp-getsites', array(MainWPExtensions::getClassName(), 'hookGetSites'), 10, 4);
        add_filter('mainwp-getdbsites', array(MainWPExtensions::getClassName(), 'hookGetDBSites'), 10, 5);
        add_filter('mainwp-getgroups', array(MainWPExtensions::getClassName(), 'hookGetGroups'), 10, 4);
        add_action('mainwp_fetchurlsauthed', array(&$this, 'filter_fetchUrlsAuthed'), 10, 7);
        add_filter('mainwp_fetchurlauthed', array(&$this, 'filter_fetchUrlAuthed'), 10, 5);
        add_filter('mainwp_getdashboardsites', array(MainWPExtensions::getClassName(), 'hookGetDashboardSites'), 10, 7);
        add_filter('mainwp-manager-getextensions', array(MainWPExtensions::getClassName(), 'hookManagerGetExtensions'));        
        
        $this->posthandler = new MainWPPostHandler();

        do_action('mainwp-activated');

        MainWPPost::init();
        MainWPSettings::init();
        MainWPManageBackups::init();
        MainWPUser::init();
        MainWPPage::init();
        MainWPThemes::init();
        MainWPPlugins::init();
        MainWPRightNow::init();
    }

    function filter_fetchUrlsAuthed($pluginFile, $key, $dbwebsites, $what, $params, $handle, $output)
    {
        return MainWPExtensions::hookFetchUrlsAuthed($pluginFile, $key, $dbwebsites, $what, $params, $handle, $output);
    }

    function filter_fetchUrlAuthed($pluginFile, $key, $websiteId, $what, $params)
    {
       return MainWPExtensions::hookFetchUrlAuthed($pluginFile, $key, $websiteId, $what, $params);
    }

    public function in_plugin_update_message($plugin_data, $r)
    {
        if (empty($r) || !is_object($r)) return;        

        if (property_exists($r, 'key_status') && $r->key_status == 'NOK') echo '<br />Your Log-in and Password are invalid, please update your login settings <a href="'.admin_url('admin.php?page=Settings').'">here</a>.';
        else if (property_exists($r, 'package') && empty($r->package)) echo '<br />Your update license has expired, please log into <a href="https://mainwp.com/member">the members area</a> and upgrade your support and update license.';
    }

    public function localization()
    {
        load_plugin_textdomain('mainwp', false, dirname(dirname(plugin_basename(__FILE__))) . '/languages/');
    }

    public function activated_sub_check($arr)
    {
        if (!is_array($arr)) return $arr;
        if (count($arr) != 1) return $arr;
        $rslt = array('result' => MainWPAPISettings::testAPIs($arr[0]));
        return $rslt;
    }

    public function activated_check()
    {
        return $this->getVersion();
    }

    public function admin_notices()
    {
        if (get_option('mainwp_refresh'))
        {
            echo '<meta http-equiv="refresh" content="0">';
            delete_option('mainwp_refresh');
        }

        echo '<div id="message" class="mainwp-api-message-invalid updated fade" style="' . (true || $this->isAPIValid() ? 'display: none;' : '') . '"><p><strong>MainWP needs to be activated before using - <a href="' . admin_url() . 'admin.php?page=Settings">Activate Here</a>.</strong></p></div>';

        if (MainWPDB::Instance()->getWebsitesCount() == 0) {
            echo '<div id="message" class="mainwp-api-message-valid updated fade"><p><strong>MainWP is almost ready. Please <a href="' . admin_url() . 'admin.php?page=managesites&do=new">enter your first site</a>.</strong></p></div>';            
            update_option('mainwp_first_site_events_notice', 'yes');
        } else {
            if (get_option('mainwp_first_site_events_notice') == 'yes') { 
                ?>
                <div id="mainwp-events-notice" class="updated fade">
                	<p>
                    	<span style="float: right;" ><a id="mainwp-events-notice-dismiss" style="text-decoration: none;" href="#"><?php _e('Dismiss','mainwp'); ?></a></span><span><strong><?php _e('Warning: Your setup is almost complete we recommend following the directions in the following help doc to be sure your scheduled events occur as expected <a href="http://docs.mainwp.com/backups-scheduled-events-occurring/">Scheduled Events</a>'); ?></strong></span>
                    	</p>
                </div>                    
                <?php
            }
        }
        
    }

    public function getVersion()
    {
        return $this->current_version;
    }


    public function check_update_custom($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        if (isset($_GET['do']) && $_GET['do'] == 'checkUpgrade' && ((time() - $this->upgradeVersionInfo->updated) > 30)) {
            $this->checkUpgrade();
        }

        if ($this->upgradeVersionInfo != null)
        {
            foreach ($this->upgradeVersionInfo->result as $rslt)
            {
                if (!isset($rslt->slug)) continue; //Legacy, to support older versions.

                $plugin_slug = MainWPExtensions::getPluginSlug($rslt->slug);
                if (isset($transient->checked[$plugin_slug]) && version_compare($rslt->latest_version, $transient->checked[$plugin_slug], '>'))
                {
                    $obj = new stdClass();
                    $obj->slug = $rslt->slug;
                    $obj->new_version = $rslt->latest_version;
                    $obj->url = 'http://mainwp.com/';
                    $obj->package = $rslt->download_url;
                    $obj->key_status = $rslt->key_status;
                    $transient->response[$plugin_slug] = $obj;
                }
            }
        }

        return $transient;
    }

    private function checkUpgrade()
    {
        $result = MainWPAPISettings::checkUpgrade();
        if ($result == null) return;        

        $this->upgradeVersionInfo->result = $result;
        $this->upgradeVersionInfo->updated = time();
        MainWPUtility::update_option("mainwp_upgradeVersionInfo", serialize($this->upgradeVersionInfo));
    }

    public function pre_check_update_custom($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        if (($this->upgradeVersionInfo == null) || ((time() - $this->upgradeVersionInfo->updated) > 60 * 60 * 12)) {
            $this->checkUpgrade();
        }

        if ($this->upgradeVersionInfo != null)
        {
            foreach ($this->upgradeVersionInfo->result as $rslt)
            {
                $plugin_slug = MainWPExtensions::getPluginSlug($rslt->slug);
                if (isset($transient->checked[$plugin_slug]) && version_compare($rslt->latest_version, $transient->checked[$plugin_slug], '>'))
                {
                    $obj = new stdClass();
                    $obj->slug = $rslt->slug;
                    $obj->new_version = $rslt->latest_version;
                    $obj->url = 'http://mainwp.com/';
                    $obj->package = $rslt->download_url;
                    $obj->key_status = $rslt->key_status;
                    $transient->response[$plugin_slug] = $obj;
                }
            }
        }

        return $transient;
    }

    public function check_info($false, $action, $arg)
    {
        if (!isset($arg->slug) || ($arg->slug == '')) return false;

        if ($arg->slug === $this->slug)
        {
            return false;
        }

        //$slugs = MainWPExtensions::getSlugs();
        $result = MainWPExtensions::getSlugsTwo();
        $slugs = $result['slugs'];
        $am_slugs = $result['am_slugs'];
        
        if ($slugs != '')
        {           
            $slugs = explode(',', $slugs);
            if (in_array($arg->slug, $slugs)) return MainWPAPISettings::getUpgradeInformation($arg->slug);
        }
        
        if ($am_slugs != '')
        {   
            $am_slugs = explode(',', $am_slugs);            
            if (in_array($arg->slug, $am_slugs)) return MainWPAPISettings::getPluginInformation($arg->slug);
        }
        
        return false;
    }

    public function plugin_action_links($val)
    {
        $val[] = '<a href="' . admin_url() . 'admin.php?page=Settings">Licenses</a>';
        return $val;
    }

    function mainwp_cronofflinecheck_action()
    {
        MainWPUtility::update_option('mainwp_cron_last_offlinecheck', time());
        //Do cronjobs!
        //Config this in crontab: 0 0 * * * wget -q http://mainwp.com/wp-admin/?do=checkSites -O /dev/null 2>&1
        //this will execute once every day to check if websites are offline
        MainWPOfflineChecks::performAllChecks();
        die();
    }

    function print_updates_array_lines($array, $backupChecks)
    {
        $output = '';
        foreach ($array as $line)
        {
            $siteId = $line[0];
            $text = $line[1];
            $trustedText = $line[2];

            $output .= '<li>' . $text . $trustedText . ($backupChecks == null || !isset($backupChecks[$siteId]) || ($backupChecks[$siteId] == true) ? '' : '(Requires manual backup)') . '</li>'."\n";
        }
        return $output;
    }

    function mainwp_cronupdatescheck_action()
    {
        @ignore_user_abort(true);
        @set_time_limit(0);
        $mem =  '512M';
        @ini_set('memory_limit', $mem);
        @ini_set('max_execution_time', 0);

        MainWPUtility::update_option('mainwp_cron_last_updatescheck', time());

        $mainwpAutomaticDailyUpdate = get_option('mainwp_automaticDailyUpdate');

        $mainwpLastAutomaticUpdate = get_option('mainwp_updatescheck_last');
        if ($mainwpLastAutomaticUpdate == date('d/m/Y')) return;

        $websites = MainWPDB::Instance()->getWebsitesCheckUpdates(4);

        foreach ($websites as $website)
        {
            $websiteValues = array(
                'dtsAutomaticSyncStart' => time()
            );

            MainWPDB::Instance()->updateWebsiteValues($website->id, $websiteValues);
        }

        if (count($websites) == 0)
        {
            $busyCounter = MainWPDB::Instance()->getWebsitesCountWhereDtsAutomaticSyncSmallerThenStart();

            if ($busyCounter == 0)
            {
                //Send the email & update all to this time!
                $mail = '';
                $sendMail = false;

                $sitesCheckCompleted = null;
                if (get_option('mainwp_backup_before_upgrade') == 1)
                {
                    $sitesCheckCompleted = get_option('mainwp_automaticUpdate_backupChecks');
                    if (!is_array($sitesCheckCompleted)) $sitesCheckCompleted = null;
                }


                $pluginsNewUpdate = get_option('mainwp_updatescheck_mail_update_plugins_new');
                if (!is_array($pluginsNewUpdate)) $pluginsNewUpdate = array();
                $pluginsToUpdate = get_option('mainwp_updatescheck_mail_update_plugins');
                if (!is_array($pluginsToUpdate)) $pluginsToUpdate = array();
                $ignoredPluginsNewUpdate = get_option('mainwp_updatescheck_mail_ignore_plugins_new');
                if (!is_array($ignoredPluginsNewUpdate)) $ignoredPluginsNewUpdate = array();
                $ignoredPluginsToUpdate = get_option('mainwp_updatescheck_mail_ignore_plugins');
                if (!is_array($ignoredPluginsToUpdate)) $ignoredPluginsToUpdate = array();

                if ((count($pluginsNewUpdate) != 0) || (count($pluginsToUpdate) != 0) || (count($ignoredPluginsNewUpdate) != 0) || (count($ignoredPluginsToUpdate) != 0))
                {
                    $sendMail = true;

                    $mail .= '<div><strong>WordPress Plugin Updates</strong></div>';
                    $mail .= '<ul>';
                    $mail .= $this->print_updates_array_lines($pluginsNewUpdate, null);
                    $mail .= $this->print_updates_array_lines($pluginsToUpdate, $sitesCheckCompleted);
                    $mail .= $this->print_updates_array_lines($ignoredPluginsNewUpdate, null);
                    $mail .= $this->print_updates_array_lines($ignoredPluginsToUpdate, null);
                    $mail .= '</ul>';
                }

                $themesNewUpdate = get_option('mainwp_updatescheck_mail_update_themes_new');
                if (!is_array($themesNewUpdate)) $themesNewUpdate = array();
                $themesToUpdate = get_option('mainwp_updatescheck_mail_update_themes');
                if (!is_array($themesToUpdate)) $themesToUpdate = array();
                $ignoredThemesNewUpdate = get_option('mainwp_updatescheck_mail_ignore_themes_new');
                if (!is_array($ignoredThemesNewUpdate)) $ignoredThemesNewUpdate = array();
                $ignoredThemesToUpdate = get_option('mainwp_updatescheck_mail_ignore_themes');
                if (!is_array($ignoredThemesToUpdate)) $ignoredThemesToUpdate = array();

                if ((count($themesNewUpdate) != 0) || (count($themesToUpdate) != 0) || (count($ignoredThemesNewUpdate) != 0) || (count($ignoredThemesToUpdate) != 0))
                {
                    $sendMail = true;

                    $mail .= '<div><strong>WordPress Themes Updates</strong></div>';
                    $mail .= '<ul>';
                    $mail .= $this->print_updates_array_lines($themesNewUpdate, null);
                    $mail .= $this->print_updates_array_lines($themesToUpdate, $sitesCheckCompleted);
                    $mail .= $this->print_updates_array_lines($ignoredThemesNewUpdate, null);
                    $mail .= $this->print_updates_array_lines($ignoredThemesToUpdate, null);
                    $mail .= '</ul>';
                }

                $coreNewUpdate = get_option('mainwp_updatescheck_mail_update_core_new');
                if (!is_array($coreNewUpdate)) $coreNewUpdate = array();
                $coreToUpdate = get_option('mainwp_updatescheck_mail_update_core');
                if (!is_array($coreToUpdate)) $coreToUpdate = array();
                $ignoredCoreNewUpdate = get_option('mainwp_updatescheck_mail_ignore_core_new');
                if (!is_array($ignoredCoreNewUpdate)) $ignoredCoreNewUpdate = array();
                $ignoredCoreToUpdate = get_option('mainwp_updatescheck_mail_ignore_core');
                if (!is_array($ignoredCoreToUpdate)) $ignoredCoreToUpdate = array();

                if ((count($coreNewUpdate) != 0) || (count($coreToUpdate) != 0) || (count($ignoredCoreNewUpdate) != 0) || (count($ignoredCoreToUpdate) != 0))
                {
                    $sendMail = true;

                    $mail .= '<div><strong>WordPress Core Updates</strong></div>';
                    $mail .= '<ul>';
                    $mail .= $this->print_updates_array_lines($coreNewUpdate, null);
                    $mail .= $this->print_updates_array_lines($coreToUpdate, $sitesCheckCompleted);
                    $mail .= $this->print_updates_array_lines($ignoredCoreNewUpdate, null);
                    $mail .= $this->print_updates_array_lines($ignoredCoreToUpdate, null);
                    $mail .= '</ul>';
                }

                $pluginConflicts = get_option('mainwp_updatescheck_mail_pluginconflicts');
                if ($pluginConflicts === false) $pluginConflicts = '';

                if ($pluginConflicts != '')
                {
                    $sendMail = true;
                    $mail .= '<div><strong>WordPress Plugin Conflicts</strong></div>';
                    $mail .= '<ul>';
                    $mail .= $pluginConflicts;
                    $mail .= '</ul>';
                }

                $themeConflicts = get_option('mainwp_updatescheck_mail_themeconflicts');
                if ($themeConflicts === false) $themeConflicts = '';

                if ($themeConflicts != '')
                {
                    $sendMail = true;
                    $mail .= '<div><strong>WordPress Theme Conflicts</strong></div>';
                    $mail .= '<ul>';
                    $mail .= $themeConflicts;
                    $mail .= '</ul>';
                }

                MainWPUtility::update_option('mainwp_automaticUpdate_backupChecks', '');

                MainWPUtility::update_option('mainwp_updatescheck_mail_update_core_new', '');
                MainWPUtility::update_option('mainwp_updatescheck_mail_update_plugins_new', '');
                MainWPUtility::update_option('mainwp_updatescheck_mail_update_themes_new', '');

                MainWPUtility::update_option('mainwp_updatescheck_mail_update_core', '');
                MainWPUtility::update_option('mainwp_updatescheck_mail_update_plugins', '');
                MainWPUtility::update_option('mainwp_updatescheck_mail_update_themes', '');

                MainWPUtility::update_option('mainwp_updatescheck_mail_ignore_core', '');
                MainWPUtility::update_option('mainwp_updatescheck_mail_ignore_plugins', '');
                MainWPUtility::update_option('mainwp_updatescheck_mail_ignore_themes', '');

                MainWPUtility::update_option('mainwp_updatescheck_mail_ignore_core_new', '');
                MainWPUtility::update_option('mainwp_updatescheck_mail_ignore_plugins_new', '');
                MainWPUtility::update_option('mainwp_updatescheck_mail_ignore_themes_new', '');

                MainWPUtility::update_option('mainwp_updatescheck_mail_pluginconflicts', '');

                MainWPUtility::update_option('mainwp_updatescheck_mail_themeconflicts', '');

                if (!$sendMail) return;

                MainWPUtility::update_option('mainwp_updatescheck_last', date('d/m/Y'));

                if ($mainwpAutomaticDailyUpdate !== false && $mainwpAutomaticDailyUpdate != 0)
                {
                    //Create a nice email to send
                    //todo: RS: make this email global, not per user, or per user & allow better support for this
                    $email = get_option('mainwp_updatescheck_mail_email');
                    if ($email != false && $email != '') {
                        $mail = '<div>We noticed the following updates are available on your MainWP Dashboard. (<a href="'.site_url().'">'.site_url().'</a>)</div>
                                 <div></div>
                                 ' . $mail.'
                                 Update Key: (<strong><span style="color:#008000">Trusted</span></strong>) will be auto updated within 24 hours. (<strong><span style="color:#ff0000">Not Trusted</span></strong>) you will need to log into your Main Dashboard and update
                                 <div> </div>
                                 <div>If your MainWP is configured to use Auto Updates these upgrades will be installed in the next 24 hours. To find out how to enable automatic updates please see the FAQs below.</div>
                                 <div><a href="http://docs.mainwp.com/marking-a-plugin-as-trusted/" style="color:#446200" target="_blank">http://docs.mainwp.com/marking-a-plugin-as-trusted/</a></div>
                                 <div><a href="http://docs.mainwp.com/marking-a-theme-as-trusted/" style="color:#446200" target="_blank">http://docs.mainwp.com/marking-a-theme-as-trusted/</a></div>
                                 <div><a href="http://docs.mainwp.com/marking-a-sites-wp-core-updates-as-trusted/" style="color:#446200" target="_blank">http://docs.mainwp.com/marking-a-sites-wp-core-updates-as-trusted/</a></div>';
                        wp_mail($email, 'MainWP - Trusted Updates', MainWPUtility::formatEmail($email, $mail), array('From: "'.get_option('admin_email').'" <'.get_option('admin_email').'>', 'content-type: text/html'));
                    }
                }
            }
        }
        else
        {
            $userExtension = MainWPDB::Instance()->getUserExtensionByUserId($website->userid);

            $decodedIgnoredPlugins = json_decode($userExtension->ignored_plugins, true);
            if (!is_array($decodedIgnoredPlugins)) $decodedIgnoredPlugins = array();

            $trustedPlugins = json_decode($userExtension->trusted_plugins, true);
            if (!is_array($trustedPlugins)) $trustedPlugins = array();

            $decodedIgnoredThemes = json_decode($userExtension->ignored_themes, true);
            if (!is_array($decodedIgnoredThemes)) $decodedIgnoredThemes = array();

            $trustedThemes = json_decode($userExtension->trusted_themes, true);
            if (!is_array($trustedThemes)) $trustedThemes = array();

            $coreToUpdateNow = array();
            $coreToUpdate = array();
            $coreNewUpdate = array();
            $ignoredCoreToUpdate = array();
            $ignoredCoreNewUpdate = array();

            $pluginsToUpdateNow = array();
            $pluginsToUpdate = array();
            $pluginsNewUpdate = array();
            $ignoredPluginsToUpdate = array();
            $ignoredPluginsNewUpdate = array();

            $themesToUpdateNow = array();
            $themesToUpdate = array();
            $themesNewUpdate = array();
            $ignoredThemesToUpdate = array();
            $ignoredThemesNewUpdate = array();

            $pluginConflicts = '';
            $themeConflicts = '';
            $savedPluginConflicts = get_option('mainwp_pluginConflicts');
            $savedThemeConflicts = get_option('mainwp_themeConflicts');

            $allWebsites = array();

            $infoTrustedText = ' (<span style="color:#008000"><strong>Trusted</strong></span>)';
            $infoNotTrustedText = ' (<strong><span style="color:#ff0000">Not Trusted</span></strong>)';

            foreach ($websites as $website)
            {
                $websiteDecodedIgnoredPlugins = json_decode($website->ignored_plugins, true);
                if (!is_array($websiteDecodedIgnoredPlugins)) $websiteDecodedIgnoredPlugins = array();

                $websiteDecodedIgnoredThemes = json_decode($website->ignored_themes, true);
                if (!is_array($websiteDecodedIgnoredThemes)) $websiteDecodedIgnoredThemes = array();

                //Perform check & update
                if (!MainWPSync::syncSite($website))
                {
                    $websiteValues = array(
                        'dtsAutomaticSync' => time()
                    );

                    MainWPDB::Instance()->updateWebsiteValues($website->id, $websiteValues);

                    continue;
                }
                $website = MainWPDB::Instance()->getWebsiteById($website->id);

                /** Check core upgrades **/
                $websiteLastCoreUpgrades = json_decode($website->last_wp_upgrades, true);
                $websiteCoreUpgrades = json_decode($website->wp_upgrades, true);

                //Run over every update we had last time..
                if (isset($websiteCoreUpgrades['current']))
                {
                    $infoTxt = '<a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . $website->name . '</a> - ' . $websiteCoreUpgrades['current'] . ' to ' . $websiteCoreUpgrades['new'];
                    $infoNewTxt = '*NEW* <a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . $website->name . '</a> - ' . $websiteCoreUpgrades['current'] . ' to ' . $websiteCoreUpgrades['new'];
                    $newUpdate = !(isset($websiteLastCoreUpgrades['current']) && ($websiteLastCoreUpgrades['current'] == $websiteCoreUpgrades['current']) && ($websiteLastCoreUpgrades['new'] == $websiteCoreUpgrades['new']));
                    if ($website->automatic_update == 1)
                    {
                        if ($newUpdate)
                        {
                            $coreNewUpdate[] = array($website->id, $infoNewTxt, $infoTrustedText);
                        }
                        else
                        {
                            //Check ignore ? $ignoredCoreToUpdate
                            $coreToUpdateNow[] = $website->id;
                            $allWebsites[$website->id] = $website;
                            $coreToUpdate[] = array($website->id, $infoTxt, $infoTrustedText);
                        }
                    }
                    else
                    {
                        if ($newUpdate)
                        {
                            $ignoredCoreNewUpdate[] = array($website->id, $infoNewTxt, $infoNotTrustedText);
                        }
                        else
                        {
                            $ignoredCoreToUpdate[] = array($website->id, $infoTxt, $infoNotTrustedText);
                        }
                    }
                }

                /** Check plugins **/
                $websiteLastPlugins = json_decode($website->last_plugin_upgrades, true);
                $websitePlugins = json_decode($website->plugin_upgrades, true);

                /** Check themes **/
                $websiteLastThemes = json_decode($website->last_theme_upgrades, true);
                $websiteThemes = json_decode($website->theme_upgrades, true);

                $decodedPremiumUpgrades = json_decode($website->premium_upgrades, true);
                if (is_array($decodedPremiumUpgrades))
                {
                    foreach ($decodedPremiumUpgrades as $slug => $premiumUpgrade)
                    {
                        if ($premiumUpgrade['type'] == 'plugin')
                        {
                            if (!is_array($websitePlugins)) $websitePlugins = array();
                            $websitePlugins[$slug] = $premiumUpgrade;
                        }
                        else if ($premiumUpgrade['type'] == 'theme')
                        {
                            if (!is_array($websiteThemes)) $websiteThemes = array();
                            $websiteThemes[$slug] = $premiumUpgrade;
                        }
                    }
                }


                //Run over every update we had last time..
                foreach ($websitePlugins as $pluginSlug => $pluginInfo)
                {
                    $infoTxt = '<a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . $website->name . '</a> - ' . $pluginInfo['Name'] . ' ' . $pluginInfo['Version'] . ' to ' . $pluginInfo['update']['new_version'];
                    $infoNewTxt = '*NEW* <a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . $website->name . '</a> - ' . $pluginInfo['Name'] . ' ' . $pluginInfo['Version'] . ' to ' . $pluginInfo['update']['new_version'];

                    $newUpdate = !(isset($websiteLastPlugins[$pluginSlug]) && ($pluginInfo['Version'] == $websiteLastPlugins[$pluginSlug]['Version']) && ($pluginInfo['update']['new_version'] == $websiteLastPlugins[$pluginSlug]['update']['new_version']));
                    //update this..
                    if (in_array($pluginSlug, $trustedPlugins) && !isset($decodedIgnoredPlugins[$pluginSlug]) && !isset($websiteDecodedIgnoredPlugins[$pluginSlug]))
                    {
                        //Trusted
                        if ($newUpdate)
                        {
                            $pluginsNewUpdate[] = array($website->id, $infoNewTxt, $infoTrustedText);
                        }
                        else
                        {
                            $pluginsToUpdateNow[$website->id][] = $pluginSlug;
                            $allWebsites[$website->id] = $website;
                            $pluginsToUpdate[] = array($website->id, $infoTxt, $infoTrustedText);
                        }
                    }
                    else
                    {
                        //Not trusted
                        if ($newUpdate)
                        {
                            $ignoredPluginsNewUpdate[] = array($website->id, $infoNewTxt, $infoNotTrustedText);
                        }
                        else
                        {
                            $ignoredPluginsToUpdate[] = array($website->id, $infoTxt, $infoNotTrustedText);
                        }
                    }
                }

                //Run over every update we had last time..
                foreach ($websiteThemes as $themeSlug => $themeInfo)
                {
                    $infoTxt = '<a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . $website->name . '</a> - ' . $themeInfo['Name'] . ' ' . $themeInfo['Version'] . ' to ' . $themeInfo['update']['new_version'];
                    $infoNewTxt = '*NEW* <a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . $website->name . '</a> - ' . $themeInfo['Name'] . ' ' . $themeInfo['Version'] . ' to ' . $themeInfo['update']['new_version'];

                    $newUpdate = !(isset($websiteLastThemes[$themeSlug]) && ($themeInfo['Version'] == $websiteLastThemes[$themeSlug]['Version']) && ($themeInfo['update']['new_version'] == $websiteLastThemes[$themeSlug]['update']['new_version']));
                    //update this..
                    if (in_array($themeSlug, $trustedThemes) && !isset($decodedIgnoredThemes[$themeSlug]) && !isset($websiteDecodedIgnoredThemes[$themeSlug]))
                    {
                        //Trusted
                        if ($newUpdate)
                        {
                            $themesNewUpdate[] = array($website->id, $infoNewTxt, $infoTrustedText);
                        }
                        else
                        {
                            $themesToUpdateNow[$website->id][] = $themeSlug;
                            $allWebsites[$website->id] = $website;
                            $themesToUpdate[] = array($website->id, $infoTxt, $infoTrustedText);
                        }
                    }
                    else
                    {
                        //Not trusted
                        if ($newUpdate)
                        {
                            $ignoredThemesNewUpdate[] = array($website->id, $infoNewTxt, $infoNotTrustedText);
                        }
                        else
                        {
                            $ignoredThemesToUpdate[] = array($website->id, $infoTxt, $infoNotTrustedText);
                        }
                    }
                }

                /**
                 * Show plugin conflicts
                 */
                $sitePluginConflicts = json_decode($website->pluginConflicts, true);
                if (count($sitePluginConflicts) > 0)
                {
                    $infoTxt = '<a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . $website->name . '</a> - ';

                    $pluginConflicts .= '<li>' . $infoTxt;
                    $added = false;
                    foreach ($sitePluginConflicts as $sitePluginConflict)
                    {
                        if ($added) $pluginConflicts .= ', ';
                        $pluginConflicts .= $sitePluginConflict;
                        $added = true;
                    }
                    $pluginConflicts .= '</li>' . "\n";
                }

                /**
                 * Show theme conflicts
                 */
                $siteThemeConflicts = json_decode($website->themeConflicts, true);
                if (count($siteThemeConflicts) > 0)
                {
                    $infoTxt = '<a href="' . admin_url('admin.php?page=managesites&dashboard=' . $website->id) . '">' . $website->name . '</a> - ';

                    $themeConflicts .= '<li>' . $infoTxt;
                    $added = false;
                    foreach ($siteThemeConflicts as $siteThemeConflict)
                    {
                        if ($added) $themeConflicts .= ', ';
                        $themeConflicts .= $siteThemeConflict;
                        $added = true;
                    }
                    $themeConflicts .= '</li>' . "\n";
                }

                //Loop over last plugins & current plugins, check if we need to upgrade them..
                $websiteValues = array(
                    'dtsAutomaticSync' => time(),
                    'last_plugin_upgrades' => $website->plugin_upgrades,
                    'last_theme_upgrades' => $website->theme_upgrades,
                    'last_wp_upgrades' => $website->wp_upgrades
                );

                $user = get_userdata($website->userid);
                $email = MainWPUtility::getNotificationEmail($user);
                MainWPUtility::update_option('mainwp_updatescheck_mail_email', $email);
                MainWPDB::Instance()->updateWebsiteValues($website->id, $websiteValues);
            }

            if (count($coreNewUpdate) != 0)
            {
                $coreNewUpdateSaved = get_option('mainwp_updatescheck_mail_update_core_new');
                MainWPUtility::update_option('mainwp_updatescheck_mail_update_core_new', MainWPUtility::array_merge($coreNewUpdateSaved, $coreNewUpdate));
            }

            if (count($pluginsNewUpdate) != 0)
            {
                $pluginsNewUpdateSaved = get_option('mainwp_updatescheck_mail_update_plugins_new');
                MainWPUtility::update_option('mainwp_updatescheck_mail_update_plugins_new', MainWPUtility::array_merge($pluginsNewUpdateSaved, $pluginsNewUpdate));
            }

            if (count($themesNewUpdate) != 0)
            {
                $themesNewUpdateSaved = get_option('mainwp_updatescheck_mail_update_themes_new');
                MainWPUtility::update_option('mainwp_updatescheck_mail_update_themes_new', MainWPUtility::array_merge($themesNewUpdateSaved, $themesNewUpdate));
            }

            if (count($coreToUpdate) != 0)
            {
                $coreToUpdateSaved = get_option('mainwp_updatescheck_mail_update_core');
                MainWPUtility::update_option('mainwp_updatescheck_mail_update_core', MainWPUtility::array_merge($coreToUpdateSaved, $coreToUpdate));
            }

            if (count($pluginsToUpdate) != 0)
            {
                $pluginsToUpdateSaved = get_option('mainwp_updatescheck_mail_update_plugins');
                MainWPUtility::update_option('mainwp_updatescheck_mail_update_plugins', MainWPUtility::array_merge($pluginsToUpdateSaved, $pluginsToUpdate));
            }

            if (count($themesToUpdate) != 0)
            {
                $themesToUpdateSaved = get_option('mainwp_updatescheck_mail_update_themes');
                MainWPUtility::update_option('mainwp_updatescheck_mail_update_themes', MainWPUtility::array_merge($themesToUpdateSaved, $themesToUpdate));
            }

            if (count($ignoredCoreToUpdate) != 0)
            {
                $ignoredCoreToUpdateSaved = get_option('mainwp_updatescheck_mail_ignore_core');
                MainWPUtility::update_option('mainwp_updatescheck_mail_ignore_core', MainWPUtility::array_merge($ignoredCoreToUpdateSaved, $ignoredCoreToUpdate));
            }

            if (count($ignoredCoreNewUpdate) != 0)
            {
                $ignoredCoreNewUpdateSaved = get_option('mainwp_updatescheck_mail_ignore_core_new');
                MainWPUtility::update_option('mainwp_updatescheck_mail_ignore_core_new', MainWPUtility::array_merge($ignoredCoreNewUpdateSaved, $ignoredCoreNewUpdate));
            }

            if (count($ignoredPluginsToUpdate) != 0)
            {
                $ignoredPluginsToUpdateSaved = get_option('mainwp_updatescheck_mail_ignore_plugins');
                MainWPUtility::update_option('mainwp_updatescheck_mail_ignore_plugins', MainWPUtility::array_merge($ignoredPluginsToUpdateSaved, $ignoredPluginsToUpdate));
            }

            if (count($ignoredPluginsNewUpdate) != 0)
            {
                $ignoredPluginsNewUpdateSaved = get_option('mainwp_updatescheck_mail_ignore_plugins_new');
                MainWPUtility::update_option('mainwp_updatescheck_mail_ignore_plugins_new', MainWPUtility::array_merge($ignoredPluginsNewUpdateSaved, $ignoredPluginsNewUpdate));
            }

            if (count($ignoredThemesToUpdate) != 0)
            {
                $ignoredThemesToUpdateSaved = get_option('mainwp_updatescheck_mail_ignore_themes');
                MainWPUtility::update_option('mainwp_updatescheck_mail_ignore_themes', MainWPUtility::array_merge($ignoredThemesToUpdateSaved, $ignoredThemesToUpdate));
            }

            if (count($ignoredThemesNewUpdate) != 0)
            {
                $ignoredThemesNewUpdateSaved = get_option('mainwp_updatescheck_mail_ignore_themes_new');
                MainWPUtility::update_option('mainwp_updatescheck_mail_ignore_themes_new', MainWPUtility::array_merge($ignoredThemesNewUpdateSaved, $ignoredThemesNewUpdate));
            }

            if ($pluginConflicts != '')
            {
                $pluginConflictsSaved = get_option('mainwp_updatescheck_mail_pluginconflicts');
                if ($pluginConflictsSaved == false) $pluginConflictsSaved = '';
                MainWPUtility::update_option('mainwp_updatescheck_mail_pluginconflicts', $pluginConflictsSaved . $pluginConflicts);
            }

            if ($themeConflicts != '')
            {
                $themeConflictsSaved = get_option('mainwp_updatescheck_mail_themeconflicts');
                if ($themeConflictsSaved == false) $themeConflictsSaved = '';
                MainWPUtility::update_option('mainwp_updatescheck_mail_themeconflicts', $themeConflictsSaved . $themeConflicts);
            }

            if ((count($coreToUpdate) == 0) && (count($pluginsToUpdate) == 0) && (count($themesToUpdate) == 0) && (count($ignoredCoreToUpdate) == 0)  && (count($ignoredCoreNewUpdate) == 0) && (count($ignoredPluginsToUpdate) == 0) && (count($ignoredPluginsNewUpdate) == 0) && (count($ignoredThemesToUpdate) == 0) && (count($ignoredThemesNewUpdate) == 0) && ($pluginConflicts == '') && ($themeConflicts == ''))
            {
                return;
            }

            if (get_option('mainwp_automaticDailyUpdate') != 1) return;


            //Check if backups are required!
            if (get_option('mainwp_backup_before_upgrade') == 1)
            {
                $sitesCheckCompleted = get_option('mainwp_automaticUpdate_backupChecks');
                if (!is_array($sitesCheckCompleted)) $sitesCheckCompleted = array();

                $websitesToCheck = array();
                foreach ($pluginsToUpdateNow as $websiteId => $slugs)
                {
                    $websitesToCheck[$websiteId] = true;
                }

                foreach ($themesToUpdateNow as $websiteId => $slugs)
                {
                    $websitesToCheck[$websiteId] = true;
                }

                foreach ($coreToUpdateNow as $websiteId)
                {
                    $websitesToCheck[$websiteId] = true;
                }

                foreach ($websitesToCheck as $siteId => $bool)
                {
                    if ($allWebsites[$siteId]->backup_before_upgrade == 0)
                    {
                        $sitesCheckCompleted[$siteId] = true;
                    }
                    if (isset($sitesCheckCompleted[$siteId])) continue;

                    $dir = MainWPUtility::getMainWPSpecificDir($siteId);
                    //Check if backup ok
                    $lastBackup = -1;
                    if (file_exists($dir) && ($dh = opendir($dir)))
                    {
                        while (($file = readdir($dh)) !== false)
                        {
                            if ($file != '.' && $file != '..')
                            {
                                $theFile = $dir . $file;
                                if (MainWPUtility::isArchive($file) && !MainWPUtility::isSQLArchive($file) && (filemtime($theFile) > $lastBackup))
                                {
                                    $lastBackup = filemtime($theFile);
                                }
                            }
                        }
                        closedir($dh);
                    }

                    $backupRequired = ($lastBackup < (time() - (7 * 24 * 60 * 60)) ? true : false);

                    if (!$backupRequired)
                    {
                        $sitesCheckCompleted[$siteId] = true;
                        MainWPUtility::update_option('mainwp_automaticUpdate_backupChecks', $sitesCheckCompleted);
                        continue;
                    }

                    try
                    {
                        $result = MainWPManageSites::backup($siteId, 'full', '', '', 0, 0, 0, 0);
                        MainWPManageSites::backupDownloadFile($siteId, 'full', $result['url'], $result['local']);
                        $sitesCheckCompleted[$siteId] = true;
                        MainWPUtility::update_option('mainwp_automaticUpdate_backupChecks', $sitesCheckCompleted);
                    }
                    catch (Exception $e)
                    {
                        $sitesCheckCompleted[$siteId] = false;
                        MainWPUtility::update_option('mainwp_automaticUpdate_backupChecks', $sitesCheckCompleted);
                    }
                }
            }
            else
            {
                $sitesCheckCompleted = null;
            }


            //Update plugins
            foreach ($pluginsToUpdateNow as $websiteId => $slugs)
            {
                if (($sitesCheckCompleted != null) && ($sitesCheckCompleted[$websiteId] == false)) continue;

                try
                {
                    MainWPUtility::fetchUrlAuthed($allWebsites[$websiteId], 'upgradeplugintheme', array(
                        'type' => 'plugin',
                        'list' => urldecode(implode(',', $slugs))
                    ));

                    if (isset($information['sync'])) MainWPSync::syncInformationArray($allWebsites[$websiteId], $information['sync']);
                }
                catch (Exception $e)
                {
                }
            }

            //Update themes
            foreach ($themesToUpdateNow as $websiteId => $slugs)
            {
                if (($sitesCheckCompleted != null) && ($sitesCheckCompleted[$websiteId] == false)) continue;

                try
                {
                    MainWPUtility::fetchUrlAuthed($allWebsites[$websiteId], 'upgradeplugintheme', array(
                        'type' => 'theme',
                        'list' => urldecode(implode(',', $slugs))
                    ));

                    if (isset($information['sync'])) MainWPSync::syncInformationArray($allWebsites[$websiteId], $information['sync']);
                }
                catch (Exception $e)
                {
                }
            }

            //Update core
            foreach ($coreToUpdateNow as $websiteId)
            {
                if (($sitesCheckCompleted != null) && ($sitesCheckCompleted[$websiteId] == false)) continue;

                try
                {
                    MainWPUtility::fetchUrlAuthed($allWebsites[$websiteId], 'upgrade');
                }
                catch (Exception $e)
                {
                }
            }
        }
    }

    function mainwp_cronpingchilds_action()
    {
        $lastPing = get_option('mainwp_cron_last_ping');
        if ($lastPing !== false && (time() - $lastPing) < (60 * 60 * 23))
        {
            return;
        }
        MainWPUtility::update_option('mainwp_cron_last_ping', time());

        $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsites());
        while ($websites && ($website = @MainWPDB::fetch_object($websites)))
        {
            try
            {
                $url = $website->siteurl;
                if (!MainWPUtility::endsWith($url, '/')) $url .= '/';

                wp_remote_get($url . 'wp-cron.php');
            }
            catch (Exception $e)
            {

            }
        }
        @MainWPDB::free_result($websites);
    }

    function mainwp_cronconflicts_action()
    {
        $lastCronConflicts = get_option('mainwp_cron_last_cronconflicts');
        if ($lastCronConflicts !== false && (time() - $lastCronConflicts) < (60 * 60 * 11))
        {
            return;
        }
        MainWPUtility::update_option('mainwp_cron_last_cronconflicts', time());

        MainWPAPISettings::testAPIs();

        if (true || $this->isAPIValid())
        {
            $url = get_home_url();
            try
            {
                $cronjobs = get_option('mainwp_cron_jobs');
                if ($cronjobs === false) $cronjobs = 0;
                if ($cronjobs && !((get_option('mainwp_wp_cron') === false) || (get_option('mainwp_wp_cron') == 1))) $cronjobs = false;
                $result = MainWPUtility::http_post("do=getConflicts&url=" . urlencode($url). "&username=" . urldecode(get_option('mainwp_api_username')) . "&cron=" . $cronjobs, "mainwp.com", "/versioncontrol/rqst.php", 80, 'main', true);
            }
            catch (Exception $e)
            {
                MainWPLogger::Instance()->warning('An error occured when trying to reach the MainWP server: ' . $e->getMessage());
            }

            if (isset($result[1]))
            {
                $result = $result[1];
            }
            else
            {
                return;
            }

            $result = json_decode($result, true);
            $pluginConflicts = explode("\n", $result['pluginConflicts']);
            $themeConflicts = explode("\n", $result['themeConflicts']);
            $newPluginConflicts = array();
            foreach ($pluginConflicts as $pluginConflict)
            {
                $lastIndex = strrpos($pluginConflict, ' ');
                if (!$lastIndex)
                {
                    $newPluginConflicts[$pluginConflict] = false;
                }
                else
                {
                    $newPluginConflicts[substr($pluginConflict, 0, $lastIndex)] = substr($pluginConflict, $lastIndex);
                }
            }

            $newThemeConflicts = array();
            foreach ($themeConflicts as $themeConflict)
            {
                $lastIndex = strrpos($themeConflict, ' ');
                if (!$lastIndex)
                {
                    $newThemeConflicts[$themeConflict] = false;
                }
                else
                {
                    $newThemeConflicts[substr($themeConflict, 0, $lastIndex)] = substr($themeConflict, $lastIndex);
                }
            }

            MainWPUtility::update_option('mainwp_pluginConflicts', $newPluginConflicts);
            MainWPUtility::update_option('mainwp_themeConflicts', $newThemeConflicts);

            return;
        }
    }

    function mainwp_cronbackups_continue_action()
    {
        @ignore_user_abort(true);
        @set_time_limit(0);
        $mem =  '512M';
        @ini_set('memory_limit', $mem);
        @ini_set('max_execution_time', 0);

        MainWPUtility::update_option('mainwp_cron_last_backups_continue', time());

        $chunkedBackupTasks = get_option('mainwp_chunkedBackupTasks');
        if ($chunkedBackupTasks == 0) return;

        //Fetch all tasks where complete < last & last checkup is more then 1minute ago! & last is more then 1 minute ago!
        $tasks = MainWPDB::Instance()->getBackupTasksToComplete();

        foreach ($tasks as $task)
        {
            $task = MainWPDB::Instance()->getBackupTaskById($task->id);
            if ($task->completed < $task->last_run)
            {
                MainWPManageBackups::executeBackupTask($task, 5, false);
                break;
            }
        }
    }

    function mainwp_cronbackups_action()
    {
        @ignore_user_abort(true);
        @set_time_limit(0);
        $mem =  '512M';
        @ini_set('memory_limit', $mem);
        @ini_set('max_execution_time', 0);

        MainWPUtility::update_option('mainwp_cron_last_backups', time());

        //Do cronjobs!
        //Config this in crontab: 0 0 * * * wget -q http://mainwp.com/wp-admin/?do=cron -O /dev/null 2>&1
        //this will execute once every day to check to do the scheduled backups
        $allTasks = array();
        $dailyTasks = MainWPDB::Instance()->getBackupTasksTodoDaily();
        if (count($dailyTasks) > 0) {
            $allTasks = $dailyTasks;
        }
        $weeklyTasks = MainWPDB::Instance()->getBackupTasksTodoWeekly();
        if (count($weeklyTasks) > 0) {
            $allTasks = array_merge($allTasks, $weeklyTasks);
        }
        $monthlyTasks = MainWPDB::Instance()->getBackupTasksTodoMonthly();
        if (count($monthlyTasks) > 0) {
            $allTasks = array_merge($allTasks, $monthlyTasks);
        }

        foreach ($allTasks as $task)
        {
            $threshold = 0;
            if ($task->schedule == 'daily')
            {
                $threshold = (60 * 60 * 24);
            }
            else if ($task->schedule == 'weekly')
            {
                $threshold = (60 * 60 * 24 * 7);
            }
            else if ($task->schedule == 'monthly')
            {
                $threshold = (60 * 60 * 24 * 30);
            }
            $task = MainWPDB::Instance()->getBackupTaskById($task->id);
            if ((time() - $task->last_run) < $threshold) {
                continue;
            }

            if (!MainWPManageBackups::validateBackupTasks(array($task)))
            {
                $task = MainWPDB::Instance()->getBackupTaskById($task->id);
            }

            $chunkedBackupTasks = get_option('mainwp_chunkedBackupTasks');
            MainWPManageBackups::executeBackupTask($task, ($chunkedBackupTasks != 0 ? 5 : 0));
        }
    }

    function mainwp_cronstats_action()
    {
        MainWPUtility::update_option('mainwp_cron_last_stats', time());
        if (get_option('mainwp_seo') != 1) return;

        $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getWebsitesStatsUpdateSQL());

        $start = time();
        while ($websites && ($website = @MainWPDB::fetch_object($websites)))
        {
            if (($start - time()) > (60 * 60 * 2)) {
                //two hours passed, next cron will start!
                break;
            }

            $alexia = MainWPUtility::getAlexaRank($website->url);
            $pageRank = MainWPUtility::getPagerank($website->url);
            $indexed = MainWPUtility::getGoogleCount($website->url);

            MainWPDB::Instance()->updateWebsiteStats($website->id, $pageRank, $indexed, $alexia, $website->pagerank, $website->indexed, $website->alexia);

            if ($website->sync_errors != '')
            {
                //Try reconnecting
                MainWPLogger::Instance()->infoForWebsite($website, 'reconnect', 'Trying to reconnect');
                try
                {
                    if (MainWPManageSites::_reconnectSite($website))
                    {
                        //Reconnected
                        MainWPLogger::Instance()->infoForWebsite($website, 'reconnect', 'Reconnected successfully');
                    }
                }
                catch (Exception $e)
                {
                    //Still something wrong
                    MainWPLogger::Instance()->warningForWebsite($website, 'reconnect', $e->getMessage());
                }
            }
            sleep(3);
        }
        @MainWPDB::free_result($websites);
    }

    function admin_footer()
    {
        MainWPPost::initMenuSubPages();
        MainWPManageSites::initMenuSubPages();
        MainWPSettings::initMenuSubPages();
        MainWPExtensions::initMenuSubPages();
        MainWPPage::initMenuSubPages();
        MainWPThemes::initMenuSubPages();
        MainWPPlugins::initMenuSubPages();
        MainWPUser::initMenuSubPages();
        MainWPManageBackups::initMenuSubPages();

        do_action('mainwp_admin_menu_sub');
    }

    function admin_print_styles()
    {
        if (isset($_GET['hideall']) && $_GET['hideall'] == 1) {
            $post_plus = apply_filters('mainwp-ext-post-plus-enabled', false);
            ?>
        <style>
<?php   if (!$post_plus) { ?>
            #minor-publishing-actions {
                display: none;
            }
<?php   } ?>
            #screen-options-link-wrap {
                display: none;
            }

            #wpcontent #wpadminbar {
                display: none;
            }

            .update-nag {
                display: none;
            }
            #wpfooter {
                display: none;
            }
        </style>
        <?php
        }
    }

    function login_redirect($redirect_to, $request, $user)
    {
        if (session_id() == '') session_start();
        $_SESSION['showTip'] = 'yes';

        return home_url('/wp-admin/index.php');
    }

    function init()
    {
        remove_all_filters( 'admin_footer_text' );
        add_filter('admin_footer_text', array(&$this, 'admin_footer_text'));
    }

    function uploadFile($file)
    {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=' . basename($file));
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        $this->readfile_chunked($file);
    }

    function readfile_chunked($filename)
    {
        $chunksize = 1024; // how many bytes per chunk
        $handle = @fopen($filename, 'rb');
        if ($handle === false) return false;

        while (!@feof($handle))
        {
            $buffer = @fread($handle, $chunksize);
            echo $buffer;
            @ob_flush();
            @flush();
            $buffer = null;
        }
        return @fclose($handle);
    }

    function parse_init()
    {
        if (isset($_GET['do']) && $_GET['do'] == 'testLog') {
            MainWPLogger::Instance()->debug('ruben');
        }
        if (isset($_GET['do']) && $_GET['do'] == 'cronBackups') {
            $this->mainwp_cronbackups_action();
        }
        else if (isset($_GET['do']) && $_GET['do'] == 'cronBackupsContinue') {
            $this->mainwp_cronbackups_continue_action();
        }
        else if (isset($_GET['do']) && $_GET['do'] == 'cronStats') {
            $this->mainwp_cronstats_action();
        }
        else if (isset($_GET['do']) && $_GET['do'] == 'checkSites') {
            $this->mainwp_cronofflinecheck_action();
        }
        else if (isset($_GET['do']) && $_GET['do'] == 'cronConflicts') {
            $this->mainwp_cronconflicts_action();
        }
        else if (isset($_GET['do']) && $_GET['do'] == 'cronUpdatesCheck') {
            $this->mainwp_cronupdatescheck_action();
        }
        else if (isset($_GET['mwpdl']) && isset($_GET['sig']))
        {
            $mwpDir = MainWPUtility::getMainWPDir();
            $mwpDir = $mwpDir[0];
            $file = trailingslashit($mwpDir) . rawurldecode($_GET['mwpdl']);
            if (file_exists($file) && md5(filesize($file)) == $_GET['sig'])
            {
                $this->uploadFile($file);
                exit();
            }
        }
    }

    function login_form()
    {
        global $redirect_to;
        if (!isset($_GET['redirect_to'])) {
            $redirect_to = get_admin_url() . 'index.php';
        }
    }

    function post_updated_messages($messages)
    {
        $messages['post'][98] = 'Wordpress Seo values saved.';
        $messages['post'][99] = 'You have to select the sites you wish to publish to.';
        return $messages;
    }

      function mainwp_warning_notice() {
        if (get_option('mainwp_installation_warning_hide_the_notice')  == 'yes')
            return;
        ?>
        <div id="mainwp-installation-warning" class="mainwp_info-box-red">
            <h3><?php _e('Stop! Before you continue,','mainwp'); ?></h3>
            <strong><?php _e('We HIGHLY recommend a NEW WordPress install for your Main Dashboard.','mainwp'); ?></strong><br/><br/>
            <?php _e('Using a new WordPress install will help to cut down on Plugin Conflicts and other issues that can be caused by trying to run your MainWP Main Dashboard off an active site. Most hosting companies provide free subdomains ("<strong>demo.yourdomain.com</strong>") and we recommend creating one if you do not have a specific dedicated domain to run your Network Main Dashboard.<br/><br/> If you are not sure how to set up a subdomain here is a quick step by step with <a href="http://docs.mainwp.com/creating-a-subdomain-in-cpanel/">cPanel</a>, <a href="http://docs.mainwp.com/creating-a-subdomain-in-plesk/">Plesk</a> or <a href="http://docs.mainwp.com/creating-a-subdomain-in-directadmin-control-panel/">Direct Admin</a>. If you are not sure what you have, contact your hosting companies support.','mainwp'); ?>
        <br/><br/><div style="text-align: center"><a href="#" class="button button-primary" id="remove-mainwp-installation-warning">I have read the warning and I want to proceed</a></div>
        </div>
        <?php
    }

    function admin_init()
    {
        if (get_option('mainwp_activated') == 'yes')
        {
            delete_option('mainwp_activated');
            wp_redirect(admin_url('admin.php?page=managesites&do=new'));
            return;
        }
        add_action( 'admin_notices', array($this, 'mainwp_warning_notice' ));
        $this->posthandler->init();

        wp_enqueue_script('jquery-ui-tooltip');
        wp_enqueue_script('jquery-ui-autocomplete');
        wp_enqueue_script('jquery-ui-progressbar');
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_script('jquery-ui-dialog');

        global $wp_scripts;
        $ui = $wp_scripts->query('jquery-ui-core');
        $version = $ui->ver;
        if (MainWPUtility::startsWith($version, '1.10'))
        {
            wp_enqueue_style('jquery-ui-style', plugins_url('/css/1.10.4/jquery-ui.min.css', dirname(__FILE__)));
        }
        else
        {
            wp_enqueue_style('jquery-ui-style', plugins_url('/css/1.11.1/jquery-ui.min.css', dirname(__FILE__)));
        }

        wp_enqueue_script('mainwp', plugins_url('/js/mainwp.js', dirname(__FILE__)), array('jquery-ui-tooltip', 'jquery-ui-autocomplete', 'jquery-ui-progressbar', 'jquery-ui-dialog', 'jquery-ui-datepicker'));
        $mainwpParams = array('image_url' => plugins_url('/images/', dirname(__FILE__)), 'backup_before_upgrade' => (get_option('mainwp_backup_before_upgrade') == 1), 'admin_url' => admin_url(), 'date_format' => get_option('date_format'), 'time_format' => get_option('time_format'));
        wp_localize_script('mainwp', 'mainwpParams', $mainwpParams );
        wp_enqueue_script('mainwp-tristate', plugins_url('/js/tristate.min.js', dirname(__FILE__)), array('mainwp'));

        $mainwpTranslations = MainWPSystemView::getMainWPTranslations();

        wp_localize_script('mainwp', 'mainwpTranslations', $mainwpTranslations);

        $security_nonces = $this->posthandler->getSecurityNonces();
        wp_localize_script('mainwp', 'security_nonces', $security_nonces);

        MainWPMetaBoxes::initMetaBoxes();

        wp_enqueue_script('thickbox');
        wp_enqueue_script('user-profile');
        wp_enqueue_style('thickbox');

        if (!current_user_can('update_core')) remove_action('admin_notices', 'update_nag', 3);
    }


    //This function will read the metaboxes & save them to the post
    function publish_bulkpost($post_id)
    {
        $save_seo_value = isset($_POST["mainwp_wpseo_metabox_save_values"]) && (!empty($_POST["mainwp_wpseo_metabox_save_values"]))? true : false;
        $message_id = 99;
        if ($save_seo_value)
            $message_id = 98;

        //Read extra metabox
        $pid = $this->metaboxes->select_sites_handle($post_id, 'bulkpost');

        if ($save_seo_value || $pid == $post_id) {
            /** @var $wpdb wpdb */
            global $wpdb;
            $wpdb->update($wpdb->posts, array('post_status' => 'draft'), array('ID' => $post_id));
            add_filter('redirect_post_location', create_function('$location', 'return add_query_arg(array("message" => "' . $message_id . '", "hideall" => 1), $location);'));
        }
        else
        {
            $this->metaboxes->add_categories_handle($post_id, 'bulkpost');
            $this->metaboxes->add_tags_handle($post_id, 'bulkpost');
            $this->metaboxes->add_slug_handle($post_id, 'bulkpost');
            MainWPPost::add_sticky_handle($post_id);

            //Redirect to handle page! (to actually post the messages)
            wp_redirect(get_site_url() . '/wp-admin/admin.php?page=PostingBulkPost&hideall=1&id=' . $post_id);
            die();
        }
    }

    function save_bulkpost($post_id)
    {
        $post = get_post($post_id);
        if ($post->post_type != 'bulkpost' && (!isset($_POST['post_type']) || ($_POST['post_type'] != 'bulkpost'))) return;

        // verify if this is an auto save routine.
        // If it is our form has not been submitted, so we dont want to do anything
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            return;

        $save_seo_value = isset($_POST["mainwp_wpseo_metabox_save_values"]) && (!empty($_POST["mainwp_wpseo_metabox_save_values"]))? true : false;
        $message_id = 96;
        if ($save_seo_value)
            $message_id = 98;

        //Read extra metabox
        $pid = $this->metaboxes->select_sites_handle($post_id, 'bulkpost');
        $this->metaboxes->add_categories_handle($post_id, 'bulkpost');
        $this->metaboxes->add_tags_handle($post_id, 'bulkpost');
        $this->metaboxes->add_slug_handle($post_id, 'bulkpost');
        
        if (isset($_POST['save'])) {
            global $wpdb;
            $wpdb->update($wpdb->posts, array('post_status' => 'draft'), array('ID' => $post_id));
            add_filter('redirect_post_location', create_function('$location', 'return add_query_arg(array("message" => "' . $message_id . '", "hideall" => 1), $location);'));
        }
        else if ($save_seo_value || $pid == $post_id) {
            /** @var $wpdb wpdb */
            global $wpdb;
            $wpdb->update($wpdb->posts, array('post_status' => 'draft'), array('ID' => $post_id));
            add_filter('redirect_post_location', create_function('$location', 'return add_query_arg(array("message" => "' . $message_id . '", "hideall" => 1), $location);'));
        }
        else if (isset($_POST['publish']))
        {
            //Redirect to handle page! (to actually post the messages)
            wp_redirect(get_site_url() . '/wp-admin/admin.php?page=PostingBulkPost&hideall=1&id=' . $post_id);
            die();
        }
        
    }

    //This function will read the metaboxes & save them to the post
    function publish_bulkpage($post_id)
    {
        $save_seo_value = isset($_POST["mainwp_wpseo_metabox_save_values"]) && (!empty($_POST["mainwp_wpseo_metabox_save_values"]))? true : false;
        $message_id = 99;
        if ($save_seo_value)
            $message_id = 98;

        //Read extra metabox
        $pid = $this->metaboxes->select_sites_handle($post_id, 'bulkpage');

        if ($save_seo_value || $pid == $post_id) {
            /** @var $wpdb wpdb */
            global $wpdb;
            $wpdb->update($wpdb->posts, array('post_status' => 'draft'), array('ID' => $post_id));
            add_filter('redirect_post_location', create_function('$location', 'return add_query_arg(array("message" => "' . $message_id . '", "hideall" => 1), $location);'));
        }
        else
        {
            $this->metaboxes->add_slug_handle($post_id, 'bulkpage');

            //Redirect to handle page! (to actually post the messages)
            wp_redirect(get_site_url() . '/wp-admin/admin.php?page=PostingBulkPage&hideall=1&id=' . $post_id);
            die();
        }
    }

    function save_bulkpage($post_id)
    {
        $post = get_post($post_id);
        if ($post->post_type != 'bulkpage' && (!isset($_POST['post_type']) || ($_POST['post_type'] != 'bulkpage'))) return;

        // verify if this is an auto save routine.
        // If it is our form has not been submitted, so we dont want to do anything
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            return;

        $save_seo_value = isset($_POST["mainwp_wpseo_metabox_save_values"]) && (!empty($_POST["mainwp_wpseo_metabox_save_values"]))? true : false;
        $message_id = 96;
        if ($save_seo_value)
            $message_id = 98;

        //Read extra metabox
        $pid = $this->metaboxes->select_sites_handle($post_id, 'bulkpage');
        $this->metaboxes->add_slug_handle($post_id, 'bulkpage');
        
        if (isset($_POST['save'])) {
            global $wpdb;
            $wpdb->update($wpdb->posts, array('post_status' => 'draft'), array('ID' => $post_id));            
            add_filter('redirect_post_location', create_function('$location', 'return add_query_arg(array("message" => "' . $message_id . '", "hideall" => 1), $location);'));            
        }
        else if ($save_seo_value || $pid == $post_id) {
            /** @var $wpdb wpdb */
            global $wpdb;
            $wpdb->update($wpdb->posts, array('post_status' => 'draft'), array('ID' => $post_id));
            add_filter('redirect_post_location', create_function('$location', 'return add_query_arg(array("message" => "' . $message_id . '", "hideall" => 1), $location);'));
        }
        else
        {
            //Redirect to handle page! (to actually post the messages)
            wp_redirect(get_site_url() . '/wp-admin/admin.php?page=PostingBulkPage&hideall=1&id=' . $post_id);
            die();
        }
    }

    function create_post_type()
    {
        $queryable = ($post_plus = apply_filters('mainwp-ext-post-plus-enabled', false)) ? true : false;
        
        $labels = array(
            'name' => _x('Bulkpost', 'bulkpost'),
            'singular_name' => _x('Bulkpost', 'bulkpost'),
            'add_new' => _x('Add New', 'bulkpost'),
            'add_new_item' => _x('Add New Bulkpost', 'bulkpost'),
            'edit_item' => _x('Edit Bulkpost', 'bulkpost'),
            'new_item' => _x('New Bulkpost', 'bulkpost'),
            'view_item' => _x('View Bulkpost', 'bulkpost'),
            'search_items' => _x('Search Bulkpost', 'bulkpost'),
            'not_found' => _x('No bulkpost found', 'bulkpost'),
            'not_found_in_trash' => _x('No bulkpost found in Trash', 'bulkpost'),
            'parent_item_colon' => _x('Parent Bulkpost:', 'bulkpost'),
            'menu_name' => _x('Bulkpost', 'bulkpost'),
        );
        
        $args = array(
            'labels' => $labels,
            'hierarchical' => false,
            'description' => 'description...',
            'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'comments', 'revisions'),
            //'taxonomies' => array('category', 'post_tag', 'page-category'),
            'public' => true,
            'show_ui' => true,
            //'show_in_menu' => 'index.php',
            'show_in_nav_menus' => false,
            'publicly_queryable' => $queryable,
            'exclude_from_search' => true,
            'has_archive' => false,
            'query_var' => false,
            'can_export' => false,
            'rewrite' => false,
            'capabilities' => array(
                'edit_post' => 'read',
                'edit_posts' => 'read',
                'edit_others_posts' => 'read',
                'publish_posts' => 'read',
                'read_post' => 'read',
                'read_private_posts' => 'read',
                'delete_post' => 'read'
            )
        );

        register_post_type('bulkpost', $args);


        $labels = array(
            'name' => _x('Bulkpage', 'bulkpage'),
            'singular_name' => _x('Bulkpage', 'bulkpage'),
            'add_new' => _x('Add New', 'bulkpage'),
            'add_new_item' => _x('Add New Bulkpage', 'bulkpage'),
            'edit_item' => _x('Edit Bulkpage', 'bulkpage'),
            'new_item' => _x('New Bulkpage', 'bulkpage'),
            'view_item' => _x('View Bulkpage', 'bulkpage'),
            'search_items' => _x('Search Bulkpage', 'bulkpage'),
            'not_found' => _x('No bulkpage found', 'bulkpage'),
            'not_found_in_trash' => _x('No bulkpage found in Trash', 'bulkpage'),
            'parent_item_colon' => _x('Parent Bulkpage:', 'bulkpage'),
            'menu_name' => _x('Bulkpage', 'bulkpage'),
        );

        $args = array(
            'labels' => $labels,
            'hierarchical' => false,
            'description' => 'description...',
            'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'comments', 'revisions'),
            //'taxonomies' => array('category', 'post_tag', 'page-category'),
            'public' => true,
            'show_ui' => true,
            //'show_in_menu' => 'index.php',
            'show_in_nav_menus' => false,
            'publicly_queryable' => $queryable,
            'exclude_from_search' => true,
            'has_archive' => false,
            'query_var' => false,
            'can_export' => false,
            'rewrite' => false,
            'capabilities' => array(
                'edit_post' => 'read',
                'edit_posts' => 'read',
                'edit_others_posts' => 'read',
                'publish_posts' => 'read',
                'read_post' => 'read',
                'read_private_posts' => 'read',
                'delete_post' => 'read'
            )
        );

        register_post_type('bulkpage', $args);
    }

    function admin_head()
    {
//        echo '<script type="text/javascript" src="' . plugins_url('js/jquery.ui.core.min.js', dirname(__FILE__)) . '"></script>';
        if (MainWPUtility::isAdmin()) {
            echo '<script type="text/javascript" src="' . plugins_url('js/mainwp-admin.js', dirname(__FILE__)) . '"></script>';
        }
        $userExtension = MainWPDB::Instance()->getUserExtension();

//        if (($userExtension->tips == 1) && isset($_SESSION['showTip'])) {
//            echo '<script type="text/javascript" src="' . plugins_url('js/mainwp-tip.js', dirname(__FILE__)) . '"></script>';
//        }
        echo '<script type="text/javascript" src="' . plugins_url('js/mainwp-rightnow.js', dirname(__FILE__)) . '"></script>';
        echo '<script type="text/javascript" src="' . plugins_url('js/mainwp-extensions.js', dirname(__FILE__)) . '"></script>';
        echo '<script type="text/javascript" src="' . plugins_url('js/mainwp-ui.js', dirname(__FILE__)) . '"></script>';
        echo '<script type="text/javascript" src="' . plugins_url('js/fileuploader.js', dirname(__FILE__)) . '"></script>';
        echo '<script type="text/javascript" src="' . plugins_url('js/date.js', dirname(__FILE__)) . '"></script>';
        echo '<script type="text/javascript" src="' . plugins_url('js/jquery.tablesorter.min.js', dirname(__FILE__)) . '"></script>';
        echo '<script type="text/javascript" src="' . plugins_url('js/jquery.tablesorter.pager.js', dirname(__FILE__)) . '"></script>';
        echo '<script type="text/javascript" src="' . plugins_url('js/moment.min.js', dirname(__FILE__)) . '"></script>';
        echo '<script type="text/javascript" src="' . plugins_url('js/jsapi.js', dirname(__FILE__)) . '"></script>';
        echo '<script type="text/javascript">
  				google.load("visualization", "1", {packages:["corechart"]});
			</script>';
        echo '<link rel="stylesheet" id="custom_admin" type="text/css" href="' . plugins_url('css/mainwp.css', dirname(__FILE__)) . '" />';
        echo '<link rel="stylesheet" type="text/css" href="' . plugins_url('css/fileuploader.css', dirname(__FILE__)) . '" />';
        if (isset($_GET['hideall']) && $_GET['hideall'] == 1) {
            echo '<link rel="stylesheet" id="custom_admin" type="text/css" href="' . plugins_url('css/mainwp-hidden.css', dirname(__FILE__)) . '" />';
            remove_action('admin_footer', 'wp_admin_bar_render', 1000);
        }
        echo '<script type="text/javascript">var mainwp_ajax_nonce = "' . wp_create_nonce('mainwp_ajax') . '"</script>';
        echo '<script type="text/javascript" src="' . plugins_url('js/FileSaver.js', dirname(__FILE__)) . '"></script>';
        echo '<script type="text/javascript" src="' . plugins_url('js/jqueryFileTree.js', dirname(__FILE__)) . '"></script>';
        echo '<link rel="stylesheet" type="text/css" href="' . plugins_url('css/jqueryFileTree.css', dirname(__FILE__)) . '" />';
        // mainwp-article-poster
//        echo '<link rel="stylesheet" type="text/css" href="' . plugins_url('mainwp-article-poster/css/admin.css', dirname(__FILE__)) . '" />';
//        echo '<link rel="stylesheet" type="text/css" href="' . plugins_url('mainwp-article-poster/css/datePicker.css', dirname(__FILE__)) . '" />';
//        echo '<script type="text/javascript" src="' . plugins_url('mainwp-article-poster/js/date.js', dirname(__FILE__)) . '"></script>';
//        echo '<script type="text/javascript" src="' . plugins_url('mainwp-article-poster/js/jquery.datePicker.min-2.1.2.js', dirname(__FILE__)) . '"></script>';
    }
    
    function admin_body_class($class_string) {
        $screen = get_current_screen();
        
        if($screen && strpos($screen->base, "mainwp_") !== false) 
                $class_string .= "mainwp-ui";
        
        return $class_string;
    }

    function admin_menu()
    {
        global $menu, $submenu;
        foreach ($menu as $k => $item)
        {
            if ($item[2] == 'edit.php?post_type=bulkpost')
            { //Remove bulkpost
                unset($menu[$k]);
            }
            else if ($item[2] == 'edit.php?post_type=bulkpage')
            { //Remove bulkpost
                unset($menu[$k]);
            }
        }
    }

    //Empty footer text
    function admin_footer_text()
    {
        $userExtension = MainWPDB::Instance()->getUserExtension();
        if (session_id() == '') session_start();
//        if (($userExtension->tips == 1) && isset($_SESSION['showTip'])) {
//            MainWPManageTips::renderTips();
//        }
        if (isset($_SESSION['showTip'])) {
            unset($_SESSION['showTip']);
        }
        return 'MainWP - version ' . $this->current_version . ' &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Currently Managing: <span id="managedSitesCount">' . MainWPDB::Instance()->getWebsitesCount() . '</span> Sites';
    }

    //Version
    function update_footer()
    {
        $output = '<span><input type="button" style="background-image: none!important; padding-left: .6em !important;" id="dashboard_refresh" value="Sync Data" class="mainwp-upgrade-button button-primary button" /> <a class="button-primary button" href="admin.php?page=managesites&do=new">Add New Site</a> <a class="button-primary button mainwp-button-red" href="https://extensions.mainwp.com" target="_blank">Get New Extensions</a></span>';


        $current_wpid = MainWPUtility::get_current_wpid();
        if ($current_wpid)
        {
            $website = MainWPDB::Instance()->getWebsiteById($current_wpid);
            $websites = array($website);
        }
        else
        {
            $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser(false, null, 'wp.dtsSync DESC, wp.url ASC'));
        }
        ob_start();

        $cntr = 0;
        if (is_array($websites))
        {
            for ($i = 0; $i < count($websites); $i++)
            {
                $website = $websites[$i];
                if ($website->sync_errors == '')
                {
                    $cntr++;
                    echo '<input type="hidden" name="dashboard_wp_ids[]" class="dashboard_wp_id" value="'.$website->id.'" />';
                }
            }
        }
        else if ($websites !== false)
        {
            while ($website = @MainWPDB::fetch_object($websites))
            {
                if ($website->sync_errors == '')
                {
                    $cntr++;
                    echo '<input type="hidden" name="dashboard_wp_ids[]" class="dashboard_wp_id" value="'.$website->id.'" />';
                }
            }
        }

        ?>
        <div id="refresh-status-box" title="Syncing Websites" style="display: none; text-align: center">
            <div id="refresh-status-progress"></div>
            <span id="refresh-status-current">0</span> / <span id="refresh-status-total"><?php echo $cntr; ?></span> updated
            <div style="height: 160px; overflow: auto; margin-top: 20px; margin-bottom: 10px; text-align: left" id="refresh-status-content">
                <table style="width: 100%">
                <?php
                    if (is_array($websites))
                    {
                        for ($i = 0; $i < count($websites); $i++)
                        {
                            $website = $websites[$i];
                            if ($website->sync_errors == '')
                            {
                                echo '<tr><td>'.MainWPUtility::getNiceURL($website->url).'</td><td style="width: 80px"><span class="refresh-status-wp" siteid="'.$website->id.'">PENDING</span></td></tr>';
                            }
                            else
                            {
                                echo '<tr class="mainwp_wp_offline"><td>'.MainWPUtility::getNiceURL($website->url).'</td><td style="width: 80px"><span class="refresh-status-wp" siteid="'.$website->id.'">DISCONNECTED</span></td></tr>';
                            }
                        }
                    }
                    else
                    {
                        @MainWPDB::data_seek($websites, 0);
                        while ($website = @MainWPDB::fetch_object($websites))
                        {
                            if ($website->sync_errors == '')
                            {
                                echo '<tr><td>'.MainWPUtility::getNiceURL($website->url).'</td><td style="width: 80px"><span class="refresh-status-wp" siteid="'.$website->id.'">PENDING</span></td></tr>';
                            }
                            else
                            {
                                echo '<tr class="mainwp_wp_offline"><td>'.MainWPUtility::getNiceURL($website->url).'</td><td style="width: 80px"><span class="refresh-status-wp" siteid="'.$website->id.'">DISCONNECTED</span></td></tr>';
                            }
                        }
                    }
                    ?>
                </table>
            </div>
            <input id="refresh-status-close" type="button" name="Close" value="Close" class="button" />
        </div>
    <?php
        $newOutput = ob_get_clean();

        return $output . $newOutput;
    }

    function new_menus()
    {
        if (MainWPUtility::isAdmin()) // || $this->isAPIValid())
        {
            //Adding the page to manage your added sites/groups
            //The first page which will display the post area etc..
            MainWPSecurityIssues::initMenu();
            MainWPManageSites::initMenu();
            MainWPPost::initMenu();
            MainWPPage::initMenu();
            MainWPThemes::initMenu();
            MainWPPlugins::initMenu();
            MainWPUser::initMenu();
            MainWPManageBackups::initMenu();
            MainWPOfflineChecks::initMenu();
            MainWPBulkUpdateAdminPasswords::initMenu();
            MainWPManageGroups::initMenu();
            MainWPSettings::initMenu();
            MainWPExtensions::initMenu();
            do_action('mainwp_admin_menu');
            MainWPDocumentation::initMenu();
            MainWPServerInformation::initMenu();            
        }

        if (MainWPUtility::isAdmin())
        {
            MainWPAPISettings::initMenu();
        }
    }

    //On activation install the database
    function activation()
    {
        delete_option('mainwp_requests');
        MainWPDB::Instance()->update();
        MainWPDB::Instance()->install();

        //Redirect to settings page
        MainWPUtility::update_option('mainwp_activated', 'yes');
    }

    function deactivation()
    {
        wp_clear_scheduled_hook('mainwp_cron_action');
        delete_option('mainwp_requests');
        try
        {
            MainWPUtility::http_post("do=deactivation&url=" . urlencode(get_home_url()), "mainwp.com", "/versioncontrol/rqst.php", 80, 'main', true);
        }
        catch (Exception $e)
        {
            MainWPLogger::Instance()->warning('An error occured when trying to reach the MainWP server: ' . $e->getMessage());
        }
    }

    //On update update the database
    function update()
    {
        MainWPDB::Instance()->update();
        MainWPDB::Instance()->install();
    }

    function apply_filter($filter)
    {
        $output = apply_filters($filter, array());

        if (!is_array($output)) return array();

        for ($i = 0; $i < count($output); $i++)
        {
            if (!isset($output[$i]['plugin']) || !isset($output[$i]['key']))
            {
                unset($output[$i]);
                continue;
            }

            if (!MainWPExtensions::hookVerify($output[$i]['plugin'], $output[$i]['key']))
            {
                unset($output[$i]);
                continue;
            }
        }

        return $output;
    }

    public function isSingleUser()
    {
        return true;
    }

    public function isMultiUser()
    {
        return !$this->isSingleUser();
    }
}
