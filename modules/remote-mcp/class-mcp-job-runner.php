<?php
/**
 * Remote MCP module job runner.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Module jobs: plans with more than one entry, or an entry over the chunk
 * size, run one entry per cron tick from the job's own sealed copy of the plan.
 *
 * Before each entry the tick re-checks everything an inline call would have had checked at that
 * moment, because the approval may be minutes old: kill switch, grant, generations and policy
 * version, scope, target access, the activity log, and a fresh native preview token. Any failure
 * cancels the whole job; it never continues on a smaller set of sites.
 */
class MCP_Job_Runner implements Job_Runner {

    const TICK_LOCK_PREFIX = 'mainwp_remote_mcp_job_tick_';

    // Longer than any single entry runs under PHP's execution limits, so only a dead tick's lock expires.
    const TICK_LOCK_TTL = 15 * MINUTE_IN_SECONDS;

    /**
     * Module services.
     *
     * @var Remote_MCP_Services
     */
    private $services;

    /**
     * Constructor.
     *
     * @param Remote_MCP_Services $services Module services.
     */
    public function __construct( Remote_MCP_Services $services ) {
        $this->services = $services;
    }

    /**
     * Queue a plan. With an approval the store consumes it into the job in one step; without one
     * only `read` and `write` work may be queued.
     *
     * @param Remote_MCP_Context $context     Connection.
     * @param Call_Plan          $plan        Frozen plan.
     * @param int|null           $approval_id Approval to consume.
     * @return int|null Job id, or null when refused.
     */
    public function enqueue( Remote_MCP_Context $context, Call_Plan $plan, ?int $approval_id ): ?int {
        $store = $this->services->store();
        if ( null === $store || $plan->is_pending() ) {
            return null;
        }
        $policy  = $this->services->get( 'scope_policy' );
        $entries = $plan->entries();

        if ( null === $approval_id ) {
            foreach ( $entries as $entry ) {
                $tier = $policy->effective_tier( $context, $entry['ability'], $entry['args'] );
                if ( ! in_array( $tier, array( 'read', 'write' ), true ) || ! $context->has_scope( self::required_scope( $tier ) ) ) {
                    return null;
                }
            }
            $result = $store->enqueue_job( $context->grant_id, $context->policy_version, $plan );
        } else {
            $row = $store->get_approval( $approval_id );
            if ( null === $row || (int) $row['grant_id'] !== $context->grant_id || (int) $row['user_id'] !== $context->user_id ) {
                return null;
            }
            // The consume statement compares this with the row's tier, so a policy change since
            // the approval makes it lose.
            $tier = $policy->effective_tier( $context, (string) $row['ability'], $entries[0]['args'] );
            if ( null === $tier || ! $context->has_scope( OAuth_Store::SCOPE_WRITE ) ) {
                return null;
            }
            $result = $store->consume_into_job( $approval_id, $tier, $plan );
        }
        if ( OAuth_Store::OK !== ( $result['outcome'] ?? null ) ) {
            return null;
        }
        $job_id = (int) $result['job_id'];
        // Nothing recovers a running job without an event, and the approval is already used up.
        if ( ! MCP_Job_Cron::schedule( $job_id ) ) {
            $this->cancel( $job_id, 'schedule_failed' );
            return null;
        }
        return $job_id;
    }

    /**
     * Run at most one entry.
     *
     * @param int $job_id Job id.
     * @return bool Whether an entry ran.
     * @throws \Throwable What the claimed entry threw, after the job is failed.
     */
    public function tick( int $job_id ): bool {
        $store = $this->services->store();
        $job   = null === $store ? null : $store->get_job( $job_id );
        // A starting job is mid consume-and-enqueue and may still be deleted.
        if ( null === $job || 'running' !== $job['status'] ) {
            return false;
        }
        // WP-Cron can run one event twice on concurrent requests. The claim alone lets the second
        // tick run the next entry and settle the job while the first entry is still running, so
        // one tick at a time holds the job.
        $lock = self::acquire_tick_lock( $job_id );
        if ( null === $lock ) {
            // WP-Cron removed this event before calling here, and it can be the next tick the
            // lock holder scheduled. Without another event the job stays running with nothing to
            // pick it up.
            MCP_Job_Cron::schedule( $job_id );
            return false;
        }
        try {
            // Only a dead tick's lock expires, and its claimed entry may not have finished. Moving
            // on could report the job done with that entry unrun.
            if ( $lock['taken_over'] ) {
                $this->cancel( $job_id, 'tick_interrupted' );
                return false;
            }
            return $this->tick_locked( $store, $job, $job_id );
        } finally {
            self::release_tick_lock( $job_id, $lock['token'] );
        }
    }

