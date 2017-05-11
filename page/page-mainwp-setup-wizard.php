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

class MainWP_Setup_Wizard {

	private $step   = '';
	private $steps  = array();
	private $backup_extensions = array();
	private $uptime_robot_api_url = 'http://api.uptimerobot.com';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menus' ) );
		add_action( 'admin_init', array( $this, 'setup_wizard' ), 999 );                
	}

	public static function init() {
		add_action('wp_ajax_mainwp_setup_extension_getextension', array('MainWP_Setup_Wizard', 'ajax_get_backup_extension'));
		add_action('wp_ajax_mainwp_setup_extension_downloadandinstall', array('MainWP_Setup_Wizard', 'ajax_download_and_install'));
		add_action('wp_ajax_mainwp_setup_extension_grabapikey', array('MainWP_Setup_Wizard', 'ajax_grab_api_key'));
		add_action('wp_ajax_mainwp_setup_extension_activate_plugin', array('MainWP_Setup_Wizard', 'ajax_activate_plugin'));
	}

	public function admin_menus() {
		add_dashboard_page( '', '', 'manage_options', 'mainwp-setup', '' );
	}

	public function setup_wizard() {
		if ( empty( $_GET['page'] ) || 'mainwp-setup' !== $_GET['page'] ) {
			return;
		}
		$this->steps = array(
			'introduction' => array(
				'name'    =>  __( 'Introduction', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_introduction' ),
				'handler' => ''
			),
			'installation' => array(
				'name'    =>  __( 'Installation', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_installation' ),
				'handler' => array( $this, 'mwp_setup_installation_save' ),
			),
			'windows_localhost' => array(
				'name'    =>  __( 'Windows localhost', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_windows_locahost' ),
				'handler' => array( $this, 'mwp_setup_windows_locahost_save' ),
				'hidden' => true
			),
			'system_check' => array(
				'name'    =>  __( 'System checkup', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_system_requirements' ),
				'handler' => ''
			),
			'hosting_setup' => array(
				'name'    =>  __( 'Hosting setup', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_hosting' ),
				'handler' => array( $this, 'mwp_setup_hosting_save' )
			),
			'optimization' => array(
				'name'    =>  __( 'Optimization', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_optimization' ),
				'handler' => array( $this, 'mwp_setup_optimization_save' )
			),
			'notification' => array(
				'name'    =>  __( 'Notifications', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_notification' ),
				'handler' => array( $this, 'mwp_setup_notification_save' )
			),
			'backup' => array(
				'name'    =>  __( 'Backups', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_backup' ),
				'handler' => array( $this, 'mwp_setup_backup_save' )
			),
			'mainwp_register' => array(
				'name'    =>  __( 'Mainwp extensions sign up', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_mainwp_register' ),
				'handler' => '',
				'hidden' => true
			),
			'purchase_extension' => array(
				'name'    =>  __( 'Order extension', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_purchase_extension' ),
				'handler' => array( $this, 'mwp_setup_purchase_extension_save' ),
				'hidden' => true
			),
			'install_extension' => array(
				'name'    =>  __( 'Install extension', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_install_extension' ),
				'handler' => array( $this, 'mwp_setup_install_extension_save' ),
				'hidden' => true
			),
			'uptime_robot' => array(
				'name'    =>  __( 'WP-Cron trigger', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_uptime_robot' ),
				'handler' => array( $this, 'mwp_setup_uptime_robot_save' ),
			),
			'hide_wp_menus' => array(
				'name'    =>  __( 'Cleanup', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_hide_wp_menu' ),
				'handler' => array( $this, 'mwp_setup_hide_wp_menu_save' ),
			),
			'next_steps' => array(
				'name'    =>  __( 'Finish', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_ready' ),
				'handler' => ''
			)
		);

		$this->backup_extensions = array(
			'updraftplus' => array('name' => 'MainWP UpdraftPlus Extension',
			                       'product_id' => 'MainWP UpdraftPlus Extension',
			                       'slug' => 'mainwp-updraftplus-extension/mainwp-updraftplus-extension.php'),
			'backupwp' => array('name' => 'MainWP BackUpWordPress Extension',
			                    'product_id' => 'MainWP BackUpWordPress Extension',
			                    'slug' => 'mainwp-backupwordpress-extension/mainwp-backupwordpress-extension.php'),
			'backwpup' => array('name' => 'MainWP BackWPup Extension',
			                    'product_id' => 'MainWP BackWPup Extension',
			                    'slug' => 'mainwp-backwpup-extension/mainwp-backwpup-extension.php')
		);

		$this->step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : current( array_keys( $this->steps ) );
		$this->check_redirect();
        wp_enqueue_script( 'mainwp-setup', MAINWP_PLUGIN_URL . 'js/mainwp-setup.js', array( 'jquery', 'jquery-ui-tooltip' ), MAINWP_VERSION );
		wp_enqueue_script( 'mainwp-setup-select2', MAINWP_PLUGIN_URL . 'js/select2/select2.js', array( 'jquery' ), MAINWP_VERSION );			
		wp_enqueue_script( 'mainwp-setup-admin', MAINWP_PLUGIN_URL . 'js/mainwp-admin.js', array(), MAINWP_VERSION );		
		
		wp_localize_script('mainwp-setup', 'mainwpSetupLocalize', array('nonce' => wp_create_nonce('MainWPSetup')));
		wp_enqueue_style( 'mainwp', MAINWP_PLUGIN_URL . 'css/mainwp.css', array(), MAINWP_VERSION );

		wp_enqueue_style( 'mainwp-font-awesome', MAINWP_PLUGIN_URL . 'css/font-awesome/css/font-awesome.min.css', array(), MAINWP_VERSION);
		wp_enqueue_style( 'jquery-ui-style' );
		wp_enqueue_style( 'mainwp-setup', MAINWP_PLUGIN_URL . 'css/mainwp-setup.css', array( 'dashicons', 'install' ), MAINWP_VERSION );
		wp_enqueue_style( 'mainwp-setup-select2', MAINWP_PLUGIN_URL . 'js/select2/select2.css', array(), '3.4.5' );

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
        
	public function check_redirect() {
		if ($this->step == 'install_extension') {
			$backup_method = get_option('mwp_setup_primaryBackup');
			if (!isset($this->backup_extensions[$backup_method])) {
				wp_redirect( $this->get_next_step_link('backup') );
			}
		}
	}

	public function get_next_step_link($step = '') {
		if (!empty($step) && isset($step, $this->steps)) {
			return esc_url_raw( remove_query_arg('noregister', add_query_arg( 'step', $step )) );
		}
		$keys = array_keys( $this->steps );
		return esc_url_raw( remove_query_arg('noregister', add_query_arg( 'step', $keys[ array_search( $this->step, array_keys( $this->steps ) ) + 1 ] )) );
	}

	public function get_back_step_link($step = '') {
		if (!empty($step) && isset($step, $this->steps)) {
			return esc_url_raw( remove_query_arg('noregister', add_query_arg( 'step', $step )) );
		}
		$keys = array_keys( $this->steps );
		return esc_url_raw( remove_query_arg('noregister', add_query_arg( 'step', $keys[ array_search( $this->step, array_keys( $this->steps ) ) - 1 ] ) ) );
	}

	public function setup_wizard_header() {
		?>
		<!DOCTYPE html>
		<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>
		<head>
			<meta name="viewport" content="width=device-width" />
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
			<title><?php _e( 'MainWP &rsaquo; Setup Wizard', 'mainwp' ); ?></title>
			<?php wp_print_scripts( 'mainwp-setup' ); ?>
			<?php do_action( 'admin_print_styles' );  ?>
			<script type="text/javascript"> var ajaxurl = '<?php echo esc_url( admin_url('admin-ajax.php') ); ?>';</script>
		</head>
		<body class="mwp-setup wp-core-ui">
		<h1 id="mwp-logo"><a href="//mainwp.com"><img src="<?php echo MAINWP_PLUGIN_URL; ?>/images/logo-mainwp1.png" alt="MainWP" /></a></h1>
		<?php
	}

	public function setup_wizard_footer() {
		?>
		<?php if ( 'next_steps' === $this->step ) : ?>
			<a class="mwp-return-to-dashboard" href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&do=new' ) ); ?>"><?php _e( 'Go to the MainWP Overview', 'mainwp' ); ?></a>
		<?php endif; ?>
		</body>
		</html>
		<?php
	}

	public function setup_wizard_steps() {
		$ouput_steps = $this->steps;
		array_shift( $ouput_steps );
		?>
		<ol class="mwp-setup-steps">
			<?php foreach ( $ouput_steps as $step_key => $step ) :
				if (isset($step['hidden']) && $step['hidden'])
					continue;
				$clickable = false;
				?>
				<li class="<?php
				if ( $step_key === $this->step ) {
					echo 'active';
					$clickable = true;
				} elseif ( array_search( $this->step, array_keys( $this->steps ) ) > array_search( $step_key, array_keys( $this->steps ) ) ) {
					echo 'done';
					$clickable = true;
				}
				?>"><?php
					if ($clickable) {
						echo '<a href="admin.php?page=mainwp-setup&step=' . $step_key. '">' . esc_html( $step['name'] ) . '</a>';
					} else {
						echo esc_html( $step['name'] );
					}
					?>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	public function setup_wizard_content() {
		echo '<div class="mwp-setup-content">';
		call_user_func( $this->steps[ $this->step ]['view'] );
		echo '</div>';
	}

	public function mwp_setup_introduction() {
        $this->mwp_setup_ready_actions();

		?>
		<h1><?php _e( 'Welcome to MainWP Dashboard', 'mainwp' ); ?></h1>
		<p><?php _e( 'Thank you for choosing MainWP for managing your WordPress sites. This quick setup wizard will help you configure the basic settings. It\'s completely optional and shouldn\'t take longer than five minutes.' ); ?></p>
		<p><?php _e( 'If you don\'t want to go through the setup wizard, you can skip and proceed to your MainWP Dashboard by clicking the "Not right now" button. If you change your mind, you can come back later by starting the Setup Wizard from the MainWP > Settings > MainWP Tools page! ', 'mainwp' ); ?></p>


		<p class="mwp-setup-actions step">
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="button-primary button button-large"><?php _e( 'Let\'s Go!', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&do=new' ) ); ?>" class="button button-large"><?php _e( 'Not right now', 'mainwp' ); ?></a>
		</p>
		<?php
	}

	public function mwp_setup_installation() {
		$hostingType = get_option("mwp_setup_installationHostingType");
		$systemType = get_option("mwp_setup_installationSystemType");
		$style = $hostingType == 2 ? "" : 'style="display: none"';
		$disabled = $hostingType == 2 ? "" : 'disabled="disabled"';
		?>
		<h1><?php _e( 'Installation', 'mainwp' ); ?></h1>
		<form method="post">
			<p><?php  _e( 'What type of server is this?' ); ?></p>
			<ul class="mwp-setup-list os-list" id="mwp_setup_installation_hosting_type">
				<li><label><input type="radio" name="mwp_setup_installation_hosting_type" required = "required" <?php echo ($hostingType == 1 ? 'checked="true"' : ''); ?> value="1"> <?php _e( 'Web Host', 'mainwp' ); ?></label></li>
				<li><label><input type="radio" name="mwp_setup_installation_hosting_type" required = "required" <?php echo ($hostingType == 2 ? 'checked="true"' : ''); ?> value="2"> <?php _e( 'Localhost', 'mainwp' ); ?></label></li>
			</ul>
			<div id="mwp_setup_os_type" <?php echo $style; ?>>
				<p><?php  _e( 'What operating system do you use?' ); ?></p>
				<ul class="mwp-setup-list os-list">
					<li><label><input type="radio" name="mwp_setup_installation_system_type" required = "required" <?php echo ($systemType == 1 ? 'checked="true"' : ''); ?> value="1" <?php echo $disabled; ?>> <?php _e( 'MacOS', 'mainwp' ); ?></label></li>
					<li><label><input type="radio" name="mwp_setup_installation_system_type" required = "required" <?php echo ($systemType == 2 ? 'checked="true"' : ''); ?> value="2" <?php echo $disabled; ?>> <?php _e( 'Linux', 'mainwp' ); ?></label></li>
					<li><label><input type="radio" name="mwp_setup_installation_system_type" required = "required" <?php echo ($systemType == 3 ? 'checked="true"' : ''); ?> value="3" <?php echo $disabled; ?>> <?php _e( 'Windows', 'mainwp' ); ?></label></li>
				</ul>
			</div>
			<p class="mwp-setup-actions step">
				<input type="submit" class="button-primary button button-large" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
				<a href="<?php echo esc_url( $this->get_next_step_link('system_check') ); ?>" class="button button-large"><?php _e( 'Skip this step', 'mainwp' ); ?></a>
				<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="button button-large"><?php _e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
			</p>
		</form>
		<?php
	}

	public function mwp_setup_installation_save() {
		check_admin_referer( 'mwp-setup' );
		$hosting_type = intval( $_POST['mwp_setup_installation_hosting_type'] );
		$system_type = isset( $_POST['mwp_setup_installation_system_type'] ) ? intval( $_POST['mwp_setup_installation_system_type'] ) : 0;
		MainWP_Utility::update_option('mwp_setup_installationHostingType', $hosting_type);

		if ($hosting_type == 1)
			$system_type = 0;

		MainWP_Utility::update_option('mwp_setup_installationSystemType', $system_type);

		if ($system_type == 3)
			wp_redirect( $this->get_next_step_link() );
		else
			wp_redirect( $this->get_next_step_link('system_check') );
		exit;
	}

	public function mwp_setup_windows_locahost() {
		$hosting_type = get_option( 'mwp_setup_installationHostingType' );
		$system_type = get_option( 'mwp_setup_installationSystemType' );
		if ($hosting_type != 2 || $system_type != 3) {
			wp_redirect( $this->get_next_step_link() );
		}
		$openssl_loc = get_option('mwp_setup_opensslLibLocation', 'c:\xampplite\apache\conf\openssl.cnf');
		?>
		<h1><?php _e( 'Windows Localhost', 'mainwp' ); ?></h1>
		<form method="post" class="form-table">
			<p><?php _e( 'Due to bug with PHP on Windows please enter your OpenSSL Library location so MainWP Dashboard can connect to your child sites.<br /> Usually it is here:', 'mainwp' ); ?></p>
			<p><input type="text" class="" style="width: 100%" name="mwp_setup_openssl_lib_location" value="<?php echo esc_html($openssl_loc); ?>"></p>
			<em><?php echo sprintf( __( 'If your openssl.cnf file is saved to a different path from what is entered above please enter your exact path. In most cases %s should be your path if using a normal install.', 'mainwp' ), 'c:\\xampplite\\apache\\conf\\openssl.cnf', '<br />' ); ?></em>
			<br /><br />
			<p class="mwp-setup-actions step">
				<input type="submit" class="button-primary button button-large" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
				<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="button button-large"><?php _e( 'Skip this step', 'mainwp' ); ?></a>
				<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="button button-large"><?php _e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
			</p>
		</form>
		<?php
	}

	public function mwp_setup_windows_locahost_save() {
		MainWP_Utility::update_option('mwp_setup_opensslLibLocation', stripslashes($_POST['mwp_setup_openssl_lib_location']));
		wp_redirect( $this->get_next_step_link() );
		exit;
	}


	public function mwp_setup_system_requirements() {
		$hosting_type = get_option('mwp_setup_installationHostingType');
		$system_type = get_option('mwp_setup_installationSystemType');
		$back_step = "installation";
		if ($system_type == 3 && $hosting_type == 2) {
			$back_step = '';
		}
		?>
		<h1><?php _e( 'Dashboard System Requirements Checkup', 'mainwp' ); ?></h1>
		<p><?php _e( 'Any Warning here may cause the MainWP Dashboard to malfunction. After you complete the Quick Start setup it is recommended to contact your host’s support and updating your server configuration for optimal performance.', 'mainwp' ); ?></p>
		<?php MainWP_Server_Information::renderQuickSetupSystemCheck(); ?>
		<br/>
		<p class="mwp-setup-actions step">
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="button-primary button button-large"><?php _e( 'Continue', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="button button-large"><?php _e( 'Skip this step', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link($back_step) ); ?>" class="button button-large"><?php _e( 'Back', 'mainwp' ); ?></a>
			<?php wp_nonce_field( 'mwp-setup' ); ?>
		</p>
		<?php
	}

	public function mwp_setup_hosting() {
		$installation_hosting_type = get_option( 'mwp_setup_installationHostingType' );
		$system_type = get_option( 'mwp_setup_installationSystemType' );

		$hosting_settings = array_filter( (array) get_option( 'mwp_setup_hostingSetup', array() ) );
		$typeHosting = isset($hosting_settings['type_hosting']) ? $hosting_settings['type_hosting']  : false;
		$managePlanning = isset($hosting_settings['manage_planning']) ? $hosting_settings['manage_planning']  : false;
		$style = ($typeHosting == 3) && ($managePlanning == 2) ?  "" : ' style="display: none" ';
		?>
		<h1><?php _e( 'Hosting setup', 'mainwp' ); ?></h1>
		<form method="post">
			<table class="form-table">
				<?php if ($installation_hosting_type == 1) { ?>
					<tr>
						<th scope="row"><?php _e("What type of hosting is this Dashboard site on?", "mainwp"); ?></th>
						<td>
						<span class="mainwp-select-bg"><select class="mainwp-select2" name="mwp_setup_type_hosting" id="mwp_setup_type_hosting">
								<option value="3" <?php if ( false == $typeHosting || 3 == $typeHosting ) {
								?>selected<?php } ?>><?php _e('Shared', 'mainwp'); ?>
								</option>
								<option value="1" <?php if ($typeHosting == 1) {
								?>selected<?php } ?>><?php _e('VPS', 'mainwp'); ?>
								</option>
								<option value="2" <?php if ($typeHosting == 2) {
								?>selected<?php } ?>><?php _e('Dedicated', 'mainwp'); ?>
								</option>
							</select></span>
						</td>
					</tr>
				<?php } ?>
				<tr>
					<th scope="row"><label for="mwp_setup_manage_planning"><?php _e("How many child sites are you planning to manage?", "mainwp"); ?></label></th>
					<td>
						<span class="mainwp-select-bg"><select class="mainwp-select2" name="mwp_setup_manage_planning" id="mwp_setup_manage_planning">
								<option value="1" <?php if (($managePlanning == false) || ($managePlanning == 1)) {
								?>selected<?php } ?>><?php _e('Less than 50', 'mainwp'); ?>
								</option>
								<option value="2" <?php if ($managePlanning == 2) {
								?>selected<?php } ?>><?php _e('More than 50', 'mainwp'); ?>
								</option>
							</select></span>
					</td>
				</tr>
			</table>
			<span id="mwp_setup_hosting_notice" <?php echo $style; ?>><em><?php _e("Running over 50 sites on shared hosting can be resource intensive for the server so we will turn on caching for you to help. Updates will be cached for quick loading. A manual sync from the Dashboard is required to view new plugins, themes, pages or users.", "mainwp"); ?></em></span></td>
			<br /><br />
			<p class="mwp-setup-actions step">
				<input type="submit" class="button-primary button button-large" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
				<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="button button-large"><?php _e( 'Skip this step', 'mainwp' ); ?></a>
				<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="button button-large"><?php _e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
			</p>
		</form>
		<?php
	}

	public function mwp_setup_hosting_save() {
		check_admin_referer( 'mwp-setup' );
		$type_hosting =  $_POST['mwp_setup_type_hosting'];
		$manage_planning =  $_POST['mwp_setup_manage_planning'];
		$installation_hosting_type = get_option( 'mwp_setup_installationHostingType' );
		if ($installation_hosting_type == 2) // localhost
			$type_hosting = 0;

		$hosting_settings            = array_filter( (array) get_option( 'mwp_setup_hostingSetup', array() ) );
		$hosting_settings['type_hosting'] = $type_hosting;
		$hosting_settings['manage_planning'] = $manage_planning;
		update_option( 'mwp_setup_hostingSetup', $hosting_settings );

		MainWP_Utility::update_option('mainwp_optimize', ($manage_planning == 2 || $type_hosting == 3) ? 1 : 0);
		wp_redirect( $this->get_next_step_link() );
		exit;
	}

	public function mwp_setup_optimization() {
		$userExtension = MainWP_DB::Instance()->getUserExtension();
		$pluginDir = (($userExtension == null) || (($userExtension->pluginDir == null) || ($userExtension->pluginDir == '')) ? 'default' : $userExtension->pluginDir);

		$trustedPlugins = json_decode($userExtension->trusted_plugins, true);
		if (!is_array($trustedPlugins)) $trustedPlugins = array();
		$slug = "mainwp-child/mainwp-child.php";
                if (false === get_option('mwp_setup_mainwpTrustedUpdate')) {
                    $mainwp_strusted = 1;
                } else {
                    $mainwp_strusted = in_array($slug, $trustedPlugins) ? 1 : 0;
                }
                
		?>
		<h1><?php _e( 'Optimization', 'mainwp' ); ?></h1>
		<form method="post">
			<table class="form-table">
				<tr>
					<th scope="row"><?php _e('Hide your MainWP Network?','mainwp'); ?></th>
					<td>
						<div class="mainwp-checkbox">
							<input type="checkbox" value="hidden" name="mwp_setup_options_footprint_plugin_folder" id="mwp_setup_options_footprint_plugin_folder_default" <?php echo ($pluginDir == 'hidden' ? 'checked="true"' : ''); ?>/><label for="mwp_setup_options_footprint_plugin_folder_default"></label>
						</div>
						<br /><br />
						<em>
							<?php _e('This will make anyone including your competitors or Search Engines trying find the MainWP Child Plugin encounter a 404 page. This does not stop Admins from seeing the plugin is installed.','mainwp'); ?>
						</em>
						<br /><br />
						<em>
							<?php _e('Hiding the Child Plugin does require the plugin to make changes to your .htaccess file that in rare instances or server configurations could cause problems.','mainwp'); ?>
						</em>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e('Add MainWP Child to Trusted Updates','mainwp'); ?></th>
					<td>
						<div class="mainwp-checkbox">
							<input type="checkbox" name="mwp_setup_add_mainwp_to_trusted_update"
							       id="mwp_setup_add_mainwp_to_trusted_update" <?php echo ($mainwp_strusted == 1 ? 'checked="true"' : ''); ?> />
							<label for="mwp_setup_add_mainwp_to_trusted_update"></label>
						</div>
						<br /><br />
						<em>
							<?php _e( 'This allows your MainWP Dashboard to automatically update your MainWP Child plugins whenever a new version is released.', 'mainwp' ); ?>
						</em>
					</td>
				</tr>
			</table>
			<br />
			<p class="mwp-setup-actions step">
				<input type="submit" class="button-primary button button-large" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
				<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="button button-large"><?php _e( 'Skip this step', 'mainwp' ); ?></a>
				<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="button button-large"><?php _e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
			</p>
		</form>
		<?php
	}

	public function mwp_setup_optimization_save() {

		$userExtension = MainWP_DB::Instance()->getUserExtension();
		$userExtension->pluginDir = (!isset($_POST['mwp_setup_options_footprint_plugin_folder']) ? 'default' : 'hidden');

		$trustedPlugins = json_decode($userExtension->trusted_plugins, true);
		if (!is_array($trustedPlugins)) $trustedPlugins = array();
		$slug = "mainwp-child/mainwp-child.php";
		if (isset($_POST['mwp_setup_add_mainwp_to_trusted_update'])) {
			$idx = array_search(urldecode($slug), $trustedPlugins);
			if ($idx == false) $trustedPlugins[] = urldecode($slug);
			MainWP_Utility::update_option('mainwp_automaticDailyUpdate', 1);
		} else {
			$trustedPlugins = array_diff($trustedPlugins, array(urldecode($slug)));
		}
                
                MainWP_Utility::update_option('mwp_setup_mainwpTrustedUpdate', isset($_POST['mwp_setup_add_mainwp_to_trusted_update']) ? 1 : 0);
                
		$userExtension->trusted_plugins = json_encode($trustedPlugins);
		MainWP_DB::Instance()->updateUserExtension($userExtension);

		wp_redirect( $this->get_next_step_link() );
		exit;
	}

	public function mwp_setup_notification() {
		$important_notification            = get_option( 'mwp_setup_importantNotification', false );
		$user_emails = MainWP_Utility::getNotificationEmail();                
                $user_emails = explode(',', $user_emails);                
                $i = 0;
		?>
		<h1><?php _e( 'Notification', 'mainwp' ); ?></h1>
		<form method="post">
			<table class="form-table">
				<tr>
					<th scope="row"><?php _e('Do you want to receive important email notifications from your dashboard?','mainwp'); ?></th>
					<td>
						<div class="mainwp-checkbox">
							<input type="checkbox" name="mwp_setup_options_important_notification"
							       id="mwp_setup_options_important_notification" <?php echo ($important_notification == 1 ? 'checked="true"' : ''); ?> />
							<label for="mwp_setup_options_important_notification"></label>
						</div>
						<br /><br />
						<em>
							<?php _e( 'These are emails from your MainWP Dashboard notifying you of available updates and other maintenance related messages. You can change this later in your MainWP Settings tab.', 'mainwp' ); ?>
							<br />
							<?php _e( 'These are NOT emails from the MainWP team and this does NOT sign you up for any mailing lists.', 'mainwp' ); ?>
						</em>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e('Enter Your Email Address','mainwp'); ?></th>
					<td>
                                             <?php foreach($user_emails as $email) { 
                                                $i++;                                        
                                                ?>
                                                <div class="mwp_email_box">
						<input type="text" class="" id="mainwp_options_email" name="mainwp_options_email[<?php echo $i; ?>]" size="35" value="<?php echo esc_attr($email); ?>"/>&nbsp;
                                                <?php if ($i != 1) { ?>
                                                <a href="#" class="mwp_remove_email"><i class="fa fa-minus-circle fa-lg mainwp-red" aria-hidden="true"></i></a>
                                                <?php } ?>
                                                </div>                                                
                                            <?php } ?>
                                            <a href="#" id="mwp_add_other_email" class="mainwp-small"><?php _e( '+ Add New'); ?></a>
					</td>
				</tr>
			</table>
			<p class="mwp-setup-actions step">
				<input type="submit" class="button-primary button button-large" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
				<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="button button-large"><?php _e( 'Skip this step', 'mainwp' ); ?></a>
				<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="button button-large"><?php _e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
			</p>
		</form>
		<?php
	}

	public function mwp_setup_notification_save() {
		check_admin_referer( 'mwp-setup' );
		$important_notification = (!isset($_POST['mwp_setup_options_important_notification']) ? 0 : 1);
		update_option( 'mwp_setup_importantNotification', $important_notification );
		MainWP_Utility::update_option('mainwp_notificationOnBackupFail', $important_notification);
		MainWP_Utility::update_option('mainwp_automaticDailyUpdate', $important_notification ? 2 : 0);
		$userExtension = MainWP_DB::Instance()->getUserExtension();
		$userExtension->offlineChecksOnlineNotification = $important_notification;
                
                $save_emails = array();
                $user_emails = $_POST['mainwp_options_email'];
                if (is_array($user_emails)) {
                    foreach($user_emails as $email) {
                        $email = esc_html(trim($email)); 
                        if (!empty($email) && !in_array($email, $save_emails)) {
                            $save_emails[] = $email;
                        }
                    }
                }
                $save_emails = implode(',', $save_emails);
		$userExtension->user_email = $save_emails;
		MainWP_DB::Instance()->updateUserExtension($userExtension);
		wp_redirect( $this->get_next_step_link() );
		exit;
	}


	public function mwp_setup_backup() {
		$planning_backup = get_option('mwp_setup_planningBackup' , 1);
		$backup_method = get_option('mwp_setup_primaryBackup');

		$style = $planning_backup == 1 ? "" : 'style="display: none"';
//		$style_archive = ($planning_backup == 1 && empty($backup_method)) ? "" : 'style="display: none"';
		?>
		<h1><?php _e( 'Backup', 'mainwp' ); ?></h1>
		<form method="post">
			<table class="form-table">
				<tr>
					<th scope="row"><?php _e('Are you planning to use MainWP for backups?','mainwp'); ?></th>
					<td>
						<div class="mainwp-checkbox">
							<input type="checkbox" name="mwp_setup_planning_backup"
							       id="mwp_setup_planning_backup" <?php echo ($planning_backup == 1 ? 'checked="true"' : ''); ?> />
							<label for="mwp_setup_planning_backup"></label>
						</div>
					</td>
				</tr>
				<tr id="mwp_setup_tr_backup_method" <?php echo $style; ?>>
					<th scope="row"><?php _e('Choose how you want to handle backups:','mainwp'); ?></th>
					<td>
						<span class="mainwp-select-bg">						
								<select class="mainwp-select2" name="mwp_setup_backup_method" id="mwp_setup_backup_method">
									<option value="updraftplus" <?php if ($backup_method == 'updraftplus' || $backup_method == ''): ?>selected<?php endif; ?>>UpdraftPlus (Free Extension)</option>
									<option value="backupwp" <?php if ($backup_method == 'backupwp'): ?>selected<?php endif; ?>>BackUpWordPress (Free Extension)</option>
									<option value="backwpup" <?php if ($backup_method == 'backwpup'): ?>selected<?php endif; ?>>BackWPup (Free Extension)</option>
								</select>						
						</span>
						<br /><br />
						<em>							
							<span class="mainwp-backups-notice" method="updraftplus" <?php echo ($backup_method == 'updraftplus' || $backup_method == '') ? "" : 'style="display:none"'; ?> ><?php _e( 'This allows you to use the UpdraftPlus backup plugin for your Backups.','mainwp' ); ?></span>
							<span class="mainwp-backups-notice" method="backupwp" <?php echo ($backup_method == 'backupwp') ? "" : 'style="display:none"'; ?> ><?php _e( 'This allows you to use the BackupWordPress backup plugin for your Backups.','mainwp' ); ?></span>
							<span class="mainwp-backups-notice" method="backwpup" <?php echo ($backup_method == 'backwpup') ? "" : 'style="display:none"'; ?> ><?php _e( 'This allows you to use the BackWPup backup plugin for your Backups.','mainwp' ); ?></span>
						</em>
						<br /><br />
						<em>
							<?php _e( 'You can change this at any time.','mainwp' ); ?>
						</em>
					</td>
				</tr>
			</table>
			<p class="mwp-setup-actions step">
				<input type="submit" class="button-primary button button-large" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
				<a href="<?php echo  esc_url( $this->get_next_step_link('uptime_robot') ); ?>" class="button button-large"><?php _e( 'Skip this step', 'mainwp' ); ?></a>
				<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="button button-large"><?php _e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
			</p>
		</form>
		<?php
	}

	public function mwp_setup_backup_save() {
		$planning_backup = (!isset($_POST['mwp_setup_planning_backup']) ? 0 : 1);
		$backup_method = isset($_POST['mwp_setup_backup_method']) ? $_POST['mwp_setup_backup_method'] : "";
		$backup_method = !empty($backup_method) && isset($this->backup_extensions[$backup_method]) ? $backup_method : "";

		update_option( 'mwp_setup_planningBackup', $planning_backup );
		update_option( 'mwp_setup_primaryBackup', $backup_method );

		if ($planning_backup && !empty($backup_method) ) {
			MainWP_Utility::update_option('mainwp_primaryBackup', $backup_method);
			wp_redirect( $this->get_next_step_link() );
		} else {
			delete_option('mainwp_primaryBackup');
			wp_redirect( $this->get_next_step_link( 'uptime_robot' ) );
		}
		exit;
	}

	public function mwp_setup_mainwp_register() {
		$backup_method = get_option('mwp_setup_primaryBackup');
		$ext_product_id = 0;
		if (isset($this->backup_extensions[$backup_method])) {
			$ext_product_id = $this->backup_extensions[$backup_method]['product_id'];
		}
		?>
		<h1><?php _e( 'MainWP Signup', 'mainwp' ); ?></h1>
		<p><?php echo __("Skip this Step if you already have MainWP Extensions account.", "mainwp"); ?></p>
		<p><?php echo __("This Extension if free, however it requires a free MainWP account to receive updates and support.", "mainwp"); ?></p>
		<p><a href="https://mainwp.com/my-account/" class="mainwp-upgrade-button button button-hero" target="_blank"><?php _e( 'Register for MainWP Account', 'mainwp' ); ?></a><br/><em style="font-size: 13px;"><?php _e("(you will be brought to a new page)", "mainwp"); ?></em></p>
		<p><?php echo sprintf(__("If you prefer to register later, click %shere%s to automatically download and install the Extension.", "mainwp"), '<a href="admin.php?page=mainwp-setup&step=install_extension&mwp-setup=' . wp_create_nonce( 'mwp-setup' ) . '&noregister=1">', '</a>'); ?></p>
		<p class="mwp-setup-actions step">
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="button-primary button button-large"><?php _e( 'Continue', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="button button-large"><?php _e( 'Skip this step', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="button button-large"><?php _e( 'Back', 'mainwp' ); ?></a>
			<?php wp_nonce_field( 'mwp-setup' ); ?>
		</p>

		<?php
	}

	public function mwp_setup_purchase_extension() {
		$username = $password = "";
		if (get_option("mainwp_extensions_api_save_login") == true) {
			$enscrypt_u = get_option('mainwp_extensions_api_username');
			$enscrypt_p = get_option('mainwp_extensions_api_password');
			$username = !empty($enscrypt_u) ? MainWP_Api_Manager_Password_Management::decrypt_string($enscrypt_u) : "";
			$password = !empty($enscrypt_p) ? MainWP_Api_Manager_Password_Management::decrypt_string($enscrypt_p) : "";
		}

		$backup_method = get_option('mwp_setup_primaryBackup');
		$ext_product_id = $ext_name = $ext_slug = "";
		if (isset($this->backup_extensions[$backup_method])) {
			$ext_product_id = $this->backup_extensions[$backup_method]['product_id'];
			$ext_name = $this->backup_extensions[$backup_method]['name'];
			$ext_slug = $this->backup_extensions[$backup_method]['slug'];
		}

		$error = get_option('mwp_setup_error_purchase_extension');
		$message = get_option('mwp_setup_message_purchase_extension');
		?>
		<h1><?php _e( 'MainWP Login', 'mainwp' ); ?></h1>

		<p><?php echo __('Log into your MainWP account to auto install your new Extension.', 'mainwp'); ?></p>
		<p><?php echo $ext_name; ?></p>
		<?php
		if (!empty($message)) {
			delete_option('mwp_setup_message_purchase_extension');
			echo '<div class="mainwp-notice mainwp-notice-green">' . $message . '</div>';
		}
		if (!empty($error)) {
			delete_option('mwp_setup_error_purchase_extension');
			echo '<div class="mainwp-notice mainwp-notice-red">' . __( 'Error:' , 'mainwp' ) . " " .  $error . '</div>';
		}
		?>
		<form method="post">
			<input type="hidden" name="mwp_setup_purchase_product_id"  value="<?php echo esc_attr($ext_product_id); ?>">
			<table class="form-table">
				<tr>
					<th scope="row"><label for="mwp_setup_purchase_username"><?php _e("MainWP Username:", "mainwp"); ?></label></th>
					<td>
						<input type="text" id="mwp_setup_purchase_username" name="mwp_setup_purchase_username" required = "required" class="input-text" value="<?php echo esc_attr( $username ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mwp_setup_purchase_passwd"><?php _e("MainWP Password:", "mainwp"); ?></label></th>
					<td>
						<input type="password" id="mwp_setup_purchase_passwd" name="mwp_setup_purchase_passwd" required = "required" class="input-text" value="<?php echo esc_attr( $password ); ?>" />
					</td>
				</tr>
			</table>

			<p class="mwp-setup-actions step">
				<input type="submit" class="button-primary button button-large" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
				<input type="button" disabled="disabled" class="button button-large" value="<?php esc_attr_e( 'Skip this step', 'mainwp' ); ?>" />
				<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="button button-large"><?php _e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
			</p>
		</form>
		<?php
	}

	public function mwp_setup_purchase_extension_save() {
		$purchase_extension_history = isset( $_SESSION['purchase_extension_history'] ) ? $_SESSION['purchase_extension_history'] : array();

		$new_purchase_extension_history = array();
		$requests = 0;
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
			$new_purchase_extension_history[] = array( 'time' => time() );
			$_SESSION['purchase_extension_history'] = $new_purchase_extension_history;
		}

		check_admin_referer( 'mwp-setup' );
		$username = ! empty( $_POST['mwp_setup_purchase_username'] ) ? sanitize_text_field( $_POST['mwp_setup_purchase_username'] ) : '';
		$password = ! empty( $_POST['mwp_setup_purchase_passwd'] ) ? sanitize_text_field( $_POST['mwp_setup_purchase_passwd'] ) : '';

		if (($username == '') && ($password == ''))
		{
			MainWP_Utility::update_option("mainwp_extensions_api_username", $username);
			MainWP_Utility::update_option("mainwp_extensions_api_password", $password);
		} else {
			$enscrypt_u = MainWP_Api_Manager_Password_Management::encrypt_string( $username );
			$enscrypt_p = MainWP_Api_Manager_Password_Management::encrypt_string( $password );
			MainWP_Utility::update_option( "mainwp_extensions_api_username", $enscrypt_u );
			MainWP_Utility::update_option( "mainwp_extensions_api_password", $enscrypt_p );
			MainWP_Utility::update_option( "mainwp_extensions_api_save_login", true );
		}
		$product_id = ! empty( $_POST['mwp_setup_purchase_product_id'] ) ? sanitize_text_field( $_POST['mwp_setup_purchase_product_id'] ) : '';

		if (($username == '') || ($password == ''))
		{
			update_option('mwp_setup_error_purchase_extension', __('Invalid user name or password.','mainwp'));
			return;
		}

		if (empty($product_id))
			return array('error' => __('Invalid product id', 'mainwp'));

		$data = MainWP_Api_Manager::instance()->purchase_software($username, $password, $product_id);
		$result = json_decode($data, true);
		$undefined_error = false;
		if (is_array($result)) {
			if (isset($result['success']) && ($result['success'] == true)) {
				if (isset($result['code']) && ($result['code'] == 'PURCHASED')) {
					// success: do nothing
				} else if (isset($result['order_id']) && !empty($result['order_id'])) {
					// success: do nothing
				} else
					$undefined_error = true;
			} else if (isset($result['error'])) {
				update_option('mwp_setup_error_purchase_extension', $result['error']);
				return;
			} else
				$undefined_error = true;
		} else {
			$undefined_error = true;
		}

		if ($undefined_error) {
			update_option('mwp_setup_error_purchase_extension', __('Undefined error.','mainwp'));
			return;
		}

		wp_redirect( $this->get_next_step_link() );
		exit;
	}

	public static function ajax_save_extensions_api_login() {
		MainWP_Extensions::saveExtensionsApiLogin();
		die();
	}

	public static function ajax_get_backup_extension() {

		$product_id = trim( $_POST['productId'] );
		$register_later = ( isset( $_POST['register_later'] ) && !empty( $_POST['register_later'] ) ) ? true : false;

		$enscrypt_u = get_option('mainwp_extensions_api_username');
		$enscrypt_p = get_option('mainwp_extensions_api_password');
		$username = !empty($enscrypt_u) ? MainWP_Api_Manager_Password_Management::decrypt_string($enscrypt_u) : "";
		$password = !empty($enscrypt_p) ? MainWP_Api_Manager_Password_Management::decrypt_string($enscrypt_p) : "";

		if (!$register_later) {
			if (($username == '') || ($password == ''))
			{
				die(json_encode(array('error' => __('Login Invalid.','mainwp'))));
			}
		}
		$data = MainWP_Api_Manager::instance()->get_purchased_software( $username, $password, $product_id, $register_later);


		$result = json_decode($data, true);
		$return = array();
		if (is_array($result)) {
			if (isset($result['success']) && $result['success']) {
				$all_available_exts = array();
				foreach(MainWP_Extensions_View::getAvailableExtensions() as $ext) {
					$all_available_exts[$ext['product_id']] = $ext;
				}
				$purchased_data = (isset($result['purchased_data']) && is_array($result['purchased_data'])) ? $result['purchased_data'] : array();
				$html = '<div class="inside">';
				$html .= "<p>" . __("Installing the Extension ...", "mainwp") . "</p>";
				$html .= '<div class="mwp_setup_extension_installing">';
				if (!is_array($purchased_data) || !isset($purchased_data[$product_id])) {
					$html .= '<p>' . __("You are not purchased the extension.", "mainwp") . '</p>';
				} else {
					$error = false;
					$product_info = $purchased_data[$product_id];
					$software_title = isset($all_available_exts[$product_id]) ? $all_available_exts[$product_id]['title'] : $product_id;
					$error_message = '';
					if (isset($product_info['package']) && !empty($product_info['package'])){
						$package_url = apply_filters('mainwp_api_manager_upgrade_url', $product_info['package']);
						$html .= '<div class="extension_to_install" download-link="' . $package_url . '" product-id="' . $product_id . '"><span class="name">Installing <strong>' . $software_title . "</strong> ...</span> " . '<span class="ext_installing" status="queue"><i class="fa fa-spinner fa-pulse hidden" style="display: none;"></i> <span class="status hidden"><i class="fa fa-clock-o"></i> ' . __('Queued', 'mainwp') . '</span></span></div>';
					} else if (isset($product_info['error'])  && !empty($product_info['error'])) {
						$error = true;
						$error_message = MainWP_Api_Manager::instance()->check_response_for_intall_errors($product_info, $software_title);
						$html .= '<div><span class="name"><strong>' . $software_title . "</strong></span> <span style=\"color: red;\"><strong>Error</strong> " . $error_message . '</span></div>';
					} else {
						$error = true;
						$error_message = __('Undefined error.', 'mainwp');
						$html .= '<div><span class="name"><strong>' . $software_title . "</strong></span> <span style=\"color: red;\"><strong>Error</strong> " . $error_message . '</span></div>';
					}

				}
				$html .= '</div>';
				$html .= '</div>';
				$html .= '<div id="extBulkActivate"><i class="fa fa-spinner fa-pulse hidden" style="display: none"></i> <span class="status hidden"></span></div>';
				$return= array('result' => 'SUCCESS', 'data' => $html);
			} else if (isset($result['error'])){
				$return= array('error' => $result['error']);
			}
		} else {
			$apisslverify = get_option('mainwp_api_sslVerifyCertificate');
			if ($apisslverify == 1) {
				MainWP_Utility::update_option("mainwp_api_sslVerifyCertificate", 0);
				$return['retry_action'] = 1;
			}
		}
		die(json_encode($return));
	}

	public static function ajax_download_and_install() {
		self::secure_request();
		$return = MainWP_Extensions::installPlugin($_POST['download_link'], true);
		die('<mainwp>' . json_encode($return) . '</mainwp>');
	}

	public static function secure_request() {
		if ( !isset($_POST['action']) || !isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'MainWPSetup') )
			die( 0 );
	}

	public static function ajax_activate_plugin() {
		self::secure_request();
		$slug = $_POST['plugins'];
		if (is_array($slug))
			$slug = current($slug);
		if ( current_user_can( 'activate_plugins' ) ) {
			activate_plugin( $slug, '', false, true );
			do_action( 'mainwp_api_extension_activated', WP_PLUGIN_DIR . '/' .$slug );
			die( 'SUCCESS' );
		}
		die( 'FAILED' );
	}

	public static function ajax_grab_api_key( ) {
		$enscrypt_u = get_option('mainwp_extensions_api_username');
		$enscrypt_p = get_option('mainwp_extensions_api_password');
		$username = !empty($enscrypt_u) ? MainWP_Api_Manager_Password_Management::decrypt_string($enscrypt_u) : "";
		$password = !empty($enscrypt_p) ? MainWP_Api_Manager_Password_Management::decrypt_string($enscrypt_p) : "";
		$api = isset($_POST['slug']) ? dirname($_POST['slug']) : '';
		$result = MainWP_Api_Manager::instance()->grab_license_key($api, $username, $password);
		die(json_encode($result));
	}

	public function mwp_setup_install_extension() {

		$register_later = isset($_GET['noregister']) && (int) $_GET['noregister']  ? 1 : 0;

		$backup_method = get_option('mwp_setup_primaryBackup');
		$ext_product_id = $ext_name = $ext_slug = "";
		if (isset($this->backup_extensions[$backup_method])) {
			$ext_product_id = $this->backup_extensions[$backup_method]['product_id'];
			$ext_name = $this->backup_extensions[$backup_method]['name'];
			$ext_slug = $this->backup_extensions[$backup_method]['slug'];
		}

		$ext_installed = false;
		$ext_activated = false;

		$installed_exts = MainWP_Extensions::loadExtensions();
		foreach($installed_exts as $ext) {
			if (isset($ext['product_id']) && $ext_product_id == $ext['product_id']) {
				$ext_installed = true;
				if ($ext['activated_key'] == 'Activated')
					$ext_activated = true;
				break;
			}
		}

		$back_step = "";
		if ($register_later) {
			$back_step = 'mainwp_register';
			MainWP_Utility::update_option('mainwp_setup_register_later_time', time());
		}

		?>
		<h1><?php _e( 'Install and Activate', 'mainwp' ); ?></h1>
		<form method="post">
			<div class="mwp_setup_install_extension_content">
				<?php echo !empty($ext_name) ? '<p>' . $ext_name . '</p>' : ""; ?>
				<input type="hidden" name="mwp_setup_extension_product_id" id="mwp_setup_extension_product_id" value="<?php echo esc_attr($ext_product_id); ?>" slug="<?php echo esc_attr($ext_slug); ?>">
				<?php
				if ($ext_installed) {
					echo '<p><img src="' . plugins_url('images/ok.png', dirname(__FILE__)) .'" alt="Ok"/>&nbsp;' . $ext_name . " was installed on your dashboard.</p>";
				if (!$ext_activated && !$register_later) {
					?>
					<script type="text/javascript">
						jQuery(document).ready(function () {
							jQuery('#mwp_setup_active_extension').fadeIn(500);
							mainwp_setup_extension_activate(false);
						})
					</script>
				<?php
				}
				} else {
				?>
					<div id="mwp_setup-install-extension">
						<p><?php _e("Automatically install the Extension.", 'mainwp'); ?></p>
						<span id="mwp_setup_auto_install_loading">
	                        <i class="fa fa-spinner fa-pulse" style="display: none;"></i><span class="status hidden"></span>
	                    </span>
						<script type="text/javascript">
							jQuery(document).ready(function () {
								mainwp_setup_grab_extension(false, <?php echo $register_later; ?>);
							})
						</script>
					</div>
					<div id="mwp_setup_extension_retry_install" style="display: none;"><p><span class="mwp_setup_loading_wrap">
	                    <input type="button" value="Retry Install Extension" onclick="this.disabled = true;mainwp_setup_grab_extension(false, <?php echo $register_later; ?>); return false;" id="mwp_setup_extension_install_btn" class="mainwp-upgrade-button button-primary">
	                        <i style="display: none;" class="fa fa-spinner fa-pulse"></i><span class="status hidden"></span>
	                    </span></p>
					</div>
				<?php } ?>
				<?php
				if ($ext_activated) {
					echo '<p><img src="' . plugins_url('images/ok.png', dirname(__FILE__)) .'" alt="Ok"/>&nbsp;' . $ext_name . " was activated on your dashboard.</p>";
				} else { ?>
					<div id="mwp_setup_active_extension" style="display: none;">
						<p><span class="description"><?php _e("Grabbing the API Key and activating the Extension ...", "mainwp"); ?></span>
							<span id="mwp_setup_grabing_api_key_loading">
								<i class="fa fa-spinner fa-pulse" style="display: none;"></i><span class="status hidden"></span>
							</span>
						</p>
					</div>
				<?php } ?>
			</div>
			<br/>
			<p class="mwp-setup-actions step">
				<input type="submit" class="button-primary button button-large" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
				<input type="submit" class="button button-large" value="<?php esc_attr_e( 'Skip this step', 'mainwp' ); ?>" name="save_step" />
				<a href="<?php echo esc_url( $this->get_back_step_link($back_step) ); ?>" class="button button-large"><?php _e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
			</p>
		</form>
		<?php
	}

	public function mwp_setup_install_extension_save() {
		check_admin_referer( 'mwp-setup' );
		wp_redirect( $this->get_next_step_link() );
		exit;
	}

	public function mwp_setup_hide_wp_menu() {

		$wp_menu_items = array(
			'dashboard' => __( 'Dashboard', 'mainwp' ),
			'posts' => __( 'Posts', 'mainwp' ),
			'media' => __( 'Media', 'mainwp' ),
			'pages' => __( 'Pages', 'mainwp' ),
			'appearance' => __( 'Appearance', 'mainwp' ),
			'comments' => __( 'Comments', 'mainwp' ),
			'users' => __( 'Users', 'mainwp' ),
			'tools' => __( 'Tools', 'mainwp' ),
		);

		$hide_menus = get_option('mwp_setup_hide_wp_menus', array());
		if (!is_array($hide_menus))
			$hide_menus = array();

        $disable_wp_main_menu = get_option( 'mainwp_disable_wp_main_menu', true );
		?>
		<h1><?php _e( 'Cleanup your Dashboard', 'mainwp' ); ?></h1>
		<p>
			<?php _e( 'If you installed your MainWP Dashboard on a brand new site dedicated to MainWP these are sections that you will not need and you can hide in order to declutter your site.', 'mainwp' ); ?>
		</p>
		<p>
			<?php _e( 'You can change this later in the MainWP > Settings > Tools screen.', 'mainwp' ); ?>
		</p>
		<form method="post">
			<table class="form-table">

				<tr>
					<th scope="row"><?php _e("Cleanup your Dashboard:", "mainwp"); ?></th>
					<td>
						<ul class="mainwp_checkboxes mainwp_hide_wpmenu_checkboxes">
							<?php
							foreach ( $wp_menu_items as $name => $item ) {
								$_selected = '';
								if ( in_array( $name, $hide_menus ) ) {
									$_selected = 'checked'; }
								?>
								<li>
									<input type="checkbox" id="mainwp_hide_wpmenu_<?php echo $name; ?>" name="mainwp_hide_wpmenu[]" <?php echo $_selected; ?> value="<?php esc_attr_e($name); ?>">
									<label for="mainwp_hide_wpmenu_<?php echo $name; ?>"><?php echo $item; ?></label>
								</li>
							<?php }
							?>
						</ul>
					</td>
				</tr>
                <tr>
                    <th scope="row"><?php _e('Use MainWP sidebar navigation?','mainwp'); ?></th>
                    <td>
                        <div class="mainwp-checkbox">
                            <input type="checkbox" name="mwp_setup_options_use_custom_sidebar"
                                   id="mwp_setup_options_use_custom_sidebar" <?php echo ($disable_wp_main_menu ? 'checked="true"' : ''); ?> />
                            <label for="mwp_setup_options_use_custom_sidebar"></label>
                        </div><br/><br/>
                        <em><?php _e( 'If enabled, the MainWP Dashboard plguin will add custom sidebar navigation and collapse the WordPress Admin Menu. Custom navigation can be disabled/enabled at anytime on the MainWP > Settings > Dashboard Options page.', 'mainwp' ); ?></em>
                    </td>
                </tr>

			</table>
			<p class="mwp-setup-actions step">
				<input type="submit" class="button-primary button button-large" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
				<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="button button-large"><?php _e( 'Skip this step', 'mainwp' ); ?></a>
				<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="button button-large"><?php _e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
			</p>
		</form>
		<?php
	}

	public function mwp_setup_hide_wp_menu_save() {
		check_admin_referer( 'mwp-setup' );
		$hide_menus = array();
		if ( isset( $_POST['mainwp_hide_wpmenu'] ) && is_array( $_POST['mainwp_hide_wpmenu'] ) && count( $_POST['mainwp_hide_wpmenu'] ) > 0 ) {
			foreach ( $_POST['mainwp_hide_wpmenu'] as $value ) {
				$hide_menus[] = $value;
			}
		}
		MainWP_Utility::update_option('mwp_setup_hide_wp_menus', $hide_menus);
        $disable_wp_main_menu = (isset($_POST['mwp_setup_options_use_custom_sidebar']) ? 1 : 0);
        update_option( 'mainwp_disable_wp_main_menu', $disable_wp_main_menu );
		wp_redirect( $this->get_next_step_link() );
		exit;
	}

	public function mwp_setup_uptime_robot() {
		$options = get_option('advanced_uptime_monitor_extension', array());
		if (!is_array($options))
			$options = array();

		if (isset($_GET['appApiKey']) && !empty($_GET['appApiKey'])) {
			$options['api_key'] = sanitize_text_field($_GET['appApiKey']);
			update_option('advanced_uptime_monitor_extension', $options);
			$this->uptime_robot_refresh_notification_contacts($options['api_key']);
			wp_redirect( get_site_url() . '/wp-admin/admin.php?page=mainwp-setup&step=uptime_robot') ;
		}

		$planning_backup = get_option('mwp_setup_planningBackup');
		$backup_method = get_option('mwp_setup_primaryBackup');
		$back_step = "";
		if (empty($planning_backup) || empty($backup_method)) {
			$back_step = "backup";
		}

		$ur_api_key = isset($options['api_key']) ? $options['api_key'] : "";
		$callback_url = admin_url('admin.php?page=mainwp-setup&step=uptime_robot&');
		$uptimerobot_url = 'http://uptimerobot.com/authenticateApps?appID=6&callbackURLCustom=' . urlencode($callback_url);

		?>
		<h1><?php _e( 'WP-Cron Trigger', 'mainwp' ); ?></h1>
		<p><?php _e( 'MainWP relies on a built in WordPress file called wp-cron.php to trigger scheduled events.', 'mainwp' ); ?></p>
		<p><?php _e( 'However, since we suggest you install your MainWP Dashboard on a fresh dedicated site it will get almost no traffic which means your scheduled tasks such as backups and automatic updates may not be triggered in a timely manner.', 'mainwp' ); ?></p>
		<p><?php _e( 'In order to work around that we suggest you sign up for the free Uptime Robot service that will "visit" your dashboard site and make sure that Cron Jobs are regularly triggered.', 'mainwp' ); ?></p>
		<?php
		$error = get_option('mainwp_setup_error_create_uptime_robot');
		if (!empty($error)) {
			delete_option('mainwp_setup_error_create_uptime_robot');
			echo '<div class="mainwp-notice mainwp-notice-red">' . __( 'ERROR: ', 'mainwp' ) . " " .  $error . '</div>';
		}
		$error_settings = false;
		?>
		<p>
			<a class="button button-primary button-hero" target="_blank" onclick="return mainwp_setup_auth_uptime_robot('<?php echo $uptimerobot_url; ?>');" href="<?php echo $uptimerobot_url; ?>"><?php _e( 'Authorize Uptime Robot', 'mainwp' ); ?></a>
		</p>
		<form method="post">
			<table class="form-table">
				<?php if (!empty($ur_api_key)) { ?>
					<tr>
						<th scope="row"><?php _e("Your Uptime Robot API Key:", "mainwp"); ?></th>
						<td>
							<input type="text" readonly = "readonly" class="" value="<?php echo $ur_api_key; ?>" size="35" name="mwp_setup_uptime_robot_api_key">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e("Monitor Notification default Email:", "mainwp"); ?></th>
						<td>
							<?php
							if ( is_array( $options['list_notification_contact'] ) && count( $options['list_notification_contact'] ) > 0 ) {
								?>
								<select class="mainwp-select2" name="mwp_setup_uptime_robot_default_contact_id">
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
								<?php
							} else {
								$error_settings = true;
								echo 'No items found! Check your Uptime Robot Settings.';
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row">&nbsp;</th>
						<td>
							<label><input type="checkbox" <?php echo $error_settings ? 'disabled="disabled"' : ""; ?> checked="checked" name="mwp_setup_add_dashboard_as_monitor" id="mwp_setup_add_dashboard_as_monitor" /> <?php _e("Add dashboard as a monitor", "mainwp");?></label>
						</td>
					</tr>
				<?php } ?>
			</table>
			<p class="mwp-setup-actions step">
				<input type="submit" class="button-primary button button-large" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
				<a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="button button-large"><?php _e( 'Skip this step', 'mainwp' ); ?></a>
				<a href="<?php echo esc_url( $this->get_back_step_link($back_step) ); ?>" class="button button-large"><?php _e( 'Back', 'mainwp' ); ?></a>
				<?php wp_nonce_field( 'mwp-setup' ); ?>
			</p>
		</form>
		<?php
	}

	public function mwp_setup_uptime_robot_save() {
		check_admin_referer( 'mwp-setup' );
		$default_contact_id = isset($_POST['mwp_setup_uptime_robot_default_contact_id']) ? $_POST['mwp_setup_uptime_robot_default_contact_id'] : null;
		$options = get_option('advanced_uptime_monitor_extension', array());
		if (!is_array($options))
			$options = array();
		if (!empty($default_contact_id )) {
			$options['uptime_default_notification_contact_id'] = $default_contact_id;
			update_option('advanced_uptime_monitor_extension', $options);
		}

		$apiKey = isset($options['api_key']) && !empty($options['api_key']) ? $options['api_key'] : "";

		if (isset($_POST['mwp_setup_add_dashboard_as_monitor'])) {
			try {
				if (empty($apiKey) || empty($default_contact_id)) {
					throw new Exception( __("Uptime Robot settings.", "mainwp") );
				} else {
					$dashboard_url = get_site_url();
					$this->uptime_robot_add_monitor($apiKey, $default_contact_id, $dashboard_url);
				}
			} catch(Exception $e) {
				update_option( 'mainwp_setup_error_create_uptime_robot', $e->getMessage() );
				return false;
			}
		}

		wp_redirect( $this->get_next_step_link() );
		exit;
	}

	public function uptime_robot_refresh_notification_contacts($apiKey) {
		if (empty($apiKey))
			return false;
		try {
			$options = get_option('advanced_uptime_monitor_extension', array());
			$list_contacts = $this->uptime_robot_get_notification_contacts($apiKey);
			$default_contact_id = null;
			if (is_array($list_contacts) && count($list_contacts) > 0) {
				$default_contact_id = isset($options['uptime_default_notification_contact_id']) ? $options['uptime_default_notification_contact_id'] : "";
				if (empty($default_contact_id) || !isset($list_contacts[$default_contact_id])) {
					$default_contact_id = key($list_contacts);
				}
				$options['uptime_default_notification_contact_id'] = $default_contact_id;
				$options['list_notification_contact'] = $list_contacts;
				update_option('advanced_uptime_monitor_extension', $options);
			} else {
				throw new Exception( __("Error: Uptime Robot notification contact email.", "mainwp") );
			}

			if (empty($default_contact_id)) {
				throw new Exception( __("Error: Uptime Robot notification contact email.", "mainwp") );
			}
		} catch(Exception $e) {
			update_option( 'mainwp_setup_error_create_uptime_robot', $e->getMessage() );
			return false;
		}
		return true;
	}


	public function uptime_robot_add_monitor( $apiKey, $contact_id,  $monitor_url ) {
		if (empty($apiKey) || empty($contact_id) || empty($monitor_url)) {
			throw new Exception( __("Error data.", "mainwp") );
		}
		$url = $this->uptime_robot_api_url . "/newMonitor?apiKey={$apiKey}";
		$url .= "&monitorFriendlyName=" . urlencode( "My MainWP Dashboard" );
		$url .= "&monitorURL={$monitor_url}&monitorType=1&format=json";
		$url .= "&monitorAlertContacts=" . urlencode( $contact_id );
		$result = $this->uptime_robot_fetch( $url );
		$result = json_decode( $result );
		if (is_object($result)) {
			if (property_exists($result, 'stat') && $result->stat == 'ok') {
				return true;
			} else if (property_exists($result, 'message')) {
				throw new Exception( $result->message );
			}
		}
		throw new Exception( __("Uptime Robot error", "mainwp") );
	}

	public function uptime_robot_get_notification_contacts( $apiKey ) {
		if (empty($apiKey))
			return array();
		$list_contact = array();
		$url = $this->uptime_robot_api_url . "/getAlertContacts?apiKey=" . $apiKey;
		$url .= "&format=json";
		$result = $this->uptime_robot_fetch( $url );
		$result = json_decode( $result );
		if ( $result->stat != 'fail' && is_object($result) && property_exists($result, 'alertcontacts')) {
			$number_contacts = count( $result->alertcontacts->alertcontact );
			for ( $i = 0; $i < $number_contacts; $i++ ) {
				if ( $result->alertcontacts->alertcontact[ $i ]->status == 2 ) {
					$list_contact[ $result->alertcontacts->alertcontact[ $i ]->id ] = $result->alertcontacts->alertcontact[ $i ]->value;
				}
			}
		}
		return $list_contact;
	}


	private function uptime_robot_fetch( $url ) {
		$url = trim( $url );
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 50 );
		$result = curl_exec( $ch );
		curl_close( $ch );
		if ( strpos( $result,'UptimeRobotApi' ) == false )
		{
			return $result;
		} else {
			$json_encap = 'jsonUptimeRobotApi()';
			return substr( $result, strlen( $json_encap ) - 1, strlen( $result ) - strlen( $json_encap ) );
		}
	}

	private function mwp_setup_ready_actions() {
		delete_site_option('mainwp_run_quick_setup');
	}

	public function mwp_setup_ready() {
		?>

		<h1><?php _e( 'Your MainWP Dashboard is Ready!', 'mainwp' ); ?></h1>
		<p><?php  _e( 'Congratulations! Now you are ready to start managing your WordPress sites.', 'mainwp' ); ?></p>
        <div class="mwp-setup-next-steps">
			<div class="mwp-setup-next-steps-first">
				<h2><?php _e( 'Next Step', 'mainwp' ); ?></h2>
				<ul>
					<li class="setup-product"><a class="button button-primary button-large" href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&do=new' ) ); ?>"><?php _e( 'Add New Site', 'mainwp' ); ?></a></li>
				</ul>
			</div>
			<div class="mwp-setup-next-steps-last">
				<h2><?php _e( 'Helpful Links', 'mainwp' ); ?></h2>
				<ul>
					<li><a href="https://mainwp.com/mainwp-extensions/" target="_blank"><i class="fa fa-plug"></i> <?php _e( 'MainWP Extensions', 'mainwp' ); ?></a></li>
					<li><a href="https://mainwp.com/help/" target="_blank"><i class="fa fa-book"></i> <?php _e( 'MainWP Documentation', 'mainwp' ); ?></a></li>
					<li><a href="https://mainwp.com/support/" target="_blank"><i class="fa fa-life-ring"></i> <?php _e( 'MainWP Support', 'mainwp' ); ?></a></li>
				</ul>
			</div>

		</div>
        <script type="text/javascript">
                jQuery(document).ready(function () {
                        jQuery('#mwp_setup_active_extension').fadeIn(500);
                        mainwp_setup_extension_activate(false);
                })
        </script>
		<?php
	}
}

