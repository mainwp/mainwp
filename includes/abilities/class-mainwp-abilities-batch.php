<?php
/**
 * MainWP Batch Abilities
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Abilities_Batch
 *
 * Registers and implements batch operation monitoring abilities for the MainWP Dashboard.
 *
 * This class provides 1 ability:
 * - mainwp/get-batch-job-status-v1: Retrieve status of a queued batch operation
 *
 * Batch operations (sync, update, reconnect, disconnect, check, suspend) that affect
 * >200 sites are automatically queued for background processing. This ability allows
 * clients to poll for job status, progress, and results.
 */
class MainWP_Abilities_Batch { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Register all batch abilities.
     *
     * @return void
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        self::register_get_batch_job_status();
    }

    /**
     * Register mainwp/get-batch-job-status-v1 ability.
     *
     * @return void
     */
    private static function register_get_batch_job_status(): void {
        wp_register_ability(
            'mainwp/get-batch-job-status-v1',
            array(
                'label'               => __( 'Get Batch Job Status', 'mainwp' ),
                'description'         => __( 'Retrieve status of a queued batch operation (sync, update, reconnect, disconnect, check, suspend). Returns progress, results, and errors. Possible errors: mainwp_job_not_found.', 'mainwp' ),
                'category'            => 'mainwp-batch',
                'input_schema'        => self::get_batch_job_status_input_schema(),
                'output_schema'       => self::get_batch_job_status_output_schema(),
                'execute_callback'    => array( self::class, 'execute_get_batch_job_status' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => '',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for get-batch-job-status-v1.
     *
     * @return array
     */
    public static function get_batch_job_status_input_schema(): array {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'job_id' => array(
                    'type'        => 'string',
                    'pattern'     => '^(sync|update|reconnect|disconnect|check|suspend)_',
                    'description' => __( 'Batch job ID returned from queued operation (e.g., sync_abc123, update_xyz789)', 'mainwp' ),
                ),
            ),
            'required'             => array( 'job_id' ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get output schema for get-batch-job-status-v1.
     *
     * @return array
     */
    public static function get_batch_job_status_output_schema(): array { // phpcs:ignore -- NOSONAR - complex array.
        return array(
            'type'       => 'object',
            'properties' => array(
                'job_id'            => array(
                    'type' => 'string',
                ),
                'type'              => array(
                    'type' => 'string',
                    'enum' => array( 'sync', 'update', 'reconnect', 'disconnect', 'check', 'suspend' ),
                ),
                'status'            => array(
                    'type' => 'string',
                    'enum' => array( 'queued', 'processing', 'completed', 'failed' ),
                ),
                'progress'          => array(
                    'type'    => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                ),
                'processed'         => array(
                    'type'        => 'integer',
                    'description' => __( 'Number of sites processed so far', 'mainwp' ),
                ),
                'total'             => array(
                    'type'        => 'integer',
                    'description' => __( 'Total number of sites to process', 'mainwp' ),
                ),
                'succeeded'         => array(
                    'type'        => 'integer',
                    'description' => __( 'Number of successful operations', 'mainwp' ),
                ),
                'failed'            => array(
                    'type'        => 'integer',
                    'description' => __( 'Number of failed operations', 'mainwp' ),
                ),
                'started_at'        => array(
                    'oneOf' => array(
                        array(
                            'type'   => 'string',
                            'format' => 'date-time',
                        ),
                        array(
                            'type' => 'null',
                        ),
                    ),
                ),
                'completed_at'      => array(
                    'oneOf' => array(
                        array(
                            'type'   => 'string',
                            'format' => 'date-time',
                        ),
                        array(
                            'type' => 'null',
                        ),
                    ),
                ),
                'errors'            => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'site_id' => array(
                                'type' => 'integer',
                            ),
                            'code'    => array(
                                'type' => 'string',
                            ),
                            'message' => array(
                                'type' => 'string',
                            ),
                        ),
                        'required'   => array( 'site_id', 'code', 'message' ),
                    ),
                ),
                'job_timed_out'     => array(
                    'type'        => 'boolean',
                    'description' => __( 'Whether the job exceeded the maximum processing time window (4 hours).', 'mainwp' ),
                ),
                'job_error_code'    => array(
                    'type'        => 'string',
                    'description' => __( 'Top-level error code when job failed due to timeout or other job-level issue. Present when job_timed_out is true.', 'mainwp' ),
                ),
                'job_error_message' => array(
                    'type'        => 'string',
                    'description' => __( 'Human-readable description of the job-level error. Present when job_timed_out is true.', 'mainwp' ),
                ),
            ),
            'required'   => array( 'job_id', 'type', 'status', 'progress', 'processed', 'total', 'succeeded', 'failed' ),
        );
    }

    /**
     * Extract job type from job_id prefix.
     *
     * @param string $job_id The batch job ID.
     * @return string|null Job type string or null if prefix is not recognized.
     */
    private static function get_job_type_from_job_id( string $job_id ): ?string {
        if ( strpos( $job_id, 'sync_' ) === 0 ) {
            return 'sync';
        } elseif ( strpos( $job_id, 'update_' ) === 0 ) {
            return 'update';
        } elseif ( strpos( $job_id, 'reconnect_' ) === 0 ) {
            return 'reconnect';
        } elseif ( strpos( $job_id, 'disconnect_' ) === 0 ) {
            return 'disconnect';
        } elseif ( strpos( $job_id, 'check_' ) === 0 ) {
            return 'check';
        } elseif ( strpos( $job_id, 'suspend_' ) === 0 ) {
            return 'suspend';
        }

        return null;
    }

    /**
     * Execute get-batch-job-status-v1 ability.
     *
     * @param array $input Input parameters with job_id.
     * @return array|\WP_Error Job status array or WP_Error on failure.
     */
    public static function execute_get_batch_job_status( array $input ) { // phpcs:ignore -- NOSONAR - complex function.
        $job_id = isset( $input['job_id'] ) ? $input['job_id'] : '';

        if ( empty( $job_id ) || ! is_string( $job_id ) ) {
            return new \WP_Error(
                'ability_invalid_input',
                __( 'Missing or invalid job_id parameter.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $job_type_from_prefix = self::get_job_type_from_job_id( $job_id );

        if ( null === $job_type_from_prefix ) {
            return new \WP_Error(
                'mainwp_invalid_job_id',
                __( 'Invalid job ID format. Job ID must start with a recognized operation type prefix (sync_, update_, reconnect_, disconnect_, check_, suspend_).', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        $job_data = null;

        if ( 'sync' === $job_type_from_prefix ) {
            $job_data = MainWP_Abilities_Util::get_batch_sync_status( $job_id );
        } elseif ( 'update' === $job_type_from_prefix ) {
            $job_data = MainWP_Abilities_Util::get_batch_update_status( $job_id );
        } else {
            $job_data = MainWP_Abilities_Util::get_batch_operation_status( $job_id );
        }

        if ( null === $job_data ) {
            return new \WP_Error(
                'mainwp_job_not_found',
                __( 'Batch job not found. Job may have expired (jobs are kept for 24 hours).', 'mainwp' ),
                array( 'status' => 404 )
            );
        }

        // Normalize job data fields with defensive fallbacks.
        // This section tolerates partially populated transients to avoid misleading status
        // for malformed or incomplete job data (e.g., from interrupted background processes).
        $job_type = isset( $job_data['job_type'] ) && ! empty( $job_data['job_type'] )
            ? $job_data['job_type']
            : $job_type_from_prefix;

        // Validate status against known values; default to 'queued' if invalid.
        $valid_statuses = array( 'queued', 'processing', 'completed', 'failed' );
        $status         = isset( $job_data['status'] ) && in_array( $job_data['status'], $valid_statuses, true )
            ? $job_data['status']
            : 'queued';

        // Clamp progress to valid 0-100 range.
        $progress  = isset( $job_data['progress'] ) ? max( 0, min( 100, (int) $job_data['progress'] ) ) : 0;
        $processed = isset( $job_data['processed'] ) ? max( 0, (int) $job_data['processed'] ) : 0;
        $total     = isset( $job_data['total'] ) ? max( 0, (int) $job_data['total'] ) : 0;

        // Count successes from whichever key is populated (sync/update/operation).
        // Only count if the key exists AND contains an array; fall back to zero otherwise.
        $succeeded = 0;
        if ( isset( $job_data['synced'] ) && is_array( $job_data['synced'] ) ) {
            $succeeded = count( $job_data['synced'] );
        } elseif ( isset( $job_data['updated'] ) && is_array( $job_data['updated'] ) ) {
            $succeeded = count( $job_data['updated'] );
        } elseif ( isset( $job_data['successful'] ) && is_array( $job_data['successful'] ) ) {
            $succeeded = count( $job_data['successful'] );
        }

        // Treat non-array 'errors' as empty list to avoid type errors.
        $errors_array = isset( $job_data['errors'] ) && is_array( $job_data['errors'] ) ? $job_data['errors'] : array();

        $started_at   = null;
        $completed_at = null;

        if ( isset( $job_data['started'] ) && is_numeric( $job_data['started'] ) && $job_data['started'] > 0 ) {
            $started_at = gmdate( 'Y-m-d\TH:i:s\Z', (int) $job_data['started'] );
        }

        if ( isset( $job_data['completed'] ) && is_numeric( $job_data['completed'] ) && $job_data['completed'] > 0 ) {
            $completed_at = gmdate( 'Y-m-d\TH:i:s\Z', (int) $job_data['completed'] );
        }

        $normalized_errors = array();
        foreach ( $errors_array as $error ) {
            if ( is_array( $error ) ) {
                $normalized_errors[] = array(
                    'site_id' => isset( $error['site_id'] ) ? (int) $error['site_id'] : 0,
                    'code'    => isset( $error['code'] ) ? (string) $error['code'] : '',
                    'message' => isset( $error['message'] ) ? (string) $error['message'] : '',
                );
            }
        }

        $failed = count( $normalized_errors );

        $response = array(
            'job_id'       => $job_id,
            'type'         => $job_type,
            'status'       => $status,
            'progress'     => $progress,
            'processed'    => $processed,
            'total'        => $total,
            'succeeded'    => $succeeded,
            'failed'       => $failed,
            'started_at'   => $started_at,
            'completed_at' => $completed_at,
            'errors'       => $normalized_errors,
        );

        // Expose timeout state and add top-level error fields when job timed out.
        // This provides a clear indicator without requiring clients to scan the errors array.
        $job_timed_out             = ! empty( $job_data['job_timed_out'] );
        $response['job_timed_out'] = $job_timed_out;

        if ( $job_timed_out ) {
            $response['job_error_code']    = 'timeout';
            $response['job_error_message'] = __( 'Job timed out after 4 hours. Partial results may be available.', 'mainwp' );
        }

        return $response;
    }
}
