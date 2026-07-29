// Playwright config for REP entry-point regression checks.
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests',
  timeout: 90000,
  expect: {
    timeout: 20000,
  },
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8080',
    headless: true,
    ignoreHTTPSErrors: true,
    actionTimeout: 20000,
    navigationTimeout: 30000,
    trace: 'retain-on-failure',
  },
});
