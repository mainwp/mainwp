<?php
/**
 * MainWP Notes Widget
 *
 * Display current Child Site Notes.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Notes
 *
 * Grab Child Site Notes & Build Notes Widget.
 */
class MainWP_Notes {

	/**
	 * Method get_class_name()
	 *
	 * @return string __CLASS__ Class Name
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method render()
	 *
	 * Grab Child Site Notes & Render Widget.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_modal_edit_notes()
	 */
	public static function render() {
		$current_wpid = MainWP_System_Utility::get_current_wpid();

		if ( ! MainWP_Utility::ctype_digit( $current_wpid ) ) {
			return;
		}

		$website  = MainWP_DB::instance()->get_website_by_id( $current_wpid, true );
		$note     = html_entity_decode( $website->note );
		$esc_note = MainWP_Utility::esc_content( $note );
		?>

		<h3 class="ui header handle-drag">
			<?php
			/**
			 * Filter: mainwp_notes_widget_title
			 *
			 * Filters the Notes widget title text.
			 *
			 * @param object $website Object containing the child site info.
			 *
			 * @since 4.1
			 */
			echo esc_html( apply_filters( 'mainwp_notes_widget_title', __( 'Notes', 'mainwp' ), $website ) );
			?>
			<div class="sub header"><?php esc_html_e( 'Child site notes', 'mainwp' ); ?></div>
		</h3>

		<div class="ui section hidden divider"></div>

			<?php
			/**
			 * Action: mainwp_notes_widget_top
			 *
			 * Fires at the top of the Notes widget on the Individual site overview page.
			 *
			 * @param object $website Object containing the child site info.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_notes_widget_top', $website );

			if ( '' == $website->note ) {
				?>
				<h2 class="ui icon header">
					<i class="info circle icon"></i>
					<div class="content">
						<?php esc_html_e( 'No saved notes!', 'mainwp' ); ?>
						<div class="sub header"><?php esc_html_e( 'No saved notes for the child site. ', 'mainwp' ); ?><?php echo '<a href="javascript:void(0)" class="mainwp-edit-site-note" id="mainwp-notes-' . intval( $website->id ) . '">' . __( 'Click here to add a note.', 'mainwp' ) . '</a>'; ?></div>
					</div>
				</h2>
				<?php
			} else {
				?>
				<div class="content">
				<?php
				echo $esc_note;
				?>
				</div>
				<div class="ui section hidden divider"></div>
				<a href="javascript:void(0)" class="ui button green mainwp-edit-site-note" id="mainwp-notes-<?php echo intval( $website->id ); ?>"><?php esc_html_e( 'Edit Notes', 'mainwp' ); ?></a>
				<?php
			}
			?>
		<span style="display: none" id="mainwp-notes-<?php echo intval( $current_wpid ); ?>-note"><?php echo $esc_note; ?></span>
		<?php
		/**
		 * Action: mainwp_notes_widget_bottom
		 *
		 * Fires at the bottom of the Notes widget on the Individual site overview page.
		 *
		 * @param object $website Object containing the child site info.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_notes_widget_bottom', $website );

		MainWP_UI::render_modal_edit_notes();
	}

}
