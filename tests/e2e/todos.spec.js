// @ts-check
/*
 * To-dos with a type, a time and a repeat: one is added for today, shows on
 * the dashboard and in the week, is edited, and when ticked off the next one
 * of the series is made a week later. A deal takes a to-do from its own page.
 * Everything made here is deleted again.
 */
const { test, expect, post, pick } = require('./helpers');

const day = (offset) => {
    const d = new Date();
    d.setDate(d.getDate() + offset);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
};

test('a repeating to-do is added, seen, edited and done', async ({ page, request }) => {
    test.setTimeout(120_000);
    const title = 'Böngészős teszt hívás ' + Date.now();
    const made = [];

    await page.goto('todos?due_date=' + day(0));
    await expect(page.locator('#due_date')).toHaveValue(day(0));
    await page.locator('#title').fill(title);
    await page.locator('#type').selectOption('call');
    await page.locator('#due_time').fill('09:30');
    await page.locator('#recurrence').selectOption('weekly');
    await pick(page, page.locator('#partner_id'), 'a');
    await page.locator('#todo-form button[type="submit"]').click();
    await expect(page.locator('.alert-success')).toBeVisible();

    const row = page.locator('#todo-list tr', { hasText: title });
    await expect(row).toContainText('09:30');
    await expect(row.locator('.bi-telephone')).toHaveCount(1);
    await expect(row.locator('.bi-arrow-repeat')).toHaveCount(1);
    made.push(Number(await row.getAttribute('data-todo')));

    try {
        await page.goto('dashboard');
        await expect(page.locator('#my-todos')).toContainText(title);

        await page.goto('todos/week');
        const today = page.locator(`[data-day="${day(0)}"]`);
        await expect(today).toHaveClass(/is-today/);
        await expect(today).toContainText(title);

        await today.locator('[data-todo] a', { hasText: title }).click();
        await expect(page).toHaveURL(/\/todos\/\d+\/edit/);
        await page.locator('#note').fill('Megkérdezni a szállítást.');
        await page.locator('#todo-form button[type="submit"]').click();
        await expect(page).toHaveURL(/\/todos\/week/);
        await expect(page.locator('.alert-success')).toBeVisible();

        await page.locator(`[data-day="${day(0)}"] [data-todo="${made[0]}"] button`).click();
        await expect(page.locator('.alert-success')).toContainText(day(7));
        await expect(page.locator(`[data-todo="${made[0]}"]`)).toHaveClass(/is-done/);

        await page.goto('todos?status=open&q=' + encodeURIComponent(title));
        const next = page.locator('#todo-list tr', { hasText: title });
        await expect(next).toHaveCount(1);
        await expect(next).toContainText(day(7) + ' 09:30');
        await expect(next).toContainText('Megkérdezni a szállítást.');
        made.push(Number(await next.getAttribute('data-todo')));
    } finally {
        for (const id of made) {
            await post(request, `todos/${id}/delete`);
        }
    }
});

test('a deal takes a to-do from its own page', async ({ page, request }) => {
    test.setTimeout(120_000);
    await page.goto('deals/create');
    await page.locator('#title').fill('Böngészős teszt üzlet teendővel');
    await pick(page, page.locator('#partner_id'), 'a');
    await Promise.all([page.waitForURL(/\/deals\/\d+$/), page.locator('#deal-form button[type="submit"]').click()]);
    const dealId = Number(page.url().match(/\/deals\/(\d+)$/)?.[1]);

    try {
        await page.locator('#deal-todo-title').fill('Visszahívni az ajánlat miatt');
        await page.locator('#deal-todo-type').selectOption('call');
        await page.locator('#deal-todo-date').fill(day(1));
        await page.locator('#deal-todo-form button[type="submit"]').click();
        await expect(page).toHaveURL(new RegExp(`/deals/${dealId}$`));
        const row = page.locator('#deal-todos tr', { hasText: 'Visszahívni az ajánlat miatt' });
        await expect(row).toContainText(day(1));

        await row.locator('form[action$="/toggle"] button').click();
        await expect(page).toHaveURL(new RegExp(`/deals/${dealId}$`));
        await expect(page.locator('#deal-todos tr .text-decoration-line-through')).toHaveCount(1);
    } finally {
        // Az üzlet törlése a teendőt nem viszi magával: azt külön.
        const html = await (await request.get(`deals/${dealId}`)).text();
        for (const match of html.matchAll(/data-todo="(\d+)"/g)) {
            await post(request, `todos/${match[1]}/delete`);
        }
        await post(request, `deals/${dealId}/delete`);
    }
});
