<?php
/**
 * MainWP Site Info Widget
 *
 * Build the Child Site Info Widget.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Site_Info
 *
 * Grab Child Site info and build widget.
 */
class MainWP_Site_Info {

	/**
	 * Method get_class_name()
	 *
	 * @return string __CLASS__ Class name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method render()
	 *
	 * @return mixed render_site_info()
	 */
	public static function render() {
		self::render_site_info();
	}

	/**
	 * Method render_site_info()
	 *
	 * Grab Child Site Info and render.
	 */
	public static function render_site_info() {
		$current_wpid = MainWP_System_Utility::get_current_wpid();
		if ( empty( $current_wpid ) ) {
			return;
		}

		$website = MainWP_DB::instance()->get_website_by_id( $current_wpid, true );

		$website_info = json_decode( MainWP_DB::instance()->get_website_option( $website, 'site_info' ), true );

		if ( is_array( $website_info ) ) {
			$code        = $website->http_response_code;
			$code_string = MainWP_Utility::get_http_codes( $code );
			if ( ! empty( $code_string ) ) {
				$code .= ' - ' . $code_string;
			}
			$website_info['last_status'] = $code;
		}

		$child_site_info = array(
			'wpversion'     => __( 'WordPress Version', 'mainwp' ),
			'debug_mode'    => __( 'Debug Mode', 'mainwp' ),
			'phpversion'    => __( 'PHP Version', 'mainwp' ),
			'child_version' => __( 'MainWP Child Version', 'mainwp' ),
			'memory_limit'  => __( 'PHP Memory Limit', 'mainwp' ),
			'mysql_version' => __( 'MySQL Version', 'mainwp' ),
			'ip'            => __( 'Server IP', 'mainwp' ),
			'group'         => __( 'Groups', 'mainwp' ),
			'last_status'   => __( 'Last Check Status', 'mainwp' ),
		);

		/**
		 * Filter: mainwp_child_site_info_widget_content
		 *
		 * Filters the Child Info array for the Site Info widget.
		 *
		 * @since 4.1
		 */
		$child_site_info = apply_filters( 'mainwp_child_site_info_widget_content', $child_site_info );

		self::render_info( $website, $website_info, $child_site_info );
	}

	/**
	 * Render Sites Info.
	 *
	 * @param object $website Object containing the child site info.
	 * @param array  $website_info Website data.
	 * @param array  $child_site_info Website info to display.
	 */
	public static function render_info( $website, $website_info, $child_site_info ) {
		?>
		<h3 class="ui header handle-drag">
			<?php
			/**
			 * Filter: mainwp_site_info_widget_title
			 *
			 * Filters the Site info widget title text.
			 *
			 * @param object $website Object containing the child site info.
			 *
			 * @since 4.1
			 */
			echo esc_html( apply_filters( 'mainwp_site_info_widget_title', __( 'Site Info', 'mainwp' ), $website ) );
			?>
			<div class="sub header"><?php esc_html_e( 'Basic child site system information', 'mainwp' ); ?></div>
		</h3>

		<div class="ui section hidden divider"></div>

		<div class="mainwp-widget-site-info">
			<?php
			/**
			 * Actoin: mainwp_site_info_widget_top
			 *
			 * Fires at the top of the Site Info widget on the Individual site overview page.
			 *
			 * @param object $website Object containing the child site info.
			 *
			 * @since 4.0
			 */
			do_action( 'mainwp_site_info_widget_top', $website );
			?>
			<?php
			if ( ! is_array( $website_info ) || ! isset( $website_info['wpversion'] ) ) {
				?>
				<h2 class="ui icon header">
					<i class="info circle icon"></i>
					<div class="content">
						<?php esc_html_e( 'No info found!', 'mainwp' ); ?>
						<div class="sub header"><?php esc_html_e( 'Site information could not be found. Make sure your site is properly connected and syncs correctly.', 'mainwp' ); ?></div>
					</div>
				</h2>
				<?php
			} else {

				$website_info['group'] = ( '' == $website->wpgroups ? 'None' : $website->wpgroups );

				?>
			<table class="ui celled striped table">
				<tbody>
				<?php
				/**
				 * Action: mainwp_site_info_table_top
				 *
				 * Fires at the top of the Site Info table in Site Info widget on the Individual site overview page.
				 *
				 * @param object $website Object containing the child site info.
				 *
				 * @since 4.0
				 */
				do_action( 'mainwp_site_info_table_top', $website );
				?>
				<?php
				foreach ( $child_site_info as $index => $title ) {
					$val = '';
					if ( isset( $website_info[ $index ] ) ) {
						if ( 'debug_mode' == $index ) {
							$val = 1 == $website_info[ $index ] ? 'Enabled' : 'Disabled';
						} else {
							$val = $website_info[ $index ];
						}
					}
					?>
						<tr>
						<td><?php echo esc_html( $title ); ?></td>
						<td><?php echo esc_html( $val ); ?></td>
						</tr>
				<?php } ?>
				<?php
				/**
				 * Action: mainwp_site_info_table_bottom
				 *
				 * Fires at the bottom of the Site Info table in Site Info widget on the Individual site overview page.
				 *
				 * @param object $website Object containing the child site info.
				 *
				 * @since 4.0
				 */
				do_action( 'mainwp_site_info_table_bottom', $website );
				?>
				</tbody>
			</table>
			<?php } ?>
			<?php
			/**
			 * Action: mainwp_site_info_widget_bottom
			 *
			 * Fires at the bottom of the Site Info widget on the Individual site overview page.
			 *
			 * @param object $website Object containing the child site info.
			 *
			 * @since 4.0
			 */
			do_action( 'mainwp_site_info_widget_bottom', $website );
			?>
		</div>
		<?php
	}

}
