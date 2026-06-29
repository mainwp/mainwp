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

  /**
   * Resolve the dashboard's numeric site id for a child by URL. The Manage Sites
   * row carries it in the `siteid` attribute; it is how the rest of the dashboard
   * (PluginsManage scope, per-site tabs) addresses a site, since the child only
   * knows its own URL.
   */
  async siteId(childUrl: string): Promise<string | null> {
    const row = await this.siteRow(childUrl);
    return (await row.count()) ? row.getAttribute('siteid') : null;
  }

  /**
   * Read a plugin's status for one site as the dashboard last recorded it.
   *
   * PluginsManage (`admin.php?page=PluginsManage`) renders from the dashboard's
   * stored sync data, not a live child query — sync the site first so this
   * reflects the current child state. The screen is search-driven: scope to the
   * site via its `selected_sites[]` checkbox (keyed by site id), set the status
   * filter to "installed" so both active and inactive plugins render, then submit.
   * Rows are matched by `plugin-slug` (the rawurlencoded plugin file) plus
   * `site-url`, and the status comes from the badge icon
   * (`i.circle.green.icon` = Active, `i.circle.red.icon` = Inactive).
   *
   * @returns 'active' | 'inactive' | 'absent' (no matching row for that site).
   */
  async pluginStatusOnSite(childUrl: string, pluginFile: string): Promise<'active' | 'inactive' | 'absent'> {
    const host = childUrl.replace(/^https?:\/\//, '').replace(/\/+$/, '');
    const id = await this.siteId(childUrl);
    if (!id) {
      return 'absent';
    }

    await this.page.goto(`${ENV.dashUrl}/wp-admin/admin.php?page=PluginsManage`, {
      waitUntil: 'domcontentloaded',
    });
    await this.page.waitForTimeout(1_500);

    // Scope the result to this one site. The checkbox input is visually hidden by
    // the Fomantic styling, so force the check; the submit handler reads the raw
    // `selected_sites[]` values regardless of the styled wrapper.
    await this.page
      .locator(`input[name="selected_sites[]"][value="${id}"]`)
      .check({ force: true });

    // The status filter is a Fomantic dropdown over a real <select>; set the
    // underlying value and fire change rather than driving the styled menu.
    await this.page.evaluate(() => {
      const sel = document.querySelector<HTMLSelectElement>('#mainwp_plugins_search_by_status');
      if (sel) {
        sel.value = 'installed';
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });

    // Force the click: cloudways admin pages keep shifting layout, so waiting for
    // the button to be "stable" times out. The handler is bound to the element, so
    // a forced click still fires the search.
    await this.page.locator('#mainwp-show-plugins').click({ force: true });
    // Results render in place; wait for at least one row for this site to appear.
    const slug = encodeURIComponent(pluginFile);
    const rowSel = `div.mainwp-manage-plugin-item-website[site-url*="${host}"][plugin-slug="${slug}"]`;
    const row = this.page.locator(rowSel);
    try {
      await row.first().waitFor({ state: 'attached', timeout: 15_000 });
    } catch {
      return 'absent';
    }

    if ((await row.locator('i.circle.green.icon').count()) > 0) {
      return 'active';
    }
    if ((await row.locator('i.circle.red.icon').count()) > 0) {
      return 'inactive';
    }
    return 'absent';
  }
}
