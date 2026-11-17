import { test, expect } from '@playwright/test';
import { install, readTool, writeTool, CLAUDE_CODE_CLIENT_ID } from '../lib/config.js';
import { raw, json } from '../lib/http.js';
import { connect, obtainCode, exchangeCode, refresh, authorizeUrl, tokenPair } from '../lib/oauth.js';
import { listTools, callTool, mcpRequest } from '../lib/mcp.js';
import { withMuPlugin } from '../lib/mu-plugins.js';
import { revokeNewestConnection } from '../lib/panel.js';
import { pkcePair } from '../lib/pkce.js';
import { endpoints } from '../lib/urls.js';
import { verifyRead } from '../lib/workflows.js';
import * as v from '../lib/verdicts.js';

test('full flow on a root install lists and calls a read tool', async ({ page }) => {
  const conn = await connect(page, install('root'), { access: 'read' });
  const tools = await listTools(conn, conn.tokens.access);
  expect(v.toolListing(tools, readTool(), writeTool())).toEqual([]);
  expect(v.toolListResult(tools)).toEqual([]);
  const call = await callTool(conn, conn.tokens.access, readTool(), {});
  expect(call.status).toBe(200);
  verifyRead(json(call), readTool());
});

test('a write call on a read-only grant gets the step-up challenge over HTTP', async ({ page }) => {
  const conn = await connect(page, install('root'), { access: 'read' });
  const tag = `rmcp-e2e-${Date.now()}`;
  const write = await callTool(conn, conn.tokens.access, writeTool(), { name: tag });
  expect(v.stepUpChallenge(write, conn.endpoints.prm)).toEqual([]);

  // The challenge must come before anything runs: the tag the call would have created is absent.
  const after = await callTool(conn, conn.tokens.access, 'list_tags_v1', { search: tag });
  expect(after.status).toBe(200);
  verifyRead(json(after), 'list_tags_v1', tag);
});

test('parallel refresh with one token yields one winner and a usable grace sibling', async ({ page }) => {
  const conn = await connect(page, install('root'), { access: 'read' });
  const refreshToken = conn.tokens.refresh;
  expect(refreshToken, 'the grant has offline_access').toBeTruthy();

  const [a, b] = await Promise.all([refresh(conn, refreshToken!), refresh(conn, refreshToken!)]);
  expect(v.refreshRace([a, b])).toEqual([]);

  // Whichever twin is presented first is selected and the other stops working.
  const first = tokenPair(b);
  const second = tokenPair(a);
  expect((await mcpRequest(conn, first.access, 'tools/list', {})).status).toBe(200);
  expect(v.invalidTokenChallenge(await mcpRequest(conn, second.access, 'tools/list', {}), conn.endpoints.prm)).toEqual([]);
  // Last on purpose: the dropped twin's refresh token is older than the current pair, so the
  // server may treat it as a replay and end the family.
  expect(v.tokenError(await refresh(conn, second.refresh!), 'invalid_grant')).toEqual([]);
});

test('refresh replay after grace revokes the family', async ({ page }) => {
  test.setTimeout(180_000);
  const conn = await connect(page, install('root'), { access: 'read' });
  const original = conn.tokens.refresh!;
  const rotated = await refresh(conn, original);
  expect(rotated.status).toBe(200);
  const next = tokenPair(rotated);

  // The grace window is 30 seconds from rotation; wait past it.
  await new Promise((resolve) => setTimeout(resolve, 32_000));
  expect(v.tokenError(await refresh(conn, original), 'invalid_grant')).toEqual([]);
  expect(v.invalidTokenChallenge(await mcpRequest(conn, next.access, 'tools/list', {}), conn.endpoints.prm)).toEqual([]);
  expect(v.tokenError(await refresh(conn, next.refresh!), 'invalid_grant')).toEqual([]);
});

test('revocation from the panel takes effect on the next request', async ({ page }) => {
  const conn = await connect(page, install('root'), { access: 'read' });
  expect((await mcpRequest(conn, conn.tokens.access, 'tools/list', {})).status).toBe(200);
  await revokeNewestConnection(page, conn.install, 'Claude Code');
  expect(v.invalidTokenChallenge(await mcpRequest(conn, conn.tokens.access, 'tools/list', {}), conn.endpoints.prm)).toEqual([]);
  expect(v.tokenError(await refresh(conn, conn.tokens.refresh!), 'invalid_grant')).toEqual([]);
});

test('parallel code redemption yields one grant and a valid replay revokes it', async ({ page }) => {
  const inst = install('root');

  // Concurrent valid redemptions: a loser that lost the claim sees a redeemed code with valid
  // parameters, which is a replay, so the winner's grant ends up revoked as well.
  const raced = await obtainCode(page, inst, { access: 'read' });
  const results = await Promise.all(Array.from({ length: 5 }, () => exchangeCode(raced)));
  expect(v.codeRace(results)).toEqual([]);
  // The first claim always succeeds, so a server that refuses every exchange is wrong too.
  const winner = results.find((r) => r.status === 200);
  expect(winner, 'one exchange wins the race').toBeDefined();
  expect(v.invalidTokenChallenge(await mcpRequest(raced, tokenPair(winner!).access, 'tools/list', {}), raced.endpoints.prm)).toEqual([]);

  // The same rule, sequentially: a working grant, then a valid replay that revokes it.
  const pending = await obtainCode(page, inst, { access: 'read' });
  const first = await exchangeCode(pending);
  expect(first.status).toBe(200);
  const pair = tokenPair(first);
  expect((await mcpRequest(pending, pair.access, 'tools/list', {})).status).toBe(200);
  expect(v.tokenError(await exchangeCode(pending), 'invalid_grant')).toEqual([]);
  expect(v.invalidTokenChallenge(await mcpRequest(pending, pair.access, 'tools/list', {}), pending.endpoints.prm)).toEqual([]);
});

test('an unregistered client_id is refused before login and nothing is fetched', async () => {
  const inst = install('root');
  const urls = endpoints(inst);
  await withMuPlugin('client-document', async () => {
    const counter = `${inst.home}/harness/client-hits`;
    expect((await raw(`${counter}?reset=1`)).status).toBe(200);

    const url = authorizeUrl(urls.authorize, {
      clientId: `${inst.home}/harness/client.json`,
      redirectUri: 'http://127.0.0.1:43123/callback',
      scope: 'mainwp:read offline_access',
      state: 'rmcp-e2e',
      challenge: pkcePair().challenge,
      resource: urls.mcp,
    });
    expect(v.errorPageBeforeLogin(await raw(url))).toEqual([]);
    expect(json<{ hits: number }>(await raw(counter))?.hits).toBe(0);

    // The counter itself works, so the zero above means nothing fetched the document.
    expect((await raw(`${inst.home}/harness/client.json`)).status).toBe(200);
    expect(json<{ hits: number }>(await raw(counter))?.hits).toBe(1);
  });

  // The bundled client still reaches the login hand-off, so the refusal is about the client id.
  const known = authorizeUrl(urls.authorize, {
    clientId: CLAUDE_CODE_CLIENT_ID,
    redirectUri: 'http://127.0.0.1:43123/callback',
    scope: 'mainwp:read offline_access',
    state: 'rmcp-e2e',
    challenge: pkcePair().challenge,
    resource: urls.mcp,
  });
  expect(v.loginRedirect(await raw(known))).toEqual([]);
});
