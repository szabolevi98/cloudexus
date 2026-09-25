// @ts-check
/*
 * Two-step sign-in on one's own profile: set up with the key the page shows
 * (the code worked out here, as an authenticator app would), the recovery
 * codes shown once, and turned off again with the password — so the signed-in
 * user the other tests use is left as it was.
 */
const { test, expect, totp } = require('./helpers');

test('set up with a code from the key, recovery codes shown once, turned off with the password', async ({ page }) => {
    await page.goto('profile/two-factor');
    await page.locator('form[action$="/profile/two-factor/start"] button').click();

    const key = (await page.locator('.cx-secret-key').textContent()) || '';
    await expect(page.locator('.cx-qr svg')).toBeVisible();

    await page.locator('#code').fill('000000');
    await page.locator('form[action$="/profile/two-factor/confirm"] button').click();
    await expect(page.locator('.alert-danger, .cx-flash--error').first()).toBeVisible();
    await expect(page.locator('.cx-secret-key')).toHaveText(key, { useInnerText: true });

    await page.locator('#code').fill(totp(key));
    await page.locator('form[action$="/profile/two-factor/confirm"] button').click();
    await expect(page.locator('.cx-recovery-codes li')).toHaveCount(10);

    // Shown once: a reload no longer has them.
    await page.reload();
    await expect(page.locator('.cx-recovery-codes')).toHaveCount(0);
    await expect(page.locator('main')).toContainText('10');

    await page.locator('#password_off').fill('not the password');
    await page.locator('form[action$="/profile/two-factor/disable"] button').click();
    await expect(page.locator('#password_off')).toBeVisible();

    await page.locator('#password_off').fill(process.env.CX_PASSWORD || '');
    await page.locator('form[action$="/profile/two-factor/disable"] button').click();
    await expect(page.locator('form[action$="/profile/two-factor/start"]')).toBeVisible();
});
