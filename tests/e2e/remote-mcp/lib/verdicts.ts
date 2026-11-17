// One verdict per named check: each returns the list of problems with a response, empty when it
// matches what the module must send. Messages describe shapes and never echo tokens or codes.
import { ListToolsResultSchema } from '@modelcontextprotocol/sdk/types.js';
import type { RawResponse } from './http.js';
import { json } from './http.js';
import { parseBearerChallenge } from './challenge.js';

type Problems = string[];

const BOTH_SCOPES = 'mainwp:read mainwp:write';

function header(res: RawResponse, name: string): string {
  return res.headers[name.toLowerCase()] ?? '';
}

function isJson(res: RawResponse): boolean {
  return /^application\/json\b/i.test(header(res, 'content-type'));
}

function schemesScope(tool: Record<string, unknown>): string | null {
  const schemes = tool.securitySchemes;
  if (!Array.isArray(schemes) || schemes.length !== 1) return null;
  const scheme = schemes[0] as { type?: unknown; scopes?: unknown };
  if (scheme.type !== 'oauth2' || !Array.isArray(scheme.scopes) || scheme.scopes.length !== 1) return null;
  return String(scheme.scopes[0]);
}

/** The result parses under the schema MCP clients use, which refuses the whole list over one bad tool. */
export function toolListResult(tools: unknown[]): Problems {
  const parsed = ListToolsResultSchema.safeParse({ tools });
  if (parsed.success) return [];
  return parsed.error.issues.map((issue) => `${issue.path.join('.')}: ${issue.message}`);
}

/** Every enabled tool is listed on every grant with its scheme. */
export function toolListing(tools: unknown[], read: string, write: string): Problems {
  const problems: Problems = [];
  const byName = new Map<string, Record<string, unknown>>();
  for (const tool of tools) {
    if (tool && typeof tool === 'object' && typeof (tool as { name?: unknown }).name === 'string') {
      byName.set((tool as { name: string }).name, tool as Record<string, unknown>);
    }
  }
  for (const [name, scope] of [[read, 'mainwp:read'], [write, 'mainwp:write']] as const) {
    const tool = byName.get(name);
    if (!tool) {
      problems.push(`${name} is not listed`);
    } else if (schemesScope(tool) !== scope) {
      problems.push(`${name} securitySchemes should name exactly ${scope}`);
    }
  }
  return problems;
}

export function toolCallOk(message: unknown, ability = 'count_sites_v1'): Problems {
  const m = message as { result?: { isError?: unknown; content?: unknown }; error?: unknown } | undefined;
  if (!m || typeof m !== 'object') return ['response is not JSON-RPC'];
  if (m.error !== undefined) return ['tools/call returned a JSON-RPC error'];
  if (!m.result) return ['tools/call has no result'];
  if (m.result.isError === true) return ['tools/call result has isError: true'];
  if (!Array.isArray(m.result.content) || m.result.content.length === 0) return ['tools/call result has no content'];
  const result = m.result as { structuredContent?: unknown; content: Array<{ type?: string; text?: string }> };
  const payloads: unknown[] = [];
  if (result.structuredContent !== undefined) payloads.push(result.structuredContent);
  for (const block of result.content) {
    if (block.type !== 'text' || typeof block.text !== 'string') continue;
    try { payloads.push(JSON.parse(block.text)); } catch { /* A structured result may also carry a prose summary. */ }
  }
  if (!payloads.length) return ['read has no completed ability payload'];
  for (const value of payloads) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return ['read payload is not an object'];
    const payload = value as Record<string, unknown>;
    if ('status' in payload || 'approval_url' in payload || 'confirmation_token' in payload || 'job_id' in payload) return ['read returned approval, confirmation or job state'];
    if (!Number.isInteger(payload.total) || Number(payload.total) < 0) return ['read payload has no nonnegative total'];
    if (ability === 'list_tags_v1') {
      if (!Array.isArray(payload.items) || !Number.isInteger(payload.page) || !Number.isInteger(payload.per_page)) return ['tag read has no paginated items'];
      if (payload.items.some((tag: unknown) => !tag || typeof tag !== 'object' || !Number.isInteger((tag as Record<string, unknown>).id) || typeof (tag as Record<string, unknown>).name !== 'string')) return ['tag read has malformed items'];
    } else if (ability !== 'count_sites_v1') return ['no completed-payload contract for the selected read ability'];
  }
  return [];
}

/** The `challenge` form of the step-up response (Claude Code's `step_up`). */
export function stepUpChallenge(res: RawResponse, prm: string): Problems {
  const problems: Problems = [];
  if (res.status !== 403) problems.push(`status ${res.status}, expected 403`);
  const c = parseBearerChallenge(header(res, 'www-authenticate'));
  if (!c) return [...problems, 'no Bearer challenge'];
  if (c.error !== 'insufficient_scope') problems.push('error is not insufficient_scope');
  if (c.scope !== BOTH_SCOPES) problems.push(`scope is "${c.scope ?? ''}", expected "${BOTH_SCOPES}"`);
  if (c.resource_metadata !== prm) problems.push('resource_metadata is not this install\'s PRM URL');
  return problems;
}

