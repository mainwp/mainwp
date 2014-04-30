<?php
class MainWPExtensions
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static $extensionsLoaded = false;
    public static $extensions;

    public static function getPluginSlug($pSlug)
    {
        $currentExtensions = (self::$extensionsLoaded ? self::$extensions : get_option('mainwp_extensions'));

        if (!is_array($currentExtensions) || empty($currentExtensions)) return $pSlug;

        foreach ($currentExtensions as $extension)
        {
            if (isset($extension['api']) && ($extension['api'] == $pSlug))
            {
                return $extension['slug'];
            }
        }

        return $pSlug;
    }

    public static function getSlugs()
    {
        $currentExtensions = (self::$extensionsLoaded ? self::$extensions : get_option('mainwp_extensions'));

        if (!is_array($currentExtensions) || empty($currentExtensions)) return '';

        $out = '';
        foreach ($currentExtensions as $extension)
        {
            if (!isset($extension['api']) || $extension['api'] == '') continue;

            if ($out != '') $out .= ',';
            $out .= $extension['api'];
        }

        return ($out == '' ? '' : $out);
    }

    public static function init()
    {
        add_action('mainwp-pageheader-extensions', array(MainWPExtensions::getClassName(), 'renderHeader'));
        add_action('mainwp-pagefooter-extensions', array(MainWPExtensions::getClassName(), 'renderFooter'));
    }

    public static function initMenu()
    {
        MainWPExtensionsView::initMenu();

        self::$extensions = array();

        $newExtensions = apply_filters('mainwp-getextensions', array());
        $extraHeaders = array('IconURI' => 'Icon URI');
        foreach ($newExtensions as $extension)
        {
            $slug = plugin_basename($extension['plugin']);
            $plugin_data = get_plugin_data($extension['plugin']);
            $file_data = get_file_data($extension['plugin'], $extraHeaders);
            if (!isset($plugin_data['Name']) || ($plugin_data['Name'] == '')) continue;

            $extension['slug'] = $slug;
            $extension['name'] = $plugin_data['Name'];
            $extension['version'] = $plugin_data['Version'];
            $extension['description'] = $plugin_data['Description'];
            $extension['author'] = $plugin_data['Author'];
            $extension['iconURI'] = $file_data['IconURI'];
            $extension['page'] = 'Extensions-' . str_replace(' ', '-', ucwords(str_replace('-', ' ', dirname($slug))));

            self::$extensions[] = $extension;
            if (isset($extension['callback'])) add_submenu_page('mainwp_tab', $extension['name'], '<div class="mainwp-hidden">' . $extension['name'] . '</div>', 'read', $extension['page'], $extension['callback']);			
        }
        update_option("mainwp_extensions", self::$extensions);
        self::$extensionsLoaded = true;
    }

    public static function initMenuSubPages()
    {
        //if (true) return;
        if (empty(self::$extensions)) return;
		$html = "";
		if (isset(self::$extensions) && is_array(self::$extensions))
            {
                foreach (self::$extensions as $extension)
                {
                    if (MainWPExtensions::isExtensionEnabled($extension['plugin'])) { 
                        if (isset($extension['direct_page'])) {
                            $html .= '<a href="' . admin_url('admin.php?page=' . $extension['direct_page']) . '"
                               class="mainwp-submenu">' . $extension['name'] . '</a>';
                        } else {
                            $html .= '<a href="' . admin_url('admin.php?page=' . $extension['page']) . '"
                               class="mainwp-submenu">' . $extension['name'] . '</a>';                    
                        }
                    }
                }
            }			
		if (empty($html))	
			return;
        ?>
		<div id="menu-mainwp-Extensions" class="mainwp-submenu-wrapper" xmlns="http://www.w3.org/1999/html">
			<div class="wp-submenu sub-open" style="">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<?php echo $html; ?>
				</div>
			</div>
		</div>
    <?php
    }

    public static function initAjaxHandlers()
    {
        add_action('wp_ajax_mainwp_extension_enable_all', array(MainWPExtensions::getClassName(), 'enableAllExtensions'));
        add_action('wp_ajax_mainwp_extension_disable_all', array(MainWPExtensions::getClassName(), 'disableAllExtensions'));
        add_action('wp_ajax_mainwp_extension_enable', array(MainWPExtensions::getClassName(), 'enableExtension'));
        add_action('wp_ajax_mainwp_extension_disable', array(MainWPExtensions::getClassName(), 'disableExtension'));
        add_action('wp_ajax_mainwp_extension_trash', array(MainWPExtensions::getClassName(), 'trashExtension'));
    }

    public static function enableAllExtensions()
    {
        $snEnabledExtensions = array();

        foreach ($_POST['slugs'] as $slug)
        {
            $snEnabledExtensions[] = $slug;
        }

        update_option('mainwp_extloaded', $snEnabledExtensions);

        die(json_encode(array('result' => 'SUCCESS')));
    }

    public static function disableAllExtensions()
    {
        update_option('mainwp_extloaded', array());

        die(json_encode(array('result' => 'SUCCESS')));
    }

    public static function enableExtension()
    {
        $snEnabledExtensions = get_option('mainwp_extloaded');
        if (!is_array($snEnabledExtensions)) $snEnabledExtensions = array();

        $snEnabledExtensions[] = $_POST['slug'];

        update_option('mainwp_extloaded', $snEnabledExtensions);

        die(json_encode(array('result' => 'SUCCESS')));
    }

    public static function disableExtension()
    {
        $snEnabledExtensions = get_option('mainwp_extloaded');
        if (!is_array($snEnabledExtensions)) $snEnabledExtensions = array();

        $key = array_search($_POST['slug'], $snEnabledExtensions);

        if ($key !== false) unset($snEnabledExtensions[$key]);

        update_option('mainwp_extloaded', $snEnabledExtensions);

        die(json_encode(array('result' => 'SUCCESS')));
    }

    public static function trashExtension()
    {
        ob_start();
        $slug = $_POST['slug'];

        include_once(ABSPATH . '/wp-admin/includes/plugin.php');

        $thePlugin = get_plugin_data($slug);
        if ($thePlugin != null && $thePlugin != '') deactivate_plugins($slug);

        if (file_exists(ABSPATH . '/wp-admin/includes/screen.php')) include_once(ABSPATH . '/wp-admin/includes/screen.php');
        include_once(ABSPATH . '/wp-admin/includes/file.php');
        include_once(ABSPATH . '/wp-admin/includes/template.php');
        include_once(ABSPATH . '/wp-admin/includes/misc.php');
        include_once(ABSPATH . '/wp-admin/includes/class-wp-upgrader.php');
        include_once(ABSPATH . '/wp-admin/includes/class-wp-filesystem-base.php');
        include_once(ABSPATH . '/wp-admin/includes/class-wp-filesystem-direct.php');

        MainWPUtility::getWPFilesystem();
        global $wp_filesystem;
        if (empty($wp_filesystem)) $wp_filesystem = new WP_Filesystem_Direct(null);
        $pluginUpgrader = new Plugin_Upgrader();

        $thePlugin = get_plugin_data($slug);
        if ($thePlugin != null && $thePlugin != '')
        {
            $pluginUpgrader->delete_old_plugin(null, null, null, array('plugin' => $slug));
        }
        ob_end_clean();

        die(json_encode(array('result' => 'SUCCESS')));
    }

    public static function renderHeader($shownPage)
    {
        MainWPExtensionsView::renderHeader($shownPage, self::$extensions);
    }

    public static function renderFooter($shownPage)
    {
        MainWPExtensionsView::renderFooter($shownPage, self::$extensions);
    }

    public static function render()
    {
        self::renderHeader('');

        MainWPExtensionsView::render(self::$extensions);

        self::renderFooter('');
    }

    public static function isExtensionAvailable($api)
    {
        $ext_extensions = get_option('mainwp_extensions');
        if (isset($ext_extensions))
        {
            $snEnabledExtensions = get_option('mainwp_extloaded');
            if (!is_array($snEnabledExtensions)) $snEnabledExtensions = array();

            foreach($ext_extensions as $extension)
            {
                if (isset($extension['mainwp']) && ($extension['mainwp'] == true))
                {
                    if (isset($extension['api']) && ($extension['api'] == $api))
                    {
                        $slug = plugin_basename($extension['plugin']);

                        return in_array($slug, $snEnabledExtensions);
                    }
                }
            }
        }

        return false;
    }

    public static function isExtensionEnabled($pluginFile)
    {
        $slug = plugin_basename($pluginFile);
        $snEnabledExtensions = get_option('mainwp_extloaded');
        if (!is_array($snEnabledExtensions)) $snEnabledExtensions = array();

        $active = in_array($slug, $snEnabledExtensions);

        if (isset(self::$extensions))
        {
            foreach(self::$extensions as $extension)
            {
                if ($extension['plugin'] == $pluginFile)
                {
                    if (isset($extension['mainwp']) && ($extension['mainwp'] == true))
                    {
                        if (isset($extension['api']) && (MainWPAPISettings::testAPIs($extension['api']) != 'VALID'))
                        {
                            $active = false;
                        }
                    }
                    else
                    {
                        if (isset($extension['apilink']) && isset($extension['locked']) && ($extension['locked'] == true))
                        {
                            $active = false;
                        }
                    }
                    break;
                }
            }
        }

        if (!function_exists('wp_create_nonce')) include_once(ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'pluggable.php');
        return ($active ? array('key' => wp_create_nonce($pluginFile . '-SNNonceAdder')) : false);
    }

    public static function hookVerify($pluginFile, $key)
    {
        return (self::isExtensionEnabled($pluginFile) && (wp_verify_nonce($key, $pluginFile . '-SNNonceAdder') == 1));
    }

    public static function hookGetDashboardSites($pluginFile, $key)
    {
        if (!self::hookVerify($pluginFile, $key))
        {
            return null;
        }

        $current_wpid = MainWPUtility::get_current_wpid();

        if ($current_wpid)
        {
            $sql = MainWPDB::Instance()->getSQLWebsiteById($current_wpid);
        }
        else
        {
            $sql = MainWPDB::Instance()->getSQLWebsitesForCurrentUser();
        }

        return MainWPDB::Instance()->query($sql);
    }

    public static function hookFetchUrlsAuthed($pluginFile, $key, $dbwebsites, $what, $params, $handle, $output)
    {
        if (!self::hookVerify($pluginFile, $key))
        {
            return false;
        }

        return MainWPUtility::fetchUrlsAuthed($dbwebsites, $what, $params, $handle, $output);
    }

    public static function hookFetchUrlAuthed($pluginFile, $key, $websiteId, $what, $params)
    {
        if (!self::hookVerify($pluginFile, $key))
        {
            return false;
        }

        try
        {
            $website = MainWPDB::Instance()->getWebsiteById($websiteId);
            if (!MainWPUtility::can_edit_website($website)) throw new MainWPException('You can not edit this website.');

            return MainWPUtility::fetchUrlAuthed($website, $what, $params);
        }
        catch (MainWPException $e)
        {
            return array('error' => $e->getMessage());
        }
    }

    public static function hookGetDBSites($pluginFile, $key, $sites, $groups)
    {
        if (!self::hookVerify($pluginFile, $key))
        {
            return false;
        }

        $dbwebsites = array();
        if ($sites != '') {
            foreach ($sites as $k => $v) {
                if (MainWPUtility::ctype_digit($v)) {
                    $website = MainWPDB::Instance()->getWebsiteById($v);
                    $dbwebsites[$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
                }
            }
        }

        if ($groups != '') {
            foreach ($groups as $k => $v) {
                if (MainWPUtility::ctype_digit($v)) {
                    $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesByGroupId($v));
                    while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                    {
                        $dbwebsites[$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey'));
                    }
                    @MainWPDB::free_result($websites);
                }
            }
        }

        return $dbwebsites;
    }

    public static function hookGetSites($pluginFile, $key, $websiteid)
    {
        if (!self::hookVerify($pluginFile, $key))
        {
            return false;
        }

        if (isset($websiteid) && ($websiteid != null))
        {
            $website = MainWPDB::Instance()->getWebsiteById($websiteid);

            if (!MainWPUtility::can_edit_website($website)) return false;

            return array(array('id' => $websiteid, 'url' => MainWPUtility::getNiceURL($website->url, true), 'name' => $website->name, 'totalsize' => $website->totalsize));
        }


        $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
        $output = array();
        while ($websites && ($website = @MainWPDB::fetch_object($websites)))
        {
            $output[] = array('id' => $website->id, 'url' => MainWPUtility::getNiceURL($website->url, true), 'name' => $website->name, 'totalsize' => $website->totalsize);
        }
        @MainWPDB::free_result($websites);

        return $output;
    }

    public static function hookGetGroups($pluginFile, $key, $groupid)
    {
        if (!self::hookVerify($pluginFile, $key))
        {
            return false;
        }

        if (isset($groupid))
        {
            $group = MainWPDB::Instance()->getGroupById($groupid);
            if (!MainWPUtility::can_edit_group($group)) return false;

            $websites = MainWPDB::Instance()->getWebsitesByGroupId($group->id);
            $websitesOut = array();
            foreach ($websites as $website)
            {
                $websitesOut[] = $website->id;
            }
            return array(array('id' => $groupid, 'name' => $group->name, 'websites' => $websitesOut));
        }


        $groups = MainWPDB::Instance()->getGroupsAndCount();
        $output = array();
        foreach ($groups as $group)
        {
            $websites = MainWPDB::Instance()->getWebsitesByGroupId($group->id);
            $websitesOut = array();
            foreach ($websites as $website)
            {
                $websitesOut[] = $website->id;
            }
            $output[] = array('id' => $group->id, 'name' => $group->name, 'websites' => $websitesOut);
        }

        return $output;
    }
}