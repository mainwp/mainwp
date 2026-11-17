import { test, expect } from '@playwright/test';
import { install, CLAUDE_CODE_CLIENT_ID } from '../lib/config.js';
import { raw, form } from '../lib/http.js';
import { connect, obtainCode, authorizeUrl } from '../lib/oauth.js';
import { openConsent } from '../lib/browser.js';
import { withMuPlugin } from '../lib/mu-plugins.js';
import { pkcePair } from '../lib/pkce.js';
import { endpoints } from '../lib/urls.js';
import * as v from '../lib/verdicts.js';

const bearer = (token: string) => ({ Authorization: `Bearer ${token}` });

test('token is refused on wp-abilities, mainwp v2 and admin-ajax over real HTTP', async ({ page }) => {
  const conn = await connect(page, install('root'), { access: 'read' });
  const home = conn.install.home;
  const token = conn.tokens.access;

  expect(v.boundaryRefused(await raw(`${home}/wp-json/wp-abilities/v1/abilities`, { headers: bearer(token) }))).toEqual([]);
  expect(v.boundaryRefused(await raw(`${home}/wp-json/mainwp/v2/sites`, { headers: bearer(token) }))).toEqual([]);

  await withMuPlugin('whoami', async () => {
    const ajax = await raw(`${home}/wp-admin/admin-ajax.php`, {
      method: 'POST',
      headers: { ...bearer(token), 'Content-Type': 'application/x-www-form-urlencoded' },
      body: form({ action: 'mainwp_rmcp_harness_whoami' }),
    });
    expect(v.anonymous(ajax)).toEqual([]);
  });
});

test('bearer token alone does not authenticate a dummy plugin route', async ({ page }) => {
  const conn = await connect(page, install('root'), { access: 'read' });
  await withMuPlugin('whoami', async () => {
    const res = await raw(`${conn.install.home}/wp-json/harness/v1/whoami`, { headers: bearer(conn.tokens.access) });
    expect(v.anonymous(res)).toEqual([]);
  });
});

test('bearer token alone does not open the consent screen', async ({ page }) => {
  const conn = await connect(page, install('root'), { access: 'read' });
  const url = authorizeUrl(conn.endpoints.authorize, {
    clientId: CLAUDE_CODE_CLIENT_ID,
    redirectUri: 'http://127.0.0.1:43124/callback',
    scope: 'mainwp:read offline_access',
    state: 'rmcp-e2e-bearer',
    challenge: pkcePair().challenge,
    resource: conn.endpoints.mcp,
  });
  expect(v.loginRedirect(await raw(url, { headers: bearer(conn.tokens.access) }))).toEqual([]);
});

test('consent page cannot be framed', async ({ page }) => {
  const inst = install('root');
  const consent = await openConsent(page, inst, { scope: 'mainwp:read offline_access' });
  expect(v.frameBlocked(consent.response)).toEqual([]);

  // A real frame from another origin must not render the consent form.
  const framer = await page.context().newPage();
  await framer.setContent(`<iframe id="f" src="${consent.url}" width="800" height="600"></iframe>`);
  await framer.waitForTimeout(3000);
  const frame = framer.frames().find((f) => f !== framer.mainFrame());
  const rendered = frame ? await frame.locator('[data-page="consent"]').count() : 0;
  expect(rendered).toBe(0);
  await framer.close();
});

test('plain-permalink consent approval posts to the query authorize endpoint over HTTPS', async ({ page }) => {
  const inst = install('plain');
  const pending = await obtainCode(page, inst, { access: 'read' });
  expect(pending.metadata.authorization_endpoint).toBe(endpoints(inst).authorize);
  expect(pending.consentPost, 'the consent form was submitted').toBeTruthy();
  expect(v.consentPostTarget(pending.consentPost!, pending.metadata.authorization_endpoint)).toEqual([]);
  expect(pending.code).toMatch(/^mwpac_/);
});
