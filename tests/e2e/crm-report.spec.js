// @ts-check
/*
 * The CRM report: a deal won today shows in the period's figures and under
 * its owner, the forecast is drawn, and the period can be switched or typed
 * in. The deal is deleted again.
 */
const { test, expect, post, pick } = require('./helpers');

test('the CRM report counts a deal won today', async ({ page, request }) => {
    test.setTimeout(120_000);
    await page.goto('reports/crm');
    const wonBefore = await page.locator('#crm-kpis .text-success').innerText();

    await page.goto('deals/create?stage=negotiation');
    await page.locator('#title').fill('Böngészős teszt riport üzlet');
    await pick(page, page.locator('#partner_id'), 'a');
    await page.locator('#amount').fill('777000');
    await Promise.all([page.waitForURL(/\/deals\/\d+$/), page.locator('#deal-form button[type="submit"]').click()]);
    const dealId = Number(page.url().match(/\/deals\/(\d+)$/)?.[1]);

    try {
        await page.locator('[data-stage-button="won"]').click();
        await expect(page.locator('#deal-stage')).toHaveText(/Megnyert|Won/);

        await page.goto('reports/crm');
        await expect(page.locator('#crm-kpis .text-success')).not.toHaveText(wonBefore);
        await expect(page.locator('#crm-people')).toContainText('777');
        // A diagram megrajzolva: a Chart.js a vászonhoz kötötte magát.
        expect(await page.locator('#forecast-chart').evaluate((el) => !!(window.Chart && window.Chart.getChart(el)))).toBe(true);
        await expect(page.locator('#forecast-table tbody tr')).toHaveCount(9);

        await page.locator('#crm-period-form [name="period"]').first().selectOption('year');
        await expect(page).toHaveURL(/period=year/);
        await expect(page.locator('#crm-period')).toContainText(new Date().getFullYear() + '-01-01');

        await page.locator('#crm-period-form [name="from"]').fill('2020-01-01');
        await page.locator('#crm-period-form [name="to"]').fill('2020-01-31');
        await page.locator('#crm-period-form button[value="custom"]').click();
        await expect(page.locator('#crm-period')).toHaveText('2020-01-01 – 2020-01-31');
        await expect(page.locator('#crm-people')).not.toContainText('777');
    } finally {
        await post(request, `deals/${dealId}/delete`);
    }
});
