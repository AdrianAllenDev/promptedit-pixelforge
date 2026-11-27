import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { execFileSync } from 'node:child_process';

test('operator can run a date filter and read amounts at four widths', async ({ page }) => {
    const email = randomUUID() + '@example.test';
    const password = randomUUID();
    await page.goto('/register');
    await page.getByLabel('Name', { exact: true }).fill('Report reviewer');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page.getByRole('heading', { name: 'New generation' })).toBeVisible();
    await page.getByLabel('Prompt', { exact: true }).fill('Example for the daily report');
    await page.getByRole('button', { name: 'Generate', exact: true }).click();
    await expect(page.getByText('Image link expired.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Operator report' })).toHaveCount(0);
    expect((await page.request.get('/admin/report')).status()).toBe(403);
    expect((await page.request.get('/operator/report')).status()).toBe(403);

    const admin = { email: randomUUID() + '@example.test', password: randomUUID() };
    execFileSync('php', ['artisan', 'admin:create', '--credentials-file=-'], {
        input: JSON.stringify(admin),
        env: { ...process.env, APP_ENV: 'testing', DB_DATABASE: 'pixelforge_test' },
    });
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill(admin.email);
    await page.getByLabel('Password', { exact: true }).fill(admin.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/admin\/report$/);

    const day = new Date().toISOString().slice(0, 10);
    await page.getByLabel('From (UTC)').fill(day);
    await page.getByLabel('Through (UTC)').fill(day);
    await page.getByRole('button', { name: 'Run report' }).press('Enter');
    const breakdown = page.locator('details').filter({ hasText: 'User breakdown for ' + day });
    await breakdown.locator('summary').press('Enter');
    await expect(breakdown.getByText(/Report reviewer/).first()).toBeVisible();
    await expect(breakdown.getByText('$0.058184375', { exact: true }).first()).toBeVisible();
    await expect(page.getByRole('alert')).toHaveCount(0);
    for (const width of [360, 390, 768, 1440]) {
        await page.setViewportSize({ width, height: 844 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        await page.screenshot({ path: 'test-results/report-' + width + '.png', fullPage: true });
    }
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page.getByRole('heading', { name: 'Admin sign in' })).toBeVisible();
    await page.goto('/admin/report');
    await expect(page).toHaveURL(/\/admin\/login$/);
});
