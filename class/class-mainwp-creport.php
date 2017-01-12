<?php

class LiveReportResponder {



    public static $instance = null;

    public static $plugin_url;

    public $plugin_handle = 'mainwp-wpcreport-extension';

    public $plugin_slug;

    public $plugin_dir;

    protected $option;

    protected $option_handle = 'mainwp_wpcreport_extension';



    static function get_instance() {

        if ( null == LiveReportResponder::$instance ) {

            LiveReportResponder::$instance = new LiveReportResponder();

        }

        return LiveReportResponder::$instance;

    }



    public function __construct() {

        $this->plugin_dir = plugin_dir_path(__FILE__);

        self::$plugin_url = plugin_dir_url(__FILE__);

        $this->plugin_slug = plugin_basename(__FILE__);

        $this->option = get_option($this->option_handle);

 

        add_action('admin_init', array(&$this, 'admin_init'));

                        

                        
    }

 

    public function admin_init() {

        $translation_array = array('dashboard_sitename' => get_bloginfo('name'));

        MainWP_Live_Reports_Class::init();

        $mwp_creport = new MainWP_Live_Reports_Class();

        $mwp_creport->admin_init(); 

    }

 

}



class LiveReportResponder_Activator {



    protected $mainwpMainActivated = false;

    protected $childEnabled = false;

    protected $childKey = false;

    protected $childFile;

    protected $plugin_handle = 'mainwp-client-reports-extension';

    protected $product_id = 'Managed Client Reports Responder';

    protected $software_version = '1.1';



    public function __construct() {



        $this->childFile = __FILE__;

         $this->mainwpMainActivated = apply_filters('mainwp-activated-check', false);



        if ($this->mainwpMainActivated !== false) {

            $this->activate_this_plugin();

        } else {

            add_action('mainwp-activated', array(&$this, 'activate_this_plugin'));

        }

 

    }

 



    function activate_this_plugin() {



        $this->mainwpMainActivated = apply_filters('mainwp-activated-check', $this->mainwpMainActivated);

        $this->childEnabled = apply_filters('mainwp-extension-enabled-check', __FILE__);

        $this->childKey = $this->childEnabled['key'];

        if (function_exists('mainwp_current_user_can') && !mainwp_current_user_can('extension', 'mainwp-client-reports-extension')) {

            return;

        }

        new LiveReportResponder();

    }



    public function get_child_key() {



        return $this->childKey;

    }



    public function get_child_file() {



        return $this->childFile;

    }

 

    public function update_option($option_name, $option_value) {



        $success = add_option($option_name, $option_value, '', 'no');



        if (!$success) {

            $success = update_option($option_name, $option_value);

        }



        return $success;

    }



    public function activate() {

        $options = array(

            'product_id' => $this->product_id,

            'activated_key' => 'Deactivated',

            'instance_id' => apply_filters('mainwp-extensions-apigeneratepassword', 12, false),

            'software_version' => $this->software_version,

        );

        $this->update_option($this->plugin_handle . '_APIManAdder', $options);

    }



    public function deactivate() {

        $this->update_option($this->plugin_handle . '_APIManAdder', '');

    }



}

global $mainWPCReportExtensionActivator;

$mainWPCReportExtensionActivator = new LiveReportResponder_Activator();



class MainWP_Live_Reports_Class {



    private static $stream_tokens = array();

    private static $tokens_nav_top = array();

    private static $buffer = array();

    private static $order = '';

    private static $enabled_piwik = null;

    private static $enabled_sucuri = false;

    private static $enabled_ga = null;

    private static $enabled_aum = null;

    private static $enabled_woocomstatus = null;

    private static $count_sec_header = 0;

    private static $count_sec_body = 0;

    private static $count_sec_footer = 0;



    public function __construct() {

        

    }



