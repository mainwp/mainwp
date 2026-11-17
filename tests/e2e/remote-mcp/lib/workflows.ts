import type { RawRequest, RawResponse } from './http.js';
import { form, overHttp } from './http.js';
import { endpoints } from './urls.js';
import type { Install } from './config.js';
import { modernHeaders, modernBody } from './mcp.js';
import * as v from './verdicts.js';

function requireValid(problems: string[]): void {
  if (problems.length) throw new Error(problems.join('; '));
}

export interface CloneObservation { identity: string; state: string; tokens: number }

export async function verifyCloneCopy(
  sync: () => Promise<void>,
  observe: (side: 'source' | 'clone') => Promise<unknown>,
  changedHome: () => Promise<void>,
): Promise<void> {
  const source = await observe('source') as CloneObservation;
  await sync();
  const clone = await observe('clone') as CloneObservation;
  if (!source || !clone || source.tokens !== 2 || clone.tokens !== 2 || !/^[a-f0-9]{64}$/.test(source.identity) || !/^[a-f0-9]{64}$/.test(source.state) || source.identity !== clone.identity || source.state !== clone.state) {
    throw new Error('clone does not contain the copied module identity and grant/token state');
  }
  await changedHome();
}

export async function verifyHttpConsent(
  consent: { formAction: string; requestKey: string; nonce: string },
  cookies: Array<{ name: string; value: string }>,
  send: (url: string, options: RawRequest) => Promise<RawResponse>,
  approve: () => Promise<unknown>,
): Promise<void> {
  const login = cookies.filter((cookie) => cookie.name.startsWith('wordpress_logged_in_'));
  if (!login.length) throw new Error('no WordPress login cookie for the consent probe');
  const response = await send(overHttp(consent.formAction), {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: login.map((cookie) => `${cookie.name}=${cookie.value}`).join('; ') },
    body: form({ request_key: consent.requestKey, _wpnonce: consent.nonce, decision: 'approve' }),
  });
  requireValid(v.httpRefusal('consent', response));
  await approve();
}

export async function verifyDisabledPaths(
  inst: Install,
  access: string,
  refresh: string,
  clientId: string,
  send: (url: string, options?: RawRequest) => Promise<RawResponse>,
): Promise<void> {
  const forms = inst.permalinks === 'pretty' ? ['pretty', 'plain'] as const : ['plain'] as const;
  for (const permalinks of forms) {
    const urls = endpoints({ ...inst, permalinks });
    const approval = permalinks === 'pretty' ? `${inst.home}/mainwp-oauth/approve` : `${inst.home}/?mainwp_oauth=approve`;
    const registration = permalinks === 'pretty' ? `${inst.home}/wp-json/mainwp-remote/v1/register` : `${inst.home}/?rest_route=/mainwp-remote/v1/register`;
    const requests: Array<[string, RawRequest]> = [
      [registration, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ client_name: 'Disabled probe', redirect_uris: ['http://127.0.0.1/callback'] }) }],
      [urls.token, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form({ grant_type: 'refresh_token', refresh_token: refresh, client_id: clientId }) }],
      [urls.prm, {}],
      [urls.mcp, { method: 'POST', headers: { Authorization: `Bearer ${access}`, ...modernHeaders('tools/list') }, body: modernBody('tools/list', {}) }],
    ];
    // WordPress accepts rest_route in either mode; OAuth selectors require plain permalinks.
    if (permalinks === inst.permalinks) requests.unshift(
      [urls.authorize, {}],
      [urls.authorize, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form({ request_key: 'kill-switch-probe', decision: 'approve' }) }],
      [approval, {}],
      [approval, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form({ approval_id: '1', decision: 'approve' }) }],
    );
    for (const [url, request] of requests) requireValid(v.moduleNotFound(await send(url, request)));
  }
}

export function verifyRead(message: unknown, ability: string, absentName?: string): void {
  requireValid(v.toolCallOk(message, ability));
  if (absentName && v.containsNamed(message, absentName)) throw new Error('the refused write created its tag');
}
