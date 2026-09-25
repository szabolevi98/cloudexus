// @ts-check
/*
 * The tools on top of a list: a filter saved under a name and brought back
 * with one click, then deleted; and rows ticked and changed at once — the
 * bar only shows with something ticked, and says how many. Everything it
 * makes is removed again.
 */
const { test, expect, post } = require('./helpers');

test('a filter is saved, brought back, and deleted', async ({ page }) => {
    const name = 'E2E ' + Date.now();
    await page.goto('invoices?status=unpaid');

    await page.locator('.cx-saved-filters > button').click();
    await page.locator('.cx-saved-filters input[name="name"]').fill(name);
    await page.locator('.cx-saved-filters form[action$="/saved-filters"] button[type="submit"]').click();
    await expect(page.locator('.alert-success')).toContainText(name);

    await page.goto('invoices');
    await page.locator('.cx-saved-filters > button').click();
    await Promise.all([page.waitForURL(/status=unpaid/), page.locator('.cx-saved-filters a', { hasText: name }).click()]);

    await page.locator('.cx-saved-filters > button').click();
    const row = page.locator('.cx-saved-filters li', { hasText: name });
    await row.locator('button[type="submit"]').click();
    await expect(page.locator('.alert-success')).toBeVisible();
    await page.locator('.cx-saved-filters > button').click();
    await expect(page.locator('.cx-saved-filters li', { hasText: name })).toHaveCount(0);
});

test('ticked products are changed at once', async ({ page, request }) => {
    const prefix = 'E2EB' + Date.now().toString(36).toUpperCase();
    const ids = [];
    for (const n of [1, 2]) {
        const made = await post(request, 'products/create', {
            sku: `${prefix}-${n}`, 'name[1]': `Bulk ${n}`, price: 100, vat_rate: 27, is_active: 1,
        });
        expect([302, 303]).toContain(made.status());
    }
    for (const result of (await (await request.get(`products/search?q=${prefix}`)).json()).results) {
        ids.push(result.id);
    }
    expect(ids).toHaveLength(2);

    try {
        await page.goto(`products?q=${prefix}`);
        const bar = page.locator('[data-bulk-bar]');
        await expect(bar).toBeHidden();

        await page.locator('[data-bulk-all]').check();
        await expect(bar).toBeVisible();
        await expect(page.locator('[data-bulk-count]')).toContainText('2');

        await bar.locator('select[name="action"]').selectOption('deactivate');
        await bar.locator('button[type="submit"]').click();
        await expect(page.locator('.alert-success')).toBeVisible();
        await expect(page).toHaveURL(new RegExp(`q=${prefix}`));
        await expect(page.locator('main table tbody tr', { hasText: 'Inactive' })).toHaveCount(2);
    } finally {
        for (const id of ids) {
            await post(request, `products/${id}/delete`);
        }
    }
});
