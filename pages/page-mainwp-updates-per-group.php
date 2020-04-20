<?php
namespace MainWP\Dashboard;

/**
 * MainWP Updates Page
 */
class MainWP_Updates_Per_Group {

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method render_wpcore_updates()
	 *
	 * Render WP core updates
	 *	 
	 * @param mixed $websites
	 * @param mixed $total_wp_upgrades
	 * @param mixed $all_groups_sites
	 * @param mixed $all_groups
	 * @param mixed $site_offset
	 * @return html
	 */
	public static function render_wpcore_updates( $websites, $total_wp_upgrades, $all_groups_sites, $all_groups, $site_offset ) {
		?>
			<table class="ui stackable single line table" id="mainwp-wordpress-updates-groups-table"> <!-- Per Group table -->
				<thead>
					<tr>
						<th class="collapsing no-sort"></th>
						<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Group', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
						<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
						<th class="no-sort right aligned">
							<?php
							if ( MainWP_Updates::user_can_update_wp() ) {
								if ( 0 < $total_wp_upgrades ) {
									MainWP_Updates::set_continue_update_html_selector( 'wpcore_global_upgrade_all' );
									?>
									<a class="ui green mini basic button" onclick="return updatesoverview_wordpress_global_upgrade_all();" href="javascript:void(0)" data-position="top right" data-tooltip="<?php esc_attr_e( 'Update WordPress Core files on all child sites.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Update All Groups', 'mainwp' ); ?></a>
									<?php
								}
							}
							?>
						</th>
					</tr>
				</thead>
				<tbody class="ui accordion"> <!-- per group -->
				<?php foreach ( $all_groups_sites as $group_id => $site_ids ) : ?>
						<?php
						$total_group_wp_updates = 0;
						$group_name             = $all_groups[ $group_id ];
						?>
						<tr row-uid="uid_wp_upgrades_<?php echo esc_attr( $group_id ); ?>" class="ui title">
							<td  class="accordion-trigger"><i class="icon dropdown"></i></td>
							<td><?php echo stripslashes( $group_name ); ?></td>
							<td sort-value="0"><span total-uid="uid_wp_upgrades_<?php echo esc_attr( $group_id ); ?>" data-inverted="" data-tooltip="<?php echo esc_attr( __( 'Click to see available updates', 'mainwp' ) ); ?>"></span></td>
							<td class="right aligned">
								<?php if ( MainWP_Updates::user_can_update_wp() ) : ?>
								<a href="javascript:void(0)" data-tooltip="<?php esc_attr_e( 'Update all sites in the group', 'mainwp' ); ?>" data-inverted="" data-position="left center" btn-all-uid="uid_wp_upgrades_<?php echo esc_attr( $group_id ); ?>" class="ui green button" onClick="return updatesoverview_wordpress_global_upgrade_all( <?php echo esc_attr( $group_id ); ?> )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
						<tr style="display:none">
							<td colspan="4" class="ui content">
								<table id="mainwp-wordpress-updates-groups-inner-table" class="ui stackable single line table mainwp-per-group-table">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
											<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
											<th class="no-sort"><?php esc_html_e( 'Latest', 'mainwp' ); ?></th>
											<th class="no-sort"></th>
										</tr>
									</thead>
									<tbody id="update_wrapper_wp_upgrades_group_<?php echo esc_attr( $group_id ); ?>">
										<?php foreach ( $site_ids as $site_id ) : ?>
											<?php
											$seek = $site_offset[ $site_id ];
											MainWP_DB::data_seek( $websites, $seek );
											$website = MainWP_DB::fetch_object( $websites );
											if ( $website->is_ignoreCoreUpdates ) {
												continue;
											}

											$wp_upgrades = json_decode( MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' ), true );

											if ( ( 0 == count( $wp_upgrades ) ) && ( '' == $website->sync_errors ) ) {
												continue;
											}

											$total_group_wp_updates++;
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
										<?php endforeach; ?>
									</tbody>
								</table>
							</td>
						</tr>
						<input type="hidden" class="element_ui_view_values" elem-uid="uid_wp_upgrades_<?php echo esc_attr( $group_id ); ?>" total="<?php echo intval( $total_group_wp_updates ); ?>" can-update="<?php echo MainWP_Updates::user_can_update_wp() ? 1 : 0; ?>">
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th></th>
						<th><?php esc_html_e( 'Group', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Updates', 'mainwp' ); ?></th>
						<th class="right aligned"></th>
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
	 * @param mixed $websites
	 * @param mixed $total_plugin_upgrades
	 * @param mixed $userExtension
	 * @param mixed $all_groups_sites
	 * @param mixed $all_groups
	 * @param mixed $site_offset
	 * @param mixed $trustedPlugins
	 * @return html
	 */
	public static function render_plugins_updates( $websites, $total_plugin_upgrades, $userExtension, $all_groups_sites, $all_groups, $site_offset, $trustedPlugins ) { // phpcs:ignore -- not quite complex method
		?>
		<table class="ui stackable single line table" id="mainwp-plugins-updates-groups-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Group', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="no-sort right aligned">
						<?php MainWP_UI::render_show_all_updates_button(); ?>
						<?php
						if ( MainWP_Updates::user_can_update_plugins() ) {
							MainWP_Updates::set_continue_update_html_selector( 'plugins_global_upgrade_all' );
							if ( 0 < $total_plugin_upgrades ) {
								?>
							<a href="javascript:void(0)" onClick="return updatesoverview_plugins_global_upgrade_all();" class="ui basic mini green button" data-tooltip="<?php esc_html_e( 'Update all sites.', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php esc_html_e( 'Update All Plugins' ); ?></a>
								<?php
							}
						}
						?>
					</th>
				</tr>
			</thead>
			<tbody id="plugins-updates-global" class="ui accordion">
				<?php foreach ( $all_groups_sites as $group_id => $site_ids ) : ?>
					<?php
					if ( empty( $site_ids ) ) {
						continue;
					}

					$total_group_plugin_updates = 0;
					$group_name                 = $all_groups[ $group_id ];
					?>
					<tr class="title" row-uid="uid_plugin_updates_<?php echo esc_attr( $group_id ); ?>">
						<td class="accordion-trigger"><i class="icon dropdown"></i></td>
						<td><?php echo stripslashes( $group_name ); ?></td>
						<td total-uid="uid_plugin_updates_<?php echo esc_attr( $group_id ); ?>" sort-value="0"></td>
						<td class="right aligned" >
						<?php if ( MainWP_Updates::user_can_update_plugins() ) { ?>
							<a href="javascript:void(0)" btn-all-uid="uid_plugin_updates_<?php echo esc_attr( $group_id ); ?>" class="ui green mini button" onClick="return updatesoverview_plugins_global_upgrade_all( <?php echo esc_attr( $group_id ); ?> )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
						<?php } ?>
						</td>
					</tr>
					<tr row-uid="uid_plugin_updates_<?php echo esc_attr( $group_id ); ?>" style="display:none">
						<td colspan="4" class="content">
							<table id="mainwp-wordpress-updates-sites-inner-table" class="ui stackable single line grey table mainwp-per-group-table">
								<thead>
									<tr>
										<th class="collapsing no-sort"></th>
										<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										<th class="collapsing no-sort"></th>
									</tr>
								</thead>
								<tbody  id="update_wrapper_plugin_upgrades_group_<?php echo esc_attr( $group_id ); ?>" >
									<?php	foreach ( $site_ids as $site_id ) : ?>
										<?php
										$seek = $site_offset[ $site_id ];
										MainWP_DB::data_seek( $websites, $seek );

										$website = MainWP_DB::fetch_object( $websites );
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
													$premiumUpgrade              = array_filter( $premiumUpgrade );
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

										$total_group_plugin_updates += count( $plugin_upgrades );

										if ( ( 0 === count( $plugin_upgrades ) ) && ( '' == $website->sync_errors ) ) {
											continue;
										}
										?>
										<tr class="ui title">
											<td class="accordion-trigger"><i class="icon dropdown"></i></td>
											<td>
												<?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
											</td>
											<td sort-value="<?php echo count( $plugin_upgrades ); ?>"><?php echo count( $plugin_upgrades ) . ' ' . _n( 'Update', 'Updates', count( $plugin_upgrades ), 'mainwp' ); ?></td>
											<td class="right aligned">
												<?php if ( MainWP_Updates::user_can_update_plugins() ) : ?>
													<?php if ( 0 < count( $plugin_upgrades ) ) : ?>
														<a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_group_upgrade_plugin_all( <?php echo esc_attr( $website->id ); ?>, <?php echo esc_attr( $group_id ); ?>, this )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
													<?php endif; ?>
												<?php endif; ?>
											</td>
										</tr>
										<tr style="display:none">
											<td colspan="4" class="content">
												<table id="mainwp-wordpress-updates-plugins-inner-table" class="ui stackable single line table">
													<thead>
														<tr>
															<th><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
															<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
															<th class="no-sort"><?php esc_html_e( 'Latest', 'mainwp' ); ?></th>
															<th><?php esc_html_e( 'Trusted', 'mainwp' ); ?></th>
															<th class="no-sort"></th>
														</tr>
													</thead>
													<tbody class="plugins-bulk-updates"  id="wp_plugin_upgrades_<?php echo esc_attr( $website->id ); ?>_group_<?php echo esc_attr( $group_id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
													<?php foreach ( $plugin_upgrades as $slug => $plugin_upgrade ) : ?>
														<?php $plugin_name = rawurlencode( $slug ); ?>
														<tr class="mainwp-plugin-update" plugin_slug="<?php echo $plugin_name; ?>" premium="<?php echo ( isset( $plugin_upgrade['premium'] ) ? $plugin_upgrade['premium'] : 0 ) ? 1 : 0; ?>" updated="0">
															<td>
																<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . esc_attr( $plugin_upgrade['update']['slug'] ) . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal">
																	<?php echo esc_html( $plugin_upgrade['Name'] ); ?>
																</a>
																<input type="hidden" id="wp_upgraded_plugin_<?php echo esc_attr( $website->id ); ?>_group_<?php echo esc_attr( $group_id ); ?>_<?php echo $plugin_name; ?>" value="0"/>
															</td>
															<td><?php echo esc_html( $plugin_upgrade['Version'] ); ?></td>
															<td>
																<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . $plugin_upgrade['update']['slug'] . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '&section=changelog&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal">
																	<?php echo esc_html( $plugin_upgrade['update']['new_version'] ); ?>
																</a>
															</td>
															<td><?php echo ( in_array( $slug, $trustedPlugins ) ? MainWP_Updates::$trusted_label : MainWP_Updates::$not_trusted_label ); ?></td>
															<td class="right aligned">
															<?php if ( MainWP_Updates::user_can_ignore_updates() ) : ?>
																<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_plugins_ignore_detail( '<?php echo $plugin_name; ?>', '<?php echo rawurlencode( $plugin_upgrade['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Update', 'mainwp' ); ?></a>
															<?php endif; ?>
															<?php if ( MainWP_Updates::user_can_update_plugins() ) : ?>
																<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_plugins_upgrade( '<?php echo $plugin_name; ?>', <?php echo esc_attr( $website->id ); ?> )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
															<?php endif; ?>
															</td>
														</tr>
													<?php endforeach; ?>
													</tbody>
												</table>
											</td>
										</tr>
									<?php	endforeach; ?>
								</tbody>
								<tfoot>
									<tr>
										<th class="collapsing no-sort"></th>
										<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
										<th><?php esc_html_e( 'Updates', 'mainwp' ); ?></th>
										<th class="collapsing no-sort"></th>
									</tr>
								</tfoot>
							</table>
						</td>
					</tr>
					<input type="hidden" class="element_ui_view_values" elem-uid="uid_plugin_updates_<?php echo esc_attr( $group_id ); ?>" total="<?php echo intval( $total_group_plugin_updates ); ?>" can-update="<?php echo MainWP_Updates::user_can_update_plugins() ? 1 : 0; ?>">
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th class="collapsing no-sort"></th>
					<th><?php esc_html_e( 'Group', 'mainwp' ); ?></th>
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
	 * Render themes updates
	 *	 
	 * @param mixed $websites
	 * @param mixed $total_theme_upgrades
	 * @param mixed $userExtension
	 * @param mixed $all_groups_sites
	 * @param mixed $all_groups
	 * @param mixed $site_offset
	 * @param mixed $trustedThemes
	 * @return html
	 */
	public static function render_themes_updates( $websites, $total_theme_upgrades, $userExtension, $all_groups_sites, $all_groups, $site_offset, $trustedThemes ) { // phpcs:ignore -- not quite complex method

		?>
		<table class="ui stackable single line table" id="mainwp-themes-updates-groups-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Group', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="no-sort right aligned">
						<?php MainWP_UI::render_show_all_updates_button(); ?>
						<?php
						if ( MainWP_Updates::user_can_update_themes() ) {
							MainWP_Updates::set_continue_update_html_selector( 'themes_global_upgrade_all' );
							if ( 0 < $total_theme_upgrades ) {
								?>
							<a href="javascript:void(0)" onClick="return updatesoverview_themes_global_upgrade_all();" class="ui basic mini green button" data-tooltip="<?php esc_html_e( 'Update all sites.', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php esc_html_e( 'Update All Themes' ); ?></a>
								<?php
							}
						}
						?>
					</th>
				</tr>
			</thead>
			<tbody id="themes-updates-global" class="ui accordion">
				<?php foreach ( $all_groups_sites as $group_id => $site_ids ) : ?>
					<?php
					if ( empty( $site_ids ) ) {
						continue;
					}

					$total_group_theme_updates = 0;
					$group_name                = $all_groups[ $group_id ];
					?>
					<tr class="title" row-uid="uid_theme_updates_<?php echo esc_attr( $group_id ); ?>">
						<td class="accordion-trigger"><i class="icon dropdown"></i></td>
						<td><?php echo stripslashes( $group_name ); ?></td>
						<td total-uid="uid_theme_updates_<?php echo esc_attr( $group_id ); ?>" sort-value="0"></td>
						<td class="right aligned" >
						<?php if ( MainWP_Updates::user_can_update_themes() ) { ?>
						<a href="javascript:void(0)" btn-all-uid="uid_theme_updates_<?php echo esc_attr( $group_id ); ?>" class="ui green mini button" onClick="return updatesoverview_themes_global_upgrade_all( <?php echo esc_attr( $group_id ); ?> )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
						<?php } ?>
						</td>
					</tr>
					<tr row-uid="uid_theme_updates_<?php echo esc_attr( $group_id ); ?>" style="display:none">
						<td colspan="4" class="content">
							<table id="mainwp-wordpress-updates-sites-inner-table" class="ui stackable single line grey table mainwp-per-group-table">
								<thead>
									<tr>
										<th class="collapsing no-sort"></th>
										<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										<th class="collapsing no-sort"></th>
									</tr>
								</thead>
								<tbody class="accordion" id="update_wrapper_theme_upgrades_group_<?php echo esc_attr( $group_id ); ?>">
									<?php	foreach ( $site_ids as $site_id ) : ?>
										<?php
										$seek = $site_offset[ $site_id ];
										MainWP_DB::data_seek( $websites, $seek );

										$website = MainWP_DB::fetch_object( $websites );
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
													$premiumUpgrade             = array_filter( $premiumUpgrade );
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

										$total_group_theme_updates += count( $theme_upgrades );

										if ( ( 0 === count( $theme_upgrades ) ) && ( '' == $website->sync_errors ) ) {
											continue;
										}
										?>
										<tr class="ui title">
											<td class="accordion-trigger"><i class="icon dropdown"></i></td>
											<td>
												<?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
											</td>
											<td sort-value="<?php echo count( $theme_upgrades ); ?>"><?php echo count( $theme_upgrades ) . ' ' . _n( 'Update', 'Updates', count( $theme_upgrades ), 'mainwp' ); ?></td>
											<td class="right aligned">
												<?php if ( MainWP_Updates::user_can_update_themes() ) : ?>
													<?php if ( 0 < count( $theme_upgrades ) ) : ?>
														<a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_group_upgrade_theme_all( <?php echo esc_attr( $website->id ); ?>, <?php echo esc_attr( $group_id ); ?>, this )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
													<?php endif; ?>
												<?php endif; ?>
											</td>
										</tr>
										<tr style="display:none">
											<td colspan="4" class="content">
												<table id="mainwp-wordpress-updates-themes-inner-table" class="ui stackable single line table">
													<thead>
														<tr>
															<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
															<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
															<th class="no-sort"><?php esc_html_e( 'Latest', 'mainwp' ); ?></th>
															<th><?php esc_html_e( 'Trusted', 'mainwp' ); ?></th>
															<th class="no-sort"></th>
														</tr>
													</thead>
													<tbody class="themes-bulk-updates" id="wp_theme_upgrades_<?php echo esc_attr( $website->id ); ?>_group_<?php echo esc_attr( $group_id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
													<?php foreach ( $theme_upgrades as $slug => $theme_upgrade ) : ?>
														<?php $theme_name = rawurlencode( $slug ); ?>
														<tr class="mainwp-theme-update" theme_slug="<?php echo $theme_name; ?>" premium="<?php echo ( isset( $theme_upgrade['premium'] ) ? $theme_upgrade['premium'] : 0 ) ? 1 : 0; ?>" updated="0">
															<td>
																<?php echo esc_html( $theme_upgrade['Name'] ); ?>
																<input type="hidden" id="wp_upgraded_theme_<?php echo esc_attr( $website->id ); ?>_group_<?php echo esc_attr( $group_id ); ?>_<?php echo $theme_name; ?>" value="0"/>
															</td>
															<td><?php echo esc_html( $theme_upgrade['Version'] ); ?></td>
															<td><?php echo esc_html( $theme_upgrade['update']['new_version'] ); ?></td>
															<td><?php echo ( in_array( $slug, $trustedThemes ) ? MainWP_Updates::$trusted_label : MainWP_Updates::$not_trusted_label ); ?></td>
															<td class="right aligned">
															<?php if ( MainWP_Updates::user_can_ignore_updates() ) : ?>
																<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_themes_ignore_detail( '<?php echo $theme_name; ?>', '<?php echo rawurlencode( $theme_upgrade['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Update', 'mainwp' ); ?></a>
															<?php endif; ?>
															<?php if ( MainWP_Updates::user_can_update_themes() ) : ?>
																<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_themes_upgrade( '<?php echo $theme_name; ?>', <?php echo esc_attr( $website->id ); ?> )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
															<?php endif; ?>
															</td>
														</tr>
													<?php endforeach; ?>
													</tbody>
												</table>
											</td>
										</tr>
									<?php	endforeach; ?>
								</tbody>
								<thead>
									<tr>
										<th class="collapsing no-sort"></th>
										<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
										<th><?php esc_html_e( 'Updates', 'mainwp' ); ?></th>
										<th class="collapsing no-sort"></th>
									</tr>
								</thead>
							</table>
						</td>
					</tr>
					<input type="hidden" class="element_ui_view_values" elem-uid="uid_theme_updates_<?php echo esc_attr( $group_id ); ?>" total="<?php echo intval( $total_group_theme_updates ); ?>" can-update="<?php echo MainWP_Updates::user_can_update_themes() ? 1 : 0; ?>">
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th class="collapsing no-sort"></th>
					<th><?php esc_html_e( 'Group', 'mainwp' ); ?></th>
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
	 * @param mixed $websites
	 * @param mixed $total_translation_upgrades
	 * @param mixed $all_groups_sites
	 * @param mixed $all_groups
	 * @param mixed $site_offset
	 * @return html
	 */
	public static function render_trans_update( $websites, $total_translation_upgrades, $all_groups_sites, $all_groups, $site_offset ) {

		?>
		<table class="ui stackable single line table" id="mainwp-translations-groups-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Group', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
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
			<tbody id="translations-updates-global" class="ui accordion">
				<?php foreach ( $all_groups_sites as $group_id => $site_ids ) : ?>
					<?php
					$total_group_translation_updates = 0;
					$group_name                      = $all_groups[ $group_id ];
					?>
					<tr row-uid="uid_translation_updates_<?php echo esc_attr( $group_id ); ?>" class="ui title">
						<td class="accordion-trigger"><i class="dropdown icon"></i></td>
						<td><?php echo stripslashes( $group_name ); ?></td>
						<td total-uid="uid_translation_updates_<?php echo esc_attr( $group_id ); ?>" sort-value="0"></td>
						<td class="right aligned">
						<?php if ( MainWP_Updates::user_can_update_trans() ) { ?>
							<a href="javascript:void(0)" btn-all-uid="uid_translation_updates_<?php echo esc_attr( $group_id ); ?>" class="ui green mini button"  onClick="return updatesoverview_translations_global_upgrade_all( <?php echo esc_attr( $group_id ); ?> )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
						<?php } ?>
						</td>
					</tr>
					<tr style="display:none">
						<td colspan="4" class="ui content">
							<table class="ui stackable single line grey table mainwp-per-group-table" id="mainwp-translations-sites-table">
								<thead>
									<tr>
										<th class="collapsing no-sort"></th>
										<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										<th class="right aligned"></th>
									</tr>
								</thead>
								<tbody class="accordion" id="update_wrapper_translation_upgrades_group_<?php echo esc_attr( $group_id ); ?>" row-uid="uid_translation_updates_<?php echo esc_attr( $group_id ); ?>">
								<?php foreach ( $site_ids as $site_id ) : ?>
									<?php
									$seek = $site_offset[ $site_id ];
									MainWP_DB::data_seek( $websites, $seek );
									$website                          = MainWP_DB::fetch_object( $websites );
									$translation_upgrades             = json_decode( $website->translation_upgrades, true );
									$total_group_translation_updates += count( $translation_upgrades );

									if ( ( 0 === count( $translation_upgrades ) ) && ( '' == $website->sync_errors ) ) {
										continue;
									}
									?>
									<tr class="ui title">
										<td class="accordion-trigger"><i class="dropdown icon"></i></td>
										<td>
											<?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
										</td>
										<td sort-value="<?php echo count( $translation_upgrades ); ?>">
											<?php echo _n( 'Update', 'Updates', count( $translation_upgrades ), 'mainwp' ); ?>
										</td>
										<td class="right aligned">
										<?php if ( MainWP_Updates::user_can_update_trans() ) : ?>
											<?php if ( 0 < count( $translation_upgrades ) ) : ?>
												<a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_group_upgrade_translation_all( <?php echo esc_attr( $website->id ); ?>, <?php echo esc_attr( $group_id ); ?>, this )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
											<?php endif; ?>
										<?php endif; ?>
										</td>
									</tr>
									<tr style="display:none">
										<td class="content" colspan="4">
											<table class="ui stackable single line table" id="mainwp-translations-table">
												<thead>
													<tr>
														<th><?php esc_html_e( 'translationName', 'mainwp' ); ?></th>
														<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
														<th class="right aligned"></th>
													</tr>
												</thead>
												<tbody id="wp_translation_upgrades_<?php echo esc_attr( $website->id ); ?>_group_<?php echo esc_attr( $group_id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
												<?php foreach ( $translation_upgrades as $translation_upgrade ) : ?>
													<?php
													$translation_name = isset( $translation_upgrade['name'] ) ? $translation_upgrade['name'] : $translation_upgrade['slug'];
													$translation_slug = $translation_upgrade['slug'];
													?>
													<tr class="mainwp-translation-update" translation_slug="<?php echo $translation_slug; ?>" updated="0">
														<td>
															<?php echo $translation_name; ?>
															<input type="hidden" id="wp_upgraded_translation_<?php echo esc_attr( $website->id ); ?>_group_<?php echo esc_attr( $group_id ); ?>_<?php echo $translation_slug; ?>" value="0"/>
														</td>
														<td>
															<?php echo esc_html( $translation_upgrade['version'] ); ?>
														</td>
														<td class="right aligned">
														<?php if ( MainWP_Updates::user_can_update_trans() ) : ?>
															<a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_group_upgrade_translation( <?php echo esc_attr( $website->id ); ?>, '<?php echo $translation_slug; ?>', <?php echo esc_attr( $group_id ); ?> )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
														<?php endif; ?>
														</td>
													</tr>
												<?php endforeach; ?>
												</tbody>
											</table>
										</td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</td>
					</tr>
					<input type="hidden" class="element_ui_view_values" elem-uid="uid_translation_updates_<?php echo esc_attr( $group_id ); ?>" total="<?php echo intval( $total_group_translation_updates ); ?>" can-update="<?php echo MainWP_Updates::user_can_update_trans() ? 1 : 0; ?>">
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th class="collapsing no-sort"></th>
					<th><?php esc_html_e( 'Group', 'mainwp' ); ?></th>
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
	 * @param mixed $websites
	 * @param mixed $all_groups_sites
	 * @param mixed $all_groups
	 * @param mixed $site_offset
	 * @param mixed $decodedDismissedPlugins
	 *
	 * @return html
	 */
	public static function render_abandoned_plugins( $websites, $all_groups_sites, $all_groups, $site_offset, $decodedDismissedPlugins ) {
		$str_format = __( 'Updated %s days ago', 'mainwp' );
		?>
		<table class="ui stackable single line table" id="mainwp-abandoned-plugins-groups-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Group', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="right aligned indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
				</tr>
			</thead>
			<tbody class="ui accordion">
			<?php foreach ( $all_groups_sites as $group_id => $site_ids ) : ?>
				<?php
				$total_group_plugins_outdate = 0;
				$group_name                  = $all_groups[ $group_id ];
				?>
				<tr row-uid="uid_plugins_outdate_<?php echo esc_attr( $group_id ); ?>" class="title">
					<td class="accordion-trigger"><i class="dropdown icon"></i></td>
					<td><?php echo stripslashes( $group_name ); ?></td>
					<td class="right aligned" total-uid="uid_plugins_outdate_<?php echo esc_attr( $group_id ); ?>" sort-value="0"></td>
				</tr>
				<tr style="display:none" row-uid="uid_plugins_outdate_<?php echo esc_attr( $group_id ); ?>">
					<td colspan="3" class="content">
						<table class="ui stackable single line grey table mainwp-per-group-table" id="mainwp-abandoned-plugins-sites-table">
							<thead>
								<tr>
								<th class="collapsing no-sort"></th>
								<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
								<th class="right aligned indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
								</tr>
							</thead>
							<tbody class="accordion">
							<?php foreach ( $site_ids as $site_id ) : ?>
								<?php
								$seek = $site_offset[ $site_id ];
								MainWP_DB::data_seek( $websites, $seek );

								$website = MainWP_DB::fetch_object( $websites );

								$plugins_outdate = json_decode( MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' ), true );

								if ( ! is_array( $plugins_outdate ) ) {
									$plugins_outdate = array();
								}

								if ( 0 < count( $plugins_outdate ) ) {
									$pluginsOutdateDismissed = json_decode( MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_dismissed' ), true );
									if ( is_array( $pluginsOutdateDismissed ) ) {
										$plugins_outdate = array_diff_key( $plugins_outdate, $pluginsOutdateDismissed );
									}

									if ( is_array( $decodedDismissedPlugins ) ) {
										$plugins_outdate = array_diff_key( $plugins_outdate, $decodedDismissedPlugins );
									}
								}

								$total_group_plugins_outdate += count( $plugins_outdate );
								?>
								<?php if ( 0 < count( $plugins_outdate ) ) : ?>
								<tr class="ui title">
									<td class="accordion-trigger"><i class="dropdown icon"></i></td>
									<td>
										<?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
									</td>
									<td class="right aligned" sort-value="<?php echo count( $plugins_outdate ); ?>">
										<?php echo count( $plugins_outdate ); ?> <?php echo _n( 'Plugin', 'Plugins', count( $plugins_outdate ), 'mainwp' ); ?>
									</td>
								</tr>
								<tr style="display:none">
									<td colspan="3" class="ui content">
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
											<tbody site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
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
								<?php endif; ?>
							<?php endforeach; ?>
							</tbody>
						</table>
					</td>
				</tr>
				<input type="hidden" class="element_ui_view_values" elem-uid="uid_plugins_outdate_<?php echo esc_attr( $group_id ); ?>" total="<?php echo intval( $total_group_plugins_outdate ); ?>" can-update="0">
			<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th class="collapsing no-sort"></th>
					<th><?php esc_html_e( 'Group', 'mainwp' ); ?></th>
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
	 * @param mixed $websites
	 * @param mixed $all_groups_sites
	 * @param mixed $all_groups
	 * @param mixed $site_offset
	 * @param mixed $decodedDismissedThemes
	 *
	 * @return html
	 */
	public static function render_abandoned_themes( $websites, $all_groups_sites, $all_groups, $site_offset, $decodedDismissedThemes ) {
		$str_format = __( 'Updated %s days ago', 'mainwp' );
		?>
		<table class="ui stackable single line table" id="mainwp-abandoned-themes-groups-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Group', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="right aligned indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
				</tr>
			</thead>
			<tbody class="ui accordion">
			<?php foreach ( $all_groups_sites as $group_id => $site_ids ) : ?>
				<?php
				$total_group_themes_outdate = 0;
				$group_name                 = $all_groups[ $group_id ];
				?>
				<tr row-uid="uid_themes_outdate_<?php echo esc_attr( $group_id ); ?>" class="title">
					<td class="accordion-trigger"><i class="dropdown icon"></i></td>
					<td><?php echo stripslashes( $group_name ); ?></td>
					<td class="right aligned" total-uid="uid_themes_outdate_<?php echo esc_attr( $group_id ); ?>" sort-value="0"></td>
				</tr>
				<tr style="display:none" row-uid="uid_themes_outdate_<?php echo esc_attr( $group_id ); ?>">
					<td colspan="3" class="content">
						<table class="ui stackable single line grey table mainwp-per-group-table" id="mainwp-abandoned-themes-sites-table">
							<thead>
								<tr>
									<th class="collapsing no-sort"></th>
									<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
									<th class="right aligned indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
								</tr>
							</thead>
							<tbody class="accordion">
							<?php foreach ( $site_ids as $site_id ) : ?>
								<?php
								$seek = $site_offset[ $site_id ];
								MainWP_DB::data_seek( $websites, $seek );

								$website = MainWP_DB::fetch_object( $websites );

								$themes_outdate = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' ), true );

								if ( ! is_array( $themes_outdate ) ) {
									$themes_outdate = array();
								}

								if ( 0 < count( $themes_outdate ) ) {
									$themesOutdateDismissed = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' ), true );
									if ( is_array( $themesOutdateDismissed ) ) {
										$themes_outdate = array_diff_key( $themes_outdate, $themesOutdateDismissed );
									}

									if ( is_array( $decodedDismissedThemes ) ) {
										$themes_outdate = array_diff_key( $themes_outdate, $decodedDismissedThemes );
									}
								}

								$total_group_themes_outdate += count( $themes_outdate );
								?>
								<?php if ( 0 < count( $themes_outdate ) ) : ?>
								<tr class="ui title">
									<td class="accordion-trigger"><i class="dropdown icon"></i></td>
									<td>
									<?php MainWP_Updates::render_site_link_dashboard(); ?>
									</td>
									<td class="right aligned" sort-value="<?php echo count( $themes_outdate ); ?>">
										<?php echo count( $themes_outdate ); ?> <?php echo _n( 'Theme', 'Themes', count( $themes_outdate ), 'mainwp' ); ?>
									</td>
								</tr>
								<tr style="display:none">
									<td colspan="3" class="ui content">
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
								<?php endif; ?>
							<?php endforeach; ?>
							</tbody>
						</table>
					</td>
				</tr>
				<input type="hidden" class="element_ui_view_values" elem-uid="uid_themes_outdate_<?php echo esc_attr( $group_id ); ?>" total="<?php echo intval( $total_group_themes_outdate ); ?>" can-update="0">
			<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th class="collapsing no-sort"></th>
					<th><?php esc_html_e( 'Group', 'mainwp' ); ?></th>
					<th class="right aligned"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?></th>
				</tr>
			</tfoot>
		</table>
		<?php
	}

}
