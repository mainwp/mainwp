import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import { install, cloneSyncCommand } from '../lib/config.js';
import { connect } from '../lib/oauth.js';
import { mcpRequest } from '../lib/mcp.js';
import { endpoints } from '../lib/urls.js';
import { cloneObserver } from '../lib/clone.js';
import { verifyCloneCopy } from '../lib/workflows.js';
import * as v from '../lib/verdicts.js';

test('copied tokens fail on a clone with a different home', async ({ page }) => {
  test.setTimeout(300_000);
  const conn = await connect(page, install('root'), { access: 'read' });
  expect((await mcpRequest(conn, conn.tokens.access, 'tools/list', {})).status).toBe(200);

  const clone = install('clone');
  expect(clone.home).not.toBe(conn.install.home);
  await verifyCloneCopy(async () => {
    // Host output can contain database credentials; retain neither stdout nor stderr.
    try { execSync(cloneSyncCommand(), { stdio: 'pipe', timeout: 240_000 }); }
    catch { throw new Error('clone synchronization failed; host output was suppressed'); }
  }, cloneObserver(conn), async () => {
    const cloned = { ...conn, install: clone, endpoints: endpoints(clone) };
    expect(v.invalidTokenChallenge(await mcpRequest(cloned, conn.tokens.access, 'tools/list', {}), cloned.endpoints.prm)).toEqual([]);
  });

  // The original keeps working.
  expect((await mcpRequest(conn, conn.tokens.access, 'tools/list', {})).status).toBe(200);
});
