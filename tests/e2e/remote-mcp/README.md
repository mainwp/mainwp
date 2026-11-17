# Remote MCP end-to-end harness

Playwright checks for the Dashboard's remote MCP connector, run against deployed copies on Local sites over real HTTPS. The harness is opt-in and has no CI job.

Two Playwright projects:

- `selftest` runs offline. It checks the harness itself: URL forms, PKCE, the bearer proxy, the conformance baselines, and the verdict each E2E check uses to decide pass or fail.
- `e2e` runs the named checks against the sites below. It runs serially, because the checks share one Dashboard.

## Sites

Use sacrificial Local sites only. Each one runs a real copy of the plugin in its plugins directory (copied with rsync, never symlinked) with the connector turned on in the panel.

| Variable | Site |
|---|---|
| `RMCP_E2E_ROOT_URL` | Root install, pretty permalinks. Most checks and the conformance suite run here |
| `RMCP_E2E_SUBDIR_URL` | Subdirectory install, for path-append discovery and Claude Code |
| `RMCP_E2E_PLAIN_URL` | Plain-permalink install, for the query endpoints, kill switch and Claude Code |
| `RMCP_E2E_CLONE_URL` | Clone of the root install with a different `home` |

All four must be `https` URLs without a trailing slash.

| Variable | Purpose |
|---|---|
| `RMCP_E2E_ADMIN_USER`, `RMCP_E2E_ADMIN_PASSWORD` | Login used on every site. The user needs `manage_options` and no Team Control limits |
| `RMCP_E2E_ROOT_MU_PLUGINS_DIR` | Absolute path of the root install's `wp-content/mu-plugins`. Checks copy a harness plugin there and remove it when they finish |
| `RMCP_E2E_PLAIN_MU_PLUGINS_DIR` | Absolute path of the plain install's `wp-content/mu-plugins`, used for its kill-switch check |
| `RMCP_E2E_CLONE_SYNC_CMD` | Shell command that copies the root install's database to the clone and rewrites its `home`, the way a staging tool would. The clone check runs it after it connects |
| `RMCP_E2E_ROOT_WP_PATH`, `RMCP_E2E_CLONE_WP_PATH` | Local WordPress directories used for independent clone inspection with the Dashboard plugin skipped |
| `RMCP_E2E_WP_CLI` | WP-CLI executable, default `wp`. Clone proof passes credentials over stdin and retains only keyed fingerprints |
| `RMCP_E2E_PANEL_PATH` | Panel path, default `/wp-admin/admin.php?page=mainwp-mcp&tab=direct` |
| `RMCP_E2E_READ_TOOL` | Completed read to validate, default `count_sites_v1`; `list_tags_v1` is also supported. Other names fail until their payload contract is added. The write tool is always `add_tag_v1`, because the step-up check looks for its tag with `list_tags_v1` |
| `RMCP_E2E_CLAUDE_MODEL` | Model for the Claude Code print-mode call, default `haiku` |
| `RMCP_E2E_BROWSER_CHANNEL` | For example `chrome`, to use an installed browser instead of Playwright's |

Pass credentials through the environment. Don't write them into a file in this directory.

A check with a missing variable fails with `... is not set`. It is never skipped.

## Running

```sh
cd tests/e2e/remote-mcp
npm ci
npx playwright install chromium   # skip when RMCP_E2E_BROWSER_CHANNEL is set
npm run selftest
bash redcheck.sh
npm run e2e
npx playwright test --project=e2e specs/conformance.spec.ts
```

TLS is verified against Node's bundled roots plus the system store, which is where Local's certificate is trusted. Nothing turns verification off.

The Dashboard makes no outbound requests during these checks. The Claude Code check needs internet access for the `claude` CLI itself. It also needs `tmux` and a logged-in `claude`.

## What the checks leave behind

- Connections for the harness admin, all with "View only" access. The conformance server checks revoke theirs. Use "Disconnect all my AI apps" in the panel to clear the rest.
- The write call on a read-only grant must not create its tag. If it does, the check fails and the tag stays.
- The Claude Code check adds a temporary server name, logs it out when it finishes and deletes its temporary project.
- Results under `output/`, which git ignores. Server conformance `checks.json` files can contain tool output. Authorization checks run in memory and save only sanitized IDs and statuses; callback URLs and token details are discarded.

## Conformance baselines

The suite is pinned to `@modelcontextprotocol/conformance` `0.2.0-alpha.12`. Bumping it is a deliberate change that reruns the checks and gets the baselines reviewed again.

`conformance/baseline-<revision>.yml` lists the expected failures for one served revision. An entry is allowed only for:

- (a) a capability v1 doesn't declare
- (b) a fixture tool or prompt that a real server doesn't have
- (c) a scenario that would only test the bearer proxy

Every entry names one check (`scenario:check-id`), with its class and a reason. Whole scenarios and wire-schema checks cannot be excluded. The `selftest` project enforces these rules. Any other failure is a finding for review, not a new baseline entry.

The suite exits 1 for a stale entry too, so a scenario that starts passing has to come out of the baseline. A pass means the applicable checks pass against a reviewed baseline. It does not mean the server is conformant.

The mutation check requires 24 helper mutations and nine workflow mutations to produce assertion failures, then runs the restored self-test project. A missed mutation, missing check, or failing restored project exits nonzero. This is offline evidence; live E2E coverage still requires the deployed sites.

The clone command must preserve the module secret, grant and token rows while changing the stored home. Before any clone HTTP request, a separate WP-CLI process compares fresh keyed fingerprints of the source and cloned identity and grant/token state. It suppresses host-command output and never returns credentials or stored token hashes.
