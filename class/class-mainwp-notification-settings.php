<?php
/**
 * MainWP Notification Settings
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Notification_Settings
 *
 * @package MainWP\Dashboard
 */
class MainWP_Notification_Settings { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.


    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null.
     */
    private static $instance = null;


    /**
     * Get class name.
     *
     * @return string Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Create a new self instance.
     *
     * @return mixed static::$instance
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * MainWP_Notification_Settings constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
    }

    /**
     * Manage general email settings.
     *
     * @return bool True if saved successfully, false if not.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public static function emails_general_settings_handle() {
        if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'SettingsEmail' ) ) {
            $emails_settings = get_option( 'mainwp_settings_notification_emails' );
            if ( ! is_array( $emails_settings ) ) {
                $emails_settings = array();
            }

            $type                       = isset( $_POST['mainwp_setting_emails_type'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_setting_emails_type'] ) ) : '';
            $update_settings            = isset( $_POST['mainwp_settingEmails'][ $type ] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_settingEmails'][ $type ] ) ) : '';
            $update_settings['disable'] = ( isset( $_POST['mainwp_settingEmails'][ $type ] ) && isset( $_POST['mainwp_settingEmails'][ $type ]['disable'] ) ) ? 0 : 1; // to set 'disable' values.
            $emails_settings[ $type ]   = $update_settings;

            /**
            * Action: mainwp_before_save_email_settings
            *
            * Fires before save email settings.
            *
            * @since 4.1
            */
            do_action( 'mainwp_before_save_email_settings', $type, $update_settings );

            MainWP_Utility::update_option( 'mainwp_settings_notification_emails', $emails_settings );

            /**
            * Action: mainwp_after_save_email_settings
            *
            * Fires after save email settings.
            *
            * @since 4.1
            */
            do_action( 'mainwp_after_save_email_settings', $emails_settings );

