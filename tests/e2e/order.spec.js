// @ts-check
/*
 * A customer order typed in the way a person types it: the customer and the
 * products picked by typing a few letters, each line priced by the server as
 * it is picked, the totals following every change — then saved, and deleted
 * again (an order that was never invoiced can be, once cancelled).
 */
const { test, expect, post, pick } = require('./helpers');

/** "12 345 Ft" → 12345 */
const amount = (text) => Number((text || '').replace(/[^\d,-]/g, '').replace(',', '.'));

let orderId = 0;

test.afterAll(async ({ request }) => {
    // A confirmed order is cancelled first; a cancelled one can be deleted.
    if (orderId) {
        await post(request, `orders/${orderId}/cancel`);
        await post(request, `orders/${orderId}/delete`);
    }
});

test('the lines price themselves, the total follows, and the order is saved', async ({ page }) => {
    await page.goto('orders/create');

    await pick(page, page.locator('#partner_id'), 'a');

    // The form opens with one empty line.
    await expect(page.locator('.cx-line-item')).toHaveCount(1);
    const first = page.locator('.cx-line-item').nth(0);
    await pick(page, first.locator('select.cx-line-item__product'), 'a');
    // The price comes from the server for this customer, quantity and day.
    await expect(first.locator('.cx-line-item__price')).not.toHaveValue('');
    await first.locator('.cx-line-item__quantity').fill('3');
    await first.locator('.cx-line-item__quantity').blur();

    const price = Number(await first.locator('.cx-line-item__price').inputValue());
    expect(price).toBeGreaterThan(0);
    await expect.poll(async () => amount(await first.locator('.cx-line-item__total').textContent())).toBe(Math.round(price * 3));

    await page.locator('#add-line-item').click();
    const second = page.locator('.cx-line-item').nth(1);
    await pick(page, second.locator('select.cx-line-item__product'), 'e');
    await expect(second.locator('.cx-line-item__price')).not.toHaveValue('');
    await second.locator('.cx-line-item__quantity').fill('1');
    await second.locator('.cx-line-item__quantity').blur();

    const lineTotals = async () => {
        let sum = 0;
        for (const cell of await page.locator('.cx-line-item__total').all()) {
            sum += amount(await cell.textContent());
        }
        return sum;
    };
    await expect.poll(async () => amount(await page.locator('#line-items-total').textContent())).toBe(await lineTotals());

    // Taking a line off takes it off the total too.
    await second.locator('.cx-line-item__remove').click();
    await expect(page.locator('.cx-line-item')).toHaveCount(1);
    await expect.poll(async () => amount(await page.locator('#line-items-total').textContent())).toBe(Math.round(price * 3));

    await Promise.all([page.waitForURL(/\/orders\/\d+$/), page.locator('#order-form button[type="submit"]').click()]);
    orderId = Number(page.url().match(/\/orders\/(\d+)$/)?.[1] || 0);
    expect(orderId).toBeGreaterThan(0);
    // The saved order has the one line, with the total the form showed.
    const saved = page.locator('main table tbody tr');
    await expect(saved).toHaveCount(1);
    expect(amount(await saved.first().locator('td.text-end').last().textContent())).toBe(Math.round(price * 3));
});
