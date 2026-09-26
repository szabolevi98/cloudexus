// @ts-check
/*
 * A forgotten password, from the link on the sign-in page to the email and
 * back: the answer does not say whether the user exists, the link in the
 * email sets a new password once, and then no longer works. It sets the same
 * password again, so the signed-in user the other tests use is left as it
 * was.
 *
 * Needs [mail] transport = file on the server, and the server's var/mail
 * folder here (CX_MAIL_DIR, default var/mail): the test reads the email from
 * it. Skipped when the application has no email.
 */
const { test, expect } = require('./helpers');
const fs = require('fs');
const path = require('path');

const mailDir = process.env.CX_MAIL_DIR || 'var/mail';

/** The newest reset link in the mail folder written after `since`. */
function latestLink(since) {
    if (!fs.existsSync(mailDir)) {
        return null;
    }
    const files = fs.readdirSync(mailDir)
        .filter((name) => name.endsWith('.eml'))
        .map((name) => path.join(mailDir, name))
        .filter((file) => fs.statSync(file).mtimeMs >= since)
        .sort((a, b) => fs.statSync(b).mtimeMs - fs.statSync(a).mtimeMs);
    for (const file of files) {
        // Quoted-printable: soft line breaks joined, =3D back to "=".
        const text = fs.readFileSync(file, 'utf8').replace(/=\r?\n/g, '').replace(/=3D/g, '=');
        const match = text.match(/reset-password\/([0-9a-f]{64})/);
        if (match) {
            return 'reset-password/' + match[1];
        }
    }
    return null;
}

test.use({ storageState: { cookies: [], origins: [] } });

test('a reset link from the email sets a new password once', async ({ page }) => {
    await page.goto('lang/en');
    await page.goto('login');
    const forgot = page.locator('a[href$="/forgot-password"]');
    test.skip((await forgot.count()) === 0, 'the application sends no email');

    const since = Date.now() - 1000;
    await forgot.click();

    // Nobody by that name: the same answer, and no email.
    await page.locator('#login').fill('nobody-' + since);
    await page.locator('form[action$="/forgot-password"] button[type="submit"]').click();
    await expect(page.locator('.alert-success')).toBeVisible();
    expect(latestLink(since)).toBeNull();

    await page.goto('forgot-password');
    await page.locator('#login').fill(process.env.CX_USER || '');
    await page.locator('form[action$="/forgot-password"] button[type="submit"]').click();
    await expect(page.locator('.alert-success')).toBeVisible();

    await expect.poll(() => latestLink(since), { timeout: 10_000 }).not.toBeNull();
    const link = String(latestLink(since));

    await page.goto(link);
    await page.locator('#password').fill(process.env.CX_PASSWORD || '');
    await page.locator('#password_confirm').fill('something else');
    await page.locator('form button[type="submit"]').click();
    await expect(page.locator('.alert-danger')).toBeVisible();

    await page.locator('#password').fill(process.env.CX_PASSWORD || '');
    await page.locator('#password_confirm').fill(process.env.CX_PASSWORD || '');
    await Promise.all([page.waitForURL(/\/login$/), page.locator('form button[type="submit"]').click()]);
    await expect(page.locator('.alert-success')).toBeVisible();

    // Once only.
    await page.goto(link);
    await expect(page.locator('#password')).toHaveCount(0);
    await expect(page.locator('a[href$="/forgot-password"]').first()).toBeVisible();

    // A new password signs out every browser signed in before it — the saved
    // session of the other tests too. Sign in again and save the new one.
    await page.goto('login');
    await page.locator('#username').fill(process.env.CX_USER || '');
    await page.locator('#password').fill(process.env.CX_PASSWORD || '');
    await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/login')), page.locator('#login-submit').click()]);
    await page.goto('lang/en');
    await page.context().storageState({ path: String(test.info().project.use.storageState) });
});
