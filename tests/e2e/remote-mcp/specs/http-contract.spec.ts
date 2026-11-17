import { test, expect } from '@playwright/test';
import { install } from '../lib/config.js';
import { raw, form, json, overHttp } from '../lib/http.js';
import { connect, obtainCode, exchangeCode, refresh, tokenPair } from '../lib/oauth.js';
import { openConsent, approveOpenConsent } from '../lib/browser.js';
import { mcpRequest, modernBody, modernHeaders } from '../lib/mcp.js';
import { withMuPlugin } from '../lib/mu-plugins.js';
import { endpoints, withQuery } from '../lib/urls.js';
import { verifyHttpConsent } from '../lib/workflows.js';
import * as v from '../lib/verdicts.js';

const list = () => modernBody('tools/list', {});

function unauthenticatedMcp(url: string, extra: Record<string, string> = {}) {
  return raw(url, { method: 'POST', headers: { ...modernHeaders('tools/list'), ...extra }, body: list() });
}

test('malformed JSON gets a JSON-RPC parse error, not rest_invalid_json', async ({ page }) => {
  const conn = await connect(page, install('root'), { access: 'read' });
  const res = await raw(conn.endpoints.mcp, {
    method: 'POST',
    headers: { ...modernHeaders('tools/list'), Authorization: `Bearer ${conn.tokens.access}` },
    body: '{"jsonrpc":"2.0","id":1,"method":"tools/list",',
  });
  expect(v.jsonRpcParseError(res)).toEqual([]);
});

test('_envelope, _jsonp and _fields leave the 401 status, challenge and body unchanged', async () => {
  const urls = endpoints(install('root'));
  const plain = await unauthenticatedMcp(urls.mcp);
  expect(v.missingTokenChallenge(plain, urls.prm)).toEqual([]);
  const variants: Array<Record<string, string>> = [{ _envelope: '1' }, { _jsonp: 'cb' }, { _fields: 'id' }, { _envelope: '1', _jsonp: 'cb', _fields: 'id' }];
  for (const query of variants) {
    const variant = await unauthenticatedMcp(withQuery(urls.mcp, query));
    expect(v.sameChallenge(plain, variant), JSON.stringify(query)).toEqual([]);
  }
});

test('no CORS headers on MCP, token or PRM responses, with or without Origin', async () => {
  const inst = install('root');
  const urls = endpoints(inst);
  const origins: Array<Record<string, string>> = [{}, { Origin: new URL(inst.home).origin }, { Origin: 'https://evil.example' }];
  for (const origin of origins) {
    const label = origin.Origin ?? 'no Origin';
    expect(v.noCors(await unauthenticatedMcp(urls.mcp, origin)), `MCP, ${label}`).toEqual([]);
    expect(v.noCors(await raw(urls.mcp, { method: 'OPTIONS', headers: { ...origin, 'Access-Control-Request-Method': 'POST' } })), `MCP OPTIONS, ${label}`).toEqual([]);
    const token = await raw(urls.token, {
      method: 'POST',
      headers: { ...origin, 'Content-Type': 'application/x-www-form-urlencoded' },
      body: form({ grant_type: 'refresh_token', refresh_token: 'mwprt_not-a-real-token', client_id: 'https://claude.ai/oauth/claude-code-client-metadata' }),
    });
    expect(v.noCors(token), `token, ${label}`).toEqual([]);
    expect(v.noCors(await raw(urls.prm, { headers: origin })), `PRM, ${label}`).toEqual([]);
  }
});

test('plain-HTTP requests to authorize, consent, token and MCP are refused without state change', async ({ page }) => {
  const inst = install('root');

  // Authorize and consent: refused over http, and the pending request still approves over https.
  const consent = await openConsent(page, inst, { scope: 'mainwp:read offline_access' });
  expect(v.httpRefusal('authorize', await raw(overHttp(consent.url)))).toEqual([]);
  await verifyHttpConsent(consent, await page.context().cookies(inst.home), raw, async () => {
    const approved = await approveOpenConsent(page, consent);
    expect(approved.code.startsWith('mwpac_'), 'the same pending request still yields a code').toBe(true);
  });

  // Token: a code sent over http is not burned and still redeems over https.
  const pending = await obtainCode(page, inst, { access: 'read' });
  const codeOverHttp = await raw(overHttp(pending.endpoints.token), {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: form({ grant_type: 'authorization_code', code: pending.code, code_verifier: pending.verifier, client_id: pending.clientId, redirect_uri: pending.redirectUri, resource: pending.endpoints.mcp }),
  });
  expect(v.httpRefusal('token', codeOverHttp)).toEqual([]);
  const redeemed = await exchangeCode(pending);
  expect(redeemed.status).toBe(200);
  const pair = tokenPair(redeemed);

  // A refresh token sent over http is not rotated or treated as a replay.
  const refreshOverHttp = await raw(overHttp(pending.endpoints.token), {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: form({ grant_type: 'refresh_token', refresh_token: pair.refresh, client_id: pending.clientId }),
  });
  expect(v.httpRefusal('token', refreshOverHttp)).toEqual([]);

  // MCP: refused over http, and the same token works over https.
  const mcpOverHttp = await raw(overHttp(pending.endpoints.mcp), {
    method: 'POST',
    headers: { ...modernHeaders('tools/list'), Authorization: `Bearer ${pair.access}` },
    body: list(),
  });
  expect(v.httpRefusal('mcp', mcpOverHttp)).toEqual([]);
  expect((await mcpRequest(pending, pair.access, 'tools/list', {})).status).toBe(200);
  const rotated = await refresh(pending, pair.refresh!);
  expect(rotated.status).toBe(200);
  expect(json<{ access_token?: string }>(rotated)?.access_token).toBeTruthy();
});

test('a plugin that blocks anonymous REST does not intercept the MCP challenge', async () => {
  const inst = install('root');
  const urls = endpoints(inst);
  await withMuPlugin('block-anonymous-rest', async () => {
    // The blocker is active: core REST refuses an anonymous request with its error.
    const core = await raw(`${inst.home}/wp-json/wp/v2/users/me`);
    expect(core.body).toContain('harness_rest_blocked');
    expect(v.missingTokenChallenge(await unauthenticatedMcp(urls.mcp), urls.prm)).toEqual([]);
  });
});
