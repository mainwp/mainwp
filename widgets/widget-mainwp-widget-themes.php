<?php
/**
 * MainWP Themes Widget
 *
 * Grab current Child Site theme data & build Widget
 *
 * @package MainWP/Plugins
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Widget_Themes
 */
class MainWP_Widget_Themes { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method get_class_name()
     *
     * Get Class Name
     *
     * @return string __CLASS__ Class Name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method render()
     *
     * Fire off render_widget().
     */
    public static function render() {
        static::render_widget();
    }


    /**
     * Method render_widget()
     *
     * Build themes widget.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
     */
    public static function render_widget() {
        $current_wpid = MainWP_System_Utility::get_current_wpid();
        if ( empty( $current_wpid ) ) {
            return;
        }

        $sql       = MainWP_DB::instance()->get_sql_website_by_id( $current_wpid );
        $websites  = MainWP_DB::instance()->query( $sql );
        $allThemes = array();
        if ( $websites ) {
            $website = MainWP_DB::fetch_object( $websites );
            if ( $website && '' !== $website->themes ) {
                $themes = json_decode( $website->themes, 1 );
                if ( is_array( $themes ) && ! empty( $themes ) ) {
                    foreach ( $themes as $theme ) {
                        $allThemes[] = $theme;
                    }
                }
            }
            MainWP_DB::free_result( $websites );
        }

        static::render_html_widget( $website, $allThemes );
    }

    /**
     *
     * Method render_html_widget().
     *
     * Render html themes widget for current site.
     *
     * @param object $website   Object containing the child site info.
     * @param array  $allThemes Array containing all detected themes data.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::get_sub_array_having()
     * @uses \MainWP\Dashboard\MainWP_Utility::sortmulti()
     */
    public static function render_html_widget( $website, $allThemes ) { // phpcs:ignore -- NOSONAR - complex.

        $is_demo = MainWP_Demo_Handle::is_demo_mode();

        $actived_themes = MainWP_Utility::get_sub_array_having( $allThemes, 'active', 1 );
        $actived_themes = MainWP_Utility::sortmulti( $actived_themes, 'name', 'asc' );

        $inactive_themes = MainWP_Utility::get_sub_array_having( $allThemes, 'active', 0 );
        $inactive_themes = MainWP_Utility::sortmulti( $inactive_themes, 'name', 'asc' );

        ?>
        <div class="mainwp-widget-header">
            <h2 class="ui header handle-drag">
                <?php
                /**
                 * Filter: mainwp_themes_widget_title
                 *
                 * Filters the Themes widget title text.
                 *
                 * @since 4.1
                 */
                echo esc_html( apply_filters( 'mainwp_themes_widget_title', esc_html__( 'Themes', 'mainwp' ), $website ) );
                ?>
                <div class="sub header"><?php esc_html_e( 'Inactive themes on the child site', 'mainwp' ); ?></div>
            </h2>
        </div>
        <div class="mainwp-scrolly-overflow">
            <?php
            /**
             * Action: mainwp_themes_widget_top
             *
             * Fires at the top of the Themes widget on the Individual site overview page.
             *
             * @param object $website   Object containing the child site info.
             * @param array  $allThemes Array containing all detected themes data.
             *
             * @since 4.1
             */
            do_action( 'mainwp_themes_widget_top', $website, $allThemes );
            ?>
            <div id="mainwp-widget-inactive-themes">
                <?php
                /**
                 * Action: mainwp_before_inactive_themes_list
                 *
                 * Fires before the inactive themes list in the Themes widget on the Individual site overview page.
                 *
                 * @param object $website        Object containing the child site info.
                 * @param array  $inactive_themes Array containing all inactive themes data.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_before_inactive_themes_list', $website, $inactive_themes );
                ?>
                <div class="ui divided list">
                    <?php
                    $_count = count( $inactive_themes );
                    for ( $i = 0; $i < $_count; $i++ ) {
                        $slug      = $inactive_themes[ $i ]['slug'];
                        $is_parent = ( isset( $inactive_themes[ $i ]['parent_active'] ) && 1 === (int) $inactive_themes[ $i ]['parent_active'] ) ? true : false;
                        ?>
                        <div class="item row-manage-item">
                            <input class="themeName" type="hidden" name="slug" value="<?php echo esc_attr( wp_strip_all_tags( $inactive_themes[ $i ]['name'] ) ); ?>"/>
                            <input class="themeSlug" type="hidden" name="slug" value="<?php echo esc_attr( wp_strip_all_tags( $inactive_themes[ $i ]['slug'] ) ); ?>"/>
                            <input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr( $website->id ); ?>"/>
                            <div class="right floated content themesAction">
                                <div class="ui right pointing dropdown">
                                    <i class="ellipsis vertical icon"></i>
                                    <div class="menu">
                                        <?php if ( \mainwp_current_user_can( 'dashboard', 'activate_deactivate_themes' ) ) : ?>
                                            <a href="#" class="mainwp-theme-activate item <?php echo $is_demo ? 'disabled' : ''; ?>"><?php esc_html_e( 'Activate', 'mainwp' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
                                        <?php endif; ?>
                                        <?php if ( \mainwp_current_user_can( 'dashboard', 'delete_themes' ) ) : ?>
                                            <a href="#" class="<?php echo $is_parent ? '' : 'mainwp-theme-delete'; ?> item <?php echo $is_demo ? 'disabled' : ''; ?>" <?php echo $is_parent ? 'disabled onclick="javascript:void(0)"' : ''; ?>><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="middle aligned content">
                                <?php echo MainWP_System_Utility::get_theme_icon( $slug ); // phpcs:ignore WordPress.Security.EscapeOutput ?>&nbsp;&nbsp;&nbsp;&nbsp;
                                <?php echo esc_html( $inactive_themes[ $i ]['name'] . ' ' . $inactive_themes[ $i ]['version'] ); ?>
                            </div>
                            <div class="mainwp-row-actions-working">
                                <i class="ui active inline loader tiny"></i> <?php esc_html_e( 'Please wait...', 'mainwp' ); ?>
                            </div>
                        </div>
                    <?php } ?>
                </div>
                <?php
                /**
                 * Action: mainwp_after_inactive_themes_list
                 *
                 * Fires after the inactive themes list in the Themes widget on the Individual site overview page.
                 *
                 * @param object $website        Object containing the child site info.
                 * @param array  $inactive_themes Array containing all inactive themes data.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_after_inactive_themes_list', $website, $inactive_themes );
                ?>
            </div>
        </div>

        <?php
        /**
         * Action: mainwp_themes_widget_bottom
         *
         * Fires at the bottom of the Themes widget on the Individual site overview page.
         *
         * @param object $website   Object containing the child site info.
         * @param array  $allThemes Array containing all detected themes data.
         *
         * @since 4.1
         */
        do_action( 'mainwp_themes_widget_bottom', $website, $allThemes );
        ?>
        <div class="ui two column grid mainwp-widget-footer">
            <div class="left aligned middle aligned column"></div>
            <div class="right aligned middle aligned column">
                <a href="admin.php?page=ThemesManage"><?php esc_html_e( 'Manage Themes', 'mainwp' ); ?></a>
            </div>
        </div>
        <?php
    }

