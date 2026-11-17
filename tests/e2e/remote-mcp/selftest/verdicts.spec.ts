// Each named E2E check decides pass or fail through one verdict function in lib/verdicts.ts. These
// tests feed each verdict the response shape the module must send and one shape it forbids, so a
// verdict that would pass anything (and turn its E2E check vacuous) fails here first.
import { test, expect } from '@playwright/test';
import type { RawResponse } from '../lib/http.js';
import * as v from '../lib/verdicts.js';

const PRM = 'https://d.example/wp-json/mainwp-remote/v1/oauth-protected-resource';
const ISSUER = 'https://d.example';

function res(status: number, headers: Record<string, string> = {}, body = ''): RawResponse {
  const lower: Record<string, string> = {};
  for (const [k, val] of Object.entries(headers)) lower[k.toLowerCase()] = val;
  return { status, headers: lower, body };
}

const tokenOk = (access: string, refresh: string) =>
  res(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store', Pragma: 'no-cache' },
    JSON.stringify({ access_token: access, token_type: 'Bearer', expires_in: 1800, scope: 'mainwp:read offline_access', refresh_token: refresh }));
const tokenErr = (code: string, status = 400) =>
  res(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }, JSON.stringify({ error: code, error_description: 'x' }));
const challenge401 = (extra = '') =>
  res(401, { 'WWW-Authenticate': `Bearer resource_metadata="${PRM}", scope="mainwp:read mainwp:write"${extra}`, 'Cache-Control': 'no-store' }, '');

const READ_SCHEMES = [{ type: 'oauth2', scopes: ['mainwp:read'] }];
const WRITE_SCHEMES = [{ type: 'oauth2', scopes: ['mainwp:write'] }];

test.describe('verdict for: full flow on a root install lists and calls a read tool', () => {
  test('accepts read and write tools with their securitySchemes and a successful call', () => {
    const tools = [
      { name: 'count_sites_v1', securitySchemes: READ_SCHEMES },
      { name: 'add_tag_v1', securitySchemes: WRITE_SCHEMES },
    ];
    expect(v.toolListing(tools, 'count_sites_v1', 'add_tag_v1')).toEqual([]);
    expect(v.toolCallOk({ jsonrpc: '2.0', id: 1, result: { content: [{ type: 'text', text: '{"total":3}' }] } })).toEqual([]);
  });
  test('rejects a missing write tool, a wrong scheme and an error result', () => {
    expect(v.toolListing([{ name: 'count_sites_v1', securitySchemes: READ_SCHEMES }], 'count_sites_v1', 'add_tag_v1')).not.toEqual([]);
    expect(v.toolListing([
      { name: 'count_sites_v1', securitySchemes: READ_SCHEMES },
      { name: 'add_tag_v1', securitySchemes: READ_SCHEMES },
    ], 'count_sites_v1', 'add_tag_v1')).not.toEqual([]);
    expect(v.toolCallOk({ jsonrpc: '2.0', id: 1, result: { isError: true, content: [{ type: 'text', text: 'no' }] } })).not.toEqual([]);
    expect(v.toolCallOk({ jsonrpc: '2.0', id: 1, error: { code: -32601, message: 'x' } })).not.toEqual([]);
  });
  test('accepts a tool list the MCP SDK can parse and rejects a nullable object input type', () => {
    expect(v.toolListResult([{ name: 'count_sites_v1', inputSchema: { type: 'object' } }])).toEqual([]);
    expect(v.toolListResult([{ name: 'count_sites_v1', inputSchema: { type: ['object', 'null'] } }])).not.toEqual([]);
  });
});

test.describe('verdict for: a write call on a read-only grant gets the step-up challenge over HTTP', () => {
  test('accepts the step-up challenge form', () => {
    const r = res(403, { 'WWW-Authenticate': `Bearer error="insufficient_scope", scope="mainwp:read mainwp:write", resource_metadata="${PRM}", error_description="This connection can only view"` });
    expect(v.stepUpChallenge(r, PRM)).toEqual([]);
  });
  test('rejects a tool result, a read-only scope and a 401', () => {
    expect(v.stepUpChallenge(res(200, {}, '{"result":{"isError":true}}'), PRM)).not.toEqual([]);
    expect(v.stepUpChallenge(res(403, { 'WWW-Authenticate': `Bearer error="insufficient_scope", scope="mainwp:read", resource_metadata="${PRM}"` }), PRM)).not.toEqual([]);
    expect(v.stepUpChallenge(challenge401(), PRM)).not.toEqual([]);
  });
});

