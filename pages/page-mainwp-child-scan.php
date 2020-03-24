<?php
namespace MainWP\Dashboard;

/**
 * MainWP Child Scan
 */
class MainWP_Child_Scan {

	public static function get_class_name() {
		return __CLASS__;
	}

	public static function init_menu() {
		add_submenu_page( 'mainwp_tab', __( 'MainWP Child Scan', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'MainWP Child Scan', 'mainwp' ) . '</div>', 'read', 'MainWP_Child_Scan', array( self::get_class_name(), 'render' ) );
	}

	public static function render_header( $shownPage = '' ) {

		$params = array(
			'title' => __( 'Child Scan', 'mainwp' ),
		);
		MainWP_UI::render_top_header($params);
		?>
			<div class="wrap">
				<div id="mainwp_wrap-inside">
					<?php
	}

	public static function render_footer( $shownPage ) {
		?>
				</div>
			</div>
			<?php
	}

	public static function render() {

		self::render_header( '' );
		?>
			<a class="button-primary mwp-child-scan" href="#"><?php esc_html_e( 'Scan', 'mainwp' ); ?></a>
			<?php
			$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
			if ( ! $websites ) {
				esc_html_e( '<p>No websites to scan.</p>', 'mainwp' );
			} else {
				?>
				<table id="mwp_child_scan_childsites">
					<tr><th>Child</th><th>Status</th></tr>
					<?php
					while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
						$imgfavi = '';
						if ( $website !== null ) {
							if ( get_option( 'mainwp_use_favicon', 1 ) == 1 ) {
								$favi_url = MainWP_Utility::get_favico_url( $website );
								$imgfavi  = '<img src="' . $favi_url . '" width="16" height="16" style="vertical-align:middle;"/>&nbsp;';
							}
						}

						if ( $website->sync_errors == '' ) {
							echo '<tr siteid="' . intval($website->id) . '"><td title="' . $website->url . '">' . $imgfavi . ' ' . stripslashes( $website->name ) . ':</td><td></td></tr>';
						} else {
							echo '<tr><td title="' . $website->url . '">' . $imgfavi . ' ' . stripslashes( $website->name ) . ':</td><td>Sync errors</td></tr>';
						}
					}
					MainWP_DB::free_result( $websites );
					?>
				</table>
				<?php
			}
			?>
			<?php
			self::render_footer( '' );
	}

	public static function scan() {
		if ( ! isset( $_POST['childId'] ) ) {
			die( wp_json_encode( array( 'error' => 'Wrong request' ) ) );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $_POST['childId'] );
		if ( ! $website ) {
			die( wp_json_encode( array( 'error' => 'Site not found' ) ) );
		}

		try {
			$post_data = array(
				'search'         => 'mainwp-child-id-*',
				'search_columns' => 'user_login,display_name,user_email',
			);

			$rslt       = MainWP_Utility::fetch_url_authed( $website, 'search_users', $post_data );
			$usersfound = ! ( is_array( $rslt ) && count( $rslt ) == 0 );

			if ( ! $usersfound ) {
				// fallback to plugin search
				$post_data = array(
					'keyword' => 'WordPress admin security',
				);

				$post_data['status'] = 'active';
				$post_data['filter'] = true;

				$rslt = MainWP_Utility::fetch_url_authed( $website, 'get_all_plugins', $post_data );

				$pluginfound = ! ( is_array( $rslt ) && count( $rslt ) == 0 );

				if ( ! $pluginfound ) {
					die( wp_json_encode( array( 'success' => 'No issues found!' ) ) );
				}
			}

			die( wp_json_encode( array( 'success' => 'mainwp-child-id users found' ) ) );
		} catch ( Exception $e ) {
			die( 'error' );
		}
	}

}
