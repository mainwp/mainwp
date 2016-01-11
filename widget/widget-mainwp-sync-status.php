<?php

class MainWP_Sync_Status {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function init() {

	}

	public static function getName() {
		return '<i class="fa fa-refresh"></i> ' . __( 'Sync Status', 'mainwp' );
	}

	public static function render() {
		?>
		<div id="sync_status_list" xmlns="http://www.w3.org/1999/html"><?php MainWP_Sync_Status::renderSites(); ?></div>
		<?php
	}

	public static function renderSites() {
		$sql = MainWP_DB::Instance()->getSQLWebsitesForCurrentUser();
		$websites = MainWP_DB::Instance()->query( $sql );

		if ( ! $websites ) {
			return;
		}
		$all_sites_synced = true;
		$top_row = true;
		?>

		<div class="clear">
			<div id="wp_syncs">
				<?php
				ob_start();

				@MainWP_DB::data_seek( $websites, 0 );
				while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
					if ( empty( $website ) || $website->sync_errors != '' ) {
						continue;
					}
					if ( time() - $website->dtsSync < 60 * 60 * 24 ) {
						continue;
					}
					$all_sites_synced = false;
					$lastSyncTime = ! empty( $website->dtsSync ) ? MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $website->dtsSync ) ) : '';
					?>
					<div class="<?php echo $top_row ? 'mainwp-row-top' : 'mainwp-row' ?> mainwp_wp_sync" site_id="<?php echo $website->id; ?>" site_name="<?php echo rawurlencode( $website->name ); ?>">
						<span class="mainwp-left-col"><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a><input type="hidden" id="wp_sync<?php echo $website->id; ?>" /></span>
						<span class="mainwp-mid-col wordpressInfo" id="wp_sync_<?php echo $website->id; ?>">
							<?php echo $lastSyncTime; ?>
						</span>
						<span class="mainwp-right-col wordpressAction">
							<div id="wp_syncs_<?php echo $website->id; ?>">
								<a class="mainwp-upgrade-button button" onClick="rightnow_wp_sync('<?php echo $website->id; ?>')"><?php _e( 'Sync Now', 'mainwp' ); ?></a>
							</div>
						</span>
					</div>
					<?php
					$top_row = false;
				}
				$output = ob_get_clean();

				if ( $all_sites_synced ) {
					echo esc_html__( 'All sites have been synced within the last 24 hours', 'mainwp' ) . ".";
				} else {
					echo '<div class="mainwp_info-box-red">' . esc_html__( 'Sites not synced in the last 24 hours.', 'mainwp' ) . '</div>';
					echo $output;
				}

				?>
			</div>
		</div>
		<?php
		@MainWP_DB::free_result( $websites );
	}
}
