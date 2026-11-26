import { expect, test } from '@playwright/test';

import { adminLogin, adminOpen, openBooking, row, TEAM, unit } from './admin-support';
import { expectNoHorizontalOverflow } from './support';

test.describe('admin queue (detailers)', () => {
    test('a detailer moves a unit along the queue', async ({ page }, info) => {
        page.on('dialog', (dialog) => dialog.accept());
        await adminLogin(page, TEAM.operations);
        await adminOpen(page, '/operations/queue');

        const booked = page.locator('section[aria-labelledby="queue-confirmed"]');
        const arrived = page.locator('section[aria-labelledby="queue-arrived"]');
        await expect(booked.getByText(unit(info, 'Queue'))).toBeVisible();
        await expectNoHorizontalOverflow(page);

        await booked.locator('article').filter({ hasText: unit(info, 'Queue') }).getByRole('button', { name: 'Arrived' }).click();
        await expect(arrived.getByText(unit(info, 'Queue'))).toBeVisible();
        await expect(booked.getByText(unit(info, 'Queue'))).toHaveCount(0);
    });

    test('the queue shows the day cap and the unit', async ({ page }, info) => {
        await adminLogin(page, TEAM.operations);
        await adminOpen(page, '/operations/queue');
        await expect(page.getByText(/Dropping off today: \d+ of 1/)).toBeVisible();
        await expect(page.locator('article').filter({ hasText: unit(info, 'Roles') })).toBeVisible();
    });

    test('sales has no queue: the old queue link lands on its own dashboard', async ({ page }) => {
        await adminLogin(page, TEAM.sales);
        await adminOpen(page, '/admin/queue');
        await expect(page).toHaveURL(/\/sales\/?$/);
        await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    });

    test('a detailer cannot cancel a booking, only move it along', async ({ page }, info) => {
        await adminLogin(page, TEAM.operations);
        await openBooking(page, unit(info, 'Roles'), 'operations');
        await expect(page.getByRole('button', { name: 'Arrived', exact: true }).first()).toBeVisible();
        await page.getByRole('button', { name: /actions/i }).first().click();
        await expect(page.getByRole('menuitem', { name: 'Cancel booking' })).toHaveCount(0);
    });
});

test.describe('admin customers (CRM)', () => {
    test('the customer list finds a customer, and the profile shows their unit', async ({ page }) => {
        await adminLogin(page, TEAM.sales);
        await adminOpen(page, '/sales/customers');
        await page.getByPlaceholder('Search').first().fill('E2E Customer');
        const found = row(page, 'E2E Customer');
        await expect(found).toHaveCount(1);
        await expectNoHorizontalOverflow(page);

        await found.getByRole('link', { name: 'View' }).click();
        await page.waitForURL(/\/sales\/customers\/\d+/);
        await page.waitForLoadState('networkidle');
        await expect(page.getByRole('heading', { name: /E2E Customer/ }).first()).toBeVisible();

        await page.getByRole('tab', { name: /Units/ }).click();
        await expect(page.getByText('Honda City').first()).toBeVisible();
        await expect(page.getByText('CRM1234').first()).toBeVisible();
    });
});
