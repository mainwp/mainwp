// Login and consent in a real browser. Selectors use the consent form's field names (`decision`,
// `access`, `request_key`, `_wpnonce`) and the pages' `data-page` marker instead of visible text,
// so rewording a page doesn't break the harness.
import type { Page, Response } from '@playwright/test';
import crypto from 'node:crypto';
import { adminCredentials, CLAUDE_CODE_CLIENT_ID, type Install } from './config.js';
import type { RawResponse } from './http.js';
import { startCallbackServer, type CallbackServer } from './callback.js';
import { pkcePair } from './pkce.js';
import { discover, authorizeUrl, type AsMetadata, type PendingCode } from './oauth.js';
import type { Endpoints } from './urls.js';

export type ConsentAccess = 'read' | 'write';

const LOOPBACK = /^http:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?\//;

export async function loginIfNeeded(page: Page): Promise<void> {
  if (!new URL(page.url()).pathname.endsWith('/wp-login.php')) return;
  const { user, password } = adminCredentials();
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(password);
  await Promise.all([page.waitForNavigation(), page.locator('#wp-submit').click()]);
  if (new URL(page.url()).pathname.endsWith('/wp-login.php')) {
    throw new Error('login failed for the harness admin');
  }
}

async function toRaw(response: Response | null): Promise<RawResponse> {
  if (!response) throw new Error('no response for the consent page');
  return { status: response.status(), headers: await response.allHeaders(), body: await response.text() };
}

async function waitForConsentOrError(page: Page): Promise<void> {
  await page.locator('[data-page="consent"], [data-page="error"]').first().waitFor({ timeout: 30_000 });
  const error = page.locator('[data-page="error"]');
  if (await error.count()) {
    throw new Error(`authorization error page: ${(await error.getAttribute('data-error')) ?? 'unknown'}`);
  }
}

async function chooseAccessAndApprove(page: Page, access: ConsentAccess | undefined): Promise<{ method: string; url: string }> {
  // The access choice is shown only when the request includes mainwp:write.
  const radio = page.locator(`input[name="access"][value="${access ?? 'write'}"]`);
  if (access && (await radio.count())) {
    await radio.check();
  } else if (access === 'write' && !(await radio.count())) {
    throw new Error('the consent page offers no write access');
  }
  const post = page.waitForRequest((r) => r.method() === 'POST', { timeout: 30_000 });
  await page.locator('button[name="decision"][value="approve"]').click();
  const request = await post;
  return { method: request.method(), url: request.url() };
}

/**
 * Approve an authorization URL another program built (Claude Code, the conformance suite) and
 * return the loopback redirect the browser was sent to. Our error page throws at once, so a bad
 * request fails here instead of leaving the other program waiting for its callback.
 */
export async function approveConsentAt(
  page: Page,
  url: string,
  options: { access?: ConsentAccess } = {},
): Promise<string> {
  const loopback = page.waitForRequest((r) => LOOPBACK.test(r.url()), { timeout: 90_000 });
  // A later step can throw before loopback is awaited; without a handler its rejection goes unhandled.
  loopback.catch(() => undefined);
  await page.goto(url);
  await loginIfNeeded(page);
  await waitForConsentOrError(page);
  await chooseAccessAndApprove(page, options.access);
  return (await loopback).url();
}

export interface OpenConsent {
  url: string;
  response: RawResponse;
  formAction: string;
  requestKey: string;
  nonce: string;
  install: Install;
  endpoints: Endpoints;
  metadata: AsMetadata;
  callback: CallbackServer;
  verifier: string;
  state: string;
}

/** Run the scripted client up to a rendered consent page, logged in as the harness admin. */
export async function openConsent(page: Page, inst: Install, options: { scope: string }): Promise<OpenConsent> {
  const { endpoints, metadata } = await discover(inst);
  const callback = await startCallbackServer();
  const pkce = pkcePair();
  const state = crypto.randomBytes(16).toString('base64url');
  const url = authorizeUrl(metadata.authorization_endpoint, {
    clientId: CLAUDE_CODE_CLIENT_ID,
    redirectUri: callback.redirectUri,
    scope: options.scope,
    state,
    challenge: pkce.challenge,
    resource: endpoints.mcp,
  });
  await page.goto(url);
  if (new URL(page.url()).pathname.endsWith('/wp-login.php')) {
    await loginIfNeeded(page);
  }
  // Load it again so the response headers are the consent page's own, not the login redirect's.
  const response = await toRaw(await page.goto(url));
  await waitForConsentOrError(page);
  const formEl = page.locator('[data-page="consent"] form');
  return {
    url,
    response,
    formAction: (await formEl.getAttribute('action')) ?? '',
    requestKey: (await formEl.locator('input[name="request_key"]').getAttribute('value')) ?? '',
    nonce: (await formEl.locator('input[name="_wpnonce"]').getAttribute('value')) ?? '',
    install: inst,
    endpoints,
    metadata,
    callback,
    verifier: pkce.verifier,
    state,
  };
}

/** Approve the open consent page and check the redirect the way a client must (state, iss). */
export async function approveOpenConsent(page: Page, consent: OpenConsent, access?: ConsentAccess): Promise<PendingCode> {
  try {
    const consentPost = await chooseAccessAndApprove(page, access);
    const params = await consent.callback.wait(30_000);
    if (params.get('error')) throw new Error(`authorization redirect carried error=${params.get('error')}`);
    if (params.get('state') !== consent.state) throw new Error('authorization redirect state mismatch');
    if (params.get('iss') !== consent.metadata.issuer) throw new Error('authorization redirect iss mismatch');
    const code = params.get('code');
    if (!code) throw new Error('authorization redirect has no code');
    return {
      install: consent.install,
      endpoints: consent.endpoints,
      metadata: consent.metadata,
      clientId: CLAUDE_CODE_CLIENT_ID,
      redirectUri: consent.callback.redirectUri,
      code,
      verifier: consent.verifier,
      consentPost,
    };
  } finally {
    await consent.callback.close();
  }
}
