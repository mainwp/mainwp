<?php
/**
 * MainWP Themes Widget
 *
 * Grab current Child Site theme data & build Widget
 *
 * @package MainWP/Plugins
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Widget_Themes
 */
class MainWP_Widget_Themes {
	/**
	 * Method get_class_name()
	 *
	 * Get Class Name
	 *
	 * @return string __CLASS__ Class Name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method render()
	 *
	 * Fire off render_widget().
	 */
	public static function render() {
		self::render_widget();
	}


	/**
	 * Method render_widget()
	 *
	 * Build Themes Widget
	 *
	 * @param none	 
	 */
	public static function render_widget() {
		$current_wpid = MainWP_Utility::get_current_wpid();
		if ( empty( $current_wpid ) ) {
			return;
		}

		$sql       = MainWP_DB::instance()->get_sql_website_by_id( $current_wpid );
		$websites  = MainWP_DB::instance()->query( $sql );
		$allThemes = array();
		if ( $websites ) {
			$website = MainWP_DB::fetch_object( $websites );
			if ( $website && '' !== $website->themes ) {
				$themes = json_decode( $website->themes, 1 );
				if ( is_array( $themes ) && 0 != count( $themes ) ) {
					foreach ( $themes as $theme ) {
						$allThemes[] = $theme;
					}
				}
			}
			MainWP_DB::free_result( $websites );
		}

		self::render_html_widget( $website, $allThemes );		
	}
	
	/**
	 * 
	 * Method render_html_widget().
	 * 
	 * Render html themes widget for current site
	 *	 
	 * @param mixed $website current site.
	 * @param mixed $allPlugins all plugins.
	 *
	 * @return echo html
	 */
	public static function render_html_widget( $website, $allThemes ) {
		
		$actived_themes = MainWP_Utility::get_sub_array_having( $allThemes, 'active', 1 );
		$actived_themes = MainWP_Utility::sortmulti( $actived_themes, 'name', 'asc' );

		$inactive_themes = MainWP_Utility::get_sub_array_having( $allThemes, 'active', 0 );
		$inactive_themes = MainWP_Utility::sortmulti( $inactive_themes, 'name', 'asc' );

	?>
		<div class="ui grid">
			<div class="twelve wide column">
				<h3 class="ui header handle-drag">
					<?php esc_html_e( 'Themes', 'mainwp' ); ?>
					<div class="sub header"><?php esc_html_e( 'Installed themes on the child site', 'mainwp' ); ?></div>
				</h3>
			</div>
			<div class="four wide column right aligned">
				<div class="ui dropdown right mainwp-dropdown-tab">
					<div class="text"><?php esc_html_e( 'Active', 'mainwp' ); ?></div>
					<i class="dropdown icon"></i>
					<div class="menu">
						<a class="item" data-tab="active_themes" data-value="active_themes" title="<?php esc_attr_e( 'Active', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Active', 'mainwp' ); ?></a>
						<a class="item" data-tab="inactive_themes" data-value="inactive_themes" title="<?php esc_attr_e( 'Inactive', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Inactive', 'mainwp' ); ?></a>
					</div>
				</div>
			</div>
		</div>
		<div class="ui section hidden divider"></div>
		<div id="mainwp-widget-active-themes" class="ui tab active" data-tab="active_themes">
			<div class="ui divided selection list">
				<?php
				$_count = count( $actived_themes );
				for ( $i = 0; $i < $_count; $i ++ ) {
					$slug = $actived_themes[ $i ]['slug'];
					?>
					<div class="item">
						<input class="themeSlug" type="hidden" name="slug" value="<?php echo esc_attr( wp_strip_all_tags( $actived_themes[ $i ]['slug'] ) ); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr( $website->id ); ?>"/>
						<div class="right floated content themesAction">
							<a href="#" disabled class="button ui mini grey basic" data-position="top right" data-tooltip="<?php esc_attr_e( 'Active theme cannot be deactivated. If you need to activate another theme, go to the list of inactive themes and activate the wanted theme.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Deactivate', 'mainwp' ); ?></a>
						</div>
						<div class="middle aligned content">
							<?php echo esc_html( $actived_themes[ $i ]['name'] . ' ' . $actived_themes[ $i ]['version'] ); ?>
						</div>
						<div class="mainwp-row-actions-working">
							<i class="ui active inline loader tiny"></i> <?php esc_html_e( 'Please wait...', 'mainwp' ); ?>
						</div>
					</div>
				<?php } ?>
			</div>
		</div>
		<div id="mainwp-widget-inactive-themes" class="ui tab" data-tab="inactive_themes">
			<div class="ui divided selection list">
				<?php
				$_count = count( $inactive_themes );
				for ( $i = 0; $i < $_count; $i ++ ) {
					$slug = $inactive_themes[ $i ]['slug'];
					?>
					<div class="item">
						<input class="themeName" type="hidden" name="slug" value="<?php echo esc_attr( wp_strip_all_tags( $inactive_themes[ $i ]['name'] ) ); ?>"/>
						<input class="themeSlug" type="hidden" name="slug" value="<?php echo esc_attr( wp_strip_all_tags( $inactive_themes[ $i ]['slug'] ) ); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr( $website->id ); ?>"/>
						<div class="right floated content themesAction">
							<?php if ( mainwp_current_user_can( 'dashboard', 'activate_deactivate_themes' ) ) { ?>
								<a href="#" class="mainwp-theme-activate ui mini green button" data-position="top right" data-tooltip="<?php esc_attr_e( 'Activate the ', 'mainwp' ) . wp_strip_all_tags( $inactive_themes[ $i ]['name'] ) . esc_attr_e( ' theme on the child site.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Activate', 'mainwp' ); ?></a>
							<?php } ?>
							<?php if ( mainwp_current_user_can( 'dashboard', 'delete_themes' ) ) { ?>
								<a href="#" class="mainwp-theme-delete ui mini basic button" data-position="top right" data-tooltip="<?php esc_attr_e( 'Delete the ', 'mainwp' ) . wp_strip_all_tags( $inactive_themes[ $i ]['name'] ) . esc_attr_e( ' theme from the child site.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
							<?php } ?>
						</div>
						<div class="middle aligned content">
							<?php echo esc_html( $inactive_themes[ $i ]['name'] . ' ' . $inactive_themes[ $i ]['version'] ); ?>
						</div>
						<div class="mainwp-row-actions-working">
							<i class="ui active inline loader tiny"></i> <?php esc_html_e( 'Please wait...', 'mainwp' ); ?>
						</div>
					</div>
				<?php } ?>
			</div>
		</div>
	<?php
	}

