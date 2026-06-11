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
        foreach ( $columns as $column_name => $title ) {
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
            'edit'   => sprintf( '<a class="item" href="admin.php?page=ManageBackups&id=%s"><i class="edit outline icon"></i> ' . esc_html__( 'Edit', 'mainwp' ) . '</a>', $item->id ),
            'delete' => sprintf( '<a class="submitdelete item" href="#" task_id="%s" onClick="return managebackups_remove(this);"><i class="trash alternate outline icon"></i> ' . esc_html__( 'Delete', 'mainwp' ) . '</a>', $item->id ),
        );

        if ( ! \mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) {
            unset( $actions['edit'] );
        }

        if ( ! \mainwp_current_user_can( 'dashboard', 'delete_backup_tasks' ) ) {
            unset( $actions['delete'] );
        }

        if ( 1 === (int) $item->paused ) {
            if ( \mainwp_current_user_can( 'dashboard', 'pause_resume_backup_tasks' ) ) {
                $actions['resume'] = sprintf( '<a href="#" class="item" task_id="%s" onClick="return managebackups_resume(this)"><i class="play icon"></i> ' . esc_html__( 'Resume', 'mainwp' ) . '</a>', $item->id );
            }
        } elseif ( \mainwp_current_user_can( 'dashboard', 'pause_resume_backup_tasks' ) ) {
                $actions['pause'] = sprintf( '<a href="#" class="item" task_id="%s" onClick="return managebackups_pause(this)"><i class="pause icon"></i> ' . esc_html__( 'Pause', 'mainwp' ) . '</a>', $item->id );
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
        return stripslashes( $item->name );
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
        return strtoupper( $item->schedule );
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
            return trim( $extraOutput, '<br />' );
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
                $output .= ' ' . $this->upload_state_label( $jobs );
            }
        }

        return $output;
    }

    /**
     * The worst-state-wins label for a snapshot's upload jobs.
     *
     * @param object[] $jobs Upload job rows.
     *
     * @return string Label markup.
     */
    private function upload_state_label( $jobs ) {
        $statuses = array_column( $jobs, 'status' );

        if ( in_array( MainWP_Backup_Upload_Job_Store::STATUS_CREDENTIALS_INVALID, $statuses, true ) ) {
            return '<span class="ui small red label">' . esc_html__( 'Re-enter credentials', 'mainwp' ) . '</span>';
        }
        if ( in_array( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, $statuses, true ) ) {
            return '<span class="ui small red label">' . esc_html__( 'Upload failed', 'mainwp' ) . '</span>';
        }
        if ( count( array_keys( $statuses, MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, true ) ) === count( $statuses ) ) {
            return '<span class="ui small green label">' . esc_html__( 'Uploaded', 'mainwp' ) . '</span>';
        }

        return '<span class="ui small blue label">' . esc_html__( 'Uploading', 'mainwp' ) . '</span>';
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
            $output         .= esc_html__( '<br />CURRENTLY RUNNING: ', 'mainwp' ) . '</strong>';
            $completed_sites = $item->completed_sites;
            if ( '' !== $completed_sites ) {
                $completed_sites = json_decode( $completed_sites, 1 );
            }
            if ( ! is_array( $completed_sites ) ) {
                $completed_sites = array();
            }
            $output .= count( $completed_sites ) . '/' . count( $item->the_sites );
        }
        return $output;
    }

    /**
     *  Column Trigger.
     *
     * @param mixed $item Item to go in column.
     *
     * @return string Action content.
     */
    public function column_trigger( $item ) {
        return '<span class="backup_run_loading"><img src="' . MAINWP_PLUGIN_URL . 'assets/images/loader.gif" /></span>&nbsp;<a href="#" class="backup_run_now" task_id="' . $item->id . '" task_type="' . $item->type . '">' . esc_html__( 'Run now', 'mainwp' ) . '</a>';
    }
}
