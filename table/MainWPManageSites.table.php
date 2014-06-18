<?php

if( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}


class MainWPManageSites_List_Table extends WP_List_Table
{
    protected $globalIgnoredPluginConflicts;
    protected $globalIgnoredThemeConflicts;

    function __construct()
    {
        parent::__construct(array(
            'singular' => __('site', 'mainwp'), //singular name of the listed records
            'plural' => __('sites', 'mainwp'), //plural name of the listed records
            'ajax' => true //does this table support ajax?

        ));

//        add_action('admin_head', array(&$this, 'admin_header'));
    }

//    function admin_header()
//    {
//        $page = (isset($_GET['page'])) ? esc_attr($_GET['page']) : false;
//        if ('my_list_test' != $page)
//            return;
//        echo '<style type="text/css">';
//        echo '.wp-list-table .column-id { width: 5%; }';
//        echo '.wp-list-table .column-booktitle { width: 40%; }';
//        echo '.wp-list-table .column-author { width: 35%; }';
//        echo '.wp-list-table .column-isbn { width: 20%;}';
//        echo '</style>';
//    }

    function no_items()
    {
        _e('No sites found.');
    }

    function column_default($item, $column_name)
    {

        $item = apply_filters('mainwp-sitestable-item', $item, $item);

        switch ($column_name)
        {
            case 'status':
            case 'site':
            case 'url':
            case 'groups':
            case 'backup':
            case 'last_sync':
            case 'last_post':
            case 'seo':
            case 'notes':
                return $item[$column_name];
            default:
                return $item[$column_name];
               // return print_r($item, true); //Show the whole array for troubleshooting purposes
        }
    }

    function get_sortable_columns()
    {
        $sortable_columns = array(
            'site' => array('site', false),
            'url' => array('url', false),
            'groups' => array('groups', false),
            'last_sync' => array('last_sync', false),
            'last_post' => array('last_post', false)
        );
        return $sortable_columns;
    }

    function get_columns()
    {
        $columns = array(
            'status' => __('Status', 'mainwp'),
            'site' => __('Site', 'mainwp'),
            'url' => __('URL', 'mainwp'),
            'groups' => __('Groups', 'mainwp'),
            'backup' => __('Backup', 'mainwp'),
            'last_sync' => __('Last Sync', 'mainwp'),
            'last_post' => __('Last Post', 'mainwp'),
            'seo' => __('SEO', 'mainwp'),
            'notes' => __('Notes', 'mainwp')
        );

        if (get_option('mainwp_seo') != 1) unset($columns['seo']);

        $columns = apply_filters('mainwp-sitestable-getcolumns', $columns, $columns);
        return $columns;
    }

    function column_status($item)
    {
        $pluginConflicts = json_decode($item['pluginConflicts'], true);
        $themeConflicts = json_decode($item['themeConflicts'], true);

        $ignoredPluginConflicts = json_decode($item['ignored_pluginConflicts'], true);
        if (!is_array($ignoredPluginConflicts)) $ignoredPluginConflicts = array();
        $ignoredThemeConflicts = json_decode($item['ignored_themeConflicts'], true);
        if (!is_array($ignoredThemeConflicts)) $ignoredThemeConflicts = array();

        $isConflict = false;
        if (count($pluginConflicts) > 0)
        {
            foreach ($pluginConflicts as $pluginConflict)
            {
                if (!in_array($pluginConflict, $ignoredPluginConflicts) && !in_array($pluginConflict, $this->globalIgnoredPluginConflicts)) $isConflict = true;
            }
        }

        if (!$isConflict && (count($themeConflicts) > 0))
        {
            foreach ($themeConflicts as $themeConflict)
            {
                if (!in_array($themeConflict, $ignoredThemeConflicts) && !in_array($themeConflict, $this->globalIgnoredThemeConflicts)) $isConflict = true;
            }
        }

        $hasSyncErrors = ($item['sync_errors'] != '');

        $output = '';
        $cnt = 0;
        if ($item['offline_check_result'] == 1 && !$hasSyncErrors && !$isConflict)
        {
            $websiteCore = json_decode($item['wp_upgrades'], true);
            if (isset($websiteCore['current'])) $cnt++;

            $websitePlugins = json_decode($item['plugin_upgrades'], true);
            if (is_array($websitePlugins)) $cnt += count($websitePlugins);

            $websiteThemes = json_decode($item['theme_upgrades'], true);
            if (is_array($websiteThemes)) $cnt += count($websiteThemes);

            if ($cnt > 0)
            {
                $output .= '<span class="mainwp-av-updates-col"> ' . $cnt . '</span>';
            }
        }

        $output .= '
       <img class="down-img down-img-align" title="Site is Offline" src="' . plugins_url('images/down.png', dirname(__FILE__)) . '" ' . ($item['offline_check_result'] == -1 && !$hasSyncErrors && !$isConflict ? '' : 'style="display:none;"') . ' />
       <img class="up-img up-img-align" title="Plugin or Theme Conflict Found" src="' . plugins_url('images/conflict.png', dirname(__FILE__)) . '" ' . (!$hasSyncErrors && $isConflict ? '' : 'style="display:none;"') . '/>
       <img class="up-img up-img-align" title="Site is Online" src="' . plugins_url('images/up.png', dirname(__FILE__)) . '" ' . ($item['offline_check_result'] == 1 && !$hasSyncErrors && !$isConflict && ($cnt == 0) ? '' : 'style="display:none;"'). '/>
       <img class="up-img up-img-align" title="Site Disconnected" src="' .  plugins_url('images/disconnected.png', dirname(__FILE__)) . '" ' . ($hasSyncErrors ? '' : 'style="display:none;"') . '/>
       ';

        return $output;
    }

