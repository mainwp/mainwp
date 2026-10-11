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
    private static function authorize_base( string $right ) {
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
     * @return object|\WP_Error
     */
    public static function authorize_site( int $site_id, string $right ) {
        $base = self::authorize_base( $right );
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
     * @return object|\WP_Error
     */
    public static function authorize_task( int $task_id, string $right ) {
        $base = self::authorize_base( $right );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
        if ( ! $task || ! empty( $task->template ) || ( ! self::userless_cli() && ! MainWP_Manage_Backups_Handler::can_edit_backuptask( $task ) ) ) {
            return self::not_found( MainWP_Backup_Error_Codes::ERR_TASK_NOT_FOUND );
        }
        // Only the run set is checked: no task action touches a deleted site
        // or a group member added after the authorized snapshot.
        foreach ( MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task ) as $site_id ) {
            if ( is_wp_error( self::authorize_site( $site_id, $right ) ) ) {
                return self::not_found( MainWP_Backup_Error_Codes::ERR_TASK_NOT_FOUND );
            }
        }
        return $task;
    }

    /**
     * Snapshot access follows the owning site.
     *
     * @param int $snapshot_id Snapshot identity.
     * @return object|\WP_Error
     */
    public static function authorize_snapshot( int $snapshot_id ) {
        $base = self::authorize_base( 'execute_backups' );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
        return ! $snapshot || is_wp_error( self::authorize_site( (int) $snapshot->site_id, 'execute_backups' ) ) ? self::not_found( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_NOT_FOUND ) : $snapshot;
    }

    /**
     * Reservations are internal until a restore is admitted.
     *
     * @param int $restore_job_id Restore job identity.
     * @return object|\WP_Error
     */
    public static function authorize_restore_job( int $restore_job_id ) {
        $base = self::authorize_base( 'execute_backups' );
        if ( is_wp_error( $base ) ) {
            return $base;
        }
        $job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $restore_job_id );
        return ! $job || 'reserved' === $job->status || is_wp_error( self::authorize_site( (int) $job->site_id, 'execute_backups' ) ) ? self::not_found( MainWP_Backup_Error_Codes::ERR_RESTORE_JOB_NOT_FOUND ) : $job;
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
                'count'               => $task ? (int) $task->retention_count : MainWP_Manage_Backups_Handler::NEW_TASK_RETENTION_COUNT,
                'days'                => $task ? (int) $task->retention_days : 0,
                'storage_mb'          => $task ? (int) $task->retention_storage_mb : 0,
                'delete_cloud_copies' => ! empty( $task->delete_cloud_copies ),
                'max_chain_length'    => $task ? (int) $task->max_chain_length : 0,
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
        return MainWP_Backup_Settings::is_native_backups_enabled() ? null : new \WP_Error( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED, esc_html__( 'Native backups are not enabled.', 'mainwp' ), array( 'status' => 409 ) );
    }

    /**
     * Stored selections must not widen the scope checked at save time.
     *
     * @param array  $values Validated selections.
     * @param string $right Task operation right.
     * @return true|\WP_Error
     */
    private static function authorize_task_input_scope( array $values, string $right ) {
        $sites  = implode( ',', $values['site_ids'] );
        $groups = implode( ',', $values['group_ids'] );
        foreach ( MainWP_Manage_Backups_Handler::expand_task_site_ids( $sites, $groups ) as $id ) {
            $site = self::authorize_site( $id, $right );
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
        $base = self::authorize_base( 'add_backup_tasks' );
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
        $task = self::authorize_task( $task_id, 'edit_backup_tasks' );
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
        $scope = self::authorize_task_input_scope( $values, $right );
        if ( is_wp_error( $scope ) ) {
            return $scope;
        }
        // Both current scopes must be reachable before saving a partial edit.
        $scope_error = $task ? MainWP_Manage_Backups_Handler::authorize_task_update_scope( implode( ',', MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task ) ), '', implode( ',', $values['site_ids'] ), implode( ',', $values['group_ids'] ) ) : '';
        if ( '' !== $scope_error ) {
            return self::not_found( MainWP_Backup_Error_Codes::ERR_TASK_NOT_FOUND );
        }
        if ( null !== $task ) {
            $history = self::authorize_task_history_sites( (int) $task->id );
            if ( is_wp_error( $history ) ) {
                return $history;
            }
        }
        return $values;
    }

    /**
     * Retention changes can prune sites that are no longer in the task's current scope.
     *
     * @param int $task_id Task identity.
     * @return true|\WP_Error
     */
    private static function authorize_task_history_sites( int $task_id ) {
        foreach ( MainWP_Backup_Retention_Query::instance()->list_site_ids_for_task( $task_id ) as $site_id ) {
            // A scoped lookup would mistake a hidden site for a deleted one.
            if ( empty( MainWP_DB::instance()->get_website_by_id_unscoped( $site_id ) ) ) {
                continue;
            }
            if ( is_wp_error( self::authorize_site( $site_id, 'edit_backup_tasks' ) ) ) {
                return self::not_found( MainWP_Backup_Error_Codes::ERR_TASK_NOT_FOUND );
            }
        }
        return true;
    }

    /**
     * The schedule and retention savers use the same values as the normalized result.
     *
     * @param array       $values Normalized task fields.
     * @param array       $fields Submitted legacy filename fields.
     * @param object|null $task Existing task, or null for create.
     * @return array|\WP_Error
     */
    private static function save_task( array $values, array $fields, ?object $task = null ) {
        $db        = MainWP_DB_Backup::instance();
        $sites     = implode( ',', $values['site_ids'] );
        $groups    = implode( ',', $values['group_ids'] );
        $exclude   = MainWP_Backup_Settings::sanitize_exclude_list( array_map( 'htmlentities', $values['exclude'] ) );
        $flags     = $values['exclude_flags'];
        $tuning    = $values['archive_tuning'];
        $subfolder = sanitize_text_field( $fields['subfolder'] ?? $task->subfolder ?? '' );
        $filename  = sanitize_text_field( $fields['filename'] ?? $task->filename ?? '' );
        if ( $task ) {
            do_action( 'mainwp_update_backuptask', $task->id );
            $saved = $db->update_backup_task( $task->id, get_current_user_id(), htmlentities( $values['name'] ), $values['schedule'], $values['type'], $exclude, $sites, $groups, $subfolder, $filename, (int) $flags['backup_archives'], (int) $flags['cache'], (int) $flags['non_wp_folders'], (int) $flags['zip_archives'], $values['archive_format'], $tuning['max_file_descriptors_override'], $tuning['max_file_descriptors_auto'], $tuning['max_file_descriptors'], (int) $tuning['load_files_before_zip'], (int) $tuning['zip_force_override'] );
        } else {
            $saved = $db->add_backup_task( get_current_user_id(), htmlentities( $values['name'] ), $values['schedule'], $values['type'], $exclude, $sites, $groups, $subfolder, $filename, 0, (int) $flags['backup_archives'], (int) $flags['cache'], (int) $flags['non_wp_folders'], (int) $flags['zip_archives'], $values['archive_format'], $tuning['max_file_descriptors_override'], $tuning['max_file_descriptors_auto'], $tuning['max_file_descriptors'], (int) $tuning['load_files_before_zip'], (int) $tuning['zip_force_override'] );
        }
        if ( false === $saved ) {
            return new \WP_Error( 'mainwp_internal_error', esc_html__( 'Could not save the backup task. Please check that all required fields are filled in and try again. If the issue persists, check your server error log.', 'mainwp' ), array( 'status' => 500 ) );
        }
        $id = (int) ( $task ? $task->id : $saved->id );
        MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( $id, $sites, $groups );
        MainWP_Backup_Storage_Handler::save_task_destinations( $id, $values['destination_ids'] );
        $schedule = MainWP_Manage_Backups_Handler::save_task_schedule_fields(
            $id,
            array(
                'schedule_interval_hours' => $values['interval_hours'] ?? 0,
                'schedule_time_of_day'    => $values['time_of_day'] ?? '',
                'schedule_day_of_week'    => $values['day_of_week'] ?? $task->day_of_week ?? 0,
                'schedule_day_of_month'   => $values['day_of_month'] ?? $task->day_of_month ?? 1,
            )
        );
        MainWP_Manage_Backups_Handler::save_task_retention_fields(
            $id,
            array(
                'retention_count'      => $values['retention']['count'],
                'retention_days'       => $values['retention']['days'],
                'retention_storage_mb' => $values['retention']['storage_mb'],
                'delete_cloud_copies'  => $values['retention']['delete_cloud_copies'],
                'max_chain_length'     => $values['retention']['max_chain_length'],
            ),
            null === $task
        );
        if ( null === $task ) {
            do_action( 'mainwp_add_backuptask', $id );
        }
        return array(
            'task_id'     => $id,
            'normalized'  => $values,
            'next_run_at' => empty( $schedule['next_run'] ) ? null : (int) $schedule['next_run'],
        );
    }

    /**
     * Deleting a task can also destroy its historical snapshots.
     *
     * @param int    $task_id Task identity.
     * @param bool   $delete_snapshots Whether to purge owned snapshots.
     * @param string $context Calling transport.
     * @return array|\WP_Error
     */
    public static function delete_task( int $task_id, bool $delete_snapshots = true, string $context = 'ui' ) {
        $task = self::authorize_task( $task_id, 'delete_backup_tasks' );
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
        // Purging historical sites needs the same direct scope checks as the current run.
        foreach ( MainWP_Backup_Retention_Query::instance()->list_site_ids_for_task( $task_id ) as $id ) {
            $site = self::authorize_site( (int) $id, 'delete_backup_tasks' );
            if ( is_wp_error( $site ) ) {
                return self::not_found( MainWP_Backup_Error_Codes::ERR_TASK_NOT_FOUND );
            }
        }
        return self::task_action_result( MainWP_Manage_Backups_Handler::remove_backup_result( $task_id, $delete_snapshots ), (int) $task->completed < (int) $task->last_run ? MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING : 'mainwp_operation_failed' );
    }

    /**
     * Pausing remains available after native backups are switched off.
     *
     * @param int    $task_id Task identity.
     * @param string $context Calling transport.
     * @return array|\WP_Error
     */
    public static function pause_task( int $task_id, string $context = 'ui' ) {
        $task = self::authorize_task( $task_id, 'pause_resume_backup_tasks' );
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
        $task = self::authorize_task( $task_id, 'pause_resume_backup_tasks' );
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
        $task = self::authorize_task( $task_id, 'run_backup_tasks' );
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
                if ( (int) $task->completed < (int) $task->last_run ) {
                    $result['status'] = 'already_running';
                } elseif ( 'running' === $result['status'] ) {
                    $result['status'] = 'started';
                } elseif ( 'queued' === $result['status'] ) {
                    $result['status'] = ! empty( $result['run_open'] ) ? 'already_running' : 'not_started';
                } else {
                    $result['status'] = 'already_running';
                }
            }
            return $result;
        } finally {
            MainWP_Backup_Run_History::set_context( $previous['trigger'], $previous['user_id'] );
        }
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
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED, esc_html__( 'Native backups are not enabled.', 'mainwp' ), array( 'status' => 409 ) );
        }

        $website = self::authorize_site( $site_id, 'execute_backups' );
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
            return (
                array(
                    'job_id'  => (int) $task->id,
                    'started' => (int) $task->last_run,
                    'status'  => 'ability' === $context ? 'already_running' : 'running',
                )
            );
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
            return (
                array(
                    'job_id'  => (int) $task->id,
                    'started' => (int) $task->last_run,
                    'status'  => 'ability' === $context ? 'already_running' : 'queued',
                )
            );
        }

        // Kick the first step. updateRun = true resets completed_sites and
        // stamps last_run through a claim the settle window can refuse, which
        // both starts the run and makes it eligible for the
        // cron_backups_continue backstop. The claim result distinguishes a
        // slot refusal from a run that opened and failed before returning.
        $started  = time();
        $previous = MainWP_Backup_Run_History::set_context( 'ability' === $context ? 'ability' : 'manual', get_current_user_id() );
        $claimed  = null;
        try {
            $had_slot = ( false !== MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true, $settle, $claimed ) );
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
        $task        = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        $started_out = null !== $task && (int) $task->last_run >= $started ? (int) $task->last_run : $started;

        // The hidden manual task is normally created by the claim above, but a
        // concurrent site delete (or a failed insert) can leave the re-read
        // empty; report the failure instead of dereferencing null.
        if ( empty( $task ) ) {
            return new \WP_Error( MainWP_Backup_Error_Codes::ERR_START_FAILED, esc_html__( 'The backup could not be started for this site. Please reload the page and try again.', 'mainwp' ), array( 'status' => 502 ) );
        }

        return (
            array(
                'job_id'  => (int) $task->id,
                'started' => $started_out,
                'status'  => 'ability' === $context ? ( true === $claimed ? 'started' : ( (int) $task->completed < (int) $task->last_run ? 'already_running' : 'not_started' ) ) : ( $had_slot ? 'running' : 'queued' ),
            )
        );
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
     * @param int $site_id Website identity.
     * @return array|\WP_Error
     */
    public static function cancel_site_backup( int $site_id ) {
        $website = self::authorize_site( $site_id, 'execute_backups' );
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
     * A stale Dashboard run can close even when its Child stop is unconfirmed.
     *
     * @param int $task_id Scheduled task identity.
     * @return array|\WP_Error Reset evidence.
     */
    public static function reset_task_run( int $task_id ) {
        $task = self::authorize_task( $task_id, 'pause_resume_backup_tasks' );
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
        $site = self::authorize_site( $site_id, 'execute_backups' );
        if ( is_wp_error( $site ) ) {
            return $site;
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
        $job = 'ui' === $context ? MainWP_Backup_Restore_Job_Store::instance()->get_job( $restore_job_id ) : self::authorize_restore_job( $restore_job_id );
        if ( is_wp_error( $job ) ) {
            return $job;
        }
        // A reserved row is an internal slot hold, not a restore anyone can roll back.
        if ( null === $job || 'reserved' === $job->status ) {
            return self::not_found( MainWP_Backup_Error_Codes::ERR_RESTORE_JOB_NOT_FOUND );
        }
        $site = self::authorize_site( (int) $job->site_id, 'execute_backups' );
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
        return self::storage_result(
            MainWP_Backup_Storage_Handler::test_saved_or_submitted(
                array(
                    'id'       => $id,
                    'provider' => $destination->provider,
                    'name'     => $destination->name,
                    'settings' => null,
                )
            )
        );
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
        if ( ! $fields || array_diff( array_keys( $fields ), array( 'name', 'is_default' ) ) || ( array_key_exists( 'name', $fields ) && ( ! is_string( $fields['name'] ) || '' === trim( $fields['name'] ) || mb_strlen( $fields['name'], 'UTF-8' ) > 255 ) ) || ( array_key_exists( 'is_default', $fields ) && ! is_bool( $fields['is_default'] ) ) ) {
            return new \WP_Error( 'mainwp_invalid_input', self::safe_error( 'mainwp_invalid_input' )['message'], array( 'status' => 400 ) );
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
                    'settings'   => $destination->settings,
                )
            )
        );
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
        return is_wp_error( $disabled ) ? $disabled : self::storage_result( MainWP_Backup_Storage_Handler::delete_destination_flow( $id, true ) );
    }

    /**
     * Provider details can contain credentials or paths, so never copy their errors.
     *
     * @param array $result Existing handler response.
     * @return array|\WP_Error
     */
    private static function storage_result( array $result ) {
        if ( isset( $result['error'] ) || ( array_key_exists( 'ok', $result ) && ! $result['ok'] ) ) {
            return new \WP_Error( 'mainwp_operation_failed', self::safe_error( 'mainwp_operation_failed' )['message'], array( 'status' => 502 ) );
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
        if ( ! in_array( $context, array( 'ui', 'ability' ), true ) ) {
            return new \WP_Error( 'mainwp_invalid_input', self::safe_error( 'mainwp_invalid_input' )['message'], array( 'status' => 400 ) );
        }
        if ( 'ui' !== $context ) {
            $scope = MainWP_Abilities_Util::check_unrestricted_access();
            if ( is_wp_error( $scope ) ) {
                return $scope;
            }
        }
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
        $options = array(
            'on_demand_retention_count'   => 'mainwp_on_demand_retention_count',
            'max_concurrent_backups'      => 'mainwp_backup_max_concurrent',
            'diagnostics_enabled'         => MainWP_Backup_Diagnostics::OPTION_ENABLED,
            'update_gate.enabled'         => 'mainwp_backup_before_upgrade',
            'update_gate.max_age_days'    => 'mainwp_backup_before_upgrade_days',
            'update_gate.require_offsite' => 'mainwp_backup_before_upgrade_require_offsite',
        );
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
     * The stored confirmation decides whether a stop can be claimed.
     *
     * @param int $restore_job_id Restore identity.
     * @return array|\WP_Error Cancellation evidence.
     */
    public static function cancel_restore( int $restore_job_id ) {
        $before = self::authorize_restore_job( $restore_job_id );
        if ( is_wp_error( $before ) ) {
            return $before;
        }
        $call = 'not called';
        if ( in_array( $before->status, MainWP_Backup_Restore_Job_Store::active_statuses(), true ) && 'verifying' !== $before->status ) {
            try {
                MainWP_Backup_Restore_Orchestrator::instance()->cancel_job( $restore_job_id );
                $call = 'returned';
            } catch ( MainWP_Exception $e ) {
                // Another lane can finish after admission, so only the re-read job decides the result.
                $call = MainWP_Backup_Restore_Orchestrator::CANCEL_FINISHED_ON_SITE === $e->get_message_error_code() ? 'finished' : 'failed';
            }
        }
        $job = MainWP_Backup_Restore_Job_Store::instance()->get_job( $restore_job_id );
        if ( null === $job || 'reserved' === $job->status ) {
            return self::not_found( MainWP_Backup_Error_Codes::ERR_RESTORE_JOB_NOT_FOUND );
        }
        $confirmation = $job->checkpoint['cancel_confirmation']['outcome'] ?? '';
        $result       = 'not_cancellable';
        if ( in_array( $job->status, array( 'cancelled', 'failed_permanent' ), true ) ) {
            if ( 'unconfirmed' === $confirmation ) {
                $result = 'cancel_unconfirmed';
            } elseif ( 'cancelled' === $job->status ) {
                $result = 'cancelled';
            } elseif ( 'confirmed' === $confirmation ) {
                $result = 'ended_failed';
            }
        } elseif ( in_array( $job->status, MainWP_Backup_Restore_Job_Store::active_statuses(), true ) && 'verifying' !== $job->status ) {
            $phase = 'failed_retryable' === $job->status ? (string) ( $job->checkpoint['resume_status'] ?? 'pending' ) : $job->status;
            if ( 'verifying' === $phase ) {
                // The worker drives a verification retry on, even when it applies an armed intent.
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
     * The existing retention pass owns guarded deletion.
     *
     * @param int      $site_id Website identity.
     * @param int|null $keep Optional keep-count override.
     * @param bool     $dry_run Whether to preview deletion.
     * @return array|\WP_Error
     */
    public static function cleanup_site_backups( int $site_id, ?int $keep, bool $dry_run = false ) {
        $site = self::authorize_site( $site_id, 'execute_backups' );
        if ( is_wp_error( $site ) ) {
            return $site;
        }
        // A preview must not fall through to the existing destructive pass.
        if ( $dry_run ) {
            return MainWP_Backup_Retention_Executor::instance()->plan_site_cleanup( $site_id, $keep );
        }
        return MainWP_Backup_Retention_Executor::instance()->cleanup_site_backups( $site_id, $keep );
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
        $db         = MainWP_DB_Backup::instance();
        $task       = $db->get_manual_backup_task( $site_id );
        $keep       = MainWP_Backup_Settings::get_on_demand_retention_count();
        $candidates = $task ? self::retention_after_backup( $site_id, (int) $task->id, new MainWP_Backup_Retention_Policy( $keep, 0, 0 ) ) : array();
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
            array( __( 'Site capabilities and available run slots are checked when you confirm.', 'mainwp' ) )
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
        $stale   = array();
        $sites   = MainWP_Manage_Backups_Handler::resolve_task_run_sites( $task, $stale );
        $code    = '';
        $message = MainWP_Manage_Backups_Handler::manual_task_start_error( $task, $stale, $code );
        $impact  = array();
        $policy  = new MainWP_Backup_Retention_Policy( (int) $task->retention_count, (int) $task->retention_days, (int) $task->retention_storage_mb );
        foreach ( $sites as $id ) {
            $ids     = array();
            $reasons = array();
            foreach ( self::retention_after_backup( $id, $task_id, $policy ) as $candidate ) {
                $ids       = array_merge( $ids, $candidate['snapshot_ids'] );
                $reasons[] = $candidate['reason'];
            }
            $impact[] = array(
                'site_id'                  => $id,
                'would_prune_snapshot_ids' => $ids,
                'reasons'                  => array_values( array_unique( $reasons ) ),
            );
        }
        $warnings = array(
            __( 'Site capabilities and available run slots are checked when you confirm.', 'mainwp' ),
            __( 'Retention estimates use the stored snapshots; the size of the new backup is not known yet.', 'mainwp' ),
        );
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
     * @return array
     */
    private static function retention_after_backup( int $site_id, int $task_id, MainWP_Backup_Retention_Policy $policy ): array {
        $units  = MainWP_Backup_Retention_Query::instance()->get_units_for_site_task( $site_id, $task_id );
        $now    = time();
        $newest = $now;
        foreach ( $units as $unit ) {
            $newest = max( $newest, (int) $unit['newest_created'] + 1 );
        }
        $units[] = array(
            'snapshots'      => array(),
            'is_complete'    => true,
            'size_bytes'     => 0,
            'newest_created' => $newest,
            'oldest_created' => $newest,
        );
        return $policy->evaluate( $units, $now );
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
     * @return array|\WP_Error
     */
    public static function preview_site_cleanup( int $site_id, ?int $keep ) {
        $site = self::authorize_site( $site_id, 'execute_backups' );
        if ( is_wp_error( $site ) ) {
            return $site;
        }
        $error = self::native_task_write_error();
        if ( $error ) {
            return $error;
        }
        $task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
        $keep = $keep ?? ( ! empty( $task->retention_count ) ? (int) $task->retention_count : MainWP_Backup_Settings::get_on_demand_retention_count() );
        $plan = MainWP_Backup_Retention_Executor::instance()->plan_site_cleanup( $site_id, $keep );
        return self::backup_preview(
            array(
                'site_id'                    => $site_id,
                'keep'                       => $keep,
                'orphan_dirs'                => count( $plan['orphan_dirs'] ),
                'orphaned_task_snapshot_ids' => $plan['orphaned_task_snapshot_ids'],
                'on_demand_units'            => $plan['on_demand_units'],
                'bytes_to_prune'             => $plan['bytes'],
            )
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
        $before = self::normalize_task_fields( array( 'name' => html_entity_decode( $task->name, ENT_QUOTES ) ), $task );
        // A repair of an invalid legacy value must still be reviewable.
        $before = is_wp_error( $before ) ? self::stored_task_fields( $task ) : $before;
        $query  = MainWP_Backup_Retention_Query::instance();
        $policy = new MainWP_Backup_Retention_Policy( $values['retention']['count'], $values['retention']['days'], $values['retention']['storage_mb'] );
        $impact = array();
        foreach ( $query->list_site_ids_for_task( $task_id ) as $site_id ) {
            $ids     = array();
            $reasons = array();
            foreach ( $policy->evaluate( $query->get_units_for_site_task( $site_id, $task_id ), time() ) as $candidate ) {
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
            )
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
        $calculator = new MainWP_Backup_Schedule_Calculator( wp_timezone(), time() );
        $next       = $calculator->compute_next_run( (object) $values, empty( $task->last_run ) ? null : (int) $task->last_run );
        return $next > 0 ? $next : null;
    }

    /**
     * Historical sites need authorization before a deletion preview exposes their snapshots.
     *
     * @param int  $task_id Task identity.
     * @param bool $delete_snapshots Whether snapshots would be purged.
     * @return array|\WP_Error
     */
    public static function preview_task_delete( int $task_id, bool $delete_snapshots = true ) {
        $task = self::authorize_task( $task_id, 'delete_backup_tasks' );
        if ( is_wp_error( $task ) ) {
            return $task;
        }
        $error = self::native_task_write_error();
        if ( $error ) {
            return $error;
        }
        $query     = MainWP_Backup_Retention_Query::instance();
        $snapshots = array();
        $cloud     = array();
        foreach ( $query->list_site_ids_for_task( $task_id ) as $site_id ) {
            if ( is_wp_error( self::authorize_site( $site_id, 'delete_backup_tasks' ) ) ) {
                return self::not_found( MainWP_Backup_Error_Codes::ERR_TASK_NOT_FOUND );
            }
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
                        if ( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE !== $job->status || 'local' === $job->provider ) {
                            continue;
                        }
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
        $warnings = array();
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
     * Resuming recomputes only a timed schedule, matching the existing action.
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
        if ( ! $paused && (int) $task->next_run > 0 ) {
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
