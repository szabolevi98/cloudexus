// @ts-check
/*
 * Signs in once, through the form, and keeps the session for every test — in
 * English, whatever the user had chosen, so that what the tests read is what
 * they expect.
 */
const { chromium } = require('@playwright/test');
const fs = require('fs');

module.exports = async function signIn(config) {
    const { baseURL, storageState, channel } = config.projects[0].use;
    const user = process.env.CX_USER;
    const password = process.env.CX_PASSWORD;

    if (!user || !password) {
        throw new Error('Set CX_USER and CX_PASSWORD to a super admin of the application at ' + baseURL);
    }

    const browser = await chromium.launch({ channel });
    const page = await browser.newPage({ baseURL });

    await page.goto('lang/en');
    await page.goto('login');
    await page.locator('#username').fill(user);
    await page.locator('#password').fill(password);
    await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/login')), page.locator('#login-submit').click()]);

    fs.mkdirSync('test-results', { recursive: true });
    await page.context().storageState({ path: String(storageState) });
    await browser.close();
};
