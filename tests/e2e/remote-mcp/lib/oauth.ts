// The scripted OAuth client: real discovery, PKCE, consent in the browser, code exchange and
// refresh, as Claude Code's client id. Nothing is minted through a test hook.
import type { Page } from '@playwright/test';
import type { Install } from './config.js';
import { raw, form, json, type RawResponse } from './http.js';
import { endpoints, wellKnown, type Endpoints } from './urls.js';
import { parseBearerChallenge } from './challenge.js';
import { modernBody, modernHeaders } from './mcp.js';
import { openConsent, approveOpenConsent, type ConsentAccess } from './browser.js';

export interface AsMetadata {
  issuer: string;
  authorization_endpoint: string;
  token_endpoint: string;
  [key: string]: unknown;
}

export interface Session {
  install: Install;
  endpoints: Endpoints;
  metadata: AsMetadata;
  clientId: string;
  redirectUri: string;
}

export interface PendingCode extends Session {
  code: string;
  verifier: string;
  consentPost: { method: string; url: string } | null;
}

export interface Tokens {
  access: string;
  refresh?: string;
  scope?: string;
}

export interface Connection extends Session {
  tokens: Tokens;
}

export interface ConnectOptions {
  access?: ConsentAccess;
  scope?: string;
}

// Claude Code asks for both scopes because the 401 challenge names both.
export const DEFAULT_SCOPE = 'mainwp:read mainwp:write offline_access';

/**
 * Discovery as clients do it: the 401 challenge names the PRM, the PRM names the
 * issuer, and the metadata comes from the first form that answers with that issuer. Every URL is
 * checked against the stored-home forms, so a mismatch fails here, not deep inside a check.
 */
export async function discover(inst: Install): Promise<{ endpoints: Endpoints; metadata: AsMetadata }> {
  const urls = endpoints(inst);
  const challenge = await raw(urls.mcp, { method: 'POST', headers: modernHeaders('tools/list'), body: modernBody('tools/list', {}) });
  const params = parseBearerChallenge(challenge.headers['www-authenticate']);
  if (challenge.status !== 401 || !params) {
    throw new Error(`discovery: unauthenticated MCP request got ${challenge.status} without a Bearer challenge`);
  }
  if (params.resource_metadata !== urls.prm) {
    throw new Error(`discovery: resource_metadata is ${params.resource_metadata}, expected ${urls.prm}`);
  }
  const prm = json<{ resource?: string; authorization_servers?: string[] }>(await raw(urls.prm));
  if (!prm || prm.resource !== urls.mcp || prm.authorization_servers?.[0] !== urls.issuer) {
    throw new Error('discovery: PRM does not name this resource and issuer');
  }
  const forms = [
    wellKnown(urls.issuer, 'oauth-authorization-server').pathInsert,
    wellKnown(urls.issuer, 'oauth-authorization-server').pathAppend,
    wellKnown(urls.issuer, 'openid-configuration').pathInsert,
    wellKnown(urls.issuer, 'openid-configuration').pathAppend,
  ];
  for (const url of forms) {
    const doc = json<AsMetadata>(await raw(url));
    if (doc && doc.issuer === urls.issuer) {
      if (doc.authorization_endpoint !== urls.authorize || doc.token_endpoint !== urls.token) {
        throw new Error('discovery: metadata endpoints differ from the stored-home forms');
      }
      return { endpoints: urls, metadata: doc };
    }
  }
  throw new Error('discovery: no well-known form returned metadata with this issuer');
}

export interface AuthorizeParams {
  clientId: string;
  redirectUri: string;
  scope: string;
  state: string;
  challenge: string;
  resource: string;
}

export function authorizeUrl(endpoint: string, p: AuthorizeParams): string {
  const query = new URLSearchParams({
    response_type: 'code',
    client_id: p.clientId,
    redirect_uri: p.redirectUri,
    scope: p.scope,
    state: p.state,
    code_challenge: p.challenge,
    code_challenge_method: 'S256',
    resource: p.resource,
  }).toString();
  return `${endpoint}${endpoint.includes('?') ? '&' : '?'}${query}`;
}

export async function obtainCode(page: Page, inst: Install, options: ConnectOptions = {}): Promise<PendingCode> {
  const consent = await openConsent(page, inst, { scope: options.scope ?? DEFAULT_SCOPE });
  return approveOpenConsent(page, consent, options.access);
}

export function exchangeCode(p: PendingCode): Promise<RawResponse> {
  return raw(p.metadata.token_endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
    body: form({
      grant_type: 'authorization_code',
      code: p.code,
      code_verifier: p.verifier,
      client_id: p.clientId,
      redirect_uri: p.redirectUri,
      resource: p.endpoints.mcp,
    }),
  });
}

export function refresh(s: Session, refreshToken: string): Promise<RawResponse> {
  return raw(s.metadata.token_endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
    body: form({ grant_type: 'refresh_token', refresh_token: refreshToken, client_id: s.clientId, resource: s.endpoints.mcp }),
  });
}

export function tokenPair(res: RawResponse): Tokens {
  const body = json<{ access_token?: string; refresh_token?: string; scope?: string }>(res);
  if (res.status !== 200 || !body?.access_token) {
    throw new Error(`token response ${res.status}: ${body && 'error' in body ? String((body as { error: unknown }).error) : 'no access_token'}`);
  }
  return { access: body.access_token, refresh: body.refresh_token, scope: body.scope };
}

export async function connect(page: Page, inst: Install, options: ConnectOptions = {}): Promise<Connection> {
  const pending = await obtainCode(page, inst, options);
  const tokens = tokenPair(await exchangeCode(pending));
  const { code: _code, verifier: _verifier, consentPost: _post, ...session } = pending;
  return { ...session, tokens };
}
