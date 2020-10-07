<?php
/**
 * MainWP Extensions View
 *
 * Renders MainWP Extensions Page.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Extensions_View
 *
 * @package MainWP\Dashboard
 */
class MainWP_Extensions_View {
	/**
	 * Method init_menu()
	 *
	 * Add MainWP > Extensions Submenu
	 *
	 * @return $page
	 */
	public static function init_menu() {
		$page = add_submenu_page(
			'mainwp_tab',
			__( 'Extensions', 'mainwp' ),
			' <span id="mainwp-Extensions">' . __( 'Extensions', 'mainwp' ) . '</span>',
			'read',
			'Extensions',
			array(
				MainWP_Extensions::get_class_name(),
				'render',
			)
		);

		return $page;
	}

	/**
	 * Method render_header()
	 *
	 * Render page header.
	 *
	 * @param string $shownPage The page slug shown at this moment.
	 *
	 * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
	 */
	public static function render_header( $shownPage = '' ) {
		if ( isset( $_GET['page'] ) && 'Extensions' === $_GET['page'] ) {
			$params = array(
				'title' => __( 'Extensions', 'mainwp' ),
			);
		} else {
			$extension_name_raw = sanitize_text_field( wp_unslash( $_GET['page'] ) );
			$extension_name     = str_replace( array( 'Extensions', '-', 'Mainwp', 'Extension' ), ' ', $extension_name_raw );
			$params             = array(
				'title' => $extension_name,
			);
		}

		MainWP_UI::render_top_header( $params );

		$renderItems   = array();
		$renderItems[] = array(
			'title'  => __( 'Manage Extensions', 'mainwp' ),
			'href'   => 'admin.php?page=Extensions',
			'active' => ( '' === $shownPage ) ? true : false,
		);

		// get extensions to generate manage site page header.
		$extensions = MainWP_Extensions_Handler::get_extensions();
		foreach ( $extensions as $extension ) {
			if ( $extension['plugin'] == $shownPage ) {
				$renderItems[] = array(
					'title'  => $extension['name'],
					'href'   => 'admin.php?page=' . $extension['page'],
					'active' => true,
				);
				break;
			}
		}
		MainWP_UI::render_page_navigation( $renderItems );
		do_action( 'mainwp_extensions_top_header_after_tab', $shownPage );
	}

	/**
	 * Method render_footer()
	 *
	 * Render page footer.
	 *
	 * @param string $shownPage The page slug shown at this moment.
	 */
	public static function render_footer( $shownPage ) {
		echo '</div>';
	}

	/**
	 * Method render()
	 *
	 * Render the extensions page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager_Password_Management::decrypt_string()
	 */
	public static function render() {

		$username = '';
		$password = '';
		if ( true == get_option( 'mainwp_extensions_api_save_login' ) ) {
			$enscrypt_u = get_option( 'mainwp_extensions_api_username' );
			$enscrypt_p = get_option( 'mainwp_extensions_api_password' );
			$username   = ! empty( $enscrypt_u ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_u ) : '';
			$password   = ! empty( $enscrypt_p ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_p ) : '';
		}

		if ( 1 == get_option( 'mainwp_api_sslVerifyCertificate' ) ) {
			update_option( 'mainwp_api_sslVerifyCertificate', 0 );
		}

		$extensions       = MainWP_Extensions_Handler::get_extensions();
		$extension_update = get_site_transient( 'update_plugins' );
		?>
		<div id="mainwp-manage-extensions" class="ui alt segment">
			<div class="mainwp-main-content">
			<?php self::render_incompatible_notice(); ?>
			<?php if ( 0 == count( $extensions ) ) : ?>
				<?php self::render_intro_notice(); ?>
				<?php
			else :
				self::render_search_box( $extensions );
				?>
				<div class="ui four stackable cards" id="mainwp-extensions-list">
				<?php $available_extensions_data = self::get_available_extensions(); ?>
				<?php if ( isset( $extensions ) && is_array( $extensions ) ) : ?>
						<?php foreach ( $extensions as $extension ) : ?>
							<?php
							if ( ! mainwp_current_user_have_right( 'extension', dirname( $extension['slug'] ) ) ) {
								continue;
							}

							$extensions_data = isset( $available_extensions_data[ dirname( $extension['slug'] ) ] ) ? $available_extensions_data[ dirname( $extension['slug'] ) ] : array();
							$added_on_menu   = MainWP_Extensions_Handler::added_on_menu( $extension['slug'] );

							if ( isset( $extensions_data['img'] ) ) {
								$img_url = $extensions_data['img'];
							} elseif ( isset( $extension['iconURI'] ) && '' !== $extension['iconURI'] ) {
								$img_url = MainWP_Utility::remove_http_prefix( $extension['iconURI'] );
							} else {
								$img_url = MAINWP_PLUGIN_URL . 'assets/images/extensions/placeholder.png';
							}
							// not used feature?.
							if ( $added_on_menu ) {
								$extensino_to_menu_buton = '<button class="button mainwp-extensions-remove-menu">' . __( 'Remove from menu', 'mainwp' ) . '</button>';
							} else {
								$extensino_to_menu_buton = '<button class="button-primary mainwp-extensions-add-menu" >' . __( 'Add to menu', 'mainwp' ) . '</button>';
							}
							self::render_extension_card( $extension, $extension_update, $img_url );
							?>

						<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<?php endif; ?>
			<?php self::render_purchase_notice(); ?>
			</div>
			<div class="mainwp-side-content">
				<?php self::render_side_box( $username, $password ); ?>
			</div>
			<div style="clear:both"></div>
		</div>
		<?php
	}

