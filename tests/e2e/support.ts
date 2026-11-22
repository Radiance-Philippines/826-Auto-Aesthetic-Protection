import { expect, type Page, type TestInfo } from '@playwright/test';

/** The pages every visitor can open: path, what the h1 must say (a pattern) and the status the server answers */
export const KEY_PAGES = [
    { name: 'home', path: '/', status: 200 },
    { name: 'services', path: '/services', status: 200 },
    { name: 'service', path: '/services/exterior-detailing', status: 200 },
    { name: 'branches', path: '/branches', status: 200 },
    { name: 'branch', path: '/branches/cainta', status: 200 },
    { name: 'gallery', path: '/gallery', status: 200 },
    { name: 'book', path: '/book', status: 200 },
    { name: 'home-service', path: '/home-service', status: 200 },
    { name: 'bookings', path: '/bookings', status: 200 },
    { name: 'not-found', path: '/no-such-page', status: 404 },
] as const;

/**
 * Collects what the browser complains about while a page is used: console
 * errors, uncaught exceptions, and own-site requests that failed.
 */
export function watchProblems(page: Page): () => string[] {
    const problems: string[] = [];
    const origin = '127.0.0.1';

    page.on('console', (message) => {
        if (message.type() === 'error') {
            problems.push(`console: ${message.text()}`);
        }
    });
    page.on('pageerror', (error) => problems.push(`exception: ${error.message}`));
    page.on('requestfailed', (request) => {
        if (new URL(request.url()).hostname === origin) {
            problems.push(`request failed: ${request.url()} (${request.failure()?.errorText})`);
        }
    });

    return () => problems;
}

/** Open a page and wait until the React shell has drawn its content */
export async function open(page: Page, path: string) {
    const response = await page.goto(path);
    await page.locator('main#main h1, main#main h2').first().waitFor();
    await page.waitForLoadState('networkidle');

    return response;
}

/** The page is no wider than the screen */
export async function expectNoHorizontalOverflow(page: Page) {
    const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
    expect(scroll, `page is ${scroll}px wide in a ${client}px window`).toBeLessThanOrEqual(client);
}

/** Sign in as the local test customer (the site offers it on local copies only) and land on `to` */
export async function signIn(page: Page, to = '/book') {
    await page.goto(`/auth/local?return=${encodeURIComponent(to)}`);
    await page.locator('main#main h1, main#main h2').first().waitFor();
}

export const isPhone = (info: TestInfo) => info.project.name === 'phone';
