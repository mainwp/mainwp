<?php
/**
 * MainWP Backup Task Form.
 *
 * Renders the add/edit backup task form on the Backups pages.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Task_Form
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Task_Form { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
     * Render edit.
     *
     * @param mixed $task Task to edit.
     */
    public static function render_edit( $task ): void {
        MainWP_Backup_Page::render_header( 'ManageBackupsEdit' );
        ?>
        <div class="ui alt segment">
            <form method="POST" action="" class="ui form">
                <?php MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' ); ?>
                <input type="hidden" name="mainwp_managebackups_edit_id" id="mainwp_managebackups_edit_id" value="<?php echo esc_attr( $task->id ); ?>"/>
                <?php
                static::render_new_edit( $task );
                ?>
            </form>
        </div>
        <?php
        MainWP_Backup_Page::render_footer( 'ManageBackupsEdit' );
    }

    /**
     * Render the new backup task form with its nonce and site selection.
     * Show the permission error instead when the user cannot add tasks.
     */
    public static function render_new(): void {
        if ( ! \mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'add backup tasks', 'mainwp' ) );
            return;
        }
        MainWP_Backup_Page::render_header( 'AddNew' );
        ?>
        <div class="ui alt segment">
            <form method="POST" action="" id="mainwp-backup-task-form" class="ui form">
                <?php MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' ); ?>
                <?php static::render_new_edit( null ); ?>
            </form>
        </div>
        <?php
        MainWP_Backup_Page::render_footer( 'AddNew' );
    }

    /**
     * Render New edit Form.
     *
     * @param mixed $task Task to edit.
     */
    public static function render_new_edit( $task ): void {
        $selected_websites = array();
        $selected_groups   = array();
        if ( ! empty( $task ) ) {
            if ( '' !== $task->sites ) {
                $selected_websites = explode( ',', $task->sites );
            }
            if ( '' !== $task->groups ) {
                $selected_groups = explode( ',', $task->groups );
            }
        }
        ?>

        <div class="mainwp-main-content" style="padding: 2em 1em;">
            <div class="ui message" id="mainwp-message-zone" style="display:none"></div>
            <?php if ( empty( $task ) ) : ?>
                <?php MainWP_Backup_Page::render_intro_message( 'mainwp-backup-task-add-info-message', __( 'A backup task backs up the sites you select on a schedule and copies each backup to the storage destinations you pick.', 'mainwp' ), 'blue' ); ?>
            <?php endif; ?>
            <?php static::render_schedule_backup( $task ); ?>
        </div>
        <div class="mainwp-side-content mainwp-no-padding">
            <div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
                <div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
                <div class="content active">
                <?php
                $sel_params = array(
                    'selected_sites'       => $selected_websites,
                    'selected_groups'      => $selected_groups,
                    'enable_offline_sites' => true,
                );
                MainWP_UI_Select_Sites::select_sites_box( $sel_params );
                ?>
                </div>
            </div>
            <div class="ui divider"></div>
            <div class="mainwp-search-submit">
                <?php if ( ! empty( $task ) ) : ?>
                <input type="hidden" id="backup_task_id" value="<?php echo esc_attr( $task->id ); ?>"/>
                <input type="button" name="mainwp_managebackups_update" id="mainwp_managebackups_update" class="ui big green fluid button" value="<?php esc_attr_e( 'Update Backup Task', 'mainwp' ); ?>"/>
                <?php else : ?>
                <input type="button" name="mainwp_managebackups_add" id="mainwp_managebackups_add" class="ui big green fluid button" value="<?php esc_attr_e( 'Schedule Backup', 'mainwp' ); ?>"/>
                <?php endif; ?>
            </div>
        </div>
        <div class="ui hidden clearing divider"></div>
        <?php
    }

    /**
     * Render the storage destinations selection. Cloud storage is opt-in
     * per task: with nothing selected, backups stay on the dashboard.
     *
     * @param object|null $task The task being edited, if any.
     *
     * @return void
     */
    public static function render_destinations_field( $task ): void {
        $destinations = MainWP_Backup_Storage_Destinations::instance()->list_destinations();

        $selected = array();
        if ( ! empty( $task ) && ! empty( $task->destinations ) ) {
            $decoded  = json_decode( (string) $task->destinations, true );
            $selected = is_array( $decoded ) ? array_map( 'intval', $decoded ) : array();
        } elseif ( empty( $task ) ) {
            $default = MainWP_Backup_Storage_Destinations::instance()->get_default();
            if ( null !== $default ) {
                $selected[] = (int) $default->id;
            }
        }
        ?>
        <div class="ui grid field">
            <label class="six wide column middle aligned"><?php esc_html_e( 'Upload to storage', 'mainwp' ); ?></label>
            <div class="ten wide column">
                <?php if ( empty( $destinations ) ) : ?>
                    <span class="ui small text"><?php esc_html_e( 'No storage destinations configured. Backups stay on this dashboard.', 'mainwp' ); ?>
                        <?php if ( ! is_wp_error( MainWP_Abilities_Util::check_unrestricted_access() ) && \mainwp_current_user_can( 'dashboard', 'download_backups' ) ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ManageBackupsStorage' ) ); ?>"><?php esc_html_e( 'Add a destination', 'mainwp' ); ?></a>
                        <?php endif; ?>
                    </span>
                <?php else : ?>
                    <select name="mainwp-backup-task-destinations" id="mainwp-backup-task-destinations" multiple="" class="ui multiple selection dropdown">
                        <option value=""><?php esc_html_e( 'Keep on dashboard only', 'mainwp' ); ?></option>
                        <?php foreach ( $destinations as $destination ) : ?>
                            <option value="<?php echo intval( $destination->id ); ?>" <?php echo in_array( (int) $destination->id, $selected, true ) ? 'selected' : ''; ?>><?php echo esc_html( $destination->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div><span class="ui small text"><?php esc_html_e( 'Completed backups upload to the selected destinations. With nothing selected they stay on this dashboard.', 'mainwp' ); ?></span></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the retention policy fields. Zero means no limit. A new task
     * starts with Keep last at the new-task default; an existing task shows
     * what it saved, so a task saved with no limit keeps showing none.
     *
     * @param object|null $task The task being edited, if any.
     *
     * @return void
     */
    public static function render_retention_fields( $task ): void {
        $retention_count      = isset( $task->retention_count ) ? (int) $task->retention_count : MainWP_Manage_Backups_Handler::NEW_TASK_RETENTION_COUNT;
        $retention_days       = isset( $task->retention_days ) ? (int) $task->retention_days : 0;
        $retention_storage_mb = isset( $task->retention_storage_mb ) ? (int) $task->retention_storage_mb : 0;
        $delete_cloud_copies  = ! isset( $task->delete_cloud_copies ) || 1 === (int) $task->delete_cloud_copies;
        $max_chain_length     = isset( $task->max_chain_length ) ? (int) $task->max_chain_length : 0;
        $chain_wrapper_style  = isset( $task->type ) && 'incremental' === $task->type ? '' : 'style="display:none"';
        ?>
        <h3 class="ui dividing header">
                <?php esc_html_e( 'Backup Retention', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Choose how many backups to keep and when older backups should be removed. Leave a limit empty to keep backups without that limit.', 'mainwp' ); ?></div>
            </h3>
        <div class="ui grid field">
            <label class="six wide column middle aligned"><?php esc_html_e( 'Keep last', 'mainwp' ); ?></label>
            <div class="ten wide column">
                <input type="number" name="mainwp-backup-retention-count" id="mainwp-backup-retention-count" min="0" placeholder="<?php esc_attr_e( 'No limit', 'mainwp' ); ?>" value="<?php echo $retention_count > 0 ? esc_attr( $retention_count ) : ''; ?>"/>
                <span class="ui small text"><?php esc_html_e( 'Number of backups to keep per site. Older backups are removed once newer ones complete, and the most recent backup always stays.', 'mainwp' ); ?></span>
            </div>
        </div>
        <div class="ui grid field">
            <label class="six wide column middle aligned"><?php esc_html_e( 'Remove backups older than (days)', 'mainwp' ); ?></label>
            <div class="ten wide column">
                <input type="number" name="mainwp-backup-retention-days" id="mainwp-backup-retention-days" min="0" placeholder="<?php esc_attr_e( 'No limit', 'mainwp' ); ?>" value="<?php echo $retention_days > 0 ? esc_attr( $retention_days ) : ''; ?>"/>
            </div>
        </div>
        <div class="ui grid field">
            <label class="six wide column middle aligned"><?php esc_html_e( 'Storage cap (MB)', 'mainwp' ); ?></label>
            <div class="ten wide column">
                <input type="number" name="mainwp-backup-retention-storage-mb" id="mainwp-backup-retention-storage-mb" min="0" placeholder="<?php esc_attr_e( 'No limit', 'mainwp' ); ?>" value="<?php echo $retention_storage_mb > 0 ? esc_attr( $retention_storage_mb ) : ''; ?>"/>
                <span class="ui small text"><?php esc_html_e( 'When this task\'s backups for a site exceed the cap, the oldest are removed until they fit.', 'mainwp' ); ?></span>
            </div>
        </div>
        <div class="ui grid field" id="mainwp-backup-max-chain-length-wrapper" <?php echo $chain_wrapper_style; //phpcs:ignore -- static attribute. ?>>
            <label class="six wide column middle aligned"><?php esc_html_e( 'Start a new chain after (backups)', 'mainwp' ); ?></label>
            <div class="ten wide column">
                <input type="number" name="mainwp-backup-max-chain-length" id="mainwp-backup-max-chain-length" min="0" placeholder="<?php esc_attr_e( 'No limit', 'mainwp' ); ?>" value="<?php echo $max_chain_length > 0 ? esc_attr( $max_chain_length ) : ''; ?>"/>
                <span class="ui small text"><?php esc_html_e( 'Once an incremental chain reaches this many backups, counting the full backup it builds on, the next run creates a fresh full backup and starts a new chain. Retention removes whole chains, so a limit lets older chains age out and keeps restore times steady.', 'mainwp' ); ?></span>
            </div>
        </div>
        <div class="ui grid field">
            <label class="six wide column middle aligned"><?php esc_html_e( 'Remove cloud copies too', 'mainwp' ); ?></label>
            <div class="ten wide column ui toggle checkbox">
                <input type="checkbox" id="mainwp-backup-delete-cloud-copies" <?php echo $delete_cloud_copies ? 'checked' : ''; ?>>
                <span class="ui small text"><?php esc_html_e( 'Removed backups are also deleted from the storage destinations they uploaded to.', 'mainwp' ); ?></span>
            </div>
        </div>
        <?php
    }

    /**
     * Render Scheduled Backup.
     *
     * @param mixed $task Authorized task to edit, or null for a new task.
     * @return void
     */
    public static function render_schedule_backup( $task = null ): void {
        $globalArchiveFormat = get_option( 'mainwp_archiveFormat' );
        if ( false === $globalArchiveFormat ) {
            $globalArchiveFormat = 'tar.gz';
        }
        $globalArchiveFormatText = 'Tar GZip';
        if ( 'zip' === $globalArchiveFormat ) {
            $globalArchiveFormatText = 'Zip';
        } elseif ( 'tar' === $globalArchiveFormat ) {
            $globalArchiveFormatText = 'Tar';
        } elseif ( 'tar.gz' === $globalArchiveFormat ) {
            $globalArchiveFormatText = 'Tar GZip';
        } elseif ( 'tar.bz2' === $globalArchiveFormat ) {
            $globalArchiveFormatText = 'Tar BZip2';
        }

        $archiveFormat = isset( $task ) ? $task->archiveFormat : 'site';
        $useGlobal     = ( 'global' === $archiveFormat );
        $useSite       = ( empty( $archiveFormat ) || 'site' === $archiveFormat );

        static::render_task_details( $task, $globalArchiveFormatText, $archiveFormat, $useGlobal, $useSite );
    }

    /**
     * Render Task Details.
     *
     * @param mixed $task Task to perform.
     * @param mixed $globalArchiveFormatText Global Archived Format Text.
     * @param mixed $archiveFormat Archive Format.
     * @param mixed $useGlobal Use Global.
     * @param mixed $useSite Use Site.
     */
    public static function render_task_details( $task, $globalArchiveFormatText, $archiveFormat, $useGlobal, $useSite ): void { // phpcs:ignore -- NOSONAR - complex.
        ?>

            <h3 class="ui dividing header">
                <?php esc_html_e( 'Backup Details', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Name your task, choose what to back up, and set its schedule and storage destinations.', 'mainwp' ); ?></div>
            </h3>
            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Backup task name', 'mainwp' ); ?> <span class="ui small red text"><?php esc_html_e( '(Required)', 'mainwp' ); ?></span></label>
                <div class="ten wide column">
                    <input type="text" id="mainwp_managebackups_add_name" name="mainwp_managebackups_add_name" value="<?php echo isset( $task ) ? esc_html( stripslashes( $task->name ) ) : ''; ?>"/>
                </div>
            </div>
            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Backup file name', 'mainwp' ); ?></label>
                <div class="ten wide column">
                    <input type="text" id="backup_filename" name="backup_filename" value="<?php echo isset( $task ) ? esc_html( stripslashes( $task->filename ) ) : ''; ?>"/>
                    <span class="ui small text"><?php esc_html_e( 'Allowed tokens: %sitename%, %url%, %date%, %time%, %type%.', 'mainwp' ); ?></span>
                </div>
            </div>
            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Backup type', 'mainwp' ); ?></label>
                <div class="ten wide column">
                    <select name="mainwp-backup-type" id="mainwp-backup-type" class="ui dropdown">
                        <option value="full" <?php echo ! isset( $task ) || 'full' === $task->type ? 'selected' : ''; ?>><?php esc_html_e( 'Full Backup', 'mainwp' ); ?></option>
                        <option value="db" <?php echo isset( $task ) && 'db' === $task->type ? 'selected' : ''; ?>><?php esc_html_e( 'Database Backup', 'mainwp' ); ?></option>
                        <option value="incremental" <?php echo isset( $task ) && 'incremental' === $task->type ? 'selected' : ''; ?>><?php esc_html_e( 'Incremental Backup', 'mainwp' ); ?></option>
                        <option value="files" <?php echo isset( $task ) && 'files' === $task->type ? 'selected' : ''; ?>><?php esc_html_e( 'Files Only Backup', 'mainwp' ); ?></option>
                    </select>
                </div>
            </div>
            <?php $incremental_note_style = isset( $task ) && 'incremental' === $task->type ? '' : 'style="display:none"'; ?>
            <div class="ui blue message" id="mainwp-backup-incremental-note" <?php echo $incremental_note_style; //phpcs:ignore -- static attribute. ?>>
                <p><?php esc_html_e( 'Incremental backups copy only the files that changed since the previous run in the chain and include a fresh database dump every time, so each run restores to its own point in time. Sites running a MainWP Child version without incremental support receive a full backup on every run, with a note recorded on the task.', 'mainwp' ); ?></p>
                <p><?php esc_html_e( 'The first run of this task, including Run Now, makes a full backup automatically. Later runs back up only what changed.', 'mainwp' ); ?></p>
            </div>
            <?php $files_note_style = isset( $task ) && 'files' === $task->type ? '' : 'style="display:none"'; ?>
            <div class="ui blue message" id="mainwp-backup-files-note" <?php echo $files_note_style; //phpcs:ignore -- static attribute. ?>>
                <?php esc_html_e( 'Files only backups archive the site\'s files without a database dump. Pair this task with a Database Backup task to cover the whole site. Because the archive carries no database, the dashboard cannot restore a site from it; download the archive to recover individual files.', 'mainwp' ); ?>
            </div>
            <?php
            $schedule       = isset( $task->schedule ) ? (string) $task->schedule : 'daily';
            $interval_hours = isset( $task->interval_hours ) && (int) $task->interval_hours > 0 ? (int) $task->interval_hours : 1;
            $time_of_day    = isset( $task->time_of_day ) ? (string) $task->time_of_day : '';
            $day_of_week    = isset( $task->day_of_week ) ? (int) $task->day_of_week : 0;
            $day_of_month   = isset( $task->day_of_month ) && (int) $task->day_of_month > 0 ? (int) $task->day_of_month : 1;

            $interval_style = 'interval' === $schedule ? '' : 'style="display:none"';
            $tod_style      = in_array( $schedule, array( 'daily', 'weekly', 'monthly' ), true ) ? '' : 'style="display:none"';
            $dow_style      = 'weekly' === $schedule ? '' : 'style="display:none"';
            $dom_style      = 'monthly' === $schedule ? '' : 'style="display:none"';

            $week_days = array(
                esc_html__( 'Sunday', 'mainwp' ),
                esc_html__( 'Monday', 'mainwp' ),
                esc_html__( 'Tuesday', 'mainwp' ),
                esc_html__( 'Wednesday', 'mainwp' ),
                esc_html__( 'Thursday', 'mainwp' ),
                esc_html__( 'Friday', 'mainwp' ),
                esc_html__( 'Saturday', 'mainwp' ),
            );
            ?>
            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Schedule', 'mainwp' ); ?></label>
                <div class="ten wide column">
                    <select name="mainwp-backup-task-schedule" id="mainwp-backup-task-schedule" class="ui dropdown">
                        <option value="daily" <?php echo 'daily' === $schedule ? 'selected' : ''; ?>><?php esc_html_e( 'Daily', 'mainwp' ); ?></option>
                        <option value="weekly" <?php echo 'weekly' === $schedule ? 'selected' : ''; ?>><?php esc_html_e( 'Weekly', 'mainwp' ); ?></option>
                        <option value="monthly" <?php echo 'monthly' === $schedule ? 'selected' : ''; ?>><?php esc_html_e( 'Monthly', 'mainwp' ); ?></option>
                        <option value="interval" <?php echo 'interval' === $schedule ? 'selected' : ''; ?>><?php esc_html_e( 'Every N hours', 'mainwp' ); ?></option>
                    </select>
                </div>
            </div>
            <div class="ui grid field" id="mainwp-schedule-interval-wrapper" <?php echo $interval_style; //phpcs:ignore -- static attribute. ?>>
                <label class="six wide column middle aligned"><?php esc_html_e( 'Run every (hours)', 'mainwp' ); ?></label>
                <div class="ten wide column">
                    <input type="number" name="mainwp-schedule-interval-hours" id="mainwp-schedule-interval-hours" min="1" max="8760" value="<?php echo esc_attr( $interval_hours ); ?>"/>
                </div>
            </div>
            <div class="ui grid field" id="mainwp-schedule-time-of-day-wrapper" <?php echo $tod_style; //phpcs:ignore -- static attribute. ?>>
                <label class="six wide column middle aligned"><?php esc_html_e( 'Time of day (optional)', 'mainwp' ); ?></label>
                <div class="ten wide column">
                    <div class="time-selector" id="mainwp-backup-time-selector">
                        <div class="ui input left icon">
                            <i class="clock icon"></i>
                            <input type="text" name="mainwp-schedule-time-of-day" id="mainwp-schedule-time-of-day" value="<?php echo esc_attr( $time_of_day ); ?>" autocomplete="off"/>
                        </div>
                    </div>
                    <script type="text/javascript">
                        jQuery( function () {
                            jQuery( '#mainwp-backup-time-selector' ).calendar( {
                                type: 'time',
                                ampm: false,
                                formatter: { time: 'HH:mm', cellTime: 'H:mm' }
                            } );
                        } );
                    </script>
                    <span class="ui small text"><?php esc_html_e( 'Runs at this time in the WordPress site timezone. Leave empty to run whenever the schedule interval has elapsed.', 'mainwp' ); ?></span>
                </div>
            </div>
            <div class="ui grid field" id="mainwp-schedule-day-of-week-wrapper" <?php echo $dow_style; //phpcs:ignore -- static attribute. ?>>
                <label class="six wide column middle aligned"><?php esc_html_e( 'Day of week', 'mainwp' ); ?></label>
                <div class="ten wide column">
                    <select name="mainwp-schedule-day-of-week" id="mainwp-schedule-day-of-week" class="ui dropdown">
                        <?php foreach ( $week_days as $day_index => $day_label ) : ?>
                            <option value="<?php echo intval( $day_index ); ?>" <?php echo $day_index === $day_of_week ? 'selected' : ''; ?>><?php echo $day_label; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="ui grid field" id="mainwp-schedule-day-of-month-wrapper" <?php echo $dom_style; //phpcs:ignore -- static attribute. ?>>
                <label class="six wide column middle aligned"><?php esc_html_e( 'Day of month', 'mainwp' ); ?></label>
                <div class="ten wide column">
                    <input type="number" name="mainwp-schedule-day-of-month" id="mainwp-schedule-day-of-month" min="1" max="28" value="<?php echo esc_attr( $day_of_month ); ?>"/>
                    <span class="ui small text"><?php esc_html_e( 'Days 1 through 28, so the schedule lands in every month.', 'mainwp' ); ?></span>
                </div>
            </div>
            <?php static::render_destinations_field( $task ); ?>
            <?php static::render_retention_fields( $task ); ?>
            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Archive type', 'mainwp' ); ?></label>
                <div class="ten wide column">
                    <select name="mainwp_archiveFormat" id="mainwp_archiveFormat" class="ui dropdown">
                        <option value="site"
                        <?php
                        if ( $useSite ) :
                            ?>
                            selected<?php endif; ?>><?php esc_html_e( 'Site specific setting', 'mainwp' ); ?></option>
                        <option value="global"
                        <?php
                        if ( $useGlobal ) :
                            ?>
                            selected<?php endif; ?>><?php esc_html_e( 'Global setting', 'mainwp' ); ?> (<?php echo esc_html( $globalArchiveFormatText ); ?>)</option>
                        <option value="zip"
                        <?php
                        if ( 'zip' === $archiveFormat ) :
                            ?>
                            selected<?php endif; ?>><?php esc_html_e( 'Zip', 'mainwp' ); ?></option>
                        <option value="tar"
                        <?php
                        if ( 'tar' === $archiveFormat ) :
                            ?>
                            selected<?php endif; ?>><?php esc_html_e( 'Tar', 'mainwp' ); ?></option>
                        <option value="tar.gz"
                        <?php
                        if ( 'tar.gz' === $archiveFormat ) :
                            ?>
                            selected<?php endif; ?>><?php esc_html_e( 'Tar GZip', 'mainwp' ); ?></option>
                        <option value="tar.bz2"
                        <?php
                        if ( 'tar.bz2' === $archiveFormat ) :
                            ?>
                            selected<?php endif; ?>><?php esc_html_e( 'Tar BZip2', 'mainwp' ); ?></option>
                    </select>
                    <div><span class="ui small text mainwp-archive-format-note"><?php esc_html_e( 'For large sites, Tar GZip is the safer choice. Zip rewrites the whole archive after every slice, so it gets slower as the backup grows and needs as much free disk as the archive itself. Tar GZip appends instead.', 'mainwp' ); ?></span></div>
                </div>
            </div>
            <?php $zip_override_style = 'zip' === $archiveFormat ? '' : 'style="display:none"'; ?>
            <div class="ui grid field" id="mainwp-backup-zip-force-override-wrapper" <?php echo $zip_override_style; //phpcs:ignore -- static attribute. ?>>
                <label class="six wide column middle aligned"><?php esc_html_e( 'Keep Zip for large sites', 'mainwp' ); ?></label>
                <div class="ten wide column">
                    <div class="ui toggle checkbox">
                        <input type="checkbox" name="mainwp_zip_force_override" id="mainwp-backup-zip-force-override" value="1" <?php echo( isset( $task->zip_force_override ) && 1 === (int) $task->zip_force_override ? 'checked' : '' ); ?>>
                        <label><?php esc_html_e( 'Keep Zip even when the site is large', 'mainwp' ); ?></label>
                    </div>
                    <div><span class="ui small text"><?php esc_html_e( 'A large site on Zip is switched to Tar GZip so the backup can finish across timeouts. Enable this to keep Zip anyway.', 'mainwp' ); ?></span></div>
                </div>
            </div>
            <?php
            $style = isset( $task ) && 'db' === $task->type ? 'style="display: none;"' : '';
            ?>
            <div class="mainwp-backup-full-exclude" <?php echo $style; //phpcs:ignore -- ok. ?>>
                <h3 class="ui dividing header">
                <?php esc_html_e( 'Backup Excludes', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Choose which files and folders to leave out of this task to reduce backup size and avoid unnecessary data.', 'mainwp' ); ?></div>
            </h3>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Known backup locations', 'mainwp' ); ?></label>
                    <div class="ten wide column ui toggle checkbox">
                        <input type="checkbox" id="mainwp-known-backup-locations" <?php echo( ! isset( $task ) || 1 === (int) $task->excludebackup ? 'checked' : '' ); ?>>
                        <span class="ui small text"><?php esc_html_e( 'This adds known backup locations of popular WordPress backup plugins to the exclude list. Old backups can take up a lot of space and can cause your current MainWP backup to time out.', 'mainwp' ); ?></span>
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Known cache locations', 'mainwp' ); ?></label>
                    <div class="ten wide column ui toggle checkbox">
                        <input type="checkbox" id="mainwp-known-cache-locations" <?php echo( ! isset( $task ) || 1 === (int) $task->excludecache ? 'checked' : '' ); ?>>
                        <span class="ui small text"><?php esc_html_e( 'This adds known cache locations of popular WordPress cache plugins to the exclude list. A cache can be massive with thousands of files and can cause your current MainWP backup to time out. Your cache will be rebuilt by your caching plugin when the backup is restored.', 'mainwp' ); ?></span>
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Non-WordPress folders', 'mainwp' ); ?></label>
                    <div class="ten wide column ui toggle checkbox">
                        <input type="checkbox" id="mainwp-non-wordpress-folders" <?php echo( ! isset( $task ) || 1 === (int) $task->excludenonwp ? 'checked' : '' ); ?>>
                        <span class="ui small text"><?php esc_html_e( 'This adds folders that are not part of the WordPress core (wp-admin, wp-content and wp-include) to the exclude list. Non-WordPress folders can contain a large amount of data or may be a sub-domain or add-on domain that should be backed up individually and not with this backup.', 'mainwp' ); ?></span>
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'ZIP archives', 'mainwp' ); ?></label>
                    <div class="ten wide column ui toggle checkbox">
                        <input type="checkbox" id="mainwp-zip-archives" <?php echo( ! isset( $task ) || 1 === (int) $task->excludezip ? 'checked' : '' ); ?>>
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Custom excludes', 'mainwp' ); ?></label>
                    <div class="ten wide column ui toggle checkbox">
                        <?php
                        $excluded_paths = isset( $task ) ? explode( ',', (string) $task->exclude ) : array();
                        $excluded_paths = array_filter( array_map( 'trim', $excluded_paths ), 'strlen' );
                        $excluded_text  = empty( $excluded_paths ) ? '' : implode( "/\n", $excluded_paths ) . "/\n";
                        ?>
                        <textarea id="excluded_folders_list"><?php echo esc_textarea( $excluded_text ); ?></textarea>
                    </div>
                </div>
            </div>
        <?php
    }
}
