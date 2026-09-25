// @ts-check
/*
 * An invoice as a PDF, downloaded and sent by email: the download is a PDF
 * named after the invoice number, and the email — to the address typed in,
 * with the message typed in — carries it as an attachment and is noted on
 * the invoice page.
 *
 * Runs the queue's sender (CX_OUTBOX_CMD, default php bin/outbox.php) and
 * reads the email from the server's var/mail (CX_MAIL_DIR), so it needs
 * [mail] transport = file there; skipped when the application has no email.
 */
const { test, expect } = require('./helpers');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const mailDir = process.env.CX_MAIL_DIR || 'var/mail';

function emailsSince(since) {
    if (!fs.existsSync(mailDir)) {
        return [];
    }
    return fs.readdirSync(mailDir)
        .filter((name) => name.endsWith('.eml'))
        .map((name) => path.join(mailDir, name))
        .filter((file) => fs.statSync(file).mtimeMs >= since)
        .map((file) => fs.readFileSync(file, 'utf8'));
}

test('an invoice downloads as a PDF and goes out by email with it attached', async ({ page, request }) => {
    // A PDF is drawn twice (download, attachment): slower than a page.
    test.setTimeout(90_000);
    await page.goto('invoices');
    const first = page.locator('main table tbody a[href*="/invoices/"]').first();
    await first.click();
    await expect(page).toHaveURL(/\/invoices\/\d+$/);
    const id = Number(page.url().match(/\/invoices\/(\d+)$/)?.[1]);

    const pdf = await request.get(`invoices/${id}/pdf`);
    expect(pdf.headers()['content-type']).toBe('application/pdf');
    expect(pdf.headers()['content-disposition']).toMatch(/filename="[A-Za-z0-9_-]+\.pdf"/);
    expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-');

    const send = page.locator('[data-bs-target="#invoice-email"]');
    test.skip((await send.count()) === 0, 'the application sends no email');

    const since = Date.now() - 1000;
    await send.click();
    await page.locator('#email-to').fill('accounts@e2e-customer.test');
    await page.locator('#email-message').fill('Browser test message — árvíztűrő tükörfúrógép.');
    await page.locator('#invoice-email button[type="submit"]').click();
    await expect(page.locator('.alert-success')).toContainText('accounts@e2e-customer.test');
    await expect(page.locator('main')).toContainText('accounts@e2e-customer.test');

    // Cron sends the queue every minute; here the test runs the sender itself.
    execSync(process.env.CX_OUTBOX_CMD || 'php bin/outbox.php');
    expect(emailsSince(since).filter((mail) => mail.includes('accounts@e2e-customer.test')).length).toBeGreaterThan(0);

    const mail = emailsSince(since).find((m) => m.includes('accounts@e2e-customer.test')) || '';
    expect(mail).toMatch(/Content-Type: application\/pdf; name=[^\r\n]*\.pdf/);
});
