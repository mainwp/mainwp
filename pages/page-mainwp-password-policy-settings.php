<?php
/**
 * Password Policy Settings.
 *
 * Handles propagation of password policy settings to child sites.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Password_Policy_Settings
 */
class MainWP_Password_Policy_Settings {

    /**
     * Get Class Name
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Initialize hooks.
     */
    public static function init() {
        add_action( 'wp_ajax_mainwp_password_policy_update_site', array( static::get_class_name(), 'ajax_update_site' ) );
        add_action( 'wp_ajax_mainwp_password_policy_save_individual', array( static::get_class_name(), 'ajax_save_individual' ) );
        add_action( 'admin_enqueue_scripts', array( static::get_class_name(), 'enqueue_scripts' ) );
        add_filter( 'mainwp_create_security_nonces', array( static::get_class_name(), 'create_security_nonces' ) );
        add_filter( 'mainwp_getsubpages_sites', array( static::get_class_name(), 'managesites_subpage' ), 10, 1 );
        add_filter( 'mainwp_manage_sites_navigation_items', array( static::get_class_name(), 'reorder_navigation_items' ), 10, 3 );
    }

    /**
     * Register security nonce for password policy AJAX action.
     *
     * @param array $nonces Existing nonces.
     * @return array Updated nonces.
     */
    public static function create_security_nonces( $nonces ) {
        if ( ! is_array( $nonces ) ) {
            $nonces = array();
        }
        $nonces[] = 'mainwp_password_policy_update_site';
        $nonces[] = 'mainwp_password_policy_save_individual';
        return $nonces;
    }

    /**
     * Add Password Policy tab to individual site pages.
     *
     * @param array $subpages Existing subpages.
     * @return array Updated subpages.
     */
    public static function managesites_subpage( $subpages ) {
        if ( ! is_array( $subpages ) ) {
            $subpages = array();
        }

        $subpages[] = array(
            'title'       => esc_html__( 'Password Policy', 'mainwp' ),
            'slug'        => 'PasswordPolicy',
            'sitetab'     => true,
            'menu_hidden' => true,
            'callback'    => array( static::get_class_name(), 'render_individual_site' ),
        );

        return $subpages;
    }

    /**
     * Reorder navigation items to place Password Policy after Site Hardening.
     *
     * @param array  $items Navigation items.
     * @param int    $site_id Site ID.
     * @return array Reordered navigation items.
     */
    public static function reorder_navigation_items( $items, $site_id ) {
        if ( empty( $site_id ) || ! is_array( $items ) ) {
            return $items;
        }

        $password_policy_item = null;
        $security_scan_index  = -1;
        $new_items            = array();

        foreach ( $items as $item ) {
            if ( isset( $item['href'] ) && false !== strpos( $item['href'], 'PasswordPolicy' ) ) {
                $password_policy_item = $item;
                continue;
            }

            if ( isset( $item['href'] ) && false !== strpos( $item['href'], 'scanid=' ) ) {
                $security_scan_index = count( $new_items );
            }

            $new_items[] = $item;
        }

        if ( null !== $password_policy_item && $security_scan_index >= 0 ) {
            array_splice( $new_items, $security_scan_index + 1, 0, array( $password_policy_item ) );
            return $new_items;
        }

        if ( null !== $password_policy_item ) {
            $new_items[] = $password_policy_item;
        }

        return $new_items;
    }

