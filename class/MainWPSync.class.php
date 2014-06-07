<?php

class MainWPSync
{
    public static function syncSite(&$pWebsite = null)
    {
        if ($pWebsite == null) return false;
        $userExtension = MainWPDB::Instance()->getUserExtensionByUserId($pWebsite->userid);
        if ($userExtension == null) return false;

        MainWPUtility::endSession();

        $emptyArray = json_encode(array());
        $websiteValues = array(
            'directories' => $emptyArray,
            'sync_errors' => '',
            'wp_upgrades' => $emptyArray,
            'plugin_upgrades' => $emptyArray,
            'theme_upgrades' => $emptyArray,
            'premium_upgrades' => $emptyArray,
            'uptodate' => 0,
            'securityIssues' => $emptyArray,
            'recent_comments' => $emptyArray,
            'recent_posts' => $emptyArray,
            'recent_pages' => $emptyArray,
            'themes' => $emptyArray,
            'plugins' => $emptyArray,
            'users' => $emptyArray,
            'categories' => $emptyArray,
            'pluginConflicts' => $emptyArray,
            'themeConflicts' => $emptyArray
        );

        $error = false;
        try
        {
            $pluginDir = $pWebsite->pluginDir;
            if ($pluginDir == '') $pluginDir = $userExtension->pluginDir;

            $cloneEnabled = apply_filters('mainwp_clone_enabled', false);
            $cloneSites = array();
            if ($cloneEnabled)
            {
                $disallowedCloneSites = get_option('mainwp_clone_disallowedsites');
                if ($disallowedCloneSites === false) $disallowedCloneSites = array();
                $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
                if ($websites)
                {
                    while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                    {
                        if (in_array($website->id, $disallowedCloneSites)) continue;
                        if ($website->id == $pWebsite->id) continue;

                        $cloneSites[$website->id] = array('name' => $website->name,
                            'url' => $website->url,
                            'extauth' => $website->extauth,
                            'size' => $website->totalsize);
                    }
                    @MainWPDB::free_result($websites);
                }
            }

            $pluginConflicts = get_option('mainwp_pluginConflicts');
            if ($pluginConflicts !== false)
            {
                $pluginConflicts = array_keys($pluginConflicts);
            }

            $themeConflicts = get_option('mainwp_themeConflicts');
            if ($themeConflicts !== false)
            {
                $themeConflicts = array_keys($themeConflicts);
            }

            $information = MainWPUtility::fetchUrlAuthed($pWebsite, 'stats',
                array(
                    'optimize' => ((get_option("mainwp_optimize") == 1) ? 1 : 0),
                    'heatMap' => (MainWPExtensions::isExtensionAvailable('mainwp-heatmap-extension') ? $userExtension->heatMap : 0),
                    'pluginDir' => $pluginDir,
                    'cloneSites' => (!$cloneEnabled ? 0 : urlencode(json_encode($cloneSites))),
                    'pluginConflicts' => json_encode($pluginConflicts),
                    'themeConflicts' => json_encode($themeConflicts)
                ),
                true
            );

            return self::syncInformationArray($pWebsite, $information);
        }
        catch (MainWPException $e)
        {
            $sync_errors = '';
            $offline_check_result = 1;

            if ($e->getMessage() == 'HTTPERROR')
            {
                $sync_errors = __('HTTP error','mainwp') . ($e->getMessageExtra() != null ? ' - ' . $e->getMessageExtra() : '');
                $offline_check_result = -1;
            }
            else if ($e->getMessage() == 'NOMAINWP')
            {
                $sync_errors = __('MainWP not detected','mainwp');
                $offline_check_result = 1;
            }

            return self::syncInformationArray($pWebsite, $information, $sync_errors, $offline_check_result, true);
        }
    }

