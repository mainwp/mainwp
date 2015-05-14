<?php

class MainWPShortcuts
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function getName()
    {
        return __('<i class="fa fa-share-square-o"></i> Shortcuts','mainwp');
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
        <a href="#" class="mainwp_notes_show_all" id="mainwp_notes_<?php echo $website->id; ?>"><i class="fa fa-pencil"></i> <?php _e('Open Notes','mainwp'); ?></a><img src="<?php echo plugins_url('images/notes.png', dirname(__FILE__)); ?>" class="mainwp_notes_img" id="mainwp_notes_img_<?php echo $website->id; ?>" <?php if ($website->note == '') { echo 'style="display: none;"'; } ?> />
    </div>
    <span style="display: none"
          id="mainwp_notes_<?php echo $website->id; ?>_note"><?php echo $website->note; ?></span>
    <div class="mainwp-row">
        <div style="display: inline-block; width: 100px;"><?php _e('Go to:','mainwp'); ?></div>
        <a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $website->id; ?>" target="_blank"><i class="fa fa-external-link"></i> <?php _e('WP Admin','mainwp'); ?></a> | <a target="_blank" href="<?php echo $website->url; ?>"><i class="fa fa-external-link"></i> <?php _e('Front Page','mainwp'); ?></a>
    </div>
    <div class="mainwp-row">
        <div style="display: inline-block; width: 100px;"><?php _e('Child Site:','mainwp'); ?></div>
        <a href="admin.php?page=managesites&id=<?php echo $website->id; ?>"><i class="fa fa-pencil-square-o"></i> <?php _e('Edit','mainwp'); ?></a> | <a target="_blank" href="admin.php?page=managesites&scanid=<?php echo $website->id; ?>"><i class="fa fa-shield"></i> <?php _e('Security Scan','mainwp'); ?></a>
    </div>

    <?php do_action("mainwp_shortcuts_widget", $website); ?>
    <div id="mainwp_notes_overlay" class="mainwp_overlay"></div>
    <div id="mainwp_notes" class="mainwp_popup">
        <a id="mainwp_notes_closeX" class="mainwp_closeX" style="display: inline; "></a>

        <div id="mainwp_notes_title" class="mainwp_popup_title"><a href="<?php echo admin_url('admin.php?page=managesites&dashboard=' . $website->id); ?>"><?php echo $website->name; ?></a></div>
        <div id="mainwp_notes_content">
            <textarea style="width: 580px !important; height: 300px;"
                      id="mainwp_notes_note"></textarea>
        </div>
        <div><em>Allowed HTML Tags: &lt;p&gt;, &lt;srtong&gt;, &lt;em&gt;, &lt;br/&gt;, &lt;hr/&gt;, &lt;a&gt; </em></div><br/>
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