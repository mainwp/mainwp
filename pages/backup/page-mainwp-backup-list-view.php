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
        ?>
        <table id="mainwp-backups-table" class="ui selectable compact stackable table">
            <thead class="full-width">
                <tr>
                    <th id="mainwp-title"><?php esc_html_e( 'Task name', 'mainwp' ); ?></th>
                    <th id="mainwp-type"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
                    <th id="mainwp-schedule"><?php esc_html_e( 'Schedule', 'mainwp' ); ?></th>
                    <th id="mainwp-dest" class="no-sort"><?php esc_html_e( 'Destination', 'mainwp' ); ?></th>
                    <th id="mainwp-website"><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
                    <th id="mainwp-details" class="no-sort"><?php esc_html_e( 'Details', 'mainwp' ); ?></th>
                    <th id="mainwp-actions" class="no-sort collapsing"></th>
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
                        'actions'     => esc_html__( 'Actions', 'mainwp' ),
                    );

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
                            "scrollX": true,
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
            // Backups-owned query var: a bare `id` also puts the dashboard chrome
            // into single-site mode, which crowns the edit screen with a site panel.
            'edit'   => sprintf( '<a class="item" href="admin.php?page=ManageBackups&task_id=%d"><i class="edit outline icon"></i> ' . esc_html__( 'Edit', 'mainwp' ) . '</a>', (int) $item->id ),
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

        $out = $this->primary_row_action( $item );

        $out .= '<div class="ui right pointing dropdown" style="z-index:999">
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
     * The row's visible action: Resume on a paused task, Run Now otherwise.
     *
     * @param object $item Task row.
     *
     * @return string Button markup, or empty without the capability.
     */
    private function primary_row_action( $item ) {
        if ( 1 === (int) $item->paused ) {
            if ( ! \mainwp_current_user_can( 'dashboard', 'pause_resume_backup_tasks' ) ) {
                return '';
            }
            return sprintf( '<a href="#" class="ui mini basic button" task_id="%d" onClick="return managebackups_resume(this)">' . esc_html__( 'Resume', 'mainwp' ) . '</a> ', (int) $item->id );
        }
        if ( ! \mainwp_current_user_can( 'dashboard', 'run_backup_tasks' ) ) {
            return '';
        }
        return '<a href="#" class="ui mini button backup_run_now" task_id="' . (int) $item->id . '" task_type="' . esc_attr( $item->type ) . '">' . esc_html__( 'Run Now', 'mainwp' ) . '</a> ';
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
        return MainWP_Backup_Page::backup_type_label( $item->type );
    }

    /**
     * Column Schedule.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_schedule( $item ) {
        $labels   = array(
            'daily'    => esc_html__( 'Daily', 'mainwp' ),
            'weekly'   => esc_html__( 'Weekly', 'mainwp' ),
            'monthly'  => esc_html__( 'Monthly', 'mainwp' ),
            'interval' => esc_html__( 'Interval', 'mainwp' ),
            'manual'   => esc_html__( 'Manual', 'mainwp' ),
        );
        $schedule = strtolower( (string) $item->schedule );
        $label    = isset( $labels[ $schedule ] ) ? $labels[ $schedule ] : esc_html( ucfirst( $schedule ) );
        if ( 1 === (int) ( $item->paused ?? 0 ) ) {
            $label .= ' <span class="ui small grey label">' . esc_html__( 'Paused', 'mainwp' ) . '</span>';
        }
        return $label;
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

        return esc_html__( 'Server', 'mainwp' );
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
            echo '<span class="ui small red label">0</span>';
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
        $last_run  = empty( $item->last_run ) ? '-' : MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $item->last_run ) );
        $manually  = empty( $item->last_run_manually ) ? '-' : MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $item->last_run_manually ) );
        $completed = empty( $item->completed ) ? '-' : MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $item->completed ) );

        $history = sprintf(
            /* translators: 1: when the task was last run manually, 2: when the last run completed. */
            __( 'Last run manually: %1$s. Last completed: %2$s.', 'mainwp' ),
            $manually,
            $completed
        );

        $output  = '<strong>' . esc_html__( 'Last run', 'mainwp' ) . ':</strong> ' . esc_html( $last_run );
        $output .= ' <i class="info circle icon" data-tooltip="' . esc_attr( $history ) . '" data-position="top left" data-variation="wide inverted"></i>';
        $output .= '<br /><strong>' . esc_html__( 'Next run', 'mainwp' ) . ':</strong> ' . esc_html( $this->next_run_label( $item ) );

        if ( ! empty( $item->last_run ) && $item->completed < $item->last_run ) {
            $completed_sites = $item->completed_sites;
            if ( '' !== $completed_sites ) {
                $completed_sites = json_decode( $completed_sites, 1 );
            }
            if ( ! is_array( $completed_sites ) ) {
                $completed_sites = array();
            }

            // A crashed scheduled run never advances `completed` past
            // `last_run`, so without this branch the row reads as currently
            // running forever with no way to clear it. Once the elapsed
            // time since last_run passes the same wall-clock threshold the
            // executor already uses for its per-site busy-since stall
            // check, flip to a stale state with the elapsed time and a
            // task-scoped reset affordance instead.
            $elapsed_seconds = time() - (int) $item->last_run;
            $elapsed_label   = $this->format_elapsed_time( $elapsed_seconds );

            if ( $elapsed_seconds > MainWP_Backup_Executor::STALL_THRESHOLD_SECONDS ) {
                $output .= '<br /><strong>' . esc_html__( 'Stale', 'mainwp' ) . ':</strong> '
                    . sprintf(
                        /* translators: %s: elapsed time since the run started, e.g. "3h 20m". */
                        esc_html__( 'Running for %s. May be stuck.', 'mainwp' ),
                        esc_html( $elapsed_label )
                    )
                    . ' <a href="#" class="mainwp-native-backup-task-reset" task_id="' . (int) $item->id . '" onClick="return managebackups_reset_stale(this);">' . esc_html__( 'Reset', 'mainwp' ) . '</a>';
            } else {
                $output .= '<br /><strong>' . esc_html__( 'Currently running', 'mainwp' ) . ':</strong> '
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
     * The task's next scheduled run.
     *
     * The cron writes an authoritative `next_run` for every schedule shape it
     * supports, including time-of-day, day-of-week, and day-of-month. The
     * interval estimate is only reached by rows written before that column
     * existed, and it cannot see any of those refinements.
     *
     * @param object $item Task row.
     *
     * @return string Formatted timestamp, or a placeholder when the task has
     *                never run or is paused.
     */
    private function next_run_label( $item ) {
        // A paused task keeps a stored next_run that only resume recomputes,
        // so printing it would advertise a run that will not happen.
        if ( 1 === (int) ( $item->paused ?? 0 ) ) {
            return __( 'Paused', 'mainwp' );
        }
        if ( isset( $item->next_run ) && (int) $item->next_run > 0 ) {
            return MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $item->next_run ) );
        }

        if ( empty( $item->last_run ) ) {
            return __( 'Any minute', 'mainwp' );
        }

        if ( 'daily' === $item->schedule ) {
            $interval = DAY_IN_SECONDS;
        } elseif ( 'weekly' === $item->schedule ) {
            $interval = WEEK_IN_SECONDS;
        } else {
            $interval = 30 * DAY_IN_SECONDS;
        }

        return MainWP_Utility::format_timestamp( $interval + MainWP_Utility::get_timestamp( $item->last_run ) );
    }

    /**
     * Format a duration for the details column: "80d 10h", "3h 20m", "12m",
     * or "45s".
     *
     * @param int $seconds Elapsed seconds.
     *
     * @return string Compact elapsed-time label.
     */
    private function format_elapsed_time( $seconds ) {
        $seconds = max( 0, (int) $seconds );
        $days    = (int) floor( $seconds / DAY_IN_SECONDS );
        $hours   = (int) floor( ( $seconds % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
        $minutes = (int) floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

        if ( $days > 0 ) {
            return sprintf( '%dd %dh', $days, $hours );
        }
        if ( $hours > 0 ) {
            return sprintf( '%dh %dm', $hours, $minutes );
        }
        if ( $minutes > 0 ) {
            return sprintf( '%dm', $minutes );
        }
        return sprintf( '%ds', $seconds );
    }

    /**
     * Column Trigger.
     *
     * Kept for the deprecated MainWP_Manage_Backups::column_trigger() shim;
     * the list itself renders this action in the actions cell.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_trigger( $item ) {
        return $this->primary_row_action( $item );
    }
}
