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
  if (!onLoginPage(page)) {
    return;
  }

  // Submit + confirm with a bounded poll instead of a fixed sleep. Late in a
  // heavy full-suite run the login POST (and the auth cookie it sets) can take
  // longer than any fixed wait, so the old "sleep 2s, load wp-admin once" raced
  // the cookie and intermittently bounced back to the form. The poll is the
  // correctness mechanism: load wp-admin and assert the authenticated body; if
  // auth has not landed, wp-admin redirects back to wp-login and the assert
  // throws, so the poll retries and re-submits the form on the next pass.
  //
  // Do NOT wait to *leave* wp-login.php: some hosts (staging, reverse-proxied)
  // bounce through `wp-login.php?redirect_to=...` before the cookie-driven
  // redirect settles, so "URL no longer contains wp-login" never fires. The
  // admin bar is hidden on MainWP screens, so assert body.wp-admin (present on
  // every wp-admin page) rather than the toolbar.
  await expect(async () => {
    if (onLoginPage(page)) {
      await page.locator('#user_login').waitFor({ state: 'visible', timeout: 15_000 });
      await page.fill('#user_login', user);
      await page.fill('#user_pass', pass);
      // Submit via Enter rather than clicking #wp-submit: some login pages keep
      // animating/shifting layout, so waiting for the button to be "stable"
      // before a click can time out. Submitting the focused form avoids that.
      await page.locator('#user_pass').press('Enter');
      // Settle only: form-submit navigation is synchronous, so this waits for
      // the POST -> redirect chain to reach domcontentloaded.
      await page.waitForLoadState('domcontentloaded');
    }
    await page.goto(`${baseUrl}/wp-admin/`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('body.wp-admin')).toBeAttached({ timeout: 10_000 });
  }).toPass({ timeout: 45_000, intervals: [1_000, 2_000, 4_000] });
}

/** True when the current page is a wp-login screen. */
export function onLoginPage(page: Page): boolean {
  return page.url().includes('wp-login.php');
}
