// @ts-check
/*
 * A partner in one place: its figures and its timeline (filtered), and its
 * credit limit and payment terms at work — the invoice form warns about the
 * limit and takes its due date from the terms. The partner is left as it was.
 */
const { test, expect } = require('./helpers');

const inDays = (days) => {
    const d = new Date();
    d.setDate(d.getDate() + days);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
};

test('the partner page, the credit limit and the payment terms', async ({ page, request }) => {
    test.setTimeout(120_000);
    const partner = (await (await request.get('partners/search?q=a&role=customer')).json()).results[0];

    await page.goto(`partners/${partner.id}`);
    await expect(page.locator('#partner-kpis .cx-card')).toHaveCount(6);
    await page.locator('[data-timeline-filter="money"]').click();
    const visible = page.locator('#partner-timeline [data-timeline-group]:visible');
    for (const item of await visible.all()) {
        await expect(item).toHaveAttribute('data-timeline-group', 'money');
    }
    await page.locator('[data-timeline-filter="all"]').click();

    await page.goto(`partners/${partner.id}/edit`);
    await page.locator('#credit_limit').fill('1');
    await page.locator('#payment_terms_days').fill('30');
    await page.locator('#credit_limit').evaluate((el) => el.form.requestSubmit());
    await expect(page.locator('.alert-success')).toBeVisible();

    try {
        await page.goto('invoices/create');
        // Pontosan ez a partner: a neve egy másikéban is benne lehet.
        await page.locator('#partner_id').locator('xpath=following-sibling::span[contains(@class, "select2")]').first().click();
        await page.locator('.select2-container--open .select2-search__field').fill(partner.text.split(' — ')[0]);
        await page.locator('.select2-container--open').getByRole('option', { name: partner.text, exact: true }).click();
        const credit = page.locator('.cx-partner-credit');
        await expect(credit).toBeVisible();
        await expect(credit).toContainText('30');
        await expect(page.locator('[name="due_date"]')).toHaveValue(inDays(30));
    } finally {
        await page.goto(`partners/${partner.id}/edit`);
        await page.locator('#credit_limit').fill('');
        await page.locator('#payment_terms_days').fill('');
        await page.locator('#credit_limit').evaluate((el) => el.form.requestSubmit());
        await expect(page.locator('.alert-success')).toBeVisible();
    }
});
