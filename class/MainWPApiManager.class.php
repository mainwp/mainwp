<?php

class MainWPApiManager {
    
    private $upgrade_url = 'https://extensions.mainwp.com/';
    private $renew_license_url = 'https://extensions.mainwp.com/my-account';
    const MAINWP_EXTENSIONS_SHOP_IP = '69.167.133.91'; // replace for upgrade_url
    public $domain = "";
    /**
     * @var The single instance of the class
     */
    protected static $_instance = null;

    public static function instance() {

        if ( is_null( self::$_instance ) ) {
        	self::$_instance = new self();
        }

        return self::$_instance;
    }

	/**
	 * Cloning is forbidden.
	 *
	 * @since 1.2
	 */
	private function __clone() {}

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since 1.2
	 */
	private function __wakeup() {}

	public function __construct() {
            if ( is_admin() ) {
                    // Check for external connection blocking
                    add_action( 'admin_notices', array( $this, 'check_external_blocking' ) );
            }
             $this->domain = str_ireplace( array( 'http://', 'https://' ), '', home_url());
	}
        
        public function getUpgradeUrl() {
            $url = apply_filters('mainwp_api_manager_upgrade_url', $this->upgrade_url);
            return $url;
        }
        
	public function license_key_activation( $api, $api_key, $api_email ) {
            
            $options = get_option($api . "_APIManAdder"); 

            if (!is_array($options)) $options = array();
            $current_api_key = isset($options['api_key']) ? $options['api_key'] : "";
            $current_activation_email = isset($options['activation_email']) ? $options['activation_email'] : "";
            $activation_status = isset($options['activated_key']) ? $options['activated_key'] : "";


            if ( $activation_status == 'Deactivated' || $activation_status == '' || $api_key == '' || $api_email == '' || $current_api_key != $api_key  ) {
                if ( $current_api_key != $api_key ) {
                    $reset = $this->replace_license_key(array(
                                                    'email' => $current_activation_email,
                                                    'licence_key' => $current_api_key,
                                                    'product_id' => $options['product_id'],
                                                    'instance' => $options['instance_id'],
                                                    'platform'  => $this->domain
                                                ));
                     if (!$reset) {
                        return array('error' => __('The license could not be deactivated.' , 'mainwp'));                 
                    }
               }

                $return = array();

                $args = array(
                        'email' => $api_email,
                        'licence_key' => $api_key,                
                        'product_id' => $options['product_id'],
                        'instance' => $options['instance_id'],                
                        'software_version' => $options['software_version'],
                        'platform' 			=> $this->domain
                );        

                $activate_results = json_decode( MainWPApiManagerKey::instance()->activate( $args ), true );

                if ( $activate_results['activated'] == true ) {
                        $return['result'] = 'SUCCESS';
                        $mess = isset($activate_results['message']) ? $activate_results['message'] : "";
                        $return['message'] = __( 'Plugin activated. ', 'mainwp' ) . $mess;
                        $options['api_key'] = $api_key;
                        $options['activation_email'] = $api_email;
                        $options['activated_key'] = 'Activated';
                        $options['deactivate_checkbox'] = 'off';                                        
                }
                
                if ( $activate_results == false ) {
                    $apisslverify = get_option('mainwp_api_sslVerifyCertificate');
                    if ($apisslverify == 1) {
                        MainWPUtility::update_option("mainwp_api_sslVerifyCertificate", 0);
                        $return['retry_action'] = 1;
                    } else {
                        $return['error'] =  __( 'Connection failed to the License Key API server. Try again later.', "mainwp" );
                    }
                    $options['api_key'] = '';
                    $options['activation_email'] = '';					
                    $options['activated_key'] = 'Deactivated';
                }

                if ( isset( $activate_results['code'] ) ) {                                    
                        switch ( $activate_results['code'] ) {
                                case '100':
                                case '101':
                                case '102':
                                case '103':
                                case '104':
                                case '105':
                                case '106':
                                    $options['api_key'] = '';
                                    $options['activation_email'] = '';
                                    $options['activated_key'] = 'Deactivated';
                                    $error = isset($activate_results['error']) ? $activate_results['error'] : ""; 
                                    $info = isset($activate_results['additional info']) ? ' ' . $activate_results['additional info'] : "";
                                    $return['error'] = $error . $info;													break;
                                break;
                        }
                }
                
                MainWPUtility::update_option($api . "_APIManAdder", $options);
                return $return;
            } else {            
                return array('result' => 'SUCCESS');                 
            }  
	}
        
