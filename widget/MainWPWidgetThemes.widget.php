<?php

class MainWPWidgetThemes
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
        return __('<i class="fa fa-paint-brush"></i> Themes','mainwp');
    }

    public static function render()
    {
        ?>
    <div id="recentposts_list"><?php MainWPWidgetThemes::renderWidget(false, false); ?></div>
    <?php
    }

    public static function renderWidget($renew, $pExit = true)
    {
		$current_wpid = MainWPUtility::get_current_wpid();
		if (empty($current_wpid))
			return;
		
		$sql = MainWPDB::Instance()->getSQLWebsiteById($current_wpid);        
		$websites = MainWPDB::Instance()->query($sql);		
		$allThemes = array();
		if ($websites)
		{
			$website = @MainWPDB::fetch_object($websites);			            
			if ($website && $website->themes != '')  { 
				$themes = json_decode($website->themes, 1);                                
				if (is_array($themes) && count($themes) != 0) {
					foreach ($themes as $theme)
					{
						$allThemes[] = $theme;
					}
				}
			}
			@MainWPDB::free_result($websites);
		}	

		$actived_themes = MainWPUtility::getSubArrayHaving($allThemes, 'active', 1);
		$actived_themes = MainWPUtility::sortmulti($actived_themes, 'name', 'desc');
		
		$inactive_themes = MainWPUtility::getSubArrayHaving($allThemes, 'active', 0);
		$inactive_themes = MainWPUtility::sortmulti($inactive_themes, 'name', 'desc');
                
                if ((count($allThemes)> 0) && $website) {
                    $themes_outdate = json_decode(MainWPDB::Instance()->getWebsiteOption($website, 'themes_outdate_info'), true);
                    if (!is_array($themes_outdate))
                        $themes_outdate = array();

                    $themesOutdateDismissed = json_decode(MainWPDB::Instance()->getWebsiteOption($website, 'themes_outdate_dismissed'), true);            
                    if (is_array($themesOutdateDismissed)) {                                        
                        $themes_outdate = array_diff_key($themes_outdate, $themesOutdateDismissed);
                    }
                    
                    $userExtension = MainWPDB::Instance()->getUserExtension();  
                    $decodedDismissedThemes = json_decode($userExtension->dismissed_themes, true);
                    
                    if (is_array($decodedDismissedThemes)) {
                        $themes_outdate = array_diff_key($themes_outdate, $decodedDismissedThemes);
                    }
                }
                
		
	?>
        <div class="clear">            
            <a class="mainwp_action left mainwp_action_down themes_actived_lnk" href="#"><?php _e('Active','mainwp'); ?> (<?php echo count($actived_themes); ?>)</a><a class="mainwp_action mid themes_inactive_lnk right" href="#" ><?php _e('Inactive','mainwp'); ?> (<?php echo count($inactive_themes); ?>)</a><br/><br/>
            <div class="mainwp_themes_active">
                <?php
                for ($i = 0; $i < count($actived_themes); $i++)
                {         
                    $outdate_notice = "";                    
                    $slug = $actived_themes[$i]['slug'];
                    
                    if (isset($themes_outdate[$slug])) {
                        $theme_outdate = $themes_outdate[$slug];

                        $now = new \DateTime();
                        $last_updated = $theme_outdate['last_updated'];
                        $theme_last_updated_date = new \DateTime( '@' . $last_updated );
                        $diff_in_days = $now->diff( $theme_last_updated_date )->format( '%a' );
                        $outdate_notice = sprintf( '| <strong style="color: #f00;">Outdate %1$d days</strong>', $diff_in_days );                        
                    }
                    
                    
                ?>
                <div class="mainwp-row mainwp-active">
                    <input class="themeName" type="hidden" name="name" value="<?php echo $actived_themes[$i]['name']; ?>"/>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $website->id; ?>"/>
                    <span class="mainwp-left-col">												
                            <?php echo $actived_themes[$i]['name']. " " . $actived_themes[$i]['version']; ?> <?php echo $outdate_notice; ?>                           
                    </span>					       
                </div>
                <?php } ?>
            </div>

            <div class="mainwp_themes_inactive" style="display: none">
                <?php
                for ($i = 0; $i < count($inactive_themes); $i++)
                {      
                    $outdate_notice = "";                    
                    $slug = $inactive_themes[$i]['slug'];                    
                    if (isset($themes_outdate[$slug])) {
                        $theme_outdate = $themes_outdate[$slug];

                        $now = new \DateTime();
                        $last_updated = $theme_outdate['last_updated'];
                        $theme_last_updated_date = new \DateTime( '@' . $last_updated );
                        $diff_in_days = $now->diff( $theme_last_updated_date )->format( '%a' );
                        $outdate_notice = sprintf( '| <strong style="color: #f00;">Outdate %1$d days</strong>', $diff_in_days );                        
                    }
                ?>
                <div class="mainwp-row mainwp-inactive">
                    <input class="themeName" type="hidden" name="name" value="<?php echo $inactive_themes[$i]['name']; ?>"/>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $website->id; ?>"/>
                    <span class="mainwp-left-col">					
                        <?php echo $inactive_themes[$i]['name'] . " " . $inactive_themes[$i]['version']; ?> <?php echo $outdate_notice; ?>							
                    </span>                    
                    <div class="mainwp-right-col themesAction">
                        <?php if (mainwp_current_user_can("dashboard", "activate_themes")) { ?>
                        <a href="#" class="mainwp-theme-activate"><i class="fa fa-toggle-on"></i> <?php _e('Activate','mainwp'); ?></a> |
                        <?php } ?>
                        <?php if (mainwp_current_user_can("dashboard", "delete_themes")) { ?>
                        <a href="#" class="mainwp-theme-delete mainwp-red"><i class="fa fa-trash"></i> <?php _e('Delete','mainwp'); ?></a>
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

	public static function activateTheme()
    {
        self::action('activate');
        die(json_encode(array('result' => __('Theme has been activated','mainwp'))));
    }
	
	public static function deleteTheme()
    {
        self::action('delete');
        die(json_encode(array('result' => __('Theme has been permanently deleted','mainwp'))));
    }

    public static function action($pAction)
    {
        $theme = $_POST['theme'];
        $websiteIdEnc = $_POST['websiteId'];

        if (empty($theme)) die(json_encode(array('error' => 'Invalid Request.')));
        $websiteId = $websiteIdEnc;
        if (!MainWPUtility::ctype_digit($websiteId)) die(json_encode(array('error' => 'Invalid Request.')));

        $website = MainWPDB::Instance()->getWebsiteById($websiteId);
        if (!MainWPUtility::can_edit_website($website)) die(json_encode(array('error' => 'You can not edit this website.')));

        try
        {
            $information = MainWPUtility::fetchUrlAuthed($website, 'theme_action', array(
                'action' => $pAction,
                'theme' => $theme));
        }
        catch (MainWPException $e)
        {
            die(json_encode(array('error' => $e->getMessage())));
        }

        if (!isset($information['status']) || ($information['status'] != 'SUCCESS')) die(json_encode(array('error' => 'Unexpected error.')));
    }
}
