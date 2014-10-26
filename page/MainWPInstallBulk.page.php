<?php
/**
 * Used in both InstallPlugins & InstallThemes
 */
class MainWPInstallBulk
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    //Has to be called in System constructor - adds handling for the main page
    public static function init() {
        add_action('admin_init', array(MainWPInstallBulk::getClassName(), 'admin_init'));
    }

    //Handles the uploading of a file
    public static function admin_init()
    {
        if (isset($_REQUEST['mainwp_do'])) {
            if ($_REQUEST['mainwp_do'] == 'MainWPInstallBulk-uploadfile') {
                // list of valid extensions, ex. array("jpeg", "xml", "bmp")
                $allowedExtensions = array("zip"); //Only zip allowed
                // max file size in bytes
                $sizeLimit = 2 * 1024 * 1024; //2MB = max allowed

                $uploader = new qq2FileUploader($allowedExtensions, $sizeLimit);
                $path = MainWPUtility::getMainWPSpecificDir('bulk');

                $result = $uploader->handleUpload($path, true);
                // to pass data through iframe you will need to encode all html tags
                die(htmlspecialchars(json_encode($result), ENT_NOQUOTES));
            }
        }
    }

    //Renders the main page in the WP admin part
    public static function render($title, $type = "plugin")
    {
        if (($type == "plugin" && !mainwp_current_user_can("install_plugins", "dashboard")) ||
            ($type == "theme" && !mainwp_current_user_can("install_themes", "dashboard"))) {
            mainwp_do_not_have_permissions("install plugins");  
            return;
        }
        
        $tab = 'search';
        if (isset($_REQUEST['tab'])) {
            $tab = $_REQUEST['tab'];
        }

        ?>
            <?php
            if ($tab == 'install') {
                
            } else {             
                ?>
                <a href="#" class="mainwp_action left <?php if ($tab == 'search') { echo 'mainwp_action_down'; } ?>" id="MainWPInstallBulkNavSearch"><?php _e('Search','mainwp'); ?></a><a href="#" class="mainwp_action right <?php if ($tab == 'upload') { echo 'mainwp_action_down'; } ?>" id="MainWPInstallBulkNavUpload"><?php _e('Upload','mainwp'); ?></a>


                <br class="clear" />
                <form method="POST" action="">
                    <div class="mainwp_config_box_right stick-to-window">
<!--                    <div>-->
                        <?php MainWPUI::select_sites_box() ?>
                  	</div>
                        <div class="mainwp_config_box_left">
               	 	<div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
                    <div id="MainWPInstallBulkAjax">
                        <?php
                        switch ($tab) {
                            case 'search':
                                MainWPInstallBulk::renderSearch($title);
                                break;
                            case 'upload':
                                MainWPInstallBulk::renderUpload($title);
                                break;
                            default:
                                MainWPInstallBulk::renderSearch($title);
                        }
                        ?>
                    </div>
                   	</div>
                </form>
                <?php
            }
            ?>
            <div id="MainWPInstallBulkNew" style="display: none">
                <br />
                <a href="<?php echo get_admin_url() ?>admin.php?page=<?php echo $title; ?>Install" class="add-new-h2" target="_top"><?php _e('Add New','mainwp'); ?></a>
                <a href="<?php echo get_admin_url() ?>admin.php?page=mainwp_tab" class="add-new-h2" target="_top"><?php _e('Return to Dashboard','mainwp'); ?></a>
            </div>
        <?php
    }

    public static function renderSearch($title) {
        ?>
        <?php /*<h4 style="margin: 0 0 8px;">Search</h4>*/ ?>
        <p class="install-help">Search for <?php echo strtolower($title); ?> by keyword, author, or tag.</p>
        <div class="tablenav top">
            <div class="alignleft actions">
                <form id="search-<?php echo strtolower($title); ?>" method="post" action="">
                    <input type="hidden" name="tab" value="search" />
                    <select name="type" id="mainwp_installbulk_typeselector">
                        <option value="term">Term</option>
                        <option value="author">Author</option>
                        <option value="tag">Tag</option>
                    </select>
                    <input type="text" name="s" value="<?php
        if (isset($_POST['s'])) {
            echo $_POST['s'];
        }
        ?>" id="mainwp_installbulk_s" />
                    <label class="screen-reader-text" for="plugin-search-input"><?php _e('Search','mainwp'); ?> <?php echo $title; ?></label>
                    <input type="button" name="plugin-search-input" id="mainwp-plugin-search-input" class="button-primary" value="Search <?php echo $title; ?>" onClick="return mainwp_install_search(event);" />
                    <br /><?php if ($title == 'Plugins') { echo '<br />&nbsp;&nbsp;<input type="checkbox" value="1" checked id="chk_activate_plugin" /> <label for="chk_activate_plugin">' . __('Activate plugin after installation','mainwp') .' </label>'; } ?>
                    <br />&nbsp;&nbsp;<input type="checkbox" value="2" checked id="chk_overwrite" /> <label for="chk_overwrite"><?php _e('Overwrite existing', 'mainwp'); ?></label><br />
                </form>
            </div>
            <div id="MainWPInstallBulkStatusExtra" class="MainWPInstallBulkStatus" style="display: none"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>" /> &nbsp;</div><div id="MainWPInstallBulkStatus" class="MainWPInstallBulkStatus"></div>
            <div class="tablenav-pages" style="display: none;" id="MainWPInstallBulkNav"><span id="MainWPInstallBulkResults" class="displaying-num"></span> <a class="prev-page" title="Go to the previous page" href="#" id="mainwp-plugin-search-prev" onClick="return mainwp_install_prev(event);">‹</a> <span id="MainWPInstallBulkPage"></span> of <span id="MainWPInstallBulkPages"></span> <a class="next-page" title="Go to the next page" href="#" id="mainwp-plugin-search-next" onClick="return mainwp_install_next(event);">›</a></div>
        </div>
        <div class="clear"></div>
        <div id="MainWPInstallBulkSearchAjax" style="margin-top: 2em;">

        </div>
        <?php
    }

     //Renders the upload sub part
    public static function renderUpload($title) {
        ?>
        <?php if ($title == 'Plugins') { ?>
        <div class="mainwp_info-box-red" id="mainwp-ext-notice">
            <span><?php _e('<strong>Do Not upload extensions here</strong>, they do not go on the child sites, upload and activate them via your dashboard sites <a href="/wp-admin/plugin-install.php" style="text-decoration: none;">plugin screen.</a>','mainwp'); ?></span><span style="float: right;"><a href="#" style="text-decoration: none;" id="mainwp-ext-dismiss"><?php _e('Dismiss','mainwp'); ?></a></span>
        </div>
        <?php } ?>
        <h4 style="margin: 8px 0 8px;"><?php _e('Install','mainwp'); ?> <?php echo strtolower($title); ?> <?php _e('in .zip format','mainwp'); ?></h4>
        <p class="install-help"><?php _e('If you have','mainwp'); ?> <?php echo strtolower($title); ?> <?php _e('in a .zip format, you may install it by uploading it here.','mainwp'); ?></p>
        <div id="mainwp-file-uploader">
            <noscript>
            <p><?php _e('Please enable JavaScript to use file uploader.','mainwp'); ?></p>
            </noscript>
        </div>
    <script>
        function createUploader(){
            var uploader = new qq.FileUploader({
                element: document.getElementById('mainwp-file-uploader'),
                action: location.href,
                <?php $extraOptions = apply_filters("mainwp_uploadbulk_uploader_options", "");
                $extraOptions = trim($extraOptions);
                $extraOptions = trim(trim($extraOptions, ','));
                if ($extraOptions != '')
                {
                    echo $extraOptions . ',';
                }
                ?>
                params: {mainwp_do: 'MainWPInstallBulk-uploadfile'}
            });
        }

        // in your app create uploader as soon as the DOM is ready
        // don't wait for the window to load
        createUploader();
    </script>
        <div id="MainWPInstallBulkInstallNow" style="display: none">
            <input type="button" class="button" value="<?php _e('Install Now','mainwp'); ?>" id="mainwp_upload_bulk_button" onClick="mainwp_upload_bulk('<?php echo strtolower($title); ?>');">
            <br /><?php if ($title == 'Plugins') { echo '<br />&nbsp;&nbsp;<input type="checkbox" value="1" checked id="chk_activate_plugin_upload" /> <label for="chk_activate_plugin_upload">Activate plugin after installation</label>'; } ?>
            <br />&nbsp;&nbsp;<input type="checkbox" value="2" checked id="chk_overwrite_upload" /> <label for="chk_overwrite_upload"><?php _e('Overwrite existing', 'mainwp'); ?></label><br />
        </div>
        <?php
    }

    public static function performSearch($class, $title) {
        if (isset($_POST['s'])) {
            $page = 1;
            if (isset($_POST['currpage'])) {
                $page = $_POST['currpage'];
            }
            $type = 'term';
            if (isset($_POST['type'])) {
                $type = $_POST['type'];
            }
            $term = '';
            if (isset($_POST['s'])) {
                $term = $_POST['s'];
            }


            include_once(ABSPATH . '/wp-admin/includes/plugin-install.php');
            $args = array('page' => $page, 'per_page' => 30);
            switch ($type) {
                case 'tag':
                    $args['tag'] = sanitize_title_with_dashes($term);
                    break;
                case 'term':
                    $args['search'] = $term;
                    break;
                case 'author':
                    $args['author'] = $term;
                    break;
            }
            if ($title == 'Plugins') {
                $api = plugins_api('query_plugins', $args);
            } else {
                $api = themes_api('query_themes', $args);
            }
        }
        if (!isset($api) || !isset($api->info['results']) || $api->info['results'] == 0) {
            echo '0 0 0 ';
        } else {
            echo $api->info['page'] . ' ' . $api->info['pages'] . ' ' . $api->info['results'] .' ';
        }

        do_action("mainwp_search_plugin_theme_results");
        call_user_func(array($class, 'renderFound'), $api);
    }

    public static function prepareInstall() {
        include_once(ABSPATH . '/wp-admin/includes/plugin-install.php');

        if (!isset($_POST['url'])) {
            if ($_POST['type'] == 'plugin') {
                $api = plugins_api('plugin_information', array('slug' => $_POST['slug'], 'fields' => array('sections' => false))); //Save on a bit of bandwidth.
            } else {
                $api = themes_api('theme_information', array('slug' => $_POST['slug'], 'fields' => array('sections' => false))); //Save on a bit of bandwidth.
            }
            $url = $api->download_link;
        } else {
            $url = $_POST['url'];
        }

        $output = array();
        $output['url'] = $url;
        $output['sites'] = array();

        if ($_POST['selected_by'] == 'site') {
            //Get sites
            foreach ($_POST['selected_sites'] as $enc_id)
            {
                $websiteid = $enc_id;
                if (MainWPUtility::ctype_digit($websiteid))
                {
                    $website = MainWPDB::Instance()->getWebsiteById($websiteid);
                    $output['sites'][$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name'));
                }
            }
        } else {
            //Get sites from group
            foreach ($_POST['selected_groups'] as $enc_id)
            {
                $groupid = $enc_id;
                if (MainWPUtility::ctype_digit($groupid))
                {
                    $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesByGroupId($groupid));
                    while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                    {
                        if ($website->sync_errors != '') continue;
                        $output['sites'][$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name'));
                    }
                    @MainWPDB::free_result($websites);
                }
            }
        }

        die(json_encode($output));
    }

    public static function performInstall()
    {
        MainWPUtility::endSession();

        //Fetch info..
        $post_data = array(
            'url' => json_encode($_POST['url']),
            'type' => $_POST['type']);
        if ($_POST['activatePlugin'] == 'true') $post_data['activatePlugin'] = 'yes';
        if ($_POST['overwrite'] == 'true') $post_data['overwrite'] = true;
        $output = new stdClass();
        $output->ok = array();
        $output->errors = array();
        $websites = array(MainWPDB::Instance()->getWebsiteById($_POST['siteId']));
        MainWPUtility::fetchUrlsAuthed($websites, 'installplugintheme', $post_data, array(MainWPInstallBulk::getClassName(), 'InstallPluginTheme_handler'), $output);

        die(json_encode($output));
    }

    public static function prepareUpload()
    {
        include_once(ABSPATH . '/wp-admin/includes/plugin-install.php');

        $output = array();
        $output['sites'] = array();
        if ($_POST['selected_by'] == 'site') {
            //Get sites
            foreach ($_POST['selected_sites'] as $enc_id) {
                $websiteid = $enc_id;
                if (MainWPUtility::ctype_digit($websiteid)) {
                    $website = MainWPDB::Instance()->getWebsiteById($websiteid);
                    $output['sites'][$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name'));
                }
            }
        } else {
            //Get sites from group
            foreach ($_POST['selected_groups'] as $enc_id) {
                $groupid = $enc_id;
                if (MainWPUtility::ctype_digit($groupid)) {
                    $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesByGroupId($groupid));
                    while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                    {
                        if ($website->sync_errors != '') continue;
                        $output['sites'][$website->id] = MainWPUtility::mapSite($website, array('id', 'url', 'name'));
                    }
                    @MainWPDB::free_result($websites);
                }
            }
        }

        $output['urls'] = array();
        $url = MainWPUtility::getMainWPSpecificUrl('bulk');
        foreach ($_POST['files'] as $file) {
            $output['urls'][] = $url . rawurlencode($file);
        }
        $output['urls'] = implode('||', $output['urls']);
        $output['urls'] = apply_filters('mainwp_installbulk_prepareupload', $output['urls']);

        die(json_encode($output));
    }

    public static function performUpload()
    {
        MainWPUtility::endSession();

        //Fetch info..
        $post_data = array(
            'url' => json_encode(explode('||', $_POST['urls'])),
            'type' => $_POST['type']);
        if ($_POST['activatePlugin'] == 'true') $post_data['activatePlugin'] = 'yes';
        if ($_POST['overwrite'] == 'true') $post_data['overwrite'] = true;
        $output = new stdClass();
        $output->ok = array();
        $output->errors = array();
        $websites = array(MainWPDB::Instance()->getWebsiteById($_POST['siteId']));
        MainWPUtility::fetchUrlsAuthed($websites, 'installplugintheme', $post_data, array(MainWPInstallBulk::getClassName(), 'InstallPluginTheme_handler'), $output);
        die(json_encode($output));
    }

    public static function cleanUpload()
    {
        $path = MainWPUtility::getMainWPSpecificDir('bulk');
        if (file_exists($path) && ($dh = opendir($path)))
        {
            while (($file = readdir($dh)) !== false)
            {
                if ($file != '.' && $file != '..')
                {
                    @unlink($path . $file);
                }
            }
            closedir($dh);
        }

        die(json_encode(array('ok' => true)));
    }

    public static function InstallPluginTheme_handler($data, $website, &$output) {
        if (preg_match('/<mainwp>(.*)<\/mainwp>/', $data, $results) > 0) {
            $result = $results[1];
            $information = unserialize(base64_decode($result));
            if (isset($information['installation']) && $information['installation'] == 'SUCCESS') {
                $output->ok[$website->id] = array($website->name);
            } else if (isset($information['error'])) {
                $error = $information['error'];
                if ($error == 'folder_exists')
                {
                    $error = __('Already installed');
                }
                $output->errors[$website->id] = array($website->name, $error);
            } else {
                $output->errors[$website->id] = array($website->name, __('Undefined error - please reinstall the MainWP Child plugin on the client','mainwp'));
            }
        } else {
            $output->errors[$website->id] = array($website->name, 'Error installing.');
        }
    }
}
/**
 * 
 * DO NOT TOUCH - part of http://github.com/valums/file-uploader ! (@see js/fileuploader.js)
 * 
 */
