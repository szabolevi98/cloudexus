// @ts-check
/*
 * Partner tags: typed in on the partner form, filtered on in the list, given
 * to several partners at once and taken off again. The dormant customers
 * report takes a call to-do in one click (when the data has anyone dormant —
 * the demo seed has three). Everything is put back as it was.
 */
const { test, expect, post } = require('./helpers');

test('a tag is given, filtered on, and given to many at once', async ({ page, request }) => {
    test.setTimeout(120_000);
    const tag = 'E2E címke ' + Date.now();
    const found = (await (await request.get('partners/search?q=a&role=customer')).json()).results;
    const [first, second] = found;

    await page.goto(`partners/${first.id}/edit`);
    await page.locator('#tags').locator('xpath=following-sibling::span[contains(@class, "select2")]').first().click();
    await page.keyboard.type(tag);
    await page.keyboard.press('Enter');
    await page.keyboard.press('Escape');
    expect(await page.locator('#tags').evaluate((el) => Array.from(el.selectedOptions).map((o) => o.value))).toContain(tag);
    await page.locator('#name').evaluate((el) => el.form.requestSubmit());
    await expect(page.locator('.alert-success')).toBeVisible();

    try {
        await page.goto(`partners/${first.id}`);
        await expect(page.locator('#partner-tags')).toContainText(tag);
        await page.locator('#partner-tags a', { hasText: tag }).click();
        await expect(page).toHaveURL(/partners\?tag_id=\d+/);
        await expect(page.locator('tbody tr')).toHaveCount(1);
        await expect(page.locator('tbody')).toContainText(first.text.split(' — ')[0]);

        // Tömegesen a másodiknak is.
        await page.goto('partners?q=' + encodeURIComponent(second.text.split(' — ')[0]));
        await page.locator(`input[name="ids[]"][value="${second.id}"]`).check();
        await page.locator('#bulk-form [name="action"]').selectOption('tag_add');
        await page.locator('#bulk-form [name="tag"]').fill(tag);
        await page.locator('#bulk-form button[type="submit"]').click();
        await expect(page.locator('.alert-success')).toBeVisible();

        const tagId = await page.locator('#filter-tag option', { hasText: tag }).getAttribute('value');
        await page.goto(`partners?tag_id=${tagId}`);
        await expect(page.locator('tbody tr')).toHaveCount(2);
    } finally {
        await post(request, 'partners/bulk', { 'ids[]': first.id, action: 'tag_remove', tag });
        await post(request, 'partners/bulk', { 'ids[]': second.id, action: 'tag_remove', tag });
    }
    await page.goto('partners');
    await expect(page.locator('#filter-tag option', { hasText: tag })).toHaveCount(0);
});

test('a dormant customer gets a call to-do in one click', async ({ page, request }) => {
    test.setTimeout(120_000);
    await page.goto('reports/dormant?days=90');
    await expect(page.locator('h2')).toContainText(/Alvó ügyfelek|Dormant customers/);
    const row = page.locator('#dormant-list tr[data-partner]:has(form)').first();
    test.skip((await row.count()) === 0, 'nobody is dormant in this data');

    const partnerId = await row.getAttribute('data-partner');
    await row.locator('form button').click();
    await expect(page).toHaveURL(/reports\/dormant\?days=90/);
    const link = page.locator(`#dormant-list tr[data-partner="${partnerId}"] a[href*="/todos/"]`);
    await expect(link).toBeVisible();
    const todoId = (await link.getAttribute('href'))?.match(/todos\/(\d+)/)?.[1];

    await page.goto('dashboard');
    await expect(page.locator(`#my-todos [data-todo="${todoId}"]`)).toBeVisible();
    await post(request, `todos/${todoId}/delete`);
});
