import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { HARNESS_DIR } from '../lib/config.js';
import { baselineProblems, parseBaseline } from '../lib/baseline.js';
import { redact } from '../lib/conformance.js';
import { verifyCloneCopy, verifyHttpConsent, verifyDisabledPaths, verifyRead } from '../lib/workflows.js';
import type { RawResponse } from '../lib/http.js';

const refused: RawResponse = { status: 404, headers: { 'content-type': 'text/plain', 'cache-control': 'no-store' }, body: 'Not found' };
const revisionText = (revision: string) => fs.readFileSync(path.join(HARNESS_DIR, 'conformance', `baseline-${revision}.yml`), 'utf8');

test('baseline matching: whole-scenario baselines cannot hide wire failures', () => {
  expect(baselineProblems('server:\n  - tools-call-simple-text # (b) fixture tool is absent\n', ['tools-call-simple-text'])).not.toEqual([]);
  for (const revision of ['2026-07-28', '2025-11-25']) {
    expect(parseBaseline(revisionText(revision)).entries.filter((e) => !e.id.includes(':'))).toEqual([]);
  }
});

test('redaction: every frozen credential prefix and callback parameter is redacted before persistence', () => {
  const secrets = ['mwpat_', 'mwprt_', 'mwpac_', 'mwpct_', 'mwpap_'].map((p) => `${p}synthetic-secret`);
  const output = redact(`${secrets.join(' ')} https://client.example/callback?code=opaque-code&state=opaque-state`);
  expect(secrets.filter((secret) => output.includes(secret))).toEqual([]);
  expect(output).not.toContain('opaque-code');
  expect(output).not.toContain('opaque-state');
});

test('caching exclusions: only the three unsupported resource caching checks are excluded', () => {
  const ids = parseBaseline(revisionText('2026-07-28')).entries.filter((e) => e.id.startsWith('caching:')).map((e) => e.id).sort();
  expect(ids).toEqual([
    'caching:sep-2549-resources-list-caching-hints',
    'caching:sep-2549-resources-read-caching-hints',
    'caching:sep-2549-resources-templates-list-caching-hints',
  ]);
});

test('clone workflow: rejects a successful no-op copy before changed-home requests', async () => {
  const events: string[] = [];
  await expect(verifyCloneCopy(async () => { events.push('sync'); }, async (side) => {
    events.push(side);
    return side === 'source' ? { identity: 'a'.repeat(64), state: 'b'.repeat(64), tokens: 2 } : { identity: 'c'.repeat(64), state: 'd'.repeat(64), tokens: 0 };
  }, async () => { events.push('request'); })).rejects.toThrow(/clone|copied/i);
  expect(events).not.toContain('request');
});

test('consent workflow: sends login authentication and rejects a login-required refusal', async () => {
  let approved = false;
  await expect(verifyHttpConsent({ formAction: 'https://dash.example/mainwp-oauth/authorize', requestKey: 'pending', nonce: 'nonce' },
    [{ name: 'wordpress_logged_in_hash', value: 'synthetic-session' }], async (_url, request) => {
      if (!request.headers?.Cookie?.includes('wordpress_logged_in_hash=synthetic-session')) {
        return { status: 400, headers: {}, body: '<body data-page="error" data-error="login_required">' };
      }
      expect(new URLSearchParams(request.body).get('request_key')).toBe('pending');
      return { status: 400, headers: {}, body: '<title>HTTPS required</title>' };
    }, async () => { approved = true; })).resolves.toBeUndefined();
  expect(approved).toBe(true);
  await expect(verifyHttpConsent({ formAction: 'https://dash.example/mainwp-oauth/authorize', requestKey: 'pending', nonce: 'nonce' },
    [{ name: 'wordpress_logged_in_hash', value: 'synthetic-session' }], async () => ({ status: 400, headers: {}, body: '<body data-error="login_required">' }), async () => {})).rejects.toThrow(/HTTPS/);
});

test('read workflow: refuses approval confirmation and job results even when the tag is absent', () => {
  for (const status of ['APPROVAL_REQUIRED', 'CONFIRMATION_REQUIRED', 'JOB_STARTED']) {
    const payload = { status, approval_url: 'https://dash.example/approve', confirmation_token: 'synthetic', job_id: 1 };
    const message = { jsonrpc: '2.0', id: 1, result: { isError: false, structuredContent: payload, content: [{ type: 'text', text: JSON.stringify(payload) }] } };
    expect(() => verifyRead(message, 'count_sites_v1')).toThrow();
    expect(() => verifyRead(message, 'list_tags_v1', 'absent-tag')).toThrow();
  }
});

