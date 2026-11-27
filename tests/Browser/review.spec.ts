import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { execFileSync } from 'node:child_process';

const baseURL = 'http://127.0.0.1:8186';
test.use({ baseURL, viewport: { width: 390, height: 844 } });

test('an admin can review and release credit while the customer watches history', async ({ page, browser }) => {
    const customer = randomUUID() + '@example.test';
    const password = randomUUID();
    await page.goto('/register');
    await page.getByLabel('Name', { exact: true }).fill('Credit reviewer');
    await page.getByLabel('Email', { exact: true }).fill(customer);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Create account' }).click();
    await page.getByRole('link', { name: 'Wallet', exact: true }).click();
    await page.getByRole('button', { name: 'Buy test credits' }).click();
    await expect(page.getByRole('status')).toContainText('$1.00');
    await page.getByRole('link', { name: 'Workspace' }).click();
    await page.getByLabel('Prompt', { exact: true }).fill('[review] An unresolved image');
    await page.getByRole('button', { name: 'Generate', exact: true }).click();
    await expect(page.getByText('Provider integration needs operator review.')).toBeVisible();
    expect((await page.request.get('/admin/generations/review')).status()).toBe(403);

    const credentials = { email: randomUUID() + '@example.test', password: randomUUID() };
    execFileSync('php', ['artisan', 'admin:create', '--credentials-file=-'], {
        input: JSON.stringify(credentials),
        env: { ...process.env, APP_ENV: 'testing', DB_DATABASE: 'pixelforge_test' },
    });
    const admin = await browser.newPage({ baseURL });
    try {
        await admin.goto('/admin/login');
        await admin.getByLabel('Email', { exact: true }).fill(credentials.email);
        await admin.getByLabel('Password', { exact: true }).fill(credentials.password);
        await admin.getByRole('button', { name: 'Sign in' }).click();
        await admin.getByRole('link', { name: 'Review unresolved generations' }).click();
        const item = admin.getByRole('article').filter({ hasText: customer });
        await item.getByRole('button', { name: 'Review hold' }).press('Enter');
        await item.getByLabel('Reason for release').fill('Checked the delivery log. Acceptance remains unknown.');
        for (const width of [360, 390, 768, 1440]) {
            await admin.setViewportSize({ width, height: 844 });
            expect(await admin.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
            await admin.screenshot({ path: 'test-results/review-' + width + '.png', fullPage: true });
        }
        await item.getByRole('button', { name: /^Release \$/ }).press('Enter');
        await expect(item).toContainText('Released by admin');
        await expect(item).toContainText('Checked the delivery log.');
        await expect(item.getByRole('button', { name: 'Review hold' })).toHaveCount(0);
        await expect(page.getByText(/Your hold has been released/)).toBeVisible({ timeout: 15000 });
        await page.getByRole('link', { name: 'Wallet', exact: true }).click();
        await expect(page.getByText('$1', { exact: true })).toBeVisible();
    } finally {
        await admin.close();
    }
});
