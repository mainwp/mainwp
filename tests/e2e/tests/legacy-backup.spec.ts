import { test, expect } from '@playwright/test';
import { ENV, dashConfigured } from '../lib/env';
import { Dashboard } from '../lib/dashboard';

/**
 * Legacy (pre-native) backup harness selectors.
 *
 * dev.mainwps.com currently runs stock MainWP 6.1.1, whose Backups page is the
 * CLASSIC full-backup system (page=ManageBackups), not the native/snapshot UI.
 * These selectors are recorded for harness completeness only — the native-backups
 * build uses a different surface, so they are not part of native-flow coverage.
 *
 * Confirmed live against page=ManageBackups (read-only):
 *   - Page:          admin.php?page=ManageBackups  (Add new: ...ManageBackupsAddNew)
 *   - Task table:    #mainwp-backups-table  (DataTable; _wrapper / _info siblings)
 *   - Run-now link:  a.backup_run_now[task_id][task_type]   e.g. task_type="full"
 *   - Progress box:  #managebackups-task-status-box  (close: #managebackups-task-status-close)
 *
 * The "Run now" trigger is deliberately left unautomated: it starts a REAL full
 * backup (disk + a SERVER-destination upload) on a shared dashboard, which is not
 * something a default test run should kick off. Promote only against a dedicated
 * disposable dashboard if legacy coverage is ever needed.
 */
const LEGACY = {
  page: 'admin.php?page=ManageBackups',
  table: '#mainwp-backups-table',
  runNow: 'a.backup_run_now',
  statusBox: '#managebackups-task-status-box',
};

test.describe('legacy backup harness', () => {
  test.skip(!dashConfigured, 'REMOTE_DASH_URL / creds not set.');

  test('legacy backups page loads with the task table', async ({ page }) => {
    const dash = new Dashboard(page);
    await dash.login();
    await page.goto(`${ENV.dashUrl}/wp-admin/${LEGACY.page}`, { waitUntil: 'domcontentloaded' });
    await dash.assertLoadedNotLogin();
    // The classic backups task table is present (read-only; no backup triggered).
    await expect(page.locator(LEGACY.table)).toBeAttached();
  });

  test.fixme('run-now starts a legacy full backup (dedicated dashboard only)', async ({ page }) => {
    // CONFIRM target is disposable before enabling. Then: open LEGACY.page, click
    // the task's a.backup_run_now (task_type="full"), and assert the
    // #managebackups-task-status-box reports progress to completion. Do NOT run
    // this against the shared dev.mainwps.com dashboard.
    const dash = new Dashboard(page);
    await dash.login();
    expect(LEGACY.runNow).toBeTruthy();
  });
});
