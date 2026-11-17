// Offline checks of the harness itself: URL forms, PKCE, challenge parsing, the loopback callback
// and the bearer proxy. They run without a Dashboard, so a broken helper shows up before a run
// against a deployed copy.
import { test, expect } from '@playwright/test';
import http from 'node:http';
import type { AddressInfo } from 'node:net';
import { endpoints, wellKnown, canonicalHome } from '../lib/urls.js';
import { pkcePair, challengeFor } from '../lib/pkce.js';
import { parseBearerChallenge } from '../lib/challenge.js';
import { startCallbackServer } from '../lib/callback.js';
import { raw } from '../lib/http.js';
import { startBearerProxy } from '../conformance/bearer-proxy.js';

test('endpoint URLs follow the permalink mode and the stored home', () => {
  const pretty = endpoints({ name: 'root', home: 'https://dash.example.com', permalinks: 'pretty' });
  expect(pretty.issuer).toBe('https://dash.example.com');
  expect(pretty.mcp).toBe('https://dash.example.com/wp-json/mainwp-remote/v1/mcp');
  expect(pretty.prm).toBe('https://dash.example.com/wp-json/mainwp-remote/v1/oauth-protected-resource');
  expect(pretty.token).toBe('https://dash.example.com/wp-json/mainwp-remote/v1/oauth/token');
  expect(pretty.authorize).toBe('https://dash.example.com/mainwp-oauth/authorize');

  const plain = endpoints({ name: 'plain', home: 'https://example.com/sub', permalinks: 'plain' });
  expect(plain.issuer).toBe('https://example.com/sub');
  expect(plain.mcp).toBe('https://example.com/sub/?rest_route=/mainwp-remote/v1/mcp');
  expect(plain.prm).toBe('https://example.com/sub/?rest_route=/mainwp-remote/v1/oauth-protected-resource');
  expect(plain.token).toBe('https://example.com/sub/?rest_route=/mainwp-remote/v1/oauth/token');
  expect(plain.authorize).toBe('https://example.com/sub/?mainwp_oauth=authorize');
});

test('well-known forms are path-insert and path-append of the home path', () => {
  expect(wellKnown('https://dash.example.com', 'oauth-authorization-server')).toEqual({
    pathInsert: 'https://dash.example.com/.well-known/oauth-authorization-server',
    pathAppend: 'https://dash.example.com/.well-known/oauth-authorization-server',
  });
  expect(wellKnown('https://example.com/sub', 'openid-configuration')).toEqual({
    pathInsert: 'https://example.com/.well-known/openid-configuration/sub',
    pathAppend: 'https://example.com/sub/.well-known/openid-configuration',
  });
});

test('canonical home lowercases scheme and host and drops port 443', () => {
  expect(canonicalHome('HTTPS://Dash.Example.COM:443/Sub/')).toBe('https://dash.example.com/Sub');
  expect(canonicalHome('https://dash.example.com:8443')).toBe('https://dash.example.com:8443');
});

test('S256 challenge matches the RFC 7636 appendix B vector', () => {
  expect(challengeFor('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk')).toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});

test('generated verifier fits the RFC 7636 grammar and differs per call', () => {
  const a = pkcePair();
  const b = pkcePair();
  expect(a.verifier).toMatch(/^[A-Za-z0-9._~-]{43,128}$/);
  expect(a.challenge).toMatch(/^[A-Za-z0-9_-]{43}$/);
  expect(a.challenge).toBe(challengeFor(a.verifier));
  expect(a.verifier).not.toBe(b.verifier);
});

test('parses a quoted Bearer challenge', () => {
  const parsed = parseBearerChallenge(
    'Bearer error="insufficient_scope", scope="mainwp:read mainwp:write", resource_metadata="https://d.example/wp-json/mainwp-remote/v1/oauth-protected-resource", error_description="This connection can only view"',
  );
  expect(parsed).toEqual({
    error: 'insufficient_scope',
    scope: 'mainwp:read mainwp:write',
    resource_metadata: 'https://d.example/wp-json/mainwp-remote/v1/oauth-protected-resource',
    error_description: 'This connection can only view',
  });
  expect(parseBearerChallenge('Basic realm="x"')).toBeNull();
  expect(parseBearerChallenge('')).toBeNull();
});

test('loopback callback server captures the redirect query', async () => {
  const callback = await startCallbackServer();
  try {
    expect(callback.redirectUri).toMatch(/^http:\/\/127\.0\.0\.1:\d+\/callback$/);
    const res = await raw(`${callback.redirectUri}?code=mwpac_x&state=s1&iss=https%3A%2F%2Fd.example`);
    expect(res.status).toBe(200);
    const params = await callback.wait(1000);
    expect(params.get('code')).toBe('mwpac_x');
    expect(params.get('state')).toBe('s1');
    expect(params.get('iss')).toBe('https://d.example');
  } finally {
    await callback.close();
  }
});

async function upstream(): Promise<{ url: string; seen: http.IncomingMessage[]; bodies: string[]; close: () => Promise<void> }> {
  const seen: http.IncomingMessage[] = [];
  const bodies: string[] = [];
  const server = http.createServer((req, res) => {
    const chunks: Buffer[] = [];
    req.on('data', (c: Buffer) => chunks.push(c));
    req.on('end', () => {
      seen.push(req);
      bodies.push(Buffer.concat(chunks).toString('utf8'));
      res.writeHead(418, { 'Content-Type': 'application/json', 'X-Upstream': 'yes' });
      res.end('{"jsonrpc":"2.0","id":7,"result":{}}');
    });
  });
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  const { port } = server.address() as AddressInfo;
  return {
    url: `http://127.0.0.1:${port}`,
    seen,
    bodies,
    close: () => new Promise((resolve) => server.close(() => resolve())),
  };
}

test('bearer proxy adds the token, sets Host and passes the response through unchanged', async () => {
  const up = await upstream();
  const proxy = await startBearerProxy({ target: up.url, token: 'mwpat_test', host: 'dash.example.com' });
  try {
    const res = await raw(`${proxy.url}/wp-json/mainwp-remote/v1/mcp?x=1`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'MCP-Protocol-Version': '2026-07-28', Origin: 'https://evil.example' },
      body: '{"jsonrpc":"2.0","id":7,"method":"tools/list"}',
    });
    expect(res.status).toBe(418);
    expect(res.headers['x-upstream']).toBe('yes');
    expect(res.body).toBe('{"jsonrpc":"2.0","id":7,"result":{}}');
    const req = up.seen[0];
    expect(req.url).toBe('/wp-json/mainwp-remote/v1/mcp?x=1');
    expect(req.method).toBe('POST');
    expect(req.headers.authorization).toBe('Bearer mwpat_test');
    expect(req.headers.host).toBe('dash.example.com');
    expect(req.headers['mcp-protocol-version']).toBe('2026-07-28');
    expect(req.headers.origin).toBe('https://evil.example');
    expect(up.bodies[0]).toBe('{"jsonrpc":"2.0","id":7,"method":"tools/list"}');
  } finally {
    await proxy.close();
    await up.close();
  }
});

test('bearer proxy listens on loopback only and replaces a client Authorization header', async () => {
  const up = await upstream();
  const proxy = await startBearerProxy({ target: up.url, token: 'mwpat_real', host: 'dash.example.com' });
  try {
    expect(proxy.address).toBe('127.0.0.1');
    await raw(`${proxy.url}/x`, { headers: { Authorization: 'Bearer mwpat_client' } });
    expect(up.seen[0].headers.authorization).toBe('Bearer mwpat_real');
  } finally {
    await proxy.close();
    await up.close();
  }
});
