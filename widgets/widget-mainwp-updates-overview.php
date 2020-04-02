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
 * MainWP Updates Overview
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
	 * @param mixed $default Default.
	 * @param mixed $action Action.
	 * @param mixed $args Slug.
	 *
	 * @return mixed $default|$res
	 */
	public static function plugins_api( $default, $action, $args ) {
		if ( property_exists( $args, 'slug' ) && ( 'mainwp' === $args->slug ) ) {
			return $default;
		}

		$url = 'http://api.wordpress.org/plugins/info/1.0/';
		$ssl = wp_http_supports( array( 'ssl' ) );
		if ( $ssl ) {
			$url = set_url_scheme( $url, 'https' );
		}

		$args    = array(
			'timeout'    => 15,
			'body'       => array(
				'action'     => $action,
				'request'    => serialize( $args ),
			),
		);
		$request = wp_remote_post( $url, $args );

		if ( is_wp_error( $request ) ) {
			$url  = '';
			$name = '';
			if ( isset( $_REQUEST['url'] ) ) {
				$url  = $_REQUEST['url'];
				$name = $_REQUEST['name'];
			}

			$res = new WP_Error( 'plugins_api_failed', __( '<h3>No plugin information found.</h3> This may be a premium plugin and no other details are available from WordPress.', 'mainwp' ) . ' ' . ( $url == '' ? __( 'Please visit the plugin website for more information.', 'mainwp' ) : __( 'Please visit the plugin website for more information: ', 'mainwp' ) . '<a href="' . rawurldecode( $url ) . '" target="_blank">' . rawurldecode( $name ) . '</a>' ), $request->get_error_message() );

			return $res;
		}

		return $default;
	}

	/**
	 * Method get_name()
	 *
	 * Define Widget Title.
	 */
	public static function get_name() {
		return __( 'Update Overview', 'mainwp' );
	}

	/**
	 * Method render()
	 *
	 * Check if $_GET['dashboard'] then run render_sites().
	 */
	public static function render() {
		$individual = false;
		if ( isset( $_GET['dashboard'] ) ) {
			$individual = true;
		}
		self::render_sites( false, $individual );
	}

	/**
	 * Method render_last_update()
	 *
	 * Check when the Child Site was last synced.
	 */
	public static function render_last_update() {
		$currentwp = MainWP_Utility::get_current_wpid();
		if ( ! empty( $currentwp ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $currentwp );
			$dtsSync = $website->dtsSync;
		} else {
			$dtsSync = MainWP_DB::instance()->get_first_synced_site();
		}

		if ( $dtsSync == 0 ) {
			// No settings saved!
			return;
		} else {
			esc_html_e( '(Last completed sync: ', 'mainwp' ) . MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $dtsSync ) ) . ')';
		}
	}

	/**
	 * Method sync_site()
	 *
	 * Sync Child Site.
	 */
	public static function sync_site() {
		$website = null;
		if ( isset( $_POST['wp_id'] ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $_POST['wp_id'] );
		}

		if ( $website == null ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request. Please, try again.', 'mainwp' ) ) ) );
		}

		$maxRequestsInThirtySeconds = get_option( 'mainwp_maximumRequests' );
		MainWP_Utility::end_session();

		$semLock = '103218';

		MainWP_DB::instance()->update_website_sync_values( $website->id, array( 'dtsSyncStart' => time() ) );
		MainWP_Utility::end_session();

		if ( MainWP_Sync::sync_site( $website ) ) {
			die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $website->id );

		die( wp_json_encode( array( 'error' => $website->sync_errors ) ) );
	}

	/**
	 * Method render_sites()
	 *
	 * Grab available Child Sites updates a build Widget.
	 *
	 * @param boolean $isUpdatesPage Check if Updates Page True|False.
	 */
	// phpcs:disable Generic.Metrics.CyclomaticComplexity -- Big function
	public static function render_sites( $isUpdatesPage = false ) {
		
		$globalView = true;
		global $current_user;
		$current_wpid = MainWP_Utility::get_current_wpid();

		if ( $current_wpid ) {
			$sql        = MainWP_DB::instance()->get_sql_website_by_id( $current_wpid, false, array( 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ) );
			$globalView = false;
		} else {
			$staging_enabled = is_plugin_active( 'mainwp-staging-extension/mainwp-staging-extension.php') || is_plugin_active( 'mainwp-timecapsule-extension/mainwp-timecapsule-extension.php');
			// To support staging extension.
			$is_staging = 'no';
			if ( $staging_enabled ) {
				$staging_updates_view = get_user_option( 'mainwp_staging_options_updates_view', $current_user->ID );
				if ( $staging_updates_view == 'staging' ) {
					$is_staging = 'yes';
				}
			}
			// end support.

			$sql = MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ), $is_staging );
		}

		$userExtension = MainWP_DB::instance()->get_user_extension();
		$websites      = MainWP_DB::instance()->query( $sql );

		$mainwp_show_language_updates = get_option( 'mainwp_show_language_updates', 1 );

		$decodedDismissedPlugins = json_decode( $userExtension->dismissed_plugins, true );
		$decodedDismissedThemes  = json_decode( $userExtension->dismissed_themes, true );

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

			$wp_upgrades = json_decode( MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' ), true );
			if ( $website->is_ignoreCoreUpdates ) {
				$wp_upgrades = array();
			}

			if ( is_array( $wp_upgrades ) && count( $wp_upgrades ) > 0 ) {
				$total_wp_upgrades ++;
				$all_wp_updates[] = array(
					'id'   => $website->id,
					'name' => $website->name,
				);
			}

			$translation_upgrades = json_decode( $website->translation_upgrades, true );

			$plugin_upgrades = json_decode( $website->plugin_upgrades, true );
			if ( $website->is_ignorePluginUpdates ) {
				$plugin_upgrades = array();
			}

			$theme_upgrades = json_decode( $website->theme_upgrades, true );
			if ( $website->is_ignoreThemeUpdates ) {
				$theme_upgrades = array();
			}

			$decodedPremiumUpgrades = json_decode( MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' ), true );
			if ( is_array( $decodedPremiumUpgrades ) ) {
				foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
					$premiumUpgrade['premium'] = true;

					if ( $premiumUpgrade['type'] == 'plugin' ) {
						if ( ! is_array( $plugin_upgrades ) ) {
							$plugin_upgrades = array();
						}
						if ( ! $website->is_ignorePluginUpdates ) {

							$premiumUpgrade = array_filter( $premiumUpgrade );
							if ( ! isset( $plugin_upgrades[ $crrSlug ] ) ) {
								$plugin_upgrades[ $crrSlug ] = array(); // to fix warning.
							}

							$plugin_upgrades[ $crrSlug ] = array_merge( $plugin_upgrades[ $crrSlug ], $premiumUpgrade );
						}
					} elseif ( $premiumUpgrade['type'] == 'theme' ) {
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
				$ignored_plugins = json_decode( $website->ignored_plugins, true );
				if ( is_array( $ignored_plugins ) ) {
					$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
				}

				$ignored_plugins = json_decode( $userExtension->ignored_plugins, true );
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
				$ignored_themes = json_decode( $website->ignored_themes, true );
				if ( is_array( $ignored_themes ) ) {
					$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
				}

				$ignored_themes = json_decode( $userExtension->ignored_themes, true );
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

			$pluginsIgnoredAbandoned_perSites = json_decode( MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_dismissed' ), true );
			if ( is_array( $pluginsIgnoredAbandoned_perSites ) ) {
				$pluginsIgnoredAbandoned_perSites = array_filter( $pluginsIgnoredAbandoned_perSites );
			}

			$themesIgnoredAbandoned_perSites = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' ), true );
			if ( is_array( $themesIgnoredAbandoned_perSites ) ) {
				$themesIgnoredAbandoned_perSites = array_filter( $themesIgnoredAbandoned_perSites );
			}

			$plugins_outdate = json_decode( MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' ), true );
			$themes_outdate  = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' ), true );

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

		$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
		if ( ! is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
		}
		$trustedThemes = json_decode( $userExtension->trusted_themes, true );
		if ( ! is_array( $trustedThemes ) ) {
			$trustedThemes = array();
		}
		$trusted_icon = '<i class="check circle outline icon"></i> ';

		// the hook using to set maximum number of plugins/themes for huge number of updates.
		$limit_updates_all            = apply_filters( 'mainwp_limit_updates_all', 0 );
		$continue_update              = '';
				$continue_update_slug = '';
				$continue_class       = '';
		if ( $limit_updates_all > 0 ) {
			if ( isset( $_GET['continue_update'] ) && $_GET['continue_update'] != '' ) {
				$continue_update = $_GET['continue_update'];
				if ( $continue_update == 'plugins_upgrade_all' || $continue_update == 'themes_upgrade_all' || $continue_update == 'translations_upgrade_all' ) {
					if ( isset( $_GET['slug'] ) && $_GET['slug'] != '' ) {
						$continue_update_slug = $_GET['slug'];
					}
				}
			}
		}

		if ( ! $globalView ) {
			$last_dtsSync = $currentSite->dtsSync;
		} else {
			$result                   = MainWP_DB::instance()->get_last_sync_status();
			$sync_status              = $result['sync_status'];
			$last_sync                = $result['last_sync'];
						$last_dtsSync = $result['last_sync'];

			if ( $sync_status === 'all_synced' ) {
				$now           = time();
				$last_sync_all = get_option('mainwp_last_synced_all_sites', 0);
				if ( $last_sync_all == 0 ) {
					$last_sync_all = $last_sync;
				}
				$last_dtsSync = $last_sync_all;
			}
		}

		$lastSyncMsg = '';
		if ( $last_dtsSync ) {
			$lastSyncMsg = __( 'Last successfully completed synchronization: ', 'mainwp' ) . MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $last_dtsSync ) );
		}

		$user_can_update_translation = mainwp_current_user_can( 'dashboard', 'update_translations' );
		$user_can_update_wordpress   = mainwp_current_user_can( 'dashboard', 'update_wordpress' );
		$user_can_update_themes      = mainwp_current_user_can( 'dashboard', 'update_themes' );
		$user_can_update_plugins     = mainwp_current_user_can( 'dashboard', 'update_plugins' );

		?>

		<div class="ui grid">
			<div class="sixteen wide column">
				<h3 class="ui header handle-drag">
				<?php esc_html_e( 'Updates Overview', 'mainwp' ); ?>
					<div class="sub header"><?php echo $lastSyncMsg; ?></div>
				</h3>
			</div>
		</div>
		<input type="hidden" name="updatesoverview_limit_updates_all" id="updatesoverview_limit_updates_all" value="<?php echo intval( $limit_updates_all ); ?>">
					<div class="ui two column stackable grid"><!-- Total Updates -->
						<div class="column">
							<div class="ui large statistic horizontal">
								<div class="value">
								<?php echo $total_upgrades; ?>
							</div>
							<div class="label">
								<?php esc_html_e('Total Updates', 'mainwp'); ?>
							</div>
							</div>
						</div>
						<div class="column middle aligned">
				<?php if ( $user_can_update_wordpress && $user_can_update_plugins && $user_can_update_themes && $user_can_update_translation ) : ?>
					<?php if ( ! get_option( 'mainwp_hide_update_everything', false ) ) : ?>
							<a href="#" 
							<?php
							if ( $total_upgrades == 0 ) {
								echo 'disabled'; } else {
								?>
								onClick="return updatesoverview_global_upgrade_all( 'all' );"  <?php } ?> class="ui big button fluid green" data-tooltip="<?php esc_html_e( 'Clicking this button will update all Plugins, Themes, WP Core files and translations on All your websites.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><?php esc_html_e( 'Update Everything', 'mainwp' ); ?></a>
					<?php endif; ?>
				<?php endif; ?>
						</div>
					</div><!-- END Total Updates -->

					<div class="ui hidden divider"></div>
					<div class="ui horizontal divider"><?php esc_html_e( 'Update Details', 'mainwp' ); ?></div>
					<div class="ui hidden divider"></div>

		<div class="ui grid">
			<div class="two column row">
				<div class="column">
					<div class="ui horizontal statistic">
					<div class="value">
						<?php echo $total_wp_upgrades; ?>
					</div>
					<div class="label">
					<?php esc_html_e('WordPress Updates', 'mainwp'); ?>
					</div>
					</div>
				</div>
				<div class="right aligned column">
				<?php
				if ( $user_can_update_wordpress ) :
					if ( $globalView ) {
						$detail_wp_up = 'admin.php?page=UpdatesManage&tab=wordpress-updates';
					} else {
						$detail_wp_up = 'admin.php?page=managesites&updateid=' . $current_wpid . '&tab=wordpress-updates';
					}
					?>
					<?php if ( $total_wp_upgrades > 0 ) : ?>
						<?php $continue_class = ( $continue_update == 'wpcore_global_upgrade_all' ) ? 'updatesoverview_continue_update_me' : ''; ?>
						<a href="<?php echo $detail_wp_up; ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
						<a href="#" onClick="return updatesoverview_global_upgrade_all('wp');" class="ui green basic button <?php echo $continue_class; ?>" data-tooltip="<?php esc_html_e( 'Clicking this button will update WP Core files on All your websites.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
					<?php else : ?>
						<a href="<?php echo $detail_wp_up; ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
						<a href="#" disabled class="ui grey basic button"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
					<?php endif; ?>
				<?php endif; ?>
								</div>
								</div>
							</div>

		<div class="ui grid">
			<div class="two column row">
								<div class="column">
					<div class="ui horizontal statistic">
									<div class="value">
										<?php echo $total_plugin_upgrades; ?>
									</div>
										<div class="label">
							<?php esc_html_e('Plugin Updates', 'mainwp'); ?>
										</div>
										</div>
									</div>
				<div class="right aligned column">
					<?php
					if ( $user_can_update_plugins ) :
							$continue_class = ( $continue_update == 'plugins_global_upgrade_all' ) ? 'updatesoverview_continue_update_me' : '';
						if ( $globalView ) {
							$detail_plugins_up = 'admin.php?page=UpdatesManage&tab=plugins-updates';
						} else {
							$detail_plugins_up = 'admin.php?page=managesites&updateid=' . $current_wpid . '&tab=plugins-updates';
						}

						if ( $total_plugin_upgrades == 0 ) {
							?>
								<a href="<?php echo $detail_plugins_up; ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
									<a href="#" disabled class="ui grey basic button"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
							<?php
						} else {

							?>
								<a href="<?php echo $detail_plugins_up; ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
								<a href="#" onClick="return updatesoverview_global_upgrade_all('plugin');" class="ui basic green button <?php echo $continue_class; ?>" data-tooltip="<?php esc_html_e( 'Clicking this button will update all Plugins on All your websites.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
								<?php
						}
							endif;
					?>
								</div>
							</div>
					</div>

		<div class="ui grid">
			<div class="two column row">
						<div class="column">
							<div class="ui horizontal statistic">
						<div class="value">
							<?php echo $total_theme_upgrades; ?>
							</div>
						<div class="label">
							<?php esc_html_e('Theme Updates', 'mainwp'); ?>
						</div>
							</div>
						</div>
				<div class="right aligned column">
				<?php
				if ( $user_can_update_themes ) :
						$continue_class = ( $continue_update == 'themes_global_upgrade_all' ) ? 'updatesoverview_continue_update_me' : '';

					if ( $globalView ) {
						$detail_themes_up = 'admin.php?page=UpdatesManage&tab=themes-updates';
					} else {
						$detail_themes_up = 'admin.php?page=managesites&updateid=' . $current_wpid . '&tab=themes-updates';
					}

					if ( $total_theme_upgrades == 0 ) {
						?>
								<a href="<?php echo $detail_themes_up; ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
								<a href="#" disabled class="ui grey basic button"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
						<?php
					} else {

						?>
							<a href="<?php echo $detail_themes_up; ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
							<a href="#" onClick="return updatesoverview_global_upgrade_all('theme');" class="ui basic green button <?php echo $continue_class; ?>" data-tooltip="<?php esc_html_e( 'Clicking this button will update all Themes on All your websites.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
							<?php
					}
					endif;
				?>
					</div>
				</div>
					</div>

		<?php if ( $mainwp_show_language_updates == 1 ) : ?>
		<div class="ui grid">
			<div class="two column row">
						<div class="column">
					<div class="ui horizontal statistic">
						<div class="value">
							<?php echo $total_translation_upgrades; ?>
						</div>
						<div class="label">
							<?php esc_html_e('Translation Updates', 'mainwp'); ?>
								</div>
						</div>
					</div>
				<div class="right aligned column">
				<?php
				if ( $user_can_update_translation ) :

					$continue_class = ( $continue_update == 'translations_global_upgrade_all' ) ? 'updatesoverview_continue_update_me' : '';
					if ( $globalView ) {
						$detail_trans_up = 'admin.php?page=UpdatesManage&tab=translations-updates';
					} else {
						$detail_trans_up = 'admin.php?page=managesites&updateid=' . $current_wpid . '&tab=translations-updates';
					}

					if ( $total_translation_upgrades == 0 ) {
						?>
						<a href="<?php echo $detail_trans_up; ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
						<a href="#" disabled class="ui grey basic button"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
						<?php
					} else {

						?>
						<a href="<?php echo $detail_trans_up; ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
						<a href="#" onClick="return updatesoverview_global_upgrade_all('translation');" class="ui basic green button <?php echo $continue_class; ?>" data-tooltip="<?php esc_html_e( 'Clicking this button will update all Translations on All your websites.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
						<?php
					}
					endif;
				?>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<div class="ui hidden divider"></div>
		<div class="ui horizontal divider"><?php esc_html_e( 'Abandoned Plugins & Themes', 'mainwp' ); ?></div>
		<div class="ui hidden divider"></div>

		<div class="ui grid">
			<div class="two column row">
				<div class="column">
					<div class="ui horizontal statistic">
						<?php
						if ( $globalView ) {
							$detail_aban_plugins = 'admin.php?page=UpdatesManage&tab=abandoned-plugins';
						} else {
							$detail_aban_plugins = 'admin.php?page=managesites&updateid=' . $current_wpid . '&tab=abandoned-plugins';
						}
						?>
						<div class="value">
							<?php echo $total_plugins_outdate; ?>
							</div>
							<div class="label">
							<?php esc_html_e( 'Abandoned Plugins', 'mainwp' ); ?>
								</div>
									</div>
										</div>
										<div class="right aligned column">
											<a href="<?php echo $detail_aban_plugins; ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
									</div>
								</div>
							</div>

		<div class="ui grid">
			<div class="two column row">
				<div class="column">
					<div class="ui horizontal statistic">
						<?php
						if ( $globalView ) {
							$detail_aban_themes = 'admin.php?page=UpdatesManage&tab=abandoned-themes';
						} else {
							$detail_aban_themes = 'admin.php?page=managesites&updateid=' . $current_wpid . '&tab=abandoned-themes';
						}
						?>
						<div class="value">
							<?php echo $total_themes_outdate; ?>
							</div>
						<div class="label">
							<?php esc_html_e( 'Abandoned Themes', 'mainwp' ); ?>
										</div>
										</div>
										</div>
								<div class="right aligned column">
									<a href="<?php echo $detail_aban_themes; ?>" class="ui button"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
							</div>
				</div>
			</div>



		<?php // Invisible section to support global updates all. ?>

		<div style="display: none">

			<div id="wp_upgrades">
				<?php
				if ( $user_can_update_wordpress && $total_wp_upgrades > 0 ) {
					foreach ( $all_wp_updates as $item ) {
						?>
						<div updated="0" site_id="<?php echo $item['id']; ?>" site_name="<?php echo esc_html( $item['name'] ); ?>" ></div>
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
						<div updated="0" site_id="<?php echo $item['id']; ?>" site_name="<?php echo esc_html( $item['name'] ); ?>" plugin_slug="<?php echo esc_html( $item['plugin_slug'] ); ?>" ></div>
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
						<div updated="0" site_id="<?php echo $item['id']; ?>" site_name="<?php echo esc_html( $item['name'] ); ?>" theme_slug="<?php echo esc_html( $item['theme_slug'] ); ?>" ></div>
							<?php
					}
				}
				?>

			</div>
			<?php if ( $mainwp_show_language_updates == 1 ) : ?>
			<div id="wp_translation_upgrades">

				<?php
				if ( $user_can_update_translation && $total_translation_upgrades > 0 ) {
					foreach ( $all_translations_updates as $item ) {
						?>
						<div updated="0" site_id="<?php echo $item['id']; ?>" site_name="<?php echo esc_html( $item['name'] ); ?>" translation_slug="<?php echo esc_html( $item['translation_slug'] ); ?>" ></div>
						<?php
					}
				}
				?>
			</div>
			<?php endif; ?>
		</div>


		<?php

		MainWP_DB::data_seek( $websites, 0 );
		$site_ids = array();
		while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
			$site_ids[] = $website->id;
		}

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
	// phpcs:enable -- Generic.Metrics.CyclomaticComplexity -- Big function


	/**
	 * Method dismiss_sync_errors()
	 *
	 * @param boolean $dismiss true|false.
	 *
	 * @return boolean true
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
	 */
	public static function check_backups() {
		if ( ! is_array( $_POST['sites'] ) ) {
			return true;
		}

		$primaryBackup                = MainWP_Utility::get_primary_backup();
		$global_backup_before_upgrade = get_option( 'mainwp_backup_before_upgrade' );

		$mainwp_backup_before_upgrade_days = get_option( 'mainwp_backup_before_upgrade_days' );
		if ( empty( $mainwp_backup_before_upgrade_days ) || ! ctype_digit( $mainwp_backup_before_upgrade_days ) ) {
			$mainwp_backup_before_upgrade_days = 7;
		}

		$output = array();
		foreach ( $_POST['sites'] as $siteId ) {
			$website = MainWP_DB::instance()->get_website_by_id( $siteId );
			if ( ( $website->backup_before_upgrade == 0 ) || ( ( $website->backup_before_upgrade == 2 ) && ( $global_backup_before_upgrade == 0 ) ) ) {
				continue;
			}

			if ( ! empty( $primaryBackup ) ) {
				$lastBackup = MainWP_DB::instance()->get_website_option( $website, 'primary_lasttime_backup' );

				if ( $lastBackup != -1 ) { // installed backup plugin.
					$output['sites'][ $siteId ] = ( $lastBackup < ( time() - ( $mainwp_backup_before_upgrade_days * 24 * 60 * 60 ) ) ? false : true );
				}
				$output['primary_backup'] = $primaryBackup;
			} else {
				$dir = MainWP_Utility::get_mainwp_specific_dir( $siteId );
				// Check if backup ok.
				$lastBackup = - 1;
				if ( file_exists( $dir ) ) {
					$dh = opendir( $dir );
					if ( $dh ) {
						while ( ( $file = readdir( $dh ) ) !== false ) {
							if ( $file != '.' && $file != '..' ) {
								$theFile = $dir . $file;
								if ( MainWP_Utility::is_archive( $file ) && ! MainWP_Utility::is_sql_archive( $file ) && ( filemtime( $theFile ) > $lastBackup ) ) {
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

		return $output;
	}

}
