import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

import { expect, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Warranty checker, on a finished job of this spec's own */
const BASE = '/console/operations/warranty';

/** A job that left the branch today with a 6-month warranty, in the throwaway database tests/e2e/serve.sh made */
function finishedJob(plate: string): void {
    const code = `
        \\App\\Models\\Booking::query()->where('plate_number', '${plate}')->get()->each->delete();
        $job = \\App\\Models\\Booking::factory()->on(today()->subDay()->toDateString())->create(['branch_id' => \\App\\Models\\Branch::query()->where('slug', 'cainta')->value('id'), 'name' => 'E2E Warranty ${plate}', 'plate_number' => '${plate}', 'status' => 'exited', 'exited_at' => now()]);
        $job->items()->delete();
        $job->items()->create(['service_id' => \\App\\Models\\Service::query()->value('id'), 'service_name' => 'Ceramic coating', 'duration_days' => 1, 'warranty_months' => 6]);
    `;
    execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        env: { ...process.env, APP_ENV: 'local', DB_CONNECTION: 'sqlite', DB_DATABASE: resolve('storage/e2e/e2e.sqlite'), FLOW_APP_TOKEN: '', FLOW_APP_TOKEN_FILE: '' },
        stdio: 'pipe',
    });
}

test('search a finished job, log a backjob and mark it done', async ({ page }, info) => {
    const plate = `WTY${info.project.name.toUpperCase()}${info.retry}`;
    finishedJob(plate);
    const problems = watchProblems(page);
    await adminLogin(page, TEAM.operations);
    await page.goto(BASE);
    await expect(page.getByText('Search for a finished job to see its warranty.')).toBeVisible();

    const search = page.getByRole('searchbox', { name: 'Search for a finished job' });
    await search.fill('no such plate');
    await expect(page.getByText('No finished job found.')).toBeVisible();
    await search.fill(plate.toLowerCase());
    await expect(page).toHaveURL(new RegExp(`q=${plate.toLowerCase()}`));
    const job = page.getByRole('region', { name: /^Booking 826-/ });
    await expect(job).toHaveCount(1);
    await expect(job).toContainText(`E2E Warranty ${plate}`);
    await expect(job).toContainText('Active');
    await expect(job).toContainText('6 months');
    await expect(job).toContainText('None logged.');
    await expectNoHorizontalOverflow(page);

    await job.getByRole('button', { name: 'Log a backjob' }).click();
    const dialog = page.getByRole('dialog', { name: 'Log a backjob' });
    await expect(dialog.getByRole('button', { name: 'Log backjob' })).toBeInViewport({ ratio: 1 });
    await dialog.getByRole('button', { name: 'Log backjob' }).click();
    await expect(dialog.getByText('Pick the type.')).toBeVisible();
    await dialog.getByRole('combobox', { name: 'Type' }).click();
    await page.getByRole('option', { name: 'Other' }).click();
    await dialog.getByRole('textbox', { name: 'Note' }).fill('Water spots on the hood');
    await dialog.getByRole('button', { name: 'Log backjob' }).click();
    await expect(dialog).toBeHidden();
    await expect(job).toContainText('Water spots on the hood');

    await job.getByRole('button', { name: /^Mark done/ }).click();
    const question = page.getByRole('alertdialog', { name: 'Mark done' });
    await question.getByRole('button', { name: 'Confirm' }).click();
    await expect(job.getByRole('button', { name: /^Mark done/ })).toHaveCount(0);
    await expect(job.getByRole('listitem')).toContainText('Done');

    // Allowed: the 422 asked for above, and a look-up dropped because the search moved on
    expect(problems().filter((problem) => !problem.includes('422') && !problem.includes('ERR_ABORTED'))).toEqual([]);

    await expectAccessible(page);
    await page.screenshot({ path: `test-results/console-warranty-${info.project.name}.png`, fullPage: true });
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expectAccessible(page);
    await page.screenshot({ path: `test-results/console-warranty-${info.project.name}-dark.png`, fullPage: true });
    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
});

test('a customer who is not theirs says so', async ({ page }) => {
    await adminLogin(page, TEAM.operations);
    await page.goto(`${BASE}?customer=999999`);

    await expect(page.getByRole('main').getByRole('alert')).toContainText('That isn’t there any more.');
});
