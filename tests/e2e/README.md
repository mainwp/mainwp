# Remote MainWP E2E (Playwright)

Opt-in browser tests against the **real** remote testbed (`dev.mainwps.com` + its
disposable children). They drive the live dashboard UI so the dashboard makes the
real outbound cURL + RSA + TLS calls to children — the path the mocked phpunit
suites never exercise.

This is **Tier 2**. Tier 1 (pure-PHP reachability / latency / TLS, no browser) is
`tests/integration/`, run with `run.sh test-live`.

## What runs against what

The branch these tests live on does not control what is tested. The dashboard
under test is whatever build is **deployed** to `dev.mainwps.com`, and the child
behavior is whatever is deployed to the children. To test native-backups, those
sites must be running the native-backups builds.

## Prerequisites

1. Fill the `REMOTE_*` block in `network-testbed/.env`:
   - `REMOTE_DASH_URL` (already `https://dev.mainwps.com`), `REMOTE_DASH_USER`,
     `REMOTE_DASH_PASS`
   - `REMOTE_CHILD_USER`, `REMOTE_CHILD_PASS` (shared across children)
   - `REMOTE_TEST_CHILD_URLS` (comma-separated handful of disposable children)
   - `REMOTE_TEST_BACKUP_CHILD_URL` (one small child for backup/restore)
   - `REMOTE_TEST_TOGGLE_PLUGIN` (default `hello.php`)
   - `REMOTE_TEST_KEEP_SNAPSHOTS` (optional, default 2) — completed on-demand
     snapshots the post-suite cleanup keeps per test child
2. Install the toolchain (one time):
   ```bash
   cd tests/e2e
   npm install
   npx playwright install chromium
   ```

## Run

```bash
# Sources .env, runs Tier 1 then this suite:
~/.claude/skills/run-mainwp-testbed/run.sh test-live all
# Just this suite:
~/.claude/skills/run-mainwp-testbed/run.sh test-live e2e
# Or directly (source .env yourself first):
cd tests/e2e && npx playwright test
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

All target children are disposable. Two tests are destructive. The
backup/restore round-trip (`real-path.spec.ts`, gated behind
`REMOTE_TEST_RUN_DESTRUCTIVE=1` and scoped to `REMOTE_TEST_BACKUP_CHILD_URL`)
drives a real restore that replaces the designated child's files and database
with the snapshot the run just created. The child plugin toggle
(`break-recover.spec.ts`) toggles a single non-critical plugin and restores it
in a `finally` block, scoped to `REMOTE_TEST_CHILD_URLS`. Neither touches
anything outside its configured target. Server-level breaks (500, expired
cert, host down) are out of scope here — they need host access the testbed does
not grant.

## First-run selector pass (important)

The login and navigation tests are concrete and pass against any MainWP build.
The MainWP-specific flows (sync, backup/restore, uptime monitor rows, dashboard
reflection of a child break) depend on selectors that must be confirmed against
the live DOM. They are marked `test.fixme` and centralized in `lib/dashboard.ts`
(search for `CONFIRM`). First run:

1. `npx playwright test --headed` and watch the concrete tests pass.
2. For each `CONFIRM` selector, open the live screen, read the real DOM, and fix
   the selector in `lib/dashboard.ts`.
3. Promote each `test.fixme(...)` to `test(...)` as its selectors are verified.

## Layout

```
tests/e2e/
  package.json            @playwright/test
  playwright.config.ts    timeouts, single worker, html report
  lib/
    env.ts                REMOTE_* parsing + *Configured gates
    auth.ts               wpLogin() against wp-login.php
    dashboard.ts          MainWP page object (CONFIRM selectors live here)
  tests/
    real-path.spec.ts     login + Manage Sites (real); sync, backup/restore (fixme)
    uim.spec.ts           uptime page loads (real); monitor rows (fixme)
    break-recover.spec.ts child plugin toggle (real); dashboard reflection, reconnect (fixme)
```
