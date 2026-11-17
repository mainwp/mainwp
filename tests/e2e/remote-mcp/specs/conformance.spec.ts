import { test, expect, type Page } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';
import { install, CLAUDE_CODE_CLIENT_ID, HARNESS_DIR } from '../lib/config.js';
import { connect } from '../lib/oauth.js';
import { approveConsentAt } from '../lib/browser.js';
import { revokeNewestConnection } from '../lib/panel.js';
import { runConformance, readChecks, resultsDir, freePort } from '../lib/conformance.js';
import { startBearerProxy } from '../conformance/bearer-proxy.js';
import { endpoints } from '../lib/urls.js';
import * as v from '../lib/verdicts.js';

// A pass means "applicable checks pass with a reviewed baseline", never "conformant".

test('official conformance: authorization server metadata and code grant pass', async ({ page }) => {
  test.setTimeout(420_000);
  const inst = install('root');
  const urls = endpoints(inst);
  const run = runConformance([
    'authorization',
    '--url', urls.issuer,
    '--client-id', CLAUDE_CODE_CLIENT_ID,
    // Without --resource our AS renders its error page and the suite waits out its 5 minutes.
    '--resource', urls.mcp,
    '--port', String(await freePort()),
  ]);
  try {
    const authorize = await run.waitForAuthorizeUrl(60_000);
    await approveConsentAt(page, authorize, { access: 'read' });
    const exit = await run.exit(120_000);
    const checks = run.checks();
    const out = resultsDir('authorization');
    fs.writeFileSync(path.join(out, 'checks.json'), JSON.stringify(checks, null, 2));
    expect(v.allChecksSuccess(checks, ['authorization-server-metadata-cimd']), run.output()).toEqual([]);
    expect(exit.exitCode, run.output()).toBe(0);
  } finally {
    run.kill();
  }
});

async function serverLeg(page: Page, revision: string): Promise<void> {
  test.setTimeout(900_000);
  // A View-only grant, so no scenario can change a site through the proxy.
  const conn = await connect(page, install('root'), { access: 'read' });
  const proxy = await startBearerProxy({ target: new URL(conn.install.home).origin, token: conn.tokens.access });
  let run: ReturnType<typeof runConformance> | undefined;
  try {
    const out = resultsDir(`server-${revision}`);
    run = runConformance([
      'server',
      '--url', `${proxy.url}${new URL(conn.endpoints.mcp).pathname}`,
      '--requirements', revision,
      '--expected-failures', path.join(HARNESS_DIR, 'conformance', `baseline-${revision}.yml`),
      '-o', out,
    ]);
    const exit = await run.exit(840_000);
    const checks = readChecks(out);
    expect(checks.filter((check) => check.id === 'wire-schema-harness-error' && check.status !== 'SUCCESS'), 'blocked by suite: invalid harness wire messages').toEqual([]);
    expect(v.serverLeg(exit), run.output()).toEqual([]);
  } finally {
    // A timed-out or failed leg must not leave the suite running against a closed proxy.
    run?.kill();
    await proxy.close();
    await revokeNewestConnection(page, conn.install, 'Claude Code');
  }
}

test('official conformance: 2026-07-28 server requirements pass against the reviewed baseline', async ({ page }) => {
  await serverLeg(page, '2026-07-28');
});

test('official conformance: 2025-11-25 server requirements pass against the reviewed baseline', async ({ page }) => {
  await serverLeg(page, '2025-11-25');
});
