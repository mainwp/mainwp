<?php

class MainWP_Widget_Themes {

	public static function getClassName() {
		return __CLASS__;
	}

	public static function render() {
		MainWP_Widget_Themes::renderWidget( false, false );
	}

	public static function renderWidget( $renew, $pExit = true ) {
		$current_wpid = MainWP_Utility::get_current_wpid();
		if ( empty( $current_wpid ) ) {
			return;
		}

		$sql       = MainWP_DB::Instance()->getSQLWebsiteById( $current_wpid );
		$websites  = MainWP_DB::Instance()->query( $sql );
		$allThemes = array();
		if ( $websites ) {
			$website = @MainWP_DB::fetch_object( $websites );
			if ( $website && $website->themes != '' ) {
				$themes = json_decode( $website->themes, 1 );
				if ( is_array( $themes ) && count( $themes ) != 0 ) {
					foreach ( $themes as $theme ) {
						$allThemes[] = $theme;
					}
				}
			}
			@MainWP_DB::free_result( $websites );
		}

		$actived_themes = MainWP_Utility::getSubArrayHaving( $allThemes, 'active', 1 );
		$actived_themes = MainWP_Utility::sortmulti( $actived_themes, 'name', 'asc' );

		$inactive_themes = MainWP_Utility::getSubArrayHaving( $allThemes, 'active', 0 );
		$inactive_themes = MainWP_Utility::sortmulti( $inactive_themes, 'name', 'asc' );

		if ( ( count( $allThemes ) > 0 ) && $website ) {
			$themes_outdate = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'themes_outdate_info' ), true );
			if ( ! is_array( $themes_outdate ) ) {
				$themes_outdate = array();
			}

