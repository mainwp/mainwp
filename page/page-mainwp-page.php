<?php

/**
 * @see MainWP_Bulk_Add
 */
class MainWP_Page {
	public static function getClassName() {
		return __CLASS__;
	}

	public static $subPages;

	public static function init() {
		/**
		 * This hook allows you to render the Page page header via the 'mainwp-pageheader-page' action.
		 * @link http://codex.mainwp.com/#mainwp-pageheader-page
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-page'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-page
		 *
		 * @see \MainWP_Page::renderHeader
		 */
		add_action( 'mainwp-pageheader-page', array( MainWP_Page::getClassName(), 'renderHeader' ) );

		/**
		 * This hook allows you to render the Page page footer via the 'mainwp-pagefooter-page' action.
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-page
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-page'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-page
		 *
		 * @see \MainWP_Page::renderFooter
		 */
		add_action( 'mainwp-pagefooter-page', array( MainWP_Page::getClassName(), 'renderFooter' ) );
	}

	public static function initMenu() {
		$_page = add_submenu_page( 'mainwp_tab', __( 'Pages','mainwp' ), '<span id="mainwp-Pages">'.__( 'Pages','mainwp' ).'</span>', 'read', 'PageBulkManage', array( MainWP_Page::getClassName(), 'render' ) );
		add_action( 'load-' . $_page, array(MainWP_Page::getClassName(), 'on_load_page'));			
		add_submenu_page( 'mainwp_tab', __( 'Pages','mainwp' ), '<div class="mainwp-hidden">' . __( 'Add New', 'mainwp' ). '</div>', 'read', 'PageBulkAdd', array( MainWP_Page::getClassName(), 'renderBulkAdd' ) );			
        add_submenu_page( 'mainwp_tab', __( 'Pages','mainwp' ), '<div class="mainwp-hidden">' . __( 'Edit Page', 'mainwp' ). '</div>', 'read', 'PageBulkEdit', array( MainWP_Page::getClassName(), 'renderBulkEdit' ) );
		add_submenu_page( 'mainwp_tab', __( 'Posting new bulkpage', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Add New Page', 'mainwp' ) . '</div>', 'read', 'PostingBulkPage', array( MainWP_Page::getClassName(), 'posting' ) ); //removed from menu afterwards
		

		/**
		 * This hook allows you to add extra sub pages to the Page page via the 'mainwp-getsubpages-page' filter.
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-page
		 */
		self::$subPages = apply_filters( 'mainwp-getsubpages-page', array() );
		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Page' . $subPage['slug'], $subPage['callback'] );
			}
		}
        MainWP_Page::init_sub_sub_left_menu(self::$subPages);
	}


	public static function initMenuSubPages() {
		?>
		<div id="menu-mainwp-Pages" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open" style="">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<?php if ( mainwp_current_user_can( 'dashboard', 'manage_pages' ) ) { ?>
						<a href="<?php echo admin_url( 'admin.php?page=PageBulkManage' ); ?>" class="mainwp-submenu"><?php _e( 'Manage Pages','mainwp' ); ?></a>
						<a href="<?php echo admin_url( 'admin.php?page=PageBulkAdd' ); ?>" class="mainwp-submenu"><?php _e( 'Add New','mainwp' ); ?></a>
					<?php } ?>
					<?php
					if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
						foreach ( self::$subPages as $subPage ) {
							if ( ! isset( $subPage['menu_hidden'] ) || (isset( $subPage['menu_hidden'] ) && $subPage['menu_hidden'] != true) ) {
							?>
						<a href="<?php echo admin_url( 'admin.php?page=Page'.$subPage['slug'] ); ?>" class="mainwp-submenu"><?php echo $subPage['title']; ?></a>
							<?php
							}
						}
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

    static function init_sub_sub_left_menu( $subPages = array() ) {
            MainWP_System::add_sub_left_menu(__('Pages', 'mainwp'), 'mainwp_tab', 'PageBulkManage', 'admin.php?page=PageBulkManage', '<i class="fa fa-file"></i>', '' );

            $init_sub_subleftmenu = array(
                    array(  'title' => __('Manage Pages', 'mainwp'),
                            'parent_key' => 'PageBulkManage',
                            'href' => 'admin.php?page=PageBulkManage',
                            'slug' => 'PageBulkManage',
                            'right' => 'manage_pages'
                        ),
                    array(  'title' => __('Add New', 'mainwp'),
                            'parent_key' => 'PageBulkManage',
                            'href' => 'admin.php?page=PageBulkAdd',
                            'slug' => 'PageBulkAdd',
                            'right' => 'manage_pages'
                        )
            );
            MainWP_System::init_subpages_left_menu($subPages, $init_sub_subleftmenu, 'PageBulkManage', 'Page');

            foreach($init_sub_subleftmenu as $item) {
                MainWP_System::add_sub_sub_left_menu($item['title'], $item['parent_key'], $item['slug'], $item['href'], $item['right']);
            }
    }

	public static function on_load_page() {
		MainWP_System::enqueue_postbox_scripts();		
		self::add_meta_boxes();	
	}
	