    public static function init() {

        self::$stream_tokens = array(

            'client' => array(

                'nav_group_tokens' => array(

                    'tokens' => 'Tokens',

                ),

                'tokens' => array(),

            ),

            'plugins' => array(

                'sections' => array(

                    array('name' => 'section.plugins.installed', 'desc' => 'Loops through Plugins Installed during the selected date range'),

                    array('name' => 'section.plugins.activated', 'desc' => 'Loops through Plugins Activated during the selected date range'),

                    array('name' => 'section.plugins.edited', 'desc' => 'Loops through Plugins Edited during the selected date range'),

                    array('name' => 'section.plugins.deactivated', 'desc' => 'Loops through Plugins Deactivated during the selected date range'),

                    array('name' => 'section.plugins.updated', 'desc' => 'Loops through Plugins Updated during the selected date range'),

                    array('name' => 'section.plugins.deleted', 'desc' => 'Loops through Plugins Deleted during the selected date range'),

                ),

                'nav_group_tokens' => array(

                    'sections' => 'Sections',

                    'installed' => 'Installed',

                    'activated' => 'Activated',

                    'edited' => 'Edited',

                    'deactivated' => 'Deactivated',

                    'updated' => 'Updated',

                    'deleted' => 'Deleted',

                    'additional' => 'Additional',

                ),

                'installed' => array(

                    array('name' => 'plugin.name', 'desc' => 'Displays the Plugin Name'),

                    array('name' => 'plugin.installed.date', 'desc' => 'Displays the Plugin Installation Date'),

                    array('name' => 'plugin.installed.author', 'desc' => 'Displays the User who Installed the Plugin'),

                ),

                'activated' => array(

                    array('name' => 'plugin.name', 'desc' => 'Displays the Plugin Name'),

                    array('name' => 'plugin.activated.date', 'desc' => 'Displays the Plugin Activation Date'),

                    array('name' => 'plugin.activated.author', 'desc' => 'Displays the User who Activated the Plugin'),

                ),

                'edited' => array(

                    array('name' => 'plugin.name', 'desc' => 'Displays the Plugin Name'),

                    array('name' => 'plugin.edited.date', 'desc' => 'Displays the Plugin Editing Date'),

                    array('name' => 'plugin.edited.author', 'desc' => 'Displays the User who Edited the Plugin'),

                ),

                'deactivated' => array(

                    array('name' => 'plugin.name', 'desc' => 'Displays the Plugin Name'),

                    array('name' => 'plugin.deactivated.date', 'desc' => 'Displays the Plugin Deactivation Date'),

                    array('name' => 'plugin.deactivated.author', 'desc' => 'Displays the User who Deactivated the Plugin'),

                ),

                'updated' => array(

                    array('name' => 'plugin.old.version', 'desc' => 'Displays the Plugin Version Before Update'),

                    array('name' => 'plugin.current.version', 'desc' => 'Displays the Plugin Current Vesion'),

                    array('name' => 'plugin.name', 'desc' => 'Displays the Plugin Name'),

                    array('name' => 'plugin.updated.date', 'desc' => 'Displays the Plugin Update Date'),

                    array('name' => 'plugin.updated.author', 'desc' => 'Displays the User who Updated the Plugin'),

                ),

                'deleted' => array(

                    array('name' => 'plugin.name', 'desc' => 'Displays the Plugin Name'),

                    array('name' => 'plugin.deleted.date', 'desc' => 'Displays the Plugin Deliting Date'),

                    array('name' => 'plugin.deleted.author', 'desc' => 'Displays the User who Deleted the Plugin'),

                ),

                'additional' => array(

                    array('name' => 'plugin.installed.count', 'desc' => 'Displays the Number of Installed Plugins'),

                    array('name' => 'plugin.edited.count', 'desc' => 'Displays the Number of Edited Plugins'),

                    array('name' => 'plugin.activated.count', 'desc' => 'Displays the Number of Activated Plugins'),

                    array('name' => 'plugin.deactivated.count', 'desc' => 'Displays the Number of Deactivated Plugins'),

                    array('name' => 'plugin.deleted.count', 'desc' => 'Displays the Number of Deleted Plugins'),

                    array('name' => 'plugin.updated.count', 'desc' => 'Displays the Number of Updated Plugins'),

                ),

            ),

            'themes' => array(

                'sections' => array(

                    array('name' => 'section.themes.installed', 'desc' => 'Loops through Themes Installed during the selected date range'),

                    array('name' => 'section.themes.activated', 'desc' => 'Loops through Themes Activated during the selected date range'),

                    array('name' => 'section.themes.edited', 'desc' => 'Loops through Themes Edited during the selected date range'),

                    array('name' => 'section.themes.updated', 'desc' => 'Loops through Themes Updated during the selected date range'),

                    array('name' => 'section.themes.deleted', 'desc' => 'Loops through Themes Deleted during the selected date range'),

                ),

                'nav_group_tokens' => array(

                    'sections' => 'Sections',

                    'installed' => 'Installed',

                    'activated' => 'Activated',

                    'edited' => 'Edited',

                    'updated' => 'Updated',

                    'deleted' => 'Deleted',

                    'additional' => 'Additional',

                ),

                'installed' => array(

                    array('name' => 'theme.name', 'desc' => 'Displays the Theme Name'),

                    array('name' => 'theme.installed.date', 'desc' => 'Displays the Theme Installation Date'),

                    array('name' => 'theme.installed.author', 'desc' => 'Displays the User who Installed the Theme'),

                ),

                'activated' => array(

                    array('name' => 'theme.name', 'desc' => 'Displays the Theme Name'),

                    array('name' => 'theme.activated.date', 'desc' => 'Displays the Theme Activation Date'),

                    array('name' => 'theme.activated.author', 'desc' => 'Displays the User who Activated the Theme'),

                ),

                'edited' => array(

                    array('name' => 'theme.name', 'desc' => 'Displays the Theme Name'),

                    array('name' => 'theme.edited.date', 'desc' => 'Displays the Theme Editing Date'),

                    array('name' => 'theme.edited.author', 'desc' => 'Displays the User who Edited the Theme'),

                ),

                'updated' => array(

                    array('name' => 'theme.old.version', 'desc' => 'Displays the Theme Version Before Update'),

                    array('name' => 'theme.current.version', 'desc' => 'Displays the Theme Current Version'),

                    array('name' => 'theme.name', 'desc' => 'Displays the Theme Name'),

                    array('name' => 'theme.updated.date', 'desc' => 'Displays the Theme Update Date'),

                    array('name' => 'theme.updated.author', 'desc' => 'Displays the User who Updated the Theme'),

                ),

                'deleted' => array(

                    array('name' => 'theme.name', 'desc' => 'Displays the Theme Name'),

                    array('name' => 'theme.deleted.date', 'desc' => 'Displays the Theme Deleting Date'),

                    array('name' => 'theme.deleted.author', 'desc' => 'Displays the User who Deleted the Theme'),

                ),

                'additional' => array(

                    array('name' => 'theme.installed.count', 'desc' => 'Displays the Number of Installed Themes'),

                    array('name' => 'theme.edited.count', 'desc' => 'Displays the Number of Edited Themes'),

                    array('name' => 'theme.activated.count', 'desc' => 'Displays the Number of Activated Themes'),

                    array('name' => 'theme.deleted.count', 'desc' => 'Displays the Number of Deleted Themes'),

                    array('name' => 'theme.updated.count', 'desc' => 'Displays the Number of Updated Themes'),

                ),

            ),

            'posts' => array(

                'sections' => array(

                    array('name' => 'section.posts.created', 'desc' => 'Loops through Posts Created during the selected date range'),

                    array('name' => 'section.posts.updated', 'desc' => 'Loops through Posts Updated during the selected date range'),

                    array('name' => 'section.posts.trashed', 'desc' => 'Loops through Posts Trashed during the selected date range'),

                    array('name' => 'section.posts.deleted', 'desc' => 'Loops through Posts Deleted during the selected date range'),

                    array('name' => 'section.posts.restored', 'desc' => 'Loops through Posts Restored during the selected date range'),

                ),

                'nav_group_tokens' => array(

                    'sections' => 'Sections',

                    'created' => 'Created',

                    'updated' => 'Updated',

                    'trashed' => 'Trashed',

                    'deleted' => 'Deleted',

                    'restored' => 'Restored',

                    'additional' => 'Additional',

                ),

                'created' => array(

                    array('name' => 'post.title', 'desc' => 'Displays the Post Title'),

                    array('name' => 'post.created.date', 'desc' => 'Displays the Post Createion Date'),

                    array('name' => 'post.created.author', 'desc' => 'Displays the User who Created the Post'),

                ),

                'updated' => array(

                    array('name' => 'post.title', 'desc' => 'Displays the Post Title'),

                    array('name' => 'post.updated.date', 'desc' => 'Displays the Post Update Date'),

                    array('name' => 'post.updated.author', 'desc' => 'Displays the User who Updated the Post'),

                ),

                'trashed' => array(

                    array('name' => 'post.title', 'desc' => 'Displays the Post Title'),

                    array('name' => 'post.trashed.date', 'desc' => 'Displays the Post Trashing Date'),

                    array('name' => 'post.trashed.author', 'desc' => 'Displays the User who Trashed the Post'),

                ),

                'deleted' => array(

                    array('name' => 'post.title', 'desc' => 'Displays the Post Title'),

                    array('name' => 'post.deleted.date', 'desc' => 'Displays the Post Deleting Date'),

                    array('name' => 'post.deleted.author', 'desc' => 'Displays the User who Deleted the Post'),

                ),

                'restored' => array(

                    array('name' => 'post.title', 'desc' => 'Displays Post Title'),

                    array('name' => 'post.restored.date', 'desc' => 'Displays the Post Restoring Date'),

                    array('name' => 'post.restored.author', 'desc' => 'Displays the User who Restored the Post'),

                ),

                'additional' => array(

                    array('name' => 'post.created.count', 'desc' => 'Displays the Number of Created Posts'),

                    array('name' => 'post.updated.count', 'desc' => 'Displays the Number of Updated Posts'),

                    array('name' => 'post.trashed.count', 'desc' => 'Displays the Number of Trashed Posts'),

                    array('name' => 'post.restored.count', 'desc' => 'Displays the Number of Restored Posts'),

                    array('name' => 'post.deleted.count', 'desc' => 'Displays the Number of Deleted Posts'),

                ),

            ),

            'pages' => array(

                'sections' => array(

                    array('name' => 'section.pages.created', 'desc' => 'Loops through Pages Created during the selected date range'),

                    array('name' => 'section.pages.updated', 'desc' => 'Loops through Pages Updated during the selected date range'),

                    array('name' => 'section.pages.trashed', 'desc' => 'Loops through Pages Trashed during the selected date range'),

                    array('name' => 'section.pages.deleted', 'desc' => 'Loops through Pages Deleted during the selected date range'),

                    array('name' => 'section.pages.restored', 'desc' => 'Loops through Pages Restored during the selected date range'),

                ),

                'nav_group_tokens' => array(

                    'sections' => 'Sections',

                    'created' => 'Created',

                    'updated' => 'Updated',

                    'trashed' => 'Trashed',

                    'deleted' => 'Deleted',

                    'restored' => 'Restored',

                    'additional' => 'Additional',

                ),

                'created' => array(

                    array('name' => 'page.title', 'desc' => 'Displays the Page Title'),

                    array('name' => 'page.created.date', 'desc' => 'Displays the Page Createion Date'),

                    array('name' => 'page.created.author', 'desc' => 'Displays the User who Created the Page'),

                ),

                'updated' => array(

                    array('name' => 'page.title', 'desc' => 'Displays the Page Title'),

                    array('name' => 'page.updated.date', 'desc' => 'Displays the Page Updating Date'),

                    array('name' => 'page.updated.author', 'desc' => 'Displays the User who Updated the Page'),

                ),

                'trashed' => array(

                    array('name' => 'page.title', 'desc' => 'Displays the Page Title'),

                    array('name' => 'page.trashed.date', 'desc' => 'Displays the Page Trashing Date'),

                    array('name' => 'page.trashed.author', 'desc' => 'Displays the User who Trashed the Page'),

                ),

                'deleted' => array(

                    array('name' => 'page.title', 'desc' => 'Displays the Page Title'),

                    array('name' => 'page.deleted.date', 'desc' => 'Displays the Page Deleting Date'),

                    array('name' => 'page.deleted.author', 'desc' => 'Displays the User who Deleted the Page'),

                ),

                'restored' => array(

                    array('name' => 'page.title', 'desc' => 'Displays the Page Title'),

                    array('name' => 'page.restored.date', 'desc' => 'Displays the Page Restoring Date'),

                    array('name' => 'page.restored.author', 'desc' => 'Displays the User who Restored the Page'),

                ),

                'additional' => array(

                    array('name' => 'page.created.count', 'desc' => 'Displays the Number of Created Pages'),

                    array('name' => 'page.updated.count', 'desc' => 'Displays the Number of Updated Pages'),

                    array('name' => 'page.trashed.count', 'desc' => 'Displays the Number of Trashed Pages'),

                    array('name' => 'page.restored.count', 'desc' => 'Displays the Number of Restored Pages'),

                    array('name' => 'page.deleted.count', 'desc' => 'Displays the Number of Deleted Pages'),

                ),

            ),

            'comments' => array(

                'sections' => array(

                    array('name' => 'section.comments.created', 'desc' => 'Loops through Comments Created during the selected date range'),

                    array('name' => 'section.comments.updated', 'desc' => 'Loops through Comments Updated during the selected date range'),

                    array('name' => 'section.comments.trashed', 'desc' => 'Loops through Comments Trashed during the selected date range'),

                    array('name' => 'section.comments.deleted', 'desc' => 'Loops through Comments Deleted during the selected date range'),

                    array('name' => 'section.comments.edited', 'desc' => 'Loops through Comments Edited during the selected date range'),

                    array('name' => 'section.comments.restored', 'desc' => 'Loops through Comments Restored during the selected date range'),

                    array('name' => 'section.comments.approved', 'desc' => 'Loops through Comments Approved during the selected date range'),

                    array('name' => 'section.comments.spam', 'desc' => 'Loops through Comments Spammed during the selected date range'),

                    array('name' => 'section.comments.replied', 'desc' => 'Loops through Comments Replied during the selected date range'),

                ),

                'nav_group_tokens' => array(

                    'sections' => 'Sections',

                    'created' => 'Created',

                    'updated' => 'Updated',

                    'trashed' => 'Trashed',

                    'deleted' => 'Deleted',

                    'edited' => 'Edited',

                    'restored' => 'Restored',

                    'approved' => 'Approved',

                    'spam' => 'Spam',

                    'replied' => 'Replied',

                    'additional' => 'Additional',

                ),

                'created' => array(

                    array('name' => 'comment.title', 'desc' => 'Displays the Title of the Post or the Page where the Comment is Created'),

                    array('name' => 'comment.created.date', 'desc' => 'Displays the Comment Creating Date'),

                    array('name' => 'comment.created.author', 'desc' => 'Displays the User who Created the Comment'),

                ),

                'updated' => array(

                    array('name' => 'comment.title', 'desc' => 'Displays the Title of the Post or the Page where the Comment is Updated'),

                    array('name' => 'comment.updated.date', 'desc' => 'Displays the Comment Updating Date'),

                    array('name' => 'comment.updated.author', 'desc' => 'Displays the User who Updated the Comment'),

                ),

                'trashed' => array(

                    array('name' => 'comment.title', 'desc' => 'Displays the Title of the Post or the Page where the Comment is Trashed'),

                    array('name' => 'comment.trashed.date', 'desc' => 'Displays the Comment Trashing Date'),

                    array('name' => 'comment.trashed.author', 'desc' => 'Displays the User who Trashed the Comment'),

                ),

                'deleted' => array(

                    array('name' => 'comment.title', 'desc' => 'Displays the Title of the Post or the Page where the Comment is Deleted'),

                    array('name' => 'comment.deleted.date', 'desc' => 'Displays the Comment Deleting Date'),

                    array('name' => 'comment.deleted.author', 'desc' => 'Displays the User who Deleted the Comment'),

                ),

                'edited' => array(

                    array('name' => 'comment.title', 'desc' => 'Displays the Title of the Post or the Page where the Comment is Edited'),

                    array('name' => 'comment.edited.date', 'desc' => 'Displays the Comment Editing Date'),

                    array('name' => 'comment.edited.author', 'desc' => 'Displays the User who Edited the Comment'),

                ),

                'restored' => array(

                    array('name' => 'comment.title', 'desc' => 'Displays the Title of the Post or the Page where the Comment is Restored'),

                    array('name' => 'comment.restored.date', 'desc' => 'Displays the Comment Restoring Date'),

                    array('name' => 'comment.restored.author', 'desc' => 'Displays the User who Restored the Comment'),

                ),

                'approved' => array(

                    array('name' => 'comment.title', 'desc' => 'Displays the Title of the Post or the Page where the Comment is Approved'),

                    array('name' => 'comment.approved.date', 'desc' => 'Displays the Comment Approving Date'),

                    array('name' => 'comment.approved.author', 'desc' => 'Displays the User who Approved the Comment'),

                ),

                'spam' => array(

                    array('name' => 'comment.title', 'desc' => 'Displays the Title of the Post or the Page where the Comment is Spammed'),

                    array('name' => 'comment.spam.date', 'desc' => 'Displays the Comment Spamming Date'),

                    array('name' => 'comment.spam.author', 'desc' => 'Displays the User who Spammed the Comment'),

                ),

                'replied' => array(

                    array('name' => 'comment.title', 'desc' => 'Displays the Title of the Post or the Page where the Comment is Replied'),

                    array('name' => 'comment.replied.date', 'desc' => 'Displays the Comment Replying Date'),

                    array('name' => 'comment.replied.author', 'desc' => 'Displays the User who Replied the Comment'),

                ),

                'additional' => array(

                    array('name' => 'comment.created.count', 'desc' => 'Displays the Number of Created Comments'),

                    array('name' => 'comment.trashed.count', 'desc' => 'Displays the Number of Trashed Comments'),

                    array('name' => 'comment.deleted.count', 'desc' => 'Displays the Number of Deleted Comments'),

                    array('name' => 'comment.edited.count', 'desc' => 'Displays the Number of Edited Comments'),

                    array('name' => 'comment.restored.count', 'desc' => 'Displays the Number of Restored Comments'),

                    array('name' => 'comment.deleted.count', 'desc' => 'Displays the Number of Deleted Comments'),

                    array('name' => 'comment.approved.count', 'desc' => 'Displays the Number of Approved Comments'),

                    array('name' => 'comment.spam.count', 'desc' => 'Displays the Number of Spammed Comments'),

                    array('name' => 'comment.replied.count', 'desc' => 'Displays the Number of Replied Comments'),

                ),

            ),

            'users' => array(

                'sections' => array(

                    array('name' => 'section.users.created', 'desc' => 'Loops through Users Created during the selected date range'),

                    array('name' => 'section.users.updated', 'desc' => 'Loops through Users Updated during the selected date range'),

                    array('name' => 'section.users.deleted', 'desc' => 'Loops through Users Deleted during the selected date range'),

                ),

                'nav_group_tokens' => array(

                    'sections' => 'Sections',

                    'created' => 'Created',

                    'updated' => 'Updated',

                    'deleted' => 'Deleted',

                    'additional' => 'Additional',

                ),

                'created' => array(

                    array('name' => 'user.name', 'desc' => 'Displays the User Name'),

                    array('name' => 'user.created.date', 'desc' => 'Displays the User Creation Date'),

                    array('name' => 'user.created.author', 'desc' => 'Displays the User who Created the new User'),

                    array('name' => 'user.created.role', 'desc' => 'Displays the Role of the Created User'),

                ),

                'updated' => array(

                    array('name' => 'user.name', 'desc' => 'Displays the User Name'),

                    array('name' => 'user.updated.date', 'desc' => 'Displays the User Updating Date'),

                    array('name' => 'user.updated.author', 'desc' => 'Displays the User who Updated the new User'),

                    array('name' => 'user.updated.role', 'desc' => 'Displays the Role of the Updated User'),

                ),

                'deleted' => array(

                    array('name' => 'user.name', 'desc' => 'Displays the User Name'),

                    array('name' => 'user.deleted.date', 'desc' => 'Displays the User Deleting Date'),

                    array('name' => 'user.deleted.author', 'desc' => 'Displays the User who Deleted the new User'),

                ),

                'additional' => array(

                    array('name' => 'user.created.count', 'desc' => 'Displays the Number of Created Users'),

                    array('name' => 'user.updated.count', 'desc' => 'Displays the Number of Updated Users'),

                    array('name' => 'user.deleted.count', 'desc' => 'Displays the Number of Deleted Users'),

                ),

            ),

            'media' => array(

                'sections' => array(

                    array('name' => 'section.media.uploaded', 'desc' => 'Loops through Media Uploaded during the selected date range'),

                    array('name' => 'section.media.updated', 'desc' => 'Loops through Media Updated during the selected date range'),

                    array('name' => 'section.media.deleted', 'desc' => 'Loops through Media Deleted during the selected date range'),

                ),

                'nav_group_tokens' => array(

                    'sections' => 'Sections',

                    'uploaded' => 'Uploaded',

                    'updated' => 'Updated',

                    'deleted' => 'Deleted',

                    'additional' => 'Additional',

                ),

                'uploaded' => array(

                    array('name' => 'media.name', 'desc' => 'Displays the Media Name'),

                    array('name' => 'media.uploaded.date', 'desc' => 'Displays the Media Uploading Date'),

                    array('name' => 'media.uploaded.author', 'desc' => 'Displays the User who Uploaded the Media File'),

                ),

                'updated' => array(

                    array('name' => 'media.name', 'desc' => 'Displays the Media Name'),

                    array('name' => 'media.updated.date', 'desc' => 'Displays the Media Updating Date'),

                    array('name' => 'media.updated.author', 'desc' => 'Displays the User who Updted the Media File'),

                ),

                'deleted' => array(

                    array('name' => 'media.name', 'desc' => 'Displays the Media Name'),

                    array('name' => 'media.deleted.date', 'desc' => 'Displays the Media Deleting Date'),

                    array('name' => 'media.deleted.author', 'desc' => 'Displays the User who Deleted the Media File'),

                ),

                'additional' => array(

                    array('name' => 'media.uploaded.count', 'desc' => 'Displays the Number of Uploaded Media Files'),

                    array('name' => 'media.updated.count', 'desc' => 'Displays the Number of Updated Media Files'),

                    array('name' => 'media.deleted.count', 'desc' => 'Displays the Number of Deleted Media Files'),

                ),

            ),

            'widgets' => array(

                'sections' => array(

                    array('name' => 'section.widgets.added', 'desc' => 'Loops through Widgets Added during the selected date range'),

                    array('name' => 'section.widgets.updated', 'desc' => 'Loops through Widgets Updated during the selected date range'),

                    array('name' => 'section.widgets.deleted', 'desc' => 'Loops through Widgets Deleted during the selected date range'),

                ),

                'nav_group_tokens' => array(

                    'sections' => 'Sections',

                    'added' => 'Added',

                    'updated' => 'Updated',

                    'deleted' => 'Deleted',

                    'additional' => 'Additional',

                ),

                'added' => array(

                    array('name' => 'widget.title', 'desc' => 'Displays the Widget Title'),

                    array('name' => 'widget.added.area', 'desc' => 'Displays the Widget Adding Area'),

                    array('name' => 'widget.added.date', 'desc' => 'Displays the Widget Adding Date'),

                    array('name' => 'widget.added.author', 'desc' => 'Displays the User who Added the Widget'),

                ),

                'updated' => array(

                    array('name' => 'widget.title', 'desc' => 'Displays the Widget Name'),

                    array('name' => 'widget.updated.area', 'desc' => 'Displays the Widget Updating Area'),

                    array('name' => 'widget.updated.date', 'desc' => 'Displays the Widget Updating Date'),

                    array('name' => 'widget.updated.author', 'desc' => 'Displays the User who Updated the Widget'),

                ),

                'deleted' => array(

                    array('name' => 'widget.title', 'desc' => 'Displays the Widget Name'),

                    array('name' => 'widget.deleted.area', 'desc' => 'Displays the Widget Deleting Area'),

                    array('name' => 'widget.deleted.date', 'desc' => 'Displays the Widget Deleting Date'),

                    array('name' => 'widget.deleted.author', 'desc' => 'Displays the User who Deleted the Widget'),

                ),

                'additional' => array(

                    array('name' => 'widget.added.count', 'desc' => 'Displays the Number of Added Widgets'),

                    array('name' => 'widget.updated.count', 'desc' => 'Displays the Number of Updated Widgets'),

                    array('name' => 'widget.deleted.count', 'desc' => 'Displays the Number of Deleted Widgets'),

                ),

            ),

            'menus' => array(

                'sections' => array(

                    array('name' => 'section.menus.created', 'desc' => 'Loops through Menus Created during the selected date range'),

                    array('name' => 'section.menus.updated', 'desc' => 'Loops through Menus Updated during the selected date range'),

                    array('name' => 'section.menus.deleted', 'desc' => 'Loops through Menus Deleted during the selected date range'),

                ),

                'nav_group_tokens' => array(

                    'sections' => 'Sections',

                    'created' => 'Created',

                    'updated' => 'Updated',

                    'deleted' => 'Deleted',

                    'additional' => 'Additional',

                ),

                'created' => array(

                    array('name' => 'menu.title', 'desc' => 'Displays the Menu Name'),

                    array('name' => 'menu.created.date', 'desc' => 'Displays the Menu Creation Date'),

                    array('name' => 'menu.created.author', 'desc' => 'Displays the User who Created the Menu'),

                ),

                'updated' => array(

                    array('name' => 'menu.title', 'desc' => 'Displays the Menu Name'),

                    array('name' => 'menu.updated.date', 'desc' => 'Displays the Menu Updating Date'),

                    array('name' => 'menu.updated.author', 'desc' => 'Displays the User who Updated the Menu'),

                ),

                'deleted' => array(

                    array('name' => 'menu.title', 'desc' => 'Displays the Menu Name'),

                    array('name' => 'menu.deleted.date', 'desc' => 'Displays the Menu Deleting Date'),

                    array('name' => 'menu.deleted.author', 'desc' => 'Displays the User who Deleted the Menu'),

                ),

                'additional' => array(

                    array('name' => 'menu.created.count', 'desc' => 'Displays the Number of Created Menus'),

                    array('name' => 'menu.updated.count', 'desc' => 'Displays the Number of Updated Menus'),

                    array('name' => 'menu.deleted.count', 'desc' => 'Displays the Number of Deleted Menus'),

                ),

            ),

            'wordpress' => array(

                'sections' => array(

                    array('name' => 'section.wordpress.updated', 'desc' => 'Loops through WordPress Updates during the selected date range'),

                ),

                'nav_group_tokens' => array(

                    'sections' => 'Sections',

                    'updated' => 'Updated',

                    'additional' => 'Additional',

                ),

                'updated' => array(

                    array('name' => 'wordpress.updated.date', 'desc' => 'Displays the WordPress Update Date'),

                    array('name' => 'wordpress.updated.author', 'desc' => 'Displays the User who Updated the Site'),

                ),

                'additional' => array(

                    array('name' => 'wordpress.old.version', 'desc' => 'Displays the WordPress Version Before Update'),

                    array('name' => 'wordpress.current.version', 'desc' => 'Displays the Current WordPress Version'),

                    array('name' => 'wordpress.updated.count', 'desc' => 'Displays the Number of WordPress Updates'),

                ),

            ),

            'backups' => array(

                'sections' => array(

                    array('name' => 'section.backups.created', 'desc' => ' Loops through Backups Created during the selected date range'),

                ),

                'nav_group_tokens' => array(

                    'sections' => 'Sections',

                    'created' => 'Created',

                    'additional' => 'Additional',

                ),

                'created' => array(

                    array('name' => 'backup.created.type', 'desc' => ' Displays the Created Backup type (Full or Database)'),

                    array('name' => 'backup.created.date', 'desc' => 'Displays the Backups Creation date'),

                //array("name" => "backup.created.destination", "desc" => "Displays the Created Backup destination")

                ),

                'additional' => array(

                    array('name' => 'backup.created.count', 'desc' => 'Displays the number of created backups during the selected date range'),

                ),

            ),

            'report' => array(

                'nav_group_tokens' => array('report' => 'Report'),

                'report' => array(

                    array('name' => 'report.daterange', 'desc' => 'Displays the report date range'),

                ),

            ),

            'sucuri' => array(

                'sections' => array(

                    array('name' => 'section.sucuri.checks', 'desc' => 'Loops through Security Checks during the selected date range'),

                ),

                'nav_group_tokens' => array(

                    'sections' => 'Sections',

                    'check' => 'Checks',

                    'additional' => 'Additional',

                ),

                'check' => array(

                    array('name' => 'sucuri.check.date', 'desc' => 'Displays the Security Check date'),

                    array('name' => 'sucuri.check.status', 'desc' => 'Displays the Status info for the Child Site'),

                    array('name' => 'sucuri.check.webtrust', 'desc' => 'Displays the Webtrust info for the Child Site'),

                //array("name" => "sucuri.check.results", "desc" => "Displays the Security Check details from the Security Scan Report"),

                ),

                'additional' => array(

                    array('name' => 'sucuri.checks.count', 'desc' => 'Displays the number of performed security checks during the selected date range'),

                ),

            ),

            'ga' => array(

                'nav_group_tokens' => array(

                    'ga' => 'GA',

                ),

                'ga' => array(

                    array('name' => 'ga.visits', 'desc' => 'Displays the Number Visits during the selected date range'),

                    array('name' => 'ga.pageviews', 'desc' => 'Displays the Number of Page Views during the selected date range'),

                    array('name' => 'ga.pages.visit', 'desc' => 'Displays the Number of Page visit during the selected date range'),

                    array('name' => 'ga.bounce.rate', 'desc' => 'Displays the Bounce Rate during the selected date range'),

                    array('name' => 'ga.avg.time', 'desc' => 'Displays the Average Visit Time during the selected date range'),

                    array('name' => 'ga.new.visits', 'desc' => 'Displays the Number of New Visits during the selected date range'),

                    array('name' => 'ga.visits.chart', 'desc' => 'Displays a chart for the activity over the past month'),

                    array('name' => 'ga.visits.maximum', 'desc' => "Displays the maximum visitor number and it's day within the past month"),

                    array('name' => 'ga.startdate', 'desc' => 'Displays the startdate for the chart'),

                    array('name' => 'ga.enddate', 'desc' => 'Displays the enddate or the chart'),

                //array("name" => "ga.visits.chart", "desc" => "...")

                ),

            ),

            'piwik' => array(

                'nav_group_tokens' => array(

                    'piwik' => 'Piwik',

                ),

                'piwik' => array(

                    array('name' => 'piwik.visits', 'desc' => 'Displays the Number Visits during the selected date range'),

                    array('name' => 'piwik.pageviews', 'desc' => 'Displays the Number of Page Views during the selected date range'),

                    array('name' => 'piwik.pages.visit', 'desc' => 'Displays the Number of Page visit during the selected date range'),

                    array('name' => 'piwik.bounce.rate', 'desc' => 'Displays the Bounce Rate during the selected date range'),

                    array('name' => 'piwik.avg.time', 'desc' => 'Displays the Average Visit Time during the selected date range'),

                    array('name' => 'piwik.new.visits', 'desc' => 'Displays the Number of New Visits during the selected date range'),

                ),

            ),

            'aum' => array(

                'nav_group_tokens' => array(

                    'aum' => 'AUM',

                ),

                'aum' => array(

                    array('name' => 'aum.alltimeuptimeratio', 'desc' => 'Displays the Uptime ratio from the moment the monitor has been created'),

                    array('name' => 'aum.uptime7', 'desc' => 'Displays the Uptime ratio for last 7 days'),

                    array('name' => 'aum.uptime15', 'desc' => 'Displays the Uptime ration for last 15 days'),

                    array('name' => 'aum.uptime30', 'desc' => 'Displays the Uptime ration for last 30 days'),

                    array('name' => 'aum.uptime45', 'desc' => 'Displays the Uptime ration for last 45 days'),

                    array('name' => 'aum.uptime60', 'desc' => 'Displays the Uptime ration for last 60 days'),

                ),

            ),

            'woocomstatus' => array(

                'nav_group_tokens' => array(

                    'woocomstatus' => 'WooCommerce Status',

                ),

                'woocomstatus' => array(

                    array('name' => 'wcomstatus.sales', 'desc' => 'Displays total sales during the selected data range'),

                    array('name' => 'wcomstatus.topseller', 'desc' => 'Displays the top seller product during the selected data range'),

                    array('name' => 'wcomstatus.awaitingprocessing', 'desc' => 'Displays the number of products currently awaiting for processing'),

                    array('name' => 'wcomstatus.onhold', 'desc' => 'Displays the number of orders currently on hold'),

                    array('name' => 'wcomstatus.lowonstock', 'desc' => 'Displays the number of products currently low on stock'),

                    array('name' => 'wcomstatus.outofstock', 'desc' => 'Displays the number of products currently out of stock'),

                ),

            ),

        );



        self::$tokens_nav_top = array(

            'client' => 'Client Tokens',

            'report' => 'Report',

            'plugins' => 'Plugins',

            'themes' => 'Themes',

            'posts' => 'Posts',

            'pages' => 'Pages',

            'comments' => 'Comments',

            'users' => 'Users',

            'media' => 'Media',

            'widgets' => 'Widgets',

            'menus' => 'Menus',

            'wordpress' => 'WordPress',

            'backups' => 'Backups',

            'sucuri' => 'Sucuri',

            'ga' => 'GA',

            'piwik' => 'Piwik',

            'aum' => 'AUM',

            'woocomstatus' => 'WooCommerce Status',

        );

    }



