<?php
/**
 * Remote MCP tool catalog and tier tables.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard\Remote_MCP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.Arrays.MultipleStatementAlignment -- the tier tables align per row, not per file.

/**
 * The remote tool list and the audited tier tables.
 *
 * CORE_TIERS and EXTENSION_TIERS are those tables. MCP_Tier_Registry is the only reader; the gate
 * and the job runner see them through Tier_Registry::entry() and keep no copy.
 */
final class MCP_Tool_Catalog {

    /**
     * The one namespace served, the stdio server's default. Its prefix is dropped from tool names.
     *
     * @var string
     */
    const NAMESPACE_PREFIX = 'mainwp/';

    /**
     * The module's own tool. It is not an ability.
     *
     * @var string
     */
    const MODULE_TOOL = 'get_remote_job_status_v1';

    /**
     * Category of the module tool.
     *
     * @var string
     */
    const MODULE_CATEGORY = 'mainwp-remote-mcp';

    /**
     * Category of an ability that has none.
     *
     * @var string
     */
    const UNCATEGORIZED = 'uncategorized';

    /**
     * Categories core registers its abilities in. A `mainwp/` ability in one of them that the core
     * table doesn't list is tiered `dashboard`, never by its annotations.
     *
     * @var string[]
     */
    const CORE_CATEGORIES = array( 'mainwp-backups', 'mainwp-batch', 'mainwp-clients', 'mainwp-knowledge', 'mainwp-sites', 'mainwp-tags', 'mainwp-updates' );

    /**
     * Abilities that can't answer within the 90 s budget. Empty until a measurement
     * puts one here; they are neither listed nor callable.
     *
     * @var string[]
     */
    const EXCLUDED_ABILITIES = array();

    /**
     * Ability-owned controls the gate sets itself, never published or forwarded.
     *
     * @var string[]
     */
    const ABILITY_CONTROLS = array( 'confirm', 'dry_run', 'preview_token' );

    /**
     * Ability arguments AI apps neither see nor send. Updating past the pre-update backup check
     * stays a choice made on the Updates page.
     *
     * @var string[]
     */
    const HIDDEN_ARGUMENTS = array( 'ignore_backup_gate' );

    /**
     * Abilities that change who may do what (Team Control roles, users and their rights). They are
     * `dashboard` in every policy except Hands-off, whatever their annotations or a declared
     * contract claim.
     *
     * @var string[]
     */
    const AUTHORIZATION_ABILITIES = array(
        'mainwp/create-team-control-role-v1',
        'mainwp/delete-team-control-role-v1',
        'mainwp/delete-team-control-user-v1',
        'mainwp/reconcile-team-control-role-v1',
        'mainwp/replace-team-control-role-v1',
        'mainwp/replace-team-control-user-note-v1',
        'mainwp/replace-team-control-user-roles-v1',
    );

    /**
     * Calls that stay `dashboard` under Hands-off: add-site, the snippet replace, and an
     * update-site call that sets the URL or turns off TLS verification. Changing a site's URL and
     * then reconnecting sends that site's keys to whoever controls the new URL.
     *
     * The backup rows hold the recovery path: a stolen token confirms its own chat confirmation,
     * and restoring over a live database or deleting snapshots (directly, or by lowering a
     * retention the next sweep applies) is what it would use to leave the agency nothing to
     * recover from. Cancelling a restore while the Child copies files leaves the live site part
     * restored, and the Child doesn't put those files back.
     *
     * @var array ability => argument names, an empty list meaning every call.
     */
    const HANDS_OFF_FLOOR = array(
        'mainwp/add-site-v1'                         => array(),
        'mainwp/cancel-restore-v1'                   => array(),
        'mainwp/cleanup-site-backups-v1'             => array(),
        'mainwp/delete-backup-snapshot-v1'           => array(),
        'mainwp/delete-backup-task-v1'               => array(),
        'mainwp/replace-custom-dashboard-snippet-v1' => array(),
        'mainwp/restore-site-snapshot-v1'            => array(),
        'mainwp/rollback-restore-v1'                 => array(),
        'mainwp/update-backup-destination-v1'        => array( 'minimum_remote_retention_days' ),
        'mainwp/update-backup-settings-v1'           => array( 'on_demand_retention_count' ),
        'mainwp/update-backup-task-v1'               => array( 'retention' ),
        'mainwp/update-site-v1'                      => array( 'url', 'verify_certificate', 'ssl_version' ),
    );

    /**
     * Calls refused remotely in every policy, before any row, preview or execution. A backup task
     * call names the task, not its sites, so an approval can't freeze them: a task created with
     * groups covers whatever sites join those groups later, and saving any task edit re-stamps
     * the sites its groups hold at that moment. Both stay available in MainWP under Backups.
     *
     * @var array ability => [ reason code, argument names ]: an empty list refuses every call,
     *            otherwise a call that gives any of them a non-empty value.
     */
    const REMOTE_REFUSALS = array(
        'mainwp/create-backup-task-v1' => array( 'backup_task_groups', array( 'group_ids' ) ),
        'mainwp/update-backup-task-v1' => array( 'backup_task_update', array() ),
    );

