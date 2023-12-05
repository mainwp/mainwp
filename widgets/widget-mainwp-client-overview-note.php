<?php
/**
 * MainWP Client Overview Note Widget
 *
 * Displays the Client Note.
 *
 * @package MainWP/MainWP_Client_Overview_Note
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Client_Overview_Note
 *
 * Displays the Client Note.
 */
class MainWP_Client_Overview_Note {

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
		$client_id = isset( $_GET['client_id'] ) ? intval( $_GET['client_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( empty( $client_id ) ) {
			return;
		}
		self::render_addition_info( $client_id );
	}


	/**
	 * Render client overview Info.
	 *
	 * @param object $client_id Client ID.
	 */
	public static function render_addition_info( $client_id ) {

		$client_info = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id, ARRAY_A );

		$note       = '';
		$strip_note = '';
		$esc_note   = '';

		if ( $client_info ) {
			$note     = html_entity_decode( $client_info['note'] );
			$esc_note = MainWP_Utility::esc_content( $note );
		}

		?>
		<div class="mainwp-widget-header">
			<h3 class="ui header handle-drag">
			<?php
			/**
			 * Filter: mainwp_clients_overview_note_widget_title
			 *
			 * Filters the Site info widget title text.
			 *
			 * @param object $client_info Object containing the child site info.
			 *
			 * @since 4.1
			 */
			echo esc_html( apply_filters( 'mainwp_clients_overview_note_widget_title', esc_html__( 'Notes', 'mainwp' ), $client_info ) );
			?>
				<div class="sub header"><?php echo esc_html__( 'Client notes.', 'mainwp' ); ?></div>
			</h3>
		</div>
			<div class="mainwp-widget-client-card mainwp-scrolly-overflow">
				<?php
				/**
				 * Actoin: mainwp_clients_overview_note_widget_top
				 *
				 * Fires at the top of the Site Info widget on the Individual site overview page.
				 *
				 * @param object $client_info Object containing the child site info.
				 *
				 * @since 4.0
				 */
				do_action( 'mainwp_clients_overview_note_widget_top', $client_info );
				?>
				<?php
				if ( $client_info ) {
					echo $esc_note; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>


				<div style="display:none" id="mainwp-notes-<?php echo intval( $client_info['client_id'] ); ?>-note"><?php echo wp_unslash( $esc_note ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>

				<?php

				/**
				 * Action: mainwp_clients_overview_note_widget_bottom
				 *
				 * Fires at the bottom of the Site Info widget on the Individual site overview page.
				 *
				 * @param object $client_info Object containing the child site info.
				 *
				 * @since 4.0
				 */
				do_action( 'mainwp_clients_overview_note_widget_bottom', $client_info );
				?>
			</div>
			<div class="ui two columns grid mainwp-widget-footer">
				<div class="column">
				<?php if ( empty( $note ) ) : ?>
					<a href="javascript:void(0)" class="mainwp-edit-client-note ui button mini fluid green" id="mainwp-notes-<?php echo esc_attr( $client_info['client_id'] ); ?>" data-tooltip="<?php esc_attr_e( 'Add notes.', 'mainwp' ); ?>" data-position="right center" data-inverted=""><?php esc_attr_e( 'Add Notes', 'mainwp' ); ?></a>
				<?php else : ?>
					<a href="javascript:void(0)" class="mainwp-edit-client-note ui mini fluid button green" id="mainwp-notes-<?php echo esc_attr( $client_info['client_id'] ); ?>" data-tooltip="<?php esc_attr_e( 'Edit notes.', 'mainwp' ); ?>" data-position="right center" data-inverted=""><?php esc_attr_e( 'Edit Notes', 'mainwp' ); ?></a>
				<?php endif; ?>
				</div>
				<div class="column"></div>
			</div>
			<?php
			MainWP_UI::render_modal_edit_notes();
	}
}
