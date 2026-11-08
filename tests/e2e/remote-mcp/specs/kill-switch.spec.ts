import { test, expect } from '@playwright/test';
import { install } from '../lib/config.js';
import { raw } from '../lib/http.js';
import { connect } from '../lib/oauth.js';
import { mcpRequest } from '../lib/mcp.js';
import { withMuPlugin } from '../lib/mu-plugins.js';
import { wellKnown } from '../lib/urls.js';
import { verifyDisabledPaths } from '../lib/workflows.js';

test('the kill-switch constant 404s every module path and refuses a live token', async ({ page }) => {
  for (const name of ['root', 'plain'] as const) {
    const conn = await connect(page, install(name), { access: 'read' });
    const metadata = wellKnown(conn.install.home, 'oauth-authorization-server').pathInsert;
    expect((await mcpRequest(conn, conn.tokens.access, 'tools/list', {})).status).toBe(200);
    expect((await raw(metadata)).status).toBe(200);

    await withMuPlugin('kill-switch', async () => {
      await verifyDisabledPaths(conn.install, conn.tokens.access, conn.tokens.refresh!, conn.clientId, raw);

      // Well-known paths are left to WordPress and other plugins while forced off.
      // Only pretty permalinks make WordPress answer them with a 404; on plain permalinks it
      // serves the home page, so there the check is that the module's metadata is gone.
      const wk = await raw(metadata);
      if (name === 'root') expect(wk.status, 'well-known').toBe(404);
      expect(wk.body).not.toContain('"issuer"');
    }, name);

    // The switch revokes nothing, so the same connection works once it is lifted.
    expect((await mcpRequest(conn, conn.tokens.access, 'tools/list', {})).status).toBe(200);
    expect((await raw(metadata)).status).toBe(200);
  }
});
