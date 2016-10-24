<?php

class MainWP_Footprint {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function handleSettingsPost() {
		if ( isset( $_POST['submit'] ) && wp_verify_nonce( $_POST['wp_nonce'], 'Settings' ) ) {
			$userExtension            = MainWP_DB::Instance()->getUserExtension();
			$userExtension->heatMap   = ( ! isset( $_POST['mainwp_options_footprint_heatmap'] ) ? 1 : 0 );
			$userExtension->pluginDir = ( ! isset( $_POST['mainwp_options_footprint_plugin_folder'] ) ? 'default' : 'hidden' );

			MainWP_DB::Instance()->updateUserExtension( $userExtension );

			return true;
		}

		return false;
	}

	public static function renderSettings() {
		$filter = apply_filters( 'mainwp_has_settings_networkfootprint', false );
		if ( ! $filter ) {
			return;
		}

		?>
		<table class="form-table">
			<tbody>
			<?php do_action( 'mainwp_settings_networkfootprint' ); ?>
			</tbody>
		</table>
		<div class="mainwp-notice mainwp-notice-green">
			<strong>Note: </strong><i><?php _e( 'After pressing "Save settings" below you will need to return to MainWP Dashboard and press the Sync Data button to synchronize the settings.', 'mainwp' ); ?></i>
		</div>

		<?php
	}
}
