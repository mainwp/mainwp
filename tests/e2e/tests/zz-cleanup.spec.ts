import { test, expect } from '@playwright/test';
import { ENV, dashConfigured } from '../lib/env';
import { Dashboard } from '../lib/dashboard';

/**
 * Post-suite server cleanup.
 *
 * Every remote run leaves backup weight on the shared dashboard: the
 * destructive spec records a real snapshot, and any interrupted run leaves
 * partial files that nothing on the server revisits (a low-traffic host never
 * fires the retention cron). This spec runs LAST - the `zz-` prefix sorts it
 * after every other spec file, and the suite runs with one worker - and
 * sweeps each configured test child: leftover files from interrupted backups
 * first, then old on-demand snapshots beyond REMOTE_TEST_KEEP_SNAPSHOTS
 * (default 2, floored at 1 server-side so a child always keeps its newest
 * restorable backup).
 *
 * Cleanup is deliberately its own spec rather than an afterAll of the backup
 * test: it must run whether or not the other specs passed, since a failed run
 * is exactly the one that leaves debris behind.
 */
test.describe('post-suite cleanup', () => {
  test.skip(!dashConfigured, 'REMOTE_DASH_URL / REMOTE_DASH_USER / REMOTE_DASH_PASS not set.');

  test('removes broken and old backups from the test children', async ({ page }) => {
    test.setTimeout(10 * 60_000); // one page load + one AJAX round-trip per child.
    const dash = new Dashboard(page);
    await dash.login();

    // Every child a spec may have backed up, deduplicated. childUrls covers
    // historical targets that are no longer the designated backup child.
    const targets = [
      ...new Set(
        [ENV.backupChildUrl, ENV.toggleChildUrl, ENV.breakChildUrl, ...ENV.childUrls].filter(
          Boolean,
        ),
      ),
    ];
    expect(targets.length, 'at least one test child is configured').toBeGreaterThan(0);

    const report: string[] = [];
    const failures: string[] = [];
    for (const childUrl of targets) {
      const id = await dash.siteId(childUrl);
      if (!id) {
        report.push(`${childUrl}: not connected to the dashboard, skipped`);
        continue;
      }
      try {
        const result = await dash.cleanupSnapshots(id, ENV.keepSnapshots);
        report.push(
          `${childUrl}: removed ${result.orphanDirsRemoved} leftover folder(s), ` +
            `pruned ${result.snapshotsPruned} old snapshot(s)`,
        );
        // Server-reported partial failures (an undeletable file) are real
        // problems - the disk is not actually reclaimed - but must not stop
        // the sweep of the remaining children.
        for (const err of result.errors) {
          failures.push(`${childUrl}: ${err}`);
        }
      } catch (error) {
        failures.push(`${childUrl}: ${(error as Error).message}`);
      }
    }

    console.log(`cleanup report:\n${report.join('\n')}`);
    expect(failures, 'every test child cleaned up without errors').toEqual([]);
  });
});
