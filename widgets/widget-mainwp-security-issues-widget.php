<?php
/**
 * MainWP Security Widget
 *
 * Displays detected security issues on Child Sites.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Security_Issues_Widget
 *
 * Detect security issues on CHild Sites & Build Widget.
 */
class MainWP_Security_Issues_Widget { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method get_class_name()
     *
     * @return string __CLASS__ Class Name
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method render_widget()
     *
     * Fetch Child Site issues from db & build widget.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_search_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public static function render_widget() {
        $current_wpid = MainWP_System_Utility::get_current_wpid();

        if ( $current_wpid ) {
            $sql = MainWP_DB::instance()->get_sql_website_by_id( $current_wpid );
        } else {
            $sql = MainWP_DB::instance()->get_sql_websites_for_current_user();
        }

        $websites = MainWP_DB::instance()->query( $sql );

        $total_securityIssues = 0;

        MainWP_DB::data_seek( $websites, 0 );
        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
            if ( MainWP_Utility::ctype_digit( $website->securityIssues ) ) {
                $total_securityIssues += $website->securityIssues;
            }
        }

        static::render_issues( $websites, $total_securityIssues, $current_wpid );
    }

    /**
     *
     * Method render_html_widget().
     *
     * Render html themes widget for current site
     *
     * @param mixed $websites Array of websites.
     * @param mixed $total_securityIssues Total security Issues.
     * @param int   $current_wpid current wpid.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     */
    public static function render_issues( $websites, $total_securityIssues, $current_wpid ) { // phpcs:ignore -- NOSONAR - complex.
        $is_demo        = MainWP_Demo_Handle::is_demo_mode();
        $count_websites = MainWP_DB::instance()->get_websites_count();
        $max_issues     = intval( $count_websites ) * 11;
        if ( $current_wpid ) {
            $max_issues = 11;
        }
        $resolved_issues = $max_issues - $total_securityIssues;
        ?>
        <div class="mainwp-widget-header">
            <div class="ui grid">
                <div class="fourteen wide column">
                    <h2 class="ui header handle-drag">
                        <?php
                        /**
                         * Filter: mainwp_security_issues_widget_title
                         *
                         * Filters the Security Issues widget title text.
                         *
                         * @since 4.1
                         */
                        echo esc_html( apply_filters( 'mainwp_security_issues_widget_title', esc_html__( 'Site Hardening', 'mainwp' ) ) );
                        ?>
                        <div class="sub header"><?php esc_html_e( 'Identify and strengthen weak spots to boost site hardening', 'mainwp' ); ?></div>
                    </h2>
                </div>

                <div class="two wide column right aligned">
                    <div id="widget-security-issues-dropdown-selector" class="ui dropdown top right tiny pointing not-auto-init mainwp-dropdown-tab">
                        <i class="vertical ellipsis icon"></i>
                        <div class="menu">
                            <a href="javascript:void(0)" class="item" data-value="show"><?php esc_html_e( 'Show All', 'mainwp' ); ?></a>
                            <a href="javascript:void(0)" class="item" data-value="issues"><?php esc_html_e( 'Show Issues', 'mainwp' ); ?></a>
                            <a href="javascript:void(0)" class="item" data-value="hide"><?php esc_html_e( 'Hide All', 'mainwp' ); ?></a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="ui cards">
                <div class="ui fluid small card">
                    <div class="content">
                        <div class="header">
                            <span class="ui large text"><i class="shield alternate icon"></i> <?php echo intval( $total_securityIssues ); ?></span>
                        </div>
                        <div class="meta">
                            <div class="ui tiny progress mainwp-site-hardening-progress"  data-total="<?php echo esc_attr( $max_issues ); ?>" data-value="<?php echo esc_attr( $resolved_issues ); ?>">
                                <div class="green bar"></div>
                            </div>
                        </div>
                        <div class="description">
                            <strong><?php echo esc_html( _n( 'Recommendation', 'Recommendations', $total_securityIssues, 'mainwp' ) ); ?></strong>
                        </div>
                    </div>
                </div>
            </div>
            <script type="text/javascript">
                jQuery('.mainwp-site-hardening-progress').progress();

                jQuery( document ).ready( function () {
                    let curTab = mainwp_ui_state_load('security-widget-issues');
                    curTab = ['hide', 'issues', 'show' ].includes(curTab) ? curTab : 'show';
                    _showhide_issues(curTab);
                    let $issuesSelect = jQuery( '#widget-security-issues-dropdown-selector' ).dropdown( {
                        onChange: function( value ) {
                            mainwp_ui_state_save('security-widget-issues', value);
                            _showhide_issues(value);
                        }
                    } ).dropdown("set selected", curTab);
                } );

                const _showhide_issues = function(value){
                    if('hide' === value ){
                        jQuery('#mainwp-security-issues-widget-list').hide();
                    } else {
                        jQuery('#mainwp-security-issues-widget-list').fadeIn(1000);
                    }
                    if('issues' === value){
                        jQuery('#mainwp-security-issues-widget-list').fadeIn(1000);
                        jQuery('#mainwp-security-issues-widget-list > .item').show();
                        jQuery('#mainwp-security-issues-widget-list > .item[count-issues=0]').hide();
                    } else if('show' === value){
                        jQuery('#mainwp-security-issues-widget-list').fadeIn(1000);
                        jQuery('#mainwp-security-issues-widget-list > .item').fadeIn(1000);
                    } else  {
                        jQuery('#mainwp-security-issues-widget-list').hide();
                    }
                }
            </script>
            <?php
            /**
             * Action: mainwp_security_issues_widget_top
             *
             * Fires at the bottom of the Security Issues widget.
             *
             * @since 4.1
             */
            do_action( 'mainwp_security_issues_widget_top' );
            ?>

        </div>
        <?php
        /**
         * Action: mainwp_security_issues_widget_top
         *
         * Fires at the bottom of the Security Issues widget.
         *
         * @since 4.1
         */
        do_action( 'mainwp_security_issues_widget_top' );
        ?>


            <div class="mainwp-scrolly-overflow">

            <div class="ui middle aligned divided list" id="mainwp-security-issues-widget-list" style="display:none">
                <?php
                $count_security_issues = '';
                MainWP_DB::data_seek( $websites, 0 );
                while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                    if ( '[]' === $website->securityIssues ) {
                        $count_security_issues = '';
                    } else {
                        $count_security_issues = intval( $website->securityIssues );
                    }
                    ?>
                    <div class="item" count-issues="<?php echo (int) $count_security_issues; ?>" <?php echo '' !== $count_security_issues && $count_security_issues > 0 ? 'status="queue"' : ''; ?> siteid="<?php echo intval( $website->id ); ?>">
                        <div class="right floated">
                            <div class="ui right pointing dropdown">
                                <i class="ellipsis vertical icon"></i>
                                <div class="menu">
                                    <a href="admin.php?page=managesites&scanid=<?php echo esc_attr( $website->id ); ?>" class="item"><?php esc_html_e( 'See Details', 'mainwp' ); ?></a>
                                </div>
                            </div>
                        </div>
                        <?php if ( $is_demo ) : ?>
                            <a href="<?php echo esc_html( $website->url ) . 'wp-admin.html'; ?>" target="_blank"><i class="sign in alternate icon"></i></a>
                        <?php else : ?>
                            <a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" target="_blank"><i class="sign in alternate icon"></i></a>
                        <?php endif; ?>
                        <a href="
                        <?php
                        /**
                         * Filter: mainwp_security_issues_list_item_title_url
                         *
                         * Filters the Security Issues widget list item title URL.
                         *
                         * @since 4.1
                         */
                        echo esc_url( apply_filters( 'mainwp_security_issues_list_item_title_url', 'admin.php?page=managesites&dashboard=' . $website->id, $website ) );
                        ?>
                        ">
                        <?php
                        /**
                         * Filter: mainwp_security_issues_list_item_title
                         *
                         * Filters the Security Issues widget list item title text.
                         *
                         * @since 4.1
                         */
                        echo esc_attr( stripslashes( apply_filters( 'mainwp_security_issues_list_item_title', $website->name, $website ) ) );
                        ?>
                        </a>
                        <?php if ( 0 === $count_security_issues ) : ?>
                            <div><span class="ui small text"><?php esc_html_e( 'No issues detected', 'mainwp' ); ?></span></div>
                        <?php elseif ( '' === $count_security_issues ) : ?>
                            <div><span class="ui small text"><?php esc_html_e( 'No data available', 'mainwp' ); ?></span></div>
                        <?php else : ?>
                            <div><span class="ui small text"><?php echo esc_html( $count_security_issues ); ?> <?php echo esc_html( _n( 'issue detected', 'issues detected', $count_security_issues, 'mainwp' ) ); ?></span></div>
                        <?php endif; ?>

                        <?php
                        /**
                         * Action: mainwp_security_issues_list_item_column
                         *
                         * Fires before the last (actions) colum in the security issues list.
                         *
                         * Preferred HTML structure:
                         *
                         * <div class="column middle aligned">
                         * Your content here!
                         * </div>
                         *
                         * @param object $website Object containing the child site info.
                         *
                         * @since 4.1
                         */
                        do_action( 'mainwp_security_issues_list_item_column', $website );
                        ?>

                    </div>
                <?php } ?>
            </div>
        </div>
        <?php
        /**
         * Action: mainwp_security_issues_widget_bottom
         *
         * Fires at the bottom of the Security Issues widget.
         *
         * @since 4.1
         */
        do_action( 'mainwp_security_issues_widget_bottom' );
        ?>
        <div class="ui two column grid mainwp-widget-footer">
            <div class="left aligned middle aligned column">
            </div>
            <div class="right aligned middle aligned column">

            </div>
        </div>
        <div class="ui active inverted dimmer" style="display:none" id="mainwp-secuirty-issues-loader"><div class="ui text loader"><?php esc_html_e( 'Please wait...', 'mainwp' ); ?></div></div>
        <?php
    }
}
