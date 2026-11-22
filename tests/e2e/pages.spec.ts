import { expect, test } from '@playwright/test';

import { expectNoHorizontalOverflow, KEY_PAGES, open, watchProblems } from './support';

// Every page opens, draws, stays inside the screen, says nothing in the console, and gets a screenshot for the report
for (const { name, path, status } of KEY_PAGES) {
    test(`page ${name} renders cleanly`, async ({ page }, info) => {
        const problems = watchProblems(page);
        const response = await open(page, path);

        expect(response?.status()).toBe(status);
        await expect(page).toHaveTitle(/826/);
        await expect(page.locator('main#main h1').first()).toBeVisible();
        await expect(page.locator('nav[aria-label="Main"]')).toBeVisible();
        await expect(page.locator('footer')).toBeVisible();
        await expectNoHorizontalOverflow(page);

        // Visual sanity: not a blank or half-drawn page. The picture goes to the report.
        const shot = await page.screenshot({ fullPage: true });
        await info.attach(`${name}-${info.project.name}.png`, { body: shot, contentType: 'image/png' });
        expect(shot.byteLength, 'screenshot is nearly empty').toBeGreaterThan(15_000);

        // The not-found page is a real 404, and the browser logs that itself
        expect(problems().filter((problem) => !(status === 404 && problem.includes('status of 404')))).toEqual([]);
    });
}

test('the site is served with search-engine files', async ({ request }) => {
    const robots = await request.get('/robots.txt');
    expect(robots.status()).toBe(200);
    expect(await robots.text()).toMatch(/sitemap/i);

    const sitemap = await request.get('/sitemap.xml');
    expect(sitemap.status()).toBe(200);
    expect(await sitemap.text()).toContain('/services');
});
