<?php
/**
 * MainWP Backup Test-Restore Driver - the dashboard side of a Level-5
 * verified restore.
 *
 * Verification at Level 4 proves a snapshot's bytes are retrievable (the
 * offsite state machine plus a post-PUT HEAD). Level 5 proves they
 * reconstitute: a sampled snapshot is restored into a throwaway sandbox and
 * asserted to actually come back. This driver reuses the proven restore
 * front half - resolve the restorable chain, then stage decrypted,
 * hash-verified artifacts dashboard-side - and applies the staged plan into a
 * MainWP_Backup_TestRestore_Sandbox instead of streaming it to a live child.
 * The live restore path is untouched: this is a separate, schedule-gated
 * driver that never writes to the live site or the live dashboard database.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Backup_TestRestore_Driver
 */
class MainWP_Backup_TestRestore_Driver { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * The snapshot test-restored and reconstituted cleanly.
     */
    const STATE_PASS = 'pass';

    /**
     * The snapshot did not reconstitute: a dump that would not import, an
     * unreadable archive, or assertions that did not hold.
     */
    const STATE_FAIL = 'fail';

    /**
     * The environment cannot host a sandbox (no CREATE privilege, or a
     * missing decompression extension). Distinct from a bad backup.
     */
    const STATE_UNSUPPORTED = 'unsupported';

    /**
     * Error code returned when the restorable chain cannot be resolved (pruned
     * chain, no surviving artifact). An availability problem, not a
     * reconstitution failure, so verify_snapshot() reports it without recording
     * a result; the sampler stamps its own backoff instead.
     */
    const ERR_UNRESOLVABLE = 'mainwp_testrestore_unresolvable';

    /**
     * Error code returned when the caller's deadline passes between staging
     * finishing and the sandbox applying the plan. A budget problem, not a
     * reconstitution failure, so verify_snapshot() reports it without
     * recording a result; the scheduler stamps its own backoff instead,
     * mirroring ERR_UNRESOLVABLE.
     */
    const ERR_DEADLINE_EXCEEDED = 'mainwp_testrestore_deadline_exceeded';

    /**
     * Error code returned when a staged SQL dump's decompressed size exceeds
     * the configured cap. An environment limitation (the dump would not fit
     * in memory to import), not a reconstitution failure.
     */
    const ERR_DUMP_TOO_LARGE = 'mainwp_testrestore_dump_too_large';

    /**
     * Staging-job-id namespace for test-restores, kept far above real restore
     * job ids so their staging directories never collide.
     */
    const STAGING_NAMESPACE = 990000000;

    /**
     * Staging budget for a test-restore, seconds.
     */
    const DEFAULT_STAGE_BUDGET_SECONDS = 300;

