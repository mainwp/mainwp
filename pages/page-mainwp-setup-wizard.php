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
			'introduction'         => array(
				'name'    => __( 'Introduction', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_introduction' ),
				'handler' => '',
			),
			'installation'         => array(
				'name'    => __( 'Installation', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_installation' ),
				'handler' => array( $this, 'mwp_setup_installation_save' ),
			),
			'system_check'         => array(
				'name'    => __( 'System', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_system_requirements' ),
				'handler' => '',
			),
			'install_mainwp_child' => array(
				'name'    => __( 'Install MainWP Child', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_install_mainwp_child' ),
				'handler' => '',
			),
			'connect_first_site'   => array(
				'name'    => __( 'Connect First Site', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_connect_first_site' ),
				'handler' => '',
			),
			'optimization'         => array(
				'name'    => __( 'Optimization', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_optimization' ),
				'handler' => array( $this, 'mwp_setup_optimization_save' ),
			),
			'monitoring'           => array(
				'name'    => __( 'Monitoring', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_monitoring' ),
				'handler' => array( $this, 'mwp_setup_monitoring_save' ),
			),
			'notification'         => array(
				'name'    => __( 'Notifications', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_notification' ),
				'handler' => array( $this, 'mwp_setup_notification_save' ),
			),
			'next_steps'           => array(
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
     *
     * @uses \MainWP\Dashboard\MainWP_UI::usersnap_integration()
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
					<?php if ( MainWP_UI::usersnap_integration() ) { ?>
					<div class="row" style="position:fixed !important; bottom: 10px;">
						<div class="right aligned column">
							<a class="ui button black icon" id="usersnap-bug-report-button" data-position="top right" data-inverted="" data-tooltip="<?php esc_attr_e( 'Click here (or use Ctrl + U keyboard shortcut) to open the Bug reporting mode.', 'mainwp' ); ?>" target="_blank" href="#">
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

	/**
	 * Method setup_wizard_steps()
	 *
	 * Render Setup Wizards Steps.
	 */
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
		echo '<div class="mainwp-quick-setup-wizard-steps-content" style="float:right; width:72%;">';
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
	 * Method mwp_setup_installation()
	 *
	 * Render Installation Step.
	 */
	public function mwp_setup_installation() {
		$hostingType = get_option( 'mwp_setup_installationHostingType' );
		$systemType  = get_option( 'mwp_setup_installationSystemType' );
		$style       = 2 == $hostingType ? '' : 'style="display: none"';
		$disabled    = 2 == $hostingType ? '' : 'disabled="disabled"';
		$openssl_loc = get_option( 'mwp_setup_opensslLibLocation', 'c:\xampplite\apache\conf\openssl.cnf' );
		?>
		<h1 class="ui header"><?php esc_html_e( 'Installation', 'mainwp' ); ?></h1>
		<form method="post" class="ui form">
			<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
			<div class="grouped fields">
				<label><?php esc_html_e( 'What type of server is this?', 'mainwp' ); ?></label>
				<div class="field" id="mainwp-setup-installation-hosting-type">
					<div class="ui radio checkbox">
						<input type="radio" name="mwp_setup_installation_hosting_type" required="required" <?php echo ( 1 == $hostingType ? 'checked="true"' : '' ); ?> value="1">
						<label><?php esc_html_e( 'Web Host', 'mainwp' ); ?></label>
					</div>
				</div>
				<div class="field">
					<div class="ui radio checkbox">
						<input type="radio" name="mwp_setup_installation_hosting_type" required="required" <?php echo ( 2 == $hostingType ? 'checked="true"' : '' ); ?> value="2">
						<label><?php esc_html_e( 'Localhost', 'mainwp' ); ?></label>
					</div>
				</div>
			</div>
			<div id="mainwp-quick-setup-system-type" class="grouped fields" <?php echo $style; ?>>
				<label><?php esc_html_e( 'What operating system do you use?', 'mainwp' ); ?></label>
				<div class="field">
					<div class="ui radio checkbox">
						<input type="radio" name="mwp_setup_installation_system_type" required="required" <?php echo ( 1 == $systemType ? 'checked="true"' : '' ); ?> value="1" <?php echo $disabled; ?>>
						<label><?php esc_html_e( 'MacOS', 'mainwp' ); ?></label>
					</div>
				</div>
				<div class="field">
					<div class="ui radio checkbox">
						<input type="radio" name="mwp_setup_installation_system_type" required="required" <?php echo ( 2 == $systemType ? 'checked="true"' : '' ); ?> value="2" <?php echo $disabled; ?>>
						<label><?php esc_html_e( 'Linux', 'mainwp' ); ?></label>
					</div>
				</div>
				<div class="field">
					<div class="ui radio checkbox">
						<input type="radio" name="mwp_setup_installation_system_type" required="required" <?php echo ( 3 == $systemType ? 'checked="true"' : '' ); ?> value="3" <?php echo $disabled; ?>>
						<label><?php esc_html_e( 'Windows', 'mainwp' ); ?></label>
					</div>
				</div>
			</div>
			<div id="mainwp-quick-setup-opessl-location" class="grouped fields" <?php echo 3 == $systemType ? '' : 'style="display:none"'; ?>>
				<label><?php esc_html_e( 'What is the OpenSSL.cnf file location on your computer?', 'mainwp' ); ?></label>
				<div class="ui info message"><?php esc_html_e( 'Due to bug with PHP on Windows, enter the OpenSSL.cnf file location so MainWP Dashboard can connect to your child sites.', 'mainwp' ); ?></div>
				<div class="field" id="mainwp-setup-installation-openssl-location">
					<div class="ui fluid input">
						<input type="text" name="mwp_setup_openssl_lib_location" value="<?php echo esc_attr( $openssl_loc ); ?>">
					</div>
					<div><em><?php esc_html_e( 'If your openssl.cnf file is saved to a different path from what is entered above please enter your exact path.', 'mainwp' ); ?></em></div>
					<div><em><?php echo sprintf( __( '%1$sClick here%2$s to see how to find the OpenSSL.cnf file.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/how-to-find-the-openssl-cnf-file/" target="_blank">', '</a>' ); ?></em></div>
				</div>
			</div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<input type="submit" class="ui big green right floated button" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
			<a href="<?php echo esc_url( $this->get_next_step_link( 'system_check' ) ); ?>" class="ui big button"><?php esc_html_e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>
			<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>
		<?php
	}

	/**
	 * Method mwp_setup_installation_save()
	 *
	 * Installation Step save to DB.
	 */
	public function mwp_setup_installation_save() {
		check_admin_referer( 'mwp-setup' );

		$hosting_type = isset( $_POST['mwp_setup_installation_hosting_type'] ) ? intval( $_POST['mwp_setup_installation_hosting_type'] ) : 0;
		$system_type  = isset( $_POST['mwp_setup_installation_system_type'] ) ? intval( $_POST['mwp_setup_installation_system_type'] ) : 0;

		MainWP_Utility::update_option( 'mwp_setup_installationHostingType', $hosting_type );

		if ( 1 == $hosting_type ) {
			$system_type = 0;
		}

		MainWP_Utility::update_option( 'mwp_setup_installationSystemType', $system_type );
		MainWP_Utility::update_option( 'mwp_setup_opensslLibLocation', isset( $_POST['mwp_setup_openssl_lib_location'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_setup_openssl_lib_location'] ) ) : '' );

		wp_safe_redirect( $this->get_next_step_link( 'system_check' ) );

		exit;
	}

	/**
	 * Method  mwp_setup_system_requirements()
	 *
	 * Render System Requirments Step.
	 */
	public function mwp_setup_system_requirements() {
		$hosting_type = get_option( 'mwp_setup_installationHostingType' );
		$system_type  = get_option( 'mwp_setup_installationSystemType' );
		$back_step    = 'installation';
		if ( 3 == $system_type && 2 == $hosting_type ) {
			$back_step = '';
		}
		?>
			<h1><?php esc_html_e( 'System Requirements Check', 'mainwp' ); ?></h1>
			<div class="ui message warning"><?php esc_html_e( 'Any Warning here may cause the MainWP Dashboard to malfunction. After you complete the Quick Start setup it is recommended to contact your host’s support and updating your server configuration for optimal performance.', 'mainwp' ); ?></div>
			<?php MainWP_Server_Information::render_quick_setup_system_check(); ?>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big green right floated button"><?php esc_html_e( 'Continue', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php esc_html_e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link( $back_step ) ); ?>" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>
			<?php wp_nonce_field( 'mwp-setup' ); ?>
		<?php
	}

	/**
	 * Method mwp_setup_install_mainwp_child()
	 *
	 * Render Install MainWP Child Plugin on Child Site Step.
	 */
	public function mwp_setup_install_mainwp_child() {
		?>
		<h1><?php esc_html_e( 'Add Your First WordPress Site To Your Dashboard', 'mainwp' ); ?></h1>
			<div class="ui info message">
				<?php esc_html_e( 'MainWP requires the MainWP Child plugin to be installed and activated on the WordPress site that you want to connect to your MainWP Dashboard.', 'mainwp' ); ?>
				<?php esc_html_e( 'If you have it installed, click the "MainWP Child Plugin Installed" button to connect the site, if not, follow these instructions to install it.', 'mainwp' ); ?><br /><br />
				<?php printf( __( 'If you need additional help with installing the MainWP Child, please see this %1$shelp document%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/set-up-the-mainwp-plugin/install-mainwp-child/" target="_blank">', '</a>' ); ?>
			</div>
			<ol>
				<li><?php printf( __( 'Login to the WordPress site you want to connect %1$s(open it in a new browser tab)%2$s', 'mainwp' ), '<em>', '</em>' ); ?></li>
				<li><?php printf( __( 'Go to the %1$sWP > Plugins%2$s page', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
				<li><?php printf( __( 'Click %1$sAdd New%2$s to install a new plugin', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
				<li><?php printf( __( 'In the %1$sSearch Field%2$s, enter "MainWP Child" and once the plugin shows, click the Install button', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
				<li><?php printf( __( '%1$sActivate%2$s the plugin', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
			</ol>
			<div class="ui clearing hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<a href="admin.php?page=mainwp-setup&step=connect_first_site" class="ui big green right floated button"><?php esc_attr_e( 'MainWP Child Plugin Installed', 'mainwp' ); ?></a>
			<a href="admin.php?page=mainwp-setup&step=connect_first_site" class="ui big button"><?php esc_html_e( 'Skip For Now', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>
			<?php wp_nonce_field( 'mwp-setup' ); ?>
		<?php
	}

	/**
	 * Method mwp_setup_connect_first_site()
	 *
	 * Render Install first Child Site Step form.
	 */
	public function mwp_setup_connect_first_site() {
		?>
		<h1><?php esc_html_e( 'Connect Your First Child Site', 'mainwp' ); ?></h1>
		<form method="post" class="ui form">
			<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
			<div class="ui message" id="mainwp-message-zone" style="display:none"></div>
			<div class="ui red message" id="mainwp-error-zone" style="display:none"></div>
			<div class="ui green message" id="mainwp-success-zone" style="display:none"></div>
			<div class="ui info message" id="mainwp-info-zone" style="display:none"></div>
			<div class="ui hidden divider"></div>
			<div class="field">
				<label><?php esc_html_e( 'What is the site URL? ', 'mainwp' ); ?></label>
				<div class="ui grid">
					<div class="four wide column">
						<select class="ui dropdown" id="mainwp_managesites_add_wpurl_protocol" name="mainwp_managesites_add_wpurl_protocol">
							<option value="https">https://</option>
							<option value="http">http://</option>
						</select>
					</div>
					<div class="twelve wide column">
						<input type="text" id="mainwp_managesites_add_wpurl" name="mainwp_managesites_add_wpurl" value="" placeholder="yoursite.com" />
					</div>
				</div>
			</div>
			<div class="field">
				<label><?php esc_html_e( 'What is your Administrator username on that site? ', 'mainwp' ); ?></label>
				<input type="text" id="mainwp_managesites_add_wpadmin" name="mainwp_managesites_add_wpadmin" value="" />
			</div>
			<div class="field">
				<label><?php esc_html_e( 'Add Site Title, if left blank URL is used', 'mainwp' ); ?></label>
				<input type="text" id="mainwp_managesites_add_wpname" name="mainwp_managesites_add_wpname" value="" />
			</div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui horizontal divider"><?php esc_html_e( 'Optional Settings', 'mainwp' ); ?></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="field">
				<label><?php esc_html_e( 'Did you generate Unique Security ID on the site? If yes, copy it here, if not, leave this field blank. ', 'mainwp' ); ?></label>
				<input type="text" id="mainwp_managesites_add_uniqueId" name="mainwp_managesites_add_uniqueId" value="" />
			</div>
			<div class="field">
				<label><?php esc_html_e( 'Do you have a valid SSL certificate on the site? If it\'s expired or self-signed, disable this option.', 'mainwp' ); ?></label>
				<div class="ui toggle checkbox">
					<input type="checkbox" name="mainwp_managesites_verify_certificate" id="mainwp_managesites_verify_certificate" checked="true" />
					<label></label>
				</div>
			</div>
			<div class="ui divider"></div>
			<input type="button" name="mainwp_managesites_add" id="mainwp_managesites_add" class="ui button green big" value="<?php esc_attr_e( 'Connect Site', 'mainwp' ); ?>" />

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
	 * Method mwp_setup_hosting()
	 *
	 * Redner Hosting Setup step form.
	 */
	public function mwp_setup_hosting() {
		$installation_hosting_type = get_option( 'mwp_setup_installationHostingType' );
		$system_type               = get_option( 'mwp_setup_installationSystemType' );
		$hosting_settings          = array_filter( (array) get_option( 'mwp_setup_hostingSetup', array() ) );
		$typeHosting               = isset( $hosting_settings['type_hosting'] ) ? $hosting_settings['type_hosting'] : false;
		$managePlanning            = isset( $hosting_settings['manage_planning'] ) ? $hosting_settings['manage_planning'] : false;
		$style                     = ( 3 == $typeHosting ) && ( 2 == $managePlanning ) ? '' : ' style="display: none" ';
		?>
		<h1 class="ui header"><?php esc_html_e( 'Performance', 'mainwp' ); ?></h1>
		<form method="post" class="ui form">
			<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
			<?php if ( 1 == $installation_hosting_type ) : ?>
			<div class="field">
				<label><?php esc_html_e( 'What type of hosting is this MainWP Dashboard site on?', 'mainwp' ); ?></label>
				<select name="mwp_setup_type_hosting" id="mwp_setup_type_hosting" class="ui dropdown">
					<option value="3"
					<?php
					if ( false == $typeHosting || 3 == $typeHosting ) {
						?>
						selected<?php } ?>><?php esc_html_e( 'Shared hosting environment', 'mainwp' ); ?></option>
					<option value="1"
					<?php
					if ( 1 == $typeHosting ) {
						?>
						selected<?php } ?>><?php esc_html_e( 'Virtual Private Server (VPS)', 'mainwp' ); ?></option>
					<option value="2"
					<?php
					if ( 2 == $typeHosting ) {
						?>
						selected<?php } ?>><?php esc_html_e( 'Dedicated server', 'mainwp' ); ?></option>
				</select>
			</div>
			<?php endif; ?>
			<div class="field">
				<label><?php esc_html_e( 'How many child sites are you planning to manage?', 'mainwp' ); ?></label>
				<select name="mwp_setup_manage_planning" id="mwp_setup_manage_planning" class="ui dropdown">
					<option value="1"
					<?php
					if ( ( false == $managePlanning ) || ( 1 == $managePlanning ) ) {
						?>
						selected<?php } ?>><?php esc_html_e( 'Less than 50 websites', 'mainwp' ); ?></option>
					<option value="2"
					<?php
					if ( 2 == $managePlanning ) {
						?>
						selected<?php } ?>><?php esc_html_e( 'More than 50 websites', 'mainwp' ); ?></option>
				</select>
			</div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<input type="submit" class="ui big green right floated button" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php esc_html_e( 'Skip', 'mainwp' ); ?></a>
			<a href="admin.php?page=mainwp-setup&step=install_mainwp_child" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>
			<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>

		<div id="mainwp-setup-hosting-notice" class="ui modal">
			<div class="header"><?php esc_html_e( 'Important Notice', 'mainwp' ); ?></div>
			<div class="content">
				<?php esc_html_e( 'Running over 50 sites on shared hosting can be resource intensive for the server so we will turn on caching for you to help. Updates will be cached for quick loading. A manual sync from the Dashboard is required to view new plugins, themes, pages or users.', 'mainwp' ); ?>
			</div>
			<div class="actions">
				<div class="ui positive right labeled icon button">
					<i class="check icon"></i>
					<?php esc_html_e( 'OK, I Understand', 'mainwp' ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Method mwp_setup_hosting_save()
	 *
	 * Save hosting form data.
	 */
	public function mwp_setup_hosting_save() {
		check_admin_referer( 'mwp-setup' );
		$type_hosting              = isset( $_POST['mwp_setup_type_hosting'] ) ? intval( $_POST['mwp_setup_type_hosting'] ) : 0;
		$manage_planning           = isset( $_POST['mwp_setup_manage_planning'] ) ? intval( $_POST['mwp_setup_manage_planning'] ) : 0;
		$installation_hosting_type = get_option( 'mwp_setup_installationHostingType' );
		if ( 2 == $installation_hosting_type ) {
			$type_hosting = 0;
		}
		$hosting_settings                    = array_filter( (array) get_option( 'mwp_setup_hostingSetup', array() ) );
		$hosting_settings['type_hosting']    = $type_hosting;
		$hosting_settings['manage_planning'] = $manage_planning;
		update_option( 'mwp_setup_hostingSetup', $hosting_settings );

		MainWP_Utility::update_option( 'mainwp_optimize', ( 2 == $manage_planning || 3 == $type_hosting ) ? 1 : 0 );

		wp_safe_redirect( $this->get_next_step_link() );
		exit;
	}

	/**
	 * Method mwp_setup_optimization()
	 *
	 * Render Optimization step.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 */
	public function mwp_setup_optimization() {
		$userExtension  = MainWP_DB_Common::instance()->get_user_extension();
		$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );

		if ( ! is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
		}
		$slug = 'mainwp-child/mainwp-child.php';

		if ( false === get_option( 'mwp_setup_mainwpTrustedUpdate' ) ) {
			$mainwp_strusted = 1;
		} else {
			$mainwp_strusted = in_array( $slug, $trustedPlugins, true ) ? 1 : 0;
		}
		?>
		<h1 class="ui header"><?php esc_html_e( 'Optimization', 'mainwp' ); ?></h1>
		<form method="post" class="ui form">
			<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
			<div class="field">
				<label><?php esc_html_e( 'Add MainWP Child to trusted updates?', 'mainwp' ); ?></label>
				<div class="ui info message"><?php esc_html_e( 'This allows your MainWP Dashboard to automatically update the MainWP Child plugin whenever a new version is released.', 'mainwp' ); ?></div>
				<div class="ui toggle checkbox">
					<input type="checkbox" value="hidden" name="mwp_setup_add_mainwp_to_trusted_update" id="mwp_setup_add_mainwp_to_trusted_update" <?php echo ( 1 == $mainwp_strusted ? 'checked="true"' : '' ); ?> />
					<label></label>
				</div>
			</div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<div class="ui hidden divider"></div>
			<input type="submit" class="ui big green right floated button" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php esc_html_e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>
			<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>
		<?php
	}

	/**
	 * Method mwp_setup_optimization_save()
	 *
	 * Save Optimization form data.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::update_user_extension()
	 */
	public function mwp_setup_optimization_save() {
		$userExtension  = MainWP_DB_Common::instance()->get_user_extension();
		$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
		if ( ! is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
		}
		$slug = 'mainwp-child/mainwp-child.php';
		if ( isset( $_POST['mwp_setup_add_mainwp_to_trusted_update'] ) ) {
			$idx = array_search( urldecode( $slug ), $trustedPlugins );
			if ( false == $idx ) {
				$trustedPlugins[] = urldecode( $slug );
			}
			MainWP_Utility::update_option( 'mainwp_automaticDailyUpdate', 1 );
		} else {
			$trustedPlugins = array_diff( $trustedPlugins, array( urldecode( $slug ) ) );
		}

		MainWP_Utility::update_option( 'mwp_setup_mainwpTrustedUpdate', isset( $_POST['mwp_setup_add_mainwp_to_trusted_update'] ) ? 1 : 0 );

		$userExtension->trusted_plugins = wp_json_encode( $trustedPlugins );

		MainWP_DB_Common::instance()->update_user_extension( $userExtension );

		wp_safe_redirect( $this->get_next_step_link() );
		exit;
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
		<h1 class="ui dividing header">
			<?php esc_html_e( 'Basic Uptime Monitoring', 'mainwp' ); ?>
		</h1>
		<form method="post" class="ui form">
			<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
			<div class="ui grid field">
				<div class="ui info message"><?php echo sprintf( __( 'Excessive checking can cause server resource issues. For frequent checks or lots of sites, we recommend the %1$sMainWP Advanced Uptime Monitoring%2$s extension.', 'mainwp' ), '<a href="https://mainwp.com/extension/advanced-uptime-monitor" target="_blank">', '</a>' ); ?></div>
				<label class="six wide column middle aligned"><?php esc_html_e( 'Enable basic uptime monitoring', 'mainwp' ); ?></label>
				<div class="ten wide column ui toggle checkbox mainwp-checkbox-showhide-elements" hide-parent="monitoring">
					<input type="checkbox" name="mainwp_setup_disableSitesChecking" id="mainwp_setup_disableSitesChecking" <?php echo ( 1 == $disableSitesMonitoring ? '' : 'checked="true"' ); ?>/>
				</div>
			</div>

			<div class="ui grid field" <?php echo $disableSitesMonitoring ? 'style="display:none"' : ''; ?> hide-element="monitoring">
				<label class="six wide column middle aligned"><?php esc_html_e( 'Check interval', 'mainwp' ); ?></label>
				<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Select preferred checking interval.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
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
			<h1 class="ui dividing header">
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
				<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Set preferred site health threshold.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
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
		<?php
	}

	/**
	 * Method mwp_setup_monitoring_save()
	 *
	 * Save Monitoring form data.
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
	 * Method mwp_setup_notification()
	 *
	 * Render Notifications Step.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_notification_email()
	 */
	public function mwp_setup_notification() {

		$important_notification = get_option( 'mainwp_setup_important_notification' );
		if ( false === $important_notification ) {
			$important_notification = get_option( 'mwp_setup_importantNotification' ); // going to outdated.
		}

		$user_emails = MainWP_System_Utility::get_notification_email();
		$user_emails = explode( ',', $user_emails );
		$i           = 0;
		?>
		<h1 class="ui header"><?php esc_html_e( 'Notifications', 'mainwp' ); ?></h1>
		<form method="post" class="ui form">
			<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
			<div class="field">
				<label><?php esc_html_e( 'Do you want to receive important email notifications from your MainWP Dashboard?', 'mainwp' ); ?></label>
				<div class="ui toggle checkbox">
					<input type="checkbox" value="hidden" name="mwp_setup_options_important_notification" id="mwp_setup_options_important_notification" <?php echo ( 1 == $important_notification ? 'checked="true"' : '' ); ?> />
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
							<input type="text" class="" id="mainwp_options_email" name="mainwp_options_email[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $email ); ?>"/>
							<a href="#" class="ui button basic red mainwp-multi-emails-remove" data-tooltip="<?php esc_attr_e( 'Remove this email address', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
						</div>
						<div class="ui hidden fitted divider"></div>
					<?php } ?>
					<a href="#" id="mainwp-multi-emails-add" class="ui button basic green" data-tooltip="<?php esc_attr_e( 'Add another email address to receive email notifications to multiple email addresses.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Add Another Email', 'mainwp' ); ?></a>
				</div>
				<div class="ui info message">
					<?php esc_html_e( 'These are emails from your MainWP Dashboard notifying you of available updates and other maintenance related messages. You can change this later in the MainWP Settings page.', 'mainwp' ); ?>
					<strong>
					<?php esc_html_e( 'These are NOT emails from the MainWP team and this does NOT sign you up for any mailing lists.', 'mainwp' ); ?>
					</strong>
				</div>
			</div>

			<script type="text/javascript">
				jQuery( document ).ready( function () {
					jQuery( '#mainwp-multi-emails-add' ).on( 'click', function () {
						jQuery( '#mainwp-multi-emails-add' ).before( '<div class="ui action input"><input type="text" name="mainwp_options_email[]" value=""/><a href="#" class="ui button basic red mainwp-multi-emails-remove" data-tooltip="Remove this email address" data-inverted=""><?php esc_html_e( 'Delete', 'mainwp' ); ?></a></div><div class="ui hidden fitted divider"></div>' );
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
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php esc_html_e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>
		<?php
	}

	/**
	 * Method mwp_setup_notification_save()
	 *
	 * Save Notifications form data.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::update_user_extension()
	 */
	public function mwp_setup_notification_save() {
		check_admin_referer( 'mwp-setup' );
		$important_noti = ( ! isset( $_POST['mwp_setup_options_important_notification'] ) ? 0 : 1 );
		MainWP_Utility::update_option( 'mainwp_setup_important_notification', $important_noti );

		MainWP_Utility::update_option( 'mainwp_notificationOnBackupFail', $important_noti );
		$userExtension             = MainWP_DB_Common::instance()->get_user_extension();
		$user_emails               = isset( $_POST['mainwp_options_email'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_options_email'] ) ) : '';
		$save_emails               = MainWP_Utility::valid_input_emails( $user_emails );
		$userExtension->user_email = $save_emails;
		MainWP_DB_Common::instance()->update_user_extension( $userExtension );
		wp_safe_redirect( $this->get_next_step_link() );
		exit;
	}

	/**
	 * Method mwp_setup_backup()
	 *
	 * Render Backups Step.
     *
     * @uses \MainWP\Dashboard\MainWP_Api_Manager_Password_Management::decrypt_string()
	 */
	public function mwp_setup_backup() {

		$planning_backup = get_option( 'mwp_setup_planningBackup', 0 );
		$backup_method   = get_option( 'mwp_setup_primaryBackup' );
		$style           = 1 == $planning_backup ? '' : 'style="display: none"';
		$style_alt       = ( ! empty( $backup_method ) ) ? '' : 'style="display: none"';
		$ext_product_id  = '';
		$ext_name        = '';
		$ext_slug        = '';
		if ( isset( $this->backup_extensions[ $backup_method ] ) ) {
			$ext_product_id = $this->backup_extensions[ $backup_method ]['product_id'];
			$ext_name       = $this->backup_extensions[ $backup_method ]['name'];
			$ext_slug       = $this->backup_extensions[ $backup_method ]['slug'];
		}

		$username = '';
		$password = '';
		if ( true == get_option( 'mainwp_extensions_api_save_login' ) ) {
			$enscrypt_u = get_option( 'mainwp_extensions_api_username' );
			$enscrypt_p = get_option( 'mainwp_extensions_api_password' );
			$username   = ! empty( $enscrypt_u ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_u ) : '';
			$password   = ! empty( $enscrypt_p ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_p ) : '';
		}

		$error   = get_option( 'mwp_setup_error_purchase_extension' );
		$message = get_option( 'mwp_setup_message_purchase_extension' );

		?>
		<h1 class="ui header"><?php esc_html_e( 'Backups', 'mainwp' ); ?></h1>
		<form method="post" class="ui form">
			<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
			<div class="field">
				<label><?php esc_html_e( 'Are you planning to use MainWP for backups?', 'mainwp' ); ?></label>
				<div class="ui toggle checkbox">
					<input type="checkbox" value="hidden" name="mwp_setup_planning_backup" id="mwp_setup_planning_backup" <?php echo ( 1 == $planning_backup ? 'checked="true"' : '' ); ?>/>
					<label></label>
				</div>
			</div>
			<div class="field" id="mwp_setup_tr_backup_method" <?php echo $style; ?>>
				<label><?php esc_html_e( 'Which backup system do you want to use?', 'mainwp' ); ?></label>
				<div class="ui info message"><?php esc_html_e( 'Backup system can be changed later at any time.', 'mainwp' ); ?></div>
				<select class="ui dropdown" name="mwp_setup_backup_method" id="mwp_setup_backup_method">
					<option></option>
					<option value="updraftplus"
					<?php
					if ( 'updraftplus' == $backup_method ) :
						?>
						selected<?php endif; ?>>UpdraftPlus (<?php esc_html_e( 'Free Extension', 'mainwp' ); ?>)</option>
					<option value="backupwp"
					<?php
					if ( 'backupwp' == $backup_method ) :
						?>
						selected<?php endif; ?>>BackUpWordPress (<?php esc_html_e( 'Free Extension', 'mainwp' ); ?>)</option>
					<option value="backwpup"
					<?php
					if ( 'backwpup' == $backup_method ) :
						?>
						selected<?php endif; ?>>BackWPup (<?php esc_html_e( 'Free Extension', 'mainwp' ); ?>)</option>
							</select>
			</div>
			<div class="ui hidden divider"></div>
			<div class="" id="mainwp-quick-setup-account-login" <?php echo $style_alt; ?>>
				<h4 class="ui dividing header"><?php esc_html_e( 'MainWP Account Details', 'mainwp' ); ?></h4>
				<div class="ui info message"><?php esc_html_e( 'This Extension if free, however it requires a free MainWP account to receive updates and support.', 'mainwp' ); ?></div>
				<div class="field">
					<label><?php esc_html_e( 'Enter your Username & Password registered at mainwp.com', 'mainwp' ); ?></label>
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
				<a href="https://mainwp.com/my-account/" class="ui button green basic tiny" target="_blank" data-inverted="" data-position="bottom center" data-tooltip="<?php esc_attr_e( 'If you do not have MainWP account, click here to go to the https://mainwp.com/my-account/ to register for a new account.', 'mainwp' ); ?>"><?php esc_html_e( 'Register for MainWP Account', 'mainwp' ); ?></a>
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
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php esc_html_e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>
		<?php
	}

	/**
	 * Method mwp_setup_backup_save()
	 *
	 * Save Backup step form data.
	 */
	public function mwp_setup_backup_save() {
		$planning_backup = ( ! isset( $_POST['mwp_setup_planning_backup'] ) ? 0 : 1 );
		$backup_method   = isset( $_POST['mwp_setup_backup_method'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_setup_backup_method'] ) ) : '';
		$backup_method   = ! empty( $backup_method ) && isset( $this->backup_extensions[ $backup_method ] ) ? $backup_method : '';

		update_option( 'mwp_setup_planningBackup', $planning_backup );
		update_option( 'mwp_setup_primaryBackup', $backup_method );

		if ( $planning_backup && ! empty( $backup_method ) ) {
			MainWP_Utility::update_option( 'mainwp_primaryBackup', $backup_method );
			$this->mwp_setup_purchase_extension_save();
		} else {
			delete_option( 'mainwp_primaryBackup' );
			wp_safe_redirect( $this->get_next_step_link( 'uptime_robot' ) );
			exit;
		}
	}

	/**
	 * Method mwp_setup_purchase_extension_save()
	 *
	 * MainWP Extensions login.
     *
     * @uses \MainWP\Dashboard\MainWP_Api_Manager::purchase_software()
     * @uses \MainWP\Dashboard\MainWP_Api_Manager_Password_Management::encrypt_string()
     * @uses \MainWP\Dashboard\MainWP_Cache::init_session()
	 */
	public function mwp_setup_purchase_extension_save() {
		MainWP_Cache::init_session();

		$purchase_extension_history = isset( $_SESSION['purchase_extension_history'] ) ? $_SESSION['purchase_extension_history'] : array();

		$new_purchase_extension_history = array();
		$requests                       = 0;
		foreach ( $purchase_extension_history as $purchase_extension ) {
			if ( ( time() - 1 * 60 ) < $purchase_extension['time'] ) {
				$new_purchase_extension_history[] = $purchase_extension;
				$requests++;
			}
		}

		if ( 4 < $requests ) {
			$_SESSION['purchase_extension_history'] = $new_purchase_extension_history;
			return;
		} else {
			$new_purchase_extension_history[]       = array( 'time' => time() );
			$_SESSION['purchase_extension_history'] = $new_purchase_extension_history;
		}

		check_admin_referer( 'mwp-setup' );

		$username = ! empty( $_POST['mwp_setup_purchase_username'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_setup_purchase_username'] ) ) : '';
		$password = ! empty( $_POST['mwp_setup_purchase_passwd'] ) ? wp_unslash( $_POST['mwp_setup_purchase_passwd'] ) : '';

		if ( ( '' == $username ) && ( '' == $password ) ) {
			MainWP_Utility::update_option( 'mainwp_extensions_api_username', $username );
			MainWP_Utility::update_option( 'mainwp_extensions_api_password', $password );
		} else {
			$enscrypt_u = MainWP_Api_Manager_Password_Management::encrypt_string( $username );
			$enscrypt_p = MainWP_Api_Manager_Password_Management::encrypt_string( $password );
			MainWP_Utility::update_option( 'mainwp_extensions_api_username', $enscrypt_u );
			MainWP_Utility::update_option( 'mainwp_extensions_api_password', $enscrypt_p );
			MainWP_Utility::update_option( 'mainwp_extensions_api_save_login', true );
		}
		$product_id = ! empty( $_POST['mwp_setup_purchase_product_id'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_setup_purchase_product_id'] ) ) : '';

		if ( ( '' == $username ) || ( '' == $password ) ) {
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
			if ( isset( $result['success'] ) && ( true == $result['success'] ) ) {
				if ( isset( $result['code'] ) && ( 'PURCHASED' == $result['code'] ) ) {
					$ok = true;
				} elseif ( isset( $result['order_id'] ) && ! empty( $result['order_id'] ) ) {
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

	/**
	 * Method ajax_get_backup_extension()
	 *
	 * Ajax get backup extension.
     *
     * @uses \MainWP\Dashboard\MainWP_Api_Manager::get_purchased_software()
     * @uses \MainWP\Dashboard\MainWP_Api_Manager::check_response_for_intall_errors()
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager_Password_Management::decrypt_string()
     * @uses \MainWP\Dashboard\MainWP_Extensions_View::get_available_extensions()
     */
	public static function ajax_get_backup_extension() {

		$product_id = isset( $_POST['productId'] ) ? sanitize_text_field( wp_unslash( $_POST['productId'] ) ) : 0;

		$enscrypt_u = get_option( 'mainwp_extensions_api_username' );
		$enscrypt_p = get_option( 'mainwp_extensions_api_password' );
		$username   = ! empty( $enscrypt_u ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_u ) : '';
		$password   = ! empty( $enscrypt_p ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_p ) : '';

		if ( ( '' == $username ) || ( '' == $password ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid login. Please check your username and password.', 'mainwp' ) ) ) );
		}

		$data = MainWP_Api_Manager::instance()->get_purchased_software( $username, $password, $product_id );

		$result = json_decode( $data, true );
		$return = array();
		if ( is_array( $result ) ) {
			if ( isset( $result['success'] ) && $result['success'] ) {
				$all_available_exts = array();
				foreach ( MainWP_Extensions_View::get_available_extensions() as $ext ) {
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

					/** This filter is documented in ../pages/page-mainwp-extensions.php */
					if ( isset( $product_info['package'] ) && ! empty( $product_info['package'] ) ) {
						$package_url = apply_filters( 'mainwp_api_manager_upgrade_url', $product_info['package'] );
						$html       .= '<div class="extension_to_install" download-link="' . esc_attr( $package_url ) . '" product-id="' . esc_attr( $product_id ) . '">';
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
			if ( 1 == $apisslverify ) {
				MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', 0 );
				$return['retry_action'] = 1;
			}
		}
		die( wp_json_encode( $return ) );
	}

	/**
	 * Method ajax_activate_plugin()
	 *
	 * Ajax Activate Plugin.
	 */
	public static function ajax_activate_plugin() {

		if ( ! isset( $_POST['action'] ) || ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_key( $_POST['security'] ), 'MainWPSetup' ) ) {
			die( 0 );
		}

		$slug = isset( $_POST['plugins'] ) ? wp_unslash( $_POST['plugins'] ) : '';
		if ( is_array( $slug ) ) {
			$slug = current( $slug );
		}
		if ( current_user_can( 'activate_plugins' ) ) {
			activate_plugin( $slug, '', false, true );

			/** This action is ../pages/page-mainwp-extensions-handler.php */
			do_action( 'mainwp_api_extension_activated', WP_PLUGIN_DIR . '/' . $slug );
			die( 'SUCCESS' );
		}
		die( 'FAILED' );
	}

	/**
	 * Method ajax_grab_api_key()
	 *
	 * Ajax grab api key.
     *
     * @uses \MainWP\Dashboard\MainWP_Api_Manager::grab_license_key()
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager_Password_Management::decrypt_string()
     */
	public static function ajax_grab_api_key() {
		$enscrypt_u = get_option( 'mainwp_extensions_api_username' );
		$enscrypt_p = get_option( 'mainwp_extensions_api_password' );
		$username   = ! empty( $enscrypt_u ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_u ) : '';
		$password   = ! empty( $enscrypt_p ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_p ) : '';
		$api        = isset( $_POST['slug'] ) ? dirname( wp_unslash( $_POST['slug'] ) ) : '';
		$result     = MainWP_Api_Manager::instance()->grab_license_key( $api, $username, $password );
		wp_send_json( $result );
	}

	/**
	 * Method mwp_setup_install_extension()
	 *
	 * Setup & install extensions Step.
	 */
	public function mwp_setup_install_extension() {
		$backup_method  = get_option( 'mwp_setup_primaryBackup' );
		$ext_product_id = '';
		$ext_name       = '';
		$ext_slug       = '';
		if ( isset( $this->backup_extensions[ $backup_method ] ) ) {
			$ext_product_id = $this->backup_extensions[ $backup_method ]['product_id'];
			$ext_name       = $this->backup_extensions[ $backup_method ]['name'];
			$ext_slug       = $this->backup_extensions[ $backup_method ]['slug'];
		}

		$ext_installed = false;
		$ext_activated = false;

		$installed_exts = MainWP_Extensions_Handler::get_extensions();

		foreach ( $installed_exts as $ext ) {
			if ( isset( $ext['product_id'] ) && $ext_product_id == $ext['product_id'] ) {
				$ext_installed = true;
				if ( 'Activated' == $ext['activated_key'] ) {
					$ext_activated = true;
				}
				break;
			}
		}

		?>
		<h1><?php esc_html_e( 'Backups', 'mainwp' ); ?></h1>
		<form method="post" class="ui form">
			<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
			<input type="hidden" name="mwp_setup_extension_product_id" id="mwp_setup_extension_product_id" value="<?php echo esc_attr( $ext_product_id ); ?>" slug="<?php echo esc_attr( $ext_slug ); ?>">
			<div id="mainwp-quick-setup-extension-insatllation">
				<?php if ( $ext_installed ) : ?>
					<div class="ui green message"><?php esc_html_e( 'Extension installed successfully!', 'mainwp' ); ?></div>
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
							<div class="ui text loader"><?php esc_html_e( 'Please wait...', 'mainwp' ); ?></div>
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
					<div class="ui green message"><?php esc_html_e( 'Extension activated successfully!', 'mainwp' ); ?></div>
				<?php else : ?>
						<div id="mwp_setup_active_extension" style="display: none;">
							<p><span class="description"><?php esc_html_e( 'Grabbing the API Key and activating the Extension ...', 'mainwp' ); ?></span>
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
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big button"><?php esc_html_e( 'Skip', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link( 'backup' ) ); ?>" class="ui big basic green button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
		</form>
		<?php
	}

	/**
	 * Method mwp_setup_install_extension_save()
	 *
	 * Save setup install extensions form.
	 */
	public function mwp_setup_install_extension_save() {
		check_admin_referer( 'mwp-setup' );
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
