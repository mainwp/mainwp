import { test, expect, Page } from '@playwright/test';
import { ENV, dashConfigured, childConfigured } from '../lib/env';
import { wpLogin } from '../lib/auth';
import { Dashboard } from '../lib/dashboard';

/**
 * Break / recover.
 *
 * All target children are disposable. The reversible plugin toggle and the
 * destructive connection-break (deactivate mainwp-child -> dashboard
 * disconnected -> reconnect) both restore state in a `finally`. The
 * connection-break runs against ONE designated child (ENV.breakChildUrl), kept
 * off the data-bearing backup child. The dashboard-reflection case stays
 * `test.fixme` until the PluginsManage assertion surface is confirmed.
 */

const MAINWP_CHILD = 'mainwp-child/mainwp-child.php';

type ToggleResult = 'absent' | 'changed' | 'noop';

/**
 * Set a plugin active/inactive on a child via its plugins.php screen.
 *
 * WordPress wraps the row action link in `span.activate a` (when the plugin is
 * inactive) or `span.deactivate a` (when active). The visible link text is
 * "Activate"/"Deactivate", but the accessible name carries the plugin title
 * (e.g. "Deactivate MainWP Child"), so an anchored name match never fires —
 * match the wrapper class instead.
 *
 * @returns 'absent' if the plugin is not installed, 'noop' if already in the
 *          desired state, 'changed' if it was toggled.
 */
async function setPluginActive(
  page: Page,
  childUrl: string,
  pluginFile: string,
  active: boolean,
): Promise<ToggleResult> {
  await wpLogin(page, childUrl, ENV.childUser, ENV.childPass);
  await page.goto(`${childUrl}/wp-admin/plugins.php`, { waitUntil: 'domcontentloaded' });

  const row = page.locator(`tr[data-plugin="${pluginFile}"]`);
  if ((await row.count()) === 0) {
    return 'absent';
  }
  const link = active ? row.locator('span.activate a') : row.locator('span.deactivate a');
  if ((await link.count()) === 0) {
    return 'noop'; // already in the desired state.
  }
  // The action link is a plain nonced href (plugins.php?action=activate&...).
  // Navigate to it rather than clicking: some hosts' admin pages keep shifting
  // layout, which makes a stable click time out.
  const href = await link.first().getAttribute('href');
  if (!href) {
    return 'noop';
  }
  await page.goto(new URL(href, `${childUrl}/wp-admin/`).toString(), { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1_000);
  return 'changed';
}

test.describe('break / recover', () => {
  test.skip(!childConfigured, 'REMOTE_TEST_CHILD_URLS / shared child creds not set.');

  test('child-induced plugin toggle off then on is reversible', async ({ page }) => {
    const child = ENV.toggleChildUrl;
    const plugin = ENV.togglePlugin;

    // Ensure a known starting state (active). 'absent' => plugin not installed.
    const start = await setPluginActive(page, child, plugin, true);
    test.skip(start === 'absent', `Toggle plugin "${plugin}" is not installed on ${child}.`);

    try {
      const off = await setPluginActive(page, child, plugin, false);
      expect(off).not.toBe('absent');

      await page.goto(`${child}/wp-admin/plugins.php`, { waitUntil: 'domcontentloaded' });
      await expect(
        page.locator(`tr[data-plugin="${plugin}"]`).locator('span.activate a'),
      ).toBeVisible();
    } finally {
      // Always leave the site clean.
      await setPluginActive(page, child, plugin, true);
    }
  });

  test('connection breaks when mainwp-child is deactivated, and dashboard reconnect restores it', async ({ page }) => {
    test.skip(!dashConfigured, 'Dashboard creds not set.');
    test.setTimeout(5 * 60_000); // real break + failing sync + reconnect + reloads.
    const child = ENV.breakChildUrl;
    const dash = new Dashboard(page);

    // mainwp-child must be installed to break the connection.
    const start = await setPluginActive(page, child, MAINWP_CHILD, true);
    test.skip(start === 'absent', `mainwp-child is not installed on ${child}.`);

    await dash.login();
    expect(await dash.isSiteConnected(child)).toBe(true);

    try {
      // Break: deactivate mainwp-child, then sync. The dashboard's outbound call
      // fails, it records a sync error, and the row flips to Disconnected.
      const off = await setPluginActive(page, child, MAINWP_CHILD, false);
      expect(off).not.toBe('absent');

      await dash.login();
      await dash.syncSite(child);
      await page.waitForTimeout(12_000); // let the failing sync settle.
      expect(await dash.isSiteDisconnected(child)).toBe(true);

      // Recover: reactivate mainwp-child (keys persist across the toggle), then
      // drive the dashboard reconnect and assert the site is connected again.
      await setPluginActive(page, child, MAINWP_CHILD, true);
      await dash.login();
      await dash.reconnectSite(child);
      expect(await dash.isSiteConnected(child)).toBe(true);
    } finally {
      // SAFETY: never leave the designated child disconnected.
      await setPluginActive(page, child, MAINWP_CHILD, true);
    }
  });

  test.fixme('dashboard reflects the child plugin change after sync', async ({ page }) => {
    test.skip(!dashConfigured, 'Dashboard creds not set.');
    // CONFIRM the assertion surface, then promote: deactivate ENV.togglePlugin on
    // ENV.toggleChildUrl, dash.syncSite(child), then open PluginsManage
    // (admin.php?page=PluginsManage), search that plugin scoped to the site, and
    // assert its status label is Inactive (i.circle.red) — see page-mainwp-plugins.php
    // line ~1410. Reactivate + sync and assert it flips to Active (i.circle.green).
    // syncSite() and the row helpers in lib/dashboard.ts are already in place.
    const dash = new Dashboard(page);
    await dash.login();
    expect(ENV.toggleChildUrl).toBeTruthy();
  });
});
