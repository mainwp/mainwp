<?php
/**
 * MainWP Backup Settings.
 *
 * Configuration surface for the backup system: exclude-list
 * sanitization, archive format resolution, concurrency limits, and the
 * backup settings form handler.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Settings
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Settings { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
     * Method sanitize_exclude_list()
     *
     * Sanitize a backup exclude list before it is sent to a child site.
     * Entries name folders relative to the child WordPress root, so each
     * entry is reduced to a safe relative path: null bytes and control
     * characters are stripped, backslashes are normalized, Windows drive
     * prefixes and leading slashes are removed, and dot or dot-dot path
     * segments are dropped. Entries with nothing safe left are discarded.
     *
     * @param string|array $exclude Exclude list as a newline- or comma-separated string, or an array of entries.
     *
     * @return string Comma-separated list of sanitized child-relative folder paths.
     */
    public static function sanitize_exclude_list( string|array $exclude ): string {
        if ( ! is_array( $exclude ) ) {
            $exclude = preg_split( '/[\n,]/', $exclude );
        }

        $clean = array();
        foreach ( $exclude as $entry ) {
            $entry = preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $entry );
            $entry = str_replace( '\\', '/', $entry );
            $entry = preg_replace( '/^[A-Za-z]:/', '', $entry );

            $segments = array();
            foreach ( explode( '/', $entry ) as $segment ) {
                $segment = trim( $segment );
                if ( '' === $segment || '.' === $segment || '..' === $segment ) {
                    continue;
                }
                $segments[] = $segment;
            }

            if ( ! empty( $segments ) ) {
                $clean[] = implode( '/', $segments );
            }
        }

        return implode( ',', $clean );
    }

    /**
     * Method get_current_archive_extension()
     *
     * Get extension of current Archive.
     *
     * @param object|false $website Website object, or false to use the global format.
     * @param object|false $task    Backup task object, or false when there is no task context.
     *
     * @return string Format of Archive.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_website_backup_settings()
     */
    public static function get_current_archive_extension( object|false $website = false, object|false $task = false ): string {
        $useSite = true;
        if ( false !== $task ) {
            if ( 'global' === $task->archiveFormat ) {
                $useGlobal = true;
                $useSite   = false;
            } elseif ( '' === $task->archiveFormat || 'site' === $task->archiveFormat ) {
                $useGlobal = false;
                $useSite   = true;
            } else {
                $archiveFormat = $task->archiveFormat;
                $useGlobal     = false;
                $useSite       = false;
            }
        }

        if ( $useSite ) {
            if ( false === $website ) {
                $useGlobal = true;
            } else {
                // A site that never saved per-site backup settings has no row; treat it as using the global format.
                $backupSettings = MainWP_DB_Backup::instance()->get_website_backup_settings( $website->id );
                $archiveFormat  = empty( $backupSettings ) || empty( $backupSettings->archiveFormat ) ? 'global' : $backupSettings->archiveFormat;
                $useGlobal      = ( 'global' === $archiveFormat );
            }
        }

        if ( $useGlobal ) {
            $archiveFormat = get_option( 'mainwp_archiveFormat' );
            if ( false === $archiveFormat ) {
                $archiveFormat = MainWP_Archive_Format::TarGz->value;
            }
        }

        return $archiveFormat;
    }

    /**
     * Method is_native_backups_enabled()
     *
     * Whether the built-in (native) backup feature is enabled.
     *
     * Reads the canonical option mainwp_enableNativeBackups with a
     * transparent fallback to the old mainwp_enableLegacyBackupFeature
     * option, so the answer is correct both before and after the rename
     * migration has run. Cron entry points can fire before the upgrade
     * routine on a freshly updated install, so keep the fallback until
     * the old option name is fully retired.
     *
     * @return bool True when native backups are enabled.
     */
    public static function is_native_backups_enabled(): bool {
        $value = get_option( 'mainwp_enableNativeBackups', null );
        if ( null === $value || false === $value ) {
            $value = get_option( 'mainwp_enableLegacyBackupFeature' );
        }
        return ! empty( $value );
    }

    /**
     * Method get_max_concurrent_backups()
     *
     * Get the configured maximum number of concurrent backup tasks.
     *
     * @return int Maximum concurrent backups (default 2, minimum 1).
     */
    public static function get_max_concurrent_backups(): int {
        return max( 1, (int) get_option( 'mainwp_backup_max_concurrent', 2 ) );
    }

    /**
     * Method handle_settings_post()
     *
     * Update Child Site Settings.
     *
     * @return bool true|false
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::is_admin()
     * @uses \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public static function handle_settings_post(): bool {
        if ( MainWP_System_Utility::is_admin() ) {
            if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'Settings' ) ) {
                if ( isset( $_POST['mainwp_options_backupOnServer'] ) && 0 < $_POST['mainwp_options_backupOnServer'] ) {
                    MainWP_Utility::update_option( 'mainwp_backupsOnServer', ( isset( $_POST['mainwp_options_backupOnServer'] ) ? intval( $_POST['mainwp_options_backupOnServer'] ) : 1 ) );
                }
                if ( isset( $_POST['mainwp_options_maximumFileDescriptors'] ) && - 1 < $_POST['mainwp_options_maximumFileDescriptors'] ) {
                    MainWP_Utility::update_option( 'mainwp_maximumFileDescriptors', ( isset( $_POST['mainwp_options_maximumFileDescriptors'] ) ? intval( $_POST['mainwp_options_maximumFileDescriptors'] ) : 1 ) );
                }
                MainWP_Utility::update_option( 'mainwp_maximumFileDescriptorsAuto', ( ! isset( $_POST['mainwp_maximumFileDescriptorsAuto'] ) ? 0 : 1 ) );
                if ( ! empty( $_POST['mainwp_options_backupOnExternalSources'] ) && 0 <= $_POST['mainwp_options_backupOnExternalSources'] ) {
                    MainWP_Utility::update_option( 'mainwp_backupOnExternalSources', ( isset( $_POST['mainwp_options_backupOnExternalSources'] ) ? intval( $_POST['mainwp_options_backupOnExternalSources'] ) : 1 ) );
                }
                MainWP_Utility::update_option( 'mainwp_archiveFormat', ( isset( $_POST['mainwp_archiveFormat'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_archiveFormat'] ) ) : '' ) );

                $old_primaryBackup          = get_option( 'mainwp_primaryBackup' );
                $old_enableLegacyBackup     = get_option( 'mainwp_enableLegacyBackupFeature' );
                $updated_enableLegacyBackup = false;

                if ( isset( $_POST['mainwp_primaryBackup'] ) ) {
                    if ( ! empty( $_POST['mainwp_primaryBackup'] ) ) { // not default backup method.
                        MainWP_Utility::update_option( 'mainwp_notificationOnBackupFail', 0 );
                        MainWP_Utility::update_option( 'mainwp_notificationOnBackupStart', 0 );
                        MainWP_Utility::update_option( 'mainwp_chunkedBackupTasks', 0 );
                        if ( empty( $old_primaryBackup ) ) {
                            MainWP_Utility::update_option( 'mainwp_enableLegacyBackupFeature', 0 );
                            $updated_enableLegacyBackup = true;
                        }
                    }
                    MainWP_Utility::update_option( 'mainwp_primaryBackup', sanitize_text_field( wp_unslash( $_POST['mainwp_primaryBackup'] ) ) );
                }

                if ( ! isset( $_POST['mainwp_primaryBackup'] ) || empty( $_POST['mainwp_primaryBackup'] ) ) {
                    MainWP_Utility::update_option( 'mainwp_options_loadFilesBeforeZip', ( ! isset( $_POST['mainwp_options_loadFilesBeforeZip'] ) ? 0 : 1 ) );
                    MainWP_Utility::update_option( 'mainwp_notificationOnBackupFail', ( ! isset( $_POST['mainwp_options_notificationOnBackupFail'] ) ? 0 : 1 ) );
                    MainWP_Utility::update_option( 'mainwp_notificationOnBackupStart', ( ! isset( $_POST['mainwp_options_notificationOnBackupStart'] ) ? 0 : 1 ) );
                    MainWP_Utility::update_option( 'mainwp_chunkedBackupTasks', ( ! isset( $_POST['mainwp_options_chunkedBackupTasks'] ) ? 0 : 1 ) );
                }

                $enableLegacyBackup = ( isset( $_POST['mainwp_options_enableLegacyBackupFeature'] ) && ! empty( $_POST['mainwp_options_enableLegacyBackupFeature'] ) ) ? 1 : 0;
                if ( $enableLegacyBackup && empty( $old_enableLegacyBackup ) ) {
                    MainWP_Utility::update_option( 'mainwp_primaryBackup', '' );
                }

                if ( ! $updated_enableLegacyBackup ) {
                    MainWP_Utility::update_option( 'mainwp_enableLegacyBackupFeature', $enableLegacyBackup );
                }
                return true;
            }
        }
        return false;
    }
}
