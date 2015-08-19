<?php

class MainWPTwitter
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    static function get_filter() {
        return array(   'upgrade_everything',
                            'upgrade_all_wp_core',
                            'upgrade_all_plugins',
                            'upgrade_all_themes',
                            'new_post',
                            'new_page',
                            'installing_new_plugin',
                            'installing_new_theme',
                            'create_new_user',
                        ); 
    }
    
    public static function enabledTwitterMessages() {        
        if (!get_option('mainwp_hide_twitters_message', 0)) {
            return true;
        }
        return false;
    }
    
    public static function clearAllTwitterMessages() {        
        $filters = self::get_filter();
        $user_id = get_current_user_id();
        
        foreach($filters as $what) {
            $opt_name = 'mainwp_tt_message_' . $what;
            delete_user_option($user_id, $opt_name);
        }
    }
    
    static function getNotice($what, $value) {
        
        if (!is_array($value) || empty($value['sites']) || !isset($value['seconds'])) 
            return "";
        
        $message = "";
        switch ( $what ) {
            case 'upgrade_everything':  
                    $message = 'Wow, you updated %d ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['sites']);                       
                break;
            case 'upgrade_all_wp_core':  
                    $message = 'Wow, you updated %d WordPress ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['sites']);                       
                break;
            case 'upgrade_all_plugins':  
                    $message = 'Wow, you updated %d ' . ($value['items'] == 1 ? 'plugin' : 'plugins') . ' on %d ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['items'], $value['sites']);                                        
                break;
            case 'upgrade_all_themes':  
                    $message = 'Wow, you updated %d ' . ($value['items'] == 1 ? 'theme' : 'themes') . ' on %d ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['items'], $value['sites']);                                                            
                break;
            case 'new_post':  
                    $message = 'Wow, you published a new post on %d ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['sites']);                    
                break;
            case 'new_page':                      
                    $message = 'Wow, you published a new page on %d ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['sites']);
                break;
            case 'installing_new_plugin':  
                    $message = 'Wow, you installed a new plugin on %d ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['sites']);
                break;
            case 'installing_new_theme':                      
                    $message = 'Wow, you installed a new theme on %d ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['sites']);
                break;
            case 'create_new_user':                      
                    $message = 'Wow, you created a new user on %d ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['sites']);
                break;
         } 
         
         if (!empty($message)) {           
            $in_sec = $value['seconds'];
            if ( $in_sec <= 60) {
                $message .= " " . sprintf(__('in %d seconds', 'mainwp'), $in_sec);
            }
            $message .= ".";                                     
         }
         
         return $message;
    }
    
    public static function genTwitterButton($content, $echo = true) {
        ob_start();
        ?>  
<button class="mainwp_tweet_this"><i class="fa fa-twitter fa-1x" style="color: #4099FF;"></i>&nbsp;Tweet</button>
        <script type="text/javascript">                
                var maiwpTweetUrlBuilder = function(o){
                    return [
                        'https://twitter.com/intent/tweet?tw_p=tweetbutton',
                        '&url=" "',                        
                        '&text=', o.text
                    ].join('');
                };                
                jQuery('.mainwp_tweet_this').on('click', function(){                
                    var url = maiwpTweetUrlBuilder({                        
                        text: '<?php echo $content; ?>'
                    });                                    
                    window.open(url, 'Tweet', 'height=450,width=700');
                    mainwp_twitter_dismiss(this);
                })
        </script>
        <?php
         $return = ob_get_clean();
         
         if ($echo)
            echo $return;
         else
            return $return;
    }
    
    public static function updateTwitterInfo($what, $countSites = 0, $countSec = 0, $countItems = 0, $twId = 0) {        
        if (empty($twId))
            return false;
        
        $filters = self::get_filter();       
        
        if (!in_array($what, $filters))
            return false;    
        
        if (empty($countSec)) $countSec = 1;        
        $data = array($twId => array('sites' => $countSites, 'seconds' => $countSec, 'items' => $countItems));
        $user_id = get_current_user_id();         
        
        $opt_name = 'mainwp_tt_message_' . $what;       
         
        if (update_user_option($user_id, $opt_name, $data )) {            
            return true;
        }            
        
        return false;   
    }    
    
    public static function clearTwitterInfo($what, $twId = 0) {        
        if (empty($twId))
            return false;
        
        $filters = self::get_filter();
        
        if (!in_array($what, $filters))
            return false;
         
        $opt_name = 'mainwp_tt_message_' . $what;
        
        $data = get_user_option($opt_name);
        
        if (!is_array($data))
            $data = array(); 
        
        if (isset($data[$twId])) {
            unset($data[$twId]);                
            $user_id = get_current_user_id();           
            update_user_option($user_id, $opt_name, $data );                
        }
        
        return true;   
    }
    
    public static function getTwitterNotice($what, $twId = 0) {        
        
        $filters = self::get_filter();
        
        if (!in_array($what, $filters))
            return false;
          
        $opt_name = 'mainwp_tt_message_' . $what;        
        $twitter_messages = get_user_option($opt_name);     
        
        $return = array();
        
        if (is_array($twitter_messages)) {            
            if (!empty($twId)) {
                if (isset($twitter_messages[$twId])) { 
                    $value = $twitter_messages[$twId];
                    $mess = self::getNotice($what, $value);
                    if (!empty($mess))
                        $return[$twId] = $mess;
                } 
            } else {
                foreach($twitter_messages as $time => $value) {                            
                    $mess = self::getNotice($what, $value);
                    if (!empty($mess))
                        $return[$time] = $mess;                    
                }
            }
        }
        return $return;
    }    
    
    public static function getTwitToSend($what, $twId = 0) {        
        
        $filters = self::get_filter();
        
        if (!in_array($what, $filters))
            return "";
        
        $opt_name = 'mainwp_tt_message_' . $what;
        $twitter_messages = get_user_option($opt_name);        
        if (is_array($twitter_messages[$twId]) && isset($twitter_messages[$twId])) {
            $value = $twitter_messages[$twId];                          
            if (is_array($value) && !empty($value['sites']) && !empty($value['seconds'])) {                
                $twit = "";                 
                switch ( $what ) {
                   case 'upgrade_everything':  
                            $twit = 'Thanks to @mymainwp I just successfully updated %d child ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['sites']);
                       break;
                   case 'upgrade_all_wp_core':                             
                            $twit = 'Thanks to @mymainwp I just successfully updated %d WordPress ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['sites']);
                       break;
                   case 'upgrade_all_plugins':
                            $twit = 'Thanks to @mymainwp I just successfully updated %d ' . ($value['items'] == 1 ? 'plugin' : 'plugins') . ' on %d child ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['items'], $value['sites']);
                       break;
                   case 'upgrade_all_themes':  
                            $twit = 'Thanks to @mymainwp I just successfully updated %d ' . ($value['items'] == 1 ? 'theme' : 'themes') . ' on %d child ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['items'], $value['sites']);
                       break;
                   case 'new_post':                              
                            $twit = 'Thanks to @mymainwp I just successfully published a new post on %d child ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['sites']);
                       break;
                   case 'new_page':  
                            $twit = 'Thanks to @mymainwp I just successfully published a new page on %d child ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['sites']);
                       break;
                   case 'installing_new_plugin':  
                            $twit = 'Thanks to @mymainwp I just successfully installed a new plugin on %d child ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['sites']);                            
                       break;
                   case 'installing_new_theme':  
                            $twit = 'Thanks to @mymainwp I just successfully installed a new theme on %d child ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['sites']);                            
                       break;
                   case 'create_new_user':  
                            $twit = 'Thanks to @mymainwp I just successfully created a new user on %d child ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['sites']);
                       break;
                }
                if (!empty($twit)) {                      
                    $in_sec = $value['seconds'];
                    if ( $in_sec <= 60 ) {
                        $twit .= " " . sprintf(__('in %d seconds', 'mainwp'), $in_sec);
                    }                    
                    $twit .= ' https://mainwp.com';                                        
                    return $twit;
                }                
            } 
        }    
        return "";
    }
    
}