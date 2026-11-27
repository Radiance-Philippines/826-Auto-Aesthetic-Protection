import { expect, type Page, test } from '@playwright/test';

import { adminLogin, TEAM } from './admin-support';
import { expectAccessible } from './console-support';
import { expectNoHorizontalOverflow, watchProblems } from './support';

/** The React admin's Shop → Vehicle sizes, and with it the data table. Every size made here is deleted again. */
const rows = (page: Page) => page.getByRole('table', { name: 'Vehicle sizes' }).locator('tbody tr');
const names = async (page: Page) => (await rows(page).locator('th > span:first-child').allTextContents()).map((name) => name.trim());

async function openSizes(page: Page) {
    await adminLogin(page, TEAM.owner);
    await page.goto('/console/owner/vehicle-sizes');
    await expect(rows(page).first()).toBeVisible();
}

test('add, correct, reorder and delete a size', async ({ page }, info) => {
    const name = `Console ${info.project.name} ${info.retry}`;
    const problems = watchProblems(page);
    await openSizes(page);
    const seeded = await names(page);

    await page.getByRole('button', { name: 'Add a size' }).click();
    const dialog = page.getByRole('dialog', { name: 'Add a size' });
    await expect(dialog.getByText('What the team calls this class.')).toBeVisible();
    await dialog.getByRole('textbox', { name: 'Name' }).fill('x'.repeat(5));
    await dialog.getByRole('textbox', { name: 'Name' }).fill('');
    await dialog.getByRole('button', { name: 'Add size' }).click();
    await expect(dialog.getByText('Give the size a name.')).toBeVisible();
    await expect(dialog.getByRole('textbox', { name: 'Name' })).toHaveAttribute('aria-invalid', 'true');

    await dialog.getByRole('textbox', { name: 'Name' }).fill(name);
    await dialog.getByRole('textbox', { name: 'Examples' }).fill('Hiace, Urvan');
    await dialog.getByRole('button', { name: 'Add size' }).click();
    await expect(dialog).toBeHidden();
    const row = rows(page).filter({ hasText: name });
    await expect(row).toContainText('Hiace, Urvan');

    // The server's 422 above is the only failed request allowed
    expect(problems().filter((problem) => !problem.includes('422'))).toEqual([]);

    await row.getByRole('button', { name: `Edit ${name}` }).click();
    const edit = page.getByRole('dialog', { name: `Edit ${name}` });
    await edit.getByRole('textbox', { name: 'Examples' }).fill('Hiace');
    await edit.getByRole('switch', { name: 'Offered when reviewing a unit' }).click();
    await edit.getByRole('button', { name: 'Save changes' }).click();
    await expect(edit).toBeHidden();
    await expect(row).not.toContainText('Urvan');
    await expect(row).toContainText('No');

    // Where it landed (a new size has no order yet, as in Filament), then one place at a time
    const start = await names(page);
    const at = start.indexOf(name);
    expect(at).toBeGreaterThanOrEqual(0);
    /** The list with the new size moved to this position */
    const placed = (position: number) => {
        const others = start.filter((each) => each !== name);

        return [...others.slice(0, position), name, ...others.slice(position)];
    };

    await page.getByRole('button', { name: `More actions for ${start[0]}` }).click();
    await expect(page.getByRole('menuitem', { name: 'Move up' })).toHaveAttribute('aria-disabled', 'true');
    await page.keyboard.press('Escape');

    // Without dragging: the actions menu
    await row.getByRole('button', { name: `More actions for ${name}` }).click();
    const saved = page.waitForResponse('**/vehicle-sizes/reorder');
    await page.getByRole('menuitem', { name: 'Move down' }).click();
    expect((await saved).status()).toBe(204);
    await expect.poll(() => names(page)).toEqual(placed(at + 1));

    // The drag handle from the keyboard: pick up, move, drop
    const handle = page.getByRole('button', { name: `Move ${name}` });
    await handle.focus();
    await page.keyboard.press('Space');
    await page.keyboard.press('ArrowDown');
    const dropped = page.waitForResponse('**/vehicle-sizes/reorder');
    await page.keyboard.press('Space');
    expect((await dropped).status()).toBe(204);
    await expect.poll(() => names(page)).toEqual(placed(at + 2));
    await page.reload();
    await expect.poll(() => names(page)).toEqual(placed(at + 2));

    // And with the pointer: back above the row over it
    const grip = await page.getByRole('button', { name: `Move ${name}` }).boundingBox();
    const above = await page.getByRole('button', { name: `Move ${placed(at + 2)[at + 1]}` }).boundingBox();
    const dragged = page.waitForResponse('**/vehicle-sizes/reorder');
    await page.mouse.move(grip!.x + grip!.width / 2, grip!.y + grip!.height / 2);
    await page.mouse.down();
    await page.mouse.move(above!.x + above!.width / 2, above!.y + 4, { steps: 12 });
    await page.mouse.up();
    expect((await dragged).status()).toBe(204);
    await expect.poll(() => names(page)).toEqual(placed(at + 1));

    await row.getByRole('button', { name: `More actions for ${name}` }).click();
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    const question = page.getByRole('alertdialog', { name: `Delete ${name}?` });
    await expect(question).toContainText('Its prices go too.');
    await question.getByRole('button', { name: 'Cancel' }).click();
    await expect(row).toBeVisible();

    await row.getByRole('button', { name: `More actions for ${name}` }).click();
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    await question.getByRole('button', { name: 'Delete' }).click();
    await expect(row).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Add a size' })).toBeFocused();
    await expect.poll(() => names(page)).toEqual(seeded);
});

