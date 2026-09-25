// @ts-check
/*
 * The parts of the frame every page shares: a column header's three states,
 * the sidebar keeping its place after a click in it, and the theme switch
 * that stays switched.
 */
const { test, expect } = require('./helpers');

test('a column header sorts ascending, then descending, then back to the list’s own order', async ({ page }) => {
    await page.goto('products');
    const header = page.locator('th a.cx-sort').first();
    const key = new URL(String(await header.getAttribute('href')), page.url()).searchParams.get('sort');

    await header.click();
    await expect(page).toHaveURL(new RegExp(`sort=${key}&dir=asc`));
    await expect(page.locator('th[aria-sort="ascending"]')).toHaveCount(1);

    await page.locator('th a.cx-sort.is-sorted').click();
    await expect(page).toHaveURL(new RegExp(`sort=${key}&dir=desc`));
    await expect(page.locator('th[aria-sort="descending"]')).toHaveCount(1);

    await page.locator('th a.cx-sort.is-sorted').click();
    await expect(page).not.toHaveURL(/sort=/);
    await expect(page.locator('th[aria-sort]')).toHaveCount(0);
});

test('the sidebar stays where it was after a click in it', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 500 });
    await page.goto('dashboard');
    const sidebar = page.locator('.cx-sidebar');
    const links = sidebar.locator('a.cx-nav__link');

    // A link far down, scrolled to, then clicked.
    const target = links.nth((await links.count()) - 2);
    await target.scrollIntoViewIfNeeded();
    const before = await sidebar.evaluate((el) => el.scrollTop);
    expect(before).toBeGreaterThan(0);

    await Promise.all([page.waitForLoadState('load'), target.click()]);
    await expect.poll(() => sidebar.evaluate((el) => el.scrollTop)).toBeGreaterThan(0);
    await expect(sidebar.locator('.cx-nav__link.is-active')).toBeInViewport();
});

test('the theme switch stays switched', async ({ page }) => {
    await page.goto('dashboard');
    const html = page.locator('html');

    await page.goto('theme/dark');
    await page.goto('dashboard');
    await expect(html).toHaveAttribute('data-bs-theme', 'dark');

    await page.goto('theme/light');
    await page.goto('dashboard');
    await expect(html).toHaveAttribute('data-bs-theme', 'light');

    await page.goto('theme/system');
});
