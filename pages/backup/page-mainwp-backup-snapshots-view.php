<?php
/**
 * MainWP Backup Snapshots View - the restore catalog page.
 *
 * Lists a chosen site's snapshots with their availability (local disk,
 * cloud storage, or removed by retention) and a Restore action on each
 * restorable row. The confirm modal requires explicit overwrite consent
 * and offers a pre-restore safety backup; once a restore starts the
 * same modal follows the job's progress, with cancel while it is still
 * safe and a rollback offer when a failed restore left a safety backup
 * behind.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Snapshots_View
 */
class MainWP_Backup_Snapshots_View { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Render the snapshots page.
     */
    public static function render(): void {
        if ( ! \mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'restore backups', 'mainwp' ) );
            return;
        }

        $site_id = isset( $_GET['site_id'] ) ? (int) $_GET['site_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display filter only.

        // The AJAX list handler gates the same site per-request in
        // authorize_restore_site(); this render path reaches the site's
        // destination names too, so it needs the same per-site check rather
        // than trusting a site id off the query string.
        if ( $site_id > 0 ) {
            $website = MainWP_DB::instance()->get_website_by_id( $site_id );
            if ( ! MainWP_System_Utility::can_edit_website( $website ) || ! \mainwp_current_user_can( 'site', $site_id ) ) {
                $site_id = 0;
            }
        }

        MainWP_Backup_Page::render_header( 'Snapshots' );
        static::render_restore_surface( $site_id, false );
        // render_top_header() opens .mainwp-content-wrap and only the footer
        // closes it. The embedded per-site surface renders inside a page that
        // owns its own wrapper, so the footer belongs here, not in
        // render_restore_surface().
        MainWP_Backup_Page::render_footer( 'Snapshots' );
    }

    /**
     * Render the restore surface: the site picker (standalone only), the
     * snapshot list, the confirm/progress/cancel/rollback modal, and the page
     * script. Shared by the standalone Snapshots page and the per-site Backups
     * tab so both speak to the same restore flow.
     *
     * @param int  $site_id Preselected (standalone) or locked (per-site) site id; 0 = none.
     * @param bool $locked  When true, render for one fixed site: no site picker,
     *                      and a section heading is shown for the embedded context.
     */
    public static function render_restore_surface( int $site_id, bool $locked = false ): void { // phpcs:ignore -- NOSONAR - page assembly.
        if ( ! \mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'restore backups', 'mainwp' ) );
            return;
        }
        wp_enqueue_script( 'mainwp-backup-cancel-result', MAINWP_PLUGIN_URL . 'assets/js/mainwp-backup-cancel-result.js', array(), MainWP_System::instance()->get_version(), true );
        wp_localize_script(
            'mainwp-backup-cancel-result',
            'mainwpBackupCancelMessages',
            array(
                'cancelled'         => __( 'Backup cancelled.', 'mainwp' ),
                'already_finished'  => __( 'Backup already finished. Collecting files...', 'mainwp' ),
                'already_failed'    => __( 'Backup already failed. Checking the result...', 'mainwp' ),
                'busy'              => __( 'The backup is still starting or mid-step, so it could not be cancelled yet. Try again in a moment.', 'mainwp' ),
                'no_active_on_site' => __( 'No active backup found. Checking the run...', 'mainwp' ),
                'not_running'       => __( 'No backup is running.', 'mainwp' ),
                'failed'            => __( 'Could not cancel the backup. Try again.', 'mainwp' ),
            )
        );
        $native_enabled = MainWP_Backup_Settings::is_native_backups_enabled();
        $disconnected   = $site_id > 0 && MainWP_Post_Backup_Handler::site_is_disconnected( MainWP_DB::instance()->get_website_by_id( $site_id ) );
        $reconnect_url  = $site_id > 0 ? admin_url( 'admin.php?page=managesites&dashboard=' . $site_id ) : '';
        $controls_off   = 0 === $site_id || $disconnected;
        if ( $locked ) :
            ?>
        <div id="mainwp-backup-snapshots" class="ui alt segment">
            <h3 class="ui dividing header" style="scroll-margin-top: 6em;"><?php esc_html_e( 'Snapshots & Restore', 'mainwp' ); ?></h3>
            <?php self::render_actions_bar( $site_id, $locked, $native_enabled, $controls_off ); ?>
        <?php else : ?>
            <?php self::render_actions_bar( $site_id, $locked, $native_enabled, $controls_off ); ?>
        <div id="mainwp-backup-snapshots" class="ui padded segment">
            <?php MainWP_Backup_Page::render_intro_message( 'mainwp-backup-snapshots-info-message', __( 'Pick a site to see its backups. From here you can back the site up right now or restore it to one of its backups.', 'mainwp' ) ); ?>
        <?php endif; ?>
            <?php
            if ( $native_enabled ) :
                $manual_plan = $site_id > 0 ? MainWP_DB_Backup::instance()->manual_backup_destination_plan( $site_id ) : array(
                    'ids'    => array(),
                    'source' => 'none',
                );
                $manual_ids  = $manual_plan['ids'];
                sort( $manual_ids, SORT_NUMERIC );
                $destination_names = array();
                foreach ( MainWP_Backup_Storage_Destinations::instance()->list_destinations() as $destination ) {
                    $destination_names[ (int) $destination->id ] = (string) $destination->name;
                }
                $manual_destinations = array();
                foreach ( $manual_ids as $destination_id ) {
                    $manual_destinations[] = $destination_names[ $destination_id ] ?? sprintf(
                        /* translators: %d: storage destination ID. */
                        __( 'Destination #%d (removed)', 'mainwp' ),
                        $destination_id
                    );
                }
                $backup_now_local_only = $site_id > 0 && empty( $manual_ids );
                $local_note            = 'tasks' === $manual_plan['source']
                    ? __( 'The scheduled backup tasks that include this site have no backup destination, so this backup will be kept on the dashboard.', 'mainwp' )
                    : __( 'No backup destination is configured for this site, so this backup will be kept on the dashboard.', 'mainwp' );
                $destinations_template = 'default' === $manual_plan['source']
                    /* translators: %s: storage destination names joined with +. */
                    ? __( 'Backup destinations: Dashboard + %s. No scheduled backup task includes this site, so Back Up Now uses the Default destination.', 'mainwp' )
                    /* translators: %s: storage destination names joined with +. */
                    : __( 'Backup destinations: Dashboard + %s. These come from the scheduled backup tasks that include this site.', 'mainwp' );
                $destinations_note = sprintf(
                    $destinations_template,
                    implode( ' + ', $manual_destinations )
                );
                ?>
            <div id="mainwp-native-backup-now-status" class="mainwp-native-backup-now-status" aria-live="polite" style="margin-top: .5em;"></div>
            <div id="mainwp-native-backup-now-note" class="mainwp-native-backup-now-note" data-local-only="<?php echo $backup_now_local_only ? '1' : '0'; ?>" style="margin-top: .5em; opacity: .8;<?php echo $site_id > 0 ? '' : ' display: none;'; ?>">
                <i class="info circle icon"></i><span class="mainwp-backup-local-note"<?php echo $backup_now_local_only ? '' : ' style="display: none;"'; ?>><?php echo esc_html( $local_note ); ?></span>
                <span class="mainwp-backup-destinations-note"<?php echo $backup_now_local_only ? ' style="display: none;"' : ''; ?>><?php echo esc_html( $destinations_note ); ?></span>
            </div>
            <?php endif; ?>

            <div class="ui yellow message" id="mainwp-snapshots-disconnected"<?php echo $disconnected ? '' : ' style="display: none;"'; ?>>
                <?php esc_html_e( 'This site is disconnected from the Dashboard, so Back Up Now and Restore are turned off until it reconnects.', 'mainwp' ); ?>
                <a href="<?php echo esc_url( $reconnect_url ); ?>" id="mainwp-snapshots-reconnect-link"><?php esc_html_e( 'Reconnect the site', 'mainwp' ); ?></a>
            </div>

            <div class="ui form" style="margin-top: 1em;">
                <?php
                $test_restore_notice = MainWP_Backup_TestRestore_Scheduler::instance()->get_capability_notice();
                if ( ! $locked && '' !== $test_restore_notice ) :
                    ?>
                <div class="ui small info message">
                    <?php echo esc_html( $test_restore_notice ); ?>
                </div>
                <?php endif; ?>

                <?php
                // One column list drives the header and the no-JS
                // placeholder row's colspan, so adding or removing a column
                // cannot leave the placeholder spanning the wrong width.
                // DataTables computes its own colspan for the rows it draws.
                $snapshot_columns = array(
                    'backup'       => esc_html__( 'Backup', 'mainwp' ),
                    'type'         => esc_html__( 'Type', 'mainwp' ),
                    'created'      => esc_html__( 'Created', 'mainwp' ),
                    'size'         => esc_html__( 'Size', 'mainwp' ),
                    'source'       => esc_html__( 'Available from', 'mainwp' ),
                    'test_restore' => esc_html__( 'Test Restore', 'mainwp' ),
                    'actions'      => '',
                );
                ?>
                <table class="ui table" id="mainwp-restore-snapshots-table">
                    <thead>
                        <tr>
                            <?php foreach ( $snapshot_columns as $column_key => $column_label ) : ?>
                            <th scope="col" class="<?php echo esc_attr( 'actions' === $column_key ? 'collapsing' : '' ); ?>"><?php echo esc_html( $column_label ); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="<?php echo (int) count( $snapshot_columns ); ?>"><?php echo $locked ? esc_html__( 'Loading backups...', 'mainwp' ) : esc_html__( 'Select a site to list its backups.', 'mainwp' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html__ escapes both branches. ?></td></tr>
                    </tbody>
                </table>
                <div id="mainwp-restore-failed-jobs" style="display:none;">
                    <h4 class="ui header"><?php esc_html_e( 'Recent restores', 'mainwp' ); ?></h4>
                    <table class="ui table" id="mainwp-restore-history-table">
                        <thead>
                            <tr>
                                <th scope="col" class="collapsing"><?php esc_html_e( 'Restore', 'mainwp' ); ?></th>
                                <th scope="col" class="collapsing"><?php esc_html_e( 'Started', 'mainwp' ); ?></th>
                                <th scope="col" class="collapsing"><?php esc_html_e( 'Result', 'mainwp' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Details', 'mainwp' ); ?></th>
                                <th scope="col" class="collapsing"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="ui small modal" id="mainwp-snapshot-delete-modal">
            <div class="header"><?php esc_html_e( 'Delete Backup', 'mainwp' ); ?></div>
            <div class="content">
                <div class="ui yellow message"><?php esc_html_e( 'These backups and their files on this Dashboard will be deleted. This cannot be undone.', 'mainwp' ); ?></div>
                <div class="ui divided list" id="mainwp-snapshot-delete-ids" style="max-height: 14em; overflow-y: auto;"></div>
                <div class="ui form">
                    <div class="field">
                        <div class="ui checkbox">
                            <input type="checkbox" id="mainwp-snapshot-delete-cloud">
                            <label for="mainwp-snapshot-delete-cloud"><?php esc_html_e( 'Also delete cloud copies', 'mainwp' ); ?></label>
                        </div>
                    </div>
                </div>
                <div class="ui yellow message" id="mainwp-snapshot-delete-warnings" style="display:none;"></div>
                <div class="ui message" id="mainwp-snapshot-delete-result" style="display:none;" aria-live="polite"></div>
            </div>
            <div class="actions">
                <button type="button" class="ui button" id="mainwp-snapshot-delete-close"><?php esc_html_e( 'Close', 'mainwp' ); ?></button>
                <button type="button" class="ui red disabled button" id="mainwp-snapshot-delete-confirm"><?php esc_html_e( 'Delete', 'mainwp' ); ?></button>
            </div>
        </div>

        <div class="ui small modal" id="mainwp-restore-modal">
            <div class="header"><?php esc_html_e( 'Restore Backup', 'mainwp' ); ?></div>
            <div class="content">
                <div id="mainwp-restore-confirm-pane">
                    <div class="ui yellow message">
                        <span id="mainwp-restore-warning-text"><?php esc_html_e( 'Restoring replaces the site\'s files and database with the contents of this backup. Anything changed since the backup was taken will be overwritten.', 'mainwp' ); ?></span>
                    </div>
                    <div class="ui form">
                        <div class="field">
                            <div class="ui checkbox" id="mainwp-restore-presnapshot-box">
                                <input type="checkbox" id="mainwp-restore-presnapshot" checked="checked">
                                <label for="mainwp-restore-presnapshot"><?php esc_html_e( 'Create a safety backup first (recommended). It stays on the site and is the rollback if the restore fails. Without it, files changed by a failed restore cannot be rolled back automatically.', 'mainwp' ); ?></label>
                            </div>
                        </div>
                        <div class="field">
                            <div class="ui checkbox" id="mainwp-restore-consent-box">
                                <input type="checkbox" id="mainwp-restore-consent">
                                <label for="mainwp-restore-consent"><?php esc_html_e( 'I understand this replaces the current site content.', 'mainwp' ); ?></label>
                            </div>
                        </div>
                        <div class="field" id="mainwp-restore-preserve-orders-box" style="display:none;">
                            <div class="ui checkbox">
                                <input type="checkbox" id="mainwp-restore-preserve-orders">
                                <label><?php esc_html_e( 'Preserve orders placed since the backup', 'mainwp' ); ?></label>
                            </div>
                            <div class="mainwp-restore-preserve-orders-desc" style="margin: .35em 0 0 1.85em; opacity: .8; font-size: .9em;"><?php esc_html_e( 'Orders received, customer accounts registered, and product stock changes made after this backup date stay on the site after the restore. The restore still replaces all other database content with the backup version. This works on stores that keep orders in the WooCommerce High-Performance Order Storage tables with synchronization turned off, and only when WooCommerce has not changed version since the backup. The restore stops with an explanation if the store does not meet those conditions, and your data is left untouched. It keeps the current orders as they are and does not restore older orders from the backup.', 'mainwp' ); ?></div>
                        </div>
                    </div>
                </div>
                <div id="mainwp-restore-progress-pane" style="display:none;">
                    <div class="ui active inline loader" id="mainwp-restore-spinner"></div>
                    <span id="mainwp-restore-status-text" aria-live="polite"></span>
                    <div class="ui tiny indicating progress" id="mainwp-restore-progress-bar" style="display:none;margin-top:.75em;"><div class="bar"></div></div>
                    <div class="ui small blue message" id="mainwp-restore-safety-note" style="display:none;"><?php esc_html_e( 'A safety backup was taken before this restore. If the result looks wrong, you can roll back to it.', 'mainwp' ); ?></div>
                    <div class="ui small yellow message" id="mainwp-restore-cancel-warning" style="display:none;"><?php esc_html_e( 'Cancelling now stops the restore partway through. Files already copied stay in place and the previous database tables are kept aside.', 'mainwp' ); ?> <span id="mainwp-restore-cancel-rollback-note" style="display:none;"><?php esc_html_e( 'You can roll back to the safety backup afterwards.', 'mainwp' ); ?></span></div>
                    <div class="ui message" id="mainwp-restore-result-message" style="display:none;"></div>
                    <div class="ui small yellow message" id="mainwp-restore-outcome-note" style="display:none;"></div>
                    <div class="ui small message" id="mainwp-restore-preserve-note" style="display:none;"></div>
                    <div class="ui small message" id="mainwp-restore-reconcile-note" style="display:none;"></div>
                    <div class="ui small red message" id="mainwp-restore-maintenance-note" style="display:none;"><?php esc_html_e( 'The site is in maintenance mode until the database is checked, so it will not serve visitors until then.', 'mainwp' ); ?></div>
                    <div id="mainwp-restore-visit-site-wrap" style="display:none;margin-top:.5em;"><a id="mainwp-restore-visit-site" href="#" target="_blank" rel="noopener" class="ui tiny basic button"><i class="external alternate icon"></i><?php esc_html_e( 'Visit site', 'mainwp' ); ?></a></div>
                </div>
            </div>
            <div class="actions">
                <button type="button" class="ui cancel button" id="mainwp-restore-close-btn"><?php esc_html_e( 'Close', 'mainwp' ); ?></button>
                <button type="button" class="ui button" id="mainwp-restore-cancel-btn" style="display:none;"><?php esc_html_e( 'Cancel Restore', 'mainwp' ); ?></button>
                <button type="button" class="ui basic button" id="mainwp-restore-rollback-btn" style="display:none;"><?php esc_html_e( 'Roll Back', 'mainwp' ); ?></button>
                <button type="button" class="ui red disabled button" id="mainwp-restore-start-btn"><i class="undo icon"></i><?php esc_html_e( 'Start Restore', 'mainwp' ); ?></button>
            </div>
        </div>
        <?php
        static::render_script( $site_id, $disconnected );
    }

    /**
     * Page-level controls in the core actions bar: what to list on the left,
     * what to do with the selected site on the right.
     *
     * @param int  $site_id        Preselected or locked site id; 0 = none.
     * @param bool $locked         Per-site surface: no site picker.
     * @param bool $native_enabled Whether Back Up Now and Clean Up apply.
     * @param bool $controls_off   Whether Back Up Now starts disabled.
     */
    private static function render_actions_bar( int $site_id, bool $locked, bool $native_enabled, bool $controls_off ): void {
        // Clean Up works on the Dashboard's copies, so a disconnected site
        // keeps it; its Child prune just fails and is logged.
        $cleanup_off = 0 === $site_id;
        // The per-site tab hosts the bar inside the surface's own segment,
        // which already insets it; the theme's !important side margins
        // would inset it a second time, past the tables' edges. Inline
        // !important is the only override that outranks them.
        ?>
        <div class="mainwp-actions-bar" id="mainwp-backup-snapshots-toolbar"<?php echo $locked ? ' style="margin-left: 0 !important; margin-right: 0 !important;"' : ''; ?>>
            <div class="ui mini form">
                <div class="ui stackable grid">
                    <div class="ui two column row">
                        <div class="middle aligned column">
                            <?php if ( ! $locked ) : ?>
                            <select id="mainwp-restore-site-selector" class="ui search selection mini dropdown" aria-label="<?php esc_attr_e( 'Site', 'mainwp' ); ?>">
                                <option value="0"><?php esc_html_e( 'Select a site', 'mainwp' ); ?></option>
                                <?php
                                $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
                                while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition -- standard MainWP result loop.
                                    ?>
                                    <option value="<?php echo (int) $website->id; ?>" <?php selected( $site_id, (int) $website->id ); ?>><?php echo esc_html( stripslashes( $website->name ) ); ?></option>
                                    <?php
                                }
                                MainWP_DB::free_result( $websites );
                                ?>
                            </select>
                            <?php endif; ?>
                            <div class="ui toggle checkbox"<?php echo $locked ? '' : ' style="margin-left: 1rem;"'; ?>>
                                <input type="checkbox" id="mainwp-snapshots-show-removed">
                                <label for="mainwp-snapshots-show-removed"><?php esc_html_e( 'Show removed backups', 'mainwp' ); ?></label>
                            </div>
                        </div>
                        <div class="right aligned middle aligned column">
                            <?php if ( $native_enabled ) : ?>
                            <button type="button" class="ui mini green button<?php echo $controls_off ? ' disabled' : ''; ?>" id="mainwp-native-backup-now-btn" <?php disabled( true, $controls_off ); ?>>
                                <i class="cloud upload alternate icon"></i><?php esc_html_e( 'Back Up Now', 'mainwp' ); ?>
                            </button>
                            <button type="button" class="ui mini button" id="mainwp-native-backup-cancel-btn" style="display: none;">
                                <i class="times icon"></i><?php esc_html_e( 'Cancel backup', 'mainwp' ); ?>
                            </button>
                            <button type="button" class="ui mini basic button<?php echo $cleanup_off ? ' disabled' : ''; ?>" id="mainwp-snapshots-cleanup-btn" <?php disabled( true, $cleanup_off ); ?>>
                                <i class="broom icon"></i><?php esc_html_e( 'Clean Up', 'mainwp' ); ?>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Inline page script: list loading, the confirm flow, progress
     * polling, cancel, and rollback.
     *
     * @param int  $site_id      Preselected site id.
     * @param bool $disconnected Whether the preselected site has sync errors.
     */
    private static function render_script( int $site_id, bool $disconnected = false ): void { // phpcs:ignore -- NOSONAR - page script.
        $labels = array(
            'pending'          => esc_html__( 'Queued', 'mainwp' ),
            'preflight'        => esc_html__( 'Checking the site is ready', 'mainwp' ),
            'pre_snapshot'     => esc_html__( 'Creating the safety backup', 'mainwp' ),
            'staging'          => esc_html__( 'Preparing backup files', 'mainwp' ),
            'transferring'     => esc_html__( 'Sending files to the site', 'mainwp' ),
            'running'          => esc_html__( 'Restoring on the site', 'mainwp' ),
            'verifying'        => esc_html__( 'Verifying the restored site', 'mainwp' ),
            'completed'        => esc_html__( 'Restore completed', 'mainwp' ),
            'failed_retryable' => esc_html__( 'Hit a problem; retrying automatically', 'mainwp' ),
            'failed_permanent' => esc_html__( 'Restore failed', 'mainwp' ),
            'cancelled'        => esc_html__( 'Restore cancelled', 'mainwp' ),
        );

        // A rollback job runs through the same phases, but its outcome must
        // read as a rollback, not as another restore.
        $rollback_labels = array(
            'completed'        => esc_html__( 'Rollback completed', 'mainwp' ),
            'failed_permanent' => esc_html__( 'Rollback failed', 'mainwp' ),
            'cancelled'        => esc_html__( 'Rollback cancelled', 'mainwp' ),
        );

        // The rows are built in JS from the AJAX payload, so the shared display
        // map has to travel with the page. backup_type_label() already escapes,
        // so the values go into the row markup as-is; snap.type itself stays raw
        // everywhere the restore flow reads it.
        $type_labels = array(
            'full'        => MainWP_Backup_Page::backup_type_label( 'full' ),
            'db'          => MainWP_Backup_Page::backup_type_label( 'db' ),
            'incremental' => MainWP_Backup_Page::backup_type_label( 'incremental' ),
            'files'       => MainWP_Backup_Page::backup_type_label( 'files' ),
        );
        ?>
        <script type="text/javascript">
        jQuery( function ( $ ) {
            var nonces = {
                list:     '<?php echo esc_js( wp_create_nonce( 'mainwp_restore_snapshot_list' ) ); ?>',
                deletePreview: '<?php echo esc_js( wp_create_nonce( 'mainwp_backup_snapshot_delete_preview' ) ); ?>',
                deleteConfirm: '<?php echo esc_js( wp_create_nonce( 'mainwp_backup_snapshot_delete' ) ); ?>',
                download: '<?php echo esc_js( wp_create_nonce( 'mainwp_backup_snapshot_download_url' ) ); ?>',
                initiate: '<?php echo esc_js( wp_create_nonce( 'mainwp_restore_initiate' ) ); ?>',
                progress: '<?php echo esc_js( wp_create_nonce( 'mainwp_restore_progress' ) ); ?>',
                cancel:   '<?php echo esc_js( wp_create_nonce( 'mainwp_restore_cancel' ) ); ?>',
                rollback: '<?php echo esc_js( wp_create_nonce( 'mainwp_restore_rollback' ) ); ?>',
                nativeBackupInitiate: '<?php echo esc_js( wp_create_nonce( 'mainwp_native_backup_initiate' ) ); ?>',
                nativeBackupProgress: '<?php echo esc_js( wp_create_nonce( 'mainwp_native_backup_progress' ) ); ?>',
                nativeBackupCancel: '<?php echo esc_js( wp_create_nonce( 'mainwp_native_backup_cancel' ) ); ?>',
                snapshotsCleanup: '<?php echo esc_js( wp_create_nonce( 'mainwp_snapshots_cleanup' ) ); ?>'
            };
            var statusLabels = <?php echo wp_json_encode( $labels ); ?>;
            var restoreCancelLabels = {
                cancel: '<?php echo esc_js( esc_html__( 'Cancel Restore', 'mainwp' ) ); ?>',
                stop_checking: '<?php echo esc_js( esc_html__( 'Stop Checking', 'mainwp' ) ); ?>',
                checking: '<?php echo esc_js( esc_html__( 'The Dashboard is checking the site right now, or the restore status just changed. Try again in a moment.', 'mainwp' ) ); ?>',
                stopped: '<?php echo esc_js( esc_html__( 'Stopped checking. The restore finished on the site, but the Dashboard could not reach it afterwards, so this restore is recorded as failed. Open the site to check it.', 'mainwp' ) ); ?>'
            };
            var restoreReportLabels = {
                filesApplied: '<?php echo esc_js( esc_html__( 'The files from the backup were copied to the site and stay in place.', 'mainwp' ) ); ?>',
                filesPartial: '<?php echo esc_js( esc_html__( 'Some files from the backup may have been copied to the site before the restore stopped. Any copied files stay in place.', 'mainwp' ) ); ?>',
                filesNotStarted: '<?php echo esc_js( esc_html__( 'No files were changed.', 'mainwp' ) ); ?>',
                databaseApplied: '<?php echo esc_js( esc_html__( 'The database was restored from the backup.', 'mainwp' ) ); ?>',
                databasePartial: '<?php echo esc_js( esc_html__( 'The database may be partly restored and may not match the backup.', 'mainwp' ) ); ?>',
                databaseNotStarted: '<?php echo esc_js( esc_html__( 'The database was not changed.', 'mainwp' ) ); ?>',
                databaseRolledBack: '<?php echo esc_js( esc_html__( 'The database was put back the way it was before the restore.', 'mainwp' ) ); ?>',
                nothingChanged: '<?php echo esc_js( esc_html__( 'The restore stopped before it changed anything on the site.', 'mainwp' ) ); ?>',
                unknown: '<?php echo esc_js( esc_html__( 'The site\'s restore report could not be read, so files or database tables may be partly restored.', 'mainwp' ) ); ?>',
                rollBack: '<?php echo esc_js( esc_html__( 'Use Roll Back to apply the safety backup.', 'mainwp' ) ); ?>'
            };
            var rollbackStatusLabels = <?php echo wp_json_encode( $rollback_labels ); ?>;
            var rollbackActionLabels = {
                rollBack: '<?php echo esc_js( esc_html__( 'Roll Back', 'mainwp' ) ); ?>',
                retry: '<?php echo esc_js( esc_html__( 'Retry rollback', 'mainwp' ) ); ?>'
            };
            var historyStatusColors = { completed: 'green', failed_permanent: 'red', cancelled: 'grey' };
            var safetyNoteText = '<?php echo esc_js( esc_html__( 'A safety backup was taken before this restore. If the result looks wrong, you can roll back to it.', 'mainwp' ) ); ?>';
            var siteDisconnected = <?php echo $disconnected ? 'true' : 'false'; ?>;
            // The page needs only execute_backups, but restore, cancel and
            // rollback are registered only for staff who also hold
            // restore_backups; an unregistered admin-ajax action answers 400.
            var canRestore = <?php echo \mainwp_current_user_can( 'dashboard', 'restore_backups' ) ? 'true' : 'false'; ?>;
            var backupTypeLabels = <?php echo wp_json_encode( $type_labels ); ?>;
            var currentSite = <?php echo (int) $site_id; ?>;
            var snapshotDeleteBusy = false;
            var snapshotDeletePreview = null;
            var snapshotDeleteTarget = 0;
            var snapshotDeleteRequest = 0;
            var deleteLabels = {
                action: '<?php echo esc_js( esc_html__( 'Delete', 'mainwp' ) ); ?>',
                busy: '<?php echo esc_js( esc_html__( 'A backup or restore is running on this site. Wait for it to finish.', 'mainwp' ) ); ?>',
                failed: '<?php echo esc_js( esc_html__( 'The delete request failed. Reload the page and try again.', 'mainwp' ) ); ?>',
                complete: '<?php echo esc_js( esc_html__( 'Backups deleted.', 'mainwp' ) ); ?>',
                incomplete: '<?php echo esc_js( esc_html__( 'Deletion did not finish. Check which backups remain, then try again.', 'mainwp' ) ); ?>',
                deleted: '<?php /* translators: %s: comma-separated backup IDs, e.g. "#171, #172". */ echo esc_js( esc_html__( 'Deleted backups: %s.', 'mainwp' ) ); ?>',
                remaining: '<?php /* translators: %s: comma-separated backup IDs, e.g. "#171, #172". */ echo esc_js( esc_html__( 'Backups still present: %s.', 'mainwp' ) ); ?>',
                queued: '<?php /* translators: %s: comma-separated backups and their storage destinations, e.g. "#171 to Backblaze". */ echo esc_js( esc_html__( 'Cloud copies queued for deletion: %s.', 'mainwp' ) ); ?>',
                queuedTo: '<?php /* translators: 1: backup ID, e.g. "#171", 2: storage destination name. */ echo esc_js( esc_html__( '%1$s to %2$s', 'mainwp' ) ); ?>'
            };
            var snapshotsTable = null;
            var showRemovedSnapshots = false;
            var currentSnapshot = 0;
            var currentJob = 0;
            var rollbackRequestPending = false;
            var pollTimer = null;
            var restorePollRetries = 0;
            var backupPollTimer = null;
            var backupElapsedTimer = null;
            var backupStartTime = null;
            var backupProgressCache = null;
            var backupLastPhase = null;
            var backupPhaseStartTime = null;
            var backupPhaseStartPercent = 0;
            var backupPollRetries = 0;
            var backupSinceTs = 0;
            var currentSiteUrl = '';
            var phaseOrder = [ 'pending', 'preflight', 'pre_snapshot', 'staging', 'transferring', 'running', 'verifying', 'completed' ];

            // The child reports two recovery outcomes when an in-place database
            // restore fails: rolled_back means the previous database was put
            // back and no data was lost; rollback_failed means recovery could
            // not finish and the site is held in maintenance. Both arrive as a
            // failed restore carrying an error_code, so the terminal branch
            // speaks to the outcome rather than echoing the raw child error.
            var restoreOutcomeMessages = {
                'mainwp_restore_rolled_back': '<?php echo esc_js( __( 'The restore could not finish, so the database was put back the way it was before the restore started. If files were restored before the failure, they stay in place. Check the site, then try the restore again.', 'mainwp' ) ); ?>',
                'mainwp_restore_rollback_failed': '<?php echo esc_js( esc_html__( 'The restore could not finish and the database could not be returned to its previous state automatically, so it may be in a partial state. Have your host or a database administrator check the database before bringing the site back online.', 'mainwp' ) ); ?>'
            };

            var sourceLabels = {
                'local': '<?php echo esc_js( esc_html__( 'This dashboard', 'mainwp' ) ); ?>',
                'remote': '<?php echo esc_js( esc_html__( 'Cloud storage', 'mainwp' ) ); ?>',
                'remote_chunked': '<?php echo esc_js( esc_html__( 'Cloud storage', 'mainwp' ) ); ?>',
                'unavailable': '<?php echo esc_js( esc_html__( 'Removed by retention', 'mainwp' ) ); ?>',
                'removed': '<?php echo esc_js( esc_html__( 'Removed', 'mainwp' ) ); ?>'
            };
            var removalLabels = {
                'manual': '<?php echo esc_js( esc_html__( 'Deleted manually', 'mainwp' ) ); ?>',
                'retention': '<?php echo esc_js( esc_html__( 'Removed by retention', 'mainwp' ) ); ?>',
                'task_deleted': '<?php echo esc_js( esc_html__( 'Deleted with its backup task', 'mainwp' ) ); ?>',
                'cleanup': '<?php echo esc_js( esc_html__( 'Removed by Clean Up', 'mainwp' ) ); ?>'
            };
            var downloadLabels = {
                action: '<?php echo esc_js( esc_html__( 'Download', 'mainwp' ) ); ?>',
                incremental: '<?php echo esc_js( esc_html__( 'Incremental backups are stored as a chain of parts and cannot be downloaded as one file. Use Restore instead.', 'mainwp' ) ); ?>',
                missing: '<?php echo esc_js( esc_html__( 'This backup\'s archive is not stored on this Dashboard, so it cannot be downloaded here.', 'mainwp' ) ); ?>',
                failed: '<?php echo esc_js( esc_html__( 'Could not start the download. Try again.', 'mainwp' ) ); ?>'
            };
            var offsiteLabels = {
                verified: [ 'green', '<?php echo esc_js( esc_html__( 'Offsite Verified', 'mainwp' ) ); ?>' ],
                pending: [ 'blue', '<?php echo esc_js( esc_html__( 'Uploading', 'mainwp' ) ); ?>' ],
                partial: [ 'orange', '<?php echo esc_js( esc_html__( 'Partially uploaded', 'mainwp' ) ); ?>' ],
                failed: [ 'red', '<?php echo esc_js( esc_html__( 'Upload failed', 'mainwp' ) ); ?>' ]
            };
            var uploadLabels = {
                pending: [ 'grey', '<?php echo esc_js( esc_html__( 'Waiting to upload', 'mainwp' ) ); ?>' ],
                chunking: [ 'blue', '<?php echo esc_js( esc_html__( 'Uploading', 'mainwp' ) ); ?>' ],
                uploading: [ 'blue', '<?php echo esc_js( esc_html__( 'Uploading', 'mainwp' ) ); ?>' ],
                submitting: [ 'blue', '<?php echo esc_js( esc_html__( 'Uploading', 'mainwp' ) ); ?>' ],
                complete: [ 'green', '<?php echo esc_js( esc_html__( 'Uploaded', 'mainwp' ) ); ?>' ],
                failed_retryable: [ 'orange', '<?php echo esc_js( esc_html__( 'Upload failed, will retry', 'mainwp' ) ); ?>' ],
                failed_permanent: [ 'red', '<?php echo esc_js( esc_html__( 'Upload failed', 'mainwp' ) ); ?>' ],
                credentials_invalid: [ 'red', '<?php echo esc_js( esc_html__( 'Re-enter credentials', 'mainwp' ) ); ?>' ]
            };
            var testRestoreLabels = {
                'pass': '<?php echo esc_js( esc_html__( 'Test-restored', 'mainwp' ) ); ?>',
                'fail': '<?php echo esc_js( esc_html__( 'Test-restore failed', 'mainwp' ) ); ?>',
                'unsupported': '<?php echo esc_js( esc_html__( 'Couldn\'t test here', 'mainwp' ) ); ?>',
                'unavailable': '<?php echo esc_js( esc_html__( 'Artifacts unavailable', 'mainwp' ) ); ?>'
            };

            var restoreWarnings = {
                'default': '<?php echo esc_js( esc_html__( 'Restoring replaces the site\'s files and database with the contents of this backup. Anything changed since the backup was taken will be overwritten.', 'mainwp' ) ); ?>',
                'files': '<?php echo esc_js( esc_html__( 'Restoring this backup will replace the site\'s files with the snapshot\'s contents. The database will not be changed. Anything in the site files that changed after the snapshot was taken will be overwritten.', 'mainwp' ) ); ?>',
                'db': '<?php echo esc_js( esc_html__( 'Restoring this backup replaces the site\'s database with the snapshot\'s contents. Files are not changed. Anything in the database that changed after the snapshot was taken will be overwritten.', 'mainwp' ) ); ?>',
                'incremental': '<?php echo esc_js( esc_html__( 'Restoring this incremental backup rebuilds the site\'s files and database as of this point in the backup chain. Anything changed since then will be overwritten.', 'mainwp' ) ); ?>'
            };
            var safetyBackupLabels = {
                'default': '<?php echo esc_js( __( 'Create a safety backup first (recommended). It stays on the site and is the rollback if the restore fails. Without it, files changed by a failed restore cannot be rolled back automatically.', 'mainwp' ) ); ?>',
                'db': '<?php echo esc_js( __( 'Create a safety backup first (recommended). It stays on the site, and you can roll back to it if the restored database turns out wrong.', 'mainwp' ) ); ?>'
            };
            var networkWarning = '<?php echo esc_js( esc_html__( 'This restores every site in the network to the backup\'s point in time.', 'mainwp' ) ); ?>';
            var siteIsMultisite = false;
            var siteHasWooCommerce = false;
            var wooMaxAgeDays = <?php echo (int) apply_filters( 'mainwp_restore_woo_max_age_days', 1 ); ?>;
            var wooAgeWarning = '<?php echo esc_js( esc_html__( 'This store runs WooCommerce and this backup is %d day(s) old. Restoring its database removes any orders and customers created since the backup was taken. Consider a files-only restore to keep the current store data.', 'mainwp' ) ); ?>';

            function escAttr( text ) {
                return String( text ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
            }

            // Format a duration as m:ss, rolling to h:mm:ss past an hour.
            function fmtClock( ms ) {
                var total = Math.max( 0, Math.floor( ms / 1000 ) );
                var s = total % 60;
                var m = Math.floor( total / 60 ) % 60;
                var h = Math.floor( total / 3600 );
                var ss = s < 10 ? '0' + s : '' + s;
                if ( h > 0 ) {
                    var mm = m < 10 ? '0' + m : '' + m;
                    return h + ':' + mm + ':' + ss;
                }
                return m + ':' + ss;
            }

            // Sole writer of the backup-now status line. The poll refreshes the
            // cached fields; this ticks the elapsed clock every second so it
            // advances smoothly between the slower polls.
            function renderBackupStatus() {
                if ( ! backupProgressCache ) {
                    return;
                }
                var c = backupProgressCache;
                var parts = [ c.label ];
                if ( c.percent !== null && c.percent !== undefined ) {
                    parts.push( c.percent + '%' );
                }
                if ( backupStartTime ) {
                    parts.push( '(' + fmtClock( Date.now() - backupStartTime ) + ')' );
                }
                if ( c.eta ) {
                    parts.push( c.eta );
                }
                $( '#mainwp-native-backup-now-status' ).text( parts.join( ' ' ) ).removeClass( 'ui red text' );
            }

            function stopBackupTimers() {
                window.clearTimeout( backupPollTimer );
                // Null the handle too: the reload/switch resume in
                // loadSnapshots is gated on `! backupPollTimer`, and a stale
                // cleared id would block every later resume.
                backupPollTimer = null;
                if ( backupElapsedTimer ) {
                    window.clearInterval( backupElapsedTimer );
                    backupElapsedTimer = null;
                }
            }

            var snapshotsPlaceholders = {
                idle:    '<?php echo esc_js( esc_html__( 'Select a site to list its backups.', 'mainwp' ) ); ?>',
                loading: '<div class="ui active inline loader mini"></div>',
                empty:   '<?php echo esc_js( esc_html__( 'No backups yet for this site. Back Up Now or add it to a schedule.', 'mainwp' ) ); ?>',
                error:   '<?php echo esc_js( esc_html__( 'The backup list could not be loaded. Check the connection to the dashboard and try again.', 'mainwp' ) ); ?>'
            };

            // DataTables paints the full-width placeholder row from
            // language.emptyTable at draw time, so the loading, empty, and
            // failed states all speak through it; a hand-built <tr> would be
            // thrown away by the next draw.
            function setSnapshotsPlaceholder( text ) {
                snapshotsTable.settings()[0].oLanguage.sEmptyTable = text;
            }

            // Back Up Now and Clean Up act on the selected site, so they follow
            // the list: never left disabled by a reload that failed. Back Up
            // Now also stays off while the site is disconnected; the server
            // refuses it then anyway. Clean Up stays on, since it frees the
            // Dashboard's disk even when the Child cannot be reached.
            function enableSnapshotControls( enabled ) {
                $( '#mainwp-snapshots-cleanup-btn' ).prop( 'disabled', ! enabled ).toggleClass( 'disabled', ! enabled );
                $( '#mainwp-native-backup-now-btn' ).prop( 'disabled', ! enabled || siteDisconnected ).toggleClass( 'disabled', ! enabled || siteDisconnected );
            }

            function showDisconnected( disconnected, reconnectUrl ) {
                siteDisconnected = !! disconnected;
                if ( reconnectUrl ) {
                    $( '#mainwp-snapshots-reconnect-link' ).attr( 'href', reconnectUrl );
                }
                $( '#mainwp-snapshots-disconnected' ).toggle( siteDisconnected );
            }

            // The label and the date may sit on separate lines, but neither
            // breaks inside: the hyphen in "Test-restored" and the spaces in
            // the Dashboard's long date format would otherwise split them.
            function testRestoreCell( snap ) {
                if ( 'pass' === snap.test_restore_state ) {
                    var testedOn = snap.test_restore_label ? ' <span style="white-space: nowrap;">' + escAttr( snap.test_restore_label ) + '</span>' : '';
                    return '<span class="ui mini green label" style="white-space: nowrap;"><i class="check icon"></i>' + testRestoreLabels.pass + '</span>' + testedOn;
                }
                if ( 'fail' === snap.test_restore_state ) {
                    return '<span class="ui mini red label" style="white-space: nowrap;">' + testRestoreLabels.fail + '</span>';
                }
                if ( 'unsupported' === snap.test_restore_state ) {
                    return '<span class="ui mini grey label" style="white-space: nowrap;">' + testRestoreLabels.unsupported + '</span>';
                }
                if ( 'unavailable' === snap.test_restore_state ) {
                    return '<span class="ui mini grey label" style="white-space: nowrap;">' + testRestoreLabels.unavailable + '</span>';
                }
                return '&mdash;';
            }

            function deleteButton( snap ) {
                if ( 'pruned' === snap.status ) {
                    return '';
                }
                var disabled = snapshotDeleteBusy ? ' disabled' : '';
                var title = snapshotDeleteBusy ? ' aria-disabled="true" title="' + escAttr( deleteLabels.busy ) + '"' : '';
                return '<a href="#" class="ui mini basic button mainwp-delete-snapshot' + disabled + '" data-snapshot="' + snap.id + '"' + title + '>' + deleteLabels.action + '</a>';
            }

            function setSnapshotDeleteBusy( busy ) {
                snapshotDeleteBusy = busy;
                if ( snapshotsTable ) {
                    snapshotsTable.rows().invalidate().draw( false );
                }
            }

            function snapshotIdList( ids ) {
                return ( ids || [] ).map( function ( id ) {
                    return '#' + ( parseInt( id, 10 ) || 0 );
                } ).join( ', ' );
            }

            // Function replacements keep a "$&" in a destination name literal.
            function cloudQueueLabel( pair ) {
                var id = '#' + ( parseInt( pair.snapshot_id, 10 ) || 0 );
                var name = pair.destination_name;
                if ( ! name ) {
                    return id;
                }
                return deleteLabels.queuedTo.replace( '%1$s', function () { return id; } ).replace( '%2$s', function () { return name; } );
            }

            function deleteResultLines( response ) {
                var lines = [ 'complete' === response.status ? deleteLabels.complete : deleteLabels.incomplete ];
                [
                    [ deleteLabels.deleted, snapshotIdList( response.deleted_ids ) ],
                    [ deleteLabels.remaining, snapshotIdList( response.remaining_ids ) ],
                    [ deleteLabels.queued, ( response.cloud_deletes_queued || [] ).map( cloudQueueLabel ).join( ', ' ) ]
                ].forEach( function ( segment ) {
                    if ( '' !== segment[1] ) {
                        lines.push( segment[0].replace( '%s', function () { return segment[1]; } ) );
                    }
                } );
                ( response.errors || [] ).forEach( function ( error ) {
                    if ( error && error.message ) {
                        lines.push( error.message );
                    }
                } );
                return lines;
            }

            function setDeleteMessages( selector, lines ) {
                var $box = $( selector ).empty();
                lines.forEach( function ( line ) {
                    $( '<p></p>' ).text( line ).appendTo( $box );
                } );
                return $box.toggle( lines.length > 0 );
            }

            // backupTypeLabels are already escaped, as in the table's Type column.
            function deletePreviewItem( id, snap ) {
                var html = '<div class="item"><strong>#' + ( parseInt( id, 10 ) || 0 ) + '</strong>';
                if ( snap ) {
                    html += ' <span class="ui mini basic label">' + ( backupTypeLabels[ snap.type ] || escAttr( snap.type ) ) + '</span> <span style="white-space: nowrap;">' + escAttr( snap.created_label || '' ) + '</span>';
                }
                return html + '</div>';
            }

            function showDeletePreview( preview, notices ) {
                snapshotDeletePreview = preview;
                var rows = {};
                if ( snapshotsTable ) {
                    snapshotsTable.rows().data().toArray().forEach( function ( snap ) {
                        rows[ snap.id ] = snap;
                    } );
                }
                var $ids = $( '#mainwp-snapshot-delete-ids' ).empty();
                preview.would_affect.snapshot_ids.forEach( function ( id ) {
                    $( deletePreviewItem( id, rows[ id ] ) ).appendTo( $ids );
                } );
                $( '#mainwp-snapshot-delete-cloud' ).prop( 'checked', preview.would_affect.delete_cloud_copies );
                setDeleteMessages( '#mainwp-snapshot-delete-warnings', ( notices || [] ).concat( preview.warnings ) );
                $( '#mainwp-snapshot-delete-confirm' ).prop( 'disabled', ! preview.can_proceed ).toggleClass( 'disabled', ! preview.can_proceed );
                setDeleteMessages( '#mainwp-snapshot-delete-result', preview.refusals.map( function ( refusal ) { return refusal.message; } ) ).removeClass( 'red' );
            }

            // A changed set is a new consent step, so its message joins the
            // fresh preview's warnings; the result box reports deletes that ran.
            function showDeleteResponse( response ) {
                if ( 'changed' === response.status && response.preview && response.preview.would_affect ) {
                    showDeletePreview( response.preview, ( response.errors || [] ).map( function ( error ) {
                        return error.message;
                    } ) );
                    return;
                }
                setDeleteMessages( '#mainwp-snapshot-delete-result', deleteResultLines( response ) ).toggleClass( 'red', 'complete' !== response.status );
            }

            function requestDeletePreview( cloud, notices ) {
                var siteId = currentSite;
                var requestId = ++snapshotDeleteRequest;
                var fields = { action: 'mainwp_backup_snapshot_delete_preview', security: nonces.deletePreview, site_id: siteId, snapshot_id: snapshotDeleteTarget };
                if ( undefined !== cloud ) {
                    fields.delete_cloud_copies = cloud ? 1 : 0;
                }
                snapshotDeletePreview = null;
                $( '#mainwp-snapshot-delete-confirm' ).prop( 'disabled', true ).addClass( 'disabled' );
                $.post( ajaxurl, fields, function ( response ) {
                    if ( siteId !== currentSite || requestId !== snapshotDeleteRequest ) {
                        return;
                    }
                    if ( ! response || response.error ) {
                        setDeleteMessages( '#mainwp-snapshot-delete-result', ( notices || [] ).concat( [ response && response.error ? response.error : deleteLabels.failed ] ) );
                        return;
                    }
                    showDeletePreview( response, notices );
                }, 'json' ).fail( function () {
                    if ( siteId === currentSite && requestId === snapshotDeleteRequest ) {
                        setDeleteMessages( '#mainwp-snapshot-delete-result', ( notices || [] ).concat( [ deleteLabels.failed ] ) );
                    }
                } );
            }

            function downloadButton( snap ) {
                var reason = 'incremental' === snap.type ? downloadLabels.incremental : ( 'local' !== snap.source ? downloadLabels.missing : '' );
                if ( '' === reason ) {
                    return '<a href="#" class="ui mini basic button mainwp-download-snapshot" data-snapshot="' + snap.id + '">' + downloadLabels.action + '</a>';
                }
                // Fomantic gives disabled buttons pointer-events: none, so the wrapper's title shows on hover
                // and the anchor's own title stays its accessible description.
                var title = ' title="' + escAttr( reason ) + '"';
                return '<span' + title + '><a href="#" class="ui mini basic button mainwp-download-snapshot disabled" data-snapshot="' + snap.id + '" aria-disabled="true"' + title + '>' + downloadLabels.action + '</a></span>';
            }

            // The static placeholder row is the no-JS fallback; drop it before
            // DataTables takes the tbody over so it can never become a data row.
            $( '#mainwp-restore-snapshots-table tbody' ).empty();
            snapshotsTable = $( '#mainwp-restore-snapshots-table' ).DataTable( {
                "data": [],
                // Same order as the header rendered by render_restore_surface().
                // The snapshot object is the row data, so sorting works off the
                // raw values while only the display strings are built here.
                "columns": [
                    {
                        "data": "id",
                        "render": function ( data, type ) {
                            return 'display' === type ? '#' + data : ( parseInt( data, 10 ) || 0 );
                        }
                    },
                    {
                        "data": "type",
                        "render": function ( data, type, snap ) {
                            // backup_type_label() already escaped these; an
                            // unrecognised type falls back to the raw value.
                            var label = backupTypeLabels[ data ] || escAttr( data );
                            var n = parseInt( snap.skipped_count, 10 ) || 0;
                            if ( 'display' === type && n > 0 ) {
                                var skipped = ( 1 === n
                                    ? '<?php /* translators: %d: number of files not backed up. */ echo esc_js( _n( '%d file not backed up', '%d files not backed up', 1, 'mainwp' ) ); ?>'
                                    : '<?php /* translators: %d: number of files not backed up. */ echo esc_js( _n( '%d file not backed up', '%d files not backed up', 2, 'mainwp' ) ); ?>'
                                ).replace( '%d', n );
                                label += ' <a href="#" class="mainwp-snapshot-skipped-toggle"><small>' + escAttr( skipped ) + '</small></a>';
                            }
                            return label;
                        }
                    },
                    {
                        "data": "created",
                        "render": function ( data, type, snap ) {
                            // The Dashboard's long date format would break
                            // the time off onto its own line.
                            if ( 'display' === type ) {
                                return '<span style="white-space: nowrap;">' + escAttr( snap.created_label || '' ) + '</span>';
                            }
                            return 'filter' === type ? escAttr( snap.created_label || '' ) : ( parseInt( data, 10 ) || 0 );
                        }
                    },
                    {
                        "data": null,
                        // Not orderable: the payload carries only the formatted
                        // size, so sorting it would put 900 MB above 1.2 GB.
                        "orderable": false,
                        "render": function ( data, type, snap ) {
                            return snap.size_label ? escAttr( snap.size_label ) : '&mdash;';
                        }
                    },
                    {
                        "data": null,
                        "render": function ( data, type, snap ) {
                            var sourceCell = sourceLabels[ snap.source ] || escAttr( snap.source );
                            if ( 'unavailable' === snap.source && Object.prototype.hasOwnProperty.call( removalLabels, snap.removal_reason ) ) {
                                sourceCell = removalLabels[ snap.removal_reason ];
                            } else if ( 'unavailable' === snap.source && 'pruned' === snap.status ) {
                                // Pruned before the cause was recorded, so retention may not be why.
                                sourceCell = sourceLabels.removed;
                            }
                            if ( 'display' === type ) {
                                var offsite = offsiteLabels[ snap.offsite_state ];
                                if ( offsite ) {
                                    sourceCell += ' <span class="ui mini ' + offsite[0] + ' label">' + offsite[1] + '</span>';
                                }
                                if ( snap.uploads && snap.uploads.length ) {
                                    var complete = snap.uploads.filter( function ( upload ) { return 'complete' === upload.status; } ).length;
                                    var summary = '<?php /* translators: 1: completed uploads, 2: total uploads. */ echo esc_js( esc_html__( '%1$d of %2$d uploaded', 'mainwp' ) ); ?>'.replace( '%1$d', complete ).replace( '%2$d', snap.uploads.length );
                                    sourceCell += ' <a href="#" class="mainwp-snapshot-uploads-toggle"><small>' + summary + '</small></a>';
                                }
                            }
                            return sourceCell;
                        }
                    },
                    {
                        "data": null,
                        "render": function ( data, type, snap ) {
                            return testRestoreCell( snap );
                        }
                    },
                    {
                        "data": null,
                        "orderable": false,
                        "className": "collapsing",
                        "render": function ( data, type, snap ) {
                            var restore = snap.restorable && canRestore
                                ? '<a href="#" class="ui mini basic button mainwp-restore-snapshot' + ( siteDisconnected ? ' disabled' : '' ) + '" data-snapshot="' + snap.id + '" data-snapshot-type="' + escAttr( snap.type ) + '" data-snapshot-age="' + ( parseInt( snap.age_days, 10 ) || 0 ) + '"><?php echo esc_js( esc_html__( 'Restore', 'mainwp' ) ); ?></a>'
                                : '';
                            return restore + downloadButton( snap ) + deleteButton( snap );
                        }
                    }
                ],
                "createdRow": function ( row, snap ) {
                    if ( snap.restorable ) {
                        return;
                    }
                    if ( 'incremental' === snap.type || 'local' !== snap.source ) {
                        $( row ).addClass( 'disabled' );
                    }
                    if ( snap.restore_reason ) {
                        $( row ).attr( 'title', snap.restore_reason );
                    }
                },
                "order": [ [ 2, 'desc' ] ],
                // The columns' narrowest layout is wider than the content
                // area at 768px; scroll inside the page like Manage Sites.
                // Widths measured at init see an empty table and would clip
                // the Restore column once rows arrive.
                "scrollX": true,
                "autoWidth": false,
                "pageLength": 10,
                "pagingType": "full_numbers",
                "stateSave": true,
                "language": {
                    "emptyTable": snapshotsPlaceholders.idle,
                    "zeroRecords": '<?php echo esc_js( esc_html__( 'No backups match the current filter.', 'mainwp' ) ); ?>'
                }
            } );

            $( '#mainwp-restore-snapshots-table' ).on( 'click', '.mainwp-download-snapshot', function ( event ) {
                event.preventDefault();
                var button = $( this );
                if ( button.hasClass( 'disabled' ) || button.hasClass( 'loading' ) ) {
                    return;
                }
                button.addClass( 'loading' );
                $.post( ajaxurl, { action: 'mainwp_backup_snapshot_download_url', security: nonces.download, snapshot_id: button.data( 'snapshot' ) }, function ( response ) {
                    if ( response && response.url ) {
                        window.location.assign( response.url );
                    } else {
                        window.alert( response && response.error ? response.error : downloadLabels.failed );
                    }
                }, 'json' ).fail( function () {
                    window.alert( downloadLabels.failed );
                } ).always( function () {
                    button.removeClass( 'loading' );
                } );
            } );

            function skippedFilesNote( snap ) {
                var n = parseInt( snap.skipped_count, 10 ) || 0;
                if ( n <= 0 ) {
                    return null;
                }
                var $note = $( '<div>' ).addClass( 'ui small yellow message' );
                var sentence = ( 1 === n
                    ? '<?php /* translators: %d: number of files not backed up. */ echo esc_js( _n( '%d file was not backed up.', '%d files were not backed up.', 1, 'mainwp' ) ); ?>'
                    : '<?php /* translators: %d: number of files not backed up. */ echo esc_js( _n( '%d file was not backed up.', '%d files were not backed up.', 2, 'mainwp' ) ); ?>'
                ).replace( '%d', n );
                $note.append( $( '<p>' ).text( sentence ) );
                var paths = ( Array.isArray( snap.skipped_paths ) ? snap.skipped_paths : [] ).filter( function ( path ) {
                    return 'string' === typeof path;
                } ).slice( 0, 20 );
                var $paths = $( '<ul>' );
                paths.forEach( function ( path ) {
                    $paths.append( $( '<li>' ).text( path ) );
                } );
                $note.append( $paths );
                if ( n > paths.length ) {
                    var sample = '<?php /* translators: 1: displayed paths, 2: total files not backed up. */ echo esc_js( __( 'Showing %1$d of %2$d files that were not backed up.', 'mainwp' ) ); ?>';
                    $note.append( $( '<p>' ).text( sample.replace( '%1$d', paths.length ).replace( '%2$d', n ) ) );
                }
                return $note;
            }

            $( '#mainwp-restore-snapshots-table' ).on( 'click', '.mainwp-snapshot-skipped-toggle', function ( event ) {
                event.preventDefault();
                var row = snapshotsTable.row( $( this ).closest( 'tr' ) );
                var $row = $( row.node() );
                if ( row.child.isShown() && 'skipped' === $row.attr( 'data-mainwp-snapshot-child' ) ) {
                    row.child.hide();
                    return;
                }
                var note = skippedFilesNote( row.data() );
                if ( note ) {
                    row.child( note ).show();
                    $row.attr( 'data-mainwp-snapshot-child', 'skipped' );
                }
            } );

            $( '#mainwp-restore-snapshots-table' ).on( 'click', '.mainwp-snapshot-uploads-toggle', function ( event ) {
                event.preventDefault();
                var row = snapshotsTable.row( $( this ).closest( 'tr' ) );
                var $row = $( row.node() );
                if ( row.child.isShown() && 'uploads' === $row.attr( 'data-mainwp-snapshot-child' ) ) {
                    row.child.hide();
                    return;
                }
                var details = '<div class="ui mini divided list">';
                ( row.data().uploads || [] ).forEach( function ( upload ) {
                    var label = uploadLabels[ upload.status ] || [ 'grey', escAttr( upload.status ) ];
                    details += '<div class="item">' + escAttr( upload.name ) + ' (' + escAttr( upload.provider ) + ') <span class="ui mini ' + label[0] + ' label">' + label[1] + '</span>';
                    if ( [ 'failed_retryable', 'failed_permanent', 'credentials_invalid' ].indexOf( upload.status ) !== -1 && upload.last_error ) {
                        details += ' ' + escAttr( upload.last_error );
                    }
                    details += '</div>';
                } );
                row.child( details + '</div>' ).show();
                $row.attr( 'data-mainwp-snapshot-child', 'uploads' );
            } );

            // The search stack is global to the page, so scope the callback to
            // this table. Filtering runs before the row nodes exist, so it reads
            // the snapshot's source off the row data rather than a row class.
            $.fn.dataTable.ext.search.push( function ( settings, filterData, rowIndex, snap ) {
                if ( 'mainwp-restore-snapshots-table' !== settings.nTable.id ) {
                    return true;
                }
                return showRemovedSnapshots || ! snap || 'unavailable' !== snap.source;
            } );

            $( '#mainwp-snapshots-show-removed' ).on( 'change', function () {
                showRemovedSnapshots = this.checked;
                snapshotsTable.draw();
            } );

            // Every reload path - a site switch, a finished restore, a finished
            // backup, a cleanup - comes through here, so the table is only ever
            // refilled through the DataTables API.
            function loadSnapshots( siteId ) {
                if ( currentSite !== siteId ) {
                    ++snapshotDeleteRequest;
                    snapshotDeletePreview = null;
                    $( '#mainwp-snapshot-delete-modal' ).modal( 'hide' );
                }
                currentSite = siteId;
                if ( siteId > 0 ) {
                    enableSnapshotControls( true );
                }
                setSnapshotsPlaceholder( snapshotsPlaceholders.loading );
                snapshotsTable.clear().draw();
                $( '#mainwp-restore-failed-jobs' ).hide().find( 'tbody' ).empty();
                $.post( ajaxurl, { action: 'mainwp_restore_snapshot_list', security: nonces.list, site_id: siteId }, function ( response ) {
                    if ( siteId !== currentSite ) {
                        return; // A site switch superseded this request; its response must not repaint the newer site's list.
                    }
                    // Before the rows draw: the Restore buttons read this flag.
                    showDisconnected( response.site_disconnected, response.reconnect_url );
                    enableSnapshotControls( siteId > 0 );
                    siteIsMultisite = !! response.multisite;
                    siteHasWooCommerce = !! response.site_has_woocommerce;
                    currentSiteUrl = response.site_url || '';
                    var backupNote = $( '#mainwp-native-backup-now-note' ).attr( 'data-local-only', response.local_only ? '1' : '0' ).toggle( siteId > 0 );
                    var localNote = 'tasks' === response.destinations_source
                        ? '<?php echo esc_js( esc_html__( 'The scheduled backup tasks that include this site have no backup destination, so this backup will be kept on the dashboard.', 'mainwp' ) ); ?>'
                        : '<?php echo esc_js( esc_html__( 'No backup destination is configured for this site, so this backup will be kept on the dashboard.', 'mainwp' ) ); ?>';
                    backupNote.find( '.mainwp-backup-local-note' ).html( localNote ).toggle( !! response.local_only );
                    var destinationsTemplate = 'default' === response.destinations_source
                        ? '<?php /* translators: %s: storage destination names joined with +. */ echo esc_js( esc_html__( 'Backup destinations: Dashboard + %s. No scheduled backup task includes this site, so Back Up Now uses the Default destination.', 'mainwp' ) ); ?>'
                        : '<?php /* translators: %s: storage destination names joined with +. */ echo esc_js( esc_html__( 'Backup destinations: Dashboard + %s. These come from the scheduled backup tasks that include this site.', 'mainwp' ) ); ?>';
                    var destinationsNote = destinationsTemplate.replace( '%s', function () { return escAttr( ( response.manual_destinations || [] ).join( ' + ' ) ); } );
                    backupNote.find( '.mainwp-backup-destinations-note' ).html( destinationsNote ).toggle( ! response.local_only );

                    setSnapshotsPlaceholder( snapshotsPlaceholders.empty );
                    snapshotDeleteBusy = !! response.snapshot_delete_busy;
                    snapshotsTable.clear();
                    snapshotsTable.rows.add( response.snapshots || [] );
                    snapshotsTable.draw();

                    var failedJobs = response.failed_restore_jobs || [];
                    var $history = $( '#mainwp-restore-history-table tbody' ).empty();
                    failedJobs.forEach( function ( job ) {
                        var jobLabels = job.is_rollback ? rollbackStatusLabels : statusLabels;
                        var jobMessage = ( job.error_code && restoreOutcomeMessages[ job.error_code ] ) || job.last_error;
                        if ( 'completed' === job.status && ! jobMessage ) {
                            jobMessage = safetyNoteText;
                        }
                        var $action = $( '<td class="collapsing"></td>' );
                        // A failed rollback still holds the safety backup it
                        // restores from, so it offers another attempt.
                        if ( job.has_rollback && canRestore ) {
                            $( '<button type="button" class="ui mini basic button mainwp-restore-failed-job"></button>' )
                                .attr( 'data-job-id', job.job_id )
                                .prop( 'disabled', !! response.active_restore_job_id || siteDisconnected )
                                .text( job.is_rollback ? rollbackActionLabels.retry : rollbackActionLabels.rollBack )
                                .appendTo( $action );
                        }
                        $( '<tr></tr>' ).append(
                            $( '<td class="collapsing"></td>' ).text( '#' + job.job_id ),
                            $( '<td class="collapsing"></td>' ).text( job.date_label || '' ),
                            $( '<td class="collapsing"></td>' ).append(
                                $( '<span class="ui small label"></span>' ).addClass( historyStatusColors[ job.status ] || 'grey' ).text( jobLabels[ job.status ] || statusLabels[ job.status ] || job.status )
                            ),
                            $( '<td></td>' ).text( jobMessage ),
                            $action
                        ).appendTo( $history );
                    } );
                    $( '#mainwp-restore-failed-jobs' ).toggle( failedJobs.length > 0 );

                    // The reload bootstrap: resume what the server says is
                    // running for this site. A restore mid-flight reopens the
                    // progress panel and an open on-demand run resumes its
                    // poll, so neither depends on the tab that started it.
                    if ( response.active_restore_job_id && ! currentJob ) {
                        if ( ! $( '#mainwp-restore-modal' ).modal( 'is active' ) ) {
                            $( '#mainwp-restore-modal' ).modal( 'show' );
                        }
                        startJobFollow( response.active_restore_job_id );
                    }
                    if ( response.manual_backup_running && ! backupPollTimer ) {
                        resumeBackupFollow();
                    }
                }, 'json' ).fail( function () {
                    // A transport error skips the success callback: without this
                    // the loader spins forever and the page reads as hung. Same
                    // supersession rule as the success path, or a delayed
                    // failure from the previous site would erase the list the
                    // newer request just drew.
                    if ( siteId !== currentSite ) {
                        return;
                    }
                    setSnapshotsPlaceholder( snapshotsPlaceholders.error );
                    snapshotsTable.clear().draw();
                    enableSnapshotControls( currentSite > 0 );
                } );
            }

            function setPane( confirming ) {
                if ( confirming ) {
                    $( '#mainwp-restore-reconcile-note' ).hide();
                }
                $( '#mainwp-restore-confirm-pane' ).toggle( confirming );
                $( '#mainwp-restore-progress-pane' ).toggle( ! confirming );
                $( '#mainwp-restore-start-btn' ).toggle( confirming );
            }

            function showProgress( data ) {
                setSnapshotDeleteBusy( ! ( 'completed' === data.status || 'failed_permanent' === data.status || 'cancelled' === data.status ) );
                var isRollback = !! data.is_rollback;
                var label = ( isRollback && rollbackStatusLabels[ data.status ] ) || statusLabels[ data.status ] || data.status;
                if ( 'transferring' === data.status && data.artifact_total > 0 ) {
                    label += ' (' + ( data.artifact_index + 1 ) + '/' + data.artifact_total + ')';
                    if ( data.bytes_total > 0 ) {
                        // Byte-level progress: without it a multi-GB transfer
                        // reads as hung for its whole 10+ minutes.
                        label += ' (' + ( data.bytes_transferred / 1048576 ).toFixed( 1 ) + ' <?php echo esc_js( esc_html__( 'of', 'mainwp' ) ); ?> ' + ( data.bytes_total / 1048576 ).toFixed( 1 ) + ' MB)';
                    }
                }

                var terminal = ( 'completed' === data.status || 'failed_permanent' === data.status || 'cancelled' === data.status );
                var cancelAction = data.cancel_action || '';
                var cancelPending = ! terminal && 'cancel' === cancelAction && !! data.cancel_requested;
                if ( cancelPending ) {
                    label = '<?php echo esc_js( esc_html__( 'Cancelling once the site finishes its current step', 'mainwp' ) ); ?>';
                }
                $( '#mainwp-restore-status-text' ).text( label );

                $( '#mainwp-restore-spinner' ).toggle( ! terminal );
                $( '#mainwp-restore-cancel-btn' ).text( restoreCancelLabels[ cancelAction ] || '' ).toggle( canRestore && ! terminal && ! cancelPending && [ 'cancel', 'stop_checking' ].indexOf( cancelAction ) !== -1 );
                // A rollback job carries the safety backup it restores from, but
                // that backup is not a way back from the rollback itself. A
                // failed rollback still holds it, so it can be tried again.
                var safetyBackup = !! data.has_rollback && ! isRollback;
                var retryRollback = !! data.has_rollback && isRollback;
                $( '#mainwp-restore-cancel-warning' ).toggle( canRestore && 'running' === data.status && ! cancelPending );
                $( '#mainwp-restore-cancel-rollback-note' ).toggle( safetyBackup );
                $( '#mainwp-restore-rollback-btn' ).text( retryRollback ? rollbackActionLabels.retry : rollbackActionLabels.rollBack );
                $( '#mainwp-restore-rollback-btn' ).toggle( canRestore && ! rollbackRequestPending && terminal && ( safetyBackup || retryRollback ) );
                showRestoreOutcomeNote( data, canRestore && ! rollbackRequestPending && terminal && safetyBackup );

                // Surface the safety backup whenever one exists for this run,
                // so the safety net is visible and not implied (L4).
                $( '#mainwp-restore-safety-note' ).toggle( safetyBackup );

                // A phase progress bar across the known sequence (L3). The
                // failed/cancelled states leave it where it stopped.
                var $bar = $( '#mainwp-restore-progress-bar' );
                var idx = phaseOrder.indexOf( data.status );
                if ( 'failed_permanent' === data.status || 'cancelled' === data.status ) {
                    $bar.hide();
                } else if ( idx >= 0 ) {
                    var pct = Math.round( ( idx + 1 ) / phaseOrder.length * 100 );
                    $bar.show().toggleClass( 'success', 'completed' === data.status ).find( '.bar' ).css( 'width', pct + '%' );
                }

                var retryingAbout = '';
                if ( 'failed_retryable' === data.status && data.next_retry_label ) {
                    // A 40-minute backoff without a clock reads as hung; say
                    // when the next attempt comes.
                    retryingAbout = ' <?php echo esc_js( esc_html__( 'Retrying automatically about', 'mainwp' ) ); ?> ' + ( data.next_retry_label || '' ) + '.';
                }

                showReconcileNote( data.verification, data.sub_type, data.is_rollback );
                if ( terminal ) {
                    var $message = $( '#mainwp-restore-result-message' );
                    var rolledBack = 'mainwp_restore_rolled_back' === data.error_code;
                    var rollbackFailed = 'mainwp_restore_rollback_failed' === data.error_code;
                    var outcome = data.error_code ? restoreOutcomeMessages[ data.error_code ] : '';
                    $message.removeClass( 'green red blue' );
                    if ( 'completed' === data.status ) {
                        $message.addClass( 'green' ).text( '<?php echo esc_js( esc_html__( 'The site was restored and responds normally.', 'mainwp' ) ); ?>' );
                        showPreserveNote( data.verification );
                    } else if ( data.verify_stopped ) {
                        $message.addClass( 'red' ).text( restoreCancelLabels.stopped );
                    } else if ( rolledBack && outcome ) {
                        // The restore failed but the database was recovered:
                        // the data is safe, so this reads as information.
                        $message.addClass( 'blue' ).text( outcome );
                    } else if ( rollbackFailed && outcome ) {
                        $message.addClass( 'red' ).text( outcome );
                    } else {
                        $message.addClass( 'red' ).text( data.last_error || label );
                    }
                    $message.show();
                    // The maintenance notice belongs only to the unrecovered
                    // case, where the site is deliberately held offline.
                    $( '#mainwp-restore-maintenance-note' ).toggle( rollbackFailed );
                    // Offer to open the restored site, and let the safety note
                    // stay visible while a rollback is still available (L5).
                    $( '#mainwp-restore-visit-site-wrap' ).toggle( 'completed' === data.status && '' !== currentSiteUrl );
                    if ( 'completed' === data.status && '' !== currentSiteUrl ) {
                        $( '#mainwp-restore-visit-site' ).attr( 'href', currentSiteUrl );
                    }
                    window.clearTimeout( pollTimer );
                } else if ( data.last_error ) {
                    $( '#mainwp-restore-result-message' ).removeClass( 'green' ).addClass( 'red' ).text( data.last_error + retryingAbout ).show();
                } else {
                    $( '#mainwp-restore-result-message' ).hide();
                }
            }

            function showRestoreOutcomeNote( data, showRollback ) {
                var $note = $( '#mainwp-restore-outcome-note' ).text( '' ).hide();
                var terminal = [ 'completed', 'failed_permanent', 'cancelled' ].indexOf( data.status ) !== -1;
                var report = data.restore_outcome;
                if ( ! terminal || 'completed' === data.status || ! report || [ 'mainwp_restore_rolled_back', 'mainwp_restore_rollback_failed' ].indexOf( data.error_code ) !== -1 ) {
                    return;
                }
                if ( ! report.valid ) {
                    $note.text( restoreReportLabels.unknown ).show();
                    return;
                }
                var halves = [ report.files, report.database ].filter( function( value ) { return 'not_applicable' !== value; } );
                if ( halves.every( function( value ) { return 'not_started' === value; } ) ) {
                    $note.text( restoreReportLabels.nothingChanged ).show();
                    return;
                }
                if ( halves.every( function( value ) { return 'applied' === value; } ) ) {
                    return;
                }
                var files = { applied: restoreReportLabels.filesApplied, left_partial: restoreReportLabels.filesPartial, not_started: restoreReportLabels.filesNotStarted };
                var database = { applied: restoreReportLabels.databaseApplied, left_partial: restoreReportLabels.databasePartial, not_started: restoreReportLabels.databaseNotStarted, rolled_back: restoreReportLabels.databaseRolledBack };
                var sentences = [];
                if ( files[ report.files ] ) {
                    sentences.push( files[ report.files ] );
                }
                if ( database[ report.database ] ) {
                    sentences.push( database[ report.database ] );
                }
                if ( showRollback ) {
                    sentences.push( restoreReportLabels.rollBack );
                }
                $note.text( sentences.join( ' ' ) ).show();
            }

            // Report the WooCommerce order-preservation outcome on a completed
            // restore: what was re-added, and a warning when any kept order
            // still points at a customer that could not be brought back.
            function showPreserveNote( verification ) {
                var $note = $( '#mainwp-restore-preserve-note' ).removeClass( 'blue yellow' ).hide();
                if ( ! verification || ! verification.woo_preserve ) {
                    return;
                }
                var p = verification.woo_preserve;
                var restored = parseInt( p.customers_restored, 10 ) || 0;
                var stockApplied = parseInt( p.stock_applied, 10 ) || 0;
                var text = '<?php echo esc_js( esc_html__( 'Live orders were kept. Customer accounts re-added: %1$d. Product stock re-applied: %2$d.', 'mainwp' ) ); ?>';
                text = text.split( '%1$d' ).join( restored ).split( '%2$d' ).join( stockApplied );
                var orphans = ( verification.woo_orphans && parseInt( verification.woo_orphans.orphan_customer_orders, 10 ) ) || 0;
                if ( orphans > 0 ) {
                    text += ' ' + '<?php echo esc_js( esc_html__( '%d order(s) still reference a customer that could not be restored; review them.', 'mainwp' ) ); ?>'.split( '%d' ).join( orphans );
                    $note.addClass( 'yellow' );
                } else {
                    $note.addClass( 'blue' );
                }
                $note.text( text ).show();
            }

            function showReconcileNote( verification, subType, isRollback ) {
                var $note = $( '#mainwp-restore-reconcile-note' ).removeClass( 'blue yellow' ).empty().hide();
                if ( 'db' === subType || isRollback || ! verification || ! verification.reconcile ) {
                    return;
                }
                var reconcile = verification.reconcile;
                if ( 'skipped' === reconcile.status ) {
                    if ( [ 'no_manifest', 'no_walk_params' ].indexOf( reconcile.reason ) !== -1 ) {
                        $note.addClass( 'yellow' ).text( '<?php echo esc_js( esc_html__( 'Files added after this backup were not removed, because the backup has no file list to compare against. Backups made before MainWP Child 6.3-beta1 never have one.', 'mainwp' ) ); ?>' ).show();
                    } else if ( [ 'empty_manifest', 'manifest_unverified' ].indexOf( reconcile.reason ) !== -1 ) {
                        $note.addClass( 'yellow' ).text( '<?php echo esc_js( esc_html__( "Files added after this backup were not removed, because the backup's file list could not be verified.", 'mainwp' ) ); ?>' ).show();
                    } else if ( 'unarchived_invalid' === reconcile.reason ) {
                        $note.addClass( 'yellow' ).text( '<?php echo esc_js( esc_html__( 'Files added after this backup were not removed, because the backup\'s list of files it could not read is invalid.', 'mainwp' ) ); ?>' ).show();
                    } else if ( 'unarchived_overflow' === reconcile.reason ) {
                        $note.addClass( 'yellow' ).text( '<?php echo esc_js( esc_html__( 'Files added after this backup were not removed, because too many files were unreadable when the backup was made.', 'mainwp' ) ); ?>' ).show();
                    } else if ( 'unarchived_unsupported' === reconcile.reason ) {
                        $note.addClass( 'yellow' ).text( '<?php echo esc_js( esc_html__( 'Files added after this backup were not removed, because the site\'s MainWP Child could not confirm it supports keeping files the backup could not read.', 'mainwp' ) ); ?>' ).show();
                    }
                } else if ( 'done' === reconcile.status ) {
                    var removed = parseInt( reconcile.removed, 10 ) || 0;
                    var left = ( parseInt( reconcile.failed, 10 ) || 0 ) + ( parseInt( reconcile.skipped_unsafe, 10 ) || 0 );
                    var text = 0 === removed ? '' : ( 1 === removed
                        ? '<?php /* translators: %d: number of removed files. */ echo esc_js( _n( 'Removed %d file that was added after this backup.', 'Removed %d files that were added after this backup.', 1, 'mainwp' ) ); ?>'
                        : '<?php /* translators: %d: number of removed files. */ echo esc_js( _n( 'Removed %d file that was added after this backup.', 'Removed %d files that were added after this backup.', 2, 'mainwp' ) ); ?>'
                    ).replace( '%d', removed );
                    if ( left > 0 ) {
                        text += ( '' === text ? '' : ' ' ) + ( 1 === left
                            ? '<?php /* translators: %d: number of files that could not be removed. */ echo esc_js( _n( '%d file could not be removed and is still on the site.', '%d files could not be removed and are still on the site.', 1, 'mainwp' ) ); ?>'
                            : '<?php /* translators: %d: number of files that could not be removed. */ echo esc_js( _n( '%d file could not be removed and is still on the site.', '%d files could not be removed and are still on the site.', 2, 'mainwp' ) ); ?>'
                        ).replace( '%d', left );
                    }
                    if ( text ) {
                        $note.addClass( left > 0 ? 'yellow' : 'blue' ).text( text ).show();
                    }
                }
                var kept = parseInt( reconcile.kept, 10 ) || 0;
                if ( kept > 0 ) {
                    var keptByReason = reconcile.kept_by_reason || {};
                    // Older Child backups can hold the target's bytes at a symlink's
                    // path, so kept links stay out of the "no copy" count.
                    var keptLinks = Math.min( kept, parseInt( keptByReason.symlink, 10 ) || 0 );
                    var keptFiles = kept - keptLinks;
                    if ( keptFiles > 0 ) {
                        var keptText = ( 1 === keptFiles
                            ? '<?php /* translators: %d: number of files kept on the site. */ echo esc_js( _n( 'Kept %d file this backup has no copy of.', 'Kept %d files this backup has no copy of.', 1, 'mainwp' ) ); ?>'
                            : '<?php /* translators: %d: number of files kept on the site. */ echo esc_js( _n( 'Kept %d file this backup has no copy of.', 'Kept %d files this backup has no copy of.', 2, 'mainwp' ) ); ?>'
                        ).replace( '%d', keptFiles );
                        var reasons = [];
                        if ( ( parseInt( keptByReason.mu_plugins, 10 ) || 0 ) > 0 ) {
                            reasons.push( '<?php echo esc_js( esc_html__( 'mu-plugins files missing from the backup', 'mainwp' ) ); ?>' );
                        }
                        if ( ( parseInt( keptByReason.unarchived, 10 ) || 0 ) > 0 ) {
                            reasons.push( '<?php echo esc_js( esc_html__( 'files the backup could not read', 'mainwp' ) ); ?>' );
                        }
                        if ( reasons.length ) {
                            keptText += ' ' + '<?php /* translators: %s: reasons files were kept, separated by semicolons. */ echo esc_js( esc_html__( 'Reasons: %s.', 'mainwp' ) ); ?>'.replace( '%s', reasons.join( '; ' ) );
                        }
                        $note.append( $( '<p>' ).text( keptText ) );
                    }
                    if ( keptLinks > 0 ) {
                        $note.append( $( '<p>' ).text( ( 1 === keptLinks
                            ? '<?php /* translators: %d: number of symbolic links left on the site. */ echo esc_js( _n( 'Left %d symbolic link unchanged on the site.', 'Left %d symbolic links unchanged on the site.', 1, 'mainwp' ) ); ?>'
                            : '<?php /* translators: %d: number of symbolic links left on the site. */ echo esc_js( _n( 'Left %d symbolic link unchanged on the site.', 'Left %d symbolic links unchanged on the site.', 2, 'mainwp' ) ); ?>'
                        ).replace( '%d', keptLinks ) ) );
                    }
                    var paths = Array.isArray( reconcile.kept_paths ) ? reconcile.kept_paths.slice( 0, 20 ) : [];
                    if ( paths.length ) {
                        var $paths = $( '<ul>' );
                        paths.forEach( function ( path ) {
                            $paths.append( $( '<li>' ).text( path ) );
                        } );
                        $note.append( $paths );
                    }
                    if ( ( parseInt( keptByReason.mu_plugins, 10 ) || 0 ) > 0 ) {
                        // A file dropped into mu-plugins after the backup is kept too, malicious or not.
                        $note.append( $( '<p>' ).text( '<?php echo esc_js( esc_html__( 'Review the kept mu-plugins files if you did not add them.', 'mainwp' ) ); ?>' ) );
                    }
                    if ( kept > paths.length ) {
                        var sampleText = '<?php /* translators: 1: number of sample paths shown, 2: total files kept. */ echo esc_js( esc_html__( 'Showing %1$d of %2$d kept files.', 'mainwp' ) ); ?>';
                        $note.append( $( '<p>' ).text( sampleText.replace( '%1$d', paths.length ).replace( '%2$d', kept ) ) );
                    }
                    if ( ! $note.hasClass( 'yellow' ) ) {
                        $note.addClass( 'blue' );
                    }
                    $note.show();
                }
            }

            function poll() {
                $.post( ajaxurl, { action: 'mainwp_restore_progress', security: nonces.progress, job_id: currentJob }, function ( data ) {
                    restorePollRetries = 0;
                    // A security or job-lookup refusal arrives as HTTP 200
                    // with an error body and no status field (the handler
                    // never sends both). Without this guard the loop would
                    // reschedule forever over a garbled label.
                    if ( data && data.error && ! data.status ) {
                        $( '#mainwp-restore-result-message' ).removeClass( 'green blue' ).addClass( 'red' ).text( data.error ).show();
                        $( '#mainwp-restore-spinner' ).hide();
                        window.clearTimeout( pollTimer );
                        return;
                    }
                    showProgress( data );
                    if ( [ 'completed', 'failed_permanent', 'cancelled' ].indexOf( data.status ) === -1 ) {
                        pollTimer = window.setTimeout( poll, 5000 );
                    } else if ( 'completed' === data.status || 'failed_permanent' === data.status || 'cancelled' === data.status ) {
                        loadSnapshots( currentSite );
                    }
                }, 'json' ).fail( function () {
                    // A transport error (lost connection, timeout, a 500)
                    // skips the success callback. Each foreground poll also
                    // drives a work slice, so a silently dead poller slows
                    // the restore to the cron cadence - retry harder than
                    // the backup poller before giving the tab up.
                    restorePollRetries++;
                    if ( restorePollRetries <= 5 ) {
                        $( '#mainwp-restore-result-message' ).removeClass( 'green blue' ).addClass( 'red' ).text( '<?php echo esc_js( esc_html__( 'Lost the connection to the dashboard while following the restore. Retrying.', 'mainwp' ) ); ?>' ).show();
                        pollTimer = window.setTimeout( poll, 3000 );
                        return;
                    }
                    $( '#mainwp-restore-result-message' ).removeClass( 'green blue' ).addClass( 'red' ).text( '<?php echo esc_js( esc_html__( 'Lost the connection to the dashboard. The restore continues on the dashboard\'s schedule; reload this page to follow it again.', 'mainwp' ) ); ?>' ).show();
                    $( '#mainwp-restore-spinner' ).hide();
                    window.clearTimeout( pollTimer );
                } );
            }

            function startJobFollow( jobId ) {
                setSnapshotDeleteBusy( true );
                currentJob = jobId;
                restorePollRetries = 0;
                setPane( false );
                $( '#mainwp-restore-outcome-note' ).hide();
                $( '#mainwp-restore-result-message' ).hide();
                $( '#mainwp-restore-reconcile-note' ).hide();
                $( '#mainwp-restore-maintenance-note' ).hide();
                poll();
            }

            // Fomantic returns the wrapper div for a select it has already
            // transformed (the admin theme's global init gets there first),
            // so a change handler chained off .dropdown() binds to the div,
            // where val() reads empty and the selection goes nowhere. Bind
            // the handler to the select itself; Fomantic fires change on it.
            $( '#mainwp-restore-site-selector' ).dropdown();
            $( '#mainwp-restore-site-selector' ).on( 'change', function () {
                var siteId = parseInt( $( this ).val(), 10 );
                if ( siteId > 0 ) {
                    if ( siteId !== currentSite ) {
                        // The backup-now follow belongs to the site it
                        // started on: stop its timers and drop its since
                        // baseline so nothing pairs the new site's id with
                        // the old click's state. loadSnapshots resumes the
                        // new site's own run if one is in flight.
                        stopBackupTimers();
                        backupSinceTs = 0;
                        backupProgressCache = null;
                        backupStartTime = null;
                        setBackupStatus( '', false );
                        showCancelBackup( false );
                        reenableBackupBtn();
                    }
                    loadSnapshots( siteId );
                }
            } );

            // Initialise the modal once. Re-initialising on every open (the
            // former `.modal({...}).modal('show')` chain) re-ran setup against an
            // in-flight open/close transition and threw InvalidStateError; a single
            // init plus a guarded show keeps the transition state consistent (M5).
            $( '#mainwp-snapshot-delete-modal' ).modal( { closable: false } );
            $( '#mainwp-snapshot-delete-modal .ui.checkbox' ).checkbox();
            $( '#mainwp-restore-snapshots-table' ).on( 'click', '.mainwp-delete-snapshot', function ( event ) {
                event.preventDefault();
                if ( snapshotDeleteBusy || $( this ).hasClass( 'disabled' ) ) {
                    return;
                }
                snapshotDeleteTarget = parseInt( $( this ).attr( 'data-snapshot' ), 10 );
                $( '#mainwp-snapshot-delete-ids, #mainwp-snapshot-delete-warnings, #mainwp-snapshot-delete-result' ).empty();
                $( '#mainwp-snapshot-delete-result' ).removeClass( 'red' ).hide();
                $( '#mainwp-snapshot-delete-modal' ).modal( 'show' );
                requestDeletePreview();
            } );
            $( '#mainwp-snapshot-delete-cloud' ).on( 'change', function () {
                requestDeletePreview( $( this ).is( ':checked' ) );
            } );
            $( '#mainwp-snapshot-delete-close' ).on( 'click', function () {
                ++snapshotDeleteRequest;
                snapshotDeletePreview = null;
                $( '#mainwp-snapshot-delete-modal' ).modal( 'hide' );
            } );
            $( '#mainwp-snapshot-delete-confirm' ).on( 'click', function () {
                if ( ! snapshotDeletePreview || ! snapshotDeletePreview.can_proceed || $( this ).prop( 'disabled' ) || snapshotDeleteBusy ) {
                    return;
                }
                var effect = snapshotDeletePreview.would_affect;
                var siteId = effect.site_id;
                snapshotDeletePreview = null;
                $( this ).prop( 'disabled', true ).addClass( 'disabled' );
                $( '#mainwp-snapshot-delete-cloud, #mainwp-snapshot-delete-close' ).prop( 'disabled', true );
                $.post( ajaxurl, {
                    action: 'mainwp_backup_snapshot_delete', security: nonces.deleteConfirm,
                    site_id: siteId, snapshot_id: effect.snapshot_id,
                    expected_snapshot_ids: effect.snapshot_ids,
                    delete_cloud_copies: effect.delete_cloud_copies ? 1 : 0
                }, function ( response ) {
                    if ( siteId !== currentSite ) {
                        return;
                    }
                    if ( ! response || response.error ) {
                        var error = response && response.error ? response.error : deleteLabels.failed;
                        setDeleteMessages( '#mainwp-snapshot-delete-result', [ error ] );
                        requestDeletePreview( effect.delete_cloud_copies, [ error ] );
                        return;
                    }
                    showDeleteResponse( response );
                    loadSnapshots( siteId );
                }, 'json' ).fail( function () {
                    if ( siteId === currentSite ) {
                        setDeleteMessages( '#mainwp-snapshot-delete-result', [ deleteLabels.failed ] );
                        requestDeletePreview( effect.delete_cloud_copies, [ deleteLabels.failed ] );
                    }
                } ).always( function () {
                    $( '#mainwp-snapshot-delete-cloud, #mainwp-snapshot-delete-close' ).prop( 'disabled', false );
                } );
            } );

            $( '#mainwp-restore-modal' ).modal( { closable: false } );

            $( document ).on( 'click', '.mainwp-restore-snapshot', function ( event ) {
                event.preventDefault();
                currentSnapshot = parseInt( $( this ).data( 'snapshot' ), 10 );
                var snapType = $( this ).data( 'snapshot-type' );
                var snapAge = parseInt( $( this ).data( 'snapshot-age' ), 10 ) || 0;
                if ( $( this ).hasClass( 'disabled' ) ) {
                    return;
                }
                var warningText = restoreWarnings[ snapType ] || restoreWarnings['default'];
                if ( siteIsMultisite ) {
                    warningText += ' ' + networkWarning;
                }
                if ( siteHasWooCommerce && 'files' !== snapType && snapAge > wooMaxAgeDays ) {
                    warningText += ' ' + wooAgeWarning.replace( '%d', snapAge );
                }
                $( '#mainwp-restore-warning-text' ).text( warningText );
                // The safety backup is always a full backup, so a database-only
                // restore must not promise it only covers files.
                $( 'label[for="mainwp-restore-presnapshot"]' ).text( 'db' === snapType ? safetyBackupLabels.db : safetyBackupLabels['default'] );
                $( '#mainwp-restore-consent' ).prop( 'checked', false );
                $( '#mainwp-restore-start-btn' ).addClass( 'disabled' );
                // The keep-orders option only applies to a WooCommerce store
                // restoring a database-bearing snapshot. The child enforces the
                // supported configuration; this just offers the opt-in.
                var showPreserve = siteHasWooCommerce && 'files' !== snapType;
                $( '#mainwp-restore-preserve-orders-box' ).toggle( showPreserve );
                if ( ! showPreserve ) {
                    $( '#mainwp-restore-preserve-orders' ).prop( 'checked', false );
                }
                setPane( true );
                if ( ! $( '#mainwp-restore-modal' ).modal( 'is active' ) ) {
                    $( '#mainwp-restore-modal' ).modal( 'show' );
                }
            } );

            $( '#mainwp-restore-consent' ).on( 'change', function () {
                $( '#mainwp-restore-start-btn' ).toggleClass( 'disabled', ! this.checked );
            } );

            $( '#mainwp-restore-start-btn' ).on( 'click', function () {
                if ( $( this ).hasClass( 'disabled' ) ) {
                    return;
                }
                $( this ).addClass( 'disabled' ); // Block a second submit while this request is in flight.
                $.post( ajaxurl, {
                    action: 'mainwp_restore_initiate',
                    security: nonces.initiate,
                    site_id: currentSite,
                    snapshot_id: currentSnapshot,
                    pre_snapshot: $( '#mainwp-restore-presnapshot' ).is( ':checked' ) ? 1 : 0,
                    confirm_destructive: $( '#mainwp-restore-consent' ).is( ':checked' ) ? 1 : 0,
                    preserve_orders: $( '#mainwp-restore-preserve-orders' ).is( ':checked' ) ? 1 : 0
                }, function ( response ) {
                    if ( response.needs_confirmation ) {
                        setPane( true );
                        $( '#mainwp-restore-consent' ).prop( 'checked', false );
                        $( '#mainwp-restore-start-btn' ).addClass( 'disabled' );
                        $( '#mainwp-restore-result-message' ).addClass( 'red' ).text( '<?php echo esc_js( esc_html__( 'This restore needs confirmation. Review the warning and check the box to continue.', 'mainwp' ) ); ?>' ).show();
                        return;
                    }
                    if ( response.error ) {
                        setPane( false );
                        $( '#mainwp-restore-spinner' ).hide();
                        $( '#mainwp-restore-status-text' ).text( '' );
                        $( '#mainwp-restore-result-message' ).addClass( 'red' ).text( response.error ).show();
                        return;
                    }
                    startJobFollow( response.job_id );
                }, 'json' );
            } );

            $( '#mainwp-restore-cancel-btn' ).on( 'click', function () {
                $.post( ajaxurl, { action: 'mainwp_restore_cancel', security: nonces.cancel, job_id: currentJob }, function ( response ) {
                    if ( response.error ) {
                        $( '#mainwp-restore-result-message' ).addClass( 'red' ).text( 'mainwp_restore_checking_changed' === response.error_code ? restoreCancelLabels.checking : response.error ).show();
                    } else if ( 'stopped' === response.result ) {
                        window.clearTimeout( pollTimer );
                        poll();
                    }
                }, 'json' );
            } );

            function startRollback( jobId ) {
                if ( rollbackRequestPending ) {
                    return;
                }
                rollbackRequestPending = true;
                currentJob = jobId;
                setPane( false );
                $( '#mainwp-restore-rollback-btn' ).prop( 'disabled', true ).hide();
                $( '.mainwp-restore-failed-job' ).prop( 'disabled', true );
                $( '#mainwp-restore-result-message' ).hide();
                function rollbackRefused( message, retryable ) {
                    $( '#mainwp-restore-spinner' ).hide();
                    $( '#mainwp-restore-status-text' ).text( '' );
                    $( '#mainwp-restore-rollback-btn' ).toggle( retryable === true );
                    $( '#mainwp-restore-result-message' ).removeClass( 'green blue' ).addClass( 'red' ).text( message ).show();
                    if ( retryable !== true ) {
                        currentJob = 0;
                    }
                    loadSnapshots( currentSite );
                }
                function rollbackResponseLost() {
                    rollbackRefused( '<?php echo esc_js( __( 'The rollback request got no answer. Reloading the list to show where it stands.', 'mainwp' ) ); ?>', false );
                }
                $.post( ajaxurl, { action: 'mainwp_restore_rollback', security: nonces.rollback, job_id: jobId }, function ( response ) {
                    if ( response && response.error ) {
                        rollbackRefused( response.error, response.retryable );
                        return;
                    }
                    if ( ! response || ! response.job_id ) {
                        rollbackResponseLost();
                        return;
                    }
                    startJobFollow( response.job_id );
                    loadSnapshots( currentSite );
                }, 'json' ).fail( rollbackResponseLost ).always( function () {
                    rollbackRequestPending = false;
                    $( '#mainwp-restore-rollback-btn' ).prop( 'disabled', false );
                } );
            }

            $( '#mainwp-restore-rollback-btn' ).on( 'click', function () {
                startRollback( currentJob );
            } );

            $( '#mainwp-restore-failed-jobs' ).on( 'click', '.mainwp-restore-failed-job', function () {
                var jobId = parseInt( $( this ).attr( 'data-job-id' ), 10 );
                if ( ! $( '#mainwp-restore-modal' ).modal( 'is active' ) ) {
                    $( '#mainwp-restore-modal' ).modal( 'show' );
                }
                startRollback( jobId );
            } );

            $( '#mainwp-restore-close-btn' ).on( 'click', function () {
                window.clearTimeout( pollTimer );
            } );

            // On-demand "Back Up Now": kick the native backup, then poll the
            // same way the restore flow does and refresh the list on success.
            function setBackupStatus( text, isError ) {
                $( '#mainwp-native-backup-now-status' ).text( text ).toggleClass( 'ui red text', !! isError );
            }

            function reenableBackupBtn() {
                $( '#mainwp-native-backup-now-btn' ).prop( 'disabled', siteDisconnected ).toggleClass( 'disabled', siteDisconnected );
            }

            // Offer "Cancel backup" while a run is in flight, or after the child
            // refuses a new backup because a prior task is still stuck on it.
            function showCancelBackup( show ) {
                $( '#mainwp-native-backup-cancel-btn' ).css( 'display', show ? '' : 'none' ).prop( 'disabled', false ).removeClass( 'disabled' );
            }

            // Cancel only applies when the blocker is an active or stuck backup
            // (the child's "already running / try again" refusal). Any other
            // initiate error - native backups disabled, a restore in flight, an
            // authorization refusal - must hide the button, so the one
            // destructive control never targets a run the user did not start.
            function cancelApplies( message ) {
                var msg = message || '';
                if ( /restore/i.test( msg ) ) {
                    return false; // A restore holds the slot; cancelling a backup does not apply.
                }
                return /in progress|try again|already/i.test( msg );
            }

            // Resume following an on-demand run this tab did not start (a
            // reload, or a site switch onto a site mid-backup). No `since`
            // is available for a resumed view, so the poll judges the
            // current run by its own last_run - exactly what a viewer who
            // did not click wants to see.
            function resumeBackupFollow() {
                setSnapshotDeleteBusy( true );
                $( '#mainwp-native-backup-now-btn' ).prop( 'disabled', true ).addClass( 'disabled' );
                showCancelBackup( true );
                stopBackupTimers();
                backupSinceTs = 0;
                backupStartTime = Date.now();
                backupLastPhase = null;
                backupPhaseStartTime = null;
                backupPhaseStartPercent = 0;
                backupProgressCache = { label: '<?php echo esc_js( esc_html__( 'Backing up...', 'mainwp' ) ); ?>', percent: null, eta: '' };
                backupElapsedTimer = window.setInterval( renderBackupStatus, 1000 );
                var siteId = currentSite;
                backupPollTimer = window.setTimeout( function () { pollBackup( siteId, 0 ); }, 100 );
            }

            // The whole poll chain is keyed to the site and since captured
            // when the follow started: a site switch mid-flight must not
            // let a response for the old site repaint the new site's page,
            // and must never pair the new site's id with the old click's
            // since timestamp (the server would claim a run on the wrong
            // site with it).
            function pollBackup( siteId, sinceTs ) {
                // `since` is the started value the initiate response carried: the
                // server judges snapshot freshness against this click's own run,
                // so a later re-init bumping last_run cannot flip a completed
                // backup into a false failure.
                $.post( ajaxurl, { action: 'mainwp_native_backup_progress', security: nonces.nativeBackupProgress, site_id: siteId, since: sinceTs }, function ( data ) {
                    if ( siteId !== currentSite ) {
                        return; // The page moved to another site; this response is nobody's.
                    }
                    backupPollRetries = 0; // A response arrived: clear the transient-failure count.
                    if ( data.error ) {
                        stopBackupTimers();
                        setBackupStatus( data.error, true );
                        reenableBackupBtn();
                        loadSnapshots( siteId );
                        return;
                    }
                    if ( 'completed' === data.status ) {
                        stopBackupTimers();
                        // An advisory note (like the large-site ZIP auto-switch)
                        // rides along with the success status; show it, never
                        // dress it up as a failure.
                        var completeMsg = '<?php echo esc_js( esc_html__( 'Backup complete.', 'mainwp' ) ); ?>';
                        if ( data.notice ) {
                            completeMsg += ' ' + data.notice;
                        }
                        setBackupStatus( completeMsg, false );
                        reenableBackupBtn();
                        showCancelBackup( false );
                        loadSnapshots( siteId );
                        return;
                    }
                    if ( 'failed' === data.status ) {
                        stopBackupTimers();
                        setBackupStatus( data.error || '<?php echo esc_js( esc_html__( 'Backup failed.', 'mainwp' ) ); ?>', true );
                        reenableBackupBtn();
                        showCancelBackup( false );
                        loadSnapshots( siteId );
                        return;
                    }

                    // Running: refresh the cached fields and let the 1s renderer
                    // paint them. The human label comes from the server; the raw
                    // phase string is used only to reset the per-phase ETA.
                    var label = data.label || '<?php echo esc_js( esc_html__( 'Backing up...', 'mainwp' ) ); ?>';
                    if ( 'queued' === data.status ) {
                        label = '<?php echo esc_js( esc_html__( 'Queued; another backup is running. This one starts when it finishes. Keep this tab open until then.', 'mainwp' ) ); ?>';
                    }
                    var pct = ( data.percent !== null && data.percent !== undefined ) ? data.percent : null;

                    if ( data.phase !== backupLastPhase ) {
                        backupLastPhase = data.phase;
                        backupPhaseStartTime = Date.now();
                        backupPhaseStartPercent = ( pct !== null ) ? pct : 0;
                    }

                    var eta = '';
                    if ( pct !== null && pct > 0 && pct < 100 && backupPhaseStartTime ) {
                        var denom = pct - backupPhaseStartPercent;
                        if ( denom > 0 ) {
                            eta = '~' + fmtClock( ( Date.now() - backupPhaseStartTime ) * ( 100 - pct ) / denom ) + ' <?php echo esc_js( esc_html__( 'remaining', 'mainwp' ) ); ?>';
                        }
                    }

                    backupProgressCache = { label: label, percent: pct, eta: eta };
                    renderBackupStatus();
                    backupPollTimer = window.setTimeout( function () { pollBackup( siteId, sinceTs ); }, 5000 );
                }, 'json' ).fail( function () {
                    if ( siteId !== currentSite ) {
                        return; // A stale transport failure for a site no longer shown.
                    }
                    // A transport error (lost connection, timeout, a 503 while the
                    // child is in maintenance) skips the success callback. Retry a
                    // few times so a blip does not abandon a running backup, then
                    // stop the clock and let the operator reload rather than leave
                    // the timer ticking and the button stuck.
                    backupPollRetries++;
                    if ( backupPollRetries <= 3 ) {
                        backupPollTimer = window.setTimeout( function () { pollBackup( siteId, sinceTs ); }, 3000 );
                        return;
                    }
                    stopBackupTimers();
                    setBackupStatus( '<?php echo esc_js( esc_html__( 'Lost the connection while backing up. The backup may still be running; reload to check.', 'mainwp' ) ); ?>', true );
                    reenableBackupBtn();
                    loadSnapshots( siteId );
                } );
            }

            $( '#mainwp-native-backup-now-btn' ).on( 'click', function () {
                if ( $( this ).prop( 'disabled' ) || $( this ).hasClass( 'disabled' ) || ! ( currentSite > 0 ) ) {
                    return;
                }
                // Capture the target site now: every request and timer this
                // click spawns is keyed to it, so a selection change can
                // never redirect this run's follow-up at another site.
                var siteId = currentSite;
                setSnapshotDeleteBusy( true );
                $( this ).prop( 'disabled', true ).addClass( 'disabled' );
                showCancelBackup( true );
                stopBackupTimers();
                backupStartTime = Date.now();
                backupLastPhase = null;
                backupPhaseStartTime = null;
                backupPhaseStartPercent = 0;
                backupProgressCache = { label: '<?php echo esc_js( esc_html__( 'Starting backup...', 'mainwp' ) ); ?>', percent: null, eta: '' };
                backupElapsedTimer = window.setInterval( renderBackupStatus, 1000 );
                setBackupStatus( '<?php echo esc_js( esc_html__( 'Starting backup...', 'mainwp' ) ); ?>', false );
                $.post( ajaxurl, { action: 'mainwp_native_backup_initiate', security: nonces.nativeBackupInitiate, site_id: siteId }, function ( response ) {
                    if ( siteId !== currentSite ) {
                        return; // The page moved on; the run continues server-side.
                    }
                    if ( response.error ) {
                        stopBackupTimers();
                        setBackupStatus( response.error, true );
                        reenableBackupBtn();
                        loadSnapshots( siteId );
                        // Keep the Cancel affordance only when the blocker is an
                        // active or stuck backup; hide it for every other error.
                        showCancelBackup( cancelApplies( response.error ) );
                        return;
                    }
                    var sinceTs = response.started || 0;
                    backupSinceTs = sinceTs;
                    backupPollTimer = window.setTimeout( function () { pollBackup( siteId, sinceTs ); }, 1500 );
                }, 'json' ).fail( function () {
                    if ( siteId !== currentSite ) {
                        return;
                    }
                    // The kickoff request never reached the handler, so nothing
                    // started: stop the clock, report it, and re-enable the button.
                    stopBackupTimers();
                    setBackupStatus( '<?php echo esc_js( esc_html__( 'Could not start the backup. Please try again.', 'mainwp' ) ); ?>', true );
                    reenableBackupBtn();
                    showCancelBackup( false );
                    loadSnapshots( siteId );
                } );
            } );

            // Cancel/clear a stuck or unwanted backup. Tells the child to drive
            // its active backup task terminal so a new backup is no longer blocked.
            $( '#mainwp-native-backup-cancel-btn' ).on( 'click', function () {
                if ( $( this ).prop( 'disabled' ) || ! ( currentSite > 0 ) ) {
                    return;
                }
                // The cancel targets the site shown at click time; a
                // selection change while the request is in flight must not
                // let its response repaint another site's controls.
                var siteId = currentSite;
                $( this ).prop( 'disabled', true ).addClass( 'disabled' );
                setBackupStatus( '<?php echo esc_js( esc_html__( 'Cancelling backup...', 'mainwp' ) ); ?>', false );
                $.post( ajaxurl, { action: 'mainwp_native_backup_cancel', security: nonces.nativeBackupCancel, site_id: siteId }, function ( response ) {
                    if ( siteId !== currentSite ) {
                        return;
                    }
                    if ( response.error ) {
                        setBackupStatus( response.error, true );
                        $( '#mainwp-native-backup-cancel-btn' ).prop( 'disabled', false ).removeClass( 'disabled' );
                        return;
                    }
                    var cancelResult = window.MainWPBackupCancelResult.describeCancelResult( response.result );
                    setBackupStatus( cancelResult.message, false );
                    if ( 'cancelled' === response.result ) {
                        stopBackupTimers();
                        showCancelBackup( false );
                        reenableBackupBtn();
                        loadSnapshots( siteId );
                    } else {
                        if ( 'already_finished' === response.result || 'already_failed' === response.result ) {
                            window.clearTimeout( backupPollTimer );
                            backupPollTimer = window.setTimeout( function () { pollBackup( siteId, backupSinceTs ); }, 100 );
                        }
                        if ( ! cancelResult.keepPolling ) {
                            stopBackupTimers();
                        }
                        $( '#mainwp-native-backup-cancel-btn' ).prop( 'disabled', false ).removeClass( 'disabled' );
                    }
                }, 'json' ).fail( function () {
                    if ( siteId !== currentSite ) {
                        return;
                    }
                    setBackupStatus( '<?php echo esc_js( esc_html__( 'Could not cancel the backup. Try again.', 'mainwp' ) ); ?>', true );
                    $( '#mainwp-native-backup-cancel-btn' ).prop( 'disabled', false ).removeClass( 'disabled' );
                } );
            } );

            $( '#mainwp-snapshots-cleanup-btn' ).on( 'click', function () {
                if ( $( this ).hasClass( 'disabled' ) || ! ( currentSite > 0 ) ) {
                    return;
                }
                var runCleanup = function () {
                    $( '#mainwp-snapshots-cleanup-btn' ).prop( 'disabled', true ).addClass( 'disabled' );
                    setBackupStatus( '<?php echo esc_js( esc_html__( 'Cleaning up backups...', 'mainwp' ) ); ?>', false );
                    $.post( ajaxurl, { action: 'mainwp_snapshots_cleanup', security: nonces.snapshotsCleanup, site_id: currentSite }, function ( response ) {
                        $( '#mainwp-snapshots-cleanup-btn' ).prop( 'disabled', false ).removeClass( 'disabled' );
                        if ( ! response || response.error ) {
                            setBackupStatus( ( response && response.error ) ? response.error : '<?php echo esc_js( esc_html__( 'Cleanup failed. Please try again.', 'mainwp' ) ); ?>', true );
                            return;
                        }
                        var summary = '<?php echo esc_js( esc_html__( 'Cleanup finished: removed %1$s leftover folder(s), pruned %2$s old backup(s).', 'mainwp' ) ); ?>'
                            .replace( '%1$s', String( parseInt( response.orphan_dirs_removed, 10 ) || 0 ) )
                            .replace( '%2$s', String( parseInt( response.snapshots_pruned, 10 ) || 0 ) );
                        var orphanedPruned = parseInt( response.orphaned_snapshots_pruned, 10 ) || 0;
                        if ( orphanedPruned > 0 ) {
                            summary += ' ' + '<?php /* translators: %s: number of pruned snapshots that belonged to deleted backup tasks. */ echo esc_js( esc_html__( '%s of them belonged to deleted backup tasks.', 'mainwp' ) ); ?>'.replace( '%s', String( orphanedPruned ) );
                        }
                        if ( response.bytes_pruned_label ) {
                            summary += ' ' + '<?php /* translators: %s: total size of the pruned backups, e.g. "1.2 GB". */ echo esc_js( esc_html__( 'Space freed: %s.', 'mainwp' ) ); ?>'.replace( '%s', String( response.bytes_pruned_label ) );
                        }
                        if ( response.errors && response.errors.length ) {
                            summary += ' <?php echo esc_js( esc_html__( 'Some items could not be removed.', 'mainwp' ) ); ?>';
                        }
                        setBackupStatus( summary, !! ( response.errors && response.errors.length ) );
                        loadSnapshots( currentSite );
                    }, 'json' ).fail( function () {
                        $( '#mainwp-snapshots-cleanup-btn' ).prop( 'disabled', false ).removeClass( 'disabled' );
                        setBackupStatus( '<?php echo esc_js( esc_html__( 'Cleanup failed. Please try again.', 'mainwp' ) ); ?>', true );
                    } );
                };
                var confirmMsg = '<?php echo esc_js( esc_html__( 'Clean up this site\'s backups? Leftover files from interrupted backups are removed, and old on-demand backups beyond the retention count are pruned. Clean Up also deletes snapshots whose backup task was deleted, including their cloud copies. Pruned and deleted backups can no longer be restored.', 'mainwp' ) ); ?>';
                mainwp_confirm( confirmMsg, runCleanup );
            } );

            if ( currentSite > 0 ) {
                loadSnapshots( currentSite );
            }
        } );
        </script>
        <?php
    }
}
