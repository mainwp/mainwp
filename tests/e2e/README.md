# Remote MainWP E2E (Playwright)

Opt-in browser tests against the **real** remote testbed (`dev.mainwps.com` + its
disposable children). They drive the live dashboard UI so the dashboard makes the
real outbound cURL + RSA + TLS calls to children — the path the mocked phpunit
suites never exercise.

This is **Tier 2**. Tier 1 (pure-PHP reachability / latency / TLS, no browser) is
`tests/integration/`, configured by `phpunit.integration.xml.dist`.

## What runs against what

The branch these tests live on does not control what is tested. The dashboard
under test is whatever build is **deployed** to `dev.mainwps.com`, and the child
behavior is whatever is deployed to the children. To test native-backups, those
sites must be running the native-backups builds.

## Prerequisites

1. Export the `REMOTE_*` variables in the shell that runs the suite (for example
   by sourcing a `.env` file):
   - `REMOTE_DASH_URL` (for example `https://dev.mainwps.com`), `REMOTE_DASH_USER`,
     `REMOTE_DASH_PASS`
   - `REMOTE_CHILD_USER`, `REMOTE_CHILD_PASS` (shared across children)
   - `REMOTE_TEST_CHILD_URLS` (comma-separated handful of disposable children)
   - `REMOTE_TEST_BACKUP_CHILD_URL` (one small child for backup/restore)
   - `REMOTE_TEST_RUN_DESTRUCTIVE` (set to `1` to run the backup/restore round
     trip)
   - `REMOTE_TEST_TOGGLE_PLUGIN` (default `hello.php`)
   - `REMOTE_TEST_TOGGLE_CHILD_URL` (optional) child for the plugin toggle
     tests; the toggle plugin must be installed there. Defaults to the first
     `REMOTE_TEST_CHILD_URLS` entry.
   - `REMOTE_TEST_BREAK_CHILD_URL` (optional) child whose MainWP Child plugin
     the connection-break test deactivates. Defaults to the first
     `REMOTE_TEST_CHILD_URLS` entry. Keep it off the backup child.
   - `REMOTE_TEST_KEEP_SNAPSHOTS` (optional, default 2) — completed on-demand
     snapshots the post-suite cleanup keeps per test child
2. Install the toolchain (one time):
   ```bash
   cd tests/e2e
   npm install
   npx playwright install chromium
   ```

## Run

With the `REMOTE_*` variables exported:

```bash
cd tests/e2e
npx playwright test
npx playwright show-report
```

Specs self-skip when their `REMOTE_*` vars are unset, so an unconfigured machine
is a clean no-op.

## Post-suite cleanup

Backup runs leave weight on the shared dashboard server: real snapshots from the
destructive spec, and partial files whenever a run is interrupted. Nothing on a
low-traffic host reclaims those (retention drains on WP-Cron), so the suite
cleans up after itself: `zz-cleanup.spec.ts` runs **last** (single worker,
alphabetical file order) and calls the dashboard's `mainwp_snapshots_cleanup`
endpoint for every configured test child — leftover folders from interrupted
backups are removed first, then completed on-demand snapshots beyond
`REMOTE_TEST_KEEP_SNAPSHOTS` are pruned (server floors the keep at 1, so a child
always keeps its newest restorable backup). The deployed dashboard build must
include the cleanup endpoint; the spec fails with an explicit message when it
predates the feature.

## Safety model

All target children are disposable. Three kinds of test change a child:

- The backup/restore round trip (`real-path.spec.ts`) drives a real restore
  that replaces the files and database of `REMOTE_TEST_BACKUP_CHILD_URL` with
  the snapshot the run just created. It runs only with
  `REMOTE_TEST_RUN_DESTRUCTIVE=1`.
- The connection-break test (`break-recover.spec.ts`) deactivates MainWP Child
  on `REMOTE_TEST_BREAK_CHILD_URL`, checks that the dashboard sees the site
  disconnected, then reactivates the plugin and reconnects the site. A
  `finally` block reactivates the plugin if the test fails partway.
  `REMOTE_TEST_RUN_DESTRUCTIVE` does not gate it: it runs whenever the
  dashboard and child credentials are set.
- The plugin toggle tests (`break-recover.spec.ts`) switch one non-critical
  plugin on `REMOTE_TEST_TOGGLE_CHILD_URL` off and back on, restoring it in a
  `finally` block.

None of them touches anything outside its configured target. Server-level
breaks (500, expired cert, host down) are out of scope here because they need
host access the testbed does not grant.

## Selectors

The MainWP-specific selectors live in `lib/dashboard.ts`, so a changed MainWP
screen is fixed in one file. The sync, backup/restore, uptime monitor row and
dashboard reflection tests run as normal tests against the live DOM. The only
`test.fixme` left is the legacy Run Now in `legacy-backup.spec.ts`, kept off on
purpose because it starts a real full backup on a shared dashboard.

## Layout

```
tests/e2e/
  package.json            @playwright/test
  playwright.config.ts    timeouts, single worker, html report
  lib/
    env.ts                REMOTE_* parsing + *Configured gates
    auth.ts               wpLogin() against wp-login.php
    dashboard.ts          MainWP page object and selectors
  tests/
    real-path.spec.ts     login + Manage Sites, sync all sites, backup then restore
    uim.spec.ts           uptime page loads, monitor rows show real status
    break-recover.spec.ts plugin toggle, connection break + reconnect, dashboard reflection
    legacy-backup.spec.ts legacy Backups page loads (Run Now left as fixme)
    zz-cleanup.spec.ts    post-suite snapshot cleanup, runs last
```