    /**
     * Method activate_theme()
     *
     * Fire off Action activate & display result
     */
    public static function activate_theme() {
        static::action( 'activate' );
        die( wp_json_encode( array( 'result' => esc_html__( 'Theme has been activated!', 'mainwp' ) ) ) );
    }

    /**
     * Method delete_theme()
     *
     * Fire off action deactivate & display result
     */
    public static function delete_theme() {
        static::action( 'delete' );
        die( wp_json_encode( array( 'result' => esc_html__( 'Theme has been permanently deleted!', 'mainwp' ) ) ) );
    }

    /**
     * Method action()
     *
     * Initiate try catch for chosen Action.
     *
     * @param mixed $action Theme Action.
     *
     * @throws \MainWP_Exception Error message.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_error_message()
     * @uses \MainWP\Dashboard\MainWP_Exception
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     */
    public static function action( $action ) {
        $theme     = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $websiteId = isset( $_POST['websiteId'] ) ? intval( $_POST['websiteId'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( empty( $theme ) || empty( $websiteId ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Theme or site ID not found. Please, reload the page and try again.', 'mainwp' ) ) ) );
        }

        $website = MainWP_DB::instance()->get_website_by_id( $websiteId );
        if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'You cannot edit this website.', 'mainwp' ) ) ) );
        }

        if ( MainWP_System_Utility::is_suspended_site( $website ) ) {
            die(
                wp_json_encode(
                    array(
                        'error'     => esc_html__( 'Suspended site.', 'mainwp' ),
                        'errorCode' => 'SUSPENDED_SITE',
                    )
                )
            );
        }

        /**
        * Action: mainwp_before_theme_action
        *
        * Fires before theme activate/delete actions.
        *
        * @since 4.1
        */
        do_action( 'mainwp_before_theme_action', $action, $theme, $website );
        try {
            $information = MainWP_Connect::fetch_url_authed(
                $website,
                'theme_action',
                array(
                    'action' => $action,
                    'theme'  => $theme,
                )
            );
        } catch ( MainWP_Exception $e ) {
            die( wp_json_encode( array( 'error' => MainWP_Error_Helper::get_error_message( $e ) ) ) );
        }

        /**
        * Action: mainwp_after_theme_action
        *
        * Fires after theme activate/delete actions.
        *
        * @since 4.1
        */
        do_action( 'mainwp_after_theme_action', $information, $action, $theme, $website );

        if ( ! isset( $information['status'] ) || ( 'SUCCESS' !== $information['status'] ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Unexpected error occurred. Please try again.', 'mainwp' ) ) ) );
        }
    }
}
