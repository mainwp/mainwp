<?php
/**
 * MainWP Monotoring Sites View.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Monitoring Sites View.
 */
class MainWP_Monitoring_View {

	/**
	 * Method render_settings()
	 *
	 * Render monitoring sites settings.
	 */
	public static function render_settings() {

		$disableSitesMonitoring = get_option( 'mainwp_disableSitesChecking' );
		$frequencySitesChecking = get_option( 'mainwp_frequencySitesChecking', 60 );
		$sitehealthThreshold    = get_option( 'mainwp_sitehealthThreshold', 80 ); // "Should be improved" threshold. 
		?>
		<h3 class="ui dividing header">
			<?php esc_html_e( 'Sites Monitoring', 'mainwp' ); ?>
		</h3>
		<div class="ui grid field">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Disable Child sites monitoring', 'mainwp' ); ?></label>
			<div class="ten wide column ui toggle checkbox mainwp-checkbox-showhide-elements" hide-parent="monitoring">
				<input type="checkbox" name="mainwp_disableSitesChecking" id="mainwp_disableSitesChecking" <?php echo ( 0 == $disableSitesMonitoring ? '' : 'checked="true"' ); ?>/>
			</div>
		</div>

		<div class="ui grid field" <?php echo $disableSitesMonitoring ? 'style="display:none"' : ''; ?> hide-element="monitoring">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Check interval', 'mainwp' ); ?></label>
			<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Check interval', 'mainwp' ); ?>" data-inverted="" data-position="top left">
				<select name="mainwp_frequencySitesChecking" id="mainwp_frequencySitesChecking" class="ui dropdown">
					<option value="5" <?php echo ( 5 == $frequencySitesChecking ? 'selected' : '' ); ?>><?php esc_html_e( 'Every 5 minutes', 'mainwp' ); ?></option>
					<option value="10" <?php echo ( 10 == $frequencySitesChecking ? 'selected' : '' ); ?>><?php esc_html_e( 'Every 10 minutes', 'mainwp' ); ?></option>
					<option value="30" <?php echo ( 30 == $frequencySitesChecking ? 'selected' : '' ); ?>><?php esc_html_e( 'Every 30 minutes', 'mainwp' ); ?></option>
					<option value="60" <?php echo ( 60 == $frequencySitesChecking ? 'selected' : '' ); ?>><?php esc_html_e( 'Every hour', 'mainwp' ); ?></option>
					<option value="180" <?php echo ( 180 == $frequencySitesChecking ? 'selected' : '' ); ?>><?php esc_html_e( 'Every 3 hours', 'mainwp' ); ?></option>
					<option value="360" <?php echo ( 360 == $frequencySitesChecking ? 'selected' : '' ); ?>><?php esc_html_e( 'Every 6 hours', 'mainwp' ); ?></option>
					<option value="720" <?php echo ( 720 == $frequencySitesChecking ? 'selected' : '' ); ?>><?php esc_html_e( 'Twice a day', 'mainwp' ); ?></option>
					<option value="1440" <?php echo ( 1440 == $frequencySitesChecking ? 'selected' : '' ); ?>><?php esc_html_e( 'Once a day', 'mainwp' ); ?></option>
				</select>
			</div>
		</div>
		<div class="ui grid field" <?php echo $disableSitesMonitoring ? 'style="display:none"' : ''; ?> hide-element="monitoring">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Site health threshold', 'mainwp' ); ?></label>
			<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Site health threshold.', 'mainwp' ); ?>" data-inverted="" data-position="top left">				
				<select name="mainwp_sitehealthThreshold" id="mainwp_sitehealthThreshold" class="ui dropdown">
					<option value="80" <?php echo ( ( 80 == $sitehealthThreshold || 0 == $sitehealthThreshold ) ? 'selected' : '' ); ?>><?php esc_html_e( 'Should be improved', 'mainwp' ); ?></option>
					<option value="100" <?php echo ( 100 == $sitehealthThreshold ? 'selected' : '' ); ?>><?php esc_html_e( 'Good', 'mainwp' ); ?></option>
				</select>
			</div>
		</div>		
		<?php
	}

}