test.describe('verdict for: parallel refresh with one token yields one winner and a usable grace sibling', () => {
  test('accepts two token responses with distinct pairs', () => {
    expect(v.refreshRace([tokenOk('mwpat_a', 'mwprt_a'), tokenOk('mwpat_b', 'mwprt_b')])).toEqual([]);
  });
  test('rejects a losing invalid_grant and a repeated pair', () => {
    expect(v.refreshRace([tokenOk('mwpat_a', 'mwprt_a'), tokenErr('invalid_grant')])).not.toEqual([]);
    expect(v.refreshRace([tokenOk('mwpat_a', 'mwprt_a'), tokenOk('mwpat_a', 'mwprt_a')])).not.toEqual([]);
  });
});

test.describe('verdict for: refresh replay after grace revokes the family', () => {
  test('accepts invalid_grant then an invalid_token challenge', () => {
    expect(v.tokenError(tokenErr('invalid_grant'), 'invalid_grant')).toEqual([]);
    expect(v.invalidTokenChallenge(challenge401(', error="invalid_token"'), PRM)).toEqual([]);
  });
  test('rejects a fresh pair and a challenge without invalid_token', () => {
    expect(v.tokenError(tokenOk('mwpat_a', 'mwprt_a'), 'invalid_grant')).not.toEqual([]);
    expect(v.invalidTokenChallenge(challenge401(), PRM)).not.toEqual([]);
  });
});

test.describe('verdict for: token is refused on wp-abilities, mainwp v2 and admin-ajax over real HTTP', () => {
  test('accepts 401 and 403 from REST and the anonymous admin-ajax answer', () => {
    expect(v.boundaryRefused(res(401, {}, '{"code":"rest_not_logged_in"}'))).toEqual([]);
    expect(v.boundaryRefused(res(403, {}, '{"code":"rest_forbidden"}'))).toEqual([]);
    expect(v.anonymous(res(200, { 'Content-Type': 'application/json' }, '{"user_id":0}'))).toEqual([]);
  });
  test('rejects a 200 listing and a logged-in admin-ajax answer', () => {
    expect(v.boundaryRefused(res(200, {}, '[{"name":"mainwp/list-sites-v1"}]'))).not.toEqual([]);
    expect(v.anonymous(res(200, { 'Content-Type': 'application/json' }, '{"user_id":1}'))).not.toEqual([]);
  });
});

test.describe('verdict for: bearer token alone does not authenticate a dummy plugin route', () => {
  test('accepts user id 0 and a 401', () => {
    expect(v.anonymous(res(200, {}, '{"user_id":0}'))).toEqual([]);
    expect(v.anonymous(res(401, {}, '{"code":"rest_not_logged_in"}'))).toEqual([]);
  });
  test('rejects a resolved user and an unreadable body', () => {
    expect(v.anonymous(res(200, {}, '{"user_id":3}'))).not.toEqual([]);
    expect(v.anonymous(res(200, {}, 'ok'))).not.toEqual([]);
  });
});

test.describe('verdict for: bearer token alone does not open the consent screen', () => {
  test('accepts a redirect to wp-login.php', () => {
    expect(v.loginRedirect(res(302, { Location: 'https://d.example/wp-login.php?redirect_to=x' }))).toEqual([]);
  });
  test('rejects the consent page and a redirect elsewhere', () => {
    expect(v.loginRedirect(res(200, { 'Content-Type': 'text/html' }, '<body data-page="consent">'))).not.toEqual([]);
    expect(v.loginRedirect(res(302, { Location: 'http://127.0.0.1:5000/callback?code=x' }))).not.toEqual([]);
  });
});

