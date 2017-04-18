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
        }
               
        //Freemius integration only turns on once user opts in
        static function init_tracking() {
            if( !get_option('mainwp_enabled_tracking_dashboard', false) )                        
                    return;   
            return self::init_freemius();            
        }  
        
        public static function set_mainwp_tracking($allow = true) {
            if ($allow)
                return self::allow_fs_tracking();
            else
                return self::disable_fs_tracking();
        }
        
        static function allow_fs_tracking() {
            $fs = self::init_freemius();
            $result = $fs->allow_tracking();
            if ( true !== $result ) {
                MainWP_Utility::update_option( 'mainwp_enabled_tracking_dashboard', 0 );
            }
            return $result;
        }
        
        static function disable_fs_tracking() {            
            $fs = self::init_freemius();
            $result = $fs->stop_tracking();      
            return $result;
        }        
        
        //Freemius integration only turns on once user opts in
        private static function init_freemius() {
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
                                'slug'           => 'Settings',
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