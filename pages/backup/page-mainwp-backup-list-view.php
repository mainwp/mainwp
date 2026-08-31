<?php
/**
 * MainWP Backup List View.
 *
 * Renders the backup task list table on the Manage Backups page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_List_View
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_List_View { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return string __CLASS__
     */
    public static function get_class_name(): string {
        return __CLASS__;
    }

    /**
     * Instance variable.
     *
     * @var self|null Instance variable.
     */
    private static $instance = null;

    /**
     * Create instance.
     *
     * @return self $instance.
     */
    public static function instance(): self {
        if ( null === static::$instance ) {
            static::$instance = new MainWP_Backup_List_View();
        }

        return static::$instance;
    }

    /**
     * Render MainWP Backups Table.
     *
     * @param mixed $backup_items List Item.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_websites_by_group_id()
     */
    public function display( $backup_items ): void {  //phpcs:ignore -- NOSONAR - complex.
        $can_trigger = true;
        if ( ! \mainwp_current_user_can( 'dashboard', 'run_backup_tasks' ) ) {
            $can_trigger = false;
        }
        ?>
        <table id="mainwp-backups-table" class="ui padded selectable compact single line table">
            <thead class="full-width">
                <tr>
                    <th id="mainwp-title"><?php esc_html_e( 'Task name', 'mainwp' ); ?></th>
                    <th id="mainwp-type"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
                    <th id="mainwp-schedule"><?php esc_html_e( 'Schedule', 'mainwp' ); ?></th>
                    <th id="mainwp-dest" class="no-sort"><?php esc_html_e( 'Destination', 'mainwp' ); ?></th>
                    <th id="mainwp-website"><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
                    <th id="mainwp-details" class="no-sort"><?php esc_html_e( 'Details', 'mainwp' ); ?></th>
                    <?php if ( $can_trigger ) { ?>
                    <th id="mainwp-trigger" class="no-sort"><?php esc_html_e( 'Trigger', 'mainwp' ); ?></th>
                    <?php } ?>
                    <th id="mainwp-actions" class="no-sort"></th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ( $backup_items ) {
                    $columns = array(
                        'task_name'   => esc_html__( 'Task Name', 'mainwp' ),
                        'type'        => esc_html__( 'Type', 'mainwp' ),
                        'schedule'    => esc_html__( 'Schedule', 'mainwp' ),
                        'destination' => esc_html__( 'Destination', 'mainwp' ),
                        'websites'    => esc_html__( 'Websites', 'mainwp' ),
                        'details'     => esc_html__( 'Details', 'mainwp' ),
                        'trigger'     => esc_html__( 'Trigger', 'mainwp' ),
                        'actions'     => esc_html__( 'Trigger', 'mainwp' ),
                    );

                    if ( ! $can_trigger ) {
                        unset( $columns['trigger'] );
                    }

                    foreach ( $backup_items as $item ) {
                        $sites  = ( empty( $item->sites ) ? array() : explode( ',', $item->sites ) );
                        $groups = ( empty( $item->groups ) ? array() : explode( ',', $item->groups ) );
                        foreach ( $groups as $group ) {
                            $websites = MainWP_DB::instance()->get_websites_by_group_id( $group );
                            if ( empty( $websites ) ) {
                                continue;
                            }

                            foreach ( $websites as $website ) {
                                if ( ! in_array( $website->id, $sites ) ) {
                                    $sites[] = $website->id;
                                }
                            }
                        }

                        $item->the_sites = $sites;

                        $this->single_row( $item, $columns );
                    }
                }
                ?>
            </tbody>
        </table>
        <script type="text/javascript">
                jQuery( document ).ready( function () {
                    jQuery( '#mainwp-backups-table' ).DataTable( {
                            "colReorder" : true,
                            "stateSave":  true,
                            "pagingType": "full_numbers",
                            "order": [],
                            "columnDefs": [ {
                                "targets": 'no-sort',
                                "orderable": false
                            } ],
                            "drawCallback": function( settings ) {
                                jQuery( '#mainwp-backups-table .ui.dropdown' ).dropdown();
                            },
                    } );
                } );
        </script>
        <?php
    }

    /**
     * Single row Content.
     *
     * @param mixed $item Item to go in column.
     * @param mixed $columns Columns Array.
     */
    public function single_row( $item, $columns ): void {
        ?>
        <tr>
        <?php
        foreach ( array_keys( $columns ) as $column_name ) {
            if ( method_exists( $this, 'column_' . $column_name ) ) {
                echo '<td>';
                echo call_user_func( array( $this, 'column_' . $column_name ), $item ); // phpcs:ignore WordPress.Security.EscapeOutput
                echo '</td>';
            } else {
                echo '<td></td>';
            }
        }
        ?>
        </tr>
        <?php
    }

    /**
     * Column Actions.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_actions( $item ) {

        $actions = array(
            'edit'   => sprintf( '<a class="item" href="admin.php?page=ManageBackups&id=%d"><i class="edit outline icon"></i> ' . esc_html__( 'Edit', 'mainwp' ) . '</a>', (int) $item->id ),
            'delete' => sprintf( '<a class="submitdelete item" href="#" task_id="%d" onClick="return managebackups_remove(this);"><i class="trash alternate outline icon"></i> ' . esc_html__( 'Delete', 'mainwp' ) . '</a>', (int) $item->id ),
        );

        if ( ! \mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) {
            unset( $actions['edit'] );
        }

        if ( ! \mainwp_current_user_can( 'dashboard', 'delete_backup_tasks' ) ) {
            unset( $actions['delete'] );
        }

        if ( 1 === (int) $item->paused ) {
            if ( \mainwp_current_user_can( 'dashboard', 'pause_resume_backup_tasks' ) ) {
                $actions['resume'] = sprintf( '<a href="#" class="item" task_id="%d" onClick="return managebackups_resume(this)"><i class="play icon"></i> ' . esc_html__( 'Resume', 'mainwp' ) . '</a>', (int) $item->id );
            }
        } elseif ( \mainwp_current_user_can( 'dashboard', 'pause_resume_backup_tasks' ) ) {
                $actions['pause'] = sprintf( '<a href="#" class="item" task_id="%d" onClick="return managebackups_pause(this)"><i class="pause icon"></i> ' . esc_html__( 'Pause', 'mainwp' ) . '</a>', (int) $item->id );
        }

        $out = '<div class="ui right pointing dropdown" style="z-index:999">
                        <i class="ellipsis vertical icon"></i>
                        <div class="menu">
                        <div class="header">' . esc_html__( 'Backup Actions', 'mainwp' ) . '</div>
                        <div class="divider"></div>';
        foreach ( $actions as $link ) {
            $out .= $link;
        }
        $out .= '</div>
                        </div>';

        return $out;
    }

    /**
     * Column Task Name.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_task_name( $item ) {
        return esc_html( stripslashes( $item->name ) );
    }

    /**
     * Column Type.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_type( $item ) {
        if ( 'db' === $item->type ) {
            return esc_html__( 'DATABASE BACKUP', 'mainwp' );
        }
        if ( 'incremental' === $item->type ) {
            return esc_html__( 'INCREMENTAL BACKUP', 'mainwp' );
        }
        return esc_html__( 'FULL BACKUP', 'mainwp' );
    }

    /**
     * Column Schedule.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_schedule( $item ) {
        return esc_html( strtoupper( $item->schedule ) );
    }

    /**
     * Column Destination.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_destination( $item ) {
        $extraOutput = apply_filters( 'mainwp_backuptask_column_destination', '', $item->id );
        if ( '' !== $extraOutput ) {
            // Strip a trailing <br /> that legacy extensions append, not the
            // individual characters trim() would have removed.
            return (string) preg_replace( '#(?:\s*<br\s*/?>\s*)+$#i', '', $extraOutput );
        }

        $storage = $this->storage_destination_summary( $item );
        if ( '' !== $storage ) {
            return $storage;
        }

        return esc_html__( 'SERVER', 'mainwp' );
    }

    /**
     * Summarize a task's storage destinations and the latest run's upload
     * state.
     *
     * @param object $item Task row.
     *
     * @return string Markup, empty when the task has no destinations.
     */
    private function storage_destination_summary( $item ) {
        $ids = json_decode( isset( $item->destinations ) ? (string) $item->destinations : '', true );
        if ( ! is_array( $ids ) || empty( $ids ) ) {
            return '';
        }

        $names = array();
        foreach ( array_map( 'intval', $ids ) as $id ) {
            $destination = MainWP_Backup_Storage_Destinations::instance()->get_destination( $id );
            if ( null !== $destination ) {
                $names[] = esc_html( $destination->name );
            }
        }

        if ( empty( $names ) ) {
            return '';
        }

        $output = implode( ', ', $names );

        $snapshot = MainWP_Backup_Snapshots::instance()->get_latest_for_task( (int) $item->id );
        if ( null !== $snapshot ) {
            $jobs = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( (int) $snapshot->id );
            if ( ! empty( $jobs ) ) {
                $output .= ' ' . $this->upload_state_label( $jobs, $snapshot );
            }
        }

        return $output;
    }

    /**
     * The worst-state-wins label for a snapshot's upload jobs.
     *
     * @param object[]    $jobs     Upload job rows.
     * @param object|null $snapshot Snapshot row, for the offsite-verified state.
     *
     * @return string Label markup.
     */
    private function upload_state_label( $jobs, $snapshot = null ) {
        $statuses = array_column( $jobs, 'status' );

        if ( in_array( MainWP_Backup_Upload_Job_Store::STATUS_CREDENTIALS_INVALID, $statuses, true ) ) {
            return $this->upload_failed_label( esc_html__( 'Re-enter credentials', 'mainwp' ), $jobs, MainWP_Backup_Upload_Job_Store::STATUS_CREDENTIALS_INVALID );
        }
        if ( in_array( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, $statuses, true ) ) {
            return $this->upload_failed_label( esc_html__( 'Upload failed', 'mainwp' ), $jobs, MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT );
        }

        // A non-local copy (artifacts and manifest) is HEAD-confirmed at its
        // destination: distinct from "Uploaded", which only means the bytes
        // left the dashboard.
        if ( null !== $snapshot && isset( $snapshot->offsite_state ) && 'verified' === $snapshot->offsite_state ) {
            return '<span class="ui small green label">' . esc_html__( 'Offsite Verified', 'mainwp' ) . '</span>';
        }

        if ( count( array_keys( $statuses, MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, true ) ) === count( $statuses ) ) {
            return '<span class="ui small green label">' . esc_html__( 'Uploaded', 'mainwp' ) . '</span>';
        }

        return '<span class="ui small blue label">' . esc_html__( 'Uploading', 'mainwp' ) . '</span>';
    }

    /**
     * A failed-upload label, with the sanitized failure reason on hover.
     *
     * The reason lives on the upload job's last_error column and was otherwise
     * never shown, leaving the user a bare "Upload failed" with no cause. The
     * text is stripped of markup and run through the sanitizer so no path,
     * SQL, or credential leaks into the tooltip.
     *
     * @param string   $label  Already-escaped label text.
     * @param object[] $jobs   Upload job rows.
     * @param string   $status Status whose last_error to surface.
     *
     * @return string Label markup.
     */
    private function upload_failed_label( $label, $jobs, $status ) {
        $reason = '';
        foreach ( $jobs as $job ) {
            if ( isset( $job->status ) && $status === $job->status && ! empty( $job->last_error ) ) {
                $reason = (string) $job->last_error;
                break;
            }
        }

        if ( '' === $reason ) {
            return '<span class="ui small red label">' . $label . '</span>';
        }

        $reason = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( MainWP_Backup_Error_Codes::sanitize_message( $reason ) ) ) );
        return '<span class="ui small red label" data-tooltip="' . esc_attr( $reason ) . '" data-position="top left" data-variation="wide inverted">' . $label . '</span>';
    }

    /**
     * Column Websites.
     *
     * @param mixed $item Item to go in column.
     */
    public function column_websites( $item ) {
        if ( 0 === count( $item->the_sites ) ) {
            echo( '<span style="color: red; font-weight: bold; ">' . count( $item->the_sites ) . '</span>' );
        } else {
            echo count( $item->the_sites );
        }
    }

    /**
     * Column Details.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::format_timestamp()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_timestamp()
     */
    public function column_details( $item ) {
        $output  = '<strong>' . esc_html__( 'LAST RUN MANUALLY: ', 'mainwp' ) . '</strong>' . ( empty( $item->last_run_manually ) ? '-' : MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $item->last_run_manually ) ) ) . '<br />';
        $output .= '<strong>' . esc_html__( 'LAST RUN: ', 'mainwp' ) . '</strong>' . ( empty( $item->last_run ) ? '-' : MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $item->last_run ) ) ) . '<br />';
        $output .= '<strong>' . esc_html__( 'LAST COMPLETED: ', 'mainwp' ) . '</strong>' . ( empty( $item->completed ) ? '-' : MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $item->completed ) ) ) . '<br />';
        $output .= '<strong>' . esc_html__( 'NEXT RUN: ', 'mainwp' ) . '</strong>' . ( empty( $item->last_run ) ? esc_html__( 'Any minute', 'mainwp' ) : MainWP_Utility::format_timestamp( ( 'daily' === $item->schedule ? ( 60 * 60 * 24 ) : ( 'weekly' === $item->schedule ? ( 60 * 60 * 24 * 7 ) : ( 60 * 60 * 24 * 30 ) ) ) + MainWP_Utility::get_timestamp( $item->last_run ) ) );
        $output .= '<strong>';
        if ( ! empty( $item->last_run ) && $item->completed < $item->last_run ) {
            $completed_sites = $item->completed_sites;
            if ( '' !== $completed_sites ) {
                $completed_sites = json_decode( $completed_sites, 1 );
            }
            if ( ! is_array( $completed_sites ) ) {
                $completed_sites = array();
            }

            // A crashed scheduled run never advances `completed` past
            // `last_run`, so without this branch the row shows CURRENTLY
            // RUNNING forever with no way to clear it. Once the elapsed
            // time since last_run passes the same wall-clock threshold the
            // executor already uses for its per-site busy-since stall
            // check, flip to a stale state with the elapsed time and a
            // task-scoped reset affordance instead.
            $elapsed_seconds = time() - (int) $item->last_run;
            $elapsed_label   = $this->format_elapsed_time( $elapsed_seconds );

            if ( $elapsed_seconds > MainWP_Backup_Executor::STALL_THRESHOLD_SECONDS ) {
                $output .= '<br />' . esc_html__( 'STALE: ', 'mainwp' ) . '</strong>'
                    . sprintf(
                        /* translators: %s: elapsed time since the run started, e.g. "3h 20m". */
                        esc_html__( 'Running for %s — may be stuck.', 'mainwp' ),
                        esc_html( $elapsed_label )
                    )
                    . ' <a href="#" class="mainwp-native-backup-task-reset" task_id="' . (int) $item->id . '" onClick="return managebackups_reset_stale(this);">' . esc_html__( 'Reset', 'mainwp' ) . '</a>';
            } else {
                $output .= '<br />' . esc_html__( 'CURRENTLY RUNNING: ', 'mainwp' ) . '</strong>'
                    . count( $completed_sites ) . '/' . count( $item->the_sites )
                    . ' (' . sprintf(
                        /* translators: %s: elapsed time since the run started, e.g. "12m". */
                        esc_html__( 'running for %s', 'mainwp' ),
                        esc_html( $elapsed_label )
                    ) . ')';
            }
        }

        // Surface the last run's errors. They are stored on the task row but
        // were otherwise only emailed (when notifications are on) and never
        // shown here. The reason is sanitized and shown on hover so support and
        // the user can see what failed without leaking paths or SQL.
        if ( ! empty( $item->backup_errors ) ) {
            $error_text = wp_strip_all_tags( MainWP_Backup_Error_Codes::sanitize_message( $item->backup_errors ) );
            $error_text = trim( preg_replace( '/\s+/', ' ', $error_text ) );
            if ( '' !== $error_text ) {
                $output .= '<br /><span class="ui small red label" data-tooltip="' . esc_attr( $error_text ) . '" data-position="top left" data-variation="wide inverted">'
                    . '<i class="exclamation triangle icon"></i> ' . esc_html__( 'Last run reported errors', 'mainwp' ) . '</span>';
            }
        }

        // Advisory run notes (like the large-site ZIP auto-switch) are
        // informational: they get their own label so a successful run with a
        // note never wears the error styling.
        if ( ! empty( $item->backup_notices ) ) {
            $notice_text = wp_strip_all_tags( MainWP_Backup_Error_Codes::sanitize_message( $item->backup_notices ) );
            $notice_text = trim( preg_replace( '/\s+/', ' ', $notice_text ) );
            if ( '' !== $notice_text ) {
                $output .= '<br /><span class="ui small blue label" data-tooltip="' . esc_attr( $notice_text ) . '" data-position="top left" data-variation="wide inverted">'
                    . '<i class="info circle icon"></i> ' . esc_html__( 'Last run notes', 'mainwp' ) . '</span>';
            }
        }
        return $output;
    }

    /**
     * Format a duration for the details column: "3h 20m", "12m", or "45s".
     *
     * @param int $seconds Elapsed seconds.
     *
     * @return string Compact elapsed-time label.
     */
    private function format_elapsed_time( $seconds ) {
        $seconds = max( 0, (int) $seconds );
        $hours   = (int) floor( $seconds / HOUR_IN_SECONDS );
        $minutes = (int) floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

        if ( $hours > 0 ) {
            return sprintf( '%dh %dm', $hours, $minutes );
        }
        if ( $minutes > 0 ) {
            return sprintf( '%dm', $minutes );
        }
        return sprintf( '%ds', $seconds );
    }

    /**
     *  Column Trigger.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_trigger( $item ) {
        return '<span class="backup_run_loading"><img src="' . MAINWP_PLUGIN_URL . 'assets/images/loader.gif" /></span>&nbsp;<a href="#" class="backup_run_now" task_id="' . (int) $item->id . '" task_type="' . esc_attr( $item->type ) . '">' . esc_html__( 'Run now', 'mainwp' ) . '</a>';
    }
}