/**
 * Handle file uploads via XMLHttpRequest
 */
class qq2UploadedFileXhr {
    /**
     * Save the file to the specified path
     * @return boolean TRUE on success
     */
    function save($path) {
        $input = fopen("php://input", "r");
        $temp = tmpfile();
        $realSize = stream_copy_to_stream($input, $temp);
        fclose($input);

        if ($realSize != $this->getSize()){
            return false;
        }

        $hasWPFileSystem = MainWPUtility::getWPFilesystem();
        global $wp_filesystem;

        if ($hasWPFileSystem && !empty($wp_filesystem))
        {
            if (!is_dir(dirname(dirname(dirname($path))))) {
                if (!$wp_filesystem->mkdir(dirname(dirname(dirname($path))))) throw new Exception('Unable to create the MainWP bulk upload directory, please check your system configuration.');
            }

            if (!is_dir(dirname(dirname($path)))) {
                if (!$wp_filesystem->mkdir(dirname(dirname($path)))) throw new Exception('Unable to create the MainWP bulk upload directory, please check your system configuration.');
            }

            if (!is_dir(dirname($path))) {
                if (!$wp_filesystem->mkdir(dirname($path))) throw new Exception('Unable to create the MainWP bulk upload directory, please check your system configuration.');
            }

            fseek($temp, 0, SEEK_SET);
            $wp_filesystem->put_contents($path, stream_get_contents($temp));
        }
        else
        {
            if (!is_dir(dirname($path))) {
                @mkdir(dirname($path), 0777, true);
            }

            $target = fopen($path, "w");
            fseek($temp, 0, SEEK_SET);
            if (stream_copy_to_stream($temp, $target) <= 0) return false;
            fclose($target);
        }

        if (!file_exists($path))
        {
            throw new Exception('Unable to save the file to the MainWP upload directory, please check your system configuration.');
        }
        return true;
    }
    function getName() {
        return $_GET['qqfile'];
    }
    function getSize() {
        if (isset($_SERVER["CONTENT_LENGTH"])){
            return (int)$_SERVER["CONTENT_LENGTH"];
        } else {
            throw new Exception('Getting content length is not supported.');
         } 
    }
}
/**
 * Handle file uploads via regular form post (uses the $_FILES array)
 */
