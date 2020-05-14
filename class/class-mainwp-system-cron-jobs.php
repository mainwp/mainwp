<?php
/**
 * MainWP System Cron Jobs.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_System_Cron_Jobs.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions -- for custom read/write file.
 */
class MainWP_System_Cron_Jobs {

	/**
	 * Singleton.
	 *
	 * @var null $instance
	 */
	private static $instance = null;

	/**
	 * MainWP Cron Instance.
	 *
	 * @return self $instance
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Method __construct()
	 */
	public function __construct() {
	}

	/**
	 * Method init_cron_jobs()
	 *
	 * Instantiate Cron Jobs.
	 */
	public function init_cron_jobs() {

		do_action( 'mainwp_cronload_action' );

		add_action( 'mainwp_cronstats_action', array( $this, 'cron_stats' ) );
		add_action( 'mainwp_cronbackups_action', array( $this, 'cron_backups' ) );
		add_action( 'mainwp_cronbackups_continue_action', array( $this, 'cron_backups_continue' ) );
		add_action( 'mainwp_cronupdatescheck_action', array( $this, 'cron_updates_check' ) );
		add_action( 'mainwp_cronpingchilds_action', array( $this, 'cron_ping_childs' ) );

		// phpcs:ignore -- required for dashboard's minutely scheduled jobs.
		add_filter( 'cron_schedules', array( $this, 'get_cron_schedules' ) );

		$this->init_cron();
	}

	/**
	 * Method init_cron()
	 *
	 * Build Cron Jobs Array & initiate via init_wp_cron()
	 */
	public function init_cron() {

		// Check wether or not to use MainWP Cron false|1.
		$useWPCron = ( get_option( 'mainwp_wp_cron' ) === false ) || ( get_option( 'mainwp_wp_cron' ) == 1 );

		// Default Cron Jobs.
		$jobs = array(
			'mainwp_cronstats_action'        => 'hourly',
			'mainwp_cronpingchilds_action'   => 'daily',
			'mainwp_cronupdatescheck_action' => 'minutely',
		);

		// Legacy Backup Cron jobs.
		if ( get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			$jobs = array_merge(
				$jobs,
				array(
					'mainwp_cronbackups_action'          => 'hourly',
					'mainwp_cronbackups_continue_action' => '5minutely',
				)
			);
		} else {
			// Unset Cron Schedules.
			$sched = wp_next_scheduled( 'mainwp_cronbackups_action' );
			if ( $sched ) {
				wp_unschedule_event( $sched, 'mainwp_cronbackups_action' );
			}
			$sched = wp_next_scheduled( 'mainwp_cronbackups_continue_action' );
			if ( $sched ) {
				wp_unschedule_event( $sched, 'mainwp_cronbackups_continue_action' );
			}
		}

		foreach ( $jobs as $recur => $hook ) {
			$this->init_wp_cron( $useWPCron, $recur, $hook );
		}
	}

	/**
	 * Method init_wp_cron()
	 *
	 * Schedual Cron Jobs.
	 *
	 * @param mixed $useWPCron Wether or not to use WP_Cron.
	 * @param mixed $recurrence When cron is going to reoccur.
	 * @param mixed $cron_hook Cron job hook.
	 */
	public function init_wp_cron( $useWPCron, $recurrence, $cron_hook ) {
		$sched = wp_next_scheduled( $cron_hook );
		if ( false == $sched ) {
			if ( $useWPCron ) {
				wp_schedule_event( time(), $recurrence, $cron_hook );
			}
		} else {
			if ( ! $useWPCron ) {
				wp_unschedule_event( $sched, $cron_hook );
			}
		}
	}

