<?php

class MainWPAPISettings
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function initMenu()
    {
        MainWPAPISettingsView::initMenu();
    }

    public static function render()
    {
        
    }

    public static function checkUpgrade()
    {        
        $result = MainWPExtensions::getSlugs();
        $slugs = $result['slugs'];
        $am_slugs = $result['am_slugs'];        
        $output = array(); 
		
        if ($am_slugs != '') {            
            $am_slugs = explode(",", $am_slugs); 
            foreach($am_slugs as $am_slug) {                
                $rslt = self::getUpgradeInformation($am_slug);
                if (!empty($rslt)) {                                    
                    $output[$am_slug] = $rslt;
                }
            }
        }
        return $output;
    }
 
    public static function getUpgradeInformation($pSlug)
    {               
        $extensions = MainWPExtensions::loadExtensions();
        $rslt = null;
        if (is_array($extensions)) {
            foreach($extensions as $ext) {                
                if (isset($ext['api']) && ($pSlug == $ext['api']) && isset($ext['apiManager']) && !empty($ext['apiManager'])) {  
                    $args = array();
                    $args['plugin_name'] =  $ext['api'];                    
                    $args['version']    =   $ext['version'];
                    $args['product_id'] =   $ext['product_id'];
                    $args['api_key']    =   $ext['api_key'];
                    $args['activation_email'] =	$ext['activation_email'];
                    $args['instance']   =   $ext['instance_id'];
                    $args['software_version']   = $ext['software_version']; 
                    $response = MainWPApiManager::instance()->update_check($args);                     
                    if (!empty($response)) {
                        $rslt = new stdClass();
                        $rslt->slug = $ext['api']; //$response->slug
                        $rslt->latest_version = $response->new_version;                    
                        $rslt->download_url = $response->package;
                        $rslt->key_status = "";                                            
                        $rslt->apiManager = 1;                        
                        if ( isset( $response->errors)) {
                            $rslt->error = $response->errors;
                        }
                    }
                    break;
                }
            }
        }
        return $rslt;
    }
    
    public static function getPluginInformation($pSlug)
    {               
        $extensions = MainWPExtensions::loadExtensions();
        $rslt = null;
        if (is_array($extensions)) {
            foreach($extensions as $ext) {                
                if ($pSlug == $ext['api'] && isset($ext['apiManager']) && !empty($ext['apiManager'])) {  
                    $args = array();
                    $args['plugin_name'] =  $ext['api'];                    
                    $args['version']    =   $ext['version'];
                    $args['product_id'] =   $ext['product_id'];
                    $args['api_key']    =   $ext['api_key'];
                    $args['activation_email'] =	$ext['activation_email'];
                    $args['instance']   =   $ext['instance_id'];
                    $args['software_version']   = $ext['software_version']; 
                    $rslt = MainWPApiManager::instance()->request_plugin_information($args);                  
                    break;
                }
            }
        }
        return $rslt;
    }
    
    
    
    
}

?>
