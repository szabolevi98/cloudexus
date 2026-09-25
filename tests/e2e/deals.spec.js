// @ts-check
/*
 * The sales pipeline: a deal is added, dragged along the board (the column
 * sums follow), dropped on lost — which asks for the reason, and puts the
 * card back when that is cancelled — and deleted from its page.
 */
const { test, expect, post, pick } = require('./helpers');

test('a deal moves along the pipeline board', async ({ page, request }) => {
    test.setTimeout(120_000);
    const title = 'Böngészős teszt üzlet ' + Date.now();

    await page.goto('deals/create?stage=qualified');
    await page.locator('#title').fill(title);
    await pick(page, page.locator('#partner_id'), 'a');
    await page.locator('#amount').fill('400000');
    await Promise.all([page.waitForURL(/\/deals\/\d+$/), page.locator('#deal-form button[type="submit"]').click()]);
    const dealId = Number(page.url().match(/\/deals\/(\d+)$/)?.[1]);

    try {
        await expect(page.locator('#deal-stage')).toHaveText(/Minősített|Qualified/);

        await page.goto('deals?q=' + encodeURIComponent(title));
        const card = page.locator(`.cx-deal[data-id="${dealId}"]`);
        const column = (stage) => page.locator(`[data-stage="${stage}"]`);
        await expect(column('qualified').locator(card)).toHaveCount(1);
        await expect(column('qualified').locator('[data-col="count"]')).toHaveText('1');

        await card.dragTo(column('negotiation').locator('[data-list]'));
        await expect(column('negotiation').locator('[data-col="count"]')).toHaveText('1');
        await expect(column('qualified').locator('[data-col="count"]')).toHaveText('0');
        await expect(card.locator('[data-chance]')).toHaveText('75%');
        await expect(page.locator('#deal-totals')).toContainText('300');

        // Elvesztettre húzva az okot kéri; mégsével a kártya visszaugrik.
        await card.dragTo(column('lost').locator('[data-list]'));
        await expect(page.locator('#deal-lost-modal')).toBeVisible();
        await page.locator('#deal-lost-modal [data-bs-dismiss="modal"]').first().click();
        await expect(column('negotiation').locator(card)).toHaveCount(1);

        await card.dragTo(column('lost').locator('[data-list]'));
        await page.locator('#deal-lost-reason').fill('Olcsóbbat talált');
        await page.locator('#deal-lost-form button[type="submit"]').click();
        await expect(column('lost').locator('[data-col="count"]')).toHaveText('1');
        await expect(card.locator('[data-chance]')).toBeHidden();

        await page.reload();
        await expect(column('lost').locator(card)).toContainText('Olcsóbbat talált');

        await page.goto(`deals/${dealId}`);
        await expect(page.locator('main')).toContainText('Olcsóbbat talált');
        await page.locator('[data-stage-button="proposal"]').click();
        await expect(page.locator('#deal-stage')).toHaveText(/Ajánlattétel|Proposal/);
    } finally {
        await post(request, `deals/${dealId}/delete`);
    }
    expect((await request.get(`deals/${dealId}`, { maxRedirects: 0 })).status()).toBe(302);
});
