<?php
/**
 * Remote MCP approval gate interface.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The server-enforced write tiers. Implemented by `MCP_Approval_Gate`.
 *
 * The tools/call sequence: the tool executor calls check(), then hands every decision except
 * `refused` to deliver() and returns its result unchanged. deliver() handles every decision,
 * `run` included: for `run` it consumes once and then either runs the entries inline through
 * Tool_Executor::execute_plan_entry() or enqueues a job, and returns the final CallToolResult,
 * the ability's redacted result or the job handle (`structuredContent` `{"status": "JOB_STARTED",
 * "job_id", "entries_total", "status_tool": "get_remote_job_status_v1"}`). consume() is internal
 * to the gate; nothing outside it calls consume() or the store's consume statement.
 *
 * A decision is an array:
 * - `decision`: `run` (execute `plan` now), `confirmation_required`, `approval_required`,
 *   `denied` or `refused`
 * - `tier`: the effective tier, or null when refused before tiering
 * - `reason`: short code for `refused` and `denied`, otherwise null
 * - `approval_id`: the approval row, or null
 * - `plan`: the Call_Plan to run for `run`, otherwise null
 */
interface Approval_Gate {

    /**
     * Decide what a tools/call needs before anything runs.
     *
     * @param Remote_MCP_Context $context   Connection.
     * @param string             $ability   Ability name.
     * @param array              $arguments Client arguments, gate controls included.
     * @return array Decision.
     */
    public function check( Remote_MCP_Context $context, string $ability, array $arguments ): array;

    /**
     * The client-facing reply for a decision that needs the user, in the client's delivery mode:
     * a CallToolResult, or an array with `jsonrpc_error` for the -32042 path.
     *
     * @param Remote_MCP_Context $context  Connection.
     * @param array              $decision Decision from check().
     * @return array
     */
    public function deliver( Remote_MCP_Context $context, array $decision ): array;

    /**
     * Consume an approved decision through the store's consume statement.
     *
     * @param Remote_MCP_Context $context  Connection.
     * @param array              $decision Decision from check().
     * @return Call_Plan|null The frozen plan to run, or null when nothing may run.
     */
    public function consume( Remote_MCP_Context $context, array $decision ): ?Call_Plan;
}
