<?php

class MainWPShortcuts
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function getName()
    {
        return __("Shortcuts",'mainwp');
    }

    public static function render()
    {
        $current_wpid = MainWPUtility::get_current_wpid();

        if (!MainWPUtility::ctype_digit($current_wpid))
        {
            return;
        }

        $website = MainWPDB::Instance()->getWebsiteById($current_wpid, true);
        ?>
    <div class="mainwp-row-top">
        <div style="display: inline-block; width: 100px;"><?php _e('Groups:','mainwp'); ?></div>
        <?php echo ($website->groups == '' ? 'None' : $website->groups); ?>
    </div>
    <div class="mainwp-row">
        <div style="display: inline-block; width: 100px;"><?php _e('Notes:','mainwp'); ?></div>
        <a href="#" class="mainwp_notes_show_all" id="mainwp_notes_<?php echo $website->id; ?>"><?php _e('Open Notes','mainwp'); ?></a><img src="<?php echo plugins_url('images/notes.png', dirname(__FILE__)); ?>" class="mainwp_notes_img" id="mainwp_notes_img_<?php echo $website->id; ?>" <?php if ($website->note == '') { echo 'style="display: none;"'; } ?> />
    </div>
    <span style="display: none"
          id="mainwp_notes_<?php echo $website->id; ?>_note"><?php echo $website->note; ?></span>
    <div class="mainwp-row">
        <div style="display: inline-block; width: 100px;"><?php _e('WP-Admin:','mainwp'); ?></div>
        <a href="admin.php?page=SiteOpen&websiteid=<?php echo $website->id; ?>"><?php _e('Open embedded','mainwp'); ?></a> | <a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $website->id; ?>" target="_blank"><?php _e('Open in new window','mainwp'); ?></a>
    </div>
    <div class="mainwp-row">
        <div style="display: inline-block; width: 100px;"><?php _e('Frontpage:','mainwp'); ?></div>
        <a class="mainwp-open-link" target="_blank" href="<?php echo $website->url; ?>"><?php _e('Open','mainwp'); ?></a>
    </div>
    <div id="mainwp_notes_overlay" class="mainwp_overlay"></div>
    <div id="mainwp_notes" class="mainwp_popup">
        <a id="mainwp_notes_closeX" class="mainwp_closeX" style="display: inline; "></a>

        <div id="mainwp_notes_title" class="mainwp_popup_title"><?php echo $website->name; ?></div>
        <div id="mainwp_notes_content">
            <textarea style="width: 580px !important; height: 300px;"
                      id="mainwp_notes_note"></textarea>
        </div>
        <form>
            <div style="float: right" id="mainwp_notes_status"></div>
            <input type="button" class="button cont button-primary" id="mainwp_notes_save" value="<?php _e('Save Note','mainwp'); ?>"/>
            <input type="button" class="button cont" id="mainwp_notes_cancel" value="<?php _e('Close','mainwp'); ?>"/>
            <input type="hidden" id="mainwp_notes_websiteid" value=""/>
        </form>
    </div>
    <?php
    }
}