import { expect, test } from '@playwright/test';

test('voucher data requires sign-in and the library opens after sign-in', async ({ page, request }) => {
    const guestResponse = await request.get('/vouchers/amount-status', {
        headers: { Accept: 'application/json' },
    });
    expect(guestResponse.status()).toBe(401);

    await page.goto('/vouchers');
    await expect(page).toHaveURL(/\/login$/);
    await page.getByLabel('Username').fill('browser-employee');
    await page.getByLabel('Password').fill('BrowserEmployee123!');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page.getByRole('heading', { name: 'Voucher library' })).toBeVisible();
    await page.getByRole('textbox', { name: 'Search vouchers' }).fill('Parallel left');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.locator('a.record-link')).toHaveCount(1);
    await expect(page.getByRole('link', { name: '80000000', exact: true })).toBeVisible();

    const signedInResponse = await page.request.get('/vouchers/amount-status', {
        headers: { Accept: 'application/json' },
    });
    expect(signedInResponse.status()).toBe(200);
});
