<?php
/**
 * MainWP REST API page
 *
 * This Class handles building/Managing the
 * REST API MainWP DashboardPage & all SubPages.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Rest_Api_Page
 *
 * @package MainWP\Dashboard
 */
class MainWP_Rest_Api_Page {

	/**
	 * Protected static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * Public static varable to hold Subpages information.
	 *
	 * @var array $subPages
	 */
	public static $subPages;

	/**
	 * Get Class Name
	 *
	 * @return __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Return the single instance of the class.
	 *
	 * @return mixed $instance The single instance of the class.
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Instantiate Hooks for the REST API Page. */
	public static function init() {
		/**
		 * This hook allows you to render the REST API page header via the 'mainwp_pageheader_restapi' action.
		 *
		 * This hook is normally used in the same context of 'mainwp_getsubpages_restapi'
		 */
		add_action( 'mainwp-pageheader-restapi', array( self::get_class_name(), 'render_header' ) );

		/**
		 * This hook allows you to render the REST API page footer via the 'mainwp-pagefooter-restapi' action.
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-restapi'
		 */
		add_action( 'mainwp-pagefooter-restapi', array( self::get_class_name(), 'render_footer' ) );

		add_action( 'admin_init', array( self::get_instance(), 'admin_init' ) );
	}

	/** Run the export_sites method that exports the Child Sites .csv file */
	public function admin_init() {
		MainWP_Post_Handler::instance()->add_action( 'mainwp_rest_api_remove_keys', array( $this, 'ajax_rest_api_remove_keys' ) );
		$this->handle_rest_api_add_new();
		$this->handle_rest_api_edit();
	}


