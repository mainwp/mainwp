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
    public static function render(): void { // phpcs:ignore -- NOSONAR - page assembly.
        if ( ! \mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'restore backups', 'mainwp' ) );
            return;
        }

        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
        $site_id  = isset( $_GET['site_id'] ) ? (int) $_GET['site_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display filter only.

        MainWP_Backup_Page::render_header( 'Snapshots' );
        ?>
        <div id="mainwp-backup-snapshots" class="ui alt segment">
            <div class="mainwp-main-content">
                <div class="ui form">
                    <div class="inline field">
                        <label><?php esc_html_e( 'Site', 'mainwp' ); ?></label>
                        <select id="mainwp-restore-site-selector" class="ui search selection dropdown">
                            <option value="0"><?php esc_html_e( 'Select a site', 'mainwp' ); ?></option>
                            <?php
                            while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition -- standard MainWP result loop.
                                ?>
                                <option value="<?php echo (int) $website->id; ?>" <?php selected( $site_id, (int) $website->id ); ?>><?php echo esc_html( stripslashes( $website->name ) ); ?></option>
                                <?php
                            }
                            MainWP_DB::free_result( $websites );
                            ?>
                        </select>
                    </div>
                </div>

                <table class="ui table" id="mainwp-restore-snapshots-table">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'Backup', 'mainwp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Created', 'mainwp' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Available from', 'mainwp' ); ?></th>
                            <th scope="col" class="collapsing"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="5"><?php esc_html_e( 'Select a site to list its backups.', 'mainwp' ); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ui small modal" id="mainwp-restore-modal">
            <div class="header"><?php esc_html_e( 'Restore Backup', 'mainwp' ); ?></div>
            <div class="content">
                <div id="mainwp-restore-confirm-pane">
                    <div class="ui yellow message">
                        <i class="warning circle icon"></i>
                        <?php esc_html_e( 'Restoring replaces the site\'s files and database with the contents of this backup. Anything changed since the backup was taken will be overwritten.', 'mainwp' ); ?>
                    </div>
                    <div class="ui form">
                        <div class="field">
                            <div class="ui checkbox" id="mainwp-restore-presnapshot-box">
                                <input type="checkbox" id="mainwp-restore-presnapshot" checked="checked">
                                <label><?php esc_html_e( 'Create a safety backup first (recommended). It stays on the site and is the rollback if the restore fails. Without it, files changed by a failed restore cannot be rolled back automatically.', 'mainwp' ); ?></label>
                            </div>
                        </div>
                        <div class="field">
                            <div class="ui checkbox" id="mainwp-restore-consent-box">
                                <input type="checkbox" id="mainwp-restore-consent">
                                <label><?php esc_html_e( 'I understand this replaces the current site content.', 'mainwp' ); ?></label>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="mainwp-restore-progress-pane" style="display:none;">
                    <div class="ui active inline loader" id="mainwp-restore-spinner"></div>
                    <span id="mainwp-restore-status-text"></span>
                    <div class="ui message" id="mainwp-restore-result-message" style="display:none;"></div>
                </div>
            </div>
            <div class="actions">
                <div class="ui cancel button" id="mainwp-restore-close-btn"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
                <div class="ui button" id="mainwp-restore-cancel-btn" style="display:none;"><?php esc_html_e( 'Cancel Restore', 'mainwp' ); ?></div>
                <div class="ui button" id="mainwp-restore-rollback-btn" style="display:none;"><?php esc_html_e( 'Roll Back', 'mainwp' ); ?></div>
                <div class="ui green disabled button" id="mainwp-restore-start-btn"><i class="undo icon"></i><?php esc_html_e( 'Start Restore', 'mainwp' ); ?></div>
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
        ?>
        <script type="text/javascript">
        jQuery( function ( $ ) {
            var nonces = {
                list:     '<?php echo esc_js( wp_create_nonce( 'mainwp_restore_snapshot_list' ) ); ?>',
                initiate: '<?php echo esc_js( wp_create_nonce( 'mainwp_restore_initiate' ) ); ?>',
                progress: '<?php echo esc_js( wp_create_nonce( 'mainwp_restore_progress' ) ); ?>',
                cancel:   '<?php echo esc_js( wp_create_nonce( 'mainwp_restore_cancel' ) ); ?>',
                rollback: '<?php echo esc_js( wp_create_nonce( 'mainwp_restore_rollback' ) ); ?>'
            };
            var statusLabels = <?php echo wp_json_encode( $labels ); ?>;
            var currentSite = <?php echo (int) $site_id; ?>;
            var currentSnapshot = 0;
            var currentJob = 0;
            var pollTimer = null;

            var sourceLabels = {
                'local': '<?php echo esc_js( esc_html__( 'This dashboard', 'mainwp' ) ); ?>',
                'remote': '<?php echo esc_js( esc_html__( 'Cloud storage', 'mainwp' ) ); ?>',
                'remote_chunked': '<?php echo esc_js( esc_html__( 'Cloud storage', 'mainwp' ) ); ?>',
                'unavailable': '<?php echo esc_js( esc_html__( 'Removed by retention', 'mainwp' ) ); ?>'
            };

            function loadSnapshots( siteId ) {
                currentSite = siteId;
                var $tbody = $( '#mainwp-restore-snapshots-table tbody' );
                $tbody.html( '<tr><td colspan="5"><div class="ui active inline loader mini"></div></td></tr>' );
                $.post( ajaxurl, { action: 'mainwp_restore_snapshot_list', security: nonces.list, site_id: siteId }, function ( response ) {
                    var rows = '';
                    ( response.snapshots || [] ).forEach( function ( snap ) {
                        var when = new Date( snap.created * 1000 ).toLocaleString();
                        var action = snap.restorable
                            ? '<a href="#" class="ui mini green button mainwp-restore-snapshot" data-snapshot="' + snap.id + '"><?php echo esc_js( esc_html__( 'Restore', 'mainwp' ) ); ?></a>'
                            : '';
                        var rowClass = snap.restorable ? '' : ' class="disabled"';
                        rows += '<tr' + rowClass + '><td>#' + snap.id + '</td><td>' + snap.type + '</td><td>' + when + '</td><td>' + ( sourceLabels[ snap.source ] || snap.source ) + '</td><td>' + action + '</td></tr>';
                    } );
                    if ( '' === rows ) {
                        rows = '<tr><td colspan="5"><?php echo esc_js( esc_html__( 'No backups recorded for this site yet.', 'mainwp' ) ); ?></td></tr>';
                    }
                    $tbody.html( rows );
                }, 'json' );
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
                }
                $( '#mainwp-restore-status-text' ).text( label );

                var terminal = ( 'completed' === data.status || 'failed_permanent' === data.status || 'cancelled' === data.status );
                $( '#mainwp-restore-spinner' ).toggle( ! terminal );
                $( '#mainwp-restore-cancel-btn' ).toggle( ! terminal && [ 'pending', 'pre_snapshot', 'staging', 'transferring', 'failed_retryable' ].indexOf( data.status ) !== -1 );
                $( '#mainwp-restore-rollback-btn' ).toggle( 'failed_permanent' === data.status && !! data.has_rollback );

                if ( terminal ) {
                    var $message = $( '#mainwp-restore-result-message' );
                    $message.removeClass( 'green red' ).addClass( 'completed' === data.status ? 'green' : 'red' );
                    $message.text( 'completed' === data.status ? '<?php echo esc_js( esc_html__( 'The site was restored and responds normally.', 'mainwp' ) ); ?>' : ( data.last_error || label ) ).show();
                    window.clearTimeout( pollTimer );
                } else if ( data.last_error ) {
                    $( '#mainwp-restore-result-message' ).removeClass( 'green' ).addClass( 'red' ).text( data.last_error ).show();
                }
            }

            function poll() {
                $.post( ajaxurl, { action: 'mainwp_restore_progress', security: nonces.progress, job_id: currentJob }, function ( data ) {
                    showProgress( data );
                    if ( [ 'completed', 'failed_permanent', 'cancelled' ].indexOf( data.status ) === -1 ) {
                        pollTimer = window.setTimeout( poll, 5000 );
                    } else if ( 'completed' === data.status ) {
                        loadSnapshots( currentSite );
                    }
                }, 'json' );
            }

            function startJobFollow( jobId ) {
                currentJob = jobId;
                setPane( false );
                $( '#mainwp-restore-result-message' ).hide();
                poll();
            }

            $( '#mainwp-restore-site-selector' ).dropdown().on( 'change', function () {
                var siteId = parseInt( $( this ).val(), 10 );
                if ( siteId > 0 ) {
                    loadSnapshots( siteId );
                }
            } );

            $( document ).on( 'click', '.mainwp-restore-snapshot', function ( event ) {
                event.preventDefault();
                currentSnapshot = parseInt( $( this ).data( 'snapshot' ), 10 );
                $( '#mainwp-restore-consent' ).prop( 'checked', false );
                $( '#mainwp-restore-start-btn' ).addClass( 'disabled' );
                setPane( true );
                $( '#mainwp-restore-modal' ).modal( { closable: false } ).modal( 'show' );
            } );

            $( '#mainwp-restore-consent' ).on( 'change', function () {
                $( '#mainwp-restore-start-btn' ).toggleClass( 'disabled', ! this.checked );
            } );

            $( '#mainwp-restore-start-btn' ).on( 'click', function () {
                if ( $( this ).hasClass( 'disabled' ) ) {
                    return;
                }
                $.post( ajaxurl, {
                    action: 'mainwp_restore_initiate',
                    security: nonces.initiate,
                    site_id: currentSite,
                    snapshot_id: currentSnapshot,
                    pre_snapshot: $( '#mainwp-restore-presnapshot' ).is( ':checked' ) ? 1 : 0
                }, function ( response ) {
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

            if ( currentSite > 0 ) {
                loadSnapshots( currentSite );
            }
        } );
        </script>
        <?php
    }
}
