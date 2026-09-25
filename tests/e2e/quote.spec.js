// @ts-check
/*
 * A quote from start to order: typed in with the pickers, downloaded as a
 * PDF, sent by email (read back from var/mail), accepted, and made an order
 * of — which says which quote it came from. The order and the quote are
 * removed again.
 */
const { test, expect, post, pick } = require('./helpers');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

test('a quote is made, sent, accepted and becomes an order', async ({ page, request }) => {
    test.setTimeout(120_000);

    await page.goto('quotes/create');
    await pick(page, page.locator('#partner_id'), 'a');
    const line = page.locator('.cx-line-item').first();
    await pick(page, line.locator('select.cx-line-item__product'), 'a');
    await expect(line.locator('.cx-line-item__price')).not.toHaveValue('');
    await line.locator('.cx-line-item__quantity').fill('2');
    await page.locator('#note').fill('Szállítás 3 munkanap — böngészős teszt.');
    await Promise.all([page.waitForURL(/\/quotes\/\d+$/), page.locator('#quote-form button[type="submit"]').click()]);
    const quoteId = Number(page.url().match(/\/quotes\/(\d+)$/)?.[1]);
    let orderId = 0;

    try {
        await expect(page.locator('main')).toContainText(/Draft|Piszkozat/);
        await expect(page.locator('main')).toContainText('Szállítás 3 munkanap');

        const pdf = await request.get(`quotes/${quoteId}/pdf`);
        expect(pdf.headers()['content-type']).toBe('application/pdf');
        expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-');

        const send = page.locator('[data-bs-target="#quote-email"]');
        if (await send.count()) {
            const since = Date.now() - 1000;
            await send.click();
            await page.locator('#email-to').fill('buyer@e2e-customer.test');
            await page.locator('#quote-email button[type="submit"]').click();
            await expect(page.locator('.alert-success')).toBeVisible();
            await expect(page.locator('main')).toContainText(/Sent|Elküldve/);
            execSync(process.env.CX_OUTBOX_CMD || 'php bin/outbox.php');
            const dir = process.env.CX_MAIL_DIR || 'var/mail';
            const mails = fs.readdirSync(dir).map((f) => path.join(dir, f)).filter((f) => fs.statSync(f).mtimeMs >= since).map((f) => fs.readFileSync(f, 'utf8'));
            expect(mails.some((m) => m.includes('buyer@e2e-customer.test') && /name=AJ-\d{4}-\d{4}\.pdf/.test(m))).toBeTruthy();
        }

        await page.locator('#quote-accept').click();
        await expect(page.locator('main')).toContainText(/Accepted|Elfogadva/);

        await Promise.all([page.waitForURL(/\/orders\/\d+$/), page.locator('#quote-to-order').click()]);
        orderId = Number(page.url().match(/\/orders\/(\d+)$/)?.[1]);
        await expect(page.locator(`a[href$="/quotes/${quoteId}"]`)).toBeVisible();
    } finally {
        if (orderId) {
            await post(request, `orders/${orderId}/cancel`);
            await post(request, `orders/${orderId}/delete`);
        }
        await post(request, `quotes/${quoteId}/delete`);
    }
});