    public function admin_init() {

                        

        if (!in_array('mainwp-client-reports-extension/mainwp-client-reports-extension.php', apply_filters('active_plugins', get_option('active_plugins')))) {

            add_action('mainwp-extension-sites-edit', array(&$this, 'site_token'), 9, 1);

        }





        add_action('mainwp_update_site', array(&$this, 'update_site_update_tokens'), 8, 1);

        add_action('mainwp_delete_site', array(&$this, 'delete_site_delete_tokens'), 8, 1);

        add_action('mainwp_shortcuts_widget', array(&$this, 'shortcuts_widget'), 10, 1);

        add_filter('mainwp_managesites_column_url', array(&$this, 'managesites_column_url'), 10, 2);

        add_action('mainwp_managesite_backup', array(&$this, 'managesite_backup'), 10, 3);

        add_action('mainwp_sucuri_scan_done', array(&$this, 'sucuri_scan_done'), 10, 3);



        self::$enabled_piwik = apply_filters('mainwp-extension-available-check', 'mainwp-piwik-extension');

        self::$enabled_sucuri = apply_filters('mainwp-extension-available-check', 'mainwp-sucuri-extension');

        self::$enabled_ga = apply_filters('mainwp-extension-available-check', 'mainwp-google-analytics-extension');

        self::$enabled_aum = apply_filters('mainwp-extension-available-check', 'advanced-uptime-monitor-extension');

        self::$enabled_woocomstatus = apply_filters('mainwp-extension-available-check', 'mainwp-woocommerce-status-extension');



        self::$stream_tokens = apply_filters('mainwp_client_reports_tokens_groups', self::$stream_tokens);

        self::$tokens_nav_top = apply_filters('mainwp_client_reports_tokens_nav_top', self::$tokens_nav_top);

    }



    function managesite_backup($website, $args, $information) {

        if (empty($website)) {

            return;

        }

        $type = isset($args['type']) ? $args['type'] : '';

        if (empty($type)) {

            return;

        }

        //error_log(print_r($information,true));

        global $mainWPCReportExtensionActivator;



        $backup_type = ('full' == $type) ? 'Full' : ('db' == $type ? 'Database' : '');



        $message = '';

        $backup_status = 'success';

        $backup_size = 0;

        if (isset($information['error'])) {

            $message = $information['error'];

            $backup_status = 'failed';

        } else if ('db' == $type && !$information['db']) {

            $message = 'Database backup failed.';

            $backup_status = 'failed';

        } else if ('full' == $type && !$information['full']) {

            $message = 'Full backup failed.';

            $backup_status = 'failed';

        } else if (isset($information['db'])) {

            if (false != $information['db']) {

                $message = 'Backup database success.';

            } else if (false != $information['full']) {

                $message = 'Full backup success.';

            }

            if (isset($information['size'])) {

                $backup_size = $information['size'];

            }

        } else {

            $message = 'Database backup failed due to an undefined error';

            $backup_status = 'failed';

        }



        // save results to child site stream

        $post_data = array(

            'mwp_action' => 'save_backup_stream',

            'size' => $backup_size,

            'message' => $message,

            'destination' => 'Local Server',

            'status' => $backup_status,

            'type' => $backup_type,

        );

        apply_filters('mainwp_fetchurlauthed', $mainWPCReportExtensionActivator->get_child_file(), $mainWPCReportExtensionActivator->get_child_key(), $website->id, 'client_report', $post_data);

    }



    public static function managesite_schedule_backup($website, $args, $backupResult) {



        if (empty($website)) {

            return;

        }



        $type = isset($args['type']) ? $args['type'] : '';

        if (empty($type)) {

            return;

        }



        $destination = '';

        if (is_array($backupResult)) {

            $error = false;

            if (isset($backupResult['error'])) {

                $destination .= $backupResult['error'] . '<br />';

                $error = true;

            }



            if (isset($backupResult['ftp'])) {

                if ('success' != $backupResult['ftp']) {

                    $destination .= 'FTP: ' . $backupResult['ftp'] . '<br />';

                    $error = true;

                } else {

                    $destination .= 'FTP: success<br />';

                }

            }



            if (isset($backupResult['dropbox'])) {

                if ('success' != $backupResult['dropbox']) {

                    $destination .= 'Dropbox: ' . $backupResult['dropbox'] . '<br />';

                    $error = true;

                } else {

                    $destination .= 'Dropbox: success<br />';

                }

            }

            if (isset($backupResult['amazon'])) {

                if ('success' != $backupResult['amazon']) {

                    $destination .= 'Amazon: ' . $backupResult['amazon'] . '<br />';

                    $error = true;

                } else {

                    $destination .= 'Amazon: success<br />';

                }

            }



            if (isset($backupResult['copy'])) {

                if ('success' != $backupResult['copy']) {

                    $destination .= 'Copy.com: ' . $backupResult['amazon'] . '<br />';

                    $error = true;

                } else {

                    $destination .= 'Copy.com: success<br />';

                }

            }



            if (empty($destination)) {

                $destination = 'Local Server';

            }

        } else {

            $destination = $backupResult;

        }



        if ('full' == $type) {

            $message = 'Schedule full backup.';

            $backup_type = 'Full';

        } else {

            $message = 'Schedule database backup.';

            $backup_type = 'Database';

        }



        global $mainWPCReportExtensionActivator;



        // save results to child site stream

        $post_data = array(

            'mwp_action' => 'save_backup_stream',

            'size' => 'N/A',

            'message' => $message,

            'destination' => $destination,

            'status' => 'N/A',

            'type' => $backup_type,

        );

        apply_filters('mainwp_fetchurlauthed', $mainWPCReportExtensionActivator->get_child_file(), $mainWPCReportExtensionActivator->get_child_key(), $website->id, 'client_report', $post_data);

    }



    function mainwp_postprocess_backup_sites_feedback($output, $unique) {

        if (!is_array($output)) {

            

        } else {

            foreach ($output as $key => $value) {

                $output[$key] = $value;

            }

        }



        return $output;

    }



    public function init_cron() {

        

    }



    public static function cal_schedule_nextsend($schedule, $start_recurring_date, $scheduleLastSend = 0) {

        if (empty($schedule) || empty($start_recurring_date)) {

            return 0;

        }



        $start_today = strtotime(date('Y-m-d') . ' 00:00:00');

        $end_today = strtotime(date('Y-m-d') . ' 23:59:59');



        $next_report_date_to = 0;



        if (0 == $scheduleLastSend) {

            if ($start_recurring_date > $end_today) {

                $next_report_date_to = $start_recurring_date;

            } else if ($start_recurring_date > $start_today) {

                $next_report_date_to = $end_today;

            } else {

                $scheduleLastSend = $start_recurring_date;

            }

        }



        // need to calc next send report date

        if (0 == $next_report_date_to) {

            if ('daily' == $schedule) {

                $next_report_date_to = $scheduleLastSend + 24 * 3600;

                while ($next_report_date_to < $start_today) {

                    $next_report_date_to += 24 * 3600;

                }

            } else if ('weekly' == $schedule) {

                $next_report_date_to = $scheduleLastSend + 7 * 24 * 3600;

                while ($next_report_date_to < $start_today) {

                    $next_report_date_to += 24 * 3600;

                }

            } else if ('biweekly' == $schedule) {

                $next_report_date_to = $scheduleLastSend + 2 * 7 * 24 * 3600;

                while ($next_report_date_to < $start_today) {

                    $next_report_date_to += 2 * 7 * 24 * 3600;

                }

            } else if ('monthly' == $schedule) {

                $next_report_date_to = self::calc_next_schedule_send_date($start_recurring_date, $scheduleLastSend, 1);

                while ($next_report_date_to < $start_today) {

                    $next_report_date_to = self::calc_next_schedule_send_date($start_recurring_date, $next_report_date_to, 1);

                }

            } else if ('quarterly' == $schedule) {

                $next_report_date_to = self::calc_next_schedule_send_date($start_recurring_date, $scheduleLastSend, 3);

                while ($next_report_date_to < $start_today) {

                    $next_report_date_to = self::calc_next_schedule_send_date($start_recurring_date, $next_report_date_to, 3);

                }

            } else if ('twice_a_year' == $schedule) {

                $next_report_date_to = self::calc_next_schedule_send_date($start_recurring_date, $scheduleLastSend, 6);

                while ($next_report_date_to < $start_today) {

                    $next_report_date_to = self::calc_next_schedule_send_date($start_recurring_date, $next_report_date_to, 6);

                }

            } else if ('' == $schedule) {

                $next_report_date_to = self::calc_next_schedule_send_date($start_recurring_date, $scheduleLastSend, 12);

                while ($next_report_date_to < $start_today) {

                    $next_report_date_to = self::calc_next_schedule_send_date($start_recurring_date, $next_report_date_to, 12);

                }

            }

        }

        return $next_report_date_to;

    }



    public static function calc_next_schedule_send_date($recurring_date, $lastSend, $monthSteps) {

        $day_to_send = date('d', $recurring_date);

        $month_last_send = date('m', $lastSend);

        $year_last_send = date('Y', $lastSend);



        $day_in_month = date('t');

        if ($day_to_send > $day_in_month) {

            $day_to_send = $day_in_month;

        }



        $month_to_send = $month_last_send + $monthSteps;

        $year_to_send = $year_last_send;

        if ($month_to_send > 12) {

            $month_to_send = $month_to_send - 12;

            $year_to_send = $year_last_send + 1;

        }

        return strtotime($year_to_send . '-' . $month_to_send . '-' . $day_to_send . ' 23:59:59');

    }



    public function shortcuts_widget($website) {

        if (!empty($website)) {

            $reports = LiveReportResponder_DB::get_instance()->get_report_by('site', $website->id);

            $reports_lnk = '';

            if (is_array($reports) && count($reports) > 0) {

                $reports_lnk = '<a href="admin.php?page=Extensions-Mainwp-Client-Reports-Extension&site=' . $website->id . '">' . __('Reports', 'mainwp-client-reports-extension') . '</a> | ';

            }

            ?>

            <div class="mainwp-row">

                <div style="display: inline-block; width: 100px;"><?php _e('Client Reports:', 'mainwp-client-reports-extension'); ?></div>

                <?php echo $reports_lnk; ?>

                <a href="admin.php?page=Extensions-Mainwp-Client-Reports-Extension&action=newreport&selected_site=<?php echo $website->id; ?>"><?php _e('New Report', 'mainwp-client-reports-extension'); ?></a>

            </div>

            <?php

        }

    }



    public function managesites_column_url($actions, $site_id) {

        if (!empty($site_id)) {

            $reports = LiveReportResponder_DB::get_instance()->get_report_by('site', $site_id);

            $link = '';

            if (is_array($reports) && count($reports) > 0) {

                $link = '<a href="admin.php?page=Extensions-Mainwp-Client-Reports-Extension&site=' . $site_id . '">' . __('Reports', 'mainwp-client-reports-extension') . '</a> ' .

                        '( <a href="admin.php?page=Extensions-Mainwp-Client-Reports-Extension&action=newreport&selected_site=' . $site_id . '">' . __('New', 'mainwp-client-reports-extension') . '</a> )';

            } else {

                $link = '<a href="admin.php?page=Extensions-Mainwp-Client-Reports-Extension&action=newreport&selected_site=' . $site_id . '">' . __('New Report', 'mainwp-client-reports-extension') . '</a>';

            }

            $actions['client_reports'] = $link;

        }

        return $actions;

    }



    public static function save_report() {

        if (isset($_REQUEST['action']) && 'editreport' == $_REQUEST['action'] && isset($_REQUEST['nonce']) && wp_verify_nonce($_REQUEST['nonce'], 'mwp_creport_nonce')) {

            $messages = $errors = array();

            $report = array();

            $current_attach_files = '';

            if (isset($_REQUEST['id']) && !empty($_REQUEST['id'])) {

                $report = LiveReportResponder_DB::get_instance()->get_report_by('id', $_REQUEST['id'], null, null, ARRAY_A);

                $current_attach_files = $report['attach_files'];

                //print_r($report);

            }



            if (isset($_POST['mwp_creport_title']) && ($title = trim($_POST['mwp_creport_title'])) != '') {

                $report['title'] = $title;

            }



            $start_time = $end_time = 0;

            if (isset($_POST['mwp_creport_date_from']) && ($start_date = trim($_POST['mwp_creport_date_from'])) != '') {

                $start_time = strtotime($start_date);

            }



            if (isset($_POST['mwp_creport_date_to']) && ($end_date = trim($_POST['mwp_creport_date_to'])) != '') {

                $end_time = strtotime($end_date);

            }



            if (0 == $end_time) {

                $current = time();

                $end_time = mktime(0, 0, 0, date('m', $current), date('d', $current), date('Y', $current));

            }



            if ((0 != $start_time && 0 != $end_time) && ($start_time > $end_time)) {

                $tmp = $start_time;

                $start_time = $end_time;

                $end_time = $tmp;

            }



            $report['date_from'] = $start_time;

            $report['date_to'] = $end_time + 24 * 3600 - 1;  // end of day



            if (isset($_POST['mwp_creport_client'])) {

                $report['client'] = trim($_POST['mwp_creport_client']);

            }



            if (isset($_POST['mwp_creport_client_id'])) {

                $report['client_id'] = intval($_POST['mwp_creport_client_id']);

            }



            if (isset($_POST['mwp_creport_fname'])) {

                $report['fname'] = trim($_POST['mwp_creport_fname']);

            }



            if (isset($_POST['mwp_creport_fcompany'])) {

                $report['fcompany'] = trim($_POST['mwp_creport_fcompany']);

            }



            $from_email = '';

            if (!empty($_POST['mwp_creport_femail'])) {

                $from_email = trim($_POST['mwp_creport_femail']);

                if (!preg_match('/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/is', $from_email)) {

                    $from_email = '';

                    $errors[] = 'Incorrect Email Address in the Send From filed.';

                }

            }

            $report['femail'] = $from_email;



            if (isset($_POST['mwp_creport_name'])) {

                $report['name'] = trim($_POST['mwp_creport_name']);

            }



            if (isset($_POST['mwp_creport_company'])) {

                $report['company'] = trim($_POST['mwp_creport_company']);

            }



            $to_email = '';

            $valid_emails = array();

            if (!empty($_POST['mwp_creport_email'])) {

                $to_emails = explode(',', trim($_POST['mwp_creport_email']));

                if (is_array($to_emails)) {

                    foreach ($to_emails as $_email) {

                        if (!preg_match('/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/is', $_email) && !preg_match('/^\[.+\]/is', $_email)) {

                            $to_email = '';

                            $errors[] = 'Incorrect Email Address in the Send To field.';

                        } else {

                            $valid_emails[] = $_email;

                        }

                    }

                }

            }



            if (count($valid_emails) > 0) {

                $to_email = implode(',', $valid_emails);

            } else {

                $to_email = '';

                $errors[] = 'Incorrect Email Address in the Send To field.';

            }



            $report['email'] = $to_email;



            if (isset($_POST['mwp_creport_email_subject'])) {

                $report['subject'] = trim($_POST['mwp_creport_email_subject']);

            }



            //print_r($_POST);

            if (isset($_POST['mainwp_creport_recurring_schedule'])) {

                $report['recurring_schedule'] = trim($_POST['mainwp_creport_recurring_schedule']);

            }

            if (isset($_POST['mainwp_creport_schedule_date'])) {

                $rec_date = trim($_POST['mainwp_creport_schedule_date']);

                $report['recurring_date'] = !empty($rec_date) ? strtotime($rec_date . ' ' . date('H:i:s')) : 0;

            }

            if (isset($_POST['mainwp_creport_schedule_send_email'])) {

                $report['schedule_send_email'] = trim($_POST['mainwp_creport_schedule_send_email']);

            }

            $report['schedule_bcc_me'] = isset($_POST['mainwp_creport_schedule_bbc_me_email']) ? 1 : 0;

            if (isset($_POST['mainwp_creport_report_header'])) {

                $report['header'] = trim($_POST['mainwp_creport_report_header']);

            }



            if (isset($_POST['mainwp_creport_report_body'])) {

                $report['body'] = trim($_POST['mainwp_creport_report_body']);

            }



            if (isset($_POST['mainwp_creport_report_footer'])) {

                $report['footer'] = trim($_POST['mainwp_creport_report_footer']);

            }



            $creport_dir = apply_filters('mainwp_getspecificdir', 'client_report/');

            if (!file_exists($creport_dir)) {

                @mkdir($creport_dir, 0777, true);

            }

            if (!file_exists($creport_dir . '/index.php')) {

                @touch($creport_dir . '/index.php');

            }



            $attach_files = 'NOTCHANGE';

            $delete_files = false;

            if (isset($_POST['mainwp_creport_delete_attach_files']) && '1' == $_POST['mainwp_creport_delete_attach_files']) {

                $attach_files = '';

                if (!empty($current_attach_files)) {

                    self::delete_attach_files($current_attach_files, $creport_dir);

                }

            }



            $return = array();

            if (isset($_FILES['mainwp_creport_attach_files']) && !empty($_FILES['mainwp_creport_attach_files']['name'][0])) {

                if (!empty($current_attach_files)) {

                    self::delete_attach_files($current_attach_files, $creport_dir);

                }



                $output = self::handle_upload_files($_FILES['mainwp_creport_attach_files'], $creport_dir);

                //print_r($output);

                if (isset($output['error'])) {

                    $return['error'] = $output['error'];

                }

                if (is_array($output) && isset($output['filenames']) && !empty($output['filenames'])) {

                    $attach_files = implode(', ', $output['filenames']);

                }

            }



            if ('NOTCHANGE' !== $attach_files) {

                $report['attach_files'] = $attach_files;

            }



            $selected_site = 0;

            $selected_sites = $selected_groups = array();

            if (isset($_POST['mwp_creport_report_type']) && 'global' == $_POST['mwp_creport_report_type']) {

                if (isset($_POST['select_by'])) {

                    if (isset($_POST['selected_sites']) && is_array($_POST['selected_sites'])) {

                        foreach ($_POST['selected_sites'] as $selected) {

                            $selected_sites[] = intval($selected);

                        }

                    }



                    if (isset($_POST['selected_groups']) && is_array($_POST['selected_groups'])) {

                        foreach ($_POST['selected_groups'] as $selected) {

                            $selected_groups[] = intval($selected);

                        }

                    }

                }

                $report['type'] = 1;

            } else {

                $report['type'] = 0;

                if (isset($_POST['select_by'])) {

                    if (isset($_POST['selected_site'])) {

                        $selected_site = intval($_POST['selected_site']);

                    }

                }

            }

            $report['sites'] = base64_encode(serialize($selected_sites));

            $report['groups'] = base64_encode(serialize($selected_groups));

            $report['selected_site'] = $selected_site;



            if ('schedule' === $_POST['mwp_creport_report_submit_action']) {

                $report['scheduled'] = 1;

            }

            $report['schedule_nextsend'] = self::cal_schedule_nextsend($report['recurring_schedule'], $report['recurring_date']);



            if ('save' === $_POST['mwp_creport_report_submit_action'] ||

                    'send' === $_POST['mwp_creport_report_submit_action'] ||

                    'save_pdf' === $_POST['mwp_creport_report_submit_action'] ||

                    'schedule' === $_POST['mwp_creport_report_submit_action'] ||

                    'archive_report' === $_POST['mwp_creport_report_submit_action']) {

                //print_r($report);

                if ($result = LiveReportResponder_DB::get_instance()->update_report($report)) {

                    $return['id'] = $result->id;

                    $messages[] = 'Report has been saved.';

                } else {

                    $messages[] = 'Report has not been changed - Report Saved.';

                }

                $return['saved'] = true;

            } else if ('preview' === (string) $_POST['mwp_creport_report_submit_action'] ||

                    'send_test_email' === (string) $_POST['mwp_creport_report_submit_action']

            ) {

                $submit_report = json_decode(json_encode($report));

                $return['submit_report'] = $submit_report;

            }



            if (!isset($return['id']) && isset($report['id'])) {

                $return['id'] = $report['id'];

            }



            if (count($errors) > 0) {

                $return['error'] = $errors;

            }



            if (count($messages) > 0) {

                $return['message'] = $messages;

            }



            return $return;

        }

        return null;

    }



