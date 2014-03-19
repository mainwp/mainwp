<?php
class MainWPUI
{
	public static function select_sites_box( $title = "", $type = 'checkbox', $show_group = true, $show_select_all = true, $class = '', $style = '', &$selected_websites = array(), &$selected_groups = array())
	{
		?>
		<div class="mainwp_select_sites_box<?php if ( $class ) echo " $class"; ?>"<?php if ( $style ) echo ' style="'.$style.'"'; ?>>
            <div class="postbox">
                <h3 class="box_title mainwp_box_title"><?php echo ( $title ) ? $title : translate('Select Sites', 'mainwp') ?> <div class="mainwp_sites_selectcount"><?php echo !is_array($selected_websites) ? '0' : count($selected_websites); ?></div></h3>
                <div class="inside mainwp_inside">
                    <?php self::select_sites_box_body($selected_websites, $selected_groups, $type, $show_group, $show_select_all); ?>
                </div>
            </div>
        </div>
		<?php
	}
        

    public static function select_sites_box_body(&$selected_websites = array(), &$selected_groups = array(), $type = 'checkbox', $show_group = true, $show_select_all = true, $updateQty = false)
    {
        $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
        $groups = MainWPDB::Instance()->getNotEmptyGroups();
        ?>
        <input type="hidden" name="select_by" id="select_by" value="<?php echo (count($selected_groups) > 0 ? 'group' : 'site'); ?>" />
        <?php if ( $show_select_all ): ?>
        <div style="float:right"><?php _e('Select: ','mainwp'); ?><a href="#" onClick="return mainwp_ss_select(true)"><?php _e('All','mainwp'); ?></a> | <a href="#" onClick="return mainwp_ss_select(false)"><?php _e('None','mainwp'); ?></a></div>
        <?php endif ?>
        <?php if ( $show_group ): ?>
        <div id="mainwp_ss_site_link" <?php echo (count($selected_groups) > 0 ? 'style="display: inline-block;"' : ''); ?>><a href="#" onClick="return mainwp_ss_select_by('site')"><?php _e('By site','mainwp'); ?></a></div><div id="mainwp_ss_site_text" <?php echo (count($selected_groups) > 0 ? 'style="display: none;"' : ''); ?>><?php _e('By site','mainwp'); ?></div> | <div id="mainwp_ss_group_link" <?php echo (count($selected_groups) > 0 ? 'style="display: none;"' : ''); ?>><a href="#" onClick="return mainwp_ss_select_by('group')"><?php _e('By group','mainwp'); ?></a></div><div id="mainwp_ss_group_text" <?php echo (count($selected_groups) > 0 ? 'style="display: inline-block;"' : ''); ?>><?php _e('By group','mainwp'); ?></div>
        <?php endif ?>
        <div id="selected_sites" <?php echo (count($selected_groups) > 0 ? 'style="display: none;"' : ''); ?>>
            <?php
            if (!$websites) {
                echo __('<p>No websites have been found.</p>','mainwp');
            }
            else
            {
                while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                {
                    $selected = ($selected_websites == 'all' || in_array($website->id, $selected_websites));

                    echo '<div class="mainwp_selected_sites_item '.($selected ? 'selected_sites_item_checked' : '').'"><input onClick="mainwp_site_select(this)" type="'.$type.'" name="' . ( $type == 'radio' ? 'selected_site' : 'selected_sites[]' ) . '" siteid="' . $website->id . '" value="' . $website->id . '" id="selected_sites_' . $website->id . '" '.($selected ? 'checked="true"' : '').'/> <label for="selected_sites_' . $website->id . '">' . $website->name . '<span class="url">' . $website->url . '</span>' . '</label></div>';
                }
                @MainWPDB::free_result($websites);
            }
            ?>
        </div>
        <input id="selected_sites-filter" style="margin-top: .5em" type="text" value="" placeholder="Type here to filter sites" <?php echo (count($selected_groups) > 0 ? 'style="display: none;"' : ''); ?> />
        <?php if ( $show_group ): ?>
        <div id="selected_groups" <?php echo (count($selected_groups) > 0 ? 'style="display: block;"' : ''); ?>>
            <?php
            if (count($groups) == 0) {
                echo __('<p>No groups with entries have been found.</p>','mainwp');
            }
            foreach ($groups as $group)
            {
                $selected = in_array($group->id, $selected_groups);

                echo '<div class="mainwp_selected_groups_item '.($selected ? 'selected_groups_item_checked' : '').'"><input onClick="mainwp_group_select(this)" type="'.$type.'" name="' . ( $type == 'radio' ? 'selected_group' : 'selected_groups[]' ) . '" value="' . $group->id . '" id="selected_groups_' . $group->id . '" '.($selected ? 'checked="true"' : '').'/> <label for="selected_groups_' . $group->id . '">' . $group->name . '</label></div>';
            }
            ?>
        </div>
        <input id="selected_groups-filter"  style="margin-top: .5em" type="text" value="" placeholder="Type here to filter groups" <?php echo (count($selected_groups) > 0 ? 'style="display: block;"' : ''); ?> />
        <?php endif ?>
        <?php
        if ($updateQty)
        {
            echo '<script>jQuery(document).ready(function () {jQuery(".mainwp_sites_selectcount").html('.(!is_array($selected_websites) ? '0' : count($selected_websites)).');});</script>';
        }
    }
         
