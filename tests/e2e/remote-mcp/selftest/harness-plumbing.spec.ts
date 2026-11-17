import { test, expect } from '@playwright/test';
import childProcess from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { HARNESS_DIR } from '../lib/config.js';
import { parseBaseline } from '../lib/baseline.js';
import { withMuPlugin } from '../lib/mu-plugins.js';

test('plain kill-switch plugin uses the plain install directory and is removed on failure', async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'rmcp-mu-'));
  const rootDir = path.join(root, 'root');
  const plainDir = path.join(root, 'plain');
  fs.mkdirSync(rootDir);
  fs.mkdirSync(plainDir);
  const previous = [process.env.RMCP_E2E_ROOT_MU_PLUGINS_DIR, process.env.RMCP_E2E_PLAIN_MU_PLUGINS_DIR];
  process.env.RMCP_E2E_ROOT_MU_PLUGINS_DIR = rootDir;
  process.env.RMCP_E2E_PLAIN_MU_PLUGINS_DIR = plainDir;
  try {
    const source = fs.readFileSync(path.join(HARNESS_DIR, 'mu-plugins/kill-switch.php'), 'utf8');
    const target = 'mainwp-rmcp-harness-kill-switch.php';
    await expect(withMuPlugin('kill-switch', async () => {
      expect(fs.readdirSync(rootDir)).toEqual([]);
      expect(fs.existsSync(path.join(plainDir, target))).toBe(true);
      expect(fs.readFileSync(path.join(plainDir, target), 'utf8')).toBe(source);
      throw new Error('probe failed');
    }, 'plain')).rejects.toThrow('probe failed');
    expect(fs.readdirSync(plainDir)).toEqual([]);
    expect(fs.readdirSync(rootDir)).toEqual([]);
  } finally {
    for (const [index, name] of ['RMCP_E2E_ROOT_MU_PLUGINS_DIR', 'RMCP_E2E_PLAIN_MU_PLUGINS_DIR'].entries()) {
      if (previous[index] === undefined) delete process.env[name];
      else process.env[name] = previous[index];
    }
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('SSE baselines match the pinned session check and explain the missing session id', () => {
  const run = childProcess.spawnSync(process.execPath, ['--experimental-vm-modules', '--input-type=module', '-e', `
    import {loadPinnedSuite} from './conformance/memory-suite.mjs';
    const suite = await loadPinnedSuite();
    globalThis.fetch = async (_url, options = {}) => {
      if ((options.method ?? 'GET') === 'GET') return new Response(null, {status:405});
      const body = JSON.parse(options.body);
      if (!('id' in body)) return new Response(null, {status:202});
      const result = body.method === 'initialize'
        ? {protocolVersion:'2025-11-25', capabilities:{tools:{}}, serverInfo:{name:'offline',version:'1'}}
        : {tools:[]};
      return new Response(JSON.stringify({jsonrpc:'2.0',id:body.id,result}), {headers:{'content-type':'application/json'}});
    };
    const checks = {};
    for (const specVersion of ['2026-07-28','2025-11-25']) {
      checks[specVersion] = (await suite.serverScenario('server-sse-multiple-streams').run({serverUrl:'https://offline.invalid/mcp',specVersion})).map(({id,status}) => ({id,status}));
    }
    console.log(JSON.stringify(checks));
  `], { cwd: HARNESS_DIR, encoding: 'utf8' });
  expect(run.status, run.stderr).toBe(0);
  expect(JSON.parse(run.stdout)).toEqual({
    '2026-07-28': [{ id: 'server-accepts-multiple-post-streams', status: 'SUCCESS' }, { id: 'server-sse-streams-functional', status: 'INFO' }],
    '2025-11-25': [{ id: 'server-sse-multiple-streams-session', status: 'WARNING' }],
  });
  const entries = (revision: string) => parseBaseline(fs.readFileSync(path.join(HARNESS_DIR, 'conformance', `baseline-${revision}.yml`), 'utf8')).entries.filter((e) => e.id.startsWith('server-sse-multiple-streams:'));
  expect.soft(entries('2026-07-28')).toEqual([]);
  expect(entries('2025-11-25')).toHaveLength(1);
  expect(entries('2025-11-25')[0].reason).toMatch(/v1 never mints a session id/);
});

test('Claude tool output waits for close and includes stdout delivered after exit', () => {
  const run = childProcess.spawnSync(process.execPath, ['--experimental-vm-modules', path.join(HARNESS_DIR, 'selftest/claude-close-fixture.mjs')], { cwd: HARNESS_DIR, encoding: 'utf8' });
  expect(run.status, run.stderr).toBe(0);
  expect(JSON.parse(run.stdout)).toEqual({
    settledAtExit: false,
    events: [{ type: 'assistant' }, { type: 'user', tool_result: { total: 3 } }],
  });
});

test('a failed Claude run reports its exit code without echoing stderr', () => {
  const run = childProcess.spawnSync(process.execPath, ['--experimental-vm-modules', path.join(HARNESS_DIR, 'selftest/claude-close-fixture.mjs')], { cwd: HARNESS_DIR, encoding: 'utf8', env: { ...process.env, RMCP_FIXTURE_FAIL: '1' } });
  expect(run.status, run.stderr).toBe(0);
  const { message } = JSON.parse(run.stdout) as { message: string };
  expect(message).toContain('exited with 1');
  expect(message).not.toContain('SECRET');
});