            return true;
        }
        return false;
    }

    /**
     * Render all email settings options.
     *
     * Credits.
     *
     * Plugin-Name: WooCommerce.
     * Plugin URI: https://woocommerce.com/.
     * Author: Automattic.
     * Author URI: https://woocommerce.com.
     * License: GPLv3 or later.
     *
     * @param bool $updated True if page loaded after update, false if not.
     */
    public function render_all_settings( $updated ) {

        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage dashboard settings', 'mainwp' ) );
            return;
        }

        $email_description   = '';
        $notification_emails = static::get_notification_types();
        $emails_settings     = get_option( 'mainwp_settings_notification_emails' );
        if ( ! is_array( $emails_settings ) ) {
            $emails_settings = array();
        }

        ?>
        <div id="mainwp-all-emails-settings" class="ui segment">
        <?php if ( $updated ) : ?>
            <div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'Settings have been saved successfully!', 'mainwp' ); ?></div>
            <?php endif; ?>
            <div class="ui info message">
                <?php printf( esc_html__( 'Email notifications sent from MainWP Dashboard are listed below.  Click on an email to configure it.  For additional help, please see %1$sthis help document%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/email-settings/">', '</a> <i class="external alternate icon"></i>' ); // NOSONAR - noopener - open safe. ?>
            </div>
            <table class="ui unstackable table" id="mainwp-emails-settings-table">
                <thead>
                    <tr>
                        <th scope="col" class="collapsing"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                        <th scope="col" data-priority="1"><?php esc_html_e( 'Email', 'mainwp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Description', 'mainwp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Recipient(s)', 'mainwp' ); ?></th>
                        <th scope="col" class="no-sort collapsing" data-priority="2" style="text-align:right">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $notification_emails as $type => $name ) : ?>
                        <?php
                        $options = isset( $emails_settings[ $type ] ) ? $emails_settings[ $type ] : array();
                        $default = static::get_default_emails_fields( $type, '', true );
                        $options = array_merge( $default, $options );

                        $email_description = static::get_settings_desc( $type );
                        ?>
                        <tr>
                            <td><?php echo ( ! $options['disable'] ) ? '<span data-tooltip="Enabled." data-position="right center" data-inverted=""><i class="circular green check inverted icon"></i></span>' : '<span data-tooltip="Disabled." data-position="right center" data-inverted=""><i class="circular x icon inverted disabled"></i></span>'; ?></td>
                            <td><a href="admin.php?page=SettingsEmail&edit-email=<?php echo esc_html( rawurlencode( $type ) ); ?>" data-tooltip="<?php esc_attr_e( 'Click to configure the email.', 'mainwp' ); ?>" data-position="right center" data-inverted=""><?php echo esc_html( $name ); ?></a></td>
                            <td><?php echo esc_html( $email_description ); ?></td>
                            <td><?php echo esc_html( $options['recipients'] ); ?></td>
                            <td style="text-align:right"><a href="admin.php?page=SettingsEmail&edit-email=<?php echo esc_html( rawurlencode( $type ) ); ?>" data-tooltip="<?php esc_attr_e( 'Click to configure the email.', 'mainwp' ); ?>" data-position="left center" data-inverted="" class="ui green mini button"><?php esc_html_e( 'Manage', 'mainwp' ); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="col" class="collapsing"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Email', 'mainwp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Description', 'mainwp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Recipient(s)', 'mainwp' ); ?></th>
                        <th scope="col">&nbsp;</th>
                    </tr>
                </tfoot>
            </table>
            <?php
            /**
             * Action: mainwp_settings_email_settings
             *
             * Fires after the default email settings.
             *
             * @since 4.1
             */
            do_action( 'mainwp_settings_email_settings' );
            ?>
            <script type="text/javascript">
            var responsive = true;
            if( jQuery( window ).width() > 1140 ) {
                responsive = false;
            }
            jQuery( document ).ready( function() {
                jQuery( '#mainwp-emails-settings-table' ).DataTable( {
                    "stateSave":  true,
                    "paging":   false,
                    "ordering": true,
                    "columnDefs": [ { "orderable": false, "targets": [ 4 ] } ],
                    "order": [ [ 2, "asc" ] ],
                    "responsive": responsive,
                } );
            } );
            </script>
        </div>
        <?php
    }

    /**
     * Render email settings page.
     *
     * Credits.
     *
     * Plugin-Name: WooCommerce.
     * Plugin URI: https://woocommerce.com/.
     * Author: Automattic.
     * Author URI: https://woocommerce.com.
     * License: GPLv3 or later.
     *
     * @param string $type          Email notification type.
     * @param bool   $updated_templ True if page loaded after update, false if not.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::render_edit_template()
     * @uses \MainWP\Dashboard\MainWP_Notification_Template::get_template_name_by_notification_type()
     * @uses \MainWP\Dashboard\MainWP_Notification_Template::is_overrided_template()
     */
    public function render_edit_settings( $type, $updated_templ ) {

        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage dashboard settings', 'mainwp' ) );
            return;
        }

        $emails_settings = get_option( 'mainwp_settings_notification_emails' );
        if ( ! is_array( $emails_settings ) ) {
            $emails_settings = array();
        }

        $options = isset( $emails_settings[ $type ] ) ? $emails_settings[ $type ] : array();

        $default = static::get_default_emails_fields( $type, '', true );
        $options = array_merge( $default, $options );

        $title = static::get_notification_types( $type );

        $email_description = static::get_settings_desc( $type );

        ?>
        <div id="mainwp-emails-settings" class="ui segment">
            <?php static::render_update_template_message( $updated_templ ); ?>
            <div class="ui form">
                <form method="POST" action="admin.php?page=SettingsEmail">
                    <input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'SettingsEmail' ) ); ?>" />
                    <input type="hidden" name="mainwp_setting_emails_type" value="<?php echo esc_html( $type ); ?>" />
                    <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-email-tokens-info-message' ) ) : ?>
                        <div class="ui info message">
                            <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-manage-updates-message"></i>
                            <?php printf( esc_html__( '%1$sBoilerplate%2$s and %3$sReports%4$s extensions tokens are supported in the email settings and templates if Extensions are in use.', 'mainwp' ), '<a href="https://mainwp.com/extension/boilerplate/" target="_blank">', '</a> <i class="external alternate icon"></i>', '<a href="https://mainwp.com/extension/pro-reports/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); // NOSONAR - noopener - open safe. ?>
                        </div>
                    <?php endif; ?>
                    <h3 class="ui header">
                    <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-email-' . esc_attr( $type ) ); ?>
                    <?php echo esc_html( $title ); ?></h3>
                    <div class="sub header"><?php echo esc_html( $email_description ); ?></h3></div>
                    <div class="ui divider"></div>
                    <?php
                    $def_val = MainWP_Settings_Indicator::get_defaults_email_settings_value( $type, 'disable' );
                    ?>
                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-email-<?php echo esc_attr( $type ); ?>" default-indi-value="<?php echo esc_attr( $def_val ); ?>">
                        <label class="six wide column middle aligned">
                        <?php
                        MainWP_Settings_Indicator::render_not_default_email_settings_indicator( $type, 'disable', (int) $options['disable'] );
                        esc_html_e( 'Enable', 'mainwp' );
                        ?>
                        </label>
                        <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable this email notification.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <input type="checkbox" inverted-value="1" class="settings-field-value-change-handler" name="mainwp_settingEmails[<?php echo esc_html( $type ); ?>][disable]" id="mainwp_settingEmails[<?php echo esc_html( $type ); ?>][disable]" <?php echo ( 0 === (int) $options['disable'] ) ? 'checked="true"' : ''; ?>/>
                        </div>
                    </div>
                    <?php
                    $def_val = MainWP_Settings_Indicator::get_defaults_email_settings_value( $type, 'recipients' );
                    ?>
                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-email-<?php echo esc_attr( $type ); ?>" default-indi-value="<?php echo esc_attr( $def_val ); ?>">
                        <label class="six wide column middle aligned">
                        <?php
                        MainWP_Settings_Indicator::render_not_default_email_settings_indicator( $type, 'recipients', $options['recipients'] );
                        esc_html_e( 'Recipient(s)', 'mainwp' );
                        ?>
                        </label>
                        <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'You can add multiple emails by separating them with comma.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <input type="text" class="settings-field-value-change-handler" name="mainwp_settingEmails[<?php echo esc_html( $type ); ?>][recipients]" id="mainwp_settingEmails[<?php echo esc_html( $type ); ?>][recipients]" value="<?php echo esc_html( $options['recipients'] ); ?>"/>
                        </div>
                    </div>
                    <?php
                    $def_val = MainWP_Settings_Indicator::get_defaults_email_settings_value( $type, 'subject' );
                    ?>
                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-email-<?php echo esc_attr( $type ); ?>" default-indi-value="<?php echo esc_attr( $def_val ); ?>">
                        <label class="six wide column middle aligned">
                        <?php
                        MainWP_Settings_Indicator::render_not_default_email_settings_indicator( $type, 'subject', $options['subject'] );
                        esc_html_e( 'Subject', 'mainwp' );
                        ?>
                        </label>
                        <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Enter the email subject.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <input type="text" class="settings-field-value-change-handler" name="mainwp_settingEmails[<?php echo esc_html( $type ); ?>][subject]" id="mainwp_settingEmails[<?php echo esc_html( $type ); ?>][subject]" value="<?php echo esc_html( $options['subject'] ); ?>"/>
                        </div>
                    </div>
                    <?php
                    $def_val = MainWP_Settings_Indicator::get_defaults_email_settings_value( $type, 'heading' );
                    ?>
                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-email-<?php echo esc_attr( $type ); ?>" default-indi-value="<?php echo esc_attr( $def_val ); ?>">
                        <label class="six wide column middle aligned">
                        <?php
                        MainWP_Settings_Indicator::render_not_default_email_settings_indicator( $type, 'heading', $options['heading'] );
                        esc_html_e( 'Email heading', 'mainwp' );
                        ?>
                        </label>
                        <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Enter the email heading.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <input type="text" class="settings-field-value-change-handler" name="mainwp_settingEmails[<?php echo esc_html( $type ); ?>][heading]" id="mainwp_settingEmails[<?php echo esc_html( $type ); ?>][heading]" value="<?php echo esc_html( $options['heading'] ); ?>"/>
                        </div>
                    </div>
                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-email-<?php echo esc_attr( $type ); ?>" >
                        <?php
                        $templ     = MainWP_Notification_Template::get_template_name_by_notification_type( $type );
                        $overrided = MainWP_Notification_Template::instance()->is_overrided_template( $type );
                        ?>
                        <label class="six wide column middle aligned">
                        <?php
                        MainWP_Settings_Indicator::render_not_default_email_settings_indicator( $type, 'overrided', (int) $overrided );
                        esc_html_e( 'HTML template', 'mainwp' );
                        ?>
                        </label>
                        <div class="ui ten wide column" data-tooltip="<?php esc_attr_e( 'Manage the email HTML template.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <?php
                        /**
                         *
                         * Use mainwp_notification_template_copy_message instead.
                         *
                         * @deprecated Since v4.3.
                         */
                        $copy_message = apply_filters( 'minwp_notification_template_copy_message', '', $templ, $type, $overrided );
                        /**
                         *
                         * Filter mainwp_notification_template_copy_message.
                         *
                         * @since v4.3
                         */
                        $copy_message = apply_filters( 'mainwp_notification_template_copy_message', $copy_message, $templ, $type, $overrided );
                        if ( empty( $copy_message ) ) {
                            $copy_message = $overrided ? esc_html__( 'This template has been overridden and can be found in:', 'mainwp' ) . ' <code>wp-content/uploads/mainwp/templates/' . esc_html( $templ ) . '</code>' : esc_html__( 'To override and edit this email template copy:', 'mainwp' ) . ' <code>mainwp/templates/' . esc_html( $templ ) . '</code> ' . esc_html__( 'to the folder:', 'mainwp' ) . ' <code>wp-content/uploads/mainwp/templates/' . esc_html( $templ ) . '</code>';
                        }
                        echo $copy_message; // phpcs:ignore WordPress.Security.EscapeOutput
                        ?>
                        </div>
                    </div>
                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-email-<?php echo esc_attr( $type ); ?>" >
                        <label class="six wide column middle aligned"></label>
                        <div class="ui ten wide column" data-tooltip="<?php esc_attr_e( 'Manage the email HTML template.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <?php if ( $overrided ) : ?>
                            <a href="<?php echo esc_url( wp_nonce_url( 'admin.php?page=SettingsEmail&edit-email=' . $type, 'delete-email-template' ) ); ?>" onclick="mainwp_confirm('<?php echo esc_js( 'Are you sure you want to delete this template file?', 'mainwp' ); ?>', function(){ window.location = jQuery('a#email-delete-template').attr('href');}); return false;" id="email-delete-template" class="ui button"><?php esc_html_e( 'Return to Default Template', 'mainwp' ); ?></a>
                        <?php else : ?>
                            <a href="<?php echo esc_url( wp_nonce_url( 'admin.php?page=SettingsEmail&edit-email=' . $type, 'copy-email-template' ) ); ?>" class="ui button"><?php esc_html_e( 'Copy file to uploads', 'mainwp' ); ?></a>
                        <?php endif; ?>
                        <?php if ( $overrided ) : ?>
                            <a href="javascript:void(0)" class="ui button" onclick="mainwp_view_template('<?php echo esc_js( $type ); ?>', true ); return false;"><?php esc_html_e( 'Edit Template', 'mainwp' ); ?></a>
                        <?php else : ?>
                        <a href="javascript:void(0)" class="ui button" onclick="mainwp_view_template('<?php echo esc_js( $type ); ?>', true ); return false;"><?php esc_html_e( 'View Template', 'mainwp' ); ?></a>
                        <?php endif; ?>
                        </div>
                    </div>
                    <div class="ui divider"></div>
                    <a href="admin.php?page=SettingsEmail" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>
                    <input type="submit" name="submit" id="submit" class="ui green big button right floated" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
                    <div style="clear:both"></div>
                    </form>
            </div>
        </div>
        <?php MainWP_Manage_Sites_View::render_edit_template( $type ); ?>
        <?php
    }


    /**
     * Get email settings description.
     *
     * @param string $type Email notification type.
     *
     * @return string $email_description Email settings Description.
     */
    public static function get_settings_desc( $type ) {
        $email_description = '';
        if ( 'daily_digest' === $type ) {
            $email_description = esc_html__( 'Daily notification about available updates and disconnected sites.', 'mainwp' );
        } elseif ( 'uptime' === $type ) {
            $email_description = esc_html__( 'Alert if any of your websites is down.', 'mainwp' );
        } elseif ( 'site_health' === $type ) {
            $email_description = esc_html__( 'Alert if any of your websites site health goes under the threshold.', 'mainwp' );
        } elseif ( 'http_check' === $type ) {
            $email_description = esc_html__( 'Alert if any of your websites return unexpected HTTP status after running updates.', 'mainwp' );
        } elseif ( 'deactivated_license_alert' === $type ) {
            $email_description = esc_html__( 'Receive a notification when an extension\'s license is deactivated.', 'mainwp' );
        }

        $addition_desc = apply_filters( 'mainwp_notification_type_desc', '', $type );
        if ( ! empty( $addition_desc ) ) {
            $email_description = $addition_desc;
        }

        return $email_description;
    }

    /**
     * Render the notification after the update process.
     *
     * @param int $updated Update code (copied, saved or deleted).
     */
    public static function render_update_template_message( $updated ) {
        ?>
        <?php if ( 1 === (int) $updated ) : ?>
        <div class="ui message green"><i class="close icon"></i> <?php esc_html_e( 'Custom email template deleted successfully.', 'mainwp' ); ?></div>
        <?php endif; ?>
        <?php if ( 2 === (int) $updated ) : ?>
        <div class="ui message green"><i class="close icon"></i> <?php esc_html_e( 'Email template copied successfully.', 'mainwp' ); ?></div>
        <?php endif; ?>
        <?php if ( 3 === (int) $updated ) : ?>
        <div class="ui message green"><i class="close icon"></i> <?php esc_html_e( 'Email template updated successfully.', 'mainwp' ); ?></div>
        <?php endif; ?>
        <?php
    }

    /**
     * Get email notification types.
     *
     * @param string $type Email notification type.
     *
     * @return mixed Notification types.
     */
    public static function get_notification_types( $type = '' ) {
        $types = array(
            'daily_digest'              => esc_html__( 'Daily Digest Email', 'mainwp' ),
            'uptime'                    => esc_html__( 'Uptime Monitoring Email', 'mainwp' ),
            'site_health'               => esc_html__( 'Site Health Monitoring Email', 'mainwp' ),
            'deactivated_license_alert' => esc_html__( 'Extension License Deactivation Notification Email', 'mainwp' ),
        );

        $enable_http_check = get_option( 'mainwp_check_http_response', 0 );

        if ( $enable_http_check ) {
            $types['http_check'] = esc_html__( 'After Updates HTTP Check Email', 'mainwp' );
        }

        $addition_types = apply_filters( 'mainwp_notification_types', array(), $type );
        if ( ! empty( $addition_types ) ) {
            $types = array_merge( $types, $addition_types );
        }

        if ( empty( $type ) ) {
            return $types;
        } else {
            return ( isset( $types[ $type ] ) ) ? $types[ $type ] : false;
        }
    }

    /**
     * Get general email notifications settings.
     *
     * @param string $type Email type.
     *
     * @see function get_notification_types()
     *
     * @return array An array containing the email settings.
     */
    public static function get_general_email_settings( $type ) {
        $settings = get_option( 'mainwp_settings_notification_emails', array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $options = isset( $settings[ $type ] ) ? $settings[ $type ] : array();
        $default = static::get_default_emails_fields( $type, '', true );
        return array_merge( $default, $options );
    }

    /**
     * Get general notification email.
     *
     * @return string|empty recipients.
     */
    public static function get_general_email() {
        $gen_email_settings = static::get_general_email_settings( 'daily_digest' );
        return isset( $gen_email_settings['recipients'] ) ? $gen_email_settings['recipients'] : '';
    }

    /**
     * Update general notification email.
     *
     * @param string $emails Emails.
     *
     * @return void
     */
    public static function update_general_email( $emails ) {
        $emails_settings = get_option( 'mainwp_settings_notification_emails' );
        if ( ! is_array( $emails_settings ) ) {
            $emails_settings = array();
        }
        $type                          = 'daily_digest';
        $update_settings               = isset( $emails_settings[ $type ] ) ? $emails_settings[ $type ] : array();
        $update_settings['recipients'] = $emails;
        $emails_settings[ $type ]      = $update_settings;
        MainWP_Utility::update_option( 'mainwp_settings_notification_emails', $emails_settings );
    }

    /**
     * Get email settings for a single site.
     *
     * @param string $type    Email type.
     * @param object $website Object containing the child site information.
     *
     * @return array An array containing the email settings.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_tokens_site_values()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::replace_tokens_values()
     */
    public static function get_site_email_settings( $type, $website ) {
        if ( empty( $website ) || ! property_exists( $website, 'settings_notification_emails' ) ) {
            return array( 'disable' => 1 );
        }
        $settings = array();
        if ( ! empty( $website->settings_notification_emails ) ) {
            $settings = json_decode( $website->settings_notification_emails, true );
        }

        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $options = isset( $settings[ $type ] ) ? $settings[ $type ] : array();
        $default = static::get_default_emails_fields( $type );
        $options = array_merge( $default, $options );

        if ( preg_match( '/\[[^\]]+\]/is', $options['subject'] . $options['heading'], $matches ) ) {
            $tokens_values      = MainWP_System_Utility::get_tokens_site_values( $website );
            $options['subject'] = MainWP_System_Utility::replace_tokens_values( $options['subject'], $tokens_values );
            $options['heading'] = MainWP_System_Utility::replace_tokens_values( $options['heading'], $tokens_values );
        }

        if ( preg_match( '/\[[^\]]+\]/is', $options['recipients'] . $options['subject'] . $options['heading'], $matches ) ) {
            // support boilerplate and reports tokens.
            $fields  = array( 'recipients', 'subject', 'heading' );
            $options = static::replace_tokens_for_settings( $options, $fields, $website );
        }

        return $options;
    }

    /**
     * Get default email notifications values.
     *
     * @param string $type    Email type.
     * @param string $field   Field name.
     * @param bool   $general General or individual site settings.
     *
     * @return array|string Email field settings value.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_notification_email()
     */
    public static function get_default_emails_fields( $type, $field = '', $general = false ) { // phpcs:ignore -- NOSONAR - complex.

        $recipients = MainWP_System_Utility::get_notification_email();
        $disable    = $general ? 0 : 1;

        $default_fields = array(
            'daily_digest'              => array(
                'disable'    => $disable,
                'recipients' => $recipients,
                'subject'    => $general ? 'Daily Digest' : '[site.name] Daily Digest',
                'heading'    => $general ? 'Daily Digest' : '[site.name] Daily Digest',
            ),
            'uptime'                    => array(
                'disable'    => $disable,
                'recipients' => $recipients,
                'subject'    => $general ? 'Uptime Monitoring' : '[site.name] Uptime Monitoring',
                'heading'    => $general ? 'Uptime Monitoring' : '[site.name] Uptime Monitoring',
            ),
            'site_health'               => array(
                'disable'    => $disable,
                'recipients' => $recipients,
                'subject'    => $general ? 'Site Health Monitoring' : '[site.name] Site Health Monitoring',
                'heading'    => $general ? 'Site Health Monitoring' : '[site.name] Site Health Monitoring',
            ),
            'http_check'                => array(
                'disable'    => $disable,
                'recipients' => $recipients,
                'subject'    => 'HTTP response check',
                'heading'    => 'Sites Check',
            ),
            'deactivated_license_alert' => array(
                'disable'    => $disable,
                'recipients' => $recipients,
                'subject'    => 'Extension License Deactivation Notification',
                'heading'    => 'Extension License Deactivation Notification',
            ),
        );

        $addition_default_fields = apply_filters( 'mainwp_default_emails_fields', array(), $recipients, $type, $field, $general );
        if ( ! empty( $addition_default_fields ) ) {
            $default_fields = array_merge( $default_fields, $addition_default_fields );
        }

        if ( ! empty( $type ) && ! empty( $field ) ) {
            return isset( $default_fields[ $type ] ) && isset( $default_fields[ $type ][ $field ] ) ? $default_fields[ $type ][ $field ] : '';
        }

        if ( ! empty( $type ) && empty( $field ) ) {
            return isset( $default_fields[ $type ] ) ? $default_fields[ $type ] : array();
        }

        return array();
    }

    /**
     * Replace site tokens for settings.
     *
     * @param array  $options array of fields to find and replace tokens.
     * @param array  $fields fields names to find.
     * @param object $website The website.
     *
     * @return array $options array of fields.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::replace_tokens_values()
     */
    public static function replace_tokens_for_settings( $options, $fields, $website ) { // phpcs:ignore -- NOSONAR - complex.

        /**
         * Filter: mainwp_boilerplate_get_tokens
         *
         * Enables and filters the Boilerplate extension tokens.
         *
         * @param object $website Object containing the child site data.
         *
         * @since 4.1
         */
        $boilerplate_tokens = apply_filters( 'mainwp_boilerplate_get_tokens', false, $website );

        if ( is_array( $boilerplate_tokens ) ) {
            foreach ( $fields as $field ) {
                if ( isset( $options[ $field ] ) ) {
                    $options[ $field ] = MainWP_System_Utility::replace_tokens_values( $options[ $field ], $boilerplate_tokens );
                }
            }
        }

        /**
         * Filter: mainwp_pro_reports_get_site_tokens
         *
         * Enables and filters the Pro Reports extension tokens.
         *
         * @param object $website Object containing the child site data.
         *
         * @since 4.1
         */
        $report_tokens = apply_filters( 'mainwp_pro_reports_get_site_tokens', false, $website->id );

        if ( is_array( $report_tokens ) ) {
            foreach ( $fields as $field ) {
                if ( isset( $options[ $field ] ) ) {
                    $options[ $field ] = MainWP_System_Utility::replace_tokens_values( $options[ $field ], $report_tokens );
                }
            }
        }

        /**
         * Filter: mainwp_client_report_get_site_tokens
         *
         * Enables and filters the Client Reports extension tokens.
         *
         * @param object $website Object containing the child site data.
         *
         * @since 4.1
         */
        $client_report_tokens = apply_filters( 'mainwp_client_report_get_site_tokens', false, $website->id );
        if ( is_array( $client_report_tokens ) ) {
            foreach ( $fields as $field ) {
                if ( isset( $options[ $field ] ) ) {
                    $options[ $field ] = MainWP_System_Utility::replace_tokens_values( $options[ $field ], $client_report_tokens );
                }
            }
        }

        return $options;
    }

    /**
     * Replace site tokens for content.
     *
     * @param string $content content to find and replace tokens.
     * @param object $website The website.
     *
     * @return string $content after replaced tokens.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_tokens_site_values()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::replace_tokens_values()
     */
    public static function replace_tokens_for_content( $content, $website ) {

        $tokens_values = MainWP_System_Utility::get_tokens_site_values( $website );

        $content = MainWP_System_Utility::replace_tokens_values( $content, $tokens_values );

        // if tokens existed.
        if ( preg_match( '/\[[^\]]+\]/is', $content, $matches ) ) {

            /** This filter is documented in ../class/class-mainwp-notification-settings.php */
            $boilerplate_tokens = apply_filters( 'mainwp_boilerplate_get_tokens', false, $website );

            if ( is_array( $boilerplate_tokens ) ) {
                $content = MainWP_System_Utility::replace_tokens_values( $content, $boilerplate_tokens );
            }

            /** This filter is documented in ../class/class-mainwp-notification-settings.php */
            $report_tokens = apply_filters( 'mainwp_pro_reports_get_site_tokens', false, $website->id );

            if ( is_array( $report_tokens ) ) {
                $content = MainWP_System_Utility::replace_tokens_values( $content, $report_tokens );
            }

            /** This filter is documented in ../class/class-mainwp-notification-settings.php */
            $client_report_tokens = apply_filters( 'mainwp_client_report_get_site_tokens', false, $website->id );

            if ( is_array( $client_report_tokens ) ) {
                $content = MainWP_System_Utility::replace_tokens_values( $content, $client_report_tokens );
            }
        }

        return $content;
    }
}