			$themesOutdateDismissed = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'themes_outdate_dismissed' ), true );
			if ( is_array( $themesOutdateDismissed ) ) {
				$themes_outdate = array_diff_key( $themes_outdate, $themesOutdateDismissed );
			}

			$userExtension          = MainWP_DB::Instance()->getUserExtension();
			$decodedDismissedThemes = json_decode( $userExtension->dismissed_themes, true );

			if ( is_array( $decodedDismissedThemes ) ) {
				$themes_outdate = array_diff_key( $themes_outdate, $decodedDismissedThemes );
			}
		}

		?>

		<div class="ui grid">
			<div class="twelve wide column">
				<h3 class="ui header handle-drag">
					<?php _e('Themes', 'mainwp'); ?>
					<div class="sub header"><?php _e( 'Installed themes on the child site', 'mainwp' ); ?></div>
				</h3>
			</div>
			<div class="four wide column right aligned">
				<div class="ui dropdown right mainwp-dropdown-tab">
						<div class="text"><?php _e( 'Active', 'mainwp' ); ?></div>
						<i class="dropdown icon"></i>
						<div class="menu">
							<a class="item" data-tab="active_themes" data-value="active_themes" title="<?php esc_attr_e( 'Active', 'mainwp' ); ?>" href="#"><?php _e( 'Active', 'mainwp' ); ?></a>
							<a class="item" data-tab="inactive_themes" data-value="inactive_themes" title="<?php esc_attr_e( 'Inactive', 'mainwp' ); ?>" href="#"><?php _e( 'Inactive', 'mainwp' ); ?></a>
						</div>
				</div>
			</div>
		</div>

		<div class="ui section hidden divider"></div>

		<!-- Active Theme -->
		<div id="mainwp-widget-active-themes" class="ui tab active" data-tab="active_themes">
			<div class="ui divided selection list">
				<?php
				for ( $i = 0; $i < count( $actived_themes ); $i ++ ) {
					$slug = $actived_themes[ $i ]['slug'];
					?>
					<div class="item">
						<input class="themeSlug" type="hidden" name="slug" value="<?php echo esc_attr( strip_tags($actived_themes[ $i ]['slug'])); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr($website->id); ?>"/>
						<div class="right floated content themesAction">
								<a href="#" disabled class="button ui mini grey basic" data-position="top right" data-tooltip="<?php esc_attr_e( 'Active theme cannot be deactivated. If you need to activate another theme, go to the list of inactive themes and activate the wanted theme.', 'mainwp' ); ?>" data-inverted=""><?php _e( 'Deactivate', 'mainwp' ); ?></a>
						</div>
						<div class="middle aligned content">
								<?php echo esc_html( $actived_themes[ $i ]['name'] . ' ' . $actived_themes[ $i ]['version']); ?>
							</div>
						<div class="mainwp-row-actions-working">
							<i class="ui active inline loader tiny"></i> <?php _e( 'Please wait...', 'mainwp' ); ?>
						</div>
					</div>
				<?php } ?>
			</div>
		</div>

		<!-- Inactive Themes List -->
		<div id="mainwp-widget-inactive-themes" class="ui tab" data-tab="inactive_themes">
			<div class="ui divided selection list">
				<?php
				for ( $i = 0; $i < count( $inactive_themes ); $i ++ ) {
					$slug = $inactive_themes[ $i ]['slug'];
					?>
					<div class="item">
						<input class="themeName" type="hidden" name="slug" value="<?php echo esc_attr( strip_tags( $inactive_themes[ $i ]['name'] ) ); ?>"/>
						<input class="themeSlug" type="hidden" name="slug" value="<?php echo esc_attr( strip_tags( $inactive_themes[ $i ]['slug'] ) ); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr( $website->id ); ?>"/>
						<div class="right floated content themesAction">
							<?php if ( mainwp_current_user_can( 'dashboard', 'activate_deactivate_themes' ) ) { ?>
								<a href="#" class="mainwp-theme-activate ui mini green button" data-position="top right" data-tooltip="<?php echo __( 'Activate the ', 'mainwp') . strip_tags( $inactive_themes[ $i ]['name'] ) . __( ' theme on the child site.', 'mainwp'); ?>" data-inverted=""><?php _e( 'Activate', 'mainwp' ); ?></a>
							<?php } ?>
							<?php if ( mainwp_current_user_can( 'dashboard', 'delete_themes' ) ) { ?>
								<a href="#" class="mainwp-theme-delete ui mini basic button" data-position="top right" data-tooltip="<?php echo __( 'Delete the ', 'mainwp') . strip_tags ( $inactive_themes[ $i ]['name'] ) . __( ' theme from the child site.', 'mainwp'); ?>" data-inverted=""><?php _e( 'Delete', 'mainwp' ); ?></a>
							<?php } ?>
						</div>
						<div class="middle aligned content">
								<?php echo esc_html( $inactive_themes[ $i ]['name'] . ' ' . $inactive_themes[ $i ]['version'] ); ?>
							</div>
						<div class="mainwp-row-actions-working">
							<i class="ui active inline loader tiny"></i> <?php _e( 'Please wait...', 'mainwp' ); ?>
						</div>
					</div>
				<?php } ?>
			</div>
		</div>

		<?php

		if ( $pExit == true ) {
			exit();
		}
	}

	public static function activateTheme() {
		self::action( 'activate' );
		die( json_encode( array( 'result' => __( 'Theme has been activated!', 'mainwp' ) ) ) );
	}

	public static function deleteTheme() {
		self::action( 'delete' );
		die( json_encode( array( 'result' => __( 'Theme has been permanently deleted!', 'mainwp' ) ) ) );
	}

	public static function action( $pAction ) {
		$theme        = $_POST['theme'];
		$websiteIdEnc = $_POST['websiteId'];

		if ( empty( $theme ) ) {
			die( json_encode( array( 'error' => 'Invalid request!' ) ) );
		}
		$websiteId = $websiteIdEnc;
		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( json_encode( array( 'error' => 'Invalid request!' ) ) );
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $websiteId );
		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			die( json_encode( array( 'error' => 'You can not edit this website!' ) ) );
		}

		try {
			$information = MainWP_Utility::fetchUrlAuthed( $website, 'theme_action', array(
				'action' => $pAction,
				'theme'  => $theme,
			) );
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array( 'error' => MainWP_Error_Helper::getErrorMessage( $e ) ) ) );
		}

		if ( ! isset( $information['status'] ) || ( $information['status'] != 'SUCCESS' ) ) {
			die( json_encode( array( 'error' => 'Unexpected error!' ) ) );
		}
	}

}
