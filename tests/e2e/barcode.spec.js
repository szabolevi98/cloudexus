// @ts-check
/*
 * The barcode collector, the way a hand scanner uses it: the code typed and
 * Enter pressed. A known code adds a line, the same code again adds one to
 * it, an unknown code says so, and the button only works with something to
 * book. Nothing is booked here: a stock movement is for good.
 */
const { test, expect } = require('./helpers');

test('scans add lines, a repeated scan adds one, an unknown code is refused', async ({ page, request }) => {
    // A real SKU from the catalog: the collector takes the SKU as well as the barcode.
    const search = await (await request.get('products/search?q=a')).json();
    const sku = String(search.results[0].text).split(' — ')[0];

    await page.goto('stock/barcode');
    const scan = page.locator('#scan-input');
    const rows = page.locator('#scan-body tr:not(#scan-empty)');
    await expect(page.locator('#scan-submit')).toBeDisabled();

    await scan.fill(sku);
    await scan.press('Enter');
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText(sku);
    await expect(rows.first().locator('input[name="quantity[]"]')).toHaveValue('1');

    await scan.fill(sku);
    await scan.press('Enter');
    await expect(rows).toHaveCount(1);
    await expect(rows.first().locator('input[name="quantity[]"]')).toHaveValue('2');
    await expect(page.locator('#scan-submit')).toBeEnabled();

    await scan.fill('0000000000000-no-such-code');
    await scan.press('Enter');
    await expect(page.locator('#scan-feedback')).toContainText('0000000000000-no-such-code');
    await expect(rows).toHaveCount(1);

    // The field stays ready for the next scan.
    await expect(scan).toBeFocused();
    await expect(scan).toHaveValue('');

    await rows.first().locator('.scan-remove').click();
    await expect(rows).toHaveCount(0);
    await expect(page.locator('#scan-submit')).toBeDisabled();
});
