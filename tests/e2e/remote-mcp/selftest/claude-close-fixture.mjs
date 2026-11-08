import fs from 'node:fs';
import { EventEmitter } from 'node:events';
import { createRequire } from 'node:module';
import path from 'node:path';
import { PassThrough } from 'node:stream';
import { SourceTextModule, SyntheticModule } from 'node:vm';

const child = new EventEmitter();
child.stdout = new PassThrough();
child.stderr = new PassThrough();
let dir;
let spawned = false;
const forbidden = () => { throw new Error('offline fixture forbids process execution'); };
const dependencies = {
  'node:child_process': {
    execFile: forbidden,
    spawn(_command, _args, options) { spawned = true; dir = options.cwd; return child; },
  },
  './config.js': { claudeModel: () => 'offline' },
  './urls.js': { endpoints: () => ({ mcp: 'https://offline.invalid/mcp' }) },
  './conformance.js': { redact: (text) => text },
};

const source = fs.readFileSync(new URL('../lib/claude-code.ts', import.meta.url), 'utf8');
const require = createRequire(import.meta.url);
const { babelTransform } = require(path.join(path.dirname(require.resolve('playwright/package.json')), 'lib/transform/babelBundle.js'));
const module = new SourceTextModule(babelTransform(source, 'claude-code.ts', true, []).code);
await module.link(async (name) => {
  const exports = dependencies[name] ?? await import(name);
  return new SyntheticModule(Object.keys(exports), function () {
    for (const [key, value] of Object.entries(exports)) this.setExport(key, value);
  });
});
await module.evaluate();
try {
  const session = await module.namespace.claudeCodeSession({ name: 'root', home: 'https://offline.invalid', permalinks: 'pretty' });
  let settled = false;
  const result = session.callTool('count_sites_v1').then((events) => { settled = true; return events; });
  if (!spawned) throw new Error('spawn was not intercepted');
  if (process.env.RMCP_FIXTURE_FAIL === '1') {
    // A failing CLI whose diagnostics carry a live credential.
    child.stderr.emit('data', Buffer.from('auth failed for mwpat_SECRETSECRETSECRETSECRETSECRETSECRETSEC\n'));
    child.emit('close', 1, null);
    const message = await result.then(() => 'resolved', (e) => String(e && e.message));
    console.log(JSON.stringify({ message }));
    process.exit(0);
  }
  child.stdout.emit('data', Buffer.from('{"type":"assistant"}\n'));
  child.emit('exit', 0, null);
  await new Promise((resolve) => setImmediate(resolve));
  const settledAtExit = settled;
  child.stdout.emit('data', Buffer.from('{"type":"user","tool_result":{"total":3}}\n'));
  child.emit('close', 0, null);
  console.log(JSON.stringify({ settledAtExit, events: await result }));
} finally {
  child.emit('close', 0, null);
  if (dir) fs.rmSync(dir, { recursive: true, force: true });
}
