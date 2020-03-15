<?php
/*
 * Copyright
 * Plugin: WooCommerce
 * Plugin URI: http://www.woothemes.com/woocommerce/
 * Description: An e-commerce toolkit that helps you sell anything. Beautifully.
 * Version: 2.4.4
 * Author: WooThemes
 * Author URI: http://woothemes.com
 */


 /**
  * MainWP Setup Wizard
  */
class MainWP_Setup_Wizard {

	private $step  = '';
	private $steps = array();
	// private $backup_extensions         = array();
	// private $uptime_robot_api_url  = 'https://api.uptimerobot.com/v2';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menus' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ), 999 );
	}

	public static function init() {
		// add_action( 'wp_ajax_mainwp_setup_extension_getextension', array( 'MainWP_Setup_Wizard', 'ajax_get_backup_extension' ) );
		// add_action( 'wp_ajax_mainwp_setup_extension_downloadandinstall', array( 'MainWP_Setup_Wizard', 'ajax_download_and_install' ) );
		// add_action( 'wp_ajax_mainwp_setup_extension_grabapikey', array( 'MainWP_Setup_Wizard', 'ajax_grab_api_key' ) );
		// add_action( 'wp_ajax_mainwp_setup_extension_activate_plugin', array( 'MainWP_Setup_Wizard', 'ajax_activate_plugin' ) );
	}

	public function admin_menus() {
		add_dashboard_page( '', '', 'manage_options', 'mainwp-setup', '' );
	}

	public function admin_init() {
		if ( empty( $_GET['page'] ) || 'mainwp-setup' !== $_GET['page'] ) {
			return;
		}
		$this->steps = array(
			'introduction' => array(
				'name'       => __( 'Introduction', 'mainwp' ),
				'view'       => array( $this, 'mwp_setup_introduction' ),
				'handler'    => '',
			),
			'installation' => array(
				'name'       => __( 'Installation', 'mainwp' ),
				'view'       => array( $this, 'mwp_setup_installation' ),
				'handler'    => array( $this, 'mwp_setup_installation_save' ),
			),
			'system_check' => array(
				'name'       => __( 'System', 'mainwp' ),
				'view'       => array( $this, 'mwp_setup_system_requirements' ),
				'handler'    => '',
			),
			'install_mainwp_child' => array(
				'name'       => __( 'Install MainWP Child', 'mainwp' ),
				'view'       => array( $this, 'mwp_setup_install_mainwp_child' ),
				'handler'    => '',
			),
			'connect_first_site' => array(
				'name'       => __( 'Connect First Site', 'mainwp' ),
				'view'       => array( $this, 'mwp_setup_connect_first_site' ),
				'handler'    => '',
			),
			// 'hosting_setup'        => array(
			// 'name'       => __( 'Performance', 'mainwp' ),
			// 'view'       => array( $this, 'mwp_setup_hosting' ),
			// 'handler'    => array( $this, 'mwp_setup_hosting_save' )
			// ),
			'optimization' => array(
				'name'       => __( 'Optimization', 'mainwp' ),
				'view'       => array( $this, 'mwp_setup_optimization' ),
				'handler'    => array( $this, 'mwp_setup_optimization_save' ),
			),
			'notification' => array(
				'name'       => __( 'Notifications', 'mainwp' ),
				'view'       => array( $this, 'mwp_setup_notification' ),
				'handler'    => array( $this, 'mwp_setup_notification_save' ),
			),
			// 'backup'           => array(
			// 'name'       => __( 'Backups', 'mainwp' ),
			// 'view'       => array( $this, 'mwp_setup_backup' ),
			// 'handler'    => array( $this, 'mwp_setup_backup_save' )
			// ),
			// 'install_extension' => array(
			// 'name'    =>  __( 'Backups', 'mainwp' ),
			// 'view'    => array( $this, 'mwp_setup_install_extension' ),
			// 'handler' => array( $this, 'mwp_setup_install_extension_save' ),
			// 'hidden' => true
			// ),
			// 'uptime_robot'         => array(
			// 'name'       => __( 'WP Cron', 'mainwp' ),
			// 'view'       => array( $this, 'mwp_setup_uptime_robot' ),
			// 'handler'    => array( $this, 'mwp_setup_uptime_robot_save' ),
			// ),
			'next_steps' => array(
				'name'       => __( 'Finish', 'mainwp' ),
				'view'       => array( $this, 'mwp_setup_ready' ),
				'handler'    => '',
			),
		);

		// $this->backup_extensions = array(
		// 'updraftplus'    => array(
		// 'name'           => 'MainWP UpdraftPlus Extension',
		// 'product_id' => 'MainWP UpdraftPlus Extension',
		// 'slug'           => 'mainwp-updraftplus-extension/mainwp-updraftplus-extension.php' ),
		// 'backupwp'       => array(
		// 'name'           => 'MainWP BackUpWordPress Extension',
		// 'product_id' => 'MainWP BackUpWordPress Extension',
		// 'slug'           => 'mainwp-backupwordpress-extension/mainwp-backupwordpress-extension.php' ),
		// 'backwpup'       => array(
		// 'name'           => 'MainWP BackWPup Extension',
		// 'product_id' => 'MainWP BackWPup Extension',
		// 'slug'           => 'mainwp-backwpup-extension/mainwp-backwpup-extension.php' )
		// );

		$this->step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : current( array_keys( $this->steps ) );
		// $this->check_redirect();

		wp_enqueue_script( 'mainwp-setup', MAINWP_PLUGIN_URL . 'assets/js/mainwp-setup.js', array( 'jquery' ), MAINWP_VERSION );
		wp_localize_script( 'mainwp-setup', 'mainwpSetupLocalize', array( 'nonce' => wp_create_nonce( 'MainWPSetup' ) ) );
		wp_enqueue_script( 'semantic', MAINWP_PLUGIN_URL . 'assets/js/semantic-ui/semantic.min.js', array( 'jquery' ), MAINWP_VERSION );
		wp_enqueue_style( 'mainwp', MAINWP_PLUGIN_URL . 'assets/css/mainwp.css', array(), MAINWP_VERSION );
		wp_enqueue_style( 'semantic', MAINWP_PLUGIN_URL . 'assets/js/semantic-ui/semantic.min.css', array(), MAINWP_VERSION );

		if ( ! empty( $_POST['save_step'] ) && isset( $this->steps[ $this->step ]['handler'] ) ) {
			call_user_func( $this->steps[ $this->step ]['handler'] );
		}

		ob_start();

		$this->setup_wizard_header();
		$this->setup_wizard_steps();
		$this->setup_wizard_content();
		$this->setup_wizard_footer();
		exit;
	}

	public function get_next_step_link( $step = '' ) {
		if ( ! empty( $step ) && isset( $step, $this->steps ) ) {
			return esc_url_raw( remove_query_arg( 'noregister', add_query_arg( 'step', $step ) ) );
		}
		$keys = array_keys( $this->steps );
		return esc_url_raw( remove_query_arg( 'noregister', add_query_arg( 'step', $keys[ array_search( $this->step, array_keys( $this->steps ) ) + 1 ] ) ) );
	}

	public function get_back_step_link( $step = '' ) {
		if ( ! empty( $step ) && isset( $step, $this->steps ) ) {
			return esc_url_raw( remove_query_arg( 'noregister', add_query_arg( 'step', $step ) ) );
		}
		$keys = array_keys( $this->steps );
		return esc_url_raw( remove_query_arg( 'noregister', add_query_arg( 'step', $keys[ array_search( $this->step, array_keys( $this->steps ) ) - 1 ] ) ) );
	}

	public function setup_wizard_header() {
		?>
		<!DOCTYPE html>
		<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?> class="mainwp-quick-setup">
			<head>
				<meta name="viewport" content="width=device-width" />
				<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
				<title><?php _e( 'MainWP &rsaquo; Setup Wizard', 'mainwp' ); ?></title>
				<?php wp_print_scripts( 'mainwp-setup' ); ?>
				<?php wp_print_scripts( 'semantic' ); ?>
				<?php do_action( 'admin_print_styles' ); ?>
				<script type="text/javascript"> var ajaxurl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';</script>
			</head>
			<body>
				<div class="ui hidden divider"></div>
				<div class="ui hidden divider"></div>
				<div id="mainwp-quick-setup-wizard" class="ui padded container segment">
				<?php
	}

	public function setup_wizard_footer() {
		?>
				</div>
				<div class="ui grid">
					<div class="row">
						<div class="center aligned column">
							<a class="" href="<?php echo esc_url( admin_url( 'index.php' ) ); ?>"><?php _e( 'Quit MainWP Quick Setup Wizard and Go to WP Admin', 'mainwp' ); ?></a> | <a class="" href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp_tab' ) ); ?>"><?php _e( 'Quit MainWP Quick Setup Wizard and Go to MainWP', 'mainwp' ); ?></a>
						</div>
					</div>
		  <?php if ( MainWP_UI::usersnap_integration() ) { ?>
		  <div class="row" style="position:fixed !important; bottom: 10px;">
			<div class="right aligned column">
			<a class="ui button black icon" id="usersnap-bug-report-button" data-position="top right" data-inverted="" data-tooltip="<?php esc_html_e( 'Click here (or use Ctrl + U keyboard shortcut) to open the Bug reporting mode.', 'mainwp' ); ?>" target="_blank" href="#">
			  <i class="bug icon"></i>
			</a>
			</div>
		  </div>
		  <?php } ?>
				</div>
			</body>
		</html>
		<?php
	}

	public function setup_wizard_steps() {
		$ouput_steps = $this->steps;
		array_shift( $ouput_steps );
		?>
		<div id="mainwp-quick-setup-wizard-steps" class="ui tablet stackable ordered vertical steps" style="float: left; margin-right: 3em;">
			<?php foreach ( $ouput_steps as $step_key => $step ) : ?>
				<?php
				if ( isset( $step['hidden'] ) && $step['hidden'] ) {
					continue;}
				?>
				<div class="step 
				<?php
				if ( $step_key === $this->step ) {
					echo 'active';
				} elseif ( array_search( $this->step, array_keys( $this->steps ) ) > array_search( $step_key, array_keys( $this->steps ) ) ) {
					echo 'completed'; }
				?>
				">
					<div class="content">
						<div class="title"><?php echo esc_html( $step['name'] ); ?></div>

					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public function setup_wizard_content() {
		echo '<div class="mainwp-quick-setup-wizard-steps-content" style="float:right; width:72%;">';
		call_user_func( $this->steps[ $this->step ]['view'] );
		echo '</div>';
		echo '<div class="ui clearing hidden divider"></div>';
	}

	public function mwp_setup_introduction() {
		$this->mwp_setup_ready_actions();
		?>
		<h1 class="ui header"><?php _e( 'MainWP Quick Setup Wizard', 'mainwp' ); ?></h1>
		<p><?php _e( 'Thank you for choosing MainWP for managing your WordPress sites. This quick setup wizard will help you configure the basic settings. It\'s completely optional and shouldn\'t take longer than five minutes.', 'mainwp' ); ?></p>
		<p><?php _e( 'If you don\'t want to go through the setup wizard, you can skip and proceed to your MainWP Dashboard by clicking the "Not right now" button. If you change your mind, you can come back later by starting the Setup Wizard from the MainWP > Settings > MainWP Tools page! ', 'mainwp' ); ?></p>
		<p><?php _e( 'To go back to the WordPress Admin section, click the "Back to WP Admin" button.', 'mainwp' ); ?></p>
		<div class="ui hidden divider"></div>
		<div class="ui hidden divider"></div>
		<div class="ui hidden divider"></div>
		<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big green right floated button"><?php _e( 'Let\'s Go!', 'mainwp' ); ?></a>
		<a href="<?php echo esc_url( admin_url( 'index.php' ) ); ?>" class="ui big button"><?php _e( 'Back to WP Admin', 'mainwp' ); ?></a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&do=new' ) ); ?>" class="ui big button"><?php _e( 'Not Right Now', 'mainwp' ); ?></a>
		<?php
	}

	public function mwp_setup_installation() {
		$hostingType = get_option( 'mwp_setup_installationHostingType' );
		$systemType  = get_option( 'mwp_setup_installationSystemType' );
		$style       = $hostingType == 2 ? '' : 'style="display: none"';
		$disabled    = $hostingType == 2 ? '' : 'disabled="disabled"';
		$openssl_loc = get_option( 'mwp_setup_opensslLibLocation', 'c:\xampplite\apache\conf\openssl.cnf' );
		?>
		<h1 class="ui header"><?php _e( 'Installation', 'mainwp' ); ?></h1>
		<form method="post" class="ui form">
			<div class="grouped fields">
				<label><?php _e( 'What type of server is this?', 'mainwp' ); ?></label>
				<div class="field" id="mainwp-setup-installation-hosting-type">
					<div class="ui radio checkbox">
						<input type="radio" name="mwp_setup_installation_hosting_type" required="required" <?php echo ( $hostingType == 1 ? 'checked="true"' : '' ); ?> value="1">
						<label><?php _e( 'Web Host', 'mainwp' ); ?></label>
					</div>
				</div>
				<div class="field">
					<div class="ui radio checkbox">
						<input type="radio" name="mwp_setup_installation_hosting_type" required="required" <?php echo ( $hostingType == 2 ? 'checked="true"' : '' ); ?> value="2">
						<label><?php _e( 'Localhost', 'mainwp' ); ?></label>
					</div>
				</div>
			</div>

			<div id="mainwp-quick-setup-system-type" class="grouped fields" <?php echo $style; ?>>
				<label><?php _e( 'What operating system do you use?', 'mainwp' ); ?></label>
				<div class="field">
					<div class="ui radio checkbox">
						<input type="radio" name="mwp_setup_installation_system_type" required="required" <?php echo ( $systemType == 1 ? 'checked="true"' : '' ); ?> value="1" <?php echo $disabled; ?>>
						<label><?php _e( 'MacOS', 'mainwp' ); ?></label>
					</div>
				</div>
				<div class="field">
					<div class="ui radio checkbox">
						<input type="radio" name="mwp_setup_installation_system_type" required="required" <?php echo ( $systemType == 2 ? 'checked="true"' : '' ); ?> value="2" <?php echo $disabled; ?>>
						<label><?php _e( 'Linux', 'mainwp' ); ?></label>
					</div>
				</div>
				<div class="field">
					<div class="ui radio checkbox">
						<input type="radio" name="mwp_setup_installation_system_type" required="required" <?php echo ( $systemType == 3 ? 'checked="true"' : '' ); ?> value="3" <?php echo $disabled; ?>>
						<label><?php _e( 'Windows', 'mainwp' ); ?></label>
					</div>
				</div>
			</div>

			<div id="mainwp-quick-setup-opessl-location" class="grouped fields" <?php echo $systemType == 3 ? '' : 'style="display:none"'; ?>>
				<label><?php _e( 'What is the OpenSSL.cnf file location on your computer?', 'mainwp' ); ?></label>
				<div class="ui info message"><?php _e( 'Due to bug with PHP on Windows, enter the OpenSSL.cnf file location so MainWP Dashboard can connect to your child sites.', 'mainwp' ); ?></div>
				<div class="field" id="mainwp-setup-installation-openssl-location">
					<div class="ui fluid input">
						<input type="text" name="mwp_setup_openssl_lib_location" value="<?php echo esc_html( $openssl_loc ); ?>">
					</div>
					<div><em><?php echo __( 'If your openssl.cnf file is saved to a different path from what is entered above please enter your exact path.', 'mainwp' ); ?></em></div>
					<div><em><?php echo sprintf( __( '%1$sClick here%2$s to see how to find the OpenSSL.cnf file.', 'mainwp' ), '<a href="https://mainwp.com/help/docs/how-to-find-the-openssl-cnf-file/" target="_blank">', '</a>' ); ?></em></div>
				</div>
			</div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<input type="submit" class="ui big green right floated button" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
			<a href="<?php echo esc_url( $this->get_next_step_link( 'system_check' ) ); ?>" class="ui big button"><?php _e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php _e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>
		<?php
	}

	public function mwp_setup_installation_save() {
		check_admin_referer( 'mwp-setup' );

		$hosting_type = intval( $_POST['mwp_setup_installation_hosting_type'] );
		$system_type  = isset( $_POST['mwp_setup_installation_system_type'] ) ? intval( $_POST['mwp_setup_installation_system_type'] ) : 0;

		MainWP_Utility::update_option( 'mwp_setup_installationHostingType', $hosting_type );

		if ( $hosting_type == 1 ) {
			$system_type = 0;
		}

		MainWP_Utility::update_option( 'mwp_setup_installationSystemType', $system_type );
		MainWP_Utility::update_option( 'mwp_setup_opensslLibLocation', isset( $_POST['mwp_setup_openssl_lib_location'] ) ? stripslashes( $_POST['mwp_setup_openssl_lib_location'] ) : '' );

		wp_safe_redirect( $this->get_next_step_link( 'system_check' ) );

		exit;
	}

	public function mwp_setup_system_requirements() {
		$hosting_type = get_option( 'mwp_setup_installationHostingType' );
		$system_type  = get_option( 'mwp_setup_installationSystemType' );
		$back_step    = 'installation';
		if ( $system_type == 3 && $hosting_type == 2 ) {
			$back_step = '';
		}
		?>
			<h1><?php _e( 'System Requirements Check', 'mainwp' ); ?></h1>
			<div class="ui message warning"><?php _e( 'Any Warning here may cause the MainWP Dashboard to malfunction. After you complete the Quick Start setup it is recommended to contact your host’s support and updating your server configuration for optimal performance.', 'mainwp' ); ?></div>
			<?php MainWP_Server_Information::renderQuickSetupSystemCheck(); ?>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big green right floated button"><?php _e( 'Continue', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php _e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link( $back_step ) ); ?>" class="ui big basic green button"><?php _e( 'Back', 'mainwp' ); ?></a>
			<?php wp_nonce_field( 'mwp-setup' ); ?>
		<?php
	}

	public function mwp_setup_install_mainwp_child() {
		?>
		<h1><?php _e( 'Add Your First WordPress Site To Your Dashboard', 'mainwp' ); ?></h1>
			<div class="ui info message">
				<?php _e( 'MainWP requires the MainWP Child plugin to be installed and activated on the WordPress site that you want to connect to your MainWP Dashboard.', 'mainwp' ); ?>
				<?php _e( 'If you have it installed, click the "MainWP Child Plugin Installed" button to connect the site, if not, follow these instructions to install it.', 'mainwp' ); ?><br /><br />
				<?php _e( 'If you need additional help with installing the MainWP Child, please see this <a href="https://mainwp.com/help/docs/set-up-the-mainwp-plugin/install-mainwp-child/" target="_blank">help document</a>.', 'mainwp' ); ?>
			</div>
			<ol>
				<li><?php _e( 'Login to the WordPress site you want to connect <em>(open it in a new browser tab)</em>', 'mainwp' ); ?></li>
				<li><?php _e( 'Go to the <strong>WP > Plugins</strong> page', 'mainwp' ); ?></li>
				<li><?php _e( 'Click <strong>Add New</strong> to install a new plugin', 'mainwp' ); ?></li>
				<li><?php _e( 'In the <strong>Search Field</strong>, enter “MainWP Child” and once the plugin shows, click the Install button', 'mainwp' ); ?></li>
				<li><?php _e( '<strong>Activate</strong> the plugin', 'mainwp' ); ?></li>
			</ol>
			<div class="ui clearing hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<a href="admin.php?page=mainwp-setup&step=connect_first_site" class="ui big green right floated button"><?php esc_attr_e( 'MainWP Child Plugin Installed', 'mainwp' ); ?></a>
			<a href="admin.php?page=mainwp-setup&step=hosting_setup" class="ui big button"><?php _e( 'Skip For Now', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php _e( 'Back', 'mainwp' ); ?></a>
			<?php wp_nonce_field( 'mwp-setup' ); ?>

		<?php
	}

	public function mwp_setup_connect_first_site() {
		?>
		<h1><?php _e( 'Connect Your First Child Site', 'mainwp' ); ?></h1>
		<form method="post" class="ui form">
			<div class="ui message" id="mainwp-message-zone" style="display:none"></div>
			<div class="ui red message" id="mainwp-error-zone" style="display:none"></div>
			<div class="ui green message" id="mainwp-success-zone" style="display:none"></div>
			<div class="ui info message" id="mainwp-info-zone" style="display:none"></div>
			<div class="ui hidden divider"></div>
			<div class="field">
				<label><?php _e( 'What is the site URL? ', 'mainwp' ); ?></label>
				<div class="ui grid">
					<div class="four wide column">
						<select class="ui dropdown" id="mainwp_managesites_add_wpurl_protocol" name="mainwp_managesites_add_wpurl_protocol">
							<option value="https">https://</option>
							<option value="http">http://</option>
						</select>
					</div>
					<div class="twelve wide column">
						<input type="text"  id="mainwp_managesites_add_wpurl" name="mainwp_managesites_add_wpurl" value="" placeholder="yoursite.com" />
					</div>
				</div>
			</div>
			<div class="field">
				<label><?php _e( 'What is your Administrator username on that site? ', 'mainwp' ); ?></label>
				<input type="text" id="mainwp_managesites_add_wpadmin" name="mainwp_managesites_add_wpadmin" value="" />
			</div>
			<div class="field">
				<label><?php _e( 'Add Site Title, if left blank URL is used', 'mainwp' ); ?></label>
				<input type="text" id="mainwp_managesites_add_wpname" name="mainwp_managesites_add_wpname" value="" />
			</div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui horizontal divider"><?php _e( 'Optional Settings', 'mainwp' ); ?></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="field">
				<label><?php _e( 'Did you generate Unique Security ID on the site? If yes, copy it here, if not, leve this field blank. ', 'mainwp' ); ?></label>
				<input type="text" id="mainwp_managesites_add_uniqueId" name="mainwp_managesites_add_uniqueId" value="" />
			</div>
			<div class="field">
				<label><?php esc_html_e( 'Do you have a valid SSL certificate on the site? If it\'s expired or self-sigend, disable this option.', 'mainwp' ); ?></label>
				<div class="ui toggle checkbox">
					<input type="checkbox" name="mainwp_managesites_verify_certificate" id="mainwp_managesites_verify_certificate" checked="true" />
					<label></label>
				</div>
			</div>
			<div class="ui divider"></div>
			<input type="button" name="mainwp_managesites_add" id="mainwp_managesites_add" class="ui button green big" value="<?php _e( 'Connect Site', 'mainwp' ); ?>" />

			<div class="ui clearing hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big green right floated button"><?php _e( 'Continue', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php _e( 'Back', 'mainwp' ); ?></a>
			<?php wp_nonce_field( 'mwp-setup' ); ?>
			<input type="hidden" id="nonce_secure_data" mainwp_addwp="<?php echo wp_create_nonce( 'mainwp_addwp' ); ?>" mainwp_checkwp="<?php echo wp_create_nonce( 'mainwp_checkwp' ); ?>" />
		</form>
		<?php
	}

	public function mwp_setup_hosting() {
		$installation_hosting_type = get_option( 'mwp_setup_installationHostingType' );
		$system_type               = get_option( 'mwp_setup_installationSystemType' );
		$hosting_settings          = array_filter( (array) get_option( 'mwp_setup_hostingSetup', array() ) );
		$typeHosting               = isset( $hosting_settings['type_hosting'] ) ? $hosting_settings['type_hosting'] : false;
		$managePlanning            = isset( $hosting_settings['manage_planning'] ) ? $hosting_settings['manage_planning'] : false;
		$style                     = ( $typeHosting == 3 ) && ( $managePlanning == 2 ) ? '' : ' style="display: none" ';
		?>
		<h1 class="ui header"><?php _e( 'Performance', 'mainwp' ); ?></h1>
		<form method="post" class="ui form">
			<?php if ( $installation_hosting_type == 1 ) : ?>
			<div class="field">
				<label><?php _e( 'What type of hosting is this MainWP Dashboard site on?', 'mainwp' ); ?></label>
				<select name="mwp_setup_type_hosting" id="mwp_setup_type_hosting" class="ui dropdown">
					<option value="3" 
					<?php
					if ( false == $typeHosting || 3 == $typeHosting ) {
						?>
						selected<?php } ?>><?php _e( 'Shared hosting environment', 'mainwp' ); ?></option>
					<option value="1" 
					<?php
					if ( $typeHosting == 1 ) {
						?>
						selected<?php } ?>><?php _e( 'Virtual Private Server (VPS)', 'mainwp' ); ?></option>
					<option value="2" 
					<?php
					if ( $typeHosting == 2 ) {
						?>
						selected<?php } ?>><?php _e( 'Dedicated server', 'mainwp' ); ?></option>
				</select>
			</div>
			<?php endif; ?>
			<div class="field">
				<label><?php _e( 'How many child sites are you planning to manage?', 'mainwp' ); ?></label>
				<select name="mwp_setup_manage_planning" id="mwp_setup_manage_planning" class="ui dropdown">
					<option value="1" 
					<?php
					if ( ( $managePlanning == false ) || ( $managePlanning == 1 ) ) {
						?>
 selected<?php } ?>><?php _e( 'Less than 50 websites', 'mainwp' ); ?></option>
					<option value="2" 
					<?php
					if ( $managePlanning == 2 ) {
						?>
						selected<?php } ?>><?php _e( 'More than 50 websites', 'mainwp' ); ?></option>
				</select>
			</div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<input type="submit" class="ui big green right floated button" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php _e( 'Skip', 'mainwp' ); ?></a>
			<a href="admin.php?page=mainwp-setup&step=install_mainwp_child" class="ui big basic green button"><?php _e( 'Back', 'mainwp' ); ?></a>
			<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>

		<div id="mainwp-setup-hosting-notice" class="ui modal">
			<div class="header"><?php echo __( 'Important Notice', 'mainwp' ); ?></div>
			<div class="content">
				<?php _e( 'Running over 50 sites on shared hosting can be resource intensive for the server so we will turn on caching for you to help. Updates will be cached for quick loading. A manual sync from the Dashboard is required to view new plugins, themes, pages or users.', 'mainwp' ); ?>
			</div>
			<div class="actions">
				<div class="ui positive right labeled icon button">
					<i class="check icon"></i>
					<?php echo __( 'OK, I Understand', 'mainwp' ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function mwp_setup_hosting_save() {
		check_admin_referer( 'mwp-setup' );
		$type_hosting              = $_POST['mwp_setup_type_hosting'];
		$manage_planning           = $_POST['mwp_setup_manage_planning'];
		$installation_hosting_type = get_option( 'mwp_setup_installationHostingType' );
		if ( $installation_hosting_type == 2 ) {
			$type_hosting = 0;
		}
		$hosting_settings                    = array_filter( (array) get_option( 'mwp_setup_hostingSetup', array() ) );
		$hosting_settings['type_hosting']    = $type_hosting;
		$hosting_settings['manage_planning'] = $manage_planning;
		update_option( 'mwp_setup_hostingSetup', $hosting_settings );

		MainWP_Utility::update_option( 'mainwp_optimize', ( $manage_planning == 2 || $type_hosting == 3 ) ? 1 : 0 );

		wp_safe_redirect( $this->get_next_step_link() );
		exit;
	}

	public function mwp_setup_optimization() {
		$userExtension  = MainWP_DB::Instance()->getUserExtension();
		$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );

		if ( ! is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
		}
		$slug = 'mainwp-child/mainwp-child.php';

		if ( false === get_option( 'mwp_setup_mainwpTrustedUpdate' ) ) {
			$mainwp_strusted = 1;
		} else {
			$mainwp_strusted = in_array( $slug, $trustedPlugins ) ? 1 : 0;
		}
		?>
		<h1 class="ui header"><?php _e( 'Optimization', 'mainwp' ); ?></h1>
		<form method="post" class="ui form">
			<div class="field">
				<label><?php _e( 'Add MainWP Child to trusted updates?', 'mainwp' ); ?></label>
				<div class="ui info message"><?php _e( 'This allows your MainWP Dashboard to automatically update the MainWP Child plugin whenever a new version is released.', 'mainwp' ); ?></div>
				<div class="ui toggle checkbox">
					<input type="checkbox" value="hidden" name="mwp_setup_add_mainwp_to_trusted_update" id="mwp_setup_add_mainwp_to_trusted_update" <?php echo ( $mainwp_strusted == 1 ? 'checked="true"' : '' ); ?> />
					<label></label>
				</div>
			</div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<input type="submit" class="ui big green right floated button" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php _e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php _e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>
		<?php
	}

	public function mwp_setup_optimization_save() {
		$userExtension  = MainWP_DB::Instance()->getUserExtension();
		$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
		if ( ! is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
		}
		$slug = 'mainwp-child/mainwp-child.php';
		if ( isset( $_POST['mwp_setup_add_mainwp_to_trusted_update'] ) ) {
			$idx = array_search( urldecode( $slug ), $trustedPlugins );
			if ( $idx == false ) {
				$trustedPlugins[] = urldecode( $slug );
			}
			MainWP_Utility::update_option( 'mainwp_automaticDailyUpdate', 1 );
		} else {
			$trustedPlugins = array_diff( $trustedPlugins, array( urldecode( $slug ) ) );
		}

		MainWP_Utility::update_option( 'mwp_setup_mainwpTrustedUpdate', isset( $_POST['mwp_setup_add_mainwp_to_trusted_update'] ) ? 1 : 0 );

		$userExtension->trusted_plugins = wp_json_encode( $trustedPlugins );

		MainWP_DB::Instance()->updateUserExtension( $userExtension );

		wp_safe_redirect( $this->get_next_step_link() );
		exit;
	}

	public function mwp_setup_notification() {
		$important_notification = get_option( 'mwp_setup_importantNotification', false );
		$user_emails            = MainWP_Utility::getNotificationEmail();
		$user_emails            = explode( ',', $user_emails );
		$i                      = 0;
		?>
		<h1 class="ui header"><?php _e( 'Notifications', 'mainwp' ); ?></h1>
		<form method="post" class="ui form">
			<div class="field">
				<label><?php _e( 'Do you want to receive important email notifications from your MainWP Dashboard?', 'mainwp' ); ?></label>
				<div class="ui toggle checkbox">
					<input type="checkbox" value="hidden" name="mwp_setup_options_important_notification" id="mwp_setup_options_important_notification" <?php echo ( $important_notification == 1 ? 'checked="true"' : '' ); ?> />
					<label></label>
				</div>
			</div>
			<div class="field">
				<label><?php esc_html_e( 'Enter your email address(es)', 'mainwp' ); ?></label>
				<div class="mainwp-multi-emails">
						<?php
						foreach ( $user_emails as $email ) {
							$i++;
							?>
						<div class="ui action input">
							<input type="text" class="" id="mainwp_options_email" name="mainwp_options_email[<?php echo $i; ?>]" value="<?php echo $email; ?>"/>
							<a href="#" class="ui button basic red mainwp-multi-emails-remove" data-tooltip="<?php esc_attr_e( 'Remove this email address', 'mainwp' ); ?>" data-inverted=""><?php _e( 'Delete', 'mainwp' ); ?></a>
						</div>
						<div class="ui hidden fitted divider"></div>
								<?php } ?>
						<a href="#" id="mainwp-multi-emails-add" class="ui button basic green" data-tooltip="<?php esc_attr_e( 'Add another email address to receive email notifications to multiple email addresses.', 'mainwp' ); ?>" data-inverted=""><?php _e( 'Add Another Email', 'mainwp' ); ?></a>
				</div>
				<div class="ui info message">
					<?php _e( 'These are emails from your MainWP Dashboard notifying you of available updates and other maintenance related messages. You can change this later in the MainWP Settings page.', 'mainwp' ); ?>
					<strong>
						<?php _e( 'These are NOT emails from the MainWP team and this does NOT sign you up for any mailing lists.', 'mainwp' ); ?>
					</strong>
				</div>
			</div>

			<script type="text/javascript">
				jQuery( document ).ready( function () {
					jQuery( '#mainwp-multi-emails-add' ).on( 'click', function () {
						jQuery( '#mainwp-multi-emails-add' ).before( '<div class="ui action input"><input type="text" name="mainwp_options_email[]" value=""/><a href="#" class="ui button basic red mainwp-multi-emails-remove" data-tooltip="Remove this email address" data-inverted=""><?php _e( 'Delete', 'mainwp' ); ?></a></div><div class="ui hidden fitted divider"></div>' );
						return false;
					} );
					jQuery( '.mainwp-multi-emails-remove' ).on( 'click', function () {
						jQuery( this ).closest( '.ui.action.input' ).remove();
						return false;
					} );
				} );
			</script>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<input type="submit" class="ui big green right floated button" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php _e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php _e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>
		<?php
	}

	public function mwp_setup_notification_save() {
		check_admin_referer( 'mwp-setup' );
		$important_notification = ( ! isset( $_POST['mwp_setup_options_important_notification'] ) ? 0 : 1 );
		update_option( 'mwp_setup_importantNotification', $important_notification );
		MainWP_Utility::update_option( 'mainwp_notificationOnBackupFail', $important_notification );
		$userExtension                                  = MainWP_DB::Instance()->getUserExtension();
		$userExtension->offlineChecksOnlineNotification = $important_notification;

		$save_emails = array();
		$user_emails = $_POST['mainwp_options_email'];
		if ( is_array( $user_emails ) ) {
			foreach ( $user_emails as $email ) {
				$email = esc_html( trim( $email ) );
				if ( ! empty( $email ) && ! in_array( $email, $save_emails ) ) {
					$save_emails[] = $email;
				}
			}
		}
		$save_emails               = implode( ',', $save_emails );
		$userExtension->user_email = $save_emails;
		MainWP_DB::Instance()->updateUserExtension( $userExtension );
		wp_safe_redirect( $this->get_next_step_link() );
		exit;
	}

	public function mwp_setup_backup() {

		$planning_backup = get_option( 'mwp_setup_planningBackup', 0 );
		$backup_method   = get_option( 'mwp_setup_primaryBackup' );
		$style           = $planning_backup == 1 ? '' : 'style="display: none"';
		$style_alt       = ( ! empty( $backup_method ) ) ? '' : 'style="display: none"';
		$ext_product_id  = $ext_name         = $ext_slug         = '';
		if ( isset( $this->backup_extensions[ $backup_method ] ) ) {
			$ext_product_id = $this->backup_extensions[ $backup_method ]['product_id'];
			$ext_name       = $this->backup_extensions[ $backup_method ]['name'];
			$ext_slug       = $this->backup_extensions[ $backup_method ]['slug'];
		}

		$username = $password     = '';
		if ( get_option( 'mainwp_extensions_api_save_login' ) == true ) {
			$enscrypt_u = get_option( 'mainwp_extensions_api_username' );
			$enscrypt_p = get_option( 'mainwp_extensions_api_password' );
			$username   = ! empty( $enscrypt_u ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_u ) : '';
			$password   = ! empty( $enscrypt_p ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_p ) : '';
		}

		$error   = get_option( 'mwp_setup_error_purchase_extension' );
		$message = get_option( 'mwp_setup_message_purchase_extension' );

		?>
		<h1 class="ui header"><?php _e( 'Backups', 'mainwp' ); ?></h1>
		<form method="post" class="ui form">
			<div class="field">
				<label><?php _e( 'Are you planning to use MainWP for backups?', 'mainwp' ); ?></label>
				<div class="ui toggle checkbox">
					<input type="checkbox" value="hidden" name="mwp_setup_planning_backup" id="mwp_setup_planning_backup" <?php echo ( $planning_backup == 1 ? 'checked="true"' : '' ); ?>/>
					<label></label>
						</div>
			</div>
			<div class="field" id="mwp_setup_tr_backup_method" <?php echo $style; ?>>
				<label><?php _e( 'Which backup system do you want to use?', 'mainwp' ); ?></label>
				<div class="ui info message"><?php _e( 'Backup system can be changed later at any time.', 'mainwp' ); ?></div>
				<select class="ui dropdown" name="mwp_setup_backup_method" id="mwp_setup_backup_method">
					<option></option>
					<option value="updraftplus" 
					<?php
					if ( $backup_method == 'updraftplus' ) :
						?>
						selected<?php endif; ?>>UpdraftPlus (<?php _e( 'Free Extension', 'mainwp' ); ?>)</option>
					<option value="backupwp" 
					<?php
					if ( $backup_method == 'backupwp' ) :
						?>
						selected<?php endif; ?>>BackUpWordPress (<?php _e( 'Free Extension', 'mainwp' ); ?>)</option>
					<option value="backwpup" 
					<?php
					if ( $backup_method == 'backwpup' ) :
						?>
						selected<?php endif; ?>>BackWPup (<?php _e( 'Free Extension', 'mainwp' ); ?>)</option>
							</select>
			</div>
			<div class="ui hidden divider"></div>
			<div class="" id="mainwp-quick-setup-account-login" <?php echo $style_alt; ?>>
				<h4 class="ui dividing header"><?php _e( 'MainWP Account Details', 'mainwp' ); ?></h4>
				<div class="ui info message"><?php echo __( 'This Extension if free, however it requires a free MainWP account to receive updates and support.', 'mainwp' ); ?></div>
				<div class="field">
					<label><?php _e( 'Enter your Username & Password registered at mainwp.com', 'mainwp' ); ?></label>
					<div class="two fields">
						<div class="field">
							<input type="text" placeholder="<?php esc_attr_e( 'Username', 'mainwp' ); ?>" id="mwp_setup_purchase_username" name="mwp_setup_purchase_username" value="<?php echo esc_attr( $username ); ?>" />
						</div>
						<div class="field">
							<input type="password" placeholder="<?php esc_attr_e( 'Password', 'mainwp' ); ?>" id="mwp_setup_purchase_passwd" name="mwp_setup_purchase_passwd" value="<?php echo esc_attr( $password ); ?>" />
						</div>
					</div>
				</div>
				<div class="ui hidden divider"></div>
				<a href="https://mainwp.com/my-account/" class="ui button green basic tiny" target="_blank" data-inverted="" data-position="bottom center" data-tooltip="<?php esc_attr_e( 'If you do not have MainWP account, click here to go to the https://mainwp.com/my-account/ to register for a new account.', 'mainwp' ); ?>"><?php _e( 'Register for MainWP Account', 'mainwp' ); ?></a>
				<input type="hidden" name="mwp_setup_purchase_product_id"  value="<?php echo esc_attr( $ext_product_id ); ?>">
				<div class="ui hidden divider"></div>
				<?php
				if ( ! empty( $message ) ) {
					delete_option( 'mwp_setup_message_purchase_extension' );
					echo '<div class="ui green message">' . $message . '</div>';
				}
				if ( ! empty( $error ) ) {
					delete_option( 'mwp_setup_error_purchase_extension' );
					echo '<div class="ui red message">' . $error . '</div>';
				}
				?>
			</div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<input type="submit" class="ui big green right floated button" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php _e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php _e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>
		<?php
	}

	public function mwp_setup_backup_save() {
		$planning_backup = ( ! isset( $_POST['mwp_setup_planning_backup'] ) ? 0 : 1 );
		$backup_method   = isset( $_POST['mwp_setup_backup_method'] ) ? $_POST['mwp_setup_backup_method'] : '';
		$backup_method   = ! empty( $backup_method ) && isset( $this->backup_extensions[ $backup_method ] ) ? $backup_method : '';

		update_option( 'mwp_setup_planningBackup', $planning_backup );
		update_option( 'mwp_setup_primaryBackup', $backup_method );

		if ( $planning_backup && ! empty( $backup_method ) ) {
			MainWP_Utility::update_option( 'mainwp_primaryBackup', $backup_method );
			$this->mwp_setup_purchase_extension_save(); // redirect here if needed
		} else {
			delete_option( 'mainwp_primaryBackup' );
			wp_safe_redirect( $this->get_next_step_link( 'uptime_robot' ) );
			exit;
		}
	}

	public function mwp_setup_purchase_extension_save() {
		MainWP_Cache::initSession();

		$purchase_extension_history = isset( $_SESSION['purchase_extension_history'] ) ? $_SESSION['purchase_extension_history'] : array();

		$new_purchase_extension_history = array();
		$requests                       = 0;
		foreach ( $purchase_extension_history as $purchase_extension ) {
			if ( $purchase_extension['time'] > ( time() - 1 * 60 ) ) {
				$new_purchase_extension_history[] = $purchase_extension;
				$requests++;
			}
		}

		if ( $requests > 4 ) {
			$_SESSION['purchase_extension_history'] = $new_purchase_extension_history;
			return;
		} else {
			$new_purchase_extension_history[]       = array( 'time' => time() );
			$_SESSION['purchase_extension_history'] = $new_purchase_extension_history;
		}

		check_admin_referer( 'mwp-setup' );
		$username = ! empty( $_POST['mwp_setup_purchase_username'] ) ? trim( $_POST['mwp_setup_purchase_username'] ) : '';
		$password = ! empty( $_POST['mwp_setup_purchase_passwd'] ) ? trim( $_POST['mwp_setup_purchase_passwd'] ) : '';

		if ( ( $username == '' ) && ( $password == '' ) ) {
			MainWP_Utility::update_option( 'mainwp_extensions_api_username', $username );
			MainWP_Utility::update_option( 'mainwp_extensions_api_password', $password );
		} else {
			$enscrypt_u = MainWP_Api_Manager_Password_Management::encrypt_string( $username );
			$enscrypt_p = MainWP_Api_Manager_Password_Management::encrypt_string( $password );
			MainWP_Utility::update_option( 'mainwp_extensions_api_username', $enscrypt_u );
			MainWP_Utility::update_option( 'mainwp_extensions_api_password', $enscrypt_p );
			MainWP_Utility::update_option( 'mainwp_extensions_api_save_login', true );
		}
		$product_id = ! empty( $_POST['mwp_setup_purchase_product_id'] ) ? sanitize_text_field( $_POST['mwp_setup_purchase_product_id'] ) : '';

		if ( ( $username == '' ) || ( $password == '' ) ) {
			update_option( 'mwp_setup_error_purchase_extension', __( 'Incorrect login information', 'mainwp' ) );
			return;
		}

		if ( empty( $product_id ) ) {
			return array( 'error' => __( 'Invalid Product ID.', 'mainwp' ) );
		}

		$data   = MainWP_Api_Manager::instance()->purchase_software( $username, $password, $product_id );
		$result = json_decode( $data, true );

		$undefined_error = false;
		if ( is_array( $result ) ) {
			if ( isset( $result['success'] ) && ( $result['success'] == true ) ) {
				if ( isset( $result['code'] ) && ( $result['code'] == 'PURCHASED' ) ) {
					// success: do nothing
					$ok = true;
				} elseif ( isset( $result['order_id'] ) && ! empty( $result['order_id'] ) ) {
					// success: do nothing
					$ok = true;
				} else {
					$undefined_error = true;
				}
			} elseif ( isset( $result['error'] ) ) {
				update_option( 'mwp_setup_error_purchase_extension', $result['error'] );
				return;
			} else {
				$undefined_error = true;
			}
		} else {
			$undefined_error = true;
		}

		if ( $undefined_error ) {
			update_option( 'mwp_setup_error_purchase_extension', __( 'Undefined error occurred. Please try again.', 'mainwp' ) );
			return;
		}

		wp_safe_redirect( $this->get_next_step_link() );
		exit;
	}

	public static function ajax_save_extensions_api_login() {
		MainWP_Cache::initSession();
		MainWP_Extensions::saveExtensionsApiLogin();
		die();
	}

	public static function ajax_get_backup_extension() {

		$product_id = trim( $_POST['productId'] );

		$enscrypt_u = get_option( 'mainwp_extensions_api_username' );
		$enscrypt_p = get_option( 'mainwp_extensions_api_password' );
		$username   = ! empty( $enscrypt_u ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_u ) : '';
		$password   = ! empty( $enscrypt_p ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_p ) : '';

		if ( ( $username == '' ) || ( $password == '' ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid login. Please check your username and password.', 'mainwp' ) ) ) );
		}

		$data = MainWP_Api_Manager::instance()->get_purchased_software( $username, $password, $product_id );

		$result = json_decode( $data, true );
		$return = array();
		if ( is_array( $result ) ) {
			if ( isset( $result['success'] ) && $result['success'] ) {
				$all_available_exts = array();
				foreach ( MainWP_Extensions_View::getAvailableExtensions() as $ext ) {
					$all_available_exts[ $ext['product_id'] ] = $ext;
				}
				$purchased_data = ( isset( $result['purchased_data'] ) && is_array( $result['purchased_data'] ) ) ? $result['purchased_data'] : array();

				$html = '<div id="mainwp-quick-setup-installation-progress">';

				if ( ! is_array( $purchased_data ) || ! isset( $purchased_data[ $product_id ] ) ) {
					$html .= '<div class="ui yellow message">' . __( 'Extension order could not be found. Please try to login to the My Account page on the https://mainwp.com and download the extension from there.', 'mainwp' ) . '</div>';
				} else {
					$error          = false;
					$product_info   = $purchased_data[ $product_id ];
					$software_title = isset( $all_available_exts[ $product_id ] ) ? esc_html( $all_available_exts[ $product_id ]['title'] ) : esc_html( $product_id );
					$error_message  = '';
					if ( isset( $product_info['package'] ) && ! empty( $product_info['package'] ) ) {
						$package_url = apply_filters( 'mainwp_api_manager_upgrade_url', $product_info['package'] );
						$html       .= '<div class="extension_to_install" download-link="' . esc_attr($package_url) . '" product-id="' . esc_attr($product_id) . '">';
						$html       .= '<span class="ext_installing" status="queue"><span class="install-running"><i class="notched circle loading icon"></i> ' . __( 'Installing the extension. Please wait...' ) . '</span> <span class="status hidden"></span></span>';
						$html       .= '</div>';

					} elseif ( isset( $product_info['error'] ) && ! empty( $product_info['error'] ) ) {
						$error         = true;
						$error_message = MainWP_Api_Manager::instance()->check_response_for_intall_errors( $product_info, $software_title );
						$html         .= '<div class="ui red message">' . $error_message . '</div>';
					} else {
						$error         = true;
						$error_message = __( 'Undefined error.', 'mainwp' );
						$html         .= '<div class="ui red message">' . $error_message . '</div>';
					}
				}
				$html .= '</div>';

				$return = array(
					'result' => 'SUCCESS',
					'data'   => $html,
				);
			} elseif ( isset( $result['error'] ) ) {
				$return = array( 'error' => $result['error'] );
			}
		} else {
			$apisslverify = get_option( 'mainwp_api_sslVerifyCertificate' );
			if ( $apisslverify == 1 ) {
				MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', 0 );
				$return['retry_action'] = 1;
			}
		}
		die( wp_json_encode( $return ) ); // ok
	}

	public static function ajax_download_and_install() {

		if ( ! isset( $_POST['action'] ) || ! isset( $_POST['security'] ) || ! wp_verify_nonce( $_POST['security'], 'MainWPSetup' ) ) {
			die( 0 );
		}

		$return = MainWP_Extensions::installPlugin( $_POST['download_link'], true );
		die( '<mainwp>' . wp_json_encode( $return ) . '</mainwp>' );
	}

	public static function ajax_activate_plugin() {

		if ( ! isset( $_POST['action'] ) || ! isset( $_POST['security'] ) || ! wp_verify_nonce( $_POST['security'], 'MainWPSetup' ) ) {
			die( 0 );
		}

		$slug = $_POST['plugins'];
		if ( is_array( $slug ) ) {
			$slug = current( $slug );
		}
		if ( current_user_can( 'activate_plugins' ) ) {
			activate_plugin( $slug, '', false, true );
			do_action( 'mainwp_api_extension_activated', WP_PLUGIN_DIR . '/' . $slug );
			die( 'SUCCESS' );
		}
		die( 'FAILED' );
	}

	public static function ajax_grab_api_key() {
		$enscrypt_u = get_option( 'mainwp_extensions_api_username' );
		$enscrypt_p = get_option( 'mainwp_extensions_api_password' );
		$username   = ! empty( $enscrypt_u ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_u ) : '';
		$password   = ! empty( $enscrypt_p ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_p ) : '';
		$api        = isset( $_POST['slug'] ) ? dirname( $_POST['slug'] ) : '';
		$result     = MainWP_Api_Manager::instance()->grab_license_key( $api, $username, $password );
		 wp_send_json( $result );
	}

	public function mwp_setup_install_extension() {
		$backup_method  = get_option( 'mwp_setup_primaryBackup' );
		$ext_product_id = $ext_name = $ext_slug = '';
		if ( isset( $this->backup_extensions[ $backup_method ] ) ) {
			$ext_product_id = $this->backup_extensions[ $backup_method ]['product_id'];
			$ext_name       = $this->backup_extensions[ $backup_method ]['name'];
			$ext_slug       = $this->backup_extensions[ $backup_method ]['slug'];
		}

		$ext_installed = false;
		$ext_activated = false;

		$installed_exts = MainWP_Extensions::loadExtensions();

		foreach ( $installed_exts as $ext ) {
			if ( isset( $ext['product_id'] ) && $ext_product_id == $ext['product_id'] ) {
				$ext_installed = true;
				if ( $ext['activated_key'] == 'Activated' ) {
					$ext_activated = true;
				}
				break;
			}
		}

		?>
		<h1><?php _e( 'Backups', 'mainwp' ); ?></h1>
		<form method="post" class="ui form">
				<input type="hidden" name="mwp_setup_extension_product_id" id="mwp_setup_extension_product_id" value="<?php echo esc_attr( $ext_product_id ); ?>" slug="<?php echo esc_attr( $ext_slug ); ?>">
			<div id="mainwp-quick-setup-extension-insatllation">
				<?php if ( $ext_installed ) : ?>
					<div class="ui green message"><?php echo __( 'Extension installed successfully!', 'mainwp' ); ?></div>
					<?php if ( ! $ext_activated ) : ?>
							<script type="text/javascript">
								jQuery( document ).ready( function () {
									jQuery( '#mwp_setup_active_extension' ).fadeIn( 500 );
									mainwp_setup_extension_activate( false );
								} )
							</script>
					<?php endif; ?>
				<?php else : ?>
							<div id="mwp_setup_auto_install_loading">
								<div class="ui inverted active dimmer">
									<div class="ui text loader"><?php _e( 'Please wait...', 'mainwp' ); ?></div>
								</div>
								<span class="status hidden"></span>
							</div>


							<script type="text/javascript">
								jQuery( document ).ready( function () {
										mainwp_setup_grab_extension();
								} )
							</script>

							<div id="mwp_setup_extension_retry_install" style="display: none;"><p><span class="mwp_setup_loading_wrap">
								<input type="button" value="Retry Install Extension" onclick="this.disabled = true;mainwp_setup_grab_extension(false); return false;" id="mwp_setup_extension_install_btn" class="ui big button">
									<i style="display: none;" class="fa fa-spinner fa-pulse"></i><span class="status hidden"></span>
								</span></p>
							</div>

				<?php endif; ?>
						</div>
						<div id="mainwp-quick-setup-extension-activation">
							<?php if ( $ext_activated ) : ?>
								<div class="ui green message"><?php echo __( 'Extension activated successfully!', 'mainwp' ); ?></div>
							<?php else : ?>
									<div id="mwp_setup_active_extension" style="display: none;">
										<p><span class="description"><?php _e('Grabbing the API Key and activating the Extension ...', 'mainwp'); ?></span>
											<span id="mwp_setup_grabing_api_key_loading">
												<i class="fa fa-spinner fa-pulse" style="display: none;"></i><span class="status hidden"></span>
											</span>
										</p>
									</div>
							<?php endif; ?>
						</div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<input type="submit" class="ui big green right floated button" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php _e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link( 'backup' ) ); ?>" class="ui big basic green button"><?php _e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>
		<?php
	}

	public function mwp_setup_install_extension_save() {
		check_admin_referer( 'mwp-setup' );
		wp_safe_redirect( $this->get_next_step_link() );
		exit;
	}

	public function mwp_setup_uptime_robot() {
		$options = get_option( 'advanced_uptime_monitor_extension', array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		if ( isset( $_GET['appApiKey'] ) && ! empty( $_GET['appApiKey'] ) ) {
			$options['api_key'] = sanitize_text_field( $_GET['appApiKey'] );
			update_option( 'advanced_uptime_monitor_extension', $options );
			$this->uptime_robot_refresh_notification_contacts( $options['api_key'] );
			?>
			<script type="text/javascript">
				window.opener.location.reload(true);
				window.close();
			</script>
			<?php
			return;
		}

		$ur_api_key      = isset( $options['api_key'] ) ? $options['api_key'] : '';
		$callback_url    = admin_url( 'admin.php?page=mainwp-setup&step=uptime_robot&' );
		$uptimerobot_url = 'http://uptimerobot.com/authenticateApps?appID=6&callbackURLCustom=' . urlencode( $callback_url );
		?>

		<h1 class="ui header"><?php _e( 'WP Cron', 'mainwp' ); ?></h1>
		<div class="ui info message"><?php _e( 'MainWP relies on a built in WordPress file called wp-cron.php to trigger scheduled events. However, since we suggest you install your MainWP Dashboard on a fresh dedicated site it will get almost no traffic which means your scheduled tasks such as automatic updates may not be triggered in a timely manner. In order to work around that we suggest you sign up for the free Uptime Robot service that will "visit" your dashboard site and make sure that Cron Jobs are regularly triggered.', 'mainwp' ); ?></div>
		<?php
		$error = get_option( 'mainwp_setup_error_create_uptime_robot' );
		if ( ! empty( $error ) ) {
			delete_option( 'mainwp_setup_error_create_uptime_robot' );
			echo '<div class="ui red message">' . $error . '</div>';
		}
		$error_settings = false;
		?>
		<div class="ui divider"></div>
		<a class="ui green big fluid button" target="_blank" onclick="return mainwp_setup_auth_uptime_robot( '<?php echo $uptimerobot_url; ?>' );" href="<?php echo $uptimerobot_url; ?>"><?php _e( 'Click Here To Authorize Uptime Robot', 'mainwp' ); ?></a>
		<div class="ui divider"></div>
		<form method="post" class="ui form">
			<?php if ( ! empty( $ur_api_key ) ) : ?>
			<div class="field">
				<label><?php _e( 'Your Uptime Robot API key', 'mainwp' ); ?></label>
				<div class="ui disabled input">
					<input type="text" value="<?php echo esc_attr( $ur_api_key ); ?>" name="mwp_setup_uptime_robot_api_key">
				</div>
			</div>
			<div class="field">
				<label><?php _e( 'Select the default alert contact', 'mainwp' ); ?></label>
				<?php if ( isset($options['list_notification_contact']) && is_array( $options['list_notification_contact'] ) && count( $options['list_notification_contact'] ) > 0 ) : ?>
					<select class="ui dropdown" name="mwp_setup_uptime_robot_default_contact_id">
							<?php
							foreach ( $options['list_notification_contact'] as $key => $val ) {
								if ( $options['uptime_default_notification_contact_id'] == $key ) {
									echo '<option value="' . esc_attr( $key ) . '" selected="selected">' . esc_html( $val ) . '</option>';
								} else {
									echo '<option value="' . esc_attr( $key ) . '" >' . esc_html( $val ) . '</option>';
								}
							}
							?>
								</select>
				<?php else : ?>
					<?php $error_settings = true; ?>
					<select class="ui disabled dropdown" name="mwp_setup_uptime_robot_default_contact_id">
						<option><?php _e( 'No items found! Check your Uptime Robot Settings.', 'mainwp' ); ?></option>
					</select>
				<?php endif; ?>
			</div>
			<div class="field">
				<label><?php _e( 'Do you want to create a monitor for your MainWP Dashboard?', 'mainwp' ); ?></label>
				<div class="ui toggle checkbox">
					<input type="checkbox" <?php echo $error_settings ? 'disabled="disabled"' : ''; ?> checked="checked" name="mwp_setup_add_dashboard_as_monitor" id="mwp_setup_add_dashboard_as_monitor" />
					<label></label>
				</div>
			</div>
			<?php endif; ?>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<input type="submit" class="ui big green right floated button" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php _e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link( 'backup' ) ); ?>" class="ui big basic green button"><?php _e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>
		<?php
	}

	public function mwp_setup_uptime_robot_save() {
		check_admin_referer( 'mwp-setup' );
		$default_contact_id = isset( $_POST['mwp_setup_uptime_robot_default_contact_id'] ) ? $_POST['mwp_setup_uptime_robot_default_contact_id'] : null;
		$options            = get_option( 'advanced_uptime_monitor_extension', array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		if ( ! empty( $default_contact_id ) ) {
			$options['uptime_default_notification_contact_id'] = $default_contact_id;
			update_option( 'advanced_uptime_monitor_extension', $options );
		}

		$apiKey = isset( $options['api_key'] ) && ! empty( $options['api_key'] ) ? $options['api_key'] : '';

		if ( isset( $_POST['mwp_setup_add_dashboard_as_monitor'] ) ) {
			try {
				if ( empty( $apiKey ) || empty( $default_contact_id ) ) {
					throw new Exception( __( 'Uptime Robot settings.', 'mainwp' ) );
				} else {
					$dashboard_url = get_site_url();
					$this->uptime_robot_add_monitor( $apiKey, $default_contact_id, $dashboard_url );
				}
			} catch ( Exception $e ) {
				update_option( 'mainwp_setup_error_create_uptime_robot', $e->getMessage() );
				return false;
			}
		}

		wp_safe_redirect( $this->get_next_step_link() );
		exit;
	}

	public function uptime_robot_refresh_notification_contacts( $apiKey ) {
		if ( empty( $apiKey ) ) {
			return false;
		}
		try {
			$options            = get_option( 'advanced_uptime_monitor_extension', array() );
			$list_contacts      = $this->uptime_robot_get_notification_contacts( $apiKey );
			$default_contact_id = null;
			if ( is_array( $list_contacts ) && count( $list_contacts ) > 0 ) {
				$default_contact_id = isset( $options['uptime_default_notification_contact_id'] ) ? $options['uptime_default_notification_contact_id'] : '';
				if ( empty( $default_contact_id ) || ! isset( $list_contacts[ $default_contact_id ] ) ) {
					$default_contact_id = key( $list_contacts );
				}
				$options['uptime_default_notification_contact_id'] = $default_contact_id;
				$options['list_notification_contact']              = $list_contacts;
				update_option( 'advanced_uptime_monitor_extension', $options );
			} else {
				throw new Exception( __( 'Uptime Robot notification contact email.', 'mainwp' ) );
			}

			if ( empty( $default_contact_id ) ) {
				throw new Exception( __( 'Uptime Robot notification contact email.', 'mainwp' ) );
			}
		} catch ( Exception $e ) {
			update_option( 'mainwp_setup_error_create_uptime_robot', $e->getMessage() );
			return false;
		}
		return true;
	}

	public function uptime_robot_add_monitor( $apiKey, $contact_id, $monitor_url ) {
		if ( empty( $apiKey ) || empty( $contact_id ) || empty( $monitor_url ) ) {
			throw new Exception( __( 'Error data.', 'mainwp' ) );
		}

		$url          = $this->uptime_robot_api_url . '/newMonitor';
		$post_fields  = "api_key={$apiKey}";
		$post_fields .= '&friendly_name=' . urlencode( 'My MainWP Dashboard' );
		$post_fields .= "&url={$monitor_url}&type=1&format=json";
		$post_fields .= '&alert_contacts=' . urlencode( $contact_id );
		$result       = $this->uptime_robot_fetch( $url, $post_fields);
		$result       = json_decode( $result );

		if ( is_object( $result ) ) {
			if ( property_exists( $result, 'stat' ) && $result->stat == 'ok' ) {
				return true;
			} elseif ( property_exists( $result, 'error' ) && property_exists( $result->error, 'message' ) ) {
				throw new Exception( $result->error->message );
			}
		}
		throw new Exception( __( 'Uptime Robot error', 'mainwp' ) );
	}

	public function uptime_robot_get_notification_contacts( $apiKey ) {
		if ( empty( $apiKey ) ) {
			return array();
		}
		$list_contact = array();
		$url          = $this->uptime_robot_api_url . '/getAlertContacts';
		$post_fields  = "api_key={$apiKey}";
		$post_fields .= '&format=json';
		$result       = $this->uptime_robot_fetch( $url, $post_fields );
		$result       = json_decode( $result );
		if ( ! $result || $result->stat == 'fail' ) {
			return array();
		}

		$contact_types = array(
			2  => 'E-mail',
			8  => 'Pro SMS',
			3  => 'Twitter',
			5  => 'Web-Hook',
			1  => 'Email-to-SMS',
			4  => 'Boxcar 2 (Push for iOS)',
			6  => 'Pushbullet (Push for Android, iOS &amp; Browsers)',
			9  => 'Pushover (Push for Android, iOS, Browsers &amp; Desktop)',
			10 => 'HipChat',
			11 => 'Slack',
		);

		$number_contacts = count( $result->alert_contacts );

		$list_contact = array();
		for ( $i = 0; $i < $number_contacts; $i++ ) {
			   $value = '';
				$type = $result->alert_contacts[ $i ]->type;
			if ( isset($contact_types[ $type ]) ) {
				$value = $result->alert_contacts[ $i ]->friendly_name . ' (' . $contact_types[ $type ] . ')';
			} else {
				$value = $result->alert_contacts[ $i ]->value;
			}
				$list_contact[ $result->alert_contacts[ $i ]->id ] = $value;
		}

		return $list_contact;
	}

	private function uptime_robot_fetch( $url, $post_fields = '' ) {

		$url = trim( $url );
		$ch  = curl_init();

		// cURL offers really easy proxy support.
		$proxy = new WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
			curl_setopt( $ch, CURLOPT_PROXY, $proxy->host() );
			curl_setopt( $ch, CURLOPT_PROXYPORT, $proxy->port() );

			if ( $proxy->use_authentication() ) {
				curl_setopt( $ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY );
				curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
			}
		}

		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_ENCODING, '' );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 240 );
		curl_setopt( $ch, CURLOPT_ENCODING, 'none'); // to fix
		curl_setopt( $ch, CURLOPT_MAXREDIRS, 10 );
		curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_fields );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'cache-control: no-cache',
			'content-type: application/x-www-form-urlencoded',
		) );
		$file_contents = curl_exec( $ch );
		curl_close( $ch );

		$json_encap = 'jsonUptimeRobotApi()';

		if ( strpos( $file_contents, 'UptimeRobotApi' ) == false ) {
			return $file_contents;
		} else {
			return substr( $file_contents, strlen( $json_encap ) - 1, strlen( $file_contents ) - strlen( $json_encap ) );
		}
	}

	private function mwp_setup_ready_actions() {
		delete_option( 'mainwp_run_quick_setup' );
	}

	public function mwp_setup_ready() {
		?>
		<div class="ui hidden divider"></div>
		<div class="ui hidden divider"></div>
		<h1 class="ui icon header" style="display:block">
		  <i class="thumbs up outline icon"></i>
		  <div class="content">
			<?php _e( 'Your MainWP Dashboard is Ready!', 'mainwp' ); ?>
			<div class="sub header"><?php _e( 'Congratulations! Now you are ready to start managing your WordPress sites.', 'mainwp' ); ?></div>
				<div class="ui hidden divider"></div>
				<a class="ui massive green button" href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp_tab' ) ); ?>"><?php _e( 'Start Managing Your Sites', 'mainwp' ); ?></a>
			</div>
		</h1>
		<div class="ui hidden divider"></div>
		<div class="ui hidden divider"></div>
		<?php
	}

}