	/**
	 * Method render_incompatible_notice()
	 *
	 * Render Incompatability Notice.
	 */
	public static function render_incompatible_notice() {
		$deactivated_exts = get_transient( 'mainwp_transient_deactivated_incomtible_exts' );
		if ( $deactivated_exts && is_array( $deactivated_exts ) && 0 < count( $deactivated_exts ) ) :
			?>
			<?php delete_transient( 'mainwp_transient_deactivated_incomtible_exts' ); ?>
			<div class="ui yellow message">
				<div class="header"><?php esc_html_e( 'Important Note', 'mainwp' ); ?></div>
				<p><?php esc_html_e( 'MainWP Dashboard 4.0 or newer requires Extensions 4.0 or newer. MainWP will automatically deactivate older versions of MainWP Extensions in order to prevent compatibility problems.', 'mainwp' ); ?></p>
				<div class="header"><?php esc_html_e( 'Steps to Update Extensions', 'mainwp' ); ?></div>
				<div class="ui list">
					<div class="item">1. <?php esc_html_e( 'Go to the WP Admin > Plugins > Installed Plugins page', 'mainwp' ); ?></div>
					<div class="item">2. <?php esc_html_e( 'Delete Version 3 Extensions (extensions older than version 4) from your MainWP Dashboard', 'mainwp' ); ?></div>
					<div class="item">3. <?php esc_html_e( 'Go back to the MainWP > Extensions page and use the Install Extensions button', 'mainwp' ); ?></div>
				</div>
				<p><?php esc_html_e( 'This process does not affect your extensions settings.', 'mainwp' ); ?></p>
			</div>
			<?php
		endif;
	}