	public static function add_meta_boxes() {		
		$i = 1;	
		add_meta_box(
			'mwp-pagebulk-contentbox-' . $i++,
			'<i class="fa fa-binoculars"></i> ' . __( 'Step 1: Search Pages', 'mainwp' ),
			array( 'MainWP_Page', 'renderSearchPages' ),
			'mainwp_postboxes_search_pages',
			'normal',
			'core'
		);	
	}
		
    public static function modify_bulkpage_metabox() {
        global $wp_meta_boxes;

        if ( isset( $wp_meta_boxes['bulkpage']['side']['core']['submitdiv'] ) ) {
                $wp_meta_boxes['bulkpage']['side']['core']['submitdiv']['callback'] = array(
                        self::getClassName(),
                        'post_submit_meta_box',
                );
        }
    }

    public static function post_submit_meta_box( $post ) {
        @ob_start();
		post_submit_meta_box( $post );

		$out = @ob_get_contents();
		@ob_end_clean();

        $edit_id = get_post_meta($post->ID, '_mainwp_edit_post_id', true);
        // modify html output
        if ($edit_id) {
            $find    = '<input type="submit" name="publish" id="publish" class="button button-primary button-large" value="' . translate( 'Publish' ) . '"  />';
            $replace = '<input type="submit" name="publish" id="publish" class="button button-primary button-large" value="' . translate( 'Update' ) . '"  />';
            $out = str_replace( $find, $replace, $out );
        }

        $find    = "<select name='post_status' id='post_status'>";
        $replace = "<select name='mainwp_edit_post_status' id='post_status'>";  // to fix: saving pending status
        $out = str_replace( $find, $replace, $out );

		echo str_replace( $find, $find . $replace, $out );
    }

    public static function add_status_handle( $post_id ) {
		$post = get_post( $post_id );
        if ( $post->post_type == 'bulkpage' && isset( $_POST['mainwp_edit_post_status'] ) ) {
            update_post_meta( $post_id, '_edit_post_status', $_POST['mainwp_edit_post_status'] );
        }
		return $post_id;
    }

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderHeader( $shownPage, $post_id = null ) {
        MainWP_UI::render_left_menu();
		?>
		<div class="mainwp-wrap">

			<h1 class="mainwp-margin-top-0"><i class="fa fa-file"></i> <?php _e( 'Pages','mainwp' ); ?></h1>

			<div id="mainwp-tip-zone">
				<?php if ( $shownPage == 'BulkManage' ) { ?>
					<?php if ( MainWP_Utility::showUserTip( 'mainwp-managepage-tips' ) ) { ?>
					<div class="mainwp-tips mainwp-notice mainwp-notice-blue">
						<span class="mainwp-tip" id="mainwp-managepage-tips"><strong><?php _e( 'MainWP Tip','mainwp' ); ?>: </strong><?php _e( 'You can also quickly see all Published, Draft, Pending and Trash Pages for a single site from your Individual Site Dashboard Recent Pages widget by visiting Sites &rarr; Manage Sites &rarr; Child Site &rarr; Dashboard.','mainwp' ); ?></span>
						<span><a href="#" class="mainwp-dismiss" ><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss','mainwp' ); ?></a></span>
					</div>
					<?php } ?>
				<?php } ?>
			</div>
			<div class="mainwp-tabs" id="mainwp-tabs">
				<?php if ( mainwp_current_user_can( 'dashboard', 'manage_pages' ) ) { ?>
				<a class="nav-tab pos-nav-tab <?php if ( $shownPage === 'BulkManage' ) { echo 'nav-tab-active'; } ?>" href="admin.php?page=PageBulkManage"><?php _e( 'Manage Pages','mainwp' ); ?></a>
                <?php if ( $shownPage == 'BulkEdit' ) { ?>
                        <a class="nav-tab pos-nav-tab nav-tab-active" href="admin.php?page=PageBulkEdit&post_id=<?php echo esc_attr($post_id); ?>"><?php _e( 'Edit Page', 'mainwp' ); ?></a>
                <?php } ?>
                        <a class="nav-tab pos-nav-tab <?php if ( $shownPage === 'BulkAdd' ) { echo 'nav-tab-active'; } ?>" href="admin.php?page=PageBulkAdd"><?php _e( 'Add New','mainwp' ); ?></a>
                <?php } ?>
				<?php
				if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
					foreach ( self::$subPages as $subPage ) {
						if ( isset( $subPage['tab_link_hidden'] ) && $subPage['tab_link_hidden'] == true ) {
							$tab_link = '#';
						} else { $tab_link = 'admin.php?page=Page'. $subPage['slug'];}
						?>
				<a class="nav-tab pos-nav-tab <?php if ( $shownPage === $subPage['slug'] ) { echo 'nav-tab-active'; } ?>" href="<?php echo $tab_link; ?>"><?php echo $subPage['title']; ?></a>
						<?php
						}
				}
				?>
				<div class="clear"></div>
			</div>
			<div id="mainwp_wrap-inside">
		<?php
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderFooter( $shownPage ) {
		?>
			</div>
		</div>
		<?php
	}

