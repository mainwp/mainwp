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
        <fieldset class="mainwp-fieldset-box">
    <legend><?php _e('Network Footprint','mainwp'); ?></legend><a name="network-footprint"></a>
    <table class="form-table">
        <tbody>
        <?php do_action('mainwp_settings_networkfootprint'); ?>
        </tbody>
    </table>
    <div class="mainwp_info-box"><strong>Note: </strong><i><?php _e('After pressing "Save Settings" below you will need to return to MainWP Dashboard and press the Sync Data button to synchronize the settings.','mainwp'); ?></i></div>
    </fieldset>

    <?php
    }
}