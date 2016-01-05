<?php

class MainWP_Manage_Tips {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function render() {
		foreach ( $_POST as $key => $val ) {
			if ( preg_match( '/^tip_([0-9]+)_seq$/', $key, $match ) > 0 ) {
				$tip_id = $match[1];
				if ( ! MainWP_Utility::ctype_digit( $tip_id ) ) {
					continue;
				}
				$tip_seq     = $_POST[ 'tip_' . $tip_id . '_seq' ];
				$tip_content = $_POST[ 'tip_' . $tip_id . '_content' ];
				if ( $tip_seq == '' && $tip_content == '' ) {
					if ( $tip_id < 90000 ) {
						MainWP_DB::Instance()->deleteTip( $tip_id );
					}
				} else {
					if ( $tip_id > 90000 ) {
						MainWP_DB::Instance()->addTip( $tip_seq, $tip_content );
					} else {
						MainWP_DB::Instance()->updateTip( $tip_id, $tip_seq, $tip_content );
					}
				}
			}
		}

		$tips = MainWP_DB::Instance()->getTips();
		//Loads the post screen via AJAX, which redirects to the "posting()" to really post the posts to the saved sites
		?>
		<div class="wrap">
			<a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img src="<?php echo plugins_url( 'images/logo.png', dirname( __FILE__ ) ); ?>" height="50" alt="MainWP"/></a>
			<img src="<?php echo plugins_url( 'images/icons/mainwp-tips.png', dirname( __FILE__ ) ); ?>" style="float: left; margin-right: 8px; margin-top: 7px ;" alt="MainWP Tips" height="32"/>

			<h2>Manage Tips</h2>

			<div style="clear: both;"></div>
			<br/>

			<form method="POST" action="" id="mainwp_managesites_add_form">
				<table class="form-table" style="width: 200px">
					<thead>
					<tr>
						<th style="width: 50px">Sequence</th>
						<th>Content</th>
						<th style="width: 20px"></th>
					</tr>
					</thead>
					<tbody id="mainwp_managetips_tbody">
					<?php
					foreach ( $tips as $tip ) {
						?>
						<tr>
							<td valign="top">
								<input type="text" name="tip_<?php echo $tip->id; ?>_seq" size="1"
									value="<?php echo $tip->seq; ?>" class="mainwp_managetips_tip_seq"/>
							</td>
							<td>
                            <textarea rows="4" cols="150" name="tip_<?php echo $tip->id; ?>_content"
	                            class="mainwp_managetips_tip_content"><?php echo $tip->content; ?></textarea>
							</td>
							<td valign="top"><a href="#" class="mainwp_managetips_remove">Remove</a></td>
						</tr>
						<?php

					}
					?>
					</tbody>
				</table>
				<p class="submit"><input type="button" name="mainwp_managetips_add" id="mainwp_managetips_add"
						class="button-primary" value="<?php esc_attr_e( 'Add Tip', 'mainwp' ); ?>"/> <input type="submit"
						name="mainwp_managetips_save"
						id="mainwp_managetips_save"
						class="button-primary"
						value="<?php esc_attr_e( 'Save' ); ?>"/></p>
			</form>
		</div>
		<?php
	}

	public static function renderTips() {
		$tips    = MainWP_DB::Instance()->getTips();
		$currTip = rand( 1, count( $tips ) );
		?>
		<div id="mainwp_tips_overlay" class="mainwp_overlay"></div>
		<div id="mainwp_tips" class="mainwp_popup">
			<a id="mainwp_tips_closeX" class"mainwp_closeX" style="display: inline; "></a>

			<div id="mainwp_tips_title" class="mainwp_popup_title">Tip #<span id="mainwp_tips_current_label"><?php echo $currTip; ?></span>
			</div>
			<div id="mainwp_tips_content">
				<?php
				foreach ( $tips as $tip ) {
					?>
					<span <?php echo( $currTip == $tip->seq ? '' : 'class="mainwp_tips_content_individual"' ); ?>
						id="mainwp_tips_content_<?php echo $tip->seq; ?>"><?php echo $tip->content; ?></span>
					<?php

				}
				?>
			</div>
			<form>
				<input type="button" class="button cont" id="mainwp_tips_next" value="<?php esc_attr_e( 'Next tip', 'mainwp' ); ?>"/>
				<input type="button" class="button cont" id="mainwp_tips_close" value="<?php esc_attr_e( 'Close' ); ?>" class="button cont2"/>
				<input type="checkbox" id="mainwp_tips_show" name="mainwp_tips_show" value="1"> <?php _e( 'Do not show tips anymore', 'mainwp' ); ?>
				<input type="hidden" id="mainwp_tips_current" value="<?php echo esc_attr($currTip); ?>">
				<input type="hidden" id="mainwp_tips_max" value="<?php echo count( $tips ); ?>">
			</form>
		</div>
		<?php

	}

	public static function updateTipSettings() {
		if ( MainWP_Utility::ctype_digit( $_POST['status'] ) ) {
			$userExtension       = MainWP_DB::Instance()->getUserExtension();
			$userExtension->tips = $_POST['status'];
			MainWP_DB::Instance()->updateUserExtension( $userExtension );
		}
	}
}

?>
