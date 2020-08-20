<?php
/**
 * MainWP Plugins Widget
 *
 * Grab current Child Site plugin data & build Widget
 *
 * @package MainWP/Plugins
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Widget_Plugins
 */
class MainWP_Widget_Plugins {
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
	 * Method prepair_icons()
	 *
	 * Utilizes WP API to grab plugin icons, last_updated, active_installs
	 */
	public function prepare_icons() {
		include ABSPATH . 'wp-admin/includes/plugin-install.php';

		$args = array(
			'fields' => array(
				'last_updated'    => true,
				'icons'           => true,
				'active_installs' => true,
			),
		);

		$api = plugins_api( 'query_plugins', $args );

		if ( is_wp_error( $api ) ) {
			$this->error = $api;
			return;
		}

		$this->items = $api->plugins;
	}


	/**
	 * Method render_widget()
	 *
	 * Build Plugins Widget
	 */
	public static function render_widget() {
		$current_wpid = MainWP_System_Utility::get_current_wpid();
		if ( empty( $current_wpid ) ) {
			return;
		}

		$sql        = MainWP_DB::instance()->get_sql_website_by_id( $current_wpid );
		$websites   = MainWP_DB::instance()->query( $sql );
		$allPlugins = array();
		if ( $websites ) {
			$website = MainWP_DB::fetch_object( $websites );
			if ( $website && '' !== $website->plugins ) {
				$plugins = json_decode( $website->plugins, 1 );
				if ( is_array( $plugins ) && 0 != count( $plugins ) ) {
					foreach ( $plugins as $plugin ) {
						if ( isset( $plugin['mainwp'] ) && ( 'T' === $plugin['mainwp'] ) ) {
							continue;
						}
						$allPlugins[] = $plugin;
					}
				}
			}
			MainWP_DB::free_result( $websites );
		}

		self::render_html_widget( $website, $allPlugins );
	}

