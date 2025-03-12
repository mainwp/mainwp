<?php
/**
 * MainWP Client Overview Page.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Overview
 *
 * @package MainWP\Dashboard
 */
class MainWP_Client_Overview { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Get Class Name
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * The single instance of the class
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Enabled widgets
     *
     * @var array $enable_widgets
     */
    private static $enable_widgets = array(
        'overview'           => true,
        'note'               => true,
        'fields_info'        => true,
        'websites'           => true,
        'recent_posts'       => true,
        'recent_pages'       => true,
        'non_mainwp_changes' => true,
    );

    /**
     * Current page.
     *
     * @static
     * @var string $page Current page.
     */
    public static $page;

    /**
     * Check if there is a session,
     * if there isn't one create it.
     *
     *  @return static::singlton Overview Page Session.
     *
     * @uses \MainWP\Dashboard\MainWP_Overview
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * MainWP_Overview constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
        add_filter( 'screen_layout_columns', array( &$this, 'on_screen_layout_columns' ), 10, 2 );
        add_action( 'mainwp_help_sidebar_content', array( &$this, 'mainwp_help_content' ) );
    }

    /**
     * Set the number of page coumns.
     *
     * @param mixed $columns Number of Columns.
     * @param mixed $screen Screen size.
     *
     * @return int $columns Number of desired page columns.
     */
    public function on_screen_layout_columns( $columns, $screen ) {
        if ( $screen === static::$page ) {
            $columns[ static::$page ] = 3;
        }

        return $columns;
    }


    /**
     * Method on_load_page()
     *
     * Run on page load.
     *
     * @param mixed $page Page name.
     */
    public static function on_load_page( $page ) {

        static::$page = $page;

        $val = get_user_option( 'screen_layout_' . $page );
        if ( ! $val ) {
            global $current_user;
            update_user_option( $current_user->ID, 'screen_layout_' . $page, 2, true );
        }

        wp_enqueue_script( 'common' );
        wp_enqueue_script( 'wp-lists' );
        wp_enqueue_script( 'postbox' );
        wp_enqueue_script( 'dashboard' );
        wp_enqueue_script( 'widgets' );

        static::add_meta_boxes( $page );

        add_filter( 'mainwp_header_actions_right', array( static::get_class_name(), 'screen_options' ), 10, 2 );
    }

    /**
     * Method screen_options()
     *
     * Create Page Settings button.
     *
     * @param mixed $input Page Settings button HTML.
     *
     * @return mixed Page Settings button.
     */
    public static function screen_options( $input ) {
        return $input .
                '<a class="ui button basic icon" onclick="mainwp_clients_overview_screen_options(); return false;" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="' . esc_html__( 'Page Settings', 'mainwp' ) . '">
                    <i class="cog icon"></i>
                </a>';
    }

