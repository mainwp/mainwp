import { test, expect } from '@playwright/test';
import { ENV, dashConfigured } from '../lib/env';
import { Dashboard } from '../lib/dashboard';

/**
 * Real dashboard <-> child path.
 *
 * The dashboard makes the real outbound cURL + RSA + TLS calls to the children;
 * we trigger and observe them through the UI. The login + navigation tests are
 * concrete and pass against any MainWP build. The sync and backup/restore flows
 * use the MainWP-specific selectors in lib/dashboard.ts; backup/restore also
 * needs REMOTE_TEST_RUN_DESTRUCTIVE=1.
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

  test('native backup then restore on the designated child', async ({ page }) => {
    test.skip(!ENV.backupChildUrl, 'REMOTE_TEST_BACKUP_CHILD_URL not set.');
    test.skip(
      !ENV.runDestructive,
      'Set REMOTE_TEST_RUN_DESTRUCTIVE=1 to run the destructive native backup/restore.',
    );
    test.setTimeout(30 * 60_000); // real backup + restore can take many minutes.
    const dash = new Dashboard(page);
    await dash.login();

    const id = await dash.siteId(ENV.backupChildUrl);
    expect(id, `backup child ${ENV.backupChildUrl} is connected to the dashboard`).toBeTruthy();

    // Real native backup of the designated child, then restore THAT snapshot back
    // to it: runNativeBackup returns the created snapshot's id from the explicit
    // completed status, and restoreLatestSnapshot targets it, so a failed backup
    // can never pass by restoring a pre-existing snapshot. Passing the child URL
    // makes the backup self-recovering: a task left stuck by an interrupted prior
    // run is cleared before retrying, so the suite stays usable by default
    // without a hand-cleared child between runs.
    const snapshotId = await dash.runNativeBackup(id!, ENV.backupChildUrl);
    await dash.restoreLatestSnapshot(id!, ENV.backupChildUrl, snapshotId);
  });
});