	public static function render() {
		if ( ! mainwp_current_user_can( 'dashboard', 'manage_pages' ) ) {
			mainwp_do_not_have_permissions( __( 'manage pages', 'mainwp' ) );
			return;
		}

		$cachedSearch = MainWP_Cache::getCachedContext( 'Page' );
                
                $selected_sites = $selected_groups = array();
                if ($cachedSearch != null) {
                    if (is_array($cachedSearch['sites'])) {
                        $selected_sites = $cachedSearch['sites'];
                    } else if (is_array($cachedSearch['groups'])) {
                        $selected_groups = $cachedSearch['groups'];
                    }
                }
                
		//Loads the page screen via AJAX, which redirects to the "posting()" to really post the posts to the saved sites
		?>   
		<?php self::renderHeader( 'BulkManage' ); ?>
		<div class="mainwp-search-form">
		<div class="mainwp-padding-bottom-10"><?php MainWP_Tours::renderSearchPagesTours(); ?></div>
			<div class="mainwp-postbox">
				<?php MainWP_System::do_mainwp_meta_boxes('mainwp_postboxes_search_pages'); ?>				
			</div>
			<?php MainWP_UI::select_sites_box( __( 'Step 2: Select sites', 'mainwp' ), 'checkbox', true, true, 'mainwp_select_sites_box_left', '', $selected_sites, $selected_groups ); ?>
			<div style="clear: both;"></div>
            <input type="button" name="mainwp_show_pages" id="mainwp_show_pages" class="button-primary button button-hero mainwp-button-right" value="<?php _e( 'Show pages', 'mainwp' ); ?>"/>
            <br/><br/>
            <span id="mainwp_pages_loading" class="mainwp-grabbing-info-note"> <i class="fa fa-spinner fa-pulse"></i> <em><?php _e( 'Grabbing information from child sites', 'mainwp' ) ?></em></span>
            <br/><br/>
		</div>
		<div class="clear"></div>

		<div id="mainwp_pages_error"></div>
		<div id="mainwp_pages_main" <?php if ( $cachedSearch != null ) { echo 'style="display: block;"'; } ?>>
			<div class="alignleft">
				<select name="bulk_action" class="mainwp-select2" id="mainwp_bulk_action">
					<option value="none"><?php _e( 'Bulk action','mainwp' ); ?></option>
					<option value="trash"><?php _e( 'Move to trash','mainwp' ); ?></option>
					<option value="restore"><?php _e( 'Restore','mainwp' ); ?></option>
					<option value="delete"><?php _e( 'Delete permanently','mainwp' ); ?></option>
				</select>
				<input type="button" name="" id="mainwp_bulk_page_action_apply" class="button" value="<?php esc_attr_e( 'Apply','mainwp' ); ?>"/>
			</div>
			<div class="alignright" id="mainwp_pages_total_results">
				<?php _e( 'Total Results:','mainwp' ); ?> <span id="mainwp_pages_total"><?php if ( $cachedSearch != null ) { echo $cachedSearch['count']; } ?></span>
			</div>
			<div class="clear"></div>
			<div id="mainwp_pages_content">
                            <div id="mainwp_pages_wrap_table">
                                <?php MainWP_Page::renderTable(true); ?>				
                            </div>
				<div class="clear"></div>
			</div>
		</div>

		</div>
		</div>
                <?php
                
                $current_options = get_option( 'mainwp_opts_saving_status' );
                $col_orders = "";
                if (is_array($current_options) && isset($current_options['pages_col_order'])) {
                    $col_orders = $current_options['pages_col_order'];
                }
                ?>                
                <script type="text/javascript"> var pagesColOrder = '<?php echo $col_orders; ?>';</script>                
		<?php
                
		if ( $cachedSearch != null ) { ?>
                    <script type="text/javascript">
                        jQuery(document).ready(function () {
                                mainwp_table_sort_draggable_init('page', 'mainwp_pages_table', pagesColOrder);                                                       
                        });
                        mainwp_pages_table_reinit();
                    </script>
                <?php
		}
                
	}
	
