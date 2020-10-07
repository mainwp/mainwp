<?php
/**
 * MainWP Updates Per Site
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Updates_Per_Site
 */
class MainWP_Updates_Per_Site {

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object class name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method render_wpcore_updates()
	 *
	 * Render WP core updates
	 *
	 * @param object $websites the websites.
	 * @param int    $total_wp_upgrades number of available WordPress updates.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
	 */
	public static function render_wpcore_updates( $websites, $total_wp_upgrades ) {
		?>
		<table class="ui stackable single line table" id="mainwp-wordpress-updates-table">
			<thead>
				<tr>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Version', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Latest', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="no-sort right aligned">
						<?php
						if ( MainWP_Updates::user_can_update_wp() ) {
							if ( 0 < $total_wp_upgrades ) {
								MainWP_Updates::set_continue_update_html_selector( 'wpcore_global_upgrade_all' );
								?>
								<a class="ui green mini basic button" onclick="return updatesoverview_wordpress_global_upgrade_all();" href="javascript:void(0)" data-position="top right" data-tooltip="<?php esc_attr_e( 'Update WordPress Core files on all child sites.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Update All Sites', 'mainwp' ); ?></a>
								<?php
							}
						}
						?>
					</th>
				</tr>
			</thead>
			<tbody> <!-- per site or plugin -->
				<?php
				while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
					if ( $website->is_ignoreCoreUpdates ) {
						continue;
					}

					$wp_upgrades = json_decode( MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' ), true );

					if ( ( 0 === count( $wp_upgrades ) ) && ( '' == $website->sync_errors ) ) {
						continue;
					}
					?>
				<tr class="mainwp-wordpress-update" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>" updated="<?php echo ( 0 < count( $wp_upgrades ) ) ? '0' : '1'; ?>">
					<td>
						<?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
						<input type="hidden" id="wp-updated-<?php echo esc_attr( $website->id ); ?>" value="<?php echo ( 0 < count( $wp_upgrades ) ? '0' : '1' ); ?>" />
					</td>
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
						<?php if ( MainWP_Updates::user_can_update_wp() ) : ?>
							<?php if ( 0 < count( $wp_upgrades ) ) : ?>
								<a href="javascript:void(0)" data-tooltip="<?php esc_attr_e( 'Update', 'mainwp' ) . ' ' . $website->name; ?>" data-inverted="" data-position="left center" class="ui green button mini" onClick="return updatesoverview_upgrade(<?php echo esc_attr( $website->id ); ?>, this )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
					<?php
				}
				?>
			</tbody>
			<tfoot>
				<tr>
					<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Current Version', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'New Version', 'mainwp' ); ?></th>
					<th class="right aligned">
					</th>
				</tr>
			</tfoot>
		</table>
		<?php
	}

