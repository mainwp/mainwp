<?php
/**
 * MainWP Notes Widget
 * 
 * Display current Child Site Notes
 * 
 */

/**
 * Class MainWP_Notes
 * 
 * Grab Child Site Notes & Build Notes Widget
 * 
 */
class MainWP_Notes {

	/**
	 * getClassName()
	 * 
	 * @return string __CLASS__
	 */
	public static function getClassName() {
		return __CLASS__;
	}

	/**
	 * render()
	 * 
	 * Grab Child Site Notes & Render Widget
	 */
	public static function render() {
		$current_wpid = MainWP_Utility::get_current_wpid();

		if ( !MainWP_Utility::ctype_digit( $current_wpid ) ) {
			return;
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $current_wpid, true );
        $note = html_entity_decode( $website->note ); // to compatible
        $esc_note = MainWP_Utility::esc_content( $note );


		?>

		<h3 class="ui header handle-drag">
			<?php esc_html_e('Notes', 'mainwp'); ?>
			<div class="sub header"><?php esc_html_e( 'Child site notes', 'mainwp' );  ?></div>
		</h3>

		<div class="ui section hidden divider"></div>

		<div>
			<?php
			if ( $website->note == '' ) {
				?>
				<h2 class="ui icon header">
					<i class="info circle icon"></i>
					<div class="content">
						<?php _e( 'No saved notes!', 'mainwp' ); ?>
						<div class="sub header"><?php esc_html_e( 'No saved notes for the child site. ', 'mainwp' ); ?><?php echo '<a href="javascript:void(0)" class="mainwp-edit-site-note" id="mainwp-notes-' . $website->id . '">' . __( 'Click here to add a note.', 'mainwp' ) . '</a>'; ?></div>
					</div>
				</h2>
				<?php
			} else {
				echo $esc_note;
				?>
				<div class="ui section hidden divider"></div>
				<a href="javascript:void(0)" class="ui button green mainwp-edit-site-note" id="mainwp-notes-<?php echo $website->id; ?>"><?php esc_html_e( 'Edit Notes', 'mainwp' ); ?></a>
				<?php
			}
			?>
		</div>
        <span style="display: none" id="mainwp-notes-<?php echo intval($current_wpid); ?>-note"><?php echo $esc_note; ?></span>
		<?php
        MainWP_UI::render_modal_edit_notes();
	}

}
