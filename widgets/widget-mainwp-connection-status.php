<?php
/**
 * MainWP Connection Status
 *
 * Build the MainWP Overview page Connection Status Widget.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Connection_Status
 *
 * Build the Connection Status Widget.
 */
class MainWP_Connection_Status {

	/**
	 * Method get_class_name()
	 *
	 * @return string __CLASS__ Class Name
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method render()
	 *
	 * @return mixed render_sites()
	 */
	public static function render() {
		self::render_sites();
	}

	/**
	 * Method render_sites()
	 *
	 * Build the Connection Status Widget
	 * Displays $SYNCERRORS|$UP|$ALL.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_websites_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_search_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
	 * @uses \MainWP\Dashboard\MainWP_Utility::format_timestamp()
	 * @uses \MainWP\Dashboard\MainWP_Utility::get_timestamp()
	 */
	public static function render_sites() { // phpcs:ignore -- current complexity required to achieve desired results. Pull request solutions appreciated.
		$current_wpid = MainWP_System_Utility::get_current_wpid();

		if ( $current_wpid ) {
			$sql = MainWP_DB::instance()->get_sql_website_by_id( $current_wpid );
		} else {
			$sql = MainWP_DB::instance()->get_sql_websites_for_current_user();
		}

		$websites = MainWP_DB::instance()->query( $sql );

		$count_connected    = 0;
		$count_disconnected = 0;

		// Loop 3 times.
		$SYNCERRORS = 0;
		$UP         = 1;
		$ALL        = 2;

		$html_online_sites = '';
		$html_other_sites  = '';
		$html_all_sites    = '';

		$disconnect_site_ids = array();

		for ( $j = 0; $j < 3; $j++ ) {
			MainWP_DB::data_seek( $websites, 0 );
			while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
				if ( empty( $website ) ) {
					continue;
				}

				$hasSyncErrors = ( '' !== $website->sync_errors );
				$isUp          = ! $hasSyncErrors;

				if ( $j !== $ALL ) {
					if ( $j === $SYNCERRORS ) {
						if ( ! $hasSyncErrors ) {
							continue;
						}
					}
					if ( $j === $UP ) {
						if ( ! $isUp ) {
							continue;
						}
					}
				}

				$lastSyncTime = ! empty( $website->dtsSync ) ? MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $website->dtsSync ) ) : '';

				ob_start();

				if ( $j === $ALL ) {
					self::render_all_item( $website, $lastSyncTime, $hasSyncErrors );
				} elseif ( $j === $UP ) {
					self::render_up_item( $website, $lastSyncTime );
				} else {
					self::render_down_item( $website, $lastSyncTime );
				}

