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
     * Shared backup option names; update-gate controls remain part of general settings.
     *
     * @var array Stored backup settings option names, keyed by their REST general/edit body name where one exists.
     */
    public const SETTINGS_OPTIONS = array(
        'mainwp_primary_backup'                => 'mainwp_primaryBackup',
        'mainwp_enable_legacy_backup_feature'  => 'mainwp_enableNativeBackups',
        'mainwp_backups_on_server'             => 'mainwp_backupsOnServer',
        'mainwp_backup_on_external_sources'    => 'mainwp_backupOnExternalSources',
        'mainwp_archive_format'                => 'mainwp_archiveFormat',
        'mainwp_notification_on_backup_fail'   => 'mainwp_notificationOnBackupFail',
        'mainwp_notification_on_backup_start'  => 'mainwp_notificationOnBackupStart',
        'mainwp_chunked_backup_tasks'          => 'mainwp_chunkedBackupTasks',
        'mainwp_load_files_before_zip'         => 'mainwp_options_loadFilesBeforeZip',
        'mainwp_maximum_file_descriptors_auto' => 'mainwp_maximumFileDescriptorsAuto',
        'mainwp_maximum_file_descriptors'      => 'mainwp_maximumFileDescriptors',
        'mainwp_backup_max_concurrent'         => 'mainwp_backup_max_concurrent',
        'mainwp_on_demand_retention_count'     => 'mainwp_on_demand_retention_count',
        'mainwp_backup_diagnostics_enabled'    => MainWP_Backup_Diagnostics::OPTION_ENABLED,
        'mainwp_backup_encryption_enabled'     => MainWP_Backup_Encryption::ENABLED_OPTION,
        'mainwp_backup_recovery_kek'           => MainWP_Backup_Encryption::RECOVERY_OPTION,
        'mainwp_enableLegacyBackupFeature'     => 'mainwp_enableLegacyBackupFeature',
    );

    /**
     * Whether the most recent handle_settings_post() call silently forced
     * archive encryption off because no recovery passphrase (recovery
     * blob) existed yet. Read by the settings save handler immediately
     * after handle_settings_post() returns, in the same request, so it can
     * carry the fact through the post-save redirect the same way the
     * existing "message=saved" query flag does.
     *
     * @var bool
     */
    private static bool $encryption_forced_off = false;

    /**
     * The redirect needs to preserve why a passphrase was refused, for the
     * settings banner: 'short', 'mismatch', 'current', 'current_mismatch',
     * 'key_mismatch', 'account_password', or '' when nothing was refused.
     *
     * @var string
     */
    private static string $recovery_passphrase_rejected = '';

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
     * Backup runtime options need the same staff boundary as the settings form.
     *
     * @param string $option Stored option name.
     * @return bool
     */
    public static function is_backup_option( string $option ): bool {
        $option = strtolower( $option );
        // The general Settings form still allows staff to change update-gate controls.
        if ( in_array( $option, array( 'mainwp_backup_before_upgrade', 'mainwp_backup_before_upgrade_days', 'mainwp_backup_before_upgrade_require_offsite' ), true ) ) {
            return false;
        }
        if ( in_array( $option, array_map( 'strtolower', self::SETTINGS_OPTIONS ), true ) ) {
            return true;
        }
        foreach ( array( 'mainwp_backup_', 'mainwp_testrestore_', 'mainwp_restore_' ) as $prefix ) {
            if ( 0 === strpos( $option, $prefix ) ) {
                return true;
            }
        }
        return false;
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
                $archiveFormat = MainWP_Archive_Format::TAR_GZ;
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
     * Method update_native_backups_option()
     *
     * Write the native-backups toggle and mirror it into the retired
     * option name (see mirror_native_flag_for_rollback()).
     *
     * @param mixed $value Truthy to enable, falsy to disable.
     */
    public static function update_native_backups_option( $value ): void {
        MainWP_Utility::update_option( 'mainwp_enableNativeBackups', (int) ! empty( $value ) );
        static::mirror_native_flag_for_rollback();
    }

    /**
     * Method mirror_native_flag_for_rollback()
     *
     * Keep the retired mainwp_enableLegacyBackupFeature option present,
     * mirroring the effective native-backups value, for the supported
     * rollback window. A pre-rename dashboard's install() recreates a
     * MISSING legacy flag as enabled but preserves a present one, so a
     * mirrored flag is what makes a rollback honor the user's choice
     * instead of silently re-enabling legacy backups.
     */
    public static function mirror_native_flag_for_rollback(): void {
        $value = get_option( 'mainwp_enableNativeBackups', null );
        if ( null === $value || false === $value ) {
            return; // Nothing to mirror before the rename migration has run.
        }
        MainWP_Utility::update_option( 'mainwp_enableLegacyBackupFeature', (int) ! empty( $value ) );
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
     * Method get_on_demand_retention_count()
     *
     * Get the configured number of on-demand ("Back up now") snapshots to
     * keep per site before the retention engine prunes the oldest.
     *
     * @return int Retention count (default 5, minimum 1).
     */
    public static function get_on_demand_retention_count(): int {
        return max( 1, (int) get_option( 'mainwp_on_demand_retention_count', 5 ) );
    }

    /**
     * Hidden rows must follow setting changes before their next backup.
     *
     * @param object $task Task row.
     * @return int Retention count for this policy evaluation.
     */
    public static function task_retention_count( object $task ): int {
        return ! empty( $task->template ) ? self::get_on_demand_retention_count() : (int) $task->retention_count;
    }

    /**
     * Method get_manual_run_settle_seconds()
     *
     * How long after a successful close an on-demand run refuses to
     * reopen. A duplicate initiate inside this window (a second tab, a
     * repeat click landing as the run finishes) is absorbed instead of
     * reopening the run and poisoning the freshness check of the click
     * that produced the snapshot. Failure retries are never debounced.
     *
     * @return int Settle window in seconds (default 15, minimum 0).
     */
    public static function get_manual_run_settle_seconds(): int {
        return max( 0, (int) get_option( 'mainwp_backup_manual_settle_seconds', 15 ) );
    }

    /**
     * Method encryption_was_forced_off()
     *
     * Whether the most recent handle_settings_post() call silently forced
     * archive encryption off because no recovery passphrase was set yet.
     *
     * @return bool True when encryption could not be armed on the last save.
     */
    public static function encryption_was_forced_off(): bool {
        return self::$encryption_forced_off;
    }

    /**
     * Let the save handler carry the refusal through its redirect.
     *
     * @return string The rejection flag ('short', 'mismatch', 'current', 'current_mismatch',
     *                'key_mismatch' or 'account_password'), or '' when the last save refused no passphrase.
     */
    public static function recovery_passphrase_rejection(): string {
        return self::$recovery_passphrase_rejected;
    }

    /**
     * Return warning query arguments for the last settings save.
     *
     * @return array Redirect query arguments.
     */
    public static function get_save_redirect_args(): array {
        $args = array();
        if ( self::encryption_was_forced_off() ) {
            $args['backup_encryption_forced_off'] = 1;
        }
        if ( '' !== self::recovery_passphrase_rejection() ) {
            $args['backup_recovery_passphrase_rejected'] = self::recovery_passphrase_rejection();
        }
        return $args;
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
        self::$encryption_forced_off        = false;
        self::$recovery_passphrase_rejected = '';
        if ( MainWP_System_Utility::is_admin() && ! MainWP_System_Utility::is_team_control_staff() && \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'BackupSettings' ) ) {
                $old_primaryBackup       = get_option( 'mainwp_primaryBackup' );
                $old_enableNativeBackups = static::is_native_backups_enabled();
                $enableNativeBackups     = ! empty( $_POST['mainwp_options_enableNativeBackups'] );
                $values                  = array(
                    'native_backups_enabled' => $enableNativeBackups && ! ( ! empty( $_POST['mainwp_primaryBackup'] ) && empty( $old_primaryBackup ) ),
                    'diagnostics_enabled'    => ! empty( $_POST['mainwp_options_backupDiagnosticsEnabled'] ),
                );
                if ( isset( $_POST['mainwp_options_onDemandRetentionCount'] ) ) {
                    $values['on_demand_retention_count'] = max( 1, intval( $_POST['mainwp_options_onDemandRetentionCount'] ) );
                }
                if ( is_wp_error( MainWP_Backup_Operations::save_backup_settings( $values, 'ui' ) ) ) {
                    return false;
                }
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

                if ( isset( $_POST['mainwp_primaryBackup'] ) ) {
                    if ( ! empty( $_POST['mainwp_primaryBackup'] ) ) { // not default backup method.
                        MainWP_Utility::update_option( 'mainwp_notificationOnBackupFail', 0 );
                        MainWP_Utility::update_option( 'mainwp_notificationOnBackupStart', 0 );
                        MainWP_Utility::update_option( 'mainwp_chunkedBackupTasks', 0 );
                    }
                    MainWP_Utility::update_option( 'mainwp_primaryBackup', sanitize_text_field( wp_unslash( $_POST['mainwp_primaryBackup'] ) ) );
                }

                if ( ! isset( $_POST['mainwp_primaryBackup'] ) || empty( $_POST['mainwp_primaryBackup'] ) ) {
                    MainWP_Utility::update_option( 'mainwp_options_loadFilesBeforeZip', ( ! isset( $_POST['mainwp_options_loadFilesBeforeZip'] ) ? 0 : 1 ) );
                    MainWP_Utility::update_option( 'mainwp_notificationOnBackupFail', ( ! isset( $_POST['mainwp_options_notificationOnBackupFail'] ) ? 0 : 1 ) );
                    MainWP_Utility::update_option( 'mainwp_notificationOnBackupStart', ( ! isset( $_POST['mainwp_options_notificationOnBackupStart'] ) ? 0 : 1 ) );
                    MainWP_Utility::update_option( 'mainwp_chunkedBackupTasks', ( ! isset( $_POST['mainwp_options_chunkedBackupTasks'] ) ? 0 : 1 ) );
                }

                if ( $enableNativeBackups && empty( $old_enableNativeBackups ) ) {
                    MainWP_Utility::update_option( 'mainwp_primaryBackup', '' );
                }

                // Archive encryption. A non-empty passphrase creates or re-wraps
                // the dashboard key. The toggle can only arm when a recovery
                // passphrase exists, so a backup is never sealed without a way to
                // recover it. Turning encryption off never removes the key
                // material - already-encrypted snapshots still need it.
                $encryption         = MainWP_Backup_Encryption::instance();
                $enc_passphrase     = isset( $_POST['mainwp_options_backupEncryptionPassphrase'] ) ? (string) wp_unslash( $_POST['mainwp_options_backupEncryptionPassphrase'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used verbatim as key material, never stored or rendered.
                $current_passphrase = isset( $_POST['mainwp_options_backupEncryptionCurrentPassphrase'] ) ? (string) wp_unslash( $_POST['mainwp_options_backupEncryptionCurrentPassphrase'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used verbatim as key material, never stored or rendered.
                if ( '' !== trim( $enc_passphrase ) ) {
                    $existing = $encryption->get_recovery_blob();
                    $user     = wp_get_current_user();
                    $password = isset( $_POST['mainwp_options_backupEncryptionAccountPassword'] ) ? (string) wp_unslash( $_POST['mainwp_options_backupEncryptionAccountPassword'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- password verification requires the verbatim input, never stored or rendered.
                    if ( empty( $existing['wrapped_kek'] ) && ( '' === $password || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) ) {
                        self::$recovery_passphrase_rejected = 'account_password';
                    } else {
                        $key_missing = null === $encryption->load_kek();
                        try {
                            $encryption->set_recovery_passphrase( $enc_passphrase, $current_passphrase );
                        } catch ( \InvalidArgumentException $e ) {
                            if ( MainWP_Backup_Encryption::PASSPHRASE_CURRENT_REQUIRED === $e->getCode() ) {
                                self::$recovery_passphrase_rejected = 'current';
                            } elseif ( MainWP_Backup_Encryption::PASSPHRASE_MISMATCH === $e->getCode() ) {
                                self::$recovery_passphrase_rejected = $key_missing ? 'mismatch' : 'current_mismatch';
                            } elseif ( MainWP_Backup_Encryption::PASSPHRASE_KEY_MISMATCH === $e->getCode() ) {
                                self::$recovery_passphrase_rejected = 'key_mismatch';
                            } else {
                                self::$recovery_passphrase_rejected = 'short';
                            }
                        }
                    }
                }
                $enableEncryption = ( isset( $_POST['mainwp_options_enableBackupEncryption'] ) && ! empty( $_POST['mainwp_options_enableBackupEncryption'] ) ) ? 1 : 0;
                if ( $enableEncryption && empty( $encryption->get_recovery_blob() ) ) {
                    $enableEncryption            = 0;
                    self::$encryption_forced_off = true;
                }
                MainWP_Utility::update_option( MainWP_Backup_Encryption::ENABLED_OPTION, $enableEncryption );

                return true;
            }
        }
        return false;
    }
}