   public static function select_categories_box($params)
   {
            $title = $params['title'];
            $type = isset($params['type']) ? $params['type']  : 'checkbox';
            $show_group = isset($params['show_group']) ?  $params['show_group'] : true;
            $selected_by = !empty($params['selected_by']) ?  $params['selected_by'] : 'site';
            $class = isset($params['class']) ? $params['class'] : '';
            $style = isset($params['style']) ? $params['style'] : '';
            $selected_cats = is_array($params['selected_cats']) ? $params['selected_cats']  : array();
            $prefix = $params['prefix'];
            if ($type == 'checkbox')
                $cbox_prefix = '[]';

            $websites = MainWPDB::Instance()->query(MainWPDB::Instance()->getSQLWebsitesForCurrentUser());
            $groups = MainWPDB::Instance()->getNotEmptyGroups();
            ?>
                    <div class="mainwp_select_sites_box mainwp_select_categories <?php if ( $class ) echo " $class"; ?>"<?php if ( $style ) echo ' style="'.$style.'"'; ?>>
                <div class="postbox">
                    <h3 class="box_title mainwp_box_title"><?php echo ( $title ) ? $title : _e("Select Categories", 'mainwp') ?></h3>
                    <div class="inside mainwp_inside ">
                        <input type="hidden" name="select_by_<?php echo $prefix; ?>" class="select_by" value="<?php echo $selected_by?>" />
                        <?php if ( $show_group): ?>
           <div class="mainwp_ss_site_link" <?php echo ($selected_by == 'group' ? 'style="display: inline-block;"' : ''); ?>><a href="#" onClick="return mainwp_ss_cats_select_by(this, 'site')"><?php _e('By site','mainwp'); ?></a></div>
            <div class="mainwp_ss_site_text" <?php echo ($selected_by == 'group' ? 'style="display: none;"' : ''); ?>><?php _e('By site','mainwp'); ?></div> | <div class="mainwp_ss_group_link" <?php echo ($selected_by == 'group'  ? 'style="display: none;"' : ''); ?>><a href="#" onClick="return mainwp_ss_cats_select_by(this, 'group')"><?php _e('By group','mainwp'); ?></a></div>
            <div class="mainwp_ss_group_text" <?php echo ($selected_by == 'group' ? 'style="display: inline-block;"' : ''); ?>><?php _e('By group','mainwp'); ?></div>
           <?php endif ?>
                        <div class="selected_sites" <?php echo ($selected_by == 'group' ? 'style = "display: none"' : ''); ?>>
                        <?php
                                if (!$websites) {
                                    echo __('<p>No websites have been found.</p>','mainwp');
                                }
                                else
                                {
                                    while ($websites && ($website = @MainWPDB::fetch_object($websites)))
                                            {
                                                $cats = isset($selected_cats[$website->id]) && is_array($selected_cats[$website->id]) ? $selected_cats[$website->id] : array();
                                        ?>
                                        <div class="categories_site_<?php echo $website->id;?>">
                                            <div class="categories_list_header">
                                                    <div><?php echo $website->name?></div>
                                                    <label><span class="url"><?php echo $website->url ?></span></label>
                                            </div>
                                             <div class="categories_list_<?php echo $website->id;?>">
                                                <?php
                                                if (count($cats) == 0) {
                                                    echo '<p>No selected categories.</p>';
                                                }
                                                else
                                                {
                                                        foreach ($cats as $cat)
                                                        {
                                                            echo '<div class="mainwp_selected_sites_item  selected_sites_item_checked">
                                                                <input type="'.$type.'" name="sites_selected_cats_' . $prefix . $cbox_prefix . '" value="' . $website->id  .",".  $cat['term_id'] . "," . $cat['name'] . '" id="sites_selected_cats_'. $prefix . $cat['term_id'] . '" checked="true" /><label>'.$cat['name'] . '</label>
                                                                    </div>';
                                                        }
                                                }
                                                        ?>
                                                  </div>
                                                    <div class="mainwp_categories_list_bottom">
                                                       <div style="float:right">
                                                           <a href="#" rel="<?php echo $prefix ?>" class="load_more_cats" onClick="return mainwp_ss_cats_more(this, <?php echo $website->id;?>, 'site')"><?php _e('Reload','mainwp'); ?></a>
                                                           <span class="mainwp_more_loading"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/></span>
                                                       </div>
                                                       <div class="clearfix"></div>
                                                   </div>
                                         </div>
                                            <?php
                                            }
                                    @MainWPDB::free_result($websites);
                                }
                                          ?>
                        </div>

                         <div class="selected_groups" <?php echo ($selected_by == 'group' ? 'style = "display: block"' : ''); ?>>
                              <?php
                                        if (count($groups) == 0) {
                                             echo __('<p>No groups with entries have been found.</p>','mainwp');
                                         }
                                            foreach ($groups as $gid=>$group)
                                            {
                                               ?>
                                                 <div class="categories_group_<?php echo $gid;?>">
                                                     <div class="mainwp_groups_list_header">
                                                                <div><?php echo $group->name?></div>
                                                        </div>
                                                    <?php
                                                    $websites = MainWPDB::Instance()->getWebsitesByGroupIds(array($gid));
                                                foreach ($websites as  $website)
                                                    {
                                                            $id = $website->id;
                                                            $cats = is_array($selected_cats[$id]) ? $selected_cats[$id] : array();

                                                    ?>
                                                    <div class="categories_site_<?php echo $id;?>">
                                                        <div class="categories_list_header">
                                                                <div><?php echo $website->name?></div>
                                                                <label><span class="url"><?php echo $website->url ?></span></label>
                                                        </div>
                                                            <div class="categories_list_<?php echo $id;?>">
                                                            <?php
                                                            if (count($cats) == 0) {
                                                                echo __('<p>No selected categories.</p>','mainwp');
                                                            }
                                                            else
                                                            {
                                                                    foreach ($cats as $cat)
                                                                    {
                                                                        echo '<div class="mainwp_selected_sites_item  selected_sites_item_checked">
                                                                            <input type="'.$type.'" name="groups_selected_cats_' . $prefix . $cbox_prefix . '" value="' . $id  .",".  $cat['term_id'] . "," . $cat['name'] . '" id="groups_selected_cats_'. $prefix . $cat['term_id'] . '" checked="true" /><label>'.$cat['name'] . '</label>
                                                                                </div>';
                                                                    }
                                                            }
                                                                    ?>
                                                                </div>
                                                                <div class="mainwp_categories_list_bottom">
                                                                    <div style="float:right">
                                                                        <a href="#" rel="<?php echo $prefix ?>" class="load_more_cats" onClick="return mainwp_ss_cats_more(this, <?php echo $id;?>, 'group')">Reload</a>
                                                                        <span class="mainwp_more_loading"><img src="<?php echo plugins_url('images/loader.gif', dirname(__FILE__)); ?>"/></span>
                                                                    </div>
                                                                   <div class="clearfix"></div>
                                                               </div>
                                                    </div>
                                                        <?php
                                                }
                                                ?>
                                                 </div>
                                                     <?php
                                            }
                                          ?>
                         </div>
            </div>
           </div>
                    </div>
          <?php
   }

