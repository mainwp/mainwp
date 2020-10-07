<?php
/**
 * MainWP Manage Sites View.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Manage_Sites_View
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Sites_View {

	/**
	 * Method init_menu()
	 *
	 * Initiate Sites sub menu.
	 *
	 * @return add_submenu_page()
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites::get_class_name()
	 */
	public static function init_menu() {
		return add_submenu_page(
			'mainwp_tab',
			__( 'Sites', 'mainwp' ),
			'<span id="mainwp-Sites">' . __( 'Sites', 'mainwp' ) . '</span>',
			'read',
			'managesites',
			array( MainWP_Manage_Sites::get_class_name(), 'render_manage_sites' )
		);
	}

	/**
	 * Method init_subpages_menu()
	 *
	 * @param array $subPages Sub pages array.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_subpages_menu( &$subPages ) {
		?>
		<div id="menu-mainwp-Sites" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open" style="">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<a href="<?php echo admin_url( 'admin.php?page=managesites' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Manage Sites', 'mainwp' ); ?></a>
					<?php if ( mainwp_current_user_have_right( 'dashboard', 'add_sites' ) ) { ?>
						<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'managesites_add_new' ) ) { ?>
							<a href="<?php echo admin_url( 'admin.php?page=managesites&do=new' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Add New', 'mainwp' ); ?></a>
						<?php } ?>
						<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'managesites_import' ) ) { ?>
							<a href="<?php echo admin_url( 'admin.php?page=managesites&do=bulknew' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Import Sites', 'mainwp' ); ?></a>
						<?php } ?>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ManageGroups' ) ) { ?>
						<a href="<?php echo admin_url( 'admin.php?page=ManageGroups' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Groups', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'MonitoringSites' ) ) { ?>
						<a href="<?php echo admin_url( 'admin.php?page=MonitoringSites' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Monitoring', 'mainwp' ); ?></a>
					<?php } ?>
					<?php
					if ( isset( $subPages ) && is_array( $subPages ) ) {
						foreach ( $subPages as $subPage ) {
							if ( ! isset( $subPage['menu_hidden'] ) || ( isset( $subPage['menu_hidden'] ) && true !== $subPage['menu_hidden'] ) ) {
								if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageSites' . $subPage['slug'] ) ) {
									continue;
								}
								?>
								<a href="<?php echo admin_url( 'admin.php?page=ManageSites' . $subPage['slug'] ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
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

	/**
	 * Method init_left_menu()
	 *
	 * Initiate left Sites menu.
	 *
	 * @param array $subPages Sub pages array.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::init_subpages_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_left_menu( $subPages = array() ) {

		MainWP_Menu::add_left_menu(
			array(
				'title'      => __( 'Sites', 'mainwp' ),
				'parent_key' => 'mainwp_tab',
				'slug'       => 'managesites',
				'href'       => 'admin.php?page=managesites',
				'icon'       => '<i class="globe icon"></i>',
			),
			1
		);

		$items_menu = array(
			array(
				'title'      => __( 'Manage Sites', 'mainwp' ),
				'parent_key' => 'managesites',
				'slug'       => 'managesites',
				'href'       => 'admin.php?page=managesites',
				'right'      => '',
			),
			array(
				'title'      => __( 'Add New', 'mainwp' ),
				'parent_key' => 'managesites',
				'href'       => 'admin.php?page=managesites&do=new',
				'slug'       => 'managesites',
				'right'      => 'add_sites',
				'item_slug'  => 'managesites_add_new',
			),
			array(
				'title'      => __( 'Import Sites', 'mainwp' ),
				'parent_key' => 'managesites',
				'href'       => 'admin.php?page=managesites&do=bulknew',
				'slug'       => 'managesites',
				'right'      => 'add_sites',
				'item_slug'  => 'managesites_import',
			),
			array(
				'title'      => __( 'Groups', 'mainwp' ),
				'parent_key' => 'managesites',
				'href'       => 'admin.php?page=ManageGroups',
				'slug'       => 'ManageGroups',
				'right'      => '',
			),
			array(
				'title'      => __( 'Monitoring', 'mainwp' ),
				'parent_key' => 'managesites',
				'href'       => 'admin.php?page=MonitoringSites',
				'slug'       => 'MonitoringSites',
				'right'      => '',
			),
		);

		MainWP_Menu::init_subpages_left_menu( $subPages, $items_menu, 'managesites', 'ManageSites' );

		foreach ( $items_menu as $item ) {
			if ( isset( $item['item_slug'] ) ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, $item['item_slug'] ) ) {
					continue;
				}
			} else {
				if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
					continue;
				}
			}
			MainWP_Menu::add_left_menu( $item, 2 );
		}
	}

	/**
	 * Method render_header()
	 *
	 * Build Sites page header.
	 *
	 * @param string $shownPage Current Page.
	 * @param string $subPages Sites subpages.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::get_favico_url()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_second_top_header()
	 */
	public static function render_header( $shownPage = '', $subPages = '' ) {

		if ( '' === $shownPage ) {
			$shownPage = 'ManageSites';
		}

		$site_id = 0;
		if ( isset( $_GET['id'] ) && ! empty( $_GET['id'] ) ) {
			$site_id = intval( $_GET['id'] );
		} elseif ( isset( $_GET['backupid'] ) && ! empty( $_GET['backupid'] ) ) {
			$site_id = intval( $_GET['backupid'] );
		} elseif ( isset( $_GET['updateid'] ) && ! empty( $_GET['updateid'] ) ) {
			$site_id = intval( $_GET['updateid'] );
		} elseif ( isset( $_GET['dashboard'] ) && ! empty( $_GET['dashboard'] ) ) {
			$site_id = intval( $_GET['dashboard'] );
		} elseif ( isset( $_GET['scanid'] ) && ! empty( $_GET['scanid'] ) ) {
			$site_id = intval( $_GET['scanid'] );
		} elseif ( isset( $_GET['emailsettingsid'] ) && ! empty( $_GET['emailsettingsid'] ) ) {
			$site_id = intval( $_GET['emailsettingsid'] );
		}

		$managesites_pages = array(
			'ManageSites'     => array(
				'href'   => 'admin.php?page=managesites',
				'title'  => __( 'Manage Sites', 'mainwp' ),
				'access' => true,
			),
			'AddNew'          => array(
				'href'   => 'admin.php?page=managesites&do=new',
				'title'  => __( 'Add New', 'mainwp' ),
				'access' => mainwp_current_user_have_right( 'dashboard', 'add_sites' ),
			),
			'BulkAddNew'      => array(
				'href'   => 'admin.php?page=managesites&do=bulknew',
				'title'  => __( 'Import Sites', 'mainwp' ),
				'access' => mainwp_current_user_have_right( 'dashboard', 'add_sites' ),
			),
			'ManageGroups'    => array(
				'href'   => 'admin.php?page=ManageGroups',
				'title'  => __( 'Groups', 'mainwp' ),
				'access' => true,
			),
			'MonitoringSites' => array(
				'href'   => 'admin.php?page=MonitoringSites',
				'title'  => __( 'Monitoring', 'mainwp' ),
				'access' => true,
			),
		);

		$site_pages = array(
			'ManageSitesDashboard'     => array(
				'href'   => 'admin.php?page=managesites&dashboard=' . $site_id,
				'title'  => __( 'Overview', 'mainwp' ),
				'access' => mainwp_current_user_have_right( 'dashboard', 'access_individual_dashboard' ),
			),
			'ManageSitesEdit'          => array(
				'href'   => 'admin.php?page=managesites&id=' . $site_id,
				'title'  => __( 'Edit', 'mainwp' ),
				'access' => mainwp_current_user_have_right( 'dashboard', 'edit_sites' ),
			),
			'ManageSitesUpdates'       => array(
				'href'   => 'admin.php?page=managesites&updateid=' . $site_id,
				'title'  => __( 'Updates', 'mainwp' ),
				'access' => mainwp_current_user_have_right( 'dashboard', 'access_individual_dashboard' ),
			),
			'ManageSitesEmailSettings' => array(
				'href'   => 'admin.php?page=managesites&emailsettingsid=' . $site_id,
				'title'  => __( 'Email Settings', 'mainwp' ),
				'access' => mainwp_current_user_have_right( 'dashboard', 'edit_sites' ),
			),
			'ManageSitesBackups'       => array(
				'href'   => 'admin.php?page=managesites&backupid=' . $site_id,
				'title'  => __( 'Backups', 'mainwp' ),
				'access' => mainwp_current_user_have_right( 'dashboard', 'execute_backups' ),
			),
			'SecurityScan'             => array(
				'href'   => 'admin.php?page=managesites&scanid=' . $site_id,
				'title'  => __( 'Security Scan', 'mainwp' ),
				'access' => true,
			),
		);

		/**
		 * MainWP Use External Primary backup Method global.
		 *
		 * @global string
		 */
		global $mainwpUseExternalPrimaryBackupsMethod;

		if ( ! empty( $mainwpUseExternalPrimaryBackupsMethod ) ) {
			unset( $site_pages['ManageSitesBackups'] );
		} elseif ( ! get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			if ( isset( $site_pages['ManageSitesBackups'] ) ) {
				unset( $site_pages['ManageSitesBackups'] );
			}
		}

		$pagetitle = __( 'Sites', 'mainwp' );

		if ( 0 !== $site_id ) {
			$website = MainWP_DB::instance()->get_website_by_id( $site_id );
			$imgfavi = '';
			if ( 1 == get_option( 'mainwp_use_favicon', 1 ) ) {
				$favi_url = MainWP_Connect::get_favico_url( $website );
				$imgfavi  = '<img src="' . $favi_url . '" width="16" height="16" style="vertical-align:middle;"/>&nbsp;';
			}
			$pagetitle = $imgfavi . ' ' . $website->url;
		}

		$params = array(
			'title' => $pagetitle,
		);

		MainWP_UI::render_top_header( $params );

		self::render_managesites_header( $site_pages, $managesites_pages, $subPages, $site_id, $shownPage );

		if ( 'ManageSites' === $shownPage || 'MonitoringSites' === $shownPage ) {
			$which = strtolower( $shownPage );
			MainWP_UI::render_second_top_header( $which );
		}
	}

	/**
	 * Method render_footer()
	 *
	 * Close the page container.
	 *
	 * @param string $shownPage Current Page.
	 * @param string $subPages Sites subpages.
	 */
	public static function render_footer( $shownPage, $subPages = false ) {
		echo '</div>';
	}

	/**
	 * Method render_managesites_header()
	 *
	 * Render manage sites header.
	 *
	 * @param array  $site_pages site pages.
	 * @param array  $managesites_pages manage site pages.
	 * @param array  $subPages sub pages.
	 * @param int    $site_id Site id.
	 * @param string $shownPage Current Page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
	 */
	private static function render_managesites_header( $site_pages, $managesites_pages, $subPages, $site_id, $shownPage ) {

		$renderItems = array();

		if ( isset( $managesites_pages[ $shownPage ] ) ) {
			foreach ( $managesites_pages as $page => $value ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, $page ) ) {
					continue;
				}

				$item           = $value;
				$item['active'] = ( $page == $shownPage ) ? true : false;
				$renderItems[]  = $item;
			}
		} elseif ( $site_id ) {
			foreach ( $site_pages as $page => $value ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, $page ) ) {
					continue;
				}
				$item           = $value;
				$item['active'] = ( $page == $shownPage ) ? true : false;
				$renderItems[]  = $item;
			}
		}

		if ( isset( $subPages ) && is_array( $subPages ) ) {
			foreach ( $subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageSites' . $subPage['slug'] ) ) {
					continue;
				}

				if ( isset( $subPage['sitetab'] ) && true === $subPage['sitetab'] && empty( $site_id ) ) {
					continue;
				}
				$item           = array();
				$item['title']  = $subPage['title'];
				$item['href']   = 'admin.php?page=ManageSites' . $subPage['slug'] . ( $site_id ? '&id=' . esc_attr( $site_id ) : '' );
				$item['active'] = ( $subPage['slug'] == $shownPage ) ? true : false;
				$renderItems[]  = $item;
			}
		}
		MainWP_UI::render_page_navigation( $renderItems );
	}

	/**
	 * Method render_import_sites()
	 *
	 * Render import sites dialog.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
	 */
	public static function render_import_sites() {
		?>
		<div id="mainwp-importing-sites" class="ui active inverted dimmer" style="display:none">
			<div class="ui medium text loader"><?php esc_html_e( 'Importing', 'mainwp' ); ?></div>
		</div>
		<?php
		$errors = array();
		if ( isset( $_FILES['mainwp_managesites_file_bulkupload']['error'] ) && UPLOAD_ERR_OK == $_FILES['mainwp_managesites_file_bulkupload']['error'] && check_admin_referer( 'mainwp-admin-nonce' ) ) {
			if ( isset( $_FILES['mainwp_managesites_file_bulkupload']['tmp_name'] ) && is_uploaded_file( $_FILES['mainwp_managesites_file_bulkupload']['tmp_name'] ) ) {
				$tmp_path = isset( $_FILES['mainwp_managesites_file_bulkupload']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['mainwp_managesites_file_bulkupload']['tmp_name'] ) ) : '';
				MainWP_System_Utility::get_wp_file_system();

				/**
				 * WordPress files system object.
				 *
				 * @global object
				 */
				global $wp_filesystem;

				$content = $wp_filesystem->get_contents( $tmp_path );

				// to compatible with EOL on OS.
				$content        = str_replace( "\r\n", "\n", $content );
				$content        = str_replace( "\r", "\n", $content );
				$lines          = explode( "\n", $content );
				$default_values = array(
					'name'               => '',
					'url'                => '',
					'adminname'          => '',
					'wpgroups'           => '',
					'uniqueId'           => '',
					'http_user'          => '',
					'http_pass'          => '',
					'verify_certificate' => 1,
					'ssl_version'        => 'auto',
				);

				if ( is_array( $lines ) && ( 0 < count( $lines ) ) ) {
					$row         = 0;
					$header_line = null;

					foreach ( $lines as $originalLine ) {

						$line = trim( $originalLine );

						if ( MainWP_Utility::starts_with( $line, '#' ) ) {
							continue;
						}

						$items = str_getcsv( $line, ',' );

						if ( ( null == $header_line ) && ! empty( $_POST['mainwp_managesites_chk_header_first'] ) ) {
							$header_line = $line . "\r\n";
							continue;
						}

						if ( 3 > count( $items ) ) {
							continue;
						}

						$x = 0;
						foreach ( $default_values as $field => $val ) {
							$value = isset( $items[ $x ] ) ? $items[ $x ] : $val;

							if ( 'verify_certificate' === $field ) {
								if ( 'T' === $value ) {
									$value = '1';
								} elseif ( 'Y' === $value ) {
									$value = '0';
								}
							}

							$import_data[ $field ] = $value;
							$x++;
						}
						$encoded = wp_json_encode( $import_data );
						?>
						<input type="hidden" id="mainwp_managesites_import_csv_line_<?php echo ( $row + 1 ); ?>" value="" encoded-data="<?php echo esc_attr( $encoded ); ?>" original="<?php echo esc_attr( $originalLine ); ?>" />
						<?php
						$row++;
					}
					$header_line = trim( $header_line );

					?>
					<input type="hidden" id="mainwp_managesites_do_import" value="1"/>
					<input type="hidden" id="mainwp_managesites_total_import" value="<?php echo esc_attr( $row ); ?>"/>

					<div class="mainwp_managesites_import_listing" id="mainwp_managesites_import_logging">
						<pre class="log"><?php echo esc_html( $header_line ); ?></pre>
					</div>
					<div class="mainwp_managesites_import_listing" id="mainwp_managesites_import_fail_logging" style="display: none;">
					<?php
						echo esc_html( $header_line );
					?>
					</div>

					<?php
				} else {
					$errors[] = __( 'Invalid data. Please, review the import file.', 'mainwp' ) . '<br />';
				}
			} else {
				$errors[] = __( 'Upload failed. Please, try again.', 'mainwp' ) . '<br />';
			}
		} else {
			$errors[] = __( 'Upload failed. Please, try again.', 'mainwp' ) . '<br />';
		}

		if ( 0 < count( $errors ) ) {
			?>
			<div class="error below-h2">
				<?php
				foreach ( $errors as $error ) {
					?>
					<p><strong><?php esc_html_e( 'Error', 'mainwp' ); ?></strong>: <?php echo esc_html( $error ); ?></p>
				<?php } ?>
			</div>
			<?php
		}
	}

	/**
	 * Method render_sync_exts_settings()
	 *
	 * Render sync extension settings.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Extensions_View::get_available_extensions()
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_indexed_extensions_infor()
	 */
	public static function render_sync_exts_settings() {
		$sync_extensions_options = apply_filters_deprecated( 'mainwp-sync-extensions-options', array( array() ), '4.0.7.2', 'mainwp_sync_extensions_options' );  // @deprecated Use 'mainwp_sync_extensions_options' instead.
		$sync_extensions_options = apply_filters( 'mainwp_sync_extensions_options', $sync_extensions_options );

		$working_extensions  = MainWP_Extensions_Handler::get_indexed_extensions_infor();
		$available_exts_data = MainWP_Extensions_View::get_available_extensions();
		if ( 0 < count( $working_extensions ) && 0 < count( $sync_extensions_options ) ) {
			?>

			<h3 class="ui dividing header">
				<?php esc_html_e( ' Extensions Settings Synchronization', 'mainwp' ); ?>
				<div class="sub header"><?php esc_html_e( 'You have Extensions installed that require an additional plugin to be installed on this new Child Site for the Extension to work correctly. From the list below select the plugins you want to install and if you want to apply the Extensions default settings to this Child site.', 'mainwp' ); ?></div>
			</h3>

			<?php
			foreach ( $working_extensions as $slug => $data ) {
				$dir_slug = dirname( $slug );

				if ( ! isset( $sync_extensions_options[ $dir_slug ] ) ) {
					continue;
				}

				$sync_info = isset( $sync_extensions_options[ $dir_slug ] ) ? $sync_extensions_options[ $dir_slug ] : array();
				$ext_name  = str_replace( 'MainWP', '', $data['name'] );
				$ext_name  = str_replace( 'Extension', '', $ext_name );
				$ext_name  = trim( $ext_name );
				$ext_name  = esc_html( $ext_name );

				$ext_data = isset( $available_exts_data[ dirname( $slug ) ] ) ? $available_exts_data[ dirname( $slug ) ] : array();

				if ( isset( $ext_data['img'] ) ) {
					$img_url = $ext_data['img'];
				} else {
					$img_url = MAINWP_PLUGIN_URL . 'assets/images/extensions/placeholder.png';
				}

				$html  = '<div class="ui grid field">';
				$html .= '<div class="sync-ext-row" slug="' . $dir_slug . '" ext_name = "' . esc_attr( $ext_name ) . '"status="queue">';
				$html .= '<h4>' . $ext_name . '</h4>';
				if ( isset( $sync_info['plugin_slug'] ) && ! empty( $sync_info['plugin_slug'] ) ) {
					$html .= '<div class="sync-install-plugin" slug="' . esc_attr( dirname( $sync_info['plugin_slug'] ) ) . '" plugin_name="' . esc_attr( $sync_info['plugin_name'] ) . '">';
					$html .= '<div class="ui checkbox"><input type="checkbox" class="chk-sync-install-plugin" /> <label>' . esc_html( sprintf( __( 'Install %1$s plugin', 'mainwp' ), esc_html( $sync_info['plugin_name'] ) ) ) . '</label></div> ';
					$html .= '<i class="ui active inline loader tiny" style="display: none"></i> <span class="status"></span>';
					$html .= '</div>';
					if ( ! isset( $sync_info['no_setting'] ) || empty( $sync_info['no_setting'] ) ) {
						$html .= '<div class="sync-options options-row">';
						$html .= '<div class="ui checkbox"><input type="checkbox" /><label> ';
						$html .= sprintf( __( 'Apply %1$s %2$ssettings%3$s', 'mainwp' ), esc_html( $sync_info['plugin_name'] ), '<a href="admin.php?page=' . $data['page'] . '">', '</a>' );
						$html .= '</label>';
						$html .= '</div> ';
						$html .= '<i class="ui active inline loader tiny" style="display: none"></i> <span class="status"></span>';
						$html .= '</div>';
					}
				} else {
					$html .= '<div class="sync-global-options options-row">';
					$html .= '<div class="ui checkbox"><input type="checkbox" /> <label>' . esc_html( sprintf( __( 'Apply global %1$s options' ), trim( $ext_name ) ) ) . '</label></div> ';
					$html .= '<i class="ui active inline loader tiny"  style="display: none"></i> <span class="status"></span>';
					$html .= '</div>';
				}
				$html .= '</div>';
				$html .= '</div>';

				echo $html;
			}
		}
	}

	/**
	 * Method render_dashboard()
	 *
	 * Render individual Child Site Overview page.
	 *
	 * @param mixed $website Child Site.
	 * @param mixed $page Page to render.
	 *
	 * @return string Sites Overview Page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Overview::render_dashboard_body()
	 */
	public static function render_dashboard( &$website, &$page ) {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'access_individual_dashboard' ) ) {
			mainwp_do_not_have_permissions( __( 'individual dashboard', 'mainwp' ) );
			return;
		}
		?>
		<div>
			<?php
			if ( -1 == $website->mainwpdir ) {
				echo '<div class="ui yellow message"><span class="mainwp_conflict" siteid="' . intval( $website->id ) . '"><strong>Configuration issue detected</strong>: MainWP has no write privileges to the uploads directory. Because of this some of the functionality might not work.</span></div>';
			}

			/**
			 * Screen layout columns global.
			 *
			 * @global string
			 */
			global $screen_layout_columns;

			MainWP_Overview::render_dashboard_body( array( $website ), $page, $screen_layout_columns );
			?>
		</div>
		<?php
	}

	/**
	 * Method render_header_tabs()
	 *
	 * Render Sites sub page header tabs.
	 *
	 * @param mixed $active_tab Currently active tab.
	 * @param mixed $active_text Currently active drop down text.
	 * @param mixed $show_language_updates Whether or not to show translations.
	 */
	public static function render_header_tabs( $active_tab, $active_text, $show_language_updates ) {
		?>
		<div class="mainwp-sub-header">
			<div class="ui grid">
				<div class="equal width row">
					<div class="middle aligned column">
						<?php echo apply_filters( 'mainwp_widgetupdates_actions_top', '' ); ?>
					</div>
					<div class="right aligned middle aligned column">
						<div class="inline field">
							<div class="ui selection dropdown">
								<div class="text"><?php echo $active_text; ?></div>
								<i class="dropdown icon"></i>
								<div class="menu">
									<div class="<?php echo 'WordPress' === $active_tab ? 'active' : ''; ?> item" data-tab="wordpress" data-value="wordpress"><?php esc_html_e( 'WordPress Updates', 'mainwp' ); ?></div>
									<div class="<?php echo 'plugins' === $active_tab ? 'active' : ''; ?> item" data-tab="plugins" data-value="plugins"><?php esc_html_e( 'Plugins Updates', 'mainwp' ); ?></div>
									<div class="<?php echo 'themes' === $active_tab ? 'active' : ''; ?> item" data-tab="themes" data-value="themes"><?php esc_html_e( 'Themes Updates', 'mainwp' ); ?></div>
									<?php if ( $show_language_updates ) : ?>
									<div class="<?php echo 'trans' === $active_tab ? 'active' : ''; ?> item" data-tab="translations" data-value="translations"><?php esc_html_e( 'Translations Updates', 'mainwp' ); ?></div>
									<?php endif; ?>
									<div class="<?php echo 'abandoned-plugins' === $active_tab ? 'active' : ''; ?> item" data-tab="abandoned-plugins" data-value="abandoned-plugins"><?php esc_html_e( 'Abandoned Plugins', 'mainwp' ); ?></div>
									<div class="<?php echo 'abandoned-themes' === $active_tab ? 'active' : ''; ?> item" data-tab="abandoned-themes" data-value="abandoned-themes"><?php esc_html_e( 'Abandoned Themes', 'mainwp' ); ?></div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Method render_scan_site()
	 *
	 * Render Security Scan sub page.
	 *
	 * @param mixed $website Child Site.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 */
	public static function render_scan_site( &$website ) {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_security_issues' ) ) {
			mainwp_do_not_have_permissions( __( 'security scan', 'mainwp' ) );
			return;
		}
		?>
		<div class="ui segment">
			<h3 class="ui dividing header"><?php esc_html_e( 'Basic Security Check', 'mainwp' ); ?></h3>
			<?php
			// Render security check issues.
			$websiteid = isset( $_GET['scanid'] ) ? intval( $_GET['scanid'] ) : null;
			$website   = MainWP_DB::instance()->get_website_by_id( $websiteid );
			if ( empty( $website ) ) {
				return;
			}
			if ( mainwp_current_user_have_right( 'dashboard', 'manage_security_issues' ) ) {
				do_action_deprecated( 'mainwp-securityissues-sites', array( $website ), '4.0.7.2', 'mainwp_securityissues_sites' ); // @deprecated Use 'mainwp_securityissues_sites' instead.

				/**
				 * Action: mainwp_securityissues_sites
				 *
				 * Fires on a child site Security Scan page at top.
				 *
				 * @hooked MainWP basic security scan features.
				 *
				 * @param object $website Object containing child site info.
				 *
				 * @since Unknown
				 */
				do_action( 'mainwp_securityissues_sites', $website );
			}
			?>

			<?php
			// Hook in MainWP Sucuri Extension.
			if ( mainwp_current_user_have_right( 'extension', 'mainwp-sucuri-extension' ) ) {
				if ( is_plugin_active( 'mainwp-sucuri-extension/mainwp-sucuri-extension.php' ) ) {
					do_action_deprecated( 'mainwp-sucuriscan-sites', array( $website ), '4.0.7.2', 'mainwp_sucuriscan_sites' ); // @deprecated Use 'mainwp_sucuriscan_sites' instead.

					/**
					 * Action: mainwp_sucuriscan_sites
					 *
					 * Fires on a child site Security Scan page.
					 *
					 * @hooked MainWP Sucuri Extension data.
					 *
					 * @param object $website Object containing child site info.
					 *
					 * @since Unknown
					 */
					do_action( 'mainwp_sucuriscan_sites', $website );
				}
			}
			?>

			<?php
			// Hook in MainWP Wordfence Extension.
			if ( mainwp_current_user_have_right( 'extension', 'mainwp-wordfence-extension' ) ) {
				if ( is_plugin_active( 'mainwp-wordfence-extension/mainwp-wordfence-extension.php' ) ) {
					do_action_deprecated( 'mainwp-wordfence-sites', array( $website ), '4.0.7.2', 'mainwp_wordfence_sites' ); // @deprecated Use 'mainwp_wordfence_sites' instead.

					/**
					 * Action: mainwp_wordfence_sites
					 *
					 * Fires on a child site Security Scan page.
					 *
					 * @hooked MainWP Wordfence Extension data.
					 *
					 * @param object $website Object containing child site info.
					 *
					 * @since Unknown
					 */
					do_action( 'mainwp_wordfence_sites', $website );

				}
			}
			?>
		</div>

		<?php
	}

	/**
	 * Method render_edit_site()
	 *
	 * Render individual Child Site Edit sub page.
	 *
	 * @param mixed $websiteid Child Site ID.
	 * @param mixed $updated Site settings updated check.
	 *
	 * @return string Edit Child Site sub page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_groups_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_groups_by_website_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 */
	public static function render_edit_site( $websiteid, $updated ) {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'edit_sites' ) ) {
			mainwp_do_not_have_permissions( __( 'edit sites', 'mainwp' ) );
			return;
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteid, false, array( 'monitoring_notification_emails', 'settings_notification_emails' ) );
		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			$website = null;
		}

		if ( empty( $website ) ) {
			return;
		}

		$groups = MainWP_DB_Common::instance()->get_groups_for_current_user();
		?>
		<div class="ui segment mainwp-edit-site-<?php echo intval( $website->id ); ?>" id="mainwp-edit-site">
			<?php if ( $updated ) : ?>
			<div class="ui message green"><i class="close icon"></i> <?php esc_html_e( 'Child site settings saved successfully.', 'mainwp' ); ?></div>
			<?php endif; ?>
			<form method="POST" action="" id="mainwp-edit-single-site-form" enctype="multipart/form-data" class="ui form">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'UpdateWebsite' . $website->id ); ?>" />
				<h3 class="ui dividing header"><?php esc_html_e( 'General Settings', 'mainwp' ); ?></h3>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Site URL', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter your website URL.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui left action input">
							<select class="ui compact selection dropdown" id="mainwp_managesites_edit_siteurl_protocol" name="mainwp_managesites_edit_siteurl_protocol">
								<option <?php echo ( MainWP_Utility::starts_with( $website->url, 'http:' ) ? 'selected' : '' ); ?> value="http">http://</option>
								<option <?php echo ( MainWP_Utility::starts_with( $website->url, 'https:' ) ? 'selected' : '' ); ?> value="https">https://</option>
							</select>
							<input type="text" id="mainwp_managesites_edit_siteurl" disabled="disabled" name="mainwp_managesites_edit_siteurl" value="<?php echo MainWP_Utility::remove_http_prefix( $website->url, true ); ?>" />
						</div>
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Administrator username', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the website Administrator username.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui left labeled input">
							<input type="text" id="mainwp_managesites_edit_siteadmin" name="mainwp_managesites_edit_siteadmin" value="<?php echo esc_attr( $website->adminname ); ?>" />
						</div>
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Site title', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the website title.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui left labeled input">
							<input type="text" id="mainwp_managesites_edit_sitename" name="mainwp_managesites_edit_sitename" value="<?php echo esc_attr( stripslashes( $website->name ) ); ?>" />
						</div>
					</div>
				</div>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Unique security ID', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'If in use, enter the website Unique ID.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui left labeled input">
							<input type="text" id="mainwp_managesites_edit_uniqueId" name="mainwp_managesites_edit_uniqueId" value="<?php echo esc_attr( $website->uniqueId ); ?>" />
						</div>
					</div>
				</div>
				<?php
				$groupsSite  = MainWP_DB_Common::instance()->get_groups_by_website_id( $website->id );
				$init_groups = '';
				foreach ( $groups as $group ) {
					$init_groups .= ( isset( $groupsSite[ $group->id ] ) && $groupsSite[ $group->id ] ) ? ',' . $group->id : '';
				}
				$init_groups = ltrim( $init_groups, ',' );
				?>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Groups', 'mainwp' ); ?></label>
					<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Add the website to existing group(s).', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui multiple selection dropdown" init-value="<?php echo esc_attr( $init_groups ); ?>">
							<input name="mainwp_managesites_edit_addgroups" value="" type="hidden">
							<i class="dropdown icon"></i>
							<div class="default text"><?php echo ( '' === $init_groups ) ? __( 'No groups added yet.', 'mainwp' ) : ''; ?></div>
							<div class="menu">
								<?php foreach ( $groups as $group ) { ?>
									<div class="item" data-value="<?php echo $group->id; ?>"><?php echo $group->name; ?></div>
								<?php } ?>
							</div>
						</div>
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Require backup before update', 'mainwp' ); ?></label>
					<div class="ui six wide column">
						<select class="ui dropdown" id="mainwp_backup_before_upgrade" name="mainwp_backup_before_upgrade">
							<option <?php echo ( 1 == $website->backup_before_upgrade ) ? 'selected' : ''; ?> value="1"><?php esc_html_e( 'Yes', 'mainwp' ); ?></option>
							<option <?php echo ( 0 == $website->backup_before_upgrade ) ? 'selected' : ''; ?> value="0"><?php esc_html_e( 'No', 'mainwp' ); ?></option>
							<option <?php echo ( 2 == $website->backup_before_upgrade ) ? 'selected' : ''; ?> value="2"><?php esc_html_e( 'Use global setting', 'mainwp' ); ?></option>
						</select>
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Auto update core', 'mainwp' ); ?></label>
					<div class="six wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable if you want MainWP to automatically update WP Core on this website.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<input type="checkbox" name="mainwp_automaticDailyUpdate" id="mainwp_automaticDailyUpdate" <?php echo ( 1 == $website->automatic_update ? 'checked="true"' : '' ); ?>><label for="mainwp_automaticDailyUpdate"></label>
					</div>
				</div>
				<?php if ( mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Ignore core updates', 'mainwp' ); ?></label>
						<div class="six wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable if you want to ignore WP Core updates on this website.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
							<input type="checkbox" name="mainwp_is_ignoreCoreUpdates" id="mainwp_is_ignoreCoreUpdates" <?php echo ( 1 == $website->is_ignoreCoreUpdates ? 'checked="true"' : '' ); ?>><label for="mainwp_is_ignoreCoreUpdates"></label>
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Ignore plugin updates', 'mainwp' ); ?></label>
						<div class="six wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable if you want to ignore plugin updates on this website.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
							<input type="checkbox" name="mainwp_is_ignorePluginUpdates" id="mainwp_is_ignorePluginUpdates" <?php echo ( 1 == $website->is_ignorePluginUpdates ? 'checked="true"' : '' ); ?>><label for="mainwp_is_ignorePluginUpdates"></label>
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Ignore theme updates', 'mainwp' ); ?></label>
						<div class="six wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable if you want to ignore theme updates on this website.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
							<input type="checkbox" name="mainwp_is_ignoreThemeUpdates" id="mainwp_is_ignoreThemeUpdates" <?php echo ( 1 == $website->is_ignoreThemeUpdates ? 'checked="true"' : '' ); ?>><label for="mainwp_is_ignoreThemeUpdates"></label>
						</div>
					</div>
				<?php endif; ?>
				<h3 class="ui dividing header"><?php esc_html_e( 'Child Site Uptime Monitoring (Optional)', 'mainwp' ); ?></h3>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Enable basic uptime monitoring (optional)', 'mainwp' ); ?></label>					
					<div class="six wide column ui toggle checkbox mainwp-checkbox-showhide-elements" hide-parent="monitoring" data-tooltip="<?php esc_attr_e( 'Enable if you want to monitoring this website.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<input type="checkbox" name="mainwp_managesites_edit_disableChecking" id="mainwp_managesites_edit_disableChecking" <?php echo ( 0 == $website->disable_status_check ? 'checked="true"' : '' ); ?>><label for="mainwp_managesites_edit_disableChecking"></label>
					</div>
				</div>
				<?php
				$check_interval = $website->status_check_interval;
				?>
				<div class="ui grid field" <?php echo 1 == $website->disable_status_check ? 'style="display:none"' : ''; ?> hide-element="monitoring">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Check interval (optional)', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Check interval (optional)', 'mainwp' ); ?>" data-inverted="" data-position="top left">					
						<select name="mainwp_managesites_edit_checkInterval" id="mainwp_managesites_edit_checkInterval" class="ui dropdown">
							<option value="5" <?php echo ( 5 == $check_interval ? 'selected' : '' ); ?>><?php esc_html_e( 'Every 5 minutes', 'mainwp' ); ?></option>
							<option value="10" <?php echo ( 10 == $check_interval ? 'selected' : '' ); ?>><?php esc_html_e( 'Every 10 minutes', 'mainwp' ); ?></option>
							<option value="30" <?php echo ( 30 == $check_interval ? 'selected' : '' ); ?>><?php esc_html_e( 'Every 30 minutes', 'mainwp' ); ?></option>
							<option value="60" <?php echo ( 60 == $check_interval ? 'selected' : '' ); ?>><?php esc_html_e( 'Every hour', 'mainwp' ); ?></option>
							<option value="180" <?php echo ( 180 == $check_interval ? 'selected' : '' ); ?>><?php esc_html_e( 'Every 3 hours', 'mainwp' ); ?></option>
							<option value="360" <?php echo ( 360 == $check_interval ? 'selected' : '' ); ?>><?php esc_html_e( 'Every 6 hours', 'mainwp' ); ?></option>
							<option value="720" <?php echo ( 720 == $check_interval ? 'selected' : '' ); ?>><?php esc_html_e( 'Twice a day', 'mainwp' ); ?></option>
							<option value="1440" <?php echo ( 1440 == $check_interval ? 'selected' : '' ); ?>><?php esc_html_e( 'Once a day', 'mainwp' ); ?></option>
							<option value="0" <?php echo ( 0 == $check_interval ? 'selected' : '' ); ?>><?php esc_html_e( 'Use global setting', 'mainwp' ); ?></option>
						</select>
					</div>
				</div>				
				<div class="ui grid field" <?php echo 1 == $website->disable_status_check ? 'style="display:none"' : ''; ?> hide-element="monitoring">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Additional notification emails (comma-separated)', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Additional notification emails (comma-separated).', 'mainwp' ); ?>" data-inverted="" data-position="top left">										
						<div class="ui left labeled input">
							<input type="text" id="mainwp_managesites_edit_monitoringNotificationEmails" name="mainwp_managesites_edit_monitoringNotificationEmails" value="<?php echo ! empty( $website->monitoring_notification_emails ) ? esc_html( $website->monitoring_notification_emails ) : ''; ?>"/>
						</div>
					</div>
				</div>
				<h3 class="ui dividing header"><?php esc_html_e( 'Sites Health Monitoring (Optional)', 'mainwp' ); ?></h3>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Enable Child Site Health monitoring (optional)', 'mainwp' ); ?></label>					
					<div class="six wide column ui toggle checkbox mainwp-checkbox-showhide-elements" hide-parent="health-monitoring" data-tooltip="<?php esc_attr_e( 'Enable if you want to monitoring this website.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<input type="checkbox" name="mainwp_managesites_edit_disableSiteHealthMonitoring" id="mainwp_managesites_edit_disableSiteHealthMonitoring" <?php echo ( 0 == $website->disable_status_check ? 'checked="true"' : '' ); ?>><label for="mainwp_managesites_edit_disableSiteHealthMonitoring"></label>
					</div>
				</div>
				<?php
				$healthThreshold = $website->health_threshold;
				?>
				<div class="ui grid field" <?php echo 1 == $website->disable_status_check ? 'style="display:none"' : ''; ?> hide-element="health-monitoring">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Site health threshold (optional)', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Site health threshold.', 'mainwp' ); ?>" data-inverted="" data-position="top left">				
						<select name="mainwp_managesites_edit_healthThreshold" id="mainwp_managesites_edit_healthThreshold" class="ui dropdown">
							<option value="80" <?php echo ( 80 == $healthThreshold ? 'selected' : '' ); ?>><?php esc_html_e( 'Should be improved', 'mainwp' ); ?></option>
							<option value="100" <?php echo ( 100 == $healthThreshold ? 'selected' : '' ); ?>><?php esc_html_e( 'Good', 'mainwp' ); ?></option>
							<option value="0" <?php echo ( 0 == $healthThreshold ? 'selected' : '' ); ?>><?php esc_html_e( 'Use global setting', 'mainwp' ); ?></option>
						</select>
					</div>
				</div>				
				<h3 class="ui dividing header"><?php esc_html_e( 'Advanced Settings (Optional)', 'mainwp' ); ?></h3>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Verify certificate (optional)', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Do you want to verify SSL certificate.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<select class="ui dropdown" id="mainwp_managesites_edit_verifycertificate" name="mainwp_managesites_edit_verifycertificate">
						<option <?php echo ( 1 == $website->verify_certificate ) ? 'selected' : ''; ?> value="1"><?php esc_html_e( 'Yes', 'mainwp' ); ?></option>
						<option <?php echo ( 0 == $website->verify_certificate ) ? 'selected' : ''; ?> value="0"><?php esc_html_e( 'No', 'mainwp' ); ?></option>
						<option <?php echo ( 2 == $website->verify_certificate ) ? 'selected' : ''; ?> value="2"><?php esc_html_e( 'Use global setting', 'mainwp' ); ?></option>
						</select>
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'SSL version (optional)', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Select SSL Version. If you are not sure, select "Auto Detect".', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<select class="ui dropdown" id="mainwp_managesites_edit_ssl_version" name="mainwp_managesites_edit_ssl_version">
							<option <?php echo ( 0 == $website->ssl_version ) ? 'selected' : ''; ?> value="0"><?php esc_html_e( 'Auto detect', 'mainwp' ); ?></option>
							<option <?php echo ( 6 == $website->ssl_version ) ? 'selected' : ''; ?> value="6"><?php esc_html_e( "Let's encrypt (TLS v1.2)", 'mainwp' ); ?></option>
							<option <?php echo ( 1 == $website->ssl_version ) ? 'selected' : ''; ?> value="1"><?php esc_html_e( 'TLS v1.x', 'mainwp' ); ?></option>
							<option <?php echo ( 2 == $website->ssl_version ) ? 'selected' : ''; ?> value="2"><?php esc_html_e( 'SSL v2', 'mainwp' ); ?></option>
							<option <?php echo ( 3 == $website->ssl_version ) ? 'selected' : ''; ?> value="3"><?php esc_html_e( 'SSL v3', 'mainwp' ); ?></option>
							<option <?php echo ( 4 == $website->ssl_version ) ? 'selected' : ''; ?> value="4"><?php esc_html_e( 'TLS v1.0', 'mainwp' ); ?></option>
							<option <?php echo ( 5 == $website->ssl_version ) ? 'selected' : ''; ?> value="5"><?php esc_html_e( 'TLS v1.1', 'mainwp' ); ?></option>
						</select>
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Force IPv4 (optional)', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Do you want to force IPv4 for this child site?', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<select class="ui dropdown" id="mainwp_managesites_edit_forceuseipv4" name="mainwp_managesites_edit_forceuseipv4">
							<option <?php echo ( 1 == $website->force_use_ipv4 ) ? 'selected' : ''; ?> value="1"><?php esc_html_e( 'Yes', 'mainwp' ); ?></option>
							<option <?php echo ( 0 == $website->force_use_ipv4 ) ? 'selected' : ''; ?> value="0"><?php esc_html_e( 'No', 'mainwp' ); ?></option>
							<option <?php echo ( 2 == $website->force_use_ipv4 ) ? 'selected' : ''; ?> value="2"><?php esc_html_e( 'Use global setting', 'mainwp' ); ?></option>
						</select>
					</div>
				</div>
				<input style="display:none" type="text" name="fakeusernameremembered"/>
				<input style="display:none" type="password" name="fakepasswordremembered"/>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'HTTP Username (optional)', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'If the child site is HTTP Basic Auth protected, enter the HTTP username here.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui left labeled input">
							<input type="text" id="mainwp_managesites_edit_http_user" name="mainwp_managesites_edit_http_user" value="<?php echo ( empty( $website->http_user ) ? '' : $website->http_user ); ?>" autocomplete="new-http-user" />
						</div>
					</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'HTTP Password (optional)', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'If the child site is HTTP Basic Auth protected, enter the HTTP password here.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui left labeled input">
							<input type="password" id="mainwp_managesites_edit_http_pass" name="mainwp_managesites_edit_http_pass" value="<?php echo ( empty( $website->http_pass ) ? '' : $website->http_pass ); ?>" autocomplete="new-password" />
						</div>
					</div>
				</div>
				<?php
				do_action_deprecated( 'mainwp-manage-sites-edit', array( $website ), '4.0.7.2', 'mainwp_manage_sites_edit' ); // @deprecated Use 'mainwp_manage_sites_edit' instead.
				do_action_deprecated( 'mainwp-extension-sites-edit', array( $website ), '4.0.7.2', 'mainwp_manage_sites_edit' ); // @deprecated Use 'mainwp_manage_sites_edit' instead.

				/** This action is documented in ../pages/page-mainwp-manage-sites.php */
				do_action( 'mainwp_manage_sites_edit', $website );
				do_action( 'mainwp_extension_sites_edit_tablerow', $website );
				?>
				<div class="ui divider"></div>
				<input type="submit" name="submit" id="submit" class="ui button green big right floated" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
			</form>
		</div>
		</div>
		<?php
	}

	/**
	 * Render signle site Email notification settings.
	 *
	 * Credits.
	 *
	 * Plugin-Name: WooCommerce.
	 * Plugin URI: https://woocommerce.com/.
	 * Author: Automattic.
	 * Author URI: https://woocommerce.com.
	 * License: GPLv3 or later.
	 *
	 * @param object $website       Object containng the website info.
	 * @param string $type          Email type.
	 * @param bool   $updated_templ True if page loaded after update, false if not.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_notification_types()
	 * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_default_emails_fields()
	 * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_settings_desc()
	 * @uses \MainWP\Dashboard\MainWP_Notification_Settings::render_update_template_message()
	 * @uses \MainWP\Dashboard\MainWP_Notification_Template::get_template_name_by_notification_type()
	 * @uses \MainWP\Dashboard\MainWP_Notification_Template::is_overrided_template()
	 */
	public static function render_site_edit_email_settings( $website, $type, $updated_templ ) {

		$emails_settings = json_decode( $website->settings_notification_emails, true );
		if ( ! is_array( $emails_settings ) ) {
			$emails_settings = array();
		}

		// to fix incorrect field name.
		if ( isset( $emails_settings['daily_digets'] ) ) {
			$emails_settings['daily_digest'] = $emails_settings['daily_digets'];
			unset( $emails_settings['daily_digets'] );
		}

		$default = MainWP_Notification_Settings::get_default_emails_fields( $type );
		$options = isset( $emails_settings[ $type ] ) ? $emails_settings[ $type ] : array();
		$options = array_merge( $default, $options );

		$title  = MainWP_Notification_Settings::get_notification_types( $type );
		$siteid = $website->id;

		$email_description = MainWP_Notification_Settings::get_settings_desc( $type );
		?>
		<div class="ui segment">
		<?php if ( $updated ) : ?>
		<div class="ui message green"><i class="close icon"></i> <?php esc_html_e( 'Email settings saved successfully.', 'mainwp' ); ?></div>
		<?php endif; ?>
		<?php MainWP_Notification_Settings::render_update_template_message( $updated_templ ); ?>		
		<form method="POST" action="admin.php?page=managesites&emailsettingsid=<?php echo $siteid; ?>" class="ui form">
			<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'UpdateWebsiteEmailSettings' . $siteid ); ?>" />
			<input type="hidden" name="mainwp_managesites_setting_emails_type" value="<?php echo esc_html( $type ); ?>" />				
			<div class="ui info message"><?php _e( '<a href="https://mainwp.com/extension/boilerplate/" target="_blank">Boilerplate</a> and <a href="https://mainwp.com/extension/pro-reports/" target="_blank">Reports</a> extensions tokens are supported in the email settings and templates if extensions are in use.', 'mainwp' ); ?></div>
			<h3 class="ui header"><?php echo $title; ?></h3>
			<div class="sub header"><?php echo $email_description; ?></h3></div>
			<div class="ui divider"></div>
			<div class="ui grid field">
				<label class="six wide column middle aligned"><?php esc_html_e( 'Enable', 'mainwp' ); ?></label>
				<div class="six wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable this email notification.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
					<input type="checkbox" name="mainwp_managesites_edit_settingEmails[<?php echo esc_html( $type ); ?>][disable]" id="mainwp_managesites_edit_settingEmails[<?php echo esc_html( $type ); ?>][disable]" <?php echo ( 0 == $options['disable'] ) ? 'checked="true"' : ''; ?>/>
				</div>				
			</div>					
			<div class="ui grid field" >				
				<label class="six wide column middle aligned"><?php esc_html_e( 'Recipient(s)', 'mainwp' ); ?></label>
				<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'You can add multiple emails by separating them with comma.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
					<input type="text" name="mainwp_managesites_edit_settingEmails[<?php echo esc_html( $type ); ?>][recipients]" id="mainwp_managesites_edit_settingEmails[<?php echo esc_html( $type ); ?>][recipients]" value="<?php echo esc_html( $options['recipients'] ); ?>"/>
				</div>
			</div>
			<div class="ui grid field" >				
				<label class="six wide column middle aligned"><?php esc_html_e( 'Subject', 'mainwp' ); ?></label>
				<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the email subject.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
					<input type="text" name="mainwp_managesites_edit_settingEmails[<?php echo esc_html( $type ); ?>][subject]" id="mainwp_managesites_edit_settingEmails[<?php echo esc_html( $type ); ?>][subject]" value="<?php echo esc_html( $options['subject'] ); ?>"/>
				</div>
			</div>
			<div class="ui grid field" >				
				<label class="six wide column middle aligned"><?php esc_html_e( 'Email heading', 'mainwp' ); ?></label>
				<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the email heading.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
				<input type="text" name="mainwp_managesites_edit_settingEmails[<?php echo esc_html( $type ); ?>][heading]" id="mainwp_managesites_edit_settingEmails[<?php echo esc_html( $type ); ?>][heading]" value="<?php echo esc_html( $options['heading'] ); ?>"/>
				</div>
			</div>
			<div class="ui grid field" >				
				<label class="six wide column middle aligned"><?php esc_html_e( 'HTML template', 'mainwp' ); ?></label>
				<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Manage the email HTML template.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
				<?php
				$templ     = MainWP_Notification_Template::get_template_name_by_notification_type( $type );
				$overrided = MainWP_Notification_Template::instance()->is_overrided_template( $type );
				echo $overrided ? esc_html__( 'This template has been overridden and can be found in:', 'mainwp' ) . ' <code>wp-content/uploads/mainwp/templates/' . $templ . '</code>' : esc_html__( 'To override and edit this email template copy:', 'mainwp' ) . ' <code>mainwp/templates/' . $templ . '</code> ' . esc_html__( 'to the folder:', 'mainwp' ) . ' <code>wp-content/uploads/mainwp/templates/' . $templ . '</code>';
				?>
				</div>		
			</div>	
			<div class="ui grid field" >				
				<label class="six wide column middle aligned"></label>
				<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Manage the email HTML template.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
				<?php
				if ( $overrided ) {
					?>
					<a href="<?php echo wp_nonce_url( 'admin.php?page=managesites&emailsettingsid=' . $siteid . '&edit-email=' . $type, 'delete-email-template' ); ?>" onclick="mainwp_confirm('<?php echo esc_js( 'Are you sure you want to delete this template file?', 'mainwp' ); ?>', function(){ window.location = jQuery('a#email-delete-template').attr('href');}); return false;" id="email-delete-template" class="ui button"><?php esc_html_e( 'Delete Template', 'mainwp' ); ?></a>
					<?php
				} else {
					?>
					<a href="<?php echo wp_nonce_url( 'admin.php?page=managesites&emailsettingsid=' . $siteid . '&edit-email=' . $type, 'copy-email-template' ); ?>" class="ui button"><?php esc_html_e( 'Copy file to uploads', 'mainwp' ); ?></a>
					<?php
				}
				?>
				<a href="javascript:void(0)" class="ui button" onclick="mainwp_view_template('<?php echo esc_js( $type ); ?>'); return false;"><?php esc_html_e( 'View Template', 'mainwp' ); ?></a>
				</div>		
			</div>	
			<div class="ui divider"></div>
			<a href="admin.php?page=managesites&emailsettingsid=<?php echo $siteid; ?>" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>
			<input type="submit" name="submit" id="submit" class="ui button green big right floated" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
			</form>
		</div>
		</div>		
		<?php self::render_edit_template( $type, $siteid ); ?>
		<?php
	}

	/**
	 * Render the email notification edit form.
	 *
	 * Credits.
	 *
	 * Plugin-Name: WooCommerce.
	 * Plugin URI: https://woocommerce.com/.
	 * Author: Automattic.
	 * Author URI: https://woocommerce.com.
	 * License: GPLv3 or later.
	 *
	 * @param string $type   Email type.
	 * @param bool   $siteid Child site ID.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Notification_Template::get_template_name_by_notification_type()
	 * @uses \MainWP\Dashboard\MainWP_Notification_Template::get_default_templates_dir()
	 * @uses \MainWP\Dashboard\MainWP_Notification_Template::get_custom_templates_dir()
	 */
	public static function render_edit_template( $type, $siteid = false ) {

		$template    = MainWP_Notification_Template::get_template_name_by_notification_type( $type );
		$default_dir = MainWP_Notification_Template::instance()->get_default_templates_dir();
		$custom_dir  = MainWP_Notification_Template::instance()->get_custom_templates_dir();

		$custom_file   = $custom_dir . $template;
		$default_file  = $default_dir . $template;
		$template_file = apply_filters( 'mainwp_default_template_locate', $default_file, $template, $default_dir, $type, $siteid );

		if ( $siteid ) {
			$localion = 'admin.php?page=managesites&emailsettingsid=' . $siteid . '&edit-email=' . $type;
		} else {
			$localion = 'admin.php?page=SettingsEmail&edit-email=' . $type;
		}

		$editable = false;
		?>
			<div class="ui large modal" id="mainwp-edit-email-template-modal">
				<div class="header"><?php esc_html_e( 'Edit Email Template', 'mainwp' ); ?></div>
					<div class="scrolling header">
					<form method="POST" id="email-template-form" action="<?php echo esc_html( $localion ); ?>" class="ui form">		
						<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'save-email-template' ); ?>" />
						<div class="template <?php echo esc_attr( $type ); ?>">		
							<?php if ( file_exists( $custom_file ) ) : ?>
								<div class="editor">
									<textarea class="code" cols="80" rows="20"
									<?php
									if ( ! is_writable( $custom_file ) ) : // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_is_writable -- required to achieven desired results. Pull requests are welcome.
										?>
										readonly="readonly" disabled="disabled"
										<?php
									else :
											$editable = true;
										?>
										name="edit_<?php echo esc_attr( $type ) . '_code'; ?>"<?php endif; ?>><?php echo esc_html( file_get_contents( $custom_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- required to achieven desired results. Pull requests are welcome. ?></textarea>
								</div>
							<?php elseif ( file_exists( $template_file ) ) : ?>						
								<div class="editor">
									<textarea class="code" readonly="readonly" disabled="disabled" cols="25" rows="20"><?php echo esc_html( file_get_contents( $template_file ) );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- required to achieven desired results. Pull requests are welcome. ?></textarea>
								</div>
							<?php else : ?>
								<p><?php esc_html_e( 'File was not found.', 'mainwp' ); ?></p>
							<?php endif; ?>
						</div>	
					</form>			
					</div>
					<div class="actions">
						<?php if ( $editable ) { ?>
						<input type="submit" form="email-template-form" class="ui green button" value="<?php esc_attr_e( 'Save', 'mainwp' ); ?>"/>
						<?php } ?>
						<input type="button" class="ui cancel button" value="<?php esc_attr_e( 'Close', 'mainwp' ); ?>"/>					
					</div>			
				</div>
			</div>
			<script type="text/javascript">
				jQuery( document ).ready( function () {
					mainwp_view_template = function( templ ) {
						jQuery( "#mainwp-edit-email-template-modal" ).modal( {
							closable: false,
							onHide: function() {
								location.href = '<?php echo esc_js( $localion ); ?>';
							}
						} ).modal( 'show' );
					}
				} );
			</script>
		<?php
	}

	/**
	 * Render all email settings options.
	 *
	 * @param object $website Object containing the website info.
	 * @param bool   $updated True if page loaded after update, false if not.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_notification_types()
	 * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_default_emails_fields()
	 * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_settings_desc()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_notification_email()
	 */
	public static function render_edit_site_email_settings( $website, $updated ) {
		$emails_settings = json_decode( $website->settings_notification_emails, true );
		if ( ! is_array( $emails_settings ) ) {
			$emails_settings = array();
		}

		// to fix incorrect field name.
		if ( isset( $emails_settings['daily_digets'] ) ) {
			$emails_settings['daily_digest'] = $emails_settings['daily_digets'];
			unset( $emails_settings['daily_digets'] );
		}

		$email_description   = '';
		$notification_emails = MainWP_Notification_Settings::get_notification_types();
		$default_recipients  = MainWP_System_Utility::get_notification_email();
		?>
		<div class="ui segment">
		<?php if ( $updated ) : ?>
		<div class="ui message green"><i class="close icon"></i> <?php esc_html_e( 'Email settings saved successfully.', 'mainwp' ); ?></div>
		<?php endif; ?>		
		<div class="ui info message">
			<?php esc_html_e( 'Email notifications sent from MainWP Dashboard about this child site are listed below. Click on an email to configure it.', 'mainwp' ); ?>
		</div>
			<table class="ui single line table" id="mainwp-emails-settings-table">
				<thead>
					<tr>						
						<th class="collapsing"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Email', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Description', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Recipient(s)', 'mainwp' ); ?></th>						
						<th style="text-align:right"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $notification_emails as $type => $name ) : ?>
						<?php
						$options           = isset( $emails_settings[ $type ] ) ? $emails_settings[ $type ] : array();
						$default           = MainWP_Notification_Settings::get_default_emails_fields( $type );
						$options           = array_merge( $default, $options );
						$email_description = MainWP_Notification_Settings::get_settings_desc( $type );
						?>
						<tr>
							<td><?php echo ( ! $options['disable'] ) ? '<span data-tooltip="Enabled." data-position="right center" data-inverted=""><i class="circular green check inverted icon"></i></span>' : '<span data-tooltip="Disabled." data-position="right center" data-inverted=""><i class="circular x icon inverted disabled"></i></span>'; ?></td>
							<td><a href="admin.php?page=managesites&emailsettingsid=<?php echo intval( $website->id ); ?>&edit-email=<?php echo rawurlencode( $type ); ?>" data-tooltip="<?php esc_html_e( 'Click to configure the email settings.', 'mainwp' ); ?>" data-position="right center" data-inverted=""><?php echo esc_html( $name ); ?></a></td>
							<td><?php echo esc_html( $email_description ); ?></td>
							<td><?php echo esc_html( $options['recipients'] ); ?></td>
							<td style="text-align:right"><a href="admin.php?page=managesites&emailsettingsid=<?php echo intval( $website->id ); ?>&edit-email=<?php echo rawurlencode( $type ); ?>" data-tooltip="<?php esc_html_e( 'Click to configure the email settings.', 'mainwp' ); ?>" data-position="left center" data-inverted="" class="ui green mini button"><?php esc_html_e( 'Manage', 'mainwp' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th class="collapsing"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Email', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Description', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Recipient(s)', 'mainwp' ); ?></th>
						<th></th>
					</tr>
				</tfoot>
			</table>
			<?php
			/**
			 * Action: mainwp_manage_sites_email_settings
			 *
			 * Fires on the Email Settigns page at bottom.
			 *
			 * @param object $website Object containing the website info.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_manage_sites_email_settings', $website );
			?>
			<script type="text/javascript">
			jQuery( document ).ready( function() {
				jQuery( '#mainwp-emails-settings-table' ).DataTable( {						
					"stateSave":  true,
					"paging":   false,
					"ordering": true,
					"columnDefs": [ { "orderable": false, "targets": [ 0, 3 ] } ],
					"order": [ [ 1, "asc" ] ]
				} );
			} );
			</script>			
		</div>
		<?php
	}

	/**
	 * Method m_reconnect_site()
	 *
	 * Reconnect chid site.
	 *
	 * @param object $website The website object.
	 *
	 * @return boolean true|false.
	 * @throws \Exception Exception on errors.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
	 * @uses \MainWP\Dashboard\MainWP_Exception
	 * @uses \MainWP\Dashboard\MainWP_Sync::sync_information_array()
	 * @uses \MainWP\Dashboard\MainWP_Sync::sync_site()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_openssl_conf()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 */
	public static function m_reconnect_site( $website ) {
		if ( MainWP_System_Utility::can_edit_website( $website ) ) {
			try {
				if ( MainWP_Sync::sync_site( $website, true ) ) {
					return true;
				}

				if ( function_exists( 'openssl_pkey_new' ) ) {
					$conf     = array( 'private_key_bits' => 2048 );
					$conf_loc = MainWP_System_Utility::get_openssl_conf();
					if ( ! empty( $conf_loc ) ) {
						$conf['config'] = $conf_loc;
					}
					$res = openssl_pkey_new( $conf );
					openssl_pkey_export( $res, $privkey, null, $conf );
					$pubkey = openssl_pkey_get_details( $res );
					$pubkey = $pubkey['key'];
				} else {
					$privkey = '-1';
					$pubkey  = '-1';
				}

				$information = MainWP_Connect::fetch_url_not_authed(
					$website->url,
					$website->adminname,
					'register',
					array(
						'pubkey'   => $pubkey,
						'server'   => get_admin_url(),
						'uniqueId' => $website->uniqueId,
					),
					true,
					$website->verify_certificate,
					$website->http_user,
					$website->http_pass,
					$website->ssl_version
				);

				if ( isset( $information['error'] ) && '' !== $information['error'] ) {
					$err = urldecode( $information['error'] );
					$err = MainWP_Utility::esc_content( $err );
					throw new \Exception( $err );
				} else {
					if ( isset( $information['register'] ) && 'OK' === $information['register'] ) {
						MainWP_DB::instance()->update_website_values(
							$website->id,
							array(
								'pubkey'   => base64_encode( $pubkey ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode() used for backwards compatibility.
								'privkey'  => base64_encode( $privkey ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode() used for backwards compatibility.
								'nossl'    => $information['nossl'],
								'nosslkey' => ( isset( $information['nosslkey'] ) ? $information['nosslkey'] : '' ),
								'uniqueId' => ( isset( $information['uniqueId'] ) ? $information['uniqueId'] : '' ),
							)
						);
						MainWP_Sync::sync_information_array( $website, $information );
						return true;
					} else {
						throw new \Exception( __( 'Undefined error!', 'mainwp' ) );
					}
				}
			} catch ( MainWP_Exception $e ) {
				if ( 'HTTPERROR' === $e->getMessage() ) {
					throw new \Exception( 'HTTP error' . ( null != $e->get_message_extra() ? ' - ' . $e->get_message_extra() : '' ) );
				} elseif ( 'NOMAINWP' === $e->getMessage() ) {
					$error = sprintf( __( 'MainWP Child plugin not detected. First, install and activate the plugin and add your site to your MainWP Dashboard afterward. If you continue experiencing this issue, check the child site for %1$sknown plugin conflicts%2$s, or check the %3$sMainWP Community%4$s for help.', 'mainwp' ), '<a href="https://meta.mainwp.com/t/known-plugin-conflicts/402">', '</a>', '<a href="https://meta.mainwp.com/c/community-support/5">', '</a>' );
					throw new \Exception( $error );
				}
			}
		} else {
			throw new \Exception( __( 'This operation is not allowed!', 'mainwp' ) );
		}

		return false;
	}

	/**
	 * Method add_site()
	 *
	 * Add Child Site.
	 *
	 * @param mixed $website Child Site.
	 * @param array $output Output values.
	 *
	 * @return self add_wp_site()
	 */
	public static function add_site( $website = false, &$output = array() ) {

		$params['url']               = isset( $_POST['managesites_add_wpurl'] ) ? sanitize_text_field( wp_unslash( $_POST['managesites_add_wpurl'] ) ) : '';
		$params['name']              = isset( $_POST['managesites_add_wpname'] ) ? sanitize_text_field( wp_unslash( $_POST['managesites_add_wpname'] ) ) : '';
		$params['wpadmin']           = isset( $_POST['managesites_add_wpadmin'] ) ? sanitize_text_field( wp_unslash( $_POST['managesites_add_wpadmin'] ) ) : '';
		$params['unique_id']         = isset( $_POST['managesites_add_uniqueId'] ) ? sanitize_text_field( wp_unslash( $_POST['managesites_add_uniqueId'] ) ) : '';
		$params['ssl_verify']        = empty( $_POST['verify_certificate'] ) ? null : intval( $_POST['verify_certificate'] );
		$params['force_use_ipv4']    = ( ! isset( $_POST['force_use_ipv4'] ) || ( empty( $_POST['force_use_ipv4'] ) && ( '0' !== $_POST['force_use_ipv4'] ) ) ? null : intval( $_POST['force_use_ipv4'] ) );
		$params['ssl_version']       = ! isset( $_POST['ssl_version'] ) || empty( $_POST['ssl_version'] ) ? null : intval( $_POST['ssl_version'] );
		$params['http_user']         = isset( $_POST['managesites_add_http_user'] ) ? sanitize_text_field( wp_unslash( $_POST['managesites_add_http_user'] ) ) : '';
		$params['http_pass']         = isset( $_POST['managesites_add_http_pass'] ) ? wp_unslash( $_POST['managesites_add_http_pass'] ) : '';
		$params['groupids']          = isset( $_POST['groupids'] ) && ! empty( $_POST['groupids'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_POST['groupids'] ) ) ) : array();
		$params['groupnames_import'] = isset( $_POST['groupnames_import'] ) ? sanitize_text_field( wp_unslash( $_POST['groupnames_import'] ) ) : '';

		if ( isset( $_POST['qsw_page'] ) ) {
			$params['qsw_page'] = sanitize_text_field( wp_unslash( $_POST['qsw_page'] ) );
		}

		return self::add_wp_site( $website, $params, $output );
	}

	/**
	 * Medthod add_wp_site()
	 *
	 * Add new Child Site.
	 *
	 * @param mixed $website Child Site.
	 * @param array $params Array of new Child Site to add.
	 * @param array $output Output values.
	 *
	 * @return array $message, $error, $id
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_group_by_name()
	 * @uses \MainWP\Dashboard\MainWP_DB::add_website()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_Exception
	 * @uses \MainWP\Dashboard\MainWP_Sync::sync_information_array()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_openssl_conf()
	 */
	public static function add_wp_site( $website, $params = array(), &$output = array() ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$error      = '';
		$message    = '';
		$id         = 0;
		$fetch_data = null;

		if ( $website ) {
			$error = __( 'The site is already connected to your MainWP Dashboard', 'mainwp' );
		} else {
			try {
				if ( function_exists( 'openssl_pkey_new' ) ) {
					$conf     = array( 'private_key_bits' => 2048 );
					$conf_loc = MainWP_System_Utility::get_openssl_conf();
					if ( ! empty( $conf_loc ) ) {
						$conf['config'] = $conf_loc;
					}
					$res = openssl_pkey_new( $conf );
					openssl_pkey_export( $res, $privkey, null, $conf );
					$pubkey = openssl_pkey_get_details( $res );
					$pubkey = $pubkey['key'];
				} else {
					$privkey = '-1';
					$pubkey  = '-1';
				}

				$url = $params['url'];

				$verifyCertificate = ( ! isset( $params['ssl_verify'] ) || ( empty( $params['ssl_verify'] ) && ( '0' !== $params['ssl_verify'] ) ) ? null : $params['ssl_verify'] );
				$sslVersion        = ! isset( $params['ssl_version'] ) || empty( $params['ssl_version'] ) ? 0 : $params['ssl_version'];
				$addUniqueId       = isset( $params['unique_id'] ) ? $params['unique_id'] : '';
				$http_user         = isset( $params['http_user'] ) ? $params['http_user'] : '';
				$http_pass         = isset( $params['http_pass'] ) ? $params['http_pass'] : '';
				$force_use_ipv4    = isset( $params['force_use_ipv4'] ) ? $params['force_use_ipv4'] : null;
				$information       = MainWP_Connect::fetch_url_not_authed(
					$url,
					$params['wpadmin'],
					'register',
					array(
						'pubkey'   => $pubkey,
						'server'   => get_admin_url(),
						'uniqueId' => $addUniqueId,
					),
					false,
					$verifyCertificate,
					$http_user,
					$http_pass,
					$sslVersion,
					array( 'force_use_ipv4' => $force_use_ipv4 ),
					$output
				);

				$fetch_data = isset( $output['fetch_data'] ) ? $output['fetch_data'] : '';

				if ( isset( $information['error'] ) && '' !== $information['error'] ) {
					$error = MainWP_Utility::esc_content( $information['error'] );
				} else {
					if ( isset( $information['register'] ) && 'OK' === $information['register'] ) {
						$groupids   = array();
						$groupnames = array();
						$tmpArr     = array();
						if ( isset( $params['groupids'] ) && is_array( $params['groupids'] ) ) {
							foreach ( $params['groupids'] as $group ) {
								if ( is_numeric( $group ) ) {
									$groupids[] = $group;
								} else {
									$group = trim( $group );
									if ( ! empty( $group ) ) {
										$tmpArr[] = $group;
									}
								}
							}
							foreach ( $tmpArr as $tmp ) {
								$getgroup = MainWP_DB_Common::instance()->get_group_by_name( trim( $tmp ) );
								if ( $getgroup ) {
									if ( ! in_array( $getgroup->id, $groupids, true ) ) {
										$groupids[] = $getgroup->id;
									}
								} else {
									$groupnames[] = trim( $tmp );
								}
							}
						}

						if ( ( isset( $params['groupnames_import'] ) && '' !== $params['groupnames_import'] ) ) {
							$tmpArr = preg_split( '/[;,]/', $params['groupnames_import'] );
							foreach ( $tmpArr as $tmp ) {
								$group = MainWP_DB_Common::instance()->get_group_by_name( trim( $tmp ) );
								if ( $group ) {
									if ( ! in_array( $group->id, $groupids, true ) ) {
										$groupids[] = $group->id;
									}
								} else {
									$groupnames[] = trim( $tmp );
								}
							}
						}

						if ( ! isset( $information['uniqueId'] ) || empty( $information['uniqueId'] ) ) {
							$addUniqueId = '';
						}

						$http_user = isset( $params['http_user'] ) ? $params['http_user'] : '';
						$http_pass = isset( $params['http_pass'] ) ? $params['http_pass'] : '';

						/**
						 * Current user global.
						 *
						 * @global string
						 */
						global $current_user;

						$id = MainWP_DB::instance()->add_website( $current_user->ID, $params['name'], $params['url'], $params['wpadmin'], base64_encode( $pubkey ), base64_encode( $privkey ), $information['nossl'], ( isset( $information['nosslkey'] ) ? $information['nosslkey'] : null ), $groupids, $groupnames, $verifyCertificate, $addUniqueId, $http_user, $http_pass, $sslVersion ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode() used for http encoding compatible.

						if ( isset( $params['qsw_page'] ) && $params['qsw_page'] ) {
							$message = sprintf( __( '<div class="ui header">Congratulations you have connected %1$s.</div> You can add new sites at anytime from the Add New Site page.', 'mainwp' ), '<strong>' . $params['name'] . '</strong>' );
						} else {
							$message = sprintf( __( 'Site successfully added - Visit the Site\'s %1$sDashboard%2$s now.', 'mainwp' ), '<a href="admin.php?page=managesites&dashboard=' . $id . '" style="text-decoration: none;" title="' . __( 'Dashboard', 'mainwp' ) . '">', '</a>' );
						}
						/**
						 * New site added
						 *
						 * Fires after adding a website to MainWP Dashboard.
						 *
						 * @param int $id Child site ID.
						 *
						 * @since 3.4
						 */
						do_action( 'mainwp_added_new_site', $id );

						$website = MainWP_DB::instance()->get_website_by_id( $id );
						MainWP_Sync::sync_information_array( $website, $information );
					} else {
						$error = __( 'Undefined error occurred. Please try again. For additional help, contact the MainWP Support.', 'mainwp' );
					}
				}
			} catch ( MainWP_Exception $e ) {
				if ( 'HTTPERROR' == $e->getMessage() ) {
					$error = 'HTTP error' . ( null != $e->get_message_extra() ? ' - ' . $e->get_message_extra() : '' );
				} elseif ( 'NOMAINWP' == $e->getMessage() ) {
					$error = __( 'MainWP Child Plugin not detected! Please make sure that the MainWP Child plugin is installed and activated on the child site. For additional help, contact the MainWP Support.', 'mainwp' );
				} else {
					$error = $e->getMessage();
				}
				$fetch_data = $e->get_data();
			}
		}

		return array( $message, $error, $id, $fetch_data );
	}

	/**
	 * Method update_wp_site()
	 *
	 * Update Child Site.
	 *
	 * @param mixed $params Udate parameters.
	 *
	 * @return int Child Site ID on success and return 0 on failure.
	 * @throws \Exception Error message.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 */
	public static function update_wp_site( $params ) {
		if ( ! isset( $params['websiteid'] ) || ! MainWP_Utility::ctype_digit( $params['websiteid'] ) ) {
			return 0;
		}

		if ( isset( $params['is_staging'] ) ) {
			unset( $params['is_staging'] );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $params['websiteid'] );
		if ( null == $website ) {
			return 0;
		}

		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			return 0;
		}

		$data     = array();
		$uniqueId = null;

		if ( isset( $params['name'] ) && ! empty( $params['name'] ) ) {
			$data['name'] = htmlentities( $params['name'] );
		}

		if ( isset( $params['wpadmin'] ) && ! empty( $params['wpadmin'] ) ) {
			$data['adminname'] = $params['wpadmin'];
		}

		if ( isset( $params['unique_id'] ) ) {
			$data['uniqueId'] = $params['unique_id'];
			$uniqueId         = $params['unique_id'];
		}

		if ( empty( $data ) ) {
			return 0;
		}

		MainWP_DB::instance()->update_website_values( $website->id, $data );
		if ( null !== $uniqueId ) {
			try {
				$information = MainWP_Connect::fetch_url_authed( $website, 'update_values', array( 'uniqueId' => $uniqueId ) );
			} catch ( MainWP_Exception $e ) {
				$error = $e->getMessage();
			}
		}

		/**
		 * Action: mainwp_updated_site
		 *
		 * Fires after updatig the child site options.
		 *
		 * @param int   $website->id Child site ID.
		 * @param array $data        Child site data.
		 *
		 * @since 3.5.1
		 */
		do_action( 'mainwp_updated_site', $website->id, $data );
		return $website->id;
	}

}
