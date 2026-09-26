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

test('saving one’s own profile keeps one’s role', async ({ page }) => {
    await page.goto('profile');
    const name = page.locator('#full_name');
    const original = await name.inputValue();

    await name.fill(original + ' (e2e)');
    await page.locator('main form[action$="/profile"] button[type="submit"]').click();
    await expect(page.locator('.alert-success')).toBeVisible();

    // Still a super admin: the user list is still there.
    const users = await page.goto('users');
    expect(users?.status()).toBe(200);
    await expect(page.locator('main table')).toBeVisible();

    await page.goto('profile');
    await page.locator('#full_name').fill(original);
    await page.locator('main form[action$="/profile"] button[type="submit"]').click();
    await expect(page.locator('#full_name')).toHaveValue(original);
});

test('the theme switch flips between light and dark, and the profile can follow the system', async ({ page }) => {
    const html = page.locator('html');
    const button = page.locator('.cx-theme-btn');

    await page.goto('theme/light');
    await page.goto('dashboard');
    await expect(html).toHaveAttribute('data-bs-theme', 'light');

    // As tall as the language button beside it.
    const [themeBox, languageBox] = [await button.boundingBox(), await page.locator('.cx-lang-btn').boundingBox()];
    expect(Math.round(themeBox?.height ?? 0)).toBe(Math.round(languageBox?.height ?? -1));

    // Two states only: light to dark, dark to light — and it stays, being the user's.
    await Promise.all([page.waitForURL(/dashboard/), button.click()]);
    await expect(html).toHaveAttribute('data-bs-theme', 'dark');
    await page.goto('products');
    await expect(html).toHaveAttribute('data-bs-theme', 'dark');
    await expect(html).toHaveAttribute('data-theme-mode', 'dark');

    await Promise.all([page.waitForURL(/products/), button.click()]);
    await expect(html).toHaveAttribute('data-theme-mode', 'light');

    // The system's is chosen on the profile.
    await page.goto('profile');
    await page.locator('#theme').selectOption('system');
    await page.locator('main form[action$="/profile"] button[type="submit"]').click();
    await expect(html).toHaveAttribute('data-theme-mode', 'system');
    await expect(page.locator('#theme')).toHaveValue('system');
});