    /**
     * Singleton.
     *
     * @var MainWP_Backup_TestRestore_Driver|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return MainWP_Backup_TestRestore_Driver
     */
    public static function instance(): self {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Test-restore one snapshot into a sandbox and record the outcome.
     *
     * @param int   $snapshot_id Snapshot to verify.
     * @param array $opts        { sentinel: { path: string, sha256: string },
     *                           deadline: float } Optional sentinel-file
     *                           assertion and absolute microtime(true)
     *                           deadline from a caller's own budget (e.g. the
     *                           sampler's tick). Absent, staging keeps its own
     *                           DEFAULT_STAGE_BUDGET_SECONDS and no mid-flight
     *                           deadline is enforced.
     *
     * @return array { snapshot_id: int, state: pass|fail|unsupported,
     *                 checks: array[], error: string|null, error_code: string,
     *                 sandbox: { db_name: string, temp_dir: string } }
     */
    public function verify_snapshot( int $snapshot_id, array $opts = array() ): array {
        $caller_deadline = isset( $opts['deadline'] ) ? (float) $opts['deadline'] : null;
        $sandbox         = $this->make_sandbox();
        $result          = array(
            'snapshot_id' => $snapshot_id,
            'state'       => self::STATE_FAIL,
            'checks'      => array(),
            'error'       => null,
            'error_code'  => '',
            'sandbox'     => array(
                'db_name'  => $sandbox->db_name(),
                'temp_dir' => $sandbox->temp_dir(),
            ),
        );

        // Resolve the restorable chain. A failure here is an availability
        // problem (pruned chain, missing artifact), not a reconstitution
        // failure, so it is reported but not recorded against the snapshot.
        $resolved = $this->catalog()->resolve_restorable_chain( $snapshot_id, 0 );
        if ( empty( $resolved['ok'] ) ) {
            $result['error']      = isset( $resolved['error'] ) ? (string) $resolved['error'] : '';
            $result['error_code'] = self::ERR_UNRESOLVABLE;
            return $result;
        }

        $plan       = (array) $resolved['plan'];
        $staging_id = self::STAGING_NAMESPACE + $snapshot_id;
        $transfer   = $this->transfer_driver();

        $own_deadline   = microtime( true ) + (float) self::DEFAULT_STAGE_BUDGET_SECONDS;
        $stage_deadline = null !== $caller_deadline ? min( $caller_deadline, $own_deadline ) : $own_deadline;

        try {
            $outcome = $transfer->stage_artifacts( $staging_id, $plan, $stage_deadline );
            if ( empty( $outcome['done'] ) && empty( $outcome['error'] ) ) {
                // Staging did not finish, but reported no error: it ran out of
                // budget mid-flight (a deadline hit between artifacts, or a
                // chunked reassembly still in progress). That is a neutral
                // "not finished this tick", not a reconstitution failure, so
                // report it as a deadline miss the scheduler re-samples later
                // and return before recording a fail against a healthy
                // snapshot - same as the post-staging budget path below.
                $result['error']      = esc_html__( 'The test-restore budget expired before the backup could be staged.', 'mainwp' );
                $result['error_code'] = self::ERR_DEADLINE_EXCEEDED;
                return $result;
            } elseif ( empty( $outcome['done'] ) ) {
                $result['state']      = self::STATE_FAIL;
                $result['error']      = (string) $outcome['error'];
                $result['error_code'] = isset( $outcome['error_code'] ) ? (string) $outcome['error_code'] : 'mainwp_testrestore_staging_failed';
            } elseif ( null !== $caller_deadline && microtime( true ) >= $caller_deadline ) {
                // Staging alone consumed the caller's whole budget. Bringing up
                // a sandbox and applying the plan now would overrun the tick
                // that owns this run, so bail before touching the sandbox and
                // let the caller re-sample this snapshot on a later tick
                // rather than mislabel a budget miss as a reconstitution result.
                $result['error']      = esc_html__( 'The test-restore budget expired before the sandbox could apply the plan.', 'mainwp' );
                $result['error_code'] = self::ERR_DEADLINE_EXCEEDED;
                return $result;
            } else {
                $sandbox->bring_up();
                $applied = $this->apply_plan( $sandbox, $plan );
                $checks  = $this->run_assertions( $sandbox, $applied, $opts );

                $passed = $applied['db'] || $applied['files'];
                foreach ( $checks as $check ) {
                    if ( empty( $check['ok'] ) ) {
                        $passed = false;
                    }
                }

                $result['checks'] = $checks;
                $result['state']  = $passed ? self::STATE_PASS : self::STATE_FAIL;
                if ( ! $applied['db'] && ! $applied['files'] ) {
                    $result['error']      = esc_html__( 'The backup had no database or files to test-restore.', 'mainwp' );
                    $result['error_code'] = 'mainwp_testrestore_empty';
                }
            }
        } catch ( MainWP_Exception $e ) {
            $code                 = (string) $e->get_message_error_code();
            $unsupported          = in_array( $code, array( MainWP_Backup_TestRestore_Sandbox::ERR_UNSUPPORTED, self::ERR_DUMP_TOO_LARGE ), true );
            $result['state']      = $unsupported ? self::STATE_UNSUPPORTED : self::STATE_FAIL;
            $result['error']      = $e->getMessage();
            $result['error_code'] = $code;
        } catch ( \Throwable $e ) {
            $result['state']      = self::STATE_FAIL;
            $result['error']      = $e->getMessage();
            $result['error_code'] = 'mainwp_testrestore_error';
        } finally {
            $sandbox->tear_down();
            $transfer->cleanup_staging( $staging_id );
        }

        $this->record( $snapshot_id, $result['state'] );
        return $result;
    }

    /**
     * Apply a staged plan into the sandbox: extract file archives first, then
     * replay the database dump last, mirroring the restore apply order.
     *
     * @param MainWP_Backup_TestRestore_Sandbox $sandbox Sandbox.
     * @param array                             $plan    Staged plan entries.
     *
     * @return array{db:bool,files:bool} Which artifact kinds were applied.
     */
    private function apply_plan( MainWP_Backup_TestRestore_Sandbox $sandbox, array $plan ): array {
        $db_path    = '';
        $file_paths = array();

        foreach ( $plan as $entry ) {
            $role   = isset( $entry['role'] ) ? (string) $entry['role'] : '';
            $staged = isset( $entry['staged_path'] ) ? (string) $entry['staged_path'] : '';
            if ( '' === $staged || ! file_exists( $staged ) ) {
                continue;
            }
            if ( 'db' === $role ) {
                $db_path = $staged; // The chain-newest dump; assemble_plan put it last.
            } elseif ( 'full' === $role || 'part' === $role ) {
                $file_paths[] = $staged;
            }
            // 'tombstones' are incremental deletion sidecars; a base
            // test-restore reconstitutes the chain-newest state and ignores them.
        }

        foreach ( $file_paths as $path ) {
            $sandbox->extract_files( $path );
        }
        if ( '' !== $db_path ) {
            $this->guard_dump_size( $db_path );
            $sandbox->import_database( $db_path );
        }

        return array(
            'db'    => '' !== $db_path,
            'files' => ! empty( $file_paths ),
        );
    }

    /**
     * Refuse a staged SQL dump before import_database() does its whole-buffer
     * read, when the dump's decompressed size exceeds the configured cap.
     * import_database() buffers the entire decompressed dump in memory to
     * replay it, so the size that matters is the decompressed size: for a
     * .gz dump that is read from the gzip footer's ISIZE field rather than
     * the (much smaller) on-disk footprint, since decompressing the whole
     * file just to measure it would defeat the point of this guard.
     *
     * @param string $dump_path Staged dump artifact.
     *
     * @throws MainWP_Exception When the dump exceeds the configured cap.
     *
     * @return void
     */
    private function guard_dump_size( string $dump_path ): void {
        $size = (int) filesize( $dump_path );

        if ( MainWP_Utility::ends_with( $dump_path, '.gz' ) ) {
            $handle = fopen( $dump_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- local staged artifact; only its 4-byte trailer is read here, not the whole file.
            if ( false !== $handle ) {
                if ( 0 === fseek( $handle, -4, SEEK_END ) ) {
                    $trailer = fread( $handle, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- reading the gzip ISIZE trailer, not the whole file.
                    if ( false !== $trailer && 4 === strlen( $trailer ) ) {
                        $isize = unpack( 'V', $trailer )[1];
                        $size  = max( $size, $isize );
                    }
                }
                fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the handle opened above.
            }
        }

        $default_cap = max( 64 * MB_IN_BYTES, (int) ( 0.25 * wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) ) );

        /**
         * Filter the largest decompressed SQL dump size a test-restore will
         * load into memory before refusing it as environment-unsupported.
         *
         * @param int    $default_cap Default cap in bytes: the larger of 64MB
         *                            and a quarter of the PHP memory_limit.
         * @param string $dump_path   Staged dump artifact path.
         */
        $cap = (int) apply_filters( 'mainwp_testrestore_max_sql_dump_bytes', $default_cap, $dump_path );

        if ( $size > $cap ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- debug detail and error code are for logging and matching, never echoed.
            throw new MainWP_Exception( esc_html__( 'The backup database dump is too large for the sandbox to test-restore.', 'mainwp' ), 'dump too large: ' . $size . ' > ' . $cap, self::ERR_DUMP_TOO_LARGE );
        }
    }

    /**
     * Run the cheap reconstitution assertions over an applied sandbox.
     *
     * @param MainWP_Backup_TestRestore_Sandbox $sandbox Sandbox.
     * @param array                             $applied { db: bool, files: bool }.
     * @param array                             $opts    Caller options (sentinel).
     *
     * @return array[] Check rows { name, ok, detail }.
     */
    private function run_assertions( MainWP_Backup_TestRestore_Sandbox $sandbox, array $applied, array $opts ): array {
        $checks = array();

        if ( $applied['db'] ) {
            $table_count = $sandbox->table_count();
            $checks[]    = $this->check( 'db_imported', $table_count > 0, $table_count . ' tables' );
            $checks[]    = $this->check( 'db_has_rows', $sandbox->has_table_rows(), '' );
        }

        if ( $applied['files'] ) {
            $file_count = $sandbox->temp_file_count();
            $checks[]   = $this->check( 'files_extracted', $file_count > 0, $file_count . ' files' );
        }

        if ( ! empty( $opts['sentinel']['path'] ) && ! empty( $opts['sentinel']['sha256'] ) ) {
            $actual   = $sandbox->sentinel_sha256( (string) $opts['sentinel']['path'] );
            $matches  = '' !== $actual && hash_equals( (string) $opts['sentinel']['sha256'], $actual );
            $checks[] = $this->check( 'sentinel_checksum', $matches, (string) $opts['sentinel']['path'] );
        }

        return $checks;
    }

    /**
     * Build a check row.
     *
     * @param string $name   Check name.
     * @param bool   $ok     Whether it passed.
     * @param string $detail Optional detail.
     *
     * @return array{name:string,ok:bool,detail:string}
     */
    private function check( string $name, bool $ok, string $detail = '' ): array {
        return array(
            'name'   => $name,
            'ok'     => $ok,
            'detail' => $detail,
        );
    }

    /**
     * Record the test-restore outcome on the snapshot row.
     *
     * @param int    $snapshot_id Snapshot id.
     * @param string $state       Result state.
     *
     * @return void
     */
    private function record( int $snapshot_id, string $state ): void {
        MainWP_Backup_Snapshots::instance()->set_test_restore_result( $snapshot_id, $state, time() );
    }

    /**
     * New sandbox instance (test seam).
     *
     * @return MainWP_Backup_TestRestore_Sandbox
     */
    protected function make_sandbox(): MainWP_Backup_TestRestore_Sandbox {
        return new MainWP_Backup_TestRestore_Sandbox();
    }

    /**
     * The snapshot catalog (test seam).
     *
     * @return MainWP_Backup_Snapshot_Catalog
     */
    protected function catalog(): MainWP_Backup_Snapshot_Catalog {
        return MainWP_Backup_Snapshot_Catalog::instance();
    }

    /**
     * The restore transfer driver (test seam).
     *
     * @return MainWP_Backup_Restore_Transfer_Driver
     */
    protected function transfer_driver(): MainWP_Backup_Restore_Transfer_Driver {
        return MainWP_Backup_Restore_Transfer_Driver::instance();
    }
}
