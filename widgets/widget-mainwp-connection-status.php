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
	 * Displays $SYNCERRORS|$DOWN|$UP|$ALL.
	 */
	public static function render_sites() { // phpcs:ignore -- complex method.
		$current_wpid = MainWP_System_Utility::get_current_wpid();

		if ( $current_wpid ) {
			$sql = MainWP_DB::instance()->get_sql_website_by_id( $current_wpid );
		} else {
			$sql = MainWP_DB::instance()->get_sql_websites_for_current_user();
		}

		$websites = MainWP_DB::instance()->query( $sql );

		$count_connected    = 0;
		$count_disconnected = 0;

		// Loop 4 times, first we show the conflicts, then we show the down sites, then we show the up sites.
		$SYNCERRORS = 0;
		$DOWN       = 1;
		$UP         = 2;
		$ALL        = 3;

		$html_online_sites = '';
		$html_other_sites  = '';
		$html_all_sites    = '';

		$disconnect_site_ids = array();

		for ( $j = 0; $j < 4; $j ++ ) {
			MainWP_DB::data_seek( $websites, 0 );
			while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
				if ( empty( $website ) ) {
					continue;
				}

				$hasSyncErrors = ( '' != $website->sync_errors );
				$isDown        = ( ! $hasSyncErrors && ( -1 == $website->offline_check_result ) );
				$isUp          = ( ! $hasSyncErrors && ! $isDown );
				$md5Connection = ( 1 == $website->nossl );

				if ( $j != $ALL ) {
					if ( $j == $SYNCERRORS ) {
						if ( ! $hasSyncErrors ) {
							continue;
						}
					}
					if ( $j == $DOWN ) {
						if ( ! $md5Connection && ! $isDown ) {
							continue;
						}
					}
					if ( $j == $UP ) {
						if ( $md5Connection || ! $isUp ) {
							continue;
						}
					}
				}

				$output_md5 = '';
				if ( $md5Connection ) {
					$output_md5 = '<div>' . __( 'MD5 Connection' ) . '<br /><a href="http://mainwp.com/help/docs/md5-connection-issue/" class="ui button mini green basic" target="_blank" data-tooltip="MD5 Connection" data-inverted="">' . __( 'Read More', 'mainwp' ) . '</a></div>';
				}

				$lastSyncTime = ! empty( $website->dtsSync ) ? MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $website->dtsSync ) ) : '';

				ob_start();
				if ( $j == $ALL ) {
					self::render_all_item( $website, $lastSyncTime, $md5Connection, $output_md5, $hasSyncErrors );
				} elseif ( $j == $UP ) {
					self::render_up_item( $website, $lastSyncTime, $md5Connection, $output_md5 );
				} else {
					self::render_down_item( $website, $lastSyncTime, $md5Connection, $output_md5 );
				}
				$output = ob_get_clean();

				if ( $j == $ALL ) {
					$html_all_sites .= $output;
				} elseif ( $j == $UP ) {
					$html_online_sites .= $output;
					$count_connected++;
				} elseif ( ! in_array( $website->id, $disconnect_site_ids ) ) {
					$disconnect_site_ids[] = $website->id;
					$html_other_sites     .= $output;
					$count_disconnected++;
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
		<div class="ui grid">
			<div class="twelve wide column">
				<h3 class="ui header handle-drag">
					<?php esc_html_e( 'Connection Status', 'mainwp' ); ?>
					<div class="sub header"><?php esc_html_e( 'Child sites connection status', 'mainwp' ); ?></div>
				</h3>
			</div>
			<?php if ( empty( $current_wpid ) ) { ?>
			<div class="four wide column right aligned">
				<div id="widget-connect-status-dropdown-selector" class="ui dropdown right not-auto-init mainwp-dropdown-tab">
						<div class="text"><?php esc_html_e( 'All Sites', 'mainwp' ); ?></div>
						<i class="dropdown icon"></i>
						<div class="menu">
							<a class="active item" data-tab="no-sites" data-value="" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Hide the child sites list', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Hide Details', 'mainwp' ); ?></a>
							<a class="item" data-tab="all-sites" data-value="all-sites" data-position="left center" data-inverted="" data-tooltip="<?php esc_attr_e( 'See all child sites', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'See All Sites', 'mainwp' ); ?></a>
							<a class="item" data-tab="connected" data-value="connected" data-position="left center" data-inverted="" data-tooltip="<?php esc_attr_e( 'See all connected child sites', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'See All Connected', 'mainwp' ); ?></a>
							<a class="item" data-tab="disconnected" data-value="disconnected" data-position="left center" data-inverted="" data-tooltip="<?php esc_attr_e( 'See all disconnected child sites', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'See All Disconnected', 'mainwp' ); ?></a>
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
		<div class="ui hidden divider"></div>
		<?php
	}

	/**
	 * Render the MainWP Overview page COnection Status Widget Content.
	 *
	 * @param mixed $site Site list.
	 * @param mixed $count_connected Connection Count.
	 */
	public static function render_current_status( $site, $count_connected ) {
		if ( $count_connected > 0 ) :
			?>
			<div class="ui two column stackable grid">
				<div class="column left aligned">
					<h2 class="ui header">
					<i class="green check icon"></i>
					<div class="content"><?php esc_html_e( 'Connected', 'mainwp' ); ?></div>
					</h2>
				</div>
				<div class="column right aligned">
					<a href="<?php echo $site->url; ?>" class="ui icon mini button" target="_blank" data-tooltip="<?php esc_html_e( 'Go to the site front page', 'mainwp' ); ?>" data-inverted=""><i class="external alternate icon"></i></a>
					<a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $site->id; ?>" class="ui icon mini button" target="_blank" data-tooltip="<?php esc_html_e( 'Go to the site WP Admin', 'mainwp' ); ?>" data-inverted=""><i class="sign in alternate icon"></i></a>
					<a href="javascript:void(0)" class="ui button mini green" siteid="<?php echo $site->id; ?>" onClick="updatesoverview_wp_sync( '<?php echo $site->id; ?>' )" data-tooltip="Sync <?php echo stripslashes( $site->name ); ?> data." data-inverted=""><?php esc_html_e( 'Sync Data', 'mainwp' ); ?></a>
				</div>
			</div>
		<?php else : ?>
			<div class="ui two column stackable grid mainwp_wp_sync" site_id="<?php echo $site->id; ?>">
				<div class="column left aligned">
					<h2 class="ui header">
					<i class="red unlink icon"></i>
					<div class="content"><?php esc_html_e( 'Disconnected', 'mainwp' ); ?></div>
					</h2>
				</div>
				<div class="column right aligned">
					<a href="<?php echo $site->url; ?>" class="ui icon mini button" target="_blank" data-tooltip="<?php esc_html_e( 'Go to the site front page', 'mainwp' ); ?>" data-inverted=""><i class="external alternate icon"></i></a>
					<a href="#" class="mainwp-updates-overview-reconnect-site ui mini breen basic button" siteid="<?php echo $site->id; ?>" data-tooltip="Reconnect <?php echo stripslashes( $site->name ); ?>" data-inverted=""><?php esc_html_e( 'Reconnect', 'mainwp' ); ?></a>
				</div>
			</div>
			<?php
		endif;
	}

	/**
	 * Render Count UI
	 *
	 * @param mixed $count_connected Connected Count.
	 * @param mixed $count_disconnected Disconnected Count.
	 */
	public static function render_multi_status( $count_connected, $count_disconnected ) {
		?>
		<div class="ui two column stackable grid">
			<div class="column center aligned">
				<div class="ui horizontal statistics large">
					<div class="green statistic">
						<div class="value">
							<?php echo $count_connected; ?>
						</div>
						<div class="label">
							<?php esc_html_e( 'Connected', 'mainwp' ); ?>
						</div>
					</div>
				</div>
			</div>
			<div class="column center aligned">
				<div class="ui horizontal statistics large">
					<div class="red statistic">
						<div class="value">
							<?php echo $count_disconnected; ?>
						</div>
						<div class="label">
							<?php esc_html_e( 'Disconnected', 'mainwp' ); ?>
						</div>
					</div>
				</div>
			</div>
		</div>
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
		<div class="ui hidden divider"></div>

		<div class="ui active tab" data-tab="no-sites"></div>

		<div class="ui tab" data-tab="all-sites">
			<div class="ui middle aligned divided selection list">
				<?php echo $html_all_sites; ?>
			</div>
		</div>

		<div class="ui tab" data-tab="connected">
			<div class="ui middle aligned divided selection list">
				<?php echo $html_online_sites; ?>
			</div>
		</div>

		<div class="ui tab" data-tab="disconnected">
			<div class="ui middle aligned divided selection list">
				<?php echo $html_other_sites; ?>
			</div>
		</div>
		<?php
	}


	/**
	 * Render all items list.
	 *
	 * @param mixed $website Website Info.
	 * @param mixed $lastSyncTime Last time the Child Site was synced to.
	 * @param mixed $md5Connection md5 Connection.
	 * @param mixed $output_md5 md5 decoded output.
	 * @param mixed $hasSyncErrors Collected errors.
	 */
	public static function render_all_item( $website, $lastSyncTime, $md5Connection, $output_md5, $hasSyncErrors ) {
		?>
		<div class="item mainwp_wp_sync" site_id="<?php echo intval( $website->id ); ?>" site_name="<?php echo rawurlencode( $website->name ); ?>">
			<div class="ui grid">
				<div class="six wide column middle aligned">
					<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo $website->name; ?></a>
				</div>
				<div class="one wide column middle aligned">
					<a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $website->id; ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to the site WP Admin', 'mainwp' ); ?>" data-inverted=""><i class="sign in alternate icon"></i></a>
				</div>
				<div class="one wide column middle aligned">
					<a href="<?php echo esc_html( $website->url ); ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to the site front page', 'mainwp' ); ?>" data-inverted=""><i class="external alternate icon"></i></a>
				</div>
				<div class="four wide column middle aligned">
				<span><?php echo esc_attr( $lastSyncTime ); ?></span>
				</div>
				<div class="four wide column middle aligned right aligned reconnect-wrapper">
			<?php
			if ( $md5Connection ) {
				echo $output_md5;
			} elseif ( $hasSyncErrors ) {
				?>
					<a href="javascript:void(0)" class="mainwp-updates-overview-reconnect-site ui button mini green basic" siteid="<?php echo intval( $website->id ); ?>" data-tooltip="Reconnect <?php echo stripslashes( $website->name ); ?>." data-inverted=""><?php esc_html_e( 'Reconnect', 'mainwp' ); ?></a>
				<?php
			} else {
				?>
					<a href="javascript:void(0)" class="ui button mini green" siteid="<?php echo intval( $website->id ); ?>" onClick="updatesoverview_wp_sync( '<?php echo intval( $website->id ); ?>' )" data-tooltip="Sync <?php echo stripslashes( $website->name ); ?> data." data-inverted=""><?php esc_html_e( 'Sync Data', 'mainwp' ); ?></a>
				<?php
			}
			?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Connected Sites List.
	 *
	 * @param mixed $website Website Info.
	 * @param mixed $lastSyncTime Last time the Child Site was synced to.
	 * @param mixed $md5Connection md5 Connection.
	 * @param mixed $output_md5 md5 decoded output.
	 */
	public static function render_up_item( $website, $lastSyncTime, $md5Connection, $output_md5 ) {
		?>
	<div class="item mainwp_wp_sync" site_id="<?php echo intval( $website->id ); ?>" site_name="<?php echo rawurlencode( $website->name ); ?>">
		<div class="ui grid">
			<div class="six wide column middle aligned">
				<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo $website->name; ?></a>
			</div>
			<div class="one wide column middle aligned">
				<a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $website->id; ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to the site WP Admin', 'mainwp' ); ?>" data-inverted=""><i class="sign in alternate icon"></i></a>
			</div>
			<div class="one wide column middle aligned">
				<a href="<?php echo esc_html( $website->url ); ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to the site front page', 'mainwp' ); ?>" data-inverted=""><i class="external alternate icon"></i></a>
			</div>
			<div class="four wide column middle aligned">
				<span><?php echo esc_attr( $lastSyncTime ); ?></span>
			</div>
			<div class="four wide column middle aligned right aligned">
		<?php
		if ( $md5Connection ) {
			echo $output_md5;
		} else {
			?>
				<a href="javascript:void(0)" class="ui button mini green" siteid="<?php echo intval( $website->id ); ?>" onClick="updatesoverview_wp_sync( '<?php echo intval( $website->id ); ?>' )" data-tooltip="Sync <?php echo stripslashes( $website->name ); ?> data." data-inverted=""><?php esc_html_e( 'Sync Data', 'mainwp' ); ?></a>
			<?php
		}
		?>
			</div>
		</div>
	</div>
		<?php
	}

	/**
	 * Render Disconected Sites List.
	 *
	 * @param mixed $website Website Info.
	 * @param mixed $lastSyncTime Last time the Child Site was synced to.
	 * @param mixed $md5Connection md5 Connection.
	 * @param mixed $output_md5 md5 decoded output.
	 */
	public static function render_down_item( $website, $lastSyncTime, $md5Connection, $output_md5 ) {
		?>
		<div class="item mainwp_wp_sync" site_id="<?php echo intval( $website->id ); ?>" site_name="<?php echo rawurlencode( $website->name ); ?>">
			<div class="ui grid">
				<div class="six wide column middle aligned">
					<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo $website->name; ?></a>
				</div>
				<div class="one wide column middle aligned">
					<a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $website->id; ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to the site WP Admin', 'mainwp' ); ?>" data-inverted=""><i class="sign in alternate icon"></i></a>
				</div>
				<div class="one wide column middle aligned">
					<a href="<?php echo esc_html( $website->url ); ?>" target="_blank" data-tooltip="<?php esc_attr_e( 'Go to the site front page', 'mainwp' ); ?>" data-inverted=""><i class="external alternate icon"></i></a>
				</div>
				<div class="four wide column middle aligned">
					<span><?php echo esc_attr( $lastSyncTime ); ?></span>
				</div>
				<div class="four wide column middle aligned right aligned reconnect-wrapper">
				<?php
				if ( $md5Connection ) {
					echo $output_md5;
				} else {
					?>
					<a href="#" class="mainwp-updates-overview-reconnect-site" siteid="<?php echo intval( $website->id ); ?>" data-tooltip="Reconnect <?php echo stripslashes( $website->name ); ?>" data-inverted=""><?php esc_html_e( 'Reconnect', 'mainwp' ); ?></a>
			<?php } ?>
				</div>
			</div>
		</div>
		<?php
	}

}
