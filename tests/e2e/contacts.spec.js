// @ts-check
/*
 * A partner's contacts on its page: one added, edited, offered for an
 * activity, and deleted.
 */
const { test, expect } = require('./helpers');

test('a contact is added, edited, offered for an activity, and deleted', async ({ page, request }) => {
    const partner = (await (await request.get('partners/search?q=a')).json()).results[0];
    const name = 'E2E Kapcsolat ' + Date.now().toString(36);
    await page.goto(`partners/${partner.id}`);

    await page.locator('#contact-add').click();
    const form = page.locator('#contact-new form');
    await form.locator('input[name="name"]').fill(name);
    await form.locator('input[name="position"]').fill('Beszerző');
    await form.locator('input[name="email"]').fill('e2e.kapcsolat@example.test');
    await form.locator('input[name="receives_invoices"]').check();
    await form.locator('button[type="submit"]').click();
    await expect(page.locator('.alert-success')).toContainText(name);

    const row = page.locator('#partner-contacts [data-contact]', { hasText: name });
    await expect(row).toContainText('Beszerző');
    await expect(row.locator('.bi-receipt')).toBeVisible();
    await expect(page.locator('#activity-contact option', { hasText: name })).toHaveCount(1);

    await row.locator('[data-bs-target^="#contact-edit-"]').click();
    await row.locator('form input[name="position"]').fill('Beszerzési vezető');
    await row.locator('.collapse form button[type="submit"]').click();
    await expect(page.locator('#partner-contacts [data-contact]', { hasText: name })).toContainText('Beszerzési vezető');

    await page.locator('#partner-contacts [data-contact]', { hasText: name }).locator('form[action$="/delete"] button').click();
    await expect(page.locator('#partner-contacts [data-contact]', { hasText: name })).toHaveCount(0);
});
