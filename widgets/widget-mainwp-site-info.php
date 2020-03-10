<?php

class MainWP_Site_Info {

	public static function getClassName() {
		return __CLASS__;
	}

	public static function render() {
		self::renderSiteInfo();
	}

	public static function renderSiteInfo() {
		$current_wpid = MainWP_Utility::get_current_wpid();
		if ( empty( $current_wpid ) ) {
			return;
		}

		$sql = MainWP_DB::Instance()->getSQLWebsiteById( $current_wpid, true );

		$websites = MainWP_DB::Instance()->query( $sql );
		if ( empty( $websites ) ) {
			return;
		}

		$website = MainWP_DB::fetch_object( $websites );

		$website_info = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'site_info' ), true );

		$child_site_info = array(
			'wpversion'             => __( 'WordPress Version', 'mainwp' ),
			'debug_mode'            => __( 'Debug Mode', 'mainwp' ),
			'phpversion'            => __( 'PHP Version', 'mainwp' ),
			'child_version'         => __( 'MainWP Child Version', 'mainwp' ),
			'memory_limit'          => __( 'PHP Memory Limit', 'mainwp' ),
			'mysql_version'         => __( 'MySQL Version', 'mainwp' ),
			'ip'                    => __( 'Server IP', 'mainwp' ),
			'group'                 => __( 'Groups', 'mainwp' ),
		);
		?>

		<h3 class="ui header handle-drag">
			<?php esc_html_e('Site Info', 'mainwp'); ?>
			<div class="sub header"><?php esc_html_e( 'Basic child site system information', 'mainwp' ); ?></div>
		</h3>

		<div class="ui section hidden divider"></div>

		<div class="mainwp-widget-site-info">
			<?php do_action( 'mainwp_site_info_widget_top'); ?>
			<?php
			if ( ! is_array( $website_info ) || ! isset( $website_info['wpversion'] ) ) {
				?>
				<h2 class="ui icon header">
					<i class="info circle icon"></i>
					<div class="content">
						<?php _e( 'No info found!', 'mainwp' ); ?>
						<div class="sub header"><?php esc_html_e( 'Site information could not be found. Make sure your site is properly connected and syncs correctly.', 'mainwp' ); ?></div>
					</div>
				</h2>
				<?php
			} else {

				$website_info['group'] = ( $website->wpgroups == '' ? 'None' : $website->wpgroups );

				?>
			<table class="ui celled striped table">
				<tbody>
				<?php do_action( 'mainwp_site_info_table_top'); ?>
				<?php
				foreach ( $child_site_info as $index => $title ) {
						$val = '';
					if ( isset( $website_info[ $index ] ) ) {
						if ( $index == 'debug_mode' ) {
							$val = $website_info[ $index ] == 1 ? 'Enabled' : 'Disabled';
						} else {
							$val = $website_info[ $index ];
						}
					}
					?>
						<tr>
						  <td><?php echo esc_html( $title ); ?></td>
						  <td><?php echo esc_html($val); ?></td>
						</tr>
				<?php } ?>
				<?php do_action( 'mainwp_site_info_table_bottom'); ?>
				</tbody>
			</table>
			<?php } ?>
			<?php do_action( 'mainwp_site_info_widget_bottom'); ?>
		</div>
		<?php
		MainWP_DB::free_result( $websites );
	}

}
