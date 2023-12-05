<?php
/**
 * MainWP Updates Per Group.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Updates_Per_Group
 *
 * @package MainWP\Dashboard
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
	 * @param object $websites the websites.
	 * @param int    $total_wp_upgrades total update.
	 * @param array  $all_groups_sites all groups of sites.
	 * @param array  $all_groups all groups.
	 * @param int    $site_offset_for_groups offset value.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
	 * @uses \MainWP\Dashboard\MainWP_Updates::user_can_update_wp()
	 * @uses \MainWP\Dashboard\MainWP_Updates::set_continue_update_html_selector()
	 * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
	 */
	public static function render_wpcore_updates( $websites, $total_wp_upgrades, $all_groups_sites, $all_groups, $site_offset_for_groups ) { // phpcs:ignore -- complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$is_demo = MainWP_Demo_Handle::is_demo_mode();
		?>
			<table class="ui tablet stackable table mainwp-manage-updates-table main-master-checkbox" id="mainwp-wordpress-updates-groups-table"> <!-- Per Group table -->
				<thead>
					<tr>
						<th class="collapsing no-sort trigger-all-accordion">
							<span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span>
						</th>
						<th class="indicator-accordion-sorting handle-accordion-sorting">
							<div class="ui main-master checkbox ">
								<input type="checkbox" name=""><label><?php esc_html_e( 'Tag', 'mainwp' ); ?></label>
							</div>
							<?php MainWP_UI::render_sorting_icons(); ?>
						</th>
						<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
						<th class="no-sort">
							<?php
							if ( MainWP_Updates::user_can_update_wp() ) {
								if ( 0 < $total_wp_upgrades ) {
									MainWP_Updates::set_continue_update_html_selector( 'wpcore_global_upgrade_all' );
									if ( $is_demo ) {
										MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green mini basic button disabled mainwp-update-selected-button" disabled="disabled">' . esc_html__( 'Update Selected Sites', 'mainwp' ) . '</a>' );
										MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green mini button disabled mainwp-update-all-button" disabled="disabled">' . esc_html__( 'Update All Tags', 'mainwp' ) . '</a>' );
									} else {
										?>
										<a class="mainwp-update-selected-button ui green mini basic button" onclick="event.stopPropagation(); return updatesoverview_wordpress_global_upgrade_all( false, true );" href="javascript:void(0)" data-position="top right" data-tooltip="<?php esc_attr_e( 'Update WordPress Core files on all child sites.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Update Selected Sites', 'mainwp' ); ?></a>
										<a class="mainwp-update-all-button ui green mini button" onclick="return updatesoverview_wordpress_global_upgrade_all();" href="javascript:void(0)" data-position="top right" data-tooltip="<?php esc_attr_e( 'Update WordPress Core files on all child sites.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Update All Tags', 'mainwp' ); ?></a>
										<?php
									}
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
						<tr row-uid="uid_wp_upgrades_<?php echo esc_attr( $group_id ); ?>" class="ui title master-checkbox">
							<td  class="accordion-trigger"><i class="icon dropdown"></i></td>
							<td>
								<div class="ui master checkbox">
									<input type="checkbox" name=""><label><?php echo esc_html( stripslashes( $group_name ) ); ?></label>
								</div>

							</td>
							<td sort-value="0"><span total-uid="uid_wp_upgrades_<?php echo esc_attr( $group_id ); ?>" data-inverted="" data-tooltip="<?php echo esc_attr( esc_html__( 'Click to see available updates', 'mainwp' ) ); ?>"></span></td>
							<td class="right aligned">
								<?php
								if ( MainWP_Updates::user_can_update_wp() ) :
									if ( $is_demo ) {
										MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui basic green button disabled mainwp-update-selected-button" disabled="disabled">' . esc_html__( 'Update Selected', 'mainwp' ) . '</a>' );
										MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green button disabled mainwp-update-all-button" disabled="disabled">' . esc_html__( 'Update All', 'mainwp' ) . '</a>' );
									} else {
										?>
										<a href="javascript:void(0)" data-tooltip="<?php esc_attr_e( 'Update all sites in the tag', 'mainwp' ); ?>" data-inverted="" data-position="left center" btn-all-uid2="uid_wp_upgrades_<?php echo esc_attr( $group_id ); ?>" class="mainwp-update-selected-button ui basic green button" onClick="event.stopPropagation(); return updatesoverview_wordpress_global_upgrade_all( <?php echo esc_attr( $group_id ); ?>, true )"><?php esc_html_e( 'Update Selected', 'mainwp' ); ?></a>
										<a href="javascript:void(0)" data-tooltip="<?php esc_attr_e( 'Update all sites in the tag', 'mainwp' ); ?>" data-inverted="" data-position="left center" btn-all-uid="uid_wp_upgrades_<?php echo esc_attr( $group_id ); ?>" class="mainwp-update-all-button ui green button" onClick="return updatesoverview_wordpress_global_upgrade_all( <?php echo esc_attr( $group_id ); ?> )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
									<?php } ?>
								<?php endif; ?>
							</td>
						</tr>
						<tr class="child-checkbox content">
							<td colspan="4">
								<table id="mainwp-wordpress-updates-groups-inner-table" class="ui table mainwp-manage-updates-table mainwp-per-group-table">
									<thead class="mainwp-768-hide">
										<tr>
											<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
											<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
											<th class="no-sort"><?php esc_html_e( 'Latest', 'mainwp' ); ?></th>
											<th><?php esc_html_e( 'Client', 'mainwp' ); ?></th>
											<th class="no-sort"></th>
										</tr>
									</thead>
									<tbody id="update_wrapper_wp_upgrades_group_<?php echo esc_attr( $group_id ); ?>">
										<?php foreach ( $site_ids as $site_id ) : ?>
											<?php
											if ( ! isset( $site_offset_for_groups[ $site_id ] ) ) {
												continue;
											}
											$seek = $site_offset_for_groups[ $site_id ];
											MainWP_DB::data_seek( $websites, $seek );
											$website = MainWP_DB::fetch_object( $websites );
											if ( $website->is_ignoreCoreUpdates ) {
												continue;
											}

											$wp_upgrades = MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' );
											$wp_upgrades = ! empty( $wp_upgrades ) ? json_decode( $wp_upgrades, true ) : array();

											if ( ( 0 === count( $wp_upgrades ) ) && empty( $website->sync_errors ) ) {
												continue;
											}

											$wpcore_update_disabled_by = MainWP_System_Utility::disabled_wpcore_update_by( $website );

											++$total_group_wp_updates;
											?>
											<tr class="mainwp-wordpress-update" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>" updated="<?php echo ( 0 < count( $wp_upgrades ) && empty( $wpcore_update_disabled_by ) ) ? '0' : '1'; ?>">
												<td>
													<div class="ui child checkbox">
														<input type="checkbox" name=""><label><?php MainWP_Updates::render_site_link_dashboard( $website ); ?></label>
													</div>
													<input type="hidden" id="wp-updated-<?php echo esc_attr( $website->id ); ?>" value="<?php echo ( 0 < count( $wp_upgrades ) ? '0' : '1' ); ?>" />
												</td>
												<td>
													<?php if ( 0 < count( $wp_upgrades ) ) : ?>
														<strong class="mainwp-768-show"><?php esc_html_e( 'Version:', 'mainwp' ); ?></strong> <?php echo esc_html( $wp_upgrades['current'] ); ?>
													<?php endif; ?>
												</td>
												<td>
													<?php if ( 0 < count( $wp_upgrades ) ) : ?>
														<strong class="mainwp-768-show"><?php esc_html_e( 'Latest:', 'mainwp' ); ?></strong> <?php echo esc_html( $wp_upgrades['new'] ); ?>
													<?php endif; ?>
												</td>
												<td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
												<td>
													<?php if ( MainWP_Updates::user_can_update_wp() ) : ?>
														<?php
														if ( 0 < count( $wp_upgrades ) ) :
															if ( ! empty( $wpcore_update_disabled_by ) ) {
																?>
																<span data-tooltip="<?php echo esc_html( $wpcore_update_disabled_by ); ?>" data-inverted="" data-position="left center"><a href="javascript:void(0)" class="ui green button mini disabled"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a></span>
																<?php
															} else {
																if ( $is_demo ) {
																	MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green button mini disabled" disabled="disabled">' . esc_html__( 'Update Now', 'mainwp' ) . '</a>' );
																} else {
																	?>
																<a href="javascript:void(0)" data-tooltip="<?php esc_attr_e( 'Update', 'mainwp' ) . ' ' . $website->name; ?>" data-inverted="" data-position="left center" class="mainwp-update-now-button ui green button mini" onClick="return updatesoverview_upgrade(<?php echo esc_attr( $website->id ); ?>, this )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
															<?php } ?>
														<?php } ?>
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
						<th>
							<div class="ui main-master checkbox ">
								<input type="checkbox" name=""><label><?php esc_html_e( 'Tags', 'mainwp' ); ?></label>
							</div>
						</th>
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
	 * @param object $websites the websites.
	 * @param int    $total_plugin_upgrades total plugin updates.
	 * @param mixed  $userExtension The user extension.
	 * @param array  $all_groups_sites all groups of sites.
	 * @param array  $all_groups all groups.
	 * @param int    $site_offset_for_groups offset value.
	 * @param array  $trustedPlugins all plugins trusted by user.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_show_all_updates_button()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Table_Helper
	 * @uses \MainWP\Dashboard\MainWP_Updates::user_can_update_plugins()
	 * @uses \MainWP\Dashboard\MainWP_Updates::set_continue_update_html_selector()
	 * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
	 * @uses \MainWP\Dashboard\MainWP_Updates::user_can_ignore_updates()
	 */
	public static function render_plugins_updates( $websites, $total_plugin_upgrades, $userExtension, $all_groups_sites, $all_groups, $site_offset_for_groups, $trustedPlugins ) { // phpcs:ignore -- not quite complex method.
		$updates_table_helper = new MainWP_Updates_Table_Helper( $userExtension->site_view );
		$is_demo              = MainWP_Demo_Handle::is_demo_mode();
		?>
		<table class="ui tablet stackable table mainwp-manage-updates-table main-master-checkbox" id="mainwp-plugins-updates-groups-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion">
						<span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span>
					</th>
					<th class="indicator-accordion-sorting handle-accordion-sorting">
						<div class="ui main-master checkbox ">
							<input type="checkbox" name=""><label><?php esc_html_e( 'Tag', 'mainwp' ); ?></label>
						</div>
						<?php MainWP_UI::render_sorting_icons(); ?>
					</th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="no-sort">
						<?php MainWP_UI::render_show_all_updates_button(); ?>
						<?php
						if ( MainWP_Updates::user_can_update_plugins() ) {
							MainWP_Updates::set_continue_update_html_selector( 'plugins_global_upgrade_all' );
							if ( 0 < $total_plugin_upgrades ) {
								if ( $is_demo ) {
									MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" disabled="disabled" class="ui mini green basic button disabled mainwp-update-selected-button">' . esc_html__( 'Update Selected Plugins', 'mainwp' ) . '</a>' );
									MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" disabled="disabled" class="ui mini green button disabled mainwp-update-selected-button">' . esc_html__( 'Update Selected Plugins', 'mainwp' ) . '</a>' );
								} else {
									?>
									<a href="javascript:void(0)" data-tooltip="<?php esc_html_e( 'Update Selected Plugins.', 'mainwp' ); ?>" onClick="return updatesoverview_plugins_global_upgrade_all( false, true );" class="mainwp-update-selected-button ui mini green basic button" data-inverted="" data-position="top right"><?php esc_html_e( 'Update Selected Plugins' ); ?></a>
									<a href="javascript:void(0)" data-tooltip="<?php esc_html_e( 'Update all sites.', 'mainwp' ); ?>" onClick="return updatesoverview_plugins_global_upgrade_all();" class="mainwp-update-all-button ui mini green button" data-inverted="" data-position="top right"><?php esc_html_e( 'Update All Plugins' ); ?></a>
									<?php
								}
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
					<tr class="title main-master-checkbox" row-uid="uid_plugin_updates_<?php echo esc_attr( $group_id ); ?>">
						<td class="accordion-trigger"><i class="icon dropdown"></i></td>
						<td>
						<div class="ui main-master checkbox ">
							<input type="checkbox" name=""><label><?php echo esc_html( stripslashes( $group_name ) ); ?></label>
						</div>
						</td>
						<td total-uid="uid_plugin_updates_<?php echo esc_attr( $group_id ); ?>" sort-value="0"></td>
						<td>
						<?php
						if ( MainWP_Updates::user_can_update_plugins() ) {
							if ( $is_demo ) {
								MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" disabled="disabled" class="ui mini green basic button disabled mainwp-update-selected-button">' . esc_html__( 'Update Selected', 'mainwp' ) . '</a>' );
								MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" disabled="disabled" class="ui mini green button mainwp-update-all-button disabled">' . esc_html__( 'Update All', 'mainwp' ) . '</a>' );
							} else {
								?>
								<a href="javascript:void(0)" none-btn-all-uid="uid_plugin_updates_<?php echo esc_attr( $group_id ); ?>" class="mainwp-update-selected-button ui green basic mini button"  onClick="event.stopPropagation(); return updatesoverview_plugins_global_upgrade_all( <?php echo esc_attr( $group_id ); ?>, true )"><?php esc_html_e( 'Update Selected', 'mainwp' ); ?></a>
								<a href="javascript:void(0)" btn-all-uid="uid_plugin_updates_<?php echo esc_attr( $group_id ); ?>" class="mainwp-update-all-button ui green mini button" onClick="return updatesoverview_plugins_global_upgrade_all( <?php echo esc_attr( $group_id ); ?> )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
							<?php } ?>
						<?php } ?>
						</td>
					</tr>
					<tr class="main-child-checkbox content" row-uid="uid_plugin_updates_<?php echo esc_attr( $group_id ); ?>">
						<td colspan="4">
							<table id="mainwp-wordpress-updates-sites-inner-table" class="ui grey table mainwp-per-group-table mainwp-manage-updates-table">
								<thead class="mainwp-768-hide">
									<tr>
										<th class="collapsing no-sort"></th>
										<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo esc_html__( 'Client', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										<th class="collapsing no-sort"></th>
									</tr>
								</thead>
								<tbody  id="update_wrapper_plugin_upgrades_group_<?php echo esc_attr( $group_id ); ?>" >
									<?php	foreach ( $site_ids as $site_id ) : ?>
										<?php
										if ( ! isset( $site_offset_for_groups[ $site_id ] ) ) {
											continue;
										}
										$seek = $site_offset_for_groups[ $site_id ];
										MainWP_DB::data_seek( $websites, $seek );

										$website = MainWP_DB::fetch_object( $websites );
										if ( $website->is_ignorePluginUpdates ) {
											continue;
										}

										$plugin_upgrades        = json_decode( $website->plugin_upgrades, true );
										$decodedPremiumUpgrades = MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' );
										$decodedPremiumUpgrades = ! empty( $decodedPremiumUpgrades ) ? json_decode( $decodedPremiumUpgrades, true ) : array();

										if ( is_array( $decodedPremiumUpgrades ) ) {
											foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
												$premiumUpgrade['premium'] = true;

												if ( 'plugin' === $premiumUpgrade['type'] ) {
													if ( ! is_array( $plugin_upgrades ) ) {
														$plugin_upgrades = array();
													}
													$premiumUpgrade = array_filter( $premiumUpgrade );
													if ( isset( $plugin_upgrades[ $crrSlug ] ) && is_array( $plugin_upgrades[ $crrSlug ] ) ) {
														$plugin_upgrades[ $crrSlug ] = array_merge( $plugin_upgrades[ $crrSlug ], $premiumUpgrade );
													} else {
														$plugin_upgrades[ $crrSlug ] = $premiumUpgrade;
													}
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

										if ( ( 0 === count( $plugin_upgrades ) ) && ( empty( $website->sync_errors ) ) ) {
											continue;
										}
										?>
										<tr class="ui title master-checkbox">
											<td class="accordion-trigger"><i class="icon dropdown"></i></td>
											<td>
												<div class="ui master checkbox">
													<input type="checkbox" name=""><label><?php MainWP_Updates::render_site_link_dashboard( $website ); ?></label>
												</div>
											</td>
											<td sort-value="<?php echo count( $plugin_upgrades ); ?>"><strong class="mainwp-768-show"><?php echo esc_html__( 'Updates: ', 'mainwp' ); ?></strong> <?php echo count( $plugin_upgrades ) . ' ' . esc_html( _n( 'Update', 'Updates', count( $plugin_upgrades ), 'mainwp' ) ); ?></td>
											<td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
											<td>
												<?php if ( MainWP_Updates::user_can_update_plugins() ) : ?>
													<?php
													if ( 0 < count( $plugin_upgrades ) ) :
														if ( $is_demo ) {
															MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green basic mini button disabled mainwp-update-selected-button" disabled="disabled">' . esc_html__( 'Update Selected', 'mainwp' ) . '</a>' );
															MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green mini button disabled mainwp-update-all-button" disabled="disabled">' . esc_html__( 'Update All', 'mainwp' ) . '</a>' );
														} else {
															?>
														<a href="javascript:void(0)" class="mainwp-update-selected-button ui green basic mini button" onClick="event.stopPropagation(); return updatesoverview_group_upgrade_plugin_all( <?php echo esc_attr( $website->id ); ?>, <?php echo esc_attr( $group_id ); ?>, true )"><?php esc_html_e( 'Update Selected', 'mainwp' ); ?></a>
														<a href="javascript:void(0)" class="mainwp-update-all-button ui green mini button" onClick="return updatesoverview_group_upgrade_plugin_all( <?php echo esc_attr( $website->id ); ?>, <?php echo esc_attr( $group_id ); ?> )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
														<?php } ?>
													<?php endif; ?>
												<?php endif; ?>
											</td>
										</tr>
										<tr  class="child-checkbox content">
											<td colspan="5">
												<table id="mainwp-wordpress-updates-plugins-inner-table" class="ui table mainwp-manage-updates-table mainwp-updates-list">
													<thead class="mainwp-768-hide">
														<tr>
														<?php $updates_table_helper->print_column_headers(); ?>
														</tr>
													</thead>
													<tbody class="plugins-bulk-updates"  id="wp_plugin_upgrades_<?php echo esc_attr( $website->id ); ?>_group_<?php echo esc_attr( $group_id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>">
													<?php foreach ( $plugin_upgrades as $slug => $plugin_upgrade ) : ?>
														<?php $plugin_name = rawurlencode( $slug ); ?>
														<?php
														$indent_hidden = '<input type="hidden" id="wp_upgraded_plugin_' . esc_attr( $website->id ) . '_group_' . esc_attr( $group_id ) . '_' . $plugin_name . '" value="0"/>';
														$row_columns   = array(
															'title'   => MainWP_System_Utility::get_plugin_icon( dirname( $slug ) ) . '&nbsp;&nbsp;&nbsp;&nbsp;<a href="' . admin_url() . 'plugin-install.php?tab=plugin-information&wpplugin=' . intval( $website->id ) . '&plugin=' . esc_attr( $plugin_upgrade['update']['slug'] ) . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '" target="_blank" class="open-plugin-details-modal">' . esc_html( $plugin_upgrade['Name'] ) . '</a>' . $indent_hidden,
															'version' => '<strong class="mainwp-768-show">' . esc_html__( 'Version: ', 'mainwp' ) . '</strong>' . esc_html( $plugin_upgrade['Version'] ),
															'latest'  => '<strong class="mainwp-768-show">' . esc_html__( 'Updates: ', 'mainwp' ) . '</strong><a href="' . admin_url() . 'plugin-install.php?tab=plugin-information&wpplugin=' . intval( $website->id ) . '&plugin=' . esc_attr( $plugin_upgrade['update']['slug'] ) . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '&section=changelog" target="_blank" class="open-plugin-details-modal">' . esc_html( $plugin_upgrade['update']['new_version'] ) . '</a>',
															'trusted' => ( in_array( $slug, $trustedPlugins ) ? true : false ),
															'status'  => ( isset( $plugin_upgrade['active'] ) && $plugin_upgrade['active'] ) ? true : false,
														);
														?>
														<tr class="mainwp-plugin-update" plugin_slug="<?php echo esc_attr( $plugin_name ); ?>" premium="<?php echo isset( $plugin_upgrade['premium'] ) && ! empty( $plugin_upgrade['premium'] ) ? 1 : 0; ?>" updated="0">
															<?php
															$row_columns     = $updates_table_helper->render_columns( $row_columns, $website );
															$action_rendered = isset( $row_columns['action'] ) ? true : false;
															if ( ! $action_rendered ) :
																?>
															<td>
																<?php if ( MainWP_Updates::user_can_ignore_updates() ) : ?>
																<a href="javascript:void(0)" class="mainwp-ignore-update-button ui mini button" onClick="return updatesoverview_plugins_ignore_detail( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_js( rawurlencode( $plugin_upgrade['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Update', 'mainwp' ); ?></a>
															<?php endif; ?>
																<?php
																if ( MainWP_Updates::user_can_update_plugins() ) :
																	if ( $is_demo ) {
																		MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui mini green button disabled" disabled="disabled">' . esc_html__( 'Update Now', 'mainwp' ) . '</a>' );
																	} else {
																		?>
																		<a href="javascript:void(0)" class="mainwp-update-now-button ui mini green button" onClick="return updatesoverview_plugins_upgrade( '<?php echo esc_js( $plugin_name ); ?>', <?php echo intval( $website->id ); ?> )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
																		<?php
																	}
																endif;
																?>
															</td>
															<?php endif; ?>
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
										<th><?php esc_html_e( 'Client', 'mainwp' ); ?></th>
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
					<th>
						<div class="ui main-master checkbox ">
							<input type="checkbox" name=""><label><?php esc_html_e( 'Tag', 'mainwp' ); ?></label>
						</div>

					</th>
					<th><?php echo intval( $total_plugin_upgrades ) . ' ' . esc_html( _n( 'Update', 'Updates', $total_plugin_upgrades, 'mainwp' ) ); ?></th>
					<th class="no-sort"></th>
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
	 * @param object $websites the websites.
	 * @param int    $total_theme_upgrades total themes updates.
	 * @param mixed  $userExtension The user extension.
	 * @param array  $all_groups_sites all groups of sites.
	 * @param array  $all_groups all groups.
	 * @param int    $site_offset_for_groups offset value.
	 * @param array  $trustedThemes all themes trusted by user.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_show_all_updates_button()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Table_Helper
	 * @uses \MainWP\Dashboard\MainWP_Updates::user_can_update_themes()
	 * @uses \MainWP\Dashboard\MainWP_Updates::set_continue_update_html_selector()
	 * @uses \MainWP\Dashboard\MainWP_Updates::user_can_update_themes()
	 * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
	 * @uses \MainWP\Dashboard\MainWP_Updates::user_can_ignore_updates()
	 */
	public static function render_themes_updates( $websites, $total_theme_upgrades, $userExtension, $all_groups_sites, $all_groups, $site_offset_for_groups, $trustedThemes ) { // phpcs:ignore -- not quite complex method.
		$updates_table_helper = new MainWP_Updates_Table_Helper( $userExtension->site_view, 'theme' );
		$is_demo              = MainWP_Demo_Handle::is_demo_mode();
		?>
		<table class="ui tablet stackable table mainwp-manage-updates-table main-master-checkbox" id="mainwp-themes-updates-groups-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting">
						<div class="ui main-master checkbox ">
							<input type="checkbox" name=""><label><?php esc_html_e( 'Tag', 'mainwp' ); ?></label>
						</div>
						<?php MainWP_UI::render_sorting_icons(); ?>
					</th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="no-sort right aligned">
						<?php MainWP_UI::render_show_all_updates_button(); ?>
						<?php
						if ( MainWP_Updates::user_can_update_themes() ) {
							MainWP_Updates::set_continue_update_html_selector( 'themes_global_upgrade_all' );
							if ( 0 < $total_theme_upgrades ) {
								if ( $is_demo ) {
									MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui mini basic green button disabled mainwp-update-selected-button" disabled="disabled">' . esc_html__( 'Update Selected Themes', 'mainwp' ) . '</a>' );
									MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui mini green button disabled mainwp-update-all-button" disabled="disabled">' . esc_html__( 'Update All Themes', 'mainwp' ) . '</a>' );
								} else {
									?>
									<a href="javascript:void(0)" onClick="return updatesoverview_themes_global_upgrade_all( false, true );" class="mainwp-update-selected-button ui mini basic green button" data-tooltip="<?php esc_html_e( 'Update selected sites.', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php esc_html_e( 'Update Selected Themes' ); ?></a>
									<a href="javascript:void(0)" onClick="return updatesoverview_themes_global_upgrade_all();" class="mainwp-update-all-button ui mini green button" data-tooltip="<?php esc_html_e( 'Update all sites.', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php esc_html_e( 'Update All Themes' ); ?></a>
									<?php
								}
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
					<tr class="title main-master-checkbox" row-uid="uid_theme_updates_<?php echo esc_attr( $group_id ); ?>">
						<td class="accordion-trigger"><i class="icon dropdown"></i></td>
						<td>
						<div class="ui main-master checkbox ">
								<input type="checkbox" name=""><label><?php echo esc_html( stripslashes( $group_name ) ); ?></label>
						</div>
						</td>
						<td total-uid="uid_theme_updates_<?php echo esc_attr( $group_id ); ?>" sort-value="0"></td>
						<td>
						<?php
						if ( MainWP_Updates::user_can_update_themes() ) {
							if ( $is_demo ) {
								MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green basic mini button disabled mainwp-update-selected-button" disabled="disabled">' . esc_html__( 'Update Selected', 'mainwp' ) . '</a>' );
								MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green mini button disabled mainwp-update-all-button" disabled="disabled">' . esc_html__( 'Update All', 'mainwp' ) . '</a>' );
							} else {
								?>
								<a href="javascript:void(0)" btn-all-uid2="uid_theme_updates_<?php echo esc_attr( $group_id ); ?>" class="mainwp-update-selected-button ui green basic mini button" onClick="return updatesoverview_themes_global_upgrade_all( <?php echo esc_attr( $group_id ); ?>, true )"><?php esc_html_e( 'Update Selected', 'mainwp' ); ?></a>
								<a href="javascript:void(0)" btn-all-uid="uid_theme_updates_<?php echo esc_attr( $group_id ); ?>" class="mainwp-update-all-button ui green mini button" onClick="return updatesoverview_themes_global_upgrade_all( <?php echo esc_attr( $group_id ); ?> )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
							<?php } ?>
						<?php } ?>
						</td>
					</tr>
					<tr class="title main-child-checkbox content" row-uid="uid_theme_updates_<?php echo esc_attr( $group_id ); ?>">
						<td colspan="4">
							<table id="mainwp-wordpress-updates-sites-inner-table" class="ui grey table mainwp-per-group-table mainwp-manage-updates-table">
								<thead class="mainwp-768-hide">
									<tr>
										<th class="collapsing no-sort"></th>
										<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo esc_html__( 'Client', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										<th class="collapsing no-sort"></th>
									</tr>
								</thead>
								<tbody class="accordion" id="update_wrapper_theme_upgrades_group_<?php echo esc_attr( $group_id ); ?>">
									<?php	foreach ( $site_ids as $site_id ) : ?>
										<?php
										if ( ! isset( $site_offset_for_groups[ $site_id ] ) ) {
											continue;
										}
										$seek = $site_offset_for_groups[ $site_id ];
										MainWP_DB::data_seek( $websites, $seek );

										$website = MainWP_DB::fetch_object( $websites );
										if ( $website->is_ignoreThemeUpdates ) {
											continue;
										}

										$theme_upgrades         = json_decode( $website->theme_upgrades, true );
										$decodedPremiumUpgrades = MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' );
										$decodedPremiumUpgrades = ! empty( $decodedPremiumUpgrades ) ? json_decode( $decodedPremiumUpgrades, true ) : array();

										if ( is_array( $decodedPremiumUpgrades ) ) {
											foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
												$premiumUpgrade['premium'] = true;

												if ( 'theme' === $premiumUpgrade['type'] ) {
													if ( ! is_array( $theme_upgrades ) ) {
														$theme_upgrades = array();
													}
													$premiumUpgrade = array_filter( $premiumUpgrade );
													if ( isset( $theme_upgrades[ $crrSlug ] ) && is_array( $theme_upgrades[ $crrSlug ] ) ) {
														$theme_upgrades[ $crrSlug ] = array_merge( $theme_upgrades[ $crrSlug ], $premiumUpgrade );
													} else {
														$theme_upgrades[ $crrSlug ] = $premiumUpgrade;
													}
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

										if ( ( 0 === count( $theme_upgrades ) ) && ( empty( $website->sync_errors ) ) ) {
											continue;
										}
										?>
										<tr class="ui title master-checkbox">
											<td class="accordion-trigger"><i class="icon dropdown"></i></td>
											<td>											
												<div class="ui master checkbox">
													<input type="checkbox" name=""><label><?php MainWP_Updates::render_site_link_dashboard( $website ); ?></label>
												</div>
											</td>
											<td sort-value="<?php echo count( $theme_upgrades ); ?>"><strong class="mainwp-768-show"><?php esc_html_e( 'Updates:', 'mainwp' ); ?></strong> <?php echo count( $theme_upgrades ) . ' ' . esc_html( _n( 'Update', 'Updates', count( $theme_upgrades ), 'mainwp' ) ); ?></td>
											<td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
											<td class="right aligned">
												<?php if ( MainWP_Updates::user_can_update_themes() ) : ?>
													<?php if ( 0 < count( $theme_upgrades ) ) : ?>
														<?php if ( $is_demo ) : ?>
															<?php MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green mini basic button disabled mainwp-update-selected-button" disabled="disabled">' . esc_html__( 'Update Selected', 'mainwp' ) . '</a>' ); ?>
															<?php MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green mini button disabled mainwp-update-all-button" disabled="disabled">' . esc_html__( 'Update All', 'mainwp' ) . '</a>' ); ?>
														<?php else : ?>
														<a href="javascript:void(0)" class="mainwp-update-selected-button ui green basic mini button" onClick="event.stopPropagation(); return updatesoverview_group_upgrade_theme_all( <?php echo esc_attr( $website->id ); ?>, <?php echo esc_attr( $group_id ); ?>, true )"><?php esc_html_e( 'Update Selected', 'mainwp' ); ?></a>
														<a href="javascript:void(0)" class="mainwp-update-all-button ui green mini button" onClick="return updatesoverview_group_upgrade_theme_all( <?php echo esc_attr( $website->id ); ?>, <?php echo esc_attr( $group_id ); ?> )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
														<?php endif; ?>
													<?php endif; ?>
												<?php endif; ?>
											</td>
										</tr>
										<tr class="child-checkbox content">
											<td colspan="5">
												<table id="mainwp-wordpress-updates-themes-inner-table" class="ui table mainwp-manage-updates-table mainwp-updates-list">
													<thead class="mainwp-768-hide">
														<tr>
															<?php $updates_table_helper->print_column_headers(); ?>
														</tr>
													</thead>
													<tbody class="themes-bulk-updates" id="wp_theme_upgrades_<?php echo esc_attr( $website->id ); ?>_group_<?php echo esc_attr( $group_id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>">
													<?php foreach ( $theme_upgrades as $slug => $theme_upgrade ) : ?>
														<?php $theme_name = rawurlencode( $slug ); ?>
														<?php $indent_hidden = '<input type="hidden" id="wp_upgraded_theme_' . esc_attr( $website->id ) . '_group_' . esc_attr( $group_id ) . '_' . $theme_name . '" value="0"/>'; ?>
														<?php
															$row_columns = array(
																'title'   => MainWP_System_Utility::get_theme_icon( $slug ) . '&nbsp;&nbsp;&nbsp;&nbsp;' . esc_html( $theme_upgrade['Name'] ) . $indent_hidden,
																'version' => '<strong class="mainwp-768-show">' . esc_html__( 'Version: ', 'mainwp' ) . '</strong>' . esc_html( $theme_upgrade['Version'] ),
																'latest'  => '<strong class="mainwp-768-show">' . esc_html__( 'Latest: ', 'mainwp' ) . '</strong>' . esc_html( $theme_upgrade['update']['new_version'] ),
																'trusted' => ( in_array( $slug, $trustedThemes, true ) ? true : false ),
																'status'  => ( isset( $theme_upgrade['active'] ) && $theme_upgrade['active'] ) ? true : false,
															);
															?>
														<tr class="mainwp-theme-update" theme_slug="<?php echo esc_attr( $theme_name ); ?>" premium="<?php echo ( isset( $theme_upgrade['premium'] ) && ! empty( $theme_upgrade['premium'] ) ? 1 : 0 ); ?>" updated="0">
															<?php
															$row_columns     = $updates_table_helper->render_columns( $row_columns, $website );
															$action_rendered = isset( $row_columns['action'] ) ? true : false;
															if ( ! $action_rendered ) :
																?>
															<td class="right aligned">
																<?php if ( MainWP_Updates::user_can_ignore_updates() ) : ?>
																<a href="javascript:void(0)" class="mainwp-ignore-update-button ui mini button" onClick="return updatesoverview_themes_ignore_detail( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $theme_upgrade['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Update', 'mainwp' ); ?></a>
															<?php endif; ?>
																<?php
																if ( MainWP_Updates::user_can_update_themes() ) :
																	if ( $is_demo ) {
																		MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui mini green button disabled" disabled="disabled">' . esc_html__( 'Update Now', 'mainwp' ) . '</a>' );
																	} else {
																		?>
																		<a href="javascript:void(0)" class="mainwp-update-now-button ui mini green button" onClick="return updatesoverview_themes_upgrade( '<?php echo esc_js( $theme_name ); ?>', <?php echo intval( $website->id ); ?> )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
																<?php } ?>
															<?php endif; ?>
															</td>
															<?php endif; ?>
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
										<th><?php esc_html_e( 'Client', 'mainwp' ); ?></th>
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
					<th>
						<div class="ui main-master checkbox ">
							<input type="checkbox" name=""><label><?php esc_html_e( 'Tag', 'mainwp' ); ?></label>
						</div>

					</th>
					<th><?php echo intval( $total_theme_upgrades ) . ' ' . esc_html( _n( 'Update', 'Updates', $total_theme_upgrades, 'mainwp' ) ); ?></th>
					<th class="no-sort"></th>
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
	 * @param int    $total_translation_upgrades total translation updates.
	 * @param array  $all_groups_sites all groups of sites.
	 * @param array  $all_groups all groups.
	 * @param int    $site_offset_for_groups offset value.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_show_all_updates_button()
	 * @uses \MainWP\Dashboard\MainWP_Updates::user_can_update_trans()
	 * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
	 */
	public static function render_trans_update( $websites, $total_translation_upgrades, $all_groups_sites, $all_groups, $site_offset_for_groups ) { //phpcs:ignore -- complex method.
		$is_demo = MainWP_Demo_Handle::is_demo_mode();
		?>
		<table class="ui tablet stackable table mainwp-manage-updates-table main-master-checkbox" id="mainwp-translations-groups-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting">
						<div class="ui main-master checkbox">
							<input type="checkbox" name=""><label><?php esc_html_e( 'Tag', 'mainwp' ); ?></label>
						</div>
						<?php MainWP_UI::render_sorting_icons(); ?>
					</th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th>
						<?php MainWP_UI::render_show_all_updates_button(); ?>
						<?php if ( MainWP_Updates::user_can_update_trans() ) : ?>
							<?php if ( 0 < $total_translation_upgrades ) : ?>
								<a href="javascript:void(0)" onClick="return updatesoverview_translations_global_upgrade_all( false, true );" class="mainwp-update-selected-button ui button mini basic green" data-tooltip="<?php esc_html_e( 'Update all translations', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php esc_html_e( 'Update Selected Sites', 'mainwp' ); ?></a>
								<a href="javascript:void(0)" onClick="return updatesoverview_translations_global_upgrade_all();" class="mainwp-update-all-button ui button mini green" data-tooltip="<?php esc_html_e( 'Update all translations', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php esc_html_e( 'Update All Sites', 'mainwp' ); ?></a>
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
					<tr row-uid="uid_translation_updates_<?php echo esc_attr( $group_id ); ?>" class="title main-master-checkbox">
						<td class="accordion-trigger"><i class="dropdown icon"></i></td>
						<td>
							<div class="ui main-master checkbox">
								<input type="checkbox" name="">
							</div>
							<?php echo esc_html( stripslashes( $group_name ) ); ?>
						</td>
						<td total-uid="uid_translation_updates_<?php echo esc_attr( $group_id ); ?>" sort-value="0"></td>
						<td class="right aligned">
						<?php if ( MainWP_Updates::user_can_update_trans() ) { ?>
							<a href="javascript:void(0)" btn-all-uid2="uid_translation_updates_<?php echo esc_attr( $group_id ); ?>" class="mainwp-update-selected-button ui green basic mini button"  onClick="event.stopPropagation(); return updatesoverview_translations_global_upgrade_all( <?php echo esc_attr( $group_id ); ?>, true )"><?php esc_html_e( 'Update Selected', 'mainwp' ); ?></a>
							<a href="javascript:void(0)" btn-all-uid="uid_translation_updates_<?php echo esc_attr( $group_id ); ?>" class="mainwp-update-all-button ui green mini button"  onClick="return updatesoverview_translations_global_upgrade_all( <?php echo esc_attr( $group_id ); ?> )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>

						<?php } ?>
						</td>
					</tr>
					<tr class="content" class="main-child-checkbox">
						<td colspan="4">
							<table class="ui grey table mainwp-per-group-table mainwp-manage-updates-table" id="mainwp-translations-sites-table">
								<thead class="mainwp-768-hide">
									<tr>
										<th class="collapsing no-sort"></th>
										<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo esc_html__( 'Client', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										<th></th>
									</tr>
								</thead>
								<tbody class="accordion" id="update_wrapper_translation_upgrades_group_<?php echo esc_attr( $group_id ); ?>" row-uid="uid_translation_updates_<?php echo esc_attr( $group_id ); ?>">
								<?php foreach ( $site_ids as $site_id ) : ?>
									<?php
									if ( ! isset( $site_offset_for_groups[ $site_id ] ) ) {
										continue;
									}
									$seek = $site_offset_for_groups[ $site_id ];
									MainWP_DB::data_seek( $websites, $seek );
									$website                          = MainWP_DB::fetch_object( $websites );
									$translation_upgrades             = json_decode( $website->translation_upgrades, true );
									$total_group_translation_updates += count( $translation_upgrades );

									if ( ( 0 === count( $translation_upgrades ) ) && ( empty( $website->sync_errors ) ) ) {
										continue;
									}
									?>
									<tr class="ui title master-checkbox">
										<td class="accordion-trigger"><i class="dropdown icon"></i></td>
										<td>
												<div class="ui master checkbox">
												<input type="checkbox" name=""><label><?php MainWP_Updates::render_site_link_dashboard( $website ); ?></label>
												</div>
										</td>
										<td sort-value="<?php echo count( $translation_upgrades ); ?>">
											<strong class="mainwp-768-show"><?php esc_html_e( 'Updates:', 'mainwp' ); ?></strong> <?php echo esc_html( _n( 'Update', 'Updates', count( $translation_upgrades ), 'mainwp' ) ); ?>
										</td>
										<td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
										<td>
										<?php if ( MainWP_Updates::user_can_update_trans() ) : ?>
											<?php
											if ( 0 < count( $translation_upgrades ) ) :
												if ( $is_demo ) {
													MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green basic mini button disabled mainwp-update-selected-button" disabled="disabled">' . esc_html__( 'Update Selected', 'mainwp' ) . '</a>' );
													MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green mini button disabled mainwp-update-all-button" disabled="disabled">' . esc_html__( 'Update All', 'mainwp' ) . '</a>' );
												} else {
													?>
													<a href="javascript:void(0)" class="mainwp-update-selected-button ui green basic mini button" onClick="event.stopPropagation(); return updatesoverview_group_upgrade_translation_all( <?php echo esc_attr( $website->id ); ?>, <?php echo esc_attr( $group_id ); ?>, true )"><?php esc_html_e( 'Update Selected', 'mainwp' ); ?></a>
													<a href="javascript:void(0)" class="mainwp-update-all-button ui green mini button" onClick="return updatesoverview_group_upgrade_translation_all( <?php echo esc_attr( $website->id ); ?>, <?php echo esc_attr( $group_id ); ?> )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
											<?php } ?>
											<?php endif; ?>
										<?php endif; ?>
										</td>
									</tr>
									<tr class="child-checkbox content">
										<td colspan="5">
											<table class="ui table mainwp-manage-updates-table" id="mainwp-translations-table">
												<thead class="mainwp-768-hide">
													<tr>
														<th><?php esc_html_e( 'translationName', 'mainwp' ); ?></th>
														<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
														<th></th>
													</tr>
												</thead>
												<tbody id="wp_translation_upgrades_<?php echo esc_attr( $website->id ); ?>_group_<?php echo esc_attr( $group_id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>">
												<?php foreach ( $translation_upgrades as $translation_upgrade ) : ?>
													<?php
													$translation_name = isset( $translation_upgrade['name'] ) ? $translation_upgrade['name'] : $translation_upgrade['slug'];
													$translation_slug = $translation_upgrade['slug'];
													?>
													<tr class="mainwp-translation-update" translation_slug="<?php echo esc_attr( $translation_slug ); ?>" updated="0">
														<td>
														<div class="ui child checkbox">
																<input type="checkbox" name="">
															</div>
															<?php echo esc_html( $translation_name ); ?>
															<input type="hidden" id="wp_upgraded_translation_<?php echo esc_attr( $website->id ); ?>_group_<?php echo esc_attr( $group_id ); ?>_<?php echo esc_attr( $translation_slug ); ?>" value="0"/>
														</td>
														<td>
															<strong class="mainwp-768-show"><?php esc_html_e( 'Varsion:', 'mainwp' ); ?></strong> <?php echo esc_html( $translation_upgrade['version'] ); ?>
														</td>
														<td>
														<?php
														if ( MainWP_Updates::user_can_update_trans() ) :
															if ( $is_demo ) {
																MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green mini button disabled" disabled="disabled">' . esc_html__( 'Update Now', 'mainwp' ) . '</a>' );
															} else {
																?>
																<a href="javascript:void(0)" class="mainwp-update-now-button ui green mini button" onClick="return updatesoverview_group_upgrade_translation( <?php echo esc_attr( $website->id ); ?>, '<?php echo esc_js( $translation_slug ); ?>', <?php echo esc_attr( $group_id ); ?> )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
															<?php } ?>
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
					<th>
						<div class="ui main-master checkbox">
							<input type="checkbox" name=""><label><?php esc_html_e( 'Tag', 'mainwp' ); ?></label>
						</div>
					</th>
					<th><?php esc_html_e( 'Updates', 'mainwp' ); ?></th>
					<th></th>
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
	 * @param array  $all_groups_sites        all groups of sites.
	 * @param array  $all_groups              all groups.
	 * @param int    $site_offset_for_groups  offset value.
	 * @param array  $decodedDismissedPlugins all dismissed plugins.
	 *
	 * @throws \Exception Error message.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
	 * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
	 * @uses \MainWP\Dashboard\MainWP_Updates::user_can_ignore_updates()
	 */
	public static function render_abandoned_plugins( $websites, $all_groups_sites, $all_groups, $site_offset_for_groups, $decodedDismissedPlugins ) {
		$str_format = esc_html__( 'Updated %s days ago', 'mainwp' );
		?>
		<table class="ui tablet stackable table mainwp-manage-updates-table" id="mainwp-abandoned-plugins-groups-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Tag', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
				</tr>
			</thead>
			<tbody class="ui accordion">
			<?php foreach ( $all_groups_sites as $group_id => $site_ids ) : ?>
				<?php
				$total_group_plugins_outdate = 0;
				$group_name                  = $all_groups[ $group_id ];
				?>
				<tr class="title" row-uid="uid_plugins_outdate_<?php echo esc_attr( $group_id ); ?>">
					<td class="accordion-trigger"><i class="dropdown icon"></i></td>
					<td><?php echo esc_html( stripslashes( $group_name ) ); ?></td>
					<td total-uid="uid_plugins_outdate_<?php echo esc_attr( $group_id ); ?>" sort-value="0"></td>
				</tr>
				<tr class="content" row-uid="uid_plugins_outdate_<?php echo esc_attr( $group_id ); ?>">
					<td colspan="3">
						<table class="ui grey table mainwp-per-group-table mainwp-manage-updates-table" id="mainwp-abandoned-plugins-sites-table">
							<thead class="mainwp-768-hide">
								<tr>
								<th class="collapsing no-sort"></th>
								<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
								<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo esc_html__( 'Client', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
								<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
								</tr>
							</thead>
							<tbody class="accordion">
							<?php foreach ( $site_ids as $site_id ) : ?>
								<?php
								if ( ! isset( $site_offset_for_groups[ $site_id ] ) ) {
									continue;
								}
								$seek = $site_offset_for_groups[ $site_id ];
								MainWP_DB::data_seek( $websites, $seek );

								$website = MainWP_DB::fetch_object( $websites );

								$plugins_outdate = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' );
								$plugins_outdate = ! empty( $plugins_outdate ) ? json_decode( $plugins_outdate, true ) : array();

								if ( ! is_array( $plugins_outdate ) ) {
									$plugins_outdate = array();
								}

								if ( 0 < count( $plugins_outdate ) ) {
									$pluginsOutdateDismissed = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_dismissed' );
									$pluginsOutdateDismissed = ! empty( $pluginsOutdateDismissed ) ? json_decode( $pluginsOutdateDismissed, true ) : array();

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
								<tr class="ui title master-checkbox">
									<td class="accordion-trigger"><i class="dropdown icon"></i></td>
									<td>										
										<strong class="mainwp-768-show"><?php esc_html_e( 'Website:', 'mainwp' ); ?></strong> <?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
									</td>
									<td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
									<td sort-value="<?php echo count( $plugins_outdate ); ?>">
										<strong class="mainwp-768-show"><?php esc_html_e( 'Abandoned:', 'mainwp' ); ?></strong> <?php echo count( $plugins_outdate ); ?> <?php echo esc_html( _n( 'Plugin', 'Plugins', count( $plugins_outdate ), 'mainwp' ) ); ?>
									</td>
								</tr>
								<tr class="child-checkbox content">
									<td colspan="4">
										<table class="ui mainwp-manage-updates-table table" id="mainwp-abandoned-plugins-table">
											<thead class="mainwp-768-hide">
												<tr>
													<tr>
														<th></th>
														<th><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
														<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
														<th><?php esc_html_e( 'Last Update', 'mainwp' ); ?></th>
														<th class="no-sort"></th>
													</tr>
												</tr>
											</thead>
											<tbody site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>">
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
														<td class="collapsing"><?php echo MainWP_System_Utility::get_plugin_icon( dirname( $slug ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
														<td>
															<a href="<?php echo esc_url( admin_url() ) . 'plugin-install.php?tab=plugin-information&wpplugin=' . intval( $website->id ) . '&plugin=' . esc_html( dirname( $slug ) ) . '&url=' . ( isset( $plugin_outdate['PluginURI'] ) ? esc_html( rawurlencode( $plugin_outdate['PluginURI'] ) ) : '' ) . '&name=' . esc_html( rawurlencode( $plugin_outdate['Name'] ) ); ?>" target="_blank" class="open-plugin-details-modal"><?php echo esc_html( $plugin_outdate['Name'] ); ?></a>
															<input type="hidden" id="wp_dismissed_plugin_<?php echo esc_attr( $website->id ); ?>_<?php echo esc_attr( $plugin_name ); ?>" value="0"/>
														</td>
														<td><strong class="mainwp-768-show"><?php esc_html_e( 'Version:', 'mainwp' ); ?></strong> <?php echo esc_html( $plugin_outdate['Version'] ); ?></td>
														<td><strong class="mainwp-768-show"><?php esc_html_e( 'Last Update:', 'mainwp' ); ?></strong> <?php echo esc_html( $outdate_notice ); ?></td>
														<td id="wp_dismissbuttons_plugin_<?php echo esc_attr( $website->id ); ?>_<?php echo esc_attr( $plugin_name ); ?>">
														<?php if ( MainWP_Updates::user_can_ignore_updates() ) { ?>
															<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_plugins_dismiss_outdate_detail( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_js( rawurlencode( $plugin_outdate['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Now', 'mainwp' ); ?></a>
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
					<th><?php esc_html_e( 'Tag', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Abandoned', 'mainwp' ); ?></th>
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
	 * @param object $websites               the websites.
	 * @param array  $all_groups_sites       all groups of sites.
	 * @param array  $all_groups             all groups.
	 * @param int    $site_offset_for_groups offset value.
	 * @param array  $decodedDismissedThemes all dismissed themes.
	 *
	 * @throws \Exception Error message.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
	 * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
	 * @uses \MainWP\Dashboard\MainWP_Updates::user_can_ignore_updates()
	 */
	public static function render_abandoned_themes( $websites, $all_groups_sites, $all_groups, $site_offset_for_groups, $decodedDismissedThemes ) {
		$str_format = esc_html__( 'Updated %s days ago', 'mainwp' );
		?>
		<table class="ui tablet stackable table mainwp-manage-updates-table" id="mainwp-abandoned-themes-groups-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Tag', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
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
					<td><?php echo esc_html( stripslashes( $group_name ) ); ?></td>
					<td total-uid="uid_themes_outdate_<?php echo esc_attr( $group_id ); ?>" sort-value="0"></td>
				</tr>
				<tr class="content" row-uid="uid_themes_outdate_<?php echo esc_attr( $group_id ); ?>">
					<td colspan="3">
						<table class="ui grey table manage-updates-item-table mainwp-manage-updates-table mainwp-per-group-table" id="mainwp-abandoned-themes-sites-table">
							<thead class="mainwp-768-hide">
								<tr>
									<th class="collapsing no-sort"></th>
									<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
									<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo esc_html__( 'Client', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
									<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
								</tr>
							</thead>
							<tbody class="accordion">
							<?php foreach ( $site_ids as $site_id ) : ?>
								<?php
								if ( ! isset( $site_offset_for_groups[ $site_id ] ) ) {
									continue;
								}
								$seek = $site_offset_for_groups[ $site_id ];
								MainWP_DB::data_seek( $websites, $seek );

								$website = MainWP_DB::fetch_object( $websites );

								$themes_outdate = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' );
								$themes_outdate = ! empty( $themes_outdate ) ? json_decode( $themes_outdate, true ) : array();

								if ( ! is_array( $themes_outdate ) ) {
									$themes_outdate = array();
								}

								if ( 0 < count( $themes_outdate ) ) {
									$themesOutdateDismissed = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' );
									$themesOutdateDismissed = ! empty( $themesOutdateDismissed ) ? json_decode( $themesOutdateDismissed, true ) : array();

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
										<strong class="mainwp-768-show"><?php esc_html_e( 'Website:', 'mainwp' ); ?></strong> <?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
									</td>
									<td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
									<td sort-value="<?php echo count( $themes_outdate ); ?>">
										<strong class="mainwp-768-show"><?php esc_html_e( 'Abandoned:', 'mainwp' ); ?></strong> <?php echo count( $themes_outdate ); ?> <?php echo esc_html( _n( 'Theme', 'Themes', count( $themes_outdate ), 'mainwp' ) ); ?>
									</td>
								</tr>
								<tr class="content">
									<td colspan="4">
										<table class="ui mainwp-manage-updates-item-table mainwp-manage-updates-table table" id="mainwp-abandoned-themes-table">
											<thead class="mainwp-768-hide">
												<tr>
													<tr>
														<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
														<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
														<th><?php esc_html_e( 'Last Update', 'mainwp' ); ?></th>
														<th class="no-sort"></th>
													</tr>
												</tr>
											</thead>
											<tbody site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>">
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
															<?php echo MainWP_System_Utility::get_theme_icon( $slug ) . '&nbsp;&nbsp;&nbsp;&nbsp;' . esc_html( $theme_outdate['Name'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
															<input type="hidden" id="wp_dismissed_theme_<?php echo esc_attr( $website->id ); ?>_<?php echo esc_attr( $theme_name ); ?>" value="0"/>
														</td>
														<td><strong class="mainwp-768-show"><?php esc_html_e( 'Version:', 'mainwp' ); ?></strong> <?php echo esc_html( $theme_outdate['Version'] ); ?></td>
														<td><strong class="mainwp-768-show"><?php esc_html_e( 'Last Update:', 'mainwp' ); ?></strong> <?php echo esc_html( $outdate_notice ); ?></td>
														<td id="wp_dismissbuttons_theme_<?php echo esc_attr( $website->id ); ?>_<?php echo esc_attr( $theme_name ); ?>">
															<?php if ( MainWP_Updates::user_can_ignore_updates() ) { ?>
															<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_themes_dismiss_outdate_detail( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $theme_outdate['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Now', 'mainwp' ); ?></a>
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
					<th><?php esc_html_e( 'Tag', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Abandoned', 'mainwp' ); ?></th>
				</tr>
			</tfoot>
		</table>
		<?php
	}
}
