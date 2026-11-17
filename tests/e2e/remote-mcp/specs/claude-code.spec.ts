import { test, expect } from '@playwright/test';
import { install, readTool, writeTool, type InstallName } from '../lib/config.js';
import { claudeCodeSession } from '../lib/claude-code.js';
import { approveConsentAt } from '../lib/browser.js';
import * as v from '../lib/verdicts.js';

test('Claude Code lists and calls a read tool on a root, a subdirectory and a plain-permalink install', async ({ page }) => {
  test.setTimeout(900_000);
  for (const name of ['root', 'subdir', 'plain'] as InstallName[]) {
    await test.step(name, async () => {
      const session = await claudeCodeSession(install(name));
      try {
        // Real discovery and consent: Claude Code prints the authorize URL it built from the
        // Dashboard's metadata, and the browser approves it the way a person would.
        const authorize = await session.startLogin();
        const redirect = await approveConsentAt(page, authorize, { access: 'read' });
        await session.finishLogin(redirect);
        const events = await session.callTool(readTool());
        expect(v.claudeCodeRun(events, session.server, readTool(), writeTool())).toEqual([]);
      } finally {
        await session.close();
      }
    });
  }
});
