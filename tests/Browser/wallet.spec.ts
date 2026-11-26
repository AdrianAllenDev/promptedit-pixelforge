import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';

test.use({ baseURL: 'http://127.0.0.1:8186' });
test('add test credit by keyboard and settle an image from the wallet', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/register');
    const password = randomUUID();
    await page.getByLabel('Name', { exact: true }).fill('Wallet reviewer');
    await page.getByLabel('Email', { exact: true }).fill(randomUUID() + '@example.test');
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page.getByText(/upper estimate at current rates/)).toBeVisible();
    await page.getByLabel('Prompt', { exact: true }).fill('A lighthouse above the fog');
    await page.getByRole('button', { name: 'Generate', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Insufficient available credit');
    await page.getByRole('link', { name: 'Buy test credits' }).click();
    await page.getByRole('button', { name: 'Buy test credits' }).press('Enter');
    await expect(page.getByRole('status')).toContainText('$1.00');
    await page.getByRole('link', { name: 'Workspace' }).click();
    await expect(page.getByLabel('Prompt', { exact: true })).toHaveValue('A lighthouse above the fog');
    const generate = page.getByRole('button', { name: 'Generate', exact: true });
    await expect(generate).toBeEnabled();
    await generate.press('Enter');
    await expect(page.getByText('Image link expired.')).toBeVisible();
    await page.getByRole('link', { name: 'Wallet', exact: true }).click();
    await expect(page.getByText('$0.941815625', { exact: true })).toBeVisible();
    for (const width of [360, 390, 768, 1440]) {
        await page.setViewportSize({ width, height: 844 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        await page.screenshot({ path: 'test-results/wallet-' + width + '.png', fullPage: true });
    }
});
