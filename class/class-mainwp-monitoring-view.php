<?php
/**
 * MainWP monitoring sites view
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Monitoring_View
 *
 * @package MainWP\Dashboard
 */
class MainWP_Monitoring_View { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Render monitoring sites settings.
     */
    public static function render_settings() {

        $disableSitesHealthMonitoring = (int) get_option( 'mainwp_disableSitesHealthMonitoring', 1 ); // disabled by default.
        $sitehealthThreshold          = (int) get_option( 'mainwp_sitehealthThreshold', 80 ); // "Should be improved" threshold.
        ?>
        <h3 class="ui dividing header">
            <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-monitor-general' ); ?>
            <?php esc_html_e( 'Uptime Monitoring', 'mainwp' ); ?>
            <div class="sub header"><?php printf( esc_html__( 'For additional help with setting up the Uptime Monitoring, please see %1$sthis help document%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/sites-monitoring/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); // NOSONAR - noopener - open safe. ?></div>
        </h3>

        <?php
            MainWP_Uptime_Monitoring_Edit::instance()->render_monitor_settings();
        ?>
        <h3 class="ui dividing header">
            <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-health-monitoring' ); ?>
            <?php esc_html_e( 'Site Health Monitoring', 'mainwp' ); ?>
            <div class="sub header"><?php printf( esc_html__( 'For additional help with setting up the Site Health monitoring, please see %1$sthis help document%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/sites-monitoring/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); // NOSONAR - noopener - open safe. ?></div>
        </h3>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-health-monitoring" default-indi-value="1">
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_disableSitesHealthMonitoring', (int) $disableSitesHealthMonitoring );
            esc_html_e( 'Enable Site Health monitoring', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column ui toggle checkbox mainwp-checkbox-showhide-elements" hide-parent="health-monitoring">
                <input type="checkbox" class="settings-field-value-change-handler" inverted-value="1" name="mainwp_disable_sitesHealthMonitoring" id="mainwp_disable_sitesHealthMonitoring" <?php echo 1 === (int) $disableSitesHealthMonitoring ? '' : 'checked="true"'; ?>/>
            </div>
        </div>

        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-health-monitoring" default-indi-value="80" <?php echo $disableSitesHealthMonitoring ? 'style="display:none"' : ''; ?> hide-element="health-monitoring">
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_sitehealthThreshold', (int) $sitehealthThreshold );
            esc_html_e( 'Site health threshold', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Set preferred site health threshold.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                <select name="mainwp_site_healthThreshold" id="mainwp_site_healthThreshold" class="ui dropdown settings-field-value-change-handler">
                    <option value="80" <?php echo ( 80 === $sitehealthThreshold || 0 === $sitehealthThreshold ) ? 'selected' : ''; ?>><?php esc_html_e( 'Should be improved', 'mainwp' ); ?></option>
                    <option value="100" <?php echo 100 === $sitehealthThreshold ? 'selected' : ''; ?>><?php esc_html_e( 'Good', 'mainwp' ); ?></option>
                </select>
            </div>
        </div>
        <?php
    }
}
