<?php
/**
 * MainWP Updates Per Item.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Updates Per Item.
 */
class MainWP_Updates_Per_Item {

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
	 * Method render_plugins_updates()
	 *
	 * Render Plugins updates
	 *
	 * @param object $websites the websites.
	 * @param int    $total_plugin_upgrades total plugin updates.
	 * @param mixed  $userExtension user extension.
	 * @param array  $allPlugins all plugins.
	 * @param array  $pluginsInfo pugins information.
	 * @param array  $trustedPlugins trusted plugins.
	 */
	public static function render_plugins_updates( $websites, $total_plugin_upgrades, $userExtension, $allPlugins, $pluginsInfo, $trustedPlugins ) { // phpcs:ignore -- not quite complex method.
		?>
		<table class="ui stackable single line table" id="mainwp-plugins-updates-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Plugin', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo $total_plugin_upgrades . ' ' . _n( 'Update', 'Updates', $total_plugin_upgrades, 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Trusted', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
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
				<?php foreach ( $allPlugins as $slug => $val ) : ?>
					<?php
					$cnt         = intval( $val['cnt'] );
					$plugin_name = rawurlencode( $slug );
					$trusted     = in_array( $slug, $trustedPlugins ) ? 1 : 0;
					?>
					<tr class="ui title">
						<td class="accordion-trigger"><i class="icon dropdown"></i></td>
						<td>
							<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . esc_attr( $pluginsInfo[ $slug ]['slug'] ) . '&url=' . ( isset( $pluginsInfo[ $slug ]['PluginURI'] ) ? rawurlencode( $pluginsInfo[ $slug ]['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $pluginsInfo[ $slug ]['name'] ) . '&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal">
								<?php echo esc_html( $pluginsInfo[ $slug ]['name'] ); ?>
							</a>
						</td>
						<td sort-value="<?php echo $cnt; ?>"><?php echo $cnt; ?> <?php echo _n( 'Update', 'Updates', $cnt, 'mainwp' ); ?></td>
						<td sort-value="<?php echo $trusted; ?>"><?php echo ( $trusted ? MainWP_Updates::$trusted_label : MainWP_Updates::$not_trusted_label ); ?></td>
						<td class="right aligned">
							<?php if ( MainWP_Updates::user_can_ignore_updates() ) : ?>
								<a href="javascript:void(0)" class="ui mini button btn-update-click-accordion" onClick="return updatesoverview_plugins_ignore_all( '<?php echo $plugin_name; ?>', '<?php echo rawurlencode( $pluginsInfo[ $slug ]['name'] ); ?>', this )"><?php esc_html_e( 'Ignore Globally', 'mainwp' ); ?></a>
							<?php endif; ?>
							<?php if ( MainWP_Updates::user_can_update_plugins() ) : ?>
								<?php if ( 0 < $cnt ) : ?>
									<?php
									if ( MAINWP_VIEW_PER_PLUGIN_THEME == $userExtension->site_view ) {
										MainWP_Updates::set_continue_update_html_selector( 'plugins_upgrade_all', $slug );
									}
									?>
									<a href="javascript:void(0)" class="ui mini button green <?php echo MainWP_Updates::get_continue_update_selector(); ?>" onClick="return updatesoverview_plugins_upgrade_all( '<?php echo $plugin_name; ?>', '<?php echo rawurlencode( $pluginsInfo[ $slug ]['name'] ); ?>' )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>

					<tr style="display:none" class="plugins-bulk-updates" plugin_slug="<?php echo $plugin_name; ?>" plugin_name="<?php echo rawurlencode( $pluginsInfo[ $slug ]['name'] ); ?>" premium="<?php echo $pluginsInfo[ $slug ]['premium'] ? 1 : 0; ?>">
						<td colspan="5" class="ui content">
							<table id="mainwp-plugins-updates-sites-inner-table" class="ui stackable single line table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
										<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
										<th class="no-sort"><?php esc_html_e( 'Latest', 'mainwp' ); ?></th>
										<th><?php esc_html_e( 'Trusted', 'mainwp' ); ?></th>
										<th class="no-sort"></th>
									</tr>
								</thead>
								<tbody plugin_slug="<?php echo $plugin_name; ?>">
									<?php
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
													$premiumUpgrade              = array_filter( $premiumUpgrade );
													$plugin_upgrades[ $crrSlug ] = array_merge( $plugin_upgrades[ $crrSlug ], $premiumUpgrade );
												}
											}
										}

										$ignored_plugins = json_decode( $website->ignored_plugins, true );
										if ( is_array( $ignored_plugins ) ) {
											$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
										}

										if ( ! isset( $plugin_upgrades[ $slug ] ) ) {
											continue;
										}
										$plugin_upgrade = $plugin_upgrades[ $slug ];
										?>
										<tr site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>" updated="0">
											<td>
											<?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
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
										<?php
									}
									?>
								</tbody>
							</table>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th class="collapsing no-sort"></th>
					<th><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
					<th><?php echo $total_plugin_upgrades . ' ' . _n( 'Update', 'Updates', $total_plugin_upgrades, 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Trusted', 'mainwp' ); ?></th>
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
	 * @param object $websites the websites.
	 * @param int    $total_theme_upgrades total themes updates.
	 * @param mixed  $userExtension user extension.
	 * @param array  $allThemes all themes.
	 * @param array  $themesInfo themes information.
	 * @param array  $trustedThemes trusted themes.
	 */
	public static function render_themes_updates( $websites, $total_theme_upgrades, $userExtension, $allThemes, $themesInfo, $trustedThemes ) { // phpcs:ignore -- not quite complex method.
		?>
		<table class="ui stackable single line table" id="mainwp-themes-updates-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="handle-accordion-sorting indicator-accordion-sorting"><?php esc_html_e( 'Theme', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="handle-accordion-sorting indicator-accordion-sorting"><?php echo $total_theme_upgrades . ' ' . _n( 'Update', 'Updates', $total_theme_upgrades, 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="handle-accordion-sorting indicator-accordion-sorting"><?php esc_html_e( 'Trusted', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
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
				<?php foreach ( $allThemes as $slug => $val ) : ?>
					<?php
					$cnt        = intval( $val['cnt'] );
					$theme_name = rawurlencode( $slug );
					$trusted    = in_array( $slug, $trustedThemes ) ? 1 : 0;
					?>
					<tr class="ui title">
						<td class="accordion-trigger"><i class="icon dropdown"></i></td>
						<td><?php echo esc_html( $themesInfo[ $slug ]['name'] ); ?></td>
						<td sort-value="<?php echo $cnt; ?>"><?php echo $cnt; ?> <?php echo _n( 'Update', 'Updates', $cnt, 'mainwp' ); ?></td>
						<td sort-value="<?php echo $trusted; ?>"><?php echo ( $trusted ? MainWP_Updates::$trusted_label : MainWP_Updates::$not_trusted_label ); ?></td>
						<td class="right aligned">
							<?php if ( MainWP_Updates::user_can_ignore_updates() ) : ?>
								<a href="javascript:void(0)" class="ui mini button btn-update-click-accordion" onClick="return updatesoverview_themes_ignore_all( '<?php echo $theme_name; ?>', '<?php echo rawurlencode( $themesInfo[ $slug ]['name'] ); ?>', this )"><?php esc_html_e( 'Ignore Globally', 'mainwp' ); ?></a>
							<?php endif; ?>
							<?php if ( MainWP_Updates::user_can_update_themes() ) : ?>
								<?php
								if ( 0 < $cnt ) :
									if ( MAINWP_VIEW_PER_PLUGIN_THEME == $userExtension->site_view ) {
										MainWP_Updates::set_continue_update_html_selector( 'themes_upgrade_all', $slug );
									}
									?>
									<a href="javascript:void(0)" class="ui mini button green <?php echo MainWP_Updates::get_continue_update_selector(); ?>" onClick="return updatesoverview_themes_upgrade_all( '<?php echo $theme_name; ?>', '<?php echo rawurlencode( $themesInfo[ $slug ]['name'] ); ?>' )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr style="display:none" class="themes-bulk-updates" theme_slug="<?php echo $theme_name; ?>" theme_name="<?php echo rawurlencode( $themesInfo[ $slug ]['name'] ); ?>" premium="<?php echo $themesInfo[ $slug ]['premium'] ? 1 : 0; ?>">
						<td colspan="5" class="ui content">
							<table id="mainwp-themes-updates-sites-inner-table" class="ui stackable single line table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
										<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
										<th class="no-sort"><?php esc_html_e( 'Latest', 'mainwp' ); ?></th>
										<th class="no-sort"></th>
									</tr>
								</thead>
								<tbody theme_slug="<?php echo $theme_name; ?>">
									<?php
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
													$premiumUpgrade             = array_filter( $premiumUpgrade );
													$theme_upgrades[ $crrSlug ] = array_merge( $theme_upgrades[ $crrSlug ], $premiumUpgrade );
												}
											}
										}
										$ignored_themes = json_decode( $website->ignored_themes, true );
										if ( is_array( $ignored_themes ) ) {
											$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
										}

										if ( ! isset( $theme_upgrades[ $slug ] ) ) {
											continue;
										}
										$theme_upgrade = $theme_upgrades[ $slug ];
										?>
										<tr site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>" updated="0">
											<td>
											<?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
											</td>
											<td><?php echo esc_html( $theme_upgrade['Version'] ); ?></td>
											<td><?php echo esc_html( $theme_upgrade['update']['new_version'] ); ?></td>
											<td class="right aligned">
											<?php if ( MainWP_Updates::user_can_ignore_updates() ) : ?>
												<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_themes_ignore_detail( '<?php echo $theme_name; ?>', '<?php echo rawurlencode( $theme_upgrade['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Update', 'mainwp' ); ?></a>
											<?php endif; ?>
											<?php if ( MainWP_Updates::user_can_update_themes() ) : ?>
												<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_themes_upgrade( '<?php echo $theme_name; ?>', <?php echo esc_attr( $website->id ); ?> )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
											<?php endif; ?>
											</td>
										</tr>
										<?php
									}
									?>
								</tbody>
							</table>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th class="collapsing no-sort"></th>
					<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
					<th><?php echo $total_theme_upgrades . ' ' . _n( 'Update', 'Updates', $total_theme_upgrades, 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Trusted', 'mainwp' ); ?></th>
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
	 * @param int    $total_translation_upgrades total translation updates.
	 * @param mixed  $userExtension user extension.
	 * @param array  $allTranslations all translations.
	 * @param array  $translationsInfo translations information.
	 */
	public static function render_trans_update( $websites, $total_translation_upgrades, $userExtension, $allTranslations, $translationsInfo ) {
		?>
		<table class="ui stackable single line table" id="mainwp-translations-sites-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Translation', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="right aligned">
						<?php MainWP_UI::render_show_all_updates_button(); ?>
						<?php if ( MainWP_Updates::user_can_update_trans() ) : ?>
							<?php if ( 0 < $total_translation_upgrades ) : ?>
								<a href="javascript:void(0)" onClick="return updatesoverview_translations_global_upgrade_all();" class="ui button basic mini green" data-tooltip="<?php esc_html_e( 'Update all sites', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php esc_html_e( 'Update All Translations', 'mainwp' ); ?></a>
							<?php endif; ?>
						<?php endif; ?>
					</th>
				</tr>
			</thead>
			<tbody id="translations-updates-global" class="ui accordion">
				<?php foreach ( $allTranslations as $slug => $val ) : ?>
					<?php $cnt = intval( $val['cnt'] ); ?>
					<tr class="title">
						<td class="accordion-trigger"><i class="dropdown icon"></i></td>
						<td><?php echo esc_html( $translationsInfo[ $slug ]['name'] ); ?></td>
						<td sort-value="<?php echo $cnt; ?>"><?php echo $cnt; ?> <?php echo _n( 'Update', 'Updates', $cnt, 'mainwp' ); ?></td>
						<td class="right aligned">
						<?php if ( MainWP_Updates::user_can_update_trans() ) : ?>
							<?php
							if ( 0 < $cnt ) :
								if ( MAINWP_VIEW_PER_PLUGIN_THEME == $userExtension->site_view ) {
									MainWP_Updates::set_continue_update_html_selector( 'translations_upgrade_all', $slug );
								}
								?>
								<a href="javascript:void(0)" class="ui mini button green <?php echo MainWP_Updates::get_continue_update_selector(); ?>" onClick="return updatesoverview_translations_upgrade_all( '<?php echo $slug; ?>', '<?php echo rawurlencode( $translationsInfo[ $slug ]['name'] ); ?>' )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
							<?php endif; ?>
						<?php endif; ?>
						</td>
					</tr>
					<tr style="display:none">
						<td colspan="4" class="content">
							<table class="ui stackable single line table" id="mainwp-translations-sites-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
										<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
										<th class="collapsing no-sort"></th>
									</tr>
								</thead>
								<tbody class="translations-bulk-updates" translation_slug="<?php echo $slug; ?>" translation_name="<?php echo rawurlencode( $translationsInfo[ $slug ]['name'] ); ?>">
									<?php
									MainWP_DB::data_seek( $websites, 0 );
									while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
										$translation_upgrades = json_decode( $website->translation_upgrades, true );
										$translation_upgrade  = null;
										foreach ( $translation_upgrades as $current_translation_upgrade ) {
											if ( $current_translation_upgrade['slug'] == $slug ) {
												$translation_upgrade = $current_translation_upgrade;
												break;
											}
										}
										if ( null === $translation_upgrade ) {
											continue;
										}
										?>
										<tr site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>" updated="0">
											<td>
											<?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
											</td>
											<td><?php echo esc_html( $translation_upgrade['version'] ); ?></td>
											<td class="right aligned">
											<?php if ( MainWP_Updates::user_can_update_trans() ) : ?>
													<a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_upgrade_translation( <?php echo esc_attr( $website->id ); ?>, '<?php echo $slug; ?>' )"><?php esc_html_e( 'Update Now', 'mainwp' ); ?></a>
											<?php endif ?>
											</td>
										</tr>
										<?php
									}
									?>
								</tbody>
								<tfoot>
									<tr>
										<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
										<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
										<th class="collapsing no-sort"></th>
									</tr>
								</tfoot>
							</table>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th class="collapsing no-sort"></th>
					<th><?php esc_html_e( 'Translation', 'mainwp' ); ?></th>
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
	 * @param object $websites the websites.
	 * @param array $allPluginsOutdate all abandoned plugins.
	 * @param array $decodedDismissedPlugins all dismissed abandoned plugins.
	 */
	public static function render_abandoned_plugins( $websites, $allPluginsOutdate, $decodedDismissedPlugins ) {
		$str_format = __( 'Updated %s days ago', 'mainwp' );
		?>
		<table class="ui stackable single line table" id="mainwp-abandoned-plugins-items-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Plugin', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="collapsing no-sort">
						<?php MainWP_UI::render_show_all_updates_button(); ?>
					</th>
				</tr>
			</thead>
			<tbody class="ui accordion">
			<?php foreach ( $allPluginsOutdate as $slug => $val ) : ?>
				<?php
				$cnt         = intval( $val['cnt'] );
				$plugin_name = rawurlencode( $slug );
				?>
				<tr class="title">
					<td class="accordion-trigger"><i class="dropdown icon"></i></td>
					<td><a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . dirname( $slug ) . '&url=' . ( isset( $pluginsOutdateInfo[ $slug ]['uri'] ) ? rawurlencode( $pluginsOutdateInfo[ $slug ]['uri'] ) : '' ) . '&name=' . rawurlencode( $pluginsOutdateInfo[ $slug ]['Name'] ) . '&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal"><?php echo esc_html( $pluginsOutdateInfo[ $slug ]['Name'] ); ?></a></td>
					<td sort-value="<?php echo $cnt; ?>"><?php echo $cnt; ?> <?php echo _n( 'Website', 'Websites', $cnt, 'mainwp' ); ?></td>
					<td class="right aligned">
						<?php if ( MainWP_Updates::user_can_ignore_updates() ) { ?>
							<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_plugins_abandoned_ignore_all( '<?php echo $plugin_name; ?>', '<?php echo rawurlencode( $pluginsOutdateInfo[ $slug ]['Name'] ); ?>', this )"><?php esc_html_e( 'Ignore Globally', 'mainwp' ); ?></a>
						<?php } ?>
					</td>
				</tr>
				<tr style="display:none">
					<td colspan="4" class="content">
						<table class="ui stackable single line table" id="mainwp-abandoned-plugins-sites-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
									<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
									<th><?php esc_html_e( 'Last Update', 'mainwp' ); ?></th>
									<th class="no-sort"></th>
								</tr>
							</thead>
							<tbody class="abandoned-plugins-ignore-global" plugin_slug="<?php echo rawurlencode( $slug ); ?>" plugin_name="<?php echo rawurlencode( $pluginsOutdateInfo[ $slug ]['Name'] ); ?>" dismissed="0">
							<?php
							MainWP_DB::data_seek( $websites, 0 );
							while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
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

								if ( ! isset( $plugins_outdate[ $slug ] ) ) {
									continue;
								}

								$plugin_outdate           = $plugins_outdate[ $slug ];
								$now                      = new \DateTime();
								$last_updated             = $plugin_outdate['last_updated'];
								$plugin_last_updated_date = new \DateTime( '@' . $last_updated );
								$diff_in_days             = $now->diff( $plugin_last_updated_date )->format( '%a' );
								$outdate_notice           = sprintf( $str_format, $diff_in_days );
								?>
								<tr site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>" dismissed="0">
									<td>
									<?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
									</td>
									<td><?php echo esc_html( $plugin_outdate['Version'] ); ?></td>
									<td><?php echo $outdate_notice; ?></td>
									<td class="right aligned">
									<?php if ( MainWP_Updates::user_can_ignore_updates() ) : ?>
										<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_plugins_dismiss_outdate_detail( '<?php echo $plugin_name; ?>', '<?php echo rawurlencode( $plugin_outdate['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Now', 'mainwp' ); ?></a>
									<?php endif; ?>
									</td>
								</tr>
								<?php
							}
							?>
							</tbody>
						</table>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th class="collapsing no-sort"></th>
					<th><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Abandoned', 'mainwp' ); ?></th>
					<th class="collapsing no-sort"></th>
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
	 * @param array  $allThemesOutdate all abandoned themes.
	 * @param array  $decodedDismissedThemes all dismissed abandoned themes.
	 */
	public static function render_abandoned_themes( $websites, $allThemesOutdate, $decodedDismissedThemes ) {
		$str_format = __( 'Updated %s days ago', 'mainwp' );
		?>
		<table class="ui stackable single line table" id="mainwp-themes-updates-table">
			<thead>
				<tr>
					<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Theme', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
					<th class="collapsing no-sort">
						<?php MainWP_UI::render_show_all_updates_button(); ?>
					</th>
				</tr>
			</thead>
			<tbody class="ui accordion">
			<?php foreach ( $allThemesOutdate as $slug => $val ) : ?>
				<?php
				$cnt        = intval( $val['cnt'] );
				$theme_name = rawurlencode( $slug );
				?>
				<tr class="title">
					<td class="accordion-trigger"><i class="dropdown icon"></i></td>
					<td><?php echo esc_html( $val['name'] ); ?></td>
					<td sort-value="<?php echo $cnt; ?>"><?php echo $cnt; ?> <?php echo _n( 'Website', 'Websites', $cnt, 'mainwp' ); ?></td>
					<td class="right aligned">
						<?php if ( MainWP_Updates::user_can_ignore_updates() ) { ?>
							<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_themes_abandoned_ignore_all( '<?php echo $theme_name; ?>', '<?php echo rawurlencode( $val['name'] ); ?>', this )"><?php esc_html_e( 'Ignore Globally', 'mainwp' ); ?></a>
						<?php } ?>
					</td>
				</tr>
				<tr style="display:none">
					<td colspan="4" class="content">
						<table class="ui stackable single line table" id="mainwp-abandoned-themes-sites-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
									<th><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
									<th><?php esc_html_e( 'Last Update', 'mainwp' ); ?></th>
									<th class="no-sort"></th>
								</tr>
							</thead>
							<tbody class="abandoned-themes-ignore-global" theme_slug="<?php echo $slug; ?>" theme_name="<?php echo rawurlencode( $val['name'] ); ?>">
							<?php
							MainWP_DB::data_seek( $websites, 0 );
							while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
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

								if ( ! isset( $themes_outdate[ $slug ] ) ) {
									continue;
								}

								$theme_outdate           = $themes_outdate[ $slug ];
								$now                     = new \DateTime();
								$last_updated            = $theme_outdate['last_updated'];
								$theme_last_updated_date = new \DateTime( '@' . $last_updated );
								$diff_in_days            = $now->diff( $theme_last_updated_date )->format( '%a' );
								$outdate_notice          = sprintf( $str_format, $diff_in_days );
								?>
								<tr site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>" outdate="1" dismissed="0">
									<td>
									<?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
									</td>
									<td><?php echo esc_html( $theme_outdate['Version'] ); ?></td>
									<td><?php echo $outdate_notice; ?></td>
									<td class="right aligned">
									<?php if ( MainWP_Updates::user_can_ignore_updates() ) : ?>
										<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_themes_dismiss_outdate_detail( '<?php echo $theme_name; ?>', '<?php echo rawurlencode( $theme_outdate['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Now', 'mainwp' ); ?></a>
									<?php endif; ?>
									</td>
								</tr>
								<?php
							}
							?>
							</tbody>
						</table>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th class="collapsing no-sort"></th>
					<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Abandoned', 'mainwp' ); ?></th>
					<th class="collapsing no-sort"></th>
				</tr>
			</tfoot>
		</table>
		<?php
	}
}