test.describe('verdict for: consent page cannot be framed', () => {
  test("accepts DENY plus frame-ancestors 'none'", () => {
    expect(v.frameBlocked(res(200, { 'X-Frame-Options': 'DENY', 'Content-Security-Policy': "frame-ancestors 'none'; default-src 'self'; style-src 'self'" }))).toEqual([]);
  });
  test('rejects a missing or permissive header', () => {
    expect(v.frameBlocked(res(200, { 'X-Frame-Options': 'DENY' }))).not.toEqual([]);
    expect(v.frameBlocked(res(200, { 'X-Frame-Options': 'SAMEORIGIN', 'Content-Security-Policy': "frame-ancestors 'none'" }))).not.toEqual([]);
  });
});

test.describe('verdict for: revocation from the panel takes effect on the next request', () => {
  test('accepts the invalid_token challenge', () => {
    expect(v.invalidTokenChallenge(challenge401(', error="invalid_token"'), PRM)).toEqual([]);
  });
  test('rejects a 200 and a 403', () => {
    expect(v.invalidTokenChallenge(res(200, {}, '{}'), PRM)).not.toEqual([]);
    expect(v.invalidTokenChallenge(res(403, { 'WWW-Authenticate': 'Bearer error="invalid_token"' }), PRM)).not.toEqual([]);
  });
});

test.describe('verdict for: copied tokens fail on a clone with a different home', () => {
  const clonePrm = 'https://clone.example/wp-json/mainwp-remote/v1/oauth-protected-resource';
  test("accepts the clone's own invalid_token challenge", () => {
    const r = res(401, { 'WWW-Authenticate': `Bearer resource_metadata="${clonePrm}", scope="mainwp:read mainwp:write", error="invalid_token"` });
    expect(v.invalidTokenChallenge(r, clonePrm)).toEqual([]);
  });
  test("rejects a challenge naming the original's PRM", () => {
    expect(v.invalidTokenChallenge(challenge401(', error="invalid_token"'), clonePrm)).not.toEqual([]);
  });
});

test.describe('verdict for: parallel code redemption yields one grant and a valid replay revokes it', () => {
  test('accepts one winner or none, every loser invalid_grant', () => {
    expect(v.codeRace([tokenOk('mwpat_a', 'mwprt_a'), tokenErr('invalid_grant'), tokenErr('invalid_grant')])).toEqual([]);
    expect(v.codeRace([tokenErr('invalid_grant'), tokenErr('invalid_grant')])).toEqual([]);
  });
  test('rejects two winners and an unexpected error code', () => {
    expect(v.codeRace([tokenOk('mwpat_a', 'mwprt_a'), tokenOk('mwpat_b', 'mwprt_b')])).not.toEqual([]);
    expect(v.codeRace([tokenOk('mwpat_a', 'mwprt_a'), tokenErr('invalid_request')])).not.toEqual([]);
  });
});

test.describe("verdict for: well-known metadata is served on Local's stock nginx config", () => {
  const doc = {
    issuer: ISSUER,
    authorization_endpoint: `${ISSUER}/mainwp-oauth/authorize`,
    token_endpoint: `${ISSUER}/wp-json/mainwp-remote/v1/oauth/token`,
    response_types_supported: ['code'],
    code_challenge_methods_supported: ['S256'],
  };
  test('accepts our metadata with our issuer', () => {
    expect(v.metadataDocument(res(200, { 'Content-Type': 'application/json; charset=utf-8' }, JSON.stringify(doc)), ISSUER)).toEqual([]);
  });
  test("rejects nginx's 404 and the wrong install's issuer", () => {
    expect(v.metadataDocument(res(404, { 'Content-Type': 'text/html' }, '<h1>404</h1>'), ISSUER)).not.toEqual([]);
    expect(v.metadataDocument(res(200, { 'Content-Type': 'application/json' }, JSON.stringify({ ...doc, issuer: 'https://d.example/sub' })), ISSUER)).not.toEqual([]);
  });
});

test.describe('verdict for: an unregistered client_id is refused before login and nothing is fetched', () => {
  test('accepts our 400 error page', () => {
    expect(v.errorPageBeforeLogin(res(400, { 'Content-Type': 'text/html' }, '<body class="mainwp-oauth" data-page="error" data-error="unknown_client">'))).toEqual([]);
  });
  test('rejects the login hand-off and the consent page', () => {
    expect(v.errorPageBeforeLogin(res(302, { Location: 'https://d.example/wp-login.php?redirect_to=x' }))).not.toEqual([]);
    expect(v.errorPageBeforeLogin(res(200, {}, '<body data-page="consent">'))).not.toEqual([]);
  });
});

