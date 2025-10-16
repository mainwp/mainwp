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
class MainWP_Setup_Wizard { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
     * Method get_instance().
     */
    public static function get_instance() {
        return new self();
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
                'desc'    => esc_html__( 'Welcome to MainWP!', 'mainwp' ),
                'view'    => array( $this, 'mwp_setup_welcome' ),
                'handler' => '',
            ),
            'introduction'       => array(
                'name'    => esc_html__( 'Introduction', 'mainwp' ),
                'desc'    => esc_html__( 'How MainWP works', 'mainwp' ),
                'view'    => array( $this, 'mwp_setup_introduction' ),
                'handler' => array( $this, 'mwp_setup_introduction_save' ),
            ),
            'system_check'       => array(
                'name'    => esc_html__( 'System Check', 'mainwp' ),
                'desc'    => esc_html__( 'Verify requirements', 'mainwp' ),
                'view'    => array( $this, 'mwp_setup_system_requirements' ),
                'handler' => array( $this, 'mwp_setup_system_requirements_save' ),
            ),
            'connect_first_site' => array(
                'name'    => esc_html__( 'Add Site', 'mainwp' ),
                'desc'    => esc_html__( 'Add your first site', 'mainwp' ),
                'view'    => array( $this, 'mwp_setup_connect_first_site' ),
                'handler' => array( $this, 'mwp_setup_connect_first_site_save' ),
            ),
            'add_client'         => array(
                'name'    => esc_html__( 'Add Client', 'mainwp' ),
                'desc'    => esc_html__( 'Set up a client', 'mainwp' ),
                'view'    => array( $this, 'mwp_setup_add_client' ),
                'handler' => '',
            ),
            'monitoring'         => array(
                'name'    => esc_html__( 'Monitoring', 'mainwp' ),
                'desc'    => esc_html__( 'Configure monitoring', 'mainwp' ),
                'view'    => array( $this, 'mwp_setup_monitoring' ),
                'handler' => array( $this, 'mwp_setup_monitoring_save' ),
            ),
            'next_steps'         => array(
                'name'    => esc_html__( 'Setup Complete!', 'mainwp' ),
                'desc'    => esc_html__( 'You\'re all set!', 'mainwp' ),
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
        wp_enqueue_script( 'mainwp-import', MAINWP_PLUGIN_URL . 'assets/js/mainwp-managesites-import.js', array( 'jquery' ), MAINWP_VERSION, true );
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
            } elseif ( 'default-dark' === $selected_theme ) {
                wp_enqueue_style( 'mainwp-custom-dashboard-extension-default-theme', MAINWP_PLUGIN_URL . 'assets/css/themes/mainwp-default-dark-theme.css', array(), MAINWP_VERSION );
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

        $this->setup_wizard_content();
        $this->setup_wizard_footer();
        ?>
        <?php
        if ( get_option( 'mainwp_enable_guided_tours', 0 ) ) {
            static::mainwp_usetiful_tours();
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
                <?php wp_print_scripts( 'mainwp-import' ); ?>
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
                <style>
                    @keyframes tadaaaa {
                        0% {
                            transform: scale(1) rotate(0deg);
                        }
                        10% {
                            transform: scale(0.9) rotate(-3deg);
                        }
                        20% {
                            transform: scale(1.1) rotate(3deg);
                        }
                        30% {
                            transform: scale(0.95) rotate(-2deg);
                        }
                        40% {
                            transform: scale(1.05) rotate(2deg);
                        }
                        50% {
                            transform: scale(1) rotate(0deg);
                        }
                        60% {
                            transform: scale(1.02) rotate(-1deg);
                        }
                        80% {
                            transform: scale(0.98) rotate(1deg);
                        }
                        100% {
                            transform: scale(1) rotate(0deg);
                        }
                    }

                    .tadaaaa {
                        display: inline-block;
                        animation: tadaaaa 0.6s ease-in-out 1 forwards;
                    }
                </style>
                <script type="text/javascript"> let ajaxurl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';</script>
                <script type="text/javascript">let mainwp_ajax_nonce = "<?php echo esc_js( wp_create_nonce( 'mainwp_ajax' ) ); ?>", mainwp_js_nonce = "<?php echo esc_js( wp_create_nonce( 'mainwp_nonce' ) ); ?>";</script>
            </head>
            <body class="mainwp-ui <?php echo ! empty( $selected_theme ) ? 'mainwp-custom-theme' : ''; ?> mainwp-ui-setup">
                <div class="ui stackable padded grid">
                    <div class="two wide left aligned middle aligned column">
                        <img src="<?php echo esc_url( MAINWP_PLUGIN_URL . 'assets/images/mainwp-icon.svg' ); ?>" alt="<?php esc_attr_e( 'MainWP', 'mainwp' ); ?>" style="width:40px;height40px;" />
                    </div>
                    <div class="twelve wide column">
                        <?php $this->setup_wizard_steps(); ?>
                    </div>
                    <div class="two wide right aligned middle aligned column">
                        
                    </div>
                </div>

                <div id="mainwp-quick-setup-wizard" class="ui grid">
                    <div class="two wide column"></div>
                        <div class="twelve wide column">
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
                    <div class="two wide column"></div>
                </div>
                <div id="mainwp-qsw-exit-links">
                    <?php esc_html_e( 'Go to', 'mainwp' ); ?> <a class="" href="<?php echo esc_url( admin_url( 'index.php' ) ); ?>"><?php esc_html_e( 'WP Admin', 'mainwp' ); ?></a> <?php esc_html_e( 'or', 'mainwp' ); ?> <a class="" href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp_tab' ) ); ?>"><?php esc_html_e( 'MainWP', 'mainwp' ); ?></a>
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
        <div id="mainwp-quick-setup-wizard-steps" class="ui circular ordered stackable fluid steps" style="">
            <?php foreach ( $ouput_steps as $step_key => $step ) { ?>
                <?php
                if ( isset( $step['hidden'] ) && $step['hidden'] ) {
                    continue;
                }

                //if ( 'welcome' === $step_key ) {
                //    continue;
               // }
                ?>
                <div
                <?php
                if ( $step_key === $this->step ) {
                    // Check if this is the last step.
                    $step_keys = array_keys( $this->steps );
                    $last_step_key = end( $step_keys );
                    
                    if ( $step_key === $last_step_key ) {
                        echo 'class="step completed" aria-label="Completed: ' . esc_attr( $step['name'] ) . '"';
                    } else {
                        echo 'class="step active tada transition" aria-current="step"';
                    }
                } elseif ( array_search( $this->step, array_keys( $this->steps ) ) > array_search( $step_key, array_keys( $this->steps ) ) ) {
                    echo 'class="step completed" aria-label="Completed: ' . esc_attr( $step['name'] ) . '"';
                } else {
                    echo 'class="step"';
                }
                ?>
                >
                    <?php if ( array_search( $this->step, array_keys( $this->steps ) ) > array_search( $step_key, array_keys( $this->steps ) ) ) : ?>
                    <span data-tooltip="<?php esc_attr_e( 'Completed!', 'mainwp' ); ?>" data-inverted="" data-position="bottom center">
                    <?php endif; ?>
                        <div class="content">
                            <div class="title"><?php echo esc_html( $step['name'] ); ?></div>
                            <div class="description"><?php echo esc_html( $step['desc'] ); ?></div>
                        </div>
                    <?php if ( array_search( $this->step, array_keys( $this->steps ) ) > array_search( $step_key, array_keys( $this->steps ) ) ) : ?>
                    </span>
                    <?php endif; ?>
                </div>
            <?php } ?>
        </div>
        <script type="text/javascript">
        jQuery( document ).ready( function( $ ) {
            // Function to handle circular steps responsive behavior.
            function handleCircularStepsResize() {
                var circularSteps = jQuery('.ui.stackable.circular.steps'),
                    isMobile = window.innerWidth <= 768;
                if (isMobile) {
                    circularSteps.addClass('vertical');
                } else {
                    circularSteps.removeClass('vertical');
                }
            }
            
            // Run on page load.
            handleCircularStepsResize();
            
            // Run on window resize.
            jQuery(window).on('resize', handleCircularStepsResize);
        });
        </script>
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
        delete_option( 'mainwp_run_quick_setup' );
        ?>
        <div class="ui vertical basic padded segments">
            <div class="ui padded segment">
                <h1 class="ui massive header"><?php esc_html_e( 'Get Your MainWP Dashboard Running in 5 Minutes!', 'mainwp' ); ?></h1>
                <p><?php esc_html_e( 'Remember WordPress\' famous 5-minute install? This is that, but for your management dashboard.', 'mainwp' ); ?>
            </div>
            <div class="ui padded segment">
                <h2 class="ui big header"><?php esc_html_e( 'We\'ll walk you through:', 'mainwp' ); ?></h2>
                <h3 class="ui header"><?php esc_html_e( 'What to Expect in the Setup Wizard', 'mainwp' ); ?></h3>
                <div class="ui bulleted list">
                    <div class="item" style="text-align:left">
                        <strong><?php esc_html_e( 'System Check', 'mainwp' ); ?></strong> - <?php esc_html_e( 'Confirm your server is ready for MainWP.', 'mainwp' ); ?>
                    </div>
                    <div class="item" style="text-align:left">
                        <strong><?php esc_html_e( 'Adding Your First Site', 'mainwp' ); ?></strong> - <?php esc_html_e( 'Connect your first WordPress install.', 'mainwp' ); ?>
                    </div>
                    <div class="item" style="text-align:left">
                        <strong><?php esc_html_e( 'Adding Your First Client', 'mainwp' ); ?></strong> - <?php esc_html_e( 'Set up a client profile.', 'mainwp' ); ?>
                    </div>
                    <div class="item" style="text-align:left">
                        <strong><?php esc_html_e( 'Monitoring Configuration', 'mainwp' ); ?></strong> - <?php esc_html_e( 'Enable uptime & site health checks.', 'mainwp' ); ?>
                    </div>
                </div>
                <em><?php esc_html_e( 'Takes about 5 minutes, maybe less if your server\'s already configured.', 'mainwp' ); ?></em>
                <p><?php esc_html_e( 'If you don\'t want to go through the setup wizard, you can skip and proceed to your MainWP Dashboard by clicking the "Skip the Setup Wizard" link. If you change your mind, you can come back later by starting the Setup Wizard from the MainWP > Settings > MainWP Tools page!', 'mainwp' ); ?></p>
            </div>
            <div class="ui padded segment">
                <a class="ui huge green basic button" href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp-setup&step=introduction' ) ); ?>"><?php esc_html_e( 'Start the MainWP Quick Setup Wizard', 'mainwp' ); ?></a>
                <div class="ui hidden divider"></div>
                <div class="ui hidden divider"></div>
                <?php esc_html_e( '...or ', 'mainwp' ); ?> <a class="" href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp_tab' ) ); ?>"><?php esc_html_e( 'Skip the Setup Wizard', 'mainwp' ); ?></a>
                <div class="ui hidden divider"></div>
                <div class="ui hidden divider"></div>
                <em><?php esc_html_e( 'Your Dashboard may seem limited until you add sites or clients. Completing the setup ensures you\'re ready to unlock the full potential of MainWP.', 'mainwp' ); ?></em>
            </div>
            <div class="ui padded segment">
                <h2 class="ui header"><?php esc_html_e( 'Quick concepts if you\'re new to MainWP:', 'mainwp' ); ?></h2>
                <div><strong><?php esc_html_e( 'MainWP Dashboard', 'mainwp' ); ?></strong> <?php esc_html_e( 'Where you manage everything. This is what you just installed.', 'mainwp' ); ?></div>
                <div><strong><?php esc_html_e( 'MainWP Child', 'mainwp' ); ?></strong> <?php esc_html_e( 'Plugin you install on each site you want to manage. It connects your sites to this dashboard.', 'mainwp' ); ?></div>
            </div>
        </div>
        <?php
    }

    /**
     * Method mwp_setup_introduction()
     *
     * Renders the Introduction screen of the Quick Start Wizard
     */
    public function mwp_setup_introduction() {
        ?>
        <div class="ui message" id="mainwp-message-zone" style="display:none"></div>
        <div class="ui vertical basic padded segments">
            <div class="ui padded segment">
                <h1 class="ui massive header"><?php esc_html_e( 'MainWP Quick Setup Wizard', 'mainwp' ); ?></h1>
                <p><?php esc_html_e( 'Thank you for choosing MainWP for managing your WordPress sites. This quick setup wizard will help you configure the basic settings.', 'mainwp' ); ?></p>
            </div>
            <div class="ui padded segment">
                <a href="https://mainwp.com/kb/get-started-with-mainwp/" target="_blank" class="ui big icon green button"><i class="youtube icon"></i> <?php esc_html_e( 'Walkthrough', 'mainwp' ); ?></a>
            </div>
        </div>
        <div class="ui hidden divider"></div>
        <div class="ui hidden divider"></div>
        <form method="post">
            <div class="ui vertical basic padded segments">
                <div class="ui padded segment">
                    <h2 class="ui big header"><?php esc_html_e( 'MainWP Guided Tours', 'mainwp' ); ?> <span class="ui blue mini label"><?php esc_html_e( 'BETA', 'mainwp' ); ?></span></h2>
                    <p><?php esc_html_e( 'MainWP guided tours are designed to provide information about all essential features on each MainWP Dashboard page.', 'mainwp' ); ?></p>
                    <div class="ui blue message">
                        <?php printf( esc_html__( 'This feature is implemented using Javascript provided by Usetiful and is subject to the %1$sUsetiful Privacy Policy%2$s.', 'mainwp' ), '<a href="https://www.usetiful.com/privacy-policy" target="_blank">', '</a>' ); ?>
                    </div>
                    <div class="ui form">
                        <div class="field">
                            <label><?php esc_html_e( 'Do you want to enable MainWP Guided Tours?', 'mainwp' ); ?></label>
                            <div class="ui hidden divider"></div>
                            <div class="ui toggle checkbox">
                                <input type="checkbox" name="mainwp-guided-tours-option" id="mainwp-guided-tours-option" checked="true">
                                <label for="mainwp-guided-tours-option"><?php esc_html_e( 'Select to enable the MainWP Guided Tours.', 'mainwp' ); ?><span class="ui left pointing green label"><?php esc_html_e( 'Highly recommended if new to MainWP!', 'mainwp' ); ?></span></label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ui padded segment">
                    <input type="submit" class="ui big green basic right floated button" value="<?php esc_html_e( 'Let\'s Go!', 'mainwp' ); ?>" name="save_step" />
                    <?php wp_nonce_field( 'mwp-setup' ); ?>
                </div>
            </div>
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
        <div class="ui vertical basic padded segments">
            <div class="ui padded segment">
                <h1 class="ui massive header"><?php esc_html_e( 'System Requirements Check', 'mainwp' ); ?></h1>
                <p>
                    <?php
                    echo sprintf(
                        esc_html__( 'These checks ensure MainWP can connect to your child sites. If any check fails, %1$slearn how to resolve the issue%2$s.', 'mainwp' ),
                        '<a href="https://mainwp.com/kb/resolve-system-requirement-issues/" target="_blank">',
                        '</a>'
                    );
                    ?>
                </p>
                <?php MainWP_System_View::mainwp_warning_notice(); ?>
            </div>
        </div>
        <form method="post" class="ui form">
            <?php MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' ); ?>
            <div class="ui vertical basic padded segments">
                <?php
                $show_ssl = false;
                if ( MainWP_Server_Information_Handler::is_openssl_config_warning() ) {
                    $openssl_loc = MainWP_System_Utility::get_openssl_conf();
                    ?>
                    <div class="ui padded segment">
                        <div class="grouped fields">
                            <label><?php esc_html_e( 'What is the openssl.cnf file location on your computer?', 'mainwp' ); ?></label>
                            <div class="field" id="mainwp-setup-installation-openssl-location">
                                <div class="ui fluid input">
                                    <input type="text" name="mwp_setup_openssl_lib_location" value="<?php echo esc_attr( $openssl_loc ); ?>">
                                </div>
                                <div><em><?php esc_html_e( 'Due to bug with PHP on some servers, enter the openssl.cnf file location so MainWP Dashboard can connect to your child sites. If your openssl.cnf file is saved to a different path from what is entered above please enter your exact path. ', 'mainwp' ); ?><?php printf( esc_html__( '%1$sClick here%2$s to see how to find the OpenSSL.cnf file.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/how-to-find-the-openssl-cnf-file/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?></em></div>
                            </div>
                        </div>
                    </div>
                    <?php
                    $show_ssl = true;
                }
                ?>
                <div class="ui padded segment">
                    <?php MainWP_Server_Information::render_quick_setup_system_check(); ?>
                </div>
                <div class="ui padded segment">
                    <a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic button"><i class="arrow left icon"></i> <?php esc_html_e( 'Back to Introduction', 'mainwp' ); ?></a>
                    <?php if ( $show_ssl ) : ?>
                        <input type="submit" class="ui big basic green right floated button" value="<?php esc_attr_e( 'Next', 'mainwp' ); ?>" name="save_step" />
                    <?php else : ?>
                        <a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" class="ui big basic green right floated button"><?php esc_html_e( 'Next to Add Site', 'mainwp' ); ?> <i class="arrow right icon"></i></a>
                    <?php endif; ?>
                </div>
            </div>
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
     * Method mwp_setup_connect_first_site()
     *
     * Render Install first Child Site Step form.
     *
     * @uses MainWP_Manage_Sites_View::render_import_sites()
     * @uses MainWP_Manage_Sites::mainwp_managesites_form_import_sites()
     * @uses MainWP_Manage_Sites::mainwp_managesites_information_import_sites()
     * @uses MainWP_Manage_Sites::render_import_sites_modal()
     * @uses MainWP_Utility::show_mainwp_message()
     */
    public function mwp_setup_connect_first_site() {
        $has_file_upload = isset( $_FILES['mainwp_managesites_file_bulkupload'] ) && isset( $_FILES['mainwp_managesites_file_bulkupload']['error'] ) && UPLOAD_ERR_OK === $_FILES['mainwp_managesites_file_bulkupload']['error'];
        $has_import_data = ! empty( $_POST['mainwp_managesites_import'] );
        ?>
        <div class="ui vertical basic padded segments">
            <div class="ui padded segment">
                <h1 class="ui massive header"><?php esc_html_e( 'Connect Your First Child Site', 'mainwp' ); ?></h1>
                <p><?php esc_html_e( 'In the MainWP system, the sites you connect are referred to as "Child Sites."', 'mainwp' ); ?> <?php esc_html_e( 'These Child Sites will be managed centrally from your MainWP Dashboard.', 'mainwp' ); ?></p>
            </div>

            <div class="ui padded segment">
                <div class="ui form">
                    <div class="field">
                        <label><?php esc_html_e( 'How would you like to get started?', 'mainwp' ); ?></label>
                    </div>
                    <div class="grouped fields mainwp-field-tab-connect">
                        <div class="field">
                            <div class="ui compact invisible checkbox">
                                <input type="radio" name="tab_connect" tabindex="0" class="hidden" value="single-site">
                                <label for="tab_connect" class="ui icon message">
                                    <i class="plus icon"></i>
                                    <div class="content">
                                        <div class="header"><?php esc_html_e( 'Add One Site ', 'mainwp' ); ?></div>
                                        <p><?php esc_html_e( 'Add one site now, add more later.', 'mainwp' ); ?></p>
                                    </div>
                                </label>
                            </div>
                        </div>
                        <div class="field">
                            <div class="ui compact invisible checkbox">
                                <input type="radio" name="tab_connect" tabindex="0" class="hidden" value="multiple-site">
                                <label for="tab_connect" class="ui icon message">
                                    <i class="file upload icon"></i>
                                    <div class="content">
                                        <div class="header"><?php esc_html_e( 'Add Multiple Sites', 'mainwp' ); ?></div>
                                        <p><?php esc_html_e( 'Connect several sites right away.', 'mainwp' ); ?></p>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php if ( ( $has_file_upload || $has_import_data ) && check_admin_referer( 'mainwp-admin-nonce' ) ) : ?>
            <?php
            $url  = 'admin.php?page=mainwp-setup';
            $url .= '&step=add_client';
            MainWP_Manage_Sites::render_import_sites_modal( $url, 'Import Sites' );
            ?>
        <?php else : ?>
            <form method="post" action="" class="ui form" enctype="multipart/form-data" id="mainwp_connect_first_site_form">
                <div class="ui vertical basic padded segments">
                    <div class="ui padded segment">
                        <div id="mainwp-qsw-connect-site-form" style="display:none">
                            <div class="ui message" id="mainwp-message-zone" style="display:none"></div>
                            <div class="ui red message" id="mainwp-error-zone" style="display:none"></div>
                            <div class="ui green message" id="mainwp-success-zone" style="display:none"></div>
                            <div class="ui info message" id="mainwp-info-zone" style="display:none"></div>
                    
                            <div class="ui top attached equal width tabular massive menu menu-connect-first-site">
                                <a class="item active" data-tab="single-site"><i class="plus grey icon"></i> <?php esc_html_e( 'Connect a Single Site', 'mainwp' ); ?></a>
                                <a class="item" data-tab="multiple-site"><i class="file upload grey icon"></i> <?php esc_html_e( 'Connect Multiple Sites', 'mainwp' ); ?></a>
                            </div>

                            <div class="ui bottom attached tab segment active" data-tab="single-site">
                                <div>
                                    <div class="ui hidden divider"></div>
                                    <div class="field">
                                        <label for="mainwp_managesites_add_wpurl_protocol"><?php esc_html_e( 'What is the site URL?', 'mainwp' ); ?> <span class="ui red small text"><?php esc_html_e( '(Reqruired)', 'mainwp' ); ?></span></label>
                                        <div class="ui left action input">
                                            <select class="ui compact selection dropdown" id="mainwp_managesites_add_wpurl_protocol" name="mainwp_managesites_add_wpurl_protocol" style="width:120px;padding:0px;">
                                                <option value="https">https://</option>
                                                <option value="http">http://</option>
                                            </select>
                                            <input type="text" id="mainwp_managesites_add_wpurl" name="mainwp_managesites_add_wpurl" value="" placeholder="yoursite.com" />
                                        </div>
                                    </div>
                                    <div class="ui hidden divider"></div>
                                    <div class="field">
                                        <label for="mainwp_managesites_add_wpadmin"><?php esc_html_e( 'What is your administrator username on that site? ', 'mainwp' ); ?> <span class="ui red small text"><?php esc_html_e( '(Reqruired)', 'mainwp' ); ?></label>
                                        <input type="text" id="mainwp_managesites_add_wpadmin" name="mainwp_managesites_add_wpadmin" value="" />
                                    </div>
                                    <div class="ui hidden divider"></div>
                                    <div class="field">
                                        <label for=""><?php esc_html_e( 'Choose your connection authentication method:', 'mainwp' ); ?></label>
                                        <div class="ui hidden fitted divider"></div>
                                        <div class="ui toggle checked checkbox not-auto-init" id="addsite-adminpwd" style="margin-right:2em;">
                                            <input type="checkbox" id="mainwp-administrator-password-checkbox-field" checked=""><label><?php esc_html_e( 'Administrator password', 'mainwp' ); ?></label>
                                        </div>
                                        <div class="ui toggle checkbox not-auto-init" id="addsite-uniqueid">
                                            <input type="checkbox" id="mainwp-unique-security-id-checkbox-field"><label><?php esc_html_e( 'Unique Security ID', 'mainwp' ); ?></label>
                                        </div>
                                    </div>
                                    <div class="ui hidden divider"></div>
                                    <div class="ui fluid segment accordion" id="mainwp-connection-authentication-accordion" style="margin-top:1em">
                                        <div class="title">
                                            <i class="dropdown icon"></i>
                                            <?php esc_html_e( 'Connection authentication methods explained', 'mainwp' ); ?>
                                        </div>
                                        <div class="content">
                                            <span class="ui text"><?php esc_html_e( 'Choose options based on your MainWP Child plugin setup on the WordPress site you want to connect.', 'mainwp' ); ?></span>
                                            <div class="ui bulleted small list">
                                                <div class="item"><?php esc_html_e( 'Default Setup: Use only the Password field if you haven\'t changed the default settings.', 'mainwp' ); ?></div>
                                                <div class="item"><?php esc_html_e( 'Advanced Setup: If you\'ve turned off all verification on the child site, switch off both fields.', 'mainwp' ); ?></div>
                                            </div>
                                            <span class="ui text"><?php esc_html_e( 'Use the sliders to control the fields shown:', 'mainwp' ); ?></span>
                                            <div class="ui bulleted small list">
                                                <div class="item"><?php esc_html_e( 'Password On: Displays the Password field.', 'mainwp' ); ?></div>
                                                <div class="item"><?php esc_html_e( 'Security Key On: Displays the Security Key field.', 'mainwp' ); ?></div>
                                                <div class="item"><?php esc_html_e( 'Both On: Displays both fields.', 'mainwp' ); ?></div>
                                                <div class="item"><?php esc_html_e( 'Both Off: Hides both fields.', 'mainwp' ); ?></div>
                                            </div>
                                            <span class="ui  text"><strong><?php esc_html_e( 'This needs to match what is set on your child site. Default is Administrator password.', 'mainwp' ); ?></strong></span>
                                        </div>
                                    </div>
                                    <div class="field" id="mainwp-administrator-password-field">
                                        <label for="mainwp_managesites_add_admin_pwd"><?php esc_html_e( 'What is your administrator password on that site?', 'mainwp' ); ?> <span class="ui red small text"><?php esc_html_e( '(Reqruired if enabled)', 'mainwp' ); ?></span></label>
                                        <input type="password" id="mainwp_managesites_add_admin_pwd" name="mainwp_managesites_add_admin_pwd" value="" />
                                        <div class="ui up pointing basic grey label" style="line-height:1.5em"><?php esc_html_e( 'Your password is never stored by your Dashboard and never sent to MainWP.com. Once this initial connection is complete, your MainWP Dashboard generates a secure Public and Private key pair (2048 bits) using OpenSSL, allowing future connections without needing your password again. For added security, you can even change this admin password once connected, just be sure not to delete the admin account, as this would disrupt the connection.', 'mainwp' ); ?></div>
                                    </div>
                                    <div class="ui hidden divider"></div>
                                    <div class="field" id="mainwp-unique-security-id-field" style="display:none" >
                                        <label for="mainwp_managesites_add_uniqueId"><?php esc_html_e( 'Did you generate unique security ID on the site? If yes, copy it here, if not, leave this field blank. ', 'mainwp' ); ?> <span class="ui red small text"><?php esc_html_e( '(Reqruired if enabled)', 'mainwp' ); ?></span></label>
                                        <input type="text" id="mainwp_managesites_add_uniqueId" name="mainwp_managesites_add_uniqueId" value="" />
                                    </div>
                                    <div class="ui hidden divider"></div>
                                    <div class="field">
                                        <label for="mainwp_managesites_add_wpname"><?php esc_html_e( 'Add site title: ', 'mainwp' ); ?> <span class="ui grey small text"><?php esc_html_e( '(Optional)', 'mainwp' ); ?></label>
                                        <input type="text" id="mainwp_managesites_add_wpname" name="mainwp_managesites_add_wpname" value="" />
                                        <em><?php esc_html_e( 'This is how your site will appear in your MainWP Dashboard. If you skip this, we\'ll use the site\'s URL automatically.', 'mainwp' ); ?></em>
                                    </div>
                                </div>
                            </div>

                            <div class="ui bottom attached tab segment" data-tab="multiple-site">
                                <div class="mainwp-wish-to-csv mainwp-wish-to-migrate">
                                    <div class="ui fluid segment accordion" id="mainwp-add-sites-info-accordion">
                                        <div class="title">
                                            <i class="dropdown icon"></i>
                                            <?php esc_html_e( 'Click here to see instructions for adding multiple sites.', 'mainwp' ); ?>
                                        </div>
                                        <div class="content">
                                            <?php MainWP_Manage_Sites::mainwp_managesites_information_import_sites( true ); ?>
                                        </div>
                                    </div>
                                    <?php MainWP_Manage_Sites::mainwp_managesites_form_import_sites(); ?>
                                    <div class="ui hidden divider"></div>
                                    <span class="ui grey text"><?php esc_html_e( 'Your password is never stored by your Dashboard and never sent to MainWP.com. Once this initial connection is complete, your MainWP Dashboard generates a secure Public and Private key pair (2048 bits) using OpenSSL, allowing future connections without needing your password again. For added security, you can even change this admin password once connected, just be sure not to delete the admin account, as this would disrupt the connection.', 'mainwp' ); ?></span>
                                    <div class="ui hidden divider"></div>
                                    <div class="ui hidden divider"></div>
                                    <div class="ui hidden divider"></div>
                                    <div class="ui horizontal left aligned divider">
                                        <?php esc_attr_e( 'or upload csv file', 'mainwp' ); ?>
                                    </div>
                                    <div class="ui hidden divider"></div>
                                    <div class="ui hidden divider"></div>
                                    <div class="ui hidden divider"></div>
                                    <div class="ui hidden divider"></div>
                                    <div class="field">
                                        <label for="mainwp_managesites_file_bulkupload"><?php esc_html_e( 'Upload the CSV file', 'mainwp' ); ?> (<a href="<?php echo esc_url( MAINWP_PLUGIN_URL . 'assets/csv/sample.csv' ); ?>"><?php esc_html_e( 'Download sample CSV file', 'mainwp' ); ?></a>)</label>
                                        <div class="ui grid">
                                            <div class="eight wide middle aligned column">
                                                <div class="ui file grey input">
                                                    <input type="file" name="mainwp_managesites_file_bulkupload" id="connect_first_site_file_bulkupload" accept="text/comma-separated-values" />
                                                </div>
                                            </div>
                                            <div class="eight wide middle aligned column">
                                                <div class="ui toggle checkbox ten wide middle aligned column">
                                                    <input type="checkbox" name="mainwp_managesites_chk_header_first" checked="checked" id="managesites_chk_header_first" value="1" /> <label for="mainwp_managesites_chk_header_first"><?php esc_html_e( 'CSV file contains a header', 'mainwp' ); ?></label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="ui hidden divider"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="ui padded segment">
                        <div class="ui toggle checkbox" id="mainwp-qsw-toggle-verify-mainwp-child-active" style="display:none">
                            <input type="checkbox" name="mainwp-qsw-verify-mainwp-child-active" id="mainwp-qsw-verify-mainwp-child-active" >
                            <label for="mainwp-qsw-verify-mainwp-child-active" ><?php esc_html_e( 'Confirm that the MainWP Child plugin is activated on the site(s) you wish to connect.', 'mainwp' ); ?></label>
                        </div>
                    </div>
                    <div class="ui padded segment">
                        <?php wp_nonce_field( 'mwp-setup' ); ?>
                        <?php MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' ); ?>
                        <input type="hidden" id="nonce_secure_data" mainwp_addwp="<?php echo esc_js( wp_create_nonce( 'mainwp_addwp' ) ); ?>" mainwp_checkwp="<?php echo esc_attr( wp_create_nonce( 'mainwp_checkwp' ) ); ?>" />
                        <div class="ui two column grid">
                            <div class="column">
                                <a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic button"><i class="arrow left icon"></i> <?php esc_html_e( 'Back to System Check', 'mainwp' ); ?></a>
                            </div>
                            
                            <div class="column">
                                <a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" id="mainwp_addsite_continue_button" class="ui big basic green right floated button"><?php esc_html_e( 'Continue Without Adding Sites', 'mainwp' ); ?> <i class="arrow right icon"></i></a>
                                <a style="display:none" name="mainwp_managesites_add" id="mainwp_managesites_add" class="ui button green basic big right floated disabled"><?php esc_html_e( 'Connect Site and Proceed', 'mainwp' ); ?> <i class="arrow right icon"></i></a>
                                <a style="display:none" name="mainwp_managesites_add_import" id="mainwp_managesites_add_import" class="ui button basic green big right floated disabled"><?php esc_html_e( 'Connect Sites and Proceed', 'mainwp' ); ?> <i class="arrow right icon"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>
                
        <script>
            jQuery('.menu-connect-first-site .item').tab({
                'onVisible': function() {
                    mainwp_menu_connect_first_site_onvisible_callback(this);
                }
            });
            jQuery('#mainwp-add-sites-info-accordion').accordion();
        </script>
        <?php
        MainWP_Manage_Sites::render_add_site_scripts();
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
                        <div><?php esc_html_e( 'By adding a new client, you streamline site management within MainWP. Assigning sites to clients allows you to group and manage websites according to the clients they belong to for better organization and accessibility.', 'mainwp' ); ?></div>
                        <div class="ui hidden divider"></div>
                        <div class="ui toggle checkbox">
                            <input type="checkbox" name="mainwp-qsw-confirm-add-new-client" id="mainwp-qsw-confirm-add-new-client" checked="true"/>
                            <label><?php esc_html_e( 'Select to create a New Client', 'mainwp' ); ?></label>
                        </div>
                    </div>
                <?php } ?>
                <?php MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' ); ?>
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
     * Method mwp_setup_add_client()
     *
     * Render Add first Client Step form.
     */
    public function mwp_setup_add_client() {
        $count_clients     = MainWP_DB_Client::instance()->count_total_clients();
        $sites             = MainWP_DB::instance()->get_sites(); // Get site data.
        $total_sites       = ! empty( $sites ) ? count( $sites ) : 5; // Set default.
        $item_class_active = 1 === $total_sites ? 'active' : '';
        $tab_class_active  = 1 < $total_sites ? 'active' : '';
        $first_site_id     = get_transient( 'mainwp_transient_just_connected_site_id' ); 
        ?>
        <div class="ui vertical basic padded segments">
            <div class="ui padded segment">
                <h1 class="ui massive header"><?php esc_html_e( 'Add Your First Client', 'mainwp' ); ?></h1>
                <p><?php esc_html_e( 'In the MainWP system, Clients help you organize and group your Child Sites.', 'mainwp' ); ?> <?php esc_html_e( 'You can assign one or more sites to each Client to keep your Dashboard organized and make management easier.', 'mainwp' ); ?></p>
            </div>
        </div>
        <form action="" method="post" enctype="multipart/form-data" name="createclient_form" id="createclient_form" class="add:clients: validate">
            <div class="ui vertical basic padded segments">
                <div class="ui padded segment">
                    <div class="ui red message" id="mainwp-message-zone" style="display:none"></div>
                    <div class="ui message" id="mainwp-message-zone-client" style="display:none;"></div>
                    <?php MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' ); ?>
                    <div class="ui top attached equal width tabular massive menu mainwp-qsw-add-client">
                        <a class="item <?php echo esc_attr( $item_class_active ); ?>" data-tab="single-client"><i class="user grey icon"></i> <?php esc_html_e( 'Add a Single Client', 'mainwp' ); ?></a>
                        <a class="item <?php echo esc_attr( $tab_class_active ); ?>" data-tab="multiple-client"><i class="users grey icon"></i> <?php esc_html_e( 'Add Multiple Clients', 'mainwp' ); ?></a>
                    </div>
                    <div class="ui bottom attached tab segment <?php echo esc_attr( $item_class_active ); ?>" data-tab="single-client">
                        <div class="ui hidden divider"></div>
                        <div id="mainwp-add-new-client-form" >
                            <?php $this->render_add_client_content( false, true ); ?>
                        </div>
                        <input type="hidden" name="selected_first_site" value="<?php echo intval( $first_site_id ); ?>">
                    </div>
                    <div class="ui bottom attached tab segment <?php echo esc_attr( $tab_class_active ); ?>" data-tab="multiple-client">
                        <div class="ui blue message">
                        <div><?php esc_html_e( 'For each site you’ve imported, please enter the Client Name and Client Email.', 'mainwp' ); ?></div>
                        <div><?php esc_html_e( 'If the same client name and email are used across multiple sites, those sites will be merged and assigned to a single client profile.', 'mainwp' ); ?></div>
                        <div><strong><?php esc_html_e( 'You can always update or edit this information later from the Clients module in your MainWP Dashboard.', 'mainwp' ); ?></strong></div>
                        </div>
                        <div>
                            <div class="ui middle aligned left aligned compact grid">
                                <div class="ui row">
                                    <div class="five wide column" >
                                        <span class="ui text small"><strong><?php esc_html_e( 'Client Site URL', 'mainwp' ); ?></strong> <span class="ui grey small text"><?php esc_html_e( '(Auto applied)', 'mainwp' ); ?></span></span>
                                    </div>
                                    <div class="five wide column">
                                        <span class="ui text small"><strong><?php esc_html_e( 'Client Name', 'mainwp' ); ?></strong> <span class="ui red small text"><?php esc_html_e( '(Reqruired)', 'mainwp' ); ?></span></span>
                                    </div>
                                    <div class="five wide column">
                                        <span class="ui text small"><strong><?php esc_html_e( 'Client Email', 'mainwp' ); ?></strong> <span class="ui red small text"><?php esc_html_e( '(Reqruired)', 'mainwp' ); ?></span></span>
                                    </div>
                                    <div class="one wide column">
                                        <span></span>
                                    </div>
                                </div>
                                <?php
                                for ( $i = 0; $i < $total_sites; $i++ ) {
                                    $website = isset( $sites[ $i ] ) ? $sites[ $i ] : array();
                                    $this->render_multi_add_client_content( $i, $website );
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ui padded segment">
                    <a style="display:none" name="createclient" current-page="qsw-add" id="bulk_add_createclient" class="ui big green basic right floated button"><?php echo esc_attr__( 'Add and Proceed to Monitoring', 'mainwp' ); ?> <i class="arrow right icon"></i></a>
                    <a style="display:none" name="create_multi_client" current-page="qsw-add" id="bulk_add_multi_create_client" class="ui big green basic right floated button"><?php echo esc_attr__( 'Add and Proceed to Monitoring', 'mainwp' ); ?> <i class="arrow right icon"></i></a>
                    <a href="<?php echo esc_url( $this->get_next_step_link() ); ?>" id="mainwp_qsw_add_client_continue_button" class="ui big green basic right floated button"><?php esc_html_e( 'Continue Without Creating Clients', 'mainwp' ); ?> <i class="arrow right icon"></i></a>
                    <a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic button"><i class="arrow left icon"></i> <?php esc_html_e( 'Back to Add Sites', 'mainwp' ); ?></a>
                </div>
            </div>
        </form>
        <script>
            jQuery('.mainwp-qsw-add-client .item').tab({
                'onVisible': function() {
                    mainwp_add_client_onvisible_callback(this);
                }
            });
        </script>
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
        $default_client_fields = MainWP_Client_Handler::get_mini_default_client_fields();
        $first_site_id         = get_transient( 'mainwp_transient_just_connected_site_id' );
        $website               = MainWP_DB::instance()->get_website_by_id( $first_site_id );
        ?>
        <div class="ui form">
            <?php if ( $first_site_id ) : ?>
                <div class="field">
                    <label><?php esc_html_e( 'Client Site URL', 'mainwp' ); ?></label>
                    <div class="ui disabled fluid input">
                        <input type="text" value="<?php echo esc_url( $website->url ); ?>" />
                    </div>
                </div>
            <?php endif; ?>
            <?php
            foreach ( $default_client_fields as $field_name => $field ) {
                $db_field = isset( $field['db_field'] ) ? $field['db_field'] : '';
                $val      = $edit_client && '' !== $db_field && property_exists( $edit_client, $db_field ) ? $edit_client->{$db_field} : '';
                $tip      = isset( $field['tooltip'] ) ? $field['tooltip'] : '';
                ?>
                <div class="field">
                    <label for="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]"><?php echo esc_html( $field['title'] ); ?> <?php echo isset( $field['required'] ) && $field['required'] ? '<span class="ui red small text">' . esc_html__( '(Required)', 'mainwp' ) . '</span>' : ''; ?></label>
                    <input type="text" value="<?php echo esc_html( $val ); ?>" id="mainwp_qsw_client_name_field" class="regular-text" name="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]"/>
                </div>
                <?php

                if ( 'client.email' === $field_name ) {
                    ?>
                    <div class="field">
                        <label><?php esc_html_e( 'Client photo', 'mainwp' ); ?></label>
                        <div class="ui file grey input">
                            <input type="file" name="mainwp_client_image_uploader[client_field]" accept="image/*" data-inverted="" data-tooltip="<?php esc_attr_e( "Image must be 500KB maximum. It will be cropped to 310px wide and 70px tall. For best results  us an image of this site. Allowed formats: jpeg, gif and png. Note that animated gifs aren't going to be preserved.", 'mainwp' ); ?>" />
                        </div>
                    </div>
                    <?php
                }
            }
            $temp = $this->get_add_contact_temp( false );
            ?>
            <div class="field">
                <a href="javascript:void(0);" class="mainwp-client-add-contact" add-contact-temp="<?php echo esc_attr( $temp ); ?>"><i class="plus icon"></i><?php esc_html_e( 'Additional Contact Information', 'mainwp' ); ?></a>
            </div>
            <div class="ui section hidden divider after-add-contact-field"></div>
        </div>
        <input type="hidden" name="client_fields[client_id]" value="<?php echo intval( $client_id ); ?>">
        <?php
    }

    /**
     * Method render_multi_add_client_content()
     *
     * Render form multi create client.
     *
     * @uses MainWP_Client_Handler::get_mini_default_contact_fields()
     *
     * @param int   $index row index.
     * @param array $website website data.
     */
    public function render_multi_add_client_content( $index, $website ) {
        $contact_fields = MainWP_Client_Handler::get_mini_default_contact_fields();
        ?>
        <div class="row mainwp-qsw-add-client-rows" id="mainwp-qsw-add-client-row-<?php echo esc_attr( $index ); ?>">
            <div class="five wide column">
                <div class="ui mini fluid input">
                    <input type="text" name="mainwp_add_client[<?php echo esc_attr( $index ); ?>][site_url]" class="mainwp-qsw-add-client-site-url" value="<?php echo isset( $website['url'] ) ? esc_attr( $website['url'] ) : ''; ?>" data-row-index="<?php echo esc_attr( $index ); ?>" id="mainwp-qsw-add-client-site-url-<?php echo esc_attr( $index ); ?>" <?php echo isset( $website['id'] ) ? 'disabled' : ''; ?>>
                    <?php if ( isset( $website['id'] ) ) : ?>
                        <input type="hidden" name="mainwp_add_client[<?php echo esc_attr( $index ); ?>][website_id]" value="<?php echo intval( $website['id'] ); ?>" id="mainwp-qsw-add-client-website-id-<?php echo esc_attr( $index ); ?>" >
                    <?php endif ?>
                </div>
            </div>
            <div class="five wide column">
                <div class="ui mini fluid input">
                    <input type="text" name="mainwp_add_client[<?php echo esc_attr( $index ); ?>][client_name]" class="mainwp-qsw-add-client-client-name" value="" data-row-index="<?php echo esc_attr( $index ); ?>" id="mainwp-qsw-add-client-client-name-<?php echo esc_attr( $index ); ?>">
                </div>
            </div>
            <div class="five wide column">
                <div class="ui mini fluid input">
                    <input type="email" name="mainwp_add_client[<?php echo esc_attr( $index ); ?>][client_email]" class="mainwp-qsw-add-client-client-email" value="" data-row-index="<?php echo esc_attr( $index ); ?>" id="mainwp-qsw-add-client-client-email-<?php echo esc_attr( $index ); ?>">
                </div>
            </div>
            <div class="one wide column">
                <div class="ui mini fluid input">
                    <a class="mainwp-qsw-add-client-more-row" onclick="mainwp_qsw_add_client_more_row(<?php echo esc_attr( $index ); ?>)" style="margin-right: 10px !important;">
                        <i class="caret left icon"  id="icon-visible-<?php echo esc_attr( $index ); ?>"></i>
                        <i class="caret down icon" id="icon-hidden-<?php echo esc_attr( $index ); ?>" style="display:none"></i>
                    </a>
                    <a class="mainwp-qsw-add-client-delete-row" href="javascript:void(0)" onclick="mainwp_qsw_add_client_delete_row(<?php echo esc_attr( $index ); ?>)">
                        <i class="trash alternate outline icon"></i>
                    </a>
                </div>
            </div>
            <?php if ( ! empty( $contact_fields ) ) : ?>
                <?php foreach ( $contact_fields as $field_name => $field ) : ?>
                    <div class="five wide column mainwp-qsw-add-client-column-more-<?php echo esc_attr( $index ); ?>" style="display:none">
                        <span class="ui small text"><?php echo esc_html( $field['title'] ); ?></span>
                        <div class="ui mini fluid input">
                            <input type="text" name="client_fields[<?php echo esc_attr( $index ); ?>][new_contacts_field][<?php echo esc_attr( $field_name ); ?>][]" class="mainwp-qsw-add-client-client-fields">
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
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

        $input_name = 'new_contacts_field';
        $contact_id = 0;
        ob_start();
        ?>
            <div class="ui hidden divider top-contact-fields"></div> <?php // must have class: top-contact-fields. ?>
            <div class="ui left aligned horizontal divider"><?php esc_html_e( 'Additional Contact Information', 'mainwp' ); ?></div>
            <div class="ui hidden divider"></div>
            <div class="ui hidden divider"></div>
            <?php
            $contact_fields = MainWP_Client_Handler::get_mini_default_contact_fields();
            foreach ( $contact_fields as $field_name => $field ) {
                $val        = '';
                $contact_id = '';
                ?>
                <div class="field">
                    <label><?php echo esc_html( $field['title'] ); ?> <?php echo isset( $field['required'] ) && $field['required'] ? '<span class="ui red small text">' . esc_html__( '(Required)', 'mainwp' ) . '</span>' : ''; ?></label>
                    <input type="text" value="<?php echo esc_html( $val ); ?>" class="regular-text" name="client_fields[<?php echo esc_html( $input_name ); ?>][<?php echo esc_attr( $field_name ); ?>][]"/>
                </div>
                <?php
            }
            ?>
            <div class="field remove-contact-field-parent">
                <a href="javascript:void(0);" contact-id="<?php echo intval( $contact_id ); ?>" class="mainwp-client-remove-contact"><i class="minus icon"></i><?php esc_html_e( 'Remove contact', 'mainwp' ); ?></a>
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

        $disableSitesHealthMonitoring = get_option( 'mainwp_disableSitesHealthMonitoring', 1 );
        $sitehealthThreshold          = get_option( 'mainwp_sitehealthThreshold', 80 ); // "Should be improved" threshold.

        $global_settings = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();

        $glo_active = 0;
        if ( isset( $global_settings['active'] ) ) {
            $glo_active = 1 === (int) $global_settings['active'] ? 1 : 0;
        }
        ?>
        <form method="post" class="ui form">
            <?php MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' ); ?>
            <div class="ui vertical basic padded segments">
                <div class="ui padded segment">
                    <h1 class="ui massive header"><?php esc_html_e( 'Enable Monitoring for Your Sites', 'mainwp' ); ?></h1>
                    <p><?php esc_html_e( 'MainWP can automatically keep an eye on your Child Sites\' uptime and overall health.', 'mainwp' ); ?></p>
                </div>
            </div>
            <div class="ui vertical basic padded segments">
                <div class="ui padded segment">
                    <h2 class="ui big header"><?php esc_html_e( 'Uptime Monitoring', 'mainwp' ); ?></h2>
                    <p><?php esc_html_e( 'MainWP checks your sites at regular intervals to ensure they\'re online and reachable. You\'ll get an email alert if any site goes down. No third-party service needed.', 'mainwp' ); ?></p>
                    <div class="ui hidden divider"></div>
                    <div class="field settings-field-indicator-wrapper settings-field-indicator-monitor-general" default-indi-value="0">
                        <label>
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_setup_enableUptimeMonitoring', (int) $glo_active, true, 0 );
                            esc_html_e( 'Enable Uptime Monitoring', 'mainwp' );
                            ?>
                        </label>
                        <div class="ui hidden divider"></div>
                        <div class="ui toggle checkbox mainwp-checkbox-showhide-elements" hide-parent="uptime-monitoring">
                            <input type="checkbox" value="1" class="settings-field-value-change-handler" name="mainwp_setup_enableUptimeMonitoring" id="mainwp_setup_enableUptimeMonitoring" <?php echo 1 === (int) $glo_active ? 'checked="true"' : ''; ?>/>
                        </div>
                    </div>
                    <div class="ui hidden divider"></div>
                    <div class="field settings-field-indicator-wrapper settings-field-indicator-monitor-general" default-indi-value="60" <?php echo $glo_active ? '' : 'style="display:none"'; ?> hide-element="uptime-monitoring">
                        <label>
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_setup_monitor_interval_hidden', (int) $global_settings['interval'], true, 60 );
                            esc_html_e( 'Monitor Interval (minutes)', 'mainwp' );
                            ?>
                        </label>
                        <div class="ui hidden divider"></div>
                        <div class="ui labeled ticked slider settings-field-value-change-handler" id="mainwp_setup_monitor_interval_slider" style="max-width: 600px"></div>
                        <input type="hidden" name="mainwp_setup_monitor_interval_hidden" class="settings-field-value-change-handler" id="mainwp_setup_monitor_interval_hidden" value="<?php echo intval( $global_settings['interval'] ); ?>" />
                    </div>
                </div>
            </div>
            <div class="ui vertical basic padded segments">
                <div class="ui padded segment">
                    <h2 class="ui big header"><?php esc_html_e( 'Site Health Monitoring', 'mainwp' ); ?></h2>
                    <p><?php esc_html_e( 'MainWP keeps an eye on each site\'s WordPress Site Health status and alerts you if any issues appear or a site\'s health drops below "Good".', 'mainwp' ); ?></p>
                    <div class="ui hidden divider"></div>
                    <div class="field settings-field-indicator-wrapper" default-indi-value="1">
                        <label>
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_disableSitesHealthMonitoring', (int) $disableSitesHealthMonitoring );
                            esc_html_e( 'Enable Site Health monitoring', 'mainwp' );
                            ?>
                        </label>
                        <div class="ui hidden divider"></div>
                        <div class="ui toggle checkbox mainwp-checkbox-showhide-elements" hide-parent="health-monitoring">
                            <input type="checkbox" class="settings-field-value-change-handler" inverted-value="1" name="mainwp_setup_disable_sitesHealthMonitoring" id="mainwp_setup_disable_sitesHealthMonitoring" <?php echo 1 === (int) $disableSitesHealthMonitoring ? '' : 'checked="true"'; ?>/>
                        </div>
                    </div>
                    <div class="ui hidden divider"></div>
                    <div class="field settings-field-indicator-wrapper" default-indi-value="80" <?php echo $disableSitesHealthMonitoring ? 'style="display:none"' : ''; ?> hide-element="health-monitoring">
                        <label>
                            <?php
                            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_sitehealthThreshold', (int) $sitehealthThreshold );
                            esc_html_e( 'Site health threshold', 'mainwp' );
                            ?>
                        </label>
                        <div class="ui hidden divider"></div>
                        <select name="mainwp_setup_site_healthThreshold" id="mainwp_setup_site_healthThreshold" class="ui compact dropdown settings-field-value-change-handler">
                            <option value="80" <?php echo 80 === $sitehealthThreshold || 0 === $sitehealthThreshold ? 'selected' : ''; ?>><?php esc_html_e( 'Should be improved', 'mainwp' ); ?></option>
                            <option value="100" <?php echo 100 === $sitehealthThreshold ? 'selected' : ''; ?>><?php esc_html_e( 'Good', 'mainwp' ); ?></option>
                        </select>
                    </div>
                </div>
                <div class="ui padded segment">
                    <input type="submit" class="ui big green basic right floated button" value="<?php esc_attr_e( 'Save and Finish!', 'mainwp' ); ?>" name="save_step" />
                    <a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui big basic button"><i class="arrow left icon"></i> <?php esc_html_e( 'Back to Add Client', 'mainwp' ); ?></a>
                </div>
            </div>
            <?php wp_nonce_field( 'mwp-setup' ); ?>
        </form>

        <script type="text/javascript">
            <?php
            $all_intervals = MainWP_Uptime_Monitoring_Edit::get_interval_values( false );
            echo 'var interval_label = ' . wp_json_encode( array_values( $all_intervals ) ) . ";\n";
            echo 'var interval_values = ' . wp_json_encode( array_keys( $all_intervals ) ) . ";\n";
            ?>
            jQuery('#mainwp_setup_monitor_interval_slider').slider({
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
                    jQuery('#mainwp_setup_monitor_interval_hidden').val(interval_values[value]).change();
                },
                onMove: function(value) {
                    jQuery(this).find('.thumb').attr('data-tooltip', interval_label[value]);
                }
            });
            jQuery('#mainwp_setup_monitor_interval_slider').slider('set value', interval_values.indexOf(<?php echo intval( $global_settings['interval'] ); ?>));
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
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $global_settings             = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
        $global_settings['active']   = ! empty( $_POST['mainwp_setup_enableUptimeMonitoring'] ) ? 1 : 0;
        $global_settings['interval'] = isset( $_POST['mainwp_setup_monitor_interval_hidden'] ) ? intval( $_POST['mainwp_setup_monitor_interval_hidden'] ) : 60;

        MainWP_Uptime_Monitoring_Handle::update_uptime_global_settings( $global_settings );

        MainWP_Utility::update_option( 'mainwp_disableSitesHealthMonitoring', ( ! isset( $_POST['mainwp_setup_disable_sitesHealthMonitoring'] ) ? 1 : 0 ) );
        $val = isset( $_POST['mainwp_setup_site_healthThreshold'] ) ? intval( $_POST['mainwp_setup_site_healthThreshold'] ) : 80;
        MainWP_Utility::update_option( 'mainwp_sitehealthThreshold', $val );
        // phpcs:enable
        wp_safe_redirect( $this->get_next_step_link() );
        exit;
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
        <div class="ui basic segment">
            <h1 class="ui icon huge header">
                <em data-emoji=":tada:" class="big tadaaaa"></em>
            </h1>
            <h2 class="ui massive header"><?php esc_html_e( 'Setup Complete!', 'mainwp' ); ?></h2>
        </div>
        <div class="ui basic segment">
            <div><?php esc_html_e( 'Your MainWP Dashboard is configured and connected. You can now:', 'mainwp' ); ?></div>
            <div class="ui bulleted list">
                <div class="item"><?php esc_html_e( 'Manage updates across all connected sites', 'mainwp' ); ?></div>
                <div class="item"><?php esc_html_e( 'Monitor uptime and site performance', 'mainwp' ); ?></div>
                <div class="item"><?php esc_html_e( 'Update plugins and themes in bulk', 'mainwp' ); ?></div>
                <div class="item"><?php esc_html_e( 'Run backups and maintenance tasks', 'mainwp' ); ?></div>
                <div class="item"><?php esc_html_e( 'And much more, all from YOUR MainWP Dashboard.', 'mainwp' ); ?></div>
            </div>
        </div> 
        <div class="ui basic segment">
            <a class="ui huge green fade in transition button" href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp_tab' ) ); ?>"><?php esc_html_e( 'Go to Dashboard', 'mainwp' ); ?></a>
        </div>
        <div class="ui basic segment">
            <span class="ui grey text"><?php esc_html_e( 'Your connected sites are ready to manage from your MainWP Dashboard.', 'mainwp' ); ?></span>
        </div>
        <div class="ui hidden divider"></div>
        <div class="ui hidden divider"></div>
        <?php
    }

    /**
     * Render usetiful tours.
     */
    public static function mainwp_usetiful_tours() {
        echo "
        <script>
    (function (w, d, s) {
        let a = d.getElementsByTagName('head')[0];
        let r = d.createElement('script');
        r.async = 1;
        r.src = s;
        r.setAttribute('id', 'usetifulScript');
        r.dataset.token = '480fa17b0507a1c60abba94bfdadd0a7';
                            a.appendChild(r);
      })(window, document, 'https://www.usetiful.com/dist/usetiful.js');</script>
        ";
    }
}
