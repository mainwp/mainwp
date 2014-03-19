<?php

class MainWPExtensionsWidget {
	
	public static function getClassName() {
		return __CLASS__;
	}

	public static $extensions;
	public static $extensionsLoaded = false;

	public static function getName() {
		return __('Extensions','mainwp');
	}

	public static function render() {

        $currentExtensions = (self::$extensionsLoaded ? self::$extensions : get_option('mainwp_extensions'));

		if (count($currentExtensions) == 0)
        {
            ?>
            <span class="mainwp-no-extensions">
                <div class="mainwp_info-box-yellow"><?php _e('You have no installed extensions.','mainwp'); ?></div><br/>
                <div style="text-align: center"><a href="http://extensions.mainwp.com/" target="_blank" class="button button-hero button-primary"><?php _e('Add an Extension Today', 'mainwp'); ?></a></div><br/>
                <h3 style="color: #7fb100;"><?php _e('What are Extensions?','mainwp'); ?></h3>
                <p><?php _e('MainWP Extensions are specific features or tools created for the purpose of expanding the basic functionality of the MainWP plugin.', 'mainwp'); ?></p>
                <h3 style="color: #7fb100;"><?php _e('Why have Extensions?','mainwp'); ?></h3>
                <p><?php _e('The core of MainWP has been designed to provide the functions most needed by our users and minimize code bloat. Extensions offer custom functions and features so that each user can tailor their MainWP to their specific needs.', 'mainwp'); ?></p>
            </span>
            <?php
		}
		else
        {
            $showGrid = get_option('mainwp_extension_widget_view', 'grid') == 'grid';
            $showList = !$showGrid;

		  ?>
          <br />
          <div id="mainwp-extensions-widget-grid" <?php echo (!$showGrid ? "style='display:none;'" : ''); ?>>
            <?php
        	    foreach ($currentExtensions as $extension) {
        	    	 $active = MainWPExtensions::isExtensionEnabled($extension['plugin']); 
        	    	?>
        	    	<span class="mainwp-widget-extensions">
                    <?php
                    if (isset($extension['iconURI']) && ($extension['iconURI'] != ''))
                    {
                        ?><a href="<?php echo($active ? admin_url('admin.php?page='.$extension['page']) : '') ?>" style="<?php echo($active ? '' : 'pointer-events: none;') ?>"><img title="<?php echo $extension['name']; ?>" src="<?php echo $extension['iconURI']; ?>" class="mainwp-widget-icon <?php echo ($active ? '' : 'mainwp-extension-icon-desaturated'); ?>" /></a><?php
                    }
                    else
                    {
                        ?><a href="<?php echo($active ? admin_url('admin.php?page='.$extension['page']) : '') ?>" style="<?php echo($active ? '' : 'pointer-events: none;') ?>"><img title="MainWP Placeholder" src="<?php echo plugins_url('images/extensions/placeholder.png', dirname(__FILE__)); ?>" class="mainwp-widget-icon <?php echo ($active ? '' : 'mainwp-extension-icon-desaturated'); ?>" /></a><?php
                    }
                    ?>
                    <h4><a href="<?php echo($active ? admin_url('admin.php?page='.$extension['page']) : '') ?>" style="<?php echo($active ? '' : 'pointer-events: none;') ?>"><?php echo $extension['name']?></a></h4>
                    </span>
                    <?php
        	    }
        	    ?>
           </div><div style="clear: both"></div>

          <table id="mainwp-extensions-widget-list" <?php echo (!$showList ? "style='display:none;'" : ''); ?>>
            <tbody>
                <?php
                   foreach ($currentExtensions as $extension) {
                   $active = MainWPExtensions::isExtensionEnabled($extension['plugin']);
                   ?>
                     <tr class="mainwp-widget-extensions-list mainwp-extensions-childHolder" extension_slug="<?php echo $extension['slug']; ?>">
                       <?php
                         if (isset($extension['iconURI']) && ($extension['iconURI'] != ''))
                           {
                             ?>
                             <td><a href="<?php echo($active ? admin_url('admin.php?page='.$extension['page']) : '') ?>" style="<?php echo($active ? '' : 'pointer-events: none;') ?>"><img title="<?php echo $extension['name']; ?>" src="<?php echo $extension['iconURI']; ?>" class="mainwp-widget-icon-list <?php echo ($active ? '' : 'mainwp-extension-icon-desaturated'); ?>" /></a></td><td class="mainwp-extension-widget-title-list"><a href="<?php echo($active ? admin_url('admin.php?page='.$extension['page']) : '') ?>" style="<?php echo($active ? '' : 'pointer-events: none;') ?>"><?php echo $extension['name']?></a></td><td class="mainwp-extension-widget-version"><?php echo $extension['version']; ?></td>
                             <td class="mainwp-extension-widget-button">
                                <?php if ($active) { ?>
                                   <a href="#" class="button mainwp-extensions-disable"><?php _e('Disable','mainwp'); ?></a>
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
                                    <button class="button-primary mainwp-extensions-enable" <?php echo ($locked ? 'disabled' : ''); ?>><?php _e('Enable','mainwp'); ?></button>
                                    </td>
                                    <?php
                                           }
                           }
                           else
                           {
                           ?>
                           <td><a href="<?php echo($active ? admin_url('admin.php?page='.$extension['page']) : '') ?>" style="<?php echo($active ? '' : 'pointer-events: none;') ?>"><img title="MainWP Placeholder" src="<?php echo plugins_url('images/extensions/placeholder.png', dirname(__FILE__)); ?>" class="mainwp-widget-icon-list <?php echo ($active ? '' : 'mainwp-extension-icon-desaturated'); ?>" /></a></td><td class="mainwp-extension-widget-title-list"><a href="<?php echo($active ? admin_url('admin.php?page='.$extension['page']) : '') ?>" style="<?php echo($active ? '' : 'pointer-events: none;') ?>"><?php echo $extension['name']?></a></td><td class="mainwp-extension-widget-version"><?php echo $extension['version']; ?></td>
                           <td class="mainwp-extension-widget-button">
                           <?php if ($active) { ?>
                              <a href="#" class="button mainwp-extensions-disable"><?php _e('Disable','mainwp'); ?></a>
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
                                <button class="button-primary mainwp-extensions-enable" <?php echo ($locked ? 'disabled' : ''); ?>><?php _e('Enable','mainwp'); ?></button>
                                <?php
                                }
                            ?>
                        </td>
                        <?php
                    }
                ?>
                    </tr>
                <?php
            }
            ?>
            </tbody>
            </table>
            <div style="clear: both; text-align: left; font-size: 12px; padding: .5em; border-top: 1px Solid #dfdfdf;"><a href="http://extensions.mainwp.com/" target="_blank" class="button mainwp-upgrade-button"><?php _e('GET MORE EXTENSIONS', 'mainwp'); ?></a><span style="float: right;"><a href="#" class="mainwp-extension-widget-switch-grid" <?php echo (!$showList ? "style='display:none;'" : ''); ?>><?php _e('Show Grid View','mainwp'); ?></a><a href="#" class="mainwp-extension-widget-switch-list" <?php echo (!$showGrid ? "style='display:none;'" : ''); ?>><?php _e('Show List View','mainwp'); ?></a></span></div><?php
        }
	}

    public static function changeDefaultView()
    {
        if (!isset($_POST['view'])) throw new Exception(__('Invalid Request'));

        if ($_POST['view'] == 'list') update_option('mainwp_extension_widget_view', 'list');
        else update_option('mainwp_extension_widget_view', 'grid');

        return array('result' => 'SUCCESS');
    }
}
?>