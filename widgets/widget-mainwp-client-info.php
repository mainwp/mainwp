<?php
/**
 * MainWP Client Info Widget
 *
 * Displays the Client Info.
 *
 * @package MainWP/MainWP_Client_Info
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Client_Info
 *
 * Displays the Client info.
 */
class MainWP_Client_Info {

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
		 *
		 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
		 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
		 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
		 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
		 */
	public static function render_site_info() {
		$current_wpid = MainWP_System_Utility::get_current_wpid();
		if ( empty( $current_wpid ) ) {
			return;
		}

		$website = MainWP_DB::instance()->get_website_by_id( $current_wpid, true );

		self::render_info( $website );
	}

		/**
		 * Render Sites Info.
		 *
		 * @param object $website Object containing the child site info.
		 */
	public static function render_info( $website ) {

		$client_info = $website->client_id ? MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $website->client_id, ARRAY_A ) : false;

		?>
			<h3 class="ui header handle-drag">
			<?php
			/**
			 * Filter: mainwp_clients_info_widget_title
			 *
			 * Filters the Site info widget title text.
			 *
			 * @param object $website Object containing the child site info.
			 *
			 * @since 4.1
			 */
			echo esc_html( apply_filters( 'mainwp_clients_info_widget_title', esc_html__( 'Client Info', 'mainwp' ), $website ) );
			?>
				<div class="sub header"><?php esc_html_e( 'Client Information', 'mainwp' ); ?></div>
			</h3>
			<div class="mainwp-widget-site-info mainwp-scrolly-overflow">
				<?php
				/**
				 * Actoin: mainwp_clients_info_widget_top
				 *
				 * Fires at the top of the Site Info widget on the Individual site overview page.
				 *
				 * @param object $website Object containing the child site info.
				 *
				 * @since 4.0
				 */
				do_action( 'mainwp_clients_info_widget_top', $website );
				?>
				<?php
				if ( $client_info ) {
					$default_client_fields = MainWP_Client_Handler::get_default_client_fields();
					$custom_fields         = MainWP_DB_Client::instance()->get_client_fields( true, $website->client_id );

					?>
				<table class="ui celled striped table">
					<tbody>
					<?php
					/**
					 * Action: mainwp_clients_info_table_top
					 *
					 * Fires at the top of the Site Info table in Site Info widget on the Individual site overview page.
					 *
					 * @param object $website Object containing the child site info.
					 *
					 * @since 4.0
					 */
					do_action( 'mainwp_clients_info_table_top', $website );
					?>
					<?php

					foreach ( $default_client_fields as $field_name => $field ) {

						$db_field = isset( $field['db_field'] ) ? $field['db_field'] : '';
						$val      = ( ! empty( $db_field ) && isset( $client_info[ $db_field ] ) ) ? $client_info[ $db_field ] : '';

						if ( empty( $val ) ) {
							continue;
						}

						?>
							<tr>
								<td><?php echo esc_html( $field['title'] ); ?></td>
								<td>
								<?php
								if ( 'name' === $db_field ) {
									?>
									<a class="item" href="admin.php?page=ClientAddNew&client_id=<?php echo intval( $client_info['client_id'] ); ?>"><?php echo esc_html( $client_info['name'] ); ?></a>
									<?php
								} elseif ( 'email' === $db_field ) {
									?>
									<a class="item" href="admin.php?page=ClientAddNew&client_id=<?php echo intval( $client_info['client_id'] ); ?>"><?php echo esc_html( $client_info['email'] ); ?></a>
									<?php

								} elseif ( 'note' === $db_field ) {
									$note       = html_entity_decode( $client_info['note'] );
									$esc_note   = MainWP_Utility::esc_content( $note );
									$strip_note = wp_strip_all_tags( $esc_note );

									if ( empty( $client_info['note'] ) ) :
										?>
										<a href="javascript:void(0)" class="mainwp-edit-client-note" id="mainwp-notes-<?php echo intval( $client_info['client_id'] ); ?>" data-tooltip="<?php esc_attr_e( 'Edit client notes.', 'mainwp' ); ?>" data-position="left center" data-inverted=""><i class="sticky note outline icon"></i></a>
									<?php else : ?>
										<a href="javascript:void(0)" class="mainwp-edit-client-note" id="mainwp-notes-<?php echo intval( $client_info['client_id'] ); ?>" data-tooltip="<?php echo substr( wp_unslash( $strip_note ), 0, 100 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" data-position="left center" data-inverted=""><i class="sticky green note icon"></i></a>
									<?php endif; ?>
									<span style="display: none" id="mainwp-notes-<?php echo intval( $client_info['client_id'] ); ?>-note"><?php echo wp_unslash( $esc_note ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
									<?php
								} else {
									echo esc_html( $val );
								}

								?>
								</td>
							</tr>
					<?php } ?>

					<?php

					if ( is_array( $custom_fields ) && count( $custom_fields ) > 0 ) {
						foreach ( $custom_fields as $field ) {
							if ( empty( $field->field_value ) ) {
								continue;
							}
							?>
							<tr>
							<td><?php echo esc_html( $field->field_desc ); ?></td>
							<td><?php echo esc_html( $field->field_value ); ?></td>
							</tr>
							<?php
						}
					}

					?>
					<?php
					/**
					 * Action: mainwp_clients_info_table_bottom
					 *
					 * Fires at the bottom of the Site Info table in Site Info widget on the Individual site overview page.
					 *
					 * @param object $website Object containing the child site info.
					 *
					 * @since 4.0
					 */
					do_action( 'mainwp_clients_info_table_bottom', $website );
					?>
					</tbody>
				</table>
				<?php } ?>
				<?php
				/**
				 * Action: mainwp_clients_info_widget_bottom
				 *
				 * Fires at the bottom of the Site Info widget on the Individual site overview page.
				 *
				 * @param object $website Object containing the child site info.
				 *
				 * @since 4.0
				 */
				do_action( 'mainwp_clients_info_widget_bottom', $website );
				?>
			</div>
			<div class="ui stackable two columns grid mainwp-widget-footer">
				<div class="middle aligned column">
					<?php if ( $client_info ) { ?>
					<a href="admin.php?page=ClientAddNew&client_id=<?php echo intval( $client_info['client_id'] ); ?>" title="" class="ui mini fluid button green basic"><?php echo esc_html__( 'Edit Client', 'mainwp' ); ?></a>
					<?php } ?>
				</div>
				<div class="middle aligned column">
					<a href="admin.php?page=ClientAddNew" title="" class="ui mini fluid button green"><?php echo esc_html__( 'Add New Client', 'mainwp' ); ?></a>
				</div>
			</div>
			<?php
	}
}
