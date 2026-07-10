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

  /**
   * Open the native Snapshots page scoped to one site. The `site_id` query selects
   * the site, which enables the "Back up now" button and loads that site's
   * restore catalog.
   */
  async openNativeBackups(siteId: string): Promise<void> {
    await this.page.goto(
      `${ENV.dashUrl}/wp-admin/admin.php?page=ManageBackupsSnapshots&site_id=${siteId}`,
      { waitUntil: 'domcontentloaded' },
    );
    await this.page.waitForTimeout(2_000);
  }

  /**
   * Trigger a native backup of the site and wait until it reports complete. The
   * status line ends at "Backup complete." and a restore-able snapshot then
   * appears in the catalog. The Back up now click is forced because cloudways
   * admin pages keep shifting layout (a stable click times out).
   *
   * When `childUrl` is given the run is self-recovering: a prior interrupted run
   * can leave a backup task that never went terminal (on an idle child the
   * watchdog cron never fires), which blocks every new backup with an
   * "in progress" notice. On that branch this clears the stuck task once
   * (clearStuckBackup) and retries, so the suite does not need a hand-cleared
   * child between runs. If it still cannot start, it throws an actionable error.
   */
  async runNativeBackup(siteId: string, childUrl = '', timeoutMs = 20 * 60_000): Promise<void> {
    await this.openNativeBackups(siteId);

    // Terminal state comes from the progress AJAX's own `status` field, not
    // from pattern-matching the status line: a server failure message need
    // not contain "fail" or "error" ("The on-demand backup did not produce a
    // snapshot." matched neither and burned the whole budget re-reading an
    // already-terminal status).
    const ajaxState = { status: '', error: '' };
    const onResponse = async (resp: import('@playwright/test').Response) => {
      try {
        if (!resp.url().includes('admin-ajax.php')) return;
        const post = resp.request().postData() ?? '';
        if (!post.includes('mainwp_native_backup_progress')) return;
        const data = JSON.parse(await resp.text());
        ajaxState.status = typeof data?.status === 'string' ? data.status : '';
        ajaxState.error = typeof data?.error === 'string' ? data.error : '';
      } catch {
        // Non-JSON or detached response: the DOM heuristics below still apply.
      }
    };
    this.page.on('response', onResponse);

    try {
      await this.page.locator('#mainwp-native-backup-now-btn').click({ force: true });
      const status = this.page.locator('#mainwp-native-backup-now-status');
      const deadline = Date.now() + timeoutMs;
      let last = '';
      let recovered = false;
      while (Date.now() < deadline) {
        await this.page.waitForTimeout(5_000);
        last = ((await status.textContent()) ?? '').trim();
        if (ajaxState.status === 'completed' || /complete/i.test(last)) {
          return;
        }
        // A backup or restore already running rejects with a "try again" notice.
        if (/in progress|try again|already/i.test(last)) {
          // One self-recovery attempt: clear a stuck task left by a prior run,
          // then re-trigger the backup. Without childUrl (or after recovery has
          // already been tried) fail with an actionable message instead of
          // waiting out the whole timeout for a state that will not change.
          if (childUrl && !recovered) {
            recovered = true;
            ajaxState.status = '';
            ajaxState.error = '';
            await this.clearStuckBackup(siteId, childUrl);
            await this.page.locator('#mainwp-native-backup-now-btn').click({ force: true });
            continue;
          }
          throw new Error(
            `Backup did not start (another operation in progress): ${last}. A prior ` +
              `backup task is stuck on ${childUrl || 'the child'}; clear it from the ` +
              `dashboard Snapshots page ("Cancel backup") or drive the child wp-cron, then re-run.`,
          );
        }
        if (ajaxState.status === 'failed') {
          throw new Error(`Backup failed: ${ajaxState.error || last}`);
        }
        if (/fail|error/i.test(last)) {
          throw new Error(`Backup failed: ${last}`);
        }
      }
      throw new Error(`Backup did not complete within ${timeoutMs}ms; last status: ${last}`);
    } finally {
      this.page.off('response', onResponse);
    }
  }

  /**
   * Clear a stuck backup task that blocks a new backup. Uses the product's own
   * Cancel affordance first (it forces the child task terminal immediately), and
   * then drives the child's WP-Cron a few times as a backstop so the backup
   * watchdog can push any remaining stalled task terminal — the browser-only
   * recovery on a testbed with no SSH. Best-effort; cron errors are ignored. The
   * page is re-opened at the end so the caller's retry clicks a fresh button.
   */
  async clearStuckBackup(siteId: string, childUrl: string, cronHits = 4, spacingMs = 15_000): Promise<void> {
    await this.openNativeBackups(siteId);
    const cancel = this.page.locator('#mainwp-native-backup-cancel-btn');
    if (await cancel.count()) {
      await cancel.click({ force: true });
      await this.page.waitForTimeout(5_000);
    }
    for (let i = 0; i < cronHits; i++) {
      await this.page.request
        .get(`${childUrl}/wp-cron.php?doing_wp_cron=1`, { timeout: 30_000 })
        .catch(() => {});
      await this.page.waitForTimeout(spacingMs);
    }
    await this.openNativeBackups(siteId);
  }

  /**
   * Clean up a site's backups on the server: sweep leftover files from
   * interrupted runs, then prune old on-demand snapshots beyond `keep`.
   *
   * Drives the Snapshots page's `mainwp_snapshots_cleanup` endpoint directly
   * from the page context (same session, same nonce the Clean up button uses)
   * so the keep-count can be passed explicitly - the button itself has no keep
   * input, it uses the task's retention setting. The nonce is read from the
   * page source because the page keeps its nonces inside a script IIFE, not in
   * DOM fields. A missing nonce means the deployed build predates the cleanup
   * feature - deploy the current branch build first.
   */
  async cleanupSnapshots(
    siteId: string,
    keep: number,
  ): Promise<{ orphanDirsRemoved: number; snapshotsPruned: number; errors: string[] }> {
    await this.openNativeBackups(siteId);
    const html = await this.page.content();
    const nonceMatch = html.match(/snapshotsCleanup:\s*'([^']+)'/);
    if (!nonceMatch) {
      throw new Error(
        'Cleanup nonce not found on the Snapshots page - the deployed dashboard build ' +
          'predates the mainwp_snapshots_cleanup feature. Deploy the current branch build.',
      );
    }

    const response = await this.page.evaluate(
      async (args: { nonce: string; siteId: string; keep: number }) => {
        const body = new URLSearchParams({
          action: 'mainwp_snapshots_cleanup',
          security: args.nonce,
          site_id: args.siteId,
          keep: String(args.keep),
        });
        const res = await fetch((window as unknown as { ajaxurl: string }).ajaxurl, {
          method: 'POST',
          credentials: 'same-origin',
          body,
        });
        return res.json();
      },
      { nonce: nonceMatch[1], siteId, keep },
    );

    if (!response || response.error) {
      throw new Error(`Cleanup failed for site ${siteId}: ${response?.error ?? 'no response'}`);
    }
    return {
      orphanDirsRemoved: parseInt(response.orphan_dirs_removed, 10) || 0,
      snapshotsPruned: parseInt(response.snapshots_pruned, 10) || 0,
      errors: Array.isArray(response.errors) ? response.errors : [],
    };
  }

  /**
   * Restore the newest snapshot to the site and wait until it reports completed.
   *
   * DESTRUCTIVE: replaces the site's files and database with the snapshot. The
   * pre-restore safety backup is turned off here to keep the run bounded — it is a
   * rollback convenience, not part of the backup→restore round-trip under test.
   *
   * The restore's "Sending files" phase advances on WP-Cron — the dashboard pushes
   * the archive and the child processes it — and neither spawns from the admin-ajax
   * progress polls. So both wp-crons are nudged each tick; the child's cron in
   * particular is what unblocks "Sending files". In production, real traffic or a
   * system cron does this for free. Restore time scales with snapshot size (a
   * ~50MB site completes in a few minutes; a several-hundred-MB site can exceed the
   * default timeout), so point REMOTE_TEST_BACKUP_CHILD_URL at a small site.
   */
  async restoreLatestSnapshot(siteId: string, childUrl: string, timeoutMs = 25 * 60_000): Promise<void> {
    await this.openNativeBackups(siteId);
    const links = this.page.locator('a.mainwp-restore-snapshot');
    await expect(links.first(), 'a restore-able snapshot is offered').toBeAttached();
    // Restore the newest snapshot (highest id) rather than relying on table order.
    const ids = await links.evaluateAll((els) =>
      els.map((e) => parseInt(e.getAttribute('data-snapshot') ?? '0', 10)).filter((n) => n > 0),
    );
    const newest = Math.max(...ids);
    const snapshot = this.page.locator(`a.mainwp-restore-snapshot[data-snapshot="${newest}"]`);
    await snapshot.click({ force: true });
    await this.page.locator('#mainwp-restore-modal').waitFor({ state: 'visible' });
    await this.page.locator('#mainwp-restore-presnapshot').uncheck({ force: true });
    await this.page.locator('#mainwp-restore-consent').check({ force: true });
    await this.page.locator('#mainwp-restore-start-btn').click({ force: true });

    const status = this.page.locator('#mainwp-restore-status-text');
    const result = this.page.locator('#mainwp-restore-result-message');
    const rollback = this.page.locator('#mainwp-restore-rollback-btn');
    const deadline = Date.now() + timeoutMs;
    let last = '';
    while (Date.now() < deadline) {
      // Drive both wp-crons each tick (see method doc): the dashboard cron pushes
      // the archive and the child cron processes it. The child's cron is what
      // unblocks "Sending files"; without it the restore stalls there.
      await this.page.request
        .get(`${ENV.dashUrl}/wp-cron.php?doing_wp_cron=1`, { timeout: 30_000 })
        .catch(() => {});
      await this.page.request
        .get(`${childUrl}/wp-cron.php?doing_wp_cron=1`, { timeout: 30_000 })
        .catch(() => {});
      await this.page.waitForTimeout(5_000);
      const sText = ((await status.textContent()) ?? '').trim();
      const rText = (await result.isVisible()) ? ((await result.textContent()) ?? '').trim() : '';
      last = `${sText} | ${rText}`;
      if (/restored and responds normally/i.test(rText) || /restore completed/i.test(sText)) {
        return;
      }
      if (await rollback.isVisible()) {
        throw new Error(`Restore failed (rollback offered): ${last}`);
      }
    }
    throw new Error(`Restore did not reach completed within ${timeoutMs}ms; last status: ${last}`);
  }
}
