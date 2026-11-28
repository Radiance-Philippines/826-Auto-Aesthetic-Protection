import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { settled } from './console-sheet';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/**
 * The React admin's Reviews. The two reviews are made for this run on units
 * that left weeks ago, and removed again (bookings and customer included).
 */
const names = (project: string) => [`ConReview ${project} One`, `ConReview ${project} Two`];

/** In the throwaway database tests/e2e/serve.sh made; nothing is published to Flow from here */
function tinker(code: string): void {
    execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        env: { ...process.env, APP_ENV: 'local', DB_CONNECTION: 'sqlite', DB_DATABASE: resolve('storage/e2e/e2e.sqlite'), FLOW_APP_TOKEN: '', FLOW_APP_TOKEN_FILE: '' },
        stdio: 'pipe',
    });
}

const clean = (project: string) =>
    tinker(`foreach (\\App\\Models\\Guest::query()->where('name', 'like', 'ConReview ${project} %')->get() as $guest) { \\App\\Models\\Review::query()->where('guest_id', $guest->id)->get()->each->delete(); \\App\\Models\\Booking::query()->where('guest_id', $guest->id)->get()->each->delete(); $guest->delete(); }`);

test.beforeEach(({}, info) => {
    clean(info.project.name);

    for (const name of names(info.project.name)) {
        tinker(
            `$branch = \\App\\Models\\Branch::query()->where('slug', 'cainta')->sole(); $guest = \\App\\Models\\Guest::factory()->walkIn()->create(['name' => '${name}']); $booking = \\App\\Models\\Booking::factory()->status('exited')->on(today()->subDays(40)->toDateString())->create(['branch_id' => $branch->id, 'guest_id' => $guest->id, 'name' => '${name}']); \\App\\Models\\Review::factory()->create(['booking_id' => $booking->id, 'guest_id' => $guest->id, 'display_name' => '${name}', 'rating' => 4, 'body' => 'Written by a test']);`,
        );
    }
});

test.afterAll(({}, info) => clean(info.project.name));

test('sales approves, features, corrects and rejects; only the owner deletes', async ({ page, browser }, info) => {
    test.setTimeout(90_000);
    const [one, two] = names(info.project.name);
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.sales);
    await page.goto('/console/sales/reviews');

    const table = page.getByRole('table', { name: 'Reviews' });
    const row = (name: string) => table.locator('tbody tr').filter({ hasText: name });
    await expect(row(one)).toContainText('Written by a test');
    await expect(row(one)).toContainText('4 out of 5');
    // Only a super admin deletes
    await expect(page.getByRole('checkbox', { name: `Select ${one}` })).toHaveCount(0);
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);

    await row(one).getByRole('button', { name: `Approve ${one}` }).click();
    const question = page.getByRole('alertdialog', { name: 'Show this review on the site?' });
    await expect(question).toContainText('It appears on the Home and Our work pages right away.');
    await question.getByRole('button', { name: 'Confirm' }).click();
    await expect(row(one)).toHaveCount(0);

    await row(two).getByRole('button', { name: `Reject ${two}` }).click();
    const reject = page.getByRole('dialog', { name: 'Don’t show this review?' });
    await settled(reject);
    await reject.getByRole('textbox', { name: 'Reason' }).fill('A test');
    await expectAccessible(page);
    await reject.getByRole('button', { name: 'Reject' }).click();
    await expect(row(two)).toHaveCount(0);

    await page.getByRole('tab', { name: 'Approved' }).click();
    await expect(page).toHaveURL(/tab=approved/);
    await row(one).getByRole('button', { name: `More actions for ${one}` }).click();
    await page.getByRole('menuitem', { name: 'Feature', exact: true }).click();
    await expect(row(one)).toContainText('Featured');

    await row(one).getByRole('button', { name: `More actions for ${one}` }).click();
    await page.getByRole('menuitem', { name: 'View' }).click();
    await expect(page.getByRole('heading', { level: 1, name: `View ${one}` })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Details' })).toContainText('Approved');
    await expect(page.getByRole('region', { name: 'Review' })).toContainText('No photo');
    await expectAccessible(page);

    await page.getByRole('link', { name: 'Edit' }).click();
    await page.getByRole('textbox', { name: 'What they wrote' }).fill('Written by a test, corrected');
    await expect(page.getByRole('button', { name: 'Delete' })).toHaveCount(0);
    await expectAccessible(page);
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Reviews' })).toBeVisible();
    await page.getByRole('tab', { name: 'All' }).click();
    await expect(row(one)).toContainText('corrected');
    await expect(row(two)).toContainText('Not shown');
    await page.getByRole('searchbox', { name: 'Search reviews' }).fill(two);
    await expect(table.locator('tbody tr')).toHaveCount(1);
    expect(problems()).toEqual([]);

    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.setViewportSize({ width: 360, height: 780 });
    await page.waitForFunction(() => document.getAnimations().every((animation) => animation.playState !== 'running'));
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);

    const context = await browser.newContext();
    const owner = await context.newPage();
    await adminLogin(owner, TEAM.owner);
    await owner.goto('/console/owner/reviews?tab=all');
    for (const name of [one, two]) {
        await owner.getByRole('checkbox', { name: `Select ${name}` }).press('Space');
    }
    await owner.getByRole('button', { name: 'Delete selected' }).click();
    await owner.getByRole('alertdialog', { name: 'Delete selected reviews' }).getByRole('button', { name: 'Delete' }).click();
    await expect(owner.getByText(one)).toHaveCount(0);
    await expect(owner.getByText(two)).toHaveCount(0);
    await context.close();
});