    static function delete_attach_files($files, $dir) {

        $files = explode(',', $files);

        if (is_array($files)) {

            foreach ($files as $file) {

                $file = trim($file);

                $file_path = $dir . $file;

                if (file_exists($file_path)) {

                    @unlink($file_path);

                }

            }

        }

    }



    public static function handle_upload_files($file_input, $dest_dir) {

        $output = array();

        $attachFiles = array();

        $allowed_files = array('jpeg', 'jpg', 'gif', 'png', 'rar', 'zip', 'pdf');



        $tmp_files = $file_input['tmp_name'];

        if (is_array($tmp_files)) {

            foreach ($tmp_files as $i => $tmp_file) {

                if ((UPLOAD_ERR_OK == $file_input['error'][$i]) && is_uploaded_file($tmp_file)) {

                    $file_size = $file_input['size'][$i];

                    // = $file_input['type'][$i];

                    $file_name = $file_input['name'][$i];

                    $file_ext = strtolower(end(explode('.', $file_name)));

                    if (($file_size > 5 * 1024 * 1024)) {

                        $output['error'][] = $file_name . ' - ' . __('File size too big');

                    } else if (!in_array($file_ext, $allowed_files)) {

                        $output['error'][] = $file_name . ' - ' . __('File type are not allowed');

                    } else {

                        $dest_file = $dest_dir . $file_name;

                        $dest_file = dirname($dest_file) . '/' . wp_unique_filename(dirname($dest_file), basename($dest_file));

                        if (move_uploaded_file($tmp_file, $dest_file)) {

                            $attachFiles[] = basename($dest_file);

                        } else {

                            $output['error'][] = $file_name . ' - ' . __('Can not copy file');

                        }

                        ;

                    }

                }

            }

        }

        $output['filenames'] = $attachFiles;

        return $output;

    }



