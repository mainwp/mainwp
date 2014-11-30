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
        <a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img
                src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50"
                alt="MainWP"/></a>
        <img src="<?php echo plugins_url('images/icons/mainwp-extensions.png', dirname(__FILE__)); ?>"
             style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Extensions" height="32"/>
        <h2><?php _e('Extensions', 'mainwp'); ?></h2><div style="clear: both;"></div><br/><br/>
        <?php if ($shownPage === '') { ?>
        <div id="mainwp-extensions-categories-menu" class="postbox">
                <div class="mainwp-inside"><span id="mainwp-extensions-menu-title"><?php _e('Get MainWP Extensions','mainwp');?></span><span style="float: right;"><a href="https://mainwp.com/member/signup/mainwpsignup" target="_blank" class="mainwp-upgrade-button button-primary button"><?php _e('Create MainWP Account','mainwp'); ?></a></span></div>
                <div style="border-bottom: 1px Solid #e5e5e5;"></div>
                <div class="mainwp-inside mainwp-align-center" style="clear: both;">
                <div id="mainwp-extensions-cat-menu">
                    <ul id="mainwp-extensions-menu-cat-list">
                        <li class="mainwp-extensions-menu-item mainwp-category-1"><a href="https://extensions.mainwp.com/product-category/mainwp-extensions/administrative/"><?php _e('Administrative','mainwp'); ?></a></li>
                        <li class="mainwp-extensions-menu-item mainwp-category-2"><a href="https://extensions.mainwp.com/product-category/mainwp-extensions/content/"><?php _e('Content','mainwp'); ?></a></li>
                        <li class="mainwp-extensions-menu-item mainwp-category-3"><a href="https://extensions.mainwp.com/product-category/mainwp-extensions/visitor-data/"><?php _e('Visitor Data','mainwp'); ?></a></li>
                        <li class="mainwp-extensions-menu-item mainwp-category-4"><a href="https://extensions.mainwp.com/product-category/mainwp-extensions/free/"><?php _e('Free Extensions','mainwp'); ?></a></li>
                        <li class="mainwp-extensions-menu-item mainwp-category-5"><a href="https://extensions.mainwp.com/shop/"><?php _e('All Extensions','mainwp'); ?></a></li>
                    </ul>
                </div>
                    <div style="clear: both;"></div>
                </div>
        </div>        
       
<?php     
    
    $loader_url = plugins_url('images/loader.gif', dirname(__FILE__));      
    if (mainwp_current_user_can("dashboard", "manage_extensions")) {     
?>   
    <div class="postbox">
           <div class="mainwp-inside"></div>                
           <div class="mainwp-inside" style="clear: both;">
<?php 
        $username = $password = "";
        $checked_save = false;
        if (get_option("mainwp_extensions_api_save_login") == true) {            
            $enscrypt_u = get_option('mainwp_extensions_api_username');
            $enscrypt_p = get_option('mainwp_extensions_api_password');
            $username = !empty($enscrypt_u) ? MainWPApiManagerPasswordManagement::decrypt_string($enscrypt_u) : "";
            $password = !empty($enscrypt_p) ? MainWPApiManagerPasswordManagement::decrypt_string($enscrypt_p) : "";             
            $checked_save = true;
        }  
?>
            <div class="api-grabbing-fields">
                <ul><li><?php _e("MainWP Extensions Login:", "mainwp"); ?></li>
                    <li><input type="text" class="input username" placeholder="<?php echo __("Username", "mainwp"); ?>" value="<?php echo $username; ?>"/>
                        <input type="password" class="input passwd" placeholder="<?php echo __("Password", "mainwp"); ?>" value="<?php echo $password; ?>"/>
                        <p><input type="checkbox" <?php echo $checked_save ? 'checked="checked"' : ""; ?> name="extensions_api_savemylogin_chk" id="extensions_api_savemylogin_chk"><label><?php _e("Save API login", "mainwp"); ?></label></span></p>
                        <p><span class="mainwp_loading"><img src="<?php echo $loader_url; ?>"/></span></p>
                        <p><span class="status hidden"></span></p>
                    </li>
                    <li>
                        <input type="button" class="mainwp-upgrade-button button-primary" id="mainwp-extensions-grabkeys" value="<?php _e("Grab Api Keys", "mainwp"); ?>">  
                        <input type="button" class="mainwp-upgrade-button button-primary" id="mainwp-extensions-bulkinstall" value="<?php _e("Install purchased extensions", "mainwp"); ?>">                            
                    </li>
                </ul>
                <div style="clear: both;"></div>
            </div>
            <div style="clear: both;"></div>
        </div>
    </div> 
       <?php } ?>         
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
        
    $loader_url = plugins_url('images/loader.gif', dirname(__FILE__));            
    if (mainwp_current_user_can("dashboard", "manage_extensions")) { ?>
        <div class="mainwp_info-box" id="mainwp-ext-notice">
            <span><?php _e('To enable extensions you need to add your MainWP login info in the <a href="admin.php?page=Settings" style="text-decoration: none;">Settings Page</a>. &nbsp;&nbsp;For more help review <a href="http://docs.mainwp.com/how-to-install-mainwp-extensions/" target="_blank" style="text-decoration: none;">this document.</a>','mainwp'); ?></span>
            <span style="float: right;"><a id="mainwp-ext-dismiss" style="text-decoration: none;" href="#"><?php _e('Dismiss','mainwp'); ?></a></span>
        </div>
        <?php } ?>

    <br/><br/><h2><?php printf(_n('%d Installed MainWP Extension', '%d Installed MainWP Extensions', (count($extensions) == 1 ? 1 : 2), 'mainwp'), count($extensions)); ?></h2>

    <hr/>

<div id="mainwp-extensions-wrap">    
    <?php if (count($extensions) == 0)  { ?>
            <div class="mainwp_info-box-yellow">
                <h3><?php _e('What are Extensions?', 'mainwp'); ?></h3>
                <?php _e('Extensions are specific features or tools created for the purpose of expanding the basic functionality of MainWP.', 'mainwp'); ?>
                <h3><?php _e('Why have Extensions?', 'mainwp'); ?></h3>
                <?php _e('The core of MainWP has been designed to provide the functions most needed by our users and minimize code bloat.  Extensions offer custom functions and features so that each user can tailor their MainWP to their specific needs.', 'mainwp'); ?>
                <p><a href="https://extensions.mainwp.com/"><?php _e('Download your first extension now.', 'mainwp'); ?></a></p>
            </div>
<?php  } ?>
<?php     
    if (count($extensions) > 0)
    {            
?>
<a class="mainwp_action left mainwp_action_down" href="#" id="mainwp-extensions-expand"><?php _e('Expand', 'mainwp'); ?></a><a class="mainwp_action right" href="#" id="mainwp-extensions-collapse"><?php _e('Collapse', 'mainwp'); ?></a>  
<?php if (mainwp_current_user_can("dashboard", "manage_extensions")) { ?>
    <div style="float: right;"><a href="#" class="button mainwp-extensions-disable-all"><?php _e('Disable All', 'mainwp'); ?></a> <a href="#" class="button-primary mainwp-extensions-enable-all"><?php _e('Enable All', 'mainwp'); ?></a> <a href="<?php echo admin_url( 'plugin-install.php?tab=upload' ); ?>" class="mainwp-upgrade-button button-primary button"><?php _e('Install New Extension', 'mainwp'); ?></a></div>
<?php } ?>
<div id="mainwp-extensions-list">
        <?php       
    if (isset($extensions) && is_array($extensions))
    {
        foreach ($extensions as $extension)
        {
            if (!mainwp_current_user_can("extension", dirname($extension['slug'])))
               continue;
            $active = MainWPExtensions::isExtensionEnabled($extension['plugin']);
            
            $queue_status = "";
            
            if (isset($extension['apiManager']) && $extension['apiManager']) { 
                $queue_status = 'status="queue"';
            }
?>
        <div class="mainwp-extensions-childHolder" extension_slug="<?php echo $extension['slug']; ?>" <?php echo $queue_status; ?> license-status="<?php echo $active ? "activated" : "deactivated"; ?>">
            <table style="width: 100%">
                <td class="mainwp-extensions-childIcon">
                    <?php
                    if (isset($extension['iconURI']) && ($extension['iconURI'] != ''))
                    {
                        ?><img title="<?php echo $extension['name']; ?>" src="<?php echo MainWPUtility::removeHttpPrefix($extension['iconURI']); ?>" class="mainwp-extensions-img large <?php echo ($active ? '' : 'mainwp-extension-icon-desaturated'); ?>" /><?php
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
                            <td class="mainwp-extensions-childName">
                                <?php 
                                    if (isset($extension['direct_page']) && !empty($extension['direct_page'])) { 
                                        ?>
                                        <a href="<?php echo admin_url('admin.php?page='.$extension['direct_page']); ?>" style="text-decoration: none;">
                                            <?php echo $extension['name']; ?>
                                        </a>
                                <?php } 
                                    else if (isset($extension['callback'])) { 
                                ?>
                                        <a href="<?php echo admin_url('admin.php?page='.$extension['page']); ?>" style="text-decoration: none;">    
                                            <?php echo $extension['name']; ?>
                                        </a>
                                <?php } 
                                    else {
                                            echo $extension['name'];
                                } ?>
                            </td>
                            <td class="mainwp-extensions-childVersion">V. <?php echo $extension['version']; ?></td>
                            <td class="mainwp-extensions-childActions">
                                <?php if ($active) { ?>
                                    <?php if (isset($extension['apiManager']) && $extension['apiManager'] && !empty($extension['api_key'])) { ?>
                                        <a href="javascript:void(0)" class="api-status activated" ><?php _e('Activated','mainwp'); ?></a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                        <?php if (mainwp_current_user_can("dashboard", "manage_extensions")) { ?>                                    
                                        <a href="#" class="mainwp-extensions-api-activation"><img src="<?php echo plugins_url('images/extensions/unlock.png', dirname(__FILE__)); ?>" title="Activated" /></a>
                                        <?php }  ?>
                                    <?php } else {  ?>
                                        <?php if (mainwp_current_user_can("dashboard", "manage_extensions")) { ?>
                                            <a href="#" class="button mainwp-extensions-disable"><?php _e('Disable','mainwp'); ?></a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                            <img src="<?php echo plugins_url('images/extensions/unlock.png', dirname(__FILE__)); ?>" title="Activated" />
                                        <?php } //  ?>
                                    <?php }  ?>
                                    <?php if (isset($extension['direct_page']) && !empty($extension['direct_page'])) { ?>
                                        <a href="<?php echo admin_url('admin.php?page='.$extension['direct_page']); ?>"><img src="<?php echo plugins_url('images/extensions/settings.png', dirname(__FILE__)); ?>" title="Settings" /></a>
                                    <?php } else if (isset($extension['callback'])) { ?>
                                        <a href="<?php echo admin_url('admin.php?page='.$extension['page']); ?>"><img src="<?php echo plugins_url('images/extensions/settings.png', dirname(__FILE__)); ?>" title="Settings" /></a>
                                    <?php } else { ?>
                                        <img src="<?php echo plugins_url('images/extensions/settings-freeze.png', dirname(__FILE__)); ?>" title="Settings" />
                                    <?php } ?>
                                    <?php if (mainwp_current_user_can("dashboard", "manage_extensions")) { ?>
                                    <img src="<?php echo plugins_url('images/extensions/trash-freeze.png', dirname(__FILE__)); ?>" title="Delete" />
                                    <?php } //  ?>
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
                                    <?php if (isset($extension['apiManager']) && $extension['apiManager'] && !empty($extension['api_key'])) { ?>
                                        <a href="javascript:void(0)" class="api-status deactivated" title="Not Activated"><?php _e('Deactivated','mainwp'); ?></a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                        <?php if (mainwp_current_user_can("dashboard", "manage_extensions")) { ?>                                                                               
                                            <a href="#" class="mainwp-extensions-api-activation"><img class="image-api-status" src="<?php echo plugins_url('images/extensions/lock.png', dirname(__FILE__)); ?>" title="Not Activated" /></a>                                    
                                        <?php } ?>
                                    <?php } else { ?>
                                        <?php if (mainwp_current_user_can("dashboard", "manage_extensions")) { ?>
                                        <button class="button-primary mainwp-extensions-enable" <?php echo ($locked ? 'disabled' : ''); ?>><?php _e('Enable','mainwp'); ?></button>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                                        <?php if ($apilink != '') { ?>
                                        <a href="<?php echo $apilink; ?>"><img src="<?php echo plugins_url('images/extensions/'.(!$locked ? 'un' : '') . 'lock.png', dirname(__FILE__)); ?>" title="Not Activated" /></a>
                                        <?php } else { ?>
                                        <img src="<?php echo plugins_url('images/extensions/unlock.png', dirname(__FILE__)); ?>" title="Activated" /></a>
                                        <?php }?>
                                        <?php } ?>                                        
                                    <?php } ?>
                                        
                                    <?php if (isset($extension['callback'])) { ?>
                                        <a href="<?php echo admin_url('admin.php?page='.$extension['page']); ?>"><img src="<?php echo plugins_url('images/extensions/settings.png', dirname(__FILE__)); ?>" title="Settings" /></a>
                                    <?php } else { ?>
                                        <img src="<?php echo plugins_url('images/extensions/settings-freeze.png', dirname(__FILE__)); ?>" title="Settings" />
                                    <?php } ?>
                                    <?php if (mainwp_current_user_can("dashboard", "manage_extensions")) { ?>
                                    <a href="#" class="mainwp-extensions-trash"><img src="<?php echo plugins_url('images/extensions/trash.png', dirname(__FILE__)); ?>" title="Delete" /></a>
                                    <?php } ?>
                                <?php } ?>
                            </td>
                        </tr>
                        <tr class="mainwp-extensions-extra mainwp-extension-description"><td colspan="3"><?php echo preg_replace('/\<cite\>.*\<\/cite\>/', '', $extension['description']); ?><br/><br/></td></tr>
                        <tr class="mainwp-extensions-links">
                            <td colspan="3">
                                <?php printf(__('By %s', 'mainwp'), $extension['author']); ?>
                                <?php echo (isset($extension['DocumentationURI']) && !empty($extension['DocumentationURI'])) ? ' | <a href="' . $extension['DocumentationURI'] . '" target="_blank" title="' . __("Documentation", "mainwp") . '">' . __("Documentation", "mainwp") . '</a>' : ""; ?>
                                <?php echo (isset($extension['SupportForumURI']) && !empty($extension['SupportForumURI'])) ? ' | <a href="' . $extension['SupportForumURI'] . '" target="_blank" title="' . __("Support Forum", "mainwp") . '">' . __("Support Forum", "mainwp") . '</a>' : ""; ?>
                                <?php if (isset($extension['apiManager']) && $extension['apiManager']) { ?>
                                    <?php echo ' | <a href="#" class="mainwp-extensions-api-activation" >' . __('Enter Activation API') . '</a>'; ?>
                                <?php } ?>
                            </td></tr>
                        <?php if (isset($extension['apiManager']) && $extension['apiManager']) { ?>
                        <tr class="mainwp-extensions-api-row">
                            <td colspan="3">
                                <div class="api-row-div">
                                    <span>
                                    <input type="text" class="input api_key" placeholder="<?php echo __("API License Key", "mainwp"); ?>" value="<?php echo $extension["api_key"]; ?>"/>
                                    <input type="text" class="input api_email" placeholder="<?php echo __("API License Email", "mainwp"); ?>" value="<?php echo $extension["activation_email"]; ?>"/>
                                    <input type="button" class="button-primary mainwp-extensions-activate" value="<?php _e("Activate", "mainwp"); ?>">                            
                                    <span class="mainwp_loading"><img src="<?php echo $loader_url; ?>"/></span>
                                    </span>
                                    <span style="float:right">
                                    <?php _e("Deactivate License Key", "mainwp"); ?>
                                    <input type="checkbox" class="mainwp-extensions-deactivate-chkbox" <?php echo $extension['deactivate_checkbox'] == 'on' ? "checked" : ""; ?>>
                                    <input type="button" class="button-primary mainwp-extensions-deactivate" value="<?php _e("Deactivate", "mainwp"); ?>">                                
                                    </span>
                                </div>
                                <span class="activate-api-status hidden"></span>
                            </td>
                        </tr>
                        <?php } ?>
                    </table>
                </td>
            </table>
        </div>

        <?php
        }
    }
        ?>
</div>
</div>
        <?php
        }
    }
}