class qq2UploadedFileForm {
    /**
     * Save the file to the specified path
     * @return boolean TRUE on success
     */
    function save($path) {
        $wpFileSystem = MainWPUtility::getWPFilesystem();

        if ($wpFileSystem != null)
        {
            $path = str_replace(MainWPUtility::getBaseDir(), '', $path);
            $moved = $wpFileSystem->put_contents($path, file_get_contents($_FILES['qqfile']['tmp_name']));
        }
        else
        {
            $moved = move_uploaded_file($_FILES['qqfile']['tmp_name'], $path);
        }

        if(!$moved){
            return false;
        }
        return true;
    }
    function getName() {
        return $_FILES['qqfile']['name'];
    }
    function getSize() {
        return $_FILES['qqfile']['size'];
    }
}


class qq2FileUploader {
    private $allowedExtensions = array();
    private $sizeLimit = 8388608;
    private $file;

    function __construct(array $allowedExtensions = array(), $sizeLimit = 8388608){
        $allowedExtensions = array_map("strtolower", $allowedExtensions);

        $this->allowedExtensions = $allowedExtensions;
        $this->sizeLimit = $sizeLimit;

        $this->checkServerSettings();
         //die("{'error':'".print_r($_POST, true).print_r($_FILES, true)."'}");
        if (isset($_GET['qqfile'])) {
            $this->file = new qq2UploadedFileXhr();
        } elseif (isset($_FILES['qqfile'])) {
            $this->file = new qq2UploadedFileForm();
        } else {
            $this->file = false;
        }
    }