    public static function handle_upload_image($file_input, $dest_dir, $max_height, $max_width = null) {

        $output = array();

        $processed_file = '';

        if (UPLOAD_ERR_OK == $file_input['error']) {

            $tmp_file = $file_input['tmp_name'];

            if (is_uploaded_file($tmp_file)) {

                $file_size = $file_input['size'];

                $file_type = $file_input['type'];

                $file_name = $file_input['name'];

                $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));



                if (($file_size > 500 * 1025)) {

                    $output['error'][] = 'File size is too large.';

                } elseif (

                        ('image/jpeg' != $file_type) &&

                        ('image/jpg' != $file_type) &&

                        ('image/gif' != $file_type) &&

                        ('image/png' != $file_type)

                ) {

                    $output['error'][] = 'File Type is not allowed.';

                } elseif (

                        ('jpeg' != $file_extension) &&

                        ('jpg' != $file_extension) &&

                        ('gif' != $file_extension) &&

                        ('png' != $file_extension)

                ) {

                    $output['error'][] = 'File Extension is not allowed.';

                } else {

                    $dest_file = $dest_dir . $file_name;

                    $dest_file = dirname($dest_file) . '/' . wp_unique_filename(dirname($dest_file), basename($dest_file));



                    if (move_uploaded_file($tmp_file, $dest_file)) {

                        if (file_exists($dest_file)) {

                            list( $width, $height, $type, $attr ) = getimagesize($dest_file);

                        }



                        $resize = false;

                        //                        if ($width > $max_width) {

                        //                            $dst_width = $max_width;

                        //                            if ($height > $max_height)

                        //                                $dst_height = $max_height;

                        //                            else

                        //                                $dst_height = $height;

                        //                            $resize = true;

                        //                        } else

                        if ($height > $max_height) {

                            $dst_height = $max_height;

                            $dst_width = $width * $max_height / $height;

                            $resize = true;

                        }



                        if ($resize) {

                            $src = $dest_file;

                            $cropped_file = wp_crop_image($src, 0, 0, $width, $height, $dst_width, $dst_height, false);

                            if (!$cropped_file || is_wp_error($cropped_file)) {

                                $output['error'][] = __('Can not resize the image.');

                            } else {

                                @unlink($dest_file);

                                $processed_file = basename($cropped_file);

                            }

                        } else {

                            $processed_file = basename($dest_file);

                        }



                        $output['filename'] = $processed_file;

                    } else {

                        $output['error'][] = 'Can not copy the file.';

                    }

                }

            }

        }

        return $output;

    }



    public function un_archive_report($report) {

        if (!empty($report) && !is_object($report)) {

            $report = LiveReportResponder_DB::get_instance()->get_report_by('id', $report);

        }



        if (!$report->is_archived) {

            return true;

        }

        $update_archive = array(

            'id' => $report->id,

            'is_archived' => 0,

            'archive_report' => '',

            'archive_report_pdf' => '',

        );

        if (LiveReportResponder_DB::get_instance()->update_report($update_archive)) {

            return true;

        }

        return false;

    }



    public static function gen_report_content($reports, $combine_report = false) {

        if (!is_array($reports)) {

            $reports = array($reports);

        }



        $remove_default_html = apply_filters('mainwp_client_reports_remove_default_html_tags', false, $reports);



        if ($combine_report) {

            ob_start();

        }

        foreach ($reports as $site_id => $report) {

            if (!$combine_report) {

                ob_start();

            }



            if (is_array($report) && isset($report['error'])) {

                ?>        

                <br>

                <div>

                    <br>

                    <div style="background:#ffffff;padding:0 1.618em;padding-bottom:50px!important">

                        <div style="width:600px;background:#fff;margin-left:auto;margin-right:auto;margin-top:10px;margin-bottom:25px;padding:0!important;border:10px Solid #fff;border-radius:10px;overflow:hidden">

                            <div style="display: block; width: 100% ; ">

                                <div style="display: block; width: 100% ; padding: .5em 0 ;">       

                                    <?php echo $report['error']; ?>

                                </div>   

                            </div>                            

                        </div>

                    </div>  

                </div>  

                <?php

            } else if (is_object($report)) {

                if ($remove_default_html) {

                    echo stripslashes(nl2br($report->filtered_header));

                    echo stripslashes(nl2br($report->filtered_body));

                    echo stripslashes(nl2br($report->filtered_footer));

                } else {

                    ?>        

                    <br>

                    <div>

                        <br>

                        <div style="background:#ffffff;padding:0 1.618em;padding-bottom:50px!important">

                            <div style="width:600px;background:#fff;margin-left:auto;margin-right:auto;margin-top:10px;margin-bottom:25px;padding:0!important;border:10px Solid #fff;border-radius:10px;overflow:hidden">

                                <div style="display: block; width: 100% ; ">

                                    <div style="display: block; width: 100% ; padding: .5em 0 ;">                          

                                        <?php

                                        //echo apply_filters( 'the_content', $report->filtered_header );

                                        echo stripslashes(nl2br($report->filtered_header));

                                        //echo self::do_filter_content($report->filtered_header);

                                        ?>                          

                                        <div style="clear: both;"></div>

                                    </div>

                                </div>

                                <br><br><br>

                                <div>

                                    <?php

                                    //echo apply_filters( 'the_content', $report->filtered_body );

                                    echo stripslashes(nl2br($report->filtered_body));

                                    //echo self::do_filter_content($report->filtered_body);

                                    ?>                        

                                </div>

                                <br><br><br>

                                <div style="display: block; width: 100% ;">

                                    <?php

                                    //echo apply_filters( 'the_content', $report->filtered_footer );

                                    echo stripslashes(nl2br($report->filtered_footer));

                                    //echo self::do_filter_content($report->filtered_footer);

                                    ?>

                                </div>                                



                            </div>                            

                        </div>

                    </div>           

                    <?php

                }

            }



            if (!$combine_report) {

                $html = ob_get_clean();

                $output[$site_id] = $html;

            }

        }

        if ($combine_report) {

            $html = ob_get_clean();

            $output[] = $html;

        }

        return $output;

    }



    static function do_filter_content($content) {

        //        if (preg_match("/(<ga_chart>(.+)<\/ga_chart>)/is", $content, $matches)) {

        //            $chart_content = $matches[2];

        //            $filtered_content = preg_replace("/(<ga_chart>.+<\/ga_chart>)/is",'[GA_CHART_MARKER]',$content);

        //            $filtered_content = stripslashes(nl2br($filtered_content));

        //            $filtered_content = preg_replace("/([GA_CHART_MARKER])/is",'$chart_content',$filtered_content);

        //            $content = $filtered_content;

        //        }

        return $content;

    }



    public static function gen_email_content_pdf($report, $combine_report = false) {

        // to fix bug from mainwp

        if (!function_exists('wp_verify_nonce')) {

            include_once( ABSPATH . WPINC . '/pluggable.php' );

        }



        if (!empty($report) && is_object($report)) {

            if ($report->is_archived) {

                if (!is_serialized($report->archive_report_pdf)) {

                    return array($report->archive_report_pdf);

                } else {

                    return unserialize($report->archive_report_pdf);

                }

            } else {

                $filtered_reports = self::filter_report($report, $allowed_tokens);

                return self::gen_report_content_pdf($filtered_reports, $combine_report);

            }

        }

        return '';

    }



    public static function gen_report_content_pdf($reports, $combine_report = false) {

        if (!is_array($reports)) {

            $reports = array(0 => $reports);

        }



        $remove_default_html = apply_filters('mainwp_client_reports_remove_default_html_tags', false, $reports);



        $output = array();

        if ($combine_report) {

            ob_start();

        }



        foreach ($reports as $site_id => $report) {

            if (!$combine_report) {

                ob_start();

            }



            if (is_array($report) && isset($report['error'])) {

                echo $report['error'];

            } else if (is_object($report)) {

                if ($remove_default_html) {

                    echo stripslashes(nl2br($report->filtered_header));

                    echo stripslashes(nl2br($report->filtered_body));

                    echo stripslashes(nl2br($report->filtered_footer));

                } else {

                    echo stripslashes(nl2br($report->filtered_header));

                    echo '<br><br>';

                    echo stripslashes(nl2br($report->filtered_body));

                    echo '<br><br>';

                    echo stripslashes(nl2br($report->filtered_footer));

                    echo '<br><br>';

                }

            }



            if (!$combine_report) {

                $html = ob_get_clean();

                $output[$site_id] = $html;

            }

        }

        if ($combine_report) {

            $html = ob_get_clean();

            $output[] = $html;

        }

        return $output;

    }



    public static function filter_report($report, $allowed_tokens) {

        global $mainWPCReportExtensionActivator;

        $websites = array();

        if (empty($report->type)) {

            if ($report->selected_site) {

                global $mainWPCReportExtensionActivator;

                $website = apply_filters('mainwp-getsites', $mainWPCReportExtensionActivator->get_child_file(), $mainWPCReportExtensionActivator->get_child_key(), $report->selected_site);

                if ($website && is_array($website)) {

                    $websites[] = current($website);

                }

            }

        } else {

            $sel_sites = unserialize(base64_decode($report->sites));

            $sel_groups = unserialize(base64_decode($report->groups));

            if (!is_array($sel_sites)) {

                $sel_sites = array();

            }

            if (!is_array($sel_groups)) {

                $sel_groups = array();

            }

            $dbwebsites = apply_filters('mainwp-getdbsites', $mainWPCReportExtensionActivator->get_child_file(), $mainWPCReportExtensionActivator->get_child_key(), $sel_sites, $sel_groups);

            $websites = array();

            if (is_array($dbwebsites)) {

                foreach ($dbwebsites as $site) {

                    $websites[] = MainWP_Live_Reports_Utility::map_site($site, array('id', 'name', 'url'));

                }

            }

        }



        if (count($websites) == 0) {

            return $report;

        }



        $filtered_reports = array();

        foreach ($websites as $site) {

            $filtered_reports[$site['id']] = self::filter_report_website($report, $site, $allowed_tokens);

        }

        return $filtered_reports;

    }



    public static function filter_report_website($report, $website, $allowed_tokens) {

        $output = new stdClass();

        $output->filtered_header = $report->header;

        $output->filtered_body = $report->body;

        $output->filtered_footer = $report->footer;

        $output->id = isset($report->id) ? $report->id : 0;

        $get_ga_tokens = ((strpos($report->header, '[ga.') !== false) || (strpos($report->body, '[ga.') !== false) || (strpos($report->footer, '[ga.') !== false)) ? true : false;

        $get_ga_chart = ((strpos($report->header, '[ga.visits.chart]') !== false) || (strpos($report->body, '[ga.visits.chart]') !== false) || (strpos($report->footer, '[ga.visits.chart]') !== false)) ? true : false;

        $get_ga_chart = $get_ga_chart || (((strpos($report->header, '[ga.visits.maximum]') !== false) || (strpos($report->body, '[ga.visits.maximum]') !== false) || (strpos($report->footer, '[ga.visits.maximum]') !== false)) ? true : false);



        $get_piwik_tokens = ((strpos($report->header, '[piwik.') !== false) || (strpos($report->body, '[piwik.') !== false) || (strpos($report->footer, '[piwik.') !== false)) ? true : false;

        $get_aum_tokens = ((strpos($report->header, '[aum.') !== false) || (strpos($report->body, '[aum.') !== false) || (strpos($report->footer, '[aum.') !== false)) ? true : false;

        $get_woocom_tokens = ((strpos($report->header, '[wcomstatus.') !== false) || (strpos($report->body, '[wcomstatus.') !== false) || (strpos($report->footer, '[wcomstatus.') !== false)) ? true : false;

        if (null !== $website) {

            $tokens = LiveReportResponder_DB::get_instance()->get_tokens();

            $site_tokens = LiveReportResponder_DB::get_instance()->get_site_tokens($website['url']);

            $replace_tokens_values = array();

            foreach ($tokens as $token) {

                $replace_tokens_values['[' . $token->token_name . ']'] = isset($site_tokens[$token->id]) ? $site_tokens[$token->id]->token_value : '';

            }



            if ($get_piwik_tokens) {

                $piwik_tokens = self::piwik_data($website['id'], $report->date_from, $report->date_to);

                if (is_array($piwik_tokens)) {

                    foreach ($piwik_tokens as $token => $value) {

                        $replace_tokens_values['[' . $token . ']'] = $value;

                    }

                }

            }



            if ($get_ga_tokens) {

                $ga_tokens = self::ga_data($website['id'], $report->date_from, $report->date_to, $get_ga_chart);

                if (is_array($ga_tokens)) {

                    foreach ($ga_tokens as $token => $value) {

                        $replace_tokens_values['[' . $token . ']'] = $value;

                    }

                }

            }



            if ($get_aum_tokens) {

                $aum_tokens = self::aum_data($website['id'], $report->date_from, $report->date_to);

                if (is_array($aum_tokens)) {

                    foreach ($aum_tokens as $token => $value) {

                        $replace_tokens_values['[' . $token . ']'] = $value;

                    }

                }

            }



            if ($get_woocom_tokens) {

                $wcomstatus_tokens = self::woocomstatus_data($website['id'], $report->date_from, $report->date_to);

                if (is_array($wcomstatus_tokens)) {

                    foreach ($wcomstatus_tokens as $token => $value) {

                        $replace_tokens_values['[' . $token . ']'] = $value;

                    }

                }

            }

            $replace_tokens_values['[report.daterange]'] = MainWP_Live_Reports_Utility::format_timestamp($report->date_from) . ' - ' . MainWP_Live_Reports_Utility::format_timestamp($report->date_to);

            ;

            $replace_tokens_values = apply_filters('mainwp_client_reports_custom_tokens', $replace_tokens_values, $report);



            $report_header = $report->header;

            $report_body = $report->body;

            $report_footer = $report->footer;



            /* // Restrictions on Client Tokens

              if (!empty($allowed_tokens)) {

              $newarrayallowedclienttokens = array();

              $clienttokensarray = unserialize(stripslashes($allowed_tokens));

              foreach ($clienttokensarray as $key => $tname) {

              $newarrayallowedclienttokens["[" . $tname . "]"] = $key;

              }

              $replace_tokens_values = array_intersect_key($replace_tokens_values, $newarrayallowedclienttokens);

              } */

//            

//            



            $result = self::parse_report_content($report_header, $replace_tokens_values, $allowed_tokens);



            if (!empty($allowed_tokens)) {

                $newarrayallowedtokens = array();

                $tokensarray = unserialize(stripslashes($allowed_tokens));

                foreach ($tokensarray as $key => $t) {

                    $newarrayallowedtokens[$key] = "[" . $t . "]";

                }

                $result['other_tokens'] = array_intersect($newarrayallowedtokens, $result['other_tokens']);

            }



            $found_tokens = $result['sections']['section_token'];

            //print_r($result);

            self::$buffer['sections']['header'] = $sections['header'] = $result['sections'];

            $other_tokens['header'] = $result['other_tokens'];

            $filtered_header = $result['filtered_content'];

            unset($result);



            $result = self::parse_report_content($report_body, $replace_tokens_values, $allowed_tokens);

            //print_r($result);

            self::$buffer['sections']['body'] = $sections['body'] = $result['sections'];

            $other_tokens['body'] = $result['other_tokens'];

            $filtered_body = $result['filtered_content'];

            unset($result);



            $result = self::parse_report_content($report_footer, $replace_tokens_values, $allowed_tokens);

            //print_r($result);



            self::$buffer['sections']['footer'] = $sections['footer'] = $result['sections'];

            $other_tokens['footer'] = $result['other_tokens'];

            $filtered_footer = $result['filtered_content'];

            unset($result);

            //print_r($sections);

            // get data from stream plugin

            $sections_data = $other_tokens_data = array();



            $information = self::fetch_stream_data($website, $report, $sections, $other_tokens);







            if (!empty($allowed_tokens)) {

                $newarrayallowedtokens = array();

                $tokensarray = unserialize(stripslashes($allowed_tokens));

                foreach ($tokensarray as $key => $t) {

                    $newarrayallowedtokens[$key] = "[" . $t . "]";

                }

//                $found_tokens = array("[section.plugins.installed]", "[section.plugins.edited]", "[section.plugins.deactivated]", "[section.plugins.activated]");



                $checkdisallowedtokens = array();

                if (isset($found_tokens) && !empty($found_tokens)) {

                    foreach ($found_tokens as $a) {

                        if (in_array($a, $newarrayallowedtokens)) {

                            $checkdisallowedtokens[] = "yes";

                        } else {

                            $checkdisallowedtokens[] = "no";

                        }

                    }

                }

                $Newinformation_array = array();

                if (isset($information['sections_data']['header']) && !empty($information['sections_data']['header'])) {

                    foreach ($information['sections_data']['header'] as $key => $value) {

                        if ($checkdisallowedtokens[$key] == "yes") {

                            $Newinformation_array["header"][] = $value;

                        } else {

                            $Newinformation_array["header"][] = array();

                        }

                    }

                    $information['sections_data'] = $Newinformation_array;

                }

            }



//             $file = fopen(dirname(__FILE__) . "/response.txt", "a+");

//            fwrite($file, serialize($result['sections']['section_token']) . " \n");

//            print_r($information);

//    $information=array(

//    "other_tokens_data"=>array("header"=>array(),"body"=>array(),"footer"=>array()),

//    "sections_data" => array("header"=>array(array(),array(),array(),array(array("[plugin.name]"=>"MainWP Child Reports"),array("[plugin.name]"=>"WooCommerce Blacklister"))))

//);

            if (is_array($information) && !isset($information['error'])) {

                self::$buffer['sections_data'] = $sections_data = isset($information['sections_data']) ? $information['sections_data'] : array();

                $other_tokens_data = isset($information['other_tokens_data']) ? $information['other_tokens_data'] : array();

            } else {

                self::$buffer = array();

                return $information;

            }

            unset($information);



            self::$count_sec_header = self::$count_sec_body = self::$count_sec_footer = 0;

            if (isset($sections_data['header']) && is_array($sections_data['header']) && count($sections_data['header']) > 0) {

                $filtered_header = preg_replace_callback('/(\[section\.[^\]]+\])(.*?)(\[\/section\.[^\]]+\])/is', array('MainWP_Live_Reports_Class', 'section_mark_header'), $filtered_header);

            }



            if (isset($sections_data['body']) && is_array($sections_data['body']) && count($sections_data['body']) > 0) {

                $filtered_body = preg_replace_callback('/(\[section\.[^\]]+\])(.*?)(\[\/section\.[^\]]+\])/is', array('MainWP_Live_Reports_Class', 'section_mark_body'), $filtered_body);

            }



            if (isset($sections_data['footer']) && is_array($sections_data['footer']) && count($sections_data['footer']) > 0) {

                $filtered_footer = preg_replace_callback('/(\[section\.[^\]]+\])(.*?)(\[\/section\.[^\]]+\])/is', array('MainWP_Live_Reports_Class', 'section_mark_footer'), $filtered_footer);

            }



            if (isset($other_tokens_data['header']) && is_array($other_tokens_data['header']) && count($other_tokens_data['header']) > 0) {

                $search = $replace = array();

                foreach ($other_tokens_data['header'] as $token => $value) {

                    if (in_array($token, $other_tokens['header'])) {

                        $search[] = $token;

                        $replace[] = $value;

                    }

                }

                $filtered_header = self::replace_content($filtered_header, $search, $replace);

            }



            if (isset($other_tokens_data['body']) && is_array($other_tokens_data['body']) && count($other_tokens_data['body']) > 0) {

                $search = $replace = array();

                foreach ($other_tokens_data['body'] as $token => $value) {

                    if (in_array($token, $other_tokens['body'])) {

                        $search[] = $token;

                        $replace[] = $value;

                    }

                }

                $filtered_body = self::replace_content($filtered_body, $search, $replace);

            }



            if (isset($other_tokens_data['footer']) && is_array($other_tokens_data['footer']) && count($other_tokens_data['footer']) > 0) {

                $search = $replace = array();

                foreach ($other_tokens_data['footer'] as $token => $value) {

                    if (in_array($token, $other_tokens['footer'])) {

                        $search[] = $token;

                        $replace[] = $value;

                    }

                }

                $filtered_footer = self::replace_content($filtered_footer, $search, $replace);

            }



            $output->filtered_header = $filtered_header;

            $output->filtered_body = $filtered_body;

            $output->filtered_footer = $filtered_footer;

            self::$buffer = array();

        }

        return $output;

    }



    public static function section_mark_header($matches) {

        $content = $matches[0];

        $sec = $matches[1];

        $index = self::$count_sec_header;

        $search = self::$buffer['sections']['header']['section_content_tokens'][$index];

        self::$count_sec_header++;

        $sec_content = trim($matches[2]);

        if (isset(self::$buffer['sections_data']['header'][$index]) && !empty(self::$buffer['sections_data']['header'][$index])) {

            $loop = self::$buffer['sections_data']['header'][$index];

            $replaced_content = '';

            if (is_array($loop)) {

                foreach ($loop as $replace) {

                    //$replace = self::sucuri_replace_data($replace);;

                    $replaced = self::replace_section_content($sec_content, $search, $replace);

                    $replaced_content .= $replaced . '<br>';

                }

            }

            return $replaced_content;

        }

        return '';

    }



    public static function section_mark_body($matches) {

        $content = $matches[0];

        $index = self::$count_sec_body;

        $search = self::$buffer['sections']['body']['section_content_tokens'][$index];

        self::$count_sec_body++;

        $sec_content = trim($matches[2]);

        if (isset(self::$buffer['sections_data']['body'][$index]) && !empty(self::$buffer['sections_data']['body'][$index])) {

            $loop = self::$buffer['sections_data']['body'][$index];

            $replaced_content = '';

            if (is_array($loop)) {

                foreach ($loop as $replace) {

                    //$replace = self::sucuri_replace_data($replace);;

                    $replaced = self::replace_section_content($sec_content, $search, $replace);

                    $replaced_content .= $replaced . '<br>';

                }

            }

            return $replaced_content;

        }

        return '';

    }



    public static function section_mark_footer($matches) {

        $content = $matches[0];

        $sec = $matches[1];

        $index = self::$count_sec_footer;

        $search = self::$buffer['sections']['footer']['section_content_tokens'][$index];

        self::$count_sec_footer++;

        $sec_content = trim($matches[2]);

        if (isset(self::$buffer['sections_data']['footer'][$index]) && !empty(self::$buffer['sections_data']['footer'][$index])) {

            $loop = self::$buffer['sections_data']['footer'][$index];

            $replaced_content = '';

            if (is_array($loop)) {

                foreach ($loop as $replace) {

                    //$replace = self::sucuri_replace_data($replace);

                    $replaced = self::replace_section_content($sec_content, $search, $replace);

                    $replaced_content .= $replaced . '<br>';

                }

            }

            return $replaced_content;

        }

        return '';

    }



    function sucuri_scan_done($website_id, $scan_status, $data) {

        $scan_result = array();

        if (is_array($data)) {

            $blacklisted = isset($data['BLACKLIST']['WARN']) ? true : false;

            $malware_exists = isset($data['MALWARE']['WARN']) ? true : false;

            $system_error = isset($data['SYSTEM']['ERROR']) ? true : false;



            $status = array();

            if ($blacklisted) {

                $status[] = __('Site Blacklisted', 'mainwp-client-reports-extension');

            }

            if ($malware_exists) {

                $status[] = __('Site With Warnings', 'mainwp-client-reports-extension');

            }



            $scan_result['status'] = count($status) > 0 ? implode(', ', $status) : __('Verified Clear', 'mainwp-client-reports-extension');

            $scan_result['webtrust'] = $blacklisted ? __('Site Blacklisted', 'mainwp-client-reports-extension') : __('Trusted', 'mainwp-client-reports-extension');

        }

        // save results to child site stream

        $post_data = array(

            'mwp_action' => 'save_sucuri_stream',

            'result' => base64_encode(serialize($scan_result)),

            'scan_status' => $scan_status,

        );

        global $mainWPCReportExtensionActivator;

        apply_filters('mainwp_fetchurlauthed', $mainWPCReportExtensionActivator->get_child_file(), $mainWPCReportExtensionActivator->get_child_key(), $website_id, 'client_report', $post_data);

    }



    public static function replace_content($content, $tokens, $replace_tokens) {

        return str_replace($tokens, $replace_tokens, $content);

    }



    public static function replace_section_content($content, $tokens, $replace_tokens) {

        foreach ($replace_tokens as $token => $value) {

            $content = str_replace($token, $value, $content);

        }

        $content = str_replace($tokens, array(), $content); // clear others tokens

        return $content;

    }



    public static function parse_report_content($content, $replaceTokensValues, $allowed_tokens) {



        $client_tokens = array_keys($replaceTokensValues);

        $replace_values = array_values($replaceTokensValues);









        $filtered_content = $content = str_replace($client_tokens, $replace_values, $content);

        $sections = array();

        if (preg_match_all('/(\[section\.[^\]]+\])(.*?)(\[\/section\.[^\]]+\])/is', $content, $matches)) {

            for ($i = 0; $i < count($matches[1]); $i++) {

                $sec = $matches[1][$i];

                $sec_content = $matches[2][$i];

                $sec_tokens = array();

                if (preg_match_all('/\[[^\]]+\]/is', $sec_content, $matches2)) {

                    $sec_tokens = $matches2[0];

                }

                //$sections[$sec] = $sec_tokens;

//                       if (!empty($allowed_tokens)) {

//                            $newarrayallowedtokens=array();

//                 $tokenssarray=unserialize(stripslashes($allowed_tokens));

//

//            foreach($tokenssarray as $key=>$t){

//    $newarrayallowedtokens[$key]="[".$t."]";

//}

//                if(in_array($sec, $newarrayallowedtokens)){

//                  $sections['section_token'][] = $sec;

//                $sections['section_content_tokens'][] = $sec_tokens;

//                } 

//            }else{

                $sections['section_token'][] = $sec;

                $sections['section_content_tokens'][] = $sec_tokens;

//                }

            }

        }





//            







        $removed_sections = preg_replace_callback('/(\[section\.[^\]]+\])(.*?)(\[\/section\.[^\]]+\])/is', create_function('$matches', 'return "";'), $content);

        $other_tokens = array();

        if (preg_match_all('/\[[^\]]+\]/is', $removed_sections, $matches)) {

            $other_tokens = $matches[0];

        }



        return array('sections' => $sections, 'other_tokens' => $other_tokens, 'filtered_content' => $filtered_content);

    }



    public static function remove_section_tokens($content) {

        $matches = array();

        $section_tokens = array();

        $section = '';

        if (preg_match_all('/\[\/?section\.[^\]]+\]/is', $content, $matches)) {

            $section_tokens = $matches[0];

            $str_tmp = str_replace(array('[', ']'), '', $section_tokens[0]);

            list($context, $action, $section) = explode('.', $str_tmp);

        }

        $content = str_replace($section_tokens, '', $content);

        return array('content' => $content, 'section' => $section);

    }



    static function ga_data($site_id, $start_date, $end_date, $chart = false) {

        // fix bug cron job

        if (null === self::$enabled_ga) {

            self::$enabled_ga = apply_filters('mainwp-extension-available-check', 'mainwp-google-analytics-extension');

        }



        if (!self::$enabled_ga) {

            return false;

        }



        //===============================================================

        //enym new

        //        $end_date = strtotime("-1 day", time());

        //        $start_date = strtotime( '-31 day', time() ); //31 days is more robust than "1 month" and this must match steprange in MainWPGA.class.php

        //===============================================================



        if (!$site_id || !$start_date || !$end_date) {

            return false;

        }

        $uniq = 'ga_' . $site_id . '_' . $start_date . '_' . $end_date;

        if (isset(self::$buffer[$uniq])) {

            return self::$buffer[$uniq];

        }



        $result = apply_filters('mainwp_ga_get_data', $site_id, $start_date, $end_date, $chart);

        $output = array(

            'ga.visits' => 'N/A',

            'ga.pageviews' => 'N/A',

            'ga.pages.visit' => 'N/A',

            'ga.bounce.rate' => 'N/A',

            'ga.new.visits' => 'N/A',

            'ga.avg.time' => 'N/A',

            'ga.visits.chart' => 'N/A', //enym new

            'ga.visits.maximum' => 'N/A', //enym new

        );

        if (!empty($result) && is_array($result)) {

            if (isset($result['stats_int'])) {

                $values = $result['stats_int'];

                $output['ga.visits'] = (isset($values['aggregates']) && isset($values['aggregates']['ga:sessions'])) ? $values['aggregates']['ga:sessions'] : 'N/A';

                $output['ga.pageviews'] = (isset($values['aggregates']) && isset($values['aggregates']['ga:pageviews'])) ? $values['aggregates']['ga:pageviews'] : 'N/A';

                $output['ga.pages.visit'] = (isset($values['aggregates']) && isset($values['aggregates']['ga:pageviewsPerSession'])) ? self::format_stats_values($values['aggregates']['ga:pageviewsPerSession'], true, false) : 'N/A';

                $output['ga.bounce.rate'] = (isset($values['aggregates']) && isset($values['aggregates']['ga:bounceRate'])) ? self::format_stats_values($values['aggregates']['ga:bounceRate'], true, true) : 'N/A';

                $output['ga.new.visits'] = (isset($values['aggregates']) && isset($values['aggregates']['ga:percentNewSessions'])) ? self::format_stats_values($values['aggregates']['ga:percentNewSessions'], true, true) : 'N/A';

                $output['ga.avg.time'] = (isset($values['aggregates']) && isset($values['aggregates']['ga:avgSessionDuration'])) ? self::format_stats_values($values['aggregates']['ga:avgSessionDuration'], false, false, true) : 'N/A';

            }



            //===============================================================

            //enym new   requires change in mainWPGA.class.php in Ga extension [send pure graph data in array]

            //help: http://charts.streitenberger.net/#

            //if (isset($result['stats_graph'])) {

            if ($chart && isset($result['stats_graphdata'])) {

                //INTERVALL chxr=1,1,COUNTALLVALUES

                $intervalls = '1,1,' . count($result['stats_graphdata']);



                //MAX DIMENSIONS chds=0,HIGHEST*2

                foreach ($result['stats_graphdata'] as $k => $v) {

                    if ($v['1'] > $maximum_value) {

                        $maximum_value = $v['1'];

                        $maximum_value_date = $v['0'];

                    }

                }



                $vertical_max = ceil($maximum_value * 1.3);

                $dimensions = '0,' . $vertical_max;



                //DATA chd=t:1,2,3,4,5,6,7,8,9,10,11,12,13,14|

                $graph_values = '';

                foreach ($result['stats_graphdata'] as $arr) {

                    $graph_values .= $arr['1'] . ',';

                }

                $graph_values = trim($graph_values, ',');



                //AXISLEGEND chd=t:1.1|2.1|3.1 ...

                $graph_dates = '';



                $step = 1;

                if (count($result['stats_graphdata']) > 20) {

                    $step = 2;

                }

                $nro = 1;

                foreach ($result['stats_graphdata'] as $arr) {

                    $nro = $nro + 1;

                    if (0 == ($nro % $step)) {



                        $teile = explode(' ', $arr['0']);

                        if ('Jan' == $teile[0]) {

                            $teile[0] = '1';

                        }

                        if ('Feb' == $teile[0]) {

                            $teile[0] = '2';

                        }

                        if ('Mar' == $teile[0]) {

                            $teile[0] = '3';

                        }

                        if ('Apr' == $teile[0]) {

                            $teile[0] = '4';

                        }

                        if ('May' == $teile[0]) {

                            $teile[0] = '5';

                        }

                        if ('Jun' == $teile[0]) {

                            $teile[0] = '6';

                        }

                        if ('Jul' == $teile[0]) {

                            $teile[0] = '7';

                        }

                        if ('Aug' == $teile[0]) {

                            $teile[0] = '8';

                        }

                        if ('Sep' == $teile[0]) {

                            $teile[0] = '9';

                        }

                        if ('Oct' == $teile[0]) {

                            $teile[0] = '10';

                        }

                        if ('Nov' == $teile[0]) {

                            $teile[0] = '11';

                        }

                        if ('Dec' == $teile[0]) {

                            $teile[0] = '12';

                        }

                        $graph_dates .= $teile[1] . '.' . $teile[0] . '.|';

                    }

                }

                //$graph_dates = urlencode($graph_dates);

                $graph_dates = trim($graph_dates, '|');



                //SCALE chxr=1,0,HIGHEST*2

                $scale = '1,0,' . $vertical_max;



                //WIREFRAME chg=0,10,1,4

                $wire = '0,10,1,4';



                //COLORS

                $barcolor = '508DDE'; //4d89f9";

                $fillcolor = 'EDF5FF'; //CCFFFF";

                //LINEFORMAT chls=1,0,0

                $lineformat = '1,0,0';



                //TITLE

                //&chtt=Last+2+Weeks+Sales

                //LEGEND

                //&chdl=Sales



                $output['ga.visits.chart'] = '<img src="http://chart.apis.google.com/chart?cht=lc&chs=600x250&chd=t:' . $graph_values . '&chds=' . $dimensions . '&chco=' . $barcolor . '&chm=B,' . $fillcolor . ',0,0,0&chls=' . $lineformat . '&chxt=x,y&chxl=0:|' . $graph_dates . '&chxr=' . $scale . '&chg=' . $wire . '">';



                $date1 = explode(' ', $maximum_value_date);

                if ('Jan' == $date1[0]) {

                    $date1[0] = '1';

                }

                if ('Feb' == $date1[0]) {

                    $date1[0] = '2';

                }

                if ('Mar' == $date1[0]) {

                    $date1[0] = '3';

                }

                if ('Apr' == $date1[0]) {

                    $date1[0] = '4';

                }

                if ('May' == $date1[0]) {

                    $date1[0] = '5';

                }

                if ('Jun' == $date1[0]) {

                    $date1[0] = '6';

                }

                if ('Jul' == $date1[0]) {

                    $date1[0] = '7';

                }

                if ('Aug' == $date1[0]) {

                    $date1[0] = '8';

                }

                if ('Sep' == $date1[0]) {

                    $date1[0] = '9';

                }

                if ('Oct' == $date1[0]) {

                    $date1[0] = '10';

                }

                if ('Nov' == $date1[0]) {

                    $date1[0] = '11';

                }

                if ('Dec' == $date1[0]) {

                    $date1[0] = '12';

                }

                $maximum_value_date = $date1[1] . '.' . $date1[0] . '.';

                $output['ga.visits.maximum'] = $maximum_value . ' (' . $maximum_value_date . ')';

            }



            $output['ga.startdate'] = date('d.m.Y', $start_date);

            $output['ga.enddate'] = date('d.m.Y', $end_date);

            //}

            //enym end

            //===============================================================

        }

        self::$buffer[$uniq] = $output;

        return $output;

    }



    static function piwik_data($site_id, $start_date, $end_date) {

        // fix bug cron job

        if (null === self::$enabled_piwik) {

            self::$enabled_piwik = apply_filters('mainwp-extension-available-check', 'mainwp-piwik-extension');

        }



        if (!self::$enabled_piwik) {

            return false;

        }

        if (!$site_id || !$start_date || !$end_date) {

            return false;

        }

        $uniq = 'pw_' . $site_id . '_' . $start_date . '_' . $end_date;

        if (isset(self::$buffer[$uniq])) {

            return self::$buffer[$uniq];

        }



        $values = apply_filters('mainwp_piwik_get_data', $site_id, $start_date, $end_date);

        //        error_log(print_r($values, true));

        //        print_r($values);

        $output = array();

        $output['piwik.visits'] = (is_array($values) && isset($values['aggregates']) && isset($values['aggregates']['nb_visits'])) ? $values['aggregates']['nb_visits'] : 'N/A';

        $output['piwik.pageviews'] = (is_array($values) && isset($values['aggregates']) && isset($values['aggregates']['nb_actions'])) ? $values['aggregates']['nb_actions'] : 'N/A';

        $output['piwik.pages.visit'] = (is_array($values) && isset($values['aggregates']) && isset($values['aggregates']['nb_actions_per_visit'])) ? $values['aggregates']['nb_actions_per_visit'] : 'N/A';

        $output['piwik.bounce.rate'] = (is_array($values) && isset($values['aggregates']) && isset($values['aggregates']['bounce_rate'])) ? $values['aggregates']['bounce_rate'] : 'N/A';

        $output['piwik.new.visits'] = (is_array($values) && isset($values['aggregates']) && isset($values['aggregates']['nb_uniq_visitors'])) ? $values['aggregates']['nb_uniq_visitors'] : 'N/A';

        $output['piwik.avg.time'] = (is_array($values) && isset($values['aggregates']) && isset($values['aggregates']['avg_time_on_site'])) ? self::format_stats_values($values['aggregates']['avg_time_on_site'], false, false, true) : 'N/A';

        self::$buffer[$uniq] = $output;



        return $output;

    }



    static function aum_data($site_id, $start_date, $end_date) {



        if (null === self::$enabled_aum) {

            self::$enabled_aum = apply_filters('mainwp-extension-available-check', 'advanced-uptime-monitor-extension');

        }



        if (!self::$enabled_aum) {

            return false;

        }



        if (!$site_id || !$start_date || !$end_date) {

            return false;

        }

        $uniq = 'aum_' . $site_id . '_' . $start_date . '_' . $end_date;

        if (isset(self::$buffer[$uniq])) {

            return self::$buffer[$uniq];

        }



        $values = apply_filters('mainwp_aum_get_data', $site_id, $start_date, $end_date);

        //print_r($values);

        $output = array();

        $output['aum.alltimeuptimeratio'] = (is_array($values) && isset($values['aum.alltimeuptimeratio'])) ? $values['aum.alltimeuptimeratio'] . '%' : 'N/A';

        $output['aum.uptime7'] = (is_array($values) && isset($values['aum.uptime7'])) ? $values['aum.uptime7'] . '%' : 'N/A';

        $output['aum.uptime15'] = (is_array($values) && isset($values['aum.uptime15'])) ? $values['aum.uptime15'] . '%' : 'N/A';

        $output['aum.uptime30'] = (is_array($values) && isset($values['aum.uptime30'])) ? $values['aum.uptime30'] . '%' : 'N/A';

        $output['aum.uptime45'] = (is_array($values) && isset($values['aum.uptime45'])) ? $values['aum.uptime45'] . '%' : 'N/A';

        $output['aum.uptime60'] = (is_array($values) && isset($values['aum.uptime60'])) ? $values['aum.uptime60'] . '%' : 'N/A';



        self::$buffer[$uniq] = $output;



        return $output;

    }



    static function woocomstatus_data($site_id, $start_date, $end_date) {



        // fix bug cron job

        if (null === self::$enabled_woocomstatus) {

            self::$enabled_woocomstatus = apply_filters('mainwp-extension-available-check', 'mainwp-woocommerce-status-extension');

        }



        if (!self::$enabled_woocomstatus) {

            return false;

        }



        if (!$site_id || !$start_date || !$end_date) {

            return false;

        }

        $uniq = 'wcstatus_' . $site_id . '_' . $start_date . '_' . $end_date;

        if (isset(self::$buffer[$uniq])) {

            return self::$buffer[$uniq];

        }



        $values = apply_filters('mainwp_woocomstatus_get_data', $site_id, $start_date, $end_date);

        $top_seller = 'N/A';

        if (is_array($values) && isset($values['wcomstatus.topseller'])) {

            $top = $values['wcomstatus.topseller'];

            if (is_object($top) && isset($top->name)) {

                $top_seller = $top->name;

            }

        }



        //print_r($values);

        $output = array();

        $output['wcomstatus.sales'] = (is_array($values) && isset($values['wcomstatus.sales'])) ? $values['wcomstatus.sales'] : 'N/A';

        $output['wcomstatus.topseller'] = $top_seller;

        $output['wcomstatus.awaitingprocessing'] = (is_array($values) && isset($values['wcomstatus.awaitingprocessing'])) ? $values['wcomstatus.awaitingprocessing'] : 'N/A';

        $output['wcomstatus.onhold'] = (is_array($values) && isset($values['wcomstatus.onhold'])) ? $values['wcomstatus.onhold'] : 'N/A';

        $output['wcomstatus.lowonstock'] = (is_array($values) && isset($values['wcomstatus.lowonstock'])) ? $values['wcomstatus.lowonstock'] : 'N/A';

        $output['wcomstatus.outofstock'] = (is_array($values) && isset($values['wcomstatus.outofstock'])) ? $values['wcomstatus.outofstock'] : 'N/A';

        self::$buffer[$uniq] = $output;

        return $output;

    }



    private static function format_stats_values($value, $round = false, $perc = false, $showAsTime = false) {

        if ($showAsTime) {

            $value = MainWP_Live_Reports_Utility::sec2hms($value);

        } else {

            if ($round) {

                $value = round($value, 2);

            }

            if ($perc) {

                $value = $value . '%';

            }

        }

        return $value;

    }



    public static function fetch_stream_data($website, $report, $sections, $tokens) {

        global $mainWPCReportExtensionActivator;

        $post_data = array(

            'mwp_action' => 'get_stream',

            'sections' => base64_encode(serialize($sections)),

            'other_tokens' => base64_encode(serialize($tokens)),

            'date_from' => $report->date_from,

            'date_to' => $report->date_to,

        );



        $information = apply_filters('mainwp_fetchurlauthed', $mainWPCReportExtensionActivator->get_child_file(), $mainWPCReportExtensionActivator->get_child_key(), $website['id'], 'client_report', $post_data);

        //        print_r($sections);

//        print_r($information);

//         return array('error' => json_encode($mainWPCReportExtensionActivator->get_child_key()));

        //error_log(print_r($information, true));

        if (is_array($information) && !isset($information['error'])) {

            return $information;

        } else {

            if (isset($information['error'])) {

                $error = $information['error'];

                if ('NO_STREAM' == $error) {

                    $error = __('Error: No Stream or MainWP Client Reports plugin installed.');

                }

            } else {

                $error = is_array($information) ? @implode('<br>', $information) : $information;

            }

            return array('error' => $error);

        }

    }



    public function site_token($website) {

        global $wpdb;

        $tokens = LiveReportResponder_DB::get_instance()->get_tokens();



        $site_tokens = array();

        if ($website) {

            $site_tokens = LiveReportResponder_DB::get_instance()->get_site_tokens($website->url);

        }



        $html = '<div class="postbox"> 

                            <h3 class="mainwp_box_title"><span>Managed Client Reports Settings</span></h3>

                            <div class="inside">';

        if (is_array($tokens) && count($tokens) > 0) {

            $html .= '<table class="form-table" style="width: 100%">';

            foreach ($tokens as $token) {

                if (!$token) {

                    continue;

                }

                $token_value = '';

                if (isset($site_tokens[$token->id]) && $site_tokens[$token->id]) {

                    $token_value = stripslashes($site_tokens[$token->id]->token_value);

                }



                $input_name = 'creport_token_' . str_replace(array('.', ' ', '-'), '_', $token->token_name);

                $html .= '<tr>                      

                            <th scope="row" class="token-name" >[' . esc_html(stripslashes($token->token_name)) . ']</th>

                            <td>                                        

                            <input type="text" value="' . esc_attr($token_value) . '" class="regular-text" name="' . esc_attr($input_name) . '"/>

                            </td>                           

                    </tr>';

            }

            $html .= '</table>';

        } else {

            $html .= 'Not found tokens.';

        }

        $html .= '                                   

                </div></div>';

        echo $html;

    }



    public function update_site_update_tokens($websiteId) {

        global $wpdb, $mainWPCReportExtensionActivator;

        if (isset($_POST['submit'])) {

            $website = apply_filters('mainwp-getsites', $mainWPCReportExtensionActivator->get_child_file(), $mainWPCReportExtensionActivator->get_child_key(), $websiteId);

            if ($website && is_array($website)) {

                $website = current($website);

            }



            if (!is_array($website)) {

                return;

            }



            $tokens = LiveReportResponder_DB::get_instance()->get_tokens();

            foreach ($tokens as $token) {

                $input_name = 'creport_token_' . str_replace(array('.', ' ', '-'), '_', $token->token_name);

                if (isset($_POST[$input_name])) {

                    $token_value = $_POST[$input_name];



                    // default token

                    //                    if ($token->type == 1 && empty($token_value))

                    //                        continue;



                    $current = LiveReportResponder_DB::get_instance()->get_tokens_by('id', $token->id, $website['url']);

                    if ($current) {

                        LiveReportResponder_DB::get_instance()->update_token_site($token->id, $token_value, $website['url']);

                    } else {

                        LiveReportResponder_DB::get_instance()->add_token_site($token->id, $token_value, $website['url']);

                    }

                }

            }

        }

    }



    public function delete_site_delete_tokens($website) {

        if ($website) {

            LiveReportResponder_DB::get_instance()->delete_site_tokens($website->url);

        }

    }



}



