import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright config for the opt-in remote MainWP E2E suite.
 *
 * Credentials and targets come from REMOTE_* env vars (source
 * network-testbed/.env first, or run via `run.sh test-live e2e`, which sources
 * it for you). Specs self-skip when the relevant REMOTE_* vars are unset.
 */
export default defineConfig({
  testDir: './tests',
  // Real cross-server actions (sync, backup) are slow; give them room. Specific
  // long flows raise their own timeout with test.setTimeout().
  timeout: 120_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    // Real public TLS — keep verification on.
    ignoreHTTPSErrors: false,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 20_000,
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
