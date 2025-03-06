<?php
/**
 * MainWP  Site Actions Widget
 *
 * Displays the Site Actions Info.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

use MainWP\Dashboard\Module\Log\Log_Manager;
use MainWP\Dashboard\Module\Log\Log_Events_List_Table;
use MainWP\Dashboard\Module\Log\Log_DB_Helper;


/**
 * Class MainWP_Site_Actions
 *
 * Displays the Site Actions.
 */
class MainWP_Site_Actions { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Private static variable to hold the single instance of the events table.
     *
     * @static
     *
     * @var mixed Default null
     */
    private $list_events_table = null;


    /**
     * Private static variable to hold the table type value.
     *
     * @var mixed Default null
     */
    private $table_id_prefix = 'widget-overview';

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Site_Actions
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
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
     * Method render()
     *
     * @return mixed render_site_info()
     */
    public static function render() {
        $current_wpid    = MainWP_System_Utility::get_current_wpid();
        $website         = null;
        $params          = array();
        $params['limit'] = apply_filters( 'mainwp_widget_site_actions_limit_number', 50 );
        if ( $current_wpid ) {
            $params  = array(
                'wpid' => $current_wpid,
            );
            $website = MainWP_DB::instance()->get_website_by_id( $current_wpid );
        } elseif ( isset( $_GET['client_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $client_id = isset( $_GET['client_id'] ) ? intval( $_GET['client_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $params    = array(
                'client_id' => $client_id,
            );
        }
        static::instance()->render_info( $params, $website );
    }

    /**
     * Render Sites actions Info.
     *
     * @param array  $params Events params.
     * @param object $website Sites info.
     */
    private function render_info( $params, $website ) { // phpcs:ignore -- NOSONAR - complex.

        if ( ! is_array( $params ) ) {
            $params = array();
        }
        $this->load_events_list_table();
        ?>

        <div class="mainwp-widget-header">
            <div class="ui grid">
                <div class="fourteen wide column">
                    <h2 class="ui header handle-drag">
                        <?php
                        /**
                         * Filter: mainwp_non_mainwp_changes_widget_title
                         *
                         * Filters the Site info widget title text.
                         *
                         * @param object $website Object containing the child site info.
                         *
                         * @since 4.1
                         */
                        echo esc_html( apply_filters( 'mainwp_non_mainwp_changes_widget_title', esc_html__( 'Sites Changes', 'mainwp' ), $website ) );
                        ?>
                        <div class="sub header"><?php esc_html_e( 'The most recent changes made to your Child Sites.', 'mainwp' ); ?></div>
                    </h2>
                </div>
                <div class="two wide column right aligned">
                    <div id="widget-sites-changes-dropdown-selector" class="ui dropdown top right tiny pointing not-auto-init mainwp-dropdown-tab">
                        <i class="vertical ellipsis icon"></i>
                        <div class="menu">
                            <a href="javascript:void(0)" class="item" data-value="wp-admin"><?php esc_html_e( 'Non-MainWP Changes', 'mainwp' ); ?></a>
                            <a href="javascript:void(0)" class="item" data-value="dashboard"><?php esc_html_e( 'Dashboard Changes', 'mainwp' ); ?></a>
                            <a href="javascript:void(0)" class="item" data-value=""><?php esc_html_e( 'Show All', 'mainwp' ); ?></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <input type="hidden" id="mainwp-widget-filter-current-site-id" value="<?php echo isset( $params['wpid'] ) ? intval( $params['wpid'] ) : 0; ?>" />
        <input type="hidden" id="mainwp-widget-filter-current-client-id" value="<?php echo isset( $params['client_id'] ) ? intval( $params['client_id'] ) : 0; ?>" />
        <input type="hidden" id="mainwp-widget-filter-events-limit" value="<?php echo isset( $params['limit'] ) ? intval( $params['limit'] ) : 50; ?>" />

        <div id="mainwp-widget-site-actions" class="mainwp-scrolly-overflow">
            <?php
            /**
             * Actoin: mainwp_non_mainwp_changes_widget_top
             *
             * Fires at the top of the Site Info widget on the Individual site overview page.
             *
             * @param object $website Object containing the child site info.
             *
             * @since 4.0
             */
            do_action( 'mainwp_non_mainwp_changes_widget_top', $website );
            ?>
                <?php
                /**
                 * Action: mainwp_non_mainwp_changes_table_top
                 *
                 * Fires at the top of the Site Info table in Site Info widget on the Individual site overview page.
                 *
                 * @param object $website Object containing the child site info.
                 *
                 * @since 4.0
                 */
                do_action( 'mainwp_non_mainwp_changes_table_top', $website );
                ?>
                <div class="ui small feed" id="mainwp-non-mainwp-changes-feed">

                    <div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
                    <?php
                    wp_nonce_field( 'mainwp-admin-nonce' );
                    $this->list_events_table->display();

                    /**
                     * Action: mainwp_non_mainwp_changes_table_bottom
                     *
                     * Fires at the bottom of the Site Info table in Site Info widget on the Individual site overview page.
                     *
                     * @param object $website Object containing the child site info.
                     *
                     * @since 4.0
                     */
                    do_action( 'mainwp_non_mainwp_changes_table_bottom', $website );
                    ?>
                </div>
        </div>
        
        <div class="mainwp-widget-footer">
            <div class="ui two columns stackable grid">
                <div class="left aligned middle aligned column">

                </div>
                <div class="right aligned middle aligned column">
                    <a href="admin.php?page=InsightsManage" class="ui mini basic button"><?php esc_html_e( 'See All Changes', 'mainwp' ); ?></a>
                </div>
            </div>
        </div>
        <?php
        /**
         * Action: mainwp_non_mainwp_changes_widget_bottom
         *
         * Fires at the bottom of the Site Info widget on the Individual site overview page.
         *
         * @param object $website Object containing the child site info.
         *
         * @since 4.0
         */
        do_action( 'mainwp_non_mainwp_changes_widget_bottom', $website );
    }

    /**
     * Method load_sites_table()
     *
     * Load sites table.
     */
    public function load_events_list_table() {
        $manager                 = Log_Manager::instance();
        $this->list_events_table = new Log_Events_List_Table( $manager, $this->table_id_prefix );
    }
}