class LiveReportResponder_DB {



	private $mainwp_wpcreport_db_version = '4.2';

	private $table_prefix;

	//Singleton

	private static $instance = null;



	//Constructor

	function __construct() {

		global $wpdb;

		$this->table_prefix = $wpdb->prefix . 'mainwp_';

		$this->default_tokens = array(

		'client.site.name' => 'Displays the Site Name',

			'client.site.url' => 'Displays the Site Url',

			'client.name' => 'Displays the Client Name',

			'client.contact.name' => 'Displays the Client Contact Name',

			'client.contact.address.1' => 'Displays the Client Contact Address 1',

			'client.contact.address.2' => 'Displays the Client Contact Address 2',

			'client.company' => 'Displays the Client Company',

			'client.city' => 'Displays the Client City',

			'client.state' => 'Displays the Client State',

			'client.zip' => 'Displays the Client Zip',

			'client.phone' => 'Displays the Client Phone',

			'client.email' => 'Displays the Client Email',

		);

		$default_report_logo = plugins_url( 'images/default-report-logo.png', dirname( __FILE__ ) );

		$this->default_reports[] = array(

			'title' => 'Default Basic Report',

			'header' => '<img style="float:left" src="' . $default_report_logo . '" alt="default-report-logo" width="300" height="56" /><br/><br/>Hello [client.contact.name],',

			'body' => '<h3>Activity report for the [client.site.url]:</h3>

<h3>Plugins</h3>

<strong>Installed Plugins:</strong> [plugin.installed.count]

<strong>Activated Plugins:</strong> [plugin.activated.count] 

<strong>Edited Plugins:</strong> [plugin.edited.count]

<strong>Deactivated Plugins:</strong> [plugin.deactivated.count]

<strong>Updated Plugins:</strong> [plugin.updated.count] 

<strong>Deleted Plugins:</strong> [plugin.deleted.count]

<h3>Themes</h3>

<strong>Installed Themes:</strong> [theme.installed.count] 

<strong>Activated Themes:</strong> [theme.activated.count] 

<strong>Edited Themes:</strong> [theme.edited.count]

<strong>Updated Themes:</strong> [theme.updated.count] 

<strong>Deleted Themes:</strong> [theme.deleted.count] 

<h3>Posts</h3>

<strong>Created Posts: </strong> [post.created.count] 

<strong>Updated Posts: </strong> [post.updated.count] 

<strong>Trashed Posts: </strong> [post.trashed.count] 

<strong>Deleted Posts: </strong> [post.deleted.count] 

<strong>Restored Posts: </strong> [post.restored.count] 

<h3>Pages</h3>

<strong>Created Pages:</strong> [page.created.count] 

<strong>Updated Pages:</strong> [page.updated.count] 

<strong>Trashed Pages:</strong> [page.trashed.count] 

<strong>Deleted Pages:</strong> [page.deleted.count] 

<strong>Restored Pages: </strong> [page.restored.count]

<h3>Users</h3>

<strong>Created Users:</strong> [user.created.count]

<strong>Updated Users:</strong> [user.updated.count]

<strong>Deleted Users:</strong> [user.deleted.count]

<h3>Comments</h3>

<strong>Created Comments:</strong> [commet.created.count]

<strong>Trashed Comments:</strong> [comment.trashed.count]

<strong>Deleted Comments:</strong> [comment.deleted.count]

<strong>Edited Comments:</strong> [comment.edited.count]

<strong>Restored Comments:</strong> [comment.restored.count]

<strong>Approved Comments:</strong> [comment.approved.count]

<strong>Spammed Comments:</strong> [comment.spam.count]

<strong>Replied Comments:</strong> [comment.replied.count]

<h3>Media</h3>

<strong>Uploaded Media:</strong> [media.uploaded.count]

<strong>Updated Media:</strong> [media.updated.count]

<strong>Deleted Media:</strong> [media.deleted.count]

<h3>Widgets</h3>

<strong>Added Widgets:</strong> [widget.added.count]

<strong>Updated Widgets:</strong> [widget.updated.count]

<strong>Deleted Widgets:</strong> [widget.deleted.count]

<h3>Menus</h3>

<strong>Created Menus:</strong> [menu.created.count]

<strong>Updated Menus:</strong> [menu.updated.count]

<strong>Deleted Menus:</strong> [menu.deleted.count]

<h3>WordPress</h3>

<strong>WordPress Updates:</strong> [wordpress.updated.count]'

				);



		$this->default_reports[] = array(

			'title' => 'Default Full Report',

			'header' => '<img style="float:left" src="' . $default_report_logo . '" alt="default-report-logo" width="300" height="56" /><br/><br/><br/>Hello [client.contact.name],',

			'body' => '<h3>Activity report for the [client.site.url]:</h3>

<h3>Plugins</h3>

<strong>[plugin.installed.count] Plugins Installed</strong>

[section.plugins.installed]

([plugin.installed.date]) [plugin.name] by [plugin.installed.author];

[/section.plugins.installed]



<strong>[plugin.activated.count] Plugins Activated</strong>

[section.plugins.activated]

([plugin.activated.date]) [plugin.name] by [plugin.activated.author];

[/section.plugins.activated]



<strong>[plugin.edited.count] Plugins Edited</strong>

[section.plugins.edited]

([plugin.edited.date]) [plugin.name] by [plugin.edited.author];

[/section.plugins.edited]



<strong>[plugin.deactivated.count] Plugins Deactivated</strong>

[section.plugins.deactivated]

([plugin.deactivated.date]) [plugin.name] by [plugin.deactivated.author];

[/section.plugins.deactivated]



<strong>[plugin.updated.count] Plugins Updated</strong>

[section.plugins.updated]

([plugin.updated.date]) [plugin.name] by [plugin.updated.author] - [plugin.old.version] to [plugin.current.version];

[/section.plugins.updated]



<strong>[plugin.deleted.count] Plugins Deleted</strong>

[section.plugins.deleted]

([plugin.deleted.date]) [plugin.name] by [plugin.deleted.author];

[/section.plugins.deleted]

<h3>Themes</h3>

<strong>[theme.installed.count] Themes Installed</strong>

[section.themes.installed]

([theme.installed.date]) [theme.name] by [theme.installed.author];

[/section.themes.installed]



<strong>[theme.activated.count] Themes Activated</strong>

[section.themes.activated]

([theme.activated.date]) [theme.name] by [theme.activated.author];

[/section.themes.activated]



<strong>[theme.edited.count] Themes Edited</strong>

[section.themes.edited]

([theme.edited.date]) [theme.name] by [theme.edited.author];

[/section.themes.edited]



<strong>[theme.updated.count] Themes Updated</strong>

[section.themes.updated]

([theme.updated.date]) [theme.name] by [theme.updated.author] - [theme.old.version] to [theme.current.version] ;

[/section.themes.updated]



<strong>[theme.deleted.count] Themes Deleted</strong>

[section.themes.deleted]

([theme.deleted.date]) [theme.name] by [theme.deleted.author];

[/section.themes.deleted]

<h3>Posts</h3>

<strong>[post.created.count] Created Posts</strong>

[section.posts.created]

([post.created.date]) [post.title] by [post.created.author];

[/section.posts.created]



<strong>[post.updated.count] Updated Posts</strong>

[section.posts.updated]

([post.updated.date]) [post.title] by [post.updated.author];

[/section.posts.updated]



<strong>[post.trashed.count] Trashed Posts</strong>

[section.posts.trashed]

([post.trashed.date]) [post.title] by [post.trashed.author];

[/section.posts.trashed]



<strong>[post.deleted.count] Deleted Posts</strong>

[section.posts.deleted]

([post.deleted.date]) [post.title] by [post.deleted.author];

[/section.posts.deleted]



<strong>[post.restored.count] Restored Posts</strong>

[section.posts.restored]

([post.restored.date]) [post.title] by [post.restored.author];

[/section.posts.restored]

<h3>Pages</h3>

<strong>[page.created.count] Created Pages</strong>

[section.pages.created]

([page.created.date]) [page.title] by [page.created.author];

[/section.pages.created]



<strong>[page.updated.count] Updated Pages</strong>

[section.pages.updated]

([page.updated.date]) [page.title] by [post.page.author];

[/section.page.updated]



<strong>[page.trashed.count] Trashed Pages</strong>

[section.pages.trashed]

([page.trashed.date]) [page.title] by [page.trashed.author];

[/section.pages.trashed]



<strong>[page.deleted.count] Deleted Pages</strong>

[section.pages.deleted]

([page.deleted.date]) [page.title] by [page.deleted.author];

[/section.pages.deleted]



<strong>[page.restored.count] Restored Pages</strong>

[section.pages.restored]

([page.restored.date]) [page.title] by [page.restored.author];

[/section.pages.restored]

<h3>Users</h3>

<strong>[user.created.count] Created Users</strong>

[section.users.created]

([user.created.date]) [user.name] ([user.created.role]) by [user.created.author];

[/section.users.created]



<strong>[user.updated.count] Updated Users</strong>

[section.users.updated]

([user.updated.date]) [user.name] ([user.updated.role]) by [user.updated.author];

[/section.users.updated]



<strong>[user.deleted.count] Deleted Users</strong>

[section.users.deleted]

([user.deleted.date]) [user.name] by [user.deleted.author];

[/section.users.deleted]

<h3>Comments</h3>

<strong>[comment.created.count] Created Comments</strong>

[section.comments.created]

([comment.created.date]) [comment.title] by [comment.created.author];

[/section.comments.created]



<strong>[comment.trashed.count] Trashed Comments</strong>

[section.comments.trashed]

([comment.trashed.date]) [comment.title] by [comment.trashed.author];

[/section.comments.trashed]



<strong>[comment.deleted.count] Deleted Comments</strong>

[section.comments.deleted]

([comment.deleted.date]) [comment.title] by [comment.deleted.author];

[/section.comments.deleted]



<strong>[comment.edited.count] Edited Comments</strong>

[section.comments.edited]

([comment.edited.date]) [comment.title] by [comment.edited.author];

[/section.comments.edited]



<strong>[comment.restored.count] Restored Comments</strong>

[section.comments.restored]

([comment.restored.date]) [comment.title] by [comment.restored.author];

[/section.comments.restored]



<strong>[comment.approved.count] Approved Comments</strong>

[section.comments.approved]

([comment.approved.date]) [comment.title] by [comment.approved.author];

[/section.comments.approved]



<strong>[comment.spam.count] Spammed Comments</strong>

[section.comments.spam]

([comment.spam.date]) [comment.title] by [comment.spam.author];

[/section.comments.spam]



<strong>[comment.replied.count] Replied Comments</strong>

[section.comments.replied]

([comment.replied.date]) [comment.title] by [comment.replied.author];

[/section.comments.replied]

<h3>Media</h3>

<strong>[media.uploaded.count] Uploaded Media</strong>

[section.media.uploaded]

([media.uploaded.date]) [media.name] by [media.uploaded.author];

[/section.media.uploaded]



<strong>[media.updated.count] Updated Media</strong>

[section.media.updated]

([media.updated.date]) [media.name] by [media.updated.author];

[/section.media.updated]



<strong>[media.deleted.count] Deleted Media</strong>

[section.media.deleted]

([media.deleted.date]) [media.name] by [media.deleted.author];

[/section.media.deleted]

<h3>Widgets</h3>

<strong>[widget.added.count] Added Widgets</strong>

[section.widgets.added]

([widget.added.date]) [widget.title] added in [widget.added.area] by [widget.added.author];

[/section.widgets.added]



<strong>[widget.updated.count] Updated Widgets</strong>

[section.widgets.updated]

([widget.updated.date]) [widget.title] in [widget.updated.area] by [widget.updated.author];

[/section.widgets.updated]



<strong>[widget.deleted.count] Deleted Widgets</strong>

[section.widgets.deleted]

([widget.deleted.date]) [widget.title] in [widget.deleted.area] by [widget.deleted.author];

[/section.widgets.deleted]

<h3>Menus</h3>

<strong>[menu.created.count] Created Menus</strong>

[section.menus.created]

([menu.added.date]) [menu.title] by [menu.added.author];

[/section.menus.created]



<strong>[menu.updated.count] Updated Menus</strong>

[section.menus.updated]

([menu.updated.date]) [menu.title] by [menu.updated.author];

[/section.menus.updated]



<strong>[menu.deleted.count] Deleted Menus</strong>

[section.menus.deleted]

([menu.deleted.date]) [menu.title] by [menu.deleted.author];

[/section.menus.deleted]

<h3>WordPress</h3>

<strong>[wordpress.updated.count] Updates WordPress</strong>

[section.wordpress.updated]

([wordpress.updated.date]) Updated by [wordpress.updated.author] - [wordpress.old.version] to [wordpress.current.version]

[/section.wordpress.updated]'

				);

		$this->default_formats = array(

		array(

				'title' => 'Default Header',

				'type' => 'H',

				'content' => $this->default_reports[0]['header'],

			),

			array(

				'title' => ' Basic Report',

				'type' => 'B',

				'content' => $this->default_reports[0]['body'],

			),

			array(

				'title' => 'Full Report',

				'type' => 'B',

				'content' => $this->default_reports[1]['body'],

			),

		);

	}



