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

## Safety model

All target children are disposable. Even so, the only destructive test
(`break-recover.spec.ts` → child plugin toggle) toggles a single non-critical
plugin and restores it in a `finally` block. It is scoped to `REMOTE_TEST_CHILD_URLS`
and never touches anything outside that list. Server-level breaks (500, expired
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