	/**
	 * Method render_plugins_updates()
	 *
	 * Render Plugins updates
	 *
	 * @param object $websites the websites.
	 * @param int    $total_plugin_upgrades number of available plugins updates.
	 * @param mixed  $userExtension user extension.
	 * @param array  $trustedPlugins trusted plugins.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_show_all_updates_button()
	 * @uses \MainWP\Dashboard\MainWP_UI
	 * @uses \MainWP\Dashboard\MainWP_Updates_Table_Helper
	 */
	public static function render_plugins_updates( $websites, $total_plugin_upgrades, $userExtension, $trustedPlugins ) { // phpcs:ignore -- not quite complex method.
		?>
		<table class="ui stackable single line table" id="mainwp-plugins-updates-sites-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo $total_plugin_upgrades . ' ' . _n( 'Update', 'Updates', $total_plugin_upgrades, 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="no-sort right aligned">
						<?php MainWP_UI::render_show_all_updates_button(); ?>
						<?php
						if ( MainWP_Updates::user_can_update_plugins() ) {
							MainWP_Updates::set_continue_update_html_selector( 'plugins_global_upgrade_all' );
							if ( 0 < $total_plugin_upgrades ) {
								?>
							<a href="javascript:void(0)" onClick="return updatesoverview_plugins_global_upgrade_all();" class="ui basic mini green button" data-tooltip="<?php esc_html_e( 'Update all plugins.', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php esc_html_e( 'Update All Sites' ); ?></a>
								<?php
							}
						}
						?>
					</th>
				</tr>
			</thead>
			<tbody id="plugins-updates-global" class="ui accordion">
				<?php
				$updates_table_helper = new MainWP_Updates_Table_Helper( $userExtension->site_view );

				MainWP_DB::data_seek( $websites, 0 );
				while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
					if ( $website->is_ignorePluginUpdates ) {
						continue;
					}
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

					if ( ( 0 === count( $plugin_upgrades ) ) && ( '' == $website->sync_errors ) ) {
						continue;
					}
					?>
					<tr class="ui title">
						<td class="accordion-trigger"><i class="icon dropdown"></i></td>
						<td>
							<?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
						</td>
						<td sort-value="<?php echo count( $plugin_upgrades ); ?>"><?php echo count( $plugin_upgrades ); ?> <?php echo _n( 'Update', 'Updates', count( $plugin_upgrades ), 'mainwp' ); ?></td>
						<td class="right aligned">
						<?php if ( MainWP_Updates::user_can_update_plugins() ) : ?>
							<?php if ( 0 < count( $plugin_upgrades ) ) : ?>
								<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_upgrade_plugin_all( <?php echo esc_attr( $website->id ); ?> )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
							<?php endif; ?>
						<?php endif; ?>
						</td>
					</tr>

					<tr style="display:none">
						<td colspan="4" class="ui content">
							<table id="mainwp-wordpress-updates-groups-inner-table" class="ui stackable single line table">
								<thead>
									<tr>
									<?php $updates_table_helper->print_column_headers(); ?>
									</tr>
								</thead>
								<tbody class="plugins-bulk-updates" id="wp_plugin_upgrades_<?php echo intval( $website->id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
									<?php foreach ( $plugin_upgrades as $slug => $plugin_upgrade ) : ?>
										<?php $plugin_name = rawurlencode( $slug ); ?>
										<?php
										$indent_hidden = '<input type="hidden" id="wp_upgraded_plugin_' . esc_attr( $website->id ) . '_' . $plugin_name . '" value="0" />';
										$row_columns   = array(
											'title'   => '<a href="' . admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . esc_attr( $plugin_upgrade['update']['slug'] ) . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '&TB_iframe=true&width=772&height=887" target="_blank" class="thickbox open-plugin-details-modal">' . esc_html( $plugin_upgrade['Name'] ) . '</a>' . $indent_hidden,
											'version' => esc_html( $plugin_upgrade['Version'] ),
											'latest'  => '<a href="' . admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . $plugin_upgrade['update']['slug'] . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '&section=changelog&TB_iframe=true&width=772&height=887" target="_blank" class="thickbox open-plugin-details-modal">' . esc_html( $plugin_upgrade['update']['new_version'] ) . '</a>',
											'trusted' => ( in_array( $slug, $trustedPlugins ) ? true : false ),
											'status'  => ( isset( $plugin_upgrade['active'] ) && $plugin_upgrade['active'] ) ? true : false,
										);
										?>
										<tr plugin_slug="<?php echo $plugin_name; ?>" premium="<?php echo ( isset( $plugin_upgrade['premium'] ) ? esc_attr( $plugin_upgrade['premium'] ) : 0 ) ? 1 : 0; ?>" updated="0">
											<?php
											$row_columns     = $updates_table_helper->render_columns( $row_columns, $website );
											$action_rendered = isset( $row_columns['action'] ) ? true : false;
											if ( ! $action_rendered ) :
												?>
											<td class="right aligned">
												<?php if ( MainWP_Updates::user_can_ignore_updates() ) : ?>
													<a href="javascript:void(0)" onClick="return updatesoverview_plugins_ignore_detail( '<?php echo $plugin_name; ?>', '<?php echo rawurlencode( $plugin_upgrade['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )" class="ui mini button"><?php esc_html_e( 'Ignore Update', 'mainwp' ); ?></a>
												<?php endif; ?>
												<?php if ( MainWP_Updates::user_can_update_plugins() ) : ?>
													<a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_upgrade_plugin( <?php echo esc_attr( $website->id ); ?>, '<?php echo $plugin_name; ?>' )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
												<?php endif; ?>
											</td>
											<?php endif; ?>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</td>
					</tr>
					<?php
				}
				?>
			</tbody>
			<tfoot>
				<tr>
					<th class="collapsing no-sort"></th>
					<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
					<th><?php echo $total_plugin_upgrades . ' ' . _n( 'Update', 'Updates', $total_plugin_upgrades, 'mainwp' ); ?></th>
					<th class="no-sort right aligned"></th>
				</tr>
			</tfoot>
		</table>
		<?php
	}

	/**
	 * Method render_themes_updates()
	 *
	 * Render Themes updates
	 *
	 * @param object $websites the websites.
	 * @param int    $total_theme_upgrades number of available themes updates.
	 * @param mixed  $userExtension user extension.
	 * @param array  $trustedThemes trusted themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_show_all_updates_button()
	 * @uses \MainWP\Dashboard\MainWP_UI
	 * @uses \MainWP\Dashboard\MainWP_Updates_Table_Helper
	 */
	public static function render_themes_updates( $websites, $total_theme_upgrades, $userExtension, $trustedThemes ) { // phpcs:ignore -- not quite complex method.
		?>
		<table class="ui stackable single line table" id="mainwp-themes-updates-sites-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo $total_theme_upgrades . ' ' . _n( 'Update', 'Updates', $total_theme_upgrades, 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="no-sort right aligned">
						<?php MainWP_UI::render_show_all_updates_button(); ?>
						<?php
						if ( MainWP_Updates::user_can_update_themes() ) {
							MainWP_Updates::set_continue_update_html_selector( 'themes_global_upgrade_all' );
							if ( 0 < $total_theme_upgrades ) {
								?>
							<a href="javascript:void(0)" onClick="return updatesoverview_themes_global_upgrade_all();" class="ui basic mini green button" data-tooltip="<?php esc_html_e( 'Update all themes.', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php esc_html_e( 'Update All Sites' ); ?></a>
								<?php
							}
						}
						?>
					</th>
				</tr>
			</thead>
			<tbody id="themes-updates-global" class="ui accordion">
				<?php
				$updates_table_helper = new MainWP_Updates_Table_Helper( $userExtension->site_view, 'theme' );
				MainWP_DB::data_seek( $websites, 0 );
				while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
					if ( $website->is_ignoreThemeUpdates ) {
						continue;
					}
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

					if ( ( 0 === count( $theme_upgrades ) ) && ( '' == $website->sync_errors ) ) {
						continue;
					}
					?>
					<tr class="ui title">
						<td class="accordion-trigger"><i class="icon dropdown"></i></td>
						<td>
							<?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
						</td>
						<td sort-value="<?php echo count( $theme_upgrades ); ?>"><?php echo count( $theme_upgrades ); ?> <?php echo _n( 'Update', 'Updates', count( $theme_upgrades ), 'mainwp' ); ?></td>
						<td class="right aligned">
						<?php if ( MainWP_Updates::user_can_update_themes() ) : ?>
							<?php if ( 0 < count( $theme_upgrades ) ) : ?>
								<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_upgrade_theme_all( <?php echo esc_attr( $website->id ); ?> )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
							<?php endif; ?>
						<?php endif; ?>
						</td>
					</tr>
					<tr style="display:none">
						<td colspan="4" class="ui content">
							<table id="mainwp-wordpress-updates-groups-inner-table" class="ui stackable single line table">
								<thead>
									<tr>
									<?php $updates_table_helper->print_column_headers(); ?>
									</tr>
								</thead>
								<tbody class="themes-bulk-updates" id="wp_theme_upgrades_<?php echo intval( $website->id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
									<?php foreach ( $theme_upgrades as $slug => $theme_upgrade ) : ?>
										<?php $theme_name = rawurlencode( $slug ); ?>
										<?php $indent_hidden = '<input type="hidden" id="wp_upgraded_theme_' . esc_attr( $website->id ) . '_' . $theme_name . '" value="0" />'; ?>
										<?php
										$row_columns = array(
											'title'   => esc_html( $theme_upgrade['Name'] ) . $indent_hidden,
											'version' => esc_html( $theme_upgrade['Version'] ),
											'latest'  => esc_html( $theme_upgrade['update']['new_version'] ),
											'trusted' => ( in_array( $slug, $trustedThemes, true ) ? true : false ),
											'status'  => ( isset( $theme_upgrade['active'] ) && $theme_upgrade['active'] ) ? true : false,
										);
										?>
										<tr theme_slug="<?php echo $theme_name; ?>" premium="<?php echo ( isset( $theme_upgrade['premium'] ) ? esc_attr( $theme_upgrade['premium'] ) : 0 ) ? 1 : 0; ?>" updated="0">
											<?php
											$row_columns     = $updates_table_helper->render_columns( $row_columns, $website );
											$action_rendered = isset( $row_columns['action'] ) ? true : false;
											if ( ! $action_rendered ) :
												?>
											<td class="right aligned">
												<?php if ( MainWP_Updates::user_can_ignore_updates() ) : ?>
													<a href="javascript:void(0)" onClick="return updatesoverview_themes_ignore_detail( '<?php echo $theme_name; ?>', '<?php echo rawurlencode( $theme_upgrade['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )" class="ui mini button"><?php esc_html_e( 'Ignore Update', 'mainwp' ); ?></a>
												<?php endif; ?>
												<?php if ( MainWP_Updates::user_can_update_themes() ) : ?>
													<a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_upgrade_theme( <?php echo esc_attr( $website->id ); ?>, '<?php echo $theme_name; ?>' )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
												<?php endif; ?>
											</td>
											<?php endif; ?>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</td>
					</tr>
					<?php
				}
				?>
			</tbody>
			<tfoot>
				<tr>
					<th class="collapsing no-sort"></th>
					<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
					<th><?php echo $total_theme_upgrades . ' ' . _n( 'Update', 'Updates', $total_theme_upgrades, 'mainwp' ); ?></th>
					<th class="no-sort right aligned"></th>
				</tr>
			</tfoot>
		</table>
		<?php
	}

	/**
	 * Method render_trans_update()
	 *
	 * Render translations updates
	 *
	 * @param object $websites the websites.
	 * @param int    $total_translation_upgrades number of available translation updates.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_show_all_updates_button()
	 */
	public static function render_trans_update( $websites, $total_translation_upgrades ) {
		?>
		<table class="ui stackable single line table" id="mainwp-translations-sites-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="right aligned">
						<?php MainWP_UI::render_show_all_updates_button(); ?>
						<?php if ( MainWP_Updates::user_can_update_trans() ) : ?>
							<?php if ( 0 < $total_translation_upgrades ) : ?>
								<a href="javascript:void(0)" onClick="return updatesoverview_translations_global_upgrade_all();" class="ui button basic mini green" data-tooltip="<?php esc_html_e( 'Update all translations', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php esc_html_e( 'Update All Sites', 'mainwp' ); ?></a>
							<?php endif; ?>
						<?php endif; ?>
					</th>
				</tr>
			</thead>
			<tbody id="translations-updates-global"  class="ui accordion">
				<?php
				MainWP_DB::data_seek( $websites, 0 );
				while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
					$translation_upgrades = json_decode( $website->translation_upgrades, true );
					if ( ( 0 === count( $translation_upgrades ) ) && ( '' == $website->sync_errors ) ) {
						continue;
					}
					?>
					<tr class="title">
						<td class="accordion-trigger"><i class="dropdown icon"></i></td>
						<td>
							<?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
						</td>
						<td sort-value="<?php echo count( $translation_upgrades ); ?>">
							<?php echo count( $translation_upgrades ); ?> <?php echo _n( 'Update', 'Updates', count( $translation_upgrades ), 'mainwp' ); ?>
						</td>
						<td class="right aligned">
						<?php if ( MainWP_Updates::user_can_update_trans() ) : ?>
							<?php if ( 0 < count( $translation_upgrades ) ) : ?>
									<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_upgrade_translation_all( <?php echo esc_attr( $website->id ); ?> )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
							<?php endif; ?>
						<?php endif; ?>
						</td>
					</tr>
					<tr style="display:none">
						<td colspan="4" class="content">
							<table class="ui stackable single line table" id="mainwp-translations-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Translation', 'mainwp' ); ?></th>
										<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
										<th class="collapsing no-sort"></th>
									</tr>
								</thead>
								<tbody class="translations-bulk-updates" id="wp_translation_upgrades_<?php echo esc_attr( $website->id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
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
											<?php if ( MainWP_Updates::user_can_update_trans() ) { ?>
												<a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_translations_upgrade( '<?php echo $translation_slug; ?>', <?php echo esc_attr( $website->id ); ?> )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
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
						</td>
					</tr>
					<?php
				}
				?>
			</tbody>
			<tfoot>
				<tr>
					<th class="collapsing no-sort"></th>
					<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Updates', 'mainwp' ); ?></th>
					<th class="right aligned"></th>
				</tr>
			</tfoot>
		</table>
		<?php
	}

	/**
	 * Method render_abandoned_plugins()
	 *
	 * Render abandoned plugins
	 *
	 * @param object $websites                the websites.
	 * @param array  $decodedDismissedPlugins all dismissed plugins.
	 *
	 * @throws \Exception Error message.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
	 */
	public static function render_abandoned_plugins( $websites, $decodedDismissedPlugins ) {
		$str_format = __( 'Updated %s days ago', 'mainwp' );
		?>
		<table class="ui stackable single line table" id="mainwp-abandoned-plugins-sites-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="right aligned indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
				</tr>
			</thead>
			<tbody class="ui accordion">
				<?php MainWP_DB::data_seek( $websites, 0 ); ?>
				<?php
				while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
					$plugins_outdate = json_decode( MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' ), true );

					if ( ! is_array( $plugins_outdate ) ) {
						$plugins_outdate = array();
					}

					$pluginsOutdateDismissed = json_decode( MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_dismissed' ), true );
					if ( is_array( $pluginsOutdateDismissed ) ) {
						$plugins_outdate = array_diff_key( $plugins_outdate, $pluginsOutdateDismissed );
					}

					if ( is_array( $decodedDismissedPlugins ) ) {
						$plugins_outdate = array_diff_key( $plugins_outdate, $decodedDismissedPlugins );
					}

					if ( 0 === count( $plugins_outdate ) ) {
						continue;
					}

					?>

					<tr class="title">
						<td class="accordion-trigger"><i class="icon dropdown"></i></td>
						<td>
							<?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
						</td>
						<td class="right aligned" sort-value="<?php echo count( $plugins_outdate ); ?>"><?php echo count( $plugins_outdate ); ?> <?php echo _n( 'Plugin', 'Plugins', count( $plugins_outdate ), 'mainwp' ); ?></td>
					</tr>
					<tr style="display:none">
						<td colspan="3" class="content">
							<table class="ui stackable single line table" id="mainwp-abandoned-plugins-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
										<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
										<th><?php esc_html_e( 'Last Update', 'mainwp' ); ?></th>
										<th class="no-sort"></th>
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
											<?php if ( MainWP_Updates::user_can_ignore_updates() ) { ?>
												<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_plugins_dismiss_outdate_detail( '<?php echo $plugin_name; ?>', '<?php echo rawurlencode( $plugin_outdate['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Now', 'mainwp' ); ?></a>
											<?php } ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</td>
					</tr>
					<?php
				}
				?>
			</tbody>
			<tfoot>
				<tr>
					<th class="collapsing no-sort"></th>
					<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
					<th class="right aligned"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?></th>
				</tr>
			</tfoot>
		</table>
		<?php
	}

	/**
	 * Method render_abandoned_themes()
	 *
	 * Render abandoned themes
	 *
	 * @param object $websites the websites.
	 * @param array  $decodedDismissedThemes all dismissed themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
	 */
	public static function render_abandoned_themes( $websites, $decodedDismissedThemes ) {
		$str_format = __( 'Updated %s days ago', 'mainwp' );
		?>
		<table class="ui stackable single line table" id="mainwp-abandoned-themes-sites-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="right aligned indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
				</tr>
			</thead>
			<tbody class="ui accordion">
				<?php MainWP_DB::data_seek( $websites, 0 ); ?>
				<?php
				while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
					$themes_outdate = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' ), true );

					if ( is_array( $themes_outdate ) ) {
						$themesOutdateDismissed = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' ), true );
						if ( is_array( $themesOutdateDismissed ) ) {
							$themes_outdate = array_diff_key( $themes_outdate, $themesOutdateDismissed );
						}
						if ( is_array( $decodedDismissedThemes ) ) {
							$themes_outdate = array_diff_key( $themes_outdate, $decodedDismissedThemes );
						}
					} else {
						$themes_outdate = array();
					}

					if ( 0 === count( $themes_outdate ) ) {
						continue;
					}

					?>
					<tr class="title">
						<td class="accordion-trigger"><i class="icon dropdown"></i></td>
						<td>
							<?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
						</td>
						<td class="right aligned" sort-value="<?php echo count( $themes_outdate ); ?>"> <?php echo count( $themes_outdate ); ?> <?php echo _n( 'Theme', 'Themes', count( $themes_outdate ), 'mainwp' ); ?></td>
					</tr>
					<tr style="display:none">
						<td colspan="3" class="content">
							<table class="ui stackable single line table" id="mainwp-abandoned-themes-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
										<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
										<th><?php esc_html_e( 'Last Update', 'mainwp' ); ?></th>
										<th class="no-sort"></th>
									</tr>
								</thead>
								<tbody id="wp_themes_outdate_<?php echo esc_attr( $website->id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
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
												<?php if ( MainWP_Updates::user_can_ignore_updates() ) { ?>
													<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_themes_dismiss_outdate_detail( '<?php echo $theme_name; ?>', '<?php echo rawurlencode( $theme_outdate['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Now', 'mainwp' ); ?></a>
												<?php } ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</td>
					</tr>
					<?php
				}
				?>
			</tbody>
			<tfoot>
				<tr>
					<th class="collapsing no-sort"></th>
					<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
					<th class="right aligned"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?></th>
				</tr>
			</tfoot>
		</table>
		<?php
	}

}