    /**
     * Core abilities, native backups included.
     * name => [ tier, resolver, adapter, contract kind, conditional callback, sensitive fields ].
     * A conditional row's tier is its strictest, used when the callback can't decide.
     *
     * @var array
     */
    const CORE_TIERS = array(
        'mainwp/list-sites-v1'               => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-site-v1'                 => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/sync-sites-v1'               => array( 'write', 'sites', 'chunked_sites', 'plan', null, array() ),
        'mainwp/get-site-plugins-v1'         => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-site-themes-v1'          => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/add-site-v1'                 => array( 'dashboard', 'none', 'atomic', 'plan', null, array( 'adminpassword', 'uniqueid', 'http_pass' ) ),
        'mainwp/update-site-v1'              => array( 'dashboard', 'site', 'atomic', 'plan', 'tier_update_site', array( 'http_pass' ) ),
        'mainwp/delete-site-v1'              => array( 'dashboard', 'site', 'chunked_sites', 'dry_run', null, array() ),
        'mainwp/reconnect-site-v1'           => array( 'write', 'site', 'chunked_sites', 'plan', null, array() ),
        'mainwp/disconnect-site-v1'          => array( 'dashboard', 'site', 'chunked_sites', 'plan', null, array() ),
        'mainwp/suspend-site-v1'             => array( 'confirm', 'site', 'chunked_sites', 'plan', null, array() ),
        'mainwp/unsuspend-site-v1'           => array( 'write', 'site', 'chunked_sites', 'plan', null, array() ),
        'mainwp/check-site-v1'               => array( 'write', 'site', 'chunked_sites', 'plan', null, array() ),
        // The activation rows are declared destructive, so MCP clients confirm them before applying.
        'mainwp/activate-site-plugins-v1'    => array( 'confirm', 'site', 'chunked_sites', 'dry_run', null, array() ),
        'mainwp/deactivate-site-plugins-v1'  => array( 'confirm', 'site', 'chunked_sites', 'dry_run', null, array() ),
        'mainwp/delete-site-plugins-v1'      => array( 'dashboard', 'site', 'chunked_sites', 'dry_run', null, array() ),
        'mainwp/get-abandoned-plugins-v1'    => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/activate-site-theme-v1'      => array( 'confirm', 'site', 'chunked_sites', 'dry_run', null, array() ),
        'mainwp/delete-site-themes-v1'       => array( 'dashboard', 'site', 'chunked_sites', 'dry_run', null, array() ),
        'mainwp/get-abandoned-themes-v1'     => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-site-security-v1'        => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-site-changes-v1'         => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-site-client-v1'          => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-site-costs-v1'           => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/count-sites-v1'              => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-sites-basic-v1'          => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/reconnect-sites-v1'          => array( 'write', 'sites', 'chunked_sites', 'plan', null, array() ),
        'mainwp/disconnect-sites-v1'         => array( 'dashboard', 'sites', 'chunked_sites', 'plan', null, array() ),
        'mainwp/check-sites-v1'              => array( 'write', 'sites', 'chunked_sites', 'plan', null, array() ),
        'mainwp/suspend-sites-v1'            => array( 'confirm', 'sites', 'chunked_sites', 'plan', null, array() ),
        'mainwp/list-updates-v1'             => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/run-updates-v1'              => array( 'confirm', 'sites', 'updates', 'dry_run', null, array() ),
        'mainwp/list-ignored-updates-v1'     => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/set-ignored-updates-v1'      => array( 'write', 'site', 'atomic', 'plan', null, array() ),
        'mainwp/unignore-site-updates-v1'    => array( 'confirm', 'site', 'atomic', 'dry_run', null, array() ),
        'mainwp/get-site-updates-v1'         => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/update-site-core-v1'         => array( 'confirm', 'site', 'updates', 'dry_run', null, array() ),
        'mainwp/update-site-plugins-v1'      => array( 'confirm', 'site', 'updates', 'dry_run', null, array() ),
        'mainwp/update-site-themes-v1'       => array( 'confirm', 'site', 'updates', 'dry_run', null, array() ),
        'mainwp/update-site-translations-v1' => array( 'confirm', 'site', 'updates', 'dry_run', null, array() ),
        'mainwp/ignore-site-core-v1'         => array( 'write', 'site', 'atomic', 'plan', null, array() ),
        'mainwp/ignore-site-plugins-v1'      => array( 'write', 'site', 'atomic', 'plan', null, array() ),
        'mainwp/ignore-site-themes-v1'       => array( 'write', 'site', 'atomic', 'plan', null, array() ),
        'mainwp/update-all-v1'               => array( 'confirm', 'sites', 'updates', 'dry_run', null, array() ),
        'mainwp/get-batch-job-status-v1'     => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/list-clients-v1'             => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/count-clients-v1'            => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-client-v1'               => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/add-client-v1'               => array( 'confirm', 'none', 'atomic', 'plan', 'tier_selected_sites', array() ),
        'mainwp/update-client-v1'            => array( 'confirm', 'client', 'atomic', 'plan', 'tier_selected_sites', array() ),
        'mainwp/delete-client-v1'            => array( 'dashboard', 'client', 'atomic', 'dry_run', null, array() ),
        'mainwp/get-client-sites-v1'         => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/count-client-sites-v1'       => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-client-costs-v1'         => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/suspend-client-v1'           => array( 'confirm', 'client', 'atomic', 'plan', null, array() ),
        'mainwp/unsuspend-client-v1'         => array( 'write', 'client', 'atomic', 'plan', null, array() ),
        'mainwp/list-tags-v1'                => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-tag-v1'                  => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/add-tag-v1'                  => array( 'write', 'none', 'atomic', 'plan', null, array() ),
        'mainwp/update-tag-v1'               => array( 'write', 'tag', 'atomic', 'plan', null, array() ),
        'mainwp/delete-tag-v1'               => array( 'confirm', 'tag', 'atomic', 'dry_run', null, array() ),
        'mainwp/get-tag-sites-v1'            => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-tag-clients-v1'          => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-knowledge-record-v1'     => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-site-knowledge-v1'       => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-client-knowledge-v1'     => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/list-knowledge-v1'           => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/create-knowledge-record-v1'  => array( 'confirm', 'none', 'atomic', 'dry_run', null, array() ),
        'mainwp/update-knowledge-record-v1'  => array( 'confirm', 'none', 'atomic', 'dry_run', null, array() ),
        'mainwp/delete-knowledge-record-v1'  => array( 'confirm', 'none', 'atomic', 'dry_run', null, array() ),
        // Native backups. Every write needs dry_run or confirm,
        // so even the write rows carry the dry_run contract. No input takes a credential.
        'mainwp/get-backup-status-v1'                => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/list-backup-history-v1'              => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/list-backup-destinations-v1'         => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-backup-destination-v1'           => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-backup-settings-v1'              => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/list-backup-tasks-v1'                => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-backup-task-v1'                  => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-backup-task-run-v1'              => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-site-backup-progress-v1'         => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/list-backup-snapshots-v1'            => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-backup-snapshot-v1'              => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/list-restore-jobs-v1'                => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/get-restore-job-v1'                  => array( 'read', 'none', 'direct', 'none', null, array() ),
        // API Backups registers these two only while its module is on. They read no credentials.
        'mainwp/list-api-backups-providers-v1'       => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/list-api-backups-site-status-v1'     => array( 'read', 'none', 'direct', 'none', null, array() ),
        'mainwp/run-site-backup-v1'                  => array( 'confirm', 'site', 'atomic', 'dry_run', null, array() ),
        'mainwp/run-backup-task-v1'                  => array( 'confirm', 'none', 'atomic', 'dry_run', null, array() ),
        'mainwp/cancel-site-backup-v1'               => array( 'confirm', 'site', 'atomic', 'dry_run', null, array() ),
        'mainwp/reset-backup-task-run-v1'            => array( 'confirm', 'none', 'atomic', 'dry_run', null, array() ),
        'mainwp/cleanup-site-backups-v1'             => array( 'dashboard', 'site', 'atomic', 'dry_run', null, array() ),
        'mainwp/delete-backup-snapshot-v1'           => array( 'dashboard', 'site', 'atomic', 'dry_run', null, array() ),
        'mainwp/create-backup-task-v1'               => array( 'confirm', 'none', 'atomic', 'dry_run', null, array() ),
        'mainwp/update-backup-task-v1'               => array( 'dashboard', 'none', 'atomic', 'dry_run', null, array() ),
        'mainwp/delete-backup-task-v1'               => array( 'dashboard', 'none', 'atomic', 'dry_run', null, array() ),
        'mainwp/pause-backup-task-v1'                => array( 'confirm', 'none', 'atomic', 'dry_run', null, array() ),
        'mainwp/resume-backup-task-v1'               => array( 'write', 'none', 'atomic', 'dry_run', null, array() ),
        'mainwp/restore-site-snapshot-v1'            => array( 'dashboard', 'site', 'atomic', 'dry_run', null, array() ),
        'mainwp/cancel-restore-v1'                   => array( 'dashboard', 'none', 'atomic', 'dry_run', null, array() ),
        'mainwp/rollback-restore-v1'                 => array( 'dashboard', 'none', 'atomic', 'dry_run', null, array() ),
        'mainwp/test-backup-destination-v1'          => array( 'write', 'none', 'atomic', 'dry_run', null, array() ),
        'mainwp/set-default-backup-destination-v1'   => array( 'confirm', 'none', 'atomic', 'dry_run', null, array() ),
        'mainwp/update-backup-destination-v1'        => array( 'dashboard', 'none', 'atomic', 'dry_run', 'tier_backup_destination', array() ),
        'mainwp/delete-backup-destination-v1'        => array( 'dashboard', 'none', 'atomic', 'dry_run', null, array() ),
        'mainwp/update-backup-settings-v1'           => array( 'dashboard', 'none', 'atomic', 'dry_run', null, array() ),
    );