function tokenSuccess(res: RawResponse): Problems {
  const problems: Problems = [];
  if (res.status !== 200) return [`status ${res.status}, expected 200`];
  const body = json<{ access_token?: unknown; token_type?: unknown }>(res);
  if (!body || typeof body.access_token !== 'string' || !body.access_token.startsWith('mwpat_')) problems.push('no mwpat_ access_token');
  if (body && body.token_type !== 'Bearer') problems.push('token_type is not Bearer');
  if (!/no-store/i.test(header(res, 'cache-control'))) problems.push('Cache-Control lacks no-store');
  return problems;
}

/** The second presentation inside the window gets a sibling pair, not an error. */
export function refreshRace(responses: RawResponse[]): Problems {
  const problems: Problems = [];
  if (responses.length !== 2) return ['expected exactly two responses'];
  responses.forEach((r, i) => tokenSuccess(r).forEach((p) => problems.push(`response ${i + 1}: ${p}`)));
  if (problems.length) return problems;
  const [a, b] = responses.map((r) => json<{ access_token: string; refresh_token?: string }>(r)!);
  if (a.access_token === b.access_token) problems.push('both responses carry the same access token');
  if (!a.refresh_token || !b.refresh_token) problems.push('a response has no refresh token');
  else if (a.refresh_token === b.refresh_token) problems.push('both responses carry the same refresh token');
  return problems;
}

/** RFC 6749 5.2 JSON error, 400 (401 for invalid_client). */
export function tokenError(res: RawResponse, code: string): Problems {
  const problems: Problems = [];
  const expected = code === 'invalid_client' ? 401 : 400;
  if (res.status !== expected) problems.push(`status ${res.status}, expected ${expected}`);
  const body = json<{ error?: unknown; access_token?: unknown }>(res);
  if (!body) return [...problems, 'body is not JSON'];
  if (body.access_token !== undefined) problems.push('an error response carries an access token');
  if (body.error !== code) problems.push(`error is "${String(body.error)}", expected "${code}"`);
  return problems;
}

/** The challenge with `error="invalid_token"`: a token was presented and rejected. */
export function invalidTokenChallenge(res: RawResponse, prm: string): Problems {
  const problems: Problems = [];
  if (res.status !== 401) problems.push(`status ${res.status}, expected 401`);
  const c = parseBearerChallenge(header(res, 'www-authenticate'));
  if (!c) return [...problems, 'no Bearer challenge'];
  if (c.error !== 'invalid_token') problems.push('error is not invalid_token');
  if (c.resource_metadata !== prm) problems.push('resource_metadata is not this install\'s PRM URL');
  return problems;
}

