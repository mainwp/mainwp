import { spawn, type ChildProcess } from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';
import { HARNESS_DIR, OUTPUT_DIR } from './config.js';

export interface ConformanceRun {
  waitForAuthorizeUrl(timeoutMs: number): Promise<string>;
  exit(timeoutMs: number): Promise<{ exitCode: number | null; signal: string | null }>;
  /** The run's output with credentials redacted, for assertion messages. */
  output(): string;
  checks(): Array<{ id: string; status: string }>;
  kill(): void;
}

const CREDENTIAL = /\b(mw(?:pat|prt|pac|pct|pap)_)[A-Za-z0-9_-]+/g;
const CODE_PARAM = /([?&](?:code|state)=)[^&\s"]+/g;

export function redact(text: string): string {
  return text.replace(CREDENTIAL, '$1[redacted]').replace(CODE_PARAM, '$1[redacted]');
}

/** Results can hold tool output with seeded data, so they stay in the ignored output directory. */
export function resultsDir(label: string): string {
  const dir = path.join(OUTPUT_DIR, 'conformance', `${label}-${new Date().toISOString().replace(/[:.]/g, '-')}`);
  fs.mkdirSync(dir, { recursive: true });
  return dir;
}

export function freePort(): Promise<number> {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.on('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address() as net.AddressInfo;
      server.close(() => resolve(port));
    });
  });
}

/** Runs the pinned suite from this package's node_modules, never a globally installed copy. */
export function runConformance(args: string[]): ConformanceRun {
  const bin = path.join(HARNESS_DIR, 'node_modules', '.bin', 'conformance');
  // Node ignores the system trust store by default; Local's certificate lives there.
  const nodeOptions = [process.env.NODE_OPTIONS, '--use-system-ca'].filter(Boolean).join(' ');
  const authorization = args[0] === 'authorization';
  if (authorization && args.some((arg) => ['-o', '--output-dir', '--file'].includes(arg) || arg.startsWith('--output-dir=') || arg.startsWith('-o='))) throw new Error('authorization suite file output is forbidden');
  const child: ChildProcess = spawn(authorization ? process.execPath : bin,
    authorization ? ['--experimental-vm-modules', path.join(HARNESS_DIR, 'conformance/memory-suite.mjs'), ...args.slice(1)] : args,
    { cwd: HARNESS_DIR, env: { ...process.env, NODE_OPTIONS: nodeOptions }, stdio: authorization ? ['ignore', 'pipe', 'pipe', 'ipc'] : ['ignore', 'pipe', 'pipe'] });
  const checks: Array<{ id: string; status: string }> = [];
  child.on('message', (message: { checks?: Array<{ id: string; status: string }> }) => {
    if (Array.isArray(message.checks)) checks.push(...message.checks.map((check) => ({ id: redact(check.id), status: redact(check.status) })));
  });
  let text = '';
  const listeners: Array<() => void> = [];
  const onData = (chunk: Buffer) => {
    text += chunk.toString('utf8');
    listeners.forEach((l) => l());
  };
  child.stdout?.on('data', onData);
  child.stderr?.on('data', onData);
  const exited = new Promise<{ exitCode: number | null; signal: string | null }>((resolve) => {
    child.on('close', (exitCode, signal) => resolve({ exitCode, signal }));
    child.on('error', () => resolve({ exitCode: -1, signal: null }));
  });

  return {
    waitForAuthorizeUrl(timeoutMs) {
      return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error(`no authorization URL from the suite:\n${redact(text).slice(-3000)}`)), timeoutMs);
        const check = () => {
          const marker = text.indexOf('Access the following URL in your browser');
          if (marker === -1) return;
          // Only a URL followed by whitespace is complete; a pipe chunk can end mid-URL.
          const url = /https?:\/\/\S+(?=\s)/.exec(text.slice(marker));
          if (url) {
            clearTimeout(timer);
            resolve(url[0]);
          }
        };
        listeners.push(check);
        void exited.then(() => {
          check();
          clearTimeout(timer);
          reject(new Error(`the suite exited before printing an authorization URL:\n${redact(text).slice(-3000)}`));
        });
        check();
      });
    },
    exit(timeoutMs) {
      return Promise.race([
        exited,
        new Promise<never>((_, reject) => setTimeout(() => reject(new Error(`the suite did not finish:\n${redact(text).slice(-3000)}`)), timeoutMs)),
      ]);
    },
    output() {
      return redact(text).slice(-6000);
    },
    checks() { return checks; },
    kill() {
      if (child.exitCode === null && child.signalCode === null) child.kill('SIGTERM');
    },
  };
}

/** Every check from every `checks.json` under a results directory. */
export function readChecks(dir: string): Array<{ id: string; status: string }> {
  const out: Array<{ id: string; status: string }> = [];
  const walk = (d: string) => {
    for (const entry of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, entry.name);
      if (entry.isDirectory()) walk(p);
      else if (entry.name === 'checks.json') {
        const parsed = JSON.parse(fs.readFileSync(p, 'utf8')) as Array<{ id: string; status: string }>;
        out.push(...parsed.map((c) => ({ id: c.id, status: c.status })));
      }
    }
  };
  if (fs.existsSync(dir)) walk(dir);
  return out;
}
