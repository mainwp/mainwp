import { defineConfig } from '@playwright/test';

// `selftest` checks the harness offline. `e2e` runs the named checks against a deployed copy and
// shares one Dashboard, so it runs serially.
export default defineConfig({
  outputDir: 'output/test-results',
  reporter: [['list']],
  forbidOnly: true,
  retries: 0,
  projects: [
    {
      name: 'selftest',
      testDir: 'selftest',
      fullyParallel: true,
      timeout: 30_000,
    },
    {
      name: 'e2e',
      testDir: 'specs',
      fullyParallel: false,
      workers: 1,
      timeout: 120_000,
      use: {
        browserName: 'chromium',
        channel: process.env.RMCP_E2E_BROWSER_CHANNEL || undefined,
        headless: process.env.RMCP_E2E_HEADED ? false : true,
        trace: 'off',
        screenshot: 'off',
      },
    },
  ],
});
