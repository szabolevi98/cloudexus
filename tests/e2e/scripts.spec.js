// @ts-check
/*
 * Every page with a script of its own opens without a script error and
 * without the Content-Security-Policy (script-src 'self') refusing anything —
 * the test fixture fails on either — and the script visibly did its work.
 */
const { test, expect } = require('./helpers');

test('the CSP allows only the application\'s own scripts', async ({ request }) => {
    const csp = (await request.get('dashboard')).headers()['content-security-policy'] || '';
    expect(csp).toMatch(/script-src 'self'/);
    expect(csp).not.toMatch(/unsafe-inline/);
});

test('the pages with scripts still work under the CSP', async ({ page }) => {
    test.setTimeout(120_000);

    await page.goto('dashboard');
    await expect(page.locator('#orders-chart')).toHaveAttribute('width', /\d+/);

    await page.goto('api-docs');
    await expect(page.locator('#markdown-content h1, #markdown-content h2').first()).toBeVisible();

    await page.goto('stock/in');
    await expect(page.locator('#location_id option').first()).toBeAttached();
    await page.goto('stock/transfer');
    await expect(page.locator('#to_location_id option').first()).toBeAttached();

    await page.goto('stock');
    await page.goto('stocktaking/create');

    await page.goto('cash/create');
    const type = page.locator('#type');
    await type.selectOption('kiadas');
    await expect(page.locator('#invoice-field')).toBeHidden();
    await type.selectOption('bevetel');
    await expect(page.locator('#invoice-field')).toBeVisible();

    await page.goto('price-rules/create');
    await page.goto('orders/create');
    await expect(page.locator('#shipping_address_id option').first()).toBeAttached();

    await page.goto('products/create');
    await expect(page.locator('.tox-tinymce').first()).toBeVisible({ timeout: 15_000 });
    await page.locator('#attr-add').click();
    await expect(page.locator('#attr-rows .attr-row')).not.toHaveCount(0);

    await page.goto('categories/create');
    await expect(page.locator('.tox-tinymce').first()).toBeVisible({ timeout: 15_000 });
});

test('a confirmation that is declined stops the form', async ({ page }) => {
    await page.goto('products');
    page.removeAllListeners('dialog');
    page.once('dialog', (dialog) => dialog.dismiss());
    await page.locator('form[action$="/delete"] button').first().click();
    await expect(page).toHaveURL(/\/products$/);
    await expect(page.locator('.alert-success')).toHaveCount(0);
    page.on('dialog', (dialog) => dialog.accept());
});
