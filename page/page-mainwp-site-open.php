<?php

class MainWP_Site_Open {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function render() {
		if ( ! mainwp_current_user_can( 'dashboard', 'access_wpadmin_on_child_sites' ) ) {
			mainwp_do_not_have_permissions( __( 'WP-Admin on child sites', 'mainwp' ) );

			return;
		}
		if ( ! isset( $_GET['websiteid'] ) ) {
			exit();
		}

		$id      = $_GET['websiteid'];
		$website = MainWP_DB::Instance()->getWebsiteById( $id );

		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			exit();
		}

		$location = '';
		if ( isset( $_GET['location'] ) ) {
			$location = base64_decode( $_GET['location'] );
		}
                
                if ( isset( $_GET['openUrl']) && $_GET['openUrl'] == 'yes' ) {
                    MainWP_Site_Open::openSiteLocation( $website, $location );
                } else {
                    MainWP_Site_Open::openSite( $website, $location, ( isset( $_GET['newWindow'] ) ? $_GET['newWindow'] : null ) );
                }
	}

	public static function openSite( $website, $location, $pNewWindow = null ) {
		?>
		<div class="wrap">
			<a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img src="<?php echo plugins_url( 'images/logo.png', dirname( __FILE__ ) ); ?>" height="50" alt="MainWP"/></a>

			<h2><i class="fa fa-globe"></i> <?php echo stripslashes( $website->name ); ?></h2>

			<div style="clear: both;"></div>
			<br/>

			<div id="mainwp_background-box">
				<?php
				if ( $pNewWindow == 'yes' ) {
					?>
					<div style="font-size: 30px; text-align: center; margin-top: 5em;"><?php _e( 'You will be redirected to your website immediately.', 'mainwp' ); ?></div>
					<form method="POST" action="<?php echo MainWP_Utility::getGetDataAuthed( $website, ( $location == null || $location == '' ) ? 'index.php' : $location ); ?>" id="redirectForm">
					</form>
					<?php
				} else {
					?>
					<div style="padding-top: 10px; padding-bottom: 10px">
                                            <?php
                                            if (isset($_GET['from']) && $_GET['from'] == 'user') { ?>
                                                <a href="<?php echo admin_url( 'admin.php?page=UserBulkManage' ); ?>" class="mainwp-backlink">← <?php _e( 'Back to users', 'mainwp' ); ?></a>&nbsp;&nbsp;&nbsp;
                                            <?php } else { ?>
						<a href="<?php echo admin_url( 'admin.php?page=managesites' ); ?>" class="mainwp-backlink">← <?php _e( 'Back to sites', 'mainwp' ); ?></a>&nbsp;&nbsp;&nbsp;
                                                <?php
                                            }
                                            ?>
						<input type="button" class="button cont" id="mainwp_notes_show" value="<?php _e( 'Notes', 'mainwp' ); ?>"/>
					</div>
					<iframe width="100%" height="1000"
						src="<?php echo MainWP_Utility::getGetDataAuthed( $website, ( $location == null || $location == '' ) ? 'index.php' : $location ); ?>"></iframe>
					<div id="mainwp_notes_overlay" class="mainwp_overlay"></div>
					<div id="mainwp_notes" class="mainwp_popup">
						<a id="mainwp_notes_closeX" class="mainwp_closeX" style="display: inline; "></a>

						<div id="mainwp_notes_title" class="mainwp_popup_title"><?php echo $website->url; ?></span>
						</div>
						<div id="mainwp_notes_content">
                                                    <div id="mainwp_notes_html" style="width: 580px !important; height: 300px;"><?php echo $website->note; ?></div>
                                                    <textarea style="width: 580px !important; height: 300px;"
                                                            id="mainwp_notes_note"><?php echo $website->note; ?></textarea>
						</div>
                                                <div><em><?php _e( 'Allowed HTML Tags:','mainwp' ); ?> &lt;p&gt;, &lt;strong&gt;, &lt;em&gt;, &lt;br&gt;, &lt;hr&gt;, &lt;a&gt;, &lt;ul&gt;, &lt;ol&gt;, &lt;li&gt;, &lt;h1&gt;, &lt;h2&gt; </em></div><br/>
						<form>
							<div style="float: right" id="mainwp_notes_status"></div>
							<input type="button" class="button button-primary" id="mainwp_notes_save" value="<?php esc_attr_e( 'Save note', 'mainwp' ); ?>"/>
                                                        <input type="button" class="button cont" id="mainwp_notes_edit" value="<?php esc_attr_e( 'Edit','mainwp' ); ?>"/>                
                                                        <input type="button" class="button cont" id="mainwp_notes_view" value="<?php esc_attr_e( 'View','mainwp' ); ?>"/>                
							<input type="button" class="button cont" id="mainwp_notes_cancel" value="Close"/>
							<input type="hidden" id="mainwp_notes_websiteid"
								value="<?php echo $website->id; ?>"/>
						</form>
					</div>
				<?php } ?>
			</div>
		</div>
		<?php
	}

	public static function renderRestore() {
		if ( ! isset( $_GET['websiteid'] ) ) {
			exit();
		}

		$id      = $_GET['websiteid'];
		$website = MainWP_DB::Instance()->getWebsiteById( $id );

		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			exit();
		}

		$file = '';
		if ( isset( $_GET['f'] ) ) {
			$file = base64_decode( esc_attr( esc_html( $_GET['f'] ) ) );
		}

		MainWP_Site_Open::openSiteRestore( $website, $file, esc_attr( esc_html( $_GET['size'] ) ) );
	}

	public static function openSiteRestore( $website, $file, $size ) {
		?>
		<div class="wrap">
			<a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img src="<?php echo plugins_url( 'images/logo.png', dirname( __FILE__ ) ); ?>" height="50" alt="MainWP"/></a>

			<h2><i class="fa fa-globe"></i> <?php echo stripslashes( $website->name ); ?></h2>

			<div style="clear: both;"></div>
			<br/>

			<div id="mainwp_background-box">
				<?php
                                
				_e( 'Will redirect to your website immediately.', 'mainwp' );
				$url = ( isset( $website->url ) && $website->url != '' ? $website->url : $website->siteurl );
				$url .= ( substr( $url, - 1 ) != '/' ? '/' : '' );

				$postdata         = MainWP_Utility::getGetDataAuthed( $website, $file, MainWP_Utility::getFileParameter( $website ), true );
				$postdata['size'] = $size;
				?>
				<form method="POST" action="<?php echo $url; ?>" id="redirectForm">
					<?php
					foreach ( $postdata as $name => $value ) {
						echo '<input type="hidden" name="' . $name . '" value="' . $value . '" />';
					}
					?>
				</form>
			</div>
		</div>
		<?php
	}
        
        public static function openSiteLocation( $website, $open_location ) {
		?>
		<div class="wrap">
			<a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img src="<?php echo plugins_url( 'images/logo.png', dirname( __FILE__ ) ); ?>" height="50" alt="MainWP"/></a>

			<h2><i class="fa fa-globe"></i> <?php echo stripslashes( $website->name ); ?></h2>

			<div style="clear: both;"></div>
			<br/>

			<div id="mainwp_background-box">
                                <div style="font-size: 30px; text-align: center; margin-top: 5em;"><?php _e( 'You will be redirected to your website immediately.', 'mainwp' ); ?></div>
				<?php				
				$url = ( isset( $website->url ) && $website->url != '' ? $website->url : $website->siteurl );
				$url .= ( substr( $url, - 1 ) != '/' ? '/' : '' );

				$postdata         = MainWP_Utility::getGetDataAuthed( $website, 'index.php', 'where', true );
				$postdata['open_location'] = base64_encode($open_location);
				?>
				<form method="POST" action="<?php echo $url; ?>" id="redirectForm">
					<?php
					foreach ( $postdata as $name => $value ) {
						echo '<input type="hidden" name="' . $name . '" value="' . $value . '" />';
					}
					?>
				</form>
			</div>
		</div>
		<?php
	}
        
}
