<?php

class MainWPNotes
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function getName()
    {
        return __('<i class="fa fa-file-text-o"></i> Notes','mainwp');
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
        <div id="mainwp-notes-area">
            <div id="mainwp-notes-note" style="padding-bottom: 1em;">
                <?php 
                  if ($website->note == '') {
                    echo "No Saved Notes";
                  }
                  else {
                      echo $website->note;
                  }
                ?>
            </div>
            <div style="text-align: center; border-top: 1px Solid #f4f4f4; padding-top: 1em;">
                <a href="#" class="mainwp_notes_show_all button button-primary" id="mainwp_notes_<?php echo $website->id; ?>"><?php _e('Edit Notes','mainwp'); ?></a>
            </div>
        </div>
    <?php
}
}
?>