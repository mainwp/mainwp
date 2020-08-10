<?php
/**
 * MainWP Manage Sites Update View.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Manage Sites Update View
 */
class MainWP_Manage_Sites_Update_View {

	/**
	 * Method render_updates()
	 *
	 * If empty do nothing else grab the Child Sites ID and pass it to
	 * method render_individual_updates().
	 *
	 * @param mixed $website Child Site Info.
	 *
	 * @return self render_individual_updates()
	 */
	public static function render_updates( $website ) {
		if ( empty( $website ) ) {
			return;
		}

		$website_id = $website->id;
		self::render_individual_updates( $website_id );
	}

	/**
	 * Method render_individual_updates()
	 *
	 * Render Plugin updates Tab.
	 *
	 * @param mixed $id Child Site ID.
	 */
	public static function render_individual_updates( $id ) {
		global $current_user;
		$userExtension = MainWP_DB_Common::instance()->get_user_extension();
		$sql           = MainWP_DB::instance()->get_sql_website_by_id( $id, false, array( 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ) );
		$websites      = MainWP_DB::instance()->query( $sql );

		MainWP_DB::data_seek( $websites, 0 );
		if ( $websites ) {
			$website = MainWP_DB::fetch_object( $websites );
		}

		$mainwp_show_language_updates = get_option( 'mainwp_show_language_updates', 1 );

		$active_tab  = 'plugins';
		$active_text = esc_html__( 'Plugins Updates', 'mainwp' );
		if ( isset( $_GET['tab'] ) ) {
			if ( 'wordpress-updates' === $_GET['tab'] ) {
				$active_tab  = 'WordPress';
				$active_text = esc_html__( 'WordPress Updates', 'mainwp' );
			} elseif ( 'themes-updates' === $_GET['tab'] ) {
				$active_tab  = 'themes';
				$active_text = esc_html__( 'Themes Updates', 'mainwp' );
			} elseif ( 'translations-updates' === $_GET['tab'] ) {
				$active_tab  = 'trans';
				$active_text = esc_html__( 'Translations Updates', 'mainwp' );
			} elseif ( 'abandoned-plugins' === $_GET['tab'] ) {
				$active_tab  = 'abandoned-plugins';
				$active_text = esc_html__( 'Abandoned Plugins', 'mainwp' );
			} elseif ( 'abandoned-themes' === $_GET['tab'] ) {
				$active_tab  = 'abandoned-themes';
				$active_text = esc_html__( 'Abandoned Themes', 'mainwp' );
			}
		}
		MainWP_Manage_Sites_View::render_header_tabs( $active_tab, $active_text, $mainwp_show_language_updates )
		?>
		<div class="ui segment" id="mainwp-manage-<?php echo $id; ?>-updates">
			<?php
			self::render_wpcore_updates( $website, $active_tab );
			self::render_plugins_updates( $website, $active_tab, $userExtension );
			self::render_themes_updates( $website, $active_tab, $userExtension );

			if ( $mainwp_show_language_updates ) :
				self::render_language_updates( $website, $active_tab );
			endif;

			self::render_abandoned_plugins( $website, $active_tab, $userExtension );
			self::render_abandoned_themes( $website, $active_tab, $userExtension );
			?>
		</div>
		<script type="text/javascript">
		jQuery( document ).ready( function () {
			jQuery( '.ui.dropdown .item' ).tab();
			jQuery( 'table.ui.table' ).DataTable( {
				"searching": true,
				"paging" : false,
				"info" : true,
				"columnDefs" : [ { "orderable": false, "targets": "no-sort" } ],
				"language" : { "emptyTable": "No available updates. Please sync your MainWP Dashboard with Child Sites to see if there are any new updates available." }
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Method render_wpcore_updates()
	 *
	 * Render the WordPress Updates Tab.
	 *
	 * @param mixed $website Child Site info.
	 * @param mixed $active_tab Current active tab.
	 */
	public static function render_wpcore_updates( $website, $active_tab ) {
		$user_can_update_wp = mainwp_current_user_have_right( 'dashboard', 'update_wordpress' );
		?>
		<div class="ui <?php echo 'WordPress' === $active_tab ? 'active' : ''; ?> tab" data-tab="wordpress">
				<table class="ui stackable single line table" id="mainwp-wordpress-updates-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
							<th><?php esc_html_e( 'New Version', 'mainwp' ); ?></th>
							<th class="right aligned"></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( ! $website->is_ignoreCoreUpdates ) : ?>
						<?php $wp_upgrades = json_decode( MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' ), true ); ?>
						<?php if ( ( 0 !== count( $wp_upgrades ) ) && ! ( '' !== $website->sync_errors ) ) : ?>
						<tr class="mainwp-wordpress-update" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>" updated="<?php echo ( 0 < count( $wp_upgrades ) ) ? '0' : '1'; ?>">
							<td>
								<?php if ( 0 < count( $wp_upgrades ) ) : ?>
									<?php echo esc_html( $wp_upgrades['current'] ); ?>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( 0 < count( $wp_upgrades ) ) : ?>
									<?php echo esc_html( $wp_upgrades['new'] ); ?>
								<?php endif; ?>
							</td>
							<td class="right aligned">
								<?php if ( $user_can_update_wp ) : ?>
									<?php if ( 0 < count( $wp_upgrades ) ) : ?>
										<a href="#" data-tooltip="<?php esc_attr_e( 'Update', 'mainwp' ) . ' ' . $website->name; ?>" data-inverted="" data-position="left center" class="ui green button mini" onClick="return updatesoverview_upgrade(<?php echo esc_attr( $website->id ); ?>, this )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
										<input type="hidden" id="wp-updated-<?php echo esc_attr( $website->id ); ?>" value="<?php echo ( 0 < count( $wp_upgrades ) ? '0' : '1' ); ?>" />
									<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
						<?php endif; ?>
					<?php endif; ?>
					</tbody>
					<thead>
						<tr>
							<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
							<th><?php esc_html_e( 'New Version', 'mainwp' ); ?></th>
							<th class="right aligned"></th>
						</tr>
					</thead>
				</table>
			</div>
		<?php
	}

	/**
	 * Method render_plugins_updates()
	 *
	 * Render the Plugin Updates Tab.
	 *
	 * @param mixed $website Child Site info.
	 * @param mixed $active_tab Current active tab.
	 * @param mixed $userExtension MainWP trusted plugin data.
	 */
	public static function render_plugins_updates( $website, $active_tab, $userExtension ) {

		$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
		if ( ! is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
		}

		$user_can_update_plugins  = mainwp_current_user_have_right( 'dashboard', 'update_plugins' );
		$user_can_ignore_unignore = mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' );
		?>
		<div class="ui <?php echo 'plugins' === $active_tab ? 'active' : ''; ?> tab" data-tab="plugins">
			<?php if ( ! $website->is_ignorePluginUpdates ) : ?>
				<?php
				$plugin_upgrades        = json_decode( $website->plugin_upgrades, true );
				$decodedPremiumUpgrades = json_decode( MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' ), true );
				if ( is_array( $decodedPremiumUpgrades ) ) {
					foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
						$premiumUpgrade['premium'] = true;

						if ( 'plugin' === $premiumUpgrade['type'] ) {
							if ( ! is_array( $plugin_upgrades ) ) {
								$plugin_upgrades = array();
							}

							$premiumUpgrade = array_filter( $premiumUpgrade );

							if ( ! isset( $plugin_upgrades[ $crrSlug ] ) ) {
								$plugin_upgrades[ $crrSlug ] = array();
							}
							$plugin_upgrades[ $crrSlug ] = array_merge( $plugin_upgrades[ $crrSlug ], $premiumUpgrade );
						}
					}
				}
				$ignored_plugins = json_decode( $website->ignored_plugins, true );
				if ( is_array( $ignored_plugins ) ) {
					$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
				}

				$ignored_plugins = json_decode( $userExtension->ignored_plugins, true );
				if ( is_array( $ignored_plugins ) ) {
					$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
				}
				?>
				<table id="mainwp-updates-plugins-table" class="ui stackable single line table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
							<th class="no-sort"><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
							<th class="no-sort"><?php esc_html_e( 'Latest', 'mainwp' ); ?></th>
							<th><?php esc_html_e( 'Trusted', 'mainwp' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
							<th class="no-sort"></th>
						</tr>
					</thead>
					<tbody class="plugins-bulk-updates" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
					<?php foreach ( $plugin_upgrades as $slug => $plugin_upgrade ) : ?>
						<?php $plugin_name = rawurlencode( $slug ); ?>
						<tr plugin_slug="<?php echo $plugin_name; ?>" premium="<?php echo ( isset( $plugin_upgrade['premium'] ) ? esc_attr( $plugin_upgrade['premium'] ) : 0 ) ? 1 : 0; ?>" updated="0">
							<td>
								<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . esc_attr( $plugin_upgrade['update']['slug'] ) . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal">
									<?php echo esc_html( $plugin_upgrade['Name'] ); ?>
								</a>
								<input type="hidden" id="wp_upgraded_plugin_<?php echo esc_attr( $website->id ); ?>_<?php echo $plugin_name; ?>" value="0" />
							</td>
							<td><?php echo esc_html( $plugin_upgrade['Version'] ); ?></td>
							<td>
								<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . $plugin_upgrade['update']['slug'] . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '&section=changelog&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal">
									<?php echo esc_html( $plugin_upgrade['update']['new_version'] ); ?>
								</a>
							</td>
							<td><?php echo ( in_array( $slug, $trustedPlugins, true ) ? MainWP_Updates::$trusted_label : MainWP_Updates::$not_trusted_label ); ?></td>
							<td><?php echo ( isset( $plugin_upgrade['active'] ) && $plugin_upgrade['active'] ) ? __( 'Active', 'mainwp' ) : __( 'Inactive', 'mainwp' ) ; ?></td>
							<td class="right aligned">
								<?php if ( $user_can_ignore_unignore ) : ?>
									<a href="#" onClick="return updatesoverview_plugins_ignore_detail( '<?php echo $plugin_name; ?>', '<?php echo rawurlencode( $plugin_upgrade['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )" class="ui mini button"><?php esc_html_e( 'Ignore Update', 'mainwp' ); ?></a>
								<?php endif; ?>
								<?php if ( $user_can_update_plugins ) : ?>
									<a href="#" class="ui green mini button" onClick="return updatesoverview_upgrade_plugin( <?php echo esc_attr( $website->id ); ?>, '<?php echo $plugin_name; ?>' )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
							<th class="no-sort"><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
							<th class="no-sort"><?php esc_html_e( 'Latest', 'mainwp' ); ?></th>
							<th><?php esc_html_e( 'Trusted', 'mainwp' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
							<th class="no-sort"></th>
						</tr>
					</tfoot>
				</table>
			<?php endif; ?>
			</div>
		<?php
	}

	/**
	 * Method render_themes_updates()
	 *
	 * Render the Themes Updates Tab.
	 *
	 * @param mixed $website Child Site info.
	 * @param mixed $active_tab Current active tab.
	 * @param mixed $userExtension MainWP trusted themes data.
	 */
	public static function render_themes_updates( $website, $active_tab, $userExtension ) {

		$trustedThemes = json_decode( $userExtension->trusted_themes, true );
		if ( ! is_array( $trustedThemes ) ) {
			$trustedThemes = array();
		}

		$user_can_update_themes   = mainwp_current_user_have_right( 'dashboard', 'update_themes' );
		$user_can_ignore_unignore = mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' );

		?>
		<div class="ui <?php echo 'themes' === $active_tab ? 'active' : ''; ?> tab" data-tab="themes">
			<?php if ( ! $website->is_ignoreThemeUpdates ) : ?>
				<?php
				$theme_upgrades         = json_decode( $website->theme_upgrades, true );
				$decodedPremiumUpgrades = json_decode( MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' ), true );
				if ( is_array( $decodedPremiumUpgrades ) ) {
					foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
						$premiumUpgrade['premium'] = true;

						if ( 'theme' === $premiumUpgrade['type'] ) {
							if ( ! is_array( $theme_upgrades ) ) {
								$theme_upgrades = array();
							}

							$premiumUpgrade = array_filter( $premiumUpgrade );

							if ( ! isset( $theme_upgrades[ $crrSlug ] ) ) {
								$theme_upgrades[ $crrSlug ] = array();
							}
							$theme_upgrades[ $crrSlug ] = array_merge( $theme_upgrades[ $crrSlug ], $premiumUpgrade );
						}
					}
				}
				$ignored_themes = json_decode( $website->ignored_themes, true );
				if ( is_array( $ignored_themes ) ) {
					$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
				}

				$ignored_themes = json_decode( $userExtension->ignored_themes, true );
				if ( is_array( $ignored_themes ) ) {
					$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
				}
				?>
				<table id="mainwp-updates-themes-table" class="ui stackable single line table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
							<th class="no-sort"><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
							<th class="no-sort"><?php esc_html_e( 'Latest', 'mainwp' ); ?></th>
							<th><?php esc_html_e( 'Trusted', 'mainwp' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
							<th class="no-sort"></th>
						</tr>
					</thead>
					<tbody class="themes-bulk-updates" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
						<?php foreach ( $theme_upgrades as $slug => $theme_upgrade ) : ?>
							<?php $theme_name = rawurlencode( $slug ); ?>
							<tr theme_slug="<?php echo $theme_name; ?>" premium="<?php echo ( isset( $theme_upgrade['premium'] ) ? esc_attr( $theme_upgrade['premium'] ) : 0 ) ? 1 : 0; ?>" updated="0">
								<td>
									<?php echo esc_html( $theme_upgrade['Name'] ); ?>
									<input type="hidden" id="wp_upgraded_theme_<?php echo esc_attr( $website->id ); ?>_<?php echo $theme_name; ?>" value="0" />
								</td>
								<td><?php echo esc_html( $theme_upgrade['Version'] ); ?></td>
								<td><?php echo esc_html( $theme_upgrade['update']['new_version'] ); ?></a></td>
								<td><?php echo ( in_array( $slug, $trustedThemes, true ) ? MainWP_Updates::$trusted_label : MainWP_Updates::$not_trusted_label ); ?></td>
								<td><?php echo ( isset( $theme_upgrade['active'] ) && $theme_upgrade['active'] ) ? __( 'Active', 'mainwp' ) : __( 'Inactive', 'mainwp' ) ; ?></td>
								<td class="right aligned">
									<?php if ( $user_can_ignore_unignore ) : ?>
										<a href="#" onClick="return updatesoverview_themes_ignore_detail( '<?php echo $theme_name; ?>', '<?php echo rawurlencode( $theme_upgrade['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )" class="ui mini button"><?php esc_html_e( 'Ignore Update', 'mainwp' ); ?></a>
									<?php endif; ?>
									<?php if ( $user_can_update_themes ) : ?>
										<a href="#" class="ui green mini button" onClick="return updatesoverview_upgrade_theme( <?php echo esc_attr( $website->id ); ?>, '<?php echo $theme_name; ?>' )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
							<th class="no-sort"><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
							<th class="no-sort"><?php esc_html_e( 'Latest', 'mainwp' ); ?></th>
							<th><?php esc_html_e( 'Trusted', 'mainwp' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
							<th class="no-sort"></th>
						</tr>
					</tfoot>
				</table>
			<?php endif; ?>
			</div>
		<?php
	}

	/**
	 * Method render_language_updates()
	 *
	 * Render the Language Updates Tab.
	 *
	 * @param mixed $website Child Site info.
	 * @param mixed $active_tab Current active tab.
	 */
	public static function render_language_updates( $website, $active_tab ) {
		$user_can_update_translation = mainwp_current_user_have_right( 'dashboard', 'update_translations' );
		?>
		<div class="ui <?php echo 'trans' === $active_tab ? 'active' : ''; ?> tab" data-tab="translations">
			<table class="ui stackable single line table" id="mainwp-translations-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Translation', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
						<th class="collapsing no-sort"></th>
					</tr>
				</thead>
				<tbody class="translations-bulk-updates" id="wp_translation_upgrades_<?php echo esc_attr( $website->id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
				<?php $translation_upgrades = json_decode( $website->translation_upgrades, true ); ?>
				<?php foreach ( $translation_upgrades as $translation_upgrade ) : ?>
					<?php
					$translation_name = isset( $translation_upgrade['name'] ) ? $translation_upgrade['name'] : $translation_upgrade['slug'];
					$translation_slug = $translation_upgrade['slug'];
					?>
					<tr translation_slug="<?php echo $translation_slug; ?>" updated="0">
						<td>
							<?php echo esc_html( $translation_name ); ?>
							<input type="hidden" id="wp_upgraded_translation_<?php echo esc_attr( $website->id ); ?>_<?php echo $translation_slug; ?>" value="0"/>
						</td>
						<td>
							<?php echo esc_html( $translation_upgrade['version'] ); ?>
						</td>
						<td class="right aligned">
							<?php if ( $user_can_update_translation ) { ?>
								<a href="#" class="ui green mini button" onClick="return updatesoverview_upgrade_translation( <?php echo esc_attr( $website->id ); ?>, '<?php echo $translation_slug; ?>' )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
							<?php } ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th><?php esc_html_e( 'Translation', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
						<th class="collapsing no-sort"></th>
					</tr>
				</tfoot>
			</table>
		</div>
		<?php
	}

	/**
	 * Method render_abandoned_plugins()
	 *
	 * Render the Abandoned Plugin Tab.
	 *
	 * @param mixed $website Child Site info.
	 * @param mixed $active_tab Current active tab.
	 * @param mixed $userExtension MainWP trusted plugin data.
	 */
	public static function render_abandoned_plugins( $website, $active_tab, $userExtension ) {

		$user_can_ignore_unignore = mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' );

		$plugins_outdate = json_decode( MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' ), true );
		if ( ! is_array( $plugins_outdate ) ) {
			$plugins_outdate = array();
		}
		$pluginsOutdateDismissed = json_decode( MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_dismissed' ), true );
		if ( is_array( $pluginsOutdateDismissed ) ) {
			$plugins_outdate = array_diff_key( $plugins_outdate, $pluginsOutdateDismissed );
		}

		$decodedDismissedPlugins = json_decode( $userExtension->dismissed_plugins, true );
		if ( is_array( $decodedDismissedPlugins ) ) {
			$plugins_outdate = array_diff_key( $plugins_outdate, $decodedDismissedPlugins );
		}
		$str_format = __( 'Updated %s days ago', 'mainwp' );
		?>

		<div class="ui <?php echo 'abandoned-plugins' === $active_tab ? 'active' : ''; ?> tab" data-tab="abandoned-plugins">
			<table class="ui stackable single line table" id="mainwp-abandoned-plugins-table">
				<thead>
					<tr>
						<tr>
							<th><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
							<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
							<th><?php esc_html_e( 'Last Update', 'mainwp' ); ?></th>
							<th class="no-sort"></th>
						</tr>
					</tr>
				</thead>
				<tbody id="wp_plugins_outdate_<?php echo esc_attr( $website->id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
					<?php foreach ( $plugins_outdate as $slug => $plugin_outdate ) : ?>
						<?php
						$plugin_name              = rawurlencode( $slug );
						$now                      = new \DateTime();
						$last_updated             = $plugin_outdate['last_updated'];
						$plugin_last_updated_date = new \DateTime( '@' . $last_updated );
						$diff_in_days             = $now->diff( $plugin_last_updated_date )->format( '%a' );
						$outdate_notice           = sprintf( $str_format, $diff_in_days );
						?>
						<tr dismissed="0">
							<td>
								<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . dirname( $slug ) . '&url=' . ( isset( $plugin_outdate['PluginURI'] ) ? rawurlencode( $plugin_outdate['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_outdate['Name'] ) . '&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal"><?php echo esc_html( $plugin_outdate['Name'] ); ?></a>
								<input type="hidden" id="wp_dismissed_plugin_<?php echo esc_attr( $website->id ); ?>_<?php echo $plugin_name; ?>" value="0"/>
							</td>
							<td><?php echo esc_html( $plugin_outdate['Version'] ); ?></td>
							<td><?php echo $outdate_notice; ?></td>
							<td class="right aligned" id="wp_dismissbuttons_plugin_<?php echo esc_attr( $website->id ); ?>_<?php echo $plugin_name; ?>">
								<?php if ( $user_can_ignore_unignore ) { ?>
								<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_plugins_dismiss_outdate_detail( '<?php echo $plugin_name; ?>', '<?php echo rawurlencode( $plugin_outdate['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Now', 'mainwp' ); ?></a>
							<?php } ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<tr>
							<th><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
							<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
							<th><?php esc_html_e( 'Last Update', 'mainwp' ); ?></th>
							<th class="no-sort"></th>
						</tr>
					</tr>
				</tfoot>
			</table>
		</div>
		<?php
	}

	/**
	 * Method render_abandoned_themes()
	 *
	 * Render the Abandoned Themes tab.
	 *
	 * @param mixed $website Child Site info.
	 * @param mixed $active_tab Current active tab.
	 * @param mixed $userExtension MainWP trusted themes data.
	 */
	public static function render_abandoned_themes( $website, $active_tab, $userExtension ) {

		$user_can_ignore_unignore = mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' );

		$themes_outdate = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' ), true );
		if ( ! is_array( $themes_outdate ) ) {
			$themes_outdate = array();
		}

		if ( 0 < count( $themes_outdate ) ) {
			$themesOutdateDismissed = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' ), true );
			if ( is_array( $themesOutdateDismissed ) ) {
				$themes_outdate = array_diff_key( $themes_outdate, $themesOutdateDismissed );
			}

			$decodedDismissedThemes = json_decode( $userExtension->dismissed_themes, true );
			if ( is_array( $decodedDismissedThemes ) ) {
				$themes_outdate = array_diff_key( $themes_outdate, $decodedDismissedThemes );
			}
		}

		?>
		<div class="ui <?php echo 'abandoned-themes' === $active_tab ? 'active' : ''; ?> tab" data-tab="abandoned-themes">
			<table class="ui stackable single line table" id="mainwp-abandoned-themes-table">
				<thead>
					<tr>
						<tr>
							<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
							<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
							<th><?php esc_html_e( 'Last Update', 'mainwp' ); ?></th>
							<th class="no-sort"></th>
						</tr>
					</tr>
				</thead>
				<tbody site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
					<?php foreach ( $themes_outdate as $slug => $theme_outdate ) : ?>
						<?php
						$theme_name              = rawurlencode( $slug );
						$now                     = new \DateTime();
						$last_updated            = $theme_outdate['last_updated'];
						$theme_last_updated_date = new \DateTime( '@' . $last_updated );
						$diff_in_days            = $now->diff( $theme_last_updated_date )->format( '%a' );
						$outdate_notice          = sprintf( $str_format, $diff_in_days );
						?>
						<tr dismissed="0">
							<td>
								<?php echo esc_html( $theme_outdate['Name'] ); ?>
								<input type="hidden" id="wp_dismissed_theme_<?php echo esc_attr( $website->id ); ?>_<?php echo $theme_name; ?>" value="0"/>
							</td>
							<td><?php echo esc_html( $theme_outdate['Version'] ); ?></td>
							<td><?php echo $outdate_notice; ?></td>
							<td class="right aligned" id="wp_dismissbuttons_theme_<?php echo esc_attr( $website->id ); ?>_<?php echo $theme_name; ?>">
								<?php if ( $user_can_ignore_unignore ) { ?>
								<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_themes_dismiss_outdate_detail( '<?php echo $theme_name; ?>', '<?php echo rawurlencode( $theme_outdate['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Now', 'mainwp' ); ?></a>
								<?php } ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
