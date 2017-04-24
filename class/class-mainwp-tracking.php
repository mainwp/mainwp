<?php

class MainWP_Tracking {
        private static $instance = null;        
        
        static function Instance() {
                if ( self::$instance == null ) {
                        self::$instance = new MainWP_Tracking();
                }
                return self::$instance;
        }

        public function __construct() {            
            
        }
        
        public static function init( $activate_for_all = false ) {                                       
                self::init_tracking();                     
                self::check_synced_settings();                
                add_action('wp_ajax_mainwp_settings_saving_tracking', array( __CLASS__ , 'ajax_saving_tracking'));
        }
        
        static function init_tracking() {             
            return self::get_freemius();       
        }  
        
        
        public static function check_synced_settings() {            
            if ( !defined( 'DOING_AJAX' ) ) {
                if (self::is_tracking_registered()) {
                    $fs = self::get_freemius();
                    // to sync setting value
                    $is_tracking = $fs->is_tracking_prohibited() ? 0 : 1;
                    if (get_option('mainwp_enabled_tracking_dashboard') != $is_tracking) {
                        update_option('mainwp_enabled_tracking_dashboard', $is_tracking);  
                    }                
                }
            }
        }
           
        public static function message( $message = 0 ) { 
            switch ( $message ) {
                case 0:
                    return  __('Need to Opt in and activate Freemius from email to enable tracking!', 'mainwp');
                    break;
                default : 
                    return '';
                    break;
            }
            return '';
        }        
                
        public static function is_tracking_registered() {                        
            $fs = self::get_freemius();
            return $fs->is_registered();            
        }
        
        public static function is_pending_activation() {                        
            $fs = self::get_freemius();
            return $fs->is_pending_activation();            
        }        
        
        public static function is_connecting_tracking() {                        
            $fs = self::get_freemius();
            if ( $fs->is_pending_activation() || $fs->is_plugin_update() ) {
                return false;
            }
            return true;
        }
        
        public static function ajax_saving_tracking() {
            if ( !isset($_POST['action']) || !isset($_POST['nonce']) || !wp_verify_nonce( $_REQUEST['nonce'], 'mainwp_ajax' ) ) {
                die('Invalid request.');
            }            
            
            if (!self::is_tracking_registered()) {
                update_option('mainwp_enabled_tracking_dashboard', 0);  
                die(json_encode(array('error' => self::message(0))));
            }     
            
            $enabled = !empty($_POST['tracking']) ? 1 : 0;
            // update option value first, before set tracking
            update_option('mainwp_enabled_tracking_dashboard', $enabled);  
            self::set_tracking($enabled);
            die(json_encode( array( 'result' => 'ok') ) );
        }
        
        public static function set_tracking( $enabled = false ) {
            if ($enabled)
                self::allow_fs_tracking();
            else
                self::disable_fs_tracking();
            return true;
        }
        
        static function allow_fs_tracking() {
            $fs = self::get_freemius();
            $result = $fs->allow_tracking();
            if ( true !== $result ) {
                MainWP_Utility::update_option( 'mainwp_enabled_tracking_dashboard', 0 );
            }
            return $result;
        }
        
        static function disable_fs_tracking() {            
            $fs = self::get_freemius();
            $result = $fs->stop_tracking();      
            return $result;
        }        
        
        //Freemius integration only turns on once user opts in
        private static function get_freemius() {
            global $mainwp_fmius;
            if ( ! isset( $mainwp_fmius ) ) {
                    // Include Freemius SDK.
                    require_once MAINWP_PLUGIN_DIR . '/include/freemius/start.php';
                    $mainwp_fmius =  fs_dynamic_init( array(
                            'id'                  => '455',
                            'slug'                => 'mainwp',
                            'type'                => 'plugin',
                            'public_key'          => 'pk_84fca553bc1ba3c8471d49505ccdd',
                            'is_premium'          => false,
                            'has_addons'          => false,
                            'has_paid_plans'      => false,
                            'menu'                => array(
                                'slug'           => 'mainwp_tab',
                                'first-path'     => 'admin.php?page=mainwp_tab',
                                'account'        => false,
                                'contact'        => false,
                                'support'        => false,
                            ),                            
                    ) );
            }                

            return $mainwp_fmius;
        }
}