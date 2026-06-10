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
            <div class="ui message" id="mainwp-message-zone" style="display:none"></div>
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

    /** Render New Task Form. */
    public static function render_new(): void {
        if ( ! \mainwp_current_user_can( 'dashboard', 'add_backup_tasks' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'add backup tasks', 'mainwp' ) );
            return;
        }
        MainWP_Backup_Page::render_header( 'AddNew' );
        ?>
        <div class="ui alt segment">
            <div class="ui message" id="mainwp-message-zone" style="display:none"></div>
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

        <div class="mainwp-main-content">
            <?php static::render_schedule_backup(); ?>
        </div>
        <div class="mainwp-side-content mainwp-no-padding">
            <div class="mainwp-select-sites">
                <div class="ui header"><?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
                <?php
                $sel_params = array(
                    'selected_sites'       => $selected_websites,
                    'selected_groups'      => $selected_groups,
                    'enable_offline_sites' => true,
                );
                MainWP_UI_Select_Sites::select_sites_box( $sel_params );
                ?>
            </div>
            <div class="ui divider"></div>
            <div class="mainwp-search-submit">
                <?php if ( ! empty( $task ) ) : ?>
                <input type="hidden" id="backup_task_id" value="<?php echo esc_attr( $task->id ); ?>"/>
                <input type="button" name="mainwp_managebackups_update" id="mainwp_managebackups_update" class="ui big green fluid button" value="<?php esc_attr_e( 'Update Schedule Backup', 'mainwp' ); ?>"/>
                <?php else : ?>
                <input type="button" name="mainwp_managebackups_add" id="mainwp_managebackups_add" class="ui big green fluid button" value="<?php esc_attr_e( 'Schedule Backup', 'mainwp' ); ?>"/>
                <?php endif; ?>
            </div>
        </div>
        <div class="ui hidden clearing divider"></div>
        <?php
    }

    /**
     * Render Scheduled Backup.
     *
     * @return void
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::can_edit_backuptask()
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::validate_backup_tasks()
     */
    public static function render_schedule_backup(): void {  //phpcs:ignore -- NOSONAR - complex.
        $backupTask   = null;
        $backupTaskId = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( ! empty( $backupTaskId ) ) {
            if ( ! \mainwp_current_user_can( 'dashboard', 'edit_backup_tasks' ) ) {
                \mainwp_do_not_have_permissions( esc_html__( 'edit backup tasks', 'mainwp' ) );
                return;
            }

            $backupTask = MainWP_DB_Backup::instance()->get_backup_task_by_id( $backupTaskId );
            if ( ! MainWP_Manage_Backups_Handler::can_edit_backuptask( $backupTask ) ) {
                $backupTask = null;
            }

            if ( ! empty( $backupTask ) && ! MainWP_Manage_Backups_Handler::validate_backup_tasks( array( $backupTask ) ) ) {
                $backupTask = MainWP_DB_Backup::instance()->get_backup_task_by_id( $backupTaskId );
            }
        }
        $task = $backupTask;

        $globalArchiveFormat = get_option( 'mainwp_archiveFormat' );
        if ( false === $globalArchiveFormat ) {
            $globalArchiveFormat = 'tar.gz';
        }
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

        <div class="ui divider hidden"></div>
        <div class="ui message" id="mainwp-message-zone" style="display:none"></div>
            <h3 class="header"><?php esc_html_e( 'Backup Details', 'mainwp' ); ?></h3>
            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Backup task name', 'mainwp' ); ?></label>
                <div class="ten wide column">
                    <input type="text" id="mainwp_managebackups_add_name" name="mainwp_managebackups_add_name" value="<?php echo isset( $task ) ? esc_html( stripslashes( $task->name ) ) : ''; ?>"/>
                </div>
            </div>
            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Backup file name', 'mainwp' ); ?></label>
                <div class="ten wide column">
                    <input type="text" id="backup_filename" name="backup_filename" value="<?php echo isset( $task ) ? esc_html( stripslashes( $task->filename ) ) : ''; ?>"/>
                </div>
            </div>
            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Backup type', 'mainwp' ); ?></label>
                <div class="ten wide column">
                    <select name="mainwp-backup-type" id="mainwp-backup-type" class="ui dropdown">
                        <option value="full" <?php echo ! isset( $task ) || 'full' === $task->type ? 'selected' : ''; ?>><?php esc_html_e( 'Full Backup', 'mainwp' ); ?></option>
                        <option value="db" <?php echo isset( $task ) && 'db' === $task->type ? 'selected' : ''; ?>><?php esc_html_e( 'Database Backup', 'mainwp' ); ?></option>
                    </select>
                </div>
            </div>
            <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Schedule', 'mainwp' ); ?></label>
                <div class="ten wide column">
                    <select name="mainwp-backup-task-schedule" id="mainwp-backup-task-schedule" class="ui dropdown">
                        <option value="daily" <?php echo( ! isset( $task ) || 'daily' === $task->schedule ? 'selected' : '' ); ?>><?php esc_html_e( 'Daily', 'mainwp' ); ?></option>
                        <option value="weekly" <?php echo( ! isset( $task ) || 'weekly' === $task->schedule ? 'selected' : '' ); ?>><?php esc_html_e( 'Weekly', 'mainwp' ); ?></option>
                        <option value="monthly" <?php echo( ! isset( $task ) || 'monthly' === $task->schedule ? 'selected' : '' ); ?>><?php esc_html_e( 'Monthly', 'mainwp' ); ?></option>
                    </select>
                </div>
            </div>
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
                </div>
            </div>
            <?php
            $style = isset( $task ) && 'db' === $task->type ? 'style="display: none;"' : '';
            ?>
            <div class="mainwp-backup-full-exclude" <?php echo $style; //phpcs:ignore -- ok. ?>>
                <h3 class="header"><?php esc_html_e( 'Backup Excludes', 'mainwp' ); ?></h3>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Known backup locations', 'mainwp' ); ?></label>
                    <div class="ten wide column ui toggle checkbox">
                        <input type="checkbox" id="mainwp-known-backup-locations" <?php echo( ! isset( $task ) || 1 === (int) $task->excludebackup ? 'checked' : '' ); ?>>
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"></label>
                    <div class="ten wide column ui toggle checkbox">
                        <textarea id="mainwp-kbl-content" disabled></textarea><br />
                        <em><?php esc_html_e( 'This adds known backup locations of popular WordPress backup plugins to the exclude list. Old backups can take up a lot of space and can cause your current MainWP backup to timeout.', 'mainwp' ); ?></em>
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Known cache locations', 'mainwp' ); ?></label>
                    <div class="ten wide column ui toggle checkbox">
                        <input type="checkbox" id="mainwp-known-cache-locations" <?php echo( ! isset( $task ) || 1 === (int) $task->excludecache ? 'checked' : '' ); ?>><br />
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"></label>
                    <div class="ten wide column ui toggle checkbox">
                        <textarea id="mainwp-kcl-content" disabled></textarea><br />
                        <em><?php esc_html_e( 'This adds known cache locations of popular WordPress cache plugins to the exclude list. A cache can be massive with thousands of files and can cause your current MainWP backup to timeout. Your cache will be rebuilt by your caching plugin when the backup is restored.', 'mainwp' ); ?></em>
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Non-WordPress folders', 'mainwp' ); ?></label>
                    <div class="ten wide column ui toggle checkbox">
                        <input type="checkbox" id="mainwp-non-wordpress-folders" <?php echo( ! isset( $task ) || 1 === (int) $task->excludenonwp ? 'checked' : '' ); ?>><br />
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"></label>
                    <div class="ten wide column ui toggle checkbox">
                        <textarea id="mainwp-nwl-content" disabled></textarea><br />
                        <em><?php esc_html_e( 'This adds folders that are not part of the WordPress core (wp-admin, wp-content and wp-include) to the exclude list. Non-WordPress folders can contain a large amount of data or may be a sub-domain or add-on domain that should be backed up individually and not with this backup.', 'mainwp' ); ?></em>
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
                        <textarea id="excluded_folders_list">
                        <?php
                            $excluded = ( isset( $task ) ? $task->exclude : '' );
                        if ( '' !== $excluded ) {
                            $excluded = explode( ',', $excluded );
                            $excluded = is_array( $excluded ) ? array_map( 'sanitize_text_field', $excluded ) : array();
                            echo implode( "/\n", $excluded ) . "/\n"; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        }
                        ?>
                        </textarea>
                    </div>
                </div>
            </div>
        <?php
    }
}
