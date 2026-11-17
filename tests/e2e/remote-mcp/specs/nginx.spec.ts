import { test, expect } from '@playwright/test';
import { install } from '../lib/config.js';
import { raw } from '../lib/http.js';
import { wellKnown } from '../lib/urls.js';
import * as v from '../lib/verdicts.js';

// Local's stock nginx denies dot-paths (`location ~ /\.`) and hands the 404 to WordPress with the
// original REQUEST_URI; the module matches that path.
test("well-known metadata is served on Local's stock nginx config", async () => {
  const root = install('root');
  for (const name of ['oauth-authorization-server', 'openid-configuration']) {
    const res = await raw(wellKnown(root.home, name).pathInsert);
    expect(v.metadataDocument(res, root.home), `root ${name}`).toEqual([]);
  }

  // A subdirectory install is reached only through path-append, which the same deny rule covers.
  const subdir = install('subdir');
  for (const name of ['oauth-authorization-server', 'openid-configuration']) {
    const res = await raw(wellKnown(subdir.home, name).pathAppend);
    expect(v.metadataDocument(res, subdir.home), `subdirectory ${name}`).toEqual([]);
  }

  // The match is exact, never a broad /.well-known/ claim (ACME stays untouched).
  const acme = await raw(`${new URL(root.home).origin}/.well-known/acme-challenge/rmcp-e2e-probe`);
  expect(acme.status).toBe(404);
  expect(acme.body).not.toContain('"issuer"');
});
