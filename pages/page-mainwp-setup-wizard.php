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
		if ( empty( $_GET['page'] ) || 'mainwp-setup' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return;
		}
		$this->steps = array(
			'welcome'            => array(
				'name'    => esc_html__( 'Welcome', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_welcome' ),
				'handler' => '',
			),
			'introduction'       => array(
				'name'    => esc_html__( 'Introduction', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_introduction' ),
				'handler' => array( $this, 'mwp_setup_introduction_save' ),
			),
			'system_check'       => array(
				'name'    => esc_html__( 'System', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_system_requirements' ),
				'handler' => array( $this, 'mwp_setup_system_requirements_save' ),
			),
			'connect_first_site' => array(
				'name'    => esc_html__( 'Connect', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_connect_first_site' ),
				'handler' => array( $this, 'mwp_setup_connect_first_site_save' ),
			),
			'add_client'         => array(
				'name'    => esc_html__( 'Add Client', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_add_client' ),
				'handler' => '',
			),
			'monitoring'         => array(
				'name'    => esc_html__( 'Monitoring', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_monitoring' ),
				'handler' => array( $this, 'mwp_setup_monitoring_save' ),
			),
			'next_steps'         => array(
				'name'    => esc_html__( 'Finish', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_ready' ),
				'handler' => '',
			),
		);

		$this->step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : current( array_keys( $this->steps ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		wp_enqueue_script( 'fomantic-ui', MAINWP_PLUGIN_URL . 'assets/js/fomantic-ui/fomantic-ui.js', array( 'jquery' ), MAINWP_VERSION, false );
		wp_localize_script( 'mainwp-setup', 'mainwpSetupLocalize', array( 'nonce' => wp_create_nonce( 'MainWPSetup' ) ) );
		wp_enqueue_script( 'mainwp', MAINWP_PLUGIN_URL . 'assets/js/mainwp.js', array( 'jquery' ), MAINWP_VERSION, true );
		wp_enqueue_script( 'mainwp-clients', MAINWP_PLUGIN_URL . 'assets/js/mainwp-clients.js', array(), MAINWP_VERSION, true );
		wp_enqueue_script( 'mainwp-setup', MAINWP_PLUGIN_URL . 'assets/js/mainwp-setup.js', array( 'jquery', 'fomantic-ui' ), MAINWP_VERSION, true );
		wp_enqueue_script( 'mainwp-ui', MAINWP_PLUGIN_URL . 'assets/js/mainwp-ui.js', array(), MAINWP_VERSION, true );
		wp_enqueue_style( 'mainwp', MAINWP_PLUGIN_URL . 'assets/css/mainwp.css', array(), MAINWP_VERSION );
		wp_enqueue_style( 'mainwp-fonts', MAINWP_PLUGIN_URL . 'assets/css/mainwp-fonts.css', array(), MAINWP_VERSION );
		wp_enqueue_style( 'fomantic', MAINWP_PLUGIN_URL . 'assets/js/fomantic-ui/fomantic-ui.css', array(), MAINWP_VERSION );
		wp_enqueue_style( 'mainwp-fomantic', MAINWP_PLUGIN_URL . 'assets/css/mainwp-fomantic.css', array(), MAINWP_VERSION );

		// load custom MainWP theme.
		$selected_theme = MainWP_Settings::get_instance()->get_selected_theme();
		if ( ! empty( $selected_theme ) ) {
			if ( 'dark' === $selected_theme ) {
				wp_enqueue_style( 'mainwp-custom-dashboard-extension-dark-theme', MAINWP_PLUGIN_URL . 'assets/css/themes/mainwp-dark-theme.css', array(), MAINWP_VERSION );
			} elseif ( 'wpadmin' === $selected_theme ) {
				wp_enqueue_style( 'mainwp-custom-dashboard-extension-wp-admin-theme', MAINWP_PLUGIN_URL . 'assets/css/themes/mainwp-wpadmin-theme.css', array(), MAINWP_VERSION );
			} elseif ( 'minimalistic' === $selected_theme ) {
				wp_enqueue_style( 'mainwp-custom-dashboard-extension-minimalistic-theme', MAINWP_PLUGIN_URL . 'assets/css/themes/mainwp-minimalistic-theme.css', array(), MAINWP_VERSION );
			} elseif ( 'default' === $selected_theme ) {
				wp_enqueue_style( 'mainwp-custom-dashboard-extension-default-theme', MAINWP_PLUGIN_URL . 'assets/css/themes/mainwp-default-theme.css', array(), MAINWP_VERSION );
			} else {
				$dirs             = MainWP_Settings::get_instance()->get_custom_theme_folder();
				$custom_theme_url = $dirs[1];
				wp_enqueue_style( 'mainwp-custom-dashboard-theme', $custom_theme_url . $selected_theme, array(), MAINWP_VERSION );
			}
		}

		if ( ! empty( $_POST['save_step'] ) && isset( $this->steps[ $this->step ]['handler'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			call_user_func( $this->steps[ $this->step ]['handler'] );
		}

		if ( MainWP_Utility::instance()->is_disabled_functions( 'error_log' ) || ! function_exists( '\error_log' ) ) {
			error_reporting(0); // phpcs:ignore -- try to disabled the error_log somewhere in WP.
		}

		ob_start();
		$this->setup_wizard_header();
		$this->setup_wizard_steps();
		$this->setup_wizard_content();
		$this->setup_wizard_footer();
		?>
		<?php
		if ( get_option( 'mainwp_enable_guided_tours', 0 ) ) {
			self::mainwp_usetiful_tours();
		}
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
			return esc_url_raw( remove_query_arg( array( 'noregister', 'message' ), add_query_arg( 'step', $step ) ) );
		}
		$keys = array_keys( $this->steps );
		return esc_url_raw( remove_query_arg( array( 'noregister', 'message' ), add_query_arg( 'step', $keys[ array_search( $this->step, array_keys( $this->steps ) ) + 1 ] ) ) );
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
			return esc_url_raw( remove_query_arg( array( 'noregister', 'message' ), add_query_arg( 'step', $step ) ) );
		}
		$keys = array_keys( $this->steps );
		return esc_url_raw( remove_query_arg( array( 'noregister', 'message' ), add_query_arg( 'step', $keys[ array_search( $this->step, array_keys( $this->steps ) ) - 1 ] ) ) );
	}

	/**
	 * Method setup_wizard_header()
	 *
	 * Render Setup Wizard's header.
	 */
	public function setup_wizard_header() {
		$selected_theme = MainWP_Settings::get_instance()->get_selected_theme();
		?>
		<!DOCTYPE html>
		<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?> class="mainwp-quick-setup">
			<head>
				<meta name="viewport" content="width=device-width" />
				<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
				<title><?php esc_html_e( 'MainWP &rsaquo; Setup Wizard', 'mainwp' ); ?></title>
				<?php wp_print_scripts( 'mainwp' ); ?>
				<?php wp_print_scripts( 'mainwp-clients' ); ?>
				<?php wp_print_scripts( 'mainwp-setup' ); ?>
				<?php wp_print_scripts( 'fomantic' ); ?>
				<?php wp_print_scripts( 'mainwp-ui' ); ?>
				<?php
				// to fix warning.
				/**
				 * Remove the deprecated `print_emoji_styles` handler.
				 * It avoids breaking style generation with a deprecation message.
				 */
				$has_emoji_styles = has_action( 'admin_print_styles', 'print_emoji_styles' );
				if ( $has_emoji_styles ) {
					remove_action( 'admin_print_styles', 'print_emoji_styles' );
				}
				?>
				<?php do_action( 'admin_print_styles' ); ?>
				<script type="text/javascript"> var ajaxurl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';</script>
				<script type="text/javascript">var mainwp_ajax_nonce = "<?php echo esc_js( wp_create_nonce( 'mainwp_ajax' ) ); ?>", mainwp_js_nonce = "<?php echo esc_js( wp_create_nonce( 'mainwp_nonce' ) ); ?>";</script>
			</head>
			<body class="mainwp-ui <?php echo ! empty( $selected_theme ) ? 'mainwp-custom-theme' : ''; ?> mainwp-ui-setup">
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
			<?php foreach ( $ouput_steps as $step_key => $step ) { ?>
				<?php
				if ( isset( $step['hidden'] ) && $step['hidden'] ) {
					continue;
				}

				if ( 'welcome' === $step_key ) {
					continue;
				}
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
			<?php } ?>
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
	 * Method mwp_setup_welcome()
	 *
	 * Renders the Welcome screen of the Quick Start Wizard
	 */
	public function mwp_setup_welcome() {
		$this->mwp_setup_ready_actions();
		$is_new       = MainWP_Demo_Handle::get_instance()->is_new_instance();
		$enabled_demo = apply_filters( 'mainwp_demo_mode_enabled', false );
		?>
		<h1 class="ui header"><?php esc_html_e( 'Welcome to your MainWP Dashboard!', 'mainwp' ); ?></h1>
		<div class="ui message" id="mainwp-message-zone" style="display:none"></div>
		<div class="ui hidden divider"></div>
		<h3><?php esc_html_e( 'Are you ready to get started adding your sites?', 'mainwp' ); ?></h3>
		<a class="ui big green basic button" href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp-setup&step=introduction' ) ); ?>"><?php esc_html_e( 'Start the MainWP Quick Setup Wizard', 'mainwp' ); ?></a>
		<div class="ui hidden divider"></div>
		<h3><?php esc_html_e( 'Would you like to see Demo content first? ', 'mainwp' ); ?> - <?php printf( esc_html__( '%sWhat is this?%s', 'mainwp' ), '<a href="https://www.youtube.com/watch?v=fCHT47AKt7s" target="_blank">', '</a>' ); ?></h3>
		<p><?php esc_attr_e( 'Explore MainWP\'s capabilities using our pre-loaded demo content.', 'mainwp' ); ?></p>
		<p><?php esc_attr_e( 'It\'s the perfect way to experience the benefits and ease of use MainWP provides without connecting to any of your own sites.', 'mainwp' ); ?></p>
		<p><?php esc_html_e( 'The demo content serves as placeholder data to give you a feel for the MainWP Dashboard. Please note that because no real websites are connected in this demo, some functionality will be restricted. Features that require a connection to actual websites will be disabled for the duration of the demo.', 'mainwp' ); ?></p>
		<p><?php esc_attr_e( 'Click this button to import the Demo content to your MainWP Dashboard and enable the Demo mode.', 'mainwp' ); ?></p>
		<p><span><button class="ui big green button mainwp-import-demo-data-button" page-import="qsw-import" <?php echo ( ! $is_new || $enabled_demo ? 'disabled="disabled"' : '' ); ?>><?php esc_html_e( 'Enable Demo Mode With Guided Tours', 'mainwp' ); ?></button></span></p>
		<div class="ui blue message">
			<?php printf( esc_html__( 'Guided tours feature is implemented using Javascript provided by Usetiful and is subject to the %1$sUsetiful Privacy Policy%2$s.', 'mainwp' ), '<a href="https://www.usetiful.com/privacy-policy" target="_blank">', '</a>' ); ?>
		</div>
		<?php
		MainWP_System_View::render_comfirm_modal();
	}

	/**
	 * Method mwp_setup_introduction()
	 *
	 * Renders the Introduction screen of the Quick Start Wizard
	 */
	public function mwp_setup_introduction() {
		?>
		<div class="ui message" id="mainwp-message-zone" style="display:none"></div>
		<h1 class="ui header"><?php esc_html_e( 'MainWP Quick Setup Wizard', 'mainwp' ); ?></h1>
		<p><?php esc_html_e( 'Thank you for choosing MainWP for managing your WordPress sites. This quick setup wizard will help you configure the basic settings. It\'s completely optional and shouldn\'t take longer than five minutes.', 'mainwp' ); ?></p>
		<div class="ui hidden divider"></div>
		<a href="https://kb.mainwp.com/docs/quick-setup-wizard-video/" target="_blank" class="ui big icon green button"><i class="youtube icon"></i> <?php esc_html_e( 'Walkthrough', 'mainwp' ); ?></a>
		<div class="ui hidden divider"></div>
		<p><?php esc_html_e( 'If you don\'t want to go through the setup wizard, you can skip and proceed to your MainWP Dashboard by clicking the "Not right now" button. If you change your mind, you can come back later by starting the Setup Wizard from the MainWP > Settings > MainWP Tools page!', 'mainwp' ); ?></p>
		<div class="ui hidden divider"></div>
		<form method="post" class="ui form">
			<h1><?php esc_html_e( 'MainWP Guided Tours', 'mainwp' ); ?> <span class="ui blue mini label"><?php esc_html_e( 'BETA', 'mainwp' ); ?></span></h1>
				<?php esc_html_e( 'MainWP guided tours are designed to provide information about all essential features on each MainWP Dashboard page.', 'mainwp' ); ?>
				<div class="ui blue message">
					<?php printf( esc_html__( 'This feature is implemented using Javascript provided by Usetiful and is subject to the %1$sUsetiful Privacy Policy%2$s.', 'mainwp' ), '<a href="https://www.usetiful.com/privacy-policy" target="_blank">', '</a>' ); ?>
				</div>
				<div class="ui form">
				<div class="field">
				<div class="ui hidden divider"></div>
					<label><?php esc_html_e( 'Do you want to enable MainWP Guided Tours?', 'mainwp' ); ?></label>
					<div class="ui hidden divider"></div>
					<div class="ui toggle checkbox">
						<input type="checkbox" name="mainwp-guided-tours-option" id="mainwp-guided-tours-option" checked="true">
						<label for="mainwp-guided-tours-option"><?php esc_html_e( 'Select to enable the MainWP Guided Tours.', 'mainwp' ); ?><span class="ui left pointing green label"><?php esc_html_e( 'Highly recommended if new to MainWP!', 'mainwp' ); ?></span></label>
					</div>
				</div>
			</div>
			<div class="ui hidden divider"></div>
			<p><?php esc_html_e( 'To go back to the WordPress Admin section, click the "Back to WP Admin" button.', 'mainwp' ); ?></p>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<input type="submit" class="ui big green right floated button" value="<?php esc_html_e( 'Let\'s Go!', 'mainwp' ); ?>" name="save_step" />
			<a href="<?php echo esc_url( admin_url( 'index.php' ) ); ?>" class="ui big button"><?php esc_html_e( 'Back to WP Admin', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&do=new' ) ); ?>" class="ui big button"><?php esc_html_e( 'Not Right Now', 'mainwp' ); ?></a>
			<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>		
		<?php
		MainWP_System_View::render_comfirm_modal();
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
		<?php MainWP_System_View::mainwp_warning_notice(); ?>
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
							<div><em><?php esc_html_e( 'Due to bug with PHP on some servers, enter the openssl.cnf file location so MainWP Dashboard can connect to your child sites. If your openssl.cnf file is saved to a different path from what is entered above please enter your exact path. ', 'mainwp' ); ?><?php printf( esc_html__( '%1$sClick here%2$s to see how to find the OpenSSL.cnf file.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/how-to-find-the-openssl-cnf-file/" target="_blank">', '</a>' ); ?></em></div>
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
				<?php
			} else {
				?>
				<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big green right floated button"><?php esc_html_e( 'Continue', 'mainwp' ); ?></a>
			<?php } ?>
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php esc_html_e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>
			<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>
		<?php
	}


	/**
	 * Method mwp_setup_introduction_save()
	 *
	 * Installation Step save to DB.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public function mwp_setup_introduction_save() {
		check_admin_referer( 'mwp-setup' );
		$enabled_tours = ! isset( $_POST['mainwp-guided-tours-option'] ) ? 0 : 1; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		MainWP_Utility::update_option( 'mainwp_enable_guided_tours', $enabled_tours );
		wp_safe_redirect( $this->get_next_step_link() );
		exit;
	}

	/**
	 * Method mwp_setup_connect_first_site_save()
	 *
	 * Installation Step after connect first site.
	 */
	public function mwp_setup_connect_first_site_save() {
		check_admin_referer( 'mwp-setup' );
		if ( isset( $_POST['mainwp-qsw-confirm-add-new-client'] ) && ! empty( $_POST['mainwp-qsw-confirm-add-new-client'] ) ) {
			wp_safe_redirect( $this->get_next_step_link() );
		} else {
			wp_safe_redirect( $this->get_next_step_link( 'monitoring' ) );
		}
		exit;
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
		if ( isset( $_POST['mwp_setup_openssl_lib_location'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			MainWP_Utility::update_option( 'mainwp_opensslLibLocation', isset( $_POST['mwp_setup_openssl_lib_location'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_setup_openssl_lib_location'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		wp_safe_redirect( $this->get_next_step_link() );
		exit;
	}



	/**
	 * Method mwp_setup_connect_first_site_already()
	 *
	 * Render Added first Child Site Step form.
	 */
	public function mwp_setup_connect_first_site_already() {
		$count_clients = MainWP_DB_Client::instance()->count_total_clients();
		?>
		<h1 class="ui header"><?php esc_html_e( 'Congratulations!', 'mainwp' ); ?></h1>
		<p><?php esc_html_e( 'You have successfully connected your first site to your MainWP Dashboard!', 'mainwp' ); ?></p>
		<div class="ui form">
			<form method="post" class="ui form">
				<?php if ( empty( $count_clients ) ) { ?>
					<div class="field">
						<label><?php esc_html_e( 'Do you want to create a client for your first child site?', 'mainwp' ); ?></label>
						<div class="ui hidden divider"></div>
						<div class="ui toggle checkbox">
						<input type="checkbox" name="mainwp-qsw-confirm-add-new-client" id="mainwp-qsw-confirm-add-new-client" checked="true"/>
							<label><?php esc_html_e( 'Select to create a New Client', 'mainwp' ); ?></label>
					</div>
				</div>
				<?php } ?>
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<div class="ui clearing hidden divider"></div>
				<div class="ui hidden divider"></div>
				<div class="ui hidden divider"></div>
				<input type="submit" class="ui big green right floated button" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
				<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
				<input type="hidden" id="nonce_secure_data" mainwp_addwp="<?php echo esc_js( wp_create_nonce( 'mainwp_addwp' ) ); ?>" mainwp_checkwp="<?php echo esc_attr( wp_create_nonce( 'mainwp_checkwp' ) ); ?>" />
			</form>
		</div>	
		<?php
	}


	/**
	 * Method mwp_setup_connect_first_site()
	 *
	 * Render Install first Child Site Step form.
	 */
	public function mwp_setup_connect_first_site() {
		$count = MainWP_DB::instance()->get_websites_count( null, true );
		if ( 1 <= $count ) {
			$this->mwp_setup_connect_first_site_already();
			return;
		}
		?>
		<h1><?php esc_html_e( 'Connect Your First Child Site', 'mainwp' ); ?></h1>
		<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-qsw-add-site-message' ) ) { ?>
			<div class="ui info message">
				<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-qsw-add-site-message"></i>
				<?php esc_html_e( 'MainWP requires the MainWP Child plugin to be installed and activated on the WordPress site that you want to connect to your MainWP Dashboard.  ', 'mainwp' ); ?>
			<?php esc_html_e( 'To install the MainWP Child plugin, please follow these steps:', 'mainwp' ); ?>
			<ol>
				<li><?php printf( esc_html__( 'Login to the WordPress site you want to connect %1$s(open it in a new browser tab)%2$s', 'mainwp' ), '<em>', '</em>' ); ?></li>
				<li><?php printf( esc_html__( 'Go to the %1$sWP > Plugins%2$s page', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
				<li><?php printf( esc_html__( 'Click %1$sAdd New%2$s to install a new plugin', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
				<li><?php printf( esc_html__( 'In the %1$sSearch Field%2$s, enter "MainWP Child" and once the plugin shows, click the Install button', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
				<li><?php printf( esc_html__( '%1$sActivate%2$s the plugin', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
			</ol>
		</div>
		<?php } ?>
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
										<select class="ui compact selection dropdown" id="mainwp_managesites_add_wpurl_protocol" name="mainwp_managesites_add_wpurl_protocol" style="width:120px;padding:0px;">
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
					</div>
				</div>
			<div class="ui clearing hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<input type="button" style="display:none" name="mainwp_managesites_add" id="mainwp_managesites_add" class="ui button green big right floated" value="<?php esc_attr_e( 'Connect Site', 'mainwp' ); ?>" />
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" id="mainwp_addsite_continue_button" class="ui big green right floated button"><?php esc_html_e( 'Continue', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>
			<?php wp_nonce_field( 'mwp-setup' ); ?>
			<input type="hidden" id="nonce_secure_data" mainwp_addwp="<?php echo esc_js( wp_create_nonce( 'mainwp_addwp' ) ); ?>" mainwp_checkwp="<?php echo esc_attr( wp_create_nonce( 'mainwp_checkwp' ) ); ?>" />
		</form>
		<?php
	}

	/**
	 * Method mwp_setup_add_client()
	 *
	 * Render Add first Client Step form.
	 */
	public function mwp_setup_add_client() {
		$count         = MainWP_DB::instance()->get_websites_count( null, true );
		$count_clients = MainWP_DB_Client::instance()->count_total_clients();
		if ( ! empty( $count_clients ) ) {
			?>
			<h1 class="ui header"><?php esc_html_e( 'Congratulations!', 'mainwp' ); ?></h1>
			<p><?php esc_html_e( 'You have successfully created your first Client.', 'mainwp' ); ?></p>
			<?php
		} else {
			$first_site_id = get_transient( 'mainwp_transient_just_connected_site_id' );
			?>
			<h1><?php esc_html_e( 'Create a Client', 'mainwp' ); ?></h1>
			<div class="ui secondary segment">
					<form action="" method="post" enctype="multipart/form-data" name="createclient_form" id="createclient_form" class="add:clients: validate">
						<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
						<div class="">
							<div class="ui hidden divider"></div>
							<div class="ui message" id="mainwp-message-zone-client" style="display:none;"></div>
							<div id="mainwp-add-new-client-form" >						
							<?php $this->render_add_client_content( false, true ); ?>
							</div>
						</div>
						<input type="hidden" name="selected_first_site" value="<?php echo intval( $first_site_id ); ?>">
					</form>
				<div class="ui clearing hidden divider"></div>
			</div>
			<?php
		}
		?>
		<div class="ui hidden divider"></div>
		<div class="ui hidden divider"></div>
		<input type="button" style="display:none" name="createclient" current-page="qsw-add" id="bulk_add_createclient" class="ui big green right floated button" value="<?php echo esc_attr__( 'Add Client', 'mainwp' ); ?> "/>
		<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" id="mainwp_qsw_add_client_continue_button" class="ui big green right floated button"><?php esc_html_e( 'Continue', 'mainwp' ); ?></a>
		<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>	
		<?php
	}


	/**
	 * Method render_add_client_content().
	 *
	 * Renders add client content window.
	 */
	public function render_add_client_content() {
		$edit_client           = false;
		$client_id             = 0;
		$client_image          = '';
		$default_client_fields = MainWP_Client_Handler::get_mini_default_client_fields();
		?>
		<div class="ui form">
			<?php
			foreach ( $default_client_fields as $field_name => $field ) {
				$db_field = isset( $field['db_field'] ) ? $field['db_field'] : '';
				$val      = $edit_client && '' !== $db_field && property_exists( $edit_client, $db_field ) ? $edit_client->{$db_field} : '';
				$tip      = isset( $field['tooltip'] ) ? $field['tooltip'] : '';
				?>
				<div class="field">
					<label <?php echo '' !== $tip ? 'data-tooltip="' . esc_attr( $tip ) . '" data-inverted="" data-position="top left"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_html( $field['title'] ); ?></label>
					<input type="text" value="<?php echo esc_html( $val ); ?>" id="mainwp_qsw_client_name_field" class="regular-text" name="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]"/>
				</div>
					<?php

					if ( 'client.name' === $field_name ) {
						?>
						<div class="field">
							<label><?php esc_html_e( 'Client photo', 'mainwp' ); ?></label>
							<div data-tooltip="<?php esc_attr_e( 'Upload a client photo.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
								<div class="ui file input">
								<input type="file" name="mainwp_client_image_uploader[client_field]" accept="image/*" data-inverted="" data-tooltip="<?php esc_attr_e( "Image must be 500KB maximum. It will be cropped to 310px wide and 70px tall. For best results  us an image of this site. Allowed formats: jpeg, gif and png. Note that animated gifs aren't going to be preserved.", 'mainwp' ); ?>" />
							</div>
						</div>
						</div>
						<?php
					}
			}
			$primary_contact_id = 0;
			$temp               = $this->get_add_contact_temp( false );
			?>
			<div class="field">
			<a href="javascript:void(0);" class="mainwp-client-add-contact" add-contact-temp="<?php echo esc_attr( $temp ); ?>"><i class="ui eye icon"></i><?php esc_html_e( 'Add Additional Contact', 'mainwp' ); ?></a>
			</div>
		<div class="ui section hidden divider after-add-contact-field"></div>
		</div>
		<input type="hidden" name="client_fields[client_id]" value="<?php echo intval( $client_id ); ?>">
		<?php
	}


	/**
	 * Method get_add_contact_temp().
	 *
	 * Get add contact template.
	 *
	 * @param bool $echo_out Echo template or not.
	 */
	public function get_add_contact_temp( $echo_out = false ) {

		$input_name    = 'new_contacts_field';
		$contact_id    = 0;
		$contact_image = '';

		ob_start();
		?>
			<div class="ui hidden divider top-contact-fields"></div> <?php // must have class: top-contact-fields. ?>
			<div class="ui horizontal divider"><?php esc_html_e( 'Add Contact', 'mainwp' ); ?></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<?php
			$contact_fields = MainWP_Client_Handler::get_mini_default_contact_fields();
			foreach ( $contact_fields as $field_name => $field ) {
				$db_field   = isset( $field['db_field'] ) ? $field['db_field'] : '';
				$val        = '';
				$contact_id = '';
				?>
				<div class="field">
					<label><?php echo esc_html( $field['title'] ); ?></label>
					<input type="text" value="<?php echo esc_html( $val ); ?>" class="regular-text" name="client_fields[<?php echo esc_html( $input_name ); ?>][<?php echo esc_attr( $field_name ); ?>][]"/>
				</div>
				<?php
			}
			?>
			<div class="field remove-contact-field-parent">
				<a href="javascript:void(0);" contact-id="<?php echo intval( $contact_id ); ?>" class="mainwp-client-remove-contact"><i class="ui eye icon"></i><?php esc_html_e( 'Remove contact', 'mainwp' ); ?></a>
			</div>
			<div class="ui section hidden divider bottom-contact-fields"></div>
			<?php
			$html = ob_get_clean();
			if ( $echo_out ) {
				echo $html; //phpcs:ignore -- validated content.
			}
			return $html;
	}

	/**
	 * Method mwp_setup_monitoring()
	 *
	 * Render Monitoring Step.
	 */
	public function mwp_setup_monitoring() {

		$disableSitesMonitoring = get_option( 'mainwp_disableSitesChecking', 1 );
		$frequencySitesChecking = (int) get_option( 'mainwp_frequencySitesChecking', 60 );

		$disableSitesHealthMonitoring = get_option( 'mainwp_disableSitesHealthMonitoring', 1 );
		$sitehealthThreshold          = get_option( 'mainwp_sitehealthThreshold', 80 ); // "Should be improved" threshold.

		?>
		<h1 class="ui header">
			<?php esc_html_e( 'Basic Uptime Monitoring', 'mainwp' ); ?>
		</h1>
		<form method="post" class="ui form">
			<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
			<div class="ui grid field">
				<div class="ui info message"><?php printf( esc_html__( 'Excessive checking can cause server resource issues.  For frequent checks or lots of sites, we recommend the %1$sMainWP Advanced Uptime Monitoring%2$s extension.', 'mainwp' ), '<a href="https://mainwp.com/extension/advanced-uptime-monitor" target="_blank">', '</a>' ); ?></div>
				<label class="six wide column middle aligned"><?php esc_html_e( 'Enable basic uptime monitoring', 'mainwp' ); ?></label>
				<div class="ten wide column ui toggle checkbox mainwp-checkbox-showhide-elements" hide-parent="monitoring">
					<input type="checkbox" name="mainwp_setup_disableSitesChecking" id="mainwp_setup_disableSitesChecking" <?php echo ( 1 === $disableSitesMonitoring ? '' : 'checked="true"' ); ?>/>
					<label class=""></label>
				</div>
			</div>

			<div class="ui grid field" <?php echo $disableSitesMonitoring ? 'style="display:none"' : ''; ?> hide-element="monitoring">
				<label class="six wide column middle aligned"><?php esc_html_e( 'Check interval', 'mainwp' ); ?></label>
				<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Select preferred checking interval.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
					<select name="mainwp_setup_frequency_sitesChecking" id="mainwp_setup_frequency_sitesChecking" class="ui dropdown">
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
			<h1 class="ui header">
				<?php esc_html_e( 'Site Health Monitoring', 'mainwp' ); ?>
			</h1>
			<div class="ui grid field">
				<label class="six wide column middle aligned"><?php esc_html_e( 'Enable Site Health monitoring', 'mainwp' ); ?></label>
				<div class="ten wide column ui toggle checkbox mainwp-checkbox-showhide-elements" hide-parent="health-monitoring">
					<input type="checkbox" name="mainwp_setup_disable_sitesHealthMonitoring" id="mainwp_setup_disable_sitesHealthMonitoring" <?php echo ( 1 === $disableSitesHealthMonitoring ? '' : 'checked="true"' ); ?>/>
				</div>
			</div>

			<div class="ui grid field" <?php echo $disableSitesHealthMonitoring ? 'style="display:none"' : ''; ?> hide-element="health-monitoring">
				<label class="six wide column middle aligned"><?php esc_html_e( 'Site health threshold', 'mainwp' ); ?></label>
				<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Set preferred site health threshold.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
					<select name="mainwp_setup_site_healthThreshold" id="mainwp_setup_site_healthThreshold" class="ui dropdown">
						<option value="80" <?php echo ( ( 80 === $sitehealthThreshold || 0 === $sitehealthThreshold ) ? 'selected' : '' ); ?>><?php esc_html_e( 'Should be improved', 'mainwp' ); ?></option>
						<option value="100" <?php echo ( 100 === $sitehealthThreshold ? 'selected' : '' ); ?>><?php esc_html_e( 'Good', 'mainwp' ); ?></option>
					</select>
				</div>
			</div>
			<input type="submit" class="ui big green right floated button" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php esc_html_e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>				

			<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>
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
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		MainWP_Utility::update_option( 'mainwp_disableSitesChecking', ( ! isset( $_POST['mainwp_setup_disableSitesChecking'] ) ? 1 : 0 ) );
		$val = isset( $_POST['mainwp_setup_frequency_sitesChecking'] ) ? intval( $_POST['mainwp_setup_frequency_sitesChecking'] ) : 1440;
		MainWP_Utility::update_option( 'mainwp_frequencySitesChecking', $val );
		MainWP_Utility::update_option( 'mainwp_disableSitesHealthMonitoring', ( ! isset( $_POST['mainwp_setup_disable_sitesHealthMonitoring'] ) ? 1 : 0 ) );
		$val = isset( $_POST['mainwp_setup_site_healthThreshold'] ) ? intval( $_POST['mainwp_setup_site_healthThreshold'] ) : 80;
		MainWP_Utility::update_option( 'mainwp_sitehealthThreshold', $val );
		// phpcs:enable
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

	/**
	 * Render usetiful tours.
	 */
	public function mainwp_usetiful_tours() {
		echo "
		<script>
	(function (w, d, s) {
		var a = d.getElementsByTagName('head')[0];
		var r = d.createElement('script');
		r.async = 1;
		r.src = s;
		r.setAttribute('id', 'usetifulScript');
		r.dataset.token = '480fa17b0507a1c60abba94bfdadd0a7';
							a.appendChild(r);
	  })(window, document, 'https://www.usetiful.com/dist/usetiful.js');</script>
		";
	}
}
