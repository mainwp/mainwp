<?php
/**
 * MainWP Backup Settings View.
 *
 * Renders the global backup settings section on the Settings page and
 * the per-site backup settings accordion on the site edit page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Settings_View
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Settings_View { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
     * Method render_settings()
     *
     * Render backup settings.
     */
    public static function render_settings(): void {  //phpcs:ignore -- NOSONAR - complex.

        $backupsOnServer            = get_option( 'mainwp_backupsOnServer' );
        $backupOnExternalSources    = get_option( 'mainwp_backupOnExternalSources' );
        $archiveFormat              = get_option( 'mainwp_archiveFormat' );
        $maximumFileDescriptors     = get_option( 'mainwp_maximumFileDescriptors' );
        $maximumFileDescriptorsAuto = get_option( 'mainwp_maximumFileDescriptorsAuto' );
        $maximumFileDescriptorsAuto = ( 1 === $maximumFileDescriptorsAuto || false === $maximumFileDescriptorsAuto );

        $notificationOnBackupFail  = get_option( 'mainwp_notificationOnBackupFail' );
        $notificationOnBackupStart = get_option( 'mainwp_notificationOnBackupStart' );
        $chunkedBackupTasks        = get_option( 'mainwp_chunkedBackupTasks' );
        $enableNativeBackups       = MainWP_Backup_Settings::is_native_backups_enabled();

        $loadFilesBeforeZip = get_option( 'mainwp_options_loadFilesBeforeZip' );
        $loadFilesBeforeZip = ( 1 === $loadFilesBeforeZip || false === $loadFilesBeforeZip );

        $primaryBackup        = get_option( 'mainwp_primaryBackup' );
        $primary_methods      = array();
        $primary_methods      = apply_filters_deprecated( 'mainwp-getprimarybackup-methods', array( $primary_methods ), '4.0.7.2', 'mainwp_getprimarybackup_methods' );  // @deprecated Use 'mainwp_getprimarybackup_methods' instead. NOSONAR - not IP.
        $primaryBackupMethods = apply_filters( 'mainwp_getprimarybackup_methods', $primary_methods );

        if ( ! is_array( $primaryBackupMethods ) ) {
            $primaryBackupMethods = array();
        }

        /**
         * MainWP use external primary backup method.
         *
         * @global string
         */
        global $mainwpUseExternalPrimaryBackupsMethod;

        $hidden_elems = false;
        if ( ! $enableNativeBackups || ( ! empty( $primaryBackup ) && $primaryBackup === $mainwpUseExternalPrimaryBackupsMethod ) ) {
            $hidden_elems = true;
        }
        ?>
        <h3 class="ui dividing header">
            <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-backups' ); ?>
            <?php esc_html_e( 'Backup Settings', 'mainwp' ); ?>
            <div class="sub header"><?php /* translators: 1: opening anchor tag, 2: closing anchor tag */ printf( esc_html__( 'MainWP is actively moving away from further development of the native backups feature. The best long-term solution would be one of the %1$sBackup Extensions%2$s.', 'mainwp' ), '<a href="https://mainwp.com/extensions/extension-category/backups/" target="_blank" ?>', '</a>' ); // NOSONAR - noopener - open safe. ?></div>
        </h3>
        <?php if ( ! empty( $primaryBackupMethods ) ) : ?>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-backups">
            <label class="six wide column middle aligned"><?php esc_html_e( 'Select primary backup system', 'mainwp' ); ?></label>
            <div class="ten wide column">
                <select class="ui dropdown" name="mainwp_primaryBackup" id="mainwp_primaryBackup">
                    <?php if ( $enableNativeBackups || empty( $primaryBackup ) ) { ?>
                        <option value="" ><?php esc_html_e( 'Native backups', 'mainwp' ); ?></option>
                    <?php } ?>
                    <?php
                    foreach ( $primaryBackupMethods as $method ) {
                        echo '<option value="' . esc_attr( $method['value'] ) . '" ' . ( ( $primaryBackup === $method['value'] ) ? 'selected' : '' ) . '>' . esc_html( $method['title'] ) . '</option>';
                    }
                    ?>

                </select>
            </div>
        </div>
        <?php endif; ?>

        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-backups">
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_enableNativeBackups', (int) $enableNativeBackups );
            esc_html_e( 'Enable native backups', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column ui toggle checkbox">
                <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_options_enableNativeBackups" id="mainwp_options_enableNativeBackups" <?php echo 0 === (int) $enableNativeBackups ? '' : 'checked="true"'; ?>/>
            </div>
        </div>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-backups" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?> default-indi-value="1">
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_backupsOnServer', (int) $backupsOnServer );
            esc_html_e( 'Backups on server', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column">
                <input type="text" class="settings-field-value-change-handler" name="mainwp_options_backupOnServer" value="<?php echo false === $backupsOnServer ? 1 : intval( $backupsOnServer ); ?>"/>
            </div>
        </div>

        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-backups" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?> default-indi-value="1">
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_backupOnExternalSources', (int) $backupOnExternalSources );
            esc_html_e( 'Backups on remote storage', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column">
                <span data-tooltip="<?php esc_attr_e( 'The number of backups to keep on your external sources. This does not affect backups on the server. 0 sets unlimited.', 'mainwp' ); ?>" data-inverted=""><input type="text" class="settings-field-value-change-handler" name="mainwp_options_backupOnExternalSources" value="<?php echo false === $backupOnExternalSources ? 1 : intval( $backupOnExternalSources ); ?>"/>
            </div>
        </div>

        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-backups" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?> default-indi-value="tar.gz">
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_archiveFormat', $archiveFormat );
            esc_html_e( 'Archive format', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column">
                <select class="ui dropdown settings-field-value-change-handler" name="mainwp_archiveFormat" id="mainwp_archiveFormat">
                    <option value="zip"
                    <?php
                    if ( 'zip' === $archiveFormat ) :
                        ?>
                        selected<?php endif; ?>>Zip</option>
                    <option value="tar"
                    <?php
                    if ( 'tar' === $archiveFormat ) :
                        ?>
                        selected<?php endif; ?>>Tar</option>
                    <option value="tar.gz"
                    <?php
                    if ( ( false === $archiveFormat ) || ( 'tar.gz' === $archiveFormat ) ) :
                        ?>
                        selected<?php endif; ?>>Tar GZip</option>
                    <option value="tar.bz2"
                    <?php
                    if ( 'tar.bz2' === $archiveFormat ) :
                        ?>
                        selected<?php endif; ?>>Tar BZip2</option>
                </select>
            </div>
        </div>

        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-backups" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?>
        <?php
        if ( empty( $hidden_elems ) && 'zip' !== $archiveFormat ) {
            echo 'style="display: none;"';
        }
        ?>
        default-indi-value="1"
        >
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_maximumFileDescriptorsAuto', (int) $maximumFileDescriptorsAuto );
            esc_html_e( 'Auto detect maximum file descriptors on child sites', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column ui toggle checkbox">
                <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_maximumFileDescriptorsAuto" id="mainwp_maximumFileDescriptorsAuto" value="1" <?php echo $maximumFileDescriptorsAuto ? 'checked="checked"' : ''; ?>/>
            </div>
        </div>

        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-backups" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?>
        <?php
        if ( empty( $hidden_elems ) && 'zip' !== $archiveFormat ) {
            echo 'style="display: none;"';
        }
        ?>
        default-indi-value="155" >
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_maximumFileDescriptors', (int) $maximumFileDescriptors );
            esc_html_e( 'Maximum file descriptors fallback value', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column">
                <input type="text" class="settings-field-value-change-handler" name="mainwp_options_maximumFileDescriptors" id="mainwp_options_maximumFileDescriptors" value="<?php echo false === $maximumFileDescriptors ? 150 : intval( $maximumFileDescriptors ); ?>"/>
            </div>
        </div>

        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-backups" default-indi-value="1" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?>
        <?php
        if ( empty( $hidden_elems ) && 'zip' !== $archiveFormat ) {
            echo 'style="display: none;"';
        }
        ?>
        >
            <label class="six wide column middle aligned">
                <?php
                MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_options_loadFilesBeforeZip', (int) $loadFilesBeforeZip );
                esc_html_e( 'Load files in memory before zipping', 'mainwp' );
                ?>
            </label>
            <div class="ten wide column ui toggle checkbox">
                <input type="checkbox" class="settings-field-value-change-handler" inverted-value="1" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip" value="1" <?php echo $loadFilesBeforeZip ? 'checked="checked"' : ''; ?>/>
            </div>
        </div>

        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-backups" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?> default-indi-value="1">
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_notificationOnBackupFail', (int) $notificationOnBackupFail );
            esc_html_e( 'Send email when backup fails', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column ui toggle checkbox">
                <input type="checkbox" class="settings-field-value-change-handler" inverted-value="1" name="mainwp_options_notificationOnBackupFail" id="mainwp_options_notificationOnBackupFail" value="1" <?php echo $notificationOnBackupFail ? 'checked="checked"' : ''; ?>/>
            </div>
        </div>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-backups" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?> default-indi-value="1">
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_notificationOnBackupStart', (int) $notificationOnBackupStart );
            esc_html_e( 'Send email when backup starts', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column ui toggle checkbox">
                <input type="checkbox" class="settings-field-value-change-handler" inverted-value="1" name="mainwp_options_notificationOnBackupStart"  id="mainwp_options_notificationOnBackupStart" value="1" <?php echo $notificationOnBackupStart ? 'checked="checked"' : ''; ?>/>
            </div>
        </div>

        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-backups" <?php echo $hidden_elems ? 'style="display:none"' : ''; ?> default-indi-value="1">
            <label class="six wide column middle aligned">
            <?php
            MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_chunkedBackupTasks', (int) $chunkedBackupTasks );
            esc_html_e( 'Execute backup tasks in chunks', 'mainwp' );
            ?>
            </label>
            <div class="ten wide column ui toggle checkbox">
                <input type="checkbox" class="settings-field-value-change-handler" inverted-value="1" name="mainwp_options_chunkedBackupTasks"  id="mainwp_options_chunkedBackupTasks" value="1" <?php echo $chunkedBackupTasks ? 'checked="checked"' : ''; ?>/>
            </div>
        </div>
        <?php
    }

    /**
     * Method render_individual_settings()
     *
     * Render per-site backup settings.
     *
     * @param mixed $website Website data.
     */
    public static function render_individual_settings( $website ): void {  //phpcs:ignore -- NOSONAR - complex.

        $primaryBackup        = $website && ! empty( $website->primary_backup_method ) ? $website->primary_backup_method : 'global';
        $primary_methods      = array();
        $primary_methods      = apply_filters_deprecated( 'mainwp-getprimarybackup-methods', array( $primary_methods ), '4.0.7.2', 'mainwp_getprimarybackup_methods' );  // @deprecated Use 'mainwp_getprimarybackup_methods' instead. NOSONAR - not IP.
        $primaryBackupMethods = apply_filters( 'mainwp_getprimarybackup_methods', $primary_methods );

        if ( ! is_array( $primaryBackupMethods ) ) {
            $primaryBackupMethods = array();
        }

        ?>
        <div class="ui basic accordion mainwp-blank-accordion mainwp-sidebar-accordion" id="mainwp-edit-site-backup-settings-accordion">
            <h2 class="ui dividing header title">
                <i class="right dropdown icon"></i>
                <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-backups' ); ?>
                <?php esc_html_e( 'Backup Settings', 'mainwp' ); ?>
                <div class="sub header"><?php /* translators: 1: opening anchor tag, 2: closing anchor tag */ printf( esc_html__( 'MainWP is actively moving away from further development of the native backups feature. The best long-term solution would be one of the %1$sBackup Extensions%2$s.', 'mainwp' ), '<a href="https://mainwp.com/extensions/extension-category/backups/" target="_blank" ?>', '</a>' ); // NOSONAR - noopener - open safe. ?></div>
            </h2>
            <div class="content">
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-wrapper settings-field-indicator-backups">
            <label class="six wide column middle aligned"><?php esc_html_e( 'Select primary backup system', 'mainwp' ); ?></label>
            <div class="ten wide column">
                <select class="ui dropdown" name="mainwp_primaryBackup" id="mainwp_primaryBackup">
                    <?php
                    echo '<option value="global" ' . ( 'global' === $primaryBackup ? 'selected' : '' ) . '>' . esc_html__( 'Use global setting', 'mainwp' ) . '</option>';
                    foreach ( $primaryBackupMethods as $method ) {
                        echo '<option value="' . esc_attr( $method['value'] ) . '" ' . ( ( $primaryBackup === $method['value'] ) ? 'selected' : '' ) . '>' . esc_html( $method['title'] ) . '</option>';
                    }
                    ?>

                </select>
            </div>
        </div>
            </div>
        </div>
        <?php
    }
}
