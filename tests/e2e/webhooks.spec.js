// @ts-check
/*
 * A webhook set up from the admin page: made with some of the events, its
 * secret shown for the receiver, a test message sent — its outcome, either
 * way, in the list of deliveries — paused, and deleted.
 */
const { test, expect } = require('./helpers');

test('a webhook is made, pinged, paused and deleted', async ({ page }) => {
    const name = 'E2E hook ' + Date.now();
    await page.goto('webhooks');

    await page.locator('#webhook-name').fill(name);
    await page.locator('#webhook-url').fill('https://example.com/cloudexus-hook');
    // Only the orders.
    for (const box of await page.locator('#webhook-form input[name="events[]"]').all()) {
        const value = await box.getAttribute('value');
        await box.setChecked(value === 'order.created' || value === 'order.cancelled');
    }
    await Promise.all([page.waitForURL(/\/webhooks\/\d+$/), page.locator('#webhook-form button[type="submit"]').click()]);
    await expect(page.locator('.alert-success')).toBeVisible();
    await expect(page.locator('#webhook-secret')).toHaveValue(/^[0-9a-f]{64}$/);
    await expect(page.locator('#event-order\\.created')).toBeChecked();
    await expect(page.locator('#event-stock\\.changed')).not.toBeChecked();

    // The ping's outcome depends on the network; either way it is said and recorded.
    await page.locator('#webhook-ping').click();
    await expect(page.locator('.alert-success, .alert-danger').first()).toBeVisible();
    await expect(page.locator('#webhook-deliveries tbody tr').first()).toContainText('ping');

    await page.locator('form[action$="/toggle"] button').click();
    await expect(page.locator('.cx-card__header .badge').first()).toContainText(/Paused/i);

    await page.locator('form[action$="/delete"] button').click();
    await expect(page).toHaveURL(/\/webhooks$/);
    await expect(page.locator('main')).not.toContainText(name);
});

test('an address into a private network is refused', async ({ page }) => {
    await page.goto('webhooks');
    await page.locator('#webhook-name').fill('Private');
    await page.locator('#webhook-url').fill('http://169.254.169.254/latest/meta-data');
    await page.locator('#webhook-form button[type="submit"]').click();
    await expect(page.locator('.alert-danger')).toBeVisible();
    await expect(page).toHaveURL(/\/webhooks$/);
});
