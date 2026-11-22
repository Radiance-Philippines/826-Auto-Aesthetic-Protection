import { expect, test } from '@playwright/test';

import { isPhone, open, watchProblems } from './support';

const LINKS = [
    { label: 'Services', path: '/services' },
    { label: 'Branches', path: '/branches' },
    { label: 'Home service', path: '/home-service' },
];

// Our work shows in the bar only once there are photos, videos or reviews; the seed has none
test('the main menu reaches every section', async ({ page }, info) => {
    const problems = watchProblems(page);
    await open(page, '/');

    for (const { label, path } of LINKS) {
        if (isPhone(info)) {
            await page.getByRole('button', { name: 'Open menu' }).click();
            await page.locator('#mobile-menu').getByRole('link', { name: label }).click();
        } else {
            await page.locator('nav[aria-label="Main"]').getByRole('link', { name: label, exact: true }).click();
        }

        await expect(page).toHaveURL(new RegExp(`${path}$`));
        await expect(page.locator('main#main h1').first()).toBeVisible();
    }

    expect(problems()).toEqual([]);
});

test('Book now opens the booking page and the logo returns home', async ({ page }) => {
    await open(page, '/services');
    await page.locator('nav[aria-label="Main"]').getByRole('link', { name: 'Book now' }).first().click();
    await expect(page).toHaveURL(/\/book/);

    await page.locator('nav[aria-label="Main"]').getByRole('link', { name: /home$/ }).click();
    await expect(page).toHaveURL(/\/$/);
});

test('the browser back button goes back one page', async ({ page }) => {
    await open(page, '/');
    await page.goto('/branches');
    await page.locator('main#main h1').first().waitFor();
    await page.goBack();
    await expect(page).toHaveURL(/\/$/);
});

test('a service and a branch open from their lists', async ({ page }) => {
    await open(page, '/services');
    await page.locator('main#main a[href^="/services/"]').first().click();
    await expect(page).toHaveURL(/\/services\/[a-z0-9-]+$/);
    await expect(page.locator('main#main h1').first()).toBeVisible();

    await open(page, '/branches');
    await page.locator('main#main a[href^="/branches/"]').first().click();
    await expect(page).toHaveURL(/\/branches\/[a-z0-9-]+$/);
    await expect(page.locator('main#main h1').first()).toBeVisible();
});

test('old addresses redirect to the current ones', async ({ page }) => {
    for (const [from, to] of [
        ['/booking', '/book'],
        ['/reserve', '/book'],
        ['/prices', '/services'],
        ['/locations', '/branches'],
    ]) {
        await page.goto(from);
        await expect(page).toHaveURL(new RegExp(`${to}`));
    }
});

test('an unknown address shows the not-found page with a way home', async ({ page }) => {
    const response = await open(page, '/services/no-such-service');
    expect(response?.status()).toBe(404);
    await expect(page.locator('main#main h1')).toBeVisible();
    await expect(page.locator('main#main').getByRole('link', { name: /home/i }).first()).toBeVisible();
});

test('the skip link moves focus to the content', async ({ page }) => {
    await open(page, '/');
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.locator('#main')).toBeInViewport();
});