        // Deactivate the current license key before activating the new license key
	private function replace_license_key( $args ) {
            $reset = MainWPApiManagerKey::instance()->deactivate( $args ); // reset license key activation

            if ( $reset == true )
                    return true;                
	}
    
	public function license_key_deactivation( $api ) {
            
                $options = get_option($api . "_APIManAdder");
                if (!is_array($options)) $options = array();

                $activation_status = isset($options['activated_key']) ? $options['activated_key'] : "";
                $current_api_key = isset($options['api_key']) ? $options['api_key'] : "";
                $current_activation_email = isset($options['activation_email']) ? $options['activation_email'] : "";
                
                $return = array();
                
                if ($activation_status == 'Activated' && $current_api_key != '' && $current_activation_email != '' ) {
                    $activate_results = MainWPApiManagerKey::instance()->deactivate( array(
                                                                           'email' => $current_activation_email,
                                                                           'licence_key' => $current_api_key,
                                                                            'product_id' => $options['product_id'],
                                                                            'instance' => $options['instance_id'],                                                                    
                                                                            'platform' 	=> $this->domain    
                                                                       )); // reset license key activation

                    $activate_results = json_decode($activate_results, true);                                                   
                    if ( $activate_results['deactivated'] == true ) {
                        $options['api_key'] = '';
                        $options['activation_email'] = '';					
                        $options['activated_key'] = 'Deactivated';
                        $options['deactivate_checkbox'] = 'on';                                                        
                        $return['result'] = 'SUCCESS';  
                        $return['activations_remaining'] = $activate_results['activations_remaining'];
                    }

                    if ( isset( $activate_results['code'] ) ) {
                            switch ( $activate_results['code'] ) {
                                    case '100':                                            
                                    case '101':
                                    case '102':
                                    case '103':
                                    case '104':
                                    case '105':
                                    case '106':
                                        $options['api_key'] = '';
                                        $options['activation_email'] = '';
                                        $options['activated_key'] = 'Deactivated';
                                        $error = isset($activate_results['error']) ? $activate_results['error'] : ""; 
                                        $info = isset($activate_results['additional info']) ? ' ' . $activate_results['additional info'] : "";
                                        $return['error'] = $error . $info;	
                                    break;
                            }

                    }

                    MainWPUtility::update_option($api . "_APIManAdder", $options);
                    return $return;       
                }
                return array('result' => 'SUCCESS');
	}
        
        public function test_login_api( $username, $password) {
            if (empty($username) || empty($password)) 
                return false;
            return MainWPApiManagerKey::instance()->testloginapi( array(
                        'username' => $username,
                        'password' => $password                                                                
                    )); 
        }
        
        public function get_purchased_software( $username, $password) {
            if (empty($username) || empty($password)) 
                return false;
            
            return MainWPApiManagerKey::instance()->getpurchasedsoftware( array(
                        'username' => $username,
                        'password' => $password                                                                
                    )); 
        }
        