    public static function syncInformationArray(&$pWebsite, &$information, $sync_errors = '', $offline_check_result = 1, $error = false)
    {
        $emptyArray = json_encode(array());
        $websiteValues = array(
            'directories' => $emptyArray,
            'sync_errors' => $sync_errors,
            'wp_upgrades' => $emptyArray,
            'plugin_upgrades' => $emptyArray,
            'theme_upgrades' => $emptyArray,
            'premium_upgrades' => $emptyArray,
            'uptodate' => 0,
            'securityIssues' => $emptyArray,
            'recent_comments' => $emptyArray,
            'recent_posts' => $emptyArray,
            'recent_pages' => $emptyArray,
            'themes' => $emptyArray,
            'plugins' => $emptyArray,
            'users' => $emptyArray,
            'categories' => $emptyArray,
            'pluginConflicts' => $emptyArray,
            'themeConflicts' => $emptyArray,
            'offline_check_result' => $offline_check_result
        );

        $done = false;

        if (isset($information['siteurl']))
        {
            $websiteValues['siteurl'] = $information['siteurl'];
            $done = true;
        }

        if (isset($information['directories']) && is_array($information['directories']))
        {
            $websiteValues['directories'] = json_encode($information['directories']);
            $done = true;
        }

        if (isset($information['wp_updates']) && $information['wp_updates'] != null)
        {
            $websiteValues['wp_upgrades'] = json_encode(array('current' => $information['wpversion'], 'new' => $information['wp_updates']));
            $done = true;
        }

        if (isset($information['plugin_updates']))
        {
            $websiteValues['plugin_upgrades'] = json_encode($information['plugin_updates']);
            $done = true;
        }

        if (isset($information['theme_updates']))
        {
            $websiteValues['theme_upgrades'] = json_encode($information['theme_updates']);
            $done = true;
        }

        if (isset($information['premium_updates']))
        {
            $websiteValues['premium_upgrades'] = json_encode($information['premium_updates']);
            $done = true;
        }

        if (isset($information['securityIssues']) && MainWPUtility::ctype_digit($information['securityIssues']) && $information['securityIssues'] >= 0)
        {
            $websiteValues['securityIssues'] = $information['securityIssues'];
            $done = true;
        }

        if (isset($information['recent_comments']))
        {
            $websiteValues['recent_comments'] = json_encode($information['recent_comments']);
            $done = true;
        }

        if (isset($information['recent_posts']))
        {
            $websiteValues['recent_posts'] = json_encode($information['recent_posts']);
            $done = true;
        }

        if (isset($information['recent_pages']))
        {
            $websiteValues['recent_pages'] = json_encode($information['recent_pages']);
            $done = true;
        }

        if (isset($information['themes']))
        {
            $websiteValues['themes'] = json_encode($information['themes']);
            $done = true;
        }

        if (isset($information['plugins']))
        {
            $websiteValues['plugins'] = json_encode($information['plugins']);
            $done = true;
        }

        if (isset($information['users']))
        {
            $websiteValues['users'] = json_encode($information['users']);
            $done = true;
        }

        if (isset($information['categories']))
        {
            $websiteValues['categories'] = json_encode($information['categories']);
            $done = true;
        }

        if (isset($information['totalsize']))
        {
            $websiteValues['totalsize'] = $information['totalsize'];
            $done = true;
        }

        if (isset($information['dbsize']))
        {
            $websiteValues['dbsize'] = $information['dbsize'];
            $done = true;
        }

        if (isset($information['extauth']))
        {
            $websiteValues['extauth'] = $information['extauth'];
            $done = true;
        }

        if (isset($information['pluginConflicts']))
        {
            $websiteValues['pluginConflicts'] = json_encode($information['pluginConflicts']);
            $done = true;
        }

        if (isset($information['themeConflicts']))
        {
            $websiteValues['themeConflicts'] = json_encode($information['themeConflicts']);
            $done = true;
        }

        if (isset($information['last_post_gmt']))
        {
            $websiteValues['last_post_gmt'] = $information['last_post_gmt'];
            $done = true;
        }

        if (isset($information['mainwpdir']))
        {
            $websiteValues['mainwpdir'] = $information['mainwpdir'];
            $done = true;
        }

        if (!$done)
        {
            if (isset($information['wpversion']))
            {
                $websiteValues['uptodate'] = 1;
            }
            else if (isset($information['error']))
            {
                $error = true;
                $websiteValues['sync_errors'] = __('Error - ', 'mainwp') . $information['error'];
            }
            else
            {
                $error = true;
                $websiteValues['sync_errors'] = __('Undefined error - please reinstall the MainWP Child Plugin on the client site', 'mainwp');
            }
        }


        $websiteValues['dtsSync'] = time();
        MainWPDB::Instance()->updateWebsiteValues($pWebsite->id, $websiteValues);

        //Sync action
        if (!$error) do_action('mainwp-site-synced', $pWebsite);

        return (!$error);
    }

    public static function statsUpdate($pSite = null)
    {
        //todo: implement
    }

    public static function offlineCheck($pSite = null)
    {
        //todo: implement

    }
}