	/**
	 * Method cron_active()
	 *
	 * Check if WP_Cron is active.
	 *
	 * @return void
	 */
	public function cron_active() {
		if ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
			return;
		}
		if ( empty( $_GET['mainwp_run'] ) || 'test' !== $_GET['mainwp_run'] ) {
			return;
		}
		session_write_close();
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ), true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'X-MainWP-Version: ' . MainWP_System::$version, true );
		nocache_headers();
		if ( 'test' == $_GET['mainwp_run'] ) {
			die( 'MainWP Test' );
		}
		die( '' );
	}

	/**
	 * Method get_cron_schedules()
	 *
	 * Get current Cron Schedual.
	 *
	 * @param array $schedules Array of currently set scheduals.
	 *
	 * @return array $scheduales.
	 */
	public function get_cron_schedules( $schedules ) {
		$schedules['5minutely'] = array(
			'interval'   => 5 * 60,
			'display'    => __( 'Once every 5 minutes', 'mainwp' ),
		);
		$schedules['minutely']  = array(
			'interval'   => 1 * 60,
			'display'    => __( 'Once every minute', 'mainwp' ),
		);

		return $schedules;
	}

	/**
	 * Method get_timestamp_from_hh_mm()
	 *
	 * Get Time Stamp from $hh_mm.
	 *
	 * @param mixed $hh_mm Global time stamp variable.
	 *
	 * @return time Y-m-d 00:00:59.
	 */
	public static function get_timestamp_from_hh_mm( $hh_mm ) {
		$hh_mm = explode( ':', $hh_mm );
		$_hour = isset( $hh_mm[0] ) ? intval( $hh_mm[0] ) : 0;
		$_mins = isset( $hh_mm[1] ) ? intval( $hh_mm[1] ) : 0;
		if ( $_hour < 0 || $_hour > 23 ) {
			$_hour = 0;
		}
		if ( $_mins < 0 || $_mins > 59 ) {
			$_mins = 0;
		}
		return strtotime( date( 'Y-m-d' ) . ' ' . $_hour . ':' . $_mins . ':59' ); // phpcs:ignore -- update check at local server time
	}

	/**
	 * Method get_period_of_time_from_hh_mm()
	 *
	 * Get Period of time from $hh_mm.
	 *
	 * @param mixed $hh_mm Global time stamp variable.
	 *
	 * @return time $_hour * 60 + $_mins.
	 */
	public static function get_period_of_time_from_hh_mm( $hh_mm ) {
		$hh_mm = explode( ':', $hh_mm );
		$_hour = isset( $hh_mm[0] ) ? intval( $hh_mm[0] ) : 0;
		$_mins = isset( $hh_mm[1] ) ? intval( $hh_mm[1] ) : 0;

		if ( $_hour < 0 || $_hour > 23 ) {
			$_hour = 0;
		}

		if ( $_mins < 0 || $_mins > 59 ) {
			$_mins = 0;
		}
		return $_hour * 60 + $_mins;
	}

	/**
	 * Method cron_updates_check()
	 *
	 * MainWP Cron Check Update
	 *
	 * This Cron Checks to see if Automatic Daily Updates need to be performed.
	 */
	public function cron_updates_check() { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- complex function.
		// current complexity is the only way to achieve desired results, pull request solutions appreciated.
		MainWP_Logger::instance()->info( 'CRON :: updates check' );

		ignore_user_abort( true );
		set_time_limit( 0 );
		add_filter(
			'admin_memory_limit',
			function() {
				return '512M';
			}
		);

		$timeDailyUpdate = get_option( 'mainwp_timeDailyUpdate' );
		$run_timestamp   = 0;
		if ( ! empty( $timeDailyUpdate ) ) {
			$run_timestamp = self::get_timestamp_from_hh_mm( $timeDailyUpdate );
			if ( time() < $run_timestamp ) {
				return;
			}
		}

		$updatecheck_running = ( 'Y' == get_option( 'mainwp_updatescheck_is_running' ) ? true : false );

		$lasttimeAutomaticUpdate = get_option( 'mainwp_updatescheck_last_timestamp' );
		$frequencyDailyUpdate    = get_option( 'mainwp_frequencyDailyUpdate' );
		if ( $frequencyDailyUpdate <= 0 ) {
			$frequencyDailyUpdate = 1;
		}

		$period_of_time = $run_timestamp ? self::get_period_of_time_from_hh_mm( $timeDailyUpdate ) : 0;

		if ( $period_of_time > 24 * 60 ) {
			$period_of_time = 0;
		}

		$enableFrequencyAutomaticUpdate = false;

		if ( $period_of_time > 0 ) {
			$mins_between = ( 24 * 60 - $period_of_time ) / $frequencyDailyUpdate;
			if ( time() < $lasttimeAutomaticUpdate + $mins_between * 60 ) {
				if ( ! $updatecheck_running ) {
					return;
				}
			} else {
				$enableFrequencyAutomaticUpdate = true;
			}
		}

		$mainwpAutomaticDailyUpdate = get_option( 'mainwp_automaticDailyUpdate' );

		$plugin_automaticDailyUpdate = get_option( 'mainwp_pluginAutomaticDailyUpdate' );
		$theme_automaticDailyUpdate  = get_option( 'mainwp_themeAutomaticDailyUpdate' );

		$mainwpLastAutomaticUpdate          = get_option( 'mainwp_updatescheck_last' );
		$mainwpHoursIntervalAutomaticUpdate = apply_filters( 'mainwp_updatescheck_hours_interval', false );

		if ( $mainwpHoursIntervalAutomaticUpdate > 0 ) {
			if ( $lasttimeAutomaticUpdate && ( $lasttimeAutomaticUpdate + $mainwpHoursIntervalAutomaticUpdate * 3600 > time() ) ) {
				if ( ! $updatecheck_running ) {
					MainWP_Logger::instance()->debug( 'CRON :: updates check :: already updated hours interval' );
					return;
				}
			}
		} elseif ( $enableFrequencyAutomaticUpdate ) {
			$websites = array();
		} elseif ( date( 'd/m/Y' ) === $mainwpLastAutomaticUpdate ) { // phpcs:ignore -- update check at local server time
			MainWP_Logger::instance()->debug( 'CRON :: updates check :: already updated today' );

			return;
		}

		if ( 'Y' == get_option( 'mainwp_updatescheck_ready_sendmail' ) ) {
			$send_noti_at = apply_filters( 'mainwp_updatescheck_sendmail_at_time', false );
			if ( ! empty( $send_noti_at ) ) {
				$send_timestamp = self::get_timestamp_from_hh_mm( $send_noti_at );
				if ( time() < $send_timestamp ) {
					return;
				}
			}
		}

		$websites             = array();
		$checkupdate_websites = MainWP_DB::instance()->get_websites_check_updates( 4 );

		foreach ( $checkupdate_websites as $website ) {
			if ( ! MainWP_DB_Backup::instance()->backup_full_task_running( $website->id ) ) {
				$websites[] = $website;
			}
		}

		MainWP_Logger::instance()->debug( 'CRON :: updates check :: found ' . count( $checkupdate_websites ) . ' websites' );
		MainWP_Logger::instance()->debug( 'CRON :: backup task running :: found ' . ( count( $checkupdate_websites ) - count( $websites ) ) . ' websites' );
		MainWP_Logger::instance()->info_update( 'CRON :: updates check :: found ' . count( $checkupdate_websites ) . ' websites' );

		$userid = null;
		foreach ( $websites as $website ) {
			$websiteValues = array(
				'dtsAutomaticSyncStart' => time(),
			);
			if ( null === $userid ) {
				$userid = $website->userid;
			}

			MainWP_DB::instance()->update_website_sync_values( $website->id, $websiteValues );
		}

		$text_format = get_option( 'mainwp_daily_digest_plain_text', false );

		if ( 0 == count( $checkupdate_websites ) ) {

			$busyCounter = MainWP_DB::instance()->get_websites_count_where_dts_automatic_sync_smaller_then_start();
			MainWP_Logger::instance()->info_update( 'CRON :: busy counter :: found ' . $busyCounter . ' websites' );
			if ( 0 === $busyCounter ) {
				if ( 'Y' != get_option( 'mainwp_updatescheck_ready_sendmail' ) ) {
					MainWP_Utility::update_option( 'mainwp_updatescheck_ready_sendmail', 'Y' );
					return false;
				}
				if ( $updatecheck_running ) {
					MainWP_Utility::update_option( 'mainwp_updatescheck_is_running', '' );
				}

				update_option( 'mainwp_last_synced_all_sites', time() );

				if ( ! $this->send_notification( $plugin_automaticDailyUpdate, $theme_automaticDailyUpdate, $mainwpAutomaticDailyUpdate, $text_format ) ) {
					return;
				}
			}
		} else {

			if ( ! $updatecheck_running ) {
				MainWP_Utility::update_option( 'mainwp_updatescheck_is_running', 'Y' );
			}

			$userExtension = MainWP_DB_Common::instance()->get_user_extension_by_user_id( $userid );

			$decodedIgnoredPlugins = json_decode( $userExtension->ignored_plugins, true );
			if ( ! is_array( $decodedIgnoredPlugins ) ) {
				$decodedIgnoredPlugins = array();
			}

			$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
			if ( ! is_array( $trustedPlugins ) ) {
				$trustedPlugins = array();
			}

			$decodedIgnoredThemes = json_decode( $userExtension->ignored_themes, true );
			if ( ! is_array( $decodedIgnoredThemes ) ) {
				$decodedIgnoredThemes = array();
			}

			$trustedThemes = json_decode( $userExtension->trusted_themes, true );
			if ( ! is_array( $trustedThemes ) ) {
				$trustedThemes = array();
			}

			$coreToUpdateNow      = array();
			$coreToUpdate         = array();
			$coreNewUpdate        = array();
			$ignoredCoreToUpdate  = array();
			$ignoredCoreNewUpdate = array();

			$pluginsToUpdateNow         = array();
			$pluginsToUpdate            = array();
			$pluginsNewUpdate           = array();
			$notTrustedPluginsToUpdate  = array();
			$notTrustedPluginsNewUpdate = array();

			$themesToUpdateNow         = array();
			$themesToUpdate            = array();
			$themesNewUpdate           = array();
			$notTrustedThemesToUpdate  = array();
			$notTrustedThemesNewUpdate = array();

			$allWebsites = array();

			$infoTrustedText    = ' (<span style="color:#008000"><strong>Trusted</strong></span>)';
			$infoNotTrustedText = '';

			foreach ( $websites as $website ) {
				$websiteDecodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );
				if ( ! is_array( $websiteDecodedIgnoredPlugins ) ) {
					$websiteDecodedIgnoredPlugins = array();
				}

				$websiteDecodedIgnoredThemes = json_decode( $website->ignored_themes, true );
				if ( ! is_array( $websiteDecodedIgnoredThemes ) ) {
					$websiteDecodedIgnoredThemes = array();
				}

				if ( ! MainWP_Sync::sync_site( $website, false, true ) ) {
					$websiteValues = array(
						'dtsAutomaticSync' => time(),
					);

					MainWP_DB::instance()->update_website_sync_values( $website->id, $websiteValues );

					continue;
				}
				$website = MainWP_DB::instance()->get_website_by_id( $website->id );

				/** Check core updates * */
				$websiteLastCoreUpgrades = json_decode( MainWP_DB::instance()->get_website_option( $website, 'last_wp_upgrades' ), true );
				$websiteCoreUpgrades     = json_decode( MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' ), true );

				if ( isset( $websiteCoreUpgrades['current'] ) ) {

					if ( $text_format ) {
						$infoTxt    = stripslashes( $website->name ) . ' - ' . $websiteCoreUpgrades['current'] . ' to ' . $websiteCoreUpgrades['new'] . ' - ' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id );
						$infoNewTxt = '*NEW* ' . stripslashes( $website->name ) . ' - ' . $websiteCoreUpgrades['current'] . ' to ' . $websiteCoreUpgrades['new'] . ' - ' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id );
					} else {
						$infoTxt    = '<a href="' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) . '">' . stripslashes( $website->name ) . '</a> - ' . $websiteCoreUpgrades['current'] . ' to ' . $websiteCoreUpgrades['new'];
						$infoNewTxt = '*NEW* <a href="' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) . '">' . stripslashes( $website->name ) . '</a> - ' . $websiteCoreUpgrades['current'] . ' to ' . $websiteCoreUpgrades['new'];
					}

					$newUpdate = ! ( isset( $websiteLastCoreUpgrades['current'] ) && ( $websiteLastCoreUpgrades['current'] == $websiteCoreUpgrades['current'] ) && ( $websiteLastCoreUpgrades['new'] == $websiteCoreUpgrades['new'] ) );
					if ( ! $website->is_ignoreCoreUpdates ) {
						if ( 1 === $website->automatic_update ) {
							if ( $newUpdate ) {
								$coreNewUpdate[] = array( $website->id, $infoNewTxt, $infoTrustedText );
							} else {
								$coreToUpdateNow[]           = $website->id;
								$allWebsites[ $website->id ] = $website;
								$coreToUpdate[]              = array( $website->id, $infoTxt, $infoTrustedText );
							}
						} else {
							if ( $newUpdate ) {
								$ignoredCoreNewUpdate[] = array( $website->id, $infoNewTxt, $infoNotTrustedText );
							} else {
								$ignoredCoreToUpdate[] = array( $website->id, $infoTxt, $infoNotTrustedText );
							}
						}
					}
				}

				/** Check plugins * */
				$websiteLastPlugins = json_decode( MainWP_DB::instance()->get_website_option( $website, 'last_plugin_upgrades' ), true );
				$websitePlugins     = json_decode( $website->plugin_upgrades, true );

				/** Check themes * */
				$websiteLastThemes = json_decode( MainWP_DB::instance()->get_website_option( $website, 'last_theme_upgrades' ), true );
				$websiteThemes     = json_decode( $website->theme_upgrades, true );

				$decodedPremiumUpgrades = json_decode( MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' ), true );
				if ( is_array( $decodedPremiumUpgrades ) ) {
					foreach ( $decodedPremiumUpgrades as $slug => $premiumUpgrade ) {
						if ( 'plugin' === $premiumUpgrade['type'] ) {
							if ( ! is_array( $websitePlugins ) ) {
								$websitePlugins = array();
							}
							$websitePlugins[ $slug ] = $premiumUpgrade;
						} elseif ( 'theme' === $premiumUpgrade['type'] ) {
							if ( ! is_array( $websiteThemes ) ) {
								$websiteThemes = array();
							}
							$websiteThemes[ $slug ] = $premiumUpgrade;
						}
					}
				}

				foreach ( $websitePlugins as $pluginSlug => $pluginInfo ) {
					if ( isset( $decodedIgnoredPlugins[ $pluginSlug ] ) || isset( $websiteDecodedIgnoredPlugins[ $pluginSlug ] ) ) {
						continue;
					}
					if ( $website->is_ignorePluginUpdates ) {
						continue;
					}
					$infoTxt    = '';
					$infoNewTxt = '';
					if ( $text_format ) {
						$infoTxt    = stripslashes( $website->name ) . ' - ' . $pluginInfo['Name'] . ' ' . $pluginInfo['Version'] . ' to ' . $pluginInfo['update']['new_version'] . ' - ' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id );
						$infoNewTxt = '*NEW* ' . stripslashes( $website->name ) . ' - ' . $pluginInfo['Name'] . ' ' . $pluginInfo['Version'] . ' to ' . $pluginInfo['update']['new_version'] . ' - ' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id );
					} else {
						$infoTxt    = '<a href="' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) . '">' . stripslashes( $website->name ) . '</a> - ' . $pluginInfo['Name'] . ' ' . $pluginInfo['Version'] . ' to ' . $pluginInfo['update']['new_version'];
						$infoNewTxt = '*NEW* <a href="' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) . '">' . stripslashes( $website->name ) . '</a> - ' . $pluginInfo['Name'] . ' ' . $pluginInfo['Version'] . ' to ' . $pluginInfo['update']['new_version'];
					}
					if ( $pluginInfo['update']['url'] && ( false !== strpos( $pluginInfo['update']['url'], 'wordpress.org/plugins' ) ) ) {
						$change_log = $pluginInfo['update']['url'];
						if ( substr( $change_log, - 1 ) != '/' ) {
							$change_log .= '/';
						}
						$change_log .= '#developers';
						if ( ! $text_format ) {
							$infoTxt    .= ' - <a href="' . $change_log . '" target="_blank">Changelog</a>';
							$infoNewTxt .= ' - <a href="' . $change_log . '" target="_blank">Changelog</a>';
						}
					}
					$newUpdate = ! ( isset( $websiteLastPlugins[ $pluginSlug ] ) && ( $pluginInfo['Version'] == $websiteLastPlugins[ $pluginSlug ]['Version'] ) && ( $pluginInfo['update']['new_version'] == $websiteLastPlugins[ $pluginSlug ]['update']['new_version'] ) );
					if ( in_array( $pluginSlug, $trustedPlugins ) ) {
						if ( $newUpdate ) {
							$pluginsNewUpdate[] = array( $website->id, $infoNewTxt, $infoTrustedText );
						} else {
							$pluginsToUpdateNow[ $website->id ][] = $pluginSlug;
							$allWebsites[ $website->id ]          = $website;
							$pluginsToUpdate[]                    = array( $website->id, $infoTxt, $infoTrustedText );
						}
					} else {
						if ( $newUpdate ) {
							$notTrustedPluginsNewUpdate[] = array( $website->id, $infoNewTxt, $infoNotTrustedText );
						} else {
							$notTrustedPluginsToUpdate[] = array( $website->id, $infoTxt, $infoNotTrustedText );
						}
					}
				}

				foreach ( $websiteThemes as $themeSlug => $themeInfo ) {
					if ( isset( $decodedIgnoredThemes[ $themeSlug ] ) || isset( $websiteDecodedIgnoredThemes[ $themeSlug ] ) ) {
						continue;
					}

					if ( $website->is_ignoreThemeUpdates ) {
						continue;
					}
					if ( $text_format ) {
						$infoTxt    = stripslashes( $website->name ) . ' - ' . $themeInfo['Name'] . ' ' . $themeInfo['Version'] . ' to ' . $themeInfo['update']['new_version'] . ' - ' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id );
						$infoNewTxt = '*NEW* ' . stripslashes( $website->name ) . ' - ' . $themeInfo['Name'] . ' ' . $themeInfo['Version'] . ' to ' . $themeInfo['update']['new_version'] . ' - ' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id );
					} else {
						$infoTxt    = '<a href="' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) . '">' . stripslashes( $website->name ) . '</a> - ' . $themeInfo['Name'] . ' ' . $themeInfo['Version'] . ' to ' . $themeInfo['update']['new_version'];
						$infoNewTxt = '*NEW* <a href="' . admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) . '">' . stripslashes( $website->name ) . '</a> - ' . $themeInfo['Name'] . ' ' . $themeInfo['Version'] . ' to ' . $themeInfo['update']['new_version'];
					}

					$newUpdate = ! ( isset( $websiteLastThemes[ $themeSlug ] ) && ( $themeInfo['Version'] == $websiteLastThemes[ $themeSlug ]['Version'] ) && ( $themeInfo['update']['new_version'] == $websiteLastThemes[ $themeSlug ]['update']['new_version'] ) );
					if ( in_array( $themeSlug, $trustedThemes ) ) {
						if ( $newUpdate ) {
							$themesNewUpdate[] = array( $website->id, $infoNewTxt, $infoTrustedText );
						} else {
							$themesToUpdateNow[ $website->id ][] = $themeSlug;
							$allWebsites[ $website->id ]         = $website;
							$themesToUpdate[]                    = array( $website->id, $infoTxt, $infoTrustedText );
						}
					} else {
						if ( $newUpdate ) {
							$notTrustedThemesNewUpdate[] = array( $website->id, $infoNewTxt, $infoNotTrustedText );
						} else {
							$notTrustedThemesToUpdate[] = array( $website->id, $infoTxt, $infoNotTrustedText );
						}
					}
				}

				do_action( 'mainwp_daily_digest_action', $website, $text_format );

				$user  = get_userdata( $website->userid );
				$email = MainWP_System_Utility::get_notification_email( $user );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_email', $email );
				MainWP_DB::instance()->update_website_sync_values( $website->id, array( 'dtsAutomaticSync' => time() ) );
				MainWP_DB::instance()->update_website_option( $website, 'last_wp_upgrades', wp_json_encode( $websiteCoreUpgrades ) );
				MainWP_DB::instance()->update_website_option( $website, 'last_plugin_upgrades', $website->plugin_upgrades );
				MainWP_DB::instance()->update_website_option( $website, 'last_theme_upgrades', $website->theme_upgrades );

				$updatescheckSitesIcon = get_option( 'mainwp_updatescheck_sites_icon' );
				if ( ! is_array( $updatescheckSitesIcon ) ) {
					$updatescheckSitesIcon = array();
				}
				if ( ! in_array( $website->id, $updatescheckSitesIcon ) ) {
					MainWP_Sync::sync_site_icon( $website->id );
					$updatescheckSitesIcon[] = $website->id;
					MainWP_Utility::update_option( 'mainwp_updatescheck_sites_icon', $updatescheckSitesIcon );
				}
			}

			if ( count( $coreNewUpdate ) != 0 ) {
				$coreNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_core_new' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_core_new', MainWP_Utility::array_merge( $coreNewUpdateSaved, $coreNewUpdate ) );
			}

			if ( count( $pluginsNewUpdate ) != 0 ) {
				$pluginsNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_plugins_new' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_plugins_new', MainWP_Utility::array_merge( $pluginsNewUpdateSaved, $pluginsNewUpdate ) );
			}

			if ( count( $themesNewUpdate ) != 0 ) {
				$themesNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_themes_new' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_themes_new', MainWP_Utility::array_merge( $themesNewUpdateSaved, $themesNewUpdate ) );
			}

			if ( count( $coreToUpdate ) != 0 ) {
				$coreToUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_core' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_core', MainWP_Utility::array_merge( $coreToUpdateSaved, $coreToUpdate ) );
			}

			if ( count( $pluginsToUpdate ) != 0 ) {
				$pluginsToUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_plugins' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_plugins', MainWP_Utility::array_merge( $pluginsToUpdateSaved, $pluginsToUpdate ) );
			}

			if ( count( $themesToUpdate ) != 0 ) {
				$themesToUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_themes' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_themes', MainWP_Utility::array_merge( $themesToUpdateSaved, $themesToUpdate ) );
			}

			if ( count( $ignoredCoreToUpdate ) != 0 ) {
				$ignoredCoreToUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_core' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_core', MainWP_Utility::array_merge( $ignoredCoreToUpdateSaved, $ignoredCoreToUpdate ) );
			}

			if ( count( $ignoredCoreNewUpdate ) != 0 ) {
				$ignoredCoreNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_core_new' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_core_new', MainWP_Utility::array_merge( $ignoredCoreNewUpdateSaved, $ignoredCoreNewUpdate ) );
			}

			if ( count( $notTrustedPluginsToUpdate ) != 0 ) {
				$notTrustedPluginsToUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_plugins' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_plugins', MainWP_Utility::array_merge( $notTrustedPluginsToUpdateSaved, $notTrustedPluginsToUpdate ) );
			}

			if ( count( $notTrustedPluginsNewUpdate ) != 0 ) {
				$notTrustedPluginsNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_plugins_new' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_plugins_new', MainWP_Utility::array_merge( $notTrustedPluginsNewUpdateSaved, $notTrustedPluginsNewUpdate ) );
			}

			if ( count( $notTrustedThemesToUpdate ) != 0 ) {
				$notTrustedThemesToUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_themes' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_themes', MainWP_Utility::array_merge( $notTrustedThemesToUpdateSaved, $notTrustedThemesToUpdate ) );
			}

			if ( count( $notTrustedThemesNewUpdate ) != 0 ) {
				$notTrustedThemesNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_themes_new' );
				MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_themes_new', MainWP_Utility::array_merge( $notTrustedThemesNewUpdateSaved, $notTrustedThemesNewUpdate ) );
			}

			if ( ( count( $coreToUpdate ) == 0 ) && ( count( $pluginsToUpdate ) == 0 ) && ( count( $themesToUpdate ) == 0 ) && ( count( $ignoredCoreToUpdate ) == 0 ) && ( count( $ignoredCoreNewUpdate ) == 0 ) && ( count( $notTrustedPluginsToUpdate ) == 0 ) && ( count( $notTrustedPluginsNewUpdate ) == 0 ) && ( count( $notTrustedThemesToUpdate ) == 0 ) && ( count( $notTrustedThemesNewUpdate ) == 0 )
			) {
				return;
			}

			if ( 1 !== $mainwpAutomaticDailyUpdate && 1 !== $plugin_automaticDailyUpdate && 1 !== $theme_automaticDailyUpdate ) {
				return;
			}

			if ( get_option( 'mainwp_enableLegacyBackupFeature' ) && get_option( 'mainwp_backup_before_upgrade' ) == 1 ) {
				$sitesCheckCompleted = get_option( 'mainwp_automaticUpdate_backupChecks' );
				if ( ! is_array( $sitesCheckCompleted ) ) {
					$sitesCheckCompleted = array();
				}

				$websitesToCheck = array();

				if ( 1 === $plugin_automaticDailyUpdate ) {
					foreach ( $pluginsToUpdateNow as $websiteId => $slugs ) {
						$websitesToCheck[ $websiteId ] = true;
					}
				}

				if ( 1 === $theme_automaticDailyUpdate ) {
					foreach ( $themesToUpdateNow as $websiteId => $slugs ) {
						$websitesToCheck[ $websiteId ] = true;
					}
				}

				if ( 1 === $mainwpAutomaticDailyUpdate ) {
					foreach ( $coreToUpdateNow as $websiteId ) {
						$websitesToCheck[ $websiteId ] = true;
					}
				}

				$hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

				global $wp_filesystem;

				if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {
					foreach ( $websitesToCheck as $siteId => $bool ) {
						if ( 0 === $allWebsites[ $siteId ]->backup_before_upgrade ) {
							$sitesCheckCompleted[ $siteId ] = true;
						}
						if ( isset( $sitesCheckCompleted[ $siteId ] ) ) {
							continue;
						}

						$dir        = MainWP_System_Utility::get_mainwp_specific_dir( $siteId );
						$dh         = opendir( $dir );
						$lastBackup = - 1;
						if ( $wp_filesystem->exists( $dir ) && $dh ) {
							while ( ( $file = readdir( $dh ) ) !== false ) {
								if ( '.' !== $file && '..' !== $file ) {
									$theFile = $dir . $file;
									if ( MainWP_Backup_Handler::is_archive( $file ) && ! MainWP_Backup_Handler::is_sql_archive( $file ) && ( $wp_filesystem->mtime( $theFile ) > $lastBackup ) ) {
										$lastBackup = $wp_filesystem->mtime( $theFile );
									}
								}
							}
							closedir( $dh );
						}

						$mainwp_backup_before_upgrade_days = get_option( 'mainwp_backup_before_upgrade_days' );
						if ( empty( $mainwp_backup_before_upgrade_days ) || ! ctype_digit( $mainwp_backup_before_upgrade_days ) ) {
							$mainwp_backup_before_upgrade_days = 7;
						}

						$backupRequired = ( $lastBackup < ( time() - ( $mainwp_backup_before_upgrade_days * 24 * 60 * 60 ) ) ? true : false );

						if ( ! $backupRequired ) {
							$sitesCheckCompleted[ $siteId ] = true;
							MainWP_Utility::update_option( 'mainwp_automaticUpdate_backupChecks', $sitesCheckCompleted );
							continue;
						}

						try {
							$result = MainWP_Backup_Handler::backup( $siteId, 'full', '', '', 0, 0, 0, 0 );
							MainWP_Backup_Handler::backup_download_file( $siteId, 'full', $result['url'], $result['local'] );
							$sitesCheckCompleted[ $siteId ] = true;
							MainWP_Utility::update_option( 'mainwp_automaticUpdate_backupChecks', $sitesCheckCompleted );
						} catch ( \Exception $e ) {
							$sitesCheckCompleted[ $siteId ] = false;
							MainWP_Utility::update_option( 'mainwp_automaticUpdate_backupChecks', $sitesCheckCompleted );
						}
					}
				}
			} else {
				$sitesCheckCompleted = null;
			}

			if ( 1 === $plugin_automaticDailyUpdate ) {
				foreach ( $pluginsToUpdateNow as $websiteId => $slugs ) {
					if ( ( null != $sitesCheckCompleted ) && ( false == $sitesCheckCompleted[ $websiteId ] ) ) {
						continue;
					}
					MainWP_Logger::instance()->info_update( 'CRON :: auto update :: websites id :: ' . $websiteId . ' :: plugins :: ' . implode( ',', $slugs ) );

					try {
						$information = MainWP_Connect::fetch_url_authed(
							$allWebsites[ $websiteId ],
							'upgradeplugintheme',
							array(
								'type'   => 'plugin',
								'list'   => urldecode( implode( ',', $slugs ) ),
							)
						);

						if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
							MainWP_Sync::sync_information_array( $allWebsites[ $websiteId ], $information['sync'] );
						}
					} catch ( \Exception $e ) {
						// ok.
					}
				}
			} else {
				$pluginsToUpdateNow = array();
			}

			if ( 1 === $theme_automaticDailyUpdate ) {
				foreach ( $themesToUpdateNow as $websiteId => $slugs ) {
					if ( ( null != $sitesCheckCompleted ) && ( false == $sitesCheckCompleted[ $websiteId ] ) ) {
						continue;
					}

					try {
						$information = MainWP_Connect::fetch_url_authed(
							$allWebsites[ $websiteId ],
							'upgradeplugintheme',
							array(
								'type'   => 'theme',
								'list'   => urldecode( implode( ',', $slugs ) ),
							)
						);

						if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
							MainWP_Sync::sync_information_array( $allWebsites[ $websiteId ], $information['sync'] );
						}
					} catch ( \Exception $e ) {
						// ok.
					}
				}
			} else {
				$themesToUpdateNow = array();
			}

			if ( 1 === $mainwpAutomaticDailyUpdate ) {
				foreach ( $coreToUpdateNow as $websiteId ) {
					if ( ( null != $sitesCheckCompleted ) && ( false == $sitesCheckCompleted[ $websiteId ] ) ) {
						continue;
					}

					try {
						MainWP_Connect::fetch_url_authed( $allWebsites[ $websiteId ], 'upgrade' );
					} catch ( \Exception $e ) {
						// ok.
					}
				}
			} else {
				$coreToUpdateNow = array();
			}

			do_action( 'mainwp_cronupdatecheck_action', $pluginsNewUpdate, $pluginsToUpdate, $pluginsToUpdateNow, $themesNewUpdate, $themesToUpdate, $themesToUpdateNow, $coreNewUpdate, $coreToUpdate, $coreToUpdateNow );
		}
	}

	/**
	 * Method send_notification().
	 *
	 * Send email notification.
	 *
	 * @param bool $plugin_automaticDailyUpdate Auto update plugins daily.
	 * @param bool $theme_automaticDailyUpdate Auto update themes daily.
	 * @param bool $mainwpAutomaticDailyUpdate Auto update core daily.
	 * @param bool $text_format Text format value.
	 *
	 * @return bool True|False
	 */
	public function send_notification( $plugin_automaticDailyUpdate, $theme_automaticDailyUpdate, $mainwpAutomaticDailyUpdate, $text_format ) {

		MainWP_Logger::instance()->debug( 'CRON :: updates check :: got to the mail part' );

		$sitesCheckCompleted = null;
		if ( get_option( 'mainwp_backup_before_upgrade' ) == 1 ) {
			$sitesCheckCompleted = get_option( 'mainwp_automaticUpdate_backupChecks' );
			if ( ! is_array( $sitesCheckCompleted ) ) {
				$sitesCheckCompleted = null;
			}
		}

		$mail_content = '';
		$sendMail     = false;

		if ( ! empty( $plugin_automaticDailyUpdate ) ) {
			$plugin_content = $this->get_mail_content_plugins( $sitesCheckCompleted, $text_format );
			if ( '' != $plugin_content ) {
				$sendMail      = true;
				$mail_content .= $plugin_content;
			}
		}

		if ( ! empty( $theme_automaticDailyUpdate ) ) {
			$themes_content = $this->get_mail_content_themes( $sitesCheckCompleted, $text_format );
			if ( '' != $themes_content ) {
				$sendMail      = true;
				$mail_content .= $themes_content;
			}
		}

		if ( ! empty( $mainwpAutomaticDailyUpdate ) ) {
			$core_content .= $this->get_mail_content_wp( $sitesCheckCompleted, $text_format );
			if ( '' != $core_content ) {
				$sendMail      = true;
				$mail_content .= $core_content;
			}
		}

		$sitesDisconnect = MainWP_DB::instance()->get_disconnected_websites();
		if ( count( $sitesDisconnect ) != 0 ) {
			$sendMail      = true;
			$mail_content .= $this->get_mail_content_connections( $sitesDisconnect, $text_format );
		}

		MainWP_Utility::update_option( 'mainwp_updatescheck_last', date( 'd/m/Y' ) ); // phpcs:ignore -- update check at local server time
		MainWP_Utility::update_option( 'mainwp_updatescheck_last_timestamp', time() );

		$plain_text = apply_filters( 'mainwp_text_format_email', false );
		MainWP_Utility::update_option( 'mainwp_daily_digest_plain_text', $plain_text );

		$disable_send_noti = apply_filters_deprecated( 'mainwp_updatescheck_disable_sendmail', array( false ), '4.0.8', 'mainwp_updatescheck_disable_notification_mail' );

		$disabled_notification = apply_filters( 'mainwp_updatescheck_disable_notification_mail', $disable_send_noti );

		$this->clear_fields();

		if ( $disabled_notification ) {
			return false;
		}

		if ( ! $sendMail ) {
			MainWP_Logger::instance()->debug( 'CRON :: updates check :: sendMail is false' );
			return false;
		}

		if ( 1 == get_option( 'mainwp_check_http_response', 0 ) ) {
			$sitesHttpCheckIds = get_option( 'mainwp_automaticUpdate_httpChecks' );
			$this->send_http_response_notification( $sitesHttpCheckIds, $text_format );
			MainWP_Utility::update_option( 'mainwp_automaticUpdate_httpChecks', '' );
		}

		$email = get_option( 'mainwp_updatescheck_mail_email' );
		MainWP_Logger::instance()->debug( 'CRON :: updates check :: send mail to ' . $email );

		if ( false !== $email && '' !== $email ) {
			$mail_content = apply_filters( 'mainwp_daily_digest_content', $mail_content, $text_format );
			$this->send_updates_notification( $email, $mail_content, $text_format );
		}

		return true;
	}


	/**
	 * Method clear_fields().
	 *
	 * Clear settings field values.
	 */
	public static function clear_fields() {
		$empty_fields = array(
			'mainwp_automaticUpdate_backupChecks',
			'mainwp_updatescheck_mail_update_core_new',
			'mainwp_updatescheck_mail_update_plugins_new',
			'mainwp_updatescheck_mail_update_themes_new',
			'mainwp_updatescheck_mail_update_core',
			'mainwp_updatescheck_mail_update_plugins',
			'mainwp_updatescheck_mail_update_themes',
			'mainwp_updatescheck_mail_ignore_core',
			'mainwp_updatescheck_mail_ignore_plugins',
			'mainwp_updatescheck_mail_ignore_themes',
			'mainwp_updatescheck_mail_ignore_core_new',
			'mainwp_updatescheck_mail_ignore_plugins_new',
			'mainwp_updatescheck_mail_ignore_themes_new',
			'mainwp_updatescheck_ready_sendmail',
			'mainwp_updatescheck_sites_icon',
		);

		foreach ( $empty_fields as $field ) {
			MainWP_Utility::update_option( $field, '' );
		}
	}

	/**
	 * Method get_mail_content_plugins().
	 *
	 * Get mail content for plugins update.
	 *
	 * @param mixed $sitesCheckCompleted Completed sites.
	 * @param bool  $text_format Text format.
	 *
	 * @return string $mail_content Email content.
	 */
	public function get_mail_content_plugins( $sitesCheckCompleted, $text_format ) {

		$pluginsNewUpdate = get_option( 'mainwp_updatescheck_mail_update_plugins_new' );
		if ( ! is_array( $pluginsNewUpdate ) ) {
			$pluginsNewUpdate = array();
		}
		$pluginsToUpdate = get_option( 'mainwp_updatescheck_mail_update_plugins' );
		if ( ! is_array( $pluginsToUpdate ) ) {
			$pluginsToUpdate = array();
		}
		$notTrustedPluginsNewUpdate = get_option( 'mainwp_updatescheck_mail_ignore_plugins_new' );
		if ( ! is_array( $notTrustedPluginsNewUpdate ) ) {
			$notTrustedPluginsNewUpdate = array();
		}
		$notTrustedPluginsToUpdate = get_option( 'mainwp_updatescheck_mail_ignore_plugins' );
		if ( ! is_array( $notTrustedPluginsToUpdate ) ) {
			$notTrustedPluginsToUpdate = array();
		}

		$mail_content = '';

		if ( ( count( $pluginsNewUpdate ) != 0 ) || ( count( $pluginsToUpdate ) != 0 ) || ( count( $notTrustedPluginsNewUpdate ) != 0 ) || ( count( $notTrustedPluginsToUpdate ) != 0 )
			) {
			$mail_lines  = '';
			$mail_lines .= $this->print_digest_lines( $pluginsNewUpdate );
			$mail_lines .= $this->print_digest_lines( $pluginsToUpdate, $sitesCheckCompleted );
			$mail_lines .= $this->print_digest_lines( $notTrustedPluginsNewUpdate );
			$mail_lines .= $this->print_digest_lines( $notTrustedPluginsToUpdate );

			if ( $text_format ) {
				$mail_content .= 'WordPress Plugin Updates' . "\r\n";
				$mail_content .= "\r\n";
				$mail_content .= $mail_lines;
				$mail_content .= "\r\n";
			} else {
				$mail_content .= '<div><strong>WordPress Plugin Updates</strong></div>';
				$mail_content .= '<ul>';
				$mail_content .= $mail_lines;
				$mail_content .= '</ul>';
			}
		}
		return $mail_content;
	}

	/**
	 * Method get_mail_content_themes().
	 *
	 * Get themes update mail content.
	 *
	 * @param mixed $sitesCheckCompleted Completed sites.
	 * @param bool  $text_format Text format.
	 *
	 * @return string $mail_content Email content
	 */
	public function get_mail_content_themes( $sitesCheckCompleted, $text_format ) {

		$themesNewUpdate = get_option( 'mainwp_updatescheck_mail_update_themes_new' );
		if ( ! is_array( $themesNewUpdate ) ) {
			$themesNewUpdate = array();
		}
		$themesToUpdate = get_option( 'mainwp_updatescheck_mail_update_themes' );
		if ( ! is_array( $themesToUpdate ) ) {
			$themesToUpdate = array();
		}
		$notTrustedThemesNewUpdate = get_option( 'mainwp_updatescheck_mail_ignore_themes_new' );
		if ( ! is_array( $notTrustedThemesNewUpdate ) ) {
			$notTrustedThemesNewUpdate = array();
		}
		$notTrustedThemesToUpdate = get_option( 'mainwp_updatescheck_mail_ignore_themes' );
		if ( ! is_array( $notTrustedThemesToUpdate ) ) {
			$notTrustedThemesToUpdate = array();
		}

		$mail_content = '';

		if ( ( count( $themesNewUpdate ) != 0 ) || ( count( $themesToUpdate ) != 0 ) || ( count( $notTrustedThemesNewUpdate ) != 0 ) || ( count( $notTrustedThemesToUpdate ) != 0 )
			) {
			$mail_lines  = '';
			$mail_lines .= $this->print_digest_lines( $themesNewUpdate );
			$mail_lines .= $this->print_digest_lines( $themesToUpdate, $sitesCheckCompleted );
			$mail_lines .= $this->print_digest_lines( $notTrustedThemesNewUpdate );
			$mail_lines .= $this->print_digest_lines( $notTrustedThemesToUpdate );

			if ( $text_format ) {
				$mail_content .= 'WordPress Themes Updates' . "\r\n";
				$mail_content .= "\r\n";
				$mail_content .= $mail_lines;
				$mail_content .= "\r\n";
			} else {
				$mail_content .= '<div><strong>WordPress Themes Updates</strong></div>';
				$mail_content .= '<ul>';
				$mail_content .= $mail_lines;
				$mail_content .= '</ul>';
			}
		}

		return $mail_content;
	}

	/**
	 * Method get_mail_content_themes().
	 *
	 * Get mail content of WP update.
	 *
	 * @param mixed $sitesCheckCompleted Completed sites.
	 * @param bool  $text_format Text format.
	 *
	 * @return string $mail_content Email content.
	 */
	public function get_mail_content_wp( $sitesCheckCompleted, $text_format ) {

		$coreNewUpdate = get_option( 'mainwp_updatescheck_mail_update_core_new' );
		if ( ! is_array( $coreNewUpdate ) ) {
			$coreNewUpdate = array();
		}
		$coreToUpdate = get_option( 'mainwp_updatescheck_mail_update_core' );
		if ( ! is_array( $coreToUpdate ) ) {
			$coreToUpdate = array();
		}
		$ignoredCoreNewUpdate = get_option( 'mainwp_updatescheck_mail_ignore_core_new' );
		if ( ! is_array( $ignoredCoreNewUpdate ) ) {
			$ignoredCoreNewUpdate = array();
		}
		$ignoredCoreToUpdate = get_option( 'mainwp_updatescheck_mail_ignore_core' );
		if ( ! is_array( $ignoredCoreToUpdate ) ) {
			$ignoredCoreToUpdate = array();
		}
		$mail_content = '';

		if ( ( count( $coreNewUpdate ) != 0 ) || ( count( $coreToUpdate ) != 0 ) || ( count( $ignoredCoreNewUpdate ) != 0 ) || ( count( $ignoredCoreToUpdate ) != 0 ) ) {
			$mail_lines  = '';
			$mail_lines .= $this->print_digest_lines( $coreNewUpdate );
			$mail_lines .= $this->print_digest_lines( $coreToUpdate, $sitesCheckCompleted );
			$mail_lines .= $this->print_digest_lines( $ignoredCoreNewUpdate );
			$mail_lines .= $this->print_digest_lines( $ignoredCoreToUpdate );

			if ( $text_format ) {
				$mail_content .= 'WordPress Core Updates' . "\r\n";
				$mail_content .= "\r\n";
				$mail_content .= $mail_lines;
				$mail_content .= "\r\n";
			} else {
				$mail_content .= '<div><strong>WordPress Core Updates</strong></div>';
				$mail_content .= '<ul>';
				$mail_content .= $mail_lines;
				$mail_content .= '</ul>';
			}
		}

		return $mail_content;
	}

	/**
	 * Method get_mail_content_connections().
	 *
	 * Get mail content of connections.
	 *
	 * @param mixed $sitesDisconnect Disconnected sites.
	 * @param bool  $text_format Text format.
	 *
	 * @return string $mail_content Email content.
	 */
	public function get_mail_content_connections( $sitesDisconnect, $text_format ) {
		$mail_lines   = $this->print_digest_lines( $sitesDisconnect, null, 'disc_sites' );
		$mail_content = '';
		if ( $text_format ) {
			$mail_content .= 'Connection Status' . "\r\n";
			$mail_content .= "\r\n";
			$mail_content .= $mail_lines;
			$mail_content .= "\r\n";
		} else {
			$mail_content .= '<b style="color: rgb(127, 177, 0); font-family: Helvetica, Sans; font-size: medium; line-height: normal;"> Connection Status </b><br>';
			$mail_content .= '<ul>';
			$mail_content .= $mail_lines;
			$mail_content .= '</ul>';
		}
		return $mail_content;
	}


	/**
	 * Method send_http_response_notification().
	 *
	 * Send HTTP response email notification.
	 *
	 * @param bool $sitesHttpCheckIds Sites IDs.
	 * @param bool $text_format Text format.
	 *
	 * @return bool False if failed.
	 */
	public function send_http_response_notification( $sitesHttpCheckIds, $text_format ) {

		if ( ! is_array( $sitesHttpCheckIds ) || empty( $sitesHttpCheckIds ) ) {
			return false;
		}

		if ( $text_format ) {
			$content_type = "Content-Type: text/plain; charset=\"utf-8\"\r\n";
		} else {
			$content_type = "Content-Type: text/html; charset=\"utf-8\"\r\n";
		}

		$mail_offline = '';
		$sitesOffline = MainWP_DB::instance()->get_websites_by_ids( $sitesHttpCheckIds );

		if ( is_array( $sitesOffline ) && count( $sitesOffline ) > 0 ) {
			foreach ( $sitesOffline as $site ) {
				if ( -1 == $site->offline_check_result ) {
					$mail_offline .= '<li>' . $site->name . ' - [' . $site->url . '] - [' . $site->http_response_code . ']</li>';
				}
			}
		}

		$email = get_option( 'mainwp_updatescheck_mail_email' );

		if ( ! empty( $email ) && '' != $mail_offline ) {
			MainWP_Logger::instance()->debug( 'CRON :: http check :: send mail to ' . $email );
			$mail_offline   = '<div>After running auto updates, following sites are not returning expected HTTP request response:</div>
							<div></div>
							<ul>
							' . $mail_offline . '
							</ul>
							<div></div>
							<div>Please visit your MainWP Dashboard as soon as possible and make sure that your sites are online. (<a href="' . site_url() . '">' . site_url() . '</a>)</div>';
			wp_mail(
				$email,
				$mail_title = 'MainWP - HTTP response check',
				MainWP_Format::format_email(
					$email,
					$mail_offline,
					$mail_title
				),
				array(
					'From: "' . get_option( 'admin_email' ) . '" <' . get_option( 'admin_email' ) . '>',
					$content_type,
				)
			);
		}
	}

	/**
	 * Method send_updates_notification().
	 *
	 * Sent available updates notification email.
	 *
	 * @param mixed $email Admin email.
	 * @param mixed $content Mail content.
	 * @param bool  $text_format Text format.
	 */
	public function send_updates_notification( $email, $content, $text_format ) {

		if ( $text_format ) {
			$content_type = "Content-Type: text/plain; charset=\"utf-8\"\r\n";
		} else {
			$content_type = "Content-Type: text/html; charset=\"utf-8\"\r\n";
		}

		if ( $text_format ) {
			$mail_content = 'We noticed the following updates are available on your MainWP Dashboard. (' . site_url() . ')' . "\r\n"
			. $content . "\r\n" .
			'If your MainWP is configured to use Auto Updates these updates will be installed in the next 24 hours.' . "\r\n";
		} else {
			$mail_content = '<div>We noticed the following updates are available on your MainWP Dashboard. (<a href="' . site_url() . '">' . site_url() . '</a>)</div>
			<div></div>
			' . $content . '
			<div> </div>
			<div>If your MainWP is configured to use Auto Updates these updates will be installed in the next 24 hours.</div>';
		}
		wp_mail(
			$email,
			$mail_title = 'Available Updates',
			MainWP_Format::format_email(
				$email,
				$mail_content,
				$mail_title,
				$text_format
			),
			array(
				'From: "' . get_option( 'admin_email' ) . '" <' . get_option( 'admin_email' ) . '>',
				$content_type,
			)
		);
	}

	/**
	 * Method print_digest_lines()
	 *
	 * List of updates to be emailed.
	 *
	 * @param array  $array Array of URLs.
	 * @param array  $backupChecks null|Child Site ID.
	 * @param string $what disc_sites|null.
	 *
	 * @return html $output Email Body.
	 */
	public function print_digest_lines( $array, $backupChecks = null, $what = 'update' ) {

		$plain_text = apply_filters( 'mainwp_text_format_email', false );

		$output = '';

		if ( 'disc_sites' === $what ) {
			if ( $plain_text ) {
				foreach ( $array as $url ) {
					$output .= $url . "\r\n";
				}
			} else {
				foreach ( $array as $url ) {
					$output .= '<li>' . $url . '</li>' . "\n";
				}
			}
		} else {

			if ( $plain_text ) {
				foreach ( $array as $line ) {
					$siteId      = $line[0];
					$text        = $line[1];
					$trustedText = $line[2];

					$output .= $text . $trustedText . ( null == $backupChecks || ! isset( $backupChecks[ $siteId ] ) || ( true == $backupChecks[ $siteId ] ) ? '' : '(Requires manual backup)' ) . "\r\n";
				}
			} else {
				foreach ( $array as $line ) {
					$siteId      = $line[0];
					$text        = $line[1];
					$trustedText = $line[2];

					$output .= '<li>' . $text . $trustedText . ( null == $backupChecks || ! isset( $backupChecks[ $siteId ] ) || ( true == $backupChecks[ $siteId ] ) ? '' : '(Requires manual backup)' ) . '</li>' . "\n";
				}
			}
		}

		return $output;
	}

	/**
	 * Method cron_ping_childs()
	 *
	 * Cron job to ping child sites.
	 */
	public function cron_ping_childs() {
		MainWP_Logger::instance()->info( 'CRON :: ping childs' );

		$lastPing = get_option( 'mainwp_cron_last_ping' );
		if ( false !== $lastPing && ( time() - $lastPing ) < ( 60 * 60 * 23 ) ) {
			return;
		}
		MainWP_Utility::update_option( 'mainwp_cron_last_ping', time() );

		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites() );
		while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
			try {
				$url = $website->siteurl;
				if ( ! MainWP_Utility::ends_with( $url, '/' ) ) {
					$url .= '/';
				}

				wp_remote_get( $url . 'wp-cron.php' );
			} catch ( \Exception $e ) {
				// ok.
			}
		}
		MainWP_DB::free_result( $websites );
	}

	/**
	 * Method cron_backups_continue()
	 *
	 * Execute remaining backup tasks.
	 */
	public function cron_backups_continue() {

		if ( ! get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			return;
		}

		MainWP_Logger::instance()->info( 'CRON :: backups continue' );

		ignore_user_abort( true );
		set_time_limit( 0 );
		add_filter(
			'admin_memory_limit',
			function() {
				return '512M';
			}
		);

		MainWP_Utility::update_option( 'mainwp_cron_last_backups_continue', time() );

		$tasks = MainWP_DB_Backup::instance()->get_backup_tasks_to_complete();

		MainWP_Logger::instance()->debug( 'CRON :: backups continue :: Found ' . count( $tasks ) . ' to continue.' );

		if ( empty( $tasks ) ) {
			return;
		}

		foreach ( $tasks as $task ) {
			MainWP_Logger::instance()->debug( 'CRON :: backups continue ::    Task: ' . $task->name );
		}

		foreach ( $tasks as $task ) {
			$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			if ( $task->completed < $task->last_run ) {
				MainWP_Manage_Backups_Handler::execute_backup_task( $task, 5, false );
				break;
			}
		}
	}

	/**
	 * Method cron_backups()
	 *
	 * Execute Backup Tasks.
	 */
	public function cron_backups() {
		if ( ! get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			return;
		}

		MainWP_Logger::instance()->info( 'CRON :: backups' );

		ignore_user_abort( true );
		set_time_limit( 0 );
		add_filter(
			'admin_memory_limit',
			function() {
				return '512M';
			}
		);

		MainWP_Utility::update_option( 'mainwp_cron_last_backups', time() );

		$allTasks   = array();
		$dailyTasks = MainWP_DB_Backup::instance()->get_backup_tasks_todo_daily();
		if ( count( $dailyTasks ) > 0 ) {
			$allTasks = $dailyTasks;
		}
		$weeklyTasks = MainWP_DB_Backup::instance()->get_backup_tasks_todo_weekly();
		if ( count( $weeklyTasks ) > 0 ) {
			$allTasks = array_merge( $allTasks, $weeklyTasks );
		}
		$monthlyTasks = MainWP_DB_Backup::instance()->get_backup_tasks_todo_monthly();
		if ( count( $monthlyTasks ) > 0 ) {
			$allTasks = array_merge( $allTasks, $monthlyTasks );
		}

		MainWP_Logger::instance()->debug( 'CRON :: backups :: Found ' . count( $allTasks ) . ' to start.' );

		foreach ( $allTasks as $task ) {
			MainWP_Logger::instance()->debug( 'CRON :: backups ::    Task: ' . $task->name );
		}

		foreach ( $allTasks as $task ) {
			$threshold = 0;
			if ( 'daily' == $task->schedule ) {
				$threshold = ( 60 * 60 * 24 );
			} elseif ( 'weekly' == $task->schedule ) {
				$threshold = ( 60 * 60 * 24 * 7 );
			} elseif ( 'monthly' == $task->schedule ) {
				$threshold = ( 60 * 60 * 24 * 30 );
			}
			$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			if ( ( time() - $task->last_run ) < $threshold ) {
				continue;
			}

			if ( ! MainWP_Manage_Backups::validate_backup_tasks( array( $task ) ) ) {
				$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			}

			$chunkedBackupTasks = get_option( 'mainwp_chunkedBackupTasks' );
			MainWP_Manage_Backups_Handler::execute_backup_task( $task, ( 0 !== $chunkedBackupTasks ? 5 : 0 ) );
		}
	}

	/**
	 * Method cron_stats()
	 *
	 * Grab MainWP Cron Job Statistics.
	 */
	public function cron_stats() {
		MainWP_Logger::instance()->info( 'CRON :: stats' );

		MainWP_Utility::update_option( 'mainwp_cron_last_stats', time() );

		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_websites_stats_update_sql() );

		$start = time();
		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
			if ( ( $start - time() ) > ( 60 * 60 * 2 ) ) {
				break;
			}

			MainWP_DB::instance()->update_website_stats( $website->id, time() );

			if ( property_exists( $website, 'sync_errors' ) && '' != $website->sync_errors ) {
				MainWP_Logger::instance()->info_for_website( $website, 'reconnect', 'Trying to reconnect' );
				try {
					if ( MainWP_Manage_Sites_View::m_reconnect_site( $website ) ) {
						MainWP_Logger::instance()->info_for_website( $website, 'reconnect', 'Reconnected successfully' );
					}
				} catch ( \Exception $e ) {
					MainWP_Logger::instance()->warning_for_website( $website, 'reconnect', $e->getMessage() );
				}
			}
			sleep( 3 );
		}
		MainWP_DB::free_result( $websites );
	}

}
