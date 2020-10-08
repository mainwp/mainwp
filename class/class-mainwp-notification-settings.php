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
class MainWP_Notification_Settings {

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
	 * @return mixed self::$instance
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
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

			// to fix incorrect field name.
			if ( isset( $emails_settings['daily_digets'] ) ) {
				$emails_settings['daily_digest'] = $emails_settings['daily_digets'];
				unset( $emails_settings['daily_digets'] );
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

		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_dashboard_settings' ) ) {
			mainwp_do_not_have_permissions( __( 'manage dashboard settings', 'mainwp' ) );
			return;
		}

		$email_description   = '';
		$notification_emails = self::get_notification_types();
		$emails_settings     = get_option( 'mainwp_settings_notification_emails' );
		if ( ! is_array( $emails_settings ) ) {
			$emails_settings = array();
		}

		// to fix incorrect field name.
		if ( isset( $emails_settings['daily_digets'] ) ) {
			$emails_settings['daily_digest'] = $emails_settings['daily_digets'];
			unset( $emails_settings['daily_digets'] );
		}

		?>
		<div id="mainwp-all-emails-settings" class="ui segment">
		<?php if ( $updated ) : ?>
			<div class="ui green message"><i class="close icon"></i><?php esc_html_e( 'Settings have been saved successfully!', 'mainwp' ); ?></div>
			<?php endif; ?>
			<div class="ui info message">
				<?php _e( 'Email notifications sent from MainWP Dashboard are listed below. Click on an email to configure it. For additional help, please see <a href="https://kb.mainwp.com/docs/email-settings/">this help document</a>.', 'mainwp' ); ?>
			</div>
			<table class="ui single line table" id="mainwp-emails-settings-table">
				<thead>
					<tr>						
						<th class="collapsing"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Email', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Description', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Recipient(s)', 'mainwp' ); ?></th>						
						<th style="text-align:right"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $notification_emails as $type => $name ) : ?>
						<?php
						$options = isset( $emails_settings[ $type ] ) ? $emails_settings[ $type ] : array();
						$default = self::get_default_emails_fields( $type, '', true );
						$options = array_merge( $default, $options );

						$email_description = self::get_settings_desc( $type );
						?>
						<tr>
							<td><?php echo ( ! $options['disable'] ) ? '<span data-tooltip="Enabled." data-position="right center" data-inverted=""><i class="circular green check inverted icon"></i></span>' : '<span data-tooltip="Disabled." data-position="right center" data-inverted=""><i class="circular x icon inverted disabled"></i></span>'; ?></td>
							<td><a href="admin.php?page=SettingsEmail&edit-email=<?php echo rawurlencode( $type ); ?>" data-tooltip="<?php esc_attr_e( 'Click to configure the email.', 'mainwp' ); ?>" data-position="right center" data-inverted=""><?php echo esc_html( $name ); ?></a></td>
							<td><?php echo esc_html( $email_description ); ?></td>
							<td><?php echo esc_html( $options['recipients'] ); ?></td>
							<td style="text-align:right"><a href="admin.php?page=SettingsEmail&edit-email=<?php echo rawurlencode( $type ); ?>" data-tooltip="<?php esc_attr_e( 'Click to configure the email.', 'mainwp' ); ?>" data-position="left center" data-inverted="" class="ui green mini button"><?php esc_html_e( 'Manage', 'mainwp' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th class="collapsing"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Email', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Description', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Recipient(s)', 'mainwp' ); ?></th>
						<th></th>
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
			jQuery( document ).ready( function() {
				jQuery( '#mainwp-emails-settings-table' ).DataTable( {					
					"stateSave":  true,
					"paging":   false,
					"ordering": true,
					"columnDefs": [ { "orderable": false, "targets": [ 0, 3 ] } ],
					"order": [ [ 2, "asc" ] ]
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

		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_dashboard_settings' ) ) {
			mainwp_do_not_have_permissions( __( 'manage dashboard settings', 'mainwp' ) );
			return;
		}

		$emails_settings = get_option( 'mainwp_settings_notification_emails' );
		if ( ! is_array( $emails_settings ) ) {
			$emails_settings = array();
		}

		// to fix incorrect field name.
		if ( isset( $emails_settings['daily_digets'] ) ) {
			$emails_settings['daily_digest'] = $emails_settings['daily_digets'];
		}

		// to fix incorrect field name.
		if ( isset( $emails_settings['daily_digets'] ) ) {
			$emails_settings['daily_digest'] = $emails_settings['daily_digets'];
			unset( $emails_settings['daily_digets'] );
		}

		$options = isset( $emails_settings[ $type ] ) ? $emails_settings[ $type ] : array();

		$default = self::get_default_emails_fields( $type, '', true );
		$options = array_merge( $default, $options );

		$title = self::get_notification_types( $type );

		$email_description = self::get_settings_desc( $type );

		?>
		<div id="mainwp-emails-settings" class="ui segment">
			<?php self::render_update_template_message( $updated_templ ); ?>
			<div class="ui form">
				<form method="POST" action="admin.php?page=SettingsEmail">
					<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'SettingsEmail' ); ?>" />
					<input type="hidden" name="mainwp_setting_emails_type" value="<?php echo esc_html( $type ); ?>" />						
					<div class="ui info message"><?php _e( '<a href="https://mainwp.com/extension/boilerplate/" target="_blank">Boilerplate</a> and <a href="https://mainwp.com/extension/pro-reports/" target="_blank">Reports</a> extensions tokens are supported in the email settings and templates if extensions are in use.', 'mainwp' ); ?></div>
					<h3 class="ui header"><?php echo $title; ?></h3>
					<div class="sub header"><?php echo $email_description; ?></h3></div>
					<div class="ui divider"></div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Enable', 'mainwp' ); ?></label>
						<div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable this email notification.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
							<input type="checkbox" name="mainwp_settingEmails[<?php echo esc_html( $type ); ?>][disable]" id="mainwp_settingEmails[<?php echo esc_html( $type ); ?>][disable]" <?php echo ( 0 == $options['disable'] ) ? 'checked="true"' : ''; ?>/>
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Recipient(s)', 'mainwp' ); ?></label>
						<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'You can add multiple emails by separating them with comma.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
							<input type="text" name="mainwp_settingEmails[<?php echo esc_html( $type ); ?>][recipients]" id="mainwp_settingEmails[<?php echo esc_html( $type ); ?>][recipients]" value="<?php echo esc_html( $options['recipients'] ); ?>"/>
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Subject', 'mainwp' ); ?></label>
						<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Enter the email subject.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
							<input type="text" name="mainwp_settingEmails[<?php echo esc_html( $type ); ?>][subject]" id="mainwp_settingEmails[<?php echo esc_html( $type ); ?>][subject]" value="<?php echo esc_html( $options['subject'] ); ?>"/>
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Email heading', 'mainwp' ); ?></label>
						<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Enter the email heading.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
							<input type="text" name="mainwp_settingEmails[<?php echo esc_html( $type ); ?>][heading]" id="mainwp_settingEmails[<?php echo esc_html( $type ); ?>][heading]" value="<?php echo esc_html( $options['heading'] ); ?>"/>
						</div>
					</div>
					<div class="ui grid field" >				
						<label class="six wide column middle aligned"><?php esc_html_e( 'HTML template', 'mainwp' ); ?></label>
						<div class="ui ten wide column" data-tooltip="<?php esc_attr_e( 'Manage the email HTML template.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<?php
						$templ     = MainWP_Notification_Template::get_template_name_by_notification_type( $type );
						$overrided = MainWP_Notification_Template::instance()->is_overrided_template( $type );
						echo $overrided ? esc_html__( 'This template has been overridden and can be found in:', 'mainwp' ) . ' <code>wp-content/uploads/mainwp/templates/' . $templ . '</code>' : esc_html__( 'To override and edit this email template copy:', 'mainwp' ) . ' <code>mainwp/templates/' . $templ . '</code> ' . esc_html__( 'to the folder:', 'mainwp' ) . ' <code>wp-content/uploads/mainwp/templates/' . $templ . '</code>';
						?>
						</div>		
					</div>
					<div class="ui grid field" >				
						<label class="six wide column middle aligned"></label>
						<div class="ui ten wide column" data-tooltip="<?php esc_attr_e( 'Manage the email HTML template.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<?php
						if ( $overrided ) {
							?>
							<a href="<?php echo wp_nonce_url( 'admin.php?page=SettingsEmail&edit-email=' . $type, 'delete-email-template' ); ?>" onclick="mainwp_confirm('<?php echo esc_js( 'Are you sure you want to delete this template file?', 'mainwp' ); ?>', function(){ window.location = jQuery('a#email-delete-template').attr('href');}); return false;" id="email-delete-template" class="ui button"><?php esc_html_e( 'Delete Template', 'mainwp' ); ?></a>
							<?php
						} else {
							?>
							<a href="<?php echo wp_nonce_url( 'admin.php?page=SettingsEmail&edit-email=' . $type, 'copy-email-template' ); ?>" class="ui button"><?php esc_html_e( 'Copy file to uploads', 'mainwp' ); ?></a>
							<?php
						}
						?>
						<a href="javascript:void(0)" class="ui button" onclick="mainwp_view_template('<?php echo esc_js( $type ); ?>', true ); return false;"><?php esc_html_e( 'View Template', 'mainwp' ); ?></a>
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
		<?php if ( 1 == $updated ) : ?>
		<div class="ui message green"><i class="close icon"></i> <?php esc_html_e( 'Custom email template deleted successfully.', 'mainwp' ); ?></div>
		<?php endif; ?>
		<?php if ( 2 == $updated ) : ?>
		<div class="ui message green"><i class="close icon"></i> <?php esc_html_e( 'Email template copied successfully.', 'mainwp' ); ?></div>
		<?php endif; ?>
		<?php if ( 3 == $updated ) : ?>
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
			'daily_digest' => __( 'Daily Digest Email', 'mainwp' ),
			'uptime'       => __( 'Basic Uptime Monitoring Email', 'mainwp' ),
			'site_health'  => __( 'Site Health Monitoring Email', 'mainwp' ),
		);

		$enable_http_check = get_option( 'mainwp_check_http_response', 0 );

		if ( $enable_http_check ) {
			$types['http_check'] = __( 'After Updates HTTP Check Email', 'mainwp' );
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

		// to fix incorrect field name.
		if ( isset( $settings['daily_digets'] ) ) {
			$settings['daily_digest'] = $settings['daily_digets'];
			unset( $settings['daily_digets'] );
		}

		$options = isset( $settings[ $type ] ) ? $settings[ $type ] : array();
		$default = self::get_default_emails_fields( $type, '', true );
		return array_merge( $default, $options );
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
		$settings = json_decode( $website->settings_notification_emails, true );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// to fix incorrect field name.
		if ( isset( $settings['daily_digets'] ) ) {
			$settings['daily_digest'] = $settings['daily_digets'];
			unset( $settings['daily_digets'] );
		}

		$options = isset( $settings[ $type ] ) ? $settings[ $type ] : array();
		$default = self::get_default_emails_fields( $type );
		$options = array_merge( $default, $options );

		if ( preg_match( '/\[[^\]]+\]/is', $options['subject'] . $options['heading'], $matches ) ) {
			$tokens_values      = MainWP_System_Utility::get_tokens_site_values( $website );
			$options['subject'] = MainWP_System_Utility::replace_tokens_values( $options['subject'], $tokens_values );
			$options['heading'] = MainWP_System_Utility::replace_tokens_values( $options['heading'], $tokens_values );
		}

		if ( preg_match( '/\[[^\]]+\]/is', $options['recipients'] . $options['subject'] . $options['heading'], $matches ) ) {
			// support boilerplate and reports tokens.
			$fields  = array( 'recipients', 'subject', 'heading' );
			$options = self::replace_tokens_for_settings( $options, $fields, $website );
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
	 * @return string Email field settings value.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_notification_email()
	 */
	public static function get_default_emails_fields( $type, $field = '', $general = false ) {

		$important_noti = get_option( 'mainwp_setup_important_notification' );
		if ( false === $important_noti ) {
			$important_noti = get_option( 'mwp_setup_importantNotification' ); // going to outdated.
		}

		$recipients = MainWP_System_Utility::get_notification_email();
		$disable    = $general && $important_noti ? 0 : 1;

		$default_fields = array(
			'daily_digest' => array(
				'disable'    => $disable,
				'recipients' => $recipients,
				'subject'    => $general ? 'Daily Digest' : '[site.name] Daily Digest',
				'heading'    => $general ? 'Daily Digest' : '[site.name] Daily Digest',
			),
			'uptime'       => array(
				'disable'    => $disable,
				'recipients' => $recipients,
				'subject'    => $general ? 'Uptime Monitoring' : '[site.name] Uptime Monitoring',
				'heading'    => $general ? 'Uptime Monitoring' : '[site.name] Uptime Monitoring',
			),
			'site_health'  => array(
				'disable'    => $disable,
				'recipients' => $recipients,
				'subject'    => $general ? 'Site Health Monitoring' : '[site.name] Site Health Monitoring',
				'heading'    => $general ? 'Site Health Monitoring' : '[site.name] Site Health Monitoring',
			),
			'http_check'   => array(
				'disable'    => $disable,
				'recipients' => $recipients,
				'subject'    => 'HTTP response check',
				'heading'    => 'Sites Check',
			),
		);

		if ( ! empty( $type ) && ! empty( $field ) ) {
			return isset( $default_fields[ $type ] ) && isset( $default_fields[ $type ][ $field ] ) ? $default_fields[ $type ][ $field ] : '';
		}

		if ( ! empty( $type ) && empty( $field ) ) {
			return isset( $default_fields[ $type ] ) ? $default_fields[ $type ] : false;
		}

		return false;
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
	public static function replace_tokens_for_settings( $options, $fields = array(), $website ) {

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
