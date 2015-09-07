<?php

class MainWPChildScan
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function initMenu()
    {
        add_submenu_page('mainwp_tab', __('MainWP Child Scan','mainwp'), '<div class="mainwp-hidden">' .  __('MainWPC Child Scan','mainwp') . '</div>', 'read', 'MainWPChildScan', array(MainWPChildScan::getClassName(), 'render'));
    }

    public static function renderHeader($shownPage)
    {
        ?>
    <div class="wrap"><a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img
            src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50" alt="MainWP"/></a>
        <h2><i class="fa fa-server"></i> <?php _e('Child Scan','mainwp'); ?></h2><div style="clear: both;"></div><br/>

        <div class="clear"></div>
        <div class="wrap">
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
        self::renderHeader('');
        ?>
        <a class="button-primary mwp-child-scan" href="#"><?php _e('Scan', "mainwp"); ?></a>
        <?php
        $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
        if (!$websites) {
            echo __('<p>No websites to scan.</p>','mainwp');
        }
        else
        {
            ?>
            <table id="mwp_child_scan_childsites">
                <tr><th>Child</th><th>Status</th></tr>
            <?php
                while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                {
                    $imgfavi = "";
                    if ($website !== null) {
                        if (get_option('mainwp_use_favicon', 1) == 1)
                        {
                            $favi = MainWPDB::Instance()->getWebsiteOption($website, 'favi_icon', "");
                            $favi_url =     MainWPUtility::get_favico_url($favi, $website);
                            $imgfavi = '<img src="' . $favi_url . '" width="16" height="16" style="vertical-align:middle;"/>&nbsp;';
                        }
                    }

                    if ($website->sync_errors == '')
                    {
                        echo '<tr siteid="' . $website->id . '"><td title="' . $website->url . '">' . $imgfavi .  ' ' . stripslashes($website->name) . ':</td><td></td></tr>';
                    }
                    else
                    {
                        echo '<tr><td title="' . $website->url . '">' . $imgfavi . ' ' . stripslashes($website->name) . ':</td><td>Sync errors</td></tr>';
                    }
                }
                @MainWPDB::free_result($websites);
            ?>
            </table>
            <?php
        }
            ?>
    <?php
      self::renderFooter('');
    }

    public static function scan() {
        if (!isset($_POST['childId'])) die(json_encode(array('error' => 'Wrong request')));

        $website = MainWPDB::Instance()->getWebsiteById($_POST['childId']);
        if (!$website) die(json_encode(array('error' => 'Site not found')));

        try
        {
            $post_data = array(
                'search' => 'mainwp-child-id-*',
                'search_columns' => 'user_login,display_name,user_email'
            );

            $rslt = MainWPUtility::fetchUrlAuthed($website, 'search_users', $post_data);
            $usersfound = !(is_array($rslt) && count($rslt) == 0);

            if (!$usersfound)
            {
                //fallback to plugin search
                $post_data = array(
                    'keyword' => 'WordPress admin security'
                );

                $post_data['status'] = 'active';
                $post_data['filter'] = true;

                $rslt = MainWPUtility::fetchUrlAuthed($website, 'get_all_plugins', $post_data);

                $pluginfound = !(is_array($rslt) && count($rslt) == 0);

                if (!$pluginfound)
                {
                    die(json_encode(array('success' => 'No issues found.')));
                }
            }

            die(json_encode(array('success' => 'mainwp-child-id users found (<a href="http://docs.mainwp.com/mainwp-cleanup/" target="_blank">solution</a>)')));
        }
        catch (Exception $e)
        {
            die('error');
        }
    }
}