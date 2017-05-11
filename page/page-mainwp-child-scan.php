<?php

class MainWP_Child_Scan {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function initMenu() {
		add_submenu_page( 'mainwp_tab', __( 'MainWP Child Scan','mainwp' ), '<div class="mainwp-hidden">' .  __( 'MainWP Child Scan','mainwp' ) . '</div>', 'read', 'MainWP_Child_Scan', array( MainWP_Child_Scan::getClassName(), 'render' ) );
	}

	public static function renderHeader( $shownPage ) {
        MainWP_UI::render_left_menu();
		?>
		<div class="mainwp-wrap">
			<a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank">
				<img src="<?php echo plugins_url( 'images/logo.png', dirname( __FILE__ ) ); ?>" height="50" alt="MainWP"/>
			</a>
			<h2><i class="fa fa-server"></i> <?php _e( 'Child Scan','mainwp' ); ?></h2>
			<div style="clear: both;"></div>
			<br/>

			<div class="clear"></div>
			<div class="wrap">
				<div id="mainwp_wrap-inside">
		<?php
	}

	public static function renderFooter( $shownPage ) {

		?>
				</div>
			</div>
		<?php
	}

	public static function render() {

		self::renderHeader( '' );
		?>
        <a class="button-primary mwp-child-scan" href="#"><?php _e( 'Scan', 'mainwp' ); ?></a>
        <?php
		$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
		if ( ! $websites ) {
			echo __( '<p>No websites to scan.</p>','mainwp' );
		} else {
			?>
			<table id="mwp_child_scan_childsites">
				<tr><th>Child</th><th>Status</th></tr>
			<?php
			while ( $websites && ($website = @MainWP_DB::fetch_object( $websites )) ) {
				$imgfavi = '';
				if ( $website !== null ) {
					if ( get_option( 'mainwp_use_favicon', 1 ) == 1 ) {
						$favi_url = MainWP_Utility::get_favico_url( $website );
						$imgfavi = '<img src="' . $favi_url . '" width="16" height="16" style="vertical-align:middle;"/>&nbsp;';
					}
				}

				if ( $website->sync_errors == '' ) {
					echo '<tr siteid="' . $website->id . '"><td title="' . $website->url . '">' . $imgfavi .  ' ' . stripslashes( $website->name ) . ':</td><td></td></tr>';
				} else {
					echo '<tr><td title="' . $website->url . '">' . $imgfavi . ' ' . stripslashes( $website->name ) . ':</td><td>Sync errors</td></tr>';
				}
			}
				@MainWP_DB::free_result( $websites );
			?>
			</table>
			<?php
		}
			?>
    <?php
	  self::renderFooter( '' );
	}

	public static function scan() {
		if ( ! isset( $_POST['childId'] ) ) {die( json_encode( array( 'error' => 'Wrong request' ) ) );}

		$website = MainWP_DB::Instance()->getWebsiteById( $_POST['childId'] );
		if ( ! $website ) {die( json_encode( array( 'error' => 'Site not found' ) ) );}

		try {
			$post_data = array(
				'search' => 'mainwp-child-id-*',
				'search_columns' => 'user_login,display_name,user_email',
			);

			$rslt = MainWP_Utility::fetchUrlAuthed( $website, 'search_users', $post_data );
			$usersfound = ! (is_array( $rslt ) && count( $rslt ) == 0);

			if ( ! $usersfound ) {
				//fallback to plugin search
				$post_data = array(
					'keyword' => 'WordPress admin security',
				);

				$post_data['status'] = 'active';
				$post_data['filter'] = true;

				$rslt = MainWP_Utility::fetchUrlAuthed( $website, 'get_all_plugins', $post_data );

				$pluginfound = ! (is_array( $rslt ) && count( $rslt ) == 0);

				if ( ! $pluginfound ) {
					die( json_encode( array( 'success' => 'No issues found!' ) ) );
				}
			}

			die( json_encode( array( 'success' => 'mainwp-child-id users found (<a href="http://docs.mainwp.com/mainwp-cleanup/" target="_blank">solution</a>)' ) ) );
		} catch (Exception $e) {
			die( 'error' );
		}
	}
}
