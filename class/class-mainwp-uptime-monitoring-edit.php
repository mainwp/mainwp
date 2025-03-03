<?php
/**
 * MainWP monitor site.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Uptime_Monitoring_Edit
 *
 * @package MainWP\Dashboard
 */
class MainWP_Uptime_Monitoring_Edit { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * The single instance of the class
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Get instance.
     *
     *  @return static::singlton
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }


    /**
     * MainWP_Setup_Wizard constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
    }



    /**
     * Method render_update_messages
     *
     * @param  mixed $individual individual.
     * @return void
     */
    public static function render_update_messages( $individual = false ) {

        if ( ! empty( $_GET['message'] ) ) { //phpcs:ignore -- NOSONAR - ok.
            $updated = intval( $_GET['message'] ); //phpcs:ignore --NOSONAR - ok.

            $message = '';

            if ( 1 === $updated ) {
                $message = $individual ? __( 'Uptime Monitor saved successfully.', 'mainwp' ) : __( 'Global Uptime Monitoring settings saved successfully.', 'mainwp' );

            }

            if ( ! empty( $message ) ) {
                ?>
                <div class="ui message green"><i class="close icon"></i> <?php echo esc_html( $message ); ?></div>
                <?php
                return;
            }

            if ( 2 === $updated ) {
                $message = __( 'Invalid data found. The sub-monitor could not be saved. Please try again.', 'mainwp' );
            } elseif ( 3 === $updated || 4 === $updated ) {
                $message = __( 'Sub URL is currently in use. Unable to save the sub-monitor. Please try again.', 'mainwp' );
            } elseif ( 5 === $updated ) {
                $message = __( 'Sub URL are empty. Unable to save the sub-monitor. Please try again.', 'mainwp' );
            }

            if ( ! empty( $message ) ) {
                ?>
                <div class="ui message error"><i class="close icon"></i> <?php echo esc_html( $message ); ?></div>
                <?php
            }
        }
    }

    /**
     * Method handle_save_settings
     *
     * @return void
     */
    public function handle_save_settings() {  //phpcs:ignore -- NOSONAR - complexity.

        if ( isset( $_POST['wp_nonce_uptime_settings'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce_uptime_settings'] ), 'UpdateMonitorSettings' ) && \mainwp_current_user_can( 'dashboard', 'edit_sites' ) ) {
            $up_status_codes = isset( $_POST['mainwp_edit_monitor_up_status_codes'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_edit_monitor_up_status_codes'] ) ) : '';
            if ( false !== strpos( $up_status_codes, 'useglobal' ) ) {
                $up_status_codes = 'useglobal'; // save "useglobal" only.
            }

            $monitoring_emails = isset( $_POST['mainwp_edit_monitor_monitoring_emails'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_edit_monitor_monitoring_emails'] ) ) : '';
            $monitoring_emails = MainWP_Utility::valid_input_emails( $monitoring_emails );

            $update = array(
                'active'          => isset( $_POST['mainwp_edit_monitor_active'] ) ? intval( $_POST['mainwp_edit_monitor_active'] ) : 0,
                'keyword'         => isset( $_POST['mainwp_edit_monitor_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_edit_monitor_keyword'] ) ) : '',
                'interval'        => isset( $_POST['mainwp_edit_monitor_interval_hidden'] ) ? intval( $_POST['mainwp_edit_monitor_interval_hidden'] ) : 60,
                'maxretries'      => isset( $_POST['mainwp_edit_monitor_maxretries'] ) ? intval( $_POST['mainwp_edit_monitor_maxretries'] ) : 0,
                'up_status_codes' => $up_status_codes,
                'type'            => isset( $_POST['mainwp_edit_monitor_type'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['mainwp_edit_monitor_type'] ) ) ) : 'http',
                'method'          => isset( $_POST['mainwp_edit_monitor_method'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['mainwp_edit_monitor_method'] ) ) ) : 'get',
                'timeout'         => isset( $_POST['mainwp_edit_monitor_timeout_number'] ) ? intval( $_POST['mainwp_edit_monitor_timeout_number'] ) : 60,
            );

            $individual = ! empty( $_POST['mainwp_edit_monitor_individual_settings'] ) ? 1 : 0;

            if ( $individual ) {

                $site_id = isset( $_REQUEST['monitor_wpid'] ) ? intval( $_REQUEST['monitor_wpid'] ) : 0;
                if ( empty( $site_id ) && isset( $_REQUEST['id'] ) ) {
                    $site_id = (int) $_REQUEST['id'];
                }

                $update['wpid'] = $site_id;
                $current        = false;
                $sub_editing    = false;

                // create or update sub monitor.
                if ( ! empty( $_POST['update_submonitor'] ) ) {
                    $sub_editing = true;

                    if ( ! empty( $_POST['edit_sub_monitor_id'] ) ) {
                        // edit sub monitor.
                        $update['monitor_id'] = intval( $_POST['edit_sub_monitor_id'] );
                    }

                    $redirect_sub_monitor = ! empty( $update['monitor_id'] ) ? '&monitor_id=' . intval( $update['monitor_id'] ) : '&action=add_submonitor';

                    $update['suburl'] = isset( $_POST['mainwp_edit_monitor_sub_url'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_edit_monitor_sub_url'] ) ) : '';

                    if ( empty( $update['suburl'] ) ) {
                        wp_safe_redirect( 'admin.php?page=managesites&id=' . $site_id . '&monitor_wpid=' . $site_id . $redirect_sub_monitor . '&message=5' );
                        exit();
                    }

                    $update['suburl'] = ltrim( trim( $update['suburl'] ), '/' ); // remove starting '/', if present.

                    if ( ! empty( $_POST['edit_sub_monitor_id'] ) ) {
                        // edit sub monitor.
                        $current = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $site_id, 'suburl', $update['suburl'], array(), ARRAY_A );
                        // existed suburl in use.
                        if ( $current && ( (int) $current['monitor_id'] !== $update['monitor_id'] ) ) {
                            wp_safe_redirect( 'admin.php?page=managesites&id=' . $site_id . '&monitor_wpid=' . $site_id . $redirect_sub_monitor . '&message=3' );
                            exit();
                        }
                    } else {
                        $params = array();

                        // add new sub monitor.
                        $current = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $site_id, 'suburl', $update['suburl'], $params, ARRAY_A );
                        // suburl in use.
                        if ( $current ) {
                            wp_safe_redirect( 'admin.php?page=managesites&id=' . $site_id . '&monitor_wpid=' . $site_id . $redirect_sub_monitor . '&message=4' );
                            exit();
                        }
                    }

                    $update['issub'] = ! empty( $_POST['monitor_edit_is_sub_url'] ) ? 1 : 0;

                    if ( ! empty( $_POST['edit_sub_monitor_id'] ) ) {
                        $update['monitor_id'] = intval( $_POST['edit_sub_monitor_id'] ); // edit sub monitor.
                    }

                    if ( empty( $update['suburl'] ) || empty( $update['issub'] ) ) {
                        wp_safe_redirect( 'admin.php?page=managesites&id=' . $site_id . '&monitor_wpid=' . $site_id . $redirect_sub_monitor . '&message=2' );
                        exit();
                    }
                } elseif ( ! empty( $_POST['mainwp_edit_monitor_id'] ) ) {
                    $update['monitor_id'] = intval( $_POST['mainwp_edit_monitor_id'] ); // update monitor or sub monitor.
                }

                if ( ! empty( $update['monitor_id'] ) ) {
                    $allowed_methods = static::get_allowed_methods();
                    if ( $current ) {
                        // fix invalid data.
                        if ( $current['method'] || ! isset( $allowed_methods[ $current['method'] ] ) ) {
                            $update['method'] = 'get';
                        }
                        $update['issub'] = ! empty( $update['suburl'] ) ? 1 : 0;
                    }
                }

                $update = apply_filters( 'mainwp_uptime_monitoring_update_monitor_data', $update, $site_id );

                MainWP_DB_Uptime_Monitoring::instance()->update_wp_monitor( $update );

                MainWP_Uptime_Monitoring_Schedule::instance()->check_to_disable_schedule_individual_uptime_monitoring(); // required a check to sync the settings.
                if ( $sub_editing ) {
                    wp_safe_redirect( 'admin.php?page=managesites&id=' . $site_id . '&monitor_wpid=' . $site_id . '&message=1' );
                    exit();
                }
            } else {
                MainWP_Uptime_Monitoring_Handle::update_uptime_global_settings( $update );
            }
        }
    }


    /**
     * Method is_enable_global_monitoring
     *
     * @return int
     */
    public static function is_enable_global_monitoring() {
        $global_settings = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
        $glo_active      = 0;
        if ( isset( $global_settings['active'] ) ) {
            $glo_active = 1 === (int) $global_settings['active'] ? 1 : 0;
        }
        return 1 === $glo_active ? true : false;
    }

    /**
     * Method render_monitor_settings
     *
     * @param  mixed $site_id site id.
     * @param  bool  $individual Individual settings.
     * @return void
     */
    public function render_monitor_settings( $site_id = false, $individual = false ) {  //phpcs:ignore -- NOSONAR - complexity.

        $title      = __( 'Site Monitoring Settings', 'mainwp' );
        $sub_header = __( 'Adjust monitoring preferences for this site to override global settings if needed.', 'mainwp' );

        $is_editing_monitor_or_sub_monitor = true;

        $default = MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings( $individual );

        $edit_sub_monitor = false;
        $show_in_modal    = false;

        $is_sub_url     = 0;
        $sub_monitor_id = 0;

        $mo_settings = array();

        if ( $individual ) {

            if ( empty( $site_id ) ) {
                ?>
                <div class="ui message error"><i class="close icon"></i> <?php esc_html_e( 'Site ID is missing. Please try again.', 'mainwp' ); ?></div>
                <?php
                return;
            }

            if ( ! empty( $_GET['sub_monitor_id'] ) ) {  //phpcs:ignore -- NOSONAR -ok.
                $mo_settings = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $site_id, 'monitor_id', $_GET['sub_monitor_id'], array(), ARRAY_A ); //phpcs:ignore -- NOSONAR -ok.
                if ( empty( $mo_settings ) ) {
                    ?>
                    <div class="ui message error"><i class="close icon"></i> <?php esc_html_e( 'Monitor not found or invalid. Please try again.', 'mainwp' ); ?></div>
                    <?php
                    return;
                }
                $edit_sub_monitor = true;
                $sub_monitor_id   = $mo_settings['monitor_id'];
                $is_sub_url       = $mo_settings['issub'];
            } else {
                // Empty monitor_id.
                // To get the main monitor of the site; if it doesn't exist, it will be created later.
                $mo_settings = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $site_id, 'issub', 0, array(), ARRAY_A );
                // if not found main site monitor.
                if ( empty( $mo_settings ) ) {
                    // get site to create new main monitor.
                    $mo_settings = MainWP_DB::instance()->get_website_by_id_params( $site_id, array(), ARRAY_A );

                }
            }

            // add new main monitor.
            if ( empty( $mo_settings['monitor_id'] ) ) {
                if ( empty( $mo_settings ) || ! is_array( $mo_settings ) ) {
                    $mo_settings = array();
                }
                $mo_settings                       = array_merge( $mo_settings, $default );
                $title                             = __( 'Add a New Monitor', 'mainwp' );
                $is_editing_monitor_or_sub_monitor = false;
            }
        } else {
            $mo_settings = get_option( 'mainwp_global_uptime_monitoring_settings', false );
        }

        if ( isset( $_GET['action'] ) && 'add_submonitor' === $_GET['action'] ) { //phpcs:ignore -- ok.
            $title                             = __( 'Add New Sub Monitor', 'mainwp' );
            $edit_sub_monitor                  = true;
            $is_sub_url                        = 1;
            $is_editing_monitor_or_sub_monitor = false;
        }

        if ( empty( $mo_settings ) ) {
            $mo_settings = $default;
        }

        $hide_style = 'style="display:none"';

        if ( $individual ) {
            $disableGeneralSitesMonitoring = false;
        } else {
            $disableGeneralSitesMonitoring = empty( $mo_settings['active'] ) ? true : false;
        }

        $disabled_methods_individual = false;

        if ( $individual && ( 'ping' === $mo_settings['type'] || 'keyword' === $mo_settings['type'] ) ) {
            $disabled_methods_individual = true;
        }

        ?>
        <?php
        if ( $individual ) {
            if ( $edit_sub_monitor ) {
                $show_in_modal = true;
            }
            ?>
            <h2 class="ui dividing header">
                <?php echo esc_html( $title ); ?>
                <div class="sub header"><?php echo esc_html( $sub_header ); ?></div>
            </h2>
            <?php
        }
        if ( $show_in_modal ) {
            $this->render_add_edit_sub_page_monitor_begin_form_in_modal( $title );
        }
        ?>
        <div id="mainwp-message-zone" class="ui message" style="display:none;"></div>
        <?php
        static::render_update_messages( $individual );
        ?>
        <?php

        if ( $show_in_modal ) {
            ?>
            <form method="POST" action="" id="mainwp-edit-monitor-site-form" enctype="multipart/form-data" class="ui form">
        <?php } ?>

            <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
            <input type="hidden" name="wp_nonce_uptime_settings" value="<?php echo esc_attr( wp_create_nonce( 'UpdateMonitorSettings' ) ); ?>" />
            <input type="hidden" id="mainwp_edit_monitor_site_id" name="mainwp_edit_monitor_site_id" value="<?php echo intval( $site_id ); ?>" />
            <input type="hidden" name="mainwp_edit_monitor_id" id="mainwp_edit_monitor_id" value="<?php echo ! empty( $mo_settings['monitor_id'] ) ? intval( $mo_settings['monitor_id'] ) : 0; ?>" />
            <input type="hidden" name="mainwp_edit_monitor_individual_settings" value="<?php echo $individual ? 1 : 0; ?>" />
            <?php

            if ( $individual && $edit_sub_monitor ) {
                ?>
                <input type="hidden" name="monitor_edit_is_sub_url" id="monitor_edit_is_sub_url" value="<?php echo intval( $is_sub_url ); ?>" />
                <input type="hidden" name="edit_sub_monitor_id" value="<?php echo intval( $sub_monitor_id ); ?>" />
                <input type="hidden" name="update_submonitor" value="1" />
                <div class="ui grid field settings-field-indicator-wrapper">
                    <label class="six wide column middle aligned">
                    <?php esc_html_e( 'Site URL', 'mainwp' ); ?>
                    </label>
                    <div class="ten wide column ui labeled input" data-tooltip="<?php esc_attr_e( 'Click to edit the main site monitor.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <a class="" href="admin.php?page=managesites&id=<?php echo intval( $site_id ); ?>&monitor_wpid=<?php echo intval( $site_id ); ?>"><?php echo esc_html( $mo_settings['url'] ); ?></a>
                    </div>
                </div>

                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-monitor-general">
                        <label class="six wide column middle aligned">
                        <?php
                        MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_edit_monitor_sub_url', $mo_settings['suburl'], true, '' );
                        esc_html_e( 'Sub-Monitor', 'mainwp' );
                        ?>
                        </label>
                        <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter a Sub Monitor excluding the site URL.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <div class="ui left labeled input">
                                <input type="text" class="settings-field-value-change-handler" id="mainwp_edit_monitor_sub_url" name="mainwp_edit_monitor_sub_url" value="<?php echo ! empty( $mo_settings['suburl'] ) ? esc_html( $mo_settings['suburl'] ) : ''; ?>"/>
                            </div>
                        </div>
                </div>
                <?php
            }

            ?>
            <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-monitor-general" default-indi-value="<?php echo $individual ? -1 : 0; ?>">
                <label class="six wide column middle aligned">
                <?php
                MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_edit_monitor_active', (int) $mo_settings['active'], true, ( $individual ? -1 : 0 ) );
                esc_html_e( 'Enable Uptime Monitoring', 'mainwp' );
                ?>
                </label>
                <?php
                if ( $individual ) {
                    ?>
                    <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Enable Uptime Monitoring.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <select name="mainwp_edit_monitor_active" id="mainwp_edit_monitor_active" class="ui dropdown settings-field-value-change-handler">
                            <option value="-1" <?php echo -1 === (int) $mo_settings['active'] ? 'selected' : ''; ?>><?php esc_html_e( 'Use global setting', 'mainwp' ); ?></option>
                            <option value="1" <?php echo 1 === (int) $mo_settings['active'] ? 'selected' : ''; ?>><?php esc_html_e( 'Enable', 'mainwp' ); ?></option>
                            <option value="0" <?php echo 0 === (int) $mo_settings['active'] ? 'selected' : ''; ?>><?php esc_html_e( 'Disable', 'mainwp' ); ?></option>
                        </select>
                    </div>
                    <?php
                } else {
                    ?>
                    <div class="ten wide column ui toggle checkbox mainwp-checkbox-showhide-elements" hide-parent="uptime-monitoring">
                        <input type="checkbox" value="1" class="settings-field-value-change-handler" name="mainwp_edit_monitor_active" id="mainwp_edit_monitor_active" <?php echo 1 === (int) $mo_settings['active'] ? 'checked="true"' : ''; ?>/>
                    </div>
                    <?php
                }
                ?>
            </div>

            <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-monitor-general" default-indi-value="<?php echo $individual ? 'useglobal' : 'http'; ?>" <?php echo $disableGeneralSitesMonitoring ? 'style="display:none"' : ''; ?> hide-element="uptime-monitoring">
                <label class="six wide column middle aligned">
                <?php
                MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_edit_monitor_type', $mo_settings['type'], true, ( $individual ? 'useglobal' : 'http' ) );
                esc_html_e( 'Monitor Type', 'mainwp' );
                ?>
                </label>
                <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Select Monitor Type.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <select name="mainwp_edit_monitor_type" id="mainwp_edit_monitor_type" class="ui dropdown settings-field-value-change-handler mainwp-selecter-showhide-elements" hide-parent="monitor-type" hide-value="http;ping;useglobal">
                    <?php
                    if ( $individual ) {
                        ?>
                        <option value="useglobal" <?php echo 'useglobal' === $mo_settings['type'] ? 'selected' : ''; ?>><?php esc_html_e( 'Use global setting', 'mainwp' ); ?></option>
                        <?php
                    }
                    ?>
                        <option value="http" <?php echo 'http' === $mo_settings['type'] ? 'selected' : ''; ?>><?php esc_html_e( 'HTTP(s)', 'mainwp' ); ?></option>
                        <option value="ping" <?php echo 'ping' === $mo_settings['type'] ? 'selected' : ''; ?>><?php esc_html_e( 'Ping', 'mainwp' ); ?></option>
                        <option value="keyword" <?php echo 'keyword' === $mo_settings['type'] ? 'selected' : ''; ?>><?php esc_html_e( 'Keyword Monitoring', 'mainwp' ); ?></option>
                    </select>
                </div>
            </div>

            <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-monitor-general" id="mainwp_edit_monitor_method_wrapper" default-indi-value="<?php echo $individual ? 'useglobal' : 'get'; ?>" <?php echo $disableGeneralSitesMonitoring || $disabled_methods_individual ? 'style="display:none"' : ''; ?> hide-element="uptime-monitoring">
                <label class="six wide column middle aligned">
                <?php
                MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_edit_monitor_method', $mo_settings['method'], true, $individual ? 'useglobal' : 'get' );
                esc_html_e( 'Method', 'mainwp' );
                $allowed_methods = static::get_allowed_methods( $individual );
                ?>
                </label>
                <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Select Method.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <select name="mainwp_edit_monitor_method" id="mainwp_edit_monitor_method" class="ui dropdown settings-field-value-change-handler mainwp-selecter-showhide-elements" hide-parent="monitor-method" hide-value="http;ping;useglobal">
                    <?php
                    foreach ( $allowed_methods as $val => $name ) {
                        ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php echo $val === $mo_settings['method'] ? 'selected' : ''; ?>><?php echo esc_html( $name ); ?></option>
                        <?php
                    }
                    ?>
                    </select>
                </div>
            </div>

            <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-monitor-general" default-indi-value="0"  <?php echo 'keyword' !== $mo_settings['type'] || $disableGeneralSitesMonitoring ? $hide_style : ''; //phpcs:ignore -- ok. ?> hide-element="monitor-type" <?php echo !$individual && 'keyword' === $mo_settings['type'] ? 'hide-sub-element="uptime-monitoring"' : ''; // to show/hide when enable/disable monitoring in global settings; ?>>
                <label class="six wide column middle aligned">
                <?php
                MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_edit_monitor_keyword', $mo_settings['keyword'] );
                esc_html_e( 'Keyword to Look For', 'mainwp' );
                ?>
                </label>
                <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Set Keyword to Look For.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <input type="text" class="settings-field-value-change-handler" name="mainwp_edit_monitor_keyword" id="mainwp_edit_monitor_keyword" value="<?php echo esc_attr( $mo_settings['keyword'] ); ?>"/>
                </div>
            </div>

            <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-monitor-general" default-indi-value="<?php echo $individual ? -1 : 60; ?>" <?php echo $disableGeneralSitesMonitoring ? 'style="display:none"' : ''; ?> hide-element="uptime-monitoring">
                <label class="six wide column middle aligned">
                <?php
                MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_edit_monitor_interval_hidden', (int) $mo_settings['interval'], true, ( $individual ? -1 : 60 ) );
                esc_html_e( 'Monitor Interval (minutes)', 'mainwp' );
                ?>
                </label>
                <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Set Monitor Interval.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <div class="ui labeled ticked slider settings-field-value-change-handler" id="mainwp_edit_monitor_interval_slider"></div>
                    <input type="hidden" name="mainwp_edit_monitor_interval_hidden" class="settings-field-value-change-handler" id="mainwp_edit_monitor_interval_hidden" value="<?php echo intval( $mo_settings['interval'] ); ?>" />
                </div>
            </div>
            <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-monitor-general" default-indi-value="<?php echo $individual ? -1 : 60; ?>" <?php echo $disableGeneralSitesMonitoring ? 'style="display:none"' : ''; ?> hide-element="uptime-monitoring">
                <label class="six wide column middle aligned">
                <?php
                MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_edit_monitor_timeout_number', (int) $mo_settings['timeout'], true, ( $individual ? -1 : 60 ) );
                esc_html_e( 'Timeout (seconds)', 'mainwp' );
                ?>
                </label>
                <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Set Monitor Interval.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <div class="ui labeled ticked slider settings-field-value-change-handler" id="mainwp_edit_monitor_timeout_slider"></div>
                    <input type="hidden" name="mainwp_edit_monitor_timeout_number" class="settings-field-value-change-handler" id="mainwp_edit_monitor_timeout_number" value="<?php echo intval( $mo_settings['timeout'] ); ?>" />
                </div>
            </div>
            <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-monitor-general" default-indi-value="<?php echo $individual ? -1 : 1; ?>" <?php echo $disableGeneralSitesMonitoring ? 'style="display:none"' : ''; ?> hide-element="uptime-monitoring">
                <label class="six wide column middle aligned">
                <?php
                if ( $individual ) {
                    MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_edit_monitor_maxretries', (int) $mo_settings['maxretries'] );
                } else {
                    MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_edit_monitor_maxretries_global', (int) $mo_settings['maxretries'] );
                }
                esc_html_e( 'Down Confirmation Check', 'mainwp' );
                ?>
                </label>
                <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Select Down Confirmation Check.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                    <select name="mainwp_edit_monitor_maxretries" id="mainwp_edit_monitor_maxretries" class="ui dropdown settings-field-value-change-handler">
                        <?php
                        if ( $individual ) {
                            ?>
                                <option value="-1" <?php echo -1 === (int) $mo_settings['maxretries'] ? 'selected' : ''; ?>><?php esc_html_e( 'Use global setting', 'mainwp' ); ?></option>
                            <?php
                        }
                        ?>
                        <option value="1" <?php echo 1 === (int) $mo_settings['maxretries'] ? 'selected' : ''; ?>><?php esc_html_e( 'Enable', 'mainwp' ); ?></option>
                        <option value="0" <?php echo 0 === (int) $mo_settings['maxretries'] ? 'selected' : ''; ?>><?php esc_html_e( 'Disable', 'mainwp' ); ?></option>
                    </select>
                </div>
            </div>

            <?php
            $http_error_codes = MainWP_Utility::get_http_codes();
            ?>
            <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-monitor-general" <?php echo $disableGeneralSitesMonitoring ? 'style="display:none"' : ''; ?> hide-element="uptime-monitoring">
                    <label class="six wide column middle aligned">
                    <?php
                    $up_statuscodes = ! empty( $mo_settings['up_status_codes'] ) ? $mo_settings['up_status_codes'] : '';
                    MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_edit_monitor_up_status_codes', $up_statuscodes );
                    esc_html_e( 'Up HTTP Codes', 'mainwp' );
                    ?>
                    </label>
                    <div class="ten wide column"  data-tooltip="<?php esc_attr_e( 'Select Up HTTP Codes.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                        <div class="ui multiple selection dropdown" init-value="<?php echo esc_attr( $up_statuscodes ); ?>">
                            <input name="mainwp_edit_monitor_up_status_codes" class="settings-field-value-change-handler" type="hidden">
                            <i class="dropdown icon"></i>
                            <div class="default text"></div>
                            <div class="menu">
                                <?php
                                if ( $individual ) {
                                    ?>
                                <div class="item" data-value='useglobal'><?php esc_html_e( 'Use global setting', 'mainwp' ); ?></div>
                                    <?php
                                }
                                foreach ( $http_error_codes as $error_code => $label ) {
                                    ?>
                                    <div class="item" data-value="<?php echo esc_attr( $error_code ); ?>"><?php echo esc_html( $error_code . ' (' . $label . ')' ); ?></div>
                                    <?php
                                }
                                ?>
                            </div>
                        </div>
                    </div>
            </div>

            <?php if ( $individual ) { ?>
                <?php

                if ( ! $edit_sub_monitor ) {

                    $_params      = array(
                        'issub' => 1,
                        'wpid'  => $site_id,
                    );
                    $sub_monitors = MainWP_DB_Uptime_Monitoring::instance()->get_monitors( $_params, ARRAY_A );
                    ?>
                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-monitor-general">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Sub-Monitors', 'mainwp' ); ?></label>
                        <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Click to create a sub-monitor.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <?php
                            $this->render_sub_urls_monitoring( $mo_settings, $sub_monitors );
                            ?>
                            <div class="ui hidden divider"></div>
                            <?php
                            if ( ! $edit_sub_monitor ) {
                                ?>
                                <a class="ui mini green basic button" href="admin.php?page=managesites&id=<?php echo intval( $site_id ); ?>&monitor_wpid=<?php echo intval( $site_id ); ?>&action=add_submonitor"><?php esc_html_e( 'Create Sub-Monitor', 'mainwp' ); ?></a>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                    <?php
                }

                if ( ! $show_in_modal && $is_editing_monitor_or_sub_monitor ) {
                    ?>
                    <input type="button" name="delete_uptime_monitor_btn" id="delete_uptime_monitor_btn" class="ui button basic big" value="<?php esc_html_e( 'Disable Monitor', 'mainwp' ); ?>">
                    <?php
                }
                ?>
            <?php } ?>
            <script type="text/javascript">
                        <?php
                        $all_intervals = static::get_interval_values( $individual );
                        echo 'var interval_label = ' . wp_json_encode( array_values( $all_intervals ) ) . ";\n";
                        echo 'var interval_values = ' . wp_json_encode( array_keys( $all_intervals ) ) . ";\n";
                        ?>
                        jQuery('#mainwp_edit_monitor_interval_slider').slider({
                            interpretLabel: function(value) {
                                return interval_label[value];
                            },
                            autoAdjustLabels: false,
                            min: 0,
                            smooth: true,
                            restrictedLabels: [interval_label[0],interval_label[<?php echo count( $all_intervals ) - 1; ?>]],
                            showThumbTooltip: true,
                            tooltipConfig: {
                                position: 'bottom center',
                                variation: 'small visible black'
                            },
                            max: <?php echo count( $all_intervals ) - 1; ?>,
                            onChange: function(value) {
                                jQuery('#mainwp_edit_monitor_interval_hidden').val(interval_values[value]).change();
                            },
                            onMove: function(value) {
                                jQuery(this).find('.thumb').attr('data-tooltip', interval_label[value]);
                            }
                        });
                        jQuery('#mainwp_edit_monitor_interval_slider').slider('set value', interval_values.indexOf(<?php echo intval( $mo_settings['interval'] ); ?>));

                        <?php
                        $all_timeouts = static::get_timeout_values( $individual );
                        echo 'var timeouts_label = ' . wp_json_encode( array_values( $all_timeouts ) ) . ";\n";
                        echo 'var timeouts_values = ' . wp_json_encode( array_keys( $all_timeouts ) ) . ";\n";
                        ?>
                        jQuery('#mainwp_edit_monitor_timeout_slider').slider({
                            interpretLabel: function(value) {
                                return timeouts_label[value];
                            },
                            autoAdjustLabels: false,
                            min: 0,
                            smooth: true,
                            restrictedLabels: [interval_label[0],timeouts_label[<?php echo count( $all_timeouts ) - 1; ?>]],
                            showThumbTooltip: true,
                            tooltipConfig: {
                                position: 'bottom center',
                                variation: 'small visible black'
                            },
                            max: <?php echo count( $all_timeouts ) - 1; ?>,
                            onChange: function(value) {
                                jQuery('#mainwp_edit_monitor_timeout_number').val(timeouts_values[value]).change();
                            },
                            onMove: function(value) {
                                jQuery(this).find('.thumb').attr('data-tooltip', timeouts_label[value]);
                            }
                        });
                        jQuery('#mainwp_edit_monitor_timeout_slider').slider('set value', timeouts_values.indexOf(<?php echo intval( $mo_settings['timeout'] ); ?>));

                        jQuery( document ).ready( function() {
                            jQuery( '#mainwp_edit_monitor_type' ).on( 'change', function() {
                                const val = jQuery(this).val();
                                if( val === 'keyword' || val === 'ping' ){
                                    jQuery( '#mainwp_edit_monitor_method_wrapper' ).hide();
                                } else {
                                    jQuery( '#mainwp_edit_monitor_method_wrapper' ).show();
                                }
                            } );
                        } );
                </script>

            <?php if ( $individual ) { ?>
                <?php
                if ( $show_in_modal ) {
                    $this->render_add_edit_sub_page_monitor_end_form_in_modal( $site_id, $is_editing_monitor_or_sub_monitor );
                    ?>
                    </form>
                    <?php
                }
            }
    }


    /**
     * Method render_sub_urls_monitoring
     *
     * @param  array $mo_settings monitoring settings.
     * @param  array $sub_urls_monitors sub urls monitors.
     * @return void
     */
    public function render_sub_urls_monitoring( $mo_settings, $sub_urls_monitors ) {

        // USE 'id' first, to sure not messed with some 'wpid'.
        if ( isset( $mo_settings['id'] ) ) {
            $site_id = intval( $mo_settings['id'] );
        } elseif ( isset( $mo_settings['wpid'] ) ) {
            $site_id = intval( $mo_settings['wpid'] );
        }

        if ( empty( $site_id ) ) {
            return;
        }
        if ( empty( $sub_urls_monitors ) || ! is_array( $sub_urls_monitors ) ) {
            esc_html_e( 'This site has no sub-monitors.', 'mainwp' );
        } else {
            ?>
            <ul>
            <?php
            foreach ( $sub_urls_monitors as $sub ) {
                ?>
                <li>
                <a class="" href="admin.php?page=managesites&id=<?php echo intval( $site_id ); ?>&monitor_wpid=<?php echo intval( $site_id ); ?>&sub_monitor_id=<?php echo intval( $sub['monitor_id'] ); ?>"><?php echo ( ! empty( $sub['suburl'] ) ) ? esc_html( $mo_settings['url'] . $sub['suburl'] ) : esc_html__( 'Invalid sub URL: field is empty.', 'mainwp' ); ?></a>
            </li>
                <?php
            }
            ?>
            </ul>
            <?php
        }
    }


    /**
     * Method get_allowed_methods
     *
     * @param  mixed $individual individual.
     * @return array
     */
    public static function get_allowed_methods( $individual = true ) {

        $methods = apply_filters(
            'mainwp_uptime_monitoring_allowed_methods',
            array(
                'useglobal' => esc_html__( 'Use global settings', 'mainwp' ),
                'head'      => 'HEAD',
                'get'       => 'GET',
                'post'      => 'POST',
                'push'      => 'PUSH',
                'patch'     => 'PATCH',
                'delete'    => 'DELETE',
            )
        );

        if ( ! $individual && isset( $methods['useglobal'] ) ) {
            unset( $methods['useglobal'] );
        }

        return $methods;
    }


    /**
     * Method get_interval_values
     *
     * @param  mixed $individual individual.
     * @param  mixed $flip_values flip values.
     * @return array
     */
    public static function get_interval_values( $individual = true, $flip_values = false ) {

        $values = array(
            -1 => 'Use global setting',
            5  => '5m',
            10 => '10m',
            15 => '15m',
            30 => '30m',
            45 => '45m',
        );

        if ( ! $individual ) {
            unset( $values[-1] );
        }

        for ( $i = 1;$i <= 24;$i++ ) {
            $values[ $i * 60 ] = $i . 'h';
        }

        if ( $flip_values ) {
            $values = array_flip( $values );
        }
        return apply_filters( 'mainwp_uptime_monitoring_interval_values', $values, $flip_values );
    }


    /**
     * Method get_timeout_values
     *
     * @param  mixed $individual individual.
     * @param  mixed $flip_values flip values.
     * @return array
     */
    public static function get_timeout_values( $individual = true, $flip_values = false ) {

        $values = array(
            -1 => 'Use global setting',
            30 => '30s',
            45 => '45s',
            60 => '60s',
            90 => '90s',
        );

        if ( ! $individual ) {
            unset( $values[-1] );
        }

        $step = 60;
        $val  = 60;
        for ( $i = 1;$i < 10;$i++ ) {
            $val           += $step;
            $values[ $val ] = ( $i + 1 ) . 'min';
        }

        $values[ 15 * 60 ] = '15min';
        $values[ 30 * 60 ] = '30min';
        $values[ 45 * 60 ] = '45min';
        $values[ 60 * 60 ] = '60min';
        $values[ 90 * 60 ] = '90min';
        $values[0]         = 'No limit';

        if ( $flip_values ) {
            $values = array_flip( $values );
        }
        return apply_filters( 'mainwp_uptime_monitoring_timeout_values', $values, $flip_values );
    }

    /**
     * Method render_add_edit_sub_page_monitor_begin_form_in_modal().
     *
     * Renders Modal.
     *
     * @param  mixed $title title.
     * @return void
     */
    public function render_add_edit_sub_page_monitor_begin_form_in_modal( $title ) {
        ?>
        <div id="mainwp-uptime-monitoring-add-edit-modal" class="ui modal">
            <i class="close icon"></i>
            <div class="header"><?php echo esc_html( $title ); ?></div>
                <div class="scrolling content mainwp-modal-content" id="tracker-bucket-edit-wrapper">
        <?php
    }

    /**
     * Method render_add_edit_sub_page_monitor_end_form_in_modal().
     *
     * Renders Modal.
     *
     * @param  int  $site_id site id.
     * @param  bool $is_editing is editing.
     * @return void
     */
    public function render_add_edit_sub_page_monitor_end_form_in_modal( $site_id, $is_editing = true ) {

        ?>
                </div>
                <div class="actions">
                    <div class="ui one column grid">
                        <div class="left aligned column">
                            <input type="submit" name="submit" id="submit" class="ui green button" value="<?php esc_html_e( 'Save', 'mainwp' ); ?>">
                                <?php
                                if ( $is_editing ) {
                                    ?>
                                    <input type="button" name="delete_uptime_monitor_btn" id="delete_uptime_monitor_btn" class="ui basic button" value="<?php esc_html_e( 'Delete', 'mainwp' ); ?>">
                                    <?php
                                }
                                ?>
                        </div>
                    </div>
                </div>
            <script type="text/javascript">
                jQuery('#mainwp-uptime-monitoring-add-edit-modal').modal({
                    allowMultiple: true,
                    onHide: function () {
                        location.href = 'admin.php?page=managesites&id=<?php echo intval( $site_id ); ?>&monitor_wpid=<?php echo intval( $site_id ); ?>';
                    }
                }).modal('show');
            </script>
            <?php
    }
}
