<?php
/**
 * MainWP Connect Helper.
 *
 * MainWP Connect Helper functions.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Connect_Helper
 *
 * @package MainWP\Dashboard
 */
class MainWP_Connect_Helper {

	/**
	 * Private static variable to hold the single instance of the class.
	 *
	 * @static
	 *
	 * @var mixed Default null
	 */
	private static $instance = null;


	/**
	 * Method instance()
	 *
	 * Create a public static instance.
	 *
	 * @static
	 * @return MainWP_Connect_Helper
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object Class name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

		/**
		 * Render renew connections modal box.
		 */
	public static function render_renew_connections_modal() {
		?>
		<div class="ui small modal" id="mainwp-tool-renew-connect-modal">
			<i class="close icon mainwp-modal-close"></i>
			<div class="header"><?php esc_html_e( 'Force your MainWP Dashboard to set a new pair of OpenSSL Keys', 'mainwp' ); ?></div>
			<div class="ui green progress mainwp-modal-progress" style="display:none;">
				<div class="bar"><div class="progress"></div></div>
				<div class="label"></div>
			</div>
			<div class="scrolling content mainwp-modal-content">
				<div class="ui message" id="mainwp-message-zone-modal" style="display:none;"></div>
				<div class="ui middle aligned divided selection list" id="mainwp-renew-connections-list"></div>
				<div class="mainwp-select-sites-wrapper">
					<div class="mainwp-select-sites ui fluid accordion mainwp-sidebar-accordion">
						<div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
							<div class="content active">
							<?php
							$sel_params = array(
								'show_group'           => false,
								'enable_offline_sites' => true,
								'show_select_all_disconnect' => true,
								'show_create_tag'      => false,
							);
							MainWP_UI_Select_Sites::select_sites_box( $sel_params );
							?>
							</div>
					</div>
				</div>
			</div>
			<div class="actions mainwp-modal-actions">
				<button class="ui green left floated button" onclick="mainwp_tool_prepare_renew_connections(this); return false;"><?php esc_html_e( 'Reset OpenSSL Key Pair', 'mainwp' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Method ajax_prepare_renew_connections()
	 */
	public function ajax_prepare_renew_connections() {
		MainWP_Post_Handler::instance()->secure_request( 'mainwp_prepare_renew_connections' );
		$sites = isset( $_POST['sites'] ) && is_array( $_POST['sites'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['sites'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$data_fields = MainWP_System_Utility::get_default_map_site_fields();

		$dbwebsites = array();
		foreach ( $sites as $k => $v ) {
			if ( MainWP_Utility::ctype_digit( $v ) ) {
				$website = MainWP_DB::instance()->get_website_by_id( $v );
				if ( $website ) {
					$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
				}
			}
		}

		if ( empty( $dbwebsites ) ) {
			wp_send_json( array( 'error' => esc_html__( 'Site not found. Please try again.' ) ) );
			return;
		}

		ob_start();
		foreach ( $dbwebsites as $site ) {
			$site_name   = $site->name;
			$is_sync_err = ( '' !== $site->sync_errors ) ? true : false;
			?>
			<div class="item <?php echo $is_sync_err ? 'disconnected-site' : ''; ?>" status="queue">
				<div class="right floated content">
					<div class="renew-site-status" niceurl="<?php echo esc_html( $site_name ); ?>" siteid="<?php echo intval( $site->id ); ?>"><span data-position="left center" data-inverted="" data-tooltip="<?php echo $is_sync_err ? esc_html__( 'Site disconnected', 'mainwp' ) : esc_html__( 'Pending', 'mainwp' ); ?>"><i class="<?php echo $is_sync_err ? 'exclamation red icon' : 'clock outline icon'; ?>"></i></span></div>
				</div>
				<div class="content">
				<?php echo esc_html( $site_name ); ?>
				</div>
			</div>
			<?php
		}
		$output = ob_get_clean();
		wp_send_json( array( 'result' => $output ) );
	}


	/**
	 * Method ajax_renew_connections()
	 */
	public function ajax_renew_connections() {

		MainWP_Post_Handler::instance()->secure_request( 'mainwp_renew_connections' );

		$site_id = isset( $_POST['siteid'] ) ? intval( $_POST['siteid'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$website = false;

		if ( ! empty( $site_id ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		}

		if ( empty( $website ) ) {
			wp_send_json( array( 'error' => esc_html__( 'Site not found.', 'mainwp' ) ) );
		}

		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			wp_send_json( array( 'error' => 'You can not edit this website.' ) );
		}

		try {
			// if disconnected, try to reconnect.
			if ( '' !== $website->sync_errors ) {
				// try reconnect, if failed.
				MainWP_Sync::sync_site( $website, true );
			}
			$data = MainWP_Connect::fetch_url_authed( $website, 'renew' ); // to disconnect,
			// disconnect success.
			if ( is_array( $data ) && isset( $data['result'] ) && 'success' === $data['result'] ) {
				// reconnect immediately, to renew.
				if ( MainWP_Manage_Sites_View::m_reconnect_site( $website, false ) ) {
					MainWP_Logger::instance()->info_for_website( $website, 'renew', 'Renew connection successfully.' );
					wp_send_json( array( 'result' => 'success' ) );
				} else {
					wp_send_json( array( 'error' => esc_html__( 'Try to reconnect site failed. Please try again.', 'mainwp' ) ) );
				}
			} else {
				MainWP_Logger::instance()->info_for_website( $website, 'renew', 'Try to disconnect site failed.' );
			}

			if ( is_array( $data ) && isset( $data['error'] ) ) {
				wp_send_json( array( 'error' => esc_html( wp_strip_all_tags( $data['error'] ) ) ) );
			} else {
				wp_send_json( array( 'error' => esc_html__( 'Try to disconnect site failed. Please try again.', 'mainwp' ) ) );
			}
		} catch ( MainWP_Exception $e ) {
			$error = MainWP_Error_Helper::get_error_message( $e );
			wp_send_json( array( 'error' => esc_html( wp_strip_all_tags( $error ) ) ) );
		}
		wp_send_json( array( 'error' => esc_html__( 'Undefined error. Please try again.', 'mainwp' ) ) );
	}
}