				/**
				 * Action: mainwp_connection_status_widget_bottom
				 *
				 * Fires at the bottom of the Connection Status widget.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_connection_status_widget_bottom' );

				$output = ob_get_clean();

				if ( $j === $ALL ) {
					$html_all_sites .= $output;
				} elseif ( $j === $UP ) {
					$html_online_sites .= $output;
					++$count_connected;
				} elseif ( ! in_array( $website->id, $disconnect_site_ids ) ) {
					$disconnect_site_ids[] = $website->id;
					$html_other_sites     .= $output;
					++$count_disconnected;
				}
			}
		}

		MainWP_DB::free_result( $websites );

		self::render_status( $current_wpid );

		if ( empty( $current_wpid ) ) {

			self::render_multi_status( $count_connected, $count_disconnected );
		} else {
			// Get site by ID $current_wpid.
			$site = MainWP_DB::instance()->get_website_by_id( $current_wpid );
			self::render_current_status( $site, $count_connected );
		}

		self::render_details( $html_all_sites, $html_online_sites, $html_other_sites );
	}

	/**
	 * The renders the MainWP Overview page Connection Status Widget Header and Drop down Box.
	 *
	 * @param mixed $current_wpid Current Website ID.
	 */
	public static function render_status( $current_wpid ) {
		?>
		<div class="ui grid mainwp-widget-header">
			<div class="twelve wide column">
				<h3 class="ui header handle-drag">
					<?php
					/**
					 * Filter: mainwp_connection_status_widget_title
					 *
					 * Filters the Connection Status widget title text.
					 *
					 * @since 4.1
					 */
					echo esc_html( apply_filters( 'mainwp_connection_status_widget_title', esc_html__( 'Connection Status', 'mainwp' ) ) );
					?>
					<div class="sub header"><?php esc_html_e( 'Child sites connection status', 'mainwp' ); ?></div>
				</h3>
			</div>
			<?php if ( empty( $current_wpid ) ) { ?>
			<div class="four wide column right aligned">
				<div id="widget-connect-status-dropdown-selector" class="ui dropdown top right pointing not-auto-init mainwp-dropdown-tab">
						<div class="text"><?php esc_html_e( 'All Sites', 'mainwp' ); ?></div>
						<i class="dropdown icon"></i>
						<div class="menu">
							<a class="item" data-tab="all-sites" data-value="all-sites" data-position="left center" data-inverted="" data-tooltip="<?php esc_attr_e( 'See all child sites', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'All Sites', 'mainwp' ); ?></a>
							<a class="item" data-tab="connected" data-value="connected" data-position="left center" data-inverted="" data-tooltip="<?php esc_attr_e( 'See all connected child sites', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Connected', 'mainwp' ); ?></a>
							<a class="item" data-tab="disconnected" data-value="disconnected" data-position="left center" data-inverted="" data-tooltip="<?php esc_attr_e( 'See all disconnected child sites', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Disconnected', 'mainwp' ); ?></a>
						<a class="item" data-tab="no-sites" data-value="no-sites" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Hide the child sites list', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Hide Details', 'mainwp' ); ?></a>
						</div>
				</div>
			</div>

			<script type="text/javascript">
				jQuery( document ).ready( function () {
					jQuery( '#widget-connect-status-dropdown-selector' ).dropdown( {
						onChange: function( val ) {
							if ( typeof( Storage ) !== 'undefined' ) {
								localStorage.setItem( 'lsWidgetConnectStatusDropdownVal', val );
							}
						}
					} );
					if ( typeof( Storage ) !== "undefined" ) {
						if ( val = localStorage.getItem( 'lsWidgetConnectStatusDropdownVal' ) ) {
							jQuery( '#widget-connect-status-dropdown-selector' ).dropdown( 'set selected',val );
							jQuery( '#widget-connect-status-dropdown-selector' ).closest( '.mainwp-widget' ).find( 'div[data-tab="' + val + '"]' ).addClass( 'active' );
						}
					}
				} );
			</script>

			<?php } ?>
		</div>
		<?php
		/**
		 * Action: mainwp_connection_status_widget_top
		 *
		 * Fires at the top of the Connection Status widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_connection_status_widget_top' );
	}

	/**
	 * Render the MainWP Overview page Conection Status Widget Content.
	 *
	 * @param mixed $site Site list.
	 * @param mixed $count_connected Connection Count.
	 */
	public static function render_current_status( $site, $count_connected ) {
		/**
		 * Action: mainwp_connection_status_widget_single_top
		 *
		 * Fires at the top of the Connection Status widget.
		 *
		 * @param object $site Object containing the child site info.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_connection_status_widget_single_top', $site );
		if ( $count_connected > 0 ) :
			?>
			<div class="ui grid stackable">
				<div class="fourteen wide column">
					<h2 class="ui header">
					<?php if ( '1' === $site->suspended ) { ?>
						<i class="pause yellow circle icon"></i>
						<div class="content"><?php esc_html_e( 'Suspended', 'mainwp' ); ?></div>
					<?php } else { ?>
						<i class="green check icon"></i>
						<div class="content"><?php esc_html_e( 'Connected', 'mainwp' ); ?></div>
					<?php } ?>	
				</h2>
				</div>
				<div class="column two wide center aligned">
					<div class="ui mini icon buttons">
					<a href="<?php echo esc_url( $site->url ); ?>" class="ui mini button" target="_blank" data-tooltip="<?php esc_html_e( 'Go to the site front page', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Go to Site', 'mainwp' ); ?></a>
					<a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $site->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" class="ui mini button" target="_blank" data-tooltip="<?php esc_html_e( 'Go to the site WP Admin', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( ' Go to WP Admin', 'mainwp' ); ?></a>
					<a href="javascript:void(0)" class="ui button mini green" siteid="<?php echo intval( $site->id ); ?>" onClick="updatesoverview_wp_sync( '<?php echo intval( $site->id ); ?>' )" data-tooltip="Sync <?php echo esc_attr( stripslashes( $site->name ) ); ?> data." data-inverted=""><?php esc_html_e( 'Sync Data', 'mainwp' ); ?></a>
				</div>
			</div>
		</div>
		<?php else : ?>
			<div class="ui grid stackable mainwp_wp_sync" site_id="<?php echo intval( $site->id ); ?>">
				<div class="fourteen wide column">
					<h2 class="ui header">
					<i class="red unlink icon"></i>
					<div class="content"><?php esc_html_e( 'Disconnected', 'mainwp' ); ?></div>
					</h2>
				</div>
				<div class="column two wide center aligned ">
					<div class="ui mini icon buttons">
					<a href="<?php echo esc_url( $site->url ); ?>" class="ui mini button" target="_blank" data-tooltip="<?php esc_html_e( 'Go to the site front page', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Go to Site', 'mainwp' ); ?></a>
					<a href="#" class="mainwp-updates-overview-reconnect-site ui mini green basic button" siteid="<?php echo intval( $site->id ); ?>" data-tooltip="Reconnect <?php echo esc_attr( stripslashes( $site->name ) ); ?>" data-inverted=""><?php esc_html_e( 'Reconnect', 'mainwp' ); ?></a>
				</div>
			</div>
		</div>
			<?php
		endif;
		/**
		 * Action: mainwp_connection_status_widget_single_bottom
		 *
		 * Fires at the bottom of the Connection Status widget.
		 *
		 * @param object $site Object containing the child site info.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_connection_status_widget_single_bottom', $site );
	}

	/**
	 * Render connection status summary section.
	 *
	 * @param int $count_connected    Connected Count.
	 * @param int $count_disconnected Disconnected Count.
	 */
	public static function render_multi_status( $count_connected, $count_disconnected ) {
		$count_total = $count_connected + $count_disconnected;
		?>
		<div class="ui two column stackable grid mainwp-widget-header">
			<div class="ui multiple progress" id="mainwp-site-status-progress" style="width:100%" data-total="<?php echo esc_attr( $count_total ); ?>" data-value="<?php echo esc_attr( $count_connected ) . ',' . esc_attr( $count_disconnected ); ?>">
				<div class="green bar"><div class="centered progress"></div></div>
				<div class="red bar"><div class="centered progress"></div></div>
						<div class="label">
				<a href="javascript:void(0);" onclick="jQuery( '#widget-connect-status-dropdown-selector' ).dropdown( 'set selected','connected' );jQuery( '#widget-connect-status-dropdown-selector' ).closest( '.mainwp-widget' ).find( 'div[data-tab=connected]' ).addClass( 'active' );jQuery( '#widget-connect-status-dropdown-selector' ).closest( '.mainwp-widget' ).find( 'div[data-tab=disconnected]' ).removeClass( 'active' );"><?php echo esc_html( $count_connected ) . ' Connected'; ?></a> - <a href="javascript:void(0);" onclick="jQuery( '#widget-connect-status-dropdown-selector' ).dropdown( 'set selected','disconnected' );jQuery( '#widget-connect-status-dropdown-selector' ).closest( '.mainwp-widget' ).find( 'div[data-tab=disconnected]' ).addClass( 'active' );jQuery( '#widget-connect-status-dropdown-selector' ).closest( '.mainwp-widget' ).find( 'div[data-tab=connected]' ).removeClass( 'active' );"><?php echo esc_html( $count_disconnected ) . ' Disconnected'; ?></a>
				</div>
			</div>
		</div>
		<script type="text/javascript">
			jQuery('#mainwp-site-status-progress').progress();
		</script>
		<?php
	}

	/**
	 * Render See All Sites List.
	 *
	 * @param mixed $html_all_sites All sites html.
	 * @param mixed $html_online_sites Online sites html.
	 * @param mixed $html_other_sites Other sites html.
	 */
	public static function render_details( $html_all_sites, $html_online_sites, $html_other_sites ) {
		?>
		<div class="mainwp-scrolly-overflow">
		<div class="ui tab" data-tab="all-sites">
			<?php
			/**
			 * Action: mainwp_connection_status_before_all_sites_list
			 *
			 * Fires before the list of all sites in the connection status widgets
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_connection_status_before_all_sites_list' )
			?>
			<div class="ui middle aligned divided selection list">
				<?php echo $html_all_sites; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
			<?php
			/**
			 * Action: mainwp_connection_status_after_all_sites_list
			 *
			 * Fires after the list of all sites in the connection status widgets
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_connection_status_after_all_sites_list' )
			?>
		</div>

		<div class="ui tab" data-tab="connected">
			<?php
			/**
			 * Action: mainwp_connection_status_before_connected_sites_list
			 *
			 * Fires before the list of connected sites in the connection status widgets
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_connection_status_before_connected_sites_list' )
			?>
			<div class="ui middle aligned divided selection list">
				<?php echo $html_online_sites; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
			<?php
			/**
			 * Action: mainwp_connection_status_after_connected_sites_list
			 *
			 * Fires after the list of connected sites in the connection status widgets
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_connection_status_after_connected_sites_list' )
			?>
		</div>

		<div class="ui tab" data-tab="disconnected">
			<?php
			/**
			 * Action: mainwp_connection_status_before_disconnected_sites_list
			 *
			 * Fires before the list of disconnected sites in the connection status widgets
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_connection_status_before_disconnected_sites_list' )
			?>
			<div class="ui middle aligned divided selection list">
				<?php echo $html_other_sites; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
			<?php
			/**
			 * Action: mainwp_connection_status_after_disconnected_sites_list
			 *
			 * Fires after the list of disconnected sites in the connection status widgets
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_connection_status_after_disconnected_sites_list' )
			?>
		</div>
		<div class="ui tab" data-tab="no-sites"></div>
		</div>
		<?php
	}


	/**
	 * Render all items list.
	 *
	 * @param mixed $website Website Info.
	 * @param mixed $lastSyncTime Last time the Child Site was synced to.
	 * @param mixed $hasSyncErrors Collected errors.
	 */
	public static function render_all_item( $website, $lastSyncTime, $hasSyncErrors ) {
		$is_demo = MainWP_Demo_Handle::is_demo_mode();
		?>
		<div class="item mainwp_wp_sync" site_id="<?php echo intval( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( $website->name ) ); ?>">
			<div class="ui stackable grid">
				<div class="twelve wide column middle aligned">
					<div>
					<a href="
					<?php
					/**
					 * Filter: mainwp_connection_status_list_item_title_url
					 *
					 * Filters the Connection Status widget list item title URL.
					 *
					 * @since 4.1
					 */
					echo esc_url( apply_filters( 'mainwp_connection_status_list_item_title_url', 'admin.php?page=managesites&dashboard=' . $website->id, $website ) );
					?>
					">
						<?php
						/**
						 * Filter: mainwp_connection_status_list_item_title
						 *
						 * Filters the Connection Status widget list item title text.
						 *
						 * @since 4.1
						 */
						echo esc_html( stripslashes( apply_filters( 'mainwp_connection_status_list_item_title', $website->name, $website ) ) );
						?>
					</a>
				</div>
					<span class="ui small text"><?php esc_html_e( 'Last Synced: ', 'mainwp' ); ?> <?php echo esc_html( $lastSyncTime ); ?></span>
				</div>
				<div class="four wide middle aligned column reconnect-wrapper">
				<div class="ui mini icon fluid buttons">
				<?php if ( $is_demo ) : ?>
					<a class="ui button" href="<?php echo esc_html( $website->url ) . 'wp-admin.html'; ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to the site WP Admin', 'mainwp' ); ?>" data-inverted=""><i class="sign in alternate icon"></i></a>
				<?php else : ?>
				<a class="ui button" href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to the site WP Admin', 'mainwp' ); ?>" data-inverted="" data-position="left center"><i class="sign in alternate icon"></i></a>
				<?php endif; ?>
				<a class="ui button" href="<?php echo esc_html( $website->url ); ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to the site front page', 'mainwp' ); ?>" data-inverted="" data-position="left center"><i class="external alternate icon"></i></a>
					<?php if ( $hasSyncErrors ) : ?>
						<a href="javascript:void(0)" class="mainwp-updates-overview-reconnect-site ui button green basic" siteid="<?php echo intval( $website->id ); ?>" data-tooltip="Reconnect <?php echo esc_html( stripslashes( $website->name ) ); ?>" data-inverted="" data-position="left center"><i class="linkify icon"></i></a>
					<?php else : ?>
						<a href="javascript:void(0)" class="ui button green" siteid="<?php echo intval( $website->id ); ?>" onClick="updatesoverview_wp_sync( '<?php echo intval( $website->id ); ?>' )" data-tooltip="Sync <?php echo esc_html( stripslashes( $website->name ) ); ?> data" data-inverted="" data-position="left center"><i class="sync alternate icon"></i></a>
					<?php endif; ?>
				</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Connected Sites List.
	 *
	 * @param object $website       Object containing the child site info.
	 * @param string $lastSyncTime  Last time the Child Site was synced to.
	 */
	public static function render_up_item( $website, $lastSyncTime ) {
		$is_demo = MainWP_Demo_Handle::is_demo_mode();
		?>
	<div class="item mainwp_wp_sync" site_id="<?php echo intval( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( $website->name ) ); ?>">
		<div class="ui stackable grid">
			<div class="six wide column middle aligned">
					<a href="
					<?php
					/**
					 * Filter: mainwp_connection_status_list_item_title_url
					 *
					 * Filters the Connection Status widget list item title URL.
					 *
					 * @since 4.1
					 */
					echo esc_url( apply_filters( 'mainwp_connection_status_list_item_title_url', 'admin.php?page=managesites&dashboard=' . $website->id, $website ) );
					?>
					">
						<?php
						/**
						 * Filter: mainwp_connection_status_list_item_title
						 *
						 * Filters the Connection Status widget list item title text.
						 *
						 * @since 4.1
						 */
						echo esc_html( stripslashes( apply_filters( 'mainwp_connection_status_list_item_title', $website->name, $website ) ) );
						?>
					</a>
			</div>
			<div class="one wide column middle aligned">
				<?php if ( $is_demo ) : ?>
					<a class="ui button" href="<?php echo esc_html( $website->url ) . 'wp-admin.html'; ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to the site WP Admin', 'mainwp' ); ?>" data-inverted=""><i class="sign in alternate icon"></i></a>
				<?php else : ?>
				<a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to the site WP Admin', 'mainwp' ); ?>" data-inverted=""><i class="sign in alternate icon"></i></a>
				<?php endif; ?>
			</div>
			<div class="one wide column middle aligned">
				<a href="<?php echo esc_html( $website->url ); ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to the site front page', 'mainwp' ); ?>" data-inverted=""><i class="external alternate icon"></i></a>
			</div>
			<div class="four wide column middle aligned">
				<span><?php echo esc_attr( $lastSyncTime ); ?></span>
			</div>
			<div class="four wide column middle aligned">
			<a href="javascript:void(0)" class="ui button mini green" siteid="<?php echo intval( $website->id ); ?>" onClick="updatesoverview_wp_sync( '<?php echo intval( $website->id ); ?>' )" data-tooltip="Sync <?php echo esc_html( stripslashes( $website->name ) ); ?> data." data-inverted=""><?php esc_html_e( 'Sync Data', 'mainwp' ); ?></a>
			</div>
		</div>
	</div>
		<?php
	}

	/**
	 * Render Disconected Sites List.
	 *
	 * @param object $website       Object containing the child site info.
	 * @param string $lastSyncTime  Last time the Child Site was synced to.
	 */
	public static function render_down_item( $website, $lastSyncTime ) {
		$is_demo = MainWP_Demo_Handle::is_demo_mode();
		?>
		<div class="item mainwp_wp_sync" site_id="<?php echo intval( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( $website->name ) ); ?>">
			<div class="ui stackable grid">
				<div class="six wide column middle aligned">
					<a href="
					<?php
					/**
					 * Filter: mainwp_connection_status_list_item_title_url
					 *
					 * Filters the Connection Status widget list item title URL.
					 *
					 * @since 4.1
					 */
					echo esc_url( apply_filters( 'mainwp_connection_status_list_item_title_url', 'admin.php?page=managesites&dashboard=' . $website->id, $website ) );
					?>
					">
						<?php
						/**
						 * Filter: mainwp_connection_status_list_item_title
						 *
						 * Filters the Connection Status widget list item title text.
						 *
						 * @since 4.1
						 */
						echo esc_attr( stripslashes( apply_filters( 'mainwp_connection_status_list_item_title', $website->name, $website ) ) );
						?>
					</a>
				</div>
				<div class="one wide column middle aligned">
					<?php if ( $is_demo ) : ?>
						<a class="ui button" href="<?php echo esc_html( $website->url ) . 'wp-admin.html'; ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to the site WP Admin', 'mainwp' ); ?>" data-inverted=""><i class="sign in alternate icon"></i></a>
					<?php else : ?>
					<a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to the site WP Admin', 'mainwp' ); ?>" data-inverted=""><i class="sign in alternate icon"></i></a>
					<?php endif; ?>
				</div>
				<div class="one wide column middle aligned">
					<a href="<?php echo esc_html( $website->url ); ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to the site front page', 'mainwp' ); ?>" data-inverted=""><i class="external alternate icon"></i></a>
				</div>
				<div class="four wide column middle aligned">
					<span><?php echo esc_attr( $lastSyncTime ); ?></span>
				</div>
				<div class="four wide column middle aligned reconnect-wrapper">
				<a href="#" class="mainwp-updates-overview-reconnect-site" siteid="<?php echo intval( $website->id ); ?>" data-tooltip="Reconnect <?php echo esc_html( stripslashes( $website->name ) ); ?>" data-inverted=""><?php esc_html_e( 'Reconnect', 'mainwp' ); ?></a>
				</div>
			</div>
		</div>
		<?php
	}
}