test.describe('verdict for: the kill-switch constant 404s every module path and refuses a live token', () => {
  test("accepts the module's own text/plain 404", () => {
    expect(v.moduleNotFound(res(404, { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-cache, must-revalidate, max-age=0, no-store, private' }, 'Not found'))).toEqual([]);
  });
  test('rejects a challenge, a 503 stub and a theme 404', () => {
    expect(v.moduleNotFound(challenge401())).not.toEqual([]);
    expect(v.moduleNotFound(res(503, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }, '{"error":"temporarily_unavailable"}'))).not.toEqual([]);
    expect(v.moduleNotFound(res(404, { 'Content-Type': 'text/html' }, '<html>'))).not.toEqual([]);
  });
});

test.describe('verdict for: malformed JSON gets a JSON-RPC parse error, not rest_invalid_json', () => {
  test('accepts a -32700 JSON-RPC error', () => {
    expect(v.jsonRpcParseError(res(400, { 'Content-Type': 'application/json' }, '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}'))).toEqual([]);
  });
  test("rejects WordPress's rest_invalid_json", () => {
    expect(v.jsonRpcParseError(res(400, { 'Content-Type': 'application/json' }, '{"code":"rest_invalid_json","message":"Invalid JSON body passed.","data":{"status":400}}'))).not.toEqual([]);
  });
});

test.describe('verdict for: _envelope, _jsonp and _fields leave the 401 status, challenge and body unchanged', () => {
  test('accepts an identical 401', () => {
    expect(v.sameChallenge(challenge401(), challenge401())).toEqual([]);
  });
  test('rejects an enveloped 200 and a changed body', () => {
    expect(v.sameChallenge(challenge401(), res(200, {}, '{"status":401,"headers":{}}'))).not.toEqual([]);
    expect(v.sameChallenge(challenge401(), { ...challenge401(), body: 'cb({})' })).not.toEqual([]);
  });
});

test.describe('verdict for: no CORS headers on MCP, token or PRM responses, with or without Origin', () => {
  test('accepts a response without access-control headers', () => {
    expect(v.noCors(challenge401())).toEqual([]);
  });
  test('rejects a reflected origin', () => {
    expect(v.noCors(res(401, { 'Access-Control-Allow-Origin': 'https://evil.example', 'Access-Control-Allow-Credentials': 'true' }))).not.toEqual([]);
    expect(v.noCors(res(401, { 'Access-Control-Expose-Headers': 'Link' }))).not.toEqual([]);
  });
});

test.describe('verdict for: plain-HTTP requests to authorize, consent, token and MCP are refused without state change', () => {
  test('accepts each endpoint refusal', () => {
    expect(v.httpRefusal('token', tokenErr('invalid_request'))).toEqual([]);
    expect(v.httpRefusal('mcp', res(400, { 'Content-Type': 'application/json' }, '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"HTTPS required"}}'))).toEqual([]);
    expect(v.httpRefusal('authorize', res(400, { 'Content-Type': 'text/html', 'X-Frame-Options': 'DENY' }, '<p>HTTPS required</p>'))).toEqual([]);
    expect(v.httpRefusal('consent', res(400, { 'Content-Type': 'text/html' }, '<p>HTTPS required</p>'))).toEqual([]);
  });
  test('rejects a redirect to https and a served request', () => {
    expect(v.httpRefusal('authorize', res(301, { Location: 'https://d.example/mainwp-oauth/authorize' }))).not.toEqual([]);
    expect(v.httpRefusal('token', tokenOk('mwpat_a', 'mwprt_a'))).not.toEqual([]);
    expect(v.httpRefusal('mcp', res(400, {}, '{"jsonrpc":"2.0","id":1,"error":{"code":-32600}}'))).not.toEqual([]);
  });
});

test.describe('verdict for: a plugin that blocks anonymous REST does not intercept the MCP challenge', () => {
  test('accepts our 401 challenge without an error parameter', () => {
    expect(v.missingTokenChallenge(challenge401(), PRM)).toEqual([]);
  });
  test("rejects the plugin's REST error and a read-only scope hint", () => {
    expect(v.missingTokenChallenge(res(401, { 'Content-Type': 'application/json' }, '{"code":"harness_rest_blocked"}'), PRM)).not.toEqual([]);
    expect(v.missingTokenChallenge(res(401, { 'WWW-Authenticate': `Bearer resource_metadata="${PRM}", scope="mainwp:read"` }), PRM)).not.toEqual([]);
  });
});

test.describe('verdict for: plain-permalink consent approval posts to the query authorize endpoint over HTTPS', () => {
  const endpoint = 'https://d.example/?mainwp_oauth=authorize';
  test('accepts a POST to the metadata authorization endpoint', () => {
    expect(v.consentPostTarget({ method: 'POST', url: endpoint }, endpoint)).toEqual([]);
  });
  test('rejects the hard-coded path form and plain HTTP', () => {
    expect(v.consentPostTarget({ method: 'POST', url: 'https://d.example/mainwp-oauth/authorize' }, endpoint)).not.toEqual([]);
    expect(v.consentPostTarget({ method: 'POST', url: 'http://d.example/?mainwp_oauth=authorize' }, 'http://d.example/?mainwp_oauth=authorize')).not.toEqual([]);
  });
});

test.describe('verdict for: Claude Code lists and calls a read tool on a root, a subdirectory and a plain-permalink install', () => {
  const init = { type: 'system', subtype: 'init', tools: ['Bash', 'mcp__h__count_sites_v1', 'mcp__h__add_tag_v1'], mcp_servers: [{ name: 'h', status: 'connected' }] };
  const use = { type: 'assistant', message: { content: [{ type: 'tool_use', id: 'tu1', name: 'mcp__h__count_sites_v1', input: {} }] } };
  const result = (isError: boolean) => ({ type: 'user', message: { content: [{ type: 'tool_result', tool_use_id: 'tu1', is_error: isError, content: '{"total":3}' }] } });
  test('accepts a connected server listing both tools and a successful read call', () => {
    expect(v.claudeCodeRun([init, use, result(false)], 'h', 'count_sites_v1', 'add_tag_v1')).toEqual([]);
  });
  test('rejects a failed call, a missing write tool and a server that never connected', () => {
    expect(v.claudeCodeRun([init, use, result(true)], 'h', 'count_sites_v1', 'add_tag_v1')).not.toEqual([]);
    expect(v.claudeCodeRun([{ ...init, tools: ['mcp__h__count_sites_v1'] }, use, result(false)], 'h', 'count_sites_v1', 'add_tag_v1')).not.toEqual([]);
    expect(v.claudeCodeRun([{ ...init, mcp_servers: [{ name: 'h', status: 'needs-auth' }] }, use, result(false)], 'h', 'count_sites_v1', 'add_tag_v1')).not.toEqual([]);
  });
});

test.describe('verdict for: official conformance: authorization server metadata and code grant pass', () => {
  const ok = (id: string) => ({ id, status: 'SUCCESS' });
  test('accepts every check SUCCESS including the CIMD check', () => {
    expect(v.allChecksSuccess([ok('authorization-server-metadata-cimd'), ok('authorization-code-grant')], ['authorization-server-metadata-cimd'])).toEqual([]);
  });
  test('rejects a WARNING and a missing required check', () => {
    expect(v.allChecksSuccess([{ id: 'authorization-server-metadata-cimd', status: 'WARNING' }], ['authorization-server-metadata-cimd'])).not.toEqual([]);
    expect(v.allChecksSuccess([ok('authorization-code-grant')], ['authorization-server-metadata-cimd'])).not.toEqual([]);
    expect(v.allChecksSuccess([], [])).not.toEqual([]);
  });
});

for (const revision of ['2026-07-28', '2025-11-25']) {
  test.describe(`verdict for: official conformance: ${revision} server requirements pass against the reviewed baseline`, () => {
    test('accepts exit 0', () => {
      expect(v.serverLeg({ exitCode: 0, signal: null })).toEqual([]);
    });
    test('rejects exit 1 and a killed run', () => {
      expect(v.serverLeg({ exitCode: 1, signal: null })).not.toEqual([]);
      expect(v.serverLeg({ exitCode: null, signal: 'SIGTERM' })).not.toEqual([]);
    });
  });
}
