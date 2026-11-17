import http from 'node:http';
import https from 'node:https';
import tls from 'node:tls';

export interface RawResponse {
  status: number;
  /** Lower-cased names; repeated headers joined with ", " except set-cookie. */
  headers: Record<string, string>;
  body: string;
}

export interface RawRequest {
  method?: string;
  headers?: Record<string, string>;
  body?: string;
  timeoutMs?: number;
}

// Local's certificate is trusted through the system store. Node ignores that store by default,
// so add it explicitly instead of turning verification off.
function trustedCas(): string[] | undefined {
  const get = (tls as unknown as { getCACertificates?: (type: string) => string[] }).getCACertificates;
  if (!get) {
    return undefined;
  }
  return [...get('default'), ...get('system')];
}

const httpsAgent = new https.Agent({ keepAlive: false, ca: trustedCas() });
const httpAgent = new http.Agent({ keepAlive: false });

/**
 * One request, no cookies, no redirects followed, the body read as text. Each call opens its own
 * connection, so concurrent calls really are concurrent on the server.
 */
export function raw(url: string, options: RawRequest = {}): Promise<RawResponse> {
  const target = new URL(url);
  const secure = target.protocol === 'https:';
  const lib = secure ? https : http;
  const headers: Record<string, string> = { ...(options.headers ?? {}) };
  if (options.body !== undefined && !Object.keys(headers).some((h) => h.toLowerCase() === 'content-length')) {
    headers['Content-Length'] = String(Buffer.byteLength(options.body));
  }
  return new Promise((resolve, reject) => {
    const req = lib.request(
      target,
      { method: options.method ?? 'GET', headers, agent: secure ? httpsAgent : httpAgent },
      (res) => {
        const chunks: Buffer[] = [];
        res.on('data', (chunk: Buffer) => chunks.push(chunk));
        res.on('end', () => {
          const out: Record<string, string> = {};
          for (const [name, value] of Object.entries(res.headers)) {
            if (value === undefined) continue;
            out[name.toLowerCase()] = Array.isArray(value) ? value.join(name === 'set-cookie' ? '\n' : ', ') : value;
          }
          resolve({ status: res.statusCode ?? 0, headers: out, body: Buffer.concat(chunks).toString('utf8') });
        });
        res.on('error', reject);
      },
    );
    req.setTimeout(options.timeoutMs ?? 30000, () => req.destroy(new Error(`timeout: ${options.method ?? 'GET'} ${target.origin}${target.pathname}`)));
    req.on('error', reject);
    if (options.body !== undefined) {
      req.write(options.body);
    }
    req.end();
  });
}

export function form(values: Record<string, string | undefined>): string {
  const params = new URLSearchParams();
  for (const [name, value] of Object.entries(values)) {
    if (value !== undefined) params.append(name, value);
  }
  return params.toString();
}

export function json<T = unknown>(res: RawResponse): T | undefined {
  try {
    return JSON.parse(res.body) as T;
  } catch {
    return undefined;
  }
}

/** Plain-HTTP twin of an https URL; Local serves both schemes on the same host. */
export function overHttp(url: string): string {
  const u = new URL(url);
  u.protocol = 'http:';
  u.port = '';
  return u.toString();
}
