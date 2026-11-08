// Drives the real Claude Code CLI: `claude mcp login --no-browser` in a tmux pane prints the
// authorize URL, the browser approves it, and a print-mode run lists and calls the tools. Each
// session uses its own temporary project and server name, and logs out and cleans up afterwards.
import { execFile, spawn } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { promisify } from 'node:util';
import { claudeModel, type Install } from './config.js';
import { endpoints } from './urls.js';
import { redact } from './conformance.js';

const run = promisify(execFile);

export interface ClaudeCodeSession {
  server: string;
  startLogin(): Promise<string>;
  finishLogin(redirect: string): Promise<void>;
  callTool(tool: string): Promise<unknown[]>;
  close(): Promise<void>;
}

async function pane(session: string): Promise<string> {
  const { stdout } = await run('tmux', ['capture-pane', '-p', '-J', '-S', '-300', '-t', session]);
  return stdout;
}

async function poll<T>(what: string, timeoutMs: number, probe: () => Promise<T | null>, describe: () => Promise<string>): Promise<T> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const value = await probe();
    if (value !== null) return value;
    await new Promise((resolve) => setTimeout(resolve, 1000));
  }
  throw new Error(`${what} timed out:\n${redact(await describe()).slice(-2000)}`);
}

export async function claudeCodeSession(inst: Install): Promise<ClaudeCodeSession> {
  const urls = endpoints(inst);
  const server = `rmcpe2e_${inst.name}_${crypto.randomBytes(3).toString('hex')}`;
  const tmux = `rmcp-e2e-${server}`;
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'rmcp-e2e-cc-'));
  fs.mkdirSync(path.join(dir, '.claude'));
  fs.writeFileSync(path.join(dir, '.claude', 'settings.json'), JSON.stringify({ enableAllProjectMcpServers: true }));
  fs.writeFileSync(path.join(dir, '.mcp.json'), JSON.stringify({ mcpServers: { [server]: { type: 'http', url: urls.mcp } } }));

  return {
    server,
    async startLogin() {
      await run('tmux', ['new-session', '-d', '-s', tmux, '-x', '250', '-y', '50', '-c', dir, `claude mcp login ${server} --no-browser; echo RMCP_LOGIN_EXIT=$?; sleep 900`]);
      return poll('Claude Code authorize URL', 90_000, async () => {
        const found = (await pane(tmux)).match(/https:\/\/\S+/g) ?? [];
        return found.find((u) => u.startsWith(`${urls.authorize}${urls.authorize.includes('?') ? '&' : '?'}`)) ?? null;
      }, () => pane(tmux));
    },
    async finishLogin(redirect) {
      // Claude Code normally receives the redirect on its own loopback listener. If it is
      // waiting for the redirect to be pasted instead, paste it, as a person would.
      const done = async () => {
        const m = /RMCP_LOGIN_EXIT=(\d+)/.exec(await pane(tmux));
        return m ? Number(m[1]) : null;
      };
      const first = await poll('Claude Code login', 20_000, done, () => pane(tmux)).catch(() => null);
      if (first === null) {
        await run('tmux', ['send-keys', '-t', tmux, '-l', redirect]);
        await run('tmux', ['send-keys', '-t', tmux, 'Enter']);
      }
      const exit = first ?? (await poll('Claude Code login', 60_000, done, () => pane(tmux)));
      if (exit !== 0) {
        throw new Error(`claude mcp login exited ${exit}:\n${redact(await pane(tmux)).slice(-2000)}`);
      }
    },
    async callTool(tool) {
      const name = `mcp__${server}__${tool}`;
      // stdin is closed because print mode would otherwise wait on it.
      const stdout = await new Promise<string>((resolve, reject) => {
        const child = spawn(
          'claude',
          ['-p', `Call the ${name} tool once with no arguments and print its output verbatim, nothing else.`, '--output-format', 'stream-json', '--verbose', '--model', claudeModel(), '--allowedTools', name],
          { cwd: dir, stdio: ['ignore', 'pipe', 'pipe'] },
        );
        let out = '';
        const timer = setTimeout(() => child.kill('SIGTERM'), 300_000);
        child.stdout.on('data', (c: Buffer) => (out += c.toString('utf8')));
        // Drained so a chatty CLI can't block on a full pipe. Never echoed: an authenticated CLI's
        // diagnostics can carry live tokens, and test output is shared.
        child.stderr.on('data', () => undefined);
        child.on('error', reject);
        child.on('close', (code) => {
          clearTimeout(timer);
          if (code !== 0) reject(new Error(`claude exited with ${String(code)}; run it by hand to see its diagnostics`));
          else resolve(out);
        });
      });
      return stdout
        .split('\n')
        .filter((line) => line.trim().startsWith('{'))
        .map((line) => JSON.parse(line) as unknown);
    },
    async close() {
      await run('claude', ['mcp', 'logout', server], { cwd: dir, timeout: 60_000 }).catch(() => undefined);
      await run('tmux', ['kill-session', '-t', tmux]).catch(() => undefined);
      fs.rmSync(dir, { recursive: true, force: true });
    },
  };
}
