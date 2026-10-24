<?php
/**
 * Shared backup actions keep transport concerns outside their refusals.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/** Class MainWP_Backup_Operations. */
class MainWP_Backup_Operations { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Repeated fleet reads must not multiply site queries within a request.
     *
     * @var array<string,int[]>
     */
    private static $authorized_site_ids_memo = array();

    /**
     * Only registered codes select messages sent to agents.
     *
     * @param string $code Registered error code.
     * @return array Fixed error code and message.
     */
    public static function safe_error( string $code ): array {
        $house      = array(
            'mainwp_permission_denied'       => __( 'You do not have permission to perform this action.', 'mainwp' ),
            'mainwp_insufficient_right'      => __( 'Your role does not permit this operation.', 'mainwp' ),
            'mainwp_restricted_account'      => __( 'This action requires unrestricted site access.', 'mainwp' ),
            'mainwp_invalid_input'           => __( 'The supplied input is invalid.', 'mainwp' ),
            'mainwp_confirmation_required'   => __( 'Confirmation is required for this action.', 'mainwp' ),
            'mainwp_site_not_found'          => __( 'The site was not found.', 'mainwp' ),
            'mainwp_restore_rollback_status' => __( 'This restore cannot be rolled back in its current status.', 'mainwp' ),
            'mainwp_operation_failed'        => __( 'The child site did not return a usable response.', 'mainwp' ),
            'mainwp_internal_error'          => __( 'An unexpected error prevented this action.', 'mainwp' ),
        );
        $registered = MainWP_Backup_Error_Codes::get_messages();
        if ( ! preg_match( '/^mainwp_[a-z0-9_]+$/D', $code ) || ( ! isset( $registered[ $code ] ) && ! isset( $house[ $code ] ) ) ) {
            $code = MainWP_Backup_Error_Codes::ERR_UNCLASSIFIED_ERROR;
        }
        return array(
            'code'    => $code,
            'message' => wp_specialchars_decode( $house[ $code ] ?? MainWP_Backup_Error_Codes::message( $code ), ENT_QUOTES ),
        );
    }

    /**
     * Userless CLI is a trusted operator; REST needs an authenticated owner.
     *
     * @return bool
     */
    private static function userless_cli(): bool {
        return 0 === get_current_user_id() && defined( 'WP_CLI' ) && WP_CLI;
    }

    /**
     * Direct rights checks also apply to authenticated machine callers.
     *
     * @param string $right Granular operation right, or empty for summary reads.
     * @return true|\WP_Error
     */
    public static function authorize_base( string $right ) {
        if ( ! self::userless_cli() ) {
            $base = MainWP_Abilities_Util::check_rest_api_permission();
            if ( is_wp_error( $base ) ) {
                return $base;
            }
        }
        if ( '' !== $right && ! MainWP_Abilities_Util::current_user_has_operation_right( $right ) ) {
            return new \WP_Error(
                'mainwp_insufficient_right',
                self::safe_error( 'mainwp_insufficient_right' )['message'],
                array(
                    'status' => 403,
                    'right'  => $right,
                )
            );
        }
        return true;
    }

    /**
     * Browser staff use Dashboard rights without an ability capability bridge.
     *
     * @param string $right Granular operation right.
     * @return true|\WP_Error
     */
    public static function authorize_ui( string $right ) {
        if ( ! is_user_logged_in() ) {
            return new \WP_Error( 'mainwp_permission_denied', self::safe_error( 'mainwp_permission_denied' )['message'], array( 'status' => 401 ) );
        }
        if ( ! MainWP_System_Utility::is_admin() ) {
            return new \WP_Error( 'mainwp_permission_denied', self::safe_error( 'mainwp_permission_denied' )['message'], array( 'status' => 403 ) );
        }
        if ( '' !== $right && ! MainWP_Abilities_Util::current_user_has_operation_right( $right ) ) {
            return new \WP_Error(
                'mainwp_insufficient_right',
                self::safe_error( 'mainwp_insufficient_right' )['message'],
                array(
                    'status' => 403,
                    'right'  => $right,
                )
            );
        }
        return true;
    }

    /**
     * Single-site and fleet access use the same scope and ownership rules.
     *
     * @param object $site Website row.
     * @return bool
     */
    private static function site_is_authorized( object $site ): bool {
        $allowed = MainWP_Abilities_Util::get_scoped_site_ids_or_all();
        if ( 'all' !== $allowed && ! in_array( (int) $site->id, $allowed, true ) ) {
            return false;
        }
        if ( ! apply_filters( 'mainwp_check_site_access', true, (int) $site->id, null ) ) {
            return false;
        }
        return self::userless_cli() || ( MainWP_System_Utility::can_edit_website( $site ) && apply_filters( 'mainwp_currentusercan', true, 'site', (int) $site->id ) );
    }

    /**
     * Missing and denied sites share a refusal to prevent existence probes.
     *
     * @param int    $site_id Website identity.
     * @param string $right Operation right.
     * @param string $context Calling transport.
     * @return object|\WP_Error
     */
    public static function authorize_site( int $site_id, string $right, string $context = 'ability' ) {
        $base = 'ui' === $context ? self::authorize_ui( $right ) : self::authorize_base( $right );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        $site = MainWP_DB::instance()->get_website_by_id( $site_id );
        if ( empty( $site ) || ! self::site_is_authorized( $site ) ) {
            /* translators: %d: site ID. */
            return new \WP_Error( 'mainwp_site_not_found', sprintf( __( 'No site found with ID %d.', 'mainwp' ), $site_id ), array( 'status' => 404 ) );
        }
        return $site;
    }

    /**
     * Fleet totals must include only sites that pass every access check.
     *
     * @param string $right Operation right, or empty for summary reads.
     * @return int[]
     */
    public static function authorized_site_ids( string $right ): array {
        $key = get_current_user_id() . ':' . (int) self::userless_cli() . ':' . (int) ( defined( 'MAINWP_REST_API_DOING' ) && MAINWP_REST_API_DOING ) . ':' . $right;
        if ( isset( self::$authorized_site_ids_memo[ $key ] ) ) {
            return self::$authorized_site_ids_memo[ $key ];
        }
        if ( is_wp_error( self::authorize_base( $right ) ) ) {
            self::$authorized_site_ids_memo[ $key ] = array();
            return array();
        }
        global $wpdb;
        $db         = MainWP_DB::instance();
        $sites      = $wpdb->get_results( 'SELECT wp.* FROM ' . $db->get_table_name( 'wp' ) . ' wp JOIN ' . $db->get_table_name( 'wp_sync' ) . ' wp_sync ON wp.id = wp_sync.wpid ORDER BY wp.id' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the roster must precede caller scoping; the join retains the single-site lookup's existence rule.
        $authorized = array();
        foreach ( $sites as $site ) {
            if ( self::site_is_authorized( $site ) ) {
                $authorized[ (int) $site->id ] = (int) $site->id;
            }
        }
        $authorized                             = array_values( $authorized );
        self::$authorized_site_ids_memo[ $key ] = $authorized;
        return $authorized;
    }

    /**
     * Isolated tests can change sites and permissions within one PHP request.
     *
     * @return void
     */
    public static function reset_authorized_site_ids_memo(): void {
        self::$authorized_site_ids_memo = array();
    }

    /**
     * Entity denials disclose no more than missing lookups.
     *
     * @param string $code Not-found code.
     * @return \WP_Error
     */
    private static function not_found( string $code ): \WP_Error {
        return new \WP_Error( $code, self::safe_error( $code )['message'], array( 'status' => 404 ) );
    }

    /**
     * A task can act only within its owner and complete site scope.
     *
     * @param int    $task_id Task identity.
     * @param string $right Operation right.
     * @param string $context Calling transport.
     * @return object|\WP_Error
     */
    public static function authorize_task( int $task_id, string $right, string $context = 'ability' ) {
        $base = 'ui' === $context ? self::authorize_ui( $right ) : self::authorize_base( $right );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
        if ( ! $task || ! empty( $task->template ) || ( ! self::userless_cli() && ! MainWP_Manage_Backups_Handler::can_edit_backuptask( $task ) ) ) {
            return self::not_found( MainWP_Backup_Error_Codes::ERR_TASK_NOT_FOUND );
        }
        // Only the run set is checked: no task action touches a deleted site
        // or a group member added after the authorized snapshot.
        $sites = MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task );
        // Same scope source as site_is_authorized(), so userless CLI keeps full scope.
        if ( empty( $sites ) && 'all' !== MainWP_Abilities_Util::get_scoped_site_ids_or_all() ) {
            return self::not_found( MainWP_Backup_Error_Codes::ERR_TASK_NOT_FOUND );
        }
        foreach ( $sites as $site_id ) {
            if ( is_wp_error( self::authorize_site( $site_id, $right, $context ) ) ) {
                return self::not_found( MainWP_Backup_Error_Codes::ERR_TASK_NOT_FOUND );
            }
        }
        return $task;
    }

    /**
     * Snapshot access follows the owning site.
     *
     * @param int    $snapshot_id Snapshot identity.
     * @param string $context Calling transport.
     * @return object|\WP_Error
     */
    public static function authorize_snapshot( int $snapshot_id, string $context = 'ability' ) {
        $base = 'ui' === $context ? self::authorize_ui( 'execute_backups' ) : self::authorize_base( 'execute_backups' );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
        return ! $snapshot || is_wp_error( self::authorize_site( (int) $snapshot->site_id, 'execute_backups', $context ) ) ? self::not_found( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_NOT_FOUND ) : $snapshot;
    }

