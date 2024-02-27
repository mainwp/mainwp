<?php
/**
 * MainWP Updates Overview Widget
 *
 * Grab Child Sites update status & build widget.
 *
 * @package MainWP/Updates_Overview
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Updates_Overview
 *
 * @package MainWP\Dashboard
 */
class MainWP_Updates_Overview {

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
	 * Method init()
	 *
	 * Add plugins api filter.
	 */
	public static function init() {
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 10, 3 );
	}

	/**
	 * Method plugins_api()
	 *
	 * Grab Child Sites update status & build widget.
	 *
	 * @param mixed $default_value Default.
	 * @param mixed $action Action.
	 * @param mixed $args Slug.
	 *
	 * @return mixed $default|$res
	 */
	public static function plugins_api( $default_value, $action, $args ) {
		if ( property_exists( $args, 'slug' ) && ( 'mainwp' === $args->slug ) ) {
			return $default_value;
		}

		$url = 'http://api.wordpress.org/plugins/info/1.0/';
		$ssl = wp_http_supports( array( 'ssl' ) );
		if ( $ssl ) {
			$url = set_url_scheme( $url, 'https' );
		}

		$args    = array(
			'timeout' => 15,
			'body'    => array(
				'action'  => $action,
				'request'    => serialize( $args ), // phpcs:ignore -- WP.org API params.
			),
		);
		$request = wp_remote_post( $url, $args );

		if ( is_wp_error( $request ) ) {
			$url  = isset( $_REQUEST['url'] ) ? esc_url_raw( wp_unslash( $_REQUEST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$name = isset( $_REQUEST['name'] ) ? wp_unslash( $_REQUEST['name'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$res  = new \WP_Error( 'plugins_api_failed', esc_html__( '<h3>No plugin information found.</h3> This may be a premium plugin and no other details are available from WordPress.', 'mainwp' ) . ' ' . ( empty( $url ) ? esc_html__( 'Please visit the plugin website for more information.', 'mainwp' ) : esc_html__( 'Please visit the plugin website for more information: ', 'mainwp' ) . '<a href="' . esc_html( rawurldecode( $url ) ) . '" target="_blank">' . esc_html( rawurldecode( $name ) ) . '</a>' ), $request->get_error_message() );

			return $res;
		}

		return $default_value;
	}

	/**
	 * Method get_name()
	 *
	 * Define Widget Title.
	 */
	public static function get_name() {
		return esc_html__( 'Update Overview', 'mainwp' );
	}

	/**
	 * Method render()
	 *
	 * Check if $_GET['dashboard'] then run render_sites().
	 */
	public static function render() {
		self::render_sites();
	}

	/**
	 * Method render_sites()
	 *
	 * Grab available Child Sites updates a build Widget.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_last_sync_status()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
	 * @uses \MainWP\Dashboard\MainWP_Utility::format_timestamp()
	 */
	public static function render_sites() { // phpcs:ignore -- current complexity required to achieve desired results. Pull request solutions appreciated.

		$globalView = true;

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		$current_wpid = MainWP_System_Utility::get_current_wpid();

		if ( $current_wpid ) {
			$sql        = MainWP_DB::instance()->get_sql_website_by_id( $current_wpid, false, array( 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ) );
			$globalView = false;
		} else {
			$staging_enabled = is_plugin_active( 'mainwp-staging-extension/mainwp-staging-extension.php' ) || is_plugin_active( 'mainwp-timecapsule-extension/mainwp-timecapsule-extension.php' );
			// To support staging extension.
			$is_staging = 'no';
			if ( $staging_enabled ) {
				$staging_updates_view = MainWP_System_Utility::get_staging_options_sites_view_for_current_users();
				if ( 'staging' === $staging_updates_view ) {
					$is_staging = 'yes';
				}
			}
			// end support.

			$sql = MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ), $is_staging );
		}

		$userExtension = MainWP_DB_Common::instance()->get_user_extension();
		$websites      = MainWP_DB::instance()->query( $sql );

		$mainwp_show_language_updates = get_option( 'mainwp_show_language_updates', 1 );

		$decodedDismissedPlugins = ! empty( $userExtension->dismissed_plugins ) ? json_decode( $userExtension->dismissed_plugins, true ) : array();
		$decodedDismissedThemes  = ! empty( $userExtension->dismissed_themes ) ? json_decode( $userExtension->dismissed_themes, true ) : array();

		$total_wp_upgrades          = 0;
		$total_plugin_upgrades      = 0;
		$total_translation_upgrades = 0;
		$total_theme_upgrades       = 0;

		$total_plugins_outdate = 0;
		$total_themes_outdate  = 0;

		$all_wp_updates           = array();
		$all_plugins_updates      = array();
		$all_themes_updates       = array();
		$all_translations_updates = array();

		MainWP_DB::data_seek( $websites, 0 );

		$currentSite = null;

		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
			if ( ! $globalView ) {
				$currentSite = $website;
			}

			$pluginsIgnoredAbandoned_perSites = array();
			$themesIgnoredAbandoned_perSites  = array();

			$wp_upgrades = MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' );
			$wp_upgrades = ! empty( $wp_upgrades ) ? json_decode( $wp_upgrades, true ) : array();

			if ( $website->is_ignoreCoreUpdates ) {
				$wp_upgrades = array();
			}

			if ( is_array( $wp_upgrades ) && count( $wp_upgrades ) > 0 ) {
				++$total_wp_upgrades;
				$all_wp_updates[] = array(
					'id'   => $website->id,
					'name' => $website->name,
				);
			}

			$translation_upgrades = ! empty( $website->translation_upgrades ) ? json_decode( $website->translation_upgrades, true ) : array();

			$plugin_upgrades = ! empty( $website->plugin_upgrades ) ? json_decode( $website->plugin_upgrades, true ) : array();

			if ( $website->is_ignorePluginUpdates ) {
				$plugin_upgrades = array();
			}

			$theme_upgrades = ! empty( $website->theme_upgrades ) ? json_decode( $website->theme_upgrades, true ) : array();
			if ( $website->is_ignoreThemeUpdates ) {
				$theme_upgrades = array();
			}

			$decodedPremiumUpgrades = MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' );
			$decodedPremiumUpgrades = ! empty( $decodedPremiumUpgrades ) ? json_decode( $decodedPremiumUpgrades, true ) : array();

			if ( is_array( $decodedPremiumUpgrades ) ) {
				foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
					$premiumUpgrade['premium'] = true;

					if ( 'plugin' === $premiumUpgrade['type'] ) {
						if ( ! is_array( $plugin_upgrades ) ) {
							$plugin_upgrades = array();
						}
						if ( ! $website->is_ignorePluginUpdates ) {

							$premiumUpgrade = array_filter( $premiumUpgrade );
							if ( ! isset( $plugin_upgrades[ $crrSlug ] ) ) {
								$plugin_upgrades[ $crrSlug ] = array();
							}

							$plugin_upgrades[ $crrSlug ] = array_merge( $plugin_upgrades[ $crrSlug ], $premiumUpgrade );
						}
					} elseif ( 'theme' === $premiumUpgrade['type'] ) {
						if ( ! is_array( $theme_upgrades ) ) {
							$theme_upgrades = array();
						}
						if ( ! $website->is_ignoreThemeUpdates ) {
							$theme_upgrades[ $crrSlug ] = $premiumUpgrade;
						}
					}
				}
			}

			if ( is_array( $translation_upgrades ) ) {

				$total_translation_upgrades += count( $translation_upgrades );

				if ( count( $translation_upgrades ) > 0 ) {
					foreach ( $translation_upgrades as $trans_upgrade ) {
						$slug                       = $trans_upgrade['slug'];
						$all_translations_updates[] = array(
							'id'               => $website->id,
							'name'             => $website->name,
							'translation_slug' => $slug,
						);
					}
				}
			}

			if ( is_array( $plugin_upgrades ) ) {
				$ignored_plugins = ! empty( $website->ignored_plugins ) ? json_decode( $website->ignored_plugins, true ) : array();
				if ( is_array( $ignored_plugins ) ) {
					$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
				}

				$ignored_plugins = ! empty( $userExtension->ignored_plugins ) ? json_decode( $userExtension->ignored_plugins, true ) : array();
				if ( is_array( $ignored_plugins ) ) {
					$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
				}

				$total_plugin_upgrades += count( $plugin_upgrades );

				if ( count( $plugin_upgrades ) > 0 ) {
					foreach ( $plugin_upgrades as $slug => $value ) {
						$all_plugins_updates[] = array(
							'id'          => $website->id,
							'name'        => $website->name,
							'plugin_slug' => $slug,
						);
					}
				}
			}

			if ( is_array( $theme_upgrades ) ) {
				$ignored_themes = ! empty( $website->ignored_themes ) ? json_decode( $website->ignored_themes, true ) : array();
				if ( is_array( $ignored_themes ) ) {
					$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
				}

				$ignored_themes = ! empty( $userExtension->ignored_themes ) ? json_decode( $userExtension->ignored_themes, true ) : array();
				if ( is_array( $ignored_themes ) ) {
					$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
				}

				$total_theme_upgrades += count( $theme_upgrades );

				if ( count( $theme_upgrades ) > 0 ) {
					foreach ( $theme_upgrades as $slug => $value ) {
						$all_themes_updates[] = array(
							'id'         => $website->id,
							'name'       => $website->name,
							'theme_slug' => $slug,
						);
					}
				}
			}

			$pluginsIgnoredAbandoned_perSites = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_dismissed' );
			$pluginsIgnoredAbandoned_perSites = ! empty( $pluginsIgnoredAbandoned_perSites ) ? json_decode( $pluginsIgnoredAbandoned_perSites, true ) : array();
			if ( is_array( $pluginsIgnoredAbandoned_perSites ) ) {
				$pluginsIgnoredAbandoned_perSites = array_filter( $pluginsIgnoredAbandoned_perSites );
			}

			$themesIgnoredAbandoned_perSites = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' );
			$themesIgnoredAbandoned_perSites = ! empty( $themesIgnoredAbandoned_perSites ) ? json_decode( $themesIgnoredAbandoned_perSites, true ) : array();
			if ( is_array( $themesIgnoredAbandoned_perSites ) ) {
				$themesIgnoredAbandoned_perSites = array_filter( $themesIgnoredAbandoned_perSites );
			}

			$plugins_outdate = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' );
			$plugins_outdate = ! empty( $plugins_outdate ) ? json_decode( $plugins_outdate, true ) : array();

			$themes_outdate = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' );
			$themes_outdate = ! empty( $themes_outdate ) ? json_decode( $themes_outdate, true ) : array();

			if ( is_array( $plugins_outdate ) ) {
				if ( is_array( $pluginsIgnoredAbandoned_perSites ) ) {
					$plugins_outdate = array_diff_key( $plugins_outdate, $pluginsIgnoredAbandoned_perSites );
				}

				if ( is_array( $decodedDismissedPlugins ) ) {
					$plugins_outdate = array_diff_key( $plugins_outdate, $decodedDismissedPlugins );
				}

				$total_plugins_outdate += count( $plugins_outdate );
			}

			if ( is_array( $themes_outdate ) ) {
				if ( is_array( $themesIgnoredAbandoned_perSites ) ) {
					$themes_outdate = array_diff_key( $themes_outdate, $themesIgnoredAbandoned_perSites );
				}

				if ( is_array( $decodedDismissedThemes ) ) {
					$themes_outdate = array_diff_key( $themes_outdate, $decodedDismissedThemes );
				}

				$total_themes_outdate += count( $themes_outdate );
			}
		}

		// WP Upgrades part.
		$total_upgrades = $total_wp_upgrades + $total_plugin_upgrades + $total_theme_upgrades;

		// to fix incorrect total updates.
		if ( $mainwp_show_language_updates ) {
			$total_upgrades += $total_translation_upgrades;
		}

		$trustedPlugins = ! empty( $userExtension->trusted_plugins ) ? json_decode( $userExtension->trusted_plugins, true ) : array();
		if ( ! is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
		}
		$trustedThemes = ! empty( $userExtension->trusted_themes ) ? json_decode( $userExtension->trusted_themes, true ) : array();
		if ( ! is_array( $trustedThemes ) ) {
			$trustedThemes = array();
		}

		/**
		 * Filter: mainwp_limit_updates_all
		 *
		 * Limits the number of updates that will be processed in a single run on Update Everything action.
		 *
		 * @since 4.0
		 */
		$limit_updates_all = apply_filters( 'mainwp_limit_updates_all', 0 );

		if ( ! $globalView ) {
			$last_dtsSync = $currentSite->dtsSync;
		} else {
			$result       = MainWP_DB_Common::instance()->get_last_sync_status();
			$sync_status  = $result['sync_status'];
			$last_sync    = $result['last_sync'];
			$last_dtsSync = $result['last_sync'];

			if ( 'all_synced' === $sync_status ) {
				$last_dtsSync = get_option( 'mainwp_last_synced_all_sites', $last_sync );
			}
		}

		$lastSyncMsg = '';
		if ( $last_dtsSync ) {
			$lastSyncMsg = esc_html__( 'Last successfully completed synchronization: ', 'mainwp' ) . MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $last_dtsSync ) );
		}

		$user_can_update_translation = mainwp_current_user_have_right( 'dashboard', 'update_translations' );
		$user_can_update_wordpress   = mainwp_current_user_have_right( 'dashboard', 'update_wordpress' );
		$user_can_update_themes      = mainwp_current_user_have_right( 'dashboard', 'update_themes' );
		$user_can_update_plugins     = mainwp_current_user_have_right( 'dashboard', 'update_plugins' );

		$can_total_update = ( $user_can_update_wordpress && $user_can_update_plugins && $user_can_update_themes && $user_can_update_translation ) ? true : false;

		self::render_total_update( $total_upgrades, $lastSyncMsg, $can_total_update, $limit_updates_all );
		echo '<div class="mainwp-scrolly-overflow">';
		self::render_wordpress_update( $user_can_update_wordpress, $total_wp_upgrades, $globalView, $current_wpid );
		self::render_plugins_update( $user_can_update_plugins, $total_plugin_upgrades, $globalView, $current_wpid );
		self::render_themes_update( $user_can_update_themes, $total_theme_upgrades, $globalView, $current_wpid );
		if ( 1 === (int) $mainwp_show_language_updates ) {
			self::render_language_update( $user_can_update_translation, $total_translation_upgrades, $globalView, $current_wpid );
		}
		self::render_abandoned_plugins( $total_plugins_outdate, $globalView, $current_wpid );
		self::render_abandoned_themes( $total_themes_outdate, $globalView, $current_wpid );

		self::render_global_update(
			$user_can_update_wordpress,
			$total_wp_upgrades,
			$all_wp_updates,
			$user_can_update_plugins,
			$total_plugin_upgrades,
			$all_plugins_updates,
			$user_can_update_themes,
			$total_theme_upgrades,
			$all_themes_updates,
			$mainwp_show_language_updates,
			$user_can_update_translation,
			$total_translation_upgrades,
			$all_translations_updates
		);
		self::render_bottom( $websites, $globalView );
		echo '</div>';
		echo '<div class="ui stackable grid mainwp-widget-footer">';
		echo '<div class="eight wide column"></div>';
		echo '<div class="eight wide column"></div>';
		echo '</div>';
	}

	/**
	 * Render total update.
	 *
	 * @param int             $total_upgrades number of update.
	 * @param string          $lastSyncMsg last sync info.
	 * @param bool true|false $can_total_update permission to update all.
	 * @param int             $limit_updates_all limit number of update per request, 0 is no limit.
	 */
	public static function render_total_update( $total_upgrades, $lastSyncMsg, $can_total_update, $limit_updates_all ) {
		$current_wpid = MainWP_System_Utility::get_current_wpid();
		$globalView   = true;
		if ( $current_wpid ) {
			$globalView = false;
		}
		?>
		<div class="ui grid mainwp-widget-header">
			<div class="sixteen wide column">
				<h3 class="ui header handle-drag">
					<?php
					/**
					 * Filter: mainwp_updates_overview_widget_title
					 *
					 * Filters the Updates Overview widget title text.
					 *
					 * @since 4.1
					 */
					echo esc_html( apply_filters( 'mainwp_updates_overview_widget_title', esc_html__( 'Updates Overview', 'mainwp' ) ) );
					?>
					<div class="sub header"><?php echo $lastSyncMsg; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				</h3>
			</div>
		</div>

		<input type="hidden" name="updatesoverview_limit_updates_all" id="updatesoverview_limit_updates_all" value="<?php echo intval( $limit_updates_all ); ?>">
			<?php
			/**
			 * Action: mainwp_updates_overview_before_total_updates
			 *
			 * Fires before the total updates section in the Updates Overview widget.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_overview_before_total_updates' );
			?>
			<div class="ui stackable grid">
			
				<div class="eight wide middle aligned column">
					
						<div class="ui large statistic horizontal">
							<div class="value">
								<?php echo intval( $total_upgrades ); ?>
							</div>
							<div class="label">
								<?php esc_html_e( 'Total Updates', 'mainwp' ); ?>
							</div>
						</div>
				</div>

				<div class="eight wide middle aligned right aligned column">
				<?php
				/**
				 * Filter:  mainwp_update_everything_button_text
				 *
				 * Filters the Update Everything button text.
				 *
				 * @since 4.1
				 */

				$is_demo = MainWP_Demo_Handle::is_demo_mode();
				?>
				<?php if ( $can_total_update ) : ?>
					<?php
					if ( ! get_option( 'mainwp_hide_update_everything', false ) ) :
						if ( $is_demo ) {
							MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui big button green fluid disabled" disabled="disabled">' . esc_html( apply_filters( 'mainwp_update_everything_button_text', esc_html__( 'Update Everything', 'mainwp' ) ) ) . '</a>' );
						} else {
							?>
							<a href="#" <?php echo empty( $total_upgrades ) ? 'disabled' : 'onClick="return updatesoverview_global_upgrade_all( \'all\' );"'; ?> class="ui big button fluid green" id="mainwp-update-everything-button" data-tooltip="<?php $globalView ? esc_attr_e( 'Clicking this button will update all Plugins, Themes, WP Core files and translations on ALL your websites.', 'mainwp' ) : esc_attr_e( 'Clicking this button will update all Plugins, Themes, WP Core files and translations on this website.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><?php echo esc_html( apply_filters( 'mainwp_update_everything_button_text', esc_html__( 'Update Everything', 'mainwp' ) ) ); ?></a>
						<?php } ?>
					<?php endif; ?>
				<?php endif; ?>
				</div>
			</div>
		<?php
		/**
		 * Action: mainwp_updates_overview_after_total_updates
		 *
		 * Fires after the total updates section in the Updates Overview widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_overview_after_total_updates' );
	}

	/**
	 * Render WordPress update details.
	 *
	 * @param bool $user_can_update_wordpress Permission to update WordPress.
	 * @param int  $total_wp_upgrades         Total number of WordPress update.
	 * @param bool $globalView                Global view or not.
	 * @param int  $current_wpid              Current site ID.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Updates::set_continue_update_html_selector()
	 * @uses \MainWP\Dashboard\MainWP_Updates::get_continue_update_selector()
	 */
	public static function render_wordpress_update( $user_can_update_wordpress, $total_wp_upgrades, $globalView, $current_wpid ) {
		?>
		<div class="ui hidden divider"></div>
		<div class="ui horizontal divider">
			<?php
			/**
			 * Filter: mainwp_updates_overview_update_details_divider
			 *
			 * Filters the Update Details divider text in the Updates Overview widget.
			 *
			 * @since 4.1
			 */
			echo esc_html( apply_filters( 'mainwp_updates_overview_update_details_divider', esc_html__( 'Available Updates', 'mainwp' ) ) );
			?>
		</div>
		<div class="ui hidden divider"></div>
		<?php
		/**
		 * Action: mainwp_updates_overview_before_update_details
		 *
		 * Fires at the top of the Update Details section in the Updates Overview widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_overview_before_update_details' );

		/**
		 * Action: mainwp_updates_overview_before_wordpress_updates
		 *
		 * Fires before the WordPress updates section in the Updates Overview widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_overview_before_wordpress_updates' );

		$is_demo = MainWP_Demo_Handle::is_demo_mode();
		?>
		<div class="ui grid">
			<div class="two column row">

				<div class="six wide column">
					<div class="ui horizontal statistic">
					<div class="value">
						<?php echo intval( $total_wp_upgrades ); ?>
					</div>
					<div class="label">
					<?php esc_html_e( 'WordPress', 'mainwp' ); ?>
					</div>
					</div>
				</div>

				<div class="ten wide right aligned column">
				<div class="ui small buttons">
				<?php
				if ( $user_can_update_wordpress ) :
					$wpcore_update_disabled_by = '';
					if ( $globalView ) {
						$detail_wp_up = 'admin.php?page=UpdatesManage&tab=wordpress-updates';
					} else {
						$detail_wp_up              = 'admin.php?page=managesites&updateid=' . $current_wpid . '&tab=wordpress-updates';
						$wpcore_update_disabled_by = MainWP_System_Utility::disabled_wpcore_update_by( $current_wpid );
					}
					?>
					<?php if ( 0 < $total_wp_upgrades ) : ?>
						<?php MainWP_Updates::set_continue_update_html_selector( 'wpcore_global_upgrade_all' ); ?>
						<a href="<?php echo esc_url( $detail_wp_up ); ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
						<?php
						if ( ! empty( $wpcore_update_disabled_by ) ) {
							?>
							<span data-tooltip="<?php echo esc_html( $wpcore_update_disabled_by ); ?>" data-inverted="" data-position="left center"><a href="javascript:void(0)"  class="ui button basic green disabled mainwp-update-all-button"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a></span>
							<?php
						} elseif ( $is_demo ) {
								MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green basic button disabled mainwp-update-all-button" disabled="disabled">' . esc_html__( 'Update All', 'mainwp' ) . '</a>' );
						} else {
							?>
							<a href="#" onClick="return updatesoverview_global_upgrade_all('wp');" class="ui green basic button mainwp-update-all-button <?php MainWP_Updates::get_continue_update_selector(); // phpcs:ignore WordPress.Security.EscapeOutput ?>" data-tooltip="<?php esc_html_e( 'Clicking this button will update WP Core files on All your websites.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
							<?php
						}
						?>
					<?php else : ?>
						
							<a href="<?php echo esc_url( $detail_wp_up ); ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
							<a href="#" class="ui disabled green basic button mainwp-update-all-button"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
						
					<?php endif; ?>
				<?php endif; ?>
				</div>
			</div>
			</div>
		</div>
		<?php
		/**
		 * Action: mainwp_updates_overview_after_wordpress_updates
		 *
		 * Fires after the WordPress updates section in the Updates Overview widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_overview_after_wordpress_updates' );
	}

	/**
	 * Render Plugins update detail.
	 *
	 * @param bool true|false $user_can_update_plugins permission to update.
	 * @param int             $total_plugin_upgrades  total number of update.
	 * @param bool true|false $globalView global view or not.
	 * @param int             $current_wpid  current site id.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Updates::set_continue_update_html_selector()
	 * @uses \MainWP\Dashboard\MainWP_Updates::get_continue_update_selector()
	 */
	public static function render_plugins_update( $user_can_update_plugins, $total_plugin_upgrades, $globalView, $current_wpid ) {
		/**
		 * Action: mainwp_updates_overview_before_plugin_updates
		 *
		 * Fires before the Plugin updates section in the Updates Overview widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_overview_before_plugin_updates' );
		$is_demo = MainWP_Demo_Handle::is_demo_mode();
		?>
	<div class="ui grid">
		<div class="two column row">
			<div class="six wide column">
				<div class="ui horizontal statistic">
						<div class="value">
							<?php echo intval( $total_plugin_upgrades ); ?>
						</div>
							<div class="label">
						<?php esc_html_e( 'Plugins', 'mainwp' ); ?>
					</div>
					</div>
				</div>
			<div class="ten wide right aligned column">
			<div class="ui small buttons">
				<?php
				if ( $user_can_update_plugins ) {
						MainWP_Updates::set_continue_update_html_selector( 'plugins_global_upgrade_all' );
					if ( $globalView ) {
						$detail_plugins_up  = 'admin.php?page=UpdatesManage&tab=plugins-updates';
						$update_all_tooltip = esc_html__( 'Clicking this button will update all Plugins on All your websites.', 'mainwp' );
					} else {
						$detail_plugins_up  = 'admin.php?page=managesites&updateid=' . $current_wpid . '&tab=plugins-updates';
						$update_all_tooltip = esc_html__( 'Clicking this button will update all Plugins on the website.', 'mainwp' );
					}

					if ( empty( $total_plugin_upgrades ) ) {
						?>
							<a href="<?php echo esc_url( $detail_plugins_up ); ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
								<a href="#" class="ui disabled green basic button mainwp-update-all-button"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
						<?php
					} else {
						?>
						<a href="<?php echo esc_url( $detail_plugins_up ); ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
						<?php
						if ( $is_demo ) {
							MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui basic green button mainwp-update-all-button disabled" disabled="disabled">' . esc_html__( 'Update All', 'mainwp' ) . '</a>' );
						} else {
							?>
							<a href="#" onClick="return updatesoverview_global_upgrade_all('plugin');" class="ui basic green mainwp-update-all-button button <?php MainWP_Updates::get_continue_update_selector(); // phpcs:ignore WordPress.Security.EscapeOutput ?>" data-tooltip="<?php echo esc_attr( $update_all_tooltip ); ?>" data-inverted="" data-position="top center"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
							<?php
						}
					}
				}
				?>
							</div>
			</div>
						</div>
				</div>
		<?php
		/**
		 * Action: mainwp_updates_overview_after_plugin_updates
		 *
		 * Fires after the Plugin updates section in the Updates Overview widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_overview_after_plugin_updates' );
	}

	/**
	 * Render Themes update detail.
	 *
	 * @param bool true|false $user_can_update_themes permission to update.
	 * @param int             $total_theme_upgrades  total number of update.
	 * @param bool true|false $globalView global view or not.
	 * @param int             $current_wpid  current site id.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Updates::set_continue_update_html_selector()
	 * @uses \MainWP\Dashboard\MainWP_Updates::get_continue_update_selector()
	 */
	public static function render_themes_update( $user_can_update_themes, $total_theme_upgrades, $globalView, $current_wpid ) {
		/**
		 * Action: mainwp_updates_overview_before_theme_updates
		 *
		 * Fires before the Theme updates section in the Updates Overview widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_overview_before_theme_updates' );
		$is_demo = MainWP_Demo_Handle::is_demo_mode();
		?>
	<div class="ui grid">
		<div class="two column row">
			<div class="six wide column">
				<div class="ui horizontal statistic">
					<div class="value">
						<?php echo intval( $total_theme_upgrades ); ?>
					</div>
					<div class="label">
						<?php esc_html_e( 'Themes', 'mainwp' ); ?>
					</div>
				</div>
			</div>
			<div class="ten wide right aligned column">
			<div class="ui small buttons">
			<?php
			if ( $user_can_update_themes ) {
				MainWP_Updates::set_continue_update_html_selector( 'themes_global_upgrade_all' );
				if ( $globalView ) {
					$detail_themes_up = 'admin.php?page=UpdatesManage&tab=themes-updates';
				} else {
					$detail_themes_up = 'admin.php?page=managesites&updateid=' . $current_wpid . '&tab=themes-updates';
				}
				if ( empty( $total_theme_upgrades ) ) {
					?>
					<a href="<?php echo esc_url( $detail_themes_up ); ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
					<a href="#" class="ui disabled green basic mainwp-update-all-button button"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
					<?php
				} else {
					?>
					<a href="<?php echo esc_url( $detail_themes_up ); ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
					<?php
					if ( $is_demo ) {
						MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui basic green button mainwp-update-all-button disabled" disabled="disabled">' . esc_html__( 'Update All', 'mainwp' ) . '</a>' );
					} else {
						?>
						<a href="#" onClick="return updatesoverview_global_upgrade_all('theme');" class="ui basic green mainwp-update-all-button button <?php MainWP_Updates::get_continue_update_selector(); // phpcs:ignore WordPress.Security.EscapeOutput ?>" data-tooltip="<?php esc_html_e( 'Clicking this button will update all Themes on All your websites.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
						<?php
					}
				}
			}
			?>
				</div>
		</div>
			</div>
		</div>
		<?php
		/**
		 * Action: mainwp_updates_overview_after_theme_updates
		 *
		 * Fires after the Theme updates section in the Updates Overview widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_overview_after_theme_updates' );
	}
	/**
	 * Render language update details.
	 *
	 * @param bool $user_can_update_translation Permission to update.
	 * @param int  $total_translation_upgrades  Total number of update.
	 * @param bool $globalView                  Global view or not.
	 * @param int  $current_wpid                Current site id.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Updates::set_continue_update_html_selector()
	 * @uses \MainWP\Dashboard\MainWP_Updates::get_continue_update_selector()
	 */
	public static function render_language_update( $user_can_update_translation, $total_translation_upgrades, $globalView, $current_wpid ) {
		/**
		 * Action: mainwp_updates_overview_before_translation_updates
		 *
		 * Fires before the Translation updates section in the Updates Overview widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_overview_before_translation_updates' );
		$is_demo = MainWP_Demo_Handle::is_demo_mode();
		?>
	<div class="ui grid">
		<div class="two column row">
			<div class="six wide column">
				<div class="ui horizontal statistic">
					<div class="value">
						<?php echo intval( $total_translation_upgrades ); ?>
					</div>
					<div class="label">
						<?php esc_html_e( 'Translations', 'mainwp' ); ?>
					</div>
				</div>
			</div>
			<div class="ten wide right aligned column">
			<div class="ui small buttons">
			<?php
			if ( $user_can_update_translation ) {
				MainWP_Updates::set_continue_update_html_selector( 'translations_global_upgrade_all' );
				if ( $globalView ) {
					$detail_trans_up = 'admin.php?page=UpdatesManage&tab=translations-updates';
				} else {
					$detail_trans_up = 'admin.php?page=managesites&updateid=' . $current_wpid . '&tab=translations-updates';
				}
				if ( empty( $total_translation_upgrades ) ) {
					?>
					<a href="<?php echo esc_url( $detail_trans_up ); ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
					<a href="#" class="ui disabled green basic mainwp-update-all-button button"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
					<?php
				} else {
					?>
					<a href="<?php echo esc_url( $detail_trans_up ); ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
					<?php
					if ( $is_demo ) {
						MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui basic green button mainwp-update-all-button disabled" disabled="disabled">' . esc_html__( 'Update All', 'mainwp' ) . '</a>' );
					} else {
						?>
						<a href="#" onClick="return updatesoverview_global_upgrade_all('translation');" class="ui basic green mainwp-update-all-button button <?php MainWP_Updates::get_continue_update_selector(); // phpcs:ignore WordPress.Security.EscapeOutput ?>" data-tooltip="<?php esc_html_e( 'Clicking this button will update all Translations on All your websites.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
						<?php
					}
				}
			}
			?>
				</div>
			</div>
		</div>
		</div>
		<?php
		/**
		 * Action: mainwp_updates_overview_after_translation_updates
		 *
		 * Fires after the Translation updates section in the Updates Overview widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_overview_after_translation_updates' );

		/**
		 * Action: mainwp_updates_overview_after_update_details
		 *
		 * Fires at the bottom of the Update Details section in the Updates Overview widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_overview_after_update_details' );
	}

	/**
	 * Render abandoned plugins detail.
	 *
	 * @param int  $total_plugins_outdate  total number of update.
	 * @param bool $globalView global view or not.
	 * @param int  $current_wpid  current site id.
	 */
	public static function render_abandoned_plugins( $total_plugins_outdate, $globalView, $current_wpid ) {
		?>
		<div class="ui hidden divider"></div>
		<div class="ui horizontal divider">
		<?php
		/**
		 * Filter: mainwp_updates_overview_abandoned_plugins_themes_divider
		 *
		 * Filters the Abandoned Plugins & Themes divider text in the Updates Overview widget.
		 *
		 * @since 4.1
		 */
		echo esc_html( apply_filters( 'mainwp_updates_overview_abandoned_plugins_themes_divider', esc_html__( 'Abandoned Plugins & Themes', 'mainwp' ) ) );
		?>
		</div>
		<div class="ui hidden divider"></div>
		<?php
		/**
		 * Action: mainwp_updates_overview_before_abandoned_plugins_themes
		 *
		 * Fires at the top of the Abandoned Plugins & Themes section in the Updates Overview widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_overview_before_abandoned_plugins_themes' );
		?>
		<div class="ui grid">
			<div class="two column row">
				<div class="six wide column">
					<div class="ui horizontal statistic">
						<?php
						if ( $globalView ) {
							$detail_aban_plugins = 'admin.php?page=UpdatesManage&tab=abandoned-plugins';
						} else {
							$detail_aban_plugins = 'admin.php?page=managesites&updateid=' . $current_wpid . '&tab=abandoned-plugins';
						}
						?>
					<div class="value">
					<?php echo intval( $total_plugins_outdate ); ?>
					</div>
					<div class="label">
					<?php esc_html_e( 'Plugins', 'mainwp' ); ?>
					</div>
					</div>
						</div>
						<div class="ten wide right aligned column">
							<a href="<?php echo esc_url( $detail_aban_plugins ); ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
						</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render abandoned themes detail.
	 *
	 * @param int  $total_themes_outdate Total number of update.
	 * @param bool $globalView global    View or not.
	 * @param int  $current_wpid         Current site ID.
	 */
	public static function render_abandoned_themes( $total_themes_outdate, $globalView, $current_wpid ) {
		?>
		<div class="ui grid">
			<div class="two column row">
				<div class="six wide column">
					<div class="ui horizontal statistic">
						<?php
						if ( $globalView ) {
							$detail_aban_themes = 'admin.php?page=UpdatesManage&tab=abandoned-themes';
						} else {
							$detail_aban_themes = 'admin.php?page=managesites&updateid=' . $current_wpid . '&tab=abandoned-themes';
						}
						?>
						<div class="value">
							<?php echo intval( $total_themes_outdate ); ?>
							</div>
						<div class="label">
							<?php esc_html_e( 'Themes', 'mainwp' ); ?>
						</div>
					</div>
				</div>
				<div class="ten wide right aligned column">
					<a href="<?php echo esc_url( $detail_aban_themes ); ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
				</div>
			</div>
		</div>
		<?php
		/**
		 * Action: mainwp_updates_overview_after_abandoned_plugins_themes
		 *
		 * Fires at the bottom of the Abandoned Plugins & Themes section in the Updates Overview widget.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_overview_after_abandoned_plugins_themes' );
	}

	/**
	 * Method render_global_update()
	 *
	 * Render global updates.
	 *
	 * @param bool  $user_can_update_wordpress Permission to update WordPress.
	 * @param int   $total_wp_upgrades         Total WordPress update.
	 * @param array $all_wp_updates            All WordPress update list.
	 *
	 * @param bool  $user_can_update_plugins permission to update plugings.
	 * @param int   $total_plugin_upgrades total WordPress update.
	 * @param array $all_plugins_updates all WordPress update list.
	 *
	 * @param bool  $user_can_update_themes permission to update themes.
	 * @param int   $total_theme_upgrades total themes update.
	 * @param mixed $all_themes_updates all themes update list.
	 *
	 * @param mixed $mainwp_show_language_updates MainWP Language Updates.
	 * @param bool  $user_can_update_translation permission to update languages.
	 * @param int   $total_translation_upgrades total WordPress update.
	 * @param mixed $all_translations_updates all transations update list.
	 */
	public static function render_global_update(
		$user_can_update_wordpress,
		$total_wp_upgrades,
		$all_wp_updates,
		$user_can_update_plugins,
		$total_plugin_upgrades,
		$all_plugins_updates,
		$user_can_update_themes,
		$total_theme_upgrades,
		$all_themes_updates,
		$mainwp_show_language_updates,
		$user_can_update_translation,
		$total_translation_upgrades,
		$all_translations_updates
	) {
		?>
		<div style="display: none">

			<div id="wp_upgrades">
				<?php
				if ( $user_can_update_wordpress && $total_wp_upgrades > 0 ) {
					foreach ( $all_wp_updates as $item ) {
						?>
						<div updated="0" site_id="<?php echo intval( $item['id'] ); ?>" site_name="<?php echo esc_attr( $item['name'] ); ?>" ></div>
						<?php
					}
				}
				?>
			</div>

			<div id="wp_plugin_upgrades">
				<?php
				if ( $user_can_update_plugins && $total_plugin_upgrades > 0 ) {
					foreach ( $all_plugins_updates as $item ) {
						?>
						<div updated="0" site_id="<?php echo intval( $item['id'] ); ?>" site_name="<?php echo esc_attr( $item['name'] ); ?>" plugin_slug="<?php echo esc_attr( $item['plugin_slug'] ); ?>" ></div>
							<?php
					}
				}
				?>
			</div>
			<div id="wp_theme_upgrades">

				<?php
				if ( $user_can_update_themes && $total_theme_upgrades > 0 ) {
					foreach ( $all_themes_updates as $item ) {
						?>
						<div updated="0" site_id="<?php echo intval( $item['id'] ); ?>" site_name="<?php echo esc_attr( $item['name'] ); ?>" theme_slug="<?php echo esc_attr( $item['theme_slug'] ); ?>" ></div>
							<?php
					}
				}
				?>

			</div>
			<?php if ( 1 === (int) $mainwp_show_language_updates ) : ?>
			<div id="wp_translation_upgrades">

				<?php
				if ( $user_can_update_translation && $total_translation_upgrades > 0 ) {
					foreach ( $all_translations_updates as $item ) {
						?>
						<div updated="0" site_id="<?php echo intval( $item['id'] ); ?>" site_name="<?php echo esc_attr( $item['name'] ); ?>" translation_slug="<?php echo esc_attr( $item['translation_slug'] ); ?>" ></div>
						<?php
					}
				}
				?>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render bottom of widget.
	 *
	 * @param object $websites   Object containing child sites info.
	 * @param bool   $globalView Whether it's global or individual site view.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 */
	public static function render_bottom( $websites, $globalView ) {

		MainWP_DB::data_seek( $websites, 0 );

		$site_ids = array();
		while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
			$site_ids[] = $website->id;
		}

		/**
		 * Action: mainwp_updatesoverview_widget_bottom
		 *
		 * Fires at the bottom of the Updates Overview widgets.
		 *
		 * @param array $side_ids Array of sites IDs.
		 * @param bool  $globalView Whether it's global or individual site view.
		 *
		 * @since 4.0
		 */
		do_action( 'mainwp_updatesoverview_widget_bottom', $site_ids, $globalView );
		?>
		<div class="ui modal" id="updatesoverview-backup-box" tabindex="0">
				<div class="header"><?php esc_html_e( 'Backup Check', 'mainwp' ); ?></div>
				<div class="scrolling content mainwp-modal-content"></div>
				<div class="actions mainwp-modal-actions">
					<input id="updatesoverview-backup-all" type="button" name="Backup All" value="<?php esc_html_e( 'Backup All', 'mainwp' ); ?>" class="button-primary"/>
					<a id="updatesoverview-backup-now" href="#" target="_blank" style="display: none"  class="button-primary button"><?php esc_html_e( 'Backup Now', 'mainwp' ); ?></a>&nbsp;
					<input id="updatesoverview-backup-ignore" type="button" name="Ignore" value="<?php esc_html_e( 'Ignore', 'mainwp' ); ?>" class="button"/>
				</div>
		</div>

		<?php
		MainWP_DB::free_result( $websites );
	}

	/**
	 * Method dismiss_sync_errors()
	 *
	 * @param bool $dismiss true|false.
	 *
	 * @return bool true
	 */
	public static function dismiss_sync_errors( $dismiss = true ) {
		global $current_user;
		update_user_option( $current_user->ID, 'mainwp_syncerrors_dismissed', $dismiss );

		return true;
	}

	/**
	 * Method checkbackups()
	 *
	 * Check if Child Site needs to be backed up before updates.
	 *
	 * @return mixed $output
	 *
	 * @uses \MainWP\Dashboard\MainWP_Backup_Handler::is_archive()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_primary_backup()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_specific_dir()
	 */
	public static function check_backups() {
		if ( empty( $_POST['sites'] ) || ! is_array( $_POST['sites'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return true;
		}

		$primaryBackup                = MainWP_System_Utility::get_primary_backup();
		$global_backup_before_upgrade = get_option( 'mainwp_backup_before_upgrade' );

		$mainwp_backup_before_upgrade_days = get_option( 'mainwp_backup_before_upgrade_days' );
		if ( empty( $mainwp_backup_before_upgrade_days ) || ! ctype_digit( $mainwp_backup_before_upgrade_days ) ) {
			$mainwp_backup_before_upgrade_days = 7;
		}

		$output = array();
		if ( isset( $_POST['sites'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			foreach ( array_map( 'sanitize_text_field', wp_unslash( $_POST['sites'] ) ) as $siteId ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$website = MainWP_DB::instance()->get_website_by_id( $siteId );
				if ( ( 0 === (int) $website->backup_before_upgrade ) || ( ( 2 === (int) $website->backup_before_upgrade ) && empty( $global_backup_before_upgrade ) ) ) {
					continue;
				}

				if ( ! empty( $primaryBackup ) ) {
					$lastBackup = MainWP_DB::instance()->get_website_option( $website, 'primary_lasttime_backup' );

					if ( -1 !== $lastBackup ) { // installed backup plugin.
						$output['sites'][ $siteId ] = ( $lastBackup < ( time() - ( $mainwp_backup_before_upgrade_days * 24 * 60 * 60 ) ) ? false : true );
					}
					$output['primary_backup'] = $primaryBackup;
				} else {
					$dir = MainWP_System_Utility::get_mainwp_specific_dir( $siteId );
					// Check if backup ok.
					$lastBackup = - 1;
					if ( file_exists( $dir ) ) {
						$dh = opendir( $dir );
						if ( $dh ) {
							while ( false !== ( $file = readdir( $dh ) ) ) {
								if ( '.' !== $file && '..' !== $file ) {
									$theFile = $dir . $file;
									if ( MainWP_Backup_Handler::is_archive( $file ) && ! MainWP_Backup_Handler::is_sql_archive( $file ) && ( filemtime( $theFile ) > $lastBackup ) ) {
										$lastBackup = filemtime( $theFile );
									}
								}
							}
							closedir( $dh );
						}
					}

					$output['sites'][ $siteId ] = ( $lastBackup < ( time() - ( $mainwp_backup_before_upgrade_days * 24 * 60 * 60 ) ) ? false : true );
				}
			}
		}

		return $output;
	}
}