	/**
	 * Method activate_theme()
	 *
	 * Fire off Action activate & display result
	 */
	public static function activate_theme() {
		self::action( 'activate' );
		die( wp_json_encode( array( 'result' => __( 'Theme has been activated!', 'mainwp' ) ) ) );
	}

	/**
	 * Method delete_theme()
	 *
	 * Fire off action deactivate & display result
	 */
	public static function delete_theme() {
		self::action( 'delete' );
		die( wp_json_encode( array( 'result' => __( 'Theme has been permanently deleted!', 'mainwp' ) ) ) );
	}

	/**
	 * Method action()
	 *
	 * Initiate try catch for chosen Action
	 *
	 * @param mixed $pAction Theme Action.
	 */
	public static function action( $pAction ) {
		$theme        = $_POST['theme'];
		$websiteIdEnc = $_POST['websiteId'];

		if ( empty( $theme ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		$websiteId = $websiteIdEnc;
		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			die( wp_json_encode( array( 'error' => __( 'You cannot edit this website.', 'mainwp' ) ) ) );
		}

		try {
			$information = MainWP_Utility::fetch_url_authed(
				$website,
				'theme_action',
				array(
					'action' => $pAction,
					'theme'  => $theme,
				)
			);
		} catch ( MainWP_Exception $e ) {
			die( wp_json_encode( array( 'error' => MainWP_Error_Helper::get_error_message( $e ) ) ) );
		}

		if ( ! isset( $information['status'] ) || ( 'SUCCESS' !== $information['status'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Unexpected error occurred. Please try again.', 'mainwp' ) ) ) );
		}
	}

}