    function column_site($item)
    {
        $actions = array(
            'dashboard' => sprintf('<a href="admin.php?page=managesites&dashboard=%s">' . __('Dashboard', 'mainwp') . '</a>', $item['id']),
            'edit' => sprintf('<a href="admin.php?page=managesites&id=%s">' . __('Edit', 'mainwp') . '</a>', $item['id']),
            'delete' => sprintf('<a class="submitdelete" href="#" onClick="return managesites_remove('."'".'%s'."'".');">' . __('Delete', 'mainwp') . '</a>', $item['id'])
        );

        if ($item['sync_errors'] != '')
        {
            $actions['reconnect'] = sprintf('<a class="mainwp_site_reconnect" href="#" siteid="%s">' . __('Reconnect', 'mainwp') . '</a>', $item['id']);
        }

        return sprintf('<a href="admin.php?page=managesites&dashboard=%s" id="mainwp_notes_%s_url">%s</a>%s', $item['id'], $item['id'], $item['name'], $this->row_actions($actions));
    }

    function column_url($item)
    {
        $actions = array(
            'open' => sprintf('<a href="admin.php?page=SiteOpen&websiteid=%1$s">' . __('Open WP Admin', 'mainwp') . '</a> (<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=%1$s" target="_blank">' . __('New Window', 'mainwp') . '</a>)', $item['id']),
            'test' => '<a href="#" class="mainwp_site_testconnection">' . __('Test Connection', 'mainwp') . '</a> <span style="display: none;"><img src="' . plugins_url('images/loading.gif', dirname(__FILE__)) . '""/>' . __('Testing Connection', 'mainwp') . '</span>'
        );
        $actions = apply_filters('mainwp_managesites_column_url', $actions, $item['id']); 
        return sprintf('<strong><a target="_blank" href="%1$s">%1$s</a></strong>%2$s', $item['url'], $this->row_actions($actions));
    }

    function column_backup($item)
    {
        $dir = MainWPUtility::getMainWPSpecificDir($item['id']);
        $lastbackup = 0;
        if (file_exists($dir) && ($dh = opendir($dir)))
        {
            while (($file = readdir($dh)) !== false)
            {
                if ($file != '.' && $file != '..')
                {
                    $theFile = $dir . $file;
                    if (preg_match('/(.*)\.zip/', $file) && !preg_match('/(.*).sql.zip$/', $file))
                    {
                        if (filemtime($theFile) > $lastbackup) $lastbackup = filemtime($theFile);
                    }
                }
            }
            closedir($dh);
        }

        $output = '';
        if ($lastbackup > 0) $output = MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($lastbackup)) . '<br />';
        else $output = '<span class="mainwp-red">Never</span><br/>';
        $output .= sprintf('<a href="admin.php?page=managesites&backupid=%s">' . __('Backup Now','mainwp') . '</a>', $item['id']);