    private function checkServerSettings(){
//        $postSize = $this->toBytes(ini_get('post_max_size'));
//        $uploadSize = $this->toBytes(ini_get('upload_max_filesize'));
//        if ($postSize < $this->sizeLimit || $uploadSize < $this->sizeLimit){
//            $size = max(1, $this->sizeLimit / 1024 / 1024) . 'M';
//            die("{'error':'increase post_max_size and upload_max_filesize to $size'}");
//        }
    }

    private function toBytes($str){
        $val = trim($str);
        $last = strtolower($str[strlen($str)-1]);
        switch($last) {
            case 'g': $val *= 1024;
            case 'm': $val *= 1024;
            case 'k': $val *= 1024;
        }
        return $val;
    }

    /**
     * Returns array('success'=>true) or array('error'=>'error message')
     */
    function handleUpload($uploadDirectory, $replaceOldFile = FALSE){
//        if (!is_writable($uploadDirectory)){
//            return array('error' => "Server error. Upload directory isn't writable.");
//        }

        if (!$this->file){
            return array('error' => 'No files were uploaded.');
        }

        $size = $this->file->getSize();

        if ($size == 0) {
            return array('error' => 'File is empty');
        }

        $postSize = $this->toBytes(ini_get('post_max_size'));
        $uploadSize = $this->toBytes(ini_get('upload_max_filesize'));
        if ($postSize < $size || $uploadSize < $size){
            return array('error' => __('File is too large, increase post_max_size and/or upload_max_filesize','mainwp'));
        }

        $pathinfo = pathinfo($this->file->getName());
        $filename = $pathinfo['filename'];
        //$filename = md5(uniqid());
        $ext = $pathinfo['extension'];

        if($this->allowedExtensions && !in_array(strtolower($ext), $this->allowedExtensions)){
            $these = implode(', ', $this->allowedExtensions);
            return array('error' => __('File has an invalid extension, it should be one of ','mainwp') . $these . '.');
        }

        if(!$replaceOldFile){
            /// don't overwrite previous files that were uploaded
            while (file_exists($uploadDirectory . $filename . '.' . $ext)) {
                $filename .= rand(10, 99);
            }
        }

        try
        {
            if ($this->file->save($uploadDirectory . $filename . '.' . $ext)){
                return array('success' => true);
            } else {
                return array('error'=> __('Could not save uploaded file.','mainwp') .
                    _('The upload was cancelled, or server error encountered','mainwp'));
            }
        }
        catch (Exception $e)
        {
            return array('error' => $e->getMessage());
        }
    }
}

?>