	function table_name( $suffix ) {

		return $this->table_prefix . $suffix;

	}



	//Support old & new versions of wordpress (3.9+)

	public static function use_mysqli() {

		/** @var $wpdb wpdb */

		if ( ! function_exists( 'mysqli_connect' ) ) {

			return false; }



		global $wpdb;

		return ($wpdb->dbh instanceof mysqli);

	}

                        



	static function get_instance() {

		if ( null == LiveReportResponder_DB::$instance ) {

			LiveReportResponder_DB::$instance = new LiveReportResponder_DB();

		}

		return LiveReportResponder_DB::$instance;

	}





	public function add_token( $token ) {

		/** @var $wpdb wpdb */

		global $wpdb;

		if ( ! empty( $token['token_name'] ) && ! empty( $token['token_description'] ) ) {

			if ( $current = $this->get_tokens_by( 'token_name', $token['token_name'] ) ) {

				return false; }

			if ( $wpdb->insert( $this->table_name( 'client_report_token' ), $token ) ) {

				return $this->get_tokens_by( 'id', $wpdb->insert_id );

			}

		}

		return false;

	}



	public function update_token( $id, $token ) {

		/** @var $wpdb wpdb */

		global $wpdb;

		if ( MainWP_Live_Reports_Utility::ctype_digit( $id ) && ! empty( $token['token_name'] ) && ! empty( $token['token_description'] ) ) {

			if ( $wpdb->update( $this->table_name( 'client_report_token' ), $token, array( 'id' => intval( $id ) ) ) ) {

				return $this->get_tokens_by( 'id', $id ); }

		}

		return false;

	}



