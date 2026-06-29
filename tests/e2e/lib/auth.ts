import { Page, expect } from '@playwright/test';

/**
 * Log into a WordPress site through the standard wp-login.php form.
 *
 * This is automation reading credentials from env (sourced from the gitignored
 * network-testbed/.env) and filling a form — standard E2E practice. The
 * "never type a password" rule is about an agent handling secrets interactively,
 * not about a test harness authenticating itself.
 *
 * @param page    Playwright page.
 * @param baseUrl Site base URL (no trailing slash).
 * @param user    WordPress username.
 * @param pass    WordPress password.
 */
export async function wpLogin(page: Page, baseUrl: string, user: string, pass: string): Promise<void> {
  await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });

  // Already authenticated (cookie reused)? wp-login redirects to the dashboard.
  if (!page.url().includes('wp-login.php')) {
    return;
  }

  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await page.click('#wp-submit');

  // Wait for the redirect off wp-login, then confirm we are in wp-admin. The WP
  // admin bar is hidden on MainWP's own screens, so assert the admin body is
  // attached (present on every wp-admin page) rather than the toolbar.
  await page.waitForURL((url) => !url.toString().includes('wp-login.php'), { timeout: 30_000 });
  await expect(page.locator('body.wp-admin')).toBeAttached({ timeout: 20_000 });
}

/** True when the current page is a wp-login screen. */
export function onLoginPage(page: Page): boolean {
  return page.url().includes('wp-login.php');
}
