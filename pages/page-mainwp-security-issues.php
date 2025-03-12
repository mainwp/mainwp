<?php
/**
 * MainWP Security Issues page
 *
 * This page is used to manage child site security issues.
 *
 * @package MainWP/Securtiy_Issues
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Security_Issues
 *
 * Detect, display & fix known Security Issues.
 */
class MainWP_Security_Issues { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method get_class_name()
     *
     * @return string __CLASS__ Class Name
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method render()
     *
     * @param null $website Child Site ID.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     */
    public static function render( $website = null ) {

        if ( empty( $website ) ) {
            $id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
            if ( ! $id ) {
                return;
            }
            $website = MainWP_DB::instance()->get_website_by_id( $id );
        }

        if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
            return;
        }
        ?>
        <table class="ui table" id="mainwp-security-issues-table">
        <thead>
                <tr>
                    <th scope="col" class="center aligned collapsing"></th>
                    <th scope="col"><?php esc_html_e( 'Site Hardening Checks', 'mainwp' ); ?></th>
                    <th scope="col" class="collapsing"></th>
                </tr>
        </thead>
        <tbody>
                <tr>
                    <td>
                        <span id="wp_uptodate_loading"><i class="notched circle big loading icon"></i></span>
                        <span id="wp_uptodate_ok" style="display: none;"><span class="ui small green label"><?php esc_html_e( 'Good', 'mainwp' ); ?></span></span>
                        <span id="wp_uptodate_nok" style="display: none;"><span class="ui small red label"><?php esc_html_e( 'Bad', 'mainwp' ); ?></span></span>
                    </td>
                    <td>
                        <div class="ui small header">
                            <?php esc_html_e( 'WordPress Version', 'mainwp' ); ?>
                            <div class="sub header">
                                <div id="wp_uptodate-status-nok" style="display: none;"><?php esc_html_e( 'WordPress is not up to date.', 'mainwp' ); ?></div>
                                <div id="wp_uptodate-status-ok" style="display: none;"><?php esc_html_e( 'WordPress is up to date.', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span id="wp_uptodate_fix" style="display: none"><a href="#"  onClick="updatesoverview_global_upgrade_all('wp'); return false;" class="ui mini green fluid button" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Click here to update the WordPress core on the site.', 'mainwp' ); ?>"><?php esc_html_e( 'Update WordPress', 'mainwp' ); ?></a></span>
                    </td>
                </tr>

                <tr>
                    <td>
                        <span id="phpversion_matched_loading"><i class="notched circle big loading icon"></i></span>
                        <span id="phpversion_matched_ok" style="display: none;"><span class="ui small green label"><?php esc_html_e( 'Good', 'mainwp' ); ?></span></span>
                        <span id="phpversion_matched_nok" style="display: none;"><span class="ui small red label"><?php esc_html_e( 'Bad', 'mainwp' ); ?></span></span>
                    </td>
                    <td>
                        <div class="ui small header">
                            <?php esc_html_e( 'PHP Version', 'mainwp' ); ?>
                            <div class="sub header">
                                <div id="phpversion_matched-status-nok" style="display: none;"><?php esc_html_e( 'PHP version older than 8.0 reached end of development and no longer receive security updates.', 'mainwp' ); ?></div>
                                <div id="phpversion_matched-status-ok" style="display: none;"><?php esc_html_e( 'PHP version is up to date.', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </td>
                    <td></td>
                </tr>
                
                <tr>
                    <td>
                        <span id="php_reporting_loading"><i class="notched circle big loading icon"></i></span>
                        <span id="php_reporting_ok" style="display: none;"><span class="ui small green label"><?php esc_html_e( 'Good', 'mainwp' ); ?></span></span>
                        <span id="php_reporting_nok" style="display: none;"><span class="ui small red label"><?php esc_html_e( 'Bad', 'mainwp' ); ?></span></span>
                    </td>
                    <td>
                        <div class="ui small header">
                            <?php esc_html_e( 'PHP Error Reporting', 'mainwp' ); ?>
                            <div class="sub header">
                                <div id="php_reporting-status-nok" style="display: none;"><?php esc_html_e( 'PHP Error reporting is not disabled. Error messages can reveal sensitive details.', 'mainwp' ); ?></div>
                                <div id="php_reporting-status-ok" style="display: none;"><?php esc_html_e( 'PHP Error reporting is disabled.', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span id="php_reporting_fix" style="display: none"><a href="#" class="ui mini fluid green button"> <?php esc_html_e( 'Disable PHP Error Reporting', 'mainwp' ); ?></a></span>
                        <span id="php_reporting_unfix" style="display: none"><a href="#" class="ui mini fluid button"><?php esc_html_e( 'Reenable PHP Error Reporting', 'mainwp' ); ?></a></span>
                    </td>
                </tr>

                <tr>
                    <td>
                        <span id="db_reporting_loading"><i class="notched circle big loading icon"></i></span>
                        <span id="db_reporting_ok" style="display: none;"><span class="ui small green label"><?php esc_html_e( 'Good', 'mainwp' ); ?></span></span>
                        <span id="db_reporting_nok" style="display: none;"><span class="ui small red label"><?php esc_html_e( 'Bad', 'mainwp' ); ?></span></span>
                    </td>
                    <td>
                        <div class="ui small header">
                            <?php esc_html_e( 'Database Error Reporting', 'mainwp' ); ?>
                            <div class="sub header">
                                <div id="db_reporting-status-nok" style="display: none;"><?php esc_html_e( 'Database Error reporting is not disabled. Error messages can reveal sensitive details.', 'mainwp' ); ?></div>
                                <div id="db_reporting-status-ok" style="display: none;"><?php esc_html_e( 'Database Error reporting is disabled.', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span id="db_reporting_fix" style="display: none"><a href="#" class="ui mini fluid green button"><?php esc_html_e( 'Disable DB Error Reporting', 'mainwp' ); ?></a></span>
                        <span id="db_reporting_unfix" style="display: none"><a href="#" class="ui mini fluid button"><?php esc_html_e( 'Reenable DB Error Reporting', 'mainwp' ); ?></a></span>
                    </td>
                </tr>

                <tr>
                    <td>
                        <span id="sslprotocol_loading"><i class="notched circle big loading icon"></i></span>
                        <span id="sslprotocol_ok" style="display: none;"><span class="ui small green label"><?php esc_html_e( 'Good', 'mainwp' ); ?></span></span>
                        <span id="sslprotocol_nok" style="display: none;"><span class="ui small red label"><?php esc_html_e( 'Bad', 'mainwp' ); ?></span></span>
                    </td>
                    <td>
                        <div class="ui small header">
                            <?php esc_html_e( 'SSL Protocol', 'mainwp' ); ?>
                            <div class="sub header">
                                <div id="sslprotocol-status-nok" style="display: none;"><?php esc_html_e( 'SSL Protocol is not in place.', 'mainwp' ); ?></div>
                                <div id="sslprotocol-status-ok" style="display: none;"><?php esc_html_e( 'SSL Protocol is in place.', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </td>
                    <td></td>
                </tr>

                <tr>
                    <td>
                        <span id="debug_disabled_loading"><i class="notched circle big loading icon"></i></span>
                        <span id="debug_disabled_ok" style="display: none;"><span class="ui small green label"><?php esc_html_e( 'Good', 'mainwp' ); ?></span></span>
                        <span id="debug_disabled_nok" style="display: none;"><span class="ui small red label"><?php esc_html_e( 'Bad', 'mainwp' ); ?></span></span>
                    </td>
                    <td>
                        <div class="ui small header">
                            <?php esc_html_e( 'Debug Mode', 'mainwp' ); ?>
                            <div class="sub header">
                                <div id="debug_disabled-status-nok" style="display: none;"><?php esc_html_e( 'WP Debug mode is not disabled. Error messages can reveal sensitive details.', 'mainwp' ); ?></div>
                                <div id="debug_disabled-status-ok" style="display: none;"><?php esc_html_e( 'WP Debug mode is disabled.', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </td>
                    <td></td>
                </tr>

                <tr>
                    <td>
                        <span id="sec_outdated_plugins_loading"><i class="notched circle big loading icon"></i></span>
                        <span id="sec_outdated_plugins_ok" style="display: none;"><span class="ui small green label"><?php esc_html_e( 'Good', 'mainwp' ); ?></span></span>
                        <span id="sec_outdated_plugins_nok" style="display: none;"><span class="ui small red label"><?php esc_html_e( 'Bad', 'mainwp' ); ?></span></span>
                    </td>
                    <td>
                        <div class="ui small header">
                            <?php esc_html_e( 'Outdated Plugins', 'mainwp' ); ?>
                            <div class="sub header">
                                <div id="sec_outdated_plugins-status-nok" style="display: none;"><?php esc_html_e( 'Plugins are not up to date. Outdated plugins can contain known vulnerabilities that attackers may exploit.', 'mainwp' ); ?></div>
                                <div id="sec_outdated_plugins-status-ok" style="display: none;"><?php esc_html_e( 'Plugins are up to date.', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span id="sec_outdated_plugins_fix" style="display: none"><a href="admin.php?page=managesites&updateid=<?php echo intval( $website->id ); ?>" class="ui mini basic button"><?php esc_html_e( 'Manage Updates', 'mainwp' ); ?></a></span>
                        <span id="sec_outdated_plugins_unfix" style="display: none"></span>
                    </td>
                </tr>

                <tr>
                    <td>
                        <span id="sec_inactive_plugins_loading"><i class="notched circle big loading icon"></i></span>
                        <span id="sec_inactive_plugins_ok" style="display: none;"><span class="ui small green label"><?php esc_html_e( 'Good', 'mainwp' ); ?></span></span>
                        <span id="sec_inactive_plugins_nok" style="display: none;"><span class="ui small red label"><?php esc_html_e( 'Bad', 'mainwp' ); ?></span></span>
                    </td>
                    <td>
                        <div class="ui small header">
                            <?php esc_html_e( 'Inactive Plugins', 'mainwp' ); ?>
                            <div class="sub header">
                                <div id="sec_inactive_plugins-status-nok" style="display: none;"><?php esc_html_e( 'Inactive plugins detected. Removing unused plugins minimizes the risk of hidden vulnerabilities that could be exploited.', 'mainwp' ); ?></div>
                                <div id="sec_inactive_plugins-status-ok" style="display: none;"><?php esc_html_e( 'No inactive plugins.', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span id="sec_inactive_plugins_fix" style="display: none"><a href="admin.php?page=PluginsManage" class="ui mini basic button"><?php esc_html_e( 'Manage Plugins', 'mainwp' ); ?></a></span>
                        <span id="sec_inactive_plugins_unfix" style="display: none"></span>
                    </td>
                </tr>

                <tr>
                    <td>
                        <span id="sec_outdated_themes_loading"><i class="notched circle big loading icon"></i></span>
                        <span id="sec_outdated_themes_ok" style="display: none;"><span class="ui small green label"><?php esc_html_e( 'Good', 'mainwp' ); ?></span></span>
                        <span id="sec_outdated_themes_nok" style="display: none;"><span class="ui small red label"><?php esc_html_e( 'Bad', 'mainwp' ); ?></span></span>
                    </td>
                    <td>
                        <div class="ui small header">
                            <?php esc_html_e( 'Outdated Themes', 'mainwp' ); ?>
                            <div class="sub header">
                                <div id="sec_outdated_themes-status-nok" style="display: none;"><?php esc_html_e( 'Themes are not up to date. Outdated themes can contain known vulnerabilities that attackers may exploit.', 'mainwp' ); ?></div>
                                <div id="sec_outdated_themes-status-ok" style="display: none;"><?php esc_html_e( 'Themes are up to date.', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span id="sec_outdated_themes_fix" style="display: none"><a href="admin.php?page=managesites&updateid=<?php echo intval( $website->id ); ?>" class="ui mini basic button"><?php esc_html_e( 'Manage Updates', 'mainwp' ); ?></a></span>
                        <span id="sec_outdated_themes_unfix" style="display: none"></span>
                    </td>
                </tr>

                <tr>
                    <td>
                        <span id="sec_inactive_themes_loading"><i class="notched circle big loading icon"></i></span>
                        <span id="sec_inactive_themes_ok" style="display: none;"><span class="ui small green label"><?php esc_html_e( 'Good', 'mainwp' ); ?></span></span>
                        <span id="sec_inactive_themes_nok" style="display: none;"><span class="ui small red label"><?php esc_html_e( 'Bad', 'mainwp' ); ?></span></span>
                    </td>
                    <td>
                        <div class="ui small header">
                            <?php esc_html_e( 'Inactive Themes', 'mainwp' ); ?>
                            <div class="sub header">
                                <div id="sec_inactive_themes-status-nok" style="display: none;"><?php esc_html_e( 'Inactive themes detected. Removing unused themes minimizes the risk of hidden vulnerabilities that could be exploited.', 'mainwp' ); ?></div>
                                <div id="sec_inactive_themes-status-ok" style="display: none;"><?php esc_html_e( 'No inactive themes.', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span id="sec_inactive_themes_fix" style="display: none"><a href="admin.php?page=ThemesManage" class="ui mini basic button"><?php esc_html_e( 'Manage Themes', 'mainwp' ); ?></a></span>
                        <span id="sec_inactive_themes_unfix" style="display: none"></span>
                    </td>
                </tr>

            </tbody>
        </table>
        
        
        <input type="hidden" id="securityIssueSite" value="<?php echo intval( $website->id ); ?>"/>
        <div id="wp_upgrades">
            <div updated="-1" site_id="<?php echo intval( $website->id ); ?>" site_name="<?php echo esc_attr( $website->name ); ?>" ></div>
        </div>
        <?php
    }


    /**
     * Method Fetch Security Issues
     *
     * Fetch stored known Child Site Security Issues from DB that were found during Sync.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     */
    public static function fetch_security_issues() {
        $id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
        if ( ! $id ) {
            return '';
        }
        $website = MainWP_DB::instance()->get_website_by_id( $id );

        if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
            return '';
        }

        $information = MainWP_Connect::fetch_url_authed( $website, 'security' );

        /**
         * Filters security issues
         *
         * Filters the default security checks and enables user to disable certain checks.
         *
         * @param bool   false        Whether security issues should be filtered.
         * @param object $information Object containing data from che chid site related to security issues.
         *                            Available options: 'db_reporting', 'php_reporting'.
         * @param object $website     Object containing child site data.
         *
         * @since 4.1
         */
        $filterStats = apply_filters( 'mainwp_security_issues_stats', false, $information, $website );
        if ( false !== $filterStats && is_array( $filterStats ) ) {
            $information = array_merge( $information, $filterStats );
        }
        return $information;
    }

    /**
     * Method Fix Security Issues
     *
     * Fix the selected security issue.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_Sync::sync_information_array()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     */
    public static function fix_security_issue() { // phpcs:ignore -- NOSONAR - complex.
        $id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
        if ( ! $id ) {
            return '';
        }
        $website = MainWP_DB::instance()->get_website_by_id( $id );

        if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
            return '';
        }

        if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
            return '';
        }

        $skip_features = array(
            'db_reporting',
            'php_reporting',
            'wp_uptodate',
            'phpversion_matched',
            'sslprotocol',
            'debug_disabled',
        );

        /**
         * Filters security issues from fixing
         *
         * Filters the default security checks and enables user to disable certain issues from being fixed by using the Fix All button.
         *
         * @param bool   false          Whether security issues should be filtered.
         * @param object $skip_features Object containing data from che chid site related to security issues.
         *                              Available options: 'db_reporting', 'php_reporting'.
         * @param object $website       Object containing child site data.
         *
         * @since 4.1
         */
        $skip_features = apply_filters( 'mainwp_security_post_data', false, $skip_features, $website );

        $feature   = isset( $_REQUEST['feature'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['feature'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
        $post_data = array( 'feature' => $feature );
        if ( ! empty( $skip_features ) && is_array( $skip_features ) ) {
            $post_data['skip_features'] = $skip_features;
        }

        $unset_scripts = apply_filters( 'mainwp_unset_security_scripts_stylesheets', true );
        if ( $unset_scripts ) {
            if ( ! isset( $post_data['skip_features'] ) ) {
                $post_data['skip_features'] = array();
            }

            if ( ! in_array( 'versions', $post_data['skip_features'] ) ) {
                $post_data['skip_features'][] = 'versions';
            }
        }

        $information = MainWP_Connect::fetch_url_authed( $website, 'securityFix', $post_data );
        if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
            MainWP_Sync::sync_information_array( $website, $information['sync'] );
            unset( $information['sync'] );
        }

        return $information;
    }

    /**
     * Method un-Fix Security Issues
     *
     * Un-Fix the selected security issue.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_Sync::sync_information_array()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     */
    public static function unfix_security_issue() {
        $id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
        if ( ! $id ) {
            return '';
        }
        $website = MainWP_DB::instance()->get_website_by_id( $id );

        if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
            return '';
        }

        if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
            return '';
        }

        $feature = isset( $_REQUEST['feature'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['feature'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended

        $information = MainWP_Connect::fetch_url_authed( $website, 'securityUnFix', array( 'feature' => $feature ) );
        if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
            MainWP_Sync::sync_information_array( $website, $information['sync'] );
            unset( $information['sync'] );
        }

        return $information;
    }
}
