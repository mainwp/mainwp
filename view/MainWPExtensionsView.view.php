<?php
class MainWPExtensionsView
{
    public static function initMenu()
    {
        add_submenu_page('mainwp_tab', __('Extensions', 'mainwp'), ' <span id="mainwp-Extensions">' . __('Extensions', 'mainwp') . '</span>', 'read', 'Extensions', array(MainWPExtensions::getClassName(), 'render'));
    }

    public static function renderHeader($shownPage, &$extensions)
    {
        ?>
    <div class="wrap">
        <a href="http://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img
                src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50"
                alt="MainWP"/></a>
        <img src="<?php echo plugins_url('images/icons/mainwp-extensions.png', dirname(__FILE__)); ?>"
             style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Extensions" height="32"/>
        <h2><?php _e('Extensions', 'mainwp'); ?></h2><div style="clear: both;"></div><br/><br/>
        <?php if ($shownPage === '') { ?>
        <div class="mainwp-ext-categories" id="mainwp-recent-extensions">
            <div id="mainwp-recent-extensions-title" style="border-bottom: 1px Solid #000;"><span style="color: #f0efe9;">Get MainWP Extensions</span></div>
            <div id="mainwp-recent-extensions-products" style="border-top: 1px Solid #555; padding-top: 1em;">
            <span class="mainwp_fivecol_one">
            <ul class="mainwp-product-categories-list">
            <li><a class="mainwp-administrative" target="_blank" href="http://extensions.mainwp.com/administrative-extensions">Administrative</a></li>
            </ul>
            </span>
            <span class="mainwp_fivecol_one">
            <ul class="mainwp-product-categories-list">
            <li><a class="mainwp-content" target="_blank" href="http://extensions.mainwp.com/content-extensions">Content</a></li>
            </ul>
            </span>
            <span class="mainwp_fivecol_one">
            <ul class="mainwp-product-categories-list">
            <li><a class="mainwp-visitor-data" target="_blank" href="http://extensions.mainwp.com/visitor-data-extensions">Visitor Data</a></li>
            </ul>
            </span>
            <span class="mainwp_fivecol_one">
            <ul class="mainwp-product-categories-list">
            <li><a class="mainwp-free" target="_blank" href="http://extensions.mainwp.com/free-extensions">Free Extensions</a></li>
            </ul>
            </span>
            <span class="mainwp_fivecol_one">
            <ul class="mainwp-product-categories-list">
            <li><a class="mainwp-all" target="_blank" href="http://extensions.mainwp.com/shop">All Extensions</a></li>
            </ul>
            </span>
            <div class="clear"></div>
            </div>
        </div>
        <?php } ?>
        <div class="mainwp-tabs" id="mainwp-tabs">
            <a class="nav-tab pos-nav-tab <?php if ($shownPage === '') { echo "nav-tab-active"; } ?>" href="admin.php?page=Extensions"><?php _e('Manage Extensions', 'mainwp'); ?></a>
            <?php
            if (isset($extensions) && is_array($extensions))
            {
                foreach ($extensions as $extension)
                {
                    if ($extension['plugin'] == $shownPage)
                    {
                        ?>
                        <a class="nav-tab pos-nav-tab echo nav-tab-active" href="admin.php?page=<?php echo $extension['page']; ?>"><?php echo $extension['name']; ?></a>
                        <?php
                    }
                }
            }
            ?>
        </div>
        <div id="mainwp_wrap-inside">
        <?php
    }

    public static function renderFooter($shownPage, &$extensions)
    {
        ?>
        </div>
    </div>
        <?php
    }