	/**
	 * Method render_html_widget().
	 *
	 * Render HTML plugins widget for current site
	 *
	 * @param object $website    Object containing the child site info.
	 * @param array  $allPlugins Arrau containing all detected plugins data.
	 */
	public static function render_html_widget( $website, $allPlugins ) {

		$actived_plugins = MainWP_Utility::get_sub_array_having( $allPlugins, 'active', 1 );
		$actived_plugins = MainWP_Utility::sortmulti( $actived_plugins, 'name', 'asc' );

		$inactive_plugins = MainWP_Utility::get_sub_array_having( $allPlugins, 'active', 0 );
		$inactive_plugins = MainWP_Utility::sortmulti( $inactive_plugins, 'name', 'asc' );

		?>
	<div class="ui grid">
			<div class="twelve wide column">
				<h3 class="ui header handle-drag">
					<?php
					/**
					 * Filter: mainwp_plugins_widget_title
					 *
					 * Filters the Plugins widget title text.
					 *
					 * @param object $website Object containing the child site info.
					 *
					 * @since 4.1
					 */
					echo esc_html( apply_filters( 'mainwp_plugins_widget_title', __( 'Plugins', 'mainwp' ), $website ) );
					?>
					<div class="sub header"><?php esc_html_e( 'Installed plugins on the child site', 'mainwp' ); ?></div>
				</h3>
			</div>
			<div class="four wide column right aligned">
				<div class="ui dropdown right mainwp-dropdown-tab">
					<div class="text"><?php esc_html_e( 'Active', 'mainwp' ); ?></div>
					<i class="dropdown icon"></i>
					<div class="menu">
						<a class="item" data-tab="active_plugins" data-value="active_plugins" title="<?php esc_attr_e( 'Active', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Active', 'mainwp' ); ?></a>
						<a class="item" data-tab="inactive_plugins" data-value="inactive_plugins" title="<?php esc_attr_e( 'Inactive', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Inactive', 'mainwp' ); ?></a>
					</div>
				</div>
			</div>
		</div>
		<div class="ui section hidden divider"></div>
		<?php
		/**
		 * Action: mainwp_plugins_widget_top
		 *
		 * Fires at the top of the Plugins widget on the Individual site overview page.
		 *
		 * @param object $website    Object containing the child site info.
		 * @param array  $allPlugins Array containing all detected plugins data.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_plugins_widget_top', $website, $allPlugins );
		?>
		<div id="mainwp-widget-active-plugins" class="ui tab active" data-tab="active_plugins">
			<?php
			/**
			 * Action: mainwp_before_active_plugins_list
			 *
			 * Fires before the active plugins list in the Plugins widget on the Individual site overview page.
			 *
			 * @param object $website         Object containing the child site info.
			 * @param array  $actived_plugins Array containing all active plugins data.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_before_active_plugins_list', $website, $actived_plugins );
			?>
			<div class="ui divided selection list">
				<?php
				$_count = count( $actived_plugins );
				for ( $i = 0; $i < $_count; $i ++ ) {
					$slug = wp_strip_all_tags( $actived_plugins[ $i ]['slug'] );
					?>
					<div class="item">
						<input class="pluginSlug" type="hidden" name="slug" value="<?php echo esc_attr( wp_strip_all_tags( $actived_plugins[ $i ]['slug'] ) ); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr( $website->id ); ?>"/>
						<div class="right floated pluginsAction">
							<?php if ( mainwp_current_user_have_right( 'dashboard', 'activate_deactivate_plugins' ) ) { ?>
								<a href="#" class="mainwp-plugin-deactivate ui mini button green" data-position="top right" data-tooltip="<?php esc_attr_e( 'Deactivate the ', 'mainwp' ) . esc_html( $actived_plugins[ $i ]['name'] ) . esc_attr_e( ' plugin on the child site.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Deactivate', 'mainwp' ); ?></a>
							<?php } ?>
						</div>
						<div class="middle aligned content">
							<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . dirname( wp_strip_all_tags( $actived_plugins[ $i ]['slug'] ) ) . '&TB_iframe=true&width=640&height=477'; ?>" target="_blank" class="thickbox open-plugin-details-modal" title="More information about <?php echo wp_strip_all_tags( $actived_plugins[ $i ]['name'] ); ?>">
								<?php echo esc_html( $actived_plugins[ $i ]['name'] . ' ' . $actived_plugins[ $i ]['version'] ); ?>
							</a>
							</div>
						<div class="mainwp-row-actions-working">
							<i class="notched circle loading icon"></i> <?php esc_html_e( 'Please wait...', 'mainwp' ); ?>
						</div>
					</div>
					<?php } ?>
			</div>
			<?php
			/**
			 * Action: mainwp_after_active_plugins_list
			 *
			 * Fires after the active plugins list in the Plugins widget on the Individual site overview page.
			 *
			 * @param object $website         Object containing the child site info.
			 * @param array  $actived_plugins Array containing all active plugins data.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_after_active_plugins_list', $website, $actived_plugins );
			?>
		</div>
		<div id="mainwp-widget-inactive-plugins" class="ui tab" data-tab="inactive_plugins">
			<?php
			/**
			 * Action: mainwp_before_inactive_plugins_list
			 *
			 * Fires before the inactive plugins list in the Plugins widget on the Individual site overview page.
			 *
			 * @param object $website          Object containing the child site info.
			 * @param array  $inactive_plugins Array containing all active plugins data.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_before_inactive_plugins_list', $website, $inactive_plugins );
			?>
			<div class="ui middle aligned divided selection list">
				<?php
				$_count = count( $inactive_plugins );
				for ( $i = 0; $i < $_count; $i ++ ) {
					$slug = $inactive_plugins[ $i ]['slug'];
					?>
					<div class="item">
						<input class="pluginSlug" type="hidden" name="slug" value="<?php echo esc_attr( wp_strip_all_tags( $inactive_plugins[ $i ]['slug'] ) ); ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr( $website->id ); ?>"/>
						<div class="right floated content pluginsAction">
							<?php if ( mainwp_current_user_have_right( 'dashboard', 'activate_deactivate_plugins' ) ) { ?>
								<a href="#" class="mainwp-plugin-activate ui mini green button" data-position="top right" data-tooltip="<?php esc_attr_e( 'Activate the ', 'mainwp' ) . wp_strip_all_tags( $inactive_plugins[ $i ]['name'] ) . esc_attr_e( ' plugin on the child site.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Activate', 'mainwp' ); ?></a>
							<?php } ?>
							<?php if ( mainwp_current_user_have_right( 'dashboard', 'delete_plugins' ) ) { ?>
								<a href="#" class="mainwp-plugin-delete ui mini basic button" data-position="top right" data-tooltip="<?php esc_attr_e( 'Delete the ', 'mainwp' ) . wp_strip_all_tags( $inactive_plugins[ $i ]['name'] ) . esc_attr_e( ' plugin from the child site.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
							<?php } ?>
						</div>
						<div class="middle aligned content">
							<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . dirname( $inactive_plugins[ $i ]['slug'] ) . '&TB_iframe=true&width=640&height=477'; ?>" target="_blank" class="thickbox open-plugin-details-modal" title="More information about <?php echo wp_strip_all_tags( $inactive_plugins[ $i ]['name'] ); ?>">
								<?php echo esc_html( $inactive_plugins[ $i ]['name'] . ' ' . $inactive_plugins[ $i ]['version'] ); ?>
							</a>
						</div>
						<div class="mainwp-row-actions-working">
							<i class="ui active inline loader tiny"></i> <?php esc_html_e( 'Please wait...', 'mainwp' ); ?>
						</div>
					</div>
				<?php } ?>
			</div>
			<?php
			/**
			 * Action: mainwp_after_inactive_plugins_list
			 *
			 * Fires after the inactive plugins list in the Plugins widget on the Individual site overview page.
			 *
			 * @param object $website          Object containing the child site info.
			 * @param array  $inactive_plugins Array containing all active plugins data.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_after_inactive_plugins_list', $website, $inactive_plugins );
			?>
		</div>
		<?php
		/**
		 * Action: mainwp_plugins_widget_bottom
		 *
		 * Fires at the bottom of the Plugins widget on the Individual site overview page.
		 *
		 * @param object $website    Object containing the child site info.
		 * @param array  $allPlugins Array containing all detected plugins data.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_plugins_widget_bottom', $website, $allPlugins );
	}

	/**
	 * Method activate_plugin()
	 *
	 * Fire off Action activate & display result
	 */
	public static function activate_plugin() {
		self::action( 'activate' );
		die( wp_json_encode( array( 'result' => __( 'Plugin has been activated!', 'mainwp' ) ) ) );
	}

	/**
	 * Method deactivate_plugin()
	 *
	 * Fire off action deactivate & display result
	 */
	public static function deactivate_plugin() {
		self::action( 'deactivate' );
		die( wp_json_encode( array( 'result' => __( 'Plugin has been deactivated!', 'mainwp' ) ) ) );
	}

	/**
	 * Method delete_plugin()
	 *
	 * Fire off action delete & display result
	 */
	public static function delete_plugin() {
		self::action( 'delete' );
		die( wp_json_encode( array( 'result' => __( 'Plugin has been permanently deleted!', 'mainwp' ) ) ) );
	}

	/**
	 * Method action()
	 *
	 * Initiate try catch for chosen Action
	 *
	 * @param mixed $action Plugin Action.
	 */
	public static function action( $action ) {
		$plugin       = $_POST['plugin'];
		$websiteIdEnc = $_POST['websiteId'];

		if ( empty( $plugin ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		$websiteId = $websiteIdEnc;
		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			die( wp_json_encode( array( 'error' => __( 'You cannot edit this website.', 'mainwp' ) ) ) );
		}

		try {

			/**
			* Action: mainwp_before_plugin_action
			*
			* Fires before plugin activate/deactivate/delete actions.
			*
			* @since 4.1
			*/
			do_action( 'mainwp_before_plugin_action', $action, $plugin, $website );

			$information = MainWP_Connect::fetch_url_authed(
				$website,
				'plugin_action',
				array(
					'action' => $action,
					'plugin' => $plugin,
				)
			);

			/**
			* Action: mainwp_after_plugin_action
			*
			* Fires after plugin activate/deactivate/delete actions.
			*
			* @since 4.1
			*/
			do_action( 'mainwp_after_plugin_action', $information, $action, $plugin, $website );

		} catch ( MainWP_Exception $e ) {
			die( wp_json_encode( array( 'error' => MainWP_Error_Helper::get_error_message( $e ) ) ) );
		}

		if ( ! isset( $information['status'] ) || ( 'SUCCESS' !== $information['status'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Unexpected error occurred. Please try again.', 'mainwp' ) ) ) );
		}
	}

}
