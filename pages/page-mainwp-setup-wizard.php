<?php
/**
 * MainWP Setup Wizard
 *
 * MainWP Quick Setup Wizard enables you to quickly set basic plugin settings
 *
 * @package MainWP/Setup_Wizard
 */

namespace MainWP\Dashboard;

/**
 * Copyright
 * Plugin: WooCommerce
 * Plugin URI: http://www.woothemes.com/woocommerce/
 * Description: An e-commerce toolkit that helps you sell anything. Beautifully.
 * Version: 2.4.4
 * Author: WooThemes
 * Author URI: http://woothemes.com
 */

/**
 * Class MainWP_Setup_Wizard
 *
 * @package MainWP\Dashboard
 */
class MainWP_Setup_Wizard {

	/**
	 * Private variable to hold current quick setup wizard step.
	 *
	 * @var string Current QSW step.
	 */
	private $step = '';

	/**
	 * Private variable to hold quick setup wizard steps.
	 *
	 * @var array QSW steps.
	 */
	private $steps = array();

	/**
	 * MainWP_Setup_Wizard constructor.
	 *
	 * Run each time the class is called.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menus' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ), 999 );
	}

	/**
	 * Method admin_menus()
	 *
	 * Add Quick Setup Wizard page.
	 */
	public function admin_menus() {
		add_dashboard_page( '', '', 'manage_options', 'mainwp-setup', '' );
	}