    /**
     * Extensions whose audited entries wait for a release: plugin file and the first version
     * that ships the abilities. UpdraftPlus has none yet: 5.2 registers its abilities only behind
     * a filter that defaults off, and no released Child advertises its operations.
     *
     * @var array
     */
    const EXTENSION_RELEASES = array(
        'clone'                 => array( 'mainwp-clone-extension/mainwp-clone-extension.php', '5.1' ),
        'woocommerce-shortcuts' => array( 'mainwp-woocommerce-shortcuts-extension/mainwp-woocommerce-shortcuts-extension.php', '5.1' ),
        'termageddon'           => array( 'termageddon-for-mainwp/termageddon-for-mainwp.php', '3.0' ),
        'updraftplus'           => array( 'mainwp-updraftplus-extension/mainwp-updraftplus-extension.php', null ),
    );

    /**
     * Released-extension abilities. The snippet replace and the two credential replaces carry a
     * tier like any other row; UpdraftPlus is inert.
     * name => [ outcome, tier, resolver, adapter, contract kind, conditional, sensitive,
     * gate-supplied fields, release key ]. Outcome `audit` is a tier with its contract;
     * `uncontracted` is a write the gate runs with controls derived from its live schema, until
     * the extension declares a contract.
     *
     * @var array
     */
    const EXTENSION_TIERS = array(
        // Cache Control v5.3.
        'mainwp/get-site-cache-control-status-v1'                => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/list-site-cache-control-statuses-v1'             => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/get-global-cache-control-configuration-v1'       => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/validate-cloudflare-credentials-v1'              => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/get-site-cache-purge-outcome-v1'                 => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/set-global-cache-control-policy-v1'              => array( 'audit', 'confirm', 'none', 'atomic', 'plan', null, array(), array(), null ),
        'mainwp/set-site-cache-control-policy-v1'                => array( 'audit', 'write', 'site', 'atomic', 'plan', null, array(), array(), null ),
        'mainwp/purge-site-cache-v1'                             => array( 'audit', 'confirm', 'site', 'atomic', 'plan', null, array(), array(), null ),
        // Clone, unreleased: enabling widens what Child admins can reach at the next sync.
        'mainwp/get-site-clone-policy-status-v1'                 => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), 'clone' ),
        'mainwp/set-clone-enabled-v1'                            => array( 'audit', 'dashboard', 'none', 'atomic', 'plan', 'tier_clone_enabled', array(), array(), 'clone' ),
        'mainwp/set-site-clone-participation-v1'                 => array( 'audit', 'dashboard', 'site', 'atomic', 'plan', 'tier_clone_participation', array(), array(), 'clone' ),
        // Custom Dashboard v5.2: a php snippet is eval()'d on every request, so replace is always
        // dashboard and plan-less (its `code` is writeOnly).
        'mainwp/get-custom-dashboard-snippet-metadata-v1'        => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/validate-custom-dashboard-php-v1'                => array( 'audit', 'write', 'none', 'atomic', 'plan', null, array( 'code' ), array(), null ),
        'mainwp/replace-custom-dashboard-snippet-v1'             => array( 'audit', 'dashboard', 'none', 'atomic', 'dry_run', null, array( 'code' ), array(), null ),
        'mainwp/clear-custom-dashboard-snippet-v1'               => array( 'audit', 'confirm', 'none', 'atomic', 'dry_run', null, array(), array(), null ),
        // Google Search Console v6.3: the gate owns `request_ref`, so a retry matches its row.
        'mainwp/list-google-search-console-connections-v1'       => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/list-google-search-console-properties-v1'        => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/get-site-google-search-console-mapping-v1'       => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/get-site-google-search-console-observation-v1'   => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/get-google-search-console-refresh-policy-v1'     => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/get-google-search-console-refresh-status-v1'     => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/replace-site-google-search-console-mapping-v1'   => array( 'audit', 'confirm', 'site', 'atomic', 'dry_run', null, array(), array( 'request_ref' ), null ),
        'mainwp/replace-google-search-console-refresh-policy-v1' => array( 'audit', 'confirm', 'none', 'atomic', 'dry_run', null, array(), array( 'request_ref' ), null ),
        'mainwp/request-site-google-search-console-refresh-v1'   => array( 'uncontracted', 'dashboard', 'site', 'atomic', 'none', null, array(), array( 'request_ref' ), null ),
        'mainwp/request-google-search-console-catalog-refresh-v1' => array( 'uncontracted', 'dashboard', 'none', 'atomic', 'none', null, array(), array( 'request_ref' ), null ),
        // Matomo v5.2.1.
        'mainwp/get-matomo-connection-v1'                        => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/replace-matomo-connection-v1'                    => array( 'audit', 'dashboard', 'none', 'atomic', 'dry_run', null, array( 'api_token' ), array(), null ),
        'mainwp/list-matomo-properties-v1'                       => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/get-site-matomo-assignment-v1'                   => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/preview-site-matomo-assignment-v1'               => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/replace-site-matomo-assignment-v1'               => array( 'audit', 'confirm', 'site', 'atomic', 'matomo_assignment', null, array(), array(), null ),
        'mainwp/get-site-matomo-observation-v1'                  => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/get-site-matomo-trend-v1'                        => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/request-matomo-property-refresh-v1'              => array( 'uncontracted', 'dashboard', 'none', 'atomic', 'none', null, array(), array(), null ),
        'mainwp/request-site-matomo-refresh-v1'                  => array( 'uncontracted', 'dashboard', 'site', 'atomic', 'none', null, array(), array(), null ),
        'mainwp/get-matomo-operation-v1'                         => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), null ),
        'mainwp/cancel-matomo-operation-v1'                      => array( 'audit', 'confirm', 'none', 'atomic', 'dry_run', null, array(), array(), null ),
        // UpdraftPlus 5.2, inert.
        'mainwp/get-updraftplus-site-v1'                         => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), 'updraftplus' ),
        'mainwp/get-updraftplus-policy-v1'                       => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), 'updraftplus' ),
        'mainwp/replace-updraftplus-policy-v1'                   => array( 'audit', 'dashboard', 'site', 'atomic', 'token', null, array(), array(), 'updraftplus' ),
        'mainwp/list-updraftplus-backups-v1'                     => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), 'updraftplus' ),
        'mainwp/get-updraftplus-backup-v1'                       => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), 'updraftplus' ),
        'mainwp/start-updraftplus-backup-v1'                     => array( 'audit', 'confirm', 'site', 'atomic', 'plan', null, array(), array(), 'updraftplus' ),
        'mainwp/get-updraftplus-operation-v1'                    => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), 'updraftplus' ),
        'mainwp/cancel-updraftplus-operation-v1'                 => array( 'audit', 'confirm', 'site', 'atomic', 'token', null, array(), array(), 'updraftplus' ),
        'mainwp/delete-updraftplus-backup-v1'                    => array( 'audit', 'dashboard', 'site', 'atomic', 'token', null, array(), array(), 'updraftplus' ),
        'mainwp/preview-updraftplus-restore-v1'                  => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), 'updraftplus' ),
        'mainwp/restore-updraftplus-backup-v1'                   => array( 'audit', 'dashboard', 'site', 'atomic', 'token', null, array(), array(), 'updraftplus' ),
        // WooCommerce Shortcuts 5.1, unreleased.
        'mainwp/get-woocommerce-shortcuts-status-v1'             => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), 'woocommerce-shortcuts' ),
        // Termageddon 3.0, no release tag.
        'mainwp/get-termageddon-connection-v1'                   => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), 'termageddon' ),
        'mainwp/get-termageddon-policy-sets-v1'                  => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), 'termageddon' ),
        'mainwp/replace-termageddon-credential-v1'               => array( 'audit', 'dashboard', 'none', 'atomic', 'token', null, array( 'api_key' ), array(), 'termageddon' ),
        'mainwp/disconnect-termageddon-v1'                       => array( 'audit', 'dashboard', 'none', 'atomic', 'token', null, array(), array(), 'termageddon' ),
        'mainwp/get-termageddon-site-policy-v1'                  => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), 'termageddon' ),
        'mainwp/replace-termageddon-site-policy-v1'              => array( 'audit', 'confirm', 'site', 'atomic', 'token', null, array(), array(), 'termageddon' ),
        'mainwp/list-termageddon-pages-v1'                       => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), 'termageddon' ),
        'mainwp/get-termageddon-page-v1'                         => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), 'termageddon' ),
        'mainwp/remove-termageddon-page-v1'                      => array( 'audit', 'dashboard', 'site', 'atomic', 'token', 'tier_termageddon_remove_page', array(), array(), 'termageddon' ),
        'mainwp/get-termageddon-operation-v1'                    => array( 'audit', 'read', 'none', 'direct', 'none', null, array(), array(), 'termageddon' ),
    );

    /**
     * Native-preview effects, excluding credentials, token renewal and observation times.
     * Termageddon credential previews generate a random target account generation; only their
     * policy and mapping counts are stable across renewals.
     *
     * A backup task call names only the task, so the approval freezes no sites; comparing the
     * preview's would_affect (the task's sites, its prune list, the snapshots and cloud copies a
     * delete removes) stales an approval once the task covers other sites or snapshots.
     *
     * @var array
     */
    const NATIVE_COMPARE_FIELDS = array(
        'mainwp/cleanup-site-backups-v1'            => array( 'would_affect' ),
        'mainwp/run-backup-task-v1'                 => array( 'would_affect' ),
        'mainwp/delete-backup-task-v1'              => array( 'would_affect' ),
        'mainwp/replace-site-matomo-assignment-v1'   => array( 'site_id', 'desired_state', 'would_change', 'cache_effect', 'conflict_state' ),
        'mainwp/replace-updraftplus-policy-v1'      => array( 'site_id', 'schedule_changed', 'policy_generation' ),
        'mainwp/cancel-updraftplus-operation-v1'    => array( 'operation_ref', 'state', 'quiescent' ),
        'mainwp/delete-updraftplus-backup-v1'       => array( 'backup_ref', 'component_count', 'locations' ),
        'mainwp/restore-updraftplus-backup-v1'      => array( 'backup_ref', 'component_count', 'state' ),
        'mainwp/replace-termageddon-credential-v1'  => array( 'policy_set_count', 'mappings_to_revalidate' ),
        'mainwp/disconnect-termageddon-v1'          => array( 'mappings_to_invalidate', 'pages_to_mark_stale' ),
        'mainwp/replace-termageddon-site-policy-v1' => array( 'action', 'target_mapping_generation' ),
        'mainwp/remove-termageddon-page-v1'         => array( 'deletion_mode', 'child_effect', 'target_generation' ),
    );

    /**
     * Text every non-read tool's description ends with, the same in every policy so published
     * descriptions never depend on the connection.
     *
     * @var string
     */
    const APPROVAL_TEXT = 'APPROVAL: This server decides how this change is approved. It may run directly, return a preview with a confirmation_token (show the preview to the user, then call again with user_confirmed: true and that confirmation_token after the user approves), or return an approval link the user must open on the MainWP Dashboard (give the user the link, then call again with the same arguments).';

    /**
     * Tier registry.
     *
     * @var Tier_Registry
     */
    private $registry;

    /**
     * Returns the registered abilities.
     *
     * @var callable
     */
    private $abilities;

    /**
     * Abilities excluded for time.
     *
     * @var string[]
     */
    private $excluded;

    /**
     * Constructor.
     *
     * @param Tier_Registry $registry  Tier registry.
     * @param callable      $abilities Returns the registered abilities.
     * @param string[]      $excluded  Abilities over the time budget.
     */
    public function __construct( Tier_Registry $registry, callable $abilities, array $excluded ) {
        $this->registry  = $registry;
        $this->abilities = $abilities;
        $this->excluded  = $excluded;
    }

    /**
     * The stdio tool name (`src/naming.ts:49`): the primary namespace dropped, hyphens to underscores.
     *
     * @param string $ability Ability name.
     * @return string
     */
    public static function tool_name( string $ability ): string {
        $rest = 0 === strpos( $ability, self::NAMESPACE_PREFIX ) ? substr( $ability, strlen( self::NAMESPACE_PREFIX ) ) : $ability;
        return str_replace( '-', '_', $rest );
    }

    /**
     * An ability's annotations, an empty array when it has none in array form.
     *
     * @param object $ability Ability.
     * @return array
     */
    public static function annotations( $ability ): array {
        $meta = is_object( $ability ) && method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();
        return is_array( $meta ) && isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
    }

    /**
     * Read tier by annotation: literal `readonly: true` and `destructive: false`, as stdio's
     * readOnlyHint (`tool-schema.ts:484`) and classifyDestructive() (`policy.ts:76`).
     *
     * @param array $annotations Annotations.
     * @return bool
     */
    public static function is_valid_read( array $annotations ): bool {
        return true === ( $annotations['readonly'] ?? null ) && false === ( $annotations['destructive'] ?? null );
    }

    /**
     * A write with valid annotations: `readonly: false` and a literal boolean `destructive`. Only
     * these can take the uncontracted path; anything malformed is unavailable.
     *
     * @param array $annotations Annotations.
     * @return bool
     */
    public static function is_valid_write( array $annotations ): bool {
        return false === ( $annotations['readonly'] ?? null ) && is_bool( $annotations['destructive'] ?? null );
    }

    /**
     * Core table entry.
     *
     * @param string $ability Ability name.
     * @return array|null
     */
    public static function core_entry( string $ability ): ?array {
        if ( ! isset( self::CORE_TIERS[ $ability ] ) ) {
            return null;
        }
        list( $tier, $resolver, $adapter, $kind, $conditional, $sensitive ) = self::CORE_TIERS[ $ability ];
        return self::entry( 'core', $tier, $resolver, $adapter, $kind, $conditional, $sensitive, array(), 'audited_core', self::NATIVE_COMPARE_FIELDS[ $ability ] ?? array() );
    }

    /**
     * Audited extension entry, before the registry applies a declared contract, the live annotations or
     * the release check.
     *
     * @param string $ability Ability name.
     * @return array|null
     */
    public static function extension_entry( string $ability ): ?array {
        if ( ! isset( self::EXTENSION_TIERS[ $ability ] ) ) {
            return null;
        }
        list( $outcome, $tier, $resolver, $adapter, $kind, $conditional, $sensitive, $gate_supplied, $release ) = self::EXTENSION_TIERS[ $ability ];
        $entry                = self::entry( $outcome, $tier, $resolver, $adapter, $kind, $conditional, $sensitive, $gate_supplied, 'uncontracted' === $outcome ? 'uncontracted' : 'audited_extension', self::NATIVE_COMPARE_FIELDS[ $ability ] ?? array() );
        $entry['min_version'] = null === $release ? null : self::EXTENSION_RELEASES[ $release ][1];
        return $entry;
    }

    /**
     * Release key of an extension entry, or null for a released extension.
     *
     * @param string $ability Ability name.
     * @return string|null
     */
    public static function extension_release( string $ability ): ?string {
        return self::EXTENSION_TIERS[ $ability ][8] ?? null;
    }

    /**
     * A Tier_Registry entry in its fixed shape.
     *
     * @param string      $source         Source.
     * @param string      $tier           Tier.
     * @param string      $resolver       Resolver type.
     * @param string      $adapter        Adapter type.
     * @param string      $kind           Contract kind: `none`, `plan`, `dry_run`, `token` or `matomo_assignment`.
     * @param string|null $conditional    Conditional-tier callback.
     * @param array       $sensitive      Sensitive fields.
     * @param array       $gate_supplied  Gate-supplied fields.
     * @param string      $reason         Reason code.
     * @param array       $compare_fields Stable preview effect fields.
     * @return array
     */
    public static function entry( string $source, string $tier, string $resolver, string $adapter, string $kind, ?string $conditional, array $sensitive, array $gate_supplied, string $reason, array $compare_fields = array() ): array {
        $uncomparable = in_array( $kind, array( 'token', 'matomo_assignment' ), true ) && empty( $compare_fields );
        $credential   = ! empty( $sensitive );
        return array(
            'source'             => $uncomparable ? 'refused' : $source,
            'tier'               => $tier,
            'conditional'        => $conditional,
            'contract'           => $uncomparable ? null : self::contract( $kind, $source, $tier, $sensitive, $gate_supplied, $compare_fields ),
            'resolver'           => $resolver,
            'adapter'            => $adapter,
            'sensitive'          => $sensitive,
            'deferred'           => false,
            'credential_bearing' => $credential,
            'gate_supplied'      => $gate_supplied,
            'compare_fields'     => $compare_fields,
            'min_version'        => null,
            'inert'              => false,
            'reason'             => $uncomparable ? 'missing_effect_fields' : $reason,
        );
    }

    /**
     * Control contract for an audited entry, in the Control_Contract shape. Reads and uncontracted
     * entries have none: the gate derives an uncontracted write's execution controls from its
     * live schema.
     *
     * @param string $kind           Contract kind.
     * @param string $source         Entry source.
     * @param string $tier           Tier.
     * @param array  $sensitive      Sensitive fields.
     * @param array  $gate_supplied  Gate-supplied fields.
     * @param array  $compare_fields Stable preview effect fields.
     * @return array|null
     */
    private static function contract( string $kind, string $source, string $tier, array $sensitive, array $gate_supplied, array $compare_fields ): ?array {
        $preview = array(
            'kind'          => 'plan',
            'ability'       => null,
            'arg_map'       => null,
            'controls'      => array(),
            'capture_token' => null,
        );
        $execute = array(
            'controls'           => array(),
            'native_token_field' => null,
        );
        switch ( $kind ) {
            case 'plan':
                break;
            case 'dry_run':
                $preview['kind']     = 'dry_run';
                $preview['controls'] = array( 'dry_run' => true );
                $execute['controls'] = array(
                    'dry_run' => false,
                    'confirm' => true,
                );
                break;
            case 'token':
                $preview['kind']          = 'dry_run';
                $preview['controls']      = array(
                    'dry_run'       => true,
                    'confirm'       => false,
                    'preview_token' => null,
                );
                $preview['capture_token'] = 'preview_token';
                $execute                  = array(
                    'controls'           => array(
                        'dry_run' => false,
                        'confirm' => true,
                    ),
                    'native_token_field' => 'preview_token',
                );
                break;
            case 'matomo_assignment':
                $preview = array(
                    'kind'          => 'ability',
                    'ability'       => 'mainwp/preview-site-matomo-assignment-v1',
                    'arg_map'       => array(
                        'site_id_or_domain' => 'site_id_or_domain',
                        'property_ref'      => 'property_ref',
                        'if_match'          => 'if_match',
                    ),
                    'controls'      => array(),
                    'capture_token' => 'preview_token',
                );
                $execute = array(
                    'controls'           => array(
                        'dry_run' => false,
                        'confirm' => true,
                    ),
                    'native_token_field' => 'preview_token',
                );
                break;
            default:
                return null;
        }
        return array(
            'source'             => 'core' === $source ? 'audit' : $source,
            'preview'            => $preview,
            'execute'            => $execute,
            'sensitive'          => $sensitive,
            'deferred'           => false,
            'credential_bearing' => ! empty( $sensitive ),
            'gate_supplied'      => $gate_supplied,
            'compare_fields'     => $compare_fields,
            'tier'               => $tier,
        );
    }

    /**
     * Tier of a value-conditional entry for these projected arguments.
     * Anything the callback doesn't recognize gets the strictest tier.
     *
     * @param string $callback  Callback name from the entry.
     * @param array  $arguments Projected arguments.
     * @return string|null Null for an unknown callback, which makes the call unavailable.
     */
    public static function conditional_tier( string $callback, array $arguments ): ?string {
        switch ( $callback ) {
            case 'tier_update_site':
                foreach ( array( 'url', 'verify_certificate', 'ssl_version', 'http_user', 'http_pass' ) as $field ) {
                    if ( array_key_exists( $field, $arguments ) ) {
                        return 'dashboard';
                    }
                }
                // It suspends without firing mainwp_site_suspended, so it isn't plain suspend-site.
                return array_key_exists( 'suspended', $arguments ) ? 'confirm' : 'write';
            case 'tier_selected_sites':
                // Attaching sites takes them away from the client that had them.
                return array_key_exists( 'selected_sites', $arguments ) ? 'confirm' : 'write';
            case 'tier_backup_destination':
                // Zero days removes the protection that keeps older remote copies past task retention.
                return array_key_exists( 'minimum_remote_retention_days', $arguments ) ? 'dashboard' : 'confirm';
            case 'tier_clone_enabled':
                return false === ( $arguments['enabled'] ?? null ) ? 'write' : 'dashboard';
            case 'tier_clone_participation':
                return false === ( $arguments['allowed'] ?? null ) ? 'write' : 'dashboard';
            case 'tier_termageddon_remove_page':
                return 'unlink_local' === ( $arguments['deletion_mode'] ?? null ) ? 'confirm' : 'dashboard';
        }
        return null;
    }

    /**
     * Whether a call stays `dashboard` under Hands-off.
     *
     * @param string $ability   Ability name.
     * @param array  $arguments Projected arguments.
     * @return bool
     */
    public static function hands_off_floor( string $ability, array $arguments ): bool {
        if ( ! isset( self::HANDS_OFF_FLOOR[ $ability ] ) ) {
            return false;
        }
        $fields = self::HANDS_OFF_FLOOR[ $ability ];
        if ( empty( $fields ) ) {
            return true;
        }
        return ! empty( array_intersect( $fields, array_keys( $arguments ) ) );
    }

    /**
     * Reason code when a call is refused remotely (REMOTE_REFUSALS), or null.
     *
     * @param string     $ability   Ability name.
     * @param array|null $arguments Projected arguments; null when a stored row has none to read.
     * @return string|null
     */
    public static function refusal( string $ability, ?array $arguments ): ?string {
        if ( ! isset( self::REMOTE_REFUSALS[ $ability ] ) ) {
            return null;
        }
        list( $reason, $fields ) = self::REMOTE_REFUSALS[ $ability ];
        if ( empty( $fields ) || null === $arguments ) {
            return $reason;
        }
        foreach ( $fields as $field ) {
            if ( ! empty( $arguments[ $field ] ) ) {
                return $reason;
            }
        }
        // create-backup-task resolves a domain again when it runs, so the approved args digest
        // wouldn't hold the site fixed if the domain moves to another site. Site IDs do.
        if ( 'mainwp/create-backup-task-v1' === $ability ) {
            foreach ( (array) ( $arguments['site_ids_or_domains'] ?? array() ) as $site ) {
                if ( ! is_int( $site ) ) {
                    return 'backup_task_domains';
                }
            }
        }
        return null;
    }

    /**
     * Reason code when a preview can't show everything the call would delete, or null. Past the
     * cap, more snapshots or cloud copies leave the listed part, and so the approval's baseline,
     * unchanged. A preview without the expected fields counts as cut.
     *
     * @param string $ability Ability name.
     * @param mixed  $preview Preview.
     * @return string|null
     */
    public static function preview_refusal( string $ability, $preview ): ?string {
        if ( 'mainwp/delete-backup-task-v1' !== $ability ) {
            return null;
        }
        $effect = is_array( $preview ) && is_array( $preview['would_affect'] ?? null ) ? $preview['would_affect'] : array();
        if ( false !== ( $effect['snapshots_truncated'] ?? null ) || false !== ( $effect['cloud_copies_truncated'] ?? null ) ) {
            return 'backup_preview_capped';
        }
        return null;
    }

    /**
     * Every remotely exposable tool: each `mainwp/` ability with `show_in_rest`, plus the
     * module tool. Context-free: no scope, switch or user filter.
     *
     * @return array Rows: the catalog keys plus `ability_object` and `entry` for the executor.
     */
    public function rows(): array {
        $by_tool = array();
        foreach ( (array) call_user_func( $this->abilities ) as $ability ) {
            if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
                continue;
            }
            $name = (string) $ability->get_name();
            if ( 0 !== strpos( $name, self::NAMESPACE_PREFIX ) || true !== $ability->get_meta_item( 'show_in_rest' ) ) {
                continue;
            }
            $by_tool[ self::tool_name( $name ) ][] = $ability;
        }

        $rows = array( $this->module_row() );
        foreach ( $by_tool as $tool => $abilities ) {
            // A collision never picks one: both are dropped and recorded.
            $collides = count( $abilities ) > 1 || self::MODULE_TOOL === $tool;
            foreach ( $abilities as $ability ) {
                $rows[] = $collides ? $this->row( $tool, $ability, null, 'not_available', 'name_collision' ) : $this->classify( $tool, $ability );
            }
        }
        return $rows;
    }

    /**
     * Catalog row for one ability.
     *
     * @param string $tool    Tool name.
     * @param object $ability Ability.
     * @return array
     */
    private function classify( string $tool, $ability ): array {
        $name = (string) $ability->get_name();
        if ( in_array( $name, $this->excluded, true ) ) {
            return $this->row( $tool, $ability, null, 'not_available', 'excluded_slow' );
        }
        $entry = $this->registry->entry( $name );
        if ( null === $entry ) {
            return $this->row( $tool, $ability, null, 'not_available', 'invalid_annotations' );
        }
        if ( 'refused' === $entry['source'] ) {
            return $this->row( $tool, $ability, $entry, 'not_available', (string) $entry['reason'] );
        }
        if ( ! empty( $entry['inert'] ) ) {
            return $this->row( $tool, $ability, $entry, 'not_available_yet', (string) $entry['reason'] );
        }
        if ( 'uncontracted' === $entry['source'] ) {
            return $this->row( $tool, $ability, $entry, 'not_reviewed', (string) $entry['reason'] );
        }
        return $this->row( $tool, $ability, $entry, 'available', (string) $entry['reason'] );
    }

    /**
     * Build a row.
     *
     * @param string      $tool         Tool name.
     * @param object|null $ability      Ability.
     * @param array|null  $entry        Registry entry.
     * @param string      $availability Availability.
     * @param string      $reason       Reason code.
     * @return array
     */
    private function row( string $tool, $ability, ?array $entry, string $availability, string $reason ): array {
        $category = null === $ability ? self::MODULE_CATEGORY : (string) $ability->get_category();
        return array(
            'tool_name'      => $tool,
            'ability'        => null === $ability ? null : (string) $ability->get_name(),
            'category'       => '' === $category ? self::UNCATEGORIZED : $category,
            'standard_tier'  => null === $entry || 'refused' === $entry['source'] ? null : $entry['tier'],
            'availability'   => $availability,
            'reason'         => $reason,
            'ability_object' => $ability,
            'entry'          => $entry,
        );
    }

    /**
     * Row of the module tool.
     *
     * @return array
     */
    private function module_row(): array {
        $row                  = $this->row( self::MODULE_TOOL, null, null, 'available', 'module_tool' );
        $row['standard_tier'] = 'read';
        return $row;
    }

    /**
     * MCP tool descriptor for an available row. Nothing in it depends on the connection, its
     * policy or the call's arguments: ChatGPT freezes schemas until an admin refreshes them.
     *
     * @param array $row Catalog row.
     * @return array
     */
    public static function descriptor( array $row ): array {
        if ( null === $row['ability_object'] ) {
            return self::job_status_descriptor();
        }
        $ability      = $row['ability_object'];
        $tier         = (string) $row['standard_tier'];
        $read         = 'read' === $tier;
        $annotations  = self::annotations( $ability );
        $label        = str_replace( '-', ' ', (string) preg_replace( '/^mainwp-/', '', (string) $row['category'] ) );
        $description  = '[' . $label . '] ' . (string) $ability->get_description();
        $description .= $read ? ' Read-only. Safe to call without confirmation.' : "\n\n" . self::APPROVAL_TEXT;

        $descriptor = array(
            'name'            => $row['tool_name'],
            'description'     => $description,
            'inputSchema'     => self::published_schema( (array) $ability->get_input_schema(), $row['entry']['gate_supplied'] ?? array(), $read ),
            'annotations'     => array(
                'title'           => (string) $ability->get_label(),
                'readOnlyHint'    => $read,
                'destructiveHint' => in_array( $tier, array( 'confirm', 'dashboard' ), true ),
                'idempotentHint'  => true === ( $annotations['idempotent'] ?? null ),
                'openWorldHint'   => true,
            ),
            'securitySchemes' => self::security_schemes( $read ),
        );
        if ( 'confirm' === $tier ) {
            // Claude Code asks the user before every call of a tool marked this way.
            $descriptor['_meta'] = array( 'anthropic/requiresUserInteraction' => true );
        }
        return $descriptor;
    }

    /**
     * The module tool's frozen descriptor, pinned by the fixture mcp/job-status-tool.json.
     *
     * @return array
     */
    public static function job_status_descriptor(): array {
        return array(
            'name'            => self::MODULE_TOOL,
            'description'     => '[remote mcp] Get the progress of a change this connection started as a background job: its status, how many entries are done out of the total, the sites the running entry acts on, and the reason when it was cancelled. Only jobs you started are visible. Read-only. Safe to call without confirmation.',
            'inputSchema'     => array(
                'type'                 => 'object',
                'properties'           => array(
                    'job_id' => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                ),
                'required'             => array( 'job_id' ),
                'additionalProperties' => false,
            ),
            'annotations'     => array(
                'title'           => 'Get remote job status',
                'readOnlyHint'    => true,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => true,
            ),
            'securitySchemes' => self::security_schemes( true ),
        );
    }

    /**
     * Per-tool scope declaration ChatGPT needs for step-up.
     *
     * @param bool $read Whether the tool is read tier.
     * @return array
     */
    private static function security_schemes( bool $read ): array {
        return array(
            array(
                'type'   => 'oauth2',
                'scopes' => array( $read ? OAuth_Store::SCOPE_READ : OAuth_Store::SCOPE_WRITE ),
            ),
        );
    }

    /**
     * Published input schema: the ability's own, minus the controls the gate sets, the hidden
     * arguments and the gate-supplied fields, plus the two optional confirmation fields on every non-read tool.
     *
     * @param array $schema        Ability input schema.
     * @param array $gate_supplied Gate-supplied fields.
     * @param bool  $read          Whether the tool is read tier.
     * @return array
     */
    public static function published_schema( array $schema, array $gate_supplied, bool $read ): array {
        if ( empty( $schema ) ) {
            $schema = array( 'type' => 'object' );
        }
        // Core abilities allow omitted input with array( 'object', 'null' ), but MCP clients reject the whole tool list unless the type is exactly "object".
        if ( is_array( $schema['type'] ?? null ) && in_array( 'object', $schema['type'], true ) ) {
            $schema['type'] = 'object';
        }
        $hidden     = array_merge( self::ABILITY_CONTROLS, self::HIDDEN_ARGUMENTS, $gate_supplied );
        $properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
        foreach ( $hidden as $field ) {
            unset( $properties[ $field ] );
        }
        if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
            $required = array_values( array_diff( $schema['required'], $hidden ) );
            if ( empty( $required ) ) {
                unset( $schema['required'] );
            } else {
                $schema['required'] = $required;
            }
        }
        if ( ! $read ) {
            $properties['user_confirmed']     = array(
                'type'        => 'boolean',
                'description' => 'Set to true, together with confirmation_token, only after the user approved the preview this tool returned.',
            );
            $properties['confirmation_token'] = array(
                'type'        => 'string',
                'description' => 'The confirmation_token from this tool\'s preview response; required alongside user_confirmed: true.',
            );
        }
        if ( isset( $schema['properties'] ) || ! $read ) {
            // An empty PHP array would publish as a JSON list, which isn't a schema.
            $schema['properties'] = empty( $properties ) ? new \stdClass() : $properties;
        }
        return $schema;
    }
}