    /**
     * Reservations are internal until a restore is admitted.
     *
     * @param int    $restore_job_id Restore job identity.
     * @param bool   $require_restore_right Whether the caller intends to change the job.
     * @param string $context Calling transport.
     * @return object|\WP_Error
     */
    public static function authorize_restore_job( int $restore_job_id, bool $require_restore_right = false, string $context = 'ability' ) {
        $base = 'ui' === $context ? self::authorize_ui( 'execute_backups' ) : self::authorize_base( 'execute_backups' );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        if ( $require_restore_right ) {
            $restore = 'ui' === $context ? self::authorize_ui( 'restore_backups' ) : self::authorize_base( 'restore_backups' );
            if ( is_wp_error( $restore ) ) {
                return $restore;
            }
        }
        $job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $restore_job_id );
        return ! $job || 'reserved' === $job->status || is_wp_error( self::authorize_site( (int) $job->site_id, 'execute_backups', $context ) ) ? self::not_found( MainWP_Backup_Error_Codes::ERR_RESTORE_JOB_NOT_FOUND ) : $job;
    }

    /**
     * Global destinations require the storage operation right.
     *
     * @param int $id Destination identity.
     * @return object|\WP_Error
     */
    public static function authorize_destination( int $id ) {
        $base = self::authorize_base( 'edit_backup_tasks' );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        $destination = MainWP_Backup_Storage_Destinations::instance()->get_destination( $id );
        return $destination ? $destination : self::not_found( MainWP_Backup_Error_Codes::ERR_DESTINATION_NOT_FOUND );
    }

    /**
     * Validation failures carry fixed Dashboard text without submitted values.
     *
     * @param string $field Invalid task field.
     * @return \WP_Error
     */
    private static function invalid_task_input( string $field ): \WP_Error {
        $message = self::safe_error( 'mainwp_invalid_input' )['message'];
        if ( 'name' === $field ) {
            $message = esc_html__( 'Invalid backup task name. Please, enter a new name and try again.', 'mainwp' );
        } elseif ( 'remove_local_after_upload' === $field ) {
            $message = esc_html__( 'Select at least one remote storage destination before enabling local archive removal.', 'mainwp' );
        } elseif ( 'type' === $field ) {
            $message = esc_html__( 'Invalid backup type. Select Full, Database, Incremental, or Files Only and try again.', 'mainwp' );
        }
        return new \WP_Error(
            'mainwp_invalid_input',
            $message,
            array(
                'status' => 400,
                'field'  => $field,
            )
        );
    }

    /**
     * Coercion before validation can authorize a different site from the one stored.
     *
     * @param mixed  $raw Submitted selection.
     * @param string $field Selection field.
     * @return int[]|\WP_Error
     */
    private static function task_input_ids( $raw, string $field ) {
        if ( ! is_array( $raw ) ) {
            return self::invalid_task_input( $field );
        }
        $ids = array();
        foreach ( $raw as $value ) {
            if ( is_int( $value ) && $value > 0 ) {
                $id = $value;
            } elseif ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
                $id = (int) $value;
                if ( (string) $id !== $value ) {
                    return self::invalid_task_input( $field );
                }
            } else {
                return self::invalid_task_input( $field );
            }
            $ids[ $id ] = $id;
        }
        return array_values( $ids );
    }

    /**
     * Partial edits retain omitted settings and nested policy values.
     *
     * @param object|null $task Stored task, or null for create defaults.
     * @return array Transport-neutral fields.
     */
    private static function stored_task_fields( ?object $task ): array {
        return array(
            'name'            => $task ? html_entity_decode( $task->name, ENT_QUOTES ) : '',
            'type'            => $task->type ?? '',
            'schedule'        => $task->schedule ?? '',
            'interval_hours'  => $task && 'interval' === $task->schedule ? (int) $task->interval_hours : null,
            'time_of_day'     => $task->time_of_day ?? '',
            'day_of_week'     => $task ? (int) $task->day_of_week : 0,
            'day_of_month'    => $task ? (int) $task->day_of_month : 1,
            'site_ids'        => $task && '' !== $task->sites ? explode( ',', $task->sites ) : array(),
            'group_ids'       => $task && '' !== $task->groups ? explode( ',', $task->groups ) : array(),
            'destination_ids' => $task ? ( json_decode( $task->destinations ?? '[]', true ) ?? array() ) : array(),
            'archive_format'  => $task->archiveFormat ?? 'site',
            'exclude'         => $task ? html_entity_decode( $task->exclude, ENT_QUOTES ) : '',
            'retention'       => array(
                'count'                     => $task ? (int) $task->retention_count : MainWP_Manage_Backups_Handler::NEW_TASK_RETENTION_COUNT,
                'days'                      => $task ? (int) $task->retention_days : 0,
                'storage_mb'                => $task ? (int) $task->retention_storage_mb : 0,
                'remove_local_after_upload' => $task ? MainWP_Backup_Retention_Protection::task_removes_local_archives( (int) $task->id ) : false,
                'delete_cloud_copies'       => ! empty( $task->delete_cloud_copies ),
                'max_chain_length'          => $task ? (int) $task->max_chain_length : 0,
            ),
            'exclude_flags'   => array(
                'backup_archives' => ! empty( $task->excludebackup ),
                'cache'           => ! empty( $task->excludecache ),
                'non_wp_folders'  => ! empty( $task->excludenonwp ),
                'zip_archives'    => ! empty( $task->excludezip ),
            ),
            'archive_tuning'  => array(
                'max_file_descriptors_override' => ! empty( $task->maximumFileDescriptorsOverride ),
                'max_file_descriptors_auto'     => ! empty( $task->maximumFileDescriptorsAuto ),
                'max_file_descriptors'          => $task ? (int) $task->maximumFileDescriptors : 150,
                'load_files_before_zip'         => ! empty( $task->loadFilesBeforeZip ),
                'zip_force_override'            => ! empty( $task->zip_force_override ),
            ),
        );
    }

    /**
     * Partial edits and previews must normalize exactly the values a save uses.
     *
     * @param array       $fields Submitted fields, with selections resolved to IDs.
     * @param object|null $task Stored row for a partial update.
     * @param string      $context Calling transport.
     * @return array|\WP_Error Normalized task fields.
     */
    public static function normalize_task_fields( array $fields, ?object $task = null, string $context = 'ability' ) {
        $base = self::stored_task_fields( $task );
        if ( empty( $fields ) || array_diff( array_keys( $fields ), array_merge( array_keys( $base ), array( 'subfolder', 'filename' ) ) ) ) {
            return self::invalid_task_input( 'fields' );
        }
        $values = array_merge( $base, $fields );
        // The task form never had size or length ceilings, so only machine callers get the
        // schema's. Each applies to a submitted value: a larger stored one survives a partial edit.
        $ceilings = 'ui' === $context ? array() : array(
            'name'                 => 255,
            'exclude'              => 255,
            'count'                => 1000,
            'days'                 => 3650,
            'storage_mb'           => 104857600,
            'max_chain_length'     => 1000,
            'max_file_descriptors' => 100000,
        );
        foreach ( array( 'name', 'type', 'schedule', 'archive_format', 'subfolder', 'filename' ) as $field ) {
            if ( isset( $values[ $field ] ) && ! is_string( $values[ $field ] ) ) {
                return self::invalid_task_input( $field );
            }
        }
        $values['name'] = sanitize_text_field( $values['name'] );
        if ( '' === $values['name'] || ( isset( $ceilings['name'], $fields['name'] ) && mb_strlen( $values['name'], 'UTF-8' ) > $ceilings['name'] ) ) {
            return self::invalid_task_input( 'name' );
        }
        foreach ( array(
            'type'           => array( 'full', 'db', 'files', 'incremental' ),
            'schedule'       => array( 'daily', 'weekly', 'monthly', 'interval' ),
            'archive_format' => array( 'site', 'global', 'zip', 'tar', 'tar.gz', 'tar.bz2' ),
        ) as $field => $allowed ) {
            if ( ! in_array( $values[ $field ], $allowed, true ) ) {
                return self::invalid_task_input( $field );
            }
        }
        foreach ( array( 'site_ids', 'group_ids', 'destination_ids' ) as $field ) {
            $ids = self::task_input_ids( $values[ $field ], $field );
            if ( is_wp_error( $ids ) ) {
                return $ids;
            }
            $values[ $field ] = $ids;
        }
        if ( empty( $values['site_ids'] ) && empty( $values['group_ids'] ) ) {
            return self::invalid_task_input( 'site_ids' );
        }

        $schedule = $values['schedule'];
        $changed  = $task && $task->schedule !== $schedule;
        $active   = array(
            'interval_hours' => 'interval',
            'day_of_week'    => 'weekly',
            'day_of_month'   => 'monthly',
        );
        foreach ( $active as $field => $applies_to ) {
            if ( array_key_exists( $field, $fields ) && $applies_to !== $schedule ) {
                return self::invalid_task_input( $field );
            }
        }
        if ( 'interval' === $schedule ) {
            if ( ( ! $task || $changed ) && ! array_key_exists( 'interval_hours', $fields ) ) {
                return self::invalid_task_input( 'interval_hours' );
            }
            if ( array_key_exists( 'time_of_day', $fields ) && ( ! $task || null !== $fields['time_of_day'] ) ) {
                return self::invalid_task_input( 'time_of_day' );
            }
            $values['time_of_day'] = null;
        } else {
            $values['interval_hours'] = null;
            if ( array_key_exists( 'time_of_day', $fields ) && null === $fields['time_of_day'] && ! $task ) {
                return self::invalid_task_input( 'time_of_day' );
            }
            if ( null !== $values['time_of_day'] && '' !== $values['time_of_day'] && ( ! is_string( $values['time_of_day'] ) || ! preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/D', $values['time_of_day'] ) ) ) {
                return self::invalid_task_input( 'time_of_day' );
            }
            $values['time_of_day'] = '' === $values['time_of_day'] ? null : $values['time_of_day'];
        }
        foreach ( array(
            'interval_hours' => array( 1, 8760 ),
            'day_of_week'    => array( 0, 6 ),
            'day_of_month'   => array( 1, 28 ),
        ) as $field => $bounds ) {
            if ( $active[ $field ] !== $schedule ) {
                $values[ $field ] = null;
                continue;
            }
            if ( $changed && 'interval_hours' !== $field && ! array_key_exists( $field, $fields ) ) {
                $values[ $field ] = $bounds[0];
            }
            if ( ! is_int( $values[ $field ] ) || $values[ $field ] < $bounds[0] || $values[ $field ] > $bounds[1] ) {
                return self::invalid_task_input( $field );
            }
        }
        $values['timing'] = 'interval' === $schedule ? 'interval' : ( null === $values['time_of_day'] ? 'elapsed' : 'time_of_day' );

        foreach ( array( 'retention', 'exclude_flags', 'archive_tuning' ) as $field ) {
            if ( ! is_array( $values[ $field ] ) || array_diff( array_keys( $values[ $field ] ), array_keys( $base[ $field ] ) ) ) {
                return self::invalid_task_input( $field );
            }
            $values[ $field ] = array_merge( $base[ $field ], $values[ $field ] );
            foreach ( $values[ $field ] as $key => $value ) {
                $submitted = array_key_exists( $key, $fields[ $field ] ?? array() );
                if ( is_bool( $base[ $field ][ $key ] ) ? ! is_bool( $value ) : ( ! is_int( $value ) || $value < 0 || ( $submitted && isset( $ceilings[ $key ] ) && $value > $ceilings[ $key ] ) ) ) {
                    return self::invalid_task_input( $field );
                }
            }
        }
        $flag_submitted = array_key_exists( 'remove_local_after_upload', $fields['retention'] ?? array() );
        if ( $values['retention']['remove_local_after_upload'] && ( $flag_submitted || array_key_exists( 'destination_ids', $fields ) || null === $task ) ) {
            $remote = false;
            foreach ( $values['destination_ids'] as $destination_id ) {
                $destination = MainWP_Backup_Storage_Destinations::instance()->get_destination( $destination_id );
                $remote      = $remote || ( $destination && 'local' !== $destination->provider );
            }
            if ( ! $remote ) {
                return self::invalid_task_input( 'remove_local_after_upload' );
            }
        }
        if ( ! is_string( $values['exclude'] ) && ! is_array( $values['exclude'] ) ) {
            return self::invalid_task_input( 'exclude' );
        }
        if ( is_array( $values['exclude'] ) ) {
            foreach ( $values['exclude'] as $entry ) {
                if ( ! is_string( $entry ) || ( isset( $ceilings['exclude'] ) && array_key_exists( 'exclude', $fields ) && mb_strlen( $entry, 'UTF-8' ) > $ceilings['exclude'] ) ) {
                    return self::invalid_task_input( 'exclude' );
                }
            }
        }
        $exclude           = MainWP_Backup_Settings::sanitize_exclude_list( $values['exclude'] );
        $values['exclude'] = '' === $exclude ? array() : explode( ',', $exclude );
        unset( $values['subfolder'], $values['filename'] );
        return $values;
    }

    /**
     * Stop-work actions bypass this gate so disabling backups never prevents a stop.
     *
     * @return \WP_Error|null
     */
    private static function native_task_write_error(): ?\WP_Error {
        return MainWP_Backup_Settings::is_native_backups_enabled() ? null : new \WP_Error( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED, wp_specialchars_decode( MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED ), ENT_QUOTES ), array( 'status' => 409 ) );
    }

    /**
     * Stored selections must not widen the scope checked at save time.
     *
     * @param array  $values Validated selections.
     * @param string $right Task operation right.
     * @param string $context Calling transport.
     * @return true|\WP_Error
     */
    private static function authorize_task_input_scope( array $values, string $right, string $context = 'ability' ) {
        $sites  = implode( ',', $values['site_ids'] );
        $groups = implode( ',', $values['group_ids'] );
        foreach ( MainWP_Manage_Backups_Handler::expand_task_site_ids( $sites, $groups ) as $id ) {
            $site = self::authorize_site( $id, $right, $context );
            if ( is_wp_error( $site ) ) {
                return $site;
            }
        }
        // The existing write gate and snapshot stamper must see the same canonical lists.
        if ( '' !== MainWP_Manage_Backups_Handler::authorize_task_scope( $sites, $groups ) ) {
            return self::not_found( MainWP_Backup_Error_Codes::ERR_TASK_NOT_FOUND );
        }
        foreach ( $values['destination_ids'] as $id ) {
            if ( ! MainWP_Backup_Storage_Destinations::instance()->get_destination( $id ) ) {
                return self::not_found( MainWP_Backup_Error_Codes::ERR_DESTINATION_NOT_FOUND );
            }
        }
        return true;
    }

    /**
     * A new schedule needs an authorized scope snapshot before cron can run it.
     *
     * @param array  $fields Submitted task fields.
     * @param string $context Calling transport.
     * @return array|\WP_Error
     */
    public static function create_task( array $fields, string $context ) {
        $base = 'ui' === $context ? self::authorize_ui( 'add_backup_tasks' ) : self::authorize_base( 'add_backup_tasks' );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        if ( ! in_array( $context, array( 'ui', 'cli', 'ability' ), true ) ) {
            return self::invalid_task_input( 'context' );
        }
        $error = self::native_task_write_error();
        if ( $error ) {
            return $error;
        }
        $values = self::prepare_task_fields( $fields, null, $context, 'add_backup_tasks' );
        return is_wp_error( $values ) ? $values : self::save_task( $values, $fields );
    }

    /**
     * A partial edit cannot bypass the existing task authorization.
     *
     * @param int    $task_id Task identity.
     * @param array  $fields Partial task fields.
     * @param string $context Calling transport.
     * @return array|\WP_Error
     */
    public static function update_task( int $task_id, array $fields, string $context ) {
        $task = self::authorize_task( $task_id, 'edit_backup_tasks', $context );
        if ( is_wp_error( $task ) ) {
            return $task;
        }
        if ( ! in_array( $context, array( 'ui', 'cli', 'ability' ), true ) ) {
            return self::invalid_task_input( 'context' );
        }
        $error = self::native_task_write_error();
        if ( $error ) {
            return $error;
        }
        $values = self::prepare_task_fields( $fields, $task, $context, 'edit_backup_tasks' );
        if ( is_wp_error( $values ) ) {
            return $values;
        }
        return self::save_task( $values, $fields, $task );
    }

    /**
     * Preview and save must reject the same partial fields and scope changes.
     *
     * @param array       $fields Submitted fields.
     * @param object|null $task Existing task or create defaults.
     * @param string      $context Calling transport.
     * @param string      $right Operation right.
     * @return array|\WP_Error
     */
    private static function prepare_task_fields( array $fields, ?object $task, string $context, string $right ) {
        $values = self::normalize_task_fields( $fields, $task, $context );
        if ( is_wp_error( $values ) ) {
            return $values;
        }
        $scope = self::authorize_task_input_scope( $values, $right, $context );
        if ( is_wp_error( $scope ) ) {
            return $scope;
        }
        // Both current scopes must be reachable before saving a partial edit.
        $scope_error = $task ? MainWP_Manage_Backups_Handler::authorize_task_update_scope( implode( ',', MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task ) ), '', implode( ',', $values['site_ids'] ), implode( ',', $values['group_ids'] ) ) : '';
        if ( '' !== $scope_error ) {
            return self::not_found( MainWP_Backup_Error_Codes::ERR_TASK_NOT_FOUND );
        }
        if ( null !== $task ) {
            $history = self::authorize_task_history_sites( (int) $task->id, 'edit_backup_tasks', $context );
            if ( is_wp_error( $history ) ) {
                return $history;
            }
        }
        return $values;
    }

    /**
     * Edits and deletion can prune sites that are no longer in the task's current scope.
     *
     * @param int    $task_id Task identity.
     * @param string $right Required operation right.
     * @param string $context Calling transport.
     * @return true|\WP_Error
     */
    private static function authorize_task_history_sites( int $task_id, string $right = 'edit_backup_tasks', string $context = 'ability' ) {
        foreach ( MainWP_Backup_Retention_Query::instance()->list_site_ids_for_task( $task_id ) as $site_id ) {
            // A scoped lookup would mistake a hidden site for a deleted one.
            if ( empty( MainWP_DB::instance()->get_website_by_id_unscoped( $site_id ) ) ) {
                continue;
            }
            if ( is_wp_error( self::authorize_site( $site_id, $right, $context ) ) ) {
                return self::not_found( MainWP_Backup_Error_Codes::ERR_TASK_NOT_FOUND );
            }
        }
        return true;
    }

    /**
     * Persist task fields before the checked local archive option.
     *
     * @param array       $values Normalized task fields.
     * @param array       $fields Submitted legacy filename fields.
     * @param object|null $task Existing task, or null for create.
     * @return array|\WP_Error
     */
    private static function save_task( array $values, array $fields, ?object $task = null ) {
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Raw evidence and guarded recovery require checked database access.
        global $wpdb;
        $table             = MainWP_DB::instance()->get_table_name( 'wp_backup' );
        $flags             = $values['exclude_flags'];
        $tuning            = $values['archive_tuning'];
        $write             = array(
            'userid'                         => get_current_user_id(),
            'name'                           => htmlentities( $values['name'] ),
            'schedule'                       => $values['schedule'],
            'type'                           => $values['type'],
            'exclude'                        => MainWP_Backup_Settings::sanitize_exclude_list( array_map( 'htmlentities', $values['exclude'] ) ),
            'sites'                          => implode( ',', $values['site_ids'] ),
            'groups'                         => implode( ',', $values['group_ids'] ),
            'subfolder'                      => MainWP_Utility::remove_preslash_spaces( sanitize_text_field( $fields['subfolder'] ?? $task->subfolder ?? '' ) ),
            'filename'                       => sanitize_text_field( $fields['filename'] ?? $task->filename ?? '' ),
            'excludebackup'                  => (int) $flags['backup_archives'],
            'excludecache'                   => (int) $flags['cache'],
            'excludenonwp'                   => (int) $flags['non_wp_folders'],
            'excludezip'                     => (int) $flags['zip_archives'],
            'archiveFormat'                  => $values['archive_format'],
            'loadFilesBeforeZip'             => (int) $tuning['load_files_before_zip'],
            'maximumFileDescriptorsOverride' => (int) $tuning['max_file_descriptors_override'],
            'maximumFileDescriptorsAuto'     => (int) $tuning['max_file_descriptors_auto'],
            'maximumFileDescriptors'         => $tuning['max_file_descriptors'],
            'zip_force_override'             => (int) $tuning['zip_force_override'],
            'authorized_sites'               => implode( ',', MainWP_Manage_Backups_Handler::expand_task_site_ids( implode( ',', $values['site_ids'] ), implode( ',', $values['group_ids'] ) ) ),
            'destinations'                   => wp_json_encode( $values['destination_ids'] ),
            'interval_hours'                 => $values['interval_hours'] ?? 0,
            'time_of_day'                    => $values['time_of_day'] ?? '',
            'day_of_week'                    => min( 6, absint( $values['day_of_week'] ?? $task->day_of_week ?? 0 ) ),
            'day_of_month'                   => max( 1, min( 28, absint( $values['day_of_month'] ?? $task->day_of_month ?? 1 ) ) ),
            'retention_count'                => $values['retention']['count'],
            'retention_days'                 => $values['retention']['days'],
            'retention_storage_mb'           => $values['retention']['storage_mb'],
            'delete_cloud_copies'            => (int) $values['retention']['delete_cloud_copies'],
            'max_chain_length'               => $values['retention']['max_chain_length'],
        );
        $calculator        = new MainWP_Backup_Schedule_Calculator( wp_timezone(), time() );
        $write['next_run'] = empty( $task->paused ) ? $calculator->compute_next_run( (object) $write, empty( $task->last_run ) ? null : (int) $task->last_run ) : (int) $task->next_run;
        $id                = $task ? (int) $task->id : 0;
        $before            = array();
        $option_before     = null;
        $key               = MainWP_Backup_Retention_Protection::TASK_OPTION_PREFIX . $id;
        $error             = new \WP_Error( 'mainwp_internal_error', esc_html__( 'Could not save the backup task. Please check that all required fields are filled in and try again. If the issue persists, check your server error log.', 'mainwp' ), array( 'status' => 500 ) );
        if ( $task ) {
            $raw = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
            if ( ! $raw || '' !== $wpdb->last_error ) {
                return $error;
            }
            $before        = array_intersect_key( $raw, $write );
            $option_before = $wpdb->get_row( $wpdb->prepare( 'SELECT option_id, option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, $key ), ARRAY_A );
            if ( '' !== $wpdb->last_error ) {
                return $error;
            }
            do_action( 'mainwp_update_backuptask', $id );
            $saved = $wpdb->update( $table, $write, array( 'id' => $id ) );
        } else {
            $write += array(
                'last'              => 0,
                'last_run'          => 0,
                'last_run_manually' => 0,
                'completed_sites'   => '',
                'completed'         => 0,
                'backup_errors'     => '',
                'backup_notices'    => '',
                'paused'            => 0,
                'template'          => 0,
                'manual_site_id'    => null,
            );
            $saved  = $wpdb->insert( $table, $write );
            if ( false === $saved ) {
                return $error;
            }
            $id            = (int) $wpdb->insert_id;
            $key           = MainWP_Backup_Retention_Protection::TASK_OPTION_PREFIX . $id;
            $option_before = $wpdb->get_row( $wpdb->prepare( 'SELECT option_id, option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, $key ), ARRAY_A );
            if ( '' !== $wpdb->last_error ) {
                return self::recover_failed_task_save( $id, $before, $write, $option_before, false, $values['retention']['remove_local_after_upload'], $error );
            }
        }
        $actual  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
        $matches = $actual && '' === $wpdb->last_error;
        foreach ( $write as $field => $value ) {
            $matches = $matches && array_key_exists( $field, $actual ?? array() ) && ( null === $value ? null === $actual[ $field ] : (string) $value === (string) $actual[ $field ] );
        }
        if ( false === $saved || ! $matches ) {
            return self::recover_failed_task_save( $id, $before, $write, $option_before, false, $values['retention']['remove_local_after_upload'], $error );
        }
        wp_cache_delete( $key, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        $saved_option = MainWP_Backup_Retention_Protection::save_task_remove_local_archives( $id, $values['retention']['remove_local_after_upload'] );
        $option_after = $wpdb->get_row( $wpdb->prepare( 'SELECT option_id, option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, $key ), ARRAY_A );
        if ( ! $saved_option || '' !== $wpdb->last_error || ! $option_after || ( $values['retention']['remove_local_after_upload'] ? '1' : '0' ) !== $option_after['option_value'] ) {
            return self::recover_failed_task_save( $id, $before, $write, $option_before, true, $values['retention']['remove_local_after_upload'], $error );
        }
        if ( null === $task ) {
            do_action( 'mainwp_add_backuptask', $id );
        }
        return array(
            'task_id'     => $id,
            'normalized'  => $values,
            'next_run_at' => empty( $write['next_run'] ) ? null : (int) $write['next_run'],
        );
    }

    /**
     * Recover only this save's values, leaving later edits in place.
     *
     * @param int        $id Task identity.
     * @param array      $before Raw prior task fields; empty for a new task.
     * @param array      $write This save's intended field values.
     * @param array|null $option_before Raw prior option row or absence.
     * @param bool       $option_attempted Whether the option write ran.
     * @param bool       $enabled This save's requested flag.
     * @param \WP_Error  $error Original save error.
     * @return \WP_Error Save or recovery failure.
     */
    private static function recover_failed_task_save( int $id, array $before, array $write, ?array $option_before, bool $option_attempted, bool $enabled, \WP_Error $error ): \WP_Error {
        global $wpdb;
        $table     = MainWP_DB::instance()->get_table_name( 'wp_backup' );
        $key       = MainWP_Backup_Retention_Protection::TASK_OPTION_PREFIX . $id;
        $recovered = true;
        if ( $option_attempted ) {
            $current = $wpdb->get_row( $wpdb->prepare( 'SELECT option_id, option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, $key ), ARRAY_A );
            if ( '' !== $wpdb->last_error ) {
                $recovered = false;
            } elseif ( $current !== $option_before ) {
                $own      = $enabled ? '1' : '0';
                $autoload = function_exists( 'wp_determine_option_autoload_value' ) ? wp_determine_option_autoload_value( $key, $enabled ? 1 : 0, $own, false ) : 'no';
                $where    = array(
                    'option_name'  => $key,
                    'option_value' => $own,
                    'autoload'     => $autoload,
                );
                if ( $option_before ) {
                    $where['option_id'] = (int) $option_before['option_id'];
                    $restored           = $wpdb->update(
                        $wpdb->options,
                        array(
                            'option_value' => $option_before['option_value'],
                            'autoload'     => $option_before['autoload'],
                        ),
                        $where
                    );
                } else {
                    $restored = $wpdb->delete( $wpdb->options, $where );
                }
                $recovered = false !== $restored && $recovered;
            }
        }
        wp_cache_delete( $key, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        $current   = $wpdb->get_row( $wpdb->prepare( 'SELECT option_id, option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, $key ), ARRAY_A );
        $recovered = '' === $wpdb->last_error && $current === $option_before && $recovered;
        if ( empty( $before ) ) {
            $deleted   = $wpdb->delete( $table, array( 'id' => $id ) );
            $remaining = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $table, $id ) );
            $recovered = false !== $deleted && '' === $wpdb->last_error && null === $remaining && $recovered;
        } else {
            foreach ( $before as $field => $value ) {
                if ( ( null === $write[ $field ] ? null : (string) $write[ $field ] ) === $value ) {
                    continue;
                }
                $set       = null === $value ? $wpdb->prepare( '%i = NULL', $field ) : $wpdb->prepare( '%i = %s', $field, $value );
                $where     = null === $write[ $field ] ? $wpdb->prepare( '%i IS NULL', $field ) : $wpdb->prepare( 'BINARY %i = BINARY %s', $field, (string) $write[ $field ] );
                $restored  = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET ', $table ) . $set . $wpdb->prepare( ' WHERE id = %d AND ', $id ) . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers and values are individually prepared.
                $recovered = false !== $restored && $recovered;
            }
            $current   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
            $recovered = $current && '' === $wpdb->last_error && array_intersect_key( $current, $before ) === $before && $recovered;
        }
        if ( ! $recovered ) {
            MainWP_Logger::instance()->warning( 'Backup task ' . $id . ' could not be restored after a failed save or concurrent change.' );
            return new \WP_Error(
                'mainwp_internal_error',
                __( 'The backup task could not be restored after a failed save or concurrent change. It may still hold new retention values. Reload the task and check its settings.', 'mainwp' ),
                array(
                    'status'                    => 500,
                    'task_save_recovery_failed' => true,
                )
            );
        }
        return $error;
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

    /**
     * Deleting a task can also destroy its historical snapshots.
     *
     * @param int    $task_id Task identity.
     * @param bool   $delete_snapshots Whether to purge owned snapshots.
     * @param string $context Calling transport.
     * @return array|\WP_Error
     */
    public static function delete_task( int $task_id, bool $delete_snapshots = true, string $context = 'ui' ) {
        $task = self::authorize_task( $task_id, 'delete_backup_tasks', $context );
        if ( is_wp_error( $task ) ) {
            return $task;
        }
        if ( ! in_array( $context, array( 'ui', 'cli', 'ability' ), true ) ) {
            return self::invalid_task_input( 'context' );
        }
        $error = self::native_task_write_error();
        if ( $error ) {
            return $error;
        }
        $history = self::authorize_task_history_sites( $task_id, 'delete_backup_tasks', $context );
        if ( is_wp_error( $history ) ) {
            return $history;
        }
        return self::task_action_result( MainWP_Manage_Backups_Handler::remove_backup_result( $task_id, $delete_snapshots, true ), (int) $task->completed < (int) $task->last_run ? MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING : 'mainwp_operation_failed' );
    }

    /**
     * Pausing remains available after native backups are switched off.
     *
     * @param int    $task_id Task identity.
     * @param string $context Calling transport.
     * @return array|\WP_Error
     */
    public static function pause_task( int $task_id, string $context = 'ui' ) {
        $task = self::authorize_task( $task_id, 'pause_resume_backup_tasks', $context );
        if ( is_wp_error( $task ) ) {
            return $task;
        }
        if ( ! in_array( $context, array( 'ui', 'cli', 'ability' ), true ) ) {
            return self::invalid_task_input( 'context' );
        }
        return self::task_action_result( MainWP_Manage_Backups_Handler::pause_backup_result( $task_id ) );
    }

    /**
     * A resumed schedule must stay inside its authorized run set.
     *
     * @param int    $task_id Task identity.
     * @param string $context Calling transport.
     * @return array|\WP_Error
     */
    public static function resume_task( int $task_id, string $context = 'ui' ) {
        $task = self::authorize_task( $task_id, 'pause_resume_backup_tasks', $context );
        if ( is_wp_error( $task ) ) {
            return $task;
        }
        if ( ! in_array( $context, array( 'ui', 'cli', 'ability' ), true ) ) {
            return self::invalid_task_input( 'context' );
        }
        $error = self::native_task_write_error();
        return $error ?? self::task_action_result( MainWP_Manage_Backups_Handler::resume_backup_result( $task_id ) );
    }

    /**
     * Manual runs need the same operation right and site scope as task execution.
     *
     * @param int    $task_id Task identity.
     * @param string $context Calling transport.
     * @return array|\WP_Error
     */
    public static function run_task( int $task_id, string $context = 'ui' ) {
        $task = self::authorize_task( $task_id, 'run_backup_tasks', $context );
        if ( is_wp_error( $task ) ) {
            return $task;
        }
        if ( ! in_array( $context, array( 'ui', 'cli', 'ability' ), true ) ) {
            return self::invalid_task_input( 'context' );
        }
        $error = self::native_task_write_error();
        if ( $error ) {
            return $error;
        }
        $stale   = array();
        $code    = '';
        $message = MainWP_Manage_Backups_Handler::manual_task_start_error( $task, $stale, $code );
        if ( '' !== $message ) {
            return new \WP_Error( $code, $message, array( 'status' => 409 ) );
        }
        $previous = MainWP_Backup_Run_History::set_context( 'ability' === $context ? 'ability' : 'manual', get_current_user_id() );
        try {
            $result = MainWP_Manage_Backups_Handler::run_backup_task_now( $task_id, $context );
            if ( isset( $result['error'] ) ) {
                $code = $result['error_code'] ?? MainWP_Backup_Error_Codes::ERR_START_FAILED;
                return new \WP_Error( $code, $result['error'], array( 'status' => MainWP_Backup_Error_Codes::ERR_START_FAILED === $code ? 502 : 409 ) );
            }
            if ( 'ability' === $context ) {
                // The handler marks a run it found open; running without that mark is a
                // won claim, and any status other than running or queued is a
                // just-closed run the call adopted. The handler's own read is the one
                // that counts: the row can open or close after this method read it.
                $result = array_merge(
                    $result,
                    self::ability_start_status(
                        ! empty( $result['attached'] ) || ! in_array( $result['status'], array( 'running', 'queued' ), true ),
                        'running' === $result['status'] ? true : null,
                        'queued' === $result['status'] ? MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id ) : null,
                        (int) $result['started']
                    )
                );
            }
            return $result;
        } finally {
            MainWP_Backup_Run_History::set_context( $previous['trigger'], $previous['user_id'] );
        }
    }

    /**
     * Agents act on the start status, so it names the run this call claimed or
     * attached to and never reports a slot refusal while a run holds the row.
     *
     * @param bool        $attached A run held the row before the call, or the call was absorbed by a clean run that just closed.
     * @param bool|null   $claimed Atomic claim result: true won, false lost, null not attempted.
     * @param object|null $row Task row read after the attempt, or null when not needed.
     * @param int         $started Start time of the run the call claimed or attached to, or the click time.
     * @return array{started: int, status: string}
     */
    private static function ability_start_status( bool $attached, ?bool $claimed, ?object $row, int $started ): array {
        if ( $attached ) {
            return array(
                'started' => $started,
                'status'  => 'already_running',
            );
        }
        if ( true === $claimed ) {
            return array(
                'started' => $started,
                'status'  => 'started',
            );
        }
        // A racing initiate can hold the row with a start older than this call's click.
        if ( null !== $row && (int) $row->completed < (int) $row->last_run ) {
            return array(
                'started' => (int) $row->last_run,
                'status'  => 'already_running',
            );
        }
        return array(
            'started' => $started,
            'status'  => 'not_started',
        );
    }

    /**
     * Legacy task refusals contain fixed Dashboard text, never Child or provider text.
     *
     * @param array  $result Existing task result.
     * @param string $code Refusal code.
     * @return array|\WP_Error
     */
    private static function task_action_result( array $result, string $code = 'mainwp_operation_failed' ) {
        if ( ! empty( $result['notask'] ) ) {
            return self::not_found( MainWP_Backup_Error_Codes::ERR_TASK_NOT_FOUND );
        }
        return isset( $result['error'] ) ? new \WP_Error( $code, $result['error'], array( 'status' => 'mainwp_operation_failed' === $code ? 502 : 409 ) ) : $result;
    }

    /**
     * Initiation absorbs clicks that already own a run.
     *
     * @param int    $site_id Website identity.
     * @param string $context Calling transport.
     * @return array|\WP_Error
     */
    public static function start_site_backup( int $site_id, string $context ) {
        if ( ! in_array( $context, array( 'ui', 'cli', 'ability' ), true ) ) {
            return new \WP_Error( 'mainwp_invalid_input', esc_html__( 'The supplied input is invalid.', 'mainwp' ), array( 'status' => 400 ) );
        }
        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED, wp_specialchars_decode( MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED ), ENT_QUOTES ), array( 'status' => 409 ) );
        }

        $website = self::authorize_site( $site_id, 'execute_backups', $context );
        if ( is_wp_error( $website ) ) {
            return $website;
        }

        if ( self::userless_cli() ) {
            return new \WP_Error( 'mainwp_permission_denied', esc_html__( 'Starting a backup from WP-CLI needs a Dashboard administrator. Run the command again with --user set to one.', 'mainwp' ), array( 'status' => 401 ) );
        }

        if ( MainWP_Post_Backup_Handler::site_is_disconnected( $website ) ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_SITE_DISCONNECTED, MainWP_Post_Backup_Handler::disconnected_site_error(), array( 'status' => 409 ) );
        }

        // A restore in flight for this site holds the child's single-backup
        // slot; refuse until it finishes so the two heavy operations cannot
        // collide on the same child.
        if ( null !== MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $site_id ) ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, esc_html__( 'A restore is in progress for this site. Try again once it finishes.', 'mainwp' ), array( 'status' => 409 ) );
        }

        MainWP_Post_Backup_Handler::raise_backup_resource_limits();

        $task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $website );
        if ( empty( $task ) ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_START_FAILED, esc_html__( 'Could not prepare the on-demand backup.', 'mainwp' ), array( 'status' => 500 ) );
        }

        // A run already in flight (last_run newer than completed): follow it
        // instead of resetting the run mid-flight.
        if ( (int) $task->completed < (int) $task->last_run ) {
            return array( 'job_id' => (int) $task->id ) + ( 'ability' === $context ? self::ability_start_status( true, null, null, (int) $task->last_run ) : array(
                'started' => (int) $task->last_run,
                'status'  => 'running',
            ) );
        }

        // A run that closed successfully seconds ago makes this initiate a
        // duplicate of the click that just finished (a second tab, a repeat
        // click landing as the run closed). Absorb it: point the poll at the
        // run that produced the snapshot instead of reopening it, which would
        // fire a second preflight, orphan a child task, and poison the
        // original click's freshness check into a false failure.
        $settle = MainWP_Backup_Settings::get_manual_run_settle_seconds();
        if ( $settle > 0 && '' === (string) $task->backup_errors && (int) $task->completed > time() - $settle ) {
            /** This action is documented in pages/page-mainwp-manage-backups-handler.php */
            do_action( 'mainwp_backup_diag_run_reinit', (int) $task->id, 'settle_absorbed', (int) $task->last_run, (int) $task->completed );
            if ( 'ability' !== $context ) {
                $snapshot = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
                if ( null !== $snapshot && (int) $snapshot->created < (int) $task->last_run ) {
                    $snapshot = null;
                }
                $message = __( 'This site finished a backup a few seconds ago, so a new one was not started.', 'mainwp' );
                if ( null !== $snapshot ) {
                    $message .= ' ' . sprintf(
                        /* translators: %s: Date and time the finished snapshot was taken. */
                        __( 'Snapshot time: %s.', 'mainwp' ),
                        MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( (int) $snapshot->created ) )
                    );
                }
            }
            return array( 'job_id' => (int) $task->id ) + ( 'ability' === $context ? self::ability_start_status( true, null, null, (int) $task->last_run ) : array(
                'started'     => (int) $task->last_run,
                'status'      => 'queued',
                'settled'     => true,
                'snapshot_id' => null !== $snapshot ? (int) $snapshot->id : 0,
                'message'     => $message,
            ) );
        }

        // Kick the first step. updateRun = true resets completed_sites and
        // stamps last_run through a claim the settle window can refuse, which
        // both starts the run and makes it eligible for the
        // cron_backups_continue backstop. The claim result distinguishes a
        // slot refusal from a run that opened and failed before returning.
        $started         = time();
        $previous        = MainWP_Backup_Run_History::set_context( 'ability' === $context ? 'ability' : 'manual', get_current_user_id() );
        $claimed         = null;
        $claimed_started = 0;
        try {
            MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true, $settle, $claimed, $claimed_started );
        } finally {
            MainWP_Backup_Run_History::set_context( $previous['trigger'], $previous['user_id'] );
        }

        // last_run is now stamped, so the task is eligible for a continue.
        // Kick one immediately so a tab-closed run finishes within seconds
        // instead of waiting for the 5-minute recurring backup cron.
        MainWP_Post_Backup_Handler::kick_backup_continuation();

        // Re-read the row: a successful claim stamped last_run, and a racing
        // initiate that won the claim stamped its own. Keying the response's
        // started value to the row (when it advanced) makes the poll judge
        // freshness against the run that actually owns this backup; a slot
        // miss leaves the row untouched and keeps the click's own timestamp.
        // An ability caller that won the claim gets its own run's start, since
        // another caller can claim a newer run between the close and this read.
        $task        = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        $started_out = null !== $task && (int) $task->last_run >= $started ? (int) $task->last_run : $started;

        // The hidden manual task is normally created by the claim above, but a
        // concurrent site delete (or a failed insert) can leave the re-read
        // empty; report the failure instead of dereferencing null.
        if ( empty( $task ) ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_START_FAILED, esc_html__( 'The backup could not be started for this site. Please reload the page and try again.', 'mainwp' ), array( 'status' => 502 ) );
        }

        return array( 'job_id' => (int) $task->id ) + ( 'ability' === $context ? self::ability_start_status( false, $claimed, $task, true === $claimed ? $claimed_started : $started_out ) : array(
            'started' => $started_out,
            'status'  => true === $claimed ? 'running' : 'queued',
        ) );
    }

    /**
     * Local completion requires an explicit stop of this run's Child task.
     *
     * An interrupted backup leaves a non-terminal task on the child that blocks
     * every new backup, and on an idle child the watchdog cron may never fire to
     * clear it. This is the Dashboard escape hatch: it tells the child to cancel
     * its known backup task, then stops treating the local manual run as
     * in-flight only after the child confirms the cancel.
     *
     * @param int    $site_id Website identity.
     * @param string $context Calling transport.
     * @return array|\WP_Error
     */
    public static function cancel_site_backup( int $site_id, string $context = 'ability' ) {
        $website = self::authorize_site( $site_id, 'execute_backups', $context );
        if ( is_wp_error( $website ) ) {
            return $website;
        }
        $db   = MainWP_DB_Backup::instance();
        $task = $db->get_manual_backup_task( $site_id );
        if ( ! $task || (int) $task->completed >= (int) $task->last_run ) {
            return array(
                'status' => 'not_running',
                'result' => 'not_running',
            );
        }
        // Pass only the child task id from this run's progress row: without it,
        // the child could resolve and cancel another run's active backup.
        // A creating marker is not a task id the child knows: a cancel inside
        // the create window waits for the task id instead.
        $progress = $db->get_backup_task_progress( (int) $task->id, $site_id );
        $id       = (string) ( $progress->child_backup_task_id ?? '' );
        if ( '' === $id || MainWP_Backup_Executor::is_creating_marker( $id ) ) {
            return array(
                'status' => 'busy',
                'result' => 'busy',
            );
        }
        try {
            $reply = MainWP_Backup_Executor::send_backup_task_cancel( $website, $id );
        } catch ( \Exception $e ) {
            $reply = null;
        }
        $result = null;
        if ( is_array( $reply ) && ! array_key_exists( 'error', $reply ) && is_string( $reply['result'] ?? null ) ) {
            switch ( $reply['result'] ?? '' ) {
                case 'ok':
                    $result = 'cancelled';
                    break;
                case 'already_terminal':
                    if ( 'completed' === ( $reply['phase'] ?? null ) ) {
                        $result = 'already_finished';
                    } elseif ( 'failed' === ( $reply['phase'] ?? null ) ) {
                        $result = 'already_failed';
                    }
                    break;
                case 'busy':
                    // A live runner still owns the lease mid-slice, so the child refused
                    // rather than fight it: the backup is still running. Closing the local
                    // run here would report a cancel that did not happen and leave the poll
                    // blind to a child task that keeps going.
                    $result = 'busy';
                    break;
                case 'no_active':
                    $result = 'no_active_on_site';
                    break;
                default:
                    break;
            }
        }
        if ( null === $result ) {
            return new \WP_Error( 'mainwp_operation_failed', self::safe_error( 'mainwp_operation_failed' )['message'], array( 'status' => 502 ) );
        }
        if ( 'cancelled' === $result ) {
            MainWP_Backup_Run_History::finalize_run( (int) $task->id, (int) $task->last_run, 'cancelled', $site_id );
            // Close the local manual run so the next poll does not re-report it as
            // running; the child confirmed this cancellation. The cancelled note and
            // log line are the diagnostic trace: without them a cancelled run is
            // indistinguishable from an ordinary completion later.
            $db->update_backup_task_with_values( (int) $task->id, array( 'completed' => time() ) );
            $db->update_backup_notices( (int) $task->id, esc_html__( 'Backup cancelled from the dashboard.', 'mainwp' ) . "\n" );
            // Drop the cancelled child task from the progress row, or the
            // progress poll keeps projecting it as running / waiting forever.
            $db->update_backup_task_progress(
                (int) $task->id,
                $site_id,
                array(
                    'child_backup_task_id' => '',
                    'child_phase'          => '',
                )
            );
            MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Backup cancelled from the dashboard (child task: ' . $id . ')' );
        }
        return array(
            'status' => $result,
            'result' => $result,
        );
    }

    /**
     * A repeated request keeps its original deadline and settled sites.
     *
     * @param int    $task_id Scheduled task identity.
     * @param int    $run Identity shown to the user.
     * @param string $context Calling transport.
     * @return array|\WP_Error AJAX result.
     */
    public static function cancel_task_run( int $task_id, int $run, string $context = 'ability' ) {
        $task = self::authorize_task( $task_id, 'pause_resume_backup_tasks', $context );
        if ( is_wp_error( $task ) ) {
            return $task;
        }
        if ( ! MainWP_Install::backup_task_run_cancel_ready() ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_NOT_STALLED, esc_html__( 'Cancel run is not available until the database update finishes.', 'mainwp' ) );
        }
        if ( $run !== (int) $task->last_run ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_NOT_STALLED, esc_html__( 'This run already ended.', 'mainwp' ) );
        }
        if ( (int) $task->completed >= (int) $task->last_run ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_NOT_STALLED, esc_html__( 'This task is not currently running.', 'mainwp' ) );
        }
        if ( null === MainWP_Backup_Run_Cancel::pending_request( $task ) ) {
            $now     = time();
            $request = array(
                'run'         => $run,
                'requested'   => $now,
                'deadline'    => $now + 1800,
                'user'        => get_current_user_id(),
                'lease'       => '',
                'lease_until' => 0,
                'settled'     => (object) array(),
            );
            if ( ! MainWP_DB_Backup::instance()->compare_and_swap_run_cancel( $task_id, $run, $task->run_cancel, (string) wp_json_encode( $request ) ) ) {
                $current = self::authorize_task( $task_id, 'pause_resume_backup_tasks', $context );
                if ( is_wp_error( $current ) ) {
                    return $current;
                }
                if ( $run !== (int) $current->last_run || (int) $current->completed >= $run ) {
                    return new \WP_Error( MainWP_Backup_Error_Codes::ERR_NOT_STALLED, esc_html__( 'This run already ended.', 'mainwp' ) );
                }
                if ( null === MainWP_Backup_Run_Cancel::pending_request( $current ) ) {
                    return new \WP_Error( 'mainwp_operation_failed', self::safe_error( 'mainwp_operation_failed' )['message'] );
                }
            }
        }
        $pass = MainWP_Backup_Run_Cancel::process( $task_id, 'ajax' );
        if ( 'closed' === $pass['status'] ) {
            return array( 'result' => $pass['closed_as'] );
        }
        return array(
            'result'  => 'cancelling',
            'waiting' => $pass['waiting'],
        );
    }

    /**
     * A stale Dashboard run can close even when its Child stop is unconfirmed.
     *
     * @param int    $task_id Scheduled task identity.
     * @param string $context Calling transport.
     * @return array|\WP_Error Reset evidence.
     */
    public static function reset_task_run( int $task_id, string $context = 'ability' ) {
        $task = self::authorize_task( $task_id, 'pause_resume_backup_tasks', $context );
        if ( is_wp_error( $task ) ) {
            return $task;
        }
        if ( (int) $task->completed >= (int) $task->last_run ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_NOT_STALLED, esc_html__( 'This task is not currently running.', 'mainwp' ), array( 'status' => 409 ) );
        }
        if ( time() - (int) $task->last_run <= MainWP_Backup_Executor::STALL_THRESHOLD_SECONDS ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_NOT_STALLED, esc_html__( 'This task is still within its normal run window and cannot be reset yet.', 'mainwp' ), array( 'status' => 409 ) );
        }
        $db          = MainWP_DB_Backup::instance();
        $cancelled   = 0;
        $uncancelled = array();
        foreach ( MainWP_Manage_Backups_Handler::resolve_reset_task_site_ids( $task ) as $site_id ) {
            $website  = MainWP_DB::instance()->get_website_by_id( (int) $site_id );
            $progress = $db->get_backup_task_progress( (int) $task->id, (int) $site_id );
            // The child task id must come from this task's own progress row;
            // a creating marker is not an id the child knows. Without a known
            // id, the transport sends nothing and the dashboard-only close
            // is the fallback.
            try {
                $reply = $website ? MainWP_Backup_Executor::send_backup_task_cancel( $website, (string) ( $progress->child_backup_task_id ?? '' ) ) : null;
            } catch ( \Exception $e ) {
                $reply = null;
            }
            // A busy reply means a live runner still owns the lease mid-slice.
            // Only an explicit stop or a known terminal phase confirms this site's stop.
            $confirmed = is_array( $reply ) && ! array_key_exists( 'error', $reply ) && ( 'ok' === ( $reply['result'] ?? '' ) || ( 'already_terminal' === ( $reply['result'] ?? '' ) && in_array( $reply['phase'] ?? '', array( 'completed', 'failed' ), true ) ) );
            if ( $confirmed ) {
                ++$cancelled;
                MainWP_Logger::instance()->debug_for_website(
                    $website,
                    'backup',
                    'Backup cancelled on the child during a stale task reset (child task: ' . (string) $progress->child_backup_task_id . ')'
                );
                // Drop the cancelled child task from the progress row, or the
                // progress poll keeps projecting it as running / waiting forever.
                $db->update_backup_task_progress(
                    (int) $task->id,
                    (int) $site_id,
                    array(
                        'child_backup_task_id' => '',
                        'child_phase'          => '',
                    )
                );
            } else {
                $uncancelled[] = (int) $site_id;
            }
        }
        $note = esc_html__( 'Backup run reset from the dashboard after being flagged stale.', 'mainwp' );
        if ( $uncancelled ) {
            $note .= ' ' . sprintf(
                /* translators: %s: Comma-separated site ids. */
                esc_html__( 'The run could not be stopped on the child for site(s) %s; their run record was cleared on the dashboard only.', 'mainwp' ),
                implode( ', ', $uncancelled )
            );
        }
        $updated = $db->update_backup_task_with_values( (int) $task->id, array( 'completed' => time() ) );
        $db->update_backup_notices( (int) $task->id, $note . "\n" );
        // A refused close leaves the driver responsible for this run's outcome.
        if ( false !== $updated ) {
            try {
                $closed = $db->get_backup_task_by_id( (int) $task->id );
                if ( ! empty( $closed ) && (int) $closed->completed >= (int) $task->last_run ) {
                    MainWP_Backup_Run_History::finalize_run( (int) $task->id, (int) $task->last_run, 'reset' );
                }
            } catch ( \Throwable $e ) {
                // The confirmation read serves history only; the reset has already landed.
            }
        }
        return array(
            'status'            => 'reset',
            'cancelled'         => $cancelled,
            'uncancelled'       => count( $uncancelled ),
            'sites_uncancelled' => $uncancelled,
        );
    }

    /**
     * Admission refusals must follow site authorization for every caller.
     *
     * @param int    $site_id Site identity.
     * @param int    $snapshot_id Snapshot identity.
     * @param array  $options Restore options and destructive consent.
     * @param string $context ui, cli or ability.
     * @throws MainWP_Exception When the browser needs its existing refusal payload.
     * @return array|\WP_Error
     */
    public static function restore_snapshot( int $site_id, int $snapshot_id, array $options, string $context ) {
        $site = self::authorize_site( $site_id, 'execute_backups', $context );
        if ( is_wp_error( $site ) ) {
            return $site;
        }
        $restore = 'ui' === $context ? self::authorize_ui( 'restore_backups' ) : self::authorize_base( 'restore_backups' );
        if ( is_wp_error( $restore ) ) {
            return $restore;
        }
        if ( ! in_array( $context, array( 'ui', 'cli', 'ability' ), true ) ) {
            return new \WP_Error( 'mainwp_invalid_input', self::safe_error( 'mainwp_invalid_input' )['message'], array( 'status' => 400 ) );
        }
        if ( MainWP_Backup_Settings::is_native_backups_enabled() && ! MainWP_Backup_Restore_Orchestrator::site_is_disconnected( $site ) ) {
            $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
            if ( null === $snapshot || (int) $snapshot->site_id !== $site_id ) {
                if ( 'ui' === $context ) {
                    throw new MainWP_Exception( esc_html__( 'Snapshot not found for this site.', 'mainwp' ), null, MainWP_Backup_Error_Codes::ERR_SNAPSHOT_NOT_FOUND ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the fixed message is escaped and the code is a trusted constant.
                }
                return self::not_found( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_NOT_FOUND );
            }
        }
        try {
            return array( 'job_id' => MainWP_Backup_Restore_Orchestrator::instance()->initiate_restore( $site_id, $snapshot_id, $options ) );
        } catch ( MainWP_Exception $e ) {
            return self::restore_refusal( $e, $context );
        }
    }

    /**
     * Rollback admission shares the same site right as a new restore.
     *
     * @param int    $restore_job_id Source job identity.
     * @param string $context ui or ability.
     * @return array|\WP_Error
     */
    public static function rollback_restore( int $restore_job_id, string $context = 'ability' ) {
        $job = self::authorize_restore_job( $restore_job_id, true, $context );
        if ( is_wp_error( $job ) ) {
            return $job;
        }
        // A reserved row is an internal slot hold, not a restore anyone can roll back.
        if ( null === $job || 'reserved' === $job->status ) {
            return self::not_found( MainWP_Backup_Error_Codes::ERR_RESTORE_JOB_NOT_FOUND );
        }
        $site = self::authorize_site( (int) $job->site_id, 'execute_backups', $context );
        if ( is_wp_error( $site ) ) {
            return $site;
        }
        try {
            return array( 'job_id' => MainWP_Backup_Restore_Orchestrator::instance()->initiate_rollback( $job ) );
        } catch ( MainWP_Exception $e ) {
            return self::restore_refusal( $e, $context );
        }
    }

    /**
     * Browser adapters retain their exceptions; machine errors never carry raw text.
     *
     * @param MainWP_Exception $exception Admission refusal.
     * @param string           $context Caller context.
     * @throws MainWP_Exception When the browser needs its existing refusal payload.
     * @return \WP_Error
     */
    private static function restore_refusal( MainWP_Exception $exception, string $context ): \WP_Error {
        if ( 'ui' === $context ) {
            throw $exception; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the orchestrator escapes its exceptions.
        }
        $safe = self::safe_error( $exception->get_message_error_code() );
        $data = array( 'status' => MainWP_Backup_Error_Codes::ERR_UNCLASSIFIED_ERROR === $safe['code'] ? 500 : 409 );
        if ( 'mainwp_confirmation_required' === $safe['code'] ) {
            $facts                        = (array) $exception->get_data();
            $data['site_has_woocommerce'] = ! empty( $facts['site_has_woocommerce'] );
            $data['age_days']             = max( 0, (int) ( $facts['age_days'] ?? 0 ) );
        }
        return new \WP_Error( $safe['code'], $safe['message'], $data );
    }

    /**
     * Stored tests need provider and name before the handler loads their settings.
     *
     * @param int $id Destination identity.
     * @return array|\WP_Error
     */
    public static function test_destination( int $id ) {
        $destination = self::authorize_destination( $id );
        if ( is_wp_error( $destination ) ) {
            return $destination;
        }
        $disabled = self::native_task_write_error();
        if ( is_wp_error( $disabled ) ) {
            return $disabled;
        }
        $before = get_option( MainWP_Backup_Storage_SFTP::HOSTKEYS_OPTION, array() );
        $result = self::storage_result(
            MainWP_Backup_Storage_Handler::test_saved_or_submitted(
                array(
                    'id'       => $id,
                    'provider' => $destination->provider,
                    'name'     => $destination->name,
                    'settings' => null,
                )
            ),
            (string) $destination->provider
        );
        $after  = get_option( MainWP_Backup_Storage_SFTP::HOSTKEYS_OPTION, array() );
        $pinned = 'sftp' === $destination->provider && ! isset( $before[ $id ] ) && isset( $after[ $id ] );
        if ( is_wp_error( $result ) ) {
            $result->add_data(
                array(
                    'status'          => $result->get_error_data()['status'] ?? 502,
                    'host_key_pinned' => $pinned,
                )
            );
        } else {
            $result['host_key_pinned'] = $pinned;
        }
        return $result;
    }

    /**
     * Rename and default changes reuse the existing connection-test-before-save flow.
     *
     * @param int   $id Destination identity.
     * @param array $fields Name or default flag.
     * @return array|\WP_Error
     */
    public static function update_destination( int $id, array $fields ) {
        $destination = self::authorize_destination( $id );
        if ( is_wp_error( $destination ) ) {
            return $destination;
        }
        $disabled = self::native_task_write_error();
        if ( is_wp_error( $disabled ) ) {
            return $disabled;
        }
        $fields = self::prepare_destination_fields( $fields );
        if ( is_wp_error( $fields ) ) {
            return $fields;
        }
        if ( ! empty( $destination->credentials_invalid ) ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_DESTINATION_CREDENTIALS_REQUIRED, self::safe_error( MainWP_Backup_Error_Codes::ERR_DESTINATION_CREDENTIALS_REQUIRED )['message'], array( 'status' => 400 ) );
        }
        return self::storage_result(
            MainWP_Backup_Storage_Handler::save_destination(
                array(
                    'id'         => $id,
                    'provider'   => $destination->provider,
                    'name'       => $fields['name'] ?? $destination->name,
                    'is_default' => $fields['is_default'] ?? ! empty( $destination->is_default ),
                    'settings'   => array_merge( $destination->settings, array_intersect_key( $fields, array( 'minimum_remote_retention_days' => true ) ) ),
                )
            ),
            (string) $destination->provider
        );
    }

    /**
     * Preview and save must use the same sanitized destination changes.
     *
     * @param array $fields Name or default flag.
     * @return array|\WP_Error
     */
    private static function prepare_destination_fields( array $fields ) {
        if ( ! $fields || array_diff( array_keys( $fields ), array( 'name', 'is_default', 'minimum_remote_retention_days' ) ) || ( array_key_exists( 'name', $fields ) && ! is_string( $fields['name'] ) ) || ( array_key_exists( 'is_default', $fields ) && ! is_bool( $fields['is_default'] ) ) ) {
            return new \WP_Error( 'mainwp_invalid_input', self::safe_error( 'mainwp_invalid_input' )['message'], array( 'status' => 400 ) );
        }
        if ( array_key_exists( 'minimum_remote_retention_days', $fields ) && ( ! is_int( $fields['minimum_remote_retention_days'] ) || $fields['minimum_remote_retention_days'] < 0 || $fields['minimum_remote_retention_days'] > 36500 ) ) {
            return new \WP_Error( 'mainwp_invalid_input', self::safe_error( 'mainwp_invalid_input' )['message'], array( 'status' => 400 ) );
        }
        if ( array_key_exists( 'name', $fields ) ) {
            $fields['name'] = sanitize_text_field( $fields['name'] );
            if ( '' === $fields['name'] || mb_strlen( $fields['name'], 'UTF-8' ) > 255 ) {
                return new \WP_Error( 'mainwp_invalid_input', self::safe_error( 'mainwp_invalid_input' )['message'], array( 'status' => 400 ) );
            }
        }
        return $fields;
    }

    /**
     * Connection tests can establish SFTP trust when no fingerprint or pin exists.
     *
     * @param object $destination Stored destination.
     * @return bool
     */
    private static function destination_may_pin_host_key( object $destination ): bool {
        // Unreadable settings fail the credential check before any handshake, so nothing can be pinned.
        if ( ! empty( $destination->credentials_invalid ) || ! is_array( $destination->settings ) ) {
            return false;
        }
        $pins = get_option( MainWP_Backup_Storage_SFTP::HOSTKEYS_OPTION, array() );
        return 'sftp' === $destination->provider && empty( $destination->settings['host_fingerprint'] ) && ! isset( $pins[ (int) $destination->id ] );
    }

    /**
     * The storage handler owns default uniqueness.
     *
     * @param int $id Destination identity.
     * @return array|\WP_Error
     */
    public static function set_default_destination( int $id ) {
        $destination = self::authorize_destination( $id );
        if ( is_wp_error( $destination ) ) {
            return $destination;
        }
        $disabled = self::native_task_write_error();
        return is_wp_error( $disabled ) ? $disabled : self::storage_result( MainWP_Backup_Storage_Handler::set_default_destination( $id ) );
    }

    /**
     * The existing flow detaches tasks and retires pending jobs before deleting storage.
     *
     * @param int $id Destination identity.
     * @return array|\WP_Error
     */
    public static function delete_destination( int $id ) {
        $destination = self::authorize_destination( $id );
        if ( is_wp_error( $destination ) ) {
            return $destination;
        }
        $disabled = self::native_task_write_error();
        if ( is_wp_error( $disabled ) ) {
            return $disabled;
        }
        $count  = count( MainWP_Backup_Storage_Handler::tasks_using_destination( $id ) );
        $result = self::storage_result( MainWP_Backup_Storage_Handler::delete_destination_flow( $id, true ) );
        if ( ! is_wp_error( $result ) ) {
            $result['tasks_detached'] = $count;
        }
        return $result;
    }

    /**
     * Provider details can contain credentials or paths, so never copy their errors.
     *
     * @param array  $result Existing handler response.
     * @param string $provider Provider whose probe failed.
     * @return array|\WP_Error
     */
    private static function storage_result( array $result, string $provider = '' ) {
        if ( isset( $result['error'] ) || ( array_key_exists( 'ok', $result ) && ! $result['ok'] ) ) {
            $codes = array(
                's3'      => MainWP_Backup_Error_Codes::ERR_S3_FAILED,
                'gcs'     => MainWP_Backup_Error_Codes::ERR_GCS_FAILED,
                'dropbox' => MainWP_Backup_Error_Codes::ERR_DROPBOX_FAILED,
                'sftp'    => MainWP_Backup_Error_Codes::ERR_FTP_FAILED,
                'local'   => MainWP_Backup_Error_Codes::ERR_LOCAL_FAILED,
            );
            $code  = $codes[ $provider ] ?? 'mainwp_internal_error';
            return new \WP_Error( $code, self::safe_error( $code )['message'], array( 'status' => 'mainwp_internal_error' === $code ? 500 : 502 ) );
        }
        return $result;
    }

    /**
     * Global settings share one saver without exposing recovery key material.
     *
     * @param array  $values Allowed settings, with omitted fields preserved.
     * @param string $context ui or ability.
     * @return array|\WP_Error
     */
    public static function save_backup_settings( array $values, string $context = 'ability' ) {
        $base = self::authorize_base( 'manage_dashboard_settings' );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        if ( MainWP_System_Utility::is_team_control_staff() || ( ! self::userless_cli() && ! MainWP_System_Utility::is_admin() ) ) {
            return new \WP_Error( 'mainwp_permission_denied', self::safe_error( 'mainwp_permission_denied' )['message'], array( 'status' => 403 ) );
        }
        if ( ! in_array( $context, array( 'ui', 'ability' ), true ) ) {
            return new \WP_Error( 'mainwp_invalid_input', self::safe_error( 'mainwp_invalid_input' )['message'], array( 'status' => 400 ) );
        }
        if ( 'ui' !== $context ) {
            $scope = MainWP_Abilities_Util::check_unrestricted_access();
            if ( is_wp_error( $scope ) ) {
                return $scope;
            }
        }
        $writes = self::backup_settings_writes( $values, $context );
        if ( is_wp_error( $writes ) ) {
            return $writes;
        }
        $options = array(
            'on_demand_retention_count'   => 'mainwp_on_demand_retention_count',
            'max_concurrent_backups'      => 'mainwp_backup_max_concurrent',
            'diagnostics_enabled'         => MainWP_Backup_Diagnostics::OPTION_ENABLED,
            'update_gate.enabled'         => 'mainwp_backup_before_upgrade',
            'update_gate.max_age_days'    => 'mainwp_backup_before_upgrade_days',
            'update_gate.require_offsite' => 'mainwp_backup_before_upgrade_require_offsite',
        );
        foreach ( $writes as $key => $value ) {
            if ( 'native_backups_enabled' === $key ) {
                if ( $value && ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
                    MainWP_Utility::update_option( 'mainwp_primaryBackup', '' );
                }
                MainWP_Backup_Settings::update_native_backups_option( $value );
            } else {
                MainWP_Utility::update_option( $options[ $key ], (int) $value );
            }
        }
        return $values;
    }

    /**
     * Validation must finish before either a preview or any persisted setting.
     *
     * @param array  $values Submitted policy fields.
     * @param string $context Caller policy bounds.
     * @return array|\WP_Error Flattened validated changes.
     */
    private static function backup_settings_writes( array $values, string $context = 'ability' ) {
        $fields = array( 'native_backups_enabled', 'on_demand_retention_count', 'max_concurrent_backups', 'diagnostics_enabled', 'update_gate' );
        $gate   = array( 'enabled', 'max_age_days', 'require_offsite' );
        if ( ! $values || array_diff( array_keys( $values ), $fields ) || ( isset( $values['update_gate'] ) && ( ! is_array( $values['update_gate'] ) || ! $values['update_gate'] || array_diff( array_keys( $values['update_gate'] ), $gate ) ) ) ) {
            return new \WP_Error( 'mainwp_invalid_input', self::safe_error( 'mainwp_invalid_input' )['message'], array( 'status' => 400 ) );
        }
        $writes = array();
        foreach ( $values as $key => $value ) {
            if ( 'update_gate' === $key ) {
                if ( ! is_array( $value ) ) {
                    return new \WP_Error( 'mainwp_invalid_input', self::safe_error( 'mainwp_invalid_input' )['message'], array( 'status' => 400 ) );
                }
                foreach ( $value as $field => $setting ) {
                    $writes[ 'update_gate.' . $field ] = $setting;
                }
            } else {
                $writes[ $key ] = $value;
            }
        }
        // The older form accepts larger counts; machine writes use the bounded policy.
        $limits = array(
            'on_demand_retention_count' => 100,
            'max_concurrent_backups'    => 10,
            'update_gate.max_age_days'  => 365,
        );
        foreach ( $writes as $key => $value ) {
            $integer = in_array( $key, array( 'on_demand_retention_count', 'max_concurrent_backups', 'update_gate.max_age_days' ), true );
            if ( ( $integer && ( ! is_int( $value ) || $value < 1 || ( 'ui' !== $context && $value > $limits[ $key ] ) ) ) || ( ! $integer && ! is_bool( $value ) ) ) {
                return new \WP_Error( 'mainwp_invalid_input', self::safe_error( 'mainwp_invalid_input' )['message'], array( 'status' => 400 ) );
            }
        }
        return $writes;
    }

    /**
     * Provider probes and the deleting flow can write, so previews only inspect stored rows.
     *
     * @param int    $id Destination identity.
     * @param string $action Destination action slug.
     * @param array  $fields Name or default change.
     * @return array|\WP_Error
     */
    public static function preview_destination( int $id, string $action, array $fields = array() ) {
        $destination = self::authorize_destination( $id );
        if ( is_wp_error( $destination ) ) {
            return $destination;
        }
        $disabled = self::native_task_write_error();
        if ( is_wp_error( $disabled ) ) {
            return $disabled;
        }
        $effect = array( 'destination_id' => $id );
        switch ( $action ) {
            case 'test-backup-destination':
                $effect['provider']            = (string) $destination->provider;
                $effect['writes_probe_object'] = true;
                $effect['may_pin_host_key']    = self::destination_may_pin_host_key( $destination );
                break;
            case 'set-default-backup-destination':
                $previous                      = MainWP_Backup_Storage_Destinations::instance()->get_default();
                $effect['previous_default_id'] = $previous ? (int) $previous->id : null;
                break;
            case 'update-backup-destination':
                $fields = self::prepare_destination_fields( $fields );
                if ( is_wp_error( $fields ) ) {
                    return $fields;
                }
                if ( ! empty( $destination->credentials_invalid ) ) {
                    return new \WP_Error( MainWP_Backup_Error_Codes::ERR_DESTINATION_CREDENTIALS_REQUIRED, self::safe_error( MainWP_Backup_Error_Codes::ERR_DESTINATION_CREDENTIALS_REQUIRED )['message'], array( 'status' => 400 ) );
                }
                $effect['changes'] = array();
                foreach ( $fields as $field => $value ) {
                    $before = 'minimum_remote_retention_days' === $field ? (int) ( $destination->settings[ $field ] ?? 0 ) : ( 'is_default' === $field ? ! empty( $destination->is_default ) : (string) $destination->name );
                    if ( $before !== $value ) {
                        $effect['changes'][] = array(
                            'field' => $field,
                            'from'  => $before,
                            'to'    => $value,
                        );
                    }
                }
                $effect['connection_test_runs'] = true;
                $effect['may_pin_host_key']     = self::destination_may_pin_host_key( $destination );
                break;
            case 'delete-backup-destination':
                $effect['tasks_detached'] = array();
                foreach ( MainWP_Backup_Storage_Handler::tasks_using_destination( $id ) as $task ) {
                    $effect['tasks_detached'][] = array(
                        'task_id' => (int) $task->id,
                        'name'    => (string) $task->name,
                    );
                }
                $effect['pending_jobs_failed'] = array(
                    'upload' => 0,
                    'delete' => 0,
                );
                global $wpdb;
                $table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
                $jobs  = $wpdb->get_results( $wpdb->prepare( "SELECT kind, COUNT(*) AS total FROM {$table} WHERE destination_id = %d AND status NOT IN (%s, %s, %s) GROUP BY kind", $id, MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, MainWP_Backup_Upload_Job_Store::STATUS_CREDENTIALS_INVALID ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the predicate must match the handler's cross-snapshot retirement rule.
                if ( null === $jobs || '' !== $wpdb->last_error ) {
                    return new \WP_Error( 'mainwp_internal_error', self::safe_error( 'mainwp_internal_error' )['message'], array( 'status' => 500 ) );
                }
                foreach ( $jobs as $job ) {
                    if ( isset( $effect['pending_jobs_failed'][ $job['kind'] ] ) ) {
                        $effect['pending_jobs_failed'][ $job['kind'] ] = (int) $job['total'];
                    }
                }
                $effect['remote_objects_left_in_place'] = true;
                $effect['was_default']                  = ! empty( $destination->is_default );
                break;
            default:
                return new \WP_Error( 'mainwp_invalid_input', self::safe_error( 'mainwp_invalid_input' )['message'], array( 'status' => 400 ) );
        }
        return self::backup_preview( $effect );
    }

    /**
     * Global policy previews must share validation without touching options or crons.
     *
     * @param array $values Submitted policy fields.
     * @return array|\WP_Error
     */
    public static function preview_backup_settings( array $values ) {
        $base = self::authorize_base( 'manage_dashboard_settings' );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        $scope = MainWP_Abilities_Util::check_unrestricted_access();
        if ( is_wp_error( $scope ) ) {
            return $scope;
        }
        $writes = self::backup_settings_writes( $values );
        if ( is_wp_error( $writes ) ) {
            return $writes;
        }
        $settings = MainWP_Backup_Read_Model::get_settings();
        $changes  = array();
        foreach ( $writes as $key => $value ) {
            $before = 0 === strpos( $key, 'update_gate.' ) ? $settings['update_gate'][ substr( $key, 12 ) ] : $settings[ $key ];
            if ( $before !== $value ) {
                $changes[] = array(
                    'setting' => $key,
                    'from'    => $before,
                    'to'      => $value,
                );
            }
        }
        $stops  = isset( $values['native_backups_enabled'] ) && ! $values['native_backups_enabled'] && $settings['native_backups_enabled'];
        $clears = ! empty( $values['native_backups_enabled'] ) && ! $settings['native_backups_enabled'] && '' !== $settings['primary_backup_method'];
        return self::backup_preview(
            array(
                'changes'                      => $changes,
                'stops_scheduled_backups'      => $stops,
                'clears_primary_backup_method' => $clears,
                'primary_backup_method'        => $clears ? substr( sanitize_key( $settings['primary_backup_method'] ), 0, 64 ) : null,
            )
        );
    }

    /**
     * The stored confirmation decides whether a stop can be claimed.
     *
     * @param int    $restore_job_id Restore identity.
     * @param string $context Caller context.
     * @return array|\WP_Error Cancellation evidence.
     */
    public static function cancel_restore( int $restore_job_id, string $context = 'ability' ) {
        if ( ! in_array( $context, array( 'ui', 'cli', 'ability' ), true ) ) {
            return new \WP_Error( 'mainwp_invalid_input', self::safe_error( 'mainwp_invalid_input' )['message'], array( 'status' => 400 ) );
        }
        $before = self::authorize_restore_job( $restore_job_id, true, $context );
        if ( is_wp_error( $before ) ) {
            return $before;
        }
        $call = 'not called';
        for ( $attempt = 0; $attempt < 2; $attempt++ ) {
            $action = MainWP_Backup_Restore_Orchestrator::resolve_cancel_action( $before );
            if ( 'none' === $action ) {
                break;
            }
            try {
                MainWP_Backup_Restore_Orchestrator::instance()->cancel_job( $restore_job_id );
                $call = 'returned';
            } catch ( MainWP_Exception $e ) {
                if ( MainWP_Backup_Error_Codes::ERR_RESTORE_SCHEMA_REPAIR_PENDING === $e->get_message_error_code() ) {
                    return new \WP_Error( $e->get_message_error_code(), self::safe_error( $e->get_message_error_code() )['message'], array( 'status' => 409 ) );
                }
                // Another lane can finish after admission, so only the re-read job decides the result.
                $call = MainWP_Backup_Restore_Orchestrator::CANCEL_FINISHED_ON_SITE === $e->get_message_error_code() ? 'finished' : 'failed';
            }
            $before = MainWP_Backup_Restore_Job_Store::instance()->get_job( $restore_job_id );
            // A changed phase needs its own cancellation decision; stopping checks never cancels a Child task.
            if ( null === $before || 'stop_checking' !== $action || 'cancel' !== MainWP_Backup_Restore_Orchestrator::resolve_cancel_action( $before ) ) {
                break;
            }
        }
        $job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $restore_job_id );
        if ( null === $job || 'reserved' === $job->status ) {
            return self::not_found( MainWP_Backup_Error_Codes::ERR_RESTORE_JOB_NOT_FOUND );
        }
        $finished = self::finished_cancel_result( $job );
        $result   = $finished ?? 'not_cancellable';
        $action   = MainWP_Backup_Restore_Orchestrator::resolve_cancel_action( $job );
        if ( null === $finished && 'none' !== $action ) {
            $phase = 'failed_retryable' === $job->status ? (string) ( $job->checkpoint['resume_status'] ?? 'pending' ) : $job->status;
            if ( 'stop_checking' === $action ) {
                $result = 'not_cancellable';
            } elseif ( MainWP_Backup_Restore_Orchestrator::has_applicable_cancel_intent( $job ) || ( ! empty( $job->checkpoint['cancel_intent'] ) && in_array( $phase, array( 'pending', 'preflight', 'pre_snapshot' ), true ) ) ) {
                // An intent armed before staging survives until the worker applies it at staging.
                $result = 'cancel_pending';
            } elseif ( in_array( $call, array( 'returned', 'failed' ), true ) ) {
                // Worker progress cannot prove that a cancellation was recorded.
                return new \WP_Error( 'mainwp_operation_failed', self::safe_error( 'mainwp_operation_failed' )['message'], array( 'status' => 502 ) );
            }
        }
        return array(
            'restore_job_id' => $restore_job_id,
            'status'         => $job->status,
            'result'         => $result,
        );
    }

    /**
     * Confirm makes no call for a finished job, so its stored outcome is the result a preview can promise.
     *
     * @param object $job Restore job row.
     * @return string|null Result for a finished job, null while the job is active.
     */
    private static function finished_cancel_result( object $job ): ?string {
        if ( in_array( $job->status, MainWP_Backup_Restore_Job_Store::active_statuses(), true ) ) {
            return null;
        }
        $confirmation = $job->checkpoint['cancel_confirmation']['outcome'] ?? '';
        if ( 'failed_permanent' === $job->status && ! empty( $job->checkpoint['verify_stopped'] ) ) {
            return 'ended_failed';
        }
        if ( in_array( $job->status, array( 'cancelled', 'failed_permanent' ), true ) ) {
            if ( 'unconfirmed' === $confirmation ) {
                return 'cancel_unconfirmed';
            }
            if ( 'cancelled' === $job->status ) {
                return 'cancelled';
            }
            if ( 'confirmed' === $confirmation ) {
                return 'ended_failed';
            }
        }
        return 'not_cancellable';
    }

    /**
     * Preview the same descendants confirmation will compare under reservation.
     *
     * @param int       $site_id Site identity.
     * @param int       $snapshot_id Target identity.
     * @param bool|null $delete_cloud_copies Null uses the owning task's policy.
     * @param string    $context Calling transport.
     * @return array|\WP_Error Shared UI and ability preview.
     */
    public static function preview_delete_snapshot( int $site_id, int $snapshot_id, ?bool $delete_cloud_copies = null, string $context = 'ability' ) {
        $site = self::authorize_site( $site_id, 'execute_backups', $context );
        if ( is_wp_error( $site ) ) {
            return $site;
        }
        $snapshot = self::authorize_snapshot( $snapshot_id, $context );
        if ( is_wp_error( $snapshot ) ) {
            return $snapshot;
        }
        if ( (int) $snapshot->site_id !== $site_id ) {
            return self::not_found( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_NOT_FOUND );
        }
        $error = self::native_task_write_error();
        if ( $error ) {
            return $error;
        }
        $task  = MainWP_DB_Backup::instance()->get_backup_task_by_id( (int) $snapshot->task_id );
        $cloud = $delete_cloud_copies ?? ( ! $task || ! empty( $task->delete_cloud_copies ) );
        $ids   = MainWP_Backup_Retention_Query::instance()->get_snapshot_delete_ids( $snapshot_id );
        sort( $ids, SORT_NUMERIC );
        $refusals = array();
        if ( empty( $ids ) ) {
            $refusals[] = self::safe_error( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_NOT_FOUND );
        }
        $busy = self::snapshot_delete_busy_message( $site_id );
        if ( '' !== $busy ) {
            $refusal            = self::safe_error( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING );
            $refusal['message'] = $busy;
            $refusals[]         = $refusal;
        }
        $gate     = self::update_gate_for_site( $site );
        $warn     = $gate['applies'] && MainWP_Backup_Executor::UPDATE_GATE_PASS === $gate['verdict']
            && MainWP_Backup_Executor::UPDATE_GATE_PASS !== MainWP_Backup_Executor::evaluate_update_backup_gate( $site_id, MainWP_Updates_Overview::get_backup_before_upgrade_days(), null, $ids );
        $warnings = array();
        if ( $warn ) {
            $warnings[] = __( 'After this deletion, updates for this site are held until it has another backup that meets the "Require a backup before an update" setting.', 'mainwp' );
        }
        if ( $cloud ) {
            $warnings = array_merge( $warnings, self::remote_minimum_warnings( $ids ) );
        }
        if ( ! $cloud ) {
            $warnings[] = __( 'Cloud copies stay in your storage, but they will no longer appear in MainWP.', 'mainwp' );
        }
        $preview = self::backup_preview(
            array(
                'site_id'             => $site_id,
                'snapshot_id'         => $snapshot_id,
                'snapshot_ids'        => $ids,
                'delete_cloud_copies' => $cloud,
                'update_gate_warning' => $warn,
            ),
            $refusals,
            $warnings
        );
        return array(
            'dry_run'      => true,
            'would_affect' => $preview['would_affect'],
            'can_proceed'  => $preview['can_proceed'],
            'warnings'     => $preview['warnings'],
            'refusals'     => $preview['refusals'],
        );
    }

    /**
     * Warn before a confirmed deletion overrides a destination minimum.
     *
     * @param array $snapshot_ids Selected history.
     * @return array Warning strings.
     */
    private static function remote_minimum_warnings( array $snapshot_ids ): array {
        $protection = MainWP_Backup_Retention_Protection::remote_protection( $snapshot_ids, null, false );
        return ! empty( $protection['unknown_age'] ) || $protection['protected_until'] > time()
            ? array( __( 'Some copies are still inside their minimum retention period, or MainWP cannot confirm that it has expired. Deleting them overrides that protection.', 'mainwp' ) )
            : array();
    }

    /**
     * Delete only after the reserved chain still matches the user's selection.
     *
     * @param int       $site_id Site identity.
     * @param int       $snapshot_id Target identity.
     * @param array     $expected_snapshot_ids Exact ids shown in the preview.
     * @param bool|null $delete_cloud_copies Null uses the owning task's policy.
     * @param string    $context Calling transport.
     * @return array|\WP_Error Shared UI and ability result.
     */
    public static function delete_snapshot( int $site_id, int $snapshot_id, array $expected_snapshot_ids, ?bool $delete_cloud_copies = null, string $context = 'ability' ) {
        $valid = MainWP_Abilities_Backups::validate_id_input( array( 'expected_snapshot_ids' => $expected_snapshot_ids ), true );
        if ( is_wp_error( $valid ) || empty( $expected_snapshot_ids ) || count( array_unique( $expected_snapshot_ids ) ) !== count( $expected_snapshot_ids ) ) {
            return new \WP_Error( 'mainwp_invalid_input', self::safe_error( 'mainwp_invalid_input' )['message'], array( 'status' => 400 ) );
        }
        $preview = self::preview_delete_snapshot( $site_id, $snapshot_id, $delete_cloud_copies, $context );
        if ( is_wp_error( $preview ) ) {
            return $preview;
        }
        $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
        if ( ! $snapshot ) {
            return self::not_found( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_NOT_FOUND );
        }
        $task   = (object) array(
            'id'                  => (int) $snapshot->task_id,
            'delete_cloud_copies' => $preview['would_affect']['delete_cloud_copies'],
        );
        $result = MainWP_Backup_Retention_Executor::instance()->delete_snapshot( $site_id, $snapshot_id, array_map( 'intval', $expected_snapshot_ids ), $task );
        unset( $result['snapshot_ids'] );
        $result = array(
            'site_id'     => $site_id,
            'snapshot_id' => $snapshot_id,
        ) + $result;
        foreach ( $result['cloud_deletes_queued'] as &$pair ) {
            $destination              = MainWP_Backup_Storage_Destinations::instance()->get_destination( $pair['destination_id'] );
            $pair['destination_name'] = $destination ? (string) $destination->name : '';
        }
        unset( $pair );
        $result['errors'] = array_map( array( MainWP_Abilities_Backups::class, 'cleanup_error' ), $result['errors'] );
        $fresh_preview    = 'changed' === $result['status'] ? self::preview_delete_snapshot( $site_id, $snapshot_id, $task->delete_cloud_copies, $context ) : null;
        if ( is_wp_error( $fresh_preview ) ) {
            $result['errors'][] = MainWP_Abilities_Backups::cleanup_error( array( 'code' => $fresh_preview->get_error_code() ) );
            $fresh_preview      = null;
        }
        $result['preview'] = $fresh_preview;
        $result['dry_run'] = false;
        return $result;
    }

    /**
     * The row and both write transports share the running-site refusal.
     *
     * @param int $site_id Site identity.
     * @return bool Whether deletion must wait.
     */
    public static function snapshot_delete_busy( int $site_id ): bool {
        return '' !== self::snapshot_delete_busy_message( $site_id );
    }

    /**
     * Preview and confirm must give the same reason for a held site.
     *
     * @param bool $restore_or_reserved Whether a restore or site reservation holds the site.
     * @return string Translated refusal.
     */
    public static function snapshot_delete_hold_message( bool $restore_or_reserved ): string {
        return $restore_or_reserved
            ? __( 'A restore or another backup action is running on this site. Try again when it finishes.', 'mainwp' )
            : __( 'A backup is running on this site. Try again when it finishes.', 'mainwp' );
    }

    /**
     * Reader locks can keep a reservation alive beyond its timestamp.
     *
     * @param int $site_id Site identity.
     * @return string Refusal, or an empty string when idle.
     */
    private static function snapshot_delete_busy_message( int $site_id ): string {
        global $wpdb;
        $restores = MainWP_Backup_Restore_Job_Store::instance();
        if ( null !== $restores->get_active_job_for_site( $site_id ) || $restores->site_is_reserved( $site_id ) || '' !== $wpdb->last_error ) {
            return self::snapshot_delete_hold_message( true );
        }
        return MainWP_Backup_Retention_Query::instance()->has_running_backup_for_site( $site_id ) ? self::snapshot_delete_hold_message( false ) : '';
    }

    /**
     * The consent preview must use the same catalog admission as a restore.
     *
     * @param int   $site_id Target site identity.
     * @param int   $snapshot_id Snapshot identity.
     * @param array $options Safety backup and order preservation choices.
     * @return array|\WP_Error
     */
    public static function preview_restore_snapshot( int $site_id, int $snapshot_id, array $options ) {
        $site = self::authorize_site( $site_id, 'execute_backups' );
        if ( is_wp_error( $site ) ) {
            return $site;
        }
        $restore = self::authorize_base( 'restore_backups' );
        if ( is_wp_error( $restore ) ) {
            return $restore;
        }
        $snapshot = self::authorize_snapshot( $snapshot_id );
        if ( is_wp_error( $snapshot ) ) {
            return $snapshot;
        }
        if ( (int) $snapshot->site_id !== $site_id ) {
            return self::not_found( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_NOT_FOUND );
        }
        $error = self::native_task_write_error();
        if ( $error ) {
            return $error;
        }
        $resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $snapshot_id, $site_id );
        $details  = MainWP_Backup_Read_Model::snapshot_details( $snapshot_id );
        if ( is_wp_error( $details ) ) {
            return $details;
        }
        $type     = $resolved['ok'] ? $resolved['sub_type'] : ( 'incremental' === $snapshot->type ? 'full' : $snapshot->type );
        $refusals = self::restore_preview_refusals( $site );
        $reason   = $resolved['ok'] ? null : self::safe_error( $resolved['code'] );
        if ( null !== $reason ) {
            $refusals[] = $reason;
        }
        $age_days = max( 0, (int) floor( ( time() - (int) $snapshot->created ) / DAY_IN_SECONDS ) );
        $woo      = MainWP_Utility::site_has_woocommerce( $site_id );
        $warnings = array(
            /* translators: %d: age of the snapshot in days. */
            sprintf( __( 'The snapshot is %d days old.', 'mainwp' ), $age_days ),
            __( 'Site capabilities and WooCommerce order preservation are checked when you confirm.', 'mainwp' ),
        );
        if ( 'files' !== $type ) {
            $warnings[] = __( 'This restore replaces the database. Content changed since the snapshot will be lost.', 'mainwp' );
            if ( $woo ) {
                $warnings[] = __( 'WooCommerce orders changed since the snapshot can be lost. Order preservation requires support from the site.', 'mainwp' );
            }
        }
        return self::backup_preview(
            array(
                'site_id'              => $site_id,
                'snapshot'             => $details['snapshot'],
                'restorable'           => $resolved['ok'],
                'restore_reason'       => $reason,
                'restore_type'         => $type,
                'replaces_database'    => 'files' !== $type,
                'chain_ids'            => $details['chain_ids'],
                'site_has_woocommerce' => $woo,
                'safety_backup'        => array(
                    'will_take' => $options['pre_snapshot'] ?? true,
                    'type'      => 'full',
                ),
                'preserve_orders'      => array(
                    'requested'  => ! empty( $options['preserve_orders'] ),
                    'checked_at' => 'confirm',
                ),
            ),
            $refusals,
            $warnings
        );
    }

    /**
     * Local evidence can refuse admission without contacting the Child.
     *
     * @param object $site Authorized site.
     * @return array
     */
    private static function restore_preview_refusals( object $site ): array {
        $refusals = array();
        if ( MainWP_Backup_Restore_Orchestrator::site_is_disconnected( $site ) ) {
            $refusals[] = self::safe_error( MainWP_Backup_Error_Codes::ERR_SITE_DISCONNECTED );
        }
        $task = MainWP_DB_Backup::instance()->get_manual_backup_task( (int) $site->id );
        if ( MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( (int) $site->id ) || ( $task && (int) $task->completed < (int) $task->last_run ) ) {
            $refusals[] = self::safe_error( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING );
        }
        return $refusals;
    }

    /**
     * A pending stop does not prove that the Child has stopped applying the restore.
     *
     * @param int $restore_job_id Restore identity.
     * @return array|\WP_Error
     */
    public static function preview_restore_cancel( int $restore_job_id ) {
        $job = self::authorize_restore_job( $restore_job_id, true );
        if ( is_wp_error( $job ) ) {
            return $job;
        }
        $action      = MainWP_Backup_Restore_Orchestrator::resolve_cancel_action( $job );
        $phase       = 'failed_retryable' === $job->status ? ( $job->checkpoint['resume_status'] ?? 'pending' ) : $job->status;
        $cancellable = 'none' !== $action;
        $asks_site   = ! empty( $job->child_task_id ) && in_array( $phase, array( 'staging', 'transferring', 'running' ), true );
        $finished    = self::finished_cancel_result( $job );
        if ( 'stop_checking' === $action ) {
            $warning = __( 'Confirming stops the checks and ends this job as failed. Rollback stays available where a safety backup exists.', 'mainwp' );
        } elseif ( 'ended_failed' === $finished && ! empty( $job->checkpoint['verify_stopped'] ) ) {
            $warning = __( 'Checks were already stopped and this job ended as failed. Confirming changes nothing and reports ended_failed.', 'mainwp' );
        } elseif ( 'cancelled' === $finished ) {
            $warning = __( 'This job is already cancelled. Confirming changes nothing and reports cancelled.', 'mainwp' );
        } elseif ( 'cancel_unconfirmed' === $finished ) {
            $warning = __( 'This job already ended without the site confirming that it stopped. Confirming changes nothing and reports cancel_unconfirmed.', 'mainwp' );
        } elseif ( 'ended_failed' === $finished ) {
            $warning = __( 'This job already failed and the site confirmed that it stopped. Confirming changes nothing and reports ended_failed.', 'mainwp' );
        } elseif ( null !== $finished ) {
            $warning = __( 'This job has already finished. Confirming changes nothing and reports not_cancellable.', 'mainwp' );
        } elseif ( ! $cancellable ) {
            $warning = __( 'The restore has reached verification, so the job can no longer be cancelled. Confirming reports not_cancellable.', 'mainwp' );
        } elseif ( $asks_site ) {
            $warning = __( 'The site is asked to stop. Confirmation can take up to 15 minutes; poll get-restore-job-v1 until the result is final.', 'mainwp' );
        } else {
            $warning = __( 'The site is not applying the restore yet, so a cancel normally stops the job at once. If the stop cannot be recorded right away, it is applied when the job reaches staging.', 'mainwp' );
        }
        return self::backup_preview(
            array(
                'restore_job_id'     => $restore_job_id,
                'status'             => $job->status,
                'cancellable'        => $cancellable,
                'site_will_be_asked' => $asks_site,
            ),
            array(),
            array( $warning )
        );
    }

    /**
     * Safety backup retention and source status determine whether rollback can start.
     *
     * @param int $restore_job_id Source restore identity.
     * @return array|\WP_Error
     */
    public static function preview_restore_rollback( int $restore_job_id ) {
        $job = self::authorize_restore_job( $restore_job_id, true );
        if ( is_wp_error( $job ) ) {
            return $job;
        }
        $error = self::native_task_write_error();
        if ( $error ) {
            return $error;
        }
        $site = self::authorize_site( (int) $job->site_id, 'execute_backups' );
        if ( is_wp_error( $site ) ) {
            return $site;
        }
        $available = in_array( $job->status, array( 'completed', 'failed_permanent', 'cancelled' ), true ) && MainWP_Backup_Restore_Orchestrator::instance()->has_rollback_source( $job );
        $refusals  = self::restore_preview_refusals( $site );
        if ( ! $available ) {
            $refusals[] = self::safe_error( 'mainwp_restore_rollback_status' );
        }
        return self::backup_preview(
            array(
                'source_restore_job_id' => $restore_job_id,
                'site_id'               => (int) $job->site_id,
                'rollback_available'    => $available,
                'source_status'         => $job->status,
                'restore_type'          => 'full',
            ),
            $refusals
        );
    }

    /**
     * The existing retention pass owns guarded deletion.
     *
     * @param int      $site_id Website identity.
     * @param int|null $keep Optional keep-count override.
     * @param bool     $dry_run Whether to preview deletion.
     * @param string   $context Calling transport.
     * @return array|\WP_Error
     */
    public static function cleanup_site_backups( int $site_id, ?int $keep, bool $dry_run = false, string $context = 'ability' ) {
        $site = self::authorize_site( $site_id, 'execute_backups', $context );
        if ( is_wp_error( $site ) ) {
            return $site;
        }
        // A preview must not fall through to the existing destructive pass.
        if ( $dry_run ) {
            return MainWP_Backup_Retention_Executor::instance()->plan_site_cleanup( $site_id, $keep, 6 * HOUR_IN_SECONDS );
        }
        return MainWP_Backup_Retention_Executor::instance()->cleanup_site_backups( $site_id, $keep, 6 * HOUR_IN_SECONDS, true );
    }
    /**
     * Fleet status must use the same applicability rule as the enforcing update lane.
     *
     * @param object $site Stored site row.
     * @return array Applicability and evidence verdict.
     */
    public static function update_gate_for_site( object $site ): array {
        $applies = MainWP_Backup_Settings::is_native_backups_enabled()
            && 1 === (int) get_option( 'mainwp_backup_before_upgrade', 0 )
            && 0 !== (int) $site->backup_before_upgrade;
        return array(
            'applies' => $applies,
            'verdict' => $applies ? MainWP_Backup_Executor::evaluate_update_backup_gate( (int) $site->id, MainWP_Updates_Overview::get_backup_before_upgrade_days() ) : 'not_applicable',
        );
    }

    /**
     * A preview must not create the hidden task or contact the site.
     *
     * @param int $site_id Site identity.
     * @return array|\WP_Error
     */
    public static function preview_site_backup( int $site_id ) {
        $site = self::authorize_site( $site_id, 'execute_backups' );
        if ( is_wp_error( $site ) ) {
            return $site;
        }
        $error = self::native_task_write_error();
        if ( $error ) {
            return $error;
        }
        $warnings   = array( __( 'Site capabilities and available run slots are checked when you confirm.', 'mainwp' ) );
        $db         = MainWP_DB_Backup::instance();
        $task       = $db->get_manual_backup_task( $site_id );
        $keep       = MainWP_Backup_Settings::get_on_demand_retention_count();
        $candidates = $task ? self::retention_after_backup( $site_id, (int) $task->id, new MainWP_Backup_Retention_Policy( $keep, 0, 0 ), $warnings ) : array();
        $ids        = array();
        foreach ( $candidates as $candidate ) {
            $ids = array_merge( $ids, $candidate['snapshot_ids'] );
        }
        $refusals = array();
        if ( MainWP_Post_Backup_Handler::site_is_disconnected( $site ) ) {
            $refusals[] = self::safe_error( MainWP_Backup_Error_Codes::ERR_SITE_DISCONNECTED );
        } elseif ( MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $site_id ) ) {
            $refusals[] = self::safe_error( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING );
        }
        return self::backup_preview(
            array(
                'site_id'                  => $site_id,
                'run_open'                 => $task && (int) $task->completed < (int) $task->last_run,
                'destinations'             => self::preview_destinations( $db->manual_backup_task_destinations( $site_id ) ),
                'retention_keep'           => $keep,
                'would_prune_snapshot_ids' => $ids,
            ),
            $refusals,
            $warnings
        );
    }

    /**
     * Stored scope and retention can be inspected without claiming a run.
     *
     * @param int $task_id Task identity.
     * @return array|\WP_Error
     */
    public static function preview_task_run( int $task_id ) {
        $task = self::authorize_task( $task_id, 'run_backup_tasks' );
        if ( is_wp_error( $task ) ) {
            return $task;
        }
        $error = self::native_task_write_error();
        if ( $error ) {
            return $error;
        }
        $warnings = array(
            __( 'Site capabilities and available run slots are checked when you confirm.', 'mainwp' ),
            __( 'Retention estimates use the stored snapshots; the size of the new backup is not known yet.', 'mainwp' ),
        );
        $stale    = array();
        $sites    = MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task, $stale );
        $code     = '';
        $message  = MainWP_Manage_Backups_Handler::manual_task_start_error( $task, $stale, $code );
        $impact   = array();
        $policy   = new MainWP_Backup_Retention_Policy( (int) $task->retention_count, (int) $task->retention_days, (int) $task->retention_storage_mb );
        foreach ( $sites as $id ) {
            $ids     = array();
            $reasons = array();
            foreach ( self::retention_after_backup( $id, $task_id, $policy, $warnings ) as $candidate ) {
                $ids       = array_merge( $ids, $candidate['snapshot_ids'] );
                $reasons[] = $candidate['reason'];
            }
            $impact[] = array(
                'site_id'                  => $id,
                'would_prune_snapshot_ids' => $ids,
                'reasons'                  => array_values( array_unique( $reasons ) ),
            );
        }
        if ( 'incremental' === $task->type ) {
            $warnings[] = __( 'Retention estimates assume a new complete backup unit. An incremental run may extend an existing chain instead.', 'mainwp' );
        }
        return self::backup_preview(
            array(
                'task_id'          => $task_id,
                'run_open'         => (int) $task->completed < (int) $task->last_run,
                'site_ids'         => $sites,
                'stale_site_ids'   => $stale,
                'destinations'     => self::preview_destinations( (array) json_decode( (string) $task->destinations, true ) ),
                'retention_impact' => $impact,
            ),
            '' === $message ? array() : array( self::safe_error( $code ) ),
            $warnings
        );
    }

    /**
     * The future backup counts toward retention without inventing an archive size or id.
     *
     * @param int                            $site_id Site identity.
     * @param int                            $task_id Task identity.
     * @param MainWP_Backup_Retention_Policy $policy Stored policy.
     * @param array                          $warnings Receives explanations of retention holds.
     * @return array
     */
    private static function retention_after_backup( int $site_id, int $task_id, MainWP_Backup_Retention_Policy $policy, array &$warnings ): array {
        $units    = MainWP_Backup_Retention_Query::instance()->get_units_for_site_task( $site_id, $task_id );
        $warnings = array_values( array_unique( array_merge( $warnings, self::retention_hold_warnings( $units ) ) ) );
        $now      = time();
        $newest   = $now;
        foreach ( $units as $unit ) {
            $newest = max( $newest, (int) $unit['newest_created'] + 1 );
        }
        $units[] = array(
            'snapshots'      => array(),
            'is_complete'    => true,
            'is_restorable'  => true,
            'size_bytes'     => 0,
            'newest_created' => $newest,
            'oldest_created' => $newest,
        );
        return $policy->evaluate( $units, $now );
    }

    /**
     * Explain why automatic retention may exceed configured limits.
     *
     * @param array $units Decorated retention units.
     * @return array Warning strings.
     */
    private static function retention_hold_warnings( array $units ): array {
        foreach ( $units as $unit ) {
            if ( ! empty( $unit['unknown_age'] ) || ! empty( $unit['required_upload_hold'] ) || ( $unit['protected_until'] ?? 0 ) > time() ) {
                return array( __( 'Automatic retention keeps backups with protected copies or unfinished required uploads. Count or storage limits may be exceeded until those holds clear.', 'mainwp' ) );
            }
        }
        return array();
    }

    /**
     * Destination previews exclude credentials and storage paths.
     *
     * @param array $ids Stored destination identities.
     * @return array
     */
    private static function preview_destinations( array $ids ): array {
        $destinations = array();
        foreach ( $ids as $id ) {
            $destination = MainWP_Backup_Storage_Destinations::instance()->get_destination( (int) $id );
            if ( $destination ) {
                $destinations[] = array(
                    'id'       => (int) $destination->id,
                    'name'     => (string) $destination->name,
                    'provider' => (string) $destination->provider,
                );
            }
        }
        return $destinations;
    }

    /**
     * Cancellation eligibility can be read without sending a stop request.
     *
     * @param int $site_id Site identity.
     * @return array|\WP_Error
     */
    public static function preview_site_cancel( int $site_id ) {
        $site = self::authorize_site( $site_id, 'execute_backups' );
        if ( is_wp_error( $site ) ) {
            return $site;
        }
        $db       = MainWP_DB_Backup::instance();
        $task     = $db->get_manual_backup_task( $site_id );
        $progress = $task ? $db->get_backup_task_progress( (int) $task->id, $site_id ) : null;
        $id       = (string) ( $progress->child_backup_task_id ?? '' );
        return self::backup_preview(
            array(
                'site_id'          => $site_id,
                'run_open'         => $task && (int) $task->completed < (int) $task->last_run,
                'child_task_known' => '' !== $id && ! MainWP_Backup_Executor::is_creating_marker( $id ),
            )
        );
    }

    /**
     * Reset eligibility must use the same stall threshold as confirmation.
     *
     * @param int $task_id Task identity.
     * @return array|\WP_Error
     */
    public static function preview_task_reset( int $task_id ) {
        $task = self::authorize_task( $task_id, 'pause_resume_backup_tasks' );
        if ( is_wp_error( $task ) ) {
            return $task;
        }
        $open    = (int) $task->completed < (int) $task->last_run;
        $elapsed = $open ? max( 0, time() - (int) $task->last_run ) : 0;
        return self::backup_preview(
            array(
                'task_id'                 => $task_id,
                'run_open'                => $open,
                'started_at'              => (int) $task->last_run,
                'stalled_for_seconds'     => $elapsed,
                'stall_threshold_seconds' => MainWP_Backup_Executor::STALL_THRESHOLD_SECONDS,
                'site_ids'                => MainWP_Manage_Backups_Handler::resolve_reset_task_site_ids( $task ),
            ),
            $open && $elapsed > MainWP_Backup_Executor::STALL_THRESHOLD_SECONDS ? array() : array( self::safe_error( MainWP_Backup_Error_Codes::ERR_NOT_STALLED ) )
        );
    }

    /**
     * Internal cleanup paths are replaced by counts before a preview reaches callers.
     *
     * @param int      $site_id Site identity.
     * @param int|null $keep Retention override.
     * @param string   $context Calling transport.
     * @return array|\WP_Error
     */
    public static function preview_site_cleanup( int $site_id, ?int $keep, string $context = 'ability' ) {
        $site = self::authorize_site( $site_id, 'execute_backups', $context );
        if ( is_wp_error( $site ) ) {
            return $site;
        }
        $error = self::native_task_write_error();
        if ( $error ) {
            return $error;
        }
        $task         = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        $keep         = $keep ?? ( $task ? MainWP_Backup_Settings::task_retention_count( $task ) : MainWP_Backup_Settings::get_on_demand_retention_count() );
        $plan         = MainWP_Backup_Retention_Executor::instance()->plan_site_cleanup( $site_id, $keep, 6 * HOUR_IN_SECONDS );
        $snapshot_ids = $plan['orphaned_task_snapshot_ids'];
        foreach ( $plan['on_demand_units'] as $unit ) {
            $snapshot_ids = array_merge( $snapshot_ids, $unit['snapshot_ids'] );
        }
        return self::backup_preview(
            array(
                'site_id'                    => $site_id,
                'keep'                       => $keep,
                'orphan_dirs'                => count( $plan['orphan_dirs'] ),
                'orphaned_task_snapshot_ids' => $plan['orphaned_task_snapshot_ids'],
                'on_demand_units'            => $plan['on_demand_units'],
                'bytes_to_prune'             => $plan['bytes'],
            ),
            array(),
            self::remote_minimum_warnings( $snapshot_ids )
        );
    }

    /**
     * A create preview needs the same defaults and scope checks as a saved task.
     *
     * @param array $fields Submitted task fields.
     * @return array|\WP_Error
     */
    public static function preview_task_create( array $fields ) {
        $base = self::authorize_base( 'add_backup_tasks' );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        $error = self::native_task_write_error();
        if ( $error ) {
            return $error;
        }
        $values = self::prepare_task_fields( $fields, null, 'ability', 'add_backup_tasks' );
        if ( is_wp_error( $values ) ) {
            return $values;
        }
        return self::backup_preview(
            array(
                'normalized'        => $values,
                'resolved_site_ids' => MainWP_Manage_Backups_Handler::expand_task_site_ids( implode( ',', $values['site_ids'] ), implode( ',', $values['group_ids'] ) ),
                'next_run_at'       => self::preview_task_next_run( $values ),
            )
        );
    }

    /**
     * Retention is evaluated without pruning so a caller can review a partial edit.
     *
     * @param int   $task_id Task identity.
     * @param array $fields Partial task fields.
     * @return array|\WP_Error
     */
    public static function preview_task_update( int $task_id, array $fields ) {
        $task = self::authorize_task( $task_id, 'edit_backup_tasks' );
        if ( is_wp_error( $task ) ) {
            return $task;
        }
        $error = self::native_task_write_error();
        if ( $error ) {
            return $error;
        }
        $values = self::prepare_task_fields( $fields, $task, 'ability', 'edit_backup_tasks' );
        if ( is_wp_error( $values ) ) {
            return $values;
        }
        // The stored row came from the form, which never had the machine-input ceilings.
        $before = self::normalize_task_fields( array( 'name' => html_entity_decode( $task->name, ENT_QUOTES ) ), $task, 'ui' );
        // A repair of an invalid legacy value must still be reviewable.
        $before   = is_wp_error( $before ) ? self::stored_task_fields( $task ) : $before;
        $query    = MainWP_Backup_Retention_Query::instance();
        $policy   = new MainWP_Backup_Retention_Policy( $values['retention']['count'], $values['retention']['days'], $values['retention']['storage_mb'] );
        $impact   = array();
        $warnings = array();
        foreach ( $query->list_site_ids_for_task( $task_id ) as $site_id ) {
            $ids     = array();
            $reasons = array();
            $units   = $query->get_units_for_site_task( $site_id, $task_id );
            foreach ( $units as &$unit ) {
                $unit['required_upload_hold'] = $values['retention']['remove_local_after_upload']
                    && $unit['required_uploads_pending'];
            }
            unset( $unit );
            $warnings = array_values( array_unique( array_merge( $warnings, self::retention_hold_warnings( $units ) ) ) );
            foreach ( $policy->evaluate( $units, time() ) as $candidate ) {
                $ids       = array_merge( $ids, $candidate['snapshot_ids'] );
                $reasons[] = $candidate['reason'];
            }
            $impact[] = array(
                'site_id'                  => $site_id,
                'would_prune_snapshot_ids' => $ids,
                'reasons'                  => array_values( array_unique( $reasons ) ),
            );
        }
        return self::backup_preview(
            array(
                'task_id'                  => $task_id,
                'changes'                  => self::preview_task_changes( $before, $values ),
                'retention_impact'         => $impact,
                'next_run_at'              => self::preview_task_next_run( $values, $task ),
                'owner_changes_to_user_id' => get_current_user_id() === (int) $task->userid ? null : get_current_user_id(),
            ),
            array(),
            $warnings
        );
    }

    /**
     * Scalar policy changes keep the diff usable without exposing stored row members.
     *
     * @param array $before Original normalized fields.
     * @param array $after Proposed normalized fields.
     * @return array
     */
    private static function preview_task_changes( array $before, array $after ): array {
        $changes = array();
        foreach ( $after as $key => $value ) {
            $old = $before[ $key ] ?? null;
            if ( in_array( $key, array( 'retention', 'exclude_flags', 'archive_tuning' ), true ) ) {
                foreach ( $value as $member => $to ) {
                    $from = $old[ $member ] ?? null;
                    if ( $from !== $to ) {
                        $changes[] = array(
                            'field' => $key . '.' . $member,
                            'from'  => $from,
                            'to'    => $to,
                        );
                    }
                }
            } elseif ( $old !== $value ) {
                $changes[] = array(
                    'field' => $key,
                    'from'  => $old,
                    'to'    => $value,
                );
            }
        }
        return $changes;
    }

    /**
     * The calculator can project a schedule without invoking the database saver.
     *
     * @param array       $values Normalized fields.
     * @param object|null $task Existing task, if any.
     * @return int|null
     */
    private static function preview_task_next_run( array $values, ?object $task = null ): ?int {
        if ( null !== $task && ! empty( $task->paused ) ) {
            return (int) $task->next_run > 0 ? (int) $task->next_run : null;
        }
        $calculator = new MainWP_Backup_Schedule_Calculator( wp_timezone(), time() );
        $next       = $calculator->compute_next_run( (object) $values, empty( $task->last_run ) ? null : (int) $task->last_run );
        return $next > 0 ? $next : null;
    }

    /**
     * Historical sites need authorization before a deletion preview exposes their snapshots.
     *
     * @param int    $task_id Task identity.
     * @param bool   $delete_snapshots Whether snapshots would be purged.
     * @param string $context Calling transport.
     * @return array|\WP_Error
     */
    public static function preview_task_delete( int $task_id, bool $delete_snapshots = true, string $context = 'ability' ) {
        $task = self::authorize_task( $task_id, 'delete_backup_tasks', $context );
        if ( is_wp_error( $task ) ) {
            return $task;
        }
        $error = self::native_task_write_error();
        if ( $error ) {
            return $error;
        }
        $history = self::authorize_task_history_sites( $task_id, 'delete_backup_tasks', $context );
        if ( is_wp_error( $history ) ) {
            return $history;
        }
        $query     = MainWP_Backup_Retention_Query::instance();
        $snapshots = array();
        $cloud     = array();
        foreach ( $query->list_site_ids_for_task( $task_id ) as $site_id ) {
            if ( ! $delete_snapshots ) {
                continue;
            }
            foreach ( $query->get_units_for_site_task( $site_id, $task_id ) as $unit ) {
                foreach ( $unit['snapshots'] as $snapshot ) {
                    $snapshots[] = array(
                        'id'         => (int) $snapshot->id,
                        'site_id'    => $site_id,
                        'type'       => $snapshot->type,
                        'created_at' => (int) $snapshot->created,
                    );
                    if ( empty( $task->delete_cloud_copies ) ) {
                        continue;
                    }
                    foreach ( MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( (int) $snapshot->id ) as $job ) {
                        $destination = MainWP_Backup_Storage_Destinations::instance()->get_destination( (int) $job->destination_id );
                        $cloud[]     = array(
                            'snapshot_id'      => (int) $snapshot->id,
                            'destination_id'   => (int) $job->destination_id,
                            'destination_name' => $destination ? $destination->name : null,
                        );
                    }
                }
            }
        }
        $warnings = ! empty( $task->delete_cloud_copies ) ? self::remote_minimum_warnings( array_column( $snapshots, 'id' ) ) : array();
        if ( count( $snapshots ) > 500 || count( $cloud ) > 500 ) {
            $warnings[] = __( 'The preview lists up to 500 snapshots and 500 cloud copies. Confirmation deletes the full selection.', 'mainwp' );
        }
        $refusals = (int) $task->completed < (int) $task->last_run ? array( self::safe_error( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING ) ) : array();
        return self::backup_preview(
            array(
                'task_id'             => $task_id,
                'task_name'           => html_entity_decode( $task->name, ENT_QUOTES ),
                'delete_snapshots'    => $delete_snapshots,
                'delete_cloud_copies' => $delete_snapshots && ! empty( $task->delete_cloud_copies ),
                'snapshots'           => array_slice( $snapshots, 0, 500 ),
                'snapshots_truncated' => count( $snapshots ) > 500,
                'cloud_copies'        => array_slice( $cloud, 0, 500 ),
            ),
            $refusals,
            $warnings
        );
    }

    /**
     * Resuming recomputes the stored slot the same way the resume action does.
     *
     * @param int  $task_id Task identity.
     * @param bool $paused Whether the action would pause the task.
     * @return array|\WP_Error
     */
    public static function preview_task_pause( int $task_id, bool $paused ) {
        $task = self::authorize_task( $task_id, 'pause_resume_backup_tasks' );
        if ( is_wp_error( $task ) ) {
            return $task;
        }
        $error = $paused ? null : self::native_task_write_error();
        if ( $error ) {
            return $error;
        }
        $next = null;
        if ( ! $paused ) {
            $calculator = new MainWP_Backup_Schedule_Calculator( wp_timezone(), time() );
            $next       = $calculator->compute_next_run( $task, empty( $task->last_run ) ? null : (int) $task->last_run );
        }
        return self::backup_preview(
            array(
                'task_id'     => $task_id,
                'paused_now'  => ! empty( $task->paused ),
                'next_run_at' => $next,
            )
        );
    }

    /**
     * A refused preview still shows the authorized target and the reason it cannot proceed.
     *
     * @param array $effect Authorized changes.
     * @param array $refusals Safe refusal messages.
     * @param array $warnings Fixed preview caveats.
     * @return array
     */
    private static function backup_preview( array $effect, array $refusals = array(), array $warnings = array() ): array {
        return array(
            'would_affect' => $effect,
            'can_proceed'  => empty( $refusals ),
            'refusals'     => $refusals,
            'warnings'     => $warnings,
        );
    }
}