	public static function renderSearchPages() {
		$cachedSearch = MainWP_Cache::getCachedContext( 'Page' );
		?>
		<ul class="mainwp_checkboxes">
			<li>
				<input type="checkbox" id="mainwp_page_search_type_publish" <?php echo ($cachedSearch == null || ($cachedSearch != null && in_array( 'publish', $cachedSearch['status'] ))) ? 'checked="checked"' : ''; ?> />
				<label for="mainwp_page_search_type_publish"><?php _e( 'Published', 'mainwp' ); ?></label>
			</li>
			<li>
				<input type="checkbox" id="mainwp_page_search_type_pending" <?php echo ($cachedSearch != null && in_array( 'pending', $cachedSearch['status'] )) ? 'checked="checked"' : ''; ?> />
				<label for="mainwp_page_search_type_pending" ><?php _e( 'Pending', 'mainwp' ); ?></label>
			</li>
			<li>
				<input type="checkbox" id="mainwp_page_search_type_private" <?php echo ($cachedSearch != null && in_array( 'private', $cachedSearch['status'] )) ? 'checked="checked"' : ''; ?> />
				<label for="mainwp_page_search_type_private"><?php _e( 'Private', 'mainwp' ); ?></label>
			</li>
			<li>
				<input type="checkbox" id="mainwp_page_search_type_future" <?php echo ($cachedSearch != null && in_array( 'future', $cachedSearch['status'] )) ? 'checked="checked"' : ''; ?> />
				<label for="mainwp_page_search_type_future" ><?php _e( 'Scheduled', 'mainwp' ); ?></label>
			</li>
			<li>
				<input type="checkbox" id="mainwp_page_search_type_draft" <?php echo ($cachedSearch != null && in_array( 'draft', $cachedSearch['status'] )) ? 'checked="checked"' : ''; ?> />
				<label for="mainwp_page_search_type_draft" ><?php _e( 'Draft', 'mainwp' ); ?></label>
			</li>
			<li>
				<input type="checkbox" id="mainwp_page_search_type_trash" <?php echo ($cachedSearch != null && in_array( 'trash', $cachedSearch['status'] )) ? 'checked="checked"' : ''; ?> />
				<label for="mainwp_page_search_type_trash" ><?php _e( 'Trash', 'mainwp' ); ?></label>
			</li>
		</ul>
		<div class="mainwp-padding-bottom-20">
			<div class="mainwp-cols-2 mainwp-left">
				<label for="mainwp_page_search_by_keyword"><?php _e( 'Containing Keyword:', 'mainwp' ); ?></label><br/>
				<input type="text" 
					   id="mainwp_page_search_by_keyword" 
					   class="" 
					   size="50" 
					   value="<?php if ( $cachedSearch != null ) { echo $cachedSearch['keyword']; } ?>"/>
			</div>
			<div class="mainwp-cols-2 mainwp-left">
				<label for="mainwp_page_search_by_dtsstart"><?php _e( 'Date Range:', 'mainwp' ); ?></label><br/>
				<input type="text" id="mainwp_page_search_by_dtsstart" class="mainwp_datepicker" size="12" value="<?php if ( $cachedSearch != null ) {
					echo $cachedSearch['dtsstart'];
				} ?>"/> <?php _e( 'to', 'mainwp' ); ?>
				<input type="text" id="mainwp_page_search_by_dtsstop" class="mainwp_datepicker" size="12" value="<?php if ( $cachedSearch != null ) {
					echo $cachedSearch['dtsstop'];
				} ?>"/>
			</div>
			<div sytle="clear:both;"></div>
		</div>
		<br/><br/>
		<div class="mainwp-padding-bottom-20 mainwp-padding-top-20">
			<label for="mainwp_maximumPages"><?php _e( 'Maximum number of pages to return', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( '0 for unlimited, CAUTION: depending on your server settings a large return amount may decrease the speed of results or temporarily break communication between Dashboard and Child.', 'mainwp' ) ); ?></label><br/>	
            <input type="number" 
            	   name="mainwp_maximumPages" 
            	   class=""
                   id="mainwp_maximumPages" 
                   value="<?php echo( ( get_option( 'mainwp_maximumPages' ) === false ) ? 50 : get_option( 'mainwp_maximumPages' ) ); ?>"/>	
		</div>
		<?php
	}
	
        public static function renderTable( $cached, $keyword = '', $dtsstart = '', $dtsstop = '', $status = '', $groups = '', $sites = '' ) {
            ?>
            <table class="wp-list-table widefat fixed pages tablesorter fix-select-all-ajax-table" id="mainwp_pages_table" cellspacing="0">
                        <thead>
                                <tr>
                                        <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox"></th>
                                        <th scope="col" id="title" class="drag-enable manage-column column-title sortable desc" style="">
                                                <a href="#" onclick="return false;"><span><?php _e( 'Title','mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                        </th>
                                        <th scope="col" id="author" class="drag-enable manage-column column-author sortable desc" style="">
                                                <a href="#" onclick="return false;"><span><?php _e( 'Author','mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                        </th>
                                        <th scope="col" id="comments" class="drag-enable manage-column column-comments num sortable desc" style="">
                                                <a href="#" onclick="return false;">
                                                        <span><span class="vers">
                                                                <img alt="Comments" src="<?php echo admin_url( 'images/comment-grey-bubble.png' ); ?>">
                                                        </span></span>
                                                        <span class="sorting-indicator"></span>
                                                </a>
                                        </th>
                                        <th scope="col" id="date" class="drag-enable manage-column column-date sortable asc" style="">
                                                <a href="#" onclick="return false;"><span><?php _e( 'Date','mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                        </th>
                                        <th scope="col" id="status" class="drag-enable manage-column column-status sortable asc" style="width: 120px;">
                                                <a href="#" onclick="return false;"><span><?php _e( 'Status','mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                        </th>
                                        <th scope="col" id="website" class="drag-enable manage-column column-categories sortable desc" style="">
                                                <a href="#" onclick="return false;"><span><?php _e( 'Website','mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                        </th>
                                </tr>
                        </thead>

                        <tfoot>
                                <tr>
                                        <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox"></th>
                                        <th scope="col" id="title" class="manage-column column-title sortable desc" style="">
                                                <a href="#" onclick="return false;"><span><?php _e( 'Title','mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                        </th>
                                        <th scope="col" id="author" class="manage-column column-author sortable desc" style="">
                                                <a href="#" onclick="return false;"><span><?php _e( 'Author','mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                        </th>
                                        <th scope="col" id="comments" class="manage-column column-comments num sortable desc" style="">
                                                <a href="#" onclick="return false;">
                                                        <span><span class="vers">
                                                                <img alt="Comments" src="<?php echo admin_url( 'images/comment-grey-bubble.png' ); ?>">
                                                        </span></span>
                                                        <span class="sorting-indicator"></span>
                                                </a>
                                        </th>
                                        <th scope="col" id="date" class="manage-column column-date sortable asc" style="">
                                                <a href="#" onclick="return false;"><span><?php _e( 'Date','mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                        </th>
                                        <th scope="col" id="status" class="manage-column column-status sortable asc" style="width: 120px;">
                                                <a href="#" onclick="return false;"><span><?php _e( 'Status','mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                        </th>
                                        <th scope="col" id="website" class="manage-column column-categories sortable desc" style="">
                                                <a href="#" onclick="return false;"><span><?php _e( 'Website','mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                        </th>
                                </tr>
                        </tfoot>

                        <tbody id="the-posts-list" class="list:pages">
                                <?php if ($cached) {
                                            MainWP_Cache::echoBody( 'Page' ); 
                                      } else {
                                            MainWP_Page::renderTableBody($keyword, $dtsstart, $dtsstop, $status, $groups, $sites);
                                      }
                                ?>
                        </tbody>
                </table>
                <div class="pager" id="pager">
                        <form>
                                <img src="<?php echo plugins_url( 'images/first.png', dirname( __FILE__ ) ); ?>" class="first">
                                <img src="<?php echo plugins_url( 'images/prev.png', dirname( __FILE__ ) ); ?>" class="prev">
                                <input type="text" class="pagedisplay">
                                <img src="<?php echo plugins_url( 'images/next.png', dirname( __FILE__ ) ); ?>" class="next">
                                <img src="<?php echo plugins_url( 'images/last.png', dirname( __FILE__ ) ); ?>" class="last">
                                <span>&nbsp;&nbsp;<?php _e( 'Show:','mainwp' ); ?> </span>
                                <select class="mainwp-select2 pagesize">
                                        <option selected="selected" value="10">10</option>
                                        <option value="25">25</option>
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                        <option value="1000000000">All</option>
                                </select>
                                <span> <?php _e( 'Pages per page','mainwp' ); ?></span>
                        </form>
                </div>
            <?php
        }
        
	public static function renderTableBody( $keyword, $dtsstart, $dtsstop, $status, $groups, $sites ) {

		MainWP_Cache::initCache( 'Page' );

		//Fetch all!
		//Build websites array
		$dbwebsites = array();
		if ( $sites != '' ) {
			foreach ( $sites as $k => $v ) {
				if ( MainWP_Utility::ctype_digit( $v ) ) {
					$website = MainWP_DB::Instance()->getWebsiteById( $v );
					$dbwebsites[ $website->id ] = MainWP_Utility::mapSite( $website, array( 'id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey' ) );
				}
			}
		}
		if ( $groups != '' ) {
			foreach ( $groups as $k => $v ) {
				if ( MainWP_Utility::ctype_digit( $v ) ) {
					$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $v ) );
					while ( $websites && ($website = @MainWP_DB::fetch_object( $websites )) ) {
						if ( $website->sync_errors != '' ) {continue;}
						$dbwebsites[ $website->id ] = MainWP_Utility::mapSite( $website, array( 'id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey' ) );
					}
					@MainWP_DB::free_result( $websites );
				}
			}
		}

		$output = new stdClass();
		$output->errors = array();
		$output->pages = 0;

		if ( count( $dbwebsites ) > 0 ) {
			$post_data = array(
				'keyword' => $keyword,
				'dtsstart' => $dtsstart,
				'dtsstop' => $dtsstop,
				'status' => $status,
				'maxRecords' => ((get_option( 'mainwp_maximumPages' ) === false) ? 50 : get_option( 'mainwp_maximumPages' )),
			);
            $post_data = apply_filters('mainwp_get_all_pages_data', $post_data);
			MainWP_Utility::fetchUrlsAuthed( $dbwebsites, 'get_all_pages', $post_data, array( MainWP_Page::getClassName(), 'PagesSearch_handler' ), $output );
		}

		MainWP_Cache::addContext( 'Page', array( 'count' => $output->pages, 'keyword' => $keyword, 'dtsstart' => $dtsstart, 'dtsstop' => $dtsstop, 'status' => $status,
                        'sites'    => ($sites != '') ? $sites : '',
                        'groups'   => ($groups != '') ? $groups : ''
                ));
                
		//Sort if required

		if ( $output->pages == 0 ) {
			ob_start();
			?>
		<tr>
			<td colspan="7">No pages found</td>
		</tr>
			<?php
			$newOutput = ob_get_clean();
			echo $newOutput;
			MainWP_Cache::addBody( 'Page', $newOutput );
			return;
		}
	}

	private static function getStatus( $status ) {
		if ( $status == 'publish' ) {
			return 'Published';
		}
		return ucfirst( $status );
	}

	public static function PagesSearch_handler( $data, $website, &$output ) {
		if ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 ) {
			$pages = unserialize( base64_decode( $results[1] ) );
			unset( $results );
			foreach ( $pages as $page ) {
                $raw_dts = '';
				if ( isset( $page['dts'] ) ) {
                    $raw_dts = $page['dts'];
					if ( ! stristr( $page['dts'], '-' ) ) {
						$page['dts'] = MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $page['dts'] ) );
					}
				}

				if ( ! isset( $page['title'] ) || ($page['title'] == '') ) {
					$page['title'] = '(No Title)';
				}
				ob_start();
				?>
			<tr id="page-1" class="page-1 page type-page status-publish format-standard hentry category-uncategorized alternate iedit author-self" valign="top">
				<th scope="row" class="check-column"><input type="checkbox" name="page[]" value="1"></th>
				<td class="page-title page-title column-title">
					<input class="pageId" type="hidden" name="id" value="<?php echo $page['id']; ?>"/>
					<input class="allowedBulkActions" type="hidden" name="allowedBulkActions" value="|get_edit|trash|delete|<?php if ( $page['status'] == 'trash' ) { echo 'restore|'; } ?>"/>
					<input class="websiteId" type="hidden" name="id" value="<?php echo $website->id; ?>"/>

					<strong>
						<abbr title="<?php echo $page['title']; ?>">
						<?php if ( $page['status'] != 'trash' ) { ?>
						<a class="row-title" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $website->id; ?>&location=<?php echo base64_encode( 'post.php?post=' . $page['id'] . '&action=edit' ); ?>" target="_blank" title="Edit '<?php echo $page['title']; ?>'?"><?php echo $page['title']; ?></a>
						<?php } else { ?>
							<?php echo $page['title']; ?>
						<?php } ?>
						</abbr>
					</strong>

					<div class="row-actions">
						<?php if ( $page['status'] != 'trash' ) { ?>
						<span class="edit">
                                                    <a class="page_getedit"
                                                    href="#"
                                                    title="Edit this item"><?php _e( 'Edit', 'mainwp' ); ?></a>
						</span>
						<span class="trash">
							| <a class="page_submitdelete" title="Move this item to the Trash" href="#"><?php _e( 'Trash','mainwp' ); ?></a>
						</span>
						<?php } ?>

						<?php if ( $page['status'] == 'publish' ) { ?>
						<span class="view">
							| <a href="<?php echo $website->url . (substr( $website->url, -1 ) != '/' ? '/' : '') . '?p=' . $page['id']; ?>" target="_blank" title="View '<?php echo $page['title']; ?>'?" rel="permalink"><?php _e( 'View','mainwp' ); ?></a>
						</span>
						<?php } ?>
						<?php if ( $page['status'] == 'trash' ) { ?>
						<span class="restore">
							<a class="page_submitrestore" title="Restore this item" href="#"><?php _e( 'Restore','mainwp' ); ?></a>
						</span>
						<span class="trash">
							| <a class="page_submitdelete_perm" title="Delete this item permanently" href="#"><?php _e('Delete permanently','mainwp'); ?></a>
						</span>
						<?php } ?>
					</div>
					<div class="row-actions-working">
						<i class="fa fa-spinner fa-pulse"></i> <?php _e( 'Please wait...','mainwp' ); ?>
					</div>
				</td>
				<td class="author column-author">
					<?php echo $page['author']; ?>
				</td>
				<td class="comments column-comments">
					<div class="page-com-count-wrapper">
						<a href="#" title="0 pending" class="post-com-count">
							<span class="comment-count"><abbr title="<?php echo $page['comment_count']; ?>"><?php echo $page['comment_count']; ?></abbr></span>
						</a>
					</div>
				</td>
				<td class="date column-date">
					<abbr raw_value="<?php echo $raw_dts; ?>" title="<?php echo $page['dts']; ?>"><?php echo $page['dts']; ?></abbr>
				</td>
				<td class="status column-status"><?php echo self::getStatus( $page['status'] ); ?>
				</td>
				<td class="categories column-categories">
					<a href="<?php echo $website->url; ?>" target="_blank"><?php echo $website->url; ?></a>
					<div class="row-actions">
						<span class="edit">
							<a href="admin.php?page=managesites&dashboard=<?php echo $website->id; ?>"><?php _e( 'Overview','mainwp' ); ?></a>
							 | <a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $website->id; ?>" target="_blank"><?php _e( 'WP Admin','mainwp' ); ?></a>
						</span>
					</div>
				</td>
			</tr>
			<?php
				$newOutput = ob_get_clean();
				echo $newOutput;
				MainWP_Cache::addBody( 'Page', $newOutput );
				$output->pages++;
			}
			unset( $pages );
		} else {
			$output->errors[ $website->id ] = MainWP_Error_Helper::getErrorMessage( new MainWP_Exception( 'NOMAINWP', $website->url ) );
		}
	}

	public static function publish() {
		MainWP_Recent_Posts::action( 'publish' );
		die( json_encode( array( 'result' => 'Page has been published!' ) ) );
	}

	public static function unpublish() {
		MainWP_Recent_Posts::action( 'unpublish' );
		die( json_encode( array( 'result' => 'Page has been unpublished!' ) ) );
	}

	public static function trash() {
		MainWP_Recent_Posts::action( 'trash' );
		die( json_encode( array( 'result' => 'Page has been moved to trash!' ) ) );
	}

	public static function delete() {
		MainWP_Recent_Posts::action( 'delete' );
		die( json_encode( array( 'result' => 'Page has been permanently deleted!' ) ) );
	}

	public static function restore() {
		MainWP_Recent_Posts::action( 'restore' );
		die( json_encode( array( 'result' => 'Page has been restored!' ) ) );
	}

	public static function renderBulkAdd() {
		if ( ! mainwp_current_user_can( 'dashboard', 'manage_pages' ) ) {
			mainwp_do_not_have_permissions( __( 'manage pages', 'mainwp' ) );
			return;
		}

		$src = get_site_url() . '/wp-admin/post-new.php?post_type=bulkpage&hideall=1';
		$src = apply_filters( 'mainwp_bulkpost_edit_source', $src );
		//Loads the post screen via AJAX, which redirects to the "posting()" to really post the posts to the saved sites
		?>
                <?php self::renderHeader( 'BulkAdd' ); ?>
				<iframe scrolling="auto" id="mainwp_iframe" src="<?php echo $src; ?>"></iframe>
			</div>
		</div>
		<?php
	}

    public static function renderBulkEdit() {
		if ( ! mainwp_current_user_can( 'dashboard', 'manage_pages' ) ) {
			mainwp_do_not_have_permissions( __( 'manage pages', 'mainwp' ) );
			return;
		}

        $post_id = isset($_REQUEST['post_id']) ? $_REQUEST['post_id'] : 0;
        $src = get_site_url() . '/wp-admin/post.php?post_type=bulkpage&hideall=1&action=edit&post=' . esc_attr( $post_id );
        $src = apply_filters( 'mainwp_bulkpost_edit_source', $src );

		//Loads the post screen via AJAX, which redirects to the "posting()" to really post the posts to the saved sites
		self::renderHeader( 'BulkEdit', $post_id ); ?>
		<iframe scrolling="auto" id="mainwp_iframe" src="<?php echo $src; ?>"></iframe>
		<?php
		self::renderFooter( 'BulkEdit' );
	}

	public static function posting() {
		$succes_message = '';
	    if ( isset( $_GET['id'] ) ) {
	        $edit_id = get_post_meta($_GET['id'], '_mainwp_edit_post_id', true);
	        if ($edit_id) {
	            $succes_message = __('Page has been updated successfully', 'mainwp');
	        } else {
	            $succes_message = __('New page created', 'mainwp');
	        }
	    }
		?>
	<div class="wrap">
        <h2><?php $edit_id ? _e('Edit Page', 'mainwp') : _e('New Page', 'mainwp') ?></h2>
		<?php //  Use this to add a new page. To bulk change pages click on the "Manage" tab.

		do_action( 'mainwp_bulkpage_before_post', $_GET['id'] );

		$skip_post = false;
		if ( isset( $_GET['id'] ) ) {
			if ( 'yes' == get_post_meta( $_GET['id'], '_mainwp_skip_posting', true ) ) {
				$skip_post = true;
				wp_delete_post( $_GET['id'], true );
			}
		}

		if ( ! $skip_post ) {
			//Posts the saved sites
			if ( isset( $_GET['id'] ) ) {
				$id = $_GET['id'];
				$post = get_post( $id );
				if ( $post ) {
					$selected_by = get_post_meta( $id, '_selected_by', true );
					$selected_sites = unserialize( base64_decode( get_post_meta( $id, '_selected_sites', true ) ) );
					$selected_groups = unserialize( base64_decode( get_post_meta( $id, '_selected_groups', true ) ) );
					$post_slug = base64_decode( get_post_meta( $id, '_slug', true ) );
					$post_custom = get_post_custom( $id );
					include_once( ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'post-thumbnail-template.php' );
					$post_featured_image = get_post_thumbnail_id( $id );
					$mainwp_upload_dir = wp_upload_dir();
                    $post_status = get_post_meta( $id, '_edit_post_status', true );
					$new_post = array(
						'post_title' => $post->post_title,
						'post_content' => $post->post_content,
						'post_status' => ($post_status == 'pending') ? 'pending' : $post->post_status, //was 'publish'
						'post_date' => $post->post_date,
						'post_date_gmt' => $post->post_date_gmt,
						'post_type' => 'page',
						'post_name' => $post_slug,
						'post_excerpt' => $post->post_excerpt,
						'comment_status' => $post->comment_status,
						'ping_status' => $post->ping_status,
						'id_spin' => $post->ID,
					);

					if ( $post_featured_image != null ) { //Featured image is set, retrieve URL
						$img = wp_get_attachment_image_src( $post_featured_image, 'full' );
						$post_featured_image = $img[0];
					}

					$galleries = get_post_gallery( $id, false );
					$post_gallery_images = array();

					if ( is_array($galleries) && isset($galleries['ids']) ) {
						$attached_images = explode( ',', $galleries['ids'] );							
						foreach( $attached_images as $attachment_id ) {
							$attachment = get_post( $attachment_id );
							if ( $attachment ) {
								$post_gallery_images[] = array(
									'id' => $attachment_id,
									'alt' => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
									'caption' => $attachment->post_excerpt,
									'description' => $attachment->post_content,									
									'src' => $attachment->guid,
									'title' => $attachment->post_title
								);
							}
						}
					}
						
					$dbwebsites = array();
					if ( $selected_by == 'site' ) { //Get all selected websites
						foreach ( $selected_sites as $k ) {
							if ( MainWP_Utility::ctype_digit( $k ) ) {
								$website = MainWP_DB::Instance()->getWebsiteById( $k );
								$dbwebsites[ $website->id ] = MainWP_Utility::mapSite( $website, array( 'id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey' ) );
							}
						}
					} else { //Get all websites from the selected groups
						foreach ( $selected_groups as $k ) {
							if ( MainWP_Utility::ctype_digit( $k ) ) {
								$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $k ) );
								while ( $websites && ($website = @MainWP_DB::fetch_object( $websites )) ) {
									if ( $website->sync_errors != '' ) {continue;}
									$dbwebsites[ $website->id ] = MainWP_Utility::mapSite( $website, array( 'id', 'url', 'name', 'adminname', 'nossl', 'privkey', 'nosslkey' ) );
								}
								@MainWP_DB::free_result( $websites );
							}
						}
					}

					$output = new stdClass();
					$output->ok = array();
					$output->errors = array();
					$startTime = time();

					if ( count( $dbwebsites ) > 0 ) {
						$post_data = array(
							'new_post' => base64_encode( serialize( $new_post ) ),
							'post_custom' => base64_encode( serialize( $post_custom ) ),
							'post_featured_image' => base64_encode( $post_featured_image ),
							'post_gallery_images' => base64_encode( serialize( $post_gallery_images ) ),
							'mainwp_upload_dir' => base64_encode( serialize( $mainwp_upload_dir ) ),
						);
						$post_data = apply_filters( 'mainwp_bulkpage_posting', $post_data, $id );
						MainWP_Utility::fetchUrlsAuthed( $dbwebsites, 'newpost', $post_data, array( MainWP_Bulk_Add::getClassName(), 'PostingBulk_handler' ), $output );
					}

					$failed_posts = array();
					foreach ( $dbwebsites as $website ) {
						if ( ($output->ok[ $website->id ] == 1) && (isset( $output->added_id[ $website->id ] )) ) {
							do_action( 'mainwp-post-posting-page', $website, $output->added_id[ $website->id ], (isset( $output->link[ $website->id ] ) ? $output->link[ $website->id ] : null) );
							do_action( 'mainwp-bulkposting-done', $post, $website, $output );
						} else {
							$failed_posts[] = $website->id;
						}
					}

					$del_post = true;
					$saved_draft = get_post_meta( $id, '_saved_as_draft', true );
					if ( $saved_draft == 'yes' ) {
						if ( count( $failed_posts ) > 0 ) {
							$del_post = false;
							update_post_meta( $post->ID, '_selected_sites', base64_encode( serialize( $failed_posts ) ) );
							update_post_meta( $post->ID, '_selected_groups', '' );
							wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
						}
					}

					if ( $del_post ) {
						wp_delete_post( $id, true );}

					$countSites = 0;
                    $countRealItems = 0;
					foreach ( $dbwebsites as $website ) {
						if ( isset( $output->ok[ $website->id ] ) && $output->ok[ $website->id ] == 1 ) {
							$countSites++;
                            $countRealItems++;
						}
					}

					if ( ! empty( $countSites ) ) {
						$seconds = (time() - $startTime);
						MainWP_Twitter::updateTwitterInfo( 'new_page', $countSites, $seconds, $countRealItems, $startTime, 1 );
					}

					if ( MainWP_Twitter::enabledTwitterMessages() ) {
						$twitters = MainWP_Twitter::getTwitterNotice( 'new_page' );
						if ( is_array( $twitters ) ) {
							foreach ( $twitters as $timeid => $twit_mess ) {
								if ( ! empty( $twit_mess ) ) {
									$sendText = MainWP_Twitter::getTwitToSend( 'new_page', $timeid );
								?>
									<div class="mainwp-tips mainwp-notice mainwp-notice-blue twitter"><span class="mainwp-tip" twit-what="new_page" twit-id="<?php echo $timeid; ?>"><?php echo $twit_mess; ?></span>&nbsp;<?php MainWP_Twitter::genTwitterButton( $sendText );?><span><a href="#" class="mainwp-dismiss-twit mainwp-right" ><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss','mainwp' ); ?></a></span></div>
								<?php
								}
							}
						}
					}
				}
				?>
				<div class="mainwp-notice mainwp-notice-green">
					<?php foreach ( $dbwebsites as $website ) { ?>
                                            <a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a>
                                            : <?php echo (isset( $output->ok[ $website->id ] ) && $output->ok[ $website->id ] == 1 ? $succes_message .' <a href="'.$output->link[ $website->id ].'"  target="_blank">View Page</a>' : 'ERROR: ' . $output->errors[ $website->id ]); ?><br/>
					<?php } ?>
				</div>

				<?php
			} else {
				?>
				<div class="error below-h2">
					<p><strong>ERROR</strong>: <?php _e( 'An undefined error occured.','mainwp' ); ?></p>
				</div>
				<?php
			}
		} // no skip posting
		?>
		<br/>
		<a href="<?php echo get_admin_url() ?>admin.php?page=PageBulkAdd" class="add-new-h2" target="_top"><?php _e( 'Add new','mainwp' ); ?></a>
		<a href="<?php echo get_admin_url() ?>admin.php?page=PageBulkManage" class="add-new-h2" target="_top"><?php _e('Return to Manage Pages','mainwp'); ?></a>
	</div>
	<?php
	}

}
