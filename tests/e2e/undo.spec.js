// @ts-check
/*
 * A product deleted by mistake, and brought back with the button that shows
 * on the next page — with the same SKU and name, at the same address. Then
 * it is deleted for good.
 */
const { test, expect, post } = require('./helpers');

test('a deleted product comes back with Undo', async ({ page, request }) => {
    const sku = 'E2EU' + Date.now().toString(36).toUpperCase();
    await post(request, 'products/create', { sku, 'name[1]': 'Visszahozható', price: 100, vat_rate: 27, is_active: 1 });
    const id = (await (await request.get(`products/search?q=${sku}`)).json()).results[0].id;

    try {
        await page.goto(`products?q=${sku}`);
        await page.locator(`form[action$="/products/${id}/delete"] button`).click();
        await expect(page.locator('#undo-offer')).toContainText(sku);
        expect((await (await request.get(`products/search?q=${sku}`)).json()).results).toHaveLength(0);

        await Promise.all([page.waitForURL(new RegExp(`/products/${id}/edit$`)), page.locator('#undo-offer button').click()]);
        await expect(page.locator('#sku')).toHaveValue(sku);
        await expect(page.locator('.alert-success')).toBeVisible();

        // Offered once: the next page no longer has it.
        await page.goto('products');
        await expect(page.locator('#undo-offer')).toHaveCount(0);
    } finally {
        await post(request, `products/${id}/delete`);
    }
});