    /**
     * Enqueue scripts and styles.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue_scripts( $hook ) {
        if ( 'mainwp_page_PasswordPolicy' !== $hook &&
            'mainwp_page_managesites' !== $hook &&
            'mainwp_page_ManageSitesPasswordPolicy' !== $hook ) {
            return;
        }

        wp_enqueue_script(
            'mainwp-password-policy',
            MAINWP_PLUGIN_URL . 'assets/js/mainwp-password-policy.js',
            array( 'jquery' ),
            MAINWP_VERSION,
            true
        );
    }

    /**
     * Method init_menu()
     *
     * Add Settings sub menu "Password Policy Settings".
     */
    public static function init_menu() {
        add_submenu_page(
            'mainwp_tab',
            __( 'Password Policy', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Password Policy', 'mainwp' ) . '</div>',
            'read',
            'PasswordPolicy',
            array(
                static::get_class_name(),
                'render',
            )
        );
    }

    /**
     * Renders the individual site Password Policy Settings page.
     */
    public static function render_individual_site() {
        do_action( 'mainwp_pageheader_sites', 'PasswordPolicy' );

        $site_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;

        if ( empty( $site_id ) ) {
            ?>
            <div class="ui red message">
                <?php esc_html_e( 'Invalid site ID.', 'mainwp' ); ?>
            </div>
            <?php
            do_action( 'mainwp_pagefooter_sites' );
            return;
        }

        $website = MainWP_DB::instance()->get_website_by_id( $site_id );

        if ( ! $website ) {
            ?>
            <div class="ui red message">
                <?php esc_html_e( 'Site not found.', 'mainwp' ); ?>
            </div>
            <?php
            do_action( 'mainwp_pagefooter_sites' );
            return;
        }

        static::render_individual_form( $website );

        do_action( 'mainwp_pagefooter_sites' );
    }

    /**
     * Renders the Password Policy Settings page.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_urls_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_User::render_header()
     * @uses \MainWP\Dashboard\MainWP_User::render_footer()
     * @uses \MainWP\Dashboard\MainWP_Utility::map_site()
     */
	public static function render() { // phpcs:ignore -- NOSONAR -- complex.
        $dbwebsites     = array();
        $errors         = array();
        $form_submitted = false;

        if ( isset( $_POST['bulk_update_password_policy'] ) ) {
            $form_submitted = true;
            check_admin_referer( 'mainwp_password_policy_settings', 'security' );

            if ( ! isset( $_POST['mainwp_password_policy_window'] ) ) {
                $errors[] = esc_html__( 'Please select a password policy window.', 'mainwp' );
            } else {
                $policy_window = sanitize_text_field( wp_unslash( $_POST['mainwp_password_policy_window'] ) );
                $valid_windows = array( '0', '30', '60', '90', '120', '180', '360' );
                if ( ! in_array( $policy_window, $valid_windows, true ) ) {
                    $errors[] = esc_html__( 'Invalid password policy window selected.', 'mainwp' );
                }
            }

            $data_fields = MainWP_System_Utility::get_default_map_site_fields();

            if ( empty( $errors ) ) {
                $policy_window    = sanitize_text_field( wp_unslash( $_POST['mainwp_password_policy_window'] ) );
                $due_soon_message = isset( $_POST['mainwp_password_policy_due_soon_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mainwp_password_policy_due_soon_message'] ) ) : '';
                $overdue_message  = isset( $_POST['mainwp_password_policy_overdue_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mainwp_password_policy_overdue_message'] ) ) : '';
                $show_notices_to  = isset( $_POST['mainwp_password_policy_show_notices_to'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_password_policy_show_notices_to'] ) ) : 'edit_posts';

                if ( ! in_array( $show_notices_to, array( 'edit_posts', 'all_users' ), true ) ) {
                    $show_notices_to = 'edit_posts';
                }

                $settings = array(
                    'max_age_days'     => intval( $policy_window ),
                    'due_soon_message' => $due_soon_message,
                    'overdue_message'  => $overdue_message,
                    'show_notices_to'  => $show_notices_to,
                );
                update_option( 'mainwp_password_policy_settings', $settings );

                $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
                while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                    if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
                        continue;
                    }

                    $individual_settings_json = MainWP_DB::instance()->get_website_option( $website, 'password_policy_individual_settings' );
                    $individual_settings      = array();

                    if ( ! empty( $individual_settings_json ) ) {
                        $individual_settings = json_decode( $individual_settings_json, true );
                        if ( ! is_array( $individual_settings ) ) {
                            $individual_settings = array();
                        }
                    }

                    if ( ! empty( $individual_settings['overwrite_enabled'] ) ) {
                        continue;
                    }

                    $dbwebsites[ $website->id ] = MainWP_Utility::map_site(
                        $website,
                        $data_fields
                    );
                }
                MainWP_DB::free_result( $websites );

                if ( empty( $dbwebsites ) ) {
                    $errors[] = esc_html__( 'No eligible sites found. All sites have sync errors or are suspended.', 'mainwp' );
                }
            }
        }

