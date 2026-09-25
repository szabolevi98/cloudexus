// @ts-check
/*
 * Ctrl+K: a few letters of a product find it, Enter opens it, and the next
 * time the box opens empty it is at the top of what was opened lately. The
 * arrow keys move, Escape closes.
 */
const { test, expect } = require('./helpers');

test('Ctrl+K finds a product, opens it, and remembers it', async ({ page, request }) => {
    const first = (await (await request.get('products/search?q=a')).json()).results[0];
    const sku = String(first.text).split(' — ')[0];

    await page.goto('dashboard');
    const box = page.locator('#cx-palette');
    await page.keyboard.press('Control+k');
    await expect(box).toBeVisible();

    await box.locator('[data-palette-input]').fill(sku);
    const item = box.locator('.cx-palette__item', { hasText: sku }).first();
    await expect(item).toBeVisible();

    // The first match is the active one; the arrows move and come back.
    await box.locator('[data-palette-input]').press('ArrowDown');
    await box.locator('[data-palette-input]').press('ArrowUp');
    await expect(box.locator('.cx-palette__item.is-active')).toContainText(sku);

    await Promise.all([page.waitForURL(/\/products\/\d+\/edit$/), box.locator('[data-palette-input]').press('Enter')]);

    await page.goto('dashboard');
    await page.locator('[data-palette-open]').click();
    await expect(box.locator('.cx-palette__item').first()).toContainText(sku);

    await page.keyboard.press('Escape');
    await expect(box).toBeHidden();
});

test('pages and actions are found by name', async ({ page }) => {
    await page.goto('dashboard');
    await page.keyboard.press('Control+k');
    await page.locator('#cx-palette [data-palette-input]').fill('barcode');
    await expect(page.locator('#cx-palette .cx-palette__item').first()).toContainText(/Barcode/i);
});
