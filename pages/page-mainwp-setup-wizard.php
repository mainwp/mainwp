<?php
/**
 * MainWP Setup Wizard
 *
 * MainWP Quick Setup Wizard enables you to quickly set basic plugin settings
 *
 * @package MainWP/Setup_Wizard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
    public function admin_init() { // phpcs:ignore -- NOSONAR - complex method.
        if ( empty( $_GET['page'] ) || 'mainwp-setup' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return;
        }
        // Consume entry redirects here so every wizard exit keeps its requested destination.
        delete_option( 'mainwp_run_quick_setup' );
        delete_option( 'mainwp_activated' );
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
                'handler' => '',
                'hidden'  => true,
            ),
            'system_check'       => array(
                'name'    => esc_html__( 'System Check', 'mainwp' ),
                'desc'    => esc_html__( 'Verify requirements', 'mainwp' ),
                'view'    => array( $this, 'mwp_setup_system_requirements' ),
                'handler' => array( $this, 'mwp_setup_system_requirements_save' ),
                'hidden'  => true,
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
                'hidden'  => true,
            ),
            'next_steps'         => array(
                'name'    => esc_html__( 'Site Summary', 'mainwp' ),
                'desc'    => esc_html__( 'Updates and health', 'mainwp' ),
                'view'    => array( $this, 'mwp_setup_ready' ),
                'handler' => array( $this, 'mwp_setup_ready_save' ),
            ),
        );

        $this->step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : current( array_keys( $this->steps ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( ! isset( $this->steps[ $this->step ] ) ) {
            $this->step = 'welcome';
        }

        wp_enqueue_script( 'fomantic-ui', MAINWP_PLUGIN_URL . 'assets/js/fomantic-ui/fomantic-ui.js', array( 'jquery' ), MAINWP_VERSION, false );
        wp_enqueue_script( 'mainwp', MAINWP_PLUGIN_URL . 'assets/js/mainwp.js', array( 'jquery' ), MAINWP_VERSION, true );
        wp_enqueue_script( 'mainwp-clients', MAINWP_PLUGIN_URL . 'assets/js/mainwp-clients.js', array(), MAINWP_VERSION, true );
        wp_enqueue_script( 'mainwp-setup', MAINWP_PLUGIN_URL . 'assets/js/mainwp-setup.js', array( 'jquery', 'fomantic-ui' ), MAINWP_VERSION, true );
        wp_localize_script(
            'mainwp-setup',
            'mainwpSetupLocalize',
            array(
                'checking'      => __( 'Checking...', 'mainwp' ),
                'yes'           => __( 'Yes', 'mainwp' ),
                'notYet'        => __( 'Not yet', 'mainwp' ),
                'no'            => __( 'No', 'mainwp' ),
                'notChecked'    => __( 'Not checked', 'mainwp' ),
                /* translators: %s: validated site URL. */
                'unreachable'   => __( "We can't reach %s. Check the URL and that the site is online.", 'mainwp' ),
                /* translators: %s: validated site URL. */
                'install'       => __( 'Install MainWP Child on %s →', 'mainwp' ),
                /* translators: %s: validated site URL. */
                'fallback'      => __( "If the link doesn't open the plugin installer, install MainWP Child from Plugins > Add New on %s.", 'mainwp' ),
                /* translators: %d: minutes remaining. */
                'remaining'     => __( 'About %d minutes left to connect.', 'mainwp' ),
                'remainingOne'  => __( 'About 1 minute left to connect.', 'mainwp' ),
                'remainingLess' => __( 'Less than a minute left to connect.', 'mainwp' ),
                'windowClosed'  => __( 'The connection window may have ended. If connecting fails, check that MainWP Child is still active.', 'mainwp' ),
                'defaultTime'   => __( 'By default this is 20 minutes after activation.', 'mainwp' ),
                /* translators: %s: validated site URL. */
                'expired'       => __( 'MainWP Child may have switched itself off because the connection window expired. Check that it is active on %s, reactivate it if needed, and click Retry.', 'mainwp' ),
                'error'         => __( 'Undefined error occurred. Please try again.', 'mainwp' ),
                'password'      => __( 'Please enter the administrator password.', 'mainwp' ),
            )
        );
        wp_enqueue_script( 'mainwp-import', MAINWP_PLUGIN_URL . 'assets/js/mainwp-managesites-import.js', array( 'jquery' ), MAINWP_VERSION, true );
        wp_enqueue_script( 'mainwp-ui', MAINWP_PLUGIN_URL . 'assets/js/mainwp-ui.js', array(), MAINWP_VERSION, true );
        if ( 'next_steps' === $this->step ) {
            wp_enqueue_script( 'mainwp-updates', MAINWP_PLUGIN_URL . 'assets/js/mainwp-updates.js', array( 'mainwp' ), MAINWP_VERSION, true );
            wp_enqueue_script( 'mainwp-backups', MAINWP_PLUGIN_URL . 'assets/js/mainwp-backups.js', array( 'mainwp' ), MAINWP_VERSION, true );
            wp_enqueue_script( 'mainwp-js-popup', MAINWP_PLUGIN_URL . 'assets/js/mainwp-popup.js', array( 'mainwp-ui' ), MAINWP_VERSION, true );
        }
        wp_enqueue_style( 'mainwp-onboarding', MAINWP_PLUGIN_URL . 'assets/css/mainwp-onboarding.css', array(), MAINWP_VERSION );
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

        if ( ! empty( $_POST['save_step'] ) && is_callable( $this->steps[ $this->step ]['handler'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
                if ( 'next_steps' === $this->step ) {
                    wp_print_scripts( array( 'mainwp-updates', 'mainwp-backups', 'mainwp-js-popup' ) );
                }
                ?>
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
                        <?php if ( ! in_array( $this->step, array( 'welcome', 'introduction', 'connect_first_site', 'next_steps' ), true ) ) : ?>
                            <?php $this->render_exit_links(); ?>
                        <?php endif; ?>
                    </div>
                    <div class="two wide column"></div>
                </div>
            </body>
        </html>
        <?php
    }

    /**
     * Keep exit destinations in the footer flow so they cannot cover form controls.
     */
    private function render_exit_links() {
        ?>
        <div id="mainwp-qsw-exit-links" class="ui small basic segment">
            <?php esc_html_e( 'Go to', 'mainwp' ); ?> <a href="<?php echo esc_url( admin_url( 'index.php' ) ); ?>"><?php esc_html_e( 'WP Admin', 'mainwp' ); ?></a> <?php esc_html_e( 'or', 'mainwp' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp_tab' ) ); ?>"><?php esc_html_e( 'MainWP', 'mainwp' ); ?></a>
        </div>
        <?php
    }

    /**
     * Method setup_wizard_steps()
     *
     * Render Setup Wizards Steps.
     */
    public function setup_wizard_steps() { // phpcs:ignore -- NOSONAR - complex method.
        $ouput_steps = $this->steps;
        ?>
        <div id="mainwp-quick-setup-wizard-steps" class="ui circular ordered stackable fluid steps" style="">
            <?php foreach ( $ouput_steps as $step_key => $step ) { ?>
                <?php
                if ( isset( $step['hidden'] ) && $step['hidden'] ) {
                    continue;
                }

                ?>
                <div
                <?php
                if ( $step_key === $this->step ) {
                    // Check if this is the last step.
                    $step_keys     = array_keys( $this->steps ); // NOSONAR -- ??.
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
        $start_url = $this->get_next_step_link( $this->system_checks_pass() ? 'connect_first_site' : 'system_check' );
        ?>
        <div class="ui padded segment">
            <h1 class="ui massive header"><?php esc_html_e( 'How do you want to start?', 'mainwp' ); ?></h1>
            <p><?php esc_html_e( 'Connect one WordPress site or import your sites from a CSV file.', 'mainwp' ); ?></p>
            <div class="ui two stackable link cards">
                <a class="ui card" href="<?php echo esc_url( add_query_arg( 'mode', 'single-site', $start_url ) ); ?>">
                    <div class="content">
                        <span class="ui small green label"><?php esc_html_e( 'Recommended', 'mainwp' ); ?></span>
                        <h2 class="header"><?php esc_html_e( 'Connect my first site', 'mainwp' ); ?></h2>
                        <p><?php esc_html_e( "Add one WordPress site. We'll check it and guide you through installing MainWP Child.", 'mainwp' ); ?></p>
                    </div>
                    <div class="extra content"><span class="ui green button"><?php esc_html_e( 'Connect my first site', 'mainwp' ); ?></span></div>
                </a>
                <a class="ui card" href="<?php echo esc_url( add_query_arg( 'mode', 'multiple-site', $start_url ) ); ?>">
                    <div class="content">
                        <h2 class="header"><?php esc_html_e( 'Import a CSV', 'mainwp' ); ?></h2>
                        <p><?php esc_html_e( "Upload a CSV file with your sites' URLs and administrator usernames.", 'mainwp' ); ?></p>
                    </div>
                    <div class="extra content"><span class="ui basic button"><?php esc_html_e( 'Import a CSV', 'mainwp' ); ?></span></div>
                </a>
            </div>
            <div class="ui two column stackable middle aligned grid">
                <div class="column">
                    <figure class="mainwp-onboarding-preview">
                        <img class="ui medium rounded bordered image" src="<?php echo esc_url( MAINWP_PLUGIN_URL . 'assets/images/onboarding/dashboard-preview.jpg' ); ?>" alt="<?php esc_attr_e( 'MainWP Dashboard overview with connected sites and pending updates', 'mainwp' ); ?>">
                        <figcaption><?php esc_html_e( "This is what you'll see in about 3 minutes.", 'mainwp' ); ?></figcaption>
                    </figure>
                </div>
                <div class="column">
                    <div class="ui list">
                        <div class="item"><?php esc_html_e( 'Updates waiting across your sites', 'mainwp' ); ?></div>
                        <div class="item"><?php esc_html_e( 'Update everything in one click', 'mainwp' ); ?></div>
                        <div class="item"><?php esc_html_e( 'Connection status for every site', 'mainwp' ); ?></div>
                    </div>
                </div>
            </div>
            <div class="ui divider"></div>
            <div class="ui stackable grid">
                <div class="ten wide column">
                    <a class="ui basic button" href="https://docs.mainwp.com/getting-started/get-started-with-mainwp" target="_blank" rel="noopener"><?php esc_html_e( 'Walkthrough', 'mainwp' ); ?></a>
                    <a class="ui basic button" href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp_tab' ) ); ?>"><?php esc_html_e( 'Skip the Setup Wizard', 'mainwp' ); ?></a>
                    <?php $this->render_exit_links(); ?>
                </div>
            </div>
        </div>
        <?php
        if ( empty( get_user_option( 'mainwp_selected_theme' ) ) ) {
            $sites = MainWP_DB::instance()->get_sites(); // Get site data.
            if ( empty( $sites ) ) {
                ?>
                <script>
                    jQuery(function ($) {
                        let detectedMode = '';
                        if(window.matchMedia('(prefers-color-scheme: dark)').matches){
                            detectedMode = 'dark';
                        } else if(window.matchMedia('(prefers-color-scheme: light)').matches){
                            detectedMode = 'light';
                        }
                        let data = mainwp_secure_data({
                            action: 'mainwp_qsw_ui_mode_detected',
                            ui_mode: detectedMode,
                        });
                        $.post(ajaxurl, data, function (response) {
                            // nothing.
                        }, 'json');
                    });
                </script>
                <?php
            }
        }
    }

    /**
     * Method mwp_setup_introduction()
     *
     * Renders the Introduction screen of the Quick Start Wizard
     */
    public function mwp_setup_introduction() {
        $this->mwp_setup_welcome();
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
                    /* translators: 1: opening anchor tag, 2: closing anchor tag */
                    printf( esc_html__( 'These checks ensure MainWP can connect to your child sites. If any check fails, %1$slearn how to resolve the issue%2$s.', 'mainwp' ), '<a href="https://docs.mainwp.com/advanced/miscellaneous/mainwp-system-requirements" target="_blank">', '</a>' );
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
                                <div><em><?php esc_html_e( 'Due to bug with PHP on some servers, enter the openssl.cnf file location so MainWP Dashboard can connect to your child sites. If your openssl.cnf file is saved to a different path from what is entered above please enter your exact path. ', 'mainwp' ); ?><?php /* translators: 1: opening anchor tag, 2: closing anchor tag with icon */ printf( esc_html__( '%1$sClick here%2$s to see how to find the OpenSSL.cnf file.', 'mainwp' ), '<a href="https://docs.mainwp.com/troubleshooting/potential-issues#openssl-library-error-invalid-request" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?></em></div>
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
                    <a href="<?php echo esc_url( $this->get_back_step_link( 'welcome' ) ); ?>" class="ui basic button"><i class="arrow left icon"></i> <?php esc_html_e( 'Back to Welcome', 'mainwp' ); ?></a>
                    <?php if ( $show_ssl ) : ?>
                        <input type="submit" class="ui basic green right floated button" value="<?php esc_attr_e( 'Next', 'mainwp' ); ?>" name="save_step" />
                    <?php else : ?>
                        <a href="<?php echo esc_url( $this->get_next_step_link( 'connect_first_site' ) ); ?>" class="ui basic green right floated button"><?php esc_html_e( 'Next to Add Site', 'mainwp' ); ?> <i class="arrow right icon"></i></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php wp_nonce_field( 'mwp-setup' ); ?>
        </form>
        <?php
    }


    /**
     * Method mwp_setup_connect_first_site_save()
     *
     * Installation Step after connect first site.
     */
    public function mwp_setup_connect_first_site_save() {
        check_admin_referer( 'mwp-setup' );
        wp_safe_redirect( $this->get_next_step_link( 'next_steps' ) );
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
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            return;
        }
        check_admin_referer( 'mwp-setup' );
        if ( isset( $_POST['mwp_setup_openssl_lib_location'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            MainWP_Utility::update_option( 'mainwp_opensslLibLocation', isset( $_POST['mwp_setup_openssl_lib_location'] ) ? sanitize_text_field( wp_unslash( $_POST['mwp_setup_openssl_lib_location'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }
        wp_safe_redirect( $this->get_next_step_link( 'connect_first_site' ) );
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
        $mode            = isset( $_GET['mode'] ) && 'multiple-site' === $_GET['mode'] ? 'multiple-site' : 'single-site'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses a view without saving data.
        $mode_chosen     = isset( $_GET['mode'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Controls navigation without saving data.
        ?>
        <div class="ui padded segment">
            <div>
                <h1 class="ui massive header"><?php esc_html_e( 'Connect Your First Child Site', 'mainwp' ); ?></h1>
                <p><?php esc_html_e( 'In the MainWP system, the sites you connect are referred to as "Child Sites."', 'mainwp' ); ?> <?php esc_html_e( 'These Child Sites will be managed centrally from your MainWP Dashboard.', 'mainwp' ); ?></p>
            </div>

        <?php if ( ( $has_file_upload || $has_import_data ) && check_admin_referer( 'mainwp-admin-nonce' ) ) : ?>
            <?php
            $url  = 'admin.php?page=mainwp-setup';
            $url .= '&step=next_steps&siteid=0';
            MainWP_Manage_Sites::render_import_sites_modal( $url, 'Import Sites' );
            ?>
            <?php
        else :
                $el_id_msg_zn_1 = 'submit';
            ?>
            <form method="post" action="" class="ui form" enctype="multipart/form-data" id="mainwp_connect_first_site_form">
                <div>
                    <div>
                        <div id="mainwp-qsw-connect-site-form" data-mode="<?php echo esc_attr( $mode ); ?>">
                            <div class="ui message" id="<?php echo esc_attr( $el_id_msg_zn_1 ); ?>" style="display:none"></div>
                            <div class="ui message" id="mainwp-message-zone" style="display:none"></div>
                            <div class="ui red message" id="mainwp-error-zone" style="display:none"></div>
                            <div class="ui green message" id="mainwp-success-zone" style="display:none"></div>
                            <div class="ui info message" id="mainwp-info-zone" style="display:none"></div>

                            <?php if ( $mode_chosen ) : ?>
                                <p class="ui small text"><a href="<?php echo esc_url( add_query_arg( 'mode', 'single-site' === $mode ? 'multiple-site' : 'single-site', $this->get_next_step_link( 'connect_first_site' ) ) ); ?>"><?php echo esc_html( 'single-site' === $mode ? __( 'Import a CSV instead', 'mainwp' ) : __( 'Connect one site instead', 'mainwp' ) ); ?></a></p>
                            <?php else : ?>
                            <div class="ui top attached equal width tabular menu menu-connect-first-site">
                                <a class="item <?php echo 'single-site' === $mode ? 'active' : ''; ?>" data-tab="single-site"><i class="plus grey icon"></i> <?php esc_html_e( 'Connect a Single Site', 'mainwp' ); ?></a>
                                <a class="item <?php echo 'multiple-site' === $mode ? 'active' : ''; ?>" data-tab="multiple-site"><i class="file upload grey icon"></i> <?php esc_html_e( 'Connect Multiple Sites', 'mainwp' ); ?></a>
                            </div>
                            <?php endif; ?>

                            <div class="ui <?php echo $mode_chosen ? '' : 'bottom attached '; ?>tab segment <?php echo 'single-site' === $mode ? 'active' : ''; ?>" data-tab="single-site">
                                <div>
                                    <div class="ui hidden divider"></div>
                                    <div class="field">
                                        <label for="mainwp_managesites_add_wpurl_protocol"><?php esc_html_e( 'What is the site URL?', 'mainwp' ); ?> <span class="ui red small text"><?php esc_html_e( '(Required)', 'mainwp' ); ?></span></label>
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
                                        <label for="mainwp_managesites_add_wpadmin"><?php esc_html_e( 'What is your administrator username on that site? ', 'mainwp' ); ?> <span class="ui red small text"><?php esc_html_e( '(Required)', 'mainwp' ); ?></label>
                                        <input type="text" id="mainwp_managesites_add_wpadmin" name="mainwp_managesites_add_wpadmin" value="" />
                                    </div>
                                    <div class="ui hidden divider"></div>
                                    <div id="mainwp-onboarding-detection" class="ui segment" aria-live="polite" hidden>
                                        <div class="ui list">
                                            <div class="item">
                                                <i id="mainwp-onboarding-reachable-icon" class="notched circle loading icon" aria-hidden="true"></i>
                                                <div class="content"><?php esc_html_e( 'Site reachable', 'mainwp' ); ?>: <strong id="mainwp-onboarding-reachable"><?php esc_html_e( 'Checking...', 'mainwp' ); ?></strong></div>
                                            </div>
                                            <div class="item">
                                                <i id="mainwp-onboarding-child-icon" class="notched circle loading icon" aria-hidden="true"></i>
                                                <div class="content"><?php esc_html_e( 'MainWP Child detected', 'mainwp' ); ?>: <strong id="mainwp-onboarding-child"><?php esc_html_e( 'Checking...', 'mainwp' ); ?></strong></div>
                                            </div>
                                        </div>
                                        <div id="mainwp-onboarding-detection-message"></div>
                                        <div id="mainwp-onboarding-install" hidden>
                                            <a id="mainwp-onboarding-install-link" target="_blank" rel="noopener"></a>
                                            <p id="mainwp-onboarding-install-fallback"></p>
                                            <a href="https://docs.mainwp.com/getting-started/get-started-with-mainwp" target="_blank" rel="noopener"><?php esc_html_e( 'Walkthrough', 'mainwp' ); ?></a>
                                        </div>
                                        <div id="mainwp-onboarding-timer" hidden>
                                            <p><?php esc_html_e( 'Unconnected MainWP Child installations switch off for security after a set time.', 'mainwp' ); ?></p>
                                            <p id="mainwp-onboarding-time-left"></p>
                                        </div>
                                        <div id="mainwp-onboarding-expiry" hidden>
                                            <p id="mainwp-onboarding-expiry-message"></p>
                                            <a id="mainwp-onboarding-plugins-link" target="_blank" rel="noopener"><?php esc_html_e( 'Open the Plugins page on that site →', 'mainwp' ); ?></a>
                                        </div>
                                        <button type="button" class="ui basic button" id="mainwp-onboarding-retry" hidden><?php esc_html_e( 'Retry', 'mainwp' ); ?></button>
                                    </div>
                                    <div class="field" id="mainwp-administrator-password-field">
                                        <label for="mainwp_managesites_add_admin_pwd"><?php esc_html_e( 'What is your administrator password on that site?', 'mainwp' ); ?> <span class="ui red small text"><?php esc_html_e( '(Required if enabled)', 'mainwp' ); ?></span></label>
                                        <input type="password" id="mainwp_managesites_add_admin_pwd" name="mainwp_managesites_add_admin_pwd" value="" />
                                    </div>
                                    <p class="ui small text"><?php esc_html_e( 'Used once to set up a secure key. Never stored or sent to MainWP.com.', 'mainwp' ); ?> <a href="#mainwp-onboarding-password-explanation" id="mainwp-onboarding-password-help" aria-describedby="mainwp-onboarding-password-explanation"><?php esc_html_e( 'How?', 'mainwp' ); ?></a></p>
                                    <div class="ui wide popup" id="mainwp-onboarding-password-explanation" role="tooltip"><?php esc_html_e( 'Your password is never stored by your Dashboard and never sent to MainWP.com. Once this initial connection is complete, your MainWP Dashboard generates a secure Public and Private key pair (2048 bits) using OpenSSL, allowing future connections without needing your password again. For added security, you can even change this admin password once connected, just be sure not to delete the admin account, as this would disrupt the connection.', 'mainwp' ); ?></div>
                                    <div class="ui hidden divider"></div>
                                    <button type="button" class="ui basic button" id="mainwp-onboarding-advanced-toggle" aria-expanded="false" aria-controls="mainwp-onboarding-advanced"><?php esc_html_e( 'Advanced options', 'mainwp' ); ?></button>
                                    <div id="mainwp-onboarding-advanced" hidden>
                                    <?php MainWP_Manage_Sites::render_connection_2fa_note(); ?>
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
                                    <?php MainWP_Manage_Sites::render_connection_unique_security_id_note(); ?>
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
                                                <div class="item"><?php esc_html_e( 'Unique Security ID On: Displays the Unique Security ID field.', 'mainwp' ); ?></div>
                                                <div class="item"><?php esc_html_e( 'Both On: Displays both fields.', 'mainwp' ); ?></div>
                                                <div class="item"><?php esc_html_e( 'Both Off: Hides both fields.', 'mainwp' ); ?></div>
                                            </div>
                                            <span class="ui  text"><strong><?php esc_html_e( 'This needs to match what is set on your child site. Default is Administrator password.', 'mainwp' ); ?></strong></span>
                                        </div>
                                    </div>
                                    <div class="field" id="mainwp-unique-security-id-field" style="display:none" >
                                        <label for="mainwp_managesites_add_uniqueId"><?php esc_html_e( 'Did you generate unique security ID on the site? If yes, copy it here, if not, leave this field blank. ', 'mainwp' ); ?> <span class="ui red small text"><?php esc_html_e( '(Required if enabled)', 'mainwp' ); ?></span></label>
                                        <input type="text" id="mainwp_managesites_add_uniqueId" name="mainwp_managesites_add_uniqueId" value="" />
                                        <span class="ui small text"><?php esc_html_e( 'Copy this from the child site under Settings > MainWP Child. Required only when Require Unique Security ID is enabled there.', 'mainwp' ); ?></span>
                                    </div>
                                    <div class="ui hidden divider"></div>
                                    <div class="field">
                                        <label for="mainwp_managesites_add_wpname"><?php esc_html_e( 'Add site title: ', 'mainwp' ); ?> <span class="ui grey small text"><?php esc_html_e( '(Optional)', 'mainwp' ); ?></label>
                                        <input type="text" id="mainwp_managesites_add_wpname" name="mainwp_managesites_add_wpname" value="" />
                                        <em><?php esc_html_e( 'This is how your site will appear in your MainWP Dashboard. If you skip this, we\'ll use the site\'s URL automatically.', 'mainwp' ); ?></em>
                                    </div>
                                    </div>
                                </div>
                            </div>

                            <div class="ui <?php echo $mode_chosen ? '' : 'bottom attached '; ?>tab segment <?php echo 'multiple-site' === $mode ? 'active' : ''; ?>" data-tab="multiple-site">
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
                                    <span class="ui grey text"><?php esc_html_e( 'Used once to set up a secure key. Never stored or sent to MainWP.com.', 'mainwp' ); ?> <span tabindex="0" data-tooltip="<?php esc_attr_e( 'Your password is never stored by your Dashboard and never sent to MainWP.com. Once this initial connection is complete, your MainWP Dashboard generates a secure Public and Private key pair (2048 bits) using OpenSSL, allowing future connections without needing your password again. For added security, you can even change this admin password once connected, just be sure not to delete the admin account, as this would disrupt the connection.', 'mainwp' ); ?>"><?php esc_html_e( 'How?', 'mainwp' ); ?></span></span>
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
                    <div>
                        <?php wp_nonce_field( 'mwp-setup' ); ?>
                        <?php MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' ); ?>
                            <input type="hidden" name="qsw_nonce[mainwp_addwp]" value="<?php echo esc_attr( wp_create_nonce( 'mainwp_addwp' ) ); ?>">
                            <input type="hidden" name="qsw_nonce[mainwp_checkwp]" value="<?php echo esc_attr( wp_create_nonce( 'mainwp_checkwp' ) ); ?>">


                        <div class="ui divider"></div>
                        <div class="ui stackable grid">
                            <div class="ten wide column">
                                <a href="<?php echo esc_url( $this->get_back_step_link( 'welcome' ) ); ?>" class="ui basic button"><i class="arrow left icon"></i> <?php esc_html_e( 'Back to Welcome', 'mainwp' ); ?></a>
                                <a href="<?php echo esc_url( add_query_arg( 'siteid', 0, $this->get_next_step_link( 'next_steps' ) ) ); ?>" id="mainwp_addsite_continue_button" class="ui basic button"><?php esc_html_e( 'Continue Without Adding Sites', 'mainwp' ); ?></a>
                                <?php $this->render_exit_links(); ?>
                            </div>
                            <div class="six wide right aligned column">
                                <button type="button" disabled name="mainwp_managesites_add" id="mainwp_managesites_add" class="ui green button"><?php esc_html_e( 'Connect Site and Proceed', 'mainwp' ); ?> <i class="arrow right icon"></i></button>
                                <a style="display:none" name="mainwp_managesites_add_import" id="mainwp_managesites_add_import" class="ui green button"><?php esc_html_e( 'Connect Sites and Proceed', 'mainwp' ); ?> <i class="arrow right icon"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>
        </div>

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
                <input type="submit" class="ui basic green right floated button" value="<?php esc_attr_e( 'Continue', 'mainwp' ); ?>" name="save_step" />
                <a href="<?php echo esc_url( $this->get_back_step_link() ); ?>" class="ui basic button"><?php esc_html_e( 'Back', 'mainwp' ); ?></a>
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
        $selected_site     = $this->summary_site();
        $sites             = MainWP_DB::instance()->get_sites( $selected_site ? $selected_site->id : null ); // Get site data.
        $total_sites       = ! empty( $sites ) ? count( $sites ) : 0;
        $item_class_active = 1 >= $total_sites ? 'active' : '';
        $tab_class_active  = 1 < $total_sites ? 'active' : '';
        $first_site_id     = $selected_site ? $selected_site->id : 0;
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
                                        <span class="ui text small"><strong><?php esc_html_e( 'Client Name', 'mainwp' ); ?></strong> <span class="ui red small text"><?php esc_html_e( '(Required)', 'mainwp' ); ?></span></span>
                                    </div>
                                    <div class="five wide column">
                                        <span class="ui text small"><strong><?php esc_html_e( 'Client Email', 'mainwp' ); ?></strong> <span class="ui red small text"><?php esc_html_e( '(Required)', 'mainwp' ); ?></span></span>
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
                    <a style="display:none" current-page="qsw-add" id="bulk_add_createclient" class="ui basic green right floated button"><?php echo esc_attr__( 'Add and Return to Summary', 'mainwp' ); ?> <i class="arrow right icon"></i></a>
                    <a style="display:none" current-page="qsw-add" id="bulk_add_multi_create_client" class="ui basic green right floated button"><?php echo esc_attr__( 'Add and Return to Summary', 'mainwp' ); ?> <i class="arrow right icon"></i></a>
                    <a href="<?php echo esc_url( $this->get_next_step_link( 'next_steps' ) ); ?>" id="mainwp_qsw_add_client_continue_button" class="ui basic green right floated button"><?php esc_html_e( 'Continue Without Creating Clients', 'mainwp' ); ?> <i class="arrow right icon"></i></a>
                    <a href="<?php echo esc_url( $this->get_back_step_link( 'connect_first_site' ) ); ?>" class="ui basic button"><i class="arrow left icon"></i> <?php esc_html_e( 'Back to Add Sites', 'mainwp' ); ?></a>
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
        $website               = $this->summary_site();
        $first_site_id         = $website ? $website->id : 0;
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
     * Keep the silent check aligned with the requirements table, including OpenSSL's path warning.
     *
     * @return bool
     */
    protected function system_checks_pass() {
        $checks = $this->system_check_values();
        return version_compare( $checks['php'], '8.3', '>=' )
            && $checks['ssl'] && $checks['curl'] && $checks['curl_ssl']
            && version_compare( $checks['mysql'], '5.0', '>=' ) && ! $checks['openssl_warning'];
    }

    /**
     * Read the same environment getters used by the visible check.
     *
     * @return array
     */
    protected function system_check_values() {
        $handler  = MainWP_Server_Information_Handler::get_class_name();
        $curl     = $handler::get_curl_support();
        $curl_ssl = false;
        if ( $curl ) {
            $ssl      = $handler::get_curl_ssl_version();
            $curl_ssl = $handler::curlssl_compare( false !== strpos( $ssl, 'LibreSSL' ) ? 'LibreSSL/2.5.0' : 'OpenSSL/1.1.0', '>=' );
        }
        return array(
            'php'             => $handler::get_php_version(),
            'ssl'             => $handler::get_ssl_support(),
            'curl'            => $curl,
            'curl_ssl'        => $curl_ssl,
            'mysql'           => $handler::get_mysql_version(),
            'openssl_warning' => $handler::is_openssl_config_warning(),
        );
    }

    /**
     * The installer already stores inactive uptime settings, so option existence cannot identify this cohort.
     */
    public static function apply_monitoring_defaults() {
        if ( ! get_option( 'mainwp_onboarding_fresh_install' ) || get_option( 'mainwp_onboarding_defaults_applied' ) || ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            return;
        }
        $settings = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
        if ( 0 === (int) $settings['active'] ) {
            $settings['active']   = 1;
            $settings['interval'] = 60;
            MainWP_Uptime_Monitoring_Handle::update_uptime_global_settings( $settings );
        }
        add_option( 'mainwp_disableSitesHealthMonitoring', 0 );
        add_option( 'mainwp_sitehealthThreshold', 80 );
        update_option( 'mainwp_onboarding_defaults_applied', time() );
    }

    /**
     * An explicit zero keeps CSV imports and skipped connections in the multi-site summary.
     *
     * @return object|false
     */
    private function summary_site() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only site selection; access is checked below.
        $id = isset( $_GET['siteid'] ) ? absint( $_GET['siteid'] ) : absint( get_transient( 'mainwp_transient_just_connected_site_id' ) );
        if ( ! $id || ! \mainwp_current_user_can( 'site', $id ) ) {
            return false;
        }
        $site = MainWP_DB::instance()->get_website_by_id( $id );
        return $site && MainWP_System_Utility::can_edit_website( $site ) ? $site : false;
    }

    /**
     * Use the update screen's ignore rules and the stats already synced during registration.
     *
     * @param object $site Accessible site.
     * @return array
     */
    private function summary_data( $site ) {
        $info    = MainWP_Utility::get_decoded_array( MainWP_DB::instance()->get_website_option( $site, 'site_info' ) );
        $core    = MainWP_Utility::get_decoded_array( MainWP_DB::instance()->get_website_option( $site, 'wp_upgrades' ) );
        $health  = MainWP_Utility::get_site_health( MainWP_Utility::get_decoded_array( MainWP_DB::instance()->get_website_option( $site, 'health_site_status' ) ) );
        $updates = MainWP_Updates_Overview::prepare_update_data( 'all', $site->id );
        if ( 1 !== (int) get_option( 'mainwp_show_language_updates', 1 ) ) {
            // The update chain skips translations while they are hidden, so the summary must not count them.
            $updates['total_translation_upgrades'] = 0;
        }
        return array(
            'synced'  => ! empty( $info ) && empty( $site->sync_errors ),
            'version' => isset( $info['wpversion'] ) ? $info['wpversion'] : '',
            'new'     => ! empty( $updates['total_wp_upgrades'] ) && isset( $core['new'] ) ? $core['new'] : '',
            'updates' => $updates,
            'good'    => 80 <= $health['val'] && empty( $health['critical'] ),
        );
    }

    /**
     * Save the summary controls using the wizard's existing settings right and nonce.
     */
    public function mwp_setup_ready_save() {
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=mainwp_tab' ) );
            exit;
        }
        check_admin_referer( 'mwp-setup' );
        MainWP_Utility::update_option( 'mainwp_enable_guided_tours', isset( $_POST['mainwp-guided-tours-option'] ) ? 1 : 0 );
        $settings           = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
        $settings['active'] = isset( $_POST['mainwp_setup_enableUptimeMonitoring'] ) ? 1 : 0;
        MainWP_Uptime_Monitoring_Handle::update_uptime_global_settings( $settings );
        $saved = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
        if ( get_option( 'mainwp_onboarding_fresh_install' ) && 1 === $settings['active'] && 1 === (int) $saved['active'] ) {
            update_option( 'mainwp_onboarding_uptime_confirmed', 1 );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=mainwp_tab' ) );
        exit;
    }

    /**
     * Render the first connected site's value before offering more setup.
     */
    public function mwp_setup_ready() {
        static::apply_monitoring_defaults();
        $site     = $this->summary_site();
        $settings = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
        $email    = MainWP_Notification_Settings::get_general_email_settings( 'uptime' );
        $site_id  = $site ? $site->id : 0;
        $monitor  = $site ? MainWP_DB_Uptime_Monitoring::instance()->get_primary_monitor( $site->id ) : false;
        // The scheduler checks only existing monitors; active = -1 follows the global setting.
        $uptime_active = $monitor && ( 1 === (int) $monitor->active || ( -1 === (int) $monitor->active && ! empty( $settings['active'] ) ) );
        ?>
        <div class="ui padded segment">
            <div>
                <h1 class="ui massive header">
                <?php
                if ( $site ) {
                    /* translators: %s: site name. */
                    printf( esc_html__( '%s is connected', 'mainwp' ), esc_html( $site->name ) );
                } else {
                    $count = intval( MainWP_Manage_Sites::get_total_sites() );
                    if ( $count ) {
                        /* translators: %d: connected site count. */
                        printf( esc_html( _n( '%d site connected', '%d sites connected', $count, 'mainwp' ) ), intval( $count ) );
                    } else {
                        esc_html_e( 'No sites connected yet', 'mainwp' );
                    }
                }
                ?>
                </h1>
                <?php if ( $site ) : ?>
                    <?php
                    $data  = $this->summary_data( $site );
                    $total = 0;
                    ?>
                    <div class="ui relaxed list">
                        <?php if ( ! empty( $data['version'] ) ) : ?>
                            <div class="item"><?php /* translators: %s: WordPress version. */ printf( esc_html__( 'WordPress %s', 'mainwp' ), esc_html( $data['version'] ) ); ?></div>
                        <?php endif; ?>
                        <?php if ( $data['synced'] ) : ?>
                            <div class="item">
                            <?php
                            $updates = $data['updates'];
                            $counts  = implode(
                                ', ',
                                array(
                                    /* translators: %d: plugin update count. */
                                    sprintf( _n( '%d plugin update', '%d plugin updates', $updates['total_plugin_upgrades'], 'mainwp' ), $updates['total_plugin_upgrades'] ),
                                    /* translators: %d: theme update count. */
                                    sprintf( _n( '%d theme update', '%d theme updates', $updates['total_theme_upgrades'], 'mainwp' ), $updates['total_theme_upgrades'] ),
                                    /* translators: %d: translation update count. */
                                    sprintf( _n( '%d translation update', '%d translation updates', $updates['total_translation_upgrades'], 'mainwp' ), $updates['total_translation_upgrades'] ),
                                )
                            );
                            $total   = $updates['total_wp_upgrades'] + $updates['total_plugin_upgrades'] + $updates['total_theme_upgrades'] + $updates['total_translation_upgrades'];
                            echo esc_html( $total ? $counts : __( 'Everything is up to date', 'mainwp' ) );
                            if ( $data['new'] ) {
                                /* translators: %s: WordPress version. */
                                $counts .= ', ' . sprintf( __( 'WordPress %s', 'mainwp' ), $data['new'] );
                                echo '<br>' . esc_html( sprintf( __( 'WordPress %s is available', 'mainwp' ), $data['new'] ) );
                            }
                            ?>
                            </div>
                            <div class="item"><?php echo esc_html( $data['good'] ? __( 'Site health: Good', 'mainwp' ) : __( 'Site health: Should be improved', 'mainwp' ) ); ?></div>
                        <?php else : ?>
                            <div class="item"><?php esc_html_e( 'Updates and health will show here after the first successful sync.', 'mainwp' ); ?></div>
                        <?php endif; ?>
                        <div class="item">
                        <?php
                        if ( $uptime_active && empty( $email['disable'] ) ) {
                            /* translators: 1: notification recipients, 2: site name. */
                            printf( esc_html__( 'Uptime monitoring is on. We\'ll email %1$s if %2$s goes down.', 'mainwp' ), esc_html( $email['recipients'] ), esc_html( $site->name ) );
                        } else {
                            echo esc_html( ! $uptime_active ? __( 'Uptime monitoring is off.', 'mainwp' ) : __( 'Uptime monitoring is on. Email alerts are disabled.', 'mainwp' ) );
                        }
                        ?>
                        </div>
                    </div>
                    <?php
                    $can_update = $total && ! get_option( 'mainwp_hide_update_everything', false ) && \mainwp_current_user_can( 'dashboard', 'update_wordpress' ) && \mainwp_current_user_can( 'dashboard', 'update_plugins' ) && \mainwp_current_user_can( 'dashboard', 'update_themes' ) && \mainwp_current_user_can( 'dashboard', 'update_translations' );
                    if ( $can_update ) :
                        ?>
                        <?php /* translators: 1: site name, 2: update counts. */ $confirm = sprintf( __( 'Update %1$s now? This installs %2$s. Updates can occasionally break a site, so make sure you have a recent backup.', 'mainwp' ), $site->name, $counts ); ?>
                        <button type="button" class="ui green button" id="mainwp-onboarding-update-all" data-siteid="<?php echo intval( $site->id ); ?>" data-confirm="<?php echo esc_attr( $confirm ); ?>"><?php esc_html_e( 'Update everything now', 'mainwp' ); ?></button>
                    <?php endif; ?>
                    <a class="ui basic button" href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&updateid=' . $site->id ) ); ?>"><?php echo esc_html( $can_update ? __( 'Review updates first', 'mainwp' ) : __( 'View updates', 'mainwp' ) ); ?></a>
                <?php elseif ( $count ) : ?>
                    <a class="ui basic button" href="<?php echo esc_url( admin_url( 'admin.php?page=UpdatesManage' ) ); ?>"><?php esc_html_e( 'View updates', 'mainwp' ); ?></a>
                <?php endif; ?>
            </div>
            <div class="ui hidden divider"></div>
            <div>
                <h2 class="ui dividing header"><?php esc_html_e( "What's next", 'mainwp' ); ?></h2>
                <div class="ui two stackable cards" id="mainwp-onboarding-next-steps">
                    <div class="card">
                        <div class="content">
                            <div class="header"><?php echo esc_html( $site || $count ? __( 'Add another site', 'mainwp' ) : __( 'Add your first site', 'mainwp' ) ); ?></div>
                            <div class="description"><?php esc_html_e( 'Connect more WordPress sites to manage them together.', 'mainwp' ); ?></div>
                        </div>
                        <div class="extra content">
                            <a class="ui basic button" href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&do=new' ) ); ?>"><?php echo esc_html( $site || $count ? __( 'Add another site', 'mainwp' ) : __( 'Add your first site', 'mainwp' ) ); ?></a>
                        </div>
                    </div>
                    <div class="card">
                        <div class="content">
                            <div class="header"><?php echo esc_html( $site ? __( 'Assign this site to a client', 'mainwp' ) : __( 'Assign sites to a client', 'mainwp' ) ); ?></div>
                            <div class="description"><?php esc_html_e( 'Group sites by client for reports and billing.', 'mainwp' ); ?></div>
                        </div>
                        <div class="extra content">
                            <a class="ui basic button" href="<?php echo esc_url( $site || $count ? add_query_arg( 'siteid', $site_id, $this->get_next_step_link( 'add_client' ) ) : admin_url( 'admin.php?page=ClientAddNew' ) ); ?>"><?php echo esc_html( $site ? __( 'Assign this site to a client', 'mainwp' ) : __( 'Assign sites to a client', 'mainwp' ) ); ?></a>
                        </div>
                    </div>
                    <?php if ( MainWP_System_Utility::is_admin() && true === MainWP_Abilities_Util::check_rest_api_permission() && ! MainWP_Menu::is_disable_menu_item( 2, 'ai' ) ) : ?>
                        <div class="card">
                            <div class="content">
                                <h3 class="header"><?php esc_html_e( 'Connect your AI assistant', 'mainwp' ); ?></h3>
                                <p><?php esc_html_e( 'Use MainWP from Claude, Cursor or another MCP client.', 'mainwp' ); ?></p>
                                <p><code><?php esc_html_e( 'Which of my sites have plugin updates waiting?', 'mainwp' ); ?></code></p>
                            </div>
                            <div class="extra content"><a class="ui basic button" href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp-mcp' ) ); ?>"><?php esc_html_e( 'Set up MCP', 'mainwp' ); ?></a></div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="ui hidden divider"></div>
                <form method="post" class="ui form">
                    <div class="field">
                        <div class="ui toggle checkbox">
                            <input type="checkbox" name="mainwp_setup_enableUptimeMonitoring" id="mainwp_setup_enableUptimeMonitoring" <?php checked( ! empty( $settings['active'] ) ); ?>>
                            <label for="mainwp_setup_enableUptimeMonitoring"><?php esc_html_e( 'Enable uptime monitoring', 'mainwp' ); ?></label>
                        </div>
                    </div>
                    <div class="field">
                        <div class="ui checkbox">
                            <input type="checkbox" name="mainwp-guided-tours-option" id="mainwp-guided-tours-option" <?php checked( get_option( 'mainwp_enable_guided_tours', 0 ) ); ?>>
                            <label for="mainwp-guided-tours-option"><?php esc_html_e( 'Show me a guided tour of MainWP', 'mainwp' ); ?></label>
                        </div>
                    </div>
                    <div class="ui blue message"><?php /* translators: 1: opening anchor tag, 2: closing anchor tag. */ printf( esc_html__( 'This feature is implemented using Javascript provided by Usetiful and is subject to the %1$sUsetiful Privacy Policy%2$s.', 'mainwp' ), '<a href="https://www.usetiful.com/privacy-policy" target="_blank">', '</a>' ); ?></div>
                    <?php wp_nonce_field( 'mwp-setup' ); ?>
                    <input type="hidden" name="save_step" value="1">
                    <div class="ui divider"></div>
                    <div class="ui stackable grid">
                        <div class="ten wide column">
                            <?php $this->render_exit_links(); ?>
                        </div>
                        <div class="six wide right aligned column">
                            <button type="submit" class="ui green button"><?php esc_html_e( 'Go to Dashboard', 'mainwp' ); ?></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php
        if ( $site ) {
            echo '<div id="mainwp_prepare_global_update_all_container" style="display:none"></div>';
            MainWP_System_View::render_footer_content( array( $site ), $site->id );
            MainWP_Updates_Overview::render_bottom( array( $site->id ), false );
        }
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