        MainWP_User::render_header( 'PasswordPolicy' );
        static::render_form( $errors, $form_submitted, $dbwebsites );
        MainWP_User::render_footer( 'PasswordPolicy' );
    }

    /**
     * Renders password policy settings update results.
     *
     * @param object $dbwebsites The websites object.
     * @param object $output Result of password policy settings update.
     */
    public static function render_modal( $dbwebsites, $output ) {
        ?>
        <div class="ui modal" id="mainwp-password-policy-settings-modal">
        <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Password Policy Settings Updated', 'mainwp' ); ?></div>
            <div class="scrolling content">
                <?php
                /**
                 * Action: mainwp_password_policy_settings_modal_top
                 *
                 * Fires at the top of the Password Policy Settings modal.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_password_policy_settings_modal_top' );
                ?>
                <div class="ui relaxed divided list">
                    <?php foreach ( $dbwebsites as $website ) : ?>
                        <div class="item">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a>
                            <span class="right floated content">
                                <?php echo isset( $output->ok[ $website->id ] ) && 1 === (int) $output->ok[ $website->id ] ? '<i class="green check icon"></i>' : '<i class="red times icon"></i> ' . esc_html( $output->errors[ $website->id ] ); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php
                /**
                 * Action: mainwp_password_policy_settings_modal_bottom
                 *
                 * Fires at the bottom of the Password Policy Settings modal.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_password_policy_settings_modal_bottom' );
                ?>
            </div>
            <div class="actions">
            </div>
        </div>
        <script type="text/javascript">
            jQuery( '#mainwp-password-policy-settings-modal' ).modal( 'show' );
        </script>
        <?php
    }

    /**
     * Renders password policy settings form.
     *
     * @param array $errors         Array of error messages.
     * @param bool  $form_submitted Whether the form was submitted.
     * @param array $dbwebsites     Array of websites to update via AJAX.
     */
    public static function render_form( $errors = array(), $form_submitted = false, $dbwebsites = array() ) {
        $defaults = array(
            'max_age_days'     => 0,
            'due_soon_message' => __( 'Your password is due to be changed soon. Please update it as soon as possible. This helps keep your account secure.', 'mainwp' ),
            'overdue_message'  => __( 'Your password change is overdue. Please update your password now. This is required by your site\'s password policy.', 'mainwp' ),
            'show_notices_to'  => 'edit_posts',
        );
        $settings = wp_parse_args( get_option( 'mainwp_password_policy_settings', array() ), $defaults );
        ?>
        <div id="mainwp-password-policy-settings" class="ui padded segment">
            <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-password-policy-settings-info-message' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-password-policy-settings-info-message"></i>
                    <div><?php esc_html_e( 'WordPress does not store a reliable "last password change" date by default. MainWP starts tracking password changes from the moment the MainWP Child plugin is updated to this version. Until a user changes their password, their "Last password change" may show as Unknown.', 'mainwp' ); ?></div><br/>
                    <div><?php esc_html_e( 'When you enable a password policy (choose a period other than Never), MainWP measures compliance from the date the policy was enabled on each site. For users with an Unknown last-change date, notices are based on "no password change recorded since policy was enabled" (not on a historical password age).', 'mainwp' ); ?></div><br/>
                    <div><?php esc_html_e( 'Notices are shown only to logged-in users. Depending on your "Show notices to" setting, they appear in wp-admin (for users with `edit_posts` and above) and/or on the front end. Front-end notices are injected by the plugin and may be affected by theme layout, caching, or custom login/account flows.', 'mainwp' ); ?></div>
                </div>
            <?php endif; ?>
            <h2 class="ui header">
                <?php esc_html_e( 'Password Policy Settings', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Configure when users should be reminded to change their passwords on your connected sites. Tracking runs in the background; reminders are only shown when a policy is enabled.', 'mainwp' ); ?></div>
            </h2>
            <form action="" method="post" name="mainwp-password-policy-settings-form" id="mainwp-password-policy-settings-form" class="ui form">
                <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                <input type="hidden" name="security" value="<?php echo esc_attr( wp_create_nonce( 'mainwp_password_policy_settings' ) ); ?>"/>
                <?php if ( $form_submitted ) : ?>
                    <?php if ( empty( $errors ) ) : ?>
                        <div class="ui green message">
                            <i class="close icon"></i>
                            <?php esc_html_e( 'Password policy settings updated successfully.', 'mainwp' ); ?>
                        </div>
                    <?php else : ?>
                        <div class="ui red message">
                            <i class="close icon"></i>
                            <?php foreach ( $errors as $error ) : ?>
                                <div><?php echo esc_html( $error ); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Require password change every', 'mainwp' ); ?></label>
                    <div class="six wide column">
                        <select name="mainwp_password_policy_window" id="mainwp_password_policy_window" class="ui dropdown">
                            <option value="0" <?php selected( $settings['max_age_days'], 0 ); ?>><?php esc_html_e( 'Never (Default)', 'mainwp' ); ?></option>
                            <option value="30" <?php selected( $settings['max_age_days'], 30 ); ?>><?php esc_html_e( '30 days', 'mainwp' ); ?></option>
                            <option value="60" <?php selected( $settings['max_age_days'], 60 ); ?>><?php esc_html_e( '60 days', 'mainwp' ); ?></option>
                            <option value="90" <?php selected( $settings['max_age_days'], 90 ); ?>><?php esc_html_e( '90 days', 'mainwp' ); ?></option>
                            <option value="120" <?php selected( $settings['max_age_days'], 120 ); ?>><?php esc_html_e( '120 days', 'mainwp' ); ?></option>
                            <option value="180" <?php selected( $settings['max_age_days'], 180 ); ?>><?php esc_html_e( '180 days', 'mainwp' ); ?></option>
                            <option value="360" <?php selected( $settings['max_age_days'], 360 ); ?>><?php esc_html_e( '360 days', 'mainwp' ); ?></option>
                        </select>
                        <br/><span class="ui small grey text"><?php esc_html_e( 'Select how often users should be prompted to change their password. "Never" disables password policy reminders. When enabled, users will see a reminder 7 days before the deadline and an overdue notice after it.', 'mainwp' ); ?></span>
                    </div>
                </div>

                <div id="mainwp-password-policy-additional-settings" style="<?php echo ( 0 === intval( $settings['max_age_days'] ) ) ? 'display:none;' : ''; ?>">
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( '"Due soon" reminder message', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input
                                type="text"
                                name="mainwp_password_policy_due_soon_message"
                                id="mainwp_password_policy_due_soon_message"
                                value="<?php echo esc_attr( $settings['due_soon_message'] ); ?>"
                                class="ui fluid input">
                            <span class="ui small grey text"><?php esc_html_e( 'Shown to users 7 days before their password change is due.', 'mainwp' ); ?></span>
                        </div>
                    </div>

                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( '"Overdue" reminder message', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input
                                type="text"
                                name="mainwp_password_policy_overdue_message"
                                id="mainwp_password_policy_overdue_message"
                                value="<?php echo esc_attr( $settings['overdue_message'] ); ?>"
                                class="ui fluid input">
                            <span class="ui small grey text"><?php esc_html_e( 'Shown to users when their password change is overdue.', 'mainwp' ); ?></span>
                        </div>
                    </div>

                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Show notices to', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <select name="mainwp_password_policy_show_notices_to" id="mainwp_password_policy_show_notices_to" class="ui dropdown">
                                <option value="edit_posts" <?php selected( $settings['show_notices_to'], 'edit_posts' ); ?>><?php esc_html_e( 'Users with wp-admin access (edit_posts and above)', 'mainwp' ); ?></option>
                                <option value="all_users" <?php selected( $settings['show_notices_to'], 'all_users' ); ?>><?php esc_html_e( 'All users (all roles)', 'mainwp' ); ?></option>
                            </select>
                            <br/><span class="ui small grey text"><?php esc_html_e( 'Choose who receives password policy notices. "wp-admin access" targets roles that can edit posts (e.g., Authors, Editors, Administrators) and avoids showing notices to subscribers/customers on the front end.', 'mainwp' ); ?></span>
                        </div>
                    </div>
                </div>

                <div class="ui divider"></div>
                <input type="submit" name="bulk_update_password_policy" id="bulk_update_password_policy" class="ui green big button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
            </form>
        </div>

    <div class="ui modal" id="mainwp-password-policy-progress-modal">
        <i class="mainwp-modal-close close icon"></i>
        <div class="header"><?php esc_html_e( 'Updating Password Policy Settings', 'mainwp' ); ?></div>
        <div class="ui green progress mainwp-modal-progress">
            <div class="bar"><div class="progress"></div></div>
            <div class="label"></div>
        </div>
        <div class="scrolling content mainwp-modal-content">
            <div class="ui middle aligned divided list" id="mainwp-password-policy-sites-list">
            </div>
        </div>
    </div>

        <?php if ( ! empty( $dbwebsites ) ) : ?>
    <script type="text/javascript">
        jQuery(document).ready(function() {
            var passwordPolicySettings = <?php echo wp_json_encode( $settings ); ?>;
            var sitesToUpdate = <?php echo wp_json_encode( array_keys( $dbwebsites ) ); ?>;
            var sitesData = <?php echo wp_json_encode( $dbwebsites ); ?>;

            mainwp_password_policy_start_update(sitesToUpdate, sitesData, passwordPolicySettings);
        });
    </script>
    <?php endif; ?>
        <?php
    }

    /**
     * Renders individual site password policy settings form.
     *
     * @param object $website Website object.
     */
    public static function render_individual_form( $website ) {
        $global_defaults = array(
            'max_age_days'     => 0,
            'due_soon_message' => __( 'Your password is due to be changed soon. Please update it as soon as possible. This helps keep your account secure.', 'mainwp' ),
            'overdue_message'  => __( 'Your password change is overdue. Please update your password now. This is required by your site\'s password policy.', 'mainwp' ),
            'show_notices_to'  => 'edit_posts',
        );
        $global_settings = wp_parse_args( get_option( 'mainwp_password_policy_settings', array() ), $global_defaults );

        $individual_settings_json = MainWP_DB::instance()->get_website_option( $website, 'password_policy_individual_settings' );
        $individual_settings      = array();

        if ( ! empty( $individual_settings_json ) ) {
            $individual_settings = json_decode( $individual_settings_json, true );
            if ( ! is_array( $individual_settings ) ) {
                $individual_settings = array();
            }
        }

        $overwrite_enabled = ! empty( $individual_settings['overwrite_enabled'] );

        if ( $overwrite_enabled ) {
            $settings = wp_parse_args( $individual_settings, $global_defaults );
        } else {
            $settings = $global_settings;
        }
        ?>
        <div id="mainwp-individual-password-policy-settings" class="ui padded segment">
            <div id="individual-password-policy-status" class="ui message" style="display:none;"></div>

            <h2 class="ui header">
                <?php /* translators: %s: site name wrapped in HTML span */ printf( esc_html__( 'Password Policy Settings for %s', 'mainwp' ), '<span class="ui green text">' . esc_html( stripslashes( $website->name ) ) . '</span>' ); ?>
                <div class="sub header">
                    <?php esc_html_e( 'Configure password policy settings specific to this site. When disabled, this site will use the global password policy settings.', 'mainwp' ); ?> <?php /* translators: %s: number of days wrapped in HTML strong tag */ printf( esc_html__( 'Current global setting: Require password change every %s', 'mainwp' ), '<strong>' . ( 0 === intval( $global_settings['max_age_days'] ) ? esc_html__( 'Never', 'mainwp' ) : intval( $global_settings['max_age_days'] ) . ' ' . esc_html__( 'days', 'mainwp' ) ) . '</strong>' ); ?>
                </div>
            </h2>

            <div class="ui hidden divider"></div>

            <form id="mainwp-individual-password-policy-form" class="ui form">
                <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                <input type="hidden" name="site_id" value="<?php echo esc_attr( $website->id ); ?>"/>

                <div class="ui toggle checkbox field" id="overwrite-enabled-checkbox">
                    <input
                        type="checkbox"
                        name="overwrite_enabled"
                        id="overwrite_enabled"
                        <?php checked( $overwrite_enabled, true ); ?>>
                    <label for="overwrite_enabled">
                        <strong><?php esc_html_e( 'Overwrite global settings for this site', 'mainwp' ); ?></strong>
                    </label>
                </div>

                <div id="individual-settings-fields" style="<?php echo $overwrite_enabled ? '' : 'display:none;'; ?>">
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Require password change every', 'mainwp' ); ?></label>
                        <div class="six wide column">
                            <select name="max_age_days" id="individual_max_age_days" class="ui dropdown">
                                <option value="0" <?php selected( $settings['max_age_days'], 0 ); ?>><?php esc_html_e( 'Never (Default)', 'mainwp' ); ?></option>
                                <option value="30" <?php selected( $settings['max_age_days'], 30 ); ?>><?php esc_html_e( '30 days', 'mainwp' ); ?></option>
                                <option value="60" <?php selected( $settings['max_age_days'], 60 ); ?>><?php esc_html_e( '60 days', 'mainwp' ); ?></option>
                                <option value="90" <?php selected( $settings['max_age_days'], 90 ); ?>><?php esc_html_e( '90 days', 'mainwp' ); ?></option>
                                <option value="120" <?php selected( $settings['max_age_days'], 120 ); ?>><?php esc_html_e( '120 days', 'mainwp' ); ?></option>
                                <option value="180" <?php selected( $settings['max_age_days'], 180 ); ?>><?php esc_html_e( '180 days', 'mainwp' ); ?></option>
                                <option value="360" <?php selected( $settings['max_age_days'], 360 ); ?>><?php esc_html_e( '360 days', 'mainwp' ); ?></option>
                            </select>
                            <br/><span class="ui small grey text"><?php esc_html_e( 'Select how often users should be prompted to change their password.', 'mainwp' ); ?></span>
                        </div>
                    </div>

                    <div id="individual-additional-settings" style="<?php echo ( 0 === intval( $settings['max_age_days'] ) ) ? 'display:none;' : ''; ?>">
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( '"Due soon" reminder message', 'mainwp' ); ?></label>
                            <div class="ten wide column">
                                <input
                                    type="text"
                                    name="due_soon_message"
                                    id="individual_due_soon_message"
                                    value="<?php echo esc_attr( $settings['due_soon_message'] ); ?>"
                                    class="ui fluid input">
                                <span class="ui small grey text"><?php esc_html_e( 'Shown to users 7 days before their password change is due.', 'mainwp' ); ?></span>
                            </div>
                        </div>

                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( '"Overdue" reminder message', 'mainwp' ); ?></label>
                            <div class="ten wide column">
                                <input
                                    type="text"
                                    name="overdue_message"
                                    id="individual_overdue_message"
                                    value="<?php echo esc_attr( $settings['overdue_message'] ); ?>"
                                    class="ui fluid input">
                                <span class="ui small grey text"><?php esc_html_e( 'Shown to users when their password change is overdue.', 'mainwp' ); ?></span>
                            </div>
                        </div>

                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Show notices to', 'mainwp' ); ?></label>
                            <div class="ten wide column">
                                <select name="show_notices_to" id="individual_show_notices_to" class="ui dropdown">
                                    <option value="edit_posts" <?php selected( $settings['show_notices_to'], 'edit_posts' ); ?>><?php esc_html_e( 'Users with wp-admin access (edit_posts and above)', 'mainwp' ); ?></option>
                                    <option value="all_users" <?php selected( $settings['show_notices_to'], 'all_users' ); ?>><?php esc_html_e( 'All users (all roles)', 'mainwp' ); ?></option>
                                </select>
                                <br/><span class="ui small grey text"><?php esc_html_e( 'Choose who receives password policy notices.', 'mainwp' ); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="ui divider"></div>
                </div>

                <button type="button" id="save-individual-password-policy" class="ui green big button" style="<?php echo $overwrite_enabled ? '' : 'display:none;'; ?>">
                    <?php esc_html_e( 'Save Settings', 'mainwp' ); ?>
                </button>
            </form>
        </div>
        <?php
    }

    /**
     * Handle password policy settings response from child sites.
     *
     * @param mixed  $data     Response data from child site.
     * @param object $website  Website object.
     * @param object $output   Output object to store results.
     * @param array  $params   Additional parameters.
     */
	public static function posting_handler( $data, $website, &$output, $params = array() ) { // phpcs:ignore -- NOSONAR -- complex.
        if ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 ) {
            $decoded = base64_decode( $results[1] );

            if ( false === $decoded ) {
                $output->errors[ $website->id ] = esc_html__( 'Failed to decode response from child site.', 'mainwp' );
                return;
            }

            $information = @unserialize( $decoded );

            if ( false === $information && 'b:0;' !== $decoded ) {
                $output->errors[ $website->id ] = esc_html__( 'Child site does not support password policy settings. Please update MainWP Child plugin.', 'mainwp' );
                return;
            }

            if ( ( isset( $information['success'] ) && $information['success'] ) ||
                ( isset( $information['result'] ) && 'SUCCESS' === $information['result'] ) ) {
                $output->ok[ $website->id ] = 1;
            } else {
                $output->errors[ $website->id ] = isset( $information['error'] ) ? $information['error'] : esc_html__( 'Undefined error occurred. Please check child site.', 'mainwp' );
            }
        } else {
            $output->errors[ $website->id ] = esc_html__( 'Invalid response from child site.', 'mainwp' );
        }
    }

    /**
     * AJAX handler for saving individual site password policy settings.
     */
	public static function ajax_save_individual() { // phpcs:ignore -- NOSONAR -- complex.
        MainWP_Post_Handler::instance()->check_security( 'mainwp_password_policy_save_individual' );

        $site_id = isset( $_POST['site_id'] ) ? intval( $_POST['site_id'] ) : 0;

        if ( empty( $site_id ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid site ID', 'mainwp' ) ) );
        }

        $website = MainWP_DB::instance()->get_website_by_id( $site_id );

        if ( ! $website ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Site not found', 'mainwp' ) ) );
        }

        $overwrite_enabled = isset( $_POST['overwrite_enabled'] ) && '1' === $_POST['overwrite_enabled'];

        $settings = array(
            'overwrite_enabled' => $overwrite_enabled,
        );

        if ( $overwrite_enabled ) {
            $max_age_days  = isset( $_POST['max_age_days'] ) ? intval( $_POST['max_age_days'] ) : 0;
            $valid_windows = array( 0, 30, 60, 90, 120, 180, 360 );

            if ( ! in_array( $max_age_days, $valid_windows, true ) ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Invalid password policy window selected.', 'mainwp' ) ) );
            }

            $settings['max_age_days']     = $max_age_days;
            $settings['due_soon_message'] = isset( $_POST['due_soon_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['due_soon_message'] ) ) : '';
            $settings['overdue_message']  = isset( $_POST['overdue_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['overdue_message'] ) ) : '';
            $settings['show_notices_to']  = isset( $_POST['show_notices_to'] ) ? sanitize_text_field( wp_unslash( $_POST['show_notices_to'] ) ) : 'edit_posts';

            if ( ! in_array( $settings['show_notices_to'], array( 'edit_posts', 'all_users' ), true ) ) {
                $settings['show_notices_to'] = 'edit_posts';
            }

            MainWP_DB::instance()->update_website_option( $website, 'password_policy_individual_settings', wp_json_encode( $settings ) );

            if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
                wp_send_json_success(
                    array(
                        'message' => esc_html__( 'Settings saved to Dashboard. Site has sync errors or is suspended, so settings were not pushed to child site.', 'mainwp' ),
                    )
                );
            }

            $post_data = array(
                'action'           => 'update_password_policy',
                'max_age_days'     => $settings['max_age_days'],
                'due_soon_days'    => 7,
                'due_soon_message' => $settings['due_soon_message'],
                'overdue_message'  => $settings['overdue_message'],
                'show_notices_to'  => $settings['show_notices_to'],
            );

            try {
                $result = MainWP_Connect::fetch_url_authed(
                    $website,
                    'password_policy_settings',
                    $post_data
                );

                if ( empty( $result ) ) {
                    wp_send_json_error( array( 'message' => esc_html__( 'Settings saved to Dashboard but failed to push to child site. Empty response received.', 'mainwp' ) ) );
                }

                if ( is_array( $result ) ) {
                    if ( isset( $result['error'] ) ) {
                        wp_send_json_error( array( 'message' => esc_html__( 'Settings saved to Dashboard but failed to push to child site: ', 'mainwp' ) . $result['error'] ) );
                    }

                    if ( isset( $result['result'] ) && 'SUCCESS' === $result['result'] ) {
                        wp_send_json_success( array( 'message' => esc_html__( 'Individual password policy settings saved and pushed to child site successfully.', 'mainwp' ) ) );
                    }
                }

                if ( is_string( $result ) && preg_match( '/<mainwp>(.*)<\/mainwp>/', $result, $results ) > 0 ) {
                    $decoded = base64_decode( $results[1] );

                    if ( false !== $decoded ) {
                        $information = json_decode( $decoded, true );

                        if ( null !== $information ) {
                            if ( ( isset( $information['success'] ) && $information['success'] ) ||
                                ( isset( $information['result'] ) && 'SUCCESS' === $information['result'] ) ) {
                                wp_send_json_success( array( 'message' => esc_html__( 'Individual password policy settings saved and pushed to child site successfully.', 'mainwp' ) ) );
                            } else {
                                $error_msg = isset( $information['error'] ) ? $information['error'] : esc_html__( 'Undefined error occurred.', 'mainwp' );
                                wp_send_json_error( array( 'message' => esc_html__( 'Settings saved to Dashboard but failed to push to child site: ', 'mainwp' ) . $error_msg ) );
                            }
                        }
                    }
                }

                wp_send_json_error( array( 'message' => esc_html__( 'Settings saved to Dashboard but received invalid response from child site.', 'mainwp' ) ) );

            } catch ( \Exception $e ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Settings saved to Dashboard but exception occurred: ', 'mainwp' ) . $e->getMessage() ) );
            }
        } else {
            MainWP_DB::instance()->update_website_option( $website, 'password_policy_individual_settings', wp_json_encode( $settings ) );
            wp_send_json_success( array( 'message' => esc_html__( 'Individual settings disabled. Site will now use global password policy settings.', 'mainwp' ) ) );
        }
    }

    /**
     * AJAX handler for updating a single site's password policy settings.
     */
	public static function ajax_update_site() { // phpcs:ignore -- NOSONAR -- complexity.
        MainWP_Post_Handler::instance()->check_security( 'mainwp_password_policy_update_site' );

        $site_id = isset( $_POST['site_id'] ) ? intval( $_POST['site_id'] ) : 0;

        if ( empty( $site_id ) ) {
            wp_send_json( array( 'error' => esc_html__( 'Invalid site ID', 'mainwp' ) ) );
        }

        $website = MainWP_DB::instance()->get_website_by_id( $site_id );

        if ( ! $website ) {
            wp_send_json( array( 'error' => esc_html__( 'Site not found', 'mainwp' ) ) );
        }

        $individual_settings_json = MainWP_DB::instance()->get_website_option( $website, 'password_policy_individual_settings' );
        $individual_settings      = array();

        if ( ! empty( $individual_settings_json ) ) {
            $individual_settings = json_decode( $individual_settings_json, true );
            if ( ! is_array( $individual_settings ) ) {
                $individual_settings = array();
            }
        }

        if ( ! empty( $individual_settings['overwrite_enabled'] ) ) {
            wp_send_json(
                array(
                    'success' => true,
                    'skipped' => true,
                    'message' => esc_html__( 'Site is using individual password policy settings', 'mainwp' ),
                )
            );
        }

        $post_data = array(
            'action'           => 'update_password_policy',
            'max_age_days'     => isset( $_POST['max_age_days'] ) ? intval( $_POST['max_age_days'] ) : 0,
            'due_soon_days'    => isset( $_POST['due_soon_days'] ) ? intval( $_POST['due_soon_days'] ) : 7,
            'due_soon_message' => isset( $_POST['due_soon_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['due_soon_message'] ) ) : '',
            'overdue_message'  => isset( $_POST['overdue_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['overdue_message'] ) ) : '',
            'show_notices_to'  => isset( $_POST['show_notices_to'] ) ? sanitize_text_field( wp_unslash( $_POST['show_notices_to'] ) ) : 'edit_posts',
        );

        try {
            $website = MainWP_DB::instance()->get_website_by_id( $site_id );

            if ( ! $website ) {
                wp_send_json( array( 'error' => esc_html__( 'Site not found', 'mainwp' ) ) );
            }

            if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
                wp_send_json( array( 'error' => esc_html__( 'Site has sync errors or is suspended', 'mainwp' ) ) );
            }

            $result = MainWP_Connect::fetch_url_authed(
                $website,
                'password_policy_settings',
                $post_data
            );

            if ( empty( $result ) ) {
                wp_send_json( array( 'error' => esc_html__( 'Empty response from child site. Please check the child site connection.', 'mainwp' ) ) );
            }

            if ( is_array( $result ) ) {
                if ( isset( $result['error'] ) ) {
                    wp_send_json( array( 'error' => $result['error'] ) );
                }

                if ( isset( $result['result'] ) && 'SUCCESS' === $result['result'] ) {
                    wp_send_json( array( 'success' => true ) );
                }

                wp_send_json(
                    array(
                        'error' => esc_html__( 'Invalid response from child site.', 'mainwp' ),
                        'debug' => 'Response is array with keys: ' . implode( ', ', array_keys( $result ) ),
                    )
                );
            }

            if ( ! is_string( $result ) ) {
                wp_send_json(
                    array(
                        'error' => esc_html__( 'Invalid response type from child site.', 'mainwp' ),
                        'debug' => 'Response type: ' . gettype( $result ),
                    )
                );
            }

            if ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $result, $results ) > 0 ) {
                $decoded = base64_decode( $results[1] );

                if ( false === $decoded ) {
                    wp_send_json( array( 'error' => esc_html__( 'Failed to decode response from child site.', 'mainwp' ) ) );
                }

                $information = json_decode( $decoded, true );

                if ( null === $information ) {
                    wp_send_json( array( 'error' => esc_html__( 'Failed to parse response from child site.', 'mainwp' ) ) );
                }

                if ( ( isset( $information['success'] ) && $information['success'] ) ||
                    ( isset( $information['result'] ) && 'SUCCESS' === $information['result'] ) ) {
                    wp_send_json( array( 'success' => true ) );
                } else {
                    $error_msg = isset( $information['error'] ) ? $information['error'] : esc_html__( 'Undefined error occurred.', 'mainwp' );
                    wp_send_json( array( 'error' => $error_msg ) );
                }
            } else {
                $response_preview = strlen( $result ) > 200 ? substr( $result, 0, 200 ) . '...' : $result;
                wp_send_json(
                    array(
                        'error' => esc_html__( 'Invalid response from child site.', 'mainwp' ),
                        'debug' => 'Response preview: ' . esc_html( $response_preview ),
                    )
                );
            }
        } catch ( \Exception $e ) {
            wp_send_json( array( 'error' => 'Exception: ' . $e->getMessage() ) );
        } catch ( \Error $e ) {
            wp_send_json( array( 'error' => 'Error: ' . $e->getMessage() ) );
        }
    }
}
