import { fileURLToPath } from 'node:url';
import path from 'node:path';

export const HARNESS_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const OUTPUT_DIR = path.join(HARNESS_DIR, 'output');

// Claude Code's bundled document registers port-less loopback callbacks, so the harness can use
// it with a random port without any test-only client in the registry.
export const CLAUDE_CODE_CLIENT_ID = 'https://claude.ai/oauth/claude-code-client-metadata';

export type InstallName = 'root' | 'subdir' | 'plain' | 'clone';

export interface Install {
  name: InstallName;
  /** The stored `home` of the install, https, no trailing slash. */
  home: string;
  /** Permalink mode decides which endpoint forms the module answers. */
  permalinks: 'pretty' | 'plain';
}

export class TargetNotConfigured extends Error {}

const INSTALL_ENV: Record<InstallName, string> = {
  root: 'RMCP_E2E_ROOT_URL',
  subdir: 'RMCP_E2E_SUBDIR_URL',
  plain: 'RMCP_E2E_PLAIN_URL',
  clone: 'RMCP_E2E_CLONE_URL',
};

function required(name: string): string {
  const value = process.env[name];
  if (!value) {
    throw new TargetNotConfigured(`${name} is not set; see tests/e2e/remote-mcp/README.md`);
  }
  return value;
}

export function install(name: InstallName): Install {
  const home = required(INSTALL_ENV[name]).replace(/\/+$/, '');
  if (!/^https:\/\//i.test(home)) {
    throw new TargetNotConfigured(`${INSTALL_ENV[name]} must be an https URL`);
  }
  return { name, home, permalinks: name === 'plain' ? 'plain' : 'pretty' };
}

export function adminCredentials(): { user: string; password: string } {
  return { user: required('RMCP_E2E_ADMIN_USER'), password: required('RMCP_E2E_ADMIN_PASSWORD') };
}

/** Absolute path of the selected install's `wp-content/mu-plugins`. */
export function muPluginsDir(name: 'root' | 'plain' = 'root'): string {
  return required(`RMCP_E2E_${name.toUpperCase()}_MU_PLUGINS_DIR`);
}

/**
 * The clone step is host-specific (a Local clone, `wp db export | wp db import` plus
 * `wp search-replace`, a staging plugin), so the operator supplies it as one command.
 */
export function cloneSyncCommand(): string {
  return required('RMCP_E2E_CLONE_SYNC_CMD');
}

export function panelPath(): string {
  return process.env.RMCP_E2E_PANEL_PATH || '/wp-admin/admin.php?page=mainwp-mcp&tab=direct';
}

export function readTool(): string {
  return process.env.RMCP_E2E_READ_TOOL || 'count_sites_v1';
}

/** Fixed, because the step-up check observes its effect through `list_tags_v1`. */
export function writeTool(): string {
  return 'add_tag_v1';
}

export function claudeModel(): string {
  return process.env.RMCP_E2E_CLAUDE_MODEL || 'haiku';
}
