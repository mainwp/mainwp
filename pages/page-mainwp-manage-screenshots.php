<?php
/**
 * MainWP Manage Screenshots.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Manage_Screenshots
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Screenshots {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * MainWP_Manage_Screenshots constructor.
	 *
	 * Run each time the class is called.
	 * Add action to generate tabletop.
	 */
	public function __construct() {
		add_action( 'mainwp_managesites_tabletop', array( &$this, 'generate_tabletop' ) );
	}

	/**
	 * Method generate_tabletop()
	 *
	 * Run the render_manage_sites_table_top menthod.
	 */
	public function generate_tabletop() {
		$this->render_manage_sites_table_top();
	}

	/**
	 * Render manage sites table top.
	 */
	public function render_manage_sites_table_top() {
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$selected_status = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : '';
		$selected_group  = isset( $_REQUEST['g'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['g'] ) ) : '';
		$selected_client = isset( $_REQUEST['client'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ) : '';
		$is_not          = isset( $_REQUEST['isnot'] ) && ( 'yes' === $_REQUEST['isnot'] ) ? true : false;

		if ( ! isset( $_REQUEST['g'] ) ) {
			$selected_status = get_user_option( 'mainwp_screenshots_filter_status', '' );
			$selected_group  = get_user_option( 'mainwp_screenshots_filter_group', '' );
			$selected_client = get_user_option( 'mainwp_screenshots_filter_client', '' );
			$is_not          = get_user_option( 'mainwp_screenshots_filter_is_not', '' );
		}
		// phpcs:enable

		?>
		<div class="ui stackable three column grid">

			<div class="row ui mini form">
				<div class="middle aligned column">
					<input type="text" id="mainwp-screenshots-sites-filter" value="" placeholder="<?php esc_attr_e( 'Type to filter your sites', 'mainwp' ); ?>">
				</div>
				<div class="middle aligned column"></div>
				<div class="right aligned middle aligned column">
					<?php MainWP_Manage_Sites_List_Table::render_page_navigation_left_items(); ?>
				</div>
			</div>

			<div class="row ui mini form" id="mainwp-sites-filters-row" style="display:none">
				<div class="sixteen wide left aligned middle aligned column">
				<?php esc_html_e( 'Filter sites: ', 'mainwp' ); ?>
					<div class="ui selection dropdown" id="mainwp_is_not_site">
							<input type="hidden" value="<?php echo $is_not ? 'yes' : ''; ?>">
							<i class="dropdown icon"></i>
							<div class="default text"><?php esc_html_e( 'Is', 'mainwp' ); ?></div>
							<div class="menu">
								<div class="item" data-value=""><?php esc_html_e( 'Is', 'mainwp' ); ?></div>
								<div class="item" data-value="yes"><?php esc_html_e( 'Is not', 'mainwp' ); ?></div>
							</div>
						</div>
						<div id="mainwp-filter-sites-group" class="ui multiple selection dropdown">
							<input type="hidden" value="<?php echo esc_html( $selected_group ); ?>">
							<i class="dropdown icon"></i>
							<div class="default text"><?php esc_html_e( 'All tags', 'mainwp' ); ?></div>
							<div class="menu">
								<?php
								$groups = MainWP_DB_Common::instance()->get_groups_for_manage_sites();
								foreach ( $groups as $group ) {
									?>
									<div class="item" data-value="<?php echo intval( $group->id ); ?>"><?php echo esc_html( stripslashes( $group->name ) ); ?></div>
									<?php
								}
								?>
								<div class="item" data-value="nogroups"><?php esc_html_e( 'No Tags', 'mainwp' ); ?></div>
							</div>
						</div>
						<div class="ui selection dropdown" id="mainwp-filter-sites-status">
							<input type="hidden" value="<?php echo esc_html( $selected_status ); ?>">
							<div class="default text"><?php esc_html_e( 'All statuses', 'mainwp' ); ?></div>
							<i class="dropdown icon"></i>
							<div class="menu">
								<div class="item" data-value="all" ><?php esc_html_e( 'All statuses', 'mainwp' ); ?></div>
								<div class="item" data-value="connected"><?php esc_html_e( 'Connected', 'mainwp' ); ?></div>
								<div class="item" data-value="disconnected"><?php esc_html_e( 'Disconnected', 'mainwp' ); ?></div>
								<div class="item" data-value="update"><?php esc_html_e( 'Available update', 'mainwp' ); ?></div>
								<div class="item" data-value="sitehealthnotgood"><?php esc_html_e( 'Site Health Not Good', 'mainwp' ); ?></div>
								<div class="item" data-value="phpver7"><?php esc_html_e( 'PHP Ver < 7.0', 'mainwp' ); ?></div>
								<div class="item" data-value="suspended"><?php esc_html_e( 'Suspended', 'mainwp' ); ?></div>
							</div>
						</div>
						<div id="mainwp-filter-clients" class="ui selection multiple dropdown">
							<input type="hidden" value="<?php echo esc_html( $selected_client ); ?>">
							<i class="dropdown icon"></i>
							<div class="default text"><?php esc_html_e( 'All clients', 'mainwp' ); ?></div>
							<div class="menu">
								<?php
								$clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all' );
								foreach ( $clients as $client ) {
									?>
									<div class="item" data-value="<?php echo intval( $client->client_id ); ?>"><?php echo esc_html( stripslashes( $client->name ) ); ?></div>
									<?php
								}
								?>
								<div class="item" data-value="noclients"><?php esc_html_e( 'No Client', 'mainwp' ); ?></div>
							</div>
						</div>
						<button onclick="mainwp_screenshots_sites_filter()" class="ui tiny basic button"><?php esc_html_e( 'Filter Sites', 'mainwp' ); ?></button>
				</div>
				</div>
			
		</div>
		<script type="text/javascript">
				mainwp_screenshots_sites_filter = function() {
						var group = jQuery( "#mainwp-filter-sites-group" ).dropdown( "get value" );
						var status = jQuery( "#mainwp-filter-sites-status" ).dropdown( "get value" );
						var isNot = jQuery("#mainwp_is_not_site").dropdown("get value");
						var client = jQuery("#mainwp-filter-clients").dropdown("get value");
						var params = '';						
						params += '&g=' + group;						
						params += '&client=' + client;						
						if ( status != '' ) {
							params += '&status=' + status;
						}
						if ( 'yes' == isNot ){
							params += '&isnot=yes';
						}
						console.log(params);
						window.location = 'admin.php?page=managesites' + params;
						return false;
				};	


				jQuery( document ).on( 'keyup', '#mainwp-screenshots-sites-filter', function () {
					var filter = jQuery(this).val().toLowerCase();
					var siteItems =  jQuery('#mainwp-sites-previews').find( '.card' );
					for ( var i = 0; i < siteItems.length; i++ ) {
						var currentElement = jQuery( siteItems[i] );
						var valueurl = jQuery(currentElement).attr('site-url').toLowerCase();
						var valuename = currentElement.find('.ui.header').text().toLowerCase();
						if ( valueurl.indexOf( filter ) > -1 || valuename.indexOf( filter ) > -1 ) {
							currentElement.show();
						} else {
							currentElement.hide();
						}
					}
				} );

				jQuery('#mainwp-sites-previews .image img').visibility({
					type       : 'image',
					transition : 'fade in',
					duration   : 1000
				});

		</script>
		<?php
	}

	/**
	 * Method render_all_sites()
	 *
	 * Render Screenshots.
	 */
	public static function render_all_sites() { // phpcs:ignore -- comlex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		/**
		 * Sites Page header
		 *
		 * Renders the tabs on the Sites screen.
		 *
		 * @since Unknown
		 */
		do_action( 'mainwp_pageheader_sites', 'managesites' );

		$websites = self::prepare_items();

		MainWP_DB::data_seek( $websites, 0 );

		$userExtension = MainWP_DB_Common::instance()->get_user_extension();

		?>

		<div id="mainwp-screenshots-sites" class="ui segment">
			<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-grid-view-mode-info-message' ) ) : ?>
			<div class="ui info message">
				<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-grid-view-mode-info-message"></i>
				<div><?php echo esc_html__( 'In the Grid mode, sites options are limited in comparison to the Table mode.', 'mainwp' ); ?></div>
		</div>
			<?php endif; ?>
		<?php
		/**
		 * Filter: mainwp_cards_per_row
		 *
		 * Filters the number of cards per row in MainWP Screenshots page.
		 *
		 * @since 4.1.8
		 */
		$cards_per_row = apply_filters( 'mainwp_cards_per_row', 'five' );
		?>
		<div id="mainwp-sites-previews">
			<div id="mainwp-message-zone" class="ui message" style="display:none;"></div>
				<div class="ui <?php echo esc_attr( $cards_per_row ); ?> cards" >
					<?php
					while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
						$hasSyncErrors = ( '' !== $website->sync_errors );
						$suspendedSite = ( '0' !== $website->suspended );

						$status_color   = '';
						$status_icon    = '';
						$status_tooltip = '';

						if ( $hasSyncErrors ) {
							$status_color   = 'red';
							$status_icon    = 'unlink';
							$status_tooltip = esc_html__( 'Disconnected', 'mainwp' );
						} elseif ( $suspendedSite ) {
							$status_color   = 'yellow';
							$status_icon    = 'pause';
							$status_tooltip = esc_html__( 'Suspended', 'mainwp' );
						} else {
							$status_color   = 'green';
							$status_icon    = 'check';
							$status_tooltip = esc_html__( 'Connected', 'mainwp' );
						}

						$total_wp_upgrades     = 0;
						$total_plugin_upgrades = 0;
						$total_theme_upgrades  = 0;

						$site_options = MainWP_DB::instance()->get_website_options_array( $website, array( 'wp_upgrades', 'premium_upgrades' ) );
						$wp_upgrades  = isset( $site_options['wp_upgrades'] ) ? json_decode( $site_options['wp_upgrades'], true ) : array();

						if ( $website->is_ignoreCoreUpdates ) {
							$wp_upgrades = array();
						}

						if ( is_array( $wp_upgrades ) && 0 < count( $wp_upgrades ) ) {
							++$total_wp_upgrades;
						}

						$plugin_upgrades = json_decode( $website->plugin_upgrades, true );

						if ( $website->is_ignorePluginUpdates ) {
							$plugin_upgrades = array();
						}

						$theme_upgrades = json_decode( $website->theme_upgrades, true );

						if ( $website->is_ignoreThemeUpdates ) {
							$theme_upgrades = array();
						}

						$decodedPremiumUpgrades = isset( $site_options['premium_upgrades'] ) ? json_decode( $site_options['premium_upgrades'], true ) : array();

						if ( is_array( $decodedPremiumUpgrades ) ) {
							foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
								$premiumUpgrade['premium'] = true;

								if ( 'plugin' === $premiumUpgrade['type'] ) {
									if ( ! is_array( $plugin_upgrades ) ) {
										$plugin_upgrades = array();
									}
									if ( ! $website->is_ignorePluginUpdates ) {
										$plugin_upgrades[ $crrSlug ] = $premiumUpgrade;
									}
								} elseif ( 'theme' === $premiumUpgrade['type'] ) {
									if ( ! is_array( $theme_upgrades ) ) {
										$theme_upgrades = array();
									}
									if ( ! $website->is_ignoreThemeUpdates ) {
										$theme_upgrades[ $crrSlug ] = $premiumUpgrade;
									}
								}
							}
						}

						if ( is_array( $plugin_upgrades ) ) {

							$ignored_plugins = json_decode( $website->ignored_plugins, true );
							if ( is_array( $ignored_plugins ) ) {
								$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
							}

							$ignored_plugins = json_decode( $userExtension->ignored_plugins, true );
							if ( is_array( $ignored_plugins ) ) {
								$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
							}

							$total_plugin_upgrades += count( $plugin_upgrades );
						}

						if ( is_array( $theme_upgrades ) ) {

							$ignored_themes = json_decode( $website->ignored_themes, true );
							if ( is_array( $ignored_themes ) ) {
								$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
							}

							$ignored_themes = json_decode( $userExtension->ignored_themes, true );
							if ( is_array( $ignored_themes ) ) {
								$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
							}

							$total_theme_upgrades += count( $theme_upgrades );
						}

						$total_updates = $total_wp_upgrades + $total_plugin_upgrades + $total_theme_upgrades;

						if ( 5 < $total_updates ) {
							$a_color = 'red';
						} elseif ( 0 < $total_updates && 5 >= $total_updates ) {
							$a_color = 'yellow';
						} else {
							$a_color = 'green';
						}

						if ( 5 < $total_wp_upgrades ) {
							$w_color = 'red';
						} elseif ( 0 < $total_wp_upgrades && 5 >= $total_wp_upgrades ) {
							$w_color = 'yellow';
						} else {
							$w_color = 'green';
						}

						if ( 5 < $total_plugin_upgrades ) {
							$p_color = 'red';
						} elseif ( 0 < $total_plugin_upgrades && 5 >= $total_plugin_upgrades ) {
							$p_color = 'yellow';
						} else {
							$p_color = 'green';
						}

						if ( 5 < $total_theme_upgrades ) {
							$t_color = 'red';
						} elseif ( 0 < $total_theme_upgrades && 5 >= $total_theme_upgrades ) {
							$t_color = 'yellow';
						} else {
							$t_color = 'green';
						}

						?>

					<div class="card" site-url="<?php echo esc_url( $website->url ); ?>">
							<div class="image" data-tooltip="<?php echo esc_attr( $status_tooltip ); ?>" data-position="top center" data-inverted="">
							<img data-src="//s0.wordpress.com/mshots/v1/<?php echo esc_html( rawurlencode( $website->url ) ); ?>?w=900">
						</div>
							<div class="ui <?php echo esc_attr( $status_color ); ?> corner label">
								<i class="<?php echo esc_attr( $status_icon ); ?> icon"></i>
							</div>
						<div class="content">
								<h5 class="ui small header">
									<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo intval( $website->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to WP Admin', 'mainwp' ); ?>" data-position="top left" data-inverted=""><i class="sign in icon"></i></a> <a href="admin.php?page=managesites&dashboard=<?php echo intval( $website->id ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a>
									<div class="sub header" style="font-size:11px"><a href="<?php echo esc_url( $website->url ); ?>" target="_blank"><?php echo esc_url( $website->url ); ?></a></div>
								</h5>
								<?php if ( isset( $website->wpgroups ) && '' !== $website->wpgroups ) : ?>
								<small data-tooltip="<?php esc_attr_e( 'Site tags', 'mainwp' ); ?>" data-position="top left" data-inverted="">
									<?php echo MainWP_System_Utility::get_site_tags( (array) $website ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</small>
								<?php endif; ?>
						</div>
							<?php if ( isset( $website->client_id ) && ! empty( $website->client_id ) ) : ?>
							<div class="extra content">
								<small data-tooltip="<?php esc_attr_e( 'See client details', 'mainwp' ); ?>" data-position="top left" data-inverted=""><i class="user icon"></i> <a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" class="ui small"><?php echo esc_html( stripslashes( $website->client_name ) ); ?></a></small>
							</div>
							<?php endif; ?>
							<div class="extra content">
								<div class="ui mini fluid buttons">
									<a  data-tooltip="<?php esc_attr_e( 'Available updates.', 'mainwp' ); ?>" data-position="top left" data-inverted="" href="admin.php?page=managesites&updateid=<?php echo intval( $website->id ); ?>" class="ui icon button"><i class="redo <?php esc_attr_e( $a_color ); ?> alternate icon"></i> <?php echo intval( $total_updates ); ?></a>
									<a  data-tooltip="<?php esc_attr_e( 'Available plugins updates.', 'mainwp' ); ?>" data-position="top center" data-inverted="" href="admin.php?page=managesites&updateid=<?php echo intval( $website->id ); ?>&tab=plugins-updates" class="ui icon button"><i class="plug <?php echo esc_attr( $p_color ); ?> icon"></i> <?php echo intval( $total_plugin_upgrades ); ?></a>
									<a  data-tooltip="<?php esc_attr_e( 'Available themes updates.', 'mainwp' ); ?>" data-position="top center" data-inverted="" href="admin.php?page=managesites&updateid=<?php echo intval( $website->id ); ?>&tab=themes-updates" class="ui icon button"><i class="brush <?php echo esc_attr( $t_color ); ?> icon"></i> <?php echo intval( $total_theme_upgrades ); ?></a>
									<a  data-tooltip="<?php esc_attr_e( 'WordPress core updates.', 'mainwp' ); ?>" data-position="top right" data-inverted="" href="admin.php?page=managesites&updateid=<?php echo intval( $website->id ); ?>&tab=wordpress-updates" class="ui icon button"><i class="WordPress <?php echo esc_attr( $w_color ); ?> icon"></i> <?php echo intval( $total_wp_upgrades ); ?></a>
						</div>
						</div>
						<div class="extra content">
							<?php if ( $hasSyncErrors ) : ?>
								<a class="ui mini green basic icon button fluid mainwp_site_card_reconnect" site-id="<?php echo intval( $website->id ); ?>" href="#"><i class="sync alternate icon"></i> <?php esc_html_e( 'Reconnect', 'mainwp' ); ?></a>
							<?php else : ?>
							<div data-tooltip="<?php esc_html_e( 'Last Sync: ', 'mainwp' ); ?> <?php echo 0 !== $website->dtsSync ? esc_html( MainWP_Utility::format_timestamp( MainWP_Utility::format_timestamp( $website->dtsSync ) ) ) : ''; ?>" data-inverted="" data-position="bottom center">
								<a href="javascript:void(0)" class="ui mini green icon button fluid mainwp-sync-this-site" site-id="<?php echo intval( $website->id ); ?>"><i class="sync alternate icon"></i> <?php esc_html_e( 'Sync Site ', 'mainwp' ); ?></a>
							</div>
							<?php endif; ?>
						</div>
					</div>
						<?php
					}
					?>
				</div>
			</div>
		</div>
		<script type="text/javascript">
			jQuery('#mainwp-sites-previews .image img').visibility( {
				type       : 'image',
				transition : 'fade in',
				duration   : 1000
			});

			mainwp_manage_sites_screen_options = function () {
				jQuery( '#mainwp-manage-sites-screen-options-modal' ).modal( {
					allowMultiple: true,
					onHide: function () {
						//ok.
					}
				} ).modal( 'show' );

				jQuery( '#manage-sites-screen-options-form' ).submit( function() {
					jQuery( '#mainwp-manage-sites-screen-options-modal' ).modal( 'hide' );
				} );
				return false;
			};

		</script>
		<?php
		MainWP_DB::free_result( $websites );
		self::render_screen_options();
		/**
		 * Sites Page Footer
		 *
		 * Renders the footer on the Sites screen.
		 *
		 * @since Unknown
		 */
		do_action( 'mainwp_pagefooter_sites', 'managesites' );
	}

	/**
	 * Method render_screen_options()
	 *
	 * Render Page Settings Modal.
	 */
	public static function render_screen_options() {
		$siteViewMode = MainWP_Utility::get_siteview_mode();
		$is_demo      = MainWP_Demo_Handle::is_demo_mode();
		?>
		<div class="ui modal" id="mainwp-manage-sites-screen-options-modal">
			<i class="close icon"></i>
			<div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
			<div class="scrolling content ui form">
				<form method="POST" action="" id="manage-sites-screen-options-form" name="manage_sites_screen_options_form">
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
					<input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'SreenshotsSitesScrOptions' ) ); ?>" />
					<div class="ui grid field">
						<label class="top aligned six wide column" tabindex="0"><?php esc_html_e( 'Sites view mode', 'mainwp' ); ?></label>
						<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Sites view mode.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
							<div class="ui info message">
								<div><strong><?php echo esc_html__( 'Sites view mode is an experimental feature.', 'mainwp' ); ?></strong></div>
								<div><?php echo esc_html__( 'In the Grid mode, sites options are limited in comparison to the Table mode.', 'mainwp' ); ?></div>
								<div><?php echo esc_html__( 'Grid mode queries WordPress.com servers to capture a screenshot of your site the same way comments show you a preview of URLs.', 'mainwp' ); ?></div>
							</div>
							<select name="mainwp_sitesviewmode" id="mainwp_sitesviewmode" class="ui dropdown">
								<option value="table" <?php echo ( 'table' === $siteViewMode ? 'selected' : '' ); ?>><?php esc_html_e( 'Table', 'mainwp' ); ?></option>
								<option value="grid" <?php echo ( 'grid' === $siteViewMode ? 'selected' : '' ); ?>><?php esc_html_e( 'Grid', 'mainwp' ); ?></option>
							</select>
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Export child sites to CSV file', 'mainwp' ); ?></label>
						<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Click this button to export all connected sites to a CSV file.', 'mainwp' ); ?>" data-inverted="" data-position="top left"><a href="admin.php?page=MainWPTools&doExportSites=yes&_wpnonce=<?php echo esc_html( wp_create_nonce( 'export_sites' ) ); ?>" class="ui button green basic"><?php esc_html_e( 'Export Child Sites', 'mainwp' ); ?></a></div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Disconnect all child sites', 'mainwp' ); ?></label>
						<div class="ten wide column" id="mainwp-disconnect-sites-tool" data-tooltip="<?php esc_attr_e( 'This will function will break the connection and leave the MainWP Child plugin active.', 'mainwp' ); ?>" data-variation="inverted" data-position="top left">
							<?php
							if ( $is_demo ) {
									MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="#" disabled="disabled" class="ui button green basic disabled">' . esc_html__( 'Disconnect Websites.', 'mainwp' ) . '</a>' );
							} else {
								?>
								<a href="admin.php?page=MainWPTools&disconnectSites=yes&_wpnonce=<?php echo esc_html( wp_create_nonce( 'disconnect_sites' ) ); ?>" onclick="mainwp_tool_disconnect_sites(); return false;"  class="ui button green basic"><?php esc_html_e( 'Disconnect Websites.', 'mainwp' ); ?></a>
							<?php } ?>
						</div>
					</div>
					<div class="ui hidden divider"></div>
					<div class="ui hidden divider"></div>
				</div>
				<div class="actions">
					<div class="ui two columns grid">
						<div class="left aligned column">
							<span data-tooltip="<?php esc_attr_e( 'Resets the page to its original layout and reinstates relocated columns.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><input type="button" class="ui button" name="reset" id="reset-managersites-settings" value="<?php esc_attr_e( 'Reset Page', 'mainwp' ); ?>" /></span>
						</div>
						<div class="ui right aligned column">
					<input type="submit" class="ui green button" name="btnSubmit" id="submit-managersites-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
				</div>
					</div>
				</div>
				<input type="hidden" name="reset_managersites_columns_order" value="0">
			</form>
		</div>
		<div class="ui small modal" id="mainwp-manage-sites-site-preview-screen-options-modal">
			<div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
			<div class="scrolling content ui form">
				<span><?php esc_html_e( 'Would you like to turn on home screen previews?  This function queries WordPress.com servers to capture a screenshot of your site the same way comments shows you preview of URLs.', 'mainwp' ); ?>
			</div>
			<div class="actions">
				<div class="ui ok button"><?php esc_html_e( 'Yes', 'mainwp' ); ?></div>
				<div class="ui cancel button"><?php esc_html_e( 'No', 'mainwp' ); ?></div>
			</div>
		</div>
		<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery('#reset-managersites-settings').on( 'click', function () {
					mainwp_confirm(__( 'Are you sure.' ), function(){
						jQuery('#mainwp_sitesviewmode').dropdown( 'set selected', 'grid' );
						jQuery('#submit-managersites-settings').click();
					}, false, false, true );
					return false;
				});
			} );
		</script>
		<?php
	}

	/**
	 * Prepare the items to be listed.
	 */
	public static function prepare_items() { // phpcs:ignore -- comlex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$orderby = 'wp.url';

		$req_orderby = null;
		$req_order   = null;

		$perPage = 9999;
		$start   = 0;

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$get_saved_state = ! isset( $_REQUEST['g'] ) && ! isset( $_REQUEST['status'] ) && ! isset( $_REQUEST['client'] );
		$get_all         = ( isset( $_REQUEST['status'] ) && 'all' === $_REQUEST['status'] ) && empty( $_REQUEST['g'] ) && empty( $_REQUEST['client'] ) ? true : false;
		$is_not          = ( isset( $_REQUEST['isnot'] ) && 'yes' === $_REQUEST['isnot'] ) ? true : false;

		$site_status = '';

		if ( ! isset( $_REQUEST['status'] ) ) {
			if ( $get_saved_state ) {
				$site_status = get_user_option( 'mainwp_screenshots_filter_status' );
			} else {
				MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_status', '' );
			}
		} else {
			MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_status', sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) );
			MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_is_not', $is_not );
			$site_status = sanitize_text_field( wp_unslash( $_REQUEST['status'] ) );
		}

		if ( $get_all ) {
			MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_group', '' );
			MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_client', '' );
		}

		$group_ids  = false;
		$client_ids = false;

		if ( ! $get_all ) {
			if ( ! isset( $_REQUEST['g'] ) ) {
				if ( $get_saved_state ) {
					$group_ids = get_user_option( 'mainwp_screenshots_filter_group' );
				} else {
					MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_group', '' );
				}
			} else {
				MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_group', sanitize_text_field( wp_unslash( $_REQUEST['g'] ) ) );
				$group_ids = sanitize_text_field( wp_unslash( $_REQUEST['g'] ) ); // may be multi groups.
			}

			if ( ! isset( $_REQUEST['client'] ) ) {
				if ( $get_saved_state ) {
					$client_ids = get_user_option( 'mainwp_screenshots_filter_client' );
				} else {
					MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_client', '' );
				}
			} else {
				MainWP_Utility::update_user_option( 'mainwp_screenshots_filter_client', sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ) );
				$client_ids = sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ); // may be multi groups.
			}
		}

		$where = null;

		if ( '' !== $site_status && 'all' !== $site_status ) {
			if ( 'connected' === $site_status ) {
				$where = 'wp_sync.sync_errors = ""';
				if ( $is_not ) {
					$where = 'wp_sync.sync_errors != ""';
				}
			} elseif ( 'disconnected' === $site_status ) {
				$where = 'wp_sync.sync_errors != ""';
				if ( $is_not ) {
					$where = 'wp_sync.sync_errors = ""';
				}
			} elseif ( 'update' === $site_status ) {
				$available_update_ids = MainWP_Common_Functions::instance()->get_available_update_siteids();
				if ( empty( $available_update_ids ) ) {
					$where = 'wp.id = -1';
					if ( $is_not ) {
						$where = 'wp.id != -1';
					}
				} else {
					$where = 'wp.id IN (' . implode( ',', $available_update_ids ) . ') ';
					if ( $is_not ) {
						$where = 'wp.id NOT IN (' . implode( ',', $available_update_ids ) . ') ';
					}
				}
			} elseif ( 'sitehealthnotgood' === $site_status ) {
				$where = ' wp_sync.health_status = 1 ';
				if ( $is_not ) {
					$where = 'wp_sync.health_status = 0';
				}
			} elseif ( 'phpver7' === $site_status ) {
				$where = ' INET_ATON(SUBSTRING_INDEX(CONCAT(wp_optionview.phpversion,".0.0.0"),".",4)) < INET_ATON("8.0.0.0") ';
				if ( $is_not ) {
					$where = ' INET_ATON(SUBSTRING_INDEX(CONCAT(wp_optionview.phpversion,".0.0.0"),".",4)) >= INET_ATON("8.0.0.0") ';
				}
			} elseif ( 'suspended' === $site_status ) {
				$where = 'wp.suspended = 1';
				if ( $is_not ) {
					$where = 'wp.suspended = 0';
				}
			}
		}
		// phpcs:enable

		$params = array(
			'selectgroups' => true,
			'orderby'      => $orderby,
			'offset'       => $start,
			'rowcount'     => $perPage,
		);

		$params['isnot'] = $is_not;

		$qry_group_ids = array();
		if ( ! empty( $group_ids ) ) {
			$group_ids = explode( ',', $group_ids ); // convert to array.
			// to fix query deleted groups.
			$groups = MainWP_DB_Common::instance()->get_groups_for_manage_sites();
			foreach ( $groups as $gr ) {
				if ( in_array( $gr->id, $group_ids ) ) {
					$qry_group_ids[] = $gr->id;
				}
			}
			// to fix.
			if ( in_array( 'nogroups', $group_ids ) ) {
				$qry_group_ids[] = 'nogroups';
			}
		}

		if ( ! empty( $qry_group_ids ) ) {
			$params['group_id'] = $qry_group_ids;
		}

		$qry_client_ids = array();
		if ( ! empty( $client_ids ) ) {
			$client_ids = explode( ',', $client_ids ); // convert to array.
			// to fix query deleted client.
			$clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all' );
			foreach ( $clients as $cl ) {
				if ( in_array( $cl->client_id, $client_ids ) ) {
					$qry_client_ids[] = $cl->client_id;
				}
			}
			// to fix.
			if ( in_array( 'noclients', $client_ids ) ) {
				$qry_client_ids[] = 'noclients';
			}
		}

		if ( ! empty( $qry_client_ids ) ) {
			$params['client_id'] = $qry_client_ids;
		}

		if ( ! empty( $where ) ) {
			$params['extra_where'] = $where;
		}

		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_search_websites_for_current_user( $params ) );
		return $websites;
	}
}