const disabledTargets = {
  root: [
    'GET /mainwp-oauth/authorize', 'POST /mainwp-oauth/authorize',
    'GET /mainwp-oauth/approve', 'POST /mainwp-oauth/approve',
    'POST /wp-json/mainwp-remote/v1/register', 'POST /wp-json/mainwp-remote/v1/oauth/token',
    'GET /wp-json/mainwp-remote/v1/oauth-protected-resource', 'POST /wp-json/mainwp-remote/v1/mcp',
    'POST /?rest_route=/mainwp-remote/v1/register', 'POST /?rest_route=/mainwp-remote/v1/oauth/token',
    'GET /?rest_route=/mainwp-remote/v1/oauth-protected-resource', 'POST /?rest_route=/mainwp-remote/v1/mcp',
  ],
  plain: [
    'GET /?mainwp_oauth=authorize', 'POST /?mainwp_oauth=authorize',
    'GET /?mainwp_oauth=approve', 'POST /?mainwp_oauth=approve',
    'POST /?rest_route=/mainwp-remote/v1/register', 'POST /?rest_route=/mainwp-remote/v1/oauth/token',
    'GET /?rest_route=/mainwp-remote/v1/oauth-protected-resource', 'POST /?rest_route=/mainwp-remote/v1/mcp',
  ],
};

