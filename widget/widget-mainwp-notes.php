<?php

class MainWP_Notes {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function getName() {
		return '<i class="fa fa-file-text-o"></i> ' . __( 'Notes', 'mainwp' );
	}

	public static function render() {
		$current_wpid = MainWP_Utility::get_current_wpid();

		if ( ! MainWP_Utility::ctype_digit( $current_wpid ) ) {
			return;
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $current_wpid, true );
        
        MainWP_UI::renderBeginReadyPopup();
		?>
		<div id="mainwp-notes-area">
			<div style="padding-bottom: 1em;">
				<?php
				if ( $website->note == '' ) {
					echo 'No Saved Notes';
				} else {
					echo html_entity_decode($website->note);
				}
				?>
			</div>			
		</div>
		<?php
         $actions = '<a href="#" class="mainwp_notes_show_all button button-primary" id="mainwp_notes_' .  $website->id . '">' . __( 'Edit notes', 'mainwp' ). '</a>';
         MainWP_UI::renderEndReadyPopup($actions, 'mainwp-postbox-actions-bottom');
	}
}
