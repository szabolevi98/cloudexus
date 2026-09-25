// @ts-check
/*
 * The browser tests (tests/e2e): the parts of Cloudexus that only a real
 * browser can check — the search-as-you-type pickers, the line items that
 * price themselves as they are typed, the barcode collector, two-step
 * sign-in, the column headers and the sidebar. The smoke test checks the same
 * routes over plain HTTP; these check that the scripts on top of them work.
 *
 *   CX_URL       where the application is (default http://127.0.0.1:8080/)
 *   CX_USER      a super admin to sign in as
 *   CX_PASSWORD  their password
 *   CX_CHANNEL   "chrome" to use the Chrome on the machine rather than the
 *                Chromium Playwright downloads
 *
 * One test at a time: they share one database and one signed-in user.
 */
const { defineConfig } = require('@playwright/test');

const base = (process.env.CX_URL || 'http://127.0.0.1:8080/').replace(/\/?$/, '/');

module.exports = defineConfig({
    testDir: 'tests/e2e',
    globalSetup: require.resolve('./tests/e2e/sign-in.js'),
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    // A local development server is slower than CI's (a debugger, no opcache).
    timeout: process.env.CI ? 30_000 : 60_000,
    expect: { timeout: 7_000 },
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: base,
        storageState: 'test-results/.signed-in.json',
        channel: process.env.CX_CHANNEL || undefined,
        locale: 'en-GB',
        viewport: { width: 1440, height: 900 },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
});
