import { test, expect } from '@playwright/test';
import { ENV, dashConfigured } from '../lib/env';
import { Dashboard } from '../lib/dashboard';

/**
 * Real dashboard <-> child path.
 *
 * The dashboard makes the real outbound cURL + RSA + TLS calls to the children;
 * we trigger and observe them through the UI. The login + navigation tests are
 * concrete and pass against any MainWP build. The sync and backup/restore flows
 * depend on MainWP-specific selectors (see lib/dashboard.ts CONFIRM markers) and
 * are `test.fixme` until verified against the live DOM on the first run.
 */
test.describe('real path', () => {
  test.skip(!dashConfigured, 'REMOTE_DASH_URL / REMOTE_DASH_USER / REMOTE_DASH_PASS not set.');

  test('logs in and loads Manage Sites', async ({ page }) => {
    const dash = new Dashboard(page);
    await dash.login();
    await dash.gotoManageSites();
    await dash.assertLoadedNotLogin();
    // The Manage Sites screen is the managed-network entry point.
    await expect(page).toHaveURL(/page=managesites/);
  });

  test('sync all sites reports back', async ({ page }) => {
    test.setTimeout(6 * 60_000); // real bulk sync of every connected site.
    const dash = new Dashboard(page);
    await dash.login();
    await dash.gotoManageSites();
    await dash.syncAllSites();

    // The Data Synchronization modal opens and reports live progress...
    await expect(dash.syncModal()).toBeVisible();
    await expect(dash.syncModal()).toContainText(/Data Synchronization/i);
    // ...and every connected site reports back (N / N synced) — the real path.
    await dash.waitForSyncComplete();
  });

  test.fixme('native backup then restore on the designated child', async ({ page }) => {
    test.skip(!ENV.backupChildUrl, 'REMOTE_TEST_BACKUP_CHILD_URL not set.');
    test.setTimeout(30 * 60_000); // real backup + restore can take many minutes.
    const dash = new Dashboard(page);
    await dash.login();
    // CONFIRM: backup trigger + progress + restore controls for this build.
    // 1) open the designated child, 2) start a native backup, 3) wait for completion,
    // 4) start a restore, 5) assert the restore reaches "completed".
  });
});