        return $output;
    }

    function column_last_sync($item)
    {
        $output = '';
        if ($item['dtsSync'] != 0) $output = MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($item['dtsSync'])) . '<br />';
        $output .= sprintf('<a href="admin.php?page=managesites&dashboard=%s&refresh=yes">' . __('Sync Data', 'mainwp') . '</a>', $item['id']);

        return $output;
    }

    function column_last_post($item)
    {
        $output = '';
        if ($item['last_post_gmt'] != 0) $output .= MainWPUtility::formatTimestamp(MainWPUtility::getTimestamp($item['last_post_gmt'])) . '<br />';
        $output .= sprintf('<a href="admin.php?page=PostBulkAdd&select=%s">' . __('Add New', 'mainwp') . '</a>', $item['id']);

        return $output;
    }

    function column_seo($item)
    {
        return sprintf('<a href="admin.php?page=managesites&seowebsiteid=%s">' . __('SEO', 'mainwp') . '</a>', $item['id']);
    }

    function column_notes($item)
    {
        return sprintf('<img src="' . plugins_url('images/notes.png', dirname(__FILE__)) . '" class="mainwp_notes_img" id="mainwp_notes_img_%1$s" style="%2$s"/> <a href="#" class="mainwp_notes_show_all" id="mainwp_notes_%1$s">' . __('Open','mainwp') . '</a><span style="display: none" id="mainwp_notes_%1$s_note">%3$s</span>', $item['id'], ($item['note'] == '' ? 'display: none;' : ''), $item['note']);
    }

//    function get_bulk_actions()
//    {
//        $actions = array(
//            'delete' => 'Delete'
//        );
//        return $actions;
//    }