    /**
     * Method add_meta_boxes()
     *
     * Add MainWP Overview Page Widgets.
     *
     * @param array $page Current page.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Handler::apply_filters()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
     * @uses \MainWP\Dashboard\MainWP_UI::add_widget_box()
     * @uses \MainWP\Dashboard\MainWP_Connection_Status::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Recent_Pages::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Recent_Posts::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Security_Issues_Widget::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Updates_Overview::get_class_name()
     */
    public static function add_meta_boxes( $page ) { // phpcs:ignore -- NOSONAR - complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        /**
         * Get getmetaboxes
         *
         * Adds metaboxes (widgets) to the Overview page.
         *
         * @since 4.3
         */
        $extMetaBoxs = array();
        $extMetaBoxs = apply_filters( 'mainwp_clients_getmetaboxes', $extMetaBoxs );
        foreach ( $extMetaBoxs as $box ) {
            if ( isset( $box['plugin'] ) ) {
                $name                            = basename( $box['plugin'], '.php' );
                static::$enable_widgets[ $name ] = true;
            } elseif ( ! empty( $box['widget_id'] ) ) {
                static::$enable_widgets[ $box['widget_id'] ] = true;
            }
        }

        $client_contacts = array();
        if ( isset( $_GET['client_id'] ) && ! empty( $_GET['client_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $client_contacts = MainWP_DB_Client::instance()->get_wp_client_contact_by( 'client_id', intval( $_GET['client_id'] ), ARRAY_A ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

        if ( is_array( $client_contacts ) ) {
            foreach ( $client_contacts as $contact ) {
                static::$enable_widgets[ 'contact_' . $contact['contact_id'] ] = true;
            }
        }
        $values = static::$enable_widgets;

        /**
         * Unset unwanted Widgets
         *
         * Contains the list of enabled widgets and allows user to unset unwanted widgets.
         *
         * @param array $values           Array containing enabled widgets.
         * @param int   $dashboard_siteid Child site (Overview) ID.
         *
         * @since 4.3
         */
        $values                 = apply_filters( 'mainwp_clients_overview_enabled_widgets', $values, null );
        static::$enable_widgets = array_merge( static::$enable_widgets, $values );

        // Load the Overview widget.
        if ( static::$enable_widgets['overview'] ) {
            MainWP_UI::add_widget_box( 'overview', array( MainWP_Client_Overview_Info::get_class_name(), 'render' ), $page, array( -1, -1, 3, 40 ) );
        }

        // Load the Notes widget.
        if ( static::$enable_widgets['note'] ) {
            MainWP_UI::add_widget_box( 'note', array( MainWP_Client_Overview_Note::get_class_name(), 'render' ), $page, array( -1, -1, 3, 40 ) );
        }

        // Load the Websites widget.
        if ( static::$enable_widgets['websites'] ) {
            MainWP_UI::add_widget_box( 'websites', array( MainWP_Client_Overview_Sites::get_class_name(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }

        // Load the Info widget.
        if ( static::$enable_widgets['fields_info'] ) {
            MainWP_UI::add_widget_box( 'fields_info', array( MainWP_Client_Overview_Custom_Info::get_class_name(), 'render' ), $page, array( -1, -1, 6, 30 ) );
        }

        // Load the Non-MainWP Changes widget.
        if ( static::$enable_widgets['non_mainwp_changes'] ) {
            MainWP_UI::add_widget_box( 'non_mainwp_changes', array( MainWP_Site_Actions::get_class_name(), 'render' ), $page, array( -1, -1, 6, 30 ) );
        }

        // Load the Recent Posts widget.
        if ( static::$enable_widgets['recent_posts'] ) {
            MainWP_UI::add_widget_box( 'recent_posts', array( MainWP_Recent_Posts::get_class_name(), 'render' ), $page, array( -1, -1, 6, 30 ) );
        }

        // Load the Recent Pages widget.
        if ( static::$enable_widgets['recent_pages'] ) {
            MainWP_UI::add_widget_box( 'recent_pages', array( MainWP_Recent_Pages::get_class_name(), 'render' ), $page, array( -1, -1, 6, 30 ) );
        }

        if ( is_array( $client_contacts ) ) {
            foreach ( $client_contacts as $contact ) {
                if ( isset( static::$enable_widgets[ 'contact_' . $contact['contact_id'] ] ) && static::$enable_widgets[ 'contact_' . $contact['contact_id'] ] ) {
                    $contact_widget          = new MainWP_Client_Overview_Contacts();
                    $contact_widget->contact = $contact;
                    MainWP_UI::add_widget_box( 'contact_' . $contact['contact_id'], array( $contact_widget, 'render' ), $page, array( -1, -1, 3, 30 ) );
                }
            }
        }

        $i = 1;
        foreach ( $extMetaBoxs as $metaBox ) {
            $enabled = true;
            if ( isset( $metaBox['plugin'] ) ) {
                $name = basename( $metaBox['plugin'], '.php' );
                if ( isset( static::$enable_widgets[ $name ] ) && ! static::$enable_widgets[ $name ] ) {
                    $enabled = false;
                }
            }

            $id = isset( $metaBox['id'] ) ? $metaBox['id'] : $i++;
            $id = 'advanced-' . $id;

            $layout = ! empty( $metaBox['layout'] ) && is_array( $metaBox['layout'] ) ? $metaBox['layout'] : array( -1, -1, 6, 30 );

            if ( $enabled ) {
                MainWP_UI::add_widget_box( $id, $metaBox['callback'], $page, $layout );
            }
        }
    }

    /**
     * Method on_show_page()
     *
     * When the page loads render the body content.
     */
    public function on_show_page() {
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_clients' ) && ! \mainw\mainwp_current_user_can( 'dashboard', 'access_client_dashboard' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'client dashboard', 'mainwp' ) );
            return;
        }

        /**
         * Screen layout columns array.
         *
         * @global object
         */
        global $screen_layout_columns;

        MainWP_Client::render_header( 'overview' );

        static::render_dashboard_body();
        ?>
        </div>
        <?php
    }

    /**
     * Method render_dashboard_body()
     *
     * Render the Dashboard Body content.
     */
    public static function render_dashboard_body() {

        MainWP_Overview::render_layout_selection();

        $screen = get_current_screen();
        ?>
        <div class="mainwp-primary-content-wrap">
        <div id="mainwp-message-zone" class="ui message" style="display:none;"></div>
        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'client-widgets' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="client-widgets"></i>
                    <?php printf( esc_html__( 'To hide or show a widget, click the Cog (%1$s) icon.', 'mainwp' ), '<i class="cog icon"></i>' ); ?>
                </div>
            <?php endif; ?>
            <?php
            /**
             * Action: mainwp_before_overview_widgets
             *
             * Fires at the top of the Overview page (before first widget).
             *
             * @since 4.3
             */
            do_action( 'mainwp_before_overview_widgets', 'clients' );
            ?>
            <div id="mainwp-grid-wrapper" class="gridster">
                <?php MainWP_UI::do_widget_boxes( $screen->id ); ?>
        </div>
            <?php
            /**
             * Action: 'mainwp_after_overview_widgets'
             *
             * Fires at the bottom of the Overview page (after the last widget).
             *
             * @since 4.3
             */
            do_action( 'mainwp_after_overview_widgets', 'clients' );
            ?>
    <script type="text/javascript">
        jQuery( document ).ready( function( $ ) {

            jQuery( '.mainwp-widget .mainwp-dropdown-tab .item' ).tab();

            mainwp_clients_overview_screen_options = function () {
                jQuery( '#mainwp-clients-overview-screen-options-modal' ).modal( {
                    allowMultiple: true,
                    onHide: function () {
                    }
                } ).modal( 'show' );
                return false;
            };
            jQuery('#reset-clients-overview-settings').on('click', function () {
                mainwp_confirm(__('Are you sure.'), function(){
                    jQuery('.mainwp_hide_wpmenu_checkboxes input[name="mainwp_show_widgets[]"]').prop('checked', true);
                    jQuery('input[name=reset_client_overview_settings]').attr('value', 1);
                    jQuery('#submit-client-overview-settings').click();
                }, false, false, true);
                return false;
            });
        } );
    </script>
    <div class="ui modal" id="mainwp-clients-overview-screen-options-modal">
    <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
            <div class="content ui form">
                <?php
                /**
                 * Action: mainwp_clients_overview_screen_options_top
                 *
                 * Fires at the top of the Sceen Options modal on the Overview page.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_clients_overview_screen_options_top' );
                ?>
                <form method="POST" action="" name="mainwp_clients_overview_screen_options_form" id="mainwp-clients-overview-screen-options-form">
                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                    <input type="hidden" name="wp_scr_options_nonce" value="<?php echo esc_attr( wp_create_nonce( 'MainWPClientsScrOptions' ) ); ?>" />
                    <?php static::render_screen_options( false ); ?>
                    <?php
                    /**
                     * Action: mainwp_clients_overview_screen_options_bottom
                     *
                     * Fires at the bottom of the Sceen Options modal on the Overview page.
                     *
                     * @since 4.1
                     */
                    do_action( 'mainwp_clients_overview_screen_options_bottom' );
                    ?>
            </div>
            <div class="actions">
                <div class="ui two columns grid">
                    <div class="left aligned column">
                        <span data-tooltip="<?php esc_attr_e( 'Resets the page to its original layout and reinstates relocated widgets.', 'mainwp' ); ?>" data-inverted="" data-position="top left"><input type="button" class="ui button" name="reset" id="reset-clients-overview-settings" value="<?php esc_attr_e( 'Reset Page', 'mainwp' ); ?>" /></span>
                    </div>
                    <div class="ui right aligned column">
                        <input type="submit" class="ui green button" id="submit-client-overview-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
                    </div>
                </div>
            </div>

            <input type="hidden" name="reset_client_overview_settings" value="" />
            </form>
        </div>
        </div>
        <?php
    }

    /**
     * Method render_screen_options()
     *
     * Render Page Settings.
     *
     * @return void  Render Page Settings html.
     */
    public static function render_screen_options() { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $default_widgets = array(
            'overview'           => esc_html__( 'Overview', 'mainwp' ),
            'note'               => esc_html__( 'Notes', 'mainwp' ),
            'fields_info'        => esc_html__( 'Addition Info', 'mainwp' ),
            'websites'           => esc_html__( 'Websites', 'mainwp' ),
            'recent_posts'       => esc_html__( 'Recent Posts', 'mainwp' ),
            'recent_pages'       => esc_html__( 'Recent Pages', 'mainwp' ),
            'non_mainwp_changes' => esc_html__( 'Sites Changes', 'mainwp' ),
        );

        if ( isset( $_GET['client_id'] ) && ! empty( $_GET['client_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $client_contacts = MainWP_DB_Client::instance()->get_wp_client_contact_by( 'client_id', intval( $_GET['client_id'] ), ARRAY_A ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if ( $client_contacts ) {
                foreach ( $client_contacts as $contact ) {
                    $default_widgets[ 'contact_' . $contact['contact_id'] ] = esc_html( $contact['contact_name'] );

                }
            }
        }

        $custom_opts = array();
        /**
         * Filter: mainwp_clients_widgets_screen_options
         *
         * Filters available widgets on the Overview page allowing users to unsent unwanted widgets.
         *
         * @since 4.0
         */
        $custom_opts = apply_filters( 'mainwp_clients_widgets_screen_options', $custom_opts );

        if ( is_array( $custom_opts ) && ! empty( $custom_opts ) ) {
            $default_widgets = array_merge( $default_widgets, $custom_opts );
        }

        $show_widgets = get_user_option( 'mainwp_clients_show_widgets' );
        if ( ! is_array( $show_widgets ) ) {
            $show_widgets = array();
        }

        /**
         * Action: mainwp_screen_options_modal_top
         *
         * Fires at the top of the Page Settings modal element.
         *
         * @since 4.1
         */
        do_action( 'mainwp_screen_options_modal_top' );
        ?>
        <?php if ( isset( $_GET['page'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized ?>
        <div class="ui grid field">
            <label class="six wide column"><?php esc_html_e( 'Show widgets', 'mainwp' ); ?></label>
            <div class="ten wide column" <?php echo 'data-tooltip="' . esc_attr__( 'Select widgets that you want to hide in the MainWP Overview page.', 'mainwp' ) . '"'; ?> data-inverted="" data-position="top left">
                <ul class="mainwp_hide_wpmenu_checkboxes">
                <?php
                foreach ( $default_widgets as $name => $title ) {
                    $_selected = '';
                    if ( ! isset( $show_widgets[ $name ] ) || 1 === (int) $show_widgets[ $name ] ) {
                        $_selected = 'checked';
                    }
                    ?>
                    <li>
                        <div class="ui checkbox">
                            <input type="checkbox" id="mainwp_show_widget_<?php echo esc_attr( $name ); ?>" name="mainwp_show_widgets[]" <?php echo esc_html( $_selected ); ?> value="<?php echo esc_attr( $name ); ?>">
                            <label for="mainwp_show_widget_<?php echo esc_attr( $name ); ?>" ><?php echo esc_html( $title ); ?></label>
                        </div>
                        <input type="hidden" name="mainwp_widgets_name[]" value="<?php echo esc_attr( $name ); ?>">
                    </li>
                    <?php
                }
                ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
        <?php
        /**
         * Action: mainwp_screen_options_modal_bottom
         *
         * Fires at the bottom of the Page Settings modal element.
         *
         * @since 4.1
         */
        do_action( 'mainwp_screen_options_modal_bottom' );
    }


    /**
     * Method mainwp_help_content()
     *
     * Creates the MainWP Help Documentation List for the help component in the sidebar.
     */
    public static function mainwp_help_content() {
        if ( isset( $_GET['page'] ) && 'ManageClients' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            ?>
            <p><?php esc_html_e( 'If you need help with your MainWP Dashboard, please review following help documents', 'mainwp' ); ?></p>
            <div class="ui list">
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-clients/" target="_blank">Manage Clients</a></div> <?php // NOSONAR - noopener - open safe. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-clients/#create-a-new-client" target="_blank">Create a new Client</a></div> <?php // NOSONAR - noopener - open safe. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-clients/#update-a-client" target="_blank">Update a Client</a></div> <?php // NOSONAR - noopener - open safe. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-clients/#delete-a-client" target="_blank">Delete a Client</a></div> <?php // NOSONAR - noopener - open safe. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-clients/#customize-the-manage-clients-table" target="_blank">Customize the Manage Clients table</a></div> <?php // NOSONAR - noopener - open safe. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-clients/#client-card" target="_blank">Client Card (View Client)</a></div> <?php // NOSONAR - noopener - open safe. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/manage-clients/#client-fields" target="_blank">Client Fields</a></div> <?php // NOSONAR - noopener - open safe. ?>
                <?php
                /**
                 * Action: mainwp_clients_overview_help_item
                 *
                 * Fires at the bottom of the help articles list in the Help sidebar on the Clients page.
                 *
                 * Suggested HTML markup:
                 *
                 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
                 *
                 * @since 4.3
                 */
                do_action( 'mainwp_clients_overview_help_item' );
                ?>
            </div>
            <?php
        }
    }
}
