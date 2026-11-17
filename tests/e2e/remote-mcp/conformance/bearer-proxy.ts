import http from 'node:http';
import https from 'node:https';
import tls from 'node:tls';
import type { AddressInfo } from 'node:net';

// The conformance suite can't send credentials, so its server leg reaches the MCP
// endpoint through this loopback proxy, which adds the harness's real access token. It changes
// nothing else: same method, path, headers and body, and the response streams back as received.
// The token stays in memory and is never logged.

export interface BearerProxy {
  url: string;
  address: string;
  port: number;
  close(): Promise<void>;
}

export interface BearerProxyOptions {
  /** Origin of the Dashboard, `https://site.local`. */
  target: string;
  token: string;
  /** Host header for the upstream request; defaults to the target's host. */
  host?: string;
}

const getCas = (tls as unknown as { getCACertificates?: (type: string) => string[] }).getCACertificates;
const httpsAgent = new https.Agent({ keepAlive: false, ca: getCas ? [...getCas('default'), ...getCas('system')] : undefined });

export async function startBearerProxy(options: BearerProxyOptions): Promise<BearerProxy> {
  const target = new URL(options.target);
  const secure = target.protocol === 'https:';
  const host = options.host ?? target.host;

  const server = http.createServer((req, res) => {
    const headers: http.OutgoingHttpHeaders = {};
    for (const [name, value] of Object.entries(req.headers)) {
      if (name === 'host' || name === 'authorization' || name === 'connection') continue;
      headers[name] = value;
    }
    headers.host = host;
    headers.authorization = `Bearer ${options.token}`;
    const upstream = (secure ? https : http).request(
      {
        protocol: target.protocol,
        hostname: target.hostname,
        port: target.port || (secure ? 443 : 80),
        servername: secure ? target.hostname : undefined,
        method: req.method,
        path: req.url,
        headers,
        agent: secure ? httpsAgent : undefined,
      },
      (up) => {
        res.writeHead(up.statusCode ?? 502, up.statusMessage, up.rawHeaders);
        up.pipe(res);
      },
    );
    upstream.on('error', () => {
      if (!res.headersSent) {
        res.writeHead(502, { 'Content-Type': 'text/plain' });
      }
      res.end('bearer proxy: upstream request failed');
    });
    req.pipe(upstream);
  });

  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  const { address, port } = server.address() as AddressInfo;
  return {
    url: `http://127.0.0.1:${port}`,
    address,
    port,
    close() {
      server.closeAllConnections();
      return new Promise((resolve) => server.close(() => resolve()));
    },
  };
}