    /**
     * Take the job's tick lock, or take over one left by a tick that died.
     *
     * The lock can't use add_option(): it checks a cached get_option() and then upserts, so two
     * racing ticks can both "add" the row. These statements go to the table directly.
     *
     * @param int $job_id Job id.
     * @return array|null `token` and `taken_over`, or null while another tick holds the lock.
     */
    private static function acquire_tick_lock( int $job_id ): ?array {
        global $wpdb;
        $name  = self::TICK_LOCK_PREFIX . $job_id;
        $token = time() . ':' . wp_generate_password( 12, false, false );
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the lock must bypass the options cache to be atomic.
        $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $token ) );
        if ( 1 === (int) $wpdb->rows_affected ) {
            return array(
                'token'      => $token,
                'taken_over' => false,
            );
        }
        $held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
        if ( ! is_string( $held ) || (int) strtok( $held, ':' ) > time() - self::TICK_LOCK_TTL ) {
            return null;
        }
        // Compare-and-swap on the stale value, so only one of several ticks takes it over.
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $name, $held ) );
        $won = 1 === (int) $wpdb->rows_affected;
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $won ? array(
            'token'      => $token,
            'taken_over' => true,
        ) : null;
    }

    /**
     * Release the job's tick lock if this tick still owns it.
     *
     * @param int    $job_id Job id.
     * @param string $token  Token from acquire_tick_lock().
     */
    private static function release_tick_lock( int $job_id, string $token ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- owner-checked release of the lock row acquired above.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::TICK_LOCK_PREFIX . $job_id, $token ) );
    }

    /**
     * Run at most one entry while this tick holds the job's lock.
     *
     * @param OAuth_Store $store  Store.
     * @param array       $job    Job row.
     * @param int         $job_id Job id.
     * @return bool Whether an entry ran.
     * @throws \Throwable What the claimed entry threw, after the job is failed.
     */
    private function tick_locked( OAuth_Store $store, array $job, int $job_id ): bool {
        $plan = $store->job_call_plan( $job );
        if ( null === $plan || $plan->is_pending() ) {
            $this->cancel( $job_id, 'plan_unreadable' );
            return false;
        }
        $index = (int) $job['next_entry'];
        // The claim advances the index before its entry runs, so an exhausted index can mean the
        // final entry is still running in another tick. That tick sets the outcome.
        if ( $index >= $plan->count() ) {
            return false;
        }

        $reason = $this->check( $store, $job, $plan, $index );
        if ( null !== $reason ) {
            $this->cancel( $job_id, $reason );
            return false;
        }
        if ( ! $store->claim_job_entry( $job_id, $index ) ) {
            $now = $store->get_job( $job_id );
            // Another tick claiming the entry first is normal; anything else means the grant
            // changed between the checks and the claim.
            if ( null !== $now && 'running' === $now['status'] && (int) $now['next_entry'] === $index ) {
                $this->cancel( $job_id, 'authorization_changed' );
            }
            return false;
        }
        // From here every return sets a terminal state or schedules the next tick, and an
        // exception fails the job, so the claim winner always settles the job. A process that
        // dies mid-entry leaves it running with no event; nothing sweeps those (fail closed).
        try {
            return $this->run_claimed( $store, $job, $plan, $index );
        } catch ( \Throwable $e ) {
            $store->finish_job( $job_id, true, 'entry_failed' );
            throw $e;
        }
    }

    /**
     * Run an entry this tick has claimed, and record its outcome.
     *
     * @param OAuth_Store $store Store.
     * @param array       $job   Job row.
     * @param Call_Plan   $plan  Job plan.
     * @param int         $index Claimed entry.
     * @return bool Whether the entry ran.
     */
    private function run_claimed( OAuth_Store $store, array $job, Call_Plan $plan, int $index ): bool {
        $job_id = (int) $job['id'];
        // Only the tick that owns the entry calls the native preview: a losing tick's failed
        // refresh would cancel the entry the winner is about to run.
        $run_plan = $this->run_plan( $plan, $index, (int) $job['user_id'] );
        if ( null === $run_plan ) {
            $this->cancel( $job_id, 'preview_changed' );
            return false;
        }

        $user_id  = (int) $job['user_id'];
        $grant_id = (int) $job['grant_id'];
        $entry    = $plan->entries()[ $index ];
        $result   = $this->services->get( 'tool_executor' )->execute_plan_entry( $run_plan, $index, $user_id, $grant_id );
        $fields   = array(
            'user_id'  => $user_id,
            'grant_id' => $grant_id,
            'job_id'   => $job_id,
            'ability'  => $entry['ability'],
            'site_ids' => (array) $entry['target_ids'],
        );

        $error = null;
        if ( ! empty( $result['queued'] ) ) {
            // Core's own queue carries no grant, so the work would run past a revoke.
            $error = 'core_queued';
        } elseif ( true !== ( $result['ok'] ?? null ) ) {
            $error = self::reason_code( $result['reason'] ?? null, 'entry_failed' );
        }
        if ( null !== $error ) {
            $store->finish_job( $job_id, true, $error );
            Remote_MCP_Events::audit(
                'job_chunk',
                $fields + array(
                    'outcome' => 'failed',
                    'reason'  => $error,
                )
            );
            return true;
        }

        Remote_MCP_Events::audit( 'job_chunk', $fields + array( 'outcome' => 'ok' ) );
        if ( $index + 1 >= $plan->count() ) {
            $store->finish_job( $job_id, false );
        } elseif ( ! MCP_Job_Cron::schedule( $job_id ) ) {
            $store->finish_job( $job_id, true, 'schedule_failed' );
        }
        return true;
    }

    /**
     * Cancel a job.
     *
     * @param int    $job_id Job id.
     * @param string $reason Reason code.
     * @return bool Whether this call cancelled it.
     */
    public function cancel( int $job_id, string $reason ): bool {
        $store = $this->services->store();
        $job   = null === $store ? null : $store->get_job( $job_id );
        if ( null === $job ) {
            return false;
        }
        $reason = self::reason_code( $reason, 'cancelled' );
        if ( ! $store->cancel_job( $job_id, $reason ) ) {
            return false;
        }
        MCP_Job_Cron::unschedule( $job_id );
        Remote_MCP_Events::audit(
            'job_cancelled',
            array(
                'user_id'  => (int) $job['user_id'],
                'grant_id' => (int) $job['grant_id'],
                'job_id'   => $job_id,
                'reason'   => $reason,
            )
        );
        return true;
    }

    /**
     * Progress of the caller's own job.
     *
     * @param int $job_id  Job id.
     * @param int $user_id User asking.
     * @return array|null
     */
    public function status( int $job_id, int $user_id ): ?array {
        $store = $this->services->store();
        $job   = null === $store || $job_id <= 0 ? null : $store->get_job( $job_id );
        if ( null === $job || (int) $job['user_id'] !== $user_id || $user_id <= 0 ) {
            return null;
        }
        $plan    = $store->job_call_plan( $job );
        $entries = null === $plan ? array() : $plan->entries();
        $total   = count( $entries );
        $next    = (int) $job['next_entry'];
        $current = 'running' === $job['status'] && isset( $entries[ $next ] ) ? (array) $entries[ $next ]['target_ids'] : array();
        return array(
            'job_id'           => (int) $job['id'],
            'status'           => (string) $job['status'],
            'entries_done'     => min( $next, $total ),
            'entries_total'    => $total,
            'current_site_ids' => $current,
            'cancel_reason'    => null === $job['last_error'] || '' === $job['last_error'] ? null : (string) $job['last_error'],
        );
    }

    /**
     * Whether the activity log is on. A seam: the test bootstrap defines the logs module off.
     *
     * @return bool
     */
    protected function activity_log_available(): bool {
        return Remote_MCP_Settings::activity_log_available();
    }

    /**
     * The per-tick checks before one entry is claimed. The native token is refreshed after the claim.
     *
     * @param OAuth_Store $store Store.
     * @param array       $job   Job row.
     * @param Call_Plan   $plan  Job plan.
     * @param int         $index Entry about to run.
     * @return string|null Reason code, or null when the entry may be claimed.
     */
    private function check( OAuth_Store $store, array $job, Call_Plan $plan, int $index ): ?string {
        if ( null !== Remote_MCP_Settings::forced_off() ) {
            return 'kill_switch';
        }
        if ( ! Remote_MCP_Settings::is_active() ) {
            return 'connector_off';
        }
        $grant = $store->get_grant( (int) $job['grant_id'] );
        if ( null === $grant || 'live' !== $grant['status'] ) {
            return 'grant_revoked';
        }
        if ( (int) $grant['policy_version'] !== (int) $job['policy_version'] ) {
            return 'policy_changed';
        }
        if ( ! $store->authorization_active( (int) $job['grant_id'], (int) $job['policy_version'] ) ) {
            return 'authorization_changed';
        }

        // No token exists at a tick, so the grant's current scope is the ceiling.
        $context = new Remote_MCP_Context(
            (int) $grant['id'],
            (int) $grant['user_id'],
            (string) $grant['client_id'],
            (string) $grant['client_kind'],
            OAuth_Store::scope_tokens( (string) $grant['scope'] ),
            (string) $grant['approval_policy'],
            (int) $grant['policy_version'],
            'job'
        );
        $entry   = $plan->entries()[ $index ];
        $tier    = $this->services->get( 'scope_policy' )->effective_tier( $context, $entry['ability'], $entry['args'] );
        if ( null === $tier ) {
            return 'not_available';
        }
        if ( ! $context->has_scope( self::required_scope( $tier ) ) ) {
            return 'scope_narrowed';
        }
        $approval = empty( $job['approval_id'] ) ? null : $store->get_approval( (int) $job['approval_id'] );
        $approved = empty( $job['approval_id'] ) ? 'write' : ( $approval['tier'] ?? null );
        $tiers    = array(
            'read'      => 0,
            'write'     => 1,
            'confirm'   => 2,
            'dashboard' => 3,
        );
        if ( ! isset( $tiers[ $approved ], $tiers[ $tier ] ) || $tiers[ $tier ] > $tiers[ $approved ] ) {
            return 'approval_required';
        }
        // Adapters can run a different ability from the tool the user approved.
        foreach ( array_unique( array( $entry['ability'], $approval['ability'] ?? $entry['ability'] ) ) as $ability ) {
            $tool = wp_has_ability( $ability ) ? wp_get_ability( $ability ) : null;
            if ( ! $tool || ! Remote_MCP_Settings::tool_enabled( MCP_Tool_Catalog::tool_name( $ability ) ) || ! Remote_MCP_Settings::category_enabled( $tool->get_category() ) ) {
                return 'not_available';
            }
        }
        $registry = $this->services->get( 'tier_registry' )->entry( $entry['ability'] );
        if ( ! is_array( $registry ) ) {
            return 'not_available';
        }
        if ( ! $this->targets_unchanged( $context, $entry, (string) ( $registry['resolver'] ?? '' ) ) ) {
            return 'targets_changed';
        }
        if ( ! $this->activity_log_available() ) {
            return 'audit_log_off';
        }
        return null;
    }

    /**
     * The plan the claimed entry runs from: the job's own, or for a token-bound entry a refreshed
     * copy that differs only in the native token.
     *
     * @param Call_Plan $plan    Job plan.
     * @param int       $index   Claimed entry.
     * @param int       $user_id Job user.
     * @return Call_Plan|null Null when the refresh fails or changes anything else.
     */
    private function run_plan( Call_Plan $plan, int $index, int $user_id ): ?Call_Plan {
        $registry = $this->services->get( 'tier_registry' )->entry( $plan->entries()[ $index ]['ability'] );
        if ( ! is_array( $registry ) ) {
            return null;
        }
        $token_field = $registry['contract']['execute']['native_token_field'] ?? null;
        if ( ! is_string( $token_field ) ) {
            return $plan;
        }
        $refreshed = $this->services->get( 'control_contract' )->refresh_native_token( $plan, $index, $user_id );
        if ( ! is_array( $refreshed ) || true === ( $refreshed['stale'] ?? true ) || ! ( $refreshed['plan'] ?? null ) instanceof Call_Plan
            || ! self::same_except_token( $plan, $refreshed['plan'], $index, $token_field ) ) {
            return null;
        }
        return $refreshed['plan'];
    }

    /**
     * Whether every target still exists and is reachable by the job's user, and an entity entry
     * still acts on exactly the sites that were approved.
     *
     * @param Remote_MCP_Context $context  Grant context.
     * @param array              $entry    Plan entry.
     * @param string             $resolver Resolver type.
     * @return bool
     */
    private function targets_unchanged( Remote_MCP_Context $context, array $entry, string $resolver ): bool {
        $frozen = (array) $entry['target_ids'];
        $sites  = MCP_Target_Resolver::as_user(
            $context->user_id,
            static function () use ( $frozen ) {
                foreach ( $frozen as $site_id ) {
                    if ( MCP_Target_Resolver::accessible_site( (int) $site_id ) !== (int) $site_id ) {
                        return false;
                    }
                }
                return true;
            }
        );
        if ( ! $sites ) {
            return false;
        }
        if ( ! in_array( $resolver, array( 'client', 'tag' ), true ) ) {
            return true;
        }
        $now = $this->services->get( 'target_resolver' )->resolve( $context, $entry['ability'], $entry['args'] );
        return is_array( $now ) && is_array( $now['site_ids'] ?? null ) && $now['site_ids'] === $frozen;
    }

    /**
     * Whether a refreshed plan differs from the frozen one only in the entry's native token.
     *
     * @param Call_Plan $frozen      Frozen plan.
     * @param Call_Plan $refreshed   Refreshed plan.
     * @param int       $index       Token-bound entry.
     * @param string    $token_field Native token field.
     * @return bool
     */
    private static function same_except_token( Call_Plan $frozen, Call_Plan $refreshed, int $index, string $token_field ): bool {
        if ( $refreshed->is_pending() ) {
            return false;
        }
        $before = self::without_token( $frozen, $index, $token_field );
        $after  = self::without_token( $refreshed, $index, $token_field );
        return null !== $before && null !== $after && hash_equals( $before, $after );
    }

    /**
     * Canonical JSON of a plan without one entry's native token. It keeps JSON objects as objects,
     * where entries() would read `{}` and `[]` as the same empty array.
     *
     * @param Call_Plan $plan        Plan.
     * @param int       $index       Token-bound entry.
     * @param string    $token_field Native token field.
     * @return string|null
     */
    private static function without_token( Call_Plan $plan, int $index, string $token_field ): ?string {
        $entries = json_decode( $plan->to_json(), false );
        if ( ! is_array( $entries ) || ! isset( $entries[ $index ] ) || ! ( $entries[ $index ]->args ?? null ) instanceof \stdClass ) {
            return null;
        }
        unset( $entries[ $index ]->args->{ $token_field } );
        return Call_Plan::canonical_json( $entries );
    }

    /**
     * Scope a tier needs.
     *
     * @param string $tier Tier.
     * @return string
     */
    private static function required_scope( string $tier ): string {
        return 'read' === $tier ? OAuth_Store::SCOPE_READ : OAuth_Store::SCOPE_WRITE;
    }

    /**
     * A short reason code safe for `last_error` and the audit log, never free text.
     *
     * @param mixed  $reason   Reason.
     * @param string $fallback Code when the reason isn't one.
     * @return string
     */
    private static function reason_code( $reason, string $fallback ): string {
        $code = is_string( $reason ) ? substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $reason ) ), 0, 64 ) : '';
        return '' === $code ? $fallback : $code;
    }
}
