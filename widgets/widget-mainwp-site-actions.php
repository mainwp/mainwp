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
		$actions_info = array();
		if ( $current_wpid ) {
			$params       = array(
				'wpid'        => $current_wpid,
				'where_extra' => ' AND dismiss = 0 ',
			);
			$website      = MainWP_DB::instance()->get_website_by_id( $current_wpid );
			$actions_info = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params );
		} elseif ( isset( $_GET['client_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$client_id = isset( $_GET['client_id'] ) ? intval( $_GET['client_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$websites  = MainWP_DB_Client::instance()->get_websites_by_client_ids( $client_id );
			$site_ids  = array();

			foreach ( $websites as $website ) {
				$site_ids[] = $website->id;
			}

			if ( ! empty( $site_ids ) ) {
				$limit        = apply_filters( 'mainwp_widget_site_actions_limit_number', 10000 );
				$params       = array(
					'limit'       => $limit,
					'where_extra' => ' AND dismiss = 0 ',
					'wpid'        => $site_ids,
				);
				$actions_info = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params );
			}
		} else {
			$limit        = apply_filters( 'mainwp_widget_site_actions_limit_number', 10000 );
			$params       = array(
				'limit'       => $limit,
				'where_extra' => ' AND dismiss = 0 ',
			);
			$actions_info = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params );
		}
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

		<div class="mainwp-widget-header">
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
			echo esc_html( apply_filters( 'mainwp_non_mainwp_changes_widget_title', esc_html__( 'Non-MainWP Changes', 'mainwp' ), $website ) );
			?>
				<div class="sub header"><?php esc_html_e( 'The most recent Non-MainWP plugin and theme changes. Sync to get latest info.', 'mainwp' ); ?></div>
			</h3>
		</div>

		<div id="mainwp-widget-site-actions" class="mainwp-scrolly-overflow">
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
			<?php if ( $actions_info ) : ?>
				<table class="ui table" id="mainwp-non-mainwp-changes-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Change', 'mainwp' ); ?></th>
							<?php if ( empty( $website ) || isset( $_GET['client_id'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized ?>
						<th class="collapsing"><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
							<?php endif; ?>
						<th class="collapsing"><?php esc_html_e( 'User', 'mainwp' ); ?></th>
						<th class="collapsing no-sort"></th>
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
					<?php foreach ( $actions_info as $idx => $data ) : ?>
						<?php
						if ( empty( $data->action_user ) || empty( $data->meta_data ) ) {
							continue;
						}

						$meta_data = json_decode( $data->meta_data );

						$action_class = '';
						if ( 'activated' === $data->action ) {
							$action_class = 'green';
						} elseif ( 'deactivated' === $data->action ) {
							$action_class = 'red';
						} elseif ( 'installed' === $data->action ) {
							$action_class = 'blue';
						}

						?>
						<tr>
							<td data-order="<?php echo esc_attr( $data->created ); ?>">
								<strong><?php echo isset( $meta_data->name ) && '' !== $meta_data->name ? esc_html( $meta_data->name ) : 'WP Core'; ?></strong> <?php echo 'wordpress' !== $data->context ? esc_html( ucfirst( rtrim( $data->context, 's' ) ) ) : 'WordPress'; //phpcs:ignore -- text. ?><br/>
								<div><strong><span class="ui medium <?php echo esc_attr( $action_class ); ?> text"><?php echo esc_html( ucfirst( $data->action ) ); ?></span></strong></div>
								<span class="ui small text"><?php echo esc_html( MainWP_Utility::format_timestamp( $data->created ) ); ?></span>
							</td>
							<?php if ( empty( $website ) || isset( $_GET['client_id'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized ?>
								<td class="collapsing"><a href="admin.php?page=managesites&dashboard=<?php echo esc_attr( $data->wpid ); ?>"><?php echo esc_html( $data->name ); ?></a></td>
								<?php endif; ?>
							<td class="collapsing"><?php echo esc_html( $data->action_user ); ?></td>
							<td class="collapsing">
								<a href="javascript:void(0)" class="mainwp-action-dismiss ui mini icon button" action-id="<?php echo intval( $data->action_id ); ?>" data-tooltip="<?php esc_attr_e( 'Dismiss the notice.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><i class="times icon"></i></a>
							</td>
						</tr>
					<?php endforeach; ?>
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
					jQuery.fn.DataTable.ext.pager.numbers_length = 4;
					jQuery( '#mainwp-non-mainwp-changes-table' ).DataTable( {
						"pageLength": 10,
						"lengthMenu": [ [5, 10, 25, 50, 100, -1], [5, 10, 25, 50, 100, "All"] ],
						"stateSave" : true,
						"stateDuration" : 0,
						"columnDefs": [ {
							"targets": 'no-sort',
							"orderable": false
						} ],
					} );
				} );
				</script>
			<?php else : ?>
				<h2 class="ui icon header">
					<i class="info circle icon"></i>
					<div class="content">
						<?php esc_html_e( 'No changes detected!', 'mainwp' ); ?>
						<div class="sub header"><?php esc_html_e( 'Sync to get the latest information about plugin and theme changes made directly on the child site.', 'mainwp' ); ?></div>
					</div>
				</h2>
			<?php endif; ?>
		</div>
		<?php
		$is_demo = MainWP_Demo_Handle::is_demo_mode();
		?>
		<div class="mainwp-widget-footer">
			<div class="ui two columns stackable grid">
				<div class="middle aligned column">
					<?php
					if ( $is_demo ) {
						MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui button mini fluid green disabled" disabled="disabled">' . esc_html__( 'Clear All Non-MainWP Changes', 'mainwp' ) . '</a>' );
					} else {
						?>
						<a href="javascript:void(0)" id="mainwp-delete-all-nonmainwp-actions-button" class="ui button mini fluid green"><?php esc_html_e( 'Clear All Non-MainWP Changes', 'mainwp' ); ?></a>
					<?php } ?>
				</div>
				<div class="middle aligned column"></div>
			</div>
		</div>
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
	}
}