	public static function submit_box( $title = "", $button = "", $name = "", $id = "", $class = "", $style = "" )
	{
		?>
		<div class="mainwp_submit_box<?php if ( $class ) echo " $class"; ?>"<?php if ( $style ) echo ' style="'.$style.'"'; ?>>
			<div class="postbox">
				<?php if ( $title ): ?>
                <h3 class="box_title mainwp_box_title"><?php echo $title ?></h3>
                <?php endif ?>
                <div class="inside mainwp_inside">
                	<input type="submit" name="<?php echo $name ?>" id="<?php echo $id ?>" class="button-primary" value="<?php echo $button ?>"  />
                </div>
           	</div>
		</div>
		<?php
	}

    public static function separator()
	{
		?>
            <div style="clear: both"></div>
		<?php
	}

    public static function renderHeader($title, $icon_url)
    {
        ?>
        <div class="wrap"><a href="http://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img
                src="<?php echo plugins_url('images/logo.png', dirname(__FILE__)); ?>" height="50" alt="MainWP"/></a>
            <img src="<?php echo $icon_url; ?>"
                 style="float: left; margin-right: 8px; margin-top: 7px ;" alt="<?php echo $title; ?>"
                 height="32"/>

            <h2><?php echo $title; ?></h2><div style="clear: both;"></div><br/>

            <div class="clear"></div>
            <div class="wrap">
                <?php
    }

    public static function renderFooter()
    {
        ?>
        </div>
    </div>
        <?php
    }

    public static function renderImage($img, $alt, $class, $height = null)
    {
        ?>
        <img src="<?php echo plugins_url($img, dirname(__FILE__)); ?>" class="<?php echo $class; ?>" alt="<?php echo $alt; ?>" <?php echo ($height == null ? '' : 'height="' . $height . '"'); ?> />
        <?php
    }
	
}



