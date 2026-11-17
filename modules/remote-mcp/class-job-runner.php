<?php
/**
 * Remote MCP job runner interface.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Module jobs for plans with more than one entry or over the chunk size. Implemented by
 * `MCP_Job_Runner`, which also schedules `mainwp_remote_mcp_job_tick` through a hook provider.
 */
interface Job_Runner {

    /**
     * Queue a plan.
     *
     * @param Remote_MCP_Context $context     Connection.
     * @param Call_Plan          $plan        Frozen plan.
     * @param int|null           $approval_id Approval consumed into the job, or null for `write` tier.
     * @return int|null Job id, or null when refused.
     */
    public function enqueue( Remote_MCP_Context $context, Call_Plan $plan, ?int $approval_id ): ?int;

    /**
     * Run at most one entry of a job, after rechecking the grant.
     *
     * @param int $job_id Job id.
     * @return bool Whether an entry ran.
     */
    public function tick( int $job_id ): bool;

    /**
     * Cancel a job.
     *
     * @param int    $job_id Job id.
     * @param string $reason Short reason code.
     * @return bool
     */
    public function cancel( int $job_id, string $reason ): bool;

    /**
     * Progress of one job for `get_remote_job_status_v1`, own jobs only.
     *
     * @param int $job_id  Job id.
     * @param int $user_id User asking; a job of any other user reads as not found.
     * @return array|null Status, or null when the job isn't that user's or doesn't exist.
     */
    public function status( int $job_id, int $user_id ): ?array;
}