	/**
	 * Method render_intro_notice()
	 *
	 * Render Intro Notice.
	 */
	public static function render_intro_notice() {
		?>
		<div class="ui secondary segment">
			<h2 class="header"><?php esc_html_e( 'What are extensions?', 'mainwp' ); ?></h2>
			<p><?php esc_html_e( 'Extensions are specific features or tools created for the purpose of expanding the basic functionality of MainWP. The core of MainWP has been designed to provide the functions most needed by our users and minimize code bloat. Extensions offer custom functions and features so that each user can tailor their MainWP Dashboard to their specific needs.', 'mainwp' ); ?></p>
			<p><?php esc_html_e( 'MainWP Extensions are composed of PHP scripts that extend the functionality of your MainWP Dashboard. They offer new additions to your Dashboard that either enhance features that were already available or add new features to your Dashboard.', 'mainwp' ); ?></p>
			<p><?php esc_html_e( 'MainWP offers a variety of Free and Premium Extensions in multiple categories which can be purchased separately or through one of the MainWP Membership Plans.', 'mainwp' ); ?></p>
			<a class="ui green button" href="https://mainwp.com/mainwp-extensions/" target="_blank"><?php esc_html_e( 'Browse All MainWP Extensions', 'mainwp' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Method render_search_box()
	 *
	 * Render Search Box.
	 *
	 * @param mixed $extensions Extentions array.
	 */
	public static function render_search_box( $extensions ) {
		?>
		<div class="ui stackable grid">
			<div class="ten wide column"></div>
			<div class="six wide column">
				<div id="mainwp-search-extensions" class="ui fluid search">
					<div class="ui icon fluid input">
						<input class="prompt" type="text" placeholder="Find extension...">
						<i class="search icon"></i>
					</div>
					<div class="results"></div>
				</div>
				<script type="text/javascript">
				jQuery( document ).ready( function () {
				jQuery( '.ui.search' ).search( {
					source: [
						<?php
						if ( isset( $extensions ) && is_array( $extensions ) ) {
							foreach ( $extensions as $extension ) {
								echo "{ title: '" . esc_html( $extension['name'] ) . "', url: '" . admin_url( 'admin.php?page=' . $extension['page'] ) . "' },";
							}
						}
						?>
				]
				} );
				} );
				</script>
			</div>
		</div>
		<?php
	}

	/**
	 * Method render_extension_card()
	 *
	 * Render the MainWP Extension Cards.
	 *
	 * @param mixed $extension Extention to render.
	 * @param mixed $extension_update Extension update.
	 * @param mixed $img_url Extension image.
	 */
	public static function render_extension_card( $extension, $extension_update, $img_url ) {

		if ( isset( $extension['direct_page'] ) && ! empty( $extension['direct_page'] ) ) {
			$extension_page_url = admin_url( 'admin.php?page=' . $extension['direct_page'] );
		} elseif ( isset( $extension['callback'] ) ) {
			$extension_page_url = admin_url( 'admin.php?page=' . $extension['page'] );
		} else {
			$extension_page_url = admin_url( 'admin.php?page=Extensions' );
		}

		$active = MainWP_Extensions_Handler::is_extension_activated( $extension['slug'] );

		if ( isset( $extension['apiManager'] ) && $extension['apiManager'] ) {
			if ( $active ) {
				$extension_api_status = '<a href="javascript:void(0)" class="mainwp-extensions-api-activation api-status mainwp-green"><i class="lock open icon"></i> ' . __( 'Update Notification Enabled', 'mainwp' ) . '</a>';
			} else {
				$extension_api_status = '<a href="javascript:void(0)" class="mainwp-extensions-api-activation api-status mainwp-red"><i class="lock icon"></i> ' . __( 'Add API to Enable Update Notification', 'mainwp' ) . '</a>';
			}
		}

		$queue_status = '';
		if ( isset( $extension['apiManager'] ) && $extension['apiManager'] ) {
			$queue_status = 'status="queue"';
		}
		?>
			<div class="card extension-card-<?php echo esc_attr( $extension['name'] ); ?>" extension-slug="<?php echo esc_attr( $extension['slug'] ); ?>" <?php echo $queue_status; ?> license-status="<?php echo $active ? 'activated' : 'deactivated'; ?>">
				<div class="content">
					<img class="right floated mini ui image" src="<?php echo esc_url( $img_url ); ?>">
					<div class="header">
						<a href="<?php echo esc_url( $extension_page_url ); ?>"><?php echo esc_html( MainWP_Extensions_Handler::polish_ext_name( $extension ) ); ?></a>
					</div>
					<div class="meta">
						<?php echo esc_html__( 'Version ', 'mainwp' ) . $extension['version']; ?> - <?php echo ( isset( $extension['DocumentationURI'] ) && ! empty( $extension['DocumentationURI'] ) ) ? ' <a href="' . str_replace( array( 'http:', 'https:' ), '', $extension['DocumentationURI'] ) . '" target="_blank">' . __( 'Documentation', 'mainwp' ) . '</a>' : ''; ?>
					</div>
					<?php if ( isset( $extension_update->response[ $extension['slug'] ] ) ) : ?>
						<a href="<?php echo admin_url( 'plugins.php' ); ?>" class="ui red ribbon label"><?php esc_html_e( 'Update available', 'mainwp' ); ?></a>
					<?php endif; ?>
					<div class="description">
						<?php echo preg_replace( '/\<cite\>.*\<\/cite\>/', '', $extension['description'] ); ?>
					</div>
				</div>
				<?php if ( isset( $extension['apiManager'] ) && $extension['apiManager'] ) : ?>
				<div class="extra content" id="mainwp-extensions-api-form" style="display: none;">
					<div class="ui form">
						<div class="field">
							<div class="ui input fluid">
								<input type="text" class="extension-api-key" placeholder="<?php esc_attr_e( 'API license key', 'mainwp' ); ?>" value="<?php echo esc_attr( $extension['api_key'] ); ?>"/>
							</div>
						</div>
						<div class="field">
							<div class="ui input fluid">
								<input type="text" class="extension-api-email" placeholder="<?php esc_attr_e( 'API license email', 'mainwp' ); ?>" value="<?php echo esc_attr( $extension['activation_email'] ); ?>"/>
							</div>
						</div>
						<?php if ( $active ) : ?>
						<div class="field">
							<div class="ui checkbox">
								<input type="checkbox" id="extension-deactivate-cb" class="mainwp-extensions-deactivate-chkbox" <?php echo 'on' === $extension['deactivate_checkbox'] ? 'checked' : ''; ?>>
								<label for="extension-deactivate-cb"><?php esc_html_e( 'Deactivate License Key', 'mainwp' ); ?></label>
							</div>
						</div>
						<input type="button" class="ui basic red fluid button mainwp-extensions-deactivate" value="<?php esc_html_e( 'Deactivate License', 'mainwp' ); ?>">
						<?php else : ?>
						<input type="button" class="ui basic green fluid button mainwp-extensions-activate" value="<?php esc_attr_e( 'Activate License', 'mainwp' ); ?>">
						<?php endif; ?>
					</div>
				</div>
					<?php if ( isset( $extension['apiManager'] ) && $extension['apiManager'] ) : ?>
				<div class="extra content api-feedback" style="display:none;">
					<div class="ui mini message"></div>
				</div>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( isset( $extension['apiManager'] ) && $extension['apiManager'] ) : ?>
				<div class="ui middle aligned extra content">
					<span class="activate-api-status"><i class="ui <?php echo ( $active ? 'green' : 'red' ); ?> empty circular label"></i> <?php echo ( $active ? esc_html__( 'License activated', 'mainwp' ) : esc_html__( 'License deactivated', 'mainwp' ) ); ?></span>
					<a class="ui mini right floated button" id="mainwp-manage-extension-license"><?php esc_html_e( 'Manage License', 'mainwp' ); ?></a>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Method render_purchase_notice()
	 *
	 * Render Purchase Notice.
	 */
	public static function render_purchase_notice() {
		?>
		<div id="mainwp-get-purchased-extensions-modal" class="ui modal">
			<div class="header"><?php esc_html_e( 'Install purchased extensions', 'mainwp' ); ?></div>
			<div class="scrolling content"></div>
			<div class="actions">
				<a class="ui basic button" id="mainwp-check-all-ext" href="#"><?php esc_html_e( 'Select all', 'mainwp' ); ?></a>
				<a class="ui basic button" id="mainwp-uncheck-all-ext" href="#"><?php esc_html_e( 'Select none', 'mainwp' ); ?></a>
				<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
				<input type="button" class="ui green button" id="mainwp-extensions-installnow" value="<?php esc_attr_e( 'Install', 'mainwp' ); ?>">
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Sidebar.
	 *
	 * @param mixed $username MainWP.com Username.
	 * @param mixed $password MainWP.com Password.
	 */
	public static function render_side_box( $username, $password ) {
		?>
		<div class="ui header">
			<?php esc_html_e( 'Install and Activate Extensions', 'mainwp' ); ?>
			<div class="sub header"><?php esc_html_e( 'Enter your mainwp.com login to automatically install and activate purchased extensions.', 'mainwp' ); ?></div>
		</div>
		<?php if ( empty( $username ) ) : ?>
		<div class="ui message info">
			<div class="header"><?php esc_html_e( 'Not registered?', 'mainwp' ); ?></div>
			<?php echo sprintf( __( '%1$sCreate MainWP account here.%2$s', 'mainwp' ), '<a href="https://mainwp.com/my-account/" target="_blank">', '</a>' ); ?>
			<div class="ui hidden divider"></div>
			<div class="header"><?php esc_html_e( 'Lost your password?', 'mainwp' ); ?></div>
			<?php echo sprintf( __( '%1$sReset password here.%2$s', 'mainwp' ), '<a href="https://mainwp.com/my-account/lost-password/" target="_blank">', '</a>' ); ?>
		</div>
		<?php endif; ?>
		<div class="ui form" id="mainwp-extensions-api-fields">
			<div class="field">
				<div class="ui input fluid">
					<input type="text" id="mainwp_com_username" placeholder="<?php esc_attr_e( 'Your MainWP Username', 'mainwp' ); ?>" value="<?php echo esc_attr( $username ); ?>"/>
				</div>
			</div>
			<div class="field">
				<div class="ui input fluid">
					<input type="password" id="mainwp_com_password" placeholder="<?php esc_attr_e( 'Your MainWP Password', 'mainwp' ); ?>" value="<?php echo stripslashes( esc_attr( $password ) ); ?>"/>
				</div>
			</div>
			<div class="field">
				<div class="ui checkbox">
					<input type="checkbox" <?php echo ( '' != $username ) ? 'checked="checked"' : ''; ?> name="extensions_api_savemylogin_chk" id="extensions_api_savemylogin_chk">
					<label for="extensions_api_savemylogin_chk"><?php esc_html_e( 'Remember me', 'mainwp' ); ?></label>
				</div>
			</div>
		</div>
		<br/>
		<input type="button" class="ui fluid button" id="mainwp-extensions-savelogin" value="<?php esc_attr_e( 'Verify My Login', 'mainwp' ); ?>">
		<div class="ui divider"></div>
		<input type="button" class="ui fluid basic green button" id="mainwp-extensions-bulkinstall" value="<?php esc_attr_e( 'Install Extensions', 'mainwp' ); ?>">
		<br/>
		<input type="button" class="ui fluid green button" id="mainwp-extensions-grabkeys" value="<?php esc_attr_e( 'Activate Extensions', 'mainwp' ); ?>">
		<div class="ui message mainwp-extensions-api-loading" style="display: none"></div>

		<?php
	}
	/**
	 * Metod get_extension_groups()
	 *
	 * Grab current MainWP Extension Groups.
	 *
	 * @return array $groups
	 */
	public static function get_extension_groups() {
		$groups = array(
			'backup'      => __( 'Backups', 'mainwp' ),
			'content'     => __( 'Content', 'mainwp' ),
			'security'    => __( 'Security', 'mainwp' ),
			'hosting'     => __( 'Hosting', 'mainwp' ),
			'admin'       => __( 'Administrative', 'mainwp' ),
			'performance' => __( 'Performance', 'mainwp' ),
			'visitor'     => __( 'Visitor Data', 'mainwp' ),
		);
		return $groups;
	}

	/**
	 * Method get_available_extensions()
	 *
	 * Static Arrays of all Available Extensions.
	 *
	 * @todo Move to MainWP Server via an XML file.
	 */
	public static function get_available_extensions() {
		$folder_url = MAINWP_PLUGIN_URL . 'assets/images/extensions/';
		return array(
			'advanced-uptime-monitor-extension'      =>
			array(
				'free'       => true,
				'slug'       => 'advanced-uptime-monitor-extension',
				'title'      => 'MainWP Advanced Uptime Monitor',
				'desc'       => 'MainWP Extension for real-time up time monitoring.',
				'link'       => 'https://mainwp.com/extension/advanced-uptime-monitor/',
				'img'        => $folder_url . 'advanced-uptime-monitor.png',
				'product_id' => 'Advanced Uptime Monitor Extension',
				'catalog_id' => '218',
				'group'      => array( 'admin' ),
			),
			'mainwp-article-uploader-extension'      =>
			array(
				'slug'       => 'mainwp-article-uploader-extension',
				'title'      => 'MainWP Article Uploader Extension',
				'desc'       => 'MainWP Article Uploader Extension allows you to bulk upload articles to your dashboard and publish to child sites.',
				'link'       => 'https://mainwp.com/extension/article-uploader/',
				'img'        => $folder_url . 'article-uploader.png',
				'product_id' => 'MainWP Article Uploader Extension',
				'catalog_id' => '15340',
				'group'      => array( 'content' ),
			),
			'mainwp-backwpup-extension'              =>
			array(
				'slug'       => 'mainwp-backwpup-extension',
				'title'      => 'MainWP BackWPup Extension',
				'desc'       => 'MainWP BackWPup Extension combines the power of your MainWP Dashboard with the popular WordPress BackWPup Plugin. It allows you to schedule backups on your child sites.',
				'link'       => 'https://mainwp.com/extension/backwpup/',
				'img'        => $folder_url . 'backwpup.png',
				'product_id' => 'MainWP BackWPup Extension',
				'catalog_id' => '995008',
				'group'      => array( 'backup' ),
			),
			'boilerplate-extension'                  =>
			array(
				'slug'       => 'boilerplate-extension',
				'title'      => 'MainWP Boilerplate Extension',
				'desc'       => 'MainWP Boilerplate extension allows you to create, edit and share repetitive pages across your network of child sites. The available placeholders allow these pages to be customized for each site without needing to be rewritten. The Boilerplate extension is the perfect solution for commonly repeated pages such as your "Privacy Policy", "About Us", "Terms of Use", "Support Policy", or any other page with standard text that needs to be distributed across your network.',
				'link'       => 'https://mainwp.com/extension/boilerplate/',
				'img'        => $folder_url . 'boilerplate.png',
				'product_id' => 'Boilerplate Extension',
				'catalog_id' => '1188',
				'group'      => array( 'content' ),
			),
			'mainwp-branding-extension'              =>
			array(
				'slug'       => 'mainwp-branding-extension',
				'title'      => 'MainWP Branding Extension',
				'desc'       => 'The MainWP Branding extension allows you to alter the details of the MianWP Child Plugin to reflect your companies brand or completely hide the plugin from the installed plugins list.',
				'link'       => 'https://mainwp.com/extension/child-plugin-branding/',
				'img'        => $folder_url . 'branding.png',
				'product_id' => 'MainWP Branding Extension',
				'catalog_id' => '10679',
				'group'      => array( 'admin' ),
			),
			'mainwp-bulk-settings-manager'           =>
			array(
				'slug'       => 'mainwp-bulk-settings-manager',
				'title'      => 'MainWP Bulk Settings Manager',
				'desc'       => 'The Bulk Settings Manager Extension unlocks the world of WordPress directly from your MainWP Dashboard.  With Bulk Settings Manager you can adjust your Child site settings for the WordPress Core and almost any WordPress Plugin or Theme.',
				'link'       => 'https://mainwp.com/extension/bulk-settings-manager/',
				'img'        => $folder_url . 'bulk-settings-manager.png',
				'product_id' => 'MainWP Bulk Settings Manager',
				'catalog_id' => '347704',
				'group'      => array( 'admin' ),
			),
			'mainwp-clean-and-lock-extension'        =>
			array(
				'free'       => true,
				'slug'       => 'mainwp-clean-and-lock-extension',
				'title'      => 'MainWP Clean and Lock Extension',
				'desc'       => 'MainWP Clean and Lock Extension enables you to remove unwanted WordPress pages from your dashboard site and to control access to your dashboard admin area.',
				'link'       => 'https://mainwp.com/extension/clean-lock/',
				'img'        => $folder_url . 'clean-and-lock.png',
				'product_id' => 'MainWP Clean and Lock Extension',
				'catalog_id' => '12907',
				'group'      => array( 'security' ),
			),
			'mainwp-client-reports-extension'        =>
			array(
				'slug'       => 'mainwp-client-reports-extension',
				'title'      => 'MainWP Client Reports Extension',
				'desc'       => 'MainWP Client Reports Extension allows you to generate activity reports for your clients sites. Requires MainWP Dashboard.',
				'link'       => 'https://mainwp.com/extension/client-reports/',
				'img'        => $folder_url . 'client-reports.png',
				'product_id' => 'MainWP Client Reports Extension',
				'catalog_id' => '12139',
				'group'      => array( 'admin' ),
			),
			'mainwp-clone-extension'                 =>
			array(
				'slug'       => 'mainwp-clone-extension',
				'title'      => 'MainWP Clone Extension',
				'desc'       => 'MainWP Clone Extension is an extension for the MainWP plugin that enables you to clone your child sites with no technical knowledge required.',
				'link'       => 'https://mainwp.com/extension/clone/',
				'img'        => $folder_url . 'clone.png',
				'product_id' => 'MainWP Clone Extension',
				'catalog_id' => '1555',
				'group'      => array( 'admin' ),
			),
			'mainwp-code-snippets-extension'         =>
			array(
				'slug'       => 'mainwp-code-snippets-extension',
				'title'      => 'MainWP Code Snippets Extension',
				'desc'       => 'The MainWP Code Snippets Extension is a powerful PHP platform that enables you to execute php code and scripts on your child sites and view the output on your Dashboard. Requires the MainWP Dashboard plugin.',
				'link'       => 'https://mainwp.com/extension/code-snippets/',
				'img'        => $folder_url . 'code-snippets.png',
				'product_id' => 'MainWP Code Snippets Extension',
				'catalog_id' => '11196',
				'group'      => array( 'admin' ),
			),
			'mainwp-comments-extension'              =>
			array(
				'slug'       => 'mainwp-comments-extension',
				'title'      => 'MainWP Comments Extension',
				'desc'       => 'MainWP Comments Extension is an extension for the MainWP plugin that enables you to manage comments on your child sites.',
				'link'       => 'https://mainwp.com/extension/comments/',
				'img'        => $folder_url . 'comments.png',
				'product_id' => 'MainWP Comments Extension',
				'catalog_id' => '1551',
				'group'      => array( 'admin' ),
			),
			'mainwp-custom-dashboard-extension'      =>
			array(
				'slug'       => 'mainwp-custom-dashboard-extension',
				'title'      => 'MainWP Custom Dashboard Extension',
				'desc'       => 'The purpose of this plugin is to contain your customisation snippets for your MainWP Dashboard.',
				'link'       => 'https://mainwp.com/extension/mainwp-custom-dashboard-extension/',
				'img'        => $folder_url . 'custom-dashboard.png',
				'product_id' => 'MainWP Custom Dashboard Extension',
				'catalog_id' => '1080528',
				'group'      => array( 'admin' ),
			),
			'mainwp-favorites-extension'             =>
			array(
				'slug'       => 'mainwp-favorites-extension',
				'title'      => 'MainWP Favorites Extension',
				'desc'       => 'MainWP Favorites is an extension for the MainWP plugin that allows you to store your favorite plugins and themes, and install them directly to child sites from the dashboard repository.',
				'link'       => 'https://mainwp.com/extension/favorites/',
				'img'        => $folder_url . 'favorites.png',
				'product_id' => 'MainWP Favorites Extension',
				'catalog_id' => '1379',
				'group'      => array( 'admin' ),
			),
			'mainwp-file-uploader-extension'         =>
			array(
				'slug'       => 'mainwp-file-uploader-extension',
				'title'      => 'MainWP File Uploader Extension',
				'desc'       => 'MainWP File Uploader Extension gives you an simple way to upload files to your child sites! Requires the MainWP Dashboard plugin.',
				'link'       => 'https://mainwp.com/extension/file-uploader/',
				'img'        => $folder_url . 'file-uploader.png',
				'product_id' => 'MainWP File Uploader Extension',
				'catalog_id' => '11637',
				'group'      => array( 'content' ),
			),
			'mainwp-google-analytics-extension'      =>
			array(
				'slug'       => 'mainwp-google-analytics-extension',
				'title'      => 'MainWP Google Analytics Extension',
				'desc'       => 'MainWP Google Analytics Extension is an extension for the MainWP plugin that enables you to monitor detailed statistics about your child sites traffic. It integrates seamlessly with your Google Analytics account.',
				'link'       => 'https://mainwp.com/extension/google-analytics/',
				'img'        => $folder_url . 'google-analytics.png',
				'product_id' => 'MainWP Google Analytics Extension',
				'catalog_id' => '1554',
				'group'      => array( 'visitor' ),
			),
			'mainwp-maintenance-extension'           =>
			array(
				'slug'       => 'mainwp-maintenance-extension',
				'title'      => 'MainWP Maintenance Extension',
				'desc'       => 'MainWP Maintenance Extension is MainWP Dashboard extension that clears unwanted entries from child sites in your network. You can delete post revisions, delete auto draft pots, delete trash posts, delete spam, pending and trash comments, delete unused tags and categories and optimize database tables on selected child sites.',
				'link'       => 'https://mainwp.com/extension/maintenance/',
				'img'        => $folder_url . 'maintenance.png',
				'product_id' => 'MainWP Maintenance Extension',
				'catalog_id' => '1141',
				'group'      => array( 'admin' ),
			),
			'mainwp-piwik-extension'                 =>
			array(
				'slug'       => 'mainwp-piwik-extension',
				'title'      => 'MainWP Piwik Extension',
				'desc'       => 'MainWP Piwik Extension is an extension for the MainWP plugin that enables you to monitor detailed statistics about your child sites traffic. It integrates seamlessly with your Piwik account.',
				'link'       => 'https://mainwp.com/extension/piwik/',
				'img'        => $folder_url . 'piwik.png',
				'product_id' => 'MainWP Piwik Extension',
				'catalog_id' => '10523',
				'group'      => array( 'visitor' ),
			),
			'mainwp-post-dripper-extension'          =>
			array(
				'slug'       => 'mainwp-post-dripper-extension',
				'title'      => 'MainWP Post Dripper Extension',
				'desc'       => 'MainWP Post Dripper Extension allows you to deliver posts or pages to your network of sites over a pre-scheduled period of time. Requires MainWP Dashboard plugin.',
				'link'       => 'https://mainwp.com/extension/post-dripper/',
				'img'        => $folder_url . 'post-dripper.png',
				'product_id' => 'MainWP Post Dripper Extension',
				'catalog_id' => '11756',
				'group'      => array( 'content' ),
			),
			'mainwp-rocket-extension'                =>
			array(
				'slug'       => 'mainwp-rocket-extension',
				'title'      => 'MainWP Rocket Extension',
				'desc'       => 'MainWP Rocket Extension combines the power of your MainWP Dashboard with the popular WP Rocket Plugin. It allows you to mange WP Rocket settings and quickly Clear and Preload cache on your child sites.',
				'link'       => 'https://mainwp.com/extension/rocket/',
				'img'        => $folder_url . 'rocket.png',
				'product_id' => 'MainWP Rocket Extension',
				'catalog_id' => '335257',
				'group'      => array( 'performance' ),
			),
			'mainwp-sucuri-extension'                =>
			array(
				'free'       => true,
				'slug'       => 'mainwp-sucuri-extension',
				'title'      => 'MainWP Sucuri Extension',
				'desc'       => 'MainWP Sucuri Extension enables you to scan your child sites for various types of malware, spam injections, website errors, and much more. Requires the MainWP Dashboard.',
				'link'       => 'https://mainwp.com/extension/sucuri/',
				'img'        => $folder_url . 'sucuri.png',
				'product_id' => 'MainWP Sucuri Extension',
				'catalog_id' => '10777',
				'group'      => array( 'security' ),
			),
			'mainwp-team-control'                    =>
			array(
				'slug'       => 'mainwp-team-control',
				'title'      => 'MainWP Team Control',
				'desc'       => 'MainWP Team Control extension allows you to create a custom roles for your dashboard site users and limiting their access to MainWP features. Requires MainWP Dashboard plugin.',
				'link'       => 'https://mainwp.com/extension/team-control/',
				'img'        => $folder_url . 'team-control.png',
				'product_id' => 'MainWP Team Control',
				'catalog_id' => '23936',
				'group'      => array( 'admin' ),
			),
			'mainwp-updraftplus-extension'           =>
			array(
				'free'       => true,
				'slug'       => 'mainwp-updraftplus-extension',
				'title'      => 'MainWP UpdraftPlus Extension',
				'desc'       => 'MainWP UpdraftPlus Extension combines the power of your MainWP Dashboard with the popular WordPress UpdraftPlus Plugin. It allows you to quickly back up your child sites.',
				'link'       => 'https://mainwp.com/extension/updraftplus/',
				'img'        => $folder_url . 'updraftplus.png',
				'product_id' => 'MainWP UpdraftPlus Extension',
				'catalog_id' => '165843',
				'group'      => array( 'backup' ),
			),
			'mainwp-url-extractor-extension'         =>
			array(
				'slug'       => 'mainwp-url-extractor-extension',
				'title'      => 'MainWP URL Extractor Extension',
				'desc'       => 'MainWP URL Extractor allows you to search your child sites post and pages and export URLs in customized format. Requires MainWP Dashboard plugin.',
				'link'       => 'https://mainwp.com/extension/url-extractor/',
				'img'        => $folder_url . 'url-extractor.png',
				'product_id' => 'MainWP Url Extractor Extension',
				'catalog_id' => '11965',
				'group'      => array( 'admin' ),
			),
			'mainwp-woocommerce-shortcuts-extension' =>
			array(
				'free'       => true,
				'slug'       => 'mainwp-woocommerce-shortcuts-extension',
				'title'      => 'MainWP WooCommerce Shortcuts Extension',
				'desc'       => 'MainWP WooCommerce Shortcuts provides you a quick access WooCommerce pages in your network. Requires MainWP Dashboard plugin.',
				'link'       => 'https://mainwp.com/extension/woocommerce-shortcuts/',
				'img'        => $folder_url . 'woo-shortcuts.png',
				'product_id' => 'MainWP WooCommerce Shortcuts Extension',
				'catalog_id' => '12706',
				'group'      => array( 'admin' ),
			),
			'mainwp-woocommerce-status-extension'    =>
			array(
				'slug'       => 'mainwp-woocommerce-status-extension',
				'title'      => 'MainWP WooCommerce Status Extension',
				'desc'       => 'MainWP WooCommerce Status provides you a quick overview of your WooCommerce stores in your network. Requires MainWP Dashboard plugin.',
				'link'       => 'https://mainwp.com/extension/woocommerce-status/',
				'img'        => $folder_url . 'woo-status.png',
				'product_id' => 'MainWP WooCommerce Status Extension',
				'catalog_id' => '12671',
				'group'      => array( 'admin' ),
			),
			'mainwp-wordfence-extension'             =>
			array(
				'slug'       => 'mainwp-wordfence-extension',
				'title'      => 'MainWP WordFence Extension',
				'desc'       => 'The WordFence Extension combines the power of your MainWP Dashboard with the popular WordPress Wordfence Plugin. It allows you to manage WordFence settings, Monitor Live Traffic and Scan your child sites directly from your dashboard. Requires MainWP Dashboard plugin.',
				'link'       => 'https://mainwp.com/extension/wordfence/',
				'img'        => $folder_url . 'wordfence.png',
				'product_id' => 'MainWP Wordfence Extension',
				'catalog_id' => '19678',
				'group'      => array( 'security' ),
			),
			'wordpress-seo-extension'                =>
			array(
				'slug'       => 'wordpress-seo-extension',
				'title'      => 'MainWP WordPress SEO Extension',
				'desc'       => 'MainWP WordPress SEO extension by MainWP enables you to manage all your WordPress SEO by Yoast plugins across your network. Create and quickly set settings templates from one central dashboard. Requires MainWP Dashboard plugin.',
				'link'       => 'https://mainwp.com/extension/wordpress-seo/',
				'img'        => $folder_url . 'wordpress-seo.png',
				'product_id' => 'MainWP Wordpress SEO Extension',
				'catalog_id' => '12080',
				'group'      => array( 'content' ),
			),
			'mainwp-page-speed-extension'            =>
			array(
				'slug'       => 'mainwp-page-speed-extension',
				'title'      => 'MainWP Page Speed Extension',
				'desc'       => 'MainWP Page Speed Extension enables you to use Google Page Speed insights to monitor website performance across your network. Requires MainWP Dashboard plugin.',
				'link'       => 'https://mainwp.com/extension/page-speed/',
				'img'        => $folder_url . 'page-speed.png',
				'product_id' => 'MainWP Page Speed Extension',
				'catalog_id' => '12581',
				'group'      => array( 'performance' ),
			),
			'mainwp-ithemes-security-extension'      =>
			array(
				'slug'       => 'mainwp-ithemes-security-extension',
				'title'      => 'MainWP iThemes Security Extension',
				'desc'       => 'The iThemes Security Extension combines the power of your MainWP Dashboard with the popular iThemes Security Plugin. It allows you to manage iThemes Security plugin settings directly from your dashboard. Requires MainWP Dashboard plugin.',
				'link'       => 'https://mainwp.com/extension/ithemes-security/',
				'img'        => $folder_url . 'ithemes.png',
				'product_id' => 'MainWP Security Extension',
				'catalog_id' => '113355',
				'group'      => array( 'security' ),
			),
			'mainwp-post-plus-extension'             =>
			array(
				'slug'       => 'mainwp-post-plus-extension',
				'title'      => 'MainWP Post Plus Extension',
				'desc'       => 'Enhance your MainWP publishing experience. The MainWP Post Plus Extension allows you to save work in progress as Post and Page drafts. That is not all, it allows you to use random authors, dates and categories for your posts and pages. Requires the MainWP Dashboard plugin.',
				'link'       => 'https://mainwp.com/extension/post-plus/',
				'img'        => $folder_url . 'post-plus.png',
				'product_id' => 'MainWP Post Plus Extension',
				'catalog_id' => '12458',
				'group'      => array( 'admin' ),
			),
			'mainwp-staging-extension'               =>
			array(
				'slug'       => 'mainwp-staging-extension',
				'title'      => 'MainWP Staging Extension',
				'desc'       => 'MainWP Staging Extension along with the WP Staging plugin, allows you to create and manage staging sites for your child sites.',
				'link'       => 'https://mainwp.com/extension/staging/',
				'img'        => $folder_url . 'staging.png',
				'product_id' => 'MainWP Staging Extension',
				'catalog_id' => '1034878',
				'group'      => array( 'admin' ),
			),
			'mainwp-custom-post-types'               =>
			array(
				'slug'       => 'mainwp-custom-post-types',
				'title'      => 'MainWP Custom Post Type',
				'desc'       => 'Custom Post Types Extension is an extension for the MainWP Plugin that allows you to manage almost any custom post type on your child sites and that includes Publishing, Editing, and Deleting custom post type content.',
				'link'       => 'https://mainwp.com/extension/custom-post-types/',
				'img'        => $folder_url . 'custom-post.png',
				'product_id' => 'MainWP Custom Post Types',
				'catalog_id' => '1002564',
				'group'      => array( 'content' ),
			),
			'mainwp-buddy-extension'                 =>
			array(
				'slug'       => 'mainwp-buddy-extension',
				'title'      => 'MainWP Buddy Extension',
				'desc'       => 'With the MainWP Buddy Extension, you can control the BackupBuddy Plugin settings for all your child sites directly from your MainWP Dashboard. This includes giving you the ability to create your child site backups and even set Backup schedules directly from your MainWP Dashboard.',
				'link'       => 'https://mainwp.com/extension/mainwpbuddy/',
				'img'        => $folder_url . 'mainwp-buddy.png',
				'product_id' => 'MainWP Buddy Extension',
				'catalog_id' => '1006044',
				'group'      => array( 'backup' ),
			),
			'mainwp-vulnerability-checker-extension' =>
			array(
				'slug'       => 'mainwp-vulnerability-checker-extension',
				'title'      => 'MainWP Vulnerability Checker Extension',
				'desc'       => 'MainWP Vulnerability Checker extension uses WPScan Vulnerability Database API to bring you information about vulnerable plugins on your Child Sites so you can act accordingly.',
				'link'       => 'https://mainwp.com/extension/vulnerability-checker/',
				'img'        => $folder_url . 'vulnerability-checker.png',
				'product_id' => 'MainWP Vulnerability Checker Extension',
				'catalog_id' => '12458',
				'group'      => array( 'security' ),
			),
			'mainwp-time-capsule-extension'          =>
			array(
				'slug'       => 'mainwp-time-capsule-extension',
				'title'      => 'MainWP Time Capsule Extension',
				'desc'       => 'With the MainWP Time Capsule Extension, you can control the WP Time Capsule Plugin on all your child sites directly from your MainWP Dashboard. This includes the ability to create your child site backups and even restore your child sites to a point back in time directly from your dashboard.',
				'link'       => 'https://mainwp.com/extension/vulnerability-checker/',
				'img'        => $folder_url . 'time-capsule.png',
				'product_id' => 'MainWP Time Capsule Extension',
				'catalog_id' => '1049003',
				'group'      => array( 'backup' ),
			),
			'mainwp-wp-compress-extension'           =>
			array(
				'slug'       => 'mainwp-wp-compress-extension',
				'title'      => 'MainWP WP Compress Extension',
				'desc'       => 'MainWP has partnered with WP Compress to bring you an automatic image optimization for all your MainWP Child sites at an exclusive discount of 30% off their normal prices.',
				'link'       => 'https://mainwp.com/extension/wp-compress/',
				'img'        => $folder_url . 'wp-compress.png',
				'product_id' => 'MainWP WP Compress Extension',
				'catalog_id' => '1053988',
				'group'      => array( 'security' ),
			),
			'mainwp-pro-reports-extension'           =>
			array(
				'slug'       => 'mainwp-pro-reports-extension',
				'title'      => 'MainWP Pro Reports Extension',
				'desc'       => 'The MainWP Pro Reports extension is a fully customizable reporting engine that allows you to create the type of report you are proud to send to your clients.',
				'link'       => 'https://mainwp.com/extension/pro-reports/',
				'img'        => $folder_url . 'pro-reports.png',
				'product_id' => 'MainWP Pro Reports Extension',
				'catalog_id' => '1133708',
				'group'      => array( 'admin' ),
			),
		);
	}

}
