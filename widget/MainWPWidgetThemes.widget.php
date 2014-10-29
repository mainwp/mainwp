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
        return __("Themes",'mainwp');
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
		
	?>
        <div class="clear">            
            <a class="mainwp_action left mainwp_action_down themes_actived_lnk" href="#"><?php _e('Active','mainwp'); ?> (<?php echo count($actived_themes); ?>)</a><a class="mainwp_action mid themes_inactive_lnk right" href="#" ><?php _e('Inactive','mainwp'); ?> (<?php echo count($inactive_themes); ?>)</a><br/><br/>
            <div class="mainwp_themes_active">
                <?php
                for ($i = 0; $i < count($actived_themes); $i++)
                {                    
                ?>
                <div class="mainwp-row mainwp-active">
                    <input class="themeName" type="hidden" name="name" value="<?php echo $actived_themes[$i]['name']; ?>"/>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $website->id; ?>"/>
                    <span class="mainwp-left-col">												
                            <?php echo $actived_themes[$i]['name']. " " . $actived_themes[$i]['version']; ?>                            
                    </span>					       
                </div>
                <?php } ?>
            </div>

            <div class="mainwp_themes_inactive" style="display: none">
                <?php
                for ($i = 0; $i < count($inactive_themes); $i++)
                {                    
                ?>
                <div class="mainwp-row mainwp-inactive">
                    <input class="themeName" type="hidden" name="name" value="<?php echo $inactive_themes[$i]['name']; ?>"/>
                    <input class="websiteId" type="hidden" name="id" value="<?php echo $website->id; ?>"/>
                    <span class="mainwp-left-col">					
                        <?php echo $inactive_themes[$i]['name'] . " " . $inactive_themes[$i]['version']; ?>							
                    </span>                    
                    <div class="mainwp-right-col themesAction">
                        <?php if (mainwp_current_user_can("dashboard", "activate_themes")) { ?>
                        <a href="#" class="mainwp-theme-activate"><?php _e('Activate','mainwp'); ?></a> | 
                        <?php } ?>
                        <?php if (mainwp_current_user_can("dashboard", "delete_themes")) { ?>
                        <a href="#" class="mainwp-theme-delete mainwp-red"><?php _e('Delete','mainwp'); ?></a>
                        <?php } ?>
                    </div>
                    <div style="clear: left;"></div>
                    <div class="mainwp-row-actions-working"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/> <?php _e('Please wait','mainwp'); ?></div>                    
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
