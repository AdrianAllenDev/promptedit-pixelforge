import { expect, test } from '@playwright/test';
import { randomUUID } from 'node:crypto';

test('a creator can quote, generate and read the result at each viewport', async ({page})=>{
    const errors:string[]=[];
    page.on('pageerror', error=>errors.push(error.message));
    await page.setViewportSize({width:390,height:844});
    await page.goto('/register');
    await page.getByLabel('Name',{exact:true}).fill('Browser reviewer');
    await page.getByLabel('Email',{exact:true}).fill(randomUUID()+'@example.test');
    const password=randomUUID();
    await page.getByLabel('Password',{exact:true}).fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button',{name:'Create account'}).click();
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
