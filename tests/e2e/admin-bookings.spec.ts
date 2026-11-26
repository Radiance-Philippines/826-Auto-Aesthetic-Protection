import { expect, test } from '@playwright/test';

import { actionThenConfirm, adminLogin, adminOpen, openBooking, row, TEAM, unit } from './admin-support';
import { expectNoHorizontalOverflow } from './support';

test.describe('admin bookings', () => {
    test('the list shows the seeded bookings with their status and branch', async ({ page }, info) => {
        await adminLogin(page, TEAM.sales);
        await adminOpen(page, '/sales/bookings');
        const found = row(page, unit(info, 'Arrive'));
        await expect(found).toHaveCount(1);
        await expect(found).toContainText('Cainta');
        await expect(found).toContainText('Booked');
        // Only a day estimate, no hours or bay
        await expect(found).toContainText('Ready around');
        await expect(found).not.toContainText(/bay/i);
        await expectNoHorizontalOverflow(page);
    });

    test('a booking page shows the customer, the unit and the day estimate, and marks the unit arrived', async ({ page }, info) => {
        await adminLogin(page, TEAM.owner);
        await openBooking(page, unit(info, 'Arrive'));
        await expect(page.getByText(unit(info, 'Arrive')).first()).toBeVisible();
        await expect(page.getByText('Size class').first()).toBeVisible();
        await expect(page.getByText('History').first()).toBeVisible();

        await actionThenConfirm(page, 'Arrived');
        await expect(page.getByText('Arrived').first()).toBeVisible();

        // The arrival is recorded, and the next step is offered
        await expect(page.getByRole('button', { name: 'In progress', exact: true }).first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Arrived', exact: true })).toHaveCount(0);
    });

    test('a unit that is ready is marked exited', async ({ page }, info) => {
        await adminLogin(page, TEAM.owner);
        await openBooking(page, unit(info, 'Exit'));
        await actionThenConfirm(page, 'Exited');
        await expect(page.getByText('Exited').first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Exited', exact: true })).toHaveCount(0);
    });

    test('sales cancels a booking with a reason', async ({ page }, info) => {
        await adminLogin(page, TEAM.sales);
        await openBooking(page, unit(info, 'Cancel'));
        await page.getByRole('button', { name: /actions/i }).first().click();
        await page.getByRole('menuitem', { name: 'Cancel booking' }).click();
        await page.getByRole('dialog').getByLabel(/Reason/).fill('Customer called to cancel');
        await page.getByRole('dialog').getByRole('button', { name: 'Cancel booking', exact: true }).click();
        await page.waitForLoadState('networkidle');
        await expect(page.getByText('Cancelled').first()).toBeVisible();
    });
});