        public function grab_license_key($api, $username, $password) { 
            
            $options = get_option($api . "_APIManAdder");        
            if (!is_array($options)) $options = array();            
            $activation_status = isset($options['activated_key']) ? $options['activated_key'] : "";

            $api_key = isset($options['api_key']) ? $options['api_key'] : ""; 
            $api_email = isset($options['activation_email']) ? $options['activation_email'] : "";

            if ( $activation_status == 'Deactivated' || $activation_status == '' || $api_key == '' || $api_email == '') {                                                   
                $return = array(); 
                if ( $username != '' && $password != '') {

                    $args = array(
                            'username' => $username,
                            'password' => $password,
                            'product_id' => $options['product_id'],
                            'instance' => $options['instance_id'],                
                            'software_version' => $options['software_version'],
                            'platform' 			=> $this->domain
                    );        

                    $activate_results = json_decode( MainWPApiManagerKey::instance()->grabapikey( $args ), true );                    
                    $options['api_key'] = '';
                    $options['activation_email'] = '';					
                    $options['activated_key'] = 'Deactivated';	
                            
                    if ( is_array($activate_results) && $activate_results['activated'] == true && !empty($activate_results['api_key'])) {
                            $return['result'] = 'SUCCESS';
                            $mess = isset($activate_results['message']) ? $activate_results['message'] : "";
                            $return['message'] = __( 'Plugin activated. ', 'mainwp' ) . $mess;
                            $options['api_key'] = $return['api_key'] = $activate_results['api_key'];
                            $options['activation_email'] = $return['activation_email'] = $activate_results['activation_email'];
                            $options['activated_key'] = 'Activated';
                            $options['deactivate_checkbox'] = 'off';                                        
                    } else {
                            if ( $activate_results == false ) 
                                $return['error'] =  __( 'Connection failed to the License Key API server. Try again later.', "mainwp" );
                            else if (empty($activate_results['api_key'])) {
                                $return['error'] =  __( 'License key is empty.', "mainwp" );
                            } else {
                                $return['error'] =  __( 'Undefined error.', "mainwp" );
                            }   			
                    } 

                    if ( isset( $activate_results['code'] ) ) {                                    
                            switch ( $activate_results['code'] ) {
                                    case '100':
                                    case '101':
                                    case '102':
                                    case '103':
                                    case '104':
                                    case '105':
                                    case '106':                                        
                                        $error = isset($activate_results['error']) ? $activate_results['error'] : ""; 
                                        $info = isset($activate_results['additional info']) ? ' ' . $activate_results['additional info'] : "";                                    
                                        $return['error'] = $error . $info;	                                    
                                    break;
                            }
                    }
                    MainWPUtility::update_option($api . "_APIManAdder", $options);
                    return $return;
                } else {            
                    return array('error' => __('Username and Password is required to grab Extension API Key.' , 'mainwp'));                 
                }     
            }
            
            return array('result' => 'SUCCESS');                 

        }
      
        public function download_revoked_error_notice( $software_title ){
            return sprintf( __( 'Download permission for %s has been revoked possibly due to a license key or subscription expiring. You can reactivate or purchase a license key from your account <a href="%s" target="_blank">dashboard</a>.', "mainwp" ), $software_title, $this->renew_license_url ) ;
	}
        
        
        public function update_check( $args ) {
            $args['domain'] = $this->domain;                                
            return MainWPApiManagerPluginUpdate::instance()->update_check( $args );
	}
        
        public function request_plugin_information( $args ) {                                       
            $args['domain'] = $this->domain;    
            return MainWPApiManagerPluginUpdate::instance()->request( $args );
	}
        
        
        public function check_response_for_errors( $response ) {                                    
            return MainWPApiManagerPluginUpdate::instance()->check_response_for_errors( $response, $this->renew_license_url);
	}
        
        
	/**
	 * Check for external blocking contstant
	 * @return string
	 */
	public function check_external_blocking() {
            // show notice if external requests are blocked through the WP_HTTP_BLOCK_EXTERNAL constant
            if( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL === true ) {

                // check if our API endpoint is in the allowed hosts
                $host = parse_url( $this->upgrade_url, PHP_URL_HOST );

                if( ! defined( 'WP_ACCESSIBLE_HOSTS' ) || stristr( WP_ACCESSIBLE_HOSTS, $host ) === false ) {
                    ?>
                    <div class="error">
                            <p><?php printf( __( '<b>Warning!</b> You\'re blocking external requests which means you won\'t be able to get some MainWP Extensions updates. Please add %s to %s.', 'mainwp' ), '<strong>' . $host . '</strong>', '<code>WP_ACCESSIBLE_HOSTS</code>'); ?></p>
                    </div>
                    <?php
                }

            }
	}

} // End of class
