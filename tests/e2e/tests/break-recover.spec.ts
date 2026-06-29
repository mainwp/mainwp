import { test, expect, Page } from '@playwright/test';
import { ENV, dashConfigured, childConfigured } from '../lib/env';
import { wpLogin } from '../lib/auth';
import { Dashboard } from '../lib/dashboard';

/**
 * Break / recover.
 *
 * All target children are disposable. The child-induced plugin toggle is
 * concrete (standard WordPress plugins.php) and always restores state in a
 * finally block. Asserting the dashboard reflects the change, and the
 * mainwp-child deactivate -> reconnect flow, depend on MainWP-specific selectors
 * and are `test.fixme` until confirmed on the live DOM.
 */

type ToggleResult = 'absent' | 'changed' | 'noop';

/**
 * Set a plugin active/inactive on a child via its plugins.php screen.
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
  // WP renders an "Activate" link when inactive, "Deactivate" when active.
  const wanted = active ? /^Activate$/i : /^Deactivate$/i;
  const link = row.getByRole('link', { name: wanted });
  if ((await link.count()) === 0) {
    return 'noop'; // already in the desired state.
  }
  await link.first().click();
  await page.waitForLoadState('domcontentloaded');
  return 'changed';
}

test.describe('break / recover', () => {
  test.skip(!childConfigured, 'REMOTE_TEST_CHILD_URLS / shared child creds not set.');

  test('child-induced plugin toggle off then on is reversible', async ({ page }) => {
    const child = ENV.childUrls[0];
    const plugin = ENV.togglePlugin;

    // Ensure a known starting state (active). 'absent' => plugin not installed.
    const start = await setPluginActive(page, child, plugin, true);
    test.skip(start === 'absent', `Toggle plugin "${plugin}" is not installed on ${child}.`);

    try {
      const off = await setPluginActive(page, child, plugin, false);
      expect(off).not.toBe('absent');

      await page.goto(`${child}/wp-admin/plugins.php`, { waitUntil: 'domcontentloaded' });
      await expect(
        page.locator(`tr[data-plugin="${plugin}"]`).getByRole('link', { name: /^Activate$/i }),
      ).toBeVisible();
    } finally {
      // Always leave the site clean.
      await setPluginActive(page, child, plugin, true);
    }
  });

  test.fixme('dashboard reflects the child plugin change after sync', async ({ page }) => {
    test.skip(!dashConfigured, 'Dashboard creds not set.');
    const child = ENV.childUrls[0];
    // CONFIRM: deactivate a non-critical plugin on the child, sync from the
    // dashboard, and assert the dashboard's plugins view shows it inactive;
    // reactivate and assert it clears.
    const dash = new Dashboard(page);
    await dash.login();
    expect(child).toBeTruthy();
  });

  test.fixme('mainwp-child deactivate breaks the connection; dashboard reconnect restores it', async ({ page }) => {
    test.skip(!dashConfigured, 'Dashboard creds not set.');
    // CONFIRM (run on ONE designated site only): deactivate the mainwp-child
    // plugin on the child, sync, assert the dashboard shows the site
    // disconnected, then drive the dashboard's reconnect flow and assert it
    // recovers. Reactivation alone may not restore the keys, which is why this
    // exercises the reconnect path explicitly.
  });
});
