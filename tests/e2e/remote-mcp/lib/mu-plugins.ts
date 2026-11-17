import fs from 'node:fs';
import path from 'node:path';
import { HARNESS_DIR, muPluginsDir } from './config.js';

/**
 * Copy one harness mu-plugin into the selected install for the duration of `fn`. The copy
 * is removed afterwards even when `fn` fails, so a failed check can't leave the kill switch on.
 */
export async function withMuPlugin<T>(name: string, fn: () => Promise<T>, install: 'root' | 'plain' = 'root'): Promise<T> {
  const dir = muPluginsDir(install);
  if (!fs.existsSync(dir) || !fs.statSync(dir).isDirectory()) {
    throw new Error(`RMCP_E2E_${install.toUpperCase()}_MU_PLUGINS_DIR is not a directory: ${dir}`);
  }
  const source = path.join(HARNESS_DIR, 'mu-plugins', `${name}.php`);
  const target = path.join(dir, `mainwp-rmcp-harness-${name}.php`);
  fs.copyFileSync(source, target);
  try {
    return await fn();
  } finally {
    fs.rmSync(target, { force: true });
  }
}
