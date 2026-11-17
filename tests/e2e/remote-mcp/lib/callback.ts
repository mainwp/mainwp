import http from 'node:http';
import type { AddressInfo } from 'node:net';

export interface CallbackServer {
  redirectUri: string;
  wait(timeoutMs: number): Promise<URLSearchParams>;
  close(): Promise<void>;
}

/**
 * The scripted client's loopback redirect target on a random port, which the bundled Claude Code
 * document's port-less `http://127.0.0.1/callback` entry allows.
 */
export async function startCallbackServer(): Promise<CallbackServer> {
  let deliver: (params: URLSearchParams) => void = () => undefined;
  const received = new Promise<URLSearchParams>((resolve) => {
    deliver = resolve;
  });
  const server = http.createServer((req, res) => {
    const url = new URL(req.url ?? '/', 'http://127.0.0.1');
    if (url.pathname !== '/callback') {
      res.writeHead(404).end();
      return;
    }
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
    res.end('<!doctype html><title>Harness callback</title><p data-harness="callback">Received.</p>');
    deliver(url.searchParams);
  });
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  const { port } = server.address() as AddressInfo;
  return {
    redirectUri: `http://127.0.0.1:${port}/callback`,
    wait(timeoutMs: number) {
      return Promise.race([
        received,
        new Promise<never>((_, reject) => setTimeout(() => reject(new Error('no callback within the timeout')), timeoutMs)),
      ]);
    },
    close() {
      server.closeAllConnections();
      return new Promise((resolve) => server.close(() => resolve()));
    },
  };
}
