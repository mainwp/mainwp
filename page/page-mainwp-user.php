<?php

/**
 * @see MainWP_Bulk_Add
 */
class MainWP_User {
	public static function getClassName() {
		return __CLASS__;
	}

	public static $subPages;

	public static function init() {
		/**
		 * This hook allows you to render the User page header via the 'mainwp-pageheader-user' action.
		 * @link http://codex.mainwp.com/#mainwp-pageheader-user
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-user'
         * @link http://codex.mainwp.com/#mainwp-getsubpages-user
		 *
		 * @see \MainWP_User::renderHeader
		 */
		add_action( 'mainwp-pageheader-user', array( MainWP_User::getClassName(), 'renderHeader' ) );

		/**
		 * This hook allows you to render the User page footer via the 'mainwp-pagefooter-user' action.
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-user
         *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-user'
         * @link http://codex.mainwp.com/#mainwp-getsubpages-user
		 *
		 * @see \MainWP_User::renderFooter
		 */
		add_action( 'mainwp-pagefooter-user', array( MainWP_User::getClassName(), 'renderFooter' ) );
	}

	public static function initMenu() {
		$_page = add_submenu_page( 'mainwp_tab', __( 'Users', 'mainwp' ), '<span id="mainwp-Users">' . __( 'Users', 'mainwp' ) . '</span>', 'read', 'UserBulkManage', array(
			MainWP_User::getClassName(),
			'render',
		) );
		add_action( 'load-' . $_page, array(MainWP_User::getClassName(), 'on_load_page'));	
		
		$_page = add_submenu_page( 'mainwp_tab', __( 'Users', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Add New', 'mainwp' ) . '</div>', 'read', 'UserBulkAdd', array(
			MainWP_User::getClassName(),
			'renderBulkAdd',
		) );
		add_action( 'load-' . $_page, array(MainWP_User::getClassName(), 'on_load_page'));	
		
		$_page = add_submenu_page( 'mainwp_tab', __( 'Import Users', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Import Users', 'mainwp' ) . '</div>', 'read', 'BulkImportUsers', array(
			MainWP_User::getClassName(),
			'renderBulkImportUsers',
		) );
		add_action( 'load-' . $_page, array(MainWP_User::getClassName(), 'on_load_page'));	
		
		/**
		 * This hook allows you to add extra sub pages to the User page via the 'mainwp-getsubpages-user' filter.
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-user
		 */
		self::$subPages = apply_filters( 'mainwp-getsubpages-user', array() );
		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'UserBulk' . $subPage['slug'], $subPage['callback'] );
			}
		}

        MainWP_User::init_sub_sub_left_menu(self::$subPages);
	}
	
	public static function on_load_page() {		
		MainWP_System::enqueue_postbox_scripts();		
		self::add_meta_boxes();
	}	

	public static function add_meta_boxes() {		
		$i = 1;	
		if ( isset($_GET['page'])) {
			if ( 'UserBulkManage' == $_GET['page'] ) {		
				add_meta_box(
					'mwp-serchusers-contentbox-' . $i++,
					'<i class="fa fa-binoculars"></i> ' . __( 'Step 1: Search users', 'mainwp' ),
					array( 'MainWP_User', 'renderSearchUsers' ),
					'mainwp_postboxes_search_users',
					'normal',
					'core'
				);
                                
                                add_meta_box(
					'mwp-serchusers-contentbox-' . $i++,
					'<i class="fa fa-refresh" aria-hidden="true"></i> ' . __( 'Update selected users', 'mainwp' ),
					array( 'MainWP_User', 'renderUpdateUsers' ),
					'mainwp_postboxes_update_users',
					'normal',
					'core'
				);
                                
			} else if ( 'UserBulkAdd' == $_GET['page'] ) {
				add_meta_box(
					'mwp-adduser-contentbox-' . $i++,
					'<i class="fa fa-user-plus"></i> ' . __( 'Step 1: Add a single user', 'mainwp' ),
					array( 'MainWP_User', 'renderAddUser' ),
					'mainwp_postboxes_add_user',
					'normal',
					'core'
				);				
			} else if ( 'BulkImportUsers' == $_GET['page'] ) {				
				add_meta_box(
					'mwp-adduser-contentbox-' . $i++,
					'<i class="fa fa-user-plus"></i> ' . __( 'Bulk upload', 'mainwp' ),
					array( 'MainWP_User', 'renderImportUsers' ),
					'mainwp_postboxes_bulk_import_users',
					'normal',
					'core'
				);
			}
		}
	}
	
	public static function initMenuSubPages() {
		?>
		<div id="menu-mainwp-Users" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open" style="">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<?php if ( mainwp_current_user_can( 'dashboard', 'manage_users' ) ) { ?>
						<a href="<?php echo admin_url( 'admin.php?page=UserBulkManage' ); ?>" class="mainwp-submenu"><?php _e( 'Manage Users', 'mainwp' ); ?></a>
					<?php } ?>
					<a href="<?php echo admin_url( 'admin.php?page=UserBulkAdd' ); ?>" class="mainwp-submenu"><?php _e( 'Add New', 'mainwp' ); ?></a>
					<a href="<?php echo admin_url( 'admin.php?page=BulkImportUsers' ); ?>" class="mainwp-submenu"><?php _e( 'Import Users', 'mainwp' ); ?></a>
					<a href="<?php echo admin_url( 'admin.php?page=UpdateAdminPasswords' ); ?>" class="mainwp-submenu"><?php _e( 'Admin Passwords', 'mainwp' ); ?></a>
					<?php
					if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
						foreach ( self::$subPages as $subPage ) {
							?>
							<a href="<?php echo admin_url( 'admin.php?page=UserBulk' . $subPage['slug'] ); ?>"
								class="mainwp-submenu"><?php echo $subPage['title']; ?></a>
							<?php
						}
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

    static function init_sub_sub_left_menu( $subPages = array() ) {
        MainWP_System::add_sub_left_menu(__('Users', 'mainwp'), 'mainwp_tab', 'UserBulkManage', 'admin.php?page=UserBulkManage', '<i class="fa fa-user"></i>', 'Manage users on your child sites' );
        $init_sub_subleftmenu = array(
                array(  'title' => __('Manage Users', 'mainwp'),
                        'parent_key' => 'UserBulkManage',
                        'href' => 'admin.php?page=UserBulkManage',
                        'slug' => 'UserBulkManage',
                        'right' => 'manage_users'
                    ),
                array(  'title' => __('Add New', 'mainwp'),
                        'parent_key' => 'UserBulkManage',
                        'href' => 'admin.php?page=UserBulkAdd',
                        'slug' => 'UserBulkAdd',
                        'right' => ''
                    ),
                array(  'title' => __('Import Users', 'mainwp'),
                        'parent_key' => 'UserBulkManage',
                        'href' => 'admin.php?page=BulkImportUsers',
                        'slug' => 'BulkImportUsers',
                        'right' => ''
                    ),
                array(  'title' => __('Admin Passwords', 'mainwp'),
                        'parent_key' => 'UserBulkManage',
                        'href' => 'admin.php?page=UpdateAdminPasswords',
                        'slug' => 'UpdateAdminPasswords',
                        'right' => ''
                    )
        );

        MainWP_System::init_subpages_left_menu($subPages, $init_sub_subleftmenu, 'UserBulkManage', 'UserBulk');

        foreach($init_sub_subleftmenu as $item) {
            MainWP_System::add_sub_sub_left_menu($item['title'], $item['parent_key'], $item['slug'], $item['href'], $item['right']);
        }
    }

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderHeader( $shownPage ) {
        MainWP_UI::render_left_menu();
		?>
		<div class="mainwp-wrap">

		<h1 class="mainwp-margin-top-0"><i class="fa fa-user"></i> <?php _e( 'Users', 'mainwp' ); ?></h1>

		<div class="mainwp-tabs" id="mainwp-tabs">
			<?php if ( mainwp_current_user_can( 'dashboard', 'manage_users' ) ) { ?>
				<a class="nav-tab pos-nav-tab <?php if ( $shownPage == '' ) {
					echo 'nav-tab-active';
				} ?>" href="admin.php?page=UserBulkManage"><?php _e( 'Manage Users', 'mainwp' ); ?></a>
			<?php } ?>
			<a class="nav-tab pos-nav-tab <?php if ( $shownPage == 'Add' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=UserBulkAdd"><?php _e( 'Add New', 'mainwp' ); ?></a>
			<a class="nav-tab pos-nav-tab <?php if ( $shownPage == 'Import' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=BulkImportUsers"><?php _e( 'Import Users', 'mainwp' ); ?></a>
			<a class="nav-tab pos-nav-tab <?php if ( $shownPage == 'UpdateAdminPasswords' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=UpdateAdminPasswords"><?php _e( 'Admin Passwords', 'mainwp' ); ?></a>						
			<?php
			if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
				foreach ( self::$subPages as $subPage ) {
					?>
					<a class="nav-tab pos-nav-tab <?php if ( $shownPage === $subPage['slug'] ) {
						echo 'nav-tab-active';
					} ?>" href="admin.php?page=UserBulk<?php echo $subPage['slug']; ?>"><?php echo $subPage['title']; ?></a>
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
		if ( ! mainwp_current_user_can( 'dashboard', 'manage_users' ) ) {
			mainwp_do_not_have_permissions( __( 'manage users', 'mainwp' ) );

			return;
		}
                
		$cachedSearch = MainWP_Cache::getCachedContext( 'Users' );
                
                $selected_sites = $selected_groups = array();
                if ($cachedSearch != null) {
                    if (is_array($cachedSearch['sites'])) {
                        $selected_sites = $cachedSearch['sites'];
                    } else if (is_array($cachedSearch['groups'])) {
                        $selected_groups = $cachedSearch['groups'];
                    }
                }
		self::renderHeader( '' ); ?>
		<div>
		<div class="mainwp-padding-bottom-10"><?php MainWP_Tours::renderSearchUsersTours(); ?></div>
            <div class="mainwp-postbox">
            	<?php MainWP_System::do_mainwp_meta_boxes('mainwp_postboxes_search_users'); ?>				
			</div>
            <?php MainWP_UI::select_sites_box(__("Step 2: Select sites", 'mainwp'), 'checkbox', true, true, 'mainwp_select_sites_box_left', '', $selected_sites, $selected_groups); ?>
            <div style="clear: both;"></div>
            <input type="button" name="mainwp_show_users" id="mainwp_show_users" class="button-primary button button-hero mainwp-button-right" value="<?php _e('Show Users','mainwp'); ?>"/>
            <br/><br/>
            <span id="mainwp_users_loading" class="mainwp-grabbing-info-note"><i class="fa fa-spinner fa-pulse"></i> <em><?php _e('Grabbing information from Child Sites','mainwp') ?></em></span>            
            <span id="mainwp_users_loading_info" class="mainwp-grabbing-info-note"> - <?php _e( 'Automatically refreshing to get up to date information.', 'mainwp' ); ?></span>
            <br/><br/>
        </div>
        <div class="clear"></div>

        <div id="mainwp_users_error"></div>
        <div id="mainwp_users_main" <?php if ( $cachedSearch != null ) { echo 'style="display: block;"'; } ?>>
        		<div class="alignleft">
				<select class="mainwp-select2" name="bulk_action" id="mainwp_bulk_action">
					<option value="none"><?php _e( 'Bulk Action', 'mainwp' ); ?></option>
                                        <option value="edit"><?php _e( 'Edit', 'mainwp' ); ?></option>
					<option value="delete"><?php _e( 'Delete', 'mainwp' ); ?></option>
				</select>
				<input type="button" name="" id="mainwp_bulk_user_action_apply" class="button" value="<?php _e( 'Apply', 'mainwp' ); ?>"/>				
			</div>
			<div class="alignright" id="mainwp_users_total_results">
				<?php _e( 'Total Results:', 'mainwp' ); ?>
				<span id="mainwp_users_total"><?php echo $cachedSearch != null ? $cachedSearch['count'] : '0'; ?></span>
			</div>
			<div class="clear"></div>
			<div id="mainwp_users_content">
                            <div id="mainwp_users_wrap_table">
				<?php MainWP_User::renderTable(true); ?>
                            </div>                            
                            <br>                            
                            <div id="mainwp-update-users-box" style="display: none">
                                <?php MainWP_System::do_mainwp_meta_boxes('mainwp_postboxes_update_users'); ?>				
                            </div>
                        
				<div class="clear"></div>
                            <br/><br/>
			</div>
		</div>        
                <?php                
                $current_options = get_option( 'mainwp_opts_saving_status' );
                $col_orders = "";
                if (is_array($current_options) && isset($current_options['users_col_order'])) {
                    $col_orders = $current_options['users_col_order'];
                }                
                ?>                
                <script type="text/javascript"> var usersColOrder = '<?php echo $col_orders; ?>' ; </script>
		<?php
		if ( $cachedSearch != null ) {
                    ?>
                        <script>
                            jQuery(document).ready(function () {                                         
                                mainwp_table_sort_draggable_init('user', 'mainwp_users_table', usersColOrder);                                                       
                            });
                            mainwp_users_table_reinit();
                        </script>
                    <?php
		}
		self::renderFooter( '' );
	}
	
	public static function renderSearchUsers() {
		$cachedSearch = MainWP_Cache::getCachedContext( 'Users' );		
	?>			
			<div class="inside">
				<ul class="mainwp_checkboxes">
					<li>
						<input type="checkbox" id="mainwp_user_role_administrator" <?php echo ( $cachedSearch == null || ( $cachedSearch != null && in_array( 'administrator', $cachedSearch['status'] ) ) ) ? 'checked="checked"' : ''; ?> />
						<label for="mainwp_user_role_administrator" ><?php _e( 'Administrator', 'mainwp' ); ?></label>
					</li>
					<li>
						<input type="checkbox" id="mainwp_user_role_editor" <?php echo ( $cachedSearch != null && in_array( 'editor', $cachedSearch['status'] ) ) ? 'checked="checked"' : ''; ?>/>
						<label for="mainwp_user_role_editor" ><?php _e( 'Editor', 'mainwp' ); ?></label>
					</li>
					<li>
						<input type="checkbox" id="mainwp_user_role_author" <?php echo ( $cachedSearch != null && in_array( 'author', $cachedSearch['status'] ) ) ? 'checked="checked"' : ''; ?> />
						<label for="mainwp_user_role_author" ><?php _e( 'Author', 'mainwp' ); ?></label>
					</li>
					<li>
						<input type="checkbox" id="mainwp_user_role_contributor" <?php echo ( $cachedSearch != null && in_array( 'contributor', $cachedSearch['status'] ) ) ? 'checked="checked"' : ''; ?> />
						<label for="mainwp_user_role_contributor" ><?php _e( 'Contributor', 'mainwp' ); ?></label>
					</li>
					<li>
						<input type="checkbox" id="mainwp_user_role_subscriber" <?php echo ( $cachedSearch != null && in_array( 'subscriber', $cachedSearch['status'] ) ) ? 'checked="checked"' : ''; ?> />
						<label for="mainwp_user_role_subscriber" ><?php _e( 'Subscriber', 'mainwp' ); ?></label>
					</li>
				</ul>
			</div>                        
			<div class="inside">
				<div class="mainwp-padding-top-20 mainwp-padding-bottom-20">
                                        <?php _e('Username', 'mainwp'); ?><br/>
					<input type="text" 
						   aria-required="true" 
						   value="<?php if ( $cachedSearch != null && isset( $cachedSearch['keyword'] ) ) { echo $cachedSearch['keyword']; } ?>"
						   id="mainwp_search_users"
						   size="50" 
						   name="mainwp_search_users">
				</div>
			</div>
		<?php
	}
	
        
        public static function renderUpdateUsers() {
            $editable_roles = array(
                    'donotupdate' => __('Do not update', 'mainwp'), 
                    'administrator' => __('Administrator', 'mainwp'),
                    'subscriber' => __('Subscriber', 'mainwp'),
                    'contributor' => __('Contributor', 'mainwp'),
                    'author' => __('Author', 'mainwp'),
                    'editor' => __('Editor', 'mainwp'),
                    '' => __('&mdash; No role for this site &mdash;', 'mainwp')                                                                                               
                ); 
            
        ?>       
        <div class="mainwp-postbox-actions-top">
        	<?php _e( 'Empty fields will not be passed to child sites.', 'mainwp' ); ?>     
        </div>
        <div class="inside mainwp_inside" style="padding-bottom: .2em !important;">
                <input name="user_login" type="hidden" id="user_login" value="admin">
                <form id="update_user_profile">					
                    <h2><?php _e( 'Name', 'mainwp' ); ?></h2>
                    <table class="form-table">                                            
                        <tr class="user-role-wrap"><th><label for="role"><?php _e('Role', 'mainwp') ?></label></th>
                            <td><select name="role" id="role">
                            <?php
                            foreach($editable_roles as $role_id => $role_name) {                                                                                                        
                                echo '<option value="' . $role_id . '" ' . ( $role_id == 'donotupdate' ? 'selected="selected"' : '' ) .  '>' . $role_name . '</option>';                                                                                                                
                            }                                                
                            ?>
                            </select>
                            </td>
                        </tr>
                        <tr class="user-first-name-wrap">
                              <th><label for="first_name"><?php _e('First Name', 'mainwp') ?></label></th>
                                  <td><input type="text" name="first_name" id="first_name" value="" class="regular-text" /></td>
                        </tr>                                                
                        <tr class="user-last-name-wrap">
                                <th><label for="last_name"><?php _e('Last Name', 'mainwp') ?></label></th>
                                <td><input type="text" name="last_name" id="last_name" value="" class="regular-text" /></td>
                        </tr>
                        <tr class="user-nickname-wrap">
                                <th><label for="nickname"><?php _e('Nickname', 'mainwp'); ?></label></th>
                                <td><input type="text" name="nickname" id="nickname" value="" class="regular-text" /></td>
                        </tr>                                           
                         <tr class="user-display-name-wrap">
                                <th><label for="display_name"><?php _e('Display name publicly as', 'mainwp'); ?></label></th>
                                <td>
                                    <select name="display_name" id="display_name">					
                                    </select>
                                </td>
                        </tr>
                     </table>    

                    <h2><?php _e( 'Contact Info', 'mainwp' ); ?></h2>


                    <table class="form-table">
                    <tr class="user-email-wrap">
                            <th><label for="email"><?php _e('Email', 'mainwp'); ?></label></th>
                            <td><input type="email" name="email" id="email" value="" class="regular-text ltr" />
                            </td>
                    </tr>

                    <tr class="user-url-wrap">
                            <th><label for="url"><?php _e('Website', 'mainwp') ?></label></th>
                            <td><input type="url" name="url" id="url" value="" class="regular-text code" /></td>
                    </tr>
                    </table>
                    <h2><?php _e( 'About the user', 'mainwp' ); ?></h2>
                    <table class="form-table" id="user-profile">
                        <tr class="user-description-wrap">
                                <th><label for="description"><?php _e('Biographical Info', 'mainwp'); ?></label></th>
                                <td><textarea name="description" id="description" rows="5" cols="30"></textarea>
                                <p class="description"><?php _e('Share a little biographical information to fill out your profile. This may be shown publicly.', 'mainwp'); ?></p></td>
                        </tr>
                    </table>                                       

                    <h2><?php _e( 'Account Management', 'mainwp' ); ?></h2>
                    <table class="form-table">
                    <?php
                    global $wp_version;
                    if ( version_compare( '4.3-alpha', $wp_version, '>=' ) ) : ?>
                            <tr class="form-field form-required">
                                <th><label for="pass1"><?php _e( 'New Password', 'mainwp' ); ?></label></th>
                                <td>
                                    <div class="form-field">
                                            <label for="pass1"><?php _e( 'Twice Required', 'mainwp' ); ?></label>

                                            <div><input name="pass1" type="password" id="pass1" autocomplete="off"/></div>
                                            <div><input name="pass2" type="password" id="pass2" autocomplete="off"/></div>
                                    </div>
                                    <div id="pass-strength-result" style="display: block"><?php _e( 'Strength Indicator', 'mainwp' ); ?></div>
                                </td>
                            </tr>    
                    <?php else : ?>	
                                <tr id="password" class="user-pass1-wrap">
                                        <th><label for="pass1"><?php _e( 'New Password', 'mainwp' ); ?></label></th>
                                        <td>
                                                <input class="hidden" value=" "/>                                                                        
                                                <button type="button" class="button button-secondary wp-generate-pw hide-if-no-js"><?php _e( 'Generate Password', 'mainwp' ); ?></button>
                                                <div class="wp-pwd hide-if-js">
                                                        <span class="password-input-wrapper">
                                                                <input type="password" name="pass1" id="pass1" class="regular-text" value="" autocomplete="off" data-pw="<?php echo esc_attr( wp_generate_password( 24 ) ); ?>" aria-describedby="pass-strength-result" />
                                                        </span>
                                                        <button type="button" class="button button-secondary wp-hide-pw hide-if-no-js" data-toggle="0" aria-label="<?php esc_attr_e( 'Hide password', 'mainwp' ); ?>">
                                                                <span class="dashicons dashicons-hidden"></span>
                                                                <span class="text"><?php _e( 'Hide' ); ?></span>
                                                        </button>
                                                        <button type="button" class="button button-secondary wp-cancel-pw hide-if-no-js" data-toggle="0" aria-label="<?php esc_attr_e( 'Cancel password change', 'mainwp' ); ?>">
                                                                <span class="text"><?php _e( 'Cancel' ); ?></span>
                                                        </button>
                                                        <div style="display:none" id="pass-strength-result" aria-live="polite"></div>
                                                </div>
                                                <p class="description indicator-hint"><?php _e('Hint: The password should be at least seven characters long. To make it stronger, use upper and lower case letters, numbers and symbols like ! " ? $ % ^ &amp; ).','mainwp'); ?></p>
                                        </td>
                                </tr>
                                <tr class="form-field form-required user-pass2-wrap hide-if-js">
                                        <td>
                                                <input name="pass2" type="password" id="pass2" value="" autocomplete="off"/>
                                        </td>
                                </tr>						
                    <?php endif; ?>
                    </table> 
                    <br>
                    <p style="text-align: center;">
                            <input type="button" value="<?php _e( 'Update', 'mainwp' ); ?>" class="button-primary"
                                    id="mainwp_btn_update_user" name="mainwp_btn_update_user">
                            <span id="mainwp_users_updating"><i class="fa fa-spinner fa-pulse"></i></span>
                    </p>

                    <p>

                    <div id="mainwp_update_password_error" style="display: none"></div>
                    </p>
                </form>
            </div>
                        <div class="clear"></div>

            <?php
        }
        
        public static function renderTable( $cached = true, $role = '', $groups = '', $sites = '', $search = null ) {
            ?>
            <table class="wp-list-table widefat fixed pages tablesorter fix-select-all-ajax-table" id="mainwp_users_table"
                        cellspacing="0">
                        <thead>
                        <tr>
                                <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input
                                                type="checkbox"></th>
                                <th scope="col" data-sorter="false" id="avatar" class="drag-enable manage-column column-avatar" style="">
                                        <div class="header-wrap"><span><?php _e( 'Avatar', 'mainwp' ); ?></span></div>
                                </th>
                                <th scope="col" id="name" class="drag-enable manage-column column-author sortable desc" style="">
                                        <a href="#" onclick="return false;"><span><?php _e( 'Name', 'mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                </th>
                                <th scope="col" id="username" class="drag-enable manage-column column-username sortable desc" style="">
                                        <a href="#" onclick="return false;"><span><?php _e( 'Username', 'mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                </th>                                
                                <th scope="col" id="email" class="drag-enable manage-column column-email sortable desc" style="">
                                        <a href="#" onclick="return false;"><span><?php _e( 'E-mail', 'mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                </th>
                                <th scope="col" id="role" class="drag-enable manage-column column-role sortable desc" style="">
                                        <a href="#" onclick="return false;"><span><?php _e( 'Role', 'mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                </th>
                                <th scope="col" id="posts" class="drag-enable manage-column column-posts sortable desc" style="">
                                        <a href="#" onclick="return false;"><span><?php _e( 'Posts', 'mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                </th>
                                <th scope="col" id="website" class="drag-enable manage-column column-website sortable desc" style="">
                                        <a href="#" onclick="return false;"><span><?php _e( 'Website', 'mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                </th>
                        </tr>
                        </thead>

                        <tfoot>
                        <tr>
                                <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input
                                                type="checkbox"></th>
                                <th scope="col" data-sorter="false" id="avatar" class="drag-enable manage-column column-avatar" style="">
                                        <span><?php _e( 'Avatar', 'mainwp' ); ?></span>
                                </th>
                                <th scope="col" id="name" class="manage-column column-author sortable desc" style="">
                                        <a href="#" onclick="return false;"><span><?php _e( 'Name', 'mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                </th>
                                <th scope="col" id="username" class="manage-column column-username sortable desc" style="">
                                        <a href="#" onclick="return false;"><span><?php _e( 'Username', 'mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                </th>                                
                                <th scope="col" id="email" class="manage-column column-email sortable desc" style="">
                                        <a href="#" onclick="return false;"><span><?php _e( 'E-mail', 'mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                </th>
                                <th scope="col" id="role" class="manage-column column-role sortable desc" style="">
                                        <a href="#" onclick="return false;"><span><?php _e( 'Role', 'mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                </th>
                                <th scope="col" id="posts" class="manage-column column-posts sortable desc" style="">
                                        <a href="#" onclick="return false;"><span><?php _e( 'Posts', 'mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                </th>
                                <th scope="col" id="website" class="manage-column column-website sortable desc" style="">
                                        <a href="#" onclick="return false;"><span><?php _e( 'Website', 'mainwp' ); ?></span><span class="sorting-indicator"></span></a>
                                </th>
                        </tr>
                        </tfoot>

                        <tbody id="the-list" class="list:user">
                        <?php 
                        if ($cached)
                            MainWP_Cache::echoBody( 'Users' ); 
                        else
                            MainWP_User::renderTableBody($role, $groups, $sites, $search);
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
                                <span>&nbsp;&nbsp;<?php _e( 'Show:', 'mainwp' ); ?> </span><select class="mainwp-select2 pagesize">
                                        <option selected="selected" value="10">10</option>
                                        <option value="25">25</option>
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                        <option value="1000000000">All</option>
                                </select><span> <?php _e( 'Users per page', 'mainwp' ); ?></span>
                        </form>
                </div>            
            <?php            
        }
        
        public static function renderTableBody( $role = '', $groups = '', $sites = '', $search = null ) {
		MainWP_Cache::initCache( 'Users' );

		$output         = new stdClass();
		$output->errors = array();
		$output->users  = 0;
                
		if ( get_option( 'mainwp_optimize' ) == 1 ) {
                        
                        $check_users_role = false;                                
                        if ( !empty($role) ) {
                            $roles = explode( ',', $role );
                            if ( is_array( $roles ) ) {
                                $check_users_role = true;
                            }
                        }
                        
			//Search in local cache
			if ( $sites != '' ) {
                                foreach ( $sites as $k => $v ) {
                                    if ( MainWP_Utility::ctype_digit( $v ) ) {
                                            $search_user_role = array();
                                            $website  = MainWP_DB::Instance()->getWebsiteById( $v );
                                            $allUsers = json_decode( $website->users, true );
                                            
                                            if ($check_users_role) {
                                                for ( $i = 0; $i < count( $allUsers ); $i ++ ) {
                                                    $user = $allUsers[ $i ];
                                                    foreach ( $roles as $_role ) {
                                                        if ( stristr( $user['role'], $_role ) ) {
                                                                if (!in_array($user['id'], $search_user_role))
                                                                    $search_user_role[] = $user['id'];											
                                                                break;
                                                        }
                                                    }
                                                }
                                            }
                                            
                                            for ( $i = 0; $i < count( $allUsers ); $i ++ ) {
                                                $user = $allUsers[ $i ];
                                                if ( $search != '' && ! stristr( $user['login'], trim( $search ) ) && ! stristr( $user['display_name'], trim( $search ) ) && ! stristr( $user['email'], trim( $search ) ) ) {
                                                    continue;
                                                }
                                                
                                                if ($check_users_role) {
                                                    if (!in_array($user['id'], $search_user_role))
                                                        continue;
                                                }

                                                $tmpUsers = array( $user );
                                                $output->users += self::usersSearchHandlerRenderer( $tmpUsers, $website );                                                        
                                            }
                                    }
                                }                                                            
			}
			if ( $groups != '' ) {
				foreach ( $groups as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $v ) );
						while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
							if ( $website->sync_errors != '' ) {
								continue;
							}
							$allUsers = json_decode( $website->users, true );
                                                        if ($check_users_role) {
                                                            for ( $i = 0; $i < count( $allUsers ); $i ++ ) {
                                                                $user = $allUsers[ $i ];
                                                                foreach ( $roles as $_role ) {
                                                                    if ( stristr( $user['role'], $_role ) ) {
                                                                            if (!in_array($user['id'], $search_user_role))
                                                                                $search_user_role[] = $user['id'];											
                                                                            break;
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        for ( $i = 0; $i < count( $allUsers ); $i ++ ) {
                                                            $user = $allUsers[ $i ];
                                                            if ( $search != '' && ! stristr( $user['login'], trim( $search ) ) && ! stristr( $user['display_name'], trim( $search ) ) && ! stristr( $user['email'], trim( $search ) ) ) {
                                                                continue;
                                                            }

                                                            if ($check_users_role) {
                                                                if (!in_array($user['id'], $search_user_role))
                                                                    continue;
                                                            }

                                                            $tmpUsers = array( $user );
                                                            $output->users += self::usersSearchHandlerRenderer( $tmpUsers, $website );                                                        
                                                        }
						}
						@MainWP_DB::free_result( $websites );
					}
				}
			}
		} else {
			//Fetch all!
			//Build websites array
			$dbwebsites = array();
			if ( $sites != '' ) {
				foreach ( $sites as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$website                    = MainWP_DB::Instance()->getWebsiteById( $v );
						$dbwebsites[ $website->id ] = MainWP_Utility::mapSite( $website, array(
							'id',
							'url',
							'name',
							'adminname',
							'nossl',
							'privkey',
							'nosslkey',
						) );
					}
				}
			}
			if ( $groups != '' ) {
				foreach ( $groups as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $v ) );
						while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
							if ( $website->sync_errors != '' ) {
								continue;
							}
							$dbwebsites[ $website->id ] = MainWP_Utility::mapSite( $website, array(
								'id',
								'url',
								'name',
								'adminname',
								'nossl',
								'privkey',
								'nosslkey',
							) );
						}
						@MainWP_DB::free_result( $websites );
					}
				}
			}

                        $post_data = array(
                                'role' => $role,
                                'search'         => '*' . trim( $search ) . '*',
                                'search_columns' => 'user_login,display_name,user_email',
                        );

                        MainWP_Utility::fetchUrlsAuthed( $dbwebsites, 'search_users', $post_data, array(
                                MainWP_User::getClassName(),
                                'UsersSearch_handler',
                        ), $output );                        
		}

		MainWP_Cache::addContext( 'Users', array(
			'count'   => $output->users,
			'keyword' => $search,
			'status'  => ( isset( $_POST['role'] ) ? $_POST['role'] : 'administrator' ),
                        'sites' => $sites != '' ? $sites : '',
                        'groups' => $groups != '' ? $groups : ''
		) );
		//Sort if required

		if ( $output->users == 0 ) {
			ob_start();
			?>
			<tr>
				<td colspan="7">No users found</td>
			</tr>
			<?php
			$newOutput = ob_get_clean();
			echo $newOutput;
			MainWP_Cache::addBody( 'Users', $newOutput );

			return;
		}
	}

	private static function getRole( $role ) {
		if ( is_array( $role ) ) {
			$allowed_roles = array( 'subscriber', 'administrator', 'editor', 'author', 'contributor' );
			$ret           = '';
			foreach ( $role as $ro ) {
				if ( in_array( $ro, $allowed_roles ) ) {
					$ret .= ucfirst( $ro ) . ', ';
				}
			}
			$ret = rtrim( $ret, ', ' );
			if ( $ret == '' ) {
				$ret = 'None';
			}

			return $ret;
		}

		return ucfirst( $role );
	}

	protected static function usersSearchHandlerRenderer( $users, $website ) {
		$return = 0;
		foreach ( $users as $user ) {
			ob_start();
			?>
			<tr id="user-1" class="alternate">
				<th scope="row" class="check-column"><input type="checkbox" name="user[]" value="1"></th>
                                <td class="username column-avatar">
                                    <?php if ( isset( $user['avatar'] ) ) {
						echo $user['avatar'];
					} ?>
                                </td>
                                <td class="name column-name">
                                    <input class="userId" type="hidden" name="id" value="<?php echo $user['id']; ?>"/>
					<input class="userName" type="hidden" name="name" value="<?php echo $user['login']; ?>"/>
					<input class="websiteId" type="hidden" name="id"
						value="<?php echo $website->id; ?>"/>
					<?php echo !empty($user['display_name']) ? $user['display_name'] : '&nbsp;' ; ?>
					<div class="row-actions">
                    <span class="edit"><a class="user_getedit"
		                    href="#"
		                    title="Edit this user"><?php _e( 'Edit', 'mainwp' ); ?></a>
                    </span>
						<?php if ( ( $user['id'] != 1 ) && ( $user['login'] != $website->adminname ) ) { ?>
							<span class="trash">
                        | <a class="user_submitdelete" title="Delete this user" href="#"><?php _e( 'Delete', 'mainwp' ); ?></a>
                    </span>
						<?php } else if ( ( $user['id'] == 1 ) || ( $user['login'] == $website->adminname ) ) { ?>
							<span class="trash">
                        | <span title="This user is used for our secure link, it can not be deleted." style="color: gray"><strong><?php _e( 'Delete', 'mainwp' ); ?></strong>&nbsp;&nbsp;<?php MainWP_Utility::renderToolTip( __( 'This user is used for our secure link, it can not be deleted.', 'mainwp' ), 'http://docs.mainwp.com/deleting-secure-link-admin', 'images/info.png', 'float: none !important;' ); ?></span>
                    </span>
						<?php } ?>
					</div>
					<div class="row-actions-working">
						<i class="fa fa-spinner fa-pulse"></i> <?php _e( 'Please wait', 'mainwp' ); ?>
					</div>
                                
                                </td>                                
				<td class="username column-username">
					<strong><abbr title="<?php echo $user['login']; ?>"><?php echo $user['login']; ?></abbr></strong>
				</td>				
				<td class="email column-email"><a
						href="mailto:<?php echo $user['email']; ?>"><?php echo $user['email']; ?></a></td>
				<td class="role column-role"><?php echo self::getRole( $user['role'] ); ?></td>
				<td class="posts column-posts" style="text-align: left; padding-left: 1.7em ;">
					<a href="<?php echo admin_url( 'admin.php?page=PostBulkManage&siteid=' . $website->id . '&userid=' . $user['id'] ); ?>"><?php echo $user['post_count']; ?></a>
				</td>
				<td class="website column-website"><a
						href="<?php echo $website->url; ?>"><?php echo $website->url; ?></a>

					<div class="row-actions">
						<span class="edit"><a href="admin.php?page=managesites&dashboard=<?php echo $website->id; ?>"><?php _e( 'Overview', 'mainwp' ); ?></a> | <a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $website->id; ?>" target="_blank"><?php _e( 'WP Admin', 'mainwp' ); ?></a></span>
					</div>
				</td>
			</tr>
			<?php
			$newOutput = ob_get_clean();
			echo $newOutput;
			MainWP_Cache::addBody( 'Users', $newOutput );
			$return ++;
		}

		return $return;
	}

	public static function UsersSearch_handler( $data, $website, &$output ) {
		if ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 ) {
			$users = unserialize( base64_decode( $results[1] ) );         
                        unset( $results );
			$output->users += self::usersSearchHandlerRenderer( $users, $website );
			unset( $users );
		} else {
			$output->errors[ $website->id ] = MainWP_Error_Helper::getErrorMessage( new MainWP_Exception( 'NOMAINWP', $website->url ) );
		}
	}

	public static function delete() {
		MainWP_User::action( 'delete' );
		die( json_encode( array( 'result' => 'User has been deleted' ) ) );
	}

        public static function edit() {
		$information = MainWP_User::action( 'edit' );
		die( json_encode( $information ) );
	}
        
        public static function updateUser() {
		MainWP_User::action( 'update_user' );
		die( json_encode( array( 'result' => 'User has been updated' ) ) );
	}
        
	public static function updatePassword() {
		MainWP_User::action( 'update_password' );
		die( json_encode( array( 'result' => 'User password has been updated' ) ) );
	}

	public static function action( $pAction, $extra = '' ) {
		$userId       = $_POST['userId'];
		$userName     = $_POST['userName'];
		$websiteIdEnc = $_POST['websiteId'];
		$pass         = $_POST['update_password'];

		if ( ! MainWP_Utility::ctype_digit( $userId ) ) {
			die( json_encode( array( 'error' => 'Invalid request!' ) ) );
		}
		$websiteId = $websiteIdEnc;
		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( json_encode( array( 'error' => 'Invalid request!' ) ) );
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $websiteId );
		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			die( json_encode( array( 'error' => 'You can not edit this website!' ) ) );
		}

		if ( ( $pAction == 'delete' ) && ( $website->adminname == $userName ) ) {
			die( json_encode( array( 'error' => __( 'This user is used for our secure link, it can not be deleted.', 'mainwp' ) ) ) );
		}
                if ($pAction == 'update_user') {
                    $user_data = $_POST['user_data'];
                    parse_str( $user_data, $extra);  
                    if ( $website->adminname == $userName ) {                       
			//This user is used for our secure link, you can not change the role.                        
                        if (is_array($extra) && isset($extra['role'])) {                                
                                unset($extra['role']);
                        }
                    }
                }   
                $optimize = ( get_option( 'mainwp_optimize' ) == 1 ) ? 1 : 0;
		try {
			$information = MainWP_Utility::fetchUrlAuthed( $website, 'user_action', array(
				'action'    => $pAction,
				'id'        => $userId,
				'extra'     => $extra,
				'user_pass' => $pass,
                                'optimize'  => $optimize,
			) );
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array( 'error' => MainWP_Error_Helper::getErrorMessage($e) ) ) );
		}
                
                if (is_array($information) && isset($information['error'])) {
                        die( json_encode( array( 'error' => $information['error'] ) ) );
                }
                
		if ( ! isset( $information['status'] ) || ( $information['status'] != 'SUCCESS' ) ) {
			die( json_encode( array( 'error' => 'Unexpected error.' ) ) );
		} else if ('update_user' === $pAction) {
                    if ($optimize && isset($information['users'])) {
                        $websiteValues['users'] = json_encode($information['users']);
                        MainWP_DB::Instance()->updateWebsiteValues( $websiteId, $websiteValues );
                    }
                }
                
                if ('edit' === $pAction) {
                    if ( $website->adminname == $userName ) {
			//This user is used for our secure link, you can not change the role.                        
                        if (is_array($information) && isset($information['user_data'])) {                                
                                $information['is_secure_admin'] = 1;
                        }
                    }                
                }
                    
                return $information;
	}

	public static function renderBulkAdd() {		
		?>
		<?php self::renderHeader( 'Add' ); ?>
		<?php if ( isset( $errors ) && count( $errors ) > 0 ) { ?>
			<div class="error below-h2">
				<?php foreach ( $errors as $error ) { ?>
					<p><strong>ERROR</strong>: <?php echo $error ?></p>
				<?php } ?>
			</div>
		<?php } ?>
		<div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
		<div id="MainWP_Bulk_AddUserLoading" class="updated">
            <div><i class="fa fa-spinner fa-pulse"></i> <?php _e('Adding the user','mainwp'); ?></div>
        </div>
		<div id="MainWP_Bulk_AddUser">
		<div class="mainwp-padding-bottom-10"><?php MainWP_Tours::renderCreateNewUserTours(); ?></div>
			<form action="" method="post" name="createuser" id="createuser" class="add:users: validate">
				<div class="mainwp_config_box_left" style="width: calc(100% - 290px);">
					<?php MainWP_System::do_mainwp_meta_boxes('mainwp_postboxes_add_user'); ?>				
				</div>
				<div class="mainwp_config_box_right">
					<?php MainWP_UI::select_sites_box( __( 'Step 2: Select sites', 'mainwp' ) ); ?>
					<input type="button" name="createuser" id="bulk_add_createuser" class="button-primary button button-hero mainwp-right"
						value="<?php _e( 'Add New User', 'mainwp' ); ?> "/>
				</div>
				<div class="mainwp-clear"></div>
			</form>
		</div>
		<?php
		self::renderFooter( 'Add' );
	}
	
	public static function renderBulkImportUsers() {
		if ( isset($_FILES['import_user_file_bulkupload']) && $_FILES['import_user_file_bulkupload']['error'] == UPLOAD_ERR_OK ) {			
			self::renderBulkUpload();			
			return;
		}
		?>
		<?php self::renderHeader( 'Import' ); ?>
		<?php if ( isset( $errors ) && count( $errors ) > 0 ) { ?>
			<div class="error below-h2">
				<?php foreach ( $errors as $error ) { ?>
					<p><strong>ERROR</strong>: <?php echo $error ?></p>
				<?php } ?>
			</div>
		<?php } ?>
		<div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>		
		<div id="MainWP_Bulk_AddUser">
		<div class="mainwp-padding-bottom-10"><?php MainWP_Tours::renderUsersImportTour(); ?></div>
			<form action="" method="post" name="createuser" id="createuser" class="add:users: validate" enctype="multipart/form-data">								
				<?php MainWP_System::do_mainwp_meta_boxes('mainwp_postboxes_bulk_import_users'); ?>									
				<input type="button" name="createuser" id="bulk_import_createuser" class="button-primary button button-hero"
					value="<?php _e( 'Import Users', 'mainwp' ); ?> "/>
			</form>
		</div>
		<?php
		self::renderFooter( 'Import' );
	}
	
	public static function renderAddUser() {
		?>
		<div class="inside">
		<table class="form-table">
			<tr class="form-field form-required">
				<th scope="row"><label for="user_login"><?php _e( 'Username', 'mainwp' ); ?>
						<span class="description"><?php _e( '(required)', 'mainwp' ); ?></span></label>
				</th>
				<td>
					<input class="" name="user_login" type="text" id="user_login" value="<?php
					if ( isset( $_POST['user_login'] ) ) {
						echo $_POST['user_login'];
					}
					?>" aria-required="true"/></td>
			</tr>
			<tr class="form-field form-required">
				<th scope="row"><label for="email"><?php _e( 'E-mail', 'mainwp' ); ?> <span
							class="description"><?php _e( '(required)', 'mainwp' ); ?></span></label>
				</th>
				<td>
					<input class="" name="email" type="text" id="email" value="<?php
					if ( isset( $_POST['email'] ) ) {
						echo $_POST['email'];
					}
					?>"/></td>
			</tr>
			<tr class="form-field">
				<th scope="row">
					<label for="first_name"><?php _e( 'First name', 'mainwp' ); ?> </label></th>
				<td>
					<input class="" name="first_name" type="text" id="first_name" value="<?php
					if ( isset( $_POST['first_name'] ) ) {
						echo $_POST['first_name'];
					}
					?>"/></td>
			</tr>
			<tr class="form-field">
				<th scope="row">
					<label for="last_name"><?php _e( 'Last name', 'mainwp' ); ?> </label></th>
				<td>
					<input class="" name="last_name" type="text" id="last_name" value="<?php
					if ( isset( $_POST['last_name'] ) ) {
						echo $_POST['last_name'];
					}
					?>"/></td>
			</tr>
			<tr class="form-field">
				<th scope="row"><label for="url"><?php _e( 'Website', 'mainwp' ); ?></label></th>
				<td>
					<input class="" name="url" type="text" id="url" class="code" value="<?php
					if ( isset( $_POST['url'] ) ) {
						echo $_POST['url'];
					}
					?>"/></td>
			</tr>
			<?php
			global $wp_version;
			if ( version_compare( '4.3-alpha', $wp_version, '>=' ) ) : ?>
				<tr class="form-field form-required">
					<th scope="row"><label for="pass1"><?php _e( 'Password', 'mainwp' ); ?> <span
								class="description"><?php _e( '(twice, required)', 'mainwp' ); ?></span></label>
					</th>
					<td>
						<input class="" name="pass1" type="password" id="pass1" autocomplete="off"/>
						<br/>
						<input class="" name="pass2" type="password" id="pass2" autocomplete="off"/>
						<br/>

						<div id="pass-strength-result" style="display: block"><?php _e( 'Strength Indicator', 'mainwp' ); ?></div>
						<br><br>
					</td>
				</tr>

			<?php else : ?>
				<tr class="form-field form-required user-pass1-wrap">
					<th scope="row">
						<label for="pass1">
							<?php _e( 'New password', 'mainwp' ); ?>
							<span class="description hide-if-js"><?php _e( '(required)', 'mainwp' ); ?></span>
						</label>
					</th>
					<td>
                                            <div class="pw-wrap">
						<input class="hidden" value=" "/><!-- #24364 workaround -->
						<button type="button" class="button button-secondary wp-generate-pw hide-if-no-js"><?php _e( 'Generate Password', 'mainwp' ); ?></button>
                                                <div class="wp-pwd hide-if-js">
                                                        <span class="password-input-wrapper">
                                                                <input type="password" name="pass1" id="pass1" class="regular-text" value="" autocomplete="off" data-pw="<?php echo esc_attr( wp_generate_password( 24 ) ); ?>" aria-describedby="pass-strength-result" />
                                                        </span>
                                                        <button type="button" class="button button-secondary wp-hide-pw hide-if-no-js" data-toggle="0" aria-label="<?php esc_attr_e( 'Hide password', 'mainwp' ); ?>">
                                                                <span class="dashicons dashicons-hidden"></span>
                                                                <span class="text"><?php _e( 'Hide' ); ?></span>
                                                        </button>
                                                        <button type="button" class="button button-secondary wp-cancel-pw hide-if-no-js" data-toggle="0" aria-label="<?php esc_attr_e( 'Cancel password change', 'mainwp' ); ?>">
                                                                <span class="text"><?php _e( 'Cancel' ); ?></span>
                                                        </button>
                                                        <div style="display:none" id="pass-strength-result" aria-live="polite"></div>
                                                </div>
                                            </div>
					</td>
				</tr>
				<tr class="form-field form-required user-pass2-wrap hide-if-js">
					<td scope="row"><label for="pass2"><?php _e( 'Repeat password', 'mainwp' ); ?>
							<span class="description"><?php _e( '(required)', 'mainwp' ); ?></span></label>
					</td>
					<td>
						<input name="pass2" type="password" id="pass2" value="<?php echo esc_attr( $initial_password ); ?>" autocomplete="off"/>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<td></td>
				<td>
					<p class="description indicator-hint"><?php _e( 'Hint: The password should be at least seven
				characters long. To make it stronger, use upper and lower case letters, numbers and
				symbols like ! " ? $ % ^ &amp; ).', 'mainwp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="send_password"><?php _e( 'Send password?', 'mainwp' ); ?></label>
				</th>
				<td><label for="send_password"><input type="checkbox" name="send_password"
							id="send_password" <?php
						if ( isset( $_POST['send_password'] ) ) {
							echo 'checked';
						}
						?> /> <?php _e( 'Send this password to the new user by email.', 'mainwp' ); ?>
					</label></td>
			</tr>
			<tr class="form-field">
				<th scope="row"><label for="role"><?php _e( 'Role', 'mainwp' ); ?></label></th>
				<td>
					<select class="mainwp-select2" name="role" id="role">
						<option value='subscriber' <?php
						if ( isset( $_POST['role'] ) && $_POST['role'] == 'subscriber' ) {
							echo 'selected';
						}
						?>><?php _e( 'Subscriber', 'mainwp' ); ?>
						</option>
						<option value='administrator' <?php
						if ( isset( $_POST['role'] ) && $_POST['role'] == 'administrator' ) {
							echo 'selected';
						}
						?>><?php _e( 'Administrator', 'mainwp' ); ?>
						</option>
						<option value='editor' <?php
						if ( isset( $_POST['role'] ) && $_POST['role'] == 'editor' ) {
							echo 'selected';
						}
						?>><?php _e( 'Editor', 'mainwp' ); ?>
						</option>
						<option value='author' <?php
						if ( isset( $_POST['role'] ) && $_POST['role'] == 'author' ) {
							echo 'selected';
						}
						?>><?php _e( 'Author', 'mainwp' ); ?>
						</option>
						<option value='contributor' <?php
						if ( isset( $_POST['role'] ) && $_POST['role'] == 'contributor' ) {
							echo 'selected';
						}
						?>><?php _e( 'Contributor', 'mainwp' ); ?>
						</option>
					</select>
				</td>
			</tr>
		</table>
		</div>
		<?php
	}
	
	public static function renderImportUsers() {
		?>
		<div class="mainwp-postbox-actions-top">
			<?php _e( 'Import users allows you to add a large number of users at once by uploading a CSV file.', 'mainwp' ); ?>
		</div>
		<div class="inside">
			<table>
				<tr>
					<th scope="row"></th>
					<td>
						<input type="file" 
							   name="import_user_file_bulkupload"
							   id="import_user_file_bulkupload" 
							   accept="text/comma-separated-values"
							   class="regular-text"/>
							<p>
								<input type="checkbox" 
									   name="import_user_chk_header_first" 
									   checked="checked"
									   id="import_user_chk_header_first" 
									   value="1"/>
								<span class="description"><?php _e( 'CSV file contains a header.', 'mainwp' ); ?></span>
							</p>
						</div>
					</td>
				</tr>
			</table>
		</div>
		<div class="mainwp-postbox-actions-bottom">
			<?php _e( 'File must be in CSV format.', 'mainwp' ); ?> <a href="https://mainwp.com/csv/sample_users.csv" target="_blank"><?php _e( 'Click here to download sample CSV file.', 'mainwp' ); ?></a>
		</div>
		<?php
	}
	
	public static function doPost() {
		$errors      = array();
		$errorFields = array();
		if ( isset( $_POST['select_by'] ) ) {
			$selected_sites = array();
			if ( isset( $_POST['selected_sites'] ) && is_array( $_POST['selected_sites'] ) ) {
				foreach ( $_POST['selected_sites'] as $selected ) {
					$selected_sites[] = $selected;
				}
			}

			$selected_groups = array();
			if ( isset( $_POST['selected_groups'] ) && is_array( $_POST['selected_groups'] ) ) {
				foreach ( $_POST['selected_groups'] as $selected ) {
					$selected_groups[] = $selected;
				}
			}
			if ( ( $_POST['select_by'] == 'group' && count( $selected_groups ) == 0 ) || ( $_POST['select_by'] == 'site' && count( $selected_sites ) == 0 ) ) {
				$errors[] = 'Please select the sites or groups you want to add the new user to.';
			}
		} else {
			$errors[] = 'Please select whether you want to add the user to specific sites or groups.';
		}
		if ( ! isset( $_POST['user_login'] ) || $_POST['user_login'] == '' ) {
			$errorFields[] = 'user_login';
		}
		if ( ! isset( $_POST['email'] ) || $_POST['email'] == '' ) {
			$errorFields[] = 'email';
		}
		if ( ! isset( $_POST['pass1'] ) || $_POST['pass1'] == '' || ! isset( $_POST['pass2'] ) || $_POST['pass2'] == '' ) {
			$errorFields[] = 'pass1';
		} else if ( $_POST['pass1'] != $_POST['pass2'] ) {
			$errorFields[] = 'pass2';
		}
		$allowed_roles = array( 'subscriber', 'administrator', 'editor', 'author', 'contributor' );
		if ( ! isset( $_POST['role'] ) || ! in_array( $_POST['role'], $allowed_roles ) ) {
			$errorFields[] = 'role';
		}

		if ( ( count( $errors ) == 0 ) && ( count( $errorFields ) == 0 ) ) {
			$user_to_add = array(
				'user_pass'  => $_POST['pass1'],
				'user_login' => $_POST['user_login'],
				'user_url'   => $_POST['url'],
				'user_email' => $_POST['email'],
				'first_name' => $_POST['first_name'],
				'last_name'  => $_POST['last_name'],
				'role'       => $_POST['role'],
			);

			$dbwebsites = array();
			if ( $_POST['select_by'] == 'site' ) { //Get all selected websites
				foreach ( $selected_sites as $k ) {
					if ( MainWP_Utility::ctype_digit( $k ) ) {
						$website                    = MainWP_DB::Instance()->getWebsiteById( $k );
						$dbwebsites[ $website->id ] = MainWP_Utility::mapSite( $website, array(
							'id',
							'url',
							'name',
							'adminname',
							'nossl',
							'privkey',
							'nosslkey',
						) );
					}
				}
			} else { //Get all websites from the selected groups
				foreach ( $selected_groups as $k ) {
					if ( MainWP_Utility::ctype_digit( $k ) ) {
						$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $k ) );
						while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
							$dbwebsites[ $website->id ] = MainWP_Utility::mapSite( $website, array(
								'id',
								'url',
								'name',
								'adminname',
								'nossl',
								'privkey',
								'nosslkey',
							) );
						}
						@MainWP_DB::free_result( $websites );
					}
				}
			}
			$startTime = time();
			if ( count( $dbwebsites ) > 0 ) {
				$post_data      = array(
					'new_user'      => base64_encode( serialize( $user_to_add ) ),
					'send_password' => ( isset( $_POST['send_password'] ) ? $_POST['send_password'] : '' ),
				);
				$output         = new stdClass();
				$output->ok     = array();
				$output->errors = array();
				MainWP_Utility::fetchUrlsAuthed( $dbwebsites, 'newuser', $post_data, array(
					MainWP_Bulk_Add::getClassName(),
					'PostingBulk_handler',
				), $output );
			}

            $countSites = $countRealItems = 0;
			foreach ( $dbwebsites as $website ) {
				if ( isset( $output->ok[ $website->id ] ) && $output->ok[ $website->id ] == 1 ) {
					$countSites ++;
                    $countRealItems++;
				}
			}

			if ( ! empty( $countSites ) ) {
				$seconds = ( time() - $startTime );
				MainWP_Twitter::updateTwitterInfo( 'create_new_user', $countSites, $seconds, $countRealItems, $startTime, 1 );
			}

			if ( MainWP_Twitter::enabledTwitterMessages() ) {
				$twitters = MainWP_Twitter::getTwitterNotice( 'create_new_user' );
				if ( is_array( $twitters ) ) {
					foreach ( $twitters as $timeid => $twit_mess ) {
						if ( ! empty( $twit_mess ) ) {
							$sendText = MainWP_Twitter::getTwitToSend( 'create_new_user', $timeid );
							?>
							<div class="mainwp-tips mainwp-notice mainwp-notice-blue twitter">
								<span class="mainwp-tip" twit-what="create_new_user" twit-id="<?php echo $timeid; ?>"><?php echo $twit_mess; ?></span>&nbsp;<?php MainWP_Twitter::genTwitterButton( $sendText ); ?>
								<span><a href="#" class="mainwp-dismiss-twit mainwp-right"><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss', 'mainwp' ); ?>
									</a></span></div>
							<?php
						}
					}
				}
			}

			?>
			<div class="mainwp-notice mainwp-notice-green">
				<?php foreach ( $dbwebsites as $website ) { ?>
                                            <a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a>
                                            : <?php echo( isset( $output->ok[ $website->id ] ) && $output->ok[ $website->id ] == 1 ? 'New user created.' : 'ERROR: ' . $output->errors[ $website->id ] ); ?><br/>
				<?php } ?>
			</div>
			<br/>
			<a href="<?php echo get_admin_url() ?>admin.php?page=UserBulkAdd" class="add-new-h2" target="_top"><?php _e( 'Add new', 'mainwp' ); ?></a>
			<a href="<?php echo get_admin_url() ?>admin.php?page=mainwp_tab" class="add-new-h2" target="_top"><?php _e( 'Return to
            Overview', 'mainwp' ); ?></a>
			<?php
		} else {
			echo 'ERROR ' . json_encode( array( $errorFields, $errors ) );
		}
	}

	public static function renderBulkUpload() {
		self::renderHeader( 'Import' );
		?>
		<div id="MainWPBulkUploadUserLoading" class="updated" style="display: none;">
            <div><i class="fa fa-spinner fa-pulse"></i> <?php _e('Importing Users','mainwp'); ?></div>
        </div>
		<div id="MainWPBulkUploadUser">
			<?php
			$errors = array();
			if ( $_FILES['import_user_file_bulkupload']['error'] == UPLOAD_ERR_OK ) {
				if ( is_uploaded_file( $_FILES['import_user_file_bulkupload']['tmp_name'] ) ) {
					$content = file_get_contents( $_FILES['import_user_file_bulkupload']['tmp_name'] );
					 $lines = explode( "\r", $content );

					if ( is_array( $lines ) && count( $lines ) > 0 ) {
						  $i = 0;
						if ( $_POST['import_user_chk_header_first'] ) {
							$header_line = trim( $lines[0] )."\n";
							unset( $lines[0] );
						}

						foreach ( $lines as $line ) {
							$line = trim( $line );
							$items = explode( ',', $line );

							$line = trim( $items[0] ) . ',' . trim( $items[1] ) .','. trim( $items[2] ) . ',' . trim( $items[3] ) . ',' . trim( $items[4] ) . ',' . trim( $items[5] ). ',' . intval( $items[6] ) . ',' . trim( strtolower( $items[7] ) ). ',' . trim( $items[8] ) . ',' . trim( $items[9] );

							?>
						   <input type="hidden" id="user_import_csv_line_<?php echo ($i + 1) // to starting by 1 ?>"  value="<?php echo $line ?>"/>
							<?php
							$i++;
						}

							?>
						   <d   v class="postbox">
						   <h3 class="mainwp_box_title"><i class="fa fa-user-plus"></i> <?php _e('Importing new users and add them to your sites.','mainwp'); ?></h3>
						   <div class="inside">
						   <input type="hidden" id="import_user_do_import" value="1"/>
						   <input type="hidden" id="import_user_total_import" value="<?php echo $i ?>"/>

							<p><div class="import_user_import_listing" id="import_user_import_logging">
							   <pre class="log"><?php echo $header_line; ?></pre>
							</div></p>

							 <p class="submit"><input type="button" name="import_user_btn_import"
											 id="import_user_btn_import"
											 class="button-primary button button-hero" value="<?php _e('Pause','mainwp'); ?>"/>
											 <input type="button" name="import_user_btn_save_csv"
											 id="import_user_btn_save_csv" disabled="disabled"
											 class="button-hero button" value="<?php _e('Save failed','mainwp'); ?>"/>
							 </p>

							<p><div class="import_user_import_listing" id="import_user_import_fail_logging" style="display: none;">
							   <pre class="log"><?php echo $header_line; ?></pre>
							</div></p>
						   </div>
						</div>

						<?php

					} else {
						$errors[] = __( 'Data is not valid.', 'mainwp' ) .'<br />';
					}
				} else {
					$errors[] = __( 'Upload error.','mainwp' ) . '<br />';
				}
			} else {
				$errors[] = __( 'Upload error.','mainwp' ) . '<br />';
			}

			if ( count( $errors ) > 0 ) {
				?>
				<div class="error below-h2">
					<?php foreach ( $errors as $error ) { ?>
						<p><strong>ERROR</strong>: <?php echo $error ?></p>
					<?php } ?>
				</div>
				<br/>
				<a href="<?php echo get_admin_url() ?>admin.php?page=UserBulkAdd" class="add-new-h2" target="_top"><?php _e( 'Add New', 'mainwp' ); ?></a>
				<a href="<?php echo get_admin_url() ?>admin.php?page=mainwp_tab" class="add-new-h2" target="_top"><?php _e( 'Return to Overview', 'mainwp' ); ?></a>
				<?php
			}
			?>

		</div>
		<?php
		self::renderFooter( 'Import' );
	}

	public static function doImport() {
		if ( isset( $_POST['select_by'] ) ) {
			$selected_sites = array();
			if ( isset( $_POST['selected_sites'] ) && is_array( $_POST['selected_sites'] ) ) {
				foreach ( $_POST['selected_sites'] as $selected ) {
					$selected_sites[] = $selected;
				}
			}

			$selected_groups = array();
			if ( isset( $_POST['selected_groups'] ) && is_array( $_POST['selected_groups'] ) ) {
				foreach ( $_POST['selected_groups'] as $selected ) {
					$selected_groups[] = $selected;
				}
			}
		}
		$user_to_add = array(
			'user_pass'  => $_POST['pass1'],
			'user_login' => $_POST['user_login'],
			'user_url'   => $_POST['url'],
			'user_email' => $_POST['email'],
			'first_name' => $_POST['first_name'],
			'last_name'  => $_POST['last_name'],
			'role'       => $_POST['role'],
		);

		$ret         = array();
		$dbwebsites  = array();
		$not_valid   = array();
		$error_sites = '';
		if ( $_POST['select_by'] == 'site' ) { //Get all selected websites
			foreach ( $selected_sites as $url ) {
				if ( ! empty( $url ) ) {
					$website = MainWP_DB::Instance()->getWebsitesByUrl( $url );
					if ( $website ) {
						$dbwebsites[ $website[0]->id ] = MainWP_Utility::mapSite( $website[0], array(
							'id',
							'url',
							'name',
							'adminname',
							'nossl',
							'privkey',
							'nosslkey',
						) );
					} else {
						$not_valid[] = __( "Error - The website doesn't exist in the Network.", 'mainwp' ) . " " . $url;;
						$error_sites .= $url . ';';
					}
				}
			}
		} else { //Get all websites from the selected groups
			foreach ( $selected_groups as $group ) {
				if ( MainWP_DB::Instance()->getGroupsByName( $group ) ) {
					$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupName( $group ) );
					if ( $websites ) {
						while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
							$dbwebsites[ $website->id ] = MainWP_Utility::mapSite( $website, array(
								'id',
								'url',
								'name',
								'adminname',
								'nossl',
								'privkey',
								'nosslkey',
							) );
						}
						@MainWP_DB::free_result( $websites );
					} else {
						$not_valid[] = __( 'Error - These are not websites in the group. ', 'mainwp' ) . $group;
						$error_sites .= $group . ';';
					}
				} else {
					$not_valid[] = __( "Error - The group doesn't exist in the Network. ", 'mainwp' ) . $group;
					$error_sites .= $group . ';';
				}
			}
		}

		if ( count( $dbwebsites ) > 0 ) {
			$post_data      = array(
				'new_user'      => base64_encode( serialize( $user_to_add ) ),
				'send_password' => ( isset( $_POST['send_password'] ) ? $_POST['send_password'] : '' ),
			);
			$output         = new stdClass();
			$output->ok     = array();
			$output->errors = array();
			MainWP_Utility::fetchUrlsAuthed( $dbwebsites, 'newuser', $post_data, array(
				MainWP_Bulk_Add::getClassName(),
				'PostingBulk_handler',
			), $output );
		}

		$ret['ok_list'] = $ret['error_list'] = array();
		foreach ( $dbwebsites as $website ) {
			if ( isset( $output->ok[ $website->id ] ) && $output->ok[ $website->id ] == 1 ) {
				$ret['ok_list'][] = 'New user(s) created: ' . stripslashes( $website->name );
			} else {
				$ret['error_list'][] = $output->errors[ $website->id ] . ' ' . stripslashes( $website->name );
				$error_sites .= $website->url . ';';
			}
		}

		foreach ( $not_valid as $val ) {
			$ret['error_list'][] = $val;
		}

		$ret['failed_logging'] = '';
		if ( ! empty( $error_sites ) ) {
			$error_sites           = rtrim( $error_sites, ';' );
			$ret['failed_logging'] = $_POST['user_login'] . ',' . $_POST['email'] . ',' . $_POST['first_name'] . ',' . $_POST['last_name'] . ',' . $_POST['url'] . ',' . $_POST['pass1'] . ',' . intval( $_POST['send_password'] ) . ',' . $_POST['role'] . ',' . $error_sites . ',';
		}

		$ret['line_number'] = $_POST['line_number'];
		die( json_encode( $ret ) );
	}

}