	/**
	 * Instantiate the REST API Menu.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_menu() {
		add_submenu_page(
			'mainwp_tab',
			esc_html__( 'REST API', 'mainwp' ),
			' <span id="mainwp-rest-api">' . esc_html__( 'REST API', 'mainwp' ) . '</span>',
			'read',
			'RESTAPI',
			array(
				self::get_class_name(),
				'render_all_api_keys',
			)
		);

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'AddApiKeys' ) ) {
			add_submenu_page(
				'mainwp_tab',
				esc_html__( 'Add API Keys', 'mainwp' ),
				' <div class="mainwp-hidden">' . esc_html__( 'Add API Keys', 'mainwp' ) . '</div>',
				'read',
				'AddApiKeys',
				array(
					self::get_class_name(),
					'render_rest_api_setings',
				)
			);
		}

		/**
		 * REST API Subpages
		 *
		 * Filters subpages for the REST API page.
		 *
		 * @since Unknown
		 */
		self::$subPages = apply_filters( 'mainwp_getsubpages_restapi', array() );

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'RESTAPI' . $subPage['slug'] ) ) {
					continue;
				}
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'RESTAPI' . $subPage['slug'], $subPage['callback'] );
			}
		}

		self::init_left_menu( self::$subPages );
	}

	/**
	 * Instantiate REST API SubPages Menu.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_subpages_menu() {
		?>
		<div id="menu-mainwp-RESTAPI" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open" style="">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=RESTAPI' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'REST API', 'mainwp' ); ?></a>
					<?php
					if ( isset( self::$subPages ) && is_array( self::$subPages ) && ( count( self::$subPages ) > 0 ) ) {
						foreach ( self::$subPages as $subPage ) {
							if ( MainWP_Menu::is_disable_menu_item( 3, 'RESTAPI' . $subPage['slug'] ) ) {
								continue;
							}
							?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=RESTAPI' . $subPage['slug'] ) ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
							<?php
						}
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Instantiate left menu
	 *
	 * REST API Page & SubPage link data.
	 *
	 * @param array $subPages SubPages Array.
	 */
	public static function init_left_menu( $subPages = array() ) {

		MainWP_Menu::add_left_menu(
			array(
				'title'      => esc_html__( 'REST API', 'mainwp' ),
				'parent_key' => 'mainwp_tab',
				'slug'       => 'RESTAPI',
				'href'       => 'admin.php?page=RESTAPI',
				'icon'       => '<div class="mainwp-api-icon">API</div>',
			),
			0
		);

		$init_sub_subleftmenu = array(
			array(
				'title'      => esc_html__( 'Manage API Keys', 'mainwp' ),
				'parent_key' => 'RESTAPI',
				'href'       => 'admin.php?page=RESTAPI',
				'slug'       => 'RESTAPI',
				'right'      => 'manage_restapi',
			),
			array(
				'title'      => esc_html__( 'Add API Keys', 'mainwp' ),
				'parent_key' => 'RESTAPI',
				'href'       => 'admin.php?page=AddApiKeys',
				'slug'       => 'AddApiKeys',
				'right'      => '',
			),
		);

		MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'RESTAPI', 'RESTAPI' );
		foreach ( $init_sub_subleftmenu as $item ) {
			if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
				continue;
			}

			MainWP_Menu::add_left_menu( $item, 2 );
		}
	}

	/**
	 * Method handle_rest_api_add_new()
	 *
	 * Handle rest api settings
	 */
	public function handle_rest_api_add_new() {
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_POST['submit'] ) && isset( $_GET['page'] ) && 'AddApiKeys' === $_GET['page'] ) {
			if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'RESTAPI' ) ) {
				$all_keys = self::check_rest_api_updates();

				if ( ! is_array( $all_keys ) ) {
					$all_keys = array();
				}

				$consumer_key    = isset( $_POST['mainwp_consumer_key'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_consumer_key'] ) ) : '';
				$consumer_secret = isset( $_POST['mainwp_consumer_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_consumer_secret'] ) ) : '';
				$desc            = isset( $_POST['mainwp_rest_add_api_key_desc'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_rest_add_api_key_desc'] ) ) : '';
				$enabled         = ! empty( $_POST['mainwp_enable_rest_api'] ) ? 1 : 0;
				$pers            = ! empty( $_POST['mainwp_rest_api_key_edit_pers'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_rest_api_key_edit_pers'] ) ) : '';

				// hash the password.
				$consumer_key_hashed       = wp_hash_password( $consumer_key );
				$consumer_secret_hashed    = wp_hash_password( $consumer_secret );
				$all_keys[ $consumer_key ] = array(
					'ck_hashed' => $consumer_key_hashed,
					'cs'        => $consumer_secret_hashed,
					'desc'      => $desc,
					'enabled'   => $enabled,
					'perms'     => $pers,
				);
				// store the data.
				MainWP_Utility::update_option( 'mainwp_rest_api_keys', $all_keys );
				wp_safe_redirect( esc_url( admin_url( 'admin.php?page=RESTAPI&message=created' ) ) );
				exit();
			}
		}
		// phpcs:enable
	}

	/**
	 * Method handle_rest_api_edit()
	 *
	 * Handle rest api settings
	 */
	public function handle_rest_api_edit() {

		$edit_id = isset( $_POST['editkey_id'] ) ? sanitize_text_field( wp_unslash( $_POST['editkey_id'] ) ) : false;
		if ( isset( $_POST['submit'] ) && ! empty( $edit_id ) && isset( $_POST['edit_key_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['edit_key_nonce'] ), 'edit-key-nonce-' . $edit_id ) ) {
			$save    = false;
			$updated = false;
			if ( ! empty( $edit_id ) ) {
				$all_keys = get_option( 'mainwp_rest_api_keys', false );
				if ( is_array( $all_keys ) && isset( $all_keys[ $edit_id ] ) ) {
					$item = $all_keys[ $edit_id ];
					if ( is_array( $item ) && isset( $item['cs'] ) ) {
						$item['desc']    = isset( $_POST['mainwp_rest_api_key_desc'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_rest_api_key_desc'] ) ) : '';
						$item['enabled'] = ! empty( $_POST['mainwp_enable_rest_api'] ) ? 1 : 0;
						$item['perms']   = ! empty( $_POST['mainwp_rest_api_key_edit_pers'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_rest_api_key_edit_pers'] ) ) : '';

						$all_keys[ $edit_id ] = $item;
						$updated              = true;
						$save                 = true;
					} else {
						unset( $all_keys[ $edit_id ] ); // delete incorrect key.
						$save = true;
					}
				}
			}

			if ( $save ) {
				MainWP_Utility::update_option( 'mainwp_rest_api_keys', $all_keys );
			}

			$msg = '';
			if ( $updated ) {
				$msg = '&message=saved';
			}
			wp_safe_redirect( esc_url( admin_url( 'admin.php?page=RESTAPI' . $msg ) ) );
			exit();
		}
	}

	/**
	 * Method ajax_rest_api_remove_keys()
	 *
	 * Remove API Key.
	 */
	public function ajax_rest_api_remove_keys() {
		MainWP_Post_Handler::instance()->check_security( 'mainwp_rest_api_remove_keys' );
		$ret         = array( 'success' => false );
		$cons_key_id = isset( $_POST['keyId'] ) ? urldecode( wp_unslash( $_POST['keyId'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! empty( $cons_key_id ) ) {
			$save     = false;
			$all_keys = get_option( 'mainwp_rest_api_keys', false );
			if ( is_array( $all_keys ) && isset( $all_keys[ $cons_key_id ] ) ) {
				$item = $all_keys[ $cons_key_id ];
				if ( is_array( $item ) && isset( $item['cs'] ) ) {
					unset( $all_keys[ $cons_key_id ] ); // delete key.
					$save = true;
				}
			}
			if ( $save ) {
				MainWP_Utility::update_option( 'mainwp_rest_api_keys', $all_keys );
			}
			$ret['success'] = 'SUCCESS';
			$ret['result']  = esc_html__( 'REST API Key deleted successfully.', 'mainwp' );
		} else {
			$ret['error'] = esc_html__( 'REST API Key ID empty.', 'mainwp' );
		}
		echo wp_json_encode( $ret );
		exit;
	}


	/**
	 * Render Page Header.
	 *
	 * @param string $shownPage The page slug shown at this moment.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
	 */
	public static function render_header( $shownPage = '' ) {

		$params = array(
			'title' => esc_html__( 'REST API', 'mainwp' ),
		);

		MainWP_UI::render_top_header( $params );

		$renderItems = array();

		if ( mainwp_current_user_have_right( 'dashboard', 'manage_restapi' ) ) {
			$renderItems[] = array(
				'title'  => esc_html__( 'Manage API Keys', 'mainwp' ),
				'href'   => 'admin.php?page=RESTAPI',
				'active' => ( '' === $shownPage ) ? true : false,
			);
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'AddApiKeys' ) ) {
			if ( isset( $_GET['editkey'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$renderItems[] = array(
					'title'  => esc_html__( 'Edit API Keys', 'mainwp' ),
					'href'   => 'admin.php?page=AddApiKeys&editkey=' . esc_url( wp_unslash( $_GET['editkey'] ) ), // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					'active' => ( 'Edit' === $shownPage ) ? true : false,
				);
			}
			$renderItems[] = array(
				'title'  => esc_html__( 'Add API Keys', 'mainwp' ),
				'href'   => 'admin.php?page=AddApiKeys',
				'active' => ( 'Settings' === $shownPage ) ? true : false,
			);
		}

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'RESTAPI' . $subPage['slug'] ) ) {
					continue;
				}
				$item           = array();
				$item['title']  = $subPage['title'];
				$item['href']   = 'admin.php?page=RESTAPI' . $subPage['slug'];
				$item['active'] = ( $subPage['slug'] === $shownPage ) ? true : false;
				$renderItems[]  = $item;
			}
		}

		MainWP_UI::render_page_navigation( $renderItems );
	}

	/**
	 * Close the HTML container.
	 */
	public static function render_footer() {
		echo '</div>';
	}

	/** Render REST API SubPage */
	public static function render_all_api_keys() { // phpcs:ignore -- complex.
		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_dashboard_restapi' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'manage dashboard REST API', 'mainwp' ) );
			return;
		}

		$all_keys = self::check_rest_api_updates();

		if ( ! is_array( $all_keys ) ) {
			$all_keys = array();
		}
		self::render_header();
		self::render_table_top();
		if ( ! self::check_rest_api_enabled() ) {
			?>
			<div class="ui message yellow"><?php printf( esc_html__( 'It seems the WordPress REST API is currently disabled on your site. MainWP REST API requires the WordPress REST API to function properly. Please enable it to ensure smooth operation. Need help? %sClick here for a guide%s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/wordpress-rest-api-does-not-respond/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?></div>
			<?php
		}

		?>
		<div id="mainwp-rest-api-keys" class="ui segment">
			<div class="ui message" id="mainwp-message-zone-apikeys" style="display:none;"></div>
			<?php self::show_messages(); ?>
			<table id="mainwp-rest-api-keys-table" class="ui unstackable table">
				<thead>
					<tr>
						<th  class="no-sort collapsing check-column"><span class="ui checkbox"><input id="cb-select-all-top" type="checkbox" /></span></th>
						<th class="collapsing"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Permissions', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Description', 'mainwp' ); ?></th>
						<th class="no-sort collapsing"><?php esc_html_e( 'Consumer key ending in', 'mainwp' ); ?></th>
						<th class="no-sort"></th>
					</tr>
				</thead>
					<tbody id="mainwp-rest-api-body-table">
					<?php
					foreach ( $all_keys as $ck => $item ) {
						if ( ! is_array( $item ) ) {
							continue;
						}
						$ending      = substr( $ck, -8 );
						$desc        = isset( $item['desc'] ) && ! empty( $item['desc'] ) ? $item['desc'] : 'N/A';
						$enabled     = isset( $item['enabled'] ) && ! empty( $item['enabled'] ) ? true : false;
						$endcoded_ck = rawurlencode( $ck );

						$pers_codes = '';
						if ( ! isset( $item['perms'] ) ) {
							$pers_codes = 'r,w,d'; // to compatible.
						} elseif ( ! empty( $item['perms'] ) ) {
							$pers_codes = $item['perms'];
						}

						$pers_names = array();
						if ( ! empty( $pers_codes ) && is_string( $pers_codes ) ) {
							$pers_codes = explode( ',', $pers_codes );
							if ( is_array( $pers_codes ) ) {
								if ( in_array( 'r', $pers_codes ) ) {
									$pers_names[] = esc_html__( 'Read', 'mainwp' );
								}
								if ( in_array( 'w', $pers_codes ) ) {
									$pers_names[] = esc_html__( 'Write', 'mainwp' );
								}
								if ( in_array( 'd', $pers_codes ) ) {
									$pers_names[] = esc_html__( 'Delete', 'mainwp' );
								}
							}
						}

						?>
						<tr key-ck-id="<?php echo esc_html( $endcoded_ck ); ?>">
							<td class="check-column">
								<div class="ui checkbox">
									<input type="checkbox" value="<?php echo esc_html( $endcoded_ck ); ?>" name=""/>
								</div>
							</td>
							<td><?php echo $enabled ? '<span class="ui green fluid label">' . esc_html__( 'Enabled', 'mainwp' ) . '</span>' : '<span class="ui gray fluid label">' . esc_html__( 'Disabled', 'mainwp' ) . '</span>'; ?></td>
							<td><?php echo ! empty( $pers_names ) ? implode( ', ', $pers_names ) : 'N/A'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>	
							<td><?php echo esc_html( $desc ); ?></td>							
							<td><code><?php echo esc_html( '...' . $ending ); // phpcs:ignore WordPress.Security.EscapeOutput ?></code></td>
							<td class="collapsing">
								<a class="ui green basic mini button" href="admin.php?page=AddApiKeys&editkey=<?php echo esc_html( $endcoded_ck ); ?>"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
								<a class="ui mini button" href="javascript:void(0)" onclick="mainwp_restapi_remove_key_confirm(jQuery(this).closest('tr').find('.check-column INPUT:checkbox'));" ><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
							</td>
						</tr>
						<?php
					}
					?>
				</tbody>
			</table>
		</div>
		<script type="text/javascript">
			var responsive = true;
			if( jQuery( window ).width() > 1140 ) {
				responsive = false;
			}
			jQuery( document ).ready( function( $ ) {
				try {
					jQuery( '#mainwp-rest-api-keys-table' ).DataTable( {
						"lengthMenu": [ [5, 10, 25, 50, 100, -1], [5, 10, 25, 50, 100, "All"] ],
						"stateSave" : true,
						"order"     : [ [1, 'asc'] ],
						"columnDefs": [ {
							"targets": 'no-sort',
							"orderable": false
						} ],
						"responsive": responsive,
						"preDrawCallback": function() {
							jQuery( '#mainwp-rest-api-keys-table .ui.dropdown' ).dropdown();
							jQuery('#mainwp-rest-api-keys-table .ui.checkbox').checkbox();
							mainwp_datatable_fix_menu_overflow();
							mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
						}
					} );
					mainwp_datatable_fix_menu_overflow();
				} catch(err) {
					// to fix js error.
				}
			});
		</script>	

		<?php
		self::render_footer();
	}


	/**
	 * Render table top.
	 */
	public static function render_table_top() {
		?>
		<div class="mainwp-actions-bar">
		<div class="ui grid">
			<div class="equal width row ui mini form">
				<div class="middle aligned column">
						<div id="mainwp-rest-api-bulk-actions-menu" class="ui selection dropdown">
							<div class="default text"><?php esc_html_e( 'Bulk actions', 'mainwp' ); ?></div>
							<i class="dropdown icon"></i>
							<div class="menu">
							<div class="item" data-value="delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></div>
							</div>
						</div>
						<button class="ui tiny basic button" id="mainwp-do-rest-api-bulk-actions"><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
					</div>
					<div class="right aligned middle aligned column"></div>
			</div>
		</div>
		</div>
		<?php
	}


	/**
	 * Method check_rest_api_updates().
	 *
	 * To Checks for updating REST API keys.
	 */
	public static function check_rest_api_updates() {
		$all_keys = get_option( 'mainwp_rest_api_keys', false );
		$cs       = get_option( 'mainwp_rest_api_consumer_key', false );

		// to compatible.
		if ( false === $all_keys || false !== $cs ) {
			if ( ! is_array( $all_keys ) ) {
				$all_keys = array();
			}
			if ( ! empty( $cs ) ) {
				$all_keys[ $cs ] = array(
					'ck'   => get_option( 'mainwp_rest_api_consumer_key', '' ),
					'cs'   => get_option( 'mainwp_rest_api_consumer_secret', '' ),
					'desc' => '',
				);
			}
			MainWP_Utility::update_option( 'mainwp_rest_api_keys', $all_keys );

			if ( false !== $cs ) {
				delete_option( 'mainwp_rest_api_consumer_key' );
				delete_option( 'mainwp_rest_api_consumer_secret' );
				delete_option( 'mainwp_enable_rest_api' );
			}
		}
		// end.
		return $all_keys;
	}


	/**
	 * Method show_messages().
	 *
	 * Show actions messages.
	 */
	public static function show_messages() {
		$msg = '';
		if ( isset( $_GET['message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( 'saved' === $_GET['message'] || 'created' === $_GET['message'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$msg = esc_html__( 'API Key have been saved successfully!', 'mainwp' );
			}
		}
		if ( ! empty( $msg ) ) {
			?>
			<div class="ui green message"><i class="close icon"></i><?php echo esc_html( $msg ); ?></div>
			<?php
		}
	}

	/** Render REST API SubPage */
	public static function render_rest_api_setings() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_dashboard_restapi' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'manage dashboard REST API', 'mainwp' ) );

			return;
		}

		$edit_key  = isset( $_GET['editkey'] ) && ! empty( $_GET['editkey'] ) ? urldecode( wp_unslash( $_GET['editkey'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$edit_item = array();
		if ( false !== $edit_key ) {
			$all_keys = get_option( 'mainwp_rest_api_keys', false );
			if ( ! is_array( $all_keys ) || ! isset( $all_keys[ $edit_key ] ) ) {
				$edit_key = false;
			} else {
				$edit_item = $all_keys[ $edit_key ];
			}
		}

		if ( false !== $edit_key && ! empty( $edit_item ) ) {
			self::render_rest_api_edit( $edit_key, $edit_item );
			return;
		}

		// we need to generate a consumer key and secret and return the result and save it into the database.
		$consumer_key    = 'ck_' . self::mainwp_generate_rand_hash();
		$consumer_secret = 'cs_' . self::mainwp_generate_rand_hash();

		self::render_header( 'Settings' );

		?>
		<div id="rest-api-settings" class="ui segment">
			<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-rest-api-info-message' ) ) : ?>
				<div class="ui info message">
					<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-rest-api-info-message"></i>
					<?php printf( esc_html__( 'Enable the MainWP REST API functionality and generate API credentials.  Check this %1$shelp document%2$s to see all available endpoints.', 'mainwp' ), '<a href="https://mainwp.dev/rest-api/" target="_blank">', '</a>' ); ?>
				</div>
			<?php endif; ?>
				<?php self::show_messages(); ?>
				<div id="api-credentials-created" class="ui green message"><?php esc_html_e( 'API credentials have been successfully generated. Please copy the consumer key and secret now as after you leave this page the credentials will no longer be accessible. Use the Description field for easier Key management when needed.', 'mainwp' ); ?></div>
				<div class="ui form">
					<form method="POST" action="">
						<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
						<input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'RESTAPI' ) ); ?>" />
						<?php
						/**
						 * Action: rest_api_form_top
						 *
						 * Fires at the top of REST API form.
						 *
						 * @since 4.1
						 */
						do_action( 'rest_api_form_top' );
						?>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Enable REST API key', 'mainwp' ); ?></label>
							<div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the REST API will be activated.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
								<input type="checkbox" name="mainwp_enable_rest_api" id="mainwp_enable_rest_api" checked="true" />
							</div>
						</div>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Description', 'mainwp' ); ?></label>
							<div class="five wide column">
								<input type="text" name="mainwp_rest_add_api_key_desc" id="mainwp_rest_add_api_key_desc" value="" />
							</div>
						</div>				
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Consumer key', 'mainwp' ); ?></label>

							<div class="five wide column">
								<input type="text" name="mainwp_consumer_key" id="mainwp_consumer_key" value="<?php echo esc_html( $consumer_key ); ?>" readonly />
							</div>

							<div class="five wide column">
								<input id="mainwp_consumer_key_clipboard_button" style="display:nonce;" type="button" name="" class="ui green basic button copy-to-clipboard" value="<?php esc_attr_e( 'Copy to Clipboard', 'mainwp' ); ?>">
							</div>
						</div>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Consumer secret', 'mainwp' ); ?></label>

							<div class="five wide column">
								<input type="text" name="mainwp_consumer_secret" id="mainwp_consumer_secret" value="<?php echo esc_html( $consumer_secret ); ?>" readonly />
							</div>

							<div class="five wide column">
								<input id="mainwp_consumer_secret_clipboard_button" style="display:nonce;" type="button" name="" class="ui green basic button copy-to-clipboard" value="<?php esc_attr_e( 'Copy to Clipboard', 'mainwp' ); ?>">
							</div>
						</div>
						<?php $init_pers = 'r,w,d'; ?>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Permissions', 'mainwp' ); ?></label>
							<div class="five wide column">
								<div class="ui multiple selection dropdown" init-value="<?php echo esc_attr( $init_pers ); ?>">
									<input name="mainwp_rest_api_key_edit_pers" value="" type="hidden">
									<i class="dropdown icon"></i>
									<div class="default text"><?php echo ( '' === $init_pers ) ? esc_html__( 'No Permissions selected.', 'mainwp' ) : ''; ?></div>
									<div class="menu">
										<div class="item" data-value="r"><?php esc_html_e( 'Read', 'mainwp' ); ?></div>
										<div class="item" data-value="w"><?php esc_html_e( 'Write', 'mainwp' ); ?></div>
										<div class="item" data-value="d"><?php esc_html_e( 'Delete', 'mainwp' ); ?></div>
									</div>
								</div>
							</div>
						</div>
						<?php
						/**
						 * Action: rest_api_form_bottom
						 *
						 * Fires at the bottom of REST API form.
						 *
						 * @since 4.1
						 */
						do_action( 'rest_api_form_bottom' );
						?>
						<div class="ui divider"></div>
						<input type="submit" name="submit" id="submit-save-settings-button" class="ui green big button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
						<div style="clear:both"></div>
					</form>
				</div>
			</div>
			<script type="text/javascript">
				jQuery(document).ready(function ($) {
					//we are going to inject the values into the copy buttons to make things easier for people
					$('#mainwp_consumer_key_clipboard_button').attr('data-clipboard-text', '<?php echo esc_html( $consumer_key ); ?>');
					$('#mainwp_consumer_secret_clipboard_button').attr('data-clipboard-text', '<?php echo esc_html( $consumer_secret ); ?>');
					//initiate clipboard
					new ClipboardJS('.copy-to-clipboard');
					//show copy to clipboard buttons 
					$('.copy-to-clipboard').show();
				});
			</script>
		<?php

		self::render_footer( 'Settings' );
	}

	/**
	 * Method mainwp_generate_rand_hash()
	 *
	 * Generates a random hash to be used when generating the consumer key and secret.
	 *
	 * @return string Returns random string.
	 */
	public static function mainwp_generate_rand_hash() {
		if ( ! function_exists( 'openssl_random_pseudo_bytes' ) ) {
			return sha1( wp_rand() );
		}

		return bin2hex( openssl_random_pseudo_bytes( 20 ) ); // @codingStandardsIgnoreLine
	}

	/**
	 * Method render_rest_api_edit().
	 *
	 * Render REST API edit screen.
	 *
	 * @param string $keyid Key ID edit.
	 * @param array  $item The Key edit.
	 */
	public static function render_rest_api_edit( $keyid, $item ) {

		$edit_desc = is_array( $item ) && isset( $item['desc'] ) ? $item['desc'] : '';
		$enabled   = is_array( $item ) && isset( $item['enabled'] ) && ! empty( $item['enabled'] ) ? true : false;
		$ending    = substr( $keyid, -8 );

		$init_pers = '';
		if ( isset( $item['perms'] ) ) {
			$init_pers = $item['perms'];
		} else {
			$init_pers = 'r,w,d'; // to compatible.
		}

		$item_pers = is_string( $init_pers ) ? explode( ',', $init_pers ) : array();

		self::render_header( 'Edit' );
		?>
		<div id="rest-api-settings" class="ui segment">
			<div class="ui form">
					<form method="POST" action="">
						<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
						<input type="hidden" name="edit_key_nonce" value="<?php echo esc_attr( wp_create_nonce( 'edit-key-nonce-' . $keyid ) ); ?>" />
						<input type="hidden" name="editkey_id" value="<?php echo esc_html( $keyid ); ?>" />
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Enable REST API Key', 'mainwp' ); ?></label>
							<div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the REST API will be activated.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
								<input type="checkbox" name="mainwp_enable_rest_api" id="mainwp_enable_rest_api" value="1" <?php echo ( $enabled ? 'checked="true"' : '' ); ?> />
							</div>
						</div>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Description', 'mainwp' ); ?></label>
							<div class="five wide column">
								<input type="text" name="mainwp_rest_api_key_desc" id="mainwp_rest_api_key_desc" value="<?php echo esc_html( $edit_desc ); ?>" />
							</div>
						</div>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Consumer key ending in', 'mainwp' ); ?></label>
							<div class="five wide column">
								<div class="ui disabled input">
									<input type="text" value="<?php echo esc_attr( '...' . $ending ); ?>" />
								</div>
							</div>
						</div>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Permissions', 'mainwp' ); ?></label>
							<div class="five wide column">
								<div class="ui multiple selection dropdown" init-value="<?php echo esc_attr( $init_pers ); ?>">
									<input name="mainwp_rest_api_key_edit_pers" value="" type="hidden">
									<i class="dropdown icon"></i>
									<div class="default text"><?php echo ( '' === $init_pers ) ? esc_html__( 'No Permissions selected.', 'mainwp' ) : ''; ?></div>
									<div class="menu">
										<div class="item" data-value="r"><?php esc_html_e( 'Read', 'mainwp' ); ?></div>
										<div class="item" data-value="w"><?php esc_html_e( 'Write', 'mainwp' ); ?></div>
										<div class="item" data-value="d"><?php esc_html_e( 'Delete', 'mainwp' ); ?></div>
									</div>
								</div>
							</div>
						</div>
						<div class="ui divider"></div>
						<input type="submit" name="submit" id="submit" class="ui green big button" value="<?php esc_attr_e( 'Save API Key', 'mainwp' ); ?>"/>
						<div style="clear:both"></div>
					</form>
				</div>

		</div>
		<?php
		self::render_footer( 'Edit' );
	}

	/**
	 * Method check_rest_api_enabled().
	 *
	 * @param bool $check_logged_in check for logged in user or not.
	 *
	 * @return bool check result.
	 */
	public static function check_rest_api_enabled( $check_logged_in = false ) {
		$cookies = array();
		if ( $check_logged_in ) {
			if ( is_user_logged_in() && defined( 'LOGGED_IN_COOKIE' ) ) {
				$cookies      = array();
				$auth_cookies = wp_parse_auth_cookie( $_COOKIE[ LOGGED_IN_COOKIE ], 'logged_in' ); // phpcs:ignore -- ok.
				if ( is_array( $auth_cookies ) ) {
					foreach ( $auth_cookies as $name => $value ) {
						$cookies[] = new \WP_Http_Cookie(
							array(
								'name'  => $name,
								'value' => $value,
							)
						);
					}
				}
			}
		}

		$args = array(
			'method'  => 'GET',
			'timeout' => 45,
			'headers' => array(
				'content-type' => 'application/json',
			),
		);

		if ( $check_logged_in && ! empty( $cookies ) ) {
			$args['cookies'] = $cookies;
		}

		$site_url = get_option( 'siteurl' );
		$response = wp_remote_post( $site_url . '/wp-json', $args );
		$body     = wp_remote_retrieve_body( $response );
		$data     = is_string( $body ) ? json_decode( $body, true ) : false;

		if ( is_array( $data ) & isset( $data['routes'] ) && ! empty( $data['routes'] ) ) {
			return true;
		} elseif ( ! $check_logged_in ) {
			return self::check_rest_api_enabled( true );
		}
		return false;
	}
}
