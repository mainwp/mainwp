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
		?>
		<div id="mainwp-notes-area">
			<div style="padding-bottom: 1em;">
				<?php
				if ( $website->note == '' ) {
					echo 'No Saved Notes';
				} else {
					echo wp_kses_post( stripslashes( $website->note ) );
				}
				?>
			</div>
			<div style="text-align: center; border-top: 1px Solid #f4f4f4; padding-top: 1em;">
				<a href="#" class="mainwp_notes_show_all button button-primary" id="mainwp_notes_<?php echo $website->id; ?>"><?php _e( 'Edit notes', 'mainwp' ); ?></a>
			</div>
		</div>
		<?php
	}
}
