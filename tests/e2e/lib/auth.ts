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
  // Submit via Enter rather than clicking #wp-submit: some login pages keep
  // animating/shifting layout, so waiting for the button to be "stable" before a
  // click can time out. Submitting the focused form avoids that wait entirely.
  await page.locator('#user_pass').press('Enter');

  // Do NOT wait to leave wp-login.php. Some hosts (staging, reverse-proxied)
  // bounce through `wp-login.php?redirect_to=...` after a successful auth before
  // the cookie-driven redirect settles, so "URL no longer contains wp-login"
  // never fires. Instead confirm the session by loading wp-admin directly: if
  // auth failed, wp-admin redirects back to the login form and body.wp-admin is
  // never attached. The admin bar is hidden on MainWP screens, so assert the
  // admin body (present on every wp-admin page) rather than the toolbar.
  await page.waitForTimeout(2_000);
  await page.goto(`${baseUrl}/wp-admin/`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('body.wp-admin')).toBeAttached({ timeout: 20_000 });
}

/** True when the current page is a wp-login screen. */
export function onLoginPage(page: Page): boolean {
  return page.url().includes('wp-login.php');
}
