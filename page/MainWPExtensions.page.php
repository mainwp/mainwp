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

        if (!is_array($currentExtensions) || empty($currentExtensions)) return array('slugs' => '', 'am_slugs' => '');

        $out = '';
        $am_out = '';
        foreach ($currentExtensions as $extension)
        {
            if (!isset($extension['api']) || $extension['api'] == '') continue;
            
            if (isset($extension['apiManager']) && !empty($extension['apiManager']) && $extension['activated_key'] == 'Activated') {
                if ($am_out != '') $am_out .= ',';
                $am_out .= $extension['api'];
            } else {
                if ($out != '') $out .= ',';
                $out .= $extension['api'];
            }
        }

        return array('slugs' => $out, 'am_slugs' => $am_out);
    }
    
    
    public static function init()
    {
        add_action('mainwp-pageheader-extensions', array(MainWPExtensions::getClassName(), 'renderHeader'));
        add_action('mainwp-pagefooter-extensions', array(MainWPExtensions::getClassName(), 'renderFooter'));
        add_filter("mainwp-extensions-apigeneratepassword", array(MainWPExtensions::getClassName(), 'genApiPassword'), 10, 3);        
    }

    public static function initMenu()
    {
        MainWPExtensionsView::initMenu();

        self::$extensions = array();
        $all_extensions = array();
        
        $newExtensions = apply_filters('mainwp-getextensions', array());
        $extraHeaders = array('IconURI' => 'Icon URI', 'SupportForumURI' => 'Support Forum URI', 'DocumentationURI' => 'Documentation URI');
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
            $extension['SupportForumURI'] = $file_data['SupportForumURI'];
            $extension['DocumentationURI'] = $file_data['DocumentationURI'];
            $extension['page'] = 'Extensions-' . str_replace(' ', '-', ucwords(str_replace('-', ' ', dirname($slug))));
            
            if (isset($extension['apiManager']) && $extension['apiManager']) { 
                $api = dirname($slug);
                $options = get_option($api . "_APIManAdder");
                if (!is_array($options)) $options = array();  
                $extension['api_key'] = isset($options["api_key"]) ?  $options["api_key"] : "";
                $extension['activation_email'] = isset($options["activation_email"]) ?  $options["activation_email"] : "";
                $extension['activated_key'] = isset($options["activated_key"]) ?  $options["activated_key"] : "Deactivated";
                $extension['deactivate_checkbox'] = isset($options["deactivate_checkbox"]) ?  $options["deactivate_checkbox"] : "off";
                $extension['product_id'] = isset($options["product_id"]) ?  $options["product_id"] : "";
                $extension['instance_id'] = isset($options["instance_id"]) ?  $options["instance_id"] : "";
                $extension['software_version'] = isset($options["software_version"]) ?  $options["software_version"] : "";
            }

            $all_extensions[] = $extension;            
            if ((defined("MWP_TEAMCONTROL_PLUGIN_SLUG") && MWP_TEAMCONTROL_PLUGIN_SLUG == $slug) || 
                    mainwp_current_user_can("extension", dirname($slug))) {
                self::$extensions[] = $extension;  
                if (mainwp_current_user_can("extension", dirname($slug))) {
                    if (isset($extension['callback'])) add_submenu_page('mainwp_tab', $extension['name'], '<div class="mainwp-hidden">' . $extension['name'] . '</div>', 'read', $extension['page'], $extension['callback']);			            
                }
            }
        }        
        MainWPUtility::update_option("mainwp_extensions", self::$extensions);
        MainWPUtility::update_option("mainwp_manager_extensions", $all_extensions);        
        self::$extensionsLoaded = true;
    }

    public static function loadExtensions() {
        if (!isset(self::$extensions))  {          
            self::$extensions = get_option('mainwp_extensions');
            self::$extensionsLoaded = true;
        }
        return self::$extensions;
    }
    
    public static function genApiPassword($length = 12, $special_chars = true, $extra_special_chars = false) {
        $api_manager_password_management = new MainWPApiManagerPasswordManagement();      
        return $api_manager_password_management->generate_password($length, $special_chars, $extra_special_chars);        
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
                    
                        if (defined("MWP_TEAMCONTROL_PLUGIN_SLUG") && (MWP_TEAMCONTROL_PLUGIN_SLUG == $extension['slug']) && !mainwp_current_user_can("extension", dirname(MWP_TEAMCONTROL_PLUGIN_SLUG))) 
                                continue;
                                                          
                        if (isset($extension['direct_page'])) {
                            $html .= '<a href="' . admin_url('admin.php?page=' . $extension['direct_page']) . '"
                               class="mainwp-submenu">' . str_replace(array("Extension", "MainWP"), "", $extension['name']) . '</a>';
                        } else {
                            $html .= '<a href="' . admin_url('admin.php?page=' . $extension['page']) . '"
                               class="mainwp-submenu">' . str_replace(array("Extension", "MainWP"), "", $extension['name']) . '</a>';                    
                        }
                    }
                }
            }			
		if (empty($html))	
			return;
        ?>
		<div id="menu-mainwp-Extensions" class="mainwp-submenu-wrapper" xmlns="http://www.w3.org/1999/html">
			<div class="wp-submenu sub-open" style="">
				<div class="mainwp_boxout mainwp-submenu-wide">
					<div class="mainwp_boxoutin"></div><ul class="mainwp-submenu-ul">
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
        add_action('wp_ajax_mainwp_extension_activate', array(MainWPExtensions::getClassName(), 'activateExtension'));
        add_action('wp_ajax_mainwp_extension_deactivate', array(MainWPExtensions::getClassName(), 'deactivateExtension'));
        add_action('wp_ajax_mainwp_extension_testextensionapilogin', array(MainWPExtensions::getClassName(), 'testExtensionsApiLogin'));                

        if (mainwp_current_user_can("dashboard", "bulk_install_and_activate_extensions")) {    
            add_action('wp_ajax_mainwp_extension_grabapikey', array(MainWPExtensions::getClassName(), 'grabapikeyExtension'));
            add_action('wp_ajax_mainwp_extension_saveextensionapilogin', array(MainWPExtensions::getClassName(), 'saveExtensionsApiLogin'));                    
            add_action('wp_ajax_mainwp_extension_getpurchased', array(MainWPExtensions::getClassName(), 'getPurchasedExts'));        
            add_action('wp_ajax_mainwp_extension_downloadandinstall', array(MainWPExtensions::getClassName(), 'downloadAndInstall'));        
            add_action('wp_ajax_mainwp_extension_bulk_activate', array(MainWPExtensions::getClassName(), 'bulkActivate'));        
            add_action('wp_ajax_mainwp_extension_apisslverifycertificate', array(MainWPExtensions::getClassName(), 'saveApiSSLVerify'));                    
        }
    }

    public static function enableAllExtensions()
    {
        $snEnabledExtensions = array();

        foreach ($_POST['slugs'] as $slug)
        {
            $snEnabledExtensions[] = $slug;
        }

        MainWPUtility::update_option('mainwp_extloaded', $snEnabledExtensions);

        die(json_encode(array('result' => 'SUCCESS')));
    }

    public static function disableAllExtensions()
    {
        MainWPUtility::update_option('mainwp_extloaded', array());

        die(json_encode(array('result' => 'SUCCESS')));
    }

    public static function enableExtension()
    {
        $snEnabledExtensions = get_option('mainwp_extloaded');
        if (!is_array($snEnabledExtensions)) $snEnabledExtensions = array();

        $snEnabledExtensions[] = $_POST['slug'];

        MainWPUtility::update_option('mainwp_extloaded', $snEnabledExtensions);

        die(json_encode(array('result' => 'SUCCESS')));
    }

    
    public static function activateExtension( ) {
        $api = dirname($_POST['slug']);                 
        $api_key = trim( $_POST['key'] );
        $api_email = trim( $_POST['email'] );
        $result = MainWPApiManager::instance()->license_key_activation($api, $api_key, $api_email);                      
        die(json_encode($result));
    }
    
    public static function deactivateExtension( ) {
        $api = dirname($_POST['slug']);
        $result = MainWPApiManager::instance()->license_key_deactivation($api);           
        die(json_encode($result));
    }
    
    
    public static function grabapikeyExtension( ) {        
        $username = trim( $_POST['username'] );
        $password = trim( $_POST['password'] );
        $api = dirname($_POST['slug']);
        $result = MainWPApiManager::instance()->grab_license_key($api, $username, $password);                       
        die(json_encode($result));
    }
    
    public static function saveExtensionsApiLogin() {        
        $username = trim( $_POST['username'] );
        $password = trim( $_POST['password'] );            
        if (($username == '') && ($password == ''))
        {
            MainWPUtility::update_option("mainwp_extensions_api_username", $username);
            MainWPUtility::update_option("mainwp_extensions_api_password", $password);
            die(json_encode(array('saved' => 1)));
        }
        $result = array();
        try {
            $test = MainWPApiManager::instance()->test_login_api($username, $password); 
        } catch (Exception $e) {            
            $return['error'] = $e->getMessage();  
            die(json_encode($return));
        }
        
        $result = json_decode($test, true);        
        $save_login = (isset($_POST['saveLogin']) && ($_POST['saveLogin'] == '1')) ? true : false;    
        $return = array();
        if (is_array($result)) {
            if (isset($result['success']) && $result['success']) {  
                if ($save_login) {
                    $enscrypt_u = MainWPApiManagerPasswordManagement::encrypt_string($username);
                    $enscrypt_p = MainWPApiManagerPasswordManagement::encrypt_string($password);                  
                    MainWPUtility::update_option("mainwp_extensions_api_username", $enscrypt_u);
                    MainWPUtility::update_option("mainwp_extensions_api_password", $enscrypt_p); 
                    MainWPUtility::update_option("mainwp_extensions_api_save_login", true);
                } 
                $return['result'] = 'SUCCESS';                                         
            } else if (isset($result['error'])){
                $return['error'] = $result['error'];                                     
            }    
        }
        
        if (!$save_login) {
            MainWPUtility::update_option("mainwp_extensions_api_username", "");
            MainWPUtility::update_option("mainwp_extensions_api_password", "");
            MainWPUtility::update_option("mainwp_extensions_api_save_login", "");
        }
        
        die(json_encode($return));                       
    }
    
    public static function saveApiSSLVerify() {  
        MainWPUtility::update_option("mainwp_api_sslVerifyCertificate", intval($_POST['api_sslverify']));                                                         
        die(json_encode(array('saved' => 1)));                       
    }
    
    
    public static function testExtensionsApiLogin() {  
        $username = trim( $_POST['username'] );
        $password = trim( $_POST['password'] );            
        if (($username == '') || ($password == ''))
        {            
            die(json_encode(array('error' => __('Login Invalid.','mainwp'))));
        }
        
        $enscrypt_u = get_option('mainwp_extensions_api_username');
        $enscrypt_p = get_option('mainwp_extensions_api_password');
        $username = !empty($enscrypt_u) ? MainWPApiManagerPasswordManagement::decrypt_string($enscrypt_u) : "";
        $password = !empty($enscrypt_p) ? MainWPApiManagerPasswordManagement::decrypt_string($enscrypt_p) : "";             
 
        $result = array();
        try {
            $test = MainWPApiManager::instance()->test_login_api($username, $password); 
        } catch (Exception $e) {            
            $return['error'] = $e->getMessage();  
            die(json_encode($return));
        }
        
        $result = json_decode($test, true);        
        $return = array();
        if (is_array($result)) {
            if (isset($result['success']) && $result['success']) {             
                $return['result'] = 'SUCCESS';                                         
            } else if (isset($result['error'])){
                $return['error'] = $result['error'];                                     
            }    
        }        
        die(json_encode($return));                       
    }
    
    
    public static function getPurchasedExts() {        
        $username = trim( $_POST['username'] );
        $password = trim( $_POST['password'] );            
        if (($username == '') || ($password == ''))
        {            
            die(json_encode(array('error' => __('Login Invalid.','mainwp'))));
        }
        
        $data = MainWPApiManager::instance()->get_purchased_software( $username, $password); 
        $result = json_decode($data, true);                               
        $return = array();
        if (is_array($result)) {
            if (isset($result['success']) && $result['success']) {                                  
                if (isset($result['purchased_data']) && is_array($result['purchased_data'])) {                    
                    self::loadExtensions();
                    $installed_softwares = array();
                    if (is_array(self::$extensions)) {
                        foreach(self::$extensions as $extension) {
                            if (isset($extension['product_id']) && !empty($extension['product_id'])) {
                                $installed_softwares[$extension['product_id']] = $extension['product_id'];
                            }
                        }
                    }                  
                    $purchased_data = $result['purchased_data'];
                    $purchased_data = array_diff_key($purchased_data, $installed_softwares);
                    $html = $message = '';
                    if (empty($purchased_data)) {
                        $message = __("All purchased extensions are Installed", "mainwp");
                    } else {
                        $html = '<div class="inside">';
                        $html .= "<h2>" . __("Installing Purchased Extensions ...", "mainwp") . "</h2><br />";                        
                        $html .= '<div class="mainwp_extension_installing">';
                        foreach($purchased_data as $software_title => $product_info) {
                            if (isset($product_info['error']) && $product_info['error'] == 'download_revoked') {
                                $html .= '<div><strong>' . $software_title . "</strong>: <p><span style=\"color: red;\"><strong>Error</strong>: " . MainWPApiManager::instance()->download_revoked_error_notice($software_title) . '</span></p></div>';
                            } else if (isset($product_info['package']) && !empty($product_info['package'])){
                                $html .= '<div class="extension_to_install" download-link="' . $product_info['package'] . '" status="queue" product-id="' . $software_title . '"><strong>' . $software_title . "</strong>: " . '<span class="ext_installing" status="queue"><i class="fa fa-spinner fa-pulse hidden"></i><br/><span class="status hidden"></span></span></div>';
                            }
                        }
                        $html .= '<div id="extBulkActivate"><i class="fa fa-spinner fa-pulse hidden"></i> <span class="status hidden"></span></div>';
                        $html .= '</div>';
                        $html .= '</div>';
                        $html .= '<script type="text/javascript">mainwp_extension_bulk_install();</script>';
                    }                    
                } else {
                    $message = __("Not found purchased extensions.", "mainwp");
                }
                $return= array('result' => 'SUCCESS', 'data' => $html , 'message' => $message, 'count' => count($purchased_data));                
            } else if (isset($result['error'])){
                $return= array('error' => $result['error']);                                         
            }    
        }                         
        die(json_encode($return));                       
    }
        
    public static function http_request_reject_unsafe_urls($r, $url)
    {
        $r['reject_unsafe_urls'] = false;
        return $r;
    }
    
    public static function noSSLFilterFunction($r, $url)
    {
        $r['sslverify'] = false;
        return $r;
    }
    
    public static function noSSLFilterExtensionUpgrade($r, $url)
    {
        if ((strpos($url, "am_download_file=") !== false) && (strpos($url, "am_email=")) !== false) {        
            $r['sslverify'] = false;
        }
        return $r;
    }
    
    public static function downloadAndInstall() {
        $return = self::installPlugin($_POST['download_link']);
        die('<mainwp>' . json_encode($return) . '</mainwp>');             
    }
    
    static function installPlugin($url)
    {
        $hasWPFileSystem = MainWPUtility::getWPFilesystem();
        global $wp_filesystem;

        if (file_exists(ABSPATH . '/wp-admin/includes/screen.php')) include_once(ABSPATH . '/wp-admin/includes/screen.php');
        include_once(ABSPATH . '/wp-admin/includes/template.php');
        include_once(ABSPATH . '/wp-admin/includes/misc.php');
        include_once(ABSPATH . '/wp-admin/includes/class-wp-upgrader.php');
        include_once(ABSPATH . '/wp-admin/includes/plugin.php');

        $installer = new WP_Upgrader();
        $ssl_verifyhost = get_option('mainwp_sslVerifyCertificate');        
        $ssl_api_verifyhost = ((get_option('mainwp_api_sslVerifyCertificate') === false) || (get_option('mainwp_api_sslVerifyCertificate') == 1)) ? 1 : 0; 
        
        if ($ssl_verifyhost === '0' || $ssl_api_verifyhost == 0)
        {                
            add_filter( 'http_request_args', array(MainWPExtensions::getClassName(), 'noSSLFilterFunction'), 99, 2);
        }
        
        add_filter('http_request_args', array(MainWPExtensions::getClassName(), 'http_request_reject_unsafe_urls'), 99, 2);
        
        $result = $installer->run(array(
            'package' => $url,
            'destination' => WP_PLUGIN_DIR,
            'clear_destination' => false, //overwrite files
            'clear_working' => true,
            'hook_extra' => array()
        ));               
        remove_filter( 'http_request_args', array(MainWPExtensions::getClassName(), 'http_request_reject_unsafe_urls') , 99, 2);
        if ($ssl_verifyhost === '0')
        {  
            remove_filter( 'http_request_args', array(MainWPExtensions::getClassName(), 'noSSLFilterFunction') , 99);
        }
        
        $error = $output = $plugin_slug = null;        
        if (is_wp_error($result))
        {
            $error = $result->get_error_codes();            
            if (is_array($error))
            {
                if ($error[0] == 'folder_exists') {
                    $error = __("Destination folder already exists.", "mainwp");
                } else 
                    $error = implode(', ', $error); 
            }            
        } else {                    
            $path = $result['destination'];
            foreach ($result['source_files'] as $srcFile)
            {
                // to fix bug
                if ($srcFile == "readme.txt")
                    continue;
                $thePlugin = get_plugin_data($path . $srcFile);                
                if ($thePlugin != null && $thePlugin != '' && $thePlugin['Name'] != '')
                {
                    $output .= "<p>" . __("Successfully installed the plugin", "mainwp") . " " . $thePlugin['Name'] . " " . $thePlugin['Version'] . "</p>";                   
                    $plugin_slug = $result['destination_name'] . "/" . $srcFile;
                    break;
                }
            }
        }                
       
        if (!empty($error)) {
            $return['error'] = $error;
        } else {
            $return['result'] = 'SUCCESS';
            $return['output'] = $output; 
            $return['slug'] = $plugin_slug; 
        }        
        return $return;        
    }
    
    public static function bulkActivate() {
        $plugins = $_POST['plugins'];
        if (is_array($plugins) && count($plugins) > 0) {
            if (current_user_can('activate_plugins') ) {
                activate_plugins($plugins);
                die('SUCCESS');
            }
        }            
        die('FAILED');
    }
    
    public static function disableExtension()
    {
        $snEnabledExtensions = get_option('mainwp_extloaded');
        if (!is_array($snEnabledExtensions)) $snEnabledExtensions = array();

        $key = array_search($_POST['slug'], $snEnabledExtensions);

        if ($key !== false) unset($snEnabledExtensions[$key]);

        MainWPUtility::update_option('mainwp_extloaded', $snEnabledExtensions);

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

    public static function isExtensionAvailable($pAPI)
    {
        $extensions = (self::$extensionsLoaded ? self::$extensions : get_option('mainwp_extensions'));         
        if (isset($extensions) && is_array($extensions))
        {
            foreach($extensions as $extension)
            {
                $slug = dirname($extension['slug']);
                if ($slug == $pAPI)
                {
                    $pluginFile = $extension['plugin'];
                    $result = self::isExtensionEnabled($pluginFile);                    
                    return ($result === false) ? false : true;
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

        // To fix bug
        self::loadExtensions();
        
        if (isset(self::$extensions))
        {                
            foreach(self::$extensions as $extension)
            {
                if ($extension['plugin'] == $pluginFile)
                {   
                    if (isset($extension['mainwp']) && ($extension['mainwp'] == true))
                    {                        
                        if (isset($extension['api_key']) && !empty($extension['api_key'])) {                            
                            if (isset($extension['activated_key']) && ($extension['activated_key'] == "Activated")) {                                                                     
                                $active = true;
                            } else {
                                $active = false;
                            }
                        } else if (isset($extension['api']) && (MainWPAPISettings::testAPIs($extension['api']) != 'VALID'))
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

        //return ($active ? array('key' => wp_create_nonce($pluginFile . '-SNNonceAdder')) : false);
        return ($active ? array('key' => md5($pluginFile . '-SNNonceAdder')) : false);
    }
   
    public static function create_nonce_function()
    {
    }

    public static function hookVerify($pluginFile, $key)
    {
        if (!function_exists('wp_create_nonce')) include_once(ABSPATH . WPINC . '/pluggable.php');
        return (self::isExtensionEnabled($pluginFile) && ((wp_verify_nonce($key, $pluginFile . '-SNNonceAdder') == 1) || (md5($pluginFile.'-SNNonceAdder') == $key)));
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

    //todo: implement correclty: MainWPDB::Instance()->getWebsiteOption($website, 'premium_upgrades')..
    private static $possible_options = array(
        'plugin_upgrades' => 'plugin_upgrades',
        'theme_upgrades' => 'theme_upgrades',
        'premium_upgrades' => 'premium_upgrades',
        'plugins' => 'plugins',
        'dtsSync' => 'dtsSync'
    );
    
    public static function hookGetDBSites($pluginFile, $key, $sites, $groups, $options = false)
    {
        if (!self::hookVerify($pluginFile, $key))
        {
            return false;
        }

        $dbwebsites = array();
        $data = array('id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey', 'verify_certificate');

        if (is_array($options))
        {
            foreach ($options as $option_name => $value)
            {
                if (($value === true) && isset(self::$possible_options[$option_name]))
                {
                    $data[] = self::$possible_options[$option_name];
                }
            }
        }

        if ($sites != '')
        {
            foreach ($sites as $k => $v) {
                if (MainWPUtility::ctype_digit($v)) {
                    $website = MainWPDB::Instance()->getWebsiteById($v);
                    $dbwebsites[$website->id] = MainWPUtility::mapSite($website, $data);
                }
            }
        }

        if ($groups != '')
        {
            foreach ($groups as $k => $v) {
                if (MainWPUtility::ctype_digit($v)) {
                    $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesByGroupId($v));
                    while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                    {
                        $dbwebsites[$website->id] = MainWPUtility::mapSite($website, $data);
                    }
                    @MainWPDB::free_result($websites);
                }
            }
        }

        return $dbwebsites;
    }

    public static function hookGetSites($pluginFile, $key, $websiteid, $for_manager = false)
    {       
        if (!self::hookVerify($pluginFile, $key))
        {
            return false;
        }        
        
        if ($for_manager && (!defined("MWP_TEAMCONTROL_PLUGIN_SLUG") || !mainwp_current_user_can("extension", dirname(MWP_TEAMCONTROL_PLUGIN_SLUG)))) {                                             
            return false;            
        }
       
        if (isset($websiteid) && ($websiteid != null))
        {
            $website = MainWPDB::Instance()->getWebsiteById($websiteid);

            if (!MainWPUtility::can_edit_website($website)) return false;
            
            if (!mainwp_current_user_can("site", $websiteid)) return false;

            return array(array('id' => $websiteid, 'url' => MainWPUtility::getNiceURL($website->url, true), 'name' => $website->name, 'totalsize' => $website->totalsize));
        }

        $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser(false, null, 'wp.url', false, false, null, $for_manager));              
        $output = array();
        while ($websites && ($website = @MainWPDB::fetch_object($websites)))
        {
            $output[] = array('id' => $website->id, 'url' => MainWPUtility::getNiceURL($website->url, true), 'name' => $website->name, 'totalsize' => $website->totalsize);
        }
        @MainWPDB::free_result($websites);

        return $output;
    }

    public static function hookGetGroups($pluginFile, $key, $groupid, $for_manager = false)
    {
        if (!self::hookVerify($pluginFile, $key))
        {
            return false;
        }
        
        if ($for_manager && (!defined("MWP_TEAMCONTROL_PLUGIN_SLUG") || !mainwp_current_user_can("extension", dirname(MWP_TEAMCONTROL_PLUGIN_SLUG)))) {            
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


        $groups = MainWPDB::Instance()->getGroupsAndCount(null, $for_manager);
        $output = array();
        foreach ($groups as $group)
        {
            $websites = MainWPDB::Instance()->getWebsitesByGroupId($group->id);
            $websitesOut = array();
            foreach ($websites as $website)
            {
                if (in_array($website->id, $websitesOut)) continue;
                $websitesOut[] = $website->id;
            }
            $output[] = array('id' => $group->id, 'name' => $group->name, 'websites' => $websitesOut);
        }

        return $output;
    }
         
    public static function hookManagerGetExtensions()
    {
        return get_option('mainwp_manager_extensions');                   
    }
    
    
}