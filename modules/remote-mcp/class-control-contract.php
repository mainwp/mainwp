<?php
/**
 * Remote MCP control contract interface.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The preview and execution control values of a tiered ability. Implemented by
 * `MCP_Control_Contracts`.
 *
 * A contract is an array:
 * - `source`: `audit`, `declared`, `derived` or `uncontracted`
 * - `preview`: `kind` (`dry_run`, `ability` or `plan`), `ability` (separate preview ability or
 *   null), `arg_map` (array or null), `controls` (exact control values, false and null included),
 *   `capture_token` (field holding the native preview token, or null)
 * - `execute`: `controls` (exact control values) and `native_token_field` (or null)
 * - `sensitive` (field names), `deferred` (bool), `credential_bearing` (bool)
 * - `gate_supplied` (field names the gate generates once per approval row, for example an
 *   idempotency `request_ref`; stripped from the published schema and client arguments, stored
 *   in `gate_values`, left out of `args_digest`)
 * - `compare_fields` (preview output fields compared when a native preview is rerun after a
 *   Dashboard approval; the `native_token_field` is excluded from freshness and `args_digest`)
 * - `tier` (`confirm` or `dashboard` for a declared contract, never `write`)
 */
interface Control_Contract {

    /**
     * Contract for an ability.
     *
     * @param Remote_MCP_Context $context Connection.
     * @param string             $ability Ability name.
     * @return array|null Contract, or null when none exists or can be derived ("needs a MainWP contract").
     */
    public function contract_for( Remote_MCP_Context $context, string $ability ): ?array;

    /**
     * The contract an extension declared through `mainwp_remote_mcp_extension_contracts`, after
     * validation, independent of any connection. The tier registry reads it for extension
     * abilities with no table entry.
     *
     * @param string $ability Ability name.
     * @return array|null Contract, or null when none is declared or it fails validation.
     */
    public function declared_contract( string $ability ): ?array;

    /**
     * Re-capture an expired native preview token for one entry of a frozen plan. The gate and job
     * ticks call it before execute_plan_entry() for token-bound entries.
     *
     * @param Call_Plan $plan    Frozen plan.
     * @param int       $index   Entry index.
     * @param int       $user_id User the entry runs as.
     * @return array|null `['plan' => Call_Plan, 'stale' => bool]` (the plan with the fresh token, and
     *                    whether the `compare_fields` changed), or null when it can't be refreshed.
     */
    public function refresh_native_token( Call_Plan $plan, int $index, int $user_id ): ?array;
}
