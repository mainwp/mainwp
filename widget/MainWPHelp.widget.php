<?php

class MainWPHelp
{
    public static function getClassName()
    {
        return __CLASS__;
    }

    public static function getName()
    {
        return __('<i class="fa fa-question-circle"></i> Help','mainwp');
    }

    public static function render()
    {
        ?>
        <div>
            <div id="mainwp-docs-search" class="mainwp-row-top">
                <form name="advanced-search-form" method="get" action="//docs.mainwp.com/" class="auto-complete" autocomplete="off" target="_blank">
                    <input type="text" style="width: 75%;" class="input-text input-txt" name="s" id="s" value="" placeholder="<?php _e('Search the MainWP Docs','mainwp'); ?>" />
                    <button type="submit" class="button button-primary mainwp-upgrade-button" style="padding-left: 3em !important; padding-right: 3em !important;"><?php _e('Search','mainwp'); ?></button>
                </form>
            </div>
            <div id="mainwp-docs-tabs" class="mainwp-row">
                <a class="mainwp_action left mainwp_action_down" href="#" id="mainwp-quick-start-tab"><?php _e('Quick Start','mainwp'); ?></a><a class="mainwp_action mid" href="#" id="mainwp-manage-tab"><?php _e('Manage','mainwp'); ?></a><a class="mainwp_action mid" href="#" id="mainwp-sites-tab"><?php _e('Sites','mainwp'); ?></a><a class="mainwp_action mid" href="#" id="mainwp-backups-tab"><?php _e('Backups','mainwp'); ?></a><a class="mainwp_action mid" href="#" id="mainwp-clone-tab"><?php _e('Clone','mainwp'); ?></a><a class="mainwp_action mid" href="#" id="mainwp-misc-tab"><?php _e('Miscellaneous','mainwp'); ?></a><a class="mainwp_action right" href="#" id="mainwp-extensions-tab"><?php _e('Extensions','mainwp'); ?></a>
            </div>
            <div id="mainwp-docs-list">
                <div id="mainwp-quick-start-docs">
                    <ul>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/mainwp-settings-overview/" target="_blank">MainWP Settings</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/adding-a-site-to-your-mainwp/" target="_blank">Adding a Site To MainWP Dashboard</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/quick-start-adding-a-new-group/" target="_blank">Adding a New Group</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/mainwp-dashboard-overview/" target="_blank">MainWP Dashboard</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/how-to-upload-and-install-a-plugin/" target="_blank">How to upload and install a Plugin</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/how-to-upload-and-install-a-theme/" target="_blank">How to upload and install a Theme</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/how-to-update-plugins/" target="_blank">How to update Plugins</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/how-to-update-themes/" target="_blank">How to update Themes</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/how-to-install-mainwp-extensions/" target="_blank">How to install MainWP Extensions</a></li>
                    </ul>
                </div>
                <div id="mainwp-manage-docs" style="display: none">
                    <ul>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/managing-posts/" target="_blank">Manage Posts</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/managing-pages/" target="_blank">Manage Pages</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/managing-comments/" target="_blank">Manage Comments</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/managing-users/" target="_blank">Manage Users</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/manage-themes/" target="_blank">Manage Themes</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/manage-plugins/" target="_blank">Manage Plugins</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/manage-admin-passwords/" target="_blank">Manage Admin Passwords</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/manage-offline-checks/" target="_blank">Manage Offline Checks</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/manage-backups/" target="_blank">Manage Backups</a></li>
                    </ul>
                </div>
                <div id="mainwp-sites-docs" style="display: none">
                    <ul>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/individual-site-dashboard-overview/" target="_blank">Individual Site Dashboards</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/how-do-i-make-sure-the-main-plugin-can-communicate-with-my-child-site/" target="_blank">How to make sure the Main Plugin can communicate with my Child site</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/how-do-i-use-the-child-unique-security-id/" target="_blank">How to use the Child Unique Security ID</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/how-do-i-change-the-number-of-sites-listed-on-my-sites-page/" target="_blank">How to change the number of sites listed on my sites page</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/backup-your-wordpress-sites-with-mainwp/" target="_blank">Backup your WordPress Sites with MainWP</a></li>
                    </ul>
                </div>
                <div id="mainwp-backups-docs" style="display: none">
                    <ul>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/mainwp-backups-overview/" target="_blank">Backup your WordPress Sites with MainWP</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/backup-remote-destinations/" target="_blank">Backup Remote Destinations</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/restoring-mainwp-backups/" target="_blank">Restoring MainWP Backups</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/execute-backups-chunks/" target="_blank">Execute Backups in Chunks</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/require-backup-upgrade/" target="_blank">Require Backups Before Upgrade</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/how-do-i-create-a-sub-folder-for-my-backups/" target="_blank">How to create a sub-folder for my backups</a></li>
                    </ul>
                </div>
                <div id="mainwp-clone-docs" style="display: none">
                    <ul>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/how-to-clone-a-wordpress-website-with-mainwp/" target="_blank">How To Clone a WordPress Site with MainWP</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/why-does-the-clone-option-not-show-on-my-child-site/" target="_blank">Why does the clone option not show on my child site?</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/how-do-i-stop-individual-sites-from-being-cloned/" target="_blank">How do I stop individual sites from being cloned?</a></li>
                    </ul>
                </div>
                <div id="mainwp-misc-docs" style="display: none">
                    <ul>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/update-mainwp-dashboard/" target="_blank">How to update MainWP Dashboard</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/what-is-maximum-requests-sent-out-to-child-sites/" target="_blank">What is Maximum Requests Sent out to Child Sites?</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/how-can-i-hide-the-client-plugin-folder/" target="_blank">How can I Hide the Client Plugin Folder?</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/how-often-are-my-analytics-updated/" target="_blank">How often are my Analytics updated</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/how-do-i-add-bulk-users/" target="_blank">How do I add bulk users</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/deleting-secure-link-admin/" target="_blank">Deleting Secure Link Admin</a></li>
                    </ul>
                </div>
                <div id="mainwp-extensions-docs" style="display: none">
                    <ul>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/category/mainwp-extensions/mainwp-spinner/" target="_blank">MainWP Spinner Extension</a> - <a style="text-decoration: none;" href="https://mainwp.com/forum/forumdisplay.php?73-MainWP-Spinner" target="_blank">Support Forum</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/category/mainwp-extensions/mainwp-advanced-uptime-monitor/" target="_blank">MainWP Advanced Uptime Monitor Extension</a> - <a style="text-decoration: none;" href="https://mainwp.com/forum/forumdisplay.php?66-Advanced-Uptime-Monitor" target="_blank">Support Forum</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/category/mainwp-extensions/mainwp-content/" target="_blank">MainWP Content Generator Extension</a> - <a style="text-decoration: none;" href="https://mainwp.com/forum/forumdisplay.php?74-Content-Extension" target="_blank">Support Forum</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/category/mainwp-extensions/mainwp-maintenance/" target="_blank">MainWP Maintenance Extension</a> - <a style="text-decoration: none;" href="https://mainwp.com/forum/forumdisplay.php?82-Maintenance-Extension" target="_blank">Support Forum</a></li>
                        <li><a style="text-decoration: none;" href="//docs.mainwp.com/category/mainwp-extensions/mainwp-maintenance/" target="_blank">MainWP Favorites Extension</a> - <a style="text-decoration: none;" href="https://mainwp.com/forum/forumdisplay.php?83-Favorites-Extension" target="_blank">Support Forum</a></li>
                    </ul>
                </div>
            </div>
        </div>
    <?php
}
}
?>