import type { Page } from '@playwright/test';
import { panelPath, type Install } from './config.js';
import { loginIfNeeded } from './browser.js';

/**
 * Revoke the newest connection whose row names `label`, through the panel's own Revoke button.
 * The panel lists connections newest first (`grants_for_user()`), and the panel template gives
 * each row a `data-grant` attribute.
 */
export async function revokeNewestConnection(page: Page, inst: Install, label: string): Promise<void> {
  await page.goto(`${inst.home}${panelPath()}`);
  await loginIfNeeded(page);
  const row = page.locator('tr[data-grant]').filter({ hasText: label }).first();
  await row.waitFor({ timeout: 30_000 });
  page.once('dialog', (dialog) => void dialog.accept());
  await Promise.all([page.waitForNavigation(), row.getByRole('button', { name: 'Revoke' }).click()]);
}
