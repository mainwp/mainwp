<?php
class MainWPManageBackups
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static $subPages;
    /** @var $sitesTable MainWPManageBackups_List_Table */
    public static $sitesTable;

    public static function init()
    {
        add_action('mainwp-pageheader-backups', array(MainWPManageBackups::getClassName(), 'renderHeader'));
        add_action('mainwp-pagefooter-backups', array(MainWPManageBackups::getClassName(), 'renderFooter'));
    }

    public static function initMenu()
    {
        $page = add_submenu_page('mainwp_tab', __('Backups','mainwp'), '<span id="mainwp-Backups">'. __('Backups','mainwp') . '</span>', 'read', 'ManageBackups', array(MainWPManageBackups::getClassName(), 'renderManager'));
        add_action('load-' . $page, array(MainWPManageBackups::getClassName(), 'load_page'));
        add_submenu_page('mainwp_tab', __('Add New Schedule','mainwp'), '<div class="mainwp-hidden">' . __('Add New','mainwp') . '</div>', 'read', 'ManageBackupsAddNew', array(MainWPManageBackups::getClassName(), 'renderNew'));
        add_submenu_page('mainwp_tab', __('Backups Help','mainwp'), '<div class="mainwp-hidden">' . __('Backups Help','mainwp') . '</div>', 'read', 'BackupsHelp', array(MainWPManageBackups::getClassName(), 'QSGManageBackups'));

        self::$subPages = apply_filters('mainwp-getsubpages-backups', array());
        if (isset(self::$subPages) && is_array(self::$subPages))
        {
            foreach (self::$subPages as $subPage)
            {
                add_submenu_page('mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'ManageBackups' . $subPage['slug'], $subPage['callback']);
            }
        }
    }

    public static function load_page()
    {
        self::$sitesTable = new MainWPManageBackups_List_Table();
    }

    public static function initMenuSubPages()
    {
        ?>
    <div id="menu-mainwp-Backups" class="mainwp-submenu-wrapper">
        <div class="wp-submenu sub-open" style="">
            <div class="mainwp_boxout">
                <div class="mainwp_boxoutin"></div>
                <a href="<?php echo admin_url('admin.php?page=ManageBackups'); ?>" class="mainwp-submenu"><?php _e('All Backups','mainwp'); ?></a>
                <a href="<?php echo admin_url('admin.php?page=ManageBackupsAddNew'); ?>" class="mainwp-submenu"><?php _e('Add New','mainwp'); ?></a>
                <?php
                if (isset(self::$subPages) && is_array(self::$subPages))
                {
                    foreach (self::$subPages as $subPage)
                    {
                    ?>
                        <a href="<?php echo admin_url('admin.php?page=ManageBackups' . $subPage['slug']); ?>"
                           class="mainwp-submenu"><?php echo $subPage['title']; ?></a>
                    <?php
                    }
                }
                ?>
            </div>
        </div>
    </div>
    <?php
    }

    public static function renderHeader($shownPage)
    {
        ?>
    <div class="wrap">
        <a href="http://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img
                src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50"
                alt="MainWP"/></a>
        <img src="<?php echo plugins_url('images/icons/mainwp-backups.png', dirname(__FILE__)); ?>"
             style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Backups" height="32"/>
        <h2><?php _e('Backups','mainwp'); ?></h2><div style="clear: both;"></div><br/><br/>
        <div class="mainwp-tabs" id="mainwp-tabs">
            <a class="nav-tab pos-nav-tab <?php if ($shownPage == '') { echo "nav-tab-active"; } ?>" href="admin.php?page=ManageBackups"><?php _e('Manage','mainwp'); ?></a>
            <a class="nav-tab pos-nav-tab <?php if ($shownPage == 'AddNew') { echo "nav-tab-active"; } ?>" href="admin.php?page=ManageBackupsAddNew"><?php _e('Add New','mainwp'); ?></a>
            <a style="float: right" class="mainwp-help-tab nav-tab pos-nav-tab <?php if ($shownPage === 'BackupsHelp') { echo "nav-tab-active"; } ?>" href="admin.php?page=BackupsHelp"><?php _e('Help','mainwp'); ?></a>
            <?php if ($shownPage == 'ManageBackupsEdit') {  ?><a class="nav-tab pos-nav-tab nav-tab-active" href="#"><?php _e('Edit','mainwp'); ?></a><?php } ?>
            <?php
            if (isset(self::$subPages) && is_array(self::$subPages))
            {
                foreach (self::$subPages as $subPage)
                {
                ?>
                    <a class="nav-tab pos-nav-tab <?php if ($shownPage === $subPage['slug']) { echo "nav-tab-active"; } ?>" href="admin.php?page=ManageBackups<?php echo $subPage['slug']; ?>"><?php echo $subPage['title']; ?></a>
                <?php
                }
            }
            ?>
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

    /**
     * @param $pBackupTasks
     * @return bool
     */
    public static function validateBackupTasks($pBackupTasks)
    {
        if (!is_array($pBackupTasks)) return true;

        $nothingChanged = true;
        foreach ($pBackupTasks as $backupTask)
        {
            if ($backupTask->groups == '')
            {
                //Check if sites exist
                $newSiteIds = '';
                $siteIds = ($backupTask->sites == '' ? array() : explode(',', $backupTask->sites));
                foreach ($siteIds as $siteId)
                {
                    $site = MainWPDB::Instance()->getWebsiteById($siteId);
                    if (!empty($site)) $newSiteIds .= ',' . $siteId;
                }

                $newSiteIds = trim($newSiteIds, ',');

                if ($newSiteIds != $backupTask->sites)
                {
                    $nothingChanged = false;
                    MainWPDB::Instance()->updateBackupTaskWithValues($backupTask->id, array('sites' => $newSiteIds));
                }
            }
            else
            {
                //Check if groups exist
                $newGroupIds = '';
                $groupIds = explode(',', $backupTask->groups);
                foreach ($groupIds as $groupId)
                {
                    $group = MainWPDB::Instance()->getGroupById($groupId);
                    if (!empty($group)) $newGroupIds .= ',' . $groupId;
                }
                $newGroupIds = trim($newGroupIds, ',');

                if ($newGroupIds != $backupTask->groups)
                {
                    $nothingChanged = false;
                    MainWPDB::Instance()->updateBackupTaskWithValues($backupTask->id, array('groups' => $newGroupIds));
                }
            }

        }

        return $nothingChanged;
    }

    public static function renderManager()
    {
        $backupTask = null;
        if (isset($_GET['id']) && MainWPUtility::ctype_digit($_GET['id']))
        {
            $backupTaskId = $_GET['id'];

            $backupTask = MainWPDB::Instance()->getBackupTaskById($backupTaskId);
            if (!MainWPUtility::can_edit_backuptask($backupTask))
            {
                $backupTask = null;
            }

            if ($backupTask != null)
            {
                if (!self::validateBackupTasks(array($backupTask)))
                {
                    $backupTask = MainWPDB::Instance()->getBackupTaskById($backupTaskId);
                }
            }
        }

        if ($backupTask == null)
        {
            self::renderHeader(''); ?>
            <div class="mainwp_info-box"><strong><?php _e('Use these backup tasks to run backups at different times on different days. To backup a site right away please go to the','mainwp'); ?> <a href="<?php echo admin_url(); ?>admin.php?page=managesites"><?php _e('Sites page','mainwp'); ?></a> <?php _e('and select Backup Now.','mainwp'); ?></strong></div>
            <div id="mainwp_managebackups_content">
                <div id="mainwp_managebackups_add_errors" class="mainwp_error error"></div>
                <div id="mainwp_managebackups_add_message" class="mainwp_updated updated" style="display: <?php if (isset($_GET['a']) && $_GET['a'] == '1') { echo 'block'; } else { echo 'none'; } ?>"><?php if (isset($_GET['a']) && $_GET['a'] == '1') { echo __('<p>The backup task was added successfully</p>','mainwp'); } ?></div>
                <p></p>
                <?php
            self::$sitesTable->prepare_items();
          ?>
        <div id="mainwp_managebackups_content">
            <form method="post" class="mainwp-table-container">
              <input type="hidden" name="page" value="sites_list_table">
              <?php
            MainWPManageSitesView::_renderNotes();
            self::$sitesTable->display();
            self::$sitesTable->clear_items();
                ?>
            </form>
        </div>
                <div id="managebackups-task-status-box" title="Running task" style="display: none; text-align: center">
                    <div style="height: 190px; overflow: auto; margin-top: 20px; margin-bottom: 10px; text-align: left" id="managebackups-task-status-text">
                    </div>
                    <input id="managebackups-task-status-close" type="button" name="Close" value="<?php _e('Cancel','mainwp'); ?>" class="button" />
                </div>
            </div>
            <?php
            self::renderFooter('');
        }
        else
        {
            MainWPManageBackups::renderEdit($backupTask);
        }
    }

    public static function renderEdit($task)
    {
        self::renderHeader('ManageBackupsEdit'); ?>
        <div id="mainwp_managebackups_add_errors" class="mainwp_error error"></div>
        <div id="mainwp_managebackups_add_message" class="mainwp_updated updated" style="display: none"></div>
        <div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
        <div id="ajax-information-zone" class="updated" style="display: none;"></div>
        <div id="mainwp_managbackups_cont">
            <form method="POST" action="" id="mainwp_managebackups_add_form">
                <input type="hidden" name="mainwp_managebackups_edit_id" id="mainwp_managebackups_edit_id" value="<?php echo $task->id ?>" />
                <?php
                MainWPManageBackups::renderNewEdit($task);
                ?>
                <p class="submit"><input type="button" name="mainwp_managebackups_update" id="mainwp_managebackups_update" class="button-primary" value="<?php _e('Update Task','mainwp'); ?>"  /></p>
            </form>
        </div>
        <?php
        self::renderFooter('ManageBackupsEdit');
    }

    public static function renderNew()
    {
        self::renderHeader('AddNew'); ?>
        <div class="mainwp_info-box"><strong><?php _e('Use these backup tasks to run backups at different times on different days. To backup a site right away please go to the','mainwp'); ?> <a href="<?php echo admin_url(); ?>admin.php?page=managesites"><?php _e('Sites page','mainwp'); ?></a> <?php _e('and select Backup Now.','mainwp'); ?></strong></div>
        <div id="mainwp_managebackups_add_errors" class="mainwp_error error"></div>
        <div id="mainwp_managebackups_add_message" class="mainwp_updated updated" style="display: none"></div>
        <div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
        <div id="ajax-information-zone" class="updated" style="display: none;"></div>
        <div id="mainwp_managbackups_cont">
            <form method="POST" action="" id="mainwp_managebackups_add_form">
                <?php
                MainWPManageBackups::renderNewEdit(null);
                ?>
                <p class="submit"><input type="button" name="mainwp_managebackups_add" id="mainwp_managebackups_add" class="button-primary" value="<?php _e('Add New Task','mainwp'); ?>"  /></p>
            </form>
        </div>
        <?php
        self::renderFooter('AddNew');
    }

    public static function renderNewEdit($task)
    {
        $selected_websites = array();
        $selected_groups = array();
        if ($task != null)
        {
            if ($task->sites != '')
                $selected_websites = explode(',', $task->sites);
            if ($task->groups != '')
                $selected_groups = explode(',', $task->groups);
        }

        $remote_destinations = apply_filters('mainwp_backups_remote_get_destinations', null, ($task != null ? array('task' => $task->id) : array()));
        $hasRemoteDestinations = ($remote_destinations == null ? $remote_destinations : count($remote_destinations));

        ?>
        <div class="mainwp_managbackups_taskoptions">
        <?php
            //to add CSS Styling to the select sites box use the one below (this adds the css class mainwp_select_sites_box_right to the box)
            //MainWPUI::select_sites_box(__("Select Sites"), 'checkbox', true, true, 'mainwp_select_sites_box_right', '', $selected_websites, $selected_groups);
        ?>
        <div class="mainwp_info-box-yellow" style="float: right; width: 240px;"><?php _e('We recommend only scheduling 1 site per backup, multiples sites can cause unintended issues.','mainwp'); ?></div>
        <?php MainWPUI::select_sites_box(__("Select Sites", 'mainwp'), 'checkbox', true, true, 'mainwp_select_sites_box_right', 'float: right !important; clear: both;', $selected_websites, $selected_groups, true); ?>
        <div class="mainwp_config_box_left">

        <div class="postbox">
        <h3 class="mainwp_box_title"><span><?php _e('Schedule Backup','mainwp'); ?></span></h3>
        <div class="inside">
        <table class="form-table" style="width: 100%">
            <tr class="form-field form-required">
                <th scope="row"><?php _e('Task Name:','mainwp'); ?></th>
                <td><input type="text" id="mainwp_managebackups_add_name" name="mainwp_managebackups_add_name" value="<?php echo (isset($task) ? $task->name : ''); ?>" /><span class="mainwp-form_hint">e.g. Site1 Daily, Site1 Full Weekly, ...</span></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Task Schedule:','mainwp'); ?></th>
                <td><a class="mainwp_action left backuptaskschedule <?php echo (!isset($task) || $task->schedule == 'daily' ? 'mainwp_action_down' : ''); ?>" href="#" id="mainwp_managebackups_schedule_daily"><?php _e('DAILY','mainwp'); ?></a><a class="mainwp_action mid backuptaskschedule <?php echo (isset($task) && $task->schedule == 'weekly' ? 'mainwp_action_down' : ''); ?>" href="#" id="mainwp_managebackups_schedule_weekly"><?php _e('WEEKLY','mainwp'); ?></a><a class="mainwp_action right backuptaskschedule <?php echo (isset($task) && $task->schedule == 'monthly' ? 'mainwp_action_down' : ''); ?>" href="#" id="mainwp_managebackups_schedule_monthly"><?php _e('MONTHLY','mainwp'); ?></a></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Backup File Name:','mainwp'); ?></th>
                <td><input type="text" name="backup_filename" id="backup_filename" value="<?php echo (isset($task) ? $task->filename : ''); ?>" /><span class="mainwp-form_hint" style="display: inline; max-width: 500px;">Allowed Structure Tags: <strong>%url%</strong>, <strong>%date%</strong>, <strong>%time%</strong>, <strong>%type%</strong></span>
                </td>
            </tr>
            <tr><td colspan="2"><hr /></td></tr>
            <tr>
                <th scope="row"><?php _e('Backup Type:','mainwp'); ?></th>
                <td>
                    <a class="mainwp_action left <?php echo (!isset($task) || $task->type != 'db' ? 'mainwp_action_down' : ''); ?>" href="#" id="backup_type_full"><?php _e('FULL BACKUP','mainwp'); ?></a><a class="mainwp_action right <?php echo (isset($task) && $task->type == 'db' ? 'mainwp_action_down' : ''); ?>" href="#" id="backup_type_db"><?php _e('DATABASE BACKUP','mainwp'); ?></a>
                </td>
            </tr>
            <tr class="mainwp_backup_exclude_files_content" <?php echo (isset($task) && $task->type == 'db' ? 'style="display: none;"' : ''); ?>><td colspan="2"><hr /></td></tr>
            <tr class="mainwp_backup_exclude_files_content" <?php echo (isset($task) && $task->type == 'db' ? 'style="display: none;"' : ''); ?>>
                <th scope="row" style="vertical-align: top"><?php _e('Exclude files', 'mainwp'); ?>:</th>
                <td>
                    <span class="mainwp-form_hint" style="display: inline; max-width: 650px;"><?php _e('The Backup will attempt to backup the files below. Exclude any files that you do not need backed up for this site.', 'mainwp'); ?></span>
                    <br />
                    <br />
                    <br />
                    <?php _e('Click directories to navigate or click to exclude.','mainwp'); ?>
                    <table class="mainwp_excluded_folders_cont">
                        <tr>
                            <td style="width: 280px">
                                <div id="backup_exclude_folders" class="mainwp_excluded_folders"></div>
                            </td>
                            <td>
                                <?php _e('Excluded files & directories:','mainwp'); ?><br/>
                                <textarea id="excluded_folders_list"><?php
                                                                            $excluded = (isset($task) ? $task->exclude : "");
                                                                            if ($excluded != '')
                                                                            {
                                                                                $excluded = explode(',', $excluded);
                                                                                echo implode("/\n", $excluded) . "/\n";
                                                                            }
                                                                        ?></textarea>
                            </td>
                        </tr>
                    </table>
                    <span class="description"><strong><?php _e('ATTENTION:','mainwp'); ?></strong> <?php _e('Do not exclude any folders if you are using this backup to clone or migrate the wordpress installation.','mainwp'); ?></span>
                </td>
            </tr>
            <tr><td colspan="2"><hr /></td></tr>
            <?php
                if ($hasRemoteDestinations !== null)
                {
            ?>
            <tr>
                <th scope="row"><?php _e('Store Backup In:','mainwp'); ?></th>
                <td>
                    <a class="mainwp_action left <?php echo (!$hasRemoteDestinations ? 'mainwp_action_down' : ''); ?>" href="#" id="backup_location_local"><?php _e('LOCAL SERVER ONLY','mainwp'); ?></a><a class="mainwp_action right <?php echo ($hasRemoteDestinations ? 'mainwp_action_down' : ''); ?>" href="#" id="backup_location_remote"><?php _e('REMOTE DESTINATION','mainwp'); ?></a>
                </td>
            </tr>
            <tr class="mainwp_backup_destinations" <?php echo (!$hasRemoteDestinations ? 'style="display: none;"' : ''); ?>>
                <th scope="row"><?php _e('Backup Subfolder:','mainwp'); ?></th>
                <td><input type="text" id="mainwp_managebackups_add_subfolder" name="backup_subfolder"
                                                       value="<?php echo (isset($task) ? $task->subfolder : 'MainWP Backups/%url%/%type%/%date%'); ?>"/><span class="mainwp-form_hint" style="display: inline; max-width: 500px;">Allowed Structure Tags: <strong>%sitename%</strong>, <strong>%url%</strong>, <strong>%date%</strong>, <strong>%task%</strong>, <strong>%type%</strong></span></td>
            </tr>
            <?php
                }
            ?>
            <?php do_action('mainwp_backups_remote_settings', array('task' => $task)); ?>
        </table>
        </div>
        </div>
        </div>
        <div class="clear"></div>

        </div>
        <?php
        if ($task != null)
        {
        ?>
            <input type="hidden" id="backup_task_id" value="<?php echo $task->id; ?>" />
            <script>mainwp_managebackups_updateExcludefolders();</script>
        <?php
        }
    }

    public static function updateBackup()
    {
        global $current_user;
        $name = $_POST['name'];
        if ($name == '')
        {
            die(json_encode(array('error' => 'Please enter a valid name for your backup task')));
        }
        $backupId = $_POST['id'];
        $task = MainWPDB::Instance()->getBackupTaskById($backupId);
        if (!MainWPUtility::can_edit_backuptask($task))
        {
            die(json_encode(array('error' => 'This is not your task')));
        }

        $schedule = $_POST['schedule'];
        $type = $_POST['type'];
        $excludedFolder = trim($_POST['exclude'], "\n");
        $excludedFolder = explode("\n", $excludedFolder);
        $excludedFolder = array_map(array('MainWPUtility', 'trimSlashes'), $excludedFolder);
        $excludedFolder = implode(",", $excludedFolder);
        $sites = '';
        $groups = '';
        if (isset($_POST['sites']))
        {
            foreach ($_POST['sites'] as $site)
            {
                if ($sites != '')
                    $sites .= ',';
                $sites .= $site;
            }
        }
        if (isset($_POST['groups']))
        {
            foreach ($_POST['groups'] as $group)
            {
                if ($groups != '')
                    $groups .= ',';
                $groups .= $group;
            }
        }

        do_action('mainwp_update_backuptask', $task->id);

        if (MainWPDB::Instance()->updateBackupTask($task->id, $current_user->ID, $name, $schedule, $type, $excludedFolder, $sites, $groups, $_POST['subfolder'], $_POST['filename'], 0, '', '', '', '', '', 0, 0, '', '', '', '', 0, '', '', '') === false)
        {
            die(json_encode(array('error' => 'An unspecified error occured.')));
        }
        else
        {
            die(json_encode(array('result' => 'The backup task was updated successfully')));
        }
    }

    public static function addBackup()
    {
        global $current_user;
        $name = $_POST['name'];
        if ($name == '')
        {
            die(json_encode(array('error' => 'Please enter a valid name for your backup task')));
        }
        $schedule = $_POST['schedule'];
        $type = $_POST['type'];
        $excludedFolder = trim($_POST['exclude'], "\n");
        $excludedFolder = explode("\n", $excludedFolder);
        $excludedFolder = array_map(array('MainWPUtility', 'trimSlashes'), $excludedFolder);
        $excludedFolder = implode(",", $excludedFolder);

        $sites = '';
        $groups = '';
        if (isset($_POST['sites']))
        {
            foreach ($_POST['sites'] as $site)
            {
                if ($sites != '')
                    $sites .= ',';
                $sites .= $site;
            }
        }
        if (isset($_POST['groups']))
        {
            foreach ($_POST['groups'] as $group)
            {
                if ($groups != '')
                    $groups .= ',';
                $groups .= $group;
            }
        }

        $task = MainWPDB::Instance()->addBackupTask($current_user->ID, $name, $schedule, $type, $excludedFolder, $sites, $groups, (isset($_POST['subfolder']) ? $_POST['subfolder'] : ''), $_POST['filename']);
        if (!$task)
        {
            die(json_encode(array('error' => 'An unspecified error occured.')));
        }
        else
        {
            do_action('mainwp_add_backuptask', $task->id);

            die(json_encode(array('result' => 'The backup task was added successfully')));
        }
    }

    public static function executeBackupTask($task, $nrOfSites = 0, $updateRun = true)
    {
        if ($updateRun) MainWPDB::Instance()->updateBackupRun($task->id);
        MainWPDB::Instance()->updateBackupLast($task->id);

        $task = MainWPDB::Instance()->getBackupTaskById($task->id);

        $completed_sites = $task->completed_sites;

        if ($completed_sites != '') $completed_sites = json_decode($completed_sites, true);
        if (!is_array($completed_sites)) $completed_sites = array();

        $sites = array();

        if ($task->groups == '') {
            if ($task->sites != '') $sites = explode(',', $task->sites);
        }
        else
        {
            $groups = explode(',', $task->groups);
            foreach ($groups as $groupid)
            {
                $group_sites = MainWPDB::Instance()->getWebsitesByGroupId($groupid);
                foreach ($group_sites as $group_site)
                {
                    $sites[] = $group_site->id;
                }
            }
        }
        $errorOutput = '';

        if ($updateRun && (get_option('mainwp_notificationOnBackupStart') == 1))
        {
            $email = MainWPDB::Instance()->getUserNotificationEmail($task->userid);
            if ($email != '')
            {
                $output = 'A scheduled backup has started with MainWP on ' . MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp(time())) . ' for the following ' . count($sites) . ' sites:<br />' ;

                foreach ($sites as $siteid)
                {
                    $website = MainWPDB::Instance()->getWebsiteById($siteid);
                    $output .= '&nbsp;&bull;&nbsp;<a href="' . $website->url . '">' . MainWPUtility::getNiceURL($website->url) . '</a><br />';
                }

                $output .= '<br />Backup Details:<br /><br />';
                $output .= '<strong>Backup Task</strong>' . ' - ' . $task->name . '<br />';
                $output .= '<strong>Backup Type</strong>' . ' - ' . ($task->type == 'db' ? 'DATABASE BACKUP' : 'FULL BACKUP') . '<br />';
                $output .= '<strong>Backup Schedule</strong>' . ' - ' . strtoupper($task->schedule) . '<br />';

                wp_mail($email, 'A Scheduled Backup has been Started - MainWP', MainWPUtility::formatEmail($email, $output), 'content-type: text/html');
            }
        }

        $currentCount = 0;
        foreach ($sites as $siteid)
        {
            if (isset($completed_sites[$siteid]) && ($completed_sites[$siteid] == true)) continue;
            $website = MainWPDB::Instance()->getWebsiteById($siteid);

            try
            {
                $subfolder = str_replace('%task%', MainWPUtility::sanitize($task->name), $task->subfolder);

                $backupResult = MainWPManageSites::backupSite($siteid, $task->userid, $task->type, $task->exclude, $task->id, $subfolder, $task->filename);
                $error = false;
                $tmpErrorOutput = '';
                if (isset($backupResult['error']))
                {
                    $tmpErrorOutput .= $backupResult['error'] . '<br />';
                    $error = true;
                }
                if (isset($backupResult['ftp']) && $backupResult['ftp'] != 'success')
                {
                    $tmpErrorOutput .= 'FTP: '.$backupResult['ftp'] . '<br />';
                    $error = true;
                }
                if (isset($backupResult['dropbox']) && $backupResult['dropbox'] != 'success')
                {
                    $tmpErrorOutput .= 'Dropbox: '.$backupResult['dropbox'] . '<br />';
                    $error = true;
                }
                if (isset($backupResult['amazon']) && $backupResult['amazon'] != 'success')
                {
                    $tmpErrorOutput .= 'Amazon: '.$backupResult['amazon'] . '<br />';
                    $error = true;
                }

                if ($error)
                {
                    $errorOutput .= 'Site: <strong>'.MainWPUtility::getNiceURL($website->url). '</strong><br />';
                    $errorOutput .= $tmpErrorOutput . '<br />';
                }
            }
            catch (Exception $e)
            {
                $errorOutput .= 'Site: <strong>'.MainWPUtility::getNiceURL($website->url). '</strong><br />';
                $errorOutput .= MainWPErrorHelper::getErrorMessage($e) . '<br />';
            }

            $currentCount++;

            $task = MainWPDB::Instance()->getBackupTaskById($task->id);

            $completed_sites = $task->completed_sites;

            if ($completed_sites != '') $completed_sites = json_decode($completed_sites, true);
            if (!is_array($completed_sites)) $completed_sites = array();

            $completed_sites[$siteid] = true;
            MainWPDB::Instance()->updateCompletedSites($task->id, $completed_sites);

            if (($nrOfSites != 0) && ($nrOfSites == $currentCount)) break;
        }

        //update completed sites
        MainWPDB::Instance()->updateBackupErrors($task->id, $errorOutput);

        if (count($completed_sites) == count($sites))
        {
            MainWPDB::Instance()->updateBackupCompleted($task->id);

            if (get_option('mainwp_notificationOnBackupFail') == 1)
            {
                $email = MainWPDB::Instance()->getUserNotificationEmail($task->userid);
                if ($email != '')
                {
                    $task = MainWPDB::Instance()->getBackupTaskById($task->id);
                    if ($task->backup_errors != '')
                    {
                        $errorOutput = 'Errors occurred while executing task: <strong>' . $task->name . '</strong><br /><br />' . $task->backup_errors;

                        wp_mail($email, 'A Scheduled Backup had an Error - MainWP', MainWPUtility::formatEmail($email, $errorOutput), 'content-type: text/html');
                    }
                }
            }
        }

        return ($errorOutput == '');
    }

    public static function backup($pTaskId, $pSiteId, $pFileNameUID)
    {
        $backupTask = MainWPDB::Instance()->getBackupTaskById($pTaskId);

        $subfolder = str_replace('%task%', MainWPUtility::sanitize($backupTask->name), $backupTask->subfolder);

        return MainWPManageSites::backup($pSiteId, $backupTask->type, $subfolder, $backupTask->exclude, $backupTask->filename, $pFileNameUID);
    }

    public static function getBackupTaskSites($pTaskId)
    {
        $sites = array();
        $backupTask = MainWPDB::Instance()->getBackupTaskById($pTaskId);
        if ($backupTask->groups == '') {
            if ($backupTask->sites != '') $sites = explode(',', $backupTask->sites);
        }
        else
        {
            $groups = explode(',', $backupTask->groups);
            foreach ($groups as $groupid)
            {
                $group_sites = MainWPDB::Instance()->getWebsitesByGroupId($groupid);
                foreach ($group_sites as $group_site)
                {
                    $sites[] = $group_site->id;
                }
            }
        }

        $allSites = array();
        foreach ($sites as $site)
        {
            $website = MainWPDB::Instance()->getWebsiteById($site);
            $allSites[] = array('id' => $website->id, 'name' => $website->name, 'fullsize' => $website->totalsize * 1024, 'dbsize' => $website->dbsize);
        }

        $remoteDestinations = apply_filters('mainwp_backuptask_remotedestinations', array(), $backupTask);
        MainWPDB::Instance()->updateBackupRunManually($pTaskId);
        return array('sites' => $allSites, 'remoteDestinations' => $remoteDestinations);
    }

    public static function getSiteDirectories()
    {
        $websites = array();
        if (isset($_REQUEST['site']) && ($_REQUEST['site'] != ''))
        {
            $siteId = $_REQUEST['site'];
            $website = MainWPDB::Instance()->getWebsiteById($siteId);
            if (MainWPUtility::can_edit_website($website)) $websites[] = $website;
        }
        else if (isset($_REQUEST['sites']) && ($_REQUEST['sites'] != ''))
        {
            $siteIds = explode(',', urldecode($_REQUEST['sites']));
            $siteIdsRequested = array();
            foreach ($siteIds as $siteId)
            {
                $siteId = $siteId;

                if (!MainWPUtility::ctype_digit($siteId)) continue;
                $siteIdsRequested[] = $siteId;
            }

            $websites = MainWPDB::Instance()->getWebsitesByIds($siteIdsRequested);
        }
        else if (isset($_REQUEST['groups']) && ($_REQUEST['groups'] != ''))
        {
            $groupIds = explode(',', urldecode($_REQUEST['groups']));
            $groupIdsRequested = array();
            foreach ($groupIds as $groupId)
            {
                $groupId = $groupId;

                if (!MainWPUtility::ctype_digit($groupId)) continue;
                $groupIdsRequested[] = $groupId;
            }

            $websites = MainWPDB::Instance()->getWebsitesByGroupIds($groupIdsRequested);
        }

        if (count($websites) == 0) die('<i><strong>Select a site or group first</strong></i>'); //Nothing selected!

        $allFiles = array();
        foreach ($websites as $website)
        {
            $files = null;

            $result = json_decode($website->directories, TRUE);
            $dir = urldecode($_POST['dir']);

            if ($dir == '') {
                if (is_array($result)) $files = array_keys($result);
            }
            else
            {
                $dirExploded = explode('/', $dir);

                $tmpResult = $result;
                foreach ($dirExploded as $innerDir)
                {
                    if ($innerDir == '') continue;

                    if (isset($tmpResult[$innerDir]))
                    {
                        $tmpResult = $tmpResult[$innerDir];
                    }
                    else {
                        $tmpResult = null;
                        break;
                    }
                }
                if ($tmpResult != null && is_array($tmpResult)) $files = array_keys($tmpResult);
                else $files = null;
            }

            if (($files != null) && (count($files) > 0))
            {
               $allFiles = array_unique(array_merge($allFiles, $files));
            }
        }

        if ($allFiles != null && count($allFiles) > 0 ) {
            natcasesort($allFiles);
            echo "<ul class=\"jqueryFileTree\" style=\"display: none;\">";
            // All dirs
            foreach( $allFiles as $file ) {
                echo "<li class=\"directory collapsed\"><a href=\"#\" rel=\"" . htmlentities($_POST['dir'] . $file) . "/\">" . htmlentities($file) . "<div title=\"Exclude form backup\" class=\"exclude_folder_control\"><img src=\"" . plugins_url('images/exclude.png', dirname(__FILE__)) . "\" /></div></a></li>";
            }
            echo "</ul>";
        }
    }

    public static function removeBackup()
    {
        if (isset($_POST['id']) && MainWPUtility::ctype_digit($_POST['id']))
        {
            $task = MainWPDB::Instance()->getBackupTaskById($_POST['id']);
            if (MainWPUtility::can_edit_backuptask($task))
            {
                //Remove from DB
                MainWPDB::Instance()->removeBackupTask($task->id);
                die(json_encode(array('result' => 'SUCCESS')));
            }
        }
        die(json_encode(array('notask' => true)));
    }

    public static function resumeBackup()
    {
        if (isset($_POST['id']) && MainWPUtility::ctype_digit($_POST['id']))
        {
            $task = MainWPDB::Instance()->getBackupTaskById($_POST['id']);
            if (MainWPUtility::can_edit_backuptask($task))
            {
                MainWPDB::Instance()->updateBackupTaskWithValues($task->id, array('paused' => 0));
                die(json_encode(array('result' => 'SUCCESS')));
            }
        }
        die(json_encode(array('notask' => true)));
    }

    public static function pauseBackup()
    {
        if (isset($_POST['id']) && MainWPUtility::ctype_digit($_POST['id']))
        {
            $task = MainWPDB::Instance()->getBackupTaskById($_POST['id']);
            if (MainWPUtility::can_edit_backuptask($task))
            {
                MainWPDB::Instance()->updateBackupTaskWithValues($task->id, array('paused' => 1));
                die(json_encode(array('result' => 'SUCCESS')));
            }
        }
        die(json_encode(array('notask' => true)));
    }

    public static function getMetaboxName()
    {
        return 'Backups';
    }

    public static function renderMetabox()
    {
        $website = MainWPUtility::get_current_wpid();
        if (!$website) return;

        $website = MainWPDB::Instance()->getWebsiteById($website);

        MainWPManageSites::showBackups($website);
        ?>
        <hr />
        <div style="text-align: center;"><a href="<?php echo admin_url('admin.php?page=managesites&backupid='.$website->id); ?>" class="button-primary"><?php _e('Backup Now','mainwp'); ?></a></div>
        <?php
    }

    public static function QSGManageBackups() {
        self::renderHeader('BackupsHelp');
    ?><div style="text-align: center"><a href="#" class="button button-primary" id="mainwp-quick-start-guide"><?php _e('Show Quick Start Guide','mainwp'); ?></a></div>
                      <div  class="mainwp_info-box-yellow" id="mainwp-qsg-tips">
                          <span><a href="#" class="mainwp-show-qsg" number="1"><?php _e('Scheduling a Backup Task','mainwp') ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg"  number="2"><?php _e('Backup Remote Destinations','mainwp') ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg"  number="3"><?php _e('How to execute backups in chunks','mainwp') ?></a></span><span><a href="#" id="mainwp-qsg-dismiss" style="float: right;"><?php _e('Dismiss','mainwp'); ?></a></span>
                      <div class="clear"></div>
                      <div id="mainwp-qsgs">
                        <div class="mainwp-qsg" number="1">
                            <h3>Scheduling a Backup Task</h3>
                            <p>
                                <ol>
                                    <li>
                                        Enter a Task Name
                                    </li>
                                    <li>
                                        Set the backup frequency
                                    </li>
                                    <li>
                                        Enter a Backup File Name
                                    </li>
                                    <li>
                                        Choose a Backup Type <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/12/backup-type.png" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        In case you donâ€™t need all files to be included in backup, click the EXCLUDE FILES link, this will show your site file map and you will be able to easily exclude any files and folders<br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/12/exclude-files.png" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Choose where do you want to keep you backup <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/12/backup-server.png" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Select sites you want to backup
                                    </li>
                                    <li>
                                        Click the Add New Task button.
                                    </li>
                                </ol>
                            </p>
                        </div>
                        <div class="mainwp-qsg" number="2">
                            <h3>Backup Remote Destination</h3>
                            <p><h4>Dropbox</h4>
                                <ol>
                                    <li>
                                        If you want to use external storage, click the Remote Destination button when setting a backup <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/12/remote-destination.png" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Enter destination Title and Directory <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/12/dropbox-settings.png" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Click the Connect to Dropbox button<br/>
                                        <em>Click on this button will open the Dropbox login screen, enter your login details and click the Sign In button</em>
                                    </li>
                                    <li>
                                        Once you Sign In, Dropbox will ask you if you want to allow MainWP to access your Dropbox. Click the Allow button. <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/12/drobbox-allow.png" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        After you get the success message, return to dashboard and click the Yes, Iâ€™ve authorized MainWP to Dropbox button <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/12/dropbox-authorized.png" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Click the Test Settings button, if it returns success message click the Save Destination button.
                                    </li>
                                </ol>
                            </p>
                            <p><h4>Amazon S3</h4>
                                <ol>
                                    <li>
                                        After choosing Remote Destination for keeping your backups, to select the Amazon S3, click the Add button next to the Amazon icon <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/12/amazon-add.png" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Settings fields will appear, here you need to provide a few details for proper use of the external source <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/12/amazon-settings.png" style="wight: 100% !important;" alt="screenshot"/>
                                        <ol>
                                            <li>Destination title, something that will help you to manage your locations easier in future</li>
                                            <li>Access Key ID and Secret Key, provided by Amazon in your account</li>
                                            <li>Bucket, default backups bucket</li>
                                            <li>Sub-directory</li>
                                        </ol>
                                    </li>
                                    <li>
                                        Once you have added all necessary info, Click the Test Settings button. If it returns the success message, click the Save Settings button and you are ready to use your Amazon S3 bucket.
                                    </li>
                                </ol>
                            </p>
                            <p><h4>FTP</h4>
                                <ol>
                                    <li>
                                        After choosing Remote Destination for keeping your backups, to use the remote FTP location, click the Add button next to the FTP icon <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/12/ftp-add.png" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                    <li>
                                        Settings fields will appear, you need to enter following <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/12/amazon-settings.png" style="wight: 100% !important;" alt="screenshot"/>
                                        <ol>
                                            <li>Title</li>
                                            <li>Server address</li>
                                            <li>Server port</li>
                                            <li>Username</li>
                                            <li>Password</li>
                                            <li>Remote path</li>
                                        </ol>
                                    </li>
                                    <li>
                                        Also you have options to use SSL and Active Mode. When done with settings, use the Test Settings button to check if you have entered correct info. If the success message is returned, click the Save Settings button and you are ready to go
                                    </li>
                                </ol>
                            </p>
                        </div>
                        <div class="mainwp-qsg" number="3">
                            <h3>Execute Backups in Chunks</h3>
                            <p>
                                <ol>
                                    <li>
                                        Go to the Settings page and set the â€œExecute backuptasks in chunksâ€ option to YES <br/><br/>
                                        <img src="http://docs.mainwp.com/wp-content/uploads/2013/11/settings-chunk-backups-1024x227.jpg" style="wight: 100% !important;" alt="screenshot"/>
                                    </li>
                                </ol>
                            </p>
                        </div>
                      </div>
                    </div>
    <?php
    self::renderFooter('BackupsHelp');
    }
}
?>
