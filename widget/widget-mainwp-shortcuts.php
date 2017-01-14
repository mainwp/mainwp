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
		?>
		<div class="mainwp-row-top">
			<div style="display: inline-block; width: 100px;"><?php _e( 'Groups:', 'mainwp' ); ?></div>
			<?php echo( $website->groups == '' ? 'None' : $website->groups ); ?>
		</div>
		<div class="mainwp-row">
			<div style="display: inline-block; width: 100px;"><?php _e( 'Notes:', 'mainwp' ); ?></div>
			<a href="#" class="mainwp_notes_show_all <?php echo $website->note == '' ? '' : 'mainwp-green'; ?>" id="mainwp_notes_<?php echo $website->id; ?>">
				<i class="fa fa fa-pencil-square-o"></i> <?php _e( 'Open notes', 'mainwp' ); ?>
			</a>
		</div>
		<span style="display: none"
			id="mainwp_notes_<?php echo $website->id; ?>_note"><?php echo $website->note; ?></span>
		<div class="mainwp-row">
			<div style="display: inline-block; width: 100px;"><?php _e( 'Go to:', 'mainwp' ); ?></div>
			<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo $website->id; ?>" target="_blank"><i class="fa fa-external-link"></i> <?php _e( 'WP Admin', 'mainwp' ); ?>
			</a> |
			<a target="_blank" href="<?php echo $website->url; ?>"><i class="fa fa-external-link"></i> <?php _e( 'Front Page', 'mainwp' ); ?>
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
		<div id="mainwp_notes_overlay" class="mainwp_overlay"></div>
		<div id="mainwp_notes" class="mainwp_popup">
			<a id="mainwp_notes_closeX" class="mainwp_closeX" style="display: inline; "></a>

			<div id="mainwp_notes_title" class="mainwp_popup_title">
				<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a>
			</div>
			<div id="mainwp_notes_content">
                            <div id="mainwp_notes_html" style="width: 580px !important; height: 300px;"></div>
                            <textarea style="width: 580px !important; height: 300px;"
                                    id="mainwp_notes_note"></textarea>
			</div>
			<div><em><?php _e( 'Allowed HTML Tags:','mainwp' ); ?> &lt;p&gt;, &lt;strong&gt;, &lt;em&gt;, &lt;br&gt;, &lt;hr&gt;, &lt;a&gt;, &lt;ul&gt;, &lt;ol&gt;, &lt;li&gt;, &lt;h1&gt;, &lt;h2&gt; </em></div><br/>
			<form>
				<div style="float: right" id="mainwp_notes_status"></div>
				<input type="button" class="button cont button-primary" id="mainwp_notes_save" value="<?php esc_attr_e( 'Save note', 'mainwp' ); ?>"/>
                                <input type="button" class="button cont" id="mainwp_notes_edit" value="<?php esc_attr_e( 'Edit','mainwp' ); ?>"/>                
                                <input type="button" class="button cont" id="mainwp_notes_view" value="<?php esc_attr_e( 'View','mainwp' ); ?>"/>                
				<input type="button" class="button cont" id="mainwp_notes_cancel" value="<?php esc_attr_e( 'Close', 'mainwp' ); ?>"/>
				<input type="hidden" id="mainwp_notes_websiteid" value=""/>
			</form>
		</div>
		<?php
	}
}
