<?php
/**
 * Remote MCP tool executor interface.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The MCP tool surface, and the trusted path that runs frozen plan entries. Implemented by
 * `MCP_Tool_Executor`.
 *
 * The tools/call sequence: call_tool() checks scope, the catalog and the switches, asks the gate's
 * check(), and for every decision except `refused` returns the gate's deliver() result unchanged.
 * call_tool() never consumes, enqueues or executes a plan itself. execute_plan_entry() is called
 * only from inside Approval_Gate::deliver() and from the job runner's tick, never from tools/call.
 *
 * Listing returns MCP tool descriptors (`name`, `description`, `inputSchema`, `annotations`,
 * `securitySchemes`, `_meta`). A call returns an MCP CallToolResult array (`content`, optional
 * `structuredContent`, `isError`, optional `_meta`), never a JSON-RPC envelope.
 */
interface Tool_Executor {

    /**
     * Tools this connection may see.
     *
     * @param Remote_MCP_Context $context Connection.
     * @return array
     */
    public function list_tools( Remote_MCP_Context $context ): array;

    /**
     * Every remotely exposable tool, filtered by nothing user-specific: no scope, switches or Team
     * Control. One row per tool: `tool_name`, `ability`, `category`,
     * `standard_tier` (or null), `availability` (`available`, `not_reviewed`, `not_available_yet`
     * or `not_available`) and `reason` (a code). The panel and consent copy use only this.
     *
     * @return array
     */
    public function catalog(): array;

    /**
     * Run one tools/call.
     *
     * @param Remote_MCP_Context $context   Connection.
     * @param string             $name      Tool name.
     * @param array              $arguments Client arguments.
     * @return array CallToolResult.
     */
    public function call_tool( Remote_MCP_Context $context, string $name, array $arguments ): array;

    /**
     * Run one entry of a frozen plan, separate from the client-facing tools/call path. It runs the
     * entry's ability with the entry's arguments exactly as frozen, controls included, as that user,
     * and the ability's own permission callback still runs. It never re-enters the catalog, the
     * approval gate, consume or target expansion: the caller has already authorized the plan.
     * Taking the plan object, not a bare entry array, means only entries that passed Call_Plan's
     * validation can reach it. A pending plan (Call_Plan::is_pending()) runs nothing.
     *
     * `$grant_id` is the grant that authorized the work: the approval
     * row's grant on a retry or from the approval page, the context's grant for a direct call, the
     * job's grant at a tick. The implementation reads that grant through OAuth_Store::get_grant()
     * and answers the Remote_MCP_Events::DEFERRED_CONTEXT filter with its id and `policy_version`
     * for this one call only. It never derives the grant from the user, who can hold several. A
     * grant it can't read runs nothing.
     *
     * @param Call_Plan $plan     Frozen plan.
     * @param int       $index    Entry index, 0-based.
     * @param int       $user_id  User the entry runs as, the grant's user.
     * @param int       $grant_id Grant that authorized the work.
     * @return array `ok` (bool), `reason` (short code or null), `result` (the ability's result or
     *               null), `queued` (bool, true when the ability answered with a core batch job, which
     *               the job runner treats as a failure).
     */
    public function execute_plan_entry( Call_Plan $plan, int $index, int $user_id, int $grant_id ): array;
}
