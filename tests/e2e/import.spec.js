// @ts-check
/*
 * Products from a CSV, the way a person does it: the file uploaded, the
 * preview read row by row — new, unchanged, refused with the reason — and
 * only then confirmed. The products it makes are deleted again.
 */
const { test, expect, post } = require('./helpers');

const sku = `E2E-${Date.now().toString(36).toUpperCase()}`;

test.afterAll(async ({ request }) => {
    const search = await (await request.get(`products/search?q=${sku}`)).json();
    for (const result of search.results) {
        await post(request, `products/${result.id}/delete`);
    }
});

test('a CSV is previewed row by row and imported only when confirmed', async ({ page, request }) => {
    const existing = String((await (await request.get('products/search?q=a')).json()).results[0].text).split(' — ')[0];
    const csv = [
        'Cikkszám;Megnevezés;Nettó ár;ÁFA %;Aktív;Színe',
        `${sku};Böngészős teszttermék;1 234,50;27;Igen;piros`,
        `${sku}-B;Hibás ár;sok;27;Igen;kék`,
        `${existing};;;;;`,
    ].join('\r\n');

    await page.goto('products');
    await page.locator('a[href$="/import/products"]').click();
    await page.locator('#file').setInputFiles({ name: 'termekek.csv', mimeType: 'text/csv', buffer: Buffer.from('﻿' + csv, 'utf8') });
    await page.locator('form[action$="/import/products"] button[type="submit"]').click();

    // The preview: one new, one refused, one unchanged; the unknown column named.
    await expect(page.locator('#import-counts [data-action="create"]')).toContainText('1');
    await expect(page.locator('#import-counts [data-action="error"]')).toContainText('1');
    await expect(page.locator('#import-counts [data-action="unchanged"]')).toContainText('1');
    await expect(page.locator('main')).toContainText('Színe');
    await expect(page.locator('#import-rows tr[data-action="error"]')).toContainText('sok');

    // Nothing is written before the confirmation.
    expect((await (await request.get(`products/search?q=${sku}`)).json()).results).toHaveLength(0);

    await Promise.all([page.waitForURL(/\/products$/), page.locator('#import-confirm').click()]);
    await expect(page.locator('.alert-success')).toBeVisible();

    const made = (await (await request.get(`products/search?q=${sku}`)).json()).results;
    expect(made).toHaveLength(1);
    expect(made[0].text).toContain('Böngészős teszttermék');
});
