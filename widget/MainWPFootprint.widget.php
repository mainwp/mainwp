<?php

class MainWPFootprint
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function handleSettingsPost()
    {
        if (isset($_POST['submit'])) {
            $userExtension = MainWPDB::Instance()->getUserExtension();
            $userExtension->heatMap = (!isset($_POST['mainwp_options_footprint_heatmap']) ? 1 : 0);
            $userExtension->pluginDir = (!isset($_POST['mainwp_options_footprint_plugin_folder']) ? 'default' : 'hidden');

            MainWPDB::Instance()->updateUserExtension($userExtension);
            return true;
        }
        return false;
    }

    public static function renderSettings()
    {
        $filter = apply_filters('mainwp_has_settings_networkfootprint', false);
        if (!$filter) return;

        ?>
        <div class="postbox" id="mainwp-footprint-settings">
    <h3 class="mainwp_box_title"><span><?php _e('Network Footprint','mainwp'); ?></span></h3><a name="network-footprint"></a>
    <div class="inside">
    <table class="form-table">
        <tbody>
        <?php do_action('mainwp_settings_networkfootprint'); ?>
        </tbody>
    </table>
    <div class="mainwp_info-box"><strong>Note: </strong><i><?php _e('After pressing "Save Settings" below you will need to return to MainWP Dashboard and press the Sync Data button to synchronize the settings.','mainwp'); ?></i></div>
    </div>
    </div>

    <?php
    }
}