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
        <table id="mainwp-backups-table" class="ui table">
            <thead class="full-width">
                <tr>
                    <th id="mainwp-title"><?php esc_html_e( 'Task name', 'mainwp' ); ?></th>
                    <th id="mainwp-type"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
                    <th id="mainwp-schedule" class="collapsing"><?php esc_html_e( 'Schedule', 'mainwp' ); ?></th>
                    <th id="mainwp-dest" class="no-sort"><?php esc_html_e( 'Destination', 'mainwp' ); ?></th>
                    <th id="mainwp-website" class="collapsing right aligned"><?php esc_html_e( 'Websites', 'mainwp' ); ?></th>
                    <th id="mainwp-last-run" class="right aligned"><?php esc_html_e( 'Last Run', 'mainwp' ); ?></th>
                    <th id="mainwp-next-run" class="right aligned"><?php esc_html_e( 'Next Run', 'mainwp' ); ?></th>
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
                        'last_run'    => esc_html__( 'Last Run', 'mainwp' ),
                        'next_run'    => esc_html__( 'Next Run', 'mainwp' ),
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
                            // Widths measured at init ignore the wrapped cells and
                            // pushed the actions menu past the container edge.
                            "autoWidth": false,
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
                // The actions cell holds a button and the menu side by side.
                $cell_classes = array(
                    'actions'  => 'collapsing',
                    'schedule' => 'collapsing',
                    'websites' => 'collapsing right aligned',
                );
                if ( in_array( $column_name, array( 'last_run', 'next_run' ), true ) ) {
                    $sort_time = 'last_run' === $column_name ? (int) $item->last_run : $this->next_run_timestamp( $item );
                    echo '<td class="right aligned" data-order="' . (int) $sort_time . '">';
                } else {
                    echo isset( $cell_classes[ $column_name ] ) ? '<td class="' . esc_attr( $cell_classes[ $column_name ] ) . '">' : '<td>';
                }
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
        $actions = array();
        $task_id = (int) $item->id;
        $paused  = 1 === (int) $item->paused;

        if ( \mainwp_current_user_can( 'dashboard', 'run_backup_tasks' ) ) {
            $actions[] = '<a href="#" class="item' . ( $paused ? ' disabled' : ' backup_run_now' ) . '" task_id="' . $task_id . '" task_type="' . esc_attr( $item->type ) . '"' . ( $paused ? ' aria-disabled="true"' : '' ) . '>' . esc_html__( 'Run Now', 'mainwp' ) . '</a>';
        }
        if ( \mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) {
            $actions[] = '<a class="item" href="' . esc_url( admin_url( 'admin.php?page=ManageBackups&task_id=' . $task_id ) ) . '">' . esc_html__( 'Edit', 'mainwp' ) . '</a>';
        }
        if ( \mainwp_current_user_can( 'dashboard', 'pause_resume_backup_tasks' ) ) {
            $handler   = $paused ? 'managebackups_resume' : 'managebackups_pause';
            $label     = $paused ? __( 'Resume', 'mainwp' ) : __( 'Pause', 'mainwp' );
            $actions[] = '<a href="#" class="item" task_id="' . $task_id . '" onClick="return ' . $handler . '(this);">' . esc_html( $label ) . '</a>';
        }
        if ( \mainwp_current_user_can( 'dashboard', 'delete_backup_tasks' ) ) {
            $preview   = MainWP_Backup_Operations::preview_task_delete( $task_id, true, 'ui' );
            $warnings  = is_wp_error( $preview ) ? '' : implode( ' ', $preview['warnings'] );
            $actions[] = '<a class="submitdelete item" href="#" task_id="' . $task_id . '" data-retention-warning="' . esc_attr( $warnings ) . '" onClick="return managebackups_remove(this);">' . esc_html__( 'Delete', 'mainwp' ) . '</a>';
        }
        if ( empty( $actions ) ) {
            return '';
        }
        return '<div class="ui right pointing dropdown" style="z-index: 99;"><a href="javascript:void(0)"><i class="ellipsis vertical icon"></i></a><div class="menu">' . implode( '', $actions ) . '</div></div>';
    }

    /**
     * Column Task Name.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_task_name( $item ) {
        $name = esc_html( stripslashes( $item->name ) );
        if ( ! \mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) {
            return $name;
        }
        return '<a href="' . esc_url( admin_url( 'admin.php?page=ManageBackups&task_id=' . (int) $item->id ) ) . '">' . $name . '</a>';
    }

    /**
     * Column Type.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_type( $item ) {
        $types = array(
            'full'        => array( 'blue', 'archive', __( 'Full', 'mainwp' ) ),
            'db'          => array( 'purple', 'database', __( 'Database', 'mainwp' ) ),
            'incremental' => array( 'teal', 'sync alternate', __( 'Incremental', 'mainwp' ) ),
            'files'       => array( 'orange', 'folder outline', __( 'Files Only', 'mainwp' ) ),
        );
        $type  = $types[ $item->type ] ?? array( 'grey', 'archive', (string) $item->type );
        return '<span class="ui small basic ' . esc_attr( $type[0] ) . ' label"><i class="' . esc_attr( $type[1] ) . ' icon"></i>' . esc_html( $type[2] ) . '</span>';
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
        $icon     = 'interval' === $schedule ? 'clock outline' : ( 'manual' === $schedule ? 'hand pointer outline' : 'calendar alternate outline' );
        $label    = '<i class="' . esc_attr( $icon ) . ' icon" aria-hidden="true"></i>' . $label;
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

        $path = '';
        if ( ! is_wp_error( MainWP_Abilities_Util::check_unrestricted_access() ) ) {
            $root = MainWP_Backup_Private_Root::resolve();
            if ( ! is_wp_error( $root ) ) {
                $path = '<div class="description"><span class="ui small text" data-tooltip="' . esc_attr__( 'Backups are organized in site-specific subdirectories beneath this location.', 'mainwp' ) . '" data-position="left center" data-inverted="">' . esc_html( $root ) . '</span></div>';
            }
        }
        return '<div class="ui list"><div class="item"><i class="large middle aligned server icon"></i><div class="content"><strong>' . esc_html__( 'Dashboard', 'mainwp' ) . '</strong>' . $path . '</div></div></div>';
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
                $icons = array(
                    's3'      => 'cloud',
                    'gcs'     => 'google',
                    'dropbox' => 'dropbox',
                    'sftp'    => 'server',
                    'local'   => 'hdd outline',
                );
                $icon  = $icons[ $destination->provider ] ?? 'cloud';
                $path  = '';
                if ( ! is_wp_error( MainWP_Abilities_Util::check_unrestricted_access() ) && is_array( $destination->settings ) ) {
                    $path = (string) ( $destination->settings['bucket'] ?? $destination->settings['base_path'] ?? '' );
                }
                $names[] = '<div class="item"><i class="large middle aligned ' . esc_attr( $icon ) . ' icon" aria-hidden="true"></i><div class="content"><strong>' . esc_html( $destination->name ) . '</strong>' . ( '' !== $path ? '<div class="description"><span class="ui small text">' . esc_html( $path ) . '</span></div>' : '' ) . '</div></div>';
            }
        }

        if ( empty( $names ) ) {
            return '';
        }

        $output = '<div class="ui list">' . implode( '', $names ) . '</div>';

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
        return '<span class="ui small red label" data-tooltip="' . esc_attr( $reason ) . '" data-position="left center" data-inverted="">' . $label . '</span>';
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
    public function column_last_run( $item ) {
        return $this->column_details( $item );
    }

    /**
     * Render the next scheduled run as a relative time with its exact date.
     *
     * @param object $item Task row.
     * @return string
     */
    public function column_next_run( $item ) {
        $timestamp = $this->next_run_timestamp( $item );
        if ( $timestamp <= 0 ) {
            return esc_html( 1 === (int) ( $item->paused ?? 0 ) ? __( 'Paused', 'mainwp' ) : __( 'Any minute', 'mainwp' ) );
        }
        $now   = time();
        $label = $timestamp > $now
            /* translators: %s: time until the next run. */
            ? sprintf( __( 'In %s', 'mainwp' ), human_time_diff( $now, $timestamp ) )
            /* translators: %s: time since the run was due. */
            : sprintf( __( 'Due %s ago', 'mainwp' ), human_time_diff( $timestamp, $now ) );
        return '<span data-tooltip="' . esc_attr( MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $timestamp ) ) ) . '" data-position="left center" data-inverted="">' . esc_html( $label ) . '</span>';
    }

    /**
     * Resolve the authoritative schedule time, with a legacy interval fallback.
     *
     * @param object $item Task row.
     * @return int
     */
    private function next_run_timestamp( $item ) {
        if ( 1 === (int) ( $item->paused ?? 0 ) ) {
            return 0;
        }
        if ( ! empty( $item->next_run ) ) {
            return (int) $item->next_run;
        }
        if ( empty( $item->last_run ) ) {
            return 0;
        }
        $intervals = array(
            'daily'   => DAY_IN_SECONDS,
            'weekly'  => WEEK_IN_SECONDS,
            'monthly' => 30 * DAY_IN_SECONDS,
        );
        $interval  = 'interval' === $item->schedule ? max( 1, (int) ( $item->interval_hours ?? 1 ) ) * HOUR_IN_SECONDS : ( $intervals[ $item->schedule ] ?? 30 * DAY_IN_SECONDS );
        return (int) $item->last_run + $interval;
    }

    /**
     * Render the last-run timestamp, history tooltip, and active-run status.
     *
     * @param object $item Backup task row.
     * @return string Last-run column markup.
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

        $history  = sprintf( __( 'Last run: %s.', 'mainwp' ), $last_run ) . ' ' . $history;
        $relative = empty( $item->last_run ) ? __( 'Never', 'mainwp' ) : MainWP_Utility::time_elapsed_string( (int) $item->last_run );
        $output   = '<span data-tooltip="' . esc_attr( $history ) . '" data-position="left center" data-inverted="">' . esc_html( $relative ) . '</span>';

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

            // A recorded cancel replaces the action: the request is already
            // bound to this run, and a second click would only add a pass.
            $cancel_pending = MainWP_Backup_Run_Cancel::pending_request( $item );
            $cancel_action  = '';
            if ( null !== $cancel_pending ) {
                $cancel_action = '<br /><strong>' . esc_html__( 'Cancelling', 'mainwp' ) . ':</strong> ' . esc_html__( 'Waiting for the sites to confirm they stopped (up to 30 minutes).', 'mainwp' );
            } elseif ( MainWP_Install::backup_task_run_cancel_ready() && \mainwp_current_user_can( 'dashboard', 'pause_resume_backup_tasks' ) ) {
                $cancel_action = ' | <a href="#" class="mainwp-native-backup-task-cancel-run" task_id="' . (int) $item->id . '" run="' . (int) $item->last_run . '" onClick="return managebackups_cancel_run(this);">' . esc_html__( 'Cancel run', 'mainwp' ) . '</a>';
            }

            if ( $elapsed_seconds > MainWP_Backup_Executor::STALL_THRESHOLD_SECONDS ) {
                $output .= '<br /><strong>' . esc_html__( 'Stale', 'mainwp' ) . ':</strong> '
                    . sprintf(
                        /* translators: %s: elapsed time since the run started, e.g. "3h 20m". */
                        esc_html__( 'Running for %s. May be stuck.', 'mainwp' ),
                        esc_html( $elapsed_label )
                    )
                    . ' <a href="#" class="mainwp-native-backup-task-reset" task_id="' . (int) $item->id . '" onClick="return managebackups_reset_stale(this);">' . esc_html__( 'Reset', 'mainwp' ) . '</a>'
                    . $cancel_action;
            } else {
                $output .= '<br /><strong>' . esc_html__( 'Currently running', 'mainwp' ) . ':</strong> '
                    . count( $completed_sites ) . '/' . count( $item->the_sites )
                    . ' (' . sprintf(
                        /* translators: %s: elapsed time since the run started, e.g. "12m". */
                        esc_html__( 'running for %s', 'mainwp' ),
                        esc_html( $elapsed_label )
                    ) . ')'
                    . $cancel_action;
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
                $output .= '<br /><span class="ui small red label" data-tooltip="' . esc_attr( $error_text ) . '" data-position="left center" data-inverted="">'
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
                $output .= '<br /><span class="ui small blue label" data-tooltip="' . esc_attr( $notice_text ) . '" data-position="left center" data-inverted="">'
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
