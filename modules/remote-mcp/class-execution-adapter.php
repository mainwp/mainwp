<?php
/**
 * Remote MCP execution adapter interface.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns projected arguments, resolved targets and the preview into the frozen Call_Plan, including
 * the per-type update grouping. Implemented by `MCP_Execution_Adapter`.
 *
 * The build() method executes and fetches nothing: given a null preview it builds from the arguments
 * and targets alone, and for adapter `updates` it returns null, because per-site approval is never
 * rebuilt from aggregates. Previews for adapter `updates` come only from collect_preview(), and
 * only the approval gate calls that, wherever it runs an updates preview: a `confirm` first call
 * and its freshness check, the `dashboard` click and a `dashboard` retry.
 */
interface Execution_Adapter {

    /**
     * Build the plan.
     *
     * @param Remote_MCP_Context $context   Connection.
     * @param string             $ability   Ability name.
     * @param array              $arguments Projected arguments.
     * @param array|null         $targets   Target_Resolver result, or null for no site target.
     * @param array|null         $preview   Preview output, or null when there is none. For adapter
     *                                      `updates`, the collect_preview() result; one whose
     *                                      per-site records miss a target gives null.
     * @return Call_Plan|null Plan, or null on a build error (never a call).
     */
    public function build( Remote_MCP_Context $context, string $ability, array $arguments, ?array $targets, ?array $preview ): ?Call_Plan;

    /**
     * Complete per-site updates preview. Core's preview cuts its per-site records at the batch
     * threshold while its aggregates cover every site, so this runs core's
     * `mainwp/run-updates-v1` preview (`dry_run: true`) as the context's user, in chunks of at most
     * Remote_MCP_Settings::chunk_size() sites, and merges the chunks. The caller passes the preview
     * controls in `$arguments`; the implementation adds only each chunk's site selector.
     *
     * The result is core's preview envelope (`get_update_preview()` in
     * class-mainwp-abilities-updates.php), merged:
     *
     * - `dry_run`: true.
     * - `would_affect.sites`: every chunk's records in chunk order, never cut. Each record is
     *   `site_id`, `site_url`, `site_name`, `items` (`type` `core|plugin|theme|translation`,
     *   `slug`, `name`, `from`, `to`, `requested`, `flags` {`core`, `major` bool or null}) and
     *   `skipped` (the same item keys without `flags`, plus `reason`
     *   `ignored_site|ignored_global|ignored_site_flag`). As in core, a site with neither items nor
     *   skipped updates has no record, and a site core couldn't plan is in `errors` instead.
     * - `would_affect.by_item`: recomputed from the merged records, one row per type and slug,
     *   `type`, `slug`, `name`, `to` (distinct target versions), `site_count`, `flags`, in core's
     *   order (core, plugin, theme, translation, then slug).
     * - `would_affect.summary`: recomputed from the merged records: `site_count`, `item_count`,
     *   `skipped_count`, `has_core`, `has_major`, `has_unknown_version`, `requested_not_found`
     *   (requested slugs no chunk found); `all_sites` false, because the targets are explicit;
     *   `queued` and `truncated` false, because every chunk is under core's threshold and the
     *   module runs the chunks itself.
     * - `count`: the merged `summary.item_count`.
     * - `warnings`: every chunk's warnings, each once, in first-seen order.
     * - `errors`: every chunk's errors, in chunk order (`site_id`, `site_url`, `site_name`, `type`,
     *   `slug`, `code`, `message`).
     *
     * @param Remote_MCP_Context $context    Connection; the preview runs as its user.
     * @param string             $ability    Updates ability being approved.
     * @param array              $arguments  Projected arguments with the preview controls.
     * @param array|null         $target_ids Resolved site ids; null gives null.
     * @return array|null Merged preview, or null when any chunk fails or a target can't be covered.
     */
    public function collect_preview( Remote_MCP_Context $context, string $ability, array $arguments, ?array $target_ids ): ?array;
}
