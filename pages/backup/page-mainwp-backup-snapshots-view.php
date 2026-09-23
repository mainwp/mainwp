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
        ?>
        <div id="mainwp-backup-snapshots" class="ui alt segment">
            <div class="mainwp-main-content">
                <?php if ( $locked ) : ?>
                    <h3 class="ui dividing header" style="scroll-margin-top: 6em; padding-top: .5em;"><?php esc_html_e( 'Snapshots & Restore', 'mainwp' ); ?></h3>
                <?php endif; ?>
                <div class="ui form">
                    <?php if ( ! $locked ) : ?>
                    <div class="inline field">
                        <label for="mainwp-restore-site-selector"><?php esc_html_e( 'Site', 'mainwp' ); ?></label>
                        <select id="mainwp-restore-site-selector" class="ui search selection dropdown">
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
                    </div>
                    <?php endif; ?>

                    <?php
                    $test_restore_notice = MainWP_Backup_TestRestore_Scheduler::instance()->get_capability_notice();
                    if ( ! $locked && '' !== $test_restore_notice ) :
                        ?>
                    <div class="ui small info message">
                        <i class="info circle icon"></i>
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
                    <div class="ui toggle checkbox" style="margin-bottom: 1em;">
                        <input type="checkbox" id="mainwp-snapshots-show-removed">
                        <label for="mainwp-snapshots-show-removed"><?php esc_html_e( 'Show removed backups', 'mainwp' ); ?></label>
                    </div>

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
                </div>

                <?php if ( MainWP_Backup_Settings::is_native_backups_enabled() ) : ?>
                <div class="mainwp-native-backup-now-row" style="margin-top: 1em;">
                    <button type="button" class="ui green button<?php echo $site_id > 0 ? '' : ' disabled'; ?>" id="mainwp-native-backup-now-btn" <?php disabled( 0, $site_id ); ?>>
                        <i class="cloud upload alternate icon"></i><?php esc_html_e( 'Back Up Now', 'mainwp' ); ?>
                    </button>
                    <button type="button" class="ui button" id="mainwp-native-backup-cancel-btn" style="display: none;">
                        <i class="times icon"></i><?php esc_html_e( 'Cancel backup', 'mainwp' ); ?>
                    </button>
                    <button type="button" class="ui basic button<?php echo $site_id > 0 ? '' : ' disabled'; ?>" id="mainwp-snapshots-cleanup-btn" <?php disabled( 0, $site_id ); ?>>
                        <i class="broom icon"></i><?php esc_html_e( 'Clean Up', 'mainwp' ); ?>
                    </button>
                    <span id="mainwp-native-backup-now-status" class="mainwp-native-backup-now-status" aria-live="polite" style="margin-left: 1em; color: var(--mainwp-text-color, inherit);"></span>
                </div>
                    <?php
                    $manual_ids = $site_id > 0 ? MainWP_DB_Backup::instance()->manual_backup_task_destinations( $site_id ) : array();
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
                    $destinations_note     = sprintf(
                        /* translators: %s: comma-separated storage destination names. */
                        __( 'Back Up Now saves this backup to the destinations of the scheduled backup tasks that include this site: %s.', 'mainwp' ),
                        implode( ', ', $manual_destinations )
                    );
                    ?>
                <div id="mainwp-native-backup-now-note" class="mainwp-native-backup-now-note" data-local-only="<?php echo $backup_now_local_only ? '1' : '0'; ?>" style="margin-top: .5em; opacity: .8;<?php echo $site_id > 0 ? '' : ' display: none;'; ?>">
                    <i class="info circle icon"></i><span class="mainwp-backup-local-note"<?php echo $backup_now_local_only ? '' : ' style="display: none;"'; ?>><?php esc_html_e( 'No backup destination is configured for this site, so this backup will be kept on the dashboard.', 'mainwp' ); ?></span>
                    <span class="mainwp-backup-destinations-note"<?php echo $backup_now_local_only ? ' style="display: none;"' : ''; ?>><?php echo esc_html( $destinations_note ); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="ui small modal" id="mainwp-restore-modal">
            <div class="header"><?php esc_html_e( 'Restore Backup', 'mainwp' ); ?></div>
            <div class="content">
                <div id="mainwp-restore-confirm-pane">
                    <div class="ui yellow message">
                        <i class="warning circle icon"></i>
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
                    <div class="ui small yellow message" id="mainwp-restore-cancel-warning" style="display:none;"><?php esc_html_e( 'Cancelling now stops the restore while the site is mid-change. Files already copied stay in place and the previous database tables are kept aside, so a rollback from the safety backup remains available if the site looks wrong afterwards.', 'mainwp' ); ?></div>
                    <div class="ui message" id="mainwp-restore-result-message" style="display:none;"></div>
                    <div class="ui small message" id="mainwp-restore-preserve-note" style="display:none;"></div>
                    <div class="ui small message" id="mainwp-restore-reconcile-note" style="display:none;"></div>
                    <div class="ui small red message" id="mainwp-restore-maintenance-note" style="display:none;"><?php esc_html_e( 'The site is in maintenance mode until the database is checked, so it will not serve visitors until then.', 'mainwp' ); ?></div>
                    <div id="mainwp-restore-visit-site-wrap" style="display:none;margin-top:.5em;"><a id="mainwp-restore-visit-site" href="#" target="_blank" rel="noopener" class="ui tiny basic button"><i class="external alternate icon"></i><?php esc_html_e( 'Visit site', 'mainwp' ); ?></a></div>
                </div>
            </div>
            <div class="actions">
                <button type="button" class="ui cancel button" id="mainwp-restore-close-btn"><?php esc_html_e( 'Close', 'mainwp' ); ?></button>
                <button type="button" class="ui button" id="mainwp-restore-cancel-btn" style="display:none;"><?php esc_html_e( 'Cancel Restore', 'mainwp' ); ?></button>
                <button type="button" class="ui button" id="mainwp-restore-rollback-btn" style="display:none;"><?php esc_html_e( 'Roll Back', 'mainwp' ); ?></button>
                <button type="button" class="ui green disabled button" id="mainwp-restore-start-btn"><i class="undo icon"></i><?php esc_html_e( 'Start Restore', 'mainwp' ); ?></button>
            </div>
        </div>
        <?php
        static::render_script( $site_id );
    }

    /**
     * Inline page script: list loading, the confirm flow, progress
     * polling, cancel, and rollback.
     *
     * @param int $site_id Preselected site id.
     */
    private static function render_script( int $site_id ): void { // phpcs:ignore -- NOSONAR - page script.
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
            var backupTypeLabels = <?php echo wp_json_encode( $type_labels ); ?>;
            var currentSite = <?php echo (int) $site_id; ?>;
            var snapshotsTable = null;
            var showRemovedSnapshots = false;
            var currentSnapshot = 0;
            var currentJob = 0;
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
                'mainwp_restore_rolled_back': '<?php echo esc_js( esc_html__( 'The restore could not finish, so the database was returned to how it was before the restore started. No data was lost. Check the site, then try the restore again.', 'mainwp' ) ); ?>',
                'mainwp_restore_rollback_failed': '<?php echo esc_js( esc_html__( 'The restore could not finish and the database could not be returned to its previous state automatically, so it may be in a partial state. Have your host or a database administrator check the database before bringing the site back online.', 'mainwp' ) ); ?>'
            };

            var sourceLabels = {
                'local': '<?php echo esc_js( esc_html__( 'This dashboard', 'mainwp' ) ); ?>',
                'remote': '<?php echo esc_js( esc_html__( 'Cloud storage', 'mainwp' ) ); ?>',
                'remote_chunked': '<?php echo esc_js( esc_html__( 'Cloud storage', 'mainwp' ) ); ?>',
                'unavailable': '<?php echo esc_js( esc_html__( 'Removed by retention', 'mainwp' ) ); ?>'
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
                $( '#mainwp-native-backup-now-status' ).text( parts.join( ' ' ) ).css( 'color', '' );
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
                empty:   '<?php echo esc_js( esc_html__( 'No backups recorded for this site yet.', 'mainwp' ) ); ?>',
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
            // the list: never left disabled by a reload that failed.
            function enableSnapshotControls( enabled ) {
                $( '#mainwp-native-backup-now-btn, #mainwp-snapshots-cleanup-btn' ).prop( 'disabled', ! enabled ).toggleClass( 'disabled', ! enabled );
            }

            function testRestoreCell( snap ) {
                if ( 'pass' === snap.test_restore_state ) {
                    var testedOn = snap.test_restore_at > 0 ? ' ' + new Date( snap.test_restore_at * 1000 ).toLocaleDateString() : '';
                    return '<span class="ui mini green label"><i class="check icon"></i>' + testRestoreLabels.pass + '</span>' + testedOn;
                }
                if ( 'fail' === snap.test_restore_state ) {
                    return '<span class="ui mini red label">' + testRestoreLabels.fail + '</span>';
                }
                if ( 'unsupported' === snap.test_restore_state ) {
                    return '<span class="ui mini grey label">' + testRestoreLabels.unsupported + '</span>';
                }
                if ( 'unavailable' === snap.test_restore_state ) {
                    return '<span class="ui mini grey label">' + testRestoreLabels.unavailable + '</span>';
                }
                return '&mdash;';
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
                        "render": function ( data ) {
                            // backup_type_label() already escaped these; an
                            // unrecognised type falls back to the raw value.
                            return backupTypeLabels[ data ] || escAttr( data );
                        }
                    },
                    {
                        "data": "created",
                        "render": function ( data, type ) {
                            return ( 'display' === type || 'filter' === type ) ? new Date( data * 1000 ).toLocaleString() : ( parseInt( data, 10 ) || 0 );
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
                            // snap.type stays raw here: the confirm pane and the
                            // WooCommerce branches read this attribute as state.
                            return snap.restorable
                                ? '<a href="#" class="ui mini green button mainwp-restore-snapshot" data-snapshot="' + snap.id + '" data-snapshot-type="' + snap.type + '" data-snapshot-age="' + ( parseInt( snap.age_days, 10 ) || 0 ) + '"><?php echo esc_js( esc_html__( 'Restore', 'mainwp' ) ); ?></a>'
                                : '';
                        }
                    }
                ],
                "createdRow": function ( row, snap ) {
                    if ( snap.restorable ) {
                        return;
                    }
                    $( row ).addClass( 'disabled' );
                    if ( snap.restore_reason ) {
                        $( row ).attr( 'title', snap.restore_reason );
                    }
                },
                "order": [ [ 2, 'desc' ] ],
                "pageLength": 10,
                "pagingType": "full_numbers",
                "stateSave": true,
                "language": {
                    "emptyTable": snapshotsPlaceholders.idle,
                    "zeroRecords": '<?php echo esc_js( esc_html__( 'No backups match the current filter.', 'mainwp' ) ); ?>'
                }
            } );

            $( '#mainwp-restore-snapshots-table' ).on( 'click', '.mainwp-snapshot-uploads-toggle', function ( event ) {
                event.preventDefault();
                var row = snapshotsTable.row( $( this ).closest( 'tr' ) );
                if ( row.child.isShown() ) {
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
                currentSite = siteId;
                if ( siteId > 0 ) {
                    enableSnapshotControls( true );
                }
                setSnapshotsPlaceholder( snapshotsPlaceholders.loading );
                snapshotsTable.clear().draw();
                $.post( ajaxurl, { action: 'mainwp_restore_snapshot_list', security: nonces.list, site_id: siteId }, function ( response ) {
                    if ( siteId !== currentSite ) {
                        return; // A site switch superseded this request; its response must not repaint the newer site's list.
                    }
                    siteIsMultisite = !! response.multisite;
                    siteHasWooCommerce = !! response.site_has_woocommerce;
                    currentSiteUrl = response.site_url || '';
                    var backupNote = $( '#mainwp-native-backup-now-note' ).attr( 'data-local-only', response.local_only ? '1' : '0' ).toggle( siteId > 0 );
                    backupNote.find( '.mainwp-backup-local-note' ).toggle( !! response.local_only );
                    var destinationsNote = '<?php /* translators: %s: comma-separated storage destination names. */ echo esc_js( esc_html__( 'Back Up Now saves this backup to the destinations of the scheduled backup tasks that include this site: %s.', 'mainwp' ) ); ?>'.replace( '%s', function () { return escAttr( ( response.manual_destinations || [] ).join( ', ' ) ); } );
                    backupNote.find( '.mainwp-backup-destinations-note' ).html( destinationsNote ).toggle( ! response.local_only );

                    setSnapshotsPlaceholder( snapshotsPlaceholders.empty );
                    snapshotsTable.clear();
                    snapshotsTable.rows.add( response.snapshots || [] );
                    snapshotsTable.draw();

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
                $( '#mainwp-restore-confirm-pane' ).toggle( confirming );
                $( '#mainwp-restore-progress-pane' ).toggle( ! confirming );
                $( '#mainwp-restore-start-btn' ).toggle( confirming );
            }

            function showProgress( data ) {
                var label = statusLabels[ data.status ] || data.status;
                if ( 'transferring' === data.status && data.artifact_total > 0 ) {
                    label += ' (' + ( data.artifact_index + 1 ) + '/' + data.artifact_total + ')';
                    if ( data.bytes_total > 0 ) {
                        // Byte-level progress: without it a multi-GB transfer
                        // reads as hung for its whole 10+ minutes.
                        label += ' (' + ( data.bytes_transferred / 1048576 ).toFixed( 1 ) + ' <?php echo esc_js( esc_html__( 'of', 'mainwp' ) ); ?> ' + ( data.bytes_total / 1048576 ).toFixed( 1 ) + ' MB)';
                    }
                }

                var terminal = ( 'completed' === data.status || 'failed_permanent' === data.status || 'cancelled' === data.status );
                var cancelPending = ! terminal && !! data.cancel_requested;
                if ( cancelPending ) {
                    label = '<?php echo esc_js( esc_html__( 'Cancelling once the site finishes its current step', 'mainwp' ) ); ?>';
                }
                $( '#mainwp-restore-status-text' ).text( label );

                $( '#mainwp-restore-spinner' ).toggle( ! terminal );
                $( '#mainwp-restore-cancel-btn' ).toggle( ! terminal && ! cancelPending && [ 'pending', 'pre_snapshot', 'staging', 'transferring', 'failed_retryable', 'running' ].indexOf( data.status ) !== -1 );
                $( '#mainwp-restore-cancel-warning' ).toggle( 'running' === data.status && ! cancelPending );
                $( '#mainwp-restore-rollback-btn' ).toggle( ( 'failed_permanent' === data.status || 'cancelled' === data.status ) && !! data.has_rollback );

                // Surface the safety backup whenever one exists for this run,
                // so the safety net is visible and not implied (L4).
                $( '#mainwp-restore-safety-note' ).toggle( !! data.has_rollback );

                // A phase progress bar across the known sequence (L3). The
                // failed/cancelled states leave it where it stopped.
                var $bar = $( '#mainwp-restore-progress-bar' );
                var idx = phaseOrder.indexOf( data.status );
                if ( 'failed_permanent' === data.status || 'cancelled' === data.status ) {
                    $bar.hide();
                } else if ( idx >= 0 ) {
                    var pct = Math.round( ( idx + 1 ) / phaseOrder.length * 100 );
                    $bar.show().find( '.bar' ).css( 'width', pct + '%' );
                }

                var retryingAbout = '';
                if ( 'failed_retryable' === data.status && data.next_retry_at ) {
                    // A 40-minute backoff without a clock reads as hung; say
                    // when the next attempt comes.
                    retryingAbout = ' <?php echo esc_js( esc_html__( 'Retrying automatically about', 'mainwp' ) ); ?> ' + new Date( data.next_retry_at * 1000 ).toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } ) + '.';
                }

                if ( terminal ) {
                    var $message = $( '#mainwp-restore-result-message' );
                    var rolledBack = 'mainwp_restore_rolled_back' === data.error_code;
                    var rollbackFailed = 'mainwp_restore_rollback_failed' === data.error_code;
                    var outcome = data.error_code ? restoreOutcomeMessages[ data.error_code ] : '';
                    $message.removeClass( 'green red blue' );
                    if ( 'completed' === data.status ) {
                        $message.addClass( 'green' ).text( '<?php echo esc_js( esc_html__( 'The site was restored and responds normally.', 'mainwp' ) ); ?>' );
                        showPreserveNote( data.verification );
                        showReconcileNote( data.verification );
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

            function showReconcileNote( verification ) {
                var $note = $( '#mainwp-restore-reconcile-note' ).removeClass( 'blue yellow' ).hide();
                if ( ! verification || ! verification.reconcile ) {
                    return;
                }
                var reconcile = verification.reconcile;
                if ( 'skipped' === reconcile.status ) {
                    $note.addClass( 'yellow' ).text( '<?php echo esc_js( esc_html__( 'Files added after this backup were not removed. Backups made before MainWP Child 6.3-beta1 have no file list to compare against.', 'mainwp' ) ); ?>' ).show();
                } else if ( 'done' === reconcile.status ) {
                    var removed = parseInt( reconcile.removed, 10 ) || 0;
                    var text = '<?php echo esc_js( esc_html__( 'No files were added after this backup.', 'mainwp' ) ); ?>';
                    if ( 1 === removed ) {
                        text = '<?php echo esc_js( esc_html__( 'Removed 1 file that was added after this backup.', 'mainwp' ) ); ?>';
                    } else if ( removed > 1 ) {
                        text = '<?php /* translators: %d: number of removed files. */ echo esc_js( esc_html__( 'Removed %d files that were added after this backup.', 'mainwp' ) ); ?>'.replace( '%d', removed );
                    }
                    $note.addClass( 'blue' ).text( text ).show();
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
                    } else if ( 'completed' === data.status ) {
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
                currentJob = jobId;
                restorePollRetries = 0;
                setPane( false );
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
            $( '#mainwp-restore-modal' ).modal( { closable: false } );

            $( document ).on( 'click', '.mainwp-restore-snapshot', function ( event ) {
                event.preventDefault();
                currentSnapshot = parseInt( $( this ).data( 'snapshot' ), 10 );
                var snapType = $( this ).data( 'snapshot-type' );
                var snapAge = parseInt( $( this ).data( 'snapshot-age' ), 10 ) || 0;
                var warningText = restoreWarnings[ snapType ] || restoreWarnings['default'];
                if ( siteIsMultisite ) {
                    warningText += ' ' + networkWarning;
                }
                if ( siteHasWooCommerce && 'files' !== snapType && snapAge > wooMaxAgeDays ) {
                    warningText += ' ' + wooAgeWarning.replace( '%d', snapAge );
                }
                $( '#mainwp-restore-warning-text' ).text( warningText );
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
                        $( '#mainwp-restore-result-message' ).addClass( 'red' ).text( response.error ).show();
                    }
                }, 'json' );
            } );

            $( '#mainwp-restore-rollback-btn' ).on( 'click', function () {
                $.post( ajaxurl, { action: 'mainwp_restore_rollback', security: nonces.rollback, job_id: currentJob }, function ( response ) {
                    if ( response.error ) {
                        $( '#mainwp-restore-result-message' ).addClass( 'red' ).text( response.error ).show();
                        return;
                    }
                    startJobFollow( response.job_id );
                }, 'json' );
            } );

            $( '#mainwp-restore-close-btn' ).on( 'click', function () {
                window.clearTimeout( pollTimer );
            } );

            // On-demand "Back Up Now": kick the native backup, then poll the
            // same way the restore flow does and refresh the list on success.
            function setBackupStatus( text, isError ) {
                $( '#mainwp-native-backup-now-status' ).text( text ).css( 'color', isError ? '#AD0000' : '' );
            }

            function reenableBackupBtn() {
                $( '#mainwp-native-backup-now-btn' ).prop( 'disabled', false ).removeClass( 'disabled' );
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
                        return;
                    }

                    // Running: refresh the cached fields and let the 1s renderer
                    // paint them. The human label comes from the server; the raw
                    // phase string is used only to reset the per-phase ETA.
                    var label = data.label || '<?php echo esc_js( esc_html__( 'Backing up...', 'mainwp' ) ); ?>';
                    if ( 'queued' === data.status ) {
                        label = '<?php echo esc_js( esc_html__( 'Queued; waiting for a free slot...', 'mainwp' ) ); ?>';
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
                    stopBackupTimers();
                    setBackupStatus( '<?php echo esc_js( esc_html__( 'Backup cancelled.', 'mainwp' ) ); ?>', false );
                    showCancelBackup( false );
                    reenableBackupBtn();
                }, 'json' ).fail( function () {
                    if ( siteId !== currentSite ) {
                        return;
                    }
                    setBackupStatus( '<?php echo esc_js( esc_html__( 'Could not cancel the backup. Please try again.', 'mainwp' ) ); ?>', true );
                    $( '#mainwp-native-backup-cancel-btn' ).prop( 'disabled', false ).removeClass( 'disabled' );
                } );
            } );

            $( '#mainwp-snapshots-cleanup-btn' ).on( 'click', function () {
                if ( ! ( currentSite > 0 ) ) {
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
                var confirmMsg = '<?php echo esc_js( esc_html__( 'Clean up this site\'s backups? Leftover files from interrupted backups are removed, and old on-demand backups beyond the retention count are pruned. Pruned backups can no longer be restored.', 'mainwp' ) ); ?>';
                if ( 'function' === typeof mainwp_confirm ) {
                    mainwp_confirm( confirmMsg, runCleanup );
                } else if ( window.confirm( confirmMsg ) ) {
                    runCleanup();
                }
            } );

            if ( currentSite > 0 ) {
                loadSnapshots( currentSite );
            }
        } );
        </script>
        <?php
    }
}
