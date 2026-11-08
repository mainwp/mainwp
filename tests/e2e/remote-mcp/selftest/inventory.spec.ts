// The named checks and the conformance baselines are a fixed contract: a renamed check or a
// baseline entry outside the three allowed classes must fail before anyone runs the suite.
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { HARNESS_DIR } from '../lib/config.js';
import { baselineProblems, parseBaseline, requiredServerScenarios } from '../lib/baseline.js';
import { specTitles } from '../lib/inventory.js';

// The named checks, in order, with the file each one belongs to.
const NAMED: Array<[string, string]> = [
  ['oauth-flow.spec.ts', 'full flow on a root install lists and calls a read tool'],
  ['oauth-flow.spec.ts', 'a write call on a read-only grant gets the step-up challenge over HTTP'],
  ['oauth-flow.spec.ts', 'parallel refresh with one token yields one winner and a usable grace sibling'],
  ['oauth-flow.spec.ts', 'refresh replay after grace revokes the family'],
  ['boundary.spec.ts', 'token is refused on wp-abilities, mainwp v2 and admin-ajax over real HTTP'],
  ['boundary.spec.ts', 'bearer token alone does not authenticate a dummy plugin route'],
  ['boundary.spec.ts', 'bearer token alone does not open the consent screen'],
  ['boundary.spec.ts', 'consent page cannot be framed'],
  ['oauth-flow.spec.ts', 'revocation from the panel takes effect on the next request'],
  ['clone.spec.ts', 'copied tokens fail on a clone with a different home'],
  ['oauth-flow.spec.ts', 'parallel code redemption yields one grant and a valid replay revokes it'],
  ['nginx.spec.ts', "well-known metadata is served on Local's stock nginx config"],
  ['oauth-flow.spec.ts', 'an unregistered client_id is refused before login and nothing is fetched'],
  ['kill-switch.spec.ts', 'the kill-switch constant 404s every module path and refuses a live token'],
  ['http-contract.spec.ts', 'malformed JSON gets a JSON-RPC parse error, not rest_invalid_json'],
  ['http-contract.spec.ts', '_envelope, _jsonp and _fields leave the 401 status, challenge and body unchanged'],
  ['http-contract.spec.ts', 'no CORS headers on MCP, token or PRM responses, with or without Origin'],
  ['http-contract.spec.ts', 'plain-HTTP requests to authorize, consent, token and MCP are refused without state change'],
  ['http-contract.spec.ts', 'a plugin that blocks anonymous REST does not intercept the MCP challenge'],
  ['boundary.spec.ts', 'plain-permalink consent approval posts to the query authorize endpoint over HTTPS'],
  ['claude-code.spec.ts', 'Claude Code lists and calls a read tool on a root, a subdirectory and a plain-permalink install'],
  ['conformance.spec.ts', 'official conformance: authorization server metadata and code grant pass'],
  ['conformance.spec.ts', 'official conformance: 2026-07-28 server requirements pass against the reviewed baseline'],
  ['conformance.spec.ts', 'official conformance: 2025-11-25 server requirements pass against the reviewed baseline'],
];

test('every named check exists exactly once, in its file, and nothing else is named there', () => {
  expect(NAMED).toHaveLength(24);
  const files = [...new Set(NAMED.map(([file]) => file))];
  for (const file of files) {
    const source = fs.readFileSync(path.join(HARNESS_DIR, 'specs', file), 'utf8');
    const expected = NAMED.filter(([f]) => f === file).map(([, title]) => title).sort();
    expect(specTitles(source).sort(), file).toEqual(expected);
  }
  expect(fs.readdirSync(path.join(HARNESS_DIR, 'specs')).filter((f) => f.endsWith('.spec.ts')).sort()).toEqual(files.sort());
});

test('the conformance suite is pinned exactly and locked', () => {
  const pkg = JSON.parse(fs.readFileSync(path.join(HARNESS_DIR, 'package.json'), 'utf8'));
  expect(pkg.devDependencies['@modelcontextprotocol/conformance']).toBe('0.2.0-alpha.12');
  const lock = JSON.parse(fs.readFileSync(path.join(HARNESS_DIR, 'package-lock.json'), 'utf8'));
  expect(lock.packages['node_modules/@modelcontextprotocol/conformance'].version).toBe('0.2.0-alpha.12');
});

for (const revision of ['2026-07-28', '2025-11-25']) {
  test(`baseline-${revision}.yml names only required scenarios, each with a class and a reason`, () => {
    const listing = execFileSync(path.join(HARNESS_DIR, 'node_modules', '.bin', 'conformance'), ['list', '--server', '--requirements', revision], { encoding: 'utf8' });
    const required = requiredServerScenarios(listing);
    expect(required.length).toBeGreaterThan(10);
    const text = fs.readFileSync(path.join(HARNESS_DIR, 'conformance', `baseline-${revision}.yml`), 'utf8');
    expect(baselineProblems(text, required)).toEqual([]);
    expect(parseBaseline(text).entries.length).toBeGreaterThan(0);
  });
}

test('baseline rules reject a missing reason, an unknown class, a client section and a wholesale-plus-check pair', () => {
  const required = ['tools-list', 'resources-list', 'server-stateless'];
  expect(baselineProblems('server:\n  - resources-list:resources-list # (a) resources are not declared in v1\n', required)).toEqual([]);
  expect(baselineProblems('server:\n  - resources-list\n', required)).not.toEqual([]);
  expect(baselineProblems('server:\n  - resources-list # (d) flaky\n', required)).not.toEqual([]);
  expect(baselineProblems('server:\n  - not-a-scenario # (b) fixture\n', required)).not.toEqual([]);
  expect(baselineProblems('client:\n  - tools_call # (b) fixture\n', required)).not.toEqual([]);
  expect(baselineProblems('server:\n  - server-stateless # (a) x\n  - server-stateless:some-check # (a) y\n', required)).not.toEqual([]);
});
