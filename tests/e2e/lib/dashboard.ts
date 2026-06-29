import { Page, expect } from '@playwright/test';
import { ENV } from './env';
import { wpLogin } from './auth';

/**
 * Page object for the remote MainWP dashboard.
 *
 * Selectors for MainWP-specific admin screens are centralized here so the
 * first-run "confirm against the live DOM" pass touches one file. Anything
 * marked CONFIRM is a best-effort guess that should be verified the first time
 * this suite runs against dev.mainwps.com; the flows that depend on them are
 * `test.fixme` in the specs until then.
 */
export class Dashboard {
  constructor(private readonly page: Page) {}

  /** Log into the dashboard (no-op if the session cookie is still valid). */
  async login(): Promise<void> {
    await wpLogin(this.page, ENV.dashUrl, ENV.dashUser, ENV.dashPass);
  }

  /** Open Manage Sites. */
  async gotoManageSites(): Promise<void> {
    await this.page.goto(`${ENV.dashUrl}/wp-admin/admin.php?page=managesites`, {
      waitUntil: 'domcontentloaded',
    });
  }

  /** Open the Uptime / Site Health monitoring page. */
  async gotoUptime(): Promise<void> {
    await this.page.goto(`${ENV.dashUrl}/wp-admin/admin.php?page=MonitoringSites`, {
      waitUntil: 'domcontentloaded',
    });
  }

  /**
   * Heading text of the current MainWP admin screen.
   * Used for robust "the page loaded and we are not on login" assertions.
   */
  async assertLoadedNotLogin(): Promise<void> {
    await expect(this.page).not.toHaveURL(/wp-login\.php/);
    // MainWP hides the WP admin bar on its own pages, so assert the admin body
    // is present rather than the toolbar's visibility.
    await expect(this.page.locator('body.wp-admin')).toBeAttached();
  }

  /** Trigger a sync of all connected sites (Manage Sites green Sync button). */
  async syncAllSites(): Promise<void> {
    await this.page.locator('#mainwp-sync-sites').click();
  }

  /** The "Data Synchronization" progress modal opened by syncAllSites(). */
  syncModal() {
    return this.page.locator('#mainwp-sync-sites-modal');
  }

  /**
   * Wait until the bulk sync reports back for every site (N / N synced) or the
   * modal closes on completion. The modal text reads e.g. "12 / 62 synced".
   *
   * @param timeoutMs Overall wait budget (real sites; allow minutes).
   */
  async waitForSyncComplete(timeoutMs = 300_000): Promise<void> {
    const modal = this.syncModal();
    await expect(modal).toBeVisible();
    await expect(async () => {
      if (!(await modal.isVisible())) {
        return; // Modal closed => sync finished.
      }
      const text = (await modal.textContent()) ?? '';
      const progress = text.match(/(\d+)\s*\/\s*(\d+)\s*synced/i);
      expect(progress, `sync progress text in: ${text.slice(0, 80)}`).not.toBeNull();
      const [, done, total] = progress!;
      expect(Number(done)).toBeGreaterThanOrEqual(Number(total));
    }).toPass({ timeout: timeoutMs, intervals: [2_000] });
  }

  /**
   * Open Manage Sites, filter the (client-side DataTable) list to `childUrl`,
   * and return that site's row. Always re-navigates so the row reflects the
   * current server-rendered connection state (the status cell is PHP-rendered
   * from the site's sync-error flag, so a stale DOM would lie).
   */
  async siteRow(childUrl: string) {
    const host = childUrl.replace(/^https?:\/\//, '').replace(/\/+$/, '');
    await this.gotoManageSites();
    await this.page.waitForTimeout(1_500);
    const search = this.page.locator('#dt-search-0');
    if (await search.count()) {
      // First label segment is a unique, safe search term for every test host.
      await search.fill(host.split('.')[0]);
      await this.page.waitForTimeout(2_000);
    }
    return this.page.locator(`tr[siteid][site-url*="${host}"]`).first();
  }

  /** Trigger a sync of a single connected site via its Manage Sites row. */
  async syncSite(childUrl: string): Promise<void> {
    const row = await this.siteRow(childUrl);
    await row.locator('a.managesites_syncdata').first().click();
  }

  /**
   * True when the dashboard shows the site Disconnected. The status cell renders
   * `a.mainwp_site_reconnect` (and the row gains the `error` class) once the
   * dashboard has recorded a sync error for the site.
   */
  async isSiteDisconnected(childUrl: string): Promise<boolean> {
    const row = await this.siteRow(childUrl);
    return (await row.locator('a.mainwp_site_reconnect').count()) > 0;
  }

  /** True when the site shows Connected (sync control present, no reconnect). */
  async isSiteConnected(childUrl: string): Promise<boolean> {
    const row = await this.siteRow(childUrl);
    return (
      (await row.locator('a.managesites_syncdata').count()) > 0 &&
      (await row.locator('a.mainwp_site_reconnect').count()) === 0
    );
  }

  /**
   * Drive the dashboard's reconnect for a disconnected site. The handler posts
   * `mainwp_reconnectwp` and force-reloads Manage Sites ~6s after success, so we
   * wait that out before the caller re-reads the row.
   */
  async reconnectSite(childUrl: string): Promise<void> {
    const row = await this.siteRow(childUrl);
    await row.locator('a.mainwp_site_reconnect').first().click();
    await this.page.waitForTimeout(9_000);
    await this.page.waitForLoadState('domcontentloaded');
  }
}
