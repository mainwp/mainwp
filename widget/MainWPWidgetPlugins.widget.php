<?php

class MainWPWidgetPlugins
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function test()
    {

    }

    public static function getName()
    {
        return __('<i class="fa fa-plug"></i> Plugins','mainwp');
    }

    public static function render()
    {
        ?>
    <div id="recentposts_list"><?php MainWPWidgetPlugins::renderWidget(false, false); ?></div>
    <?php
    }

    public static function renderWidget($renew, $pExit = true)
    {
		$current_wpid = MainWPUtility::get_current_wpid();
		if (empty($current_wpid))
			return;
		
		$sql = MainWPDB::Instance()->getSQLWebsiteById($current_wpid);        
		$websites = MainWPDB::Instance()->query($sql);		
		$allPlugins = array();
		if ($websites)
		{
			$website = @MainWPDB::fetch_object($websites);			            
			if ($website && $website->plugins != '')  { 
				$plugins = json_decode($website->plugins, 1);
				if (is_array($plugins) && count($plugins) != 0) {
					foreach ($plugins as $plugin)
					{
						$allPlugins[] = $plugin;
					}
				}
			}
			@MainWPDB::free_result($websites);
		}	

		$actived_plugins = MainWPUtility::getSubArrayHaving($allPlugins, 'active', 1);
		$actived_plugins = MainWPUtility::sortmulti($actived_plugins, 'name', 'desc');
		
		$inactive_plugins = MainWPUtility::getSubArrayHaving($allPlugins, 'active', 0);
		$inactive_plugins = MainWPUtility::sortmulti($inactive_plugins, 'name', 'desc');
                
                $plugins_outdate = array();
                
                if ((count($allPlugins)> 0) && $website) {
                      
                    $plugins_outdate = json_decode(MainWPDB::Instance()->getWebsiteOption($website, 'plugins_outdate_info'), true);
                    if (!is_array($plugins_outdate))
                        $plugins_outdate = array();

                    $pluginsOutdateDismissed = json_decode(MainWPDB::Instance()->getWebsiteOption($website, 'plugins_outdate_dismissed'), true);            
                    if (is_array($pluginsOutdateDismissed)) {                                        
                        $plugins_outdate = array_diff_key($plugins_outdate, $pluginsOutdateDismissed);
                    }

                    $userExtension = MainWPDB::Instance()->getUserExtension();  
                    $decodedDismissedPlugins = json_decode($userExtension->dismissed_plugins, true);
                   
                    if (is_array($decodedDismissedPlugins)) {
                        $plugins_outdate = array_diff_key($plugins_outdate, $decodedDismissedPlugins);
                    }
                }
		
	?>
        <div class="clear mwp_plugintheme_widget">            
            <a class="mainwp_action left mainwp_action_down plugins_actived_lnk" href="#"><?php _e('Active','mainwp'); ?> (<?php echo count($actived_plugins); ?>)</a><a class="mainwp_action mid plugins_inactive_lnk right" href="#" ><?php _e('Inactive','mainwp'); ?> (<?php echo count($inactive_plugins); ?>)</a><br/><br/>
            <div class="mainwp_plugins_active">
                <?php
                for ($i = 0; $i < count($actived_plugins); $i++)
                {      
                    $outdate_notice = "";                    
                    $slug = $actived_plugins[$i]['slug'];
                    
                    if (isset($plugins_outdate[$slug])) {
                        $plugin_outdate = $plugins_outdate[$slug];

                        $now = new \DateTime();
                        $last_updated = $plugin_outdate['last_updated'];
                        $plugin_last_updated_date = new \DateTime( '@' . $last_updated );
                        $diff_in_days = $now->diff( $plugin_last_updated_date )->format( '%a' );
                        $outdate_notice = sprintf( '| <strong style="color: #f00;">Outdate %1$d days</strong>', $diff_in_days );                        
                    }
                    
                ?>
                <div class="mainwp-row mainwp-active">
                    <input class="pluginSlug" type="hidden" name="slug" value="<?php echo $actived_plugins[$i]['slug']; ?>"/>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $website->id; ?>"/>
                    <span class="mainwp-left-col">						
                            <a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin='.dirname($actived_plugins[$i]['slug']).'&TB_iframe=true&width=640&height=477'; ?>" target="_blank"
                                                                                                                class="thickbox" title="More information about <?php echo $actived_plugins[$i]['name']; ?>">
                        <?php echo $actived_plugins[$i]['name']; ?>
                    </a><?php echo  " " . $actived_plugins[$i]['version']; ?> <?php echo $outdate_notice; ?>
					</span>					       
                    <div class="mainwp-right-col pluginsAction">
                        <?php if (mainwp_current_user_can("dashboard", "activate_deactivate_plugins")) { ?>
                            <a href="#" class="mainwp-plugin-deactivate"><i class="fa fa-toggle-off"></i> <?php _e('Deactivate','mainwp'); ?></a>
                        <?php } ?>
                    </div>                    
                    <div style="clear: left;"></div>
                    <div class="mainwp-row-actions-working"><i class="fa fa-spinner fa-pulse"></i> <?php _e('Please wait','mainwp'); ?></div>                    
                    <div>&nbsp;</div>
                </div>
                <?php } ?>
            </div>

            <div class="mainwp_plugins_inactive" style="display: none">
                <?php
                for ($i = 0; $i < count($inactive_plugins); $i++)
                {        
                    $outdate_notice = "";                    
                    $slug = $inactive_plugins[$i]['slug'];
                    if (isset($plugins_outdate[$slug])) {
                        $plugin_outdate = $plugins_outdate[$slug];

                        $now = new \DateTime();
                        $last_updated = $plugin_outdate['last_updated'];
                        $plugin_last_updated_date = new \DateTime( '@' . $last_updated );
                        $diff_in_days = $now->diff( $plugin_last_updated_date )->format( '%a' );
                        $outdate_notice = sprintf( '| <strong style="color: #f00;">Outdate %1$d days</strong>', $diff_in_days );                        
                    }
                ?>
                <div class="mainwp-row mainwp-inactive">
                    <input class="pluginSlug" type="hidden" name="slug" value="<?php echo $inactive_plugins[$i]['slug']; ?>"/>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $website->id; ?>"/>
                    <span class="mainwp-left-col">
                    <a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin='.dirname($inactive_plugins[$i]['slug']).'&TB_iframe=true&width=640&height=477'; ?>" target="_blank"
                                                                                                                                                                                                                    class="thickbox" title="More information about <?php echo $inactive_plugins[$i]['name']; ?>">
                                    <?php echo $inactive_plugins[$i]['name']; ?>
                            </a><?php echo  " " . $inactive_plugins[$i]['version']; ?> <?php echo $outdate_notice; ?>							
                    </span>                                       
                    <div class="mainwp-right-col pluginsAction">
                        <?php if (mainwp_current_user_can("dashboard", "activate_deactivate_plugins")) { ?>
                        <a href="#" class="mainwp-plugin-activate"><i class="fa fa-toggle-on"></i> <?php _e('Activate','mainwp'); ?></a> | 
                        <?php } ?>
                        <?php if (mainwp_current_user_can("dashboard", "delete_plugins")) { ?>
                        <a href="#" class="mainwp-plugin-delete mainwp-red"><i class="fa fa-trash"></i> <?php _e('Delete','mainwp'); ?></a>
                        <?php } ?>
                    </div>
                        <div style="clear: left;"></div>
                    <div class="mainwp-row-actions-working"><i class="fa fa-spinner fa-pulse"></i> <?php _e('Please wait','mainwp'); ?></div>                    
                        <div>&nbsp;</div>
                </div>
                <?php } ?>
            </div>
        </div>
    <div class="clear"></div>
    <?php
        if ($pExit == true) exit();
    }

	public static function activatePlugin()
    {
        self::action('activate');
        die(json_encode(array('result' => __('Plugin has been activated','mainwp'))));
    }
	
	public static function deactivatePlugin()
    {
        self::action('deactivate');
        die(json_encode(array('result' => __('Plugin has been deactivated','mainwp'))));
    }

	public static function deletePlugin()
    {
        self::action('delete');
        die(json_encode(array('result' => __('Plugin has been permanently deleted','mainwp'))));
    }

    public static function action($pAction)
    {
        $plugin = $_POST['plugin'];
        $websiteIdEnc = $_POST['websiteId'];

        if (empty($plugin)) die(json_encode(array('error' => 'Invalid Request.')));
        $websiteId = $websiteIdEnc;
        if (!MainWPUtility::ctype_digit($websiteId)) die(json_encode(array('error' => 'Invalid Request.')));

        $website = MainWPDB::Instance()->getWebsiteById($websiteId);
        if (!MainWPUtility::can_edit_website($website)) die(json_encode(array('error' => 'You can not edit this website.')));

        try
        {
            $information = MainWPUtility::fetchUrlAuthed($website, 'plugin_action', array(
                'action' => $pAction,
                'plugin' => $plugin));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => $e->getMessage())));
        }

        if (!isset($information['status']) || ($information['status'] != 'SUCCESS')) die(json_encode(array('error' => 'Unexpected error.')));
    }
}
