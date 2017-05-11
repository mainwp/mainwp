<?php

class MainWP_Connection_Status {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function init() {

	}

	public static function getName() {
		return '<i class="fa fa-heartbeat" aria-hidden="true"></i> ' . __( 'Connection status', 'mainwp' );
	}

	public static function render() {
		?>
		<div id="sync_status_list" xmlns="http://www.w3.org/1999/html"><?php MainWP_Connection_Status::renderSites(); ?></div>
		<?php
	}

	public static function renderSites() {
		$current_wpid = MainWP_Utility::get_current_wpid();

		if ( $current_wpid ) {
			$sql = MainWP_DB::Instance()->getSQLWebsiteById( $current_wpid );
		} else {
		    $sql = MainWP_DB::Instance()->getSQLWebsitesForCurrentUser();
		}

		$websites = MainWP_DB::Instance()->query( $sql );

		if ( ! $websites ) return;
				
		$top_row = true;		
        $top_up_row = true;
		?>
		<div class="clear">			
                        <?php
                        //Loop 3 times, first we show the conflicts, then we show the down sites, then we show the up sites
                        $SYNCERRORS = 0;
                        $DOWN       = 1;
                        $UP         = 2;

                        $html_online_sites = '';
                        $html_other_sites = '';
                        $disconnect_site_ids = array(); // to fix double display

                        for ( $j = 0; $j < 3; $j ++ ) {
                            @MainWP_DB::data_seek( $websites, 0 );
                            while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
                                if ( empty( $website ) ) continue;

                                $hasSyncErrors = ( $website->sync_errors != '' );
                                $isDown = ( ! $hasSyncErrors && ( $website->offline_check_result == - 1 ) );
                                $isUp   = ( ! $hasSyncErrors && ! $isDown );
                                $md5Connection = ($website->nossl == 1);

                                if ( $j == $SYNCERRORS ) {
                                    if ( !$hasSyncErrors ) continue;
                                }
                                if ( $j == $DOWN  ) {
                                    if ( !$md5Connection && !$isDown ) continue;
                                }
                                if ( $j == $UP ) {
                                    if ( $md5Connection || !$isUp ) continue;
                                }

                                $lastSyncTime = ! empty( $website->dtsSync ) ? MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( $website->dtsSync ) ) : '';

                                $is_top_row = false;
                                if ( ( $j == $UP && $top_up_row ) || ( $j != $UP && $top_row ) ) {
                                    $is_top_row = true;
                                }

                                ob_start();
                                ?>
                                <div class="<?php echo $is_top_row ? 'mainwp-row-top' : 'mainwp-row' ?> mainwp_wp_sync" site_id="<?php echo $website->id; ?>" site_name="<?php echo rawurlencode( $website->name ); ?>">
                                            <div class="mainwp-left mainwp-cols-3 mainwp-padding-top-10">
                                                    <a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a><input type="hidden" id="wp_sync<?php echo $website->id; ?>" />
                                            </div>
                                            <div class="mainwp-left mainwp-cols-3 mainwp-padding-top-10 wordpressInfo" id="wp_sync_<?php echo $website->id; ?>">
                                                    <span><?php echo $lastSyncTime; ?></span>
                                            </div>
                                            <div class="mainwp-right mainwp-cols-4 mainwp-t-align-right mainwp-padding-top-5 wordpressAction">
                                                <?php
                                                if ( $md5Connection ) {
                                                    ?>
                                                    <div style="position: absolute; padding-top: 5px; padding-right: 10px; right: 50px;"><?php _e('MD5 Conncetion'); ?><br /><a href="http://mainwp.com/help/docs/md5-connection-issue/" target="_blank"><?php _e('Read More'); ?></a></div>
                                                    <span class="fa-stack fa-lg" title="MD5 Connection">
                                                      <i class="fa fa-circle fa-stack-2x mainwp-red"></i>
                                                      <i class="fa fa-chain-broken fa-stack-1x mainwp-white"></i>
                                                    </span>
                                                    <?php
                                                } else if ( $hasSyncErrors ) {
                                                        ?>
                                                        <div style="position: absolute; padding-top: 5px; padding-right: 10px; right: 50px;"><a href="#" class="mainwp_rightnow_site_reconnect" siteid="<?php echo $website->id; ?>"><?php _e('Reconnect','mainwp'); ?></a></div>
                                                        <span class="fa-stack fa-lg" title="Disconnected">
                                                                        <i class="fa fa-circle fa-stack-2x mainwp-red"></i>
                                                                        <i class="fa fa-plug fa-stack-1x mainwp-white"></i>
                                                        </span>
                                                        <?php
                                                } else {
                                                        ?>
                                                        <a href="javascript:void(0)" sync-today="<?php echo $website->id; ?>" onClick="rightnow_wp_sync('<?php echo $website->id; ?>')"><?php _e( 'Sync Now', 'mainwp' ); ?></a>&nbsp;&nbsp;
                                                        <span class="fa-stack fa-lg" title="Site is Online">
                                                                <i class="fa fa-check-circle fa-2x mainwp-green"></i>
                                                        </span>
                                                        <?php
                                                }
                                                ?>

                                            </div>
                                            <div class="mainwp-clear"></div>
                                    </div>
                                <?php
                                $output = ob_get_clean();

                                if ( $j == $UP ) {
                                    $top_up_row = false;
                                    $html_online_sites .= $output;
                                } else if (!in_array($website->id, $disconnect_site_ids)) {
                                    $disconnect_site_ids[] = $website->id;
                                    $top_row = false;
                                    $html_other_sites .= $output;
                                }
                            }
                        }

                        
                        $opts           = get_option( 'mainwp_opts_showhide_sections', false );
                        $hide_sites = ( is_array( $opts ) && isset( $opts['synced_sites'] ) && $opts['synced_sites'] == 'hide' ) ? true : false;
                    ?>
                    <div class="mainwp-postbox-actions-top mainwp-padding-10">
                        <span class="mainwp-right">
                                <a id="mainwp-link-showhide-synced-sites" status="<?php echo( $hide_sites ? 'hide' : 'show' ); ?>" href="#">
                                    <i class="fa fa-eye-slash" aria-hidden="true"></i> <?php echo( $hide_sites ? __( 'Show online sites', 'mainwp' ) : __( 'Hide online sites', 'mainwp' ) ); ?>
                                </a>
                        </span>
                        <div class="mainwp-clear"></div>
                    </div>
                    <div id="mainwp-synced-status-sites-wrap" style="<?php echo( $hide_sites ? 'display: none;' : '' ); ?>">
                        <?php echo $html_online_sites; ?>
                    </div>
                         <?php echo $html_other_sites; ?>
                </div>
		<?php
		@MainWP_DB::free_result( $websites );
	}
}