test('kill-switch workflow: detects each reachable surface on its own install', async () => {
  for (const name of ['root', 'plain'] as const) {
    for (const target of disabledTargets[name]) {
      await expect(verifyDisabledPaths({ name, home: `https://${name}.example`, permalinks: name === 'plain' ? 'plain' : 'pretty' }, 'mwpat_test', 'mwprt_test', 'client',
        async (url, request) => `${request?.method ?? 'GET'} ${url.replace(`https://${name}.example`, '')}` === target ? { status: 200, headers: {}, body: 'reachable' } : refused), `${name}: ${target}`).rejects.toThrow();
    }
  }
});

test('status gate: redcheck.sh exits nonzero on a missed mutation despite a green restored suite', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'rmcp-redcheck-selftest-'));
  try {
    const harness = path.join(root, 'tests/e2e/remote-mcp');
    fs.mkdirSync(path.join(harness, 'node_modules/.bin'), { recursive: true });
    fs.cpSync(path.join(HARNESS_DIR, 'lib'), path.join(harness, 'lib'), { recursive: true });
    fs.cpSync(path.join(HARNESS_DIR, 'conformance'), path.join(harness, 'conformance'), { recursive: true });
    fs.copyFileSync(path.join(HARNESS_DIR, 'redcheck.sh'), path.join(harness, 'redcheck.sh'));
    fs.writeFileSync(path.join(harness, 'node_modules/.bin/playwright'), '#!/bin/sh\nprintf "  1 passed\\n"\nexit 0\n', { mode: 0o755 });
    // The bash running the outer redcheck is known to be 4+; PATH's bash may be macOS's 3.2.
    const shell = process.env.RMCP_REDCHECK_BASH ?? 'bash';
    const major = Number(spawnSync(shell, ['-c', 'echo "${BASH_VERSINFO[0]}"'], { encoding: 'utf8' }).stdout.trim());
    test.skip(!(major >= 4), 'redcheck.sh needs bash 4 or newer');
    const run = spawnSync(shell, [path.join(harness, 'redcheck.sh')], { encoding: 'utf8' });
    expect(run.stdout).toContain('RED-MISS');
    expect(run.status).toBe(1);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

function suiteFixture(source: string): unknown {
  const run = spawnSync(process.execPath, ['--experimental-vm-modules', '--input-type=module', '-e', source], { cwd: HARNESS_DIR, encoding: 'utf8' });
  expect(run.status, run.stderr).toBe(0);
  return JSON.parse(run.stdout);
}

test('baseline matching: the pinned matcher keeps implementation and harness wire failures outside both baselines', () => {
  const result = suiteFixture(`
    import fs from 'node:fs';
    import {loadPinnedSuite} from './conformance/memory-suite.mjs';
    const suite = await loadPinnedSuite();
    const result = [];
    for (const revision of ['2026-07-28', '2025-11-25']) {
      const text = fs.readFileSync('./conformance/baseline-' + revision + '.yml', 'utf8');
      const entries = [...text.matchAll(/^  - ([^ #]+)/gm)].map((m) => {
        const [scenario, checkId] = m[1].split(':'); return {scenario, checkId};
      });
      result.push(suite.matchBaseline([{scenario:'tools-call-simple-text', checks:[
        {id:'tools-call-simple-text',status:'FAILURE'},
        {id:'wire-schema-valid',status:'FAILURE'},
        {id:'wire-schema-harness-error',status:'FAILURE'},
      ]}], entries));
    }
    console.log(JSON.stringify(result));
  `) as Array<{ exitCode: number; unexpectedFailures: string[] }>;
  for (const revision of result) {
    expect(revision.exitCode).toBe(1);
    expect(revision.unexpectedFailures).toEqual(['tools-call-simple-text:wire-schema-valid', 'tools-call-simple-text:wire-schema-harness-error']);
  }
});

test('redaction: pinned authorization scenarios keep callback and token details in memory', () => {
  const result = suiteFixture(`
    import fs from 'node:fs';
    import {loadPinnedSuite, collectAuthorizationChecks} from './conformance/memory-suite.mjs';
    const suite = await loadPinnedSuite();
    let writes = 0;
    fs.promises.writeFile = async () => { writes++; throw new Error('raw suite write'); };
    for (const name of suite.authorizationNames()) {
      suite.authorizationDefinition(name).run = async (_settings, context) => {
        if (name === 'authorization-code-grant' && !context['authorization-server-metadata-endpoint']) throw new Error('metadata context lost');
        return [{id:name,status:'SUCCESS',details:{authorizationResponseUrl:'http://127.0.0.1/callback?code=synthetic-code&state=synthetic-state',access_token:'mwpat_synthetic',confirmation:'mwpap_synthetic'}}];
      };
    }
    const print = console.log; console.log = () => {};
    const checks = await collectAuthorizationChecks(suite, {});
    print(JSON.stringify({writes,checks}));
  `) as { writes: number; checks: Array<{ id: string; status: string }> };
  expect(result).toEqual({ writes: 0, checks: [
    { id: 'authorization-server-metadata-endpoint', status: 'SUCCESS' },
    { id: 'authorization-code-grant', status: 'SUCCESS' },
  ] });
});

test('clone workflow: requires matching identity and token state and orders observation before HTTP', async () => {
  const proof = { identity: 'a'.repeat(64), state: 'b'.repeat(64), tokens: 2 };
  const events: string[] = [];
  await verifyCloneCopy(async () => { events.push('sync'); }, async (side) => { events.push(side); return proof; }, async () => { events.push('HTTP'); });
  expect(events).toEqual(['source', 'sync', 'clone', 'HTTP']);
  for (const clone of [{ ...proof, tokens: 0 }, { ...proof, identity: 'c'.repeat(64) }, { ...proof, state: 'd'.repeat(64) }, { ...proof, tokens: undefined }]) {
    await expect(verifyCloneCopy(async () => {}, async (side) => side === 'source' ? proof : clone, async () => {})).rejects.toThrow(/copied/);
  }
});

test('consent workflow: refuses missing authentication before sending or retrying', async () => {
  const events: string[] = [];
  await expect(verifyHttpConsent({ formAction: 'https://dash.example/mainwp-oauth/authorize', requestKey: 'pending', nonce: 'nonce' }, [],
    async () => { events.push('HTTP'); return refused; }, async () => { events.push('approve'); })).rejects.toThrow(/login cookie/);
  expect(events).toEqual([]);
});

test('read workflow: completed reads validate the selected ability payload before checking tag absence', () => {
  const message = (payload: unknown) => ({ jsonrpc: '2.0', id: 1, result: { isError: false, structuredContent: payload, content: [{ type: 'text', text: JSON.stringify(payload) }] } });
  expect(() => verifyRead(message({ total: 3 }), 'count_sites_v1')).not.toThrow();
  expect(() => verifyRead(message({ items: [], page: 1, per_page: 10, total: 0 }), 'list_tags_v1', 'absent-tag')).not.toThrow();
  expect(() => verifyRead(message({ items: [{ id: 1, name: 'created-tag' }], page: 1, per_page: 10, total: 1 }), 'list_tags_v1', 'created-tag')).toThrow(/created/);
  for (const payload of [{}, { total: '3' }, { total: -1 }, { total: 1, items: 'not-an-array' }]) {
    expect(() => verifyRead(message(payload), 'list_tags_v1', 'absent-tag')).toThrow();
  }
});

test('kill-switch workflow: checks the exact URL set per install with a live bearer', async () => {
  for (const name of ['root', 'plain'] as const) {
    const seen: string[] = [];
    await expect(verifyDisabledPaths({ name, home: `https://${name}.example`, permalinks: name === 'plain' ? 'plain' : 'pretty' }, 'mwpat_test', 'mwprt_test', 'client', async (url, request) => {
      const target = `${request?.method ?? 'GET'} ${url.replace(`https://${name}.example`, '')}`;
      seen.push(target);
      if (!disabledTargets[name].includes(target)) return { status: 200, headers: { 'content-type': 'text/html' }, body: 'WordPress front page' };
      if (url.endsWith('/mcp')) expect(request?.headers?.Authorization).toBe('Bearer mwpat_test');
      return refused;
    })).resolves.toBeUndefined();
    expect(seen.sort()).toEqual([...disabledTargets[name]].sort());
  }
});

test('status gate: refuses missing checks and a failing restored suite', () => {
  for (const counts of ['31 0 31 32 0', '32 0 32 32 1', '32 1 33 32 0']) {
    const run = spawnSync('bash', ['-c', 'source "$1"; shift; redcheck_status "$@"', 'gate', path.join(HARNESS_DIR, 'lib/redcheck-status.sh'), ...counts.split(' ')]);
    expect(run.status).toBe(1);
  }
  const green = spawnSync('bash', ['-c', 'source "$1"; redcheck_status 32 0 32 32 0', 'gate', path.join(HARNESS_DIR, 'lib/redcheck-status.sh')]);
  expect(green.status).toBe(0);
});

test('clone workflow: the observer fingerprints copied live rows without booting WordPress or exposing stored hashes', () => {
  const observe = (kind: string) => spawnSync('php', [path.join(HARNESS_DIR, 'selftest/clone-observer-fixture.php')], {
    input: JSON.stringify({ comparison_key: 'f'.repeat(64), access: 'mwpat_synthetic', refresh: 'mwprt_synthetic', resource: 'https://dash.example/wp-json/mainwp-remote/v1/mcp' }),
    encoding: 'utf8', env: { ...process.env, RMCP_OBSERVER_CASE: kind },
  });
  const source = observe('source');
  const clone = observe('clone');
  expect(source.status, source.stderr).toBe(0);
  expect(clone.status, clone.stderr).toBe(0);
  const sourceProof = JSON.parse(source.stdout);
  const cloneProof = JSON.parse(clone.stdout);
  expect(cloneProof).toEqual({ ...sourceProof, home: 'https://clone.example' });
  expect(sourceProof.tokens).toBe(2);
  expect(source.stdout).not.toMatch(/mwpat_|mwprt_|token_hash|e275631b/);
  expect(observe('empty').status).toBe(1);
});

test('read workflow: Claude read results also refuse approval confirmation and job responses', async () => {
  const { claudeCodeRun } = await import('../lib/verdicts.js');
  const init = { type: 'system', subtype: 'init', tools: ['mcp__h__count_sites_v1', 'mcp__h__add_tag_v1'], mcp_servers: [{ name: 'h', status: 'connected' }] };
  const use = { type: 'assistant', message: { content: [{ type: 'tool_use', id: 'call', name: 'mcp__h__count_sites_v1' }] } };
  for (const status of ['APPROVAL_REQUIRED', 'CONFIRMATION_REQUIRED', 'JOB_STARTED']) {
    const result = { type: 'user', message: { content: [{ type: 'tool_result', tool_use_id: 'call', is_error: false, content: JSON.stringify({ status }) }] } };
    expect(claudeCodeRun([init, use, result], 'h', 'count_sites_v1', 'add_tag_v1')).not.toEqual([]);
  }
});

test('caching exclusions: the pinned matcher permits resource exclusions while retaining applicable caching failures', () => {
  const result = suiteFixture(`
    import fs from 'node:fs';
    import {loadPinnedSuite} from './conformance/memory-suite.mjs';
    const suite = await loadPinnedSuite();
    const text = fs.readFileSync('./conformance/baseline-2026-07-28.yml', 'utf8');
    const entries = [...text.matchAll(/^  - ([^ #]+)/gm)].map((m) => {
      const [scenario, checkId] = m[1].split(':'); return {scenario, checkId};
    });
    const checks = ['sep-2549-resources-list-caching-hints', 'sep-2549-resources-templates-list-caching-hints', 'sep-2549-resources-read-caching-hints'].map((id) => ({id,status:'FAILURE'}));
    const permitted = suite.matchBaseline([{scenario:'caching',checks}],entries);
    const applicable = suite.matchBaseline([{scenario:'caching',checks:[...checks,{id:'sep-2549-tools-list-caching-hints',status:'FAILURE'}]}],entries);
    console.log(JSON.stringify({permitted,applicable}));
  `) as { permitted: { exitCode: number }; applicable: { exitCode: number; unexpectedFailures: string[] } };
  expect(result.permitted.exitCode).toBe(0);
  expect(result.applicable.exitCode).toBe(1);
  expect(result.applicable.unexpectedFailures).toEqual(['caching:sep-2549-tools-list-caching-hints']);
});
