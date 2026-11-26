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
    execFileSync('php', ['artisan', 'tinker', '--execute=DB::table("users")->where("email", "' + email + '")->update(["is_operator" => true]);'], {
        env: { ...process.env, APP_ENV: 'testing', DB_DATABASE: 'pixelforge_test' },
    });
    await page.reload();
    await page.getByRole('link', { name: 'Operator report' }).click();
    await page.getByLabel('From (UTC)').fill('2026-11-26');
    await page.getByLabel('Through (UTC)').fill('2026-11-26');
    await page.getByRole('button', { name: 'Run report' }).press('Enter');
    await expect(page.getByText('2026-11-26', { exact: true })).toBeVisible();
    await expect(page.getByRole('alert')).toHaveCount(0);
    for (const width of [360, 390, 768, 1440]) {
        await page.setViewportSize({ width, height: 844 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        await page.screenshot({ path: 'test-results/report-' + width + '.png', fullPage: true });
    }
});
