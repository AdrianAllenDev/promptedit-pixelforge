import { expect, test, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';

async function registerCreator(page: Page) {
    await page.goto('/register');
    await page.getByLabel('Name',{exact:true}).fill('Browser reviewer');
    await page.getByLabel('Email',{exact:true}).fill(randomUUID()+'@example.test');
    const password=randomUUID();
    await page.getByLabel('Password',{exact:true}).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button',{name:'Create account'}).click();
}

test('a creator can quote, generate and read the result at each viewport', async ({page})=>{
    const errors:string[]=[];
    page.on('pageerror', error=>errors.push(error.message));
    await page.setViewportSize({width:390,height:844});
    await registerCreator(page);
    const prompt=page.getByLabel('Prompt',{exact:true});
    await prompt.fill('A lighthouse in the morning fog');
    const generate=page.getByRole('button',{name:'Generate',exact:true});
    await expect(generate).toBeEnabled();
    await expect(page.locator('.amount')).toContainText('$');
    await expect(page.getByText(/hold|wallet/i)).toHaveCount(0);
    await generate.focus(); await page.keyboard.press('Enter');
    await expect(page.getByText('Image link expired.')).toBeVisible();
    await expect(page.locator('dd').last()).toHaveText('$0.058184375');
    await prompt.fill('[fail] '+ '🌊'.repeat(3993));
    await generate.click();
    await expect(page.getByText('Generation failed. You were not charged.')).toBeVisible();
    for(const width of [360,390,768,1440]){
        await page.setViewportSize({width,height:844});
        await expect(page.getByRole('heading',{name:'Recent activity'})).toBeVisible();
        expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        await page.screenshot({path:`test-results/workspace-${width}.png`, fullPage:true});
    }
    expect(errors).toEqual([]);
});

test('double tap and a lost response retry one intent before a deliberate repeat', async ({ page }) => {
    await registerCreator(page);
    const prompt = page.getByLabel('Prompt', { exact: true });
    const action = page.getByRole('button', { name: 'Generate', exact: true });
    await prompt.fill('A lighthouse at dusk');
    await expect(action).toBeEnabled();
    const keys: string[] = [];
    const ids: string[] = [];
    await page.route('**/generations', async (route) => {
        keys.push(route.request().postDataJSON().submission_key);
        const response = await route.fetch();
        expect(response.status()).toBe(202);
        ids.push((await response.json()).id);
        if (keys.length === 1) {
            await route.abort('failed');
        } else {
            await route.fulfill({ response });
        }
    });
    await action.evaluate((button: HTMLButtonElement) => { button.click(); button.click(); });
    await expect(page.getByRole('button', { name: 'Retry submission' })).toBeEnabled();
    expect(keys).toHaveLength(1);
    await page.reload();
    await page.getByRole('button', { name: 'Retry submission' }).click();
    const again = page.getByRole('button', { name: 'Generate again', exact: true });
    await expect(again).toBeEnabled();
    expect(keys[1]).toBe(keys[0]);
    expect(ids[1]).toBe(ids[0]);
    await expect(page.locator('.quote-action .amount')).toContainText('$');
    await again.click();
    await expect(page.locator('.activity li')).toHaveCount(2);
    expect(keys).toHaveLength(3);
    expect(keys[2]).not.toBe(keys[1]);
    expect(ids[2]).not.toBe(ids[1]);
    const requote = page.waitForResponse(r => r.url().endsWith('/quotes') && r.ok());
    await prompt.fill('A different lighthouse');
    await requote;
    await expect(action).toBeEnabled();
});

test('Night Shift comparison at 390 by 844 includes processing and a loaded success', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await registerCreator(page);
    const prompt = page.getByLabel('Prompt', { exact: true });
    const generate = page.getByRole('button', { name: 'Generate', exact: true });
    await prompt.fill('A sunlit lighthouse on the coast');
    await generate.click();
    const image = page.locator('.activity img');
    await expect(image).toBeVisible();
    await prompt.fill('Fog rolling across the harbour');
    await generate.click();
    await expect(page.locator('.activity li').first()).toHaveAttribute('data-status', 'processing');
    await expect(page.locator('.activity li').nth(1)).toHaveAttribute('data-status', 'succeeded');
    await prompt.fill('A lighthouse in the morning fog');
    await expect(generate).toBeEnabled();
    await prompt.blur();
    await image.evaluate((el: HTMLImageElement) => el.decode());
    expect(await image.evaluate((el: HTMLImageElement) => el.naturalWidth)).toBeGreaterThan(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
    await page.evaluate(() => window.scrollTo(0, 0));
    await expect(image).toBeInViewport({ ratio: 0.9 });
    await page.screenshot({ path: 'test-results/night-shift-390x844.png' });
    await page.screenshot({ path: 'test-results/night-shift-390-full.png', fullPage: true });
});
