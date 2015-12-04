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

class MainWPSetupWizard {

	private $step   = '';
	private $steps  = array();
	private $backup_extensions = array();
	private $uptime_robot_api_url = 'http://api.uptimerobot.com';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menus' ) );
		add_action( 'admin_init', array( $this, 'setup_wizard' ), 999 );
	}

	public static function init() {
		add_action('wp_ajax_mainwp_setup_extension_getextension', array('MainWPSetupWizard', 'ajax_get_backup_extension'));
		add_action('wp_ajax_mainwp_setup_extension_downloadandinstall', array('MainWPSetupWizard', 'ajax_download_and_install'));
		add_action('wp_ajax_mainwp_setup_extension_grabapikey', array('MainWPSetupWizard', 'ajax_grab_api_key'));
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
				'name'    =>  __( 'Windows Localhost', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_windows_locahost' ),
				'handler' => array( $this, 'mwp_setup_windows_locahost_save' ),
				'hidden' => true
			),
			'system_check' => array(
				'name'    =>  __( 'System Checkup', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_system_requirements' ),
				'handler' => ''
			),
			'hosting_setup' => array(
				'name'    =>  __( 'Hosting Setup', 'mainwp' ),
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
				'name'    =>  __( 'Mainwp Extensions Sign Up', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_mainwp_register' ),
				'handler' => '',
				'hidden' => true
			),
			'purchase_extension' => array(
				'name'    =>  __( 'Order Extension', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_purchase_extension' ),
				'handler' => array( $this, 'mwp_setup_purchase_extension_save' ),
				'hidden' => true
			),
			'install_extension' => array(
				'name'    =>  __( 'Install Extension', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_install_extension' ),
				'handler' => array( $this, 'mwp_setup_install_extension_save' ),
				'hidden' => true
			),
			'primary_backup' => array(
				'name'    =>  __( 'Primary Backup System', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_primary_backup' ),
				'handler' => array( $this, 'mwp_setup_primary_backup_save' ),
				'hidden' => true
			),
			'uptime_robot' => array(
				'name'    =>  __( 'Uptime Robot', 'mainwp' ),
				'view'    => array( $this, 'mwp_setup_uptime_robot' ),
				'handler' => array( $this, 'mwp_setup_uptime_robot_save' ),
			),
			'hide_wp_menus' => array(
				'name'    =>  __( 'Hide WP Menus', 'mainwp' ),
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
								'slug' => 'mainwp-backupwordpress-extension/mainwp-backupwordpress-extension.php')
		);

		$this->step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : current( array_keys( $this->steps ) );
		$this->check_redirect();

		wp_enqueue_script( 'mainwp-setup', MAINWP_PLUGIN_URL . 'js/mainwp-setup.js', array( 'jquery', 'jquery-ui-tooltip' ), MAINWP_VERSION );
		wp_localize_script('mainwp-setup', 'mainwpSetupLocalize', array('nonce' => wp_create_nonce('mainwp-setup-nonce')));		
		wp_enqueue_style( 'mainwp', MAINWP_PLUGIN_URL . 'css/mainwp.css', array(), MAINWP_VERSION );			
		
		wp_enqueue_style( 'mainwp-font-awesome', MAINWP_PLUGIN_URL . 'css/font-awesome/css/font-awesome.min.css', array(), MAINWP_VERSION);
		wp_enqueue_style( 'jquery-ui-style' );
		wp_enqueue_style( 'mainwp-setup', MAINWP_PLUGIN_URL . 'css/mainwp-setup.css', array( 'dashicons', 'install' ), MAINWP_VERSION );

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
			return add_query_arg( 'step', $step );
		}
		$keys = array_keys( $this->steps );
		return add_query_arg( 'step', $keys[ array_search( $this->step, array_keys( $this->steps ) ) + 1 ] );
	}

	public function get_back_step_link($step = '') {
		if (!empty($step) && isset($step, $this->steps)) {
			return add_query_arg( 'step', $step );
		}
		$keys = array_keys( $this->steps );		
		return add_query_arg( 'step', $keys[ array_search( $this->step, array_keys( $this->steps ) ) - 1 ] );
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
			<script type="text/javascript"> var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';</script>
		</head>
		<body class="mwp-setup wp-core-ui">
			<h1 id="mwp-logo"><a href="//mainwp.com"><img src="<?php echo MAINWP_PLUGIN_URL; ?>/images/logo-mainwp1.png" alt="MainWP" /></a></h1>
		<?php
	}

	public function setup_wizard_footer() {
		?>
			<?php if ( 'next_steps' === $this->step ) : ?>
				<a class="mwp-return-to-dashboard" href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&do=new' ) ); ?>"><?php _e( 'Go to the MainWP Dashboard', 'mainwp' ); ?></a>
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
			<p><?php  _e( 'Where did you install your MainWP Dashboard?' ); ?></p>
			<ul class="mwp-setup-list os-list" id="mwp_setup_installation_hosting_type">
				<li><label><input type="radio" name="mwp_setup_installation_hosting_type" required = "required" <?php echo ($hostingType == 1 ? 'checked="true"' : ''); ?> value="1"> <?php _e( 'WebHost', 'mainwp' ); ?></label></li>
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
		$system_type = intval( $_POST['mwp_setup_installation_system_type'] );
		MainWPUtility::update_option('mwp_setup_installationHostingType', $hosting_type);

		if ($hosting_type == 1)
			$system_type = 0;

		MainWPUtility::update_option('mwp_setup_installationSystemType', $system_type);

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
		$openssl_loc = get_option('mwp_setup_opensslLibLocation', 'c:\\\\xampplite\\\\appache\\\\conf\\\\openssl.conf');							
		?>
		<h1><?php _e( 'Windows Localhost', 'mainwp' ); ?></h1>
		<form method="post" class="form-table">			
			<p><?php _e( 'Due to bug with PHP on Windows please enter your OpenSSL Library location.<br /> Usually it is here:', 'mainwp' ); ?></p>
			<p><input type="text" class="" style="width: 100%" name="mwp_setup_openssl_lib_location" value="<?php echo esc_html($openssl_loc); ?>"></p>
			<em><?php _e( 'In most cases c:\\\\xampplite\\\\appache\\\\conf\\\\openssl.conf should be your path if using a normal install.<br />If not your will need to change that to match your specific path.' ); ?></em>
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
		MainWPUtility::update_option('mwp_setup_opensslLibLocation', stripslashes($_POST['mwp_setup_openssl_lib_location']));
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
		<p><?php _e( 'Any detected Warning can cause plugin malfunction. It is highly recommened to contact host support and checking if it possible to update server configuration.', 'mainwp' ); ?></p>
		<?php MainWPServerInformation::renderQuickSetupSystemCheck(); ?>
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
		<h1><?php _e( 'Hosting Setup', 'mainwp' ); ?></h1>
		<form method="post">
			<table class="form-table">
				<?php if ($installation_hosting_type == 1) { ?>
				<tr>
					<th scope="row"><?php _e("What type of hosting is this Dashboard site on?", "mainwp"); ?></th>
					<td>
						<span class="mainwp-select-bg"><select name="mwp_setup_type_hosting" id="mwp_setup_type_hosting">
							<option value="1" <?php if (($typeHosting == false) || ($typeHosting == 1)) {
							?>selected<?php } ?>><?php _e('VPS', 'mainwp'); ?>
							</option>
							<option value="2" <?php if ($typeHosting == 2) {
							?>selected<?php } ?>><?php _e('Dedicated', 'mainwp'); ?>
							</option>
							<option value="3" <?php if ($typeHosting == 3) {
							?>selected<?php } ?>><?php _e('Shared', 'mainwp'); ?>
							</option>
						</select></span>
					</td>
				</tr>
			<?php } ?>
				<tr>
					<th scope="row"><label for="mwp_setup_manage_planning"><?php _e("How many child site you are planing to manage?", "mainwp"); ?></label></th>
					<td>
						<span class="mainwp-select-bg"><select name="mwp_setup_manage_planning" id="mwp_setup_manage_planning">
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

		MainWPUtility::update_option('mainwp_optimize', ($manage_planning == 2 || $type_hosting == 3) ? 1 : 0);
		wp_redirect( $this->get_next_step_link() );
		exit;
	}

	public function mwp_setup_optimization() {
		$userExtension = MainWPDB::Instance()->getUserExtension();
		$pluginDir = (($userExtension == null) || (($userExtension->pluginDir == null) || ($userExtension->pluginDir == '')) ? 'default' : $userExtension->pluginDir);

		$trustedPlugins = json_decode($userExtension->trusted_plugins, true);
		if (!is_array($trustedPlugins)) $trustedPlugins = array();
		$slug = "mainwp-child/mainwp-child.php";
		$mainwp_strusted = in_array($slug, $trustedPlugins) ? 1 : 0;
		?>
		<h1><?php _e( 'Optimization', 'mainwp' ); ?></h1>
		<form method="post">
			<table class="form-table">
				<tr>
					<th scope="row"><?php _e('Hide Network on Child Sites','mainwp'); ?></th>
					<td>
						<div class="mainwp-checkbox">
							<input type="checkbox" value="hidden" name="mwp_setup_options_footprint_plugin_folder" id="mwp_setup_options_footprint_plugin_folder_default" <?php echo ($pluginDir == 'hidden' ? 'checked="true"' : ''); ?>/><label for="mwp_setup_options_footprint_plugin_folder_default"></label>
						</div>
						<br /><br />
						<em>
							<?php _e('This will make anyone including Search Engines trying find your Child Plugin encounter a 404 page. Hiding the Child Plugin does require the plugin to make changes to your .htaccess file that in rare instances or server configurations could cause problems.','mainwp'); ?>
						</em>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e('Add MainWP to Trusted Updates','mainwp'); ?></th>
					<td>
						<div class="mainwp-checkbox">
							<input type="checkbox" name="mwp_setup_add_mainwp_to_trusted_update"
							       id="mwp_setup_add_mainwp_to_trusted_update" <?php echo ($mainwp_strusted == 1 ? 'checked="true"' : ''); ?> />
							<label for="mwp_setup_add_mainwp_to_trusted_update"></label>
						</div>
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

		$userExtension = MainWPDB::Instance()->getUserExtension();
		$userExtension->pluginDir = (!isset($_POST['mwp_setup_options_footprint_plugin_folder']) ? 'default' : 'hidden');

		$trustedPlugins = json_decode($userExtension->trusted_plugins, true);
		if (!is_array($trustedPlugins)) $trustedPlugins = array();
		$slug = "mainwp-child/mainwp-child.php";
		if (isset($_POST['mwp_setup_add_mainwp_to_trusted_update'])) {
			$idx = array_search(urldecode($slug), $trustedPlugins);
			if ($idx == false) $trustedPlugins[] = urldecode($slug);
			MainWPUtility::update_option('mainwp_automaticDailyUpdate', 1);
		} else {
			$trustedPlugins = array_diff($trustedPlugins, array(urldecode($slug)));
		}
		$userExtension->trusted_plugins = json_encode($trustedPlugins);
		MainWPDB::Instance()->updateUserExtension($userExtension);

		wp_redirect( $this->get_next_step_link() );
		exit;
	}

	public function mwp_setup_notification() {
		$important_notification            = get_option( 'mwp_setup_importantNotification', false );
		$user_email = MainWPUtility::getNotificationEmail();
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
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e('Enter Your Email Address','mainwp'); ?></th>
					<td>
						<input type="email"  class="" name="mwp_setup_options_email" size="35" value="<?php echo $user_email; ?>"/>
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
		MainWPUtility::update_option('mainwp_notificationOnBackupFail', $important_notification);
		MainWPUtility::update_option('mainwp_automaticDailyUpdate', $important_notification ? 2 : 0);
		$userExtension = MainWPDB::Instance()->getUserExtension();
		$userExtension->offlineChecksOnlineNotification = $important_notification;
		$userExtension->user_email = isset( $_POST['mwp_setup_options_email'] ) && !empty($_POST['mwp_setup_options_email']) ? $_POST['mwp_setup_options_email'] : "";
		MainWPDB::Instance()->updateUserExtension($userExtension);
		wp_redirect( $this->get_next_step_link() );
		exit;
	}


	public function mwp_setup_backup() {		
		$archiveFormat = get_option('mainwp_archiveFormat');
		if ($archiveFormat == false) $archiveFormat = 'tar.gz';
		$planning_backup = get_option('mwp_setup_planningBackup');
		$backup_method = get_option('mwp_setup_primaryBackup');
		
		$style = $planning_backup == 1 ? "" : 'style="display: none"';
		$style_archive = ($planning_backup == 1 && empty($backup_method)) ? "" : 'style="display: none"';
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
						<span class="mainwp-select-bg"><select name="mwp_setup_backup_method" id="mwp_setup_backup_method">
								<option value="updraftplus" <?php if ($backup_method == 'updraftplus'): ?>selected<?php endif; ?>>UpdraftPlus (Free Extension)</option>
								<option value="backupwp" <?php if ($backup_method == 'backupwp'): ?>selected<?php endif; ?>>BackUpWordPress (Free Extension)</option>
								<option value="" <?php if (empty($backup_method)): ?>selected<?php endif; ?>>Default Backups</option>
							</select></span>
						</span>
						<br /><br />
						<em>
							<span class="mainwp-backups-notice" method="default" <?php echo empty($backup_method) ? "" : 'style="display:none"'; ?> ><?php _e( 'This is a backup solution developed by MainWP.','mainwp' ); ?></span>
							<span class="mainwp-backups-notice" method="updraftplus" <?php echo ($backup_method == 'updraftplus') ? "" : 'style="display:none"'; ?> ><?php _e( 'This allows you to use the UpdraftPlus backup plugin for your Backups.','mainwp' ); ?></span>
							<span class="mainwp-backups-notice" method="backupwp" <?php echo ($backup_method == 'backupwp') ? "" : 'style="display:none"'; ?> ><?php _e( 'This allows you to use the BackupWordPress backup plugin for your Backups.','mainwp' ); ?></span>
						</em>
						<br /><br />
						<em>
							<?php _e( 'You can change this at any time.','mainwp' ); ?>
						</em>
					</td>
				</tr>
				<tr id="mwp_setup_tr_backup_archive_type" <?php echo $style_archive; ?>>
					<th scope="row"><?php _e('Backup File Archive Type:','mainwp'); ?></th>
					<td valign="top">
                        <span class="mainwp-select-bg"><select name="mwp_setup_archive_format" id="mwp_setup_archive_format">
                                <option value="zip" <?php if ($archiveFormat == 'zip'): ?>selected<?php endif; ?>>Zip</option>
                                <option value="tar" <?php if ($archiveFormat == 'tar'): ?>selected<?php endif; ?>>Tar</option>
                                <option value="tar.gz" <?php if ($archiveFormat == 'tar.gz'): ?>selected<?php endif; ?>>Tar GZip</option>
                                <option value="tar.bz2" <?php if ($archiveFormat == 'tar.bz2'): ?>selected<?php endif; ?>>Tar BZip2</option>
                            </select></span>
						<ul class="mwp-setup-list">
							<li>Zip - Uses PHP native Zip-library, when missing, the PCLZip library included in Wordpress will be used. (Good compression, fast with native zip-library)</li>
							<li>Tar - Creates an uncompressed tar-archive. (No compression, fast, low memory usage)</li>
							<li>Tar GZip - Creates a GZipped tar-archive. (Good compression, fast, low memory usage)</li>
							<li>Tar BZip2 - Creates a BZipped tar-archive. (Best compression, fast, low memory usage)</li>
						</ul>
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
		if (isset($_POST['mwp_setup_archive_format'])) {
			update_option( 'mainwp_archiveFormat', $_POST['mwp_setup_archive_format'] );
		}

		if ($planning_backup && !empty($backup_method) ) {
			wp_redirect( $this->get_next_step_link() );
		} else {
			wp_redirect( $this->get_next_step_link( 'uptime_robot' ) );
		}
		exit;
	}

	public function mwp_setup_mainwp_register() {		
		?>
		<h1><?php _e( 'MainWP Extensions Sign Up', 'mainwp' ); ?></h1>				
		<p><?php echo __("Skip this Step if you already have MainWP Extensions account.", "mainwp"); ?></p>
		<p><?php echo __("This extension is free, however it requires MainWP Extensions account.", "mainwp"); ?></p>
		<p><a href="https://extensions.mainwp.com/mainwp-register/" class="mainwp-upgrade-button button button-hero" target="_blank"><?php _e( 'Register for MainWP Account', 'mainwp' ); ?></a><br/><em style="font-size: 13px;"><?php _e("(you will be brought to a new page)", "mainwp"); ?></em></p>
		<p><?php echo sprintf(__("If you do not want to register now click %shere%s to use the MainWP Default Backups.", "mainwp"), '<a href="admin.php?page=mainwp-setup&step=primary_backup&method=default">', '</a>'); ?></p>
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
			$username = !empty($enscrypt_u) ? MainWPApiManagerPasswordManagement::decrypt_string($enscrypt_u) : "";
			$password = !empty($enscrypt_p) ? MainWPApiManagerPasswordManagement::decrypt_string($enscrypt_p) : "";
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
		<h1><?php _e( 'Purchase', 'mainwp' ); ?></h1>
		<p><?php echo $ext_name; ?></p>
		<?php
		if (!empty($message)) {
			delete_option('mwp_setup_message_purchase_extension');
			echo '<div class="mainwp_info-box">' . $message . '</div>';
		}
		if (!empty($error)) {
			delete_option('mwp_setup_error_purchase_extension');
			echo '<div class="mainwp_info-box-red">' . __("Error:") . " " .  $error . '</div>';
		}
		?>
		<form method="post">
			<input type="hidden" name="mwp_setup_purchase_product_id"  value="<?php echo esc_attr($ext_product_id); ?>">
			<table class="form-table">
				<tr>
					<th scope="row"><label for="mwp_setup_purchase_username"><?php _e("Username:", "mainwp"); ?></label></th>
					<td>
						<input type="text" id="mwp_setup_purchase_username" name="mwp_setup_purchase_username" required = "required" class="input-text" value="<?php echo esc_attr( $username ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mwp_setup_purchase_passwd"><?php _e("Password:", "mainwp"); ?></label></th>
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
		check_admin_referer( 'mwp-setup' );
		$username = ! empty( $_POST['mwp_setup_purchase_username'] ) ? sanitize_text_field( $_POST['mwp_setup_purchase_username'] ) : '';
		$password = ! empty( $_POST['mwp_setup_purchase_passwd'] ) ? sanitize_text_field( $_POST['mwp_setup_purchase_passwd'] ) : '';

		if (($username == '') && ($password == ''))
		{
			MainWPUtility::update_option("mainwp_extensions_api_username", $username);
			MainWPUtility::update_option("mainwp_extensions_api_password", $password);
		} else {
			$enscrypt_u = MainWPApiManagerPasswordManagement::encrypt_string( $username );
			$enscrypt_p = MainWPApiManagerPasswordManagement::encrypt_string( $password );
			MainWPUtility::update_option( "mainwp_extensions_api_username", $enscrypt_u );
			MainWPUtility::update_option( "mainwp_extensions_api_password", $enscrypt_p );
			MainWPUtility::update_option( "mainwp_extensions_api_save_login", true );
		}
		$product_id = ! empty( $_POST['mwp_setup_purchase_product_id'] ) ? sanitize_text_field( $_POST['mwp_setup_purchase_product_id'] ) : '';

		if (($username == '') || ($password == ''))
		{
			update_option('mwp_setup_error_purchase_extension', __('Invalid user name or password.','mainwp'));
			return;
		}

		if (empty($product_id))
			return array('error' => __('Invalid product id', 'mainwp'));

		$data = MainWPApiManager::instance()->purchase_software($username, $password, $product_id);
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
			update_option('mwp_setup_error_purchase_extension', __('Undefined error','mainwp'));
			return;
		}

		wp_redirect( $this->get_next_step_link() );
		exit;
	}

	public static function ajax_save_extensions_api_login() {
		MainWPExtensions::saveExtensionsApiLogin();
		die();
	}

	public static function ajax_get_backup_extension() {

		$enscrypt_u = get_option('mainwp_extensions_api_username');
		$enscrypt_p = get_option('mainwp_extensions_api_password');
		$username = !empty($enscrypt_u) ? MainWPApiManagerPasswordManagement::decrypt_string($enscrypt_u) : "";
		$password = !empty($enscrypt_p) ? MainWPApiManagerPasswordManagement::decrypt_string($enscrypt_p) : "";

		$product_id = trim( $_POST['productId'] );

		if (($username == '') || ($password == ''))
		{
			die(json_encode(array('error' => __('Login Invalid.','mainwp'))));
		}

		$data = MainWPApiManager::instance()->get_purchased_software( $username, $password, $product_id);
		$result = json_decode($data, true);
		$return = array();
		if (is_array($result)) {
			if (isset($result['success']) && $result['success']) {
				$all_available_exts = array();
				foreach(MainWPExtensionsView::getAvailableExtensions() as $ext) {
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
					if (isset($product_info['error']) && $product_info['error'] == 'download_revoked') {
						$error = true;
						$html .= '<div><span class="name"><strong>' . $software_title . "</strong></span> <span style=\"color: red;\"><strong>Error</strong> " . MainWPApiManager::instance()->download_revoked_error_notice($software_title) . '</span></div>';
					} else if (isset($product_info['package']) && !empty($product_info['package'])){
						$package_url = apply_filters('mainwp_api_manager_upgrade_url', $product_info['package']);
						$html .= '<div class="extension_to_install" download-link="' . $package_url . '" product-id="' . $product_id . '"><span class="name"><strong>' . $software_title . "</strong></span> " . '<span class="ext_installing" status="queue"><i class="fa fa-spinner fa-pulse hidden" style="display: none;"></i> <span class="status hidden"><i class="fa fa-clock-o"></i> ' . __('Queued', 'mainwp') . '</span></span></div>';
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
				MainWPUtility::update_option("mainwp_api_sslVerifyCertificate", 0);
				$return['retry_action'] = 1;
			}
		}
		die(json_encode($return));
	}

	public static function ajax_download_and_install() {
		$return = MainWPExtensions::installPlugin($_POST['download_link'], true);
		die('<mainwp>' . json_encode($return) . '</mainwp>');
	}

	public static function ajax_grab_api_key( ) {
		$enscrypt_u = get_option('mainwp_extensions_api_username');
		$enscrypt_p = get_option('mainwp_extensions_api_password');
		$username = !empty($enscrypt_u) ? MainWPApiManagerPasswordManagement::decrypt_string($enscrypt_u) : "";
		$password = !empty($enscrypt_p) ? MainWPApiManagerPasswordManagement::decrypt_string($enscrypt_p) : "";
		$api = dirname($_POST['slug']);
		$result = MainWPApiManager::instance()->grab_license_key($api, $username, $password);
		die(json_encode($result));
	}

	public function mwp_setup_install_extension() {
		$enscrypt_u = get_option('mainwp_extensions_api_username');
		$enscrypt_p = get_option('mainwp_extensions_api_password');
		$username = !empty($enscrypt_u) ? MainWPApiManagerPasswordManagement::decrypt_string($enscrypt_u) : "";
		$password = !empty($enscrypt_p) ? MainWPApiManagerPasswordManagement::decrypt_string($enscrypt_p) : "";

		$backup_method = get_option('mwp_setup_primaryBackup');

		$ext_product_id = $ext_name = $ext_slug = "";
		if (isset($this->backup_extensions[$backup_method])) {
			$ext_product_id = $this->backup_extensions[$backup_method]['product_id'];
			$ext_name = $this->backup_extensions[$backup_method]['name'];
			$ext_slug = $this->backup_extensions[$backup_method]['slug'];
		}

		$ext_installed = false;
		$ext_activated = false;

		$installed_exts = MainWPExtensions::loadExtensions();
		foreach($installed_exts as $ext) {
			if (isset($ext['product_id']) && $ext_product_id == $ext['product_id']) {
				$ext_installed = true;
				if ($ext['activated_key'] == 'Activated')
					$ext_activated = true;
				break;
			}
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
					if (!$ext_activated) {
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
						<p><?php _e("Automatically install the Extension."); ?></p>
						<span id="mwp_setup_auto_install_loading">
	                        <i class="fa fa-spinner fa-pulse" style="display: none;"></i><span class="status hidden"></span>
	                    </span>
						<script type="text/javascript">
							jQuery(document).ready(function () {
								mainwp_setup_grab_extension(false);
							})
						</script>
					</div>
					<div id="mwp_setup_extension_retry_install" style="display: none;"><p><span class="mwp_setup_loading_wrap">
	                    <input type="button" value="Retry Install Extension" id="mwp_setup_extension_install_btn" class="mainwp-upgrade-button button-primary">
	                        <i style="display: none;" class="fa fa-spinner fa-pulse"></i><span class="status hidden"></span>
	                    </span></p>
					</div>
				<?php } ?>
				<?php
				if ($ext_activated) {
					echo '<p><img src="' . plugins_url('images/ok.png', dirname(__FILE__)) .'" alt="Ok"/>&nbsp;' . $ext_name . " was activated on your dashboard.</p>";
				} else { ?>
					<div id="mwp_setup_active_extension" style="display: none;">
						<p><span class="description"><?php _e("Grabing API Key and activate the Extension ...", "mainwp"); ?></span></p>
					    <span id="mwp_setup_grabing_api_key_loading">
		                    <i class="fa fa-spinner fa-pulse" style="display: none;"></i><span class="status hidden"></span>
		                </span>
					</div>
				<?php } ?>
			</div>
			<p class="mwp-setup-actions step">
				<input type="submit" class="button-primary button button-large" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
				<input type="submit" class="button button-large" value="<?php esc_attr_e( 'Skip this step', 'mainwp' ); ?>" name="save_step" />
				<a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="button button-large"><?php _e( 'Back', 'mainwp' ); ?></a>
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

	public function mwp_setup_primary_backup() {
		if (isset($_GET['method']) && $_GET['method'] == 'default') {
			delete_option('mainwp_primaryBackup');
		}		
		
		$primaryBackup = get_option('mainwp_primaryBackup');
		$primaryBackupMethods = apply_filters("mainwp-getprimarybackup-methods", array());
		if (!is_array($primaryBackupMethods)) {
			$primaryBackupMethods = array();
		}
		?>
		<h1><?php _e( 'Set Primary Backup', 'mainwp' ); ?></h1>
		<form method="post">
			<table class="form-table">
				<tr>
					<th scope="row"><?php _e('Select Primary Backup System','mainwp'); ?></th>
					<td>
	                <span><select name="mainwp_primaryBackup" id="mainwp_primaryBackup">
			                <option value="" >Default MainWP Backups</option>
			                <?php
			                foreach($primaryBackupMethods as $method) {
				                echo '<option value="' . $method['value'] . '" ' . (($primaryBackup == $method['value']) ? "selected" : "") . '>' . $method['title'] . '</option>';
			                }
			                ?>
		                </select><label></label></span>
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

	public function mwp_setup_primary_backup_save() {
		check_admin_referer( 'mwp-setup' );
		if (isset($_POST['mainwp_primaryBackup'])) {
			MainWPUtility::update_option('mainwp_primaryBackup', $_POST['mainwp_primaryBackup']);
		}
		wp_redirect( $this->get_next_step_link() );
		exit;
	}

	public function mwp_setup_hide_wp_menu() {
		
		$wp_menu_items = array(
			'dashboard' => __( 'Dashboard', 'mainwp' ),
			'posts' => __( 'Posts', 'mainwp' ),
			'media' => __( 'Media', 'mainwp' ),
			'pages' => __( 'Pages' ),
			'appearance' => __( 'Appearance', 'mainwp' ),
			'comments' => __( 'Comments', 'mainwp' ),
			'users' => __( 'Users', 'mainwp' ),
			'tools' => __( 'Tools', 'mainwp' ),
		);
		
		$hide_menus = get_option('mwp_setup_hide_wp_menus', array());		
		if (!is_array($hide_menus))
			$hide_menus = array();
		?>
		<h1><?php _e( 'Hide WP Menus', 'mainwp' ); ?></h1>		
		
		<form method="post">			
			<table class="form-table">
				
				<tr>
					<th scope="row"><?php _e("Hide WP Menus:", "mainwp"); ?></th>
					<td>
						<ul class="mainwp_checkboxes mainwp_hide_wpmenu_checkboxes">
							<?php
							foreach ( $wp_menu_items as $name => $item ) {
								$_selected = '';
								if ( in_array( $name, $hide_menus ) ) {
									$_selected = 'checked'; }
								?>
								<li>
									<input type="checkbox" id="mainwp_hide_wpmenu_<?php echo $name; ?>" name="mainwp_hide_wpmenu[]" <?php echo $_selected; ?> value="<?php echo $name; ?>" class="mainwp-checkbox2"> 
									<label for="mainwp_hide_wpmenu_<?php echo $name; ?>" class="mainwp-label2"><?php echo $item; ?></label>
								</li>
							<?php }
							?>
						</ul>
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
		MainWPUtility::update_option('mwp_setup_hide_wp_menus', $hide_menus);
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
		<h1><?php _e( 'Uptime Robot', 'mainwp' ); ?></h1>	
		<p><?php _e( 'Create the Uptime Robot account by filling in following field.', 'mainwp' ); ?><br>
		   <?php _e( 'Later, add your dashboard site as a Monitor. Uptime Robot will "visit" your dashboard site and make sure that Cron Jobs are regularly triggered.', 'mainwp' ); ?><br>
		   <strong><?php _e( 'This is optional, but highly recommended.', 'mainwp' ); ?></strong>
		</p>
		<?php
		$error = get_option('mainwp_setup_error_create_uptime_robot');		
		if (!empty($error)) {
			delete_option('mainwp_setup_error_create_uptime_robot');
			echo '<div class="mainwp_info-box-red">' . __("Error:") . " " .  $error . '</div>';
		}
		$error_settings = false;
		?>
		<p><?php echo sprintf(__("Click %shere%s to Authorize Uptime Robot", "mainwp"), '<a href="' . $uptimerobot_url . '" target="_blank">','</a>');?></p>		
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
							<select name="mwp_setup_uptime_robot_default_contact_id">
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
					<label><input type="checkbox" <?php echo $error_settings ? 'disabled="disabled"' : ""; ?> name="mwp_setup_add_dashboard_as_monitor" id="mwp_setup_add_dashboard_as_monitor" /> <?php _e("Add dashboard as a monitor", "mainwp");?></label>	
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
		//error_log($url . "===>" . print_r($result, true));
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
		$this->mwp_setup_ready_actions();
		?>
		<h1><?php _e( 'Your MainWP is Ready!', 'mainwp' ); ?></h1>
		<p><?php  _e( 'Congratulations! Now you are ready to start managing your WordPress sites.', 'mainwp' ); ?></p>
		<div class="mwp-setup-next-steps">
			<div class="mwp-setup-next-steps-first">
				<h2><?php _e( 'Next Steps', 'mainwp' ); ?></h2>
				<ul>
					<li class="setup-product"><a class="button button-primary button-large" href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&do=new' ) ); ?>"><?php _e( 'Add New Site', 'mainwp' ); ?></a></li>
					<li class="setup-product"><a class="button button-primary button-large" href="https://extensions.mainwp.com" target="_blank"><?php _e( 'Get MainWP Extensions', 'mainwp' ); ?></a></li>
				</ul>
			</div>
			<div class="mwp-setup-next-steps-last">
				<h2><?php _e( 'Helpful Links', 'mainwp' ); ?></h2>
				<ul>
					<li><a href="https://extensions.mainwp.com" target="_blank"><i class="fa fa-plug"></i> <?php _e( 'MainWP Extensions', 'mainwp' ); ?></a></li>
					<li><a href="http://docs.mainwp.com" target="_blank"><i class="fa fa-book"></i> <?php _e( 'MainWP Documentation', 'mainwp' ); ?></a></li>
					<li><a href="http://support.mainwp.com" target="_blank"><i class="fa fa-life-ring"></i> <?php _e( 'MainWP Suppor', 'mainwp' ); ?></a></li>
					<li><a href="https://mainwp.com/forum/" target="_blank"><i class="fa fa-comments-o"></i> <?php _e( 'Community Forum', 'mainwp' ); ?></a></li>
				</ul>
			</div>
		</div>
		<?php
	}
}

