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
    
    static function randomWord() {
        $words = array (__("Awesome", 'mainwp'),
                        __("Fabulous", 'mainwp'),
                        __("Impressive", 'mainwp'),
                        __("Incredible", 'mainwp'),
                        __("Super", 'mainwp'),
                        __("Wonderful", 'mainwp'),
                        __("Wow", 'mainwp'));        
        return $words[rand(0, 6)];
    }
    
    static function getNotice($what, $value) {
        
        if (!is_array($value) || empty($value['sites']) || !isset($value['seconds'])) 
            return "";
        
        $message = "";
        $first_word = self::randomWord();
        switch ( $what ) {
            case 'upgrade_everything':  
                    $message = $first_word . ', you just updated <strong>%d</strong> ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['sites']);                       
                break;
            case 'upgrade_all_wp_core':  
                    $message = $first_word . ', you just updated <strong>%d</strong> WordPress ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['sites']);                       
                break;
            case 'upgrade_all_plugins':  
                    $message = $first_word . ', you just updated <strong>%d</strong> ' . ($value['items'] == 1 ? 'plugin' : 'plugins') . ' on <strong>%d</strong> ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['items'], $value['sites']);                                        
                break;
            case 'upgrade_all_themes':  
                    $message = $first_word . ', you just updated <strong>%d</strong> ' . ($value['items'] == 1 ? 'theme' : 'themes') . ' on <strong>%d</strong> ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['items'], $value['sites']);                                                            
                break;
            case 'new_post':  
                    $message = $first_word . ', you just published a new post on <strong>%d</strong> ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['sites']);                    
                break;
            case 'new_page':                      
                    $message = $first_word . ', you just published a new page on <strong>%d</strong> ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['sites']);
                break;
            case 'installing_new_plugin':  
                    $message = $first_word . ', you just installed a new plugin on <strong>%d</strong> ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['sites']);
                break;
            case 'installing_new_theme':                      
                    $message = $first_word . ', you just installed a new theme on <strong>%d</strong> ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['sites']);
                break;
            case 'create_new_user':                      
                    $message = $first_word . ', you just created a new user on <strong>%d</strong> ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                    $message = sprintf(__($message), $value['sites']);
                break;
         } 
        
         if (!empty($message)) {           
            $in_sec = $value['seconds'];
            if ( $in_sec <= 60) {
                if ($what == 'upgrade_all_plugins' || $what == 'upgrade_all_themes' || $what == 'upgrade_everything') {
                    $real_updated = $value['real_items'];
                    $message .= ", " . sprintf(__("<strong>%d</strong> total " . (($real_updated == 1) ? 'update' : 'updates'), 'mainwp'), $real_updated); 
                }
                $message .= " " . sprintf(__('in <strong>%d</strong> ' . (($in_sec == 1) ? 'second' : 'seconds'), 'mainwp'), $in_sec);
            }
            $message .= '!';                                     
         }
         
         return $message;
    }
    
    public static function genTwitterButton($content, $echo = true) {
        ob_start();
        ?>  
        <button class="mainwp_tweet_this">
            <i class="fa fa-twitter fa-1x" style="color: #4099FF;"></i>&nbsp;
                <?php _e("Brag on Twitter", 'mainwp'); ?></button>
        <script type="text/javascript">                
                var mainwpTweetUrlBuilder = function(o){
                    return [
                        'https://twitter.com/intent/tweet?tw_p=tweetbutton',
                        '&url=" "',                        
                        '&text=', o.text
                    ].join('');
                };                
                jQuery('.mainwp_tweet_this').on('click', function(){                
                    var url = mainwpTweetUrlBuilder({                        
                        text: '<?php echo urlencode($content); ?>'
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
    
    public static function updateTwitterInfo($what, $countSites = 0, $countSec = 0, $coutRealItems = 0, $twId = 0, $countItems = 1) {        
        if (empty($twId))
            return false;
        
        if (empty($coutRealItems))
            return false;
        
        $filters = self::get_filter();       
        
        if (!in_array($what, $filters))
            return false;
		
		if ( 'new_page' == $what || 'new_post' == $what || 'create_new_user' == $what ) {
			if ( 1 == $countSites ) 
				return false;
		} else if ( ( 1 == $countSites ) && ( 1 == $coutRealItems) ) {
			return false;
		}
			
        if (empty($countSec)) $countSec = 1; 
        // store one twitt info only
        $data = array($twId => array('sites' => $countSites, 'seconds' => $countSec, 'items' => $countItems, 'real_items' => $coutRealItems));
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
        //@MyMainWP I just quickly updated 3 plugins on 3 #WordPress sites, 5 total updates in 12 seconds
        $opt_name = 'mainwp_tt_message_' . $what;
        $twitter_messages = get_user_option($opt_name);        
        if (is_array($twitter_messages[$twId]) && isset($twitter_messages[$twId])) {
            $value = $twitter_messages[$twId];                          
            if (is_array($value) && !empty($value['sites']) && !empty($value['seconds'])) {                
                $twit = "";                 
                switch ( $what ) {
                   case 'upgrade_everything':  
                            $twit = 'Thanks to @MyMainWP I just quickly updated %d #WordPress ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['sites']);
                       break;
                   case 'upgrade_all_wp_core':                             
                            $twit = 'Thanks to @MyMainWP I just quickly updated %d #WordPress ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['sites']);
                       break;
                   case 'upgrade_all_plugins':
                            $twit = 'Thanks to @MyMainWP I just quickly updated %d ' . ($value['items'] == 1 ? 'plugin' : 'plugins') . ' on %d #WordPress ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['items'], $value['sites']);
                       break;
                   case 'upgrade_all_themes':  
                            $twit = 'Thanks to @MyMainWP I just quickly updated %d ' . ($value['items'] == 1 ? 'theme' : 'themes') . ' on %d #WordPress ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['items'], $value['sites']);
                       break;
                   case 'new_post':                              
                            $twit = 'Thanks to @MyMainWP I just quickly published a new post on %d #WordPress ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['sites']);
                       break;
                   case 'new_page':  
                            $twit = 'Thanks to @MyMainWP I just quickly published a new page on %d #WordPress ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['sites']);
                       break;
                   case 'installing_new_plugin':  
                            $twit = 'Thanks to @MyMainWP I just quickly installed a new plugin on %d #WordPress ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['sites']);                            
                       break;
                   case 'installing_new_theme':  
                            $twit = 'Thanks to @MyMainWP I just quickly installed a new theme on %d #WordPress ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['sites']);                            
                       break;
                   case 'create_new_user':  
                            $twit = 'Thanks to @MyMainWP I just quickly created a new user on %d #WordPress ' . ( $value['sites'] == 1 ? 'site' : 'sites');
                            $twit = sprintf(__($twit), $value['sites']);
                       break;
                }
                if (!empty($twit)) {                      
                    $in_sec = $value['seconds'];
                    if ( $in_sec <= 60 ) {
                        if ($what == 'upgrade_all_plugins' || $what == 'upgrade_all_themes' || $what == 'upgrade_everything') {
                            $real_updated = $value['real_items'];
                            $twit .= ", " . sprintf(__("%d total " . (($real_updated == 1) ? 'update' : 'updates'), 'mainwp'), $real_updated); 
                        }
                        $twit .= " " . sprintf(__('in %d ' . (($in_sec == 1) ? 'second' : 'seconds'), 'mainwp'), $in_sec);
                    } 
                    $twit .= '! https://mainwp.com';                                        
                    return $twit;
                }                
            } 
        }    
        return "";
    }
    
}