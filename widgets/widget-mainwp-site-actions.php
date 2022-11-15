<?php
/**
 * MainWP  Site Actions Widget
 *
 * Displays the Site Actions Info.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Site_Actions
 *
 * Displays the Site Actions.
 */
class MainWP_Site_Actions {

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
		$current_wpid = MainWP_System_Utility::get_current_wpid();
		$website      = null;
		if ( $current_wpid ) {
			$params  = array(
				'wpid'        => $current_wpid,
				'where_extra' => ' AND dismiss = 0 ',
			);
			$website = MainWP_DB::instance()->get_website_by_id( $current_wpid );
		} else {
			$limit  = apply_filters( 'mainwp_widget_site_actions_limit_number', 10000 );
			$params = array(
				'limit'       => $limit,
				'where_extra' => ' AND dismiss = 0 ',
			);
		}
		$actions_info = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params );
		self::render_info( $actions_info, $website );
	}

	/**
	 * Render Sites actions Info.
	 *
	 * @param object $actions_info Sites actions info.
	 * @param object $website Sites info.
	 */
	private static function render_info( $actions_info, $website ) {

		if ( ! is_array( $actions_info ) ) {
			$actions_info = array();
		}
		?>
			<h3 class="ui header handle-drag">
			<?php
			/**
			 * Filter: mainwp_non_mainwp_changes_widget_title
			 *
			 * Filters the Site info widget title text.
			 *
			 * @param object $website Object containing the child site info.
			 *
			 * @since 4.1
			 */
			echo esc_html( apply_filters( 'mainwp_non_mainwp_changes_widget_title', __( 'Non-MainWP Changes', 'mainwp' ), $website ) );
			?>
				<div class="sub header"><?php esc_html_e( 'The most recent Non-MainWP plugin and theme changes. Sync to get latest info.', 'mainwp' ); ?></div>
			</h3>
			<div class="ui section hidden divider"></div>
			<div class="mainwp-widget-site-info">
				<?php
				/**
				 * Actoin: mainwp_non_mainwp_changes_widget_top
				 *
				 * Fires at the top of the Site Info widget on the Individual site overview page.
				 *
				 * @param object $website Object containing the child site info.
				 *
				 * @since 4.0
				 */
				do_action( 'mainwp_non_mainwp_changes_widget_top', $website );
				?>
				<?php
				if ( $actions_info ) {
					?>
				<table class="ui table" id="mainwp-non-mainwp-changes-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Change', 'mainwp' ); ?></th>
							<?php if ( empty( $website ) ) : ?>
							<th class="center aligned"><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
							<?php endif; ?>
							<th class="center aligned"><?php esc_html_e( 'User', 'mainwp' ); ?></th>
							<th class="no-sort"></th>
						</tr>
					</thead>
					<tbody>
					<?php
					/**
					 * Action: mainwp_non_mainwp_changes_table_top
					 *
					 * Fires at the top of the Site Info table in Site Info widget on the Individual site overview page.
					 *
					 * @param object $website Object containing the child site info.
					 *
					 * @since 4.0
					 */
					do_action( 'mainwp_non_mainwp_changes_table_top', $website );
					?>
					<?php
					foreach ( $actions_info as $idx => $data ) {
						if ( empty( $data->action_user ) ) {
							continue;
						}
						$meta_data = json_decode( $data->meta_data );
						?>
						<tr>
							<td data-order="<?php echo esc_attr( $data->created ); ?>">
								<strong><?php echo isset( $meta_data->name ) && '' != $meta_data->name ? esc_html( $meta_data->name ) : 'WP Core'; ?></strong> <?php echo 'WordPress' != $data->context ? esc_html( ucfirst( rtrim( $data->context, 's' ) ) ) : 'WordPress'; ?><br/>
								<?php echo esc_html( ucfirst( $data->action ) ); ?><br/>
								<em><?php esc_html_e( 'On: ', 'mainwp' ); ?><?php echo esc_html( MainWP_Utility::format_timestamp( $data->created ) ); ?></em>
							</td>
							<?php
							if ( empty( $website ) ) {
								$site = MainWP_DB::instance()->get_website_by_id( $data->wpid );
								?>
								<td class="center aligned"><a href="admin.php?page=managesites&dashboard=<?php echo esc_attr( $site->id ); ?>"><?php echo esc_html( $site->name ); ?></a></td>
								<?php
							}
							?>
							<td class="center aligned"><?php echo esc_html( $data->action_user ); ?></td>
							<td class="center aligned">
								<a href="javascript:void(0)" class="mainwp-action-dismiss ui mini icon button" action-id="<?php echo intval( $data->action_id ); ?>" data-tooltip="<?php esc_attr_e( 'Dismiss the notice.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><i class="times icon"></i></a>
							</td>
						</tr>
						<?php
					}
					?>
					<?php
					/**
					 * Action: mainwp_non_mainwp_changes_table_bottom
					 *
					 * Fires at the bottom of the Site Info table in Site Info widget on the Individual site overview page.
					 *
					 * @param object $website Object containing the child site info.
					 *
					 * @since 4.0
					 */
					do_action( 'mainwp_non_mainwp_changes_table_bottom', $website );
					?>
					</tbody>
				</table>
				<script type="text/javascript">
				jQuery( document ).ready( function() {
					jQuery( '#mainwp-non-mainwp-changes-table' ).DataTable( {
						"lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
						"stateSave" : true,
						"columnDefs": [ {
							"targets": 'no-sort',
							"orderable": false
						} ],
					} );
				} );
				</script>
				<?php } else { ?>
				<h2 class="ui icon header">
					<i class="info circle icon"></i>
					<div class="content">
						<?php esc_html_e( 'No changes detected!', 'mainwp' ); ?>
						<div class="sub header"><?php esc_html_e( 'Sync to get the latest information about plugin and theme changes made directly on the child site.', 'mainwp' ); ?></div>
					</div>
				</h2>
			<?php } ?>
				<?php
				/**
				 * Action: mainwp_non_mainwp_changes_widget_bottom
				 *
				 * Fires at the bottom of the Site Info widget on the Individual site overview page.
				 *
				 * @param object $website Object containing the child site info.
				 *
				 * @since 4.0
				 */
				do_action( 'mainwp_non_mainwp_changes_widget_bottom', $website );
				?>
			</div>
			<?php
	}

}
