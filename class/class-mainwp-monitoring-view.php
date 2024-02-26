<?php
/**
 * MainWP monotoring sites view
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Monitoring_View
 *
 * @package MainWP\Dashboard
 */
class MainWP_Monitoring_View {

	/**
	 * Render monitoring sites settings.
	 */
	public static function render_settings() {

		$disableSitesMonitoring = (int) get_option( 'mainwp_disableSitesChecking', 1 );
		$frequencySitesChecking = (int) get_option( 'mainwp_frequencySitesChecking', 60 );

		$disableSitesHealthMonitoring = (int) get_option( 'mainwp_disableSitesHealthMonitoring', 1 ); // disabled by default.
		$sitehealthThreshold          = (int) get_option( 'mainwp_sitehealthThreshold', 80 ); // "Should be improved" threshold.
		?>
		<h3 class="ui dividing header">
			<?php esc_html_e( 'Basic Uptime Monitoring', 'mainwp' ); ?>
			<div class="sub header"><?php printf( esc_html__( 'For additional help with setting up the Basic Uptime monitoring, please see %1$sthis help document%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/sites-monitoring/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?></div>
		</h3>
		<div class="ui info message"><?php printf( esc_html__( 'Excessive checking can cause server resource issues.  For frequent checks or lots of sites, we recommend the %1$sMainWP Advanced Uptime Monitoring%2$s extension.', 'mainwp' ), '<a href="https://mainwp.com/extension/advanced-uptime-monitor" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?></div>
		<div class="ui grid field">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Enable basic uptime monitoring', 'mainwp' ); ?></label>
			<div class="ten wide column ui toggle checkbox mainwp-checkbox-showhide-elements" hide-parent="monitoring">
				<input type="checkbox" name="mainwp_disableSitesChecking" id="mainwp_disableSitesChecking" <?php echo ( 1 === (int) $disableSitesMonitoring ? '' : 'checked="true"' ); ?>/>
			</div>
		</div>

		<div class="ui grid field" <?php echo $disableSitesMonitoring ? 'style="display:none"' : ''; ?> hide-element="monitoring">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Check interval', 'mainwp' ); ?></label>
			<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Select preferred checking interval.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
				<select name="mainwp_frequency_sitesChecking" id="mainwp_frequency_sitesChecking" class="ui dropdown">
					<option value="5" <?php echo ( 5 === $frequencySitesChecking ? 'selected' : '' ); ?>><?php esc_html_e( 'Every 5 minutes', 'mainwp' ); ?></option>
					<option value="10" <?php echo ( 10 === $frequencySitesChecking ? 'selected' : '' ); ?>><?php esc_html_e( 'Every 10 minutes', 'mainwp' ); ?></option>
					<option value="30" <?php echo ( 30 === $frequencySitesChecking ? 'selected' : '' ); ?>><?php esc_html_e( 'Every 30 minutes', 'mainwp' ); ?></option>
					<option value="60" <?php echo ( 60 === $frequencySitesChecking ? 'selected' : '' ); ?>><?php esc_html_e( 'Every hour', 'mainwp' ); ?></option>
					<option value="180" <?php echo ( 180 === $frequencySitesChecking ? 'selected' : '' ); ?>><?php esc_html_e( 'Every 3 hours', 'mainwp' ); ?></option>
					<option value="360" <?php echo ( 360 === $frequencySitesChecking ? 'selected' : '' ); ?>><?php esc_html_e( 'Every 6 hours', 'mainwp' ); ?></option>
					<option value="720" <?php echo ( 720 === $frequencySitesChecking ? 'selected' : '' ); ?>><?php esc_html_e( 'Twice a day', 'mainwp' ); ?></option>
					<option value="1440" <?php echo ( 1440 === $frequencySitesChecking ? 'selected' : '' ); ?>><?php esc_html_e( 'Once a day', 'mainwp' ); ?></option>
				</select>
			</div>
		</div>
		<h3 class="ui dividing header">
			<?php esc_html_e( 'Site Health Monitoring', 'mainwp' ); ?>
			<div class="sub header"><?php printf( esc_html__( 'For additional help with setting up the Site Health monitoring, please see %1$sthis help document%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/sites-monitoring/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?></div>
		</h3>
		<div class="ui grid field">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Enable Site Health monitoring', 'mainwp' ); ?></label>
			<div class="ten wide column ui toggle checkbox mainwp-checkbox-showhide-elements" hide-parent="health-monitoring">
				<input type="checkbox" name="mainwp_disable_sitesHealthMonitoring" id="mainwp_disable_sitesHealthMonitoring" <?php echo ( 1 === (int) $disableSitesHealthMonitoring ? '' : 'checked="true"' ); ?>/>
			</div>
		</div>

		<div class="ui grid field" <?php echo $disableSitesHealthMonitoring ? 'style="display:none"' : ''; ?> hide-element="health-monitoring">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Site health threshold', 'mainwp' ); ?></label>
			<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Set preferred site health threshold.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
				<select name="mainwp_site_healthThreshold" id="mainwp_site_healthThreshold" class="ui dropdown">
					<option value="80" <?php echo ( ( 80 === $sitehealthThreshold || 0 === $sitehealthThreshold ) ? 'selected' : '' ); ?>><?php esc_html_e( 'Should be improved', 'mainwp' ); ?></option>
					<option value="100" <?php echo ( 100 === $sitehealthThreshold ? 'selected' : '' ); ?>><?php esc_html_e( 'Good', 'mainwp' ); ?></option>
				</select>
			</div>
		</div>
		<?php
	}
}
