<?php

class MainWPMetaBoxes
{
    public static function initMetaBoxes()
    {
        //Add metaboxes to bulkpost
        add_meta_box('select-sites-div', __('Select sites', 'mainwp') . '<div class="mainwp_sites_selectcount toggle">0</div>', array(&MainWPSystem::Instance()->metaboxes, 'select_sites'), 'bulkpost', 'side', 'default');
        add_meta_box('add-categories-div', __('Categories', 'mainwp'), array(&MainWPSystem::Instance()->metaboxes, 'add_categories'), 'bulkpost', 'side', 'default');
        add_meta_box('add-tags-div', __('Tags', 'mainwp'), array(&MainWPSystem::Instance()->metaboxes, 'add_tags'), 'bulkpost', 'side', 'default');
        add_meta_box('add-slug-div', __('Slug', 'mainwp'), array(&MainWPSystem::Instance()->metaboxes, 'add_slug'), 'bulkpost', 'side', 'default');

        //Add metaboxes to bulkpage
        add_meta_box('select-sites-div', __('Select sites', 'mainwp') . '<div class="mainwp_sites_selectcount toggle">0</div>', array(&MainWPSystem::Instance()->metaboxes, 'select_sites'), 'bulkpage', 'side', 'default');
        add_meta_box('add-slug-div', __('Slug', 'mainwp'), array(&MainWPSystem::Instance()->metaboxes, 'add_slug'), 'bulkpage', 'side', 'default');
    }

    function select_sites($post)
    {
        $selected_sites = unserialize(base64_decode(get_post_meta($post->ID, '_selected_sites', true)));
        if ($selected_sites == '') $selected_sites = array();

        if (isset($_REQUEST['select']))
        {
           $selected_sites = ($_REQUEST['select'] == 'all' ? 'all' : array($_REQUEST['select']));
        }
        $selected_groups = unserialize(base64_decode(get_post_meta($post->ID, '_selected_groups', true)));
        if ($selected_groups == '') $selected_groups = array();
        ?><input type="hidden" name="select_sites_nonce" id="select_sites_nonce" value="<?php echo wp_create_nonce('select_sites_' . $post->ID); ?>" /><?php


        MainWPUI::select_sites_box_body($selected_sites, $selected_groups, 'checkbox', true, true, true);
    }

    function select_sites_handle($post_id, $post_type) {
        // verify this came from the our screen and with proper authorization.
        if (!isset($_POST['select_sites_nonce']) || !wp_verify_nonce($_POST['select_sites_nonce'], 'select_sites_' . $post_id)) {
            return $post_id;
        }

        // verify if this is an auto save routine. If it is our form has not been submitted, so we dont want to do anything
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            return $post_id;

        // Check permissions
        if (!current_user_can('edit_post', $post_id))
            return $post_id;


        // OK, we're authenticated: we need to find and save the data	
        $post = get_post($post_id);
        if ($post->post_type == $post_type && isset($_POST['select_by'])) {
            //&& isset($_POST['selected_sites'])) {

            $selected_wp = array();
            if (isset($_POST['selected_sites']) && is_array($_POST['selected_sites'])) {
                foreach ($_POST['selected_sites'] as $selected) {
                    $selected_wp[] = $selected;
                }
            }
            update_post_meta($post_id, '_selected_sites', base64_encode(serialize($selected_wp)));

            $selected_group = array();
            if (isset($_POST['selected_groups']) && is_array($_POST['selected_groups'])) {
                foreach ($_POST['selected_groups'] as $selected) {
                    $selected_group[] = $selected;
                }
            }
            update_post_meta($post_id, '_selected_groups', base64_encode(serialize($selected_group)));
            update_post_meta($post_id, '_selected_by', $_POST['select_by']);

            if (($_POST['select_by'] == 'group' && count($selected_group) > 0) || ($_POST['select_by'] == 'site' && count($selected_wp) > 0))
                return $_POST['select_by'];
        }
        return $post_id;
    }