//    function column_cb($item)
//    {
//        return sprintf(
//            '<input type="checkbox" name="book[]" value="%s" />', $item['id']
//        );
//    }

    function prepare_items($globalIgnoredPluginConflicts = array(), $globalIgnoredThemeConflicts = array())
    {
        $this->globalIgnoredPluginConflicts = $globalIgnoredPluginConflicts;
        $this->globalIgnoredThemeConflicts = $globalIgnoredThemeConflicts;

        $orderby = 'wp.url';

        if (isset($_GET['orderby']))
        {
            if (($_GET['orderby'] == 'site'))
            {
                $orderby = 'wp.name ' . ($_GET['order'] == 'asc' ? 'asc' : 'desc');
            }
            else if (($_GET['orderby'] == 'url'))
            {
                $orderby = 'wp.url ' . ($_GET['order'] == 'asc' ? 'asc' : 'desc');
            }
            else if (($_GET['orderby'] == 'group'))
            {
                $orderby = 'GROUP_CONCAT(gr.name ORDER BY gr.name SEPARATOR ", ") ' . ($_GET['order'] == 'asc' ? 'asc' : 'desc');
            }
            else if (($_GET['orderby'] == 'status'))
            {
                $orderby = 'CASE true
                                WHEN ((pluginConflicts <> "[]") AND (pluginConflicts IS NOT NULL) AND (pluginConflicts <> ""))
                                    THEN 1
                                WHEN (offline_check_result = -1)
                                    THEN 2
                                WHEN (sync_errors IS NOT NULL) AND (sync_errors <> "")
                                    THEN 3
                                ELSE 4
                                    + (CASE plugin_upgrades WHEN "[]" THEN 0 ELSE 1 + LENGTH(plugin_upgrades) - LENGTH(REPLACE(plugin_upgrades, "\"Name\":", "\"Name\"")) END)
                                    + (CASE theme_upgrades WHEN "[]" THEN 0 ELSE 1 + LENGTH(theme_upgrades) - LENGTH(REPLACE(theme_upgrades, "\"Name\":", "\"Name\"")) END)
                                    + (CASE wp_upgrades WHEN "[]" THEN 0 ELSE 1 END)
                            END ' . ($_GET['order'] == 'asc' ? 'asc' : 'desc');
            }
            else if (($_REQUEST['orderby'] == 'last_post'))
            {
                $orderby = 'wp.last_post_gmt ' . ($_GET['order'] == 'asc' ? 'asc' : 'desc');
            }
        }

        $perPage = $this->get_items_per_page('mainwp_managesites_per_page');
        $currentPage = $this->get_pagenum();


        $where = null;
        if (isset($_REQUEST['status']) && ($_REQUEST['status'] != ''))
        {
            if ($_REQUEST['status'] == 'online')
            {
                $where = 'wp.offline_check_result = 1';
            }
            else if ($_REQUEST['status'] == 'offline')
            {
                $where = 'wp.offline_check_result = -1';
            }
            else if ($_REQUEST['status'] == 'disconnected')
            {
                $where = 'wp.sync_errors != ""';
            }
            else if ($_REQUEST['status'] == 'update')
            {
                $where = '(wp.wp_upgrades != "[]" OR wp.plugin_upgrades != "[]" OR wp.theme_upgrades != "[]")';
            }
        }

        if (isset($_REQUEST['g']) && ($_REQUEST['g'] != ''))
        {
            $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesByGroupId($_REQUEST['g'], true));
            $totalRecords = ($websites ? MainWPDB::num_rows($websites) : 0);

            if ($websites) @MainWPDB::free_result($websites);
            if (isset($_GET['orderby']) && ($_GET['orderby'] == 'group')) $orderby = 'wp.url';
            $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesByGroupId($_REQUEST['g'], true, $orderby, (($currentPage - 1) * $perPage), $perPage, $where));
        }
        else if (isset($_REQUEST['status']) && ($_REQUEST['status'] != ''))
        {
            $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser(true, null, $orderby, false, false, $where));
            $totalRecords = ($websites ? MainWPDB::num_rows($websites) : 0);

            if ($websites) @MainWPDB::free_result($websites);
            $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser(true,  null, $orderby, (($currentPage - 1) * $perPage), $perPage, $where));
        }
        else
        {
            $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser(true, (isset($_REQUEST['s']) && ($_REQUEST['s'] != '') ? $_REQUEST['s'] : null), $orderby));
            $totalRecords = ($websites ? MainWPDB::num_rows($websites) : 0);

            if ($websites) @MainWPDB::free_result($websites);
            $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser(true, (isset($_REQUEST['s']) && ($_REQUEST['s'] != '') ? $_REQUEST['s'] : null), $orderby, (($currentPage - 1) * $perPage), $perPage));
        }

        $this->set_pagination_args(array(
            'total_items' => $totalRecords, //WE have to calculate the total number of items
            'per_page' => $perPage //WE have to determine how many items to show on a page
        ));
        $this->items = $websites;
    }

    function clear_items()
    {
        if (MainWPDB::is_result($this->items)) @MainWPDB::free_result($this->items);
    }

    function display_rows()
    {
        if (MainWPDB::is_result($this->items))
        {
            while ($this->items && ($item = @MainWPDB::fetch_array($this->items)))
            {
                $this->single_row( $item );
            }
        }
   	}

    function single_row( $item )
    {
   		static $row_class = '';
   		$row_class = ( $row_class == '' ? ' class="alternate"' : '' );

   		echo '<tr' . $row_class . ' siteid="'.$item['id'].'">';
   		$this->single_row_columns( $item );
   		echo '</tr>';
   	}

    function extra_tablenav( $which )
    {
        ?>
    <div class="alignleft actions">
        <form method="GET" action="">
            <input type="hidden" value="<?php echo $_REQUEST['page']; ?>" name="page"/>
            <input type="text" value="<?php echo (isset($_REQUEST['s']) ? $_REQUEST['s'] : ''); ?>"
                   autocompletelist="sites" name="s" class="mainwp_autocomplete"/>
            <datalist id="sites">
                <?php
                if (MainWPDB::is_result($this->items))
                {
                    while ($this->items && ($item = @MainWPDB::fetch_array($this->items)))
                    {
                        echo '<option>' . $item['name'] . '</option>';
                    }

                    MainWPDB::data_seek($this->items, 0);
                }
                ?>
            </datalist>
            <input type="submit" value="<?php _e('Search Sites'); ?>" class="button" name=""/>
        </form>
    </div>

    <div class="alignleft actions">
        <form method="GET" action="">
            <input type="hidden" value="<?php echo $_REQUEST['page']; ?>" name="page"/>
            <select name="g">
                <option value="">Select a group</option>
                <?php
                $groups = MainWPDB::Instance()->getGroupsForCurrentUser();
                foreach ($groups as $group)
                {
                    echo '<option value="' . $group->id . '" ' . (isset($_REQUEST['g']) && $_REQUEST['g'] == $group->id ? 'selected' : '') . '>' . $group->name . '</option>';
                }
                ?>
            </select>

            <input type="hidden" value="<?php echo $_REQUEST['page']; ?>" name="page"/>
            <select name="status">
                <option value="">Select a status</option>
                <option value="online" <?php echo (isset($_REQUEST['status']) && $_REQUEST['status'] == 'online' ? 'selected' : ''); ?>>Online</option>
                <option value="offline" <?php echo (isset($_REQUEST['status']) && $_REQUEST['status'] == 'offline' ? 'selected' : ''); ?>>Offline</option>
                <option value="disconnected" <?php echo (isset($_REQUEST['status']) && $_REQUEST['status'] == 'disconnected' ? 'selected' : ''); ?>>Disconnected</option>
                <option value="update" <?php echo (isset($_REQUEST['status']) && $_REQUEST['status'] == 'update' ? 'selected' : ''); ?>>Available update</option>
            </select>
            <input type="submit" value="<?php _e('Display'); ?>" class="button" name="">
        </form>
    </div>
    <?php
    }

} //class