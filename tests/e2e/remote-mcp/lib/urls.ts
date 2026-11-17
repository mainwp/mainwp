import type { Install } from './config.js';

export interface Endpoints {
  issuer: string;
  mcp: string;
  prm: string;
  token: string;
  authorize: string;
}

/** Scheme and host lowercased, port 443 dropped, path kept, no trailing slash. */
export function canonicalHome(home: string): string {
  const u = new URL(home);
  const port = u.port && u.port !== '443' ? `:${u.port}` : '';
  return `${u.protocol.toLowerCase()}//${u.hostname.toLowerCase()}${port}${u.pathname.replace(/\/+$/, '')}`;
}

/** The module's endpoint forms; each is answered only in its own permalink mode. */
export function endpoints(inst: Install): Endpoints {
  const home = canonicalHome(inst.home);
  const rest = (route: string) =>
    inst.permalinks === 'plain' ? `${home}/?rest_route=/mainwp-remote/v1${route}` : `${home}/wp-json/mainwp-remote/v1${route}`;
  return {
    issuer: home,
    mcp: rest('/mcp'),
    prm: rest('/oauth-protected-resource'),
    token: rest('/oauth/token'),
    authorize: inst.permalinks === 'plain' ? `${home}/?mainwp_oauth=authorize` : `${home}/mainwp-oauth/authorize`,
  };
}

/** Path-insert (RFC 8414) and path-append forms of a well-known document for a home URL. */
export function wellKnown(home: string, name: string): { pathInsert: string; pathAppend: string } {
  const u = new URL(canonicalHome(home));
  const p = u.pathname.replace(/\/+$/, '');
  return {
    pathInsert: `${u.origin}/.well-known/${name}${p}`,
    pathAppend: `${u.origin}${p}/.well-known/${name}`,
  };
}

/** Appends parameters to a URL that may already carry `?rest_route=` or `?mainwp_oauth=`. */
export function withQuery(url: string, params: Record<string, string>): string {
  const query = new URLSearchParams(params).toString();
  return `${url}${url.includes('?') ? '&' : '?'}${query}`;
}