    function add_categories($post) {
        
        // depdecated, 1.0.9.2-beta
        $categories = apply_filters("mainwp_bulkpost_saved_categories", $post, array());        
        if (empty($categories) || (is_array($categories) && count($categories) == 1 && empty($categories[0]))) { // to compatible          
            if ($post) {
                $categories = base64_decode(get_post_meta($post->ID, '_categories', true));                      
                $categories = explode(",", $categories);                
            }
        }        
        
        if (!is_array($categories)) 
            $categories = array();                
        $uncat = __('Uncategorized','mainwp');
        
        ?>
        <input type="hidden" name="post_category_nonce" id="select_sites_nonce" value="<?php echo wp_create_nonce('post_category_' . $post->ID); ?>" />

    	<div id="taxonomy-category" class="categorydiv">
    		<ul id="category-tabs" class="category-tabs">
    			<li class="tabs"><a href="#category-all"><?php _e('All Categories' , 'mainwp'); ?></a></li>
    		</ul>

    		<div id="category-all" class="tabs-panel" style="display: block;">
                <ul id="categorychecklist" data-wp-lists="list:category" class="categorychecklist form-no-clear post_add_categories">
                    <?php if (!in_array($uncat, $categories)) { ?>
                          <li class="popular-category sitecategory"><label class="selectit"><input value="Uncategorized" type="checkbox" name="post_category[]"><?php _e('Uncategorized','mainwp'); ?></label></li>  
                    <?php } ?>
                    <?php foreach($categories as $cat) { 
                          if (empty($cat))
                              continue;
                          $cat_name = rawurldecode($cat);
                            ?>
                          <li class="popular-category sitecategory"><label class="selectit"><input value="<?php echo $cat; ?>" type="checkbox" checked name="post_category[]"><?php echo $cat_name; ?></label></li>
                    <?php } ?>
    			</ul>
    		</div>

            <div id="category-adder" class="wp-hidden-children">
                <h4><a id="category-add-toggle" href="#category-add" class="hide-if-no-js"><?php _e('+ Add New Category','mainwp'); ?></a></h4>
                <p id="category-add" class="category-add wp-hidden-child">
                    <label class="screen-reader-text" for="newcategory"><?php _e('Add New Category','mainwp'); ?></label>
                    <input type="text" name="newcategory" id="newcategory" class="form-required" value="<?php _e('New Category Name', 'mainwp'); ?>" aria-required="true">
                    <input type="button" id="mainwp-category-add-submit" class="button mainwp-category-add-submit" value="<?php _e('Add New Category','mainwp'); ?>">
                    <input type="hidden" id="_ajax_nonce-add-category" name="_ajax_nonce-add-category" value="<?php echo wp_create_nonce('add-category' . $post->ID); ?>">
                    <span id="category-ajax-response"></span>
                </p>
            </div>
        </div>
        <?php
    }

    function add_categories_handle($post_id, $post_type) {
        // verify this came from the our screen and with proper authorization.
        if (!isset($_POST['post_category_nonce']) || !wp_verify_nonce($_POST['post_category_nonce'], 'post_category_' . $post_id)) {
            return;
        }

        // verify if this is an auto save routine. If it is our form has not been submitted, so we dont want to do anything
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            return;

        // Check permissions
        if (!current_user_can('edit_post', $post_id))
            return;


        // OK, we're authenticated: we need to find and save the data
        $post = get_post($post_id);
        if ($post->post_type == $post_type && isset($_POST['post_category'])) {
//            update_post_meta($post_id, $saveto, base64_encode($_POST[$prefix]));
            if (isset($_POST['post_category']) && is_array($_POST['post_category']))
            {
                update_post_meta($post_id, '_categories', base64_encode(implode(',', $_POST['post_category'])));
                do_action('mainwp_bulkpost_categories_handle', $post_id, $_POST['post_category']);                
            }
            return;
        }
        return;
    }

    function add_tags($post) {
        $this->add_extra('Tags', '_tags', 'add_tags', $post);
    }

    function add_tags_handle($post_id, $post_type) {
        $this->add_extra_handle('Tags', '_tags', 'add_tags', $post_id, $post_type);
        if (isset($_POST['add_tags']))
            do_action('mainwp_bulkpost_tags_handle', $post_id, $post_type, $_POST['add_tags']);
    }

    function add_slug($post) {
        $this->add_extra('Slug', '_slug', 'add_slug', $post, false);
    }

    function add_slug_handle($post_id, $post_type) {
        $this->add_extra_handle('Slug', '_slug', 'add_slug', $post_id, $post_type);
    }

    private function add_extra($title, $saveto, $prefix, $post, $showextraline = true) {
        $extra = base64_decode(get_post_meta($post->ID, $saveto, true));
        ?>
        <input type="hidden" name="<?php echo $prefix; ?>_nonce" id="select_sites_nonce" value="<?php echo wp_create_nonce($prefix . '_' . $post->ID); ?>" />
        <input type="text" name="<?php echo $prefix; ?>" value="<?php echo $extra; ?>" />
        <?php if ($showextraline) { ?><p>Separate <?php echo strtolower($title); ?> with commas</p><?php } ?>
        <?php
    }

    private function add_extra_handle($title, $saveto, $prefix, $post_id, $post_type) {
        // verify this came from the our screen and with proper authorization.
        if (!isset($_POST[$prefix . '_nonce']) || !wp_verify_nonce($_POST[$prefix . '_nonce'], $prefix . '_' . $post_id)) {
            return $post_id;
        }

        // verify if this is an auto save routine. If it is our form has not been submitted, so we dont want to do anything
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            return $post_id;

        // Check permissions
        if (!current_user_can('edit_post', $post_id))
            return $post_id;


        // OK, we're authenticated: we need to find and save the data	
        $post = get_post($post_id);
        if ($post->post_type == $post_type && isset($_POST[$prefix])) {
            update_post_meta($post_id, $saveto, base64_encode($_POST[$prefix]));            
            return base64_encode($_POST[$prefix]);
        }
        return $post_id;
    }

}
?>
