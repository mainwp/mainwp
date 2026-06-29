import { test, expect } from '@playwright/test';
import { ENV, dashConfigured } from '../lib/env';
import { Dashboard } from '../lib/dashboard';

/**
 * UIM (uptime / speed) against real children.
 *
 * The uptime monitor makes a real outbound request to each child's public URL
 * and times it. The page-loads test is concrete; asserting real latency values
 * for specific children depends on monitors being configured + wp-cron having
 * fired, and on MainWP-specific selectors, so it is `test.fixme`.
 */
test.describe('uim', () => {
  test.skip(!dashConfigured, 'REMOTE_DASH_URL / creds not set.');

  test('uptime monitoring page loads', async ({ page }) => {
    const dash = new Dashboard(page);
    await dash.login();
    await dash.gotoUptime();
    await dash.assertLoadedNotLogin();
  });

  test('uptime monitors render real status data', async ({ page }) => {
    const dash = new Dashboard(page);
    await dash.login();
    await dash.gotoUptime();

    // The monitoring table produces real outbound-check data: at least one
    // monitored site is Up. (Status | Monitor | ... | Last 24h Status | ...)
    const rows = page.locator('table tbody tr');
    await expect(rows.first()).toBeVisible();
    await expect(page.getByText(/\bUp\b/).first()).toBeVisible();

    // For any configured test child that is actually monitored here, its row
    // must show Up (monitored children are a subset of the ~60 connected sites).
    let monitoredChildren = 0;
    for (const child of ENV.childUrls) {
      const host = child.replace(/^https?:\/\//, '');
      const row = page.locator('tr', { hasText: host });
      if ((await row.count()) === 0) {
        continue;
      }
      monitoredChildren++;
      await expect(row.first()).toContainText(/\bUp\b/i);
    }
    console.log(`[uim] ${monitoredChildren}/${ENV.childUrls.length} configured test children are monitored here.`);
  });
});
