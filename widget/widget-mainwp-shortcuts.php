<?php

class MainWP_Shortcuts {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function getName() {
		return '<i class="fa fa-share-square-o"></i> ' . __( 'Shortcuts', 'mainwp' );
	}

	public static function render() {
		$current_wpid = MainWP_Utility::get_current_wpid();

		if ( ! MainWP_Utility::ctype_digit( $current_wpid ) ) {
			return;
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $current_wpid, true );
        MainWP_UI::renderBeginReadyPopup();
        $note = html_entity_decode( $website->note ); // to compatible
        $esc_note = MainWP_Utility::esc_content( $note );

		?>
		<div class="mainwp-row-top">
			<div style="display: inline-block; width: 100px;"><?php _e( 'Groups:', 'mainwp' ); ?></div>
			<?php echo( $website->wpgroups == '' ? 'None' : $website->wpgroups ); ?>
		</div>
		<div class="mainwp-row">
			<div style="display: inline-block; width: 100px;"><?php _e( 'Notes:', 'mainwp' ); ?></div>
			<a href="#" class="mainwp_notes_show_all <?php echo $website->note == '' ? '' : 'mainwp-green'; ?>" id="mainwp_notes_<?php echo $website->id; ?>">
				<i class="fa fa fa-pencil-square-o"></i> <?php _e( 'Open notes', 'mainwp' ); ?>
			</a>
		</div>
		<span style="display: none"
			id="mainwp_notes_<?php echo $website->id; ?>_note"><?php echo $esc_note; ?></span>
		<div class="mainwp-row">
			<div style="display: inline-block; width: 100px;"><?php _e( 'Go to:', 'mainwp' ); ?></div>
			<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $website->id; ?>" target="_blank"><i class="fa fa-external-link"></i> <?php _e( 'WP Admin', 'mainwp' ); ?>
			</a> |
			<a target="_blank" class="mainwp-may-hide-referrer" href="<?php echo $website->url; ?>"><i class="fa fa-external-link"></i> <?php _e( 'Front Page', 'mainwp' ); ?>
			</a>
		</div>
		<div class="mainwp-row">
			<div style="display: inline-block; width: 100px;"><?php _e( 'Child site:', 'mainwp' ); ?></div>
			<a href="admin.php?page=managesites&id=<?php echo $website->id; ?>"><i class="fa fa-pencil-square-o"></i> <?php _e( 'Edit', 'mainwp' ); ?>
			</a> |
			<a target="_blank" href="admin.php?page=managesites&scanid=<?php echo $website->id; ?>"><i class="fa fa-shield"></i> <?php _e( 'Security scan', 'mainwp' ); ?>
			</a>
		</div>

		<?php do_action( 'mainwp_shortcuts_widget', $website ); ?>
		<?php
        MainWP_UI::renderEndReadyPopup();
	}
}
