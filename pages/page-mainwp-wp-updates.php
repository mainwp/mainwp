<?php
/**
 * MainWP WP Updates Page.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_WP_Updates
 *
 * @package MainWP\Dashboard\
 */
class MainWP_WP_Updates { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
     * @return MainWP_Utility
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * Get Class Name.
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Render Ignore Subpage.
     *
     * @param array  $websites               Object containing child sites info.
     * @param object $userExtension          User extension.
     */
    public function render_ignore( $websites, $userExtension ) {

        $decodedIgnoredCores = ! empty( $userExtension->ignored_wp_upgrades ) ? json_decode( $userExtension->ignored_wp_upgrades, true ) : array();

        if ( ! is_array( $decodedIgnoredCores ) ) {
            $decodedIgnoredCores = array();
        }

        ?>
        <div id="mainwp-ignored-cores" class="ui segment">
            <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-ignored-cores-info-message' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-ignored-cores-info-message"></i>
                    <?php printf( esc_html__( 'Manage WordPress you have told your MainWP Dashboard to ignore updates on global or per site level.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/manage-updates/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?>
                </div>
            <?php endif; ?>
            <?php
            /**
             * Action: mainwp_cores_before_ignored_updates
             *
             * Fires on the top of the Ignored Plugin Updates page.
             *
             * @since 5.2
             */
            do_action( 'mainwp_cores_before_ignored_updates', $websites );
            ?>
            <h3 class="ui header">
                <?php esc_html_e( 'Globally Ignored WordPress', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'These are WordPress you have told your MainWP Dashboard to ignore updates on global level and not notify you about pending updates.', 'mainwp' ); ?></div>
            </h3>
            <?php static::render_global_ignored( $decodedIgnoredCores ); ?>
            <div class="ui hidden divider"></div>
            <h3 class="ui header">
                <?php esc_html_e( 'Per Site Ignored WordPress' ); ?>
                <div class="sub header"><?php esc_html_e( 'These are WordPress you have told your MainWP Dashboard to ignore updates per site level and not notify you about pending updates.', 'mainwp' ); ?></div>
            </h3>
            <?php static::render_sites_ignored( $websites ); ?>
            <?php
            /**
             * Action: mainwp_cores_after_ignored_updates
             *
             * Fires on the bottom of the Ignored Plugin Updates page.
             *
             * @since 4.1
             */
            do_action( 'mainwp_cores_after_ignored_updates', $websites );
            ?>
        </div>
        <?php
    }

    /**
     * Method render_global_ignored()
     *
     * Render Global Ignored cores list.
     *
     * @param array $decodedIgnoredCores Decoded ignored cores array.
     */
    public function render_global_ignored( $decodedIgnoredCores ) { //phpcs:ignore -- NOSONAR - complex.
        ?>
        <table id="mainwp-globally-ignored-cores" class="ui compact selectable table unstackable">
                <thead>
                    <tr>
                        <th scope="col" ><?php esc_html_e( 'Ignored version', 'mainwp' ); ?></th>
                        <th scope="col" ></th>
                    </tr>
                </thead>
                <tbody id="globally-ignored-cores-list">
                    <?php if ( $decodedIgnoredCores ) : ?>
                        <?php // phpcs:disable WordPress.Security.EscapeOutput ?>
                        <?php
                        $ignored_vers = array();
                        $ig_vers      = ! empty( $decodedIgnoredCores['ignored_versions'] ) ? $decodedIgnoredCores['ignored_versions'] : '';
                        if ( ! empty( $ig_vers ) && is_array( $ig_vers ) ) {
                            $ignored_vers = $ig_vers;
                        }
                        ?>
                        <?php foreach ( $ignored_vers as $ignored_ver ) { ?>
                            <tr ignored-ver="<?php echo esc_attr( $ignored_ver ); ?>">
                                <td><?php echo 'all_versions' === $ignored_ver ? esc_html__( 'All', 'mainwp' ) : esc_html( $ignored_ver ); ?></td>
                                <td class="right aligned">
                                    <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                                        <a href="#" class="ui mini button" onClick="return updatesoverview_cores_unignore_globally( '<?php echo esc_js( rawurlencode( $ignored_ver ) ); ?>' )"><?php esc_html_e( 'Unignore', 'mainwp' ); ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php } ?>
                        <?php // phpcs:enable ?>
                    <?php endif; ?>
                </tbody>
                <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                    <?php if ( $ig_vers ) : ?>
                        <tfoot class="full-width">
                            <tr>
                                <th scope="col" colspan="2">
                                    <a class="ui right floated small green labeled icon button" onClick="return updatesoverview_cores_unignore_globally_all();" id="mainwp-unignore-globally-all">
                                        <i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
                                    </a>
                                </th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                <?php endif; ?>
            </table>

            <script type="text/javascript">
            jQuery( document ).ready( function() {
                jQuery( '#mainwp-globally-ignored-cores' ).DataTable( {
                    "searching": false,
                    "paging": false,
                    "info": false,
                    "responsive": true,
                    "columnDefs": [ {
                        "targets": 'no-sort',
                        "orderable": false
                    } ],
                    "language": {
                        "emptyTable": "<?php esc_html_e( 'No ignored WordPress.', 'mainwp' ); ?>"
                    }
                } );
            } );
            </script>
        <?php
    }

    /**
     * Render Per Site Ignored table.
     *
     * @param mixed $websites Child Sites.
     *
     * @return void
     *
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     */
    public function render_sites_ignored( $websites ) { // phpcs:ignore -- NOSONAR - complex.
        ?>
        <table id="mainwp-per-site-ignored-cores" class="ui unstackable compact selectable table ">
            <thead>
                <tr>
                    <th scope="col" ><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Ignored version', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Client', 'mainwp' ); ?></th>
                    <th scope="col" ></th>
                </tr>
            </thead>
            <tbody id="ignored-cores-list">
                    <?php
                    MainWP_DB::data_seek( $websites, 0 );
                    $count = 0;
                    while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {

                        if ( $website->is_ignoreCoreUpdates ) {
                            continue;
                        }

                        $decodedIgnoredCores = ! empty( $website->ignored_wp_upgrades ) ? json_decode( $website->ignored_wp_upgrades, true ) : array();

                        if ( ! is_array( $decodedIgnoredCores ) || empty( $decodedIgnoredCores ) ) {
                            continue;
                        }

                        $first = true;
                        // phpcs:disable WordPress.Security.EscapeOutput
                        $ignored_vers = ! empty( $decodedIgnoredCores['ignored_versions'] ) ? $decodedIgnoredCores['ignored_versions'] : '';
                        if ( ! is_array( $ignored_vers ) ) {
                            $ignored_vers = array();
                        }

                        if ( count( $ignored_vers ) ) {
                            ++$count;
                        }
                        foreach ( $ignored_vers as $ignored_ver ) {
                            ?>
                                <tr site-id="<?php echo intval( $website->id ); ?>" ignored-ver="<?php echo esc_attr( $ignored_ver ); ?>">
                                <?php if ( $first ) : ?>
                                    <td><div><a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a></div></td>
                                    <?php $first = false; ?>
                                <?php else : ?>
                                    <td><div style="display:none;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a></div></td>
                                <?php endif; ?>
                                <td><?php echo 'all_versions' === $ignored_ver ? esc_html__( 'All', 'mainwp' ) : esc_html( $ignored_ver ); ?></td>
                                <td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
                                <td class="right aligned">
                                <?php
                                if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) :
                                    ?>
                                        <a href="#" class="ui mini button" onClick="return updatesoverview_unignore_cores_by_site( <?php echo intval( $website->id ); ?>, '<?php echo esc_js( rawurlencode( $ignored_ver ) ); ?>' )"> <?php esc_html_e( 'Unignore', 'mainwp' ); ?></a>
                                    <?php endif; ?>
                                </td>
                                </tr>
                            <?php } ?>
                        <?php
                    }
                    // phpcs:enable
                    if ( $websites ) {
                        MainWP_DB::free_result( $websites );
                    }
                    ?>
            </tbody>
            <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) && $count ) : ?>
                    <tfoot class="full-width">
                        <tr>
                            <th scope="col" colspan="5">
                                <a class="ui right floated small green labeled icon button" onClick="return updatesoverview_unignore_cores_by_site_all();" id="mainwp-unignore-cores-detail-all">
                                    <i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
                                </a>
                            </th>
                        </tr>
                    </tfoot>
            <?php endif; ?>
        </table>
        <script type="text/javascript">
        jQuery( document ).ready( function() {
            jQuery( '#mainwp-per-site-ignored-cores' ).DataTable( {
                "responsive": true,
                "searching": false,
                "paging": false,
                "info": false,
                "columnDefs": [ {
                    "targets": 'no-sort',
                    "orderable": false
                } ],
                "language": {
                    "emptyTable": "<?php esc_html_e( 'No ignored WordPress', 'mainwp' ); ?>"
                }
            } );
        } );
        </script>
        <?php
    }
}
