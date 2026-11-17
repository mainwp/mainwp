import crypto from 'node:crypto';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { HARNESS_DIR } from './config.js';
import type { Connection } from './oauth.js';
import type { CloneObservation } from './workflows.js';

export function cloneObserver(connection: Connection): (side: 'source' | 'clone') => Promise<CloneObservation> {
  const comparisonKey = crypto.randomBytes(32).toString('hex');
  return async (side) => {
    const variable = side === 'source' ? 'RMCP_E2E_ROOT_WP_PATH' : 'RMCP_E2E_CLONE_WP_PATH';
    const wpPath = process.env[variable];
    if (!wpPath) throw new Error(`${variable} is required to verify the copied token state`);
    const result = spawnSync(process.env.RMCP_E2E_WP_CLI || 'wp', [
      `--path=${wpPath}`, '--skip-plugins', '--skip-themes', 'eval-file', path.join(HARNESS_DIR, 'fixtures/clone-observer.php'),
    ], {
      input: JSON.stringify({ comparison_key: comparisonKey, access: connection.tokens.access, refresh: connection.tokens.refresh, resource: connection.endpoints.mcp }),
      encoding: 'utf8', timeout: 30_000, stdio: ['pipe', 'pipe', 'pipe'],
    });
    if (result.status !== 0) throw new Error('clone observation failed; no credential output retained');
    let observation: CloneObservation & { home?: string };
    try { observation = JSON.parse(result.stdout); } catch { throw new Error('clone observer returned no valid proof'); }
    const expectedHome = side === 'source' ? connection.install.home : process.env.RMCP_E2E_CLONE_URL?.replace(/\/+$/, '');
    if (observation.home !== expectedHome) throw new Error('clone observer path does not match the configured home');
    return observation;
  };
}