	/**
	 * Medthod admin_init()
	 *
	 * Initiate Quick Setup Wizard page.
	 */
	public function admin_init() {
		if ( empty( $_GET['page'] ) || 'mainwp-setup' !== $_GET['page'] ) {
			return;
		}
		$this->steps = array(
			'introduction'       => array(
				'name'    => __( 'Introduction', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_introduction' ),
				'handler' => '',
			),
			'system_check'       => array(
				'name'    => __( 'System', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_system_requirements' ),
				'handler' => array( $this, 'mwp_setup_system_requirements_save' ),
			),
			'connect_first_site' => array(
				'name'    => __( 'Connect', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_connect_first_site' ),
				'handler' => '',
			),
			'monitoring'         => array(
				'name'    => __( 'Monitoring', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_monitoring' ),
				'handler' => array( $this, 'mwp_setup_monitoring_save' ),
			),
			'next_steps'         => array(
				'name'    => __( 'Finish', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_ready' ),
				'handler' => '',
			),
		);

		$this->step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : current( array_keys( $this->steps ) );

		wp_localize_script( 'mainwp-setup', 'mainwpSetupLocalize', array( 'nonce' => wp_create_nonce( 'MainWPSetup' ) ) );
		wp_enqueue_script( 'mainwp-setup', MAINWP_PLUGIN_URL . 'assets/js/mainwp-setup.js', array( 'jquery' ), MAINWP_VERSION, true );
		wp_enqueue_script( 'semantic', MAINWP_PLUGIN_URL . 'assets/js/semantic-ui/semantic.min.js', array( 'jquery' ), MAINWP_VERSION, true );
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

	/**
	 * Method get_next_step_link()
	 *
	 * Get the link for the next step.
	 *
	 * @param string $step Next step link.
	 *
	 * @return string Link for next step.
	 */
	public function get_next_step_link( $step = '' ) {
		if ( ! empty( $step ) && isset( $step, $this->steps ) ) {
			return esc_url_raw( remove_query_arg( 'noregister', add_query_arg( 'step', $step ) ) );
		}
		$keys = array_keys( $this->steps );
		return esc_url_raw( remove_query_arg( 'noregister', add_query_arg( 'step', $keys[ array_search( $this->step, array_keys( $this->steps ) ) + 1 ] ) ) );
	}

	/**
	 * Method get_back_step_link()
	 *
	 * Get the link for the previouse step.
	 *
	 * @param string $step Previouse step link.
	 *
	 * @return string Link for previouse step.
	 */
	public function get_back_step_link( $step = '' ) {
		if ( ! empty( $step ) && isset( $step, $this->steps ) ) {
			return esc_url_raw( remove_query_arg( 'noregister', add_query_arg( 'step', $step ) ) );
		}
		$keys = array_keys( $this->steps );
		return esc_url_raw( remove_query_arg( 'noregister', add_query_arg( 'step', $keys[ array_search( $this->step, array_keys( $this->steps ) ) - 1 ] ) ) );
	}

	/**
	 * Method setup_wizard_header()
	 *
	 * Render Setup Wizard's header.
	 */
	public function setup_wizard_header() {
		?>
		<!DOCTYPE html>
		<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?> class="mainwp-quick-setup">
			<head>
				<meta name="viewport" content="width=device-width" />
				<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
				<title><?php esc_html_e( 'MainWP &rsaquo; Setup Wizard', 'mainwp' ); ?></title>
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

	/**
	 * Method setup_wizard_footer()
	 *
	 * Render Setup Wizard's footer.
	 */
	public function setup_wizard_footer() {
		?>
				</div>
				<div class="ui grid">
					<div class="row">
						<div class="center aligned column">
							<a class="" href="<?php echo esc_url( admin_url( 'index.php' ) ); ?>"><?php esc_html_e( 'Quit MainWP Quick Setup Wizard and Go to WP Admin', 'mainwp' ); ?></a> | <a class="" href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp_tab' ) ); ?>"><?php esc_html_e( 'Quit MainWP Quick Setup Wizard and Go to MainWP', 'mainwp' ); ?></a>
						</div>
					</div>
				</div>
			</body>
		</html>
		<?php
	}

	/**
	 * Method setup_wizard_steps()
	 *
	 * Render Setup Wizards Steps.
	 */
	public function setup_wizard_steps() {
		$ouput_steps = $this->steps;
		?>
		<div id="mainwp-quick-setup-wizard-steps" class="ui ordered fluid steps" style="">
			<?php foreach ( $ouput_steps as $step_key => $step ) : ?>
				<?php
				if ( isset( $step['hidden'] ) && $step['hidden'] ) {
					continue;
				}
				?>
				<div class="step
				<?php
				if ( $step_key == $this->step ) {
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

	/**
	 * Method setup_wizard_content()
	 *
	 * Render setup Wizard's current step view.
	 */
	public function setup_wizard_content() {
		echo '<div class="mainwp-quick-setup-wizard-steps-content" style="margin-top:3em;">';
		call_user_func( $this->steps[ $this->step ]['view'] );
		echo '</div>';
		echo '<div class="ui clearing hidden divider"></div>';
	}

	/**
	 * Method mwp_setup_introduction()
	 *
	 * First start message after activation.
	 */
	public function mwp_setup_introduction() {
		$this->mwp_setup_ready_actions();
		?>
		<h1 class="ui header"><?php esc_html_e( 'MainWP Quick Setup Wizard', 'mainwp' ); ?></h1>
		<p><?php esc_html_e( 'Thank you for choosing MainWP for managing your WordPress sites. This quick setup wizard will help you configure the basic settings. It\'s completely optional and shouldn\'t take longer than five minutes.', 'mainwp' ); ?></p>
		<div class="ui hidden divider"></div>
		<a href="https://kb.mainwp.com/docs/quick-setup-wizard-video/" target="_blank" class="ui big icon green button"><i class="youtube icon"></i> <?php esc_html_e( 'Walkthrough', 'mainwp' ); ?></a>
		<div class="ui hidden divider"></div>
		<p><?php esc_html_e( 'If you don\'t want to go through the setup wizard, you can skip and proceed to your MainWP Dashboard by clicking the "Not right now" button. If you change your mind, you can come back later by starting the Setup Wizard from the MainWP > Settings > MainWP Tools page! ', 'mainwp' ); ?></p>
		<p><?php esc_html_e( 'To go back to the WordPress Admin section, click the "Back to WP Admin" button.', 'mainwp' ); ?></p>
		<div class="ui hidden divider"></div>
		<div class="ui hidden divider"></div>
		<div class="ui hidden divider"></div>
		<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big green right floated button"><?php esc_html_e( 'Let\'s Go!', 'mainwp' ); ?></a>
		<a href="<?php echo esc_url( admin_url( 'index.php' ) ); ?>" class="ui big button"><?php esc_html_e( 'Back to WP Admin', 'mainwp' ); ?></a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&do=new' ) ); ?>" class="ui big button"><?php esc_html_e( 'Not Right Now', 'mainwp' ); ?></a>
		<?php
	}


	/**
	 * Method  mwp_setup_system_requirements()
	 *
	 * Render System Requirements Step.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Server_Information::render_quick_setup_system_check()
	 */
	public function mwp_setup_system_requirements() {
		?>
		<h1><?php esc_html_e( 'System Requirements Check', 'mainwp' ); ?></h1>
	<form method="post" class="ui form">
		<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
		<?php
		$show_ssl = false;
		if ( MainWP_Server_Information_Handler::is_openssl_config_warning() ) {
			$openssl_loc = MainWP_System_Utility::get_openssl_conf();
			?>
				<div class="ui secondary segment">
			<div class="grouped fields">
						<label><?php esc_html_e( 'What is the openssl.cnf file location on your computer?', 'mainwp' ); ?></label>
				<div class="field" id="mainwp-setup-installation-openssl-location">
					<div class="ui fluid input">
						<input type="text" name="mwp_setup_openssl_lib_location" value="<?php echo esc_attr( $openssl_loc ); ?>">
					</div>
							<div><em><?php esc_html_e( 'Due to bug with PHP on some servers, enter the openssl.cnf file location so MainWP Dashboard can connect to your child sites. If your openssl.cnf file is saved to a different path from what is entered above please enter your exact path. ', 'mainwp' ); ?><?php echo sprintf( __( '%1$sClick here%2$s to see how to find the OpenSSL.cnf file.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/how-to-find-the-openssl-cnf-file/" target="_blank">', '</a>' ); ?></em></div>
						</div>
				</div>
			</div>
			<?php
			$show_ssl = true;
		}
		?>
			<div class="ui message warning"><?php esc_html_e( 'Any warning here may cause the MainWP Dashboard to malfunction. After you complete the Quick Start setup it is recommended to contact your host’s support and updating your server configuration for optimal performance.', 'mainwp' ); ?></div>
			<?php MainWP_Server_Information::render_quick_setup_system_check(); ?>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<?php
			if ( $show_ssl ) {
				?>
				<input type="submit" class="ui big green right floated button" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
			<?php } else { ?>
				<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big green right floated button"><?php esc_html_e( 'Continue', 'mainwp' ); ?></a>
			<?php } ?>
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php esc_html_e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>
			<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>
		<?php
	}


	/**
	 * Method mwp_setup_system_requirements_save()
	 *
	 * Installation Step save to DB.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public function mwp_setup_system_requirements_save() {
		check_admin_referer( 'mwp-setup' );
		if ( isset( $_POST['mwp_setup_openssl_lib_location'] ) ) {
			MainWP_Utility::update_option( 'mainwp_opensslLibLocation', isset( $_POST['mwp_setup_openssl_lib_location'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_setup_openssl_lib_location'] ) ) : '' );
		}
		wp_safe_redirect( $this->get_next_step_link() );
		exit;
	}

	/**
	 * Method mwp_setup_connect_first_site()
	 *
	 * Render Install first Child Site Step form.
	 */
	public function mwp_setup_connect_first_site() {
		?>
		<h1><?php esc_html_e( 'Connect Your First Child Site', 'mainwp' ); ?></h1>
			<div class="ui info message">
				<?php esc_html_e( 'MainWP requires the MainWP Child plugin to be installed and activated on the WordPress site that you want to connect to your MainWP Dashboard.  ', 'mainwp' ); ?>
			<?php esc_html_e( 'To install the MainWP Child plugin, please follow these steps:', 'mainwp' ); ?>
			<ol>
				<li><?php printf( __( 'Login to the WordPress site you want to connect %1$s(open it in a new browser tab)%2$s', 'mainwp' ), '<em>', '</em>' ); ?></li>
				<li><?php printf( __( 'Go to the %1$sWP > Plugins%2$s page', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
				<li><?php printf( __( 'Click %1$sAdd New%2$s to install a new plugin', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
				<li><?php printf( __( 'In the %1$sSearch Field%2$s, enter "MainWP Child" and once the plugin shows, click the Install button', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
				<li><?php printf( __( '%1$sActivate%2$s the plugin', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
			</ol>
		</div>
		<div class="ui form">
			<div class="field">
			<div class="ui hidden divider"></div>
				<label><?php esc_html_e( 'Is MainWP Child plugin installed and activated on the WordPress site that you want to connect?', 'mainwp' ); ?></label>
			<div class="ui hidden divider"></div>
				<div class="ui toggle checkbox">
					<input type="checkbox" name="mainwp-qsw-verify-mainwp-child-active" id="mainwp-qsw-verify-mainwp-child-active">
					<label for="mainwp-qsw-verify-mainwp-child-active"><?php esc_html_e( 'Select to confirm that the MainWP Child plugin is active.', 'mainwp' ); ?></label>
				</div>
			</div>
		</div>
		<form method="post" class="ui form">
			<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
			<div id="mainwp-qsw-connect-site-form" style="display:none">
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui message" id="mainwp-message-zone" style="display:none"></div>
			<div class="ui red message" id="mainwp-error-zone" style="display:none"></div>
			<div class="ui green message" id="mainwp-success-zone" style="display:none"></div>
			<div class="ui info message" id="mainwp-info-zone" style="display:none"></div>
			<div class="ui hidden divider"></div>
				<div class="ui secondary segment">
					<div class="ui hidden divider"></div>
					<div class="ui hidden divider"></div>
					<div class="ui horizontal divider"><?php esc_html_e( 'Required Fields', 'mainwp' ); ?></div>
					<div class="ui hidden divider"></div>
					<div class="ui hidden divider"></div>
			<div class="field">
				<label><?php esc_html_e( 'What is the site URL? ', 'mainwp' ); ?></label>
						<div class="ui left action input">
							<select class="ui compact selection dropdown" id="mainwp_managesites_add_wpurl_protocol" name="mainwp_managesites_add_wpurl_protocol" style="width:120px;">
							<option value="https">https://</option>
							<option value="http">http://</option>
						</select>
						<input type="text" id="mainwp_managesites_add_wpurl" name="mainwp_managesites_add_wpurl" value="" placeholder="yoursite.com" />
					</div>
				</div>
			<div class="field">
						<label><?php esc_html_e( 'What is your administrator username on that site? ', 'mainwp' ); ?></label>
				<input type="text" id="mainwp_managesites_add_wpadmin" name="mainwp_managesites_add_wpadmin" value="" />
			</div>
			<div class="field">
						<label><?php esc_html_e( 'Add site title. If left blank URL is used.', 'mainwp' ); ?></label>
				<input type="text" id="mainwp_managesites_add_wpname" name="mainwp_managesites_add_wpname" value="" />
			</div>
			<div class="ui hidden divider"></div>
			<a href="#" id="mainwp-toggle-optional-settings"><i class="ui eye icon"></i> <?php esc_html_e( 'Advanced options', 'mainwp' ); ?></a>
			<div class="ui hidden divider"></div>
			<div id="mainwp-qsw-optional-settings-form" style="display:none">
			<div class="ui hidden divider"></div>
				<div class="ui horizontal divider"><?php esc_html_e( 'Advanced Options (optional)', 'mainwp' ); ?></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="field">
						<label><?php esc_html_e( 'Did you generate unique security ID on the site? If yes, copy it here, if not, leave this field blank. ', 'mainwp' ); ?></label>
				<input type="text" id="mainwp_managesites_add_uniqueId" name="mainwp_managesites_add_uniqueId" value="" />
			</div>
			</div>
			<div class="ui hidden divider"></div>
			<div class="ui divider"></div>
			<input type="button" name="mainwp_managesites_add" id="mainwp_managesites_add" class="ui button green big" value="<?php esc_attr_e( 'Connect Site', 'mainwp' ); ?>" />
				</div>
			</div>
			<div class="ui clearing hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big green right floated button"><?php esc_html_e( 'Continue', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>
			<?php wp_nonce_field( 'mwp-setup' ); ?>
			<input type="hidden" id="nonce_secure_data" mainwp_addwp="<?php echo wp_create_nonce( 'mainwp_addwp' ); ?>" mainwp_checkwp="<?php echo wp_create_nonce( 'mainwp_checkwp' ); ?>" />
		</form>
		<?php
	}

	/**
	 * Method mwp_setup_monitoring()
	 *
	 * Render Monitoring Step.
	 */
	public function mwp_setup_monitoring() {

		$disableSitesMonitoring = get_option( 'mainwp_disableSitesChecking', 1 );
		$frequencySitesChecking = get_option( 'mainwp_frequencySitesChecking', 60 );

		$disableSitesHealthMonitoring = get_option( 'mainwp_disableSitesHealthMonitoring', 1 );
		$sitehealthThreshold          = get_option( 'mainwp_sitehealthThreshold', 80 ); // "Should be improved" threshold.

		?>
		<h1 class="ui header">
			<?php esc_html_e( 'Basic Uptime Monitoring', 'mainwp' ); ?>
		</h1>
		<form method="post" class="ui form">
			<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
			<div class="ui grid field">
				<div class="ui info message"><?php echo sprintf( __( 'Excessive checking can cause server resource issues.  For frequent checks or lots of sites, we recommend the %1$sMainWP Advanced Uptime Monitoring%2$s extension.', 'mainwp' ), '<a href="https://mainwp.com/extension/advanced-uptime-monitor" target="_blank">', '</a>' ); ?></div>
				<label class="six wide column middle aligned"><?php esc_html_e( 'Enable basic uptime monitoring', 'mainwp' ); ?></label>
				<div class="ten wide column ui toggle checkbox mainwp-checkbox-showhide-elements" hide-parent="monitoring">
					<input type="checkbox" name="mainwp_setup_disableSitesChecking" id="mainwp_setup_disableSitesChecking" <?php echo ( 1 == $disableSitesMonitoring ? '' : 'checked="true"' ); ?>/>
				</div>
			</div>

			<div class="ui grid field" <?php echo $disableSitesMonitoring ? 'style="display:none"' : ''; ?> hide-element="monitoring">
				<label class="six wide column middle aligned"><?php esc_html_e( 'Check interval', 'mainwp' ); ?></label>
				<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Select preferred checking interval.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
					<select name="mainwp_setup_frequency_sitesChecking" id="mainwp_setup_frequency_sitesChecking" class="ui dropdown">
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
			<h1 class="ui header">
				<?php esc_html_e( 'Site Health Monitoring', 'mainwp' ); ?>
			</h1>
			<div class="ui grid field">
				<label class="six wide column middle aligned"><?php esc_html_e( 'Enable Site Health monitoring', 'mainwp' ); ?></label>
				<div class="ten wide column ui toggle checkbox mainwp-checkbox-showhide-elements" hide-parent="health-monitoring">
					<input type="checkbox" name="mainwp_setup_disable_sitesHealthMonitoring" id="mainwp_setup_disable_sitesHealthMonitoring" <?php echo ( 1 == $disableSitesHealthMonitoring ? '' : 'checked="true"' ); ?>/>
				</div>
			</div>

			<div class="ui grid field" <?php echo $disableSitesHealthMonitoring ? 'style="display:none"' : ''; ?> hide-element="health-monitoring">
				<label class="six wide column middle aligned"><?php esc_html_e( 'Site health threshold', 'mainwp' ); ?></label>
				<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Set preferred site health threshold.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
					<select name="mainwp_setup_site_healthThreshold" id="mainwp_setup_site_healthThreshold" class="ui dropdown">
						<option value="80" <?php echo ( ( 80 == $sitehealthThreshold || 0 == $sitehealthThreshold ) ? 'selected' : '' ); ?>><?php esc_html_e( 'Should be improved', 'mainwp' ); ?></option>
						<option value="100" <?php echo ( 100 == $sitehealthThreshold ? 'selected' : '' ); ?>><?php esc_html_e( 'Good', 'mainwp' ); ?></option>
					</select>
				</div>
			</div>

			<input type="submit" class="ui big green right floated button" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php esc_html_e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>				

			<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>
		<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery( '.mainwp-quick-setup-wizard-steps-content .ui.checkbox' ).checkbox();
			});
		</script>
		<?php
	}

	/**
	 * Method mwp_setup_monitoring_save()
	 *
	 * Save Monitoring form data.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public function mwp_setup_monitoring_save() {
		check_admin_referer( 'mwp-setup' );
		MainWP_Utility::update_option( 'mainwp_disableSitesChecking', ( ! isset( $_POST['mainwp_setup_disableSitesChecking'] ) ? 1 : 0 ) );
		$val = isset( $_POST['mainwp_setup_frequency_sitesChecking'] ) ? intval( $_POST['mainwp_setup_frequency_sitesChecking'] ) : 1440;
		MainWP_Utility::update_option( 'mainwp_frequencySitesChecking', $val );
		MainWP_Utility::update_option( 'mainwp_disableSitesHealthMonitoring', ( ! isset( $_POST['mainwp_setup_disable_sitesHealthMonitoring'] ) ? 1 : 0 ) );
		$val = isset( $_POST['mainwp_setup_site_healthThreshold'] ) ? intval( $_POST['mainwp_setup_site_healthThreshold'] ) : 80;
		MainWP_Utility::update_option( 'mainwp_sitehealthThreshold', $val );
		wp_safe_redirect( $this->get_next_step_link() );
		exit;
	}

	/**
	 * Method mwp_setup_ready_actions()
	 *
	 * Delete option 'mainwp_run_quick_setup' when Quick Wizard
	 * Setup is completed.
	 */
	private function mwp_setup_ready_actions() {
		delete_option( 'mainwp_run_quick_setup' );
	}

	/**
	 * Method mwp_setup_ready()
	 *
	 * Render MainWP Dashboard ready message.
	 */
	public function mwp_setup_ready() {
		?>
		<div class="ui hidden divider"></div>
		<div class="ui hidden divider"></div>
		<h1 class="ui icon header" style="display:block">
			<i class="thumbs up outline icon"></i>
			<div class="content">
				<?php esc_html_e( 'Your MainWP Dashboard is Ready!', 'mainwp' ); ?>
				<div class="sub header"><?php esc_html_e( 'Congratulations! Now you are ready to start managing your WordPress sites.', 'mainwp' ); ?></div>
				<div class="ui hidden divider"></div>
				<a class="ui massive green button" href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp_tab' ) ); ?>"><?php esc_html_e( 'Start Managing Your Sites', 'mainwp' ); ?></a>
			</div>
		</h1>
		<div class="ui hidden divider"></div>
		<div class="ui hidden divider"></div>
		<?php
	}

}
