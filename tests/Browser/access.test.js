import { expect, test } from '@playwright/test';

test('signed-out visitors are directed to sign-in and cannot read voucher data', async ({ baseURL, page, request }) => {
    const guestResponse = await request.get('/vouchers/amount-status', {
        headers: { Accept: 'application/json' },
        maxRedirects: 0,
    });
    expect(guestResponse.status()).toBe(401);

    await page.goto('/vouchers');
    await expect(page).toHaveURL(new URL('/login', baseURL).href);
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
    await expect(page.getByLabel('Username')).toBeVisible();
    await expect(page.getByLabel('Password')).toBeVisible();
});

test('sign-in and voucher search work with disposable records', async ({ page }) => {
    test.skip(Boolean(process.env.BASE_URL), 'This test uses the local fixture credentials.');

    await page.goto('/login');
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