/** Without a token, the challenge names both scopes and carries no error. */
export function missingTokenChallenge(res: RawResponse, prm: string): Problems {
  const problems: Problems = [];
  if (res.status !== 401) problems.push(`status ${res.status}, expected 401`);
  const c = parseBearerChallenge(header(res, 'www-authenticate'));
  if (!c) return [...problems, 'no Bearer challenge'];
  if (c.resource_metadata !== prm) problems.push('resource_metadata is not this install\'s PRM URL');
  if (c.scope !== BOTH_SCOPES) problems.push(`scope is "${c.scope ?? ''}", expected "${BOTH_SCOPES}"`);
  if (c.error !== undefined) problems.push('a missing token carries an error parameter');
  if (/"code"\s*:\s*"[a-z_]*rest_|harness_rest_blocked/.test(res.body)) problems.push('body is a REST error, not the MCP challenge');
  return problems;
}

/** A bearer token makes nobody logged in on another REST route. */
export function boundaryRefused(res: RawResponse): Problems {
  return res.status === 401 || res.status === 403 ? [] : [`status ${res.status}, expected 401 or 403`];
}

/** The harness whoami route and admin-ajax action answer user id 0 for an anonymous request. */
export function anonymous(res: RawResponse): Problems {
  if (res.status === 401) return [];
  const body = json<{ user_id?: unknown }>(res);
  if (!body || typeof body.user_id !== 'number') return [`status ${res.status} with no user_id`];
  return body.user_id === 0 ? [] : [`resolved user ${body.user_id}, expected 0`];
}

/** A known client without a login session goes to wp-login.php, never consent. */
export function loginRedirect(res: RawResponse): Problems {
  const problems: Problems = [];
  if (res.status !== 302 && res.status !== 303) problems.push(`status ${res.status}, expected a redirect`);
  const location = header(res, 'location');
  let path = '';
  try {
    path = new URL(location).pathname;
  } catch {
    problems.push('no absolute Location');
  }
  if (path && !path.endsWith('/wp-login.php')) problems.push('Location is not wp-login.php');
  if (res.body.includes('data-page="consent"')) problems.push('the consent page was rendered');
  return problems;
}

/** The consent page refuses to be framed. */
export function frameBlocked(res: RawResponse): Problems {
  const problems: Problems = [];
  if (header(res, 'x-frame-options').trim().toUpperCase() !== 'DENY') problems.push('X-Frame-Options is not DENY');
  if (!/frame-ancestors\s+'none'/i.test(header(res, 'content-security-policy'))) problems.push("CSP lacks frame-ancestors 'none'");
  return problems;
}

/** At most one exchange wins, every other one is invalid_grant. */
export function codeRace(responses: RawResponse[]): Problems {
  const problems: Problems = [];
  const winners = responses.filter((r) => r.status === 200);
  if (winners.length > 1) problems.push(`${winners.length} exchanges succeeded`);
  const losers = responses.filter((r) => r.status !== 200);
  if (losers.length === 0) problems.push('no exchange lost');
  losers.forEach((r, i) => tokenError(r, 'invalid_grant').forEach((p) => problems.push(`loser ${i + 1}: ${p}`)));
  return problems;
}

/** Our metadata, with this install's issuer. */
export function metadataDocument(res: RawResponse, issuer: string): Problems {
  const problems: Problems = [];
  if (res.status !== 200) return [`status ${res.status}, expected 200`];
  if (!isJson(res)) problems.push('Content-Type is not application/json');
  const doc = json<Record<string, unknown>>(res);
  if (!doc) return [...problems, 'body is not JSON'];
  if (doc.issuer !== issuer) problems.push(`issuer is "${String(doc.issuer)}", expected "${issuer}"`);
  for (const field of ['authorization_endpoint', 'token_endpoint']) {
    if (typeof doc[field] !== 'string' || !String(doc[field]).startsWith(`${issuer}/`)) problems.push(`${field} is not under the issuer`);
  }
  if (!Array.isArray(doc.response_types_supported) || !doc.response_types_supported.includes('code')) problems.push('response_types_supported lacks code');
  if (!Array.isArray(doc.code_challenge_methods_supported) || !doc.code_challenge_methods_supported.includes('S256')) problems.push('code_challenge_methods_supported lacks S256');
  return problems;
}

/** Our error page, 400, before any login hand-off or redirect. */
export function errorPageBeforeLogin(res: RawResponse): Problems {
  const problems: Problems = [];
  if (res.status !== 400) problems.push(`status ${res.status}, expected 400`);
  if (header(res, 'location')) problems.push('the response redirects');
  if (!res.body.includes('data-page="error"')) problems.push('not the module error page');
  return problems;
}

/** The module's own final 404, with no challenge. */
export function moduleNotFound(res: RawResponse): Problems {
  const problems: Problems = [];
  if (res.status !== 404) problems.push(`status ${res.status}, expected 404`);
  if (!/^text\/plain\b/i.test(header(res, 'content-type'))) problems.push('Content-Type is not text/plain');
  if (header(res, 'www-authenticate')) problems.push('a challenge was sent');
  if (!/no-store/i.test(header(res, 'cache-control'))) problems.push('Cache-Control lacks no-store');
  return problems;
}

/** Our own parser answers -32700, never WordPress's rest_invalid_json. */
export function jsonRpcParseError(res: RawResponse): Problems {
  const problems: Problems = [];
  if (res.status !== 400) problems.push(`status ${res.status}, expected 400`);
  if (res.body.includes('rest_invalid_json')) problems.push('WordPress parsed the body');
  const body = json<{ jsonrpc?: unknown; error?: { code?: unknown } }>(res);
  if (!body || body.jsonrpc !== '2.0' || body.error?.code !== -32700) problems.push('not a JSON-RPC -32700 error');
  return problems;
}

/** Core's envelope, JSONP and field filters never touch our response. */
export function sameChallenge(plain: RawResponse, variant: RawResponse): Problems {
  const problems: Problems = [];
  if (variant.status !== plain.status) problems.push(`status ${variant.status}, expected ${plain.status}`);
  if (header(variant, 'www-authenticate') !== header(plain, 'www-authenticate')) problems.push('WWW-Authenticate differs');
  if (variant.body !== plain.body) problems.push('body differs');
  if (header(variant, 'content-type') !== header(plain, 'content-type')) problems.push('Content-Type differs');
  return problems;
}

/** No CORS headers in v1. */
export function noCors(res: RawResponse): Problems {
  return Object.keys(res.headers)
    .filter((name) => name.startsWith('access-control-'))
    .map((name) => `${name} is present`);
}

/** Refusal per endpoint, never a redirect to https. */
export function httpRefusal(endpoint: 'authorize' | 'consent' | 'token' | 'mcp', res: RawResponse): Problems {
  const problems: Problems = [];
  if (res.status !== 400) problems.push(`status ${res.status}, expected 400`);
  if (header(res, 'location')) problems.push('the response redirects');
  if (endpoint === 'token') {
    return [...problems, ...tokenError(res, 'invalid_request').filter((p) => !p.startsWith('status'))];
  }
  if (endpoint === 'mcp') {
    const body = json<{ id?: unknown; error?: { code?: unknown } }>(res);
    if (!body || body.error?.code !== -32600) problems.push('not a JSON-RPC -32600 error');
    if (body && body.id !== null) problems.push('id is not null');
    return problems;
  }
  if (res.body.includes('data-page="consent"')) problems.push('the consent page was rendered');
  if (!/(?:data-error=["']https_required["']|HTTPS required|only works over HTTPS)/i.test(res.body)) problems.push('not an HTTPS-specific refusal');
  return problems;
}

/** The consent form posts to the metadata authorization endpoint, never a hard-coded path. */
export function consentPostTarget(posted: { method: string; url: string }, authorizationEndpoint: string): Problems {
  const problems: Problems = [];
  if (posted.method !== 'POST') problems.push(`method ${posted.method}, expected POST`);
  if (!posted.url.startsWith('https://')) problems.push('posted over plain HTTP');
  if (posted.url !== authorizationEndpoint) problems.push('posted somewhere other than the metadata authorization_endpoint');
  return problems;
}

/** Claude Code's stream-json events: the server connected, both tools listed, the read call ran. */
export function claudeCodeRun(events: unknown[], server: string, read: string, write: string): Problems {
  const problems: Problems = [];
  const init = events.find((e) => (e as { type?: string; subtype?: string }).type === 'system' && (e as { subtype?: string }).subtype === 'init') as
    | { tools?: string[]; mcp_servers?: Array<{ name: string; status: string }> }
    | undefined;
  if (!init) return ['no init event'];
  const status = init.mcp_servers?.find((s) => s.name === server)?.status;
  if (status !== 'connected') problems.push(`server status is "${status ?? 'missing'}"`);
  const readName = `mcp__${server}__${read}`;
  for (const name of [readName, `mcp__${server}__${write}`]) {
    if (!init.tools?.includes(name)) problems.push(`${name} is not listed`);
  }
  const blocks = events.flatMap((e) => {
    const content = (e as { message?: { content?: unknown } }).message?.content;
    return Array.isArray(content) ? (content as Array<Record<string, unknown>>) : [];
  });
  const uses = blocks.filter((b) => b.type === 'tool_use' && b.name === readName).map((b) => b.id);
  if (uses.length === 0) return [...problems, `${readName} was not called`];
  const results = blocks.filter((b) => b.type === 'tool_result' && uses.includes(b.tool_use_id));
  if (results.length === 0) problems.push(`${readName} returned no result`);
  else if (results.some((b) => b.is_error === true)) problems.push(`${readName} returned an error`);
  else for (const result of results) {
    const content = typeof result.content === 'string' ? [{ type: 'text', text: result.content }] : result.content;
    problems.push(...toolCallOk({ result: { content } }, read));
  }
  return problems;
}

/** Official conformance, authorization leg: every check SUCCESS, the named ones present. */
export function allChecksSuccess(checks: Array<{ id: string; status: string }>, required: string[]): Problems {
  const problems: Problems = [];
  if (checks.length === 0) return ['no checks were recorded'];
  for (const check of checks) {
    if (check.status !== 'SUCCESS') problems.push(`${check.id}: ${check.status}`);
  }
  for (const id of required) {
    if (!checks.some((c) => c.id === id)) problems.push(`${id} did not run`);
  }
  return problems;
}

/** Official conformance, server leg: exit 0, so no new failures and no stale baseline entries. */
export function serverLeg(exit: { exitCode: number | null; signal: string | null }): Problems {
  if (exit.signal) return [`killed by ${exit.signal}`];
  return exit.exitCode === 0 ? [] : [`exit ${String(exit.exitCode)}, expected 0`];
}

/** True when any object anywhere in a JSON-RPC result, text content included, has this name. */
export function containsNamed(value: unknown, name: string): boolean {
  if (typeof value === 'string') {
    try {
      return containsNamed(JSON.parse(value), name);
    } catch {
      return false;
    }
  }
  if (Array.isArray(value)) return value.some((item) => containsNamed(item, name));
  if (value && typeof value === 'object') {
    const obj = value as Record<string, unknown>;
    if (obj.name === name) return true;
    return Object.values(obj).some((item) => containsNamed(item, name));
  }
  return false;
}