    public static function render(&$extensions)
    {
        ?>
        <div class="mainwp_info-box" id="mainwp-ext-notice">
            <span><?php _e('To enable extensions you need to add your MainWP login info in the <a href="admin.php?page=Settings" style="text-decoration: none;">Settings Page</a>. &nbsp;&nbsp;For more help review <a href="http://docs.mainwp.com/how-to-install-mainwp-extensions/" target="_blank" style="text-decoration: none;">this document.</a>','mainwp'); ?></span>
            <span style="float: right;"><a id="mainwp-ext-dismiss" style="text-decoration: none;" href="#"><?php _e('Dismiss','mainwp'); ?></a></span>
        </div>

    <br/><br/><h2><?php printf(_n('%d Installed MainWP Extension', '%d Installed MainWP Extensions', (count($extensions) == 1 ? 1 : 2), 'mainwp'), count($extensions)); ?></h2>

    <hr/>

    <?php
    if (count($extensions) == 0)
    {
?>
            <div class="mainwp_info-box-yellow">
                <h3><?php _e('What are Extensions?', 'mainwp'); ?></h3>
                <?php _e('Extensions are specific features or tools created for the purpose of expanding the basic functionality of MainWP.', 'mainwp'); ?>
                <h3><?php _e('Why have Extensions?', 'mainwp'); ?></h3>
                <?php _e('The core of MainWP has been designed to provide the functions most needed by our users and minimize code bloat.  Extensions offer custom functions and features so that each user can tailor their MainWP to their specific needs.', 'mainwp'); ?>
                <p><a href="http://extensions.mainwp.com/"><?php _e('Download your first extension now.', 'mainwp'); ?></a></p>
            </div>
<?php
    }
    else
    {
?>
    <a class="mainwp_action left mainwp_action_down" href="#" id="mainwp-extensions-expand"><?php _e('Expand', 'mainwp'); ?></a><a class="mainwp_action right" href="#" id="mainwp-extensions-collapse"><?php _e('Collapse', 'mainwp'); ?></a>
    <div style="float: right; padding-right: 2em;"><a href="#" class="button mainwp-extensions-disable-all"><?php _e('Disable All', 'mainwp'); ?></a> <a href="#" class="button-primary mainwp-extensions-enable-all"><?php _e('Enable All', 'mainwp'); ?></a></div>
<div id="mainwp-extensions-list">
        <?php
    if (isset($extensions) && is_array($extensions))
    {
        foreach ($extensions as $extension)
        {
            $active = MainWPExtensions::isExtensionEnabled($extension['plugin']);
?>

        <div class="mainwp-extensions-childHolder" extension_slug="<?php echo $extension['slug']; ?>">
            <table style="width: 100%">
                <td class="mainwp-extensions-childIcon">
                    <?php
                    if (isset($extension['iconURI']) && ($extension['iconURI'] != ''))
                    {
                        ?><img title="<?php echo $extension['name']; ?>" src="<?php echo $extension['iconURI']; ?>" class="mainwp-extensions-img large <?php echo ($active ? '' : 'mainwp-extension-icon-desaturated'); ?>" /><?php
                    }
                    else
                    {
                        ?><img title="MainWP Placeholder" src="<?php echo plugins_url('images/extensions/placeholder.png', dirname(__FILE__)); ?>" class="mainwp-extensions-img large <?php echo ($active ? '' : 'mainwp-extension-icon-desaturated'); ?>" /><?php
                    }
?>
                </td>
                <td valign="top">
                    <table style="width: 100%">
                        <tr>
                            <td class="mainwp-extensions-childName"><?php echo $extension['name']; ?></td>
                            <td class="mainwp-extensions-childVersion">V. <?php echo $extension['version']; ?></td>
                            <td class="mainwp-extensions-childActions">
                                <?php if ($active) { ?>
                                    <a href="#" class="button mainwp-extensions-disable"><?php _e('Disable','mainwp'); ?></a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                    <img src="<?php echo plugins_url('images/extensions/unlock.png', dirname(__FILE__)); ?>" title="Activated" />
									<?php if (isset($extension['direct_page']) && !empty($extension['direct_page'])) { ?>
                                        <a href="<?php echo admin_url('admin.php?page='.$extension['direct_page']); ?>"><img src="<?php echo plugins_url('images/extensions/settings.png', dirname(__FILE__)); ?>" title="Settings" /></a>
                                    <?php } else if (isset($extension['callback'])) { ?>
                                        <a href="<?php echo admin_url('admin.php?page='.$extension['page']); ?>"><img src="<?php echo plugins_url('images/extensions/settings.png', dirname(__FILE__)); ?>" title="Settings" /></a>
                                    <?php } else { ?>
                                        <img src="<?php echo plugins_url('images/extensions/settings-freeze.png', dirname(__FILE__)); ?>" title="Settings" />
                                    <?php } ?>
                                    <img src="<?php echo plugins_url('images/extensions/trash-freeze.png', dirname(__FILE__)); ?>" title="Delete" />
                                <?php } else {
                                    $apilink = '';
                                    $locked = false;
                                    if (isset($extension['mainwp']) && ($extension['mainwp'] == true))
                                    {
                                        //MainWP plugin, check if it requires authentication
                                        if (isset($extension['api']))
                                        {
                                            $apilink = admin_url('admin.php?page=Settings');
                                            //plugin locked (api not valid)
                                            $locked = (MainWPAPISettings::testAPIs($extension['api']) != 'VALID');
                                        }
                                    }
                                    else
                                    {
                                        //Third party plugin, check if it requires authentication
                                        if (isset($extension['apilink']))
                                        {
                                            $apilink = $extension['apilink'];
                                            //plugin locked
                                            $locked = (isset($extension['locked']) && ($extension['locked'] == true));
                                        }
                                    }
                                    ?>
                                    <button class="button-primary mainwp-extensions-enable" <?php echo ($locked ? 'disabled' : ''); ?>><?php _e('Enable','mainwp'); ?></button>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                    <?php if ($apilink != '') { ?>
                                    <a href="<?php echo $apilink; ?>"><img src="<?php echo plugins_url('images/extensions/'.(!$locked ? 'un' : '') . 'lock.png', dirname(__FILE__)); ?>" title="Not Activated" /></a>
                                    <?php } else { ?>
                                    <img src="<?php echo plugins_url('images/extensions/unlock.png', dirname(__FILE__)); ?>" title="Activated" /></a>
                                    <?php }?>
                                    <?php if (isset($extension['callback'])) { ?>
                                        <a href="<?php echo admin_url('admin.php?page='.$extension['page']); ?>"><img src="<?php echo plugins_url('images/extensions/settings.png', dirname(__FILE__)); ?>" title="Settings" /></a>
                                    <?php } else { ?>
                                        <img src="<?php echo plugins_url('images/extensions/settings-freeze.png', dirname(__FILE__)); ?>" title="Settings" />
                                    <?php } ?>
                                    <a href="#" class="mainwp-extensions-trash"><img src="<?php echo plugins_url('images/extensions/trash.png', dirname(__FILE__)); ?>" title="Delete" /></a>
                                <?php } ?>
                            </td>
                        </tr>
                        <tr class="mainwp-extensions-extra mainwp-extension-description"><td colspan="3"><?php echo preg_replace('/\<cite\>.*\<\/cite\>/', '', $extension['description']); ?></td></tr>
                        <tr class="mainwp-extensions-extra"><td colspan="3"><br/><?php printf(__('By %s', 'mainwp'), $extension['author']); ?></td></tr>
                    </table>
                </td>
            </table>
        </div>

        <?php
        }
    }
        ?>
</div>
        <?php
        }
    }
}