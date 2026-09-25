// @ts-check
/*
 * What the browser tests share: a test that fails on any script error on the
 * page, forms sent over HTTP the way the pages send them, a select2 picker
 * driven the way a person drives it, and TOTP codes worked out from a key.
 *
 * Addresses are relative ("orders/4", not "/orders/4"), so that an
 * application living under a path (http://localhost/cloudexus/web/) is
 * reached under it.
 */
const base = require('@playwright/test');
const crypto = require('crypto');

/** The same test, but a script error — or a script the CSP refused — on any page it opened fails it. */
const test = base.test.extend({
    page: async ({ page }, use) => {
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        // A script the Content-Security-Policy stopped is only a console error, not a page error.
        page.on('console', (message) => {
            if (message.type() === 'error' && /Content Security Policy|Refused to (execute|load)/i.test(message.text())) {
                errors.push(message.text());
            }
        });
        page.on('dialog', (dialog) => dialog.accept());
        await use(page);
        base.expect(errors, 'script errors on the page').toEqual([]);
    },
});

/** @param {import('@playwright/test').APIRequestContext} request */
async function csrf(request) {
    const html = await (await request.get('profile')).text();
    const match = html.match(/<meta name="csrf-token" content="([^"]+)"/);
    if (!match) {
        throw new Error('No CSRF token on the profile page — is the session signed in?');
    }
    return match[1];
}

/**
 * A form sent the way the page sends it, with the session's token.
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {string} path
 * @param {Record<string, string | number>} form
 */
async function post(request, path, form = {}) {
    return request.post(path, { form: { _token: await csrf(request), ...form }, maxRedirects: 0 });
}

/**
 * Picks the first match in a select2 picker: opens it, types, and chooses —
 * the search is sent to the server as it is typed.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} select the <select> select2 is on
 * @param {string} text
 */
async function pick(page, select, text) {
    await select.locator('xpath=following-sibling::span[contains(@class, "select2")]').first().click();
    await page.locator('.select2-container--open .select2-search__field').fill(text);
    const option = page.locator('.select2-container--open .select2-results__option--selectable').first();
    await base.expect(option).toBeVisible();
    await option.click();
}

/** The six digits an authenticator app would show now for a base32 key. */
function totp(secret, time = Date.now()) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of secret.replace(/\s+/g, '').toUpperCase()) {
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    }
    const key = Buffer.from((bits.match(/.{8}/g) || []).map((byte) => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(time / 1000 / 30)));
    const hash = crypto.createHmac('sha1', key).update(counter).digest();
    const offset = hash[19] & 0x0f;
    const number = (hash.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;
    return String(number).padStart(6, '0');
}

module.exports = { test, expect: base.expect, post, pick, totp };