test('search, sort and pages', async ({ page }) => {
    // Sixty made-up sizes, answered here: the table's own work, nothing written
    const data = Array.from({ length: 60 }, (_, index) => ({ id: index + 1, name: `Size ${index + 1}`, examples: index === 41 ? 'Civic' : null, isActive: true, pricesSet: 60 - index }));
    await adminLogin(page, TEAM.owner);
    await page.route('**/console/api/owner/vehicle-sizes', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data }) }));
    await page.goto('/console/owner/vehicle-sizes');

    const pages = page.getByRole('navigation', { name: 'Vehicle sizes pages' });
    await expect(rows(page)).toHaveCount(50);
    await expect(page.getByText('1–50 of 60')).toBeVisible();
    await pages.getByRole('button', { name: 'Next page' }).click();
    await expect(rows(page)).toHaveCount(10);
    await expect(page.getByText('51–60 of 60')).toBeVisible();

    // Sorting goes back to the first page, and rows can't be moved while sorted
    const byName = page.getByRole('button', { name: 'Name', exact: true });
    await byName.click();
    await byName.click();
    await expect(page.getByRole('columnheader', { name: 'Name' })).toHaveAttribute('aria-sort', 'descending');
    await expect.poll(async () => (await names(page))[0]).toBe('Size 60');
    await expect(page.getByRole('button', { name: 'Move Size 60' })).toBeDisabled();
    await page.getByRole('button', { name: 'Prices set' }).click();
    await expect.poll(async () => (await names(page))[0]).toBe('Size 60');
    await expect(page.getByRole('columnheader', { name: 'Name' })).not.toHaveAttribute('aria-sort');

    const search = page.getByRole('searchbox', { name: 'Search vehicle sizes' });
    await search.fill('civic');
    await expect(rows(page)).toHaveCount(1);
    await expect(pages).toHaveCount(0);
    await search.fill('no such size');
    await expect(page.getByText('Nothing matches “no such size”.')).toBeVisible();
});

test('the empty and the failed states', async ({ page }) => {
    await adminLogin(page, TEAM.owner);
    let status = 500;
    await page.route('**/console/api/owner/vehicle-sizes', (route) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ data: [] }) }));
    await page.goto('/console/owner/vehicle-sizes');

    await expect(page.getByRole('main').getByRole('alert')).toContainText('Something went wrong on our side.');
    status = 200;
    await page.getByRole('button', { name: 'Try again' }).click();
    await expect(page.getByText('No vehicle sizes yet')).toBeVisible();
    await expect(page.getByRole('main').getByRole('alert')).toHaveCount(0);
});

test('vehicle sizes are accessible, light and dark, and fit 360px', async ({ page }) => {
    await openSizes(page);
    await expectAccessible(page);

    await page.getByRole('button', { name: 'Add a size' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expectAccessible(page);
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toBeHidden();

    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expectAccessible(page);

    await page.setViewportSize({ width: 360, height: 780 });
    await expectNoHorizontalOverflow(page);
    await expectAccessible(page);
});

test('review screenshots', async ({ page }, info) => {
    await openSizes(page);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: `test-results/console-vehicle-sizes-${info.project.name}.png` });
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.screenshot({ path: `test-results/console-vehicle-sizes-${info.project.name}-dark.png` });
});
