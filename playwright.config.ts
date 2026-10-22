import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env.E2E_PORT ?? 8123);

/**
 * End-to-end smoke and accessibility checks. Runs against `php artisan serve`
 * with a production build of the frontend (see the CI "e2e" job).
 */
export default defineConfig({
  testDir: 'e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI
    ? [['github'], ['html', { open: 'never' }], ['json', { outputFile: 'test-results/results.json' }]]
    : 'list',
  use: {
    baseURL: `http://127.0.0.1:${port}`,
    trace: 'retain-on-failure',
    // Optional local override when the Playwright browser download is unavailable.
    // wurk.test (the marketing site's host in e2e) resolves to the local server.
    launchOptions: {
      args: ['--host-resolver-rules=MAP wurk.test 127.0.0.1', ...(process.env.PW_CHROMIUM_PATH ? ['--no-sandbox'] : [])],
      ...(process.env.PW_CHROMIUM_PATH ? { executablePath: process.env.PW_CHROMIUM_PATH } : {}),
    },
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
  ],
  webServer: {
    command: `php artisan serve --host=127.0.0.1 --port=${port}`,
    url: `http://127.0.0.1:${port}/health/live`,
    reuseExistingServer: !process.env.CI,
    timeout: 60_000,
  },
});
