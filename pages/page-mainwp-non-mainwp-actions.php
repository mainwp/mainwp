<?php
/**
 * MainWP Site Non-MainWP Actions Class
 *
 * Displays the Site Non-MainWP Actions Info.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Non_MainWP_Actions
 *
 * Displays the Site Non-MainWP Actions.
 */
class MainWP_Non_MainWP_Actions { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Non_MainWP_Actions
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
        add_action( 'mainwp_admin_menu', array( $this, 'init_menu' ), 10, 2 );
        add_action( 'admin_init', array( $this, 'admin_init' ), 10, 2 );
    }

    /**
     * Method get_class_name()
     *
     * @return string __CLASS__ Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method admin_init()
     *
     * Add Insights Overview sub menu "Insights".
     */
    public function admin_init() {
        MainWP_Post_Handler::instance()->add_action( 'mainwp_non_mainwp_changes_display_rows', array( &$this, 'ajax_optimize_display_rows' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_non_mainwp_changes_delete_actions', array( &$this, 'ajax_delete_actions' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_non_mainwp_changes_dismiss_actions', array( &$this, 'ajax_dismiss_actions' ) );
    }

    /**
     * Method init_menu()
     *
     * Add Insights Overview sub menu "Insights".
     */
    public function init_menu() {
        add_submenu_page(
            'mainwp_tab',
            esc_html__( 'Non-MainWP Changes', 'mainwp' ),
            '<div class="mainwp-hidden" id="mainwp-non-mainwp-actions">' . esc_html__( 'Non-MainWP Changes', 'mainwp' ) . '</div>',
            'read',
            'NonMainWPChanges',
            array(
                $this,
                'render_actions_list',
            )
        );
        static::init_left_menu();
    }

    /**
     * Initiates left menu.
     */
    public static function init_left_menu() {
        MainWP_Menu::add_left_menu(
            array(
                'title'                => esc_html__( 'Non-MainWP Changes', 'mainwp' ),
                'parent_key'           => 'managesites',
                'slug'                 => 'NonMainWPChanges',
                'href'                 => 'admin.php?page=NonMainWPChanges',
                'icon'                 => '<i class="pie chart icon"></i>',
                'desc'                 => 'Non-MainWP Changes Overview',
                'leftsub_order_level2' => 3.5,
            ),
            2
        );
    }

    /**
     * Render backups list.
     */
    public function render_actions_list() {

        if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_non_mainwp_actions' ) ) {
            mainwp_do_not_have_permissions( esc_html__( 'manage non-mainwp actions', 'mainwp' ) );
            return;
        }

        $actionsTable = new MainWP_Manage_Non_MainWP_Changes_List_Table();
        static::render_header();
        ?>
        <?php $this->render_actions_bar(); ?>
        <div class="ui segment" id="mainwp-non-mainwp-mananage-actions-overview">
            <div id="mainwp-message-zone" class="ui message"></div>
            <div class="ui active inverted dimmer" style="display:none;" id="mainwp-sites-table-loader">
                <div class="ui large text loader"><?php esc_html_e( 'Loading ...', 'mainwp' ); ?></div>
            </div>
            <form method="post" class="mainwp-table-container">
                <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                <?php
                $actionsTable->display();
                ?>
            </form>
        </div>
        </div>
        <?php
    }


    /**
     * Method render_header()
     *
     * Render page header.
     */
    public static function render_header() {
        $params = array(
            'title'      => esc_html__( 'Non-MainWP Changes', 'mainwp' ),
            'which'      => 'page_non_mainwp_actions_overview',
            'wrap_class' => 'mainwp-non-mainwp-mananage-actions-wrapper',
        );
        MainWP_UI::render_top_header( $params );
    }


    /**
     * Render Actions Bar
     *
     * Renders the actions bar on the Dashboard tab.
     */
    public function render_actions_bar() {
        $params       = array(
            'total_count' => true,
        );
        $totalRecords = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params );
        ?>
        <div class="mainwp-actions-bar">
            <div class="ui two columns grid">
                <div class="column ui mini form">
                        <select class="ui dropdown" id="non_mainwp_actions_bulk_action">
                            <option value="-1"><?php esc_html_e( 'Bulk actions', 'mainwp' ); ?></option>
                            <option value="dismiss"><?php esc_html_e( 'Dismiss', 'mainwp' ); ?></option>
                        </select>
                        <input type="button" name="mainwp_non_mainwp_actions_action_btn" id="mainwp_non_mainwp_actions_action_btn" class="ui basic mini button" value="<?php esc_html_e( 'Apply', 'mainwp' ); ?>"/>
                        <?php do_action( 'mainwp_non_mainwp_actions_actions_bar_left' ); ?>
                    </div>
                <div class="right aligned middle aligned column">
                    <?php if ( $totalRecords ) : ?>
                        <a href="javascript:void(0)" id="mainwp-delete-all-nonmainwp-actions-button" class="ui button mini green"><?php esc_html_e( 'Clear All Non-MainWP Changes', 'mainwp' ); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Method ajax_optimize_display_rows()
     *
     * Display table rows, optimize for shared hosting or big networks.
     */
    public static function ajax_optimize_display_rows() {
        MainWP_Post_Handler::instance()->secure_request( 'mainwp_non_mainwp_changes_display_rows' );
        $actionsTable = new MainWP_Manage_Non_MainWP_Changes_List_Table();
        $actionsTable->prepare_items( true );
        $output = $actionsTable->ajax_get_datatable_rows();
        $actionsTable->clear_items();
        wp_send_json( $output );
    }

    /**
     * Method ajax_delete_actions()
     */
    public function ajax_delete_actions() {
        MainWP_Post_Handler::instance()->secure_request( 'mainwp_non_mainwp_changes_delete_actions' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $act_id = isset( $_POST['act_id'] ) ? intval( $_POST['act_id'] ) : 0;

        $del_action = false;
        if ( ! empty( $act_id ) ) {
            $del_action = MainWP_DB_Site_Actions::instance()->get_wp_action_by_id( $act_id );
        }

        if ( empty( $del_action ) ) {
            wp_die( wp_json_encode( array( 'error' => 'Invalid change ID or Change not found.' ) ) );
        }

        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $website = MainWP_DB::instance()->get_website_by_id( $del_action->wpid );
        try {
            if ( $website ) {
                MainWP_Connect::fetch_url_authed( $website, 'delete_actions', array( 'del' => 'act' ) );
            }
        } catch ( \Exception $e ) {
            // ok!
        }
        MainWP_DB_Site_Actions::instance()->delete_action_by( 'action_id', $act_id );
        wp_die( wp_json_encode( array( 'success' => 'yes' ) ) );
    }

    /**
     * Method ajax_dismiss_actions()
     */
    public function ajax_dismiss_actions() {
        MainWP_Post_Handler::instance()->secure_request( 'mainwp_non_mainwp_changes_dismiss_actions' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $act_id = isset( $_POST['act_id'] ) ? intval( $_POST['act_id'] ) : 0;
        $action = false;
        if ( ! empty( $act_id ) ) {
            $action = MainWP_DB_Site_Actions::instance()->get_wp_action_by_id( $act_id );
        }
        if ( empty( $action ) ) {
            wp_die( wp_json_encode( array( 'error' => 'Invalid change ID or Change not found.' ) ) );
        }
        $update = array(
            'dismiss' => 1,
        );
        MainWP_DB_Site_Actions::instance()->update_action_by_id( $act_id, $update );
        wp_die( wp_json_encode( array( 'success' => 'yes' ) ) );
    }
}