	public function get_tokens_by( $by = 'id', $value = null, $site_url = '' ) {

		global $wpdb;



		if ( empty( $by ) || empty( $value ) ) {

			return null; }



		if ( 'token_name' == $by ) {

			$value = str_replace( array( '[', ']' ), '', $value );

		}



		$sql = '';

		if ( 'id' == $by ) {

			$sql = $wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'client_report_token' ) . ' WHERE `id`=%d ', $value );

		} else if ( 'token_name' == $by ) {

			$sql = $wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'client_report_token' ) . " WHERE `token_name` = '%s' ", $value );

		}



		$token = null;

		if ( ! empty( $sql ) ) {

			$token = $wpdb->get_row( $sql ); }



		$site_url = trim( $site_url );



		if ( empty( $site_url ) ) {

			return $token; }



		if ( $token && ! empty( $site_url ) ) {

//			$sql = 'SELECT * FROM ' . $this->table_name( 'client_report_site_token' ) .

//					" WHERE site_url = '" . $this->escape( $site_url ) . "' AND token_id = " . $token->id;

                        $sql = $wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'client_report_site_token' ) .' WHERE site_url =%s  AND token_id = %d', $this->escape( $site_url ), $token->id);



			$site_token = $wpdb->get_row( $sql );

			if ( $site_token ) {

				$token->site_token = $site_token;

				return $token;

			} else { 				return null; }

		}

		return null;

	}



	public function get_tokens() {

		global $wpdb;

		return $wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'client_report_token' ) . ' WHERE 1 = 1 ORDER BY type DESC, token_name ASC' );

	}



	public function get_site_token_values( $id ) {

		global $wpdb;

		if ( empty( $id ) ) {

			return false; }

//		$qry = ' SELECT st.* FROM ' . $this->table_name( 'client_report_site_token' ) . ' st ' .

//				" WHERE st.token_id = '" . $id . "' ";

                return  $wpdb->get_results($wpdb->prepare('SELECT st.* FROM ' . $this->table_name( 'client_report_site_token' ) . ' st WHERE st.token_id = %d',$id));



//		return $wpdb->get_results( $qry );

	}



	public function get_site_tokens( $site_url, $index = 'id' ) {

		global $wpdb;

		$site_url = trim( $site_url );

		if ( empty( $site_url ) ) {

			return false; }

//		$qry = ' SELECT st.*, t.token_name FROM ' . $this->table_name( 'client_report_site_token' ) . ' st , ' . $this->table_name( 'client_report_token' ) . ' t ' .

//				" WHERE st.site_url = '" . $site_url . "' AND st.token_id = t.id ";

		//echo $qry;

                 $site_tokens =  $wpdb->get_results($wpdb->prepare(' SELECT st.*, t.token_name FROM ' . $this->table_name( 'client_report_site_token' ) . ' st , ' . $this->table_name( 'client_report_token' ) . ' t WHERE st.site_url = %s AND st.token_id = t.id',$site_url));



//		$site_tokens = $wpdb->get_results( $qry );

		$return = array();

		if ( is_array( $site_tokens ) ) {

			foreach ( $site_tokens as $token ) {

				if ( 'id' == $index ) {

					$return[ $token->token_id ] = $token;

				} else {

					$return[ $token->token_name ] = $token;

				}

			}

		}

		// get default token value if empty

		$tokens = $this->get_tokens();

		if ( is_array( $tokens ) ) {

			foreach ( $tokens as $token ) {

				// check default tokens if it is empty

				if ( is_object( $token ) ) {

					if ( 'id' == $index ) {

						if ( 1 == $token->type && ( ! isset( $return[ $token->id ] ) || empty( $return[ $token->id ] )) ) {

							if ( ! isset( $return[ $token->id ] ) ) {

								$return[ $token->id ] = new stdClass(); }

							$return[ $token->id ]->token_value = $this->_get_default_token_site( $token->token_name, $site_url );

						}

					} else {

						if ( $token->type == 1 && ( ! isset( $return[ $token->token_name ] ) || empty( $return[ $token->token_name ] )) ) {

							if ( ! isset( $return[ $token->token_name ] ) ) {

								$return[ $token->token_name ] = new stdClass(); }

							$return[ $token->token_name ]->token_value = $this->_get_default_token_site( $token->token_name, $site_url );

						}

					}

				}

			}

		}

		return $return;

	}



	public function _get_default_token_site( $token_name, $site_url ) {

		$website = apply_filters( 'mainwp_getwebsitesbyurl', $site_url );

		if ( empty( $this->default_tokens[ $token_name ] ) || ! $website ) {

			return false; }

		$website = current( $website );

		if ( is_object( $website ) ) {

			$url_site = $website->url;

			$name_site = $website->name;

		} else { 			return false; }



		switch ( $token_name ) {

			case 'client.site.url':

				$token_value = $url_site;

				break;

			case 'client.site.name':

				$token_value = $name_site;

				break;

			default:

				$token_value = '';

				break;

		}

		return $token_value;

	}



	public function add_token_site( $token_id, $token_value, $site_url ) {

		/** @var $wpdb wpdb */

		global $wpdb;



		if ( empty( $token_id ) ) {

			return false; }



		$website = apply_filters( 'mainwp_getwebsitesbyurl', $site_url );

		if ( empty( $website ) ) {

			return false; }



		if ( $wpdb->insert($this->table_name( 'client_report_site_token' ), array(

			'token_id' => $token_id,

					'token_value' => $token_value,

					'site_url' => $site_url,

		)) ) {

			return $this->get_tokens_by( 'id', $token_id, $site_url );

		}



		return false;

	}



	public function update_token_site( $token_id, $token_value, $site_url ) {

		/** @var $wpdb wpdb */

		global $wpdb;



		if ( empty( $token_id ) ) {

			return false; }



		$website = apply_filters( 'mainwp_getwebsitesbyurl', $site_url );

		if ( empty( $website ) ) {

			return false; }



//		$sql = 'UPDATE ' . $this->table_name( 'client_report_site_token' ) .

//				" SET token_value = '" . $this->escape( $token_value ) . "' " .

//				' WHERE token_id = ' . intval( $token_id ) .

//				" AND site_url = '" . $this->escape( $site_url ) . "'";

		//echo $sql."<br />";

                $sql = $wpdb->query( $wpdb->prepare( "

        UPDATE ".$this->table_name( 'client_report_site_token' )."

        SET `token_value` = %s

        WHERE `token_id` = %d AND site_url = %s",

        $this->escape( $token_value ),intval( $token_id ),$this->escape( $site_url )

    ) );

                

		if ( $wpdb->query( $sql ) ) {

			return $this->get_tokens_by( 'id', $token_id, $site_url );

		}



		return false;

	}



	public function delete_site_tokens( $token_id = null, $site_url = null ) {

		global $wpdb;

		if ( ! empty( $token_id ) ) {

			return $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'client_report_site_token' ) . ' WHERE token_id = %d ', $token_id ) ); } else if ( ! empty( $site_url ) ) {

			return $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'client_report_site_token' ) . ' WHERE site_url = %s ', $site_url ) ); }

			return false;

	}



	public function delete_token_by( $by = 'id', $value = null ) {

		global $wpdb;

		if ( 'id' == $by ) {

			if ( $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'client_report_token' ) . ' WHERE id=%d ', $value ) ) ) {

				$this->delete_site_tokens( $value );

				return true;

			}

		}

		return false;

	}



	public function update_report( $report ) {

		/** @var $wpdb wpdb */

		global $wpdb;

		$id = isset( $report['id'] ) ? $report['id'] : 0;

		$updatedClient = false;

		if ( ! empty( $report['client'] ) || ! empty( $report['email'] ) ) { // client may be content tokens

			$client_id = 0;

			if ( ! empty( $report['client'] ) ) {

				$update_client = array(

					'client' => isset( $report['client'] ) ? $report['client'] : '',

					'name' => isset( $report['name'] ) ? $report['name'] : '',

					'company' => isset( $report['company'] ) ? $report['company'] : '',

					'email' => isset( $report['email'] ) ? $report['email'] : '',

				);



				if ( isset( $report['client_id'] ) && ! empty( $report['client_id'] ) ) {

					$update_client['clientid'] = $report['client_id']; 					

				} else {

					$client = null;

					$client = $this->get_client_by( 'client', $report['client'] );

					if ( empty( $client ) && ! empty( $report['email'] ) ) {

						$client = $this->get_client_by( 'email', $report['client'] );

					}



					if ( ! empty( $client ) ) {

						$client_id = $client->clientid;

						$update_client['clientid'] = $client_id;

					}

				}



				if ( $updatedClient = $this->update_client( $update_client ) ) {

					$client_id = $updatedClient->clientid;

				}

			} else if ( ! empty( $report['email'] ) ) {

				$client = $this->get_client_by( 'email', $report['client'] );

				if ( ! empty( $client ) ) {

					$client_id = $client->clientid;

				} else {

					// create client if not found client with the email

					$update_client = array(

						'client' => '',

						'name' => isset( $report['name'] ) ? $report['name'] : '',

						'company' => isset( $report['company'] ) ? $report['company'] : '',

						'email' => isset( $report['email'] ) ? $report['email'] : '',

					);

					if ( $updatedClient = $this->update_client( $update_client ) ) {

						$client_id = $updatedClient->clientid;

					}

				}

			}

			//            if (!isset($report['client_id']) || empty($report['client_id'])) {

			//                if ($updatedClient && $updatedClient->clientid) {

			//                    $report['client_id'] = $updatedClient->clientid;

			//                } else if (isset($update_client['clientid'])) {

			//

			//                }

			//            }

			

			// to fix bug not save report client

			if (empty($client_id) && !empty($report['client_id'])) {

				$client_id = $report['client_id'];

			}

			

			$report['client_id'] = $client_id;

		} else {

			if ( isset( $report['client_id'] ) ) {

				$report['client_id'] = 0; }

		}



		$report_fields = array(

		'id',

			'title',

			'date_from',

			'date_to',

			'fname',

			'fcompany',

			'femail',

			'client_id',

			'header',

			'body',

			'footer',

			'logo_file',

			'lastsend',

			'nextsend',

			'subject',

			'selected_site',

			'recurring_schedule',

			'recurring_date',

			'schedule_send_email',

			'schedule_bcc_me',

			'is_archived',

			'archive_report',

			'archive_report_pdf',

			'attach_files',

			'scheduled',

			'schedule_lastsend',

			'schedule_nextsend',

			'type',

			'sites',

			'groups',

		);



		$update_report = array();

		foreach ( $report as $key => $value ) {

			if ( in_array( $key, $report_fields ) ) {

				$update_report[ $key ] = $value; }

		}

		//print_r($update_report);

		if ( ! empty( $id ) ) {

			$updatedReport = $wpdb->update( $this->table_name( 'client_report' ), $update_report, array( 'id' => intval( $id ) ) );

			//print_r($update_report);

			if ( ! empty( $updatedReport ) || ! empty( $updatedClient ) ) {

				return $this->get_report_by( 'id', $id );

			}

		} else {

			if ( $wpdb->insert( $this->table_name( 'client_report' ), $update_report ) ) {

				return $this->get_report_by( 'id', $wpdb->insert_id );

			}

		}

		return false;

	}



	public function get_report_by( $by = 'id', $value = null, $orderby = null, $order = null, $output = OBJECT ) {

		global $wpdb;



		if ( empty( $by ) || ('all' !== $by && empty( $value )) ) {

			return false; }



		$_order_by = '';

		if ( ! empty( $orderby ) ) {

			if ( 'client' === $orderby || 'name' === $orderby ) {

				$orderby = 'c.' . $orderby;

			} else {

				$orderby = 'rp.' . $orderby;

			}

			$_order_by = ' ORDER BY ' . $orderby;

			if ( ! empty( $order ) ) {

				$_order_by .= ' ' . $order; }

		}



		$sql = '';

		if ( 'id' == $by ) {

			$sql = $wpdb->prepare('SELECT rp.*, c.* FROM ' . $this->table_name( 'client_report' ) . ' rp '

				. ' LEFT JOIN ' . $this->table_name( 'client_report_client' ) . ' c '

				. ' ON rp.client_id = c.clientid '

			. ' WHERE `id`=%d ' . $_order_by, $value);

		} if ( 'client' == $by ) {

			$sql = $wpdb->prepare('SELECT rp.*, c.* FROM ' . $this->table_name( 'client_report' ) . ' rp '

				. ' LEFT JOIN ' . $this->table_name( 'client_report_client' ) . ' c '

				. ' ON rp.client_id = c.clientid '

			. ' WHERE `client_id` = %d ' . $_order_by, $value);

			return $wpdb->get_results( $sql, $output );

		} if ( 'site' == $by ) {

			$sql = $wpdb->prepare('SELECT rp.*, c.* FROM ' . $this->table_name( 'client_report' ) . ' rp '

				. ' LEFT JOIN ' . $this->table_name( 'client_report_client' ) . ' c '

				. ' ON rp.client_id = c.clientid '

			. ' WHERE `selected_site` = %d ' . $_order_by, $value);

			return $wpdb->get_results( $sql, $output );

		} if ( 'title' == $by ) {

			$sql = $wpdb->prepare('SELECT rp.*, c.* FROM ' . $this->table_name( 'client_report' ) . ' rp '

				. ' LEFT JOIN ' . $this->table_name( 'client_report_client' ) . ' c '

				. ' ON rp.client_id = c.clientid '

			. ' WHERE `title` = %s ' . $_order_by, $value);

			return $wpdb->get_results( $sql, $output );

		} else if ( 'all' == $by ) {

			$sql = 'SELECT * FROM ' . $this->table_name( 'client_report' ) . ' rp '

					. 'LEFT JOIN ' . $this->table_name( 'client_report_client' ) . ' c '

					. ' ON rp.client_id = c.clientid '

					. ' WHERE 1 = 1 ' . $_order_by;

			return $wpdb->get_results( $sql, $output );

		}

		//echo $sql;

		if ( ! empty( $sql ) ) {

			return $wpdb->get_row( $sql, $output ); }



		return false;

	}



	public function get_avail_archive_reports() {

		global $wpdb;

		$sql = 'SELECT rp.*, c.* FROM ' . $this->table_name( 'client_report' ) . ' rp '

				. ' LEFT JOIN ' . $this->table_name( 'client_report_client' ) . ' c '

				. ' ON rp.client_id = c.clientid '

				. ' WHERE rp.is_archived = 0 AND rp.scheduled = 0'

				. ' AND rp.date_from <= ' . (time() - 3600 * 24 * 30) . '  '

				. ' AND rp.selected_site != 0 AND c.email IS NOT NULL '

				. '';

		//echo $sql;

		return $wpdb->get_results( $sql );

	}



	public function get_schedule_reports() {

		global $wpdb;

		$sql = 'SELECT rp.*, c.* FROM ' . $this->table_name( 'client_report' ) . ' rp '

				. ' LEFT JOIN ' . $this->table_name( 'client_report_client' ) . ' c '

				. ' ON rp.client_id = c.clientid '

				. " WHERE rp.recurring_schedule != '' AND rp.scheduled = 1";

		//echo $sql;

		return $wpdb->get_results( $sql );

	}



	public function delete_report_by( $by = 'id', $value = null ) {

		global $wpdb;

		if ( 'id' == $by ) {

			if ( $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'client_report' ) . ' WHERE id=%d ', $value ) ) ) {

				return true;

			}

		}

		return false;

	}



	public function get_clients() {

		global $wpdb;

		return $wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'client_report_client' ) . ' WHERE 1 = 1 ORDER BY client ASC' );

	}



	public function get_client_by( $by = 'clientid', $value = null ) {

		global $wpdb;



		if ( empty( $value ) ) {

			return false; }



		$sql = '';

		if ( 'clientid' == $by ) {

			$sql = $wpdb->prepare('SELECT * FROM ' . $this->table_name( 'client_report_client' )

			. ' WHERE `clientid` =%d ', $value);

		} else if ( 'client' == $by ) {

			$sql = $wpdb->prepare('SELECT * FROM ' . $this->table_name( 'client_report_client' )

			. ' WHERE `client` = %s ', $value);

		} else if ( 'email' == $by ) {

			$sql = $wpdb->prepare('SELECT * FROM ' . $this->table_name( 'client_report_client' )

			. ' WHERE `email` = %s ', $value);

		}



		if ( ! empty( $sql ) ) {

			return $wpdb->get_row( $sql ); }



		return false;

	}



	public function update_client( $client ) {

		/** @var $wpdb wpdb */

		global $wpdb;

		$id = isset( $client['clientid'] ) ? $client['clientid'] : 0;



		if ( ! empty( $id ) ) {

			if ( $wpdb->update( $this->table_name( 'client_report_client' ), $client, array( 'clientid' => intval( $id ) ) ) ) {

				return $this->get_client_by( 'clientid', $id ); }

		} else {

			if ( $wpdb->insert( $this->table_name( 'client_report_client' ), $client ) ) {

				//echo $wpdb->last_error;

				return $this->get_client_by( 'clientid', $wpdb->insert_id );

			}

			//echo $wpdb->last_error;

		}

		return false;

	}



//	public function delete_clientnt( $by, $value ) {

//		global $wpdb;

//		if ( 'clientid' == $by ) {

//			if ( $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'client_report_client' ) . ' WHERE clientid=%d ', $value ) ) ) {

//				return true;

//			}

//		}

//		return false;

//	}



	public function get_formats( $type = null ) {

		global $wpdb;

                return  $wpdb->prepare('SELECT * FROM ' . $this->table_name( 'client_report_format' ) 

			. ' WHERE `type` =%s ORDER BY title', $type);

//		return $wpdb->get_results( 'SELECT * FROM ' . $this->table_name( 'client_report_format' ) . " WHERE type = '" . $type . "' ORDER BY title" );

	}



	public function get_format_by( $by, $value, $type = null ) {

		global $wpdb;

		if ( empty( $value ) ) {

			return false; }

		$sql = '';

		if ( 'id' == $by ) {

			$sql = $wpdb->prepare('SELECT * FROM ' . $this->table_name( 'client_report_format' )

			. ' WHERE `id` =%d ', $value);

		} else if ( 'title' == $by ) {

			$sql = $wpdb->prepare('SELECT * FROM ' . $this->table_name( 'client_report_format' )

			. ' WHERE `title` =%s AND type = %s', $value, $type);

		}

		//echo $sql;

		if ( ! empty( $sql ) ) {

			return $wpdb->get_row( $sql ); }

		return false;

	}



	public function update_format( $format ) {

		/** @var $wpdb wpdb */

		global $wpdb;

		$id = isset( $format['id'] ) ? $format['id'] : 0;



		if ( ! empty( $id ) ) {

			if ( $wpdb->update( $this->table_name( 'client_report_format' ), $format, array( 'id' => intval( $id ) ) ) ) {

				return $this->get_format_by( 'id', $id ); }

		} else {

			if ( $wpdb->insert( $this->table_name( 'client_report_format' ), $format ) ) {

				//echo $wpdb->last_error;

				return $this->get_format_by( 'id', $wpdb->insert_id );

			}

			//echo $wpdb->last_error;

		}

		return false;

	}



	public function delete_format_by( $by = 'id', $value = null ) {

		global $wpdb;

		if ( 'id' == $by ) {

			if ( $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'client_report_format' ) . ' WHERE id=%d ', $value ) ) ) {

				return true;

			}

		}

		return false;

	}



	protected function escape( $data ) {

		/** @var $wpdb wpdb */

		global $wpdb;

		if ( function_exists( 'esc_sql' ) ) {

			return esc_sql( $data ); } else { 			return $wpdb->escape( $data ); }

	}



	public function query( $sql ) {

		if ( null == $sql ) {

			return false; }

		/** @var $wpdb wpdb */

		global $wpdb;

		$result = @self::_query( $sql, $wpdb->dbh );



		if ( ! $result || (@self::num_rows( $result ) == 0) ) {

			return false; }

		return $result;

	}



	public static function _query( $query, $link ) {

		if ( self::use_mysqli() ) {

			return mysqli_query( $link, $query );

		} else {

			return mysql_query( $query, $link );

		}

	}



	public static function fetch_object( $result ) {

		if ( self::use_mysqli() ) {

			return mysqli_fetch_object( $result );

		} else {

			return mysql_fetch_object( $result );

		}

	}



	public static function free_result( $result ) {

		if ( self::use_mysqli() ) {

			return mysqli_free_result( $result );

		} else {

			return mysql_free_result( $result );

		}

	}



	public static function data_seek( $result, $offset ) {

		if ( self::use_mysqli() ) {

			return mysqli_data_seek( $result, $offset );

		} else {

			return mysql_data_seek( $result, $offset );

		}

	}



	public static function fetch_array( $result, $result_type = null ) {

		if ( self::use_mysqli() ) {

			return mysqli_fetch_array( $result, (null == $result_type ? MYSQLI_BOTH : $result_type) );

		} else {

			return mysql_fetch_array( $result, (null == $result_type ? MYSQL_BOTH : $result_type) );

		}

	}



	public static function num_rows( $result ) {

		if ( self::use_mysqli() ) {

			return mysqli_num_rows( $result );

		} else {

			return mysql_num_rows( $result );

		}

	}



	public static function is_result( $result ) {

		if ( self::use_mysqli() ) {

			return ($result instanceof mysqli_result);

		} else {

			return is_resource( $result );

		}

	}



	public function get_results_result( $sql ) {

		if ( null == $sql ) {

			return null; }

		/** @var $wpdb wpdb */

		global $wpdb;

		return $wpdb->get_results( $sql, OBJECT_K );

	}

}



class MainWP_Live_Reports_Utility {



	public static function get_timestamp( $timestamp ) {

			$gmtOffset = get_option( 'gmt_offset' );



			return ($gmtOffset ? ($gmtOffset * HOUR_IN_SECONDS) + $timestamp : $timestamp);

	}

	

	public static function format_timestamp( $timestamp ) {

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );

	}



	static function ctype_digit( $str ) {

		return (is_string( $str ) || is_int( $str ) || is_float( $str )) && preg_match( '/^\d+\z/', $str );

	}



	public static function map_site( &$website, $keys ) {

		$outputSite = array();

		foreach ( $keys as $key ) {

			$outputSite[ $key ] = $website->$key;

		}

		return $outputSite;

	}



	static function sec2hms( $sec, $padHours = false ) {



		// start with a blank string

		$hms = '';



		// do the hours first: there are 3600 seconds in an hour, so if we divide

		// the total number of seconds by 3600 and throw away the remainder, we're

		// left with the number of hours in those seconds

		$hours = intval( intval( $sec ) / 3600 );



		// add hours to $hms (with a leading 0 if asked for)

		$hms .= ($padHours) ? str_pad( $hours, 2, '0', STR_PAD_LEFT ) . ':' : $hours . ':';



		// dividing the total seconds by 60 will give us the number of minutes

		// in total, but we're interested in *minutes past the hour* and to get

		// this, we have to divide by 60 again and then use the remainder

		$minutes = intval( ($sec / 60) % 60 );



		// add minutes to $hms (with a leading 0 if needed)

		$hms .= str_pad( $minutes, 2, '0', STR_PAD_LEFT ) . ':';



		// seconds past the minute are found by dividing the total number of seconds

		// by 60 and using the remainder

		$seconds = intval( $sec % 60 );



		// add seconds to $hms (with a leading 0 if needed)

		$hms .= str_pad( $seconds, 2, '0', STR_PAD_LEFT );



		// done!

		return $hms;

	}



	static function update_option( $option_name, $option_value ) {

		$success = add_option( $option_name, $option_value, '', 'no' );



		if ( ! $success ) {

			$success = update_option( $option_name, $option_value );

		}



		return $success;

	}

}



