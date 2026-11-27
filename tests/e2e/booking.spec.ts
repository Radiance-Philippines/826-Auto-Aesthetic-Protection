import { expect, type Locator, type Page, test } from '@playwright/test';

import { adminLogin, adminOpen, TEAM } from './admin-support';
import { expectNoHorizontalOverflow, signIn, watchProblems } from './support';

const main = (page: Page) => page.locator('main#main');
const next = (page: Page) => main(page).getByRole('button', { name: 'Next', exact: true });
const heading = (page: Page, name: string | RegExp) => main(page).getByRole('heading', { level: 2, name });

/** A unit and contact details no other test uses */
const UNIT = { year: '2021', model: 'Toyota Vios', plate: 'E2E 1234' };
const PERSON = { name: 'Maria Santos', phone: '0917 123 4567', email: 'maria@example.com' };

/** Step 1, when the page asks for it (it skips ahead when only one branch can be booked) */
async function pickBranch(page: Page) {
    if (await heading(page, 'Which branch will you drive to?').isVisible()) {
        await main(page).getByRole('radio', { name: /Cainta/ }).check({ force: true });
        await next(page).click();
    }
}

/** The unit step on a new unit's form. A customer who booked before sees their saved units first, once they have loaded */
async function startNewUnit(page: Page) {
    await expect(heading(page, 'Which unit are we working on?')).toBeVisible();

    const another = main(page).getByText('Add another unit');
    await expect(another.or(main(page).getByRole('combobox', { name: 'Year' })).first()).toBeVisible();

    if (await another.isVisible()) {
        await another.click();
    }
}

async function fillUnit(page: Page, plate = UNIT.plate) {
    await startNewUnit(page);

    await main(page).getByRole('combobox', { name: 'Year' }).click();
    await page.getByRole('option', { name: UNIT.year, exact: true }).click();
    await main(page).getByLabel('Make and model').fill(UNIT.model);
    await main(page).getByLabel('Plate number').fill(plate);
    await next(page).click();
}

async function pickService(page: Page, name = 'Exterior detailing') {
    await expect(heading(page, 'What should we do?')).toBeVisible();
    await main(page).getByRole('checkbox', { name: new RegExp(name) }).check({ force: true });
    await next(page).click();
}

/** Day buttons of the open month, by state */
const days = (page: Page, state: 'open' | 'fully booked'): Locator => main(page).locator(`button[data-iso][aria-label*=", ${state}"]`);

/** Look through every month on offer (the server decides which days exist) until a day in this state shows */
async function findDay(page: Page, state: 'open' | 'fully booked'): Promise<Locator> {
    await expect(main(page).getByRole('grid')).toBeVisible();
    const loaded = () => expect(main(page).locator('button[data-iso][aria-label*="Checking"]')).toHaveCount(0);
    const back = main(page).getByRole('button', { name: 'Previous month' });
    const forward = main(page).getByRole('button', { name: 'Next month' });
    await loaded();

    // The calendar opens on the first month with an open day: a full day can be in an earlier one (#696)
    while (await back.isEnabled()) {
        await back.click();
        await loaded();
    }

    while ((await days(page, state).count()) === 0) {
        if (!(await forward.isEnabled())) {
            throw new Error(`no ${state} day in the months on offer`);
        }

        await forward.click();
        await loaded();
    }

    return days(page, state).first();
}

async function pickDate(page: Page): Promise<string> {
    await expect(heading(page, 'Pick a drop-off date')).toBeVisible();
    const day = await findDay(page, 'open');
    const iso = (await day.getAttribute('data-iso')) as string;
    await day.click();
    await next(page).click();

    return iso;
}

async function fillDetails(page: Page, agree = true) {
    await expect(heading(page, 'Your details')).toBeVisible();
    await main(page).getByLabel('Full name').fill(PERSON.name);
    await main(page).getByLabel('Mobile number').fill(PERSON.phone);
    await main(page).getByLabel(/^Email/).fill(PERSON.email);

    if (agree) {
        await main(page).getByRole('checkbox').check({ force: true });
    }

    await next(page).click();
}

test.describe('booking validation', () => {
    test('make and model suggests units and still takes one that is not listed', async ({ page }) => {
        await signIn(page);
        await pickBranch(page);
        await startNewUnit(page);

        const model = main(page).getByLabel('Make and model');
        await model.pressSequentially('camr');
        await expect(page.getByRole('option', { name: 'Toyota Camry' })).toBeVisible();
        await expect(page.getByRole('option')).toHaveCount(1);
        await model.press('ArrowDown');
        await model.press('Enter');
        await expect(model).toHaveValue('Toyota Camry');

        await model.fill('Owner-type jeep');
        await expect(page.getByText('Not on the list. Keep what you typed.')).toBeVisible();
        await model.press('Tab');
        await expect(model).toHaveValue('Owner-type jeep');
        await expect(page.getByRole('option')).toHaveCount(0);
    });

    test('every step says what is missing before it moves on', async ({ page }) => {
        await signIn(page);
        await pickBranch(page);

        // Your unit: nothing filled in
        await startNewUnit(page);

        await next(page).click();
        await expect(main(page).getByText('Pick your unit’s year.')).toBeVisible();
        await expect(main(page).getByText('Add your unit’s make and model, like Toyota Vios.')).toBeVisible();
        await expect(main(page).getByText('Add your plate number.')).toBeVisible();
        await expect(heading(page, 'Which unit are we working on?')).toBeVisible();

        await main(page).getByLabel('Plate number').fill('AB#12');
        await next(page).click();
        await expect(main(page).getByText('Use letters and numbers only for the plate.')).toBeVisible();

        await main(page).getByLabel('Plate number').fill('');
        await main(page).getByRole('combobox', { name: 'Year' }).click();
        await page.getByRole('option', { name: UNIT.year, exact: true }).click();
        await main(page).getByLabel('Make and model').fill(UNIT.model);
        await main(page).getByLabel('Plate number').fill(UNIT.plate);
        await next(page).click();

        // Services: none picked
        await expect(heading(page, 'What should we do?')).toBeVisible();
        await next(page).click();
        await expect(main(page).getByText('Pick at least one service.')).toBeVisible();
        await main(page).getByRole('checkbox', { name: /Exterior detailing/ }).check({ force: true });
        await next(page).click();

        // Date: none picked
        await expect(heading(page, 'Pick a drop-off date')).toBeVisible();
        await next(page).click();
        await expect(main(page).getByText('Pick a day on the calendar.')).toBeVisible();
        await (await findDay(page, 'open')).click();
        await next(page).click();

        // Details: empty, then a bad phone and email, then no consent
        await expect(heading(page, 'Your details')).toBeVisible();
        await main(page).getByLabel('Full name').fill('');
        await next(page).click();
        await expect(main(page).getByText('Add your name so the branch knows who to look for.')).toBeVisible();
        await expect(main(page).getByText('Add a mobile number so the branch can reach you.')).toBeVisible();
        await expect(main(page).getByText('Agree to the privacy notice to continue.')).toBeVisible();

        await main(page).getByLabel('Full name').fill(PERSON.name);
        // Digits past a full mobile number are not taken, nor are letters
        await main(page).getByLabel('Mobile number').fill('4135135135135');
        await expect(main(page).getByLabel('Mobile number')).toHaveValue('41351351351');
        await main(page).getByLabel('Mobile number').fill('12');
        await main(page).getByLabel(/^Email/).fill('not-an-email');
        await main(page).getByRole('checkbox').check({ force: true });
        await next(page).click();
        await expect(main(page).getByText('Enter your mobile number, like 0917 123 4567.')).toBeVisible();
        await expect(main(page).getByText('That doesn’t look like an email address. Check it, or leave it blank.')).toBeVisible();
        await expect(heading(page, 'Your details')).toBeVisible();
    });

    test('a customer who is not signed in is asked to sign in at the end, with the picks kept', async ({ page }) => {
        await page.goto('/book');
        await pickBranch(page);
        await fillUnit(page);
        await pickService(page);
        await pickDate(page);
        await fillDetails(page);

        await expect(heading(page, 'Check and reserve')).toBeVisible();
        await expect(main(page).getByRole('heading', { name: 'Sign in to reserve' })).toBeVisible();
        await expect(main(page).getByRole('button', { name: 'Reserve this date' })).toHaveCount(0);
    });
});

test.describe('booking reservation', () => {
    test('the date calendar shows a full day that cannot be picked', async ({ page }) => {
        await signIn(page);
        await pickBranch(page);
        await fillUnit(page);
        await pickService(page);
        await expect(heading(page, 'Pick a drop-off date')).toBeVisible();

        const full = await findDay(page, 'fully booked');
        await expect(full).toHaveAttribute('aria-disabled', 'true');
        await full.click({ force: true });
        await next(page).click();
        await expect(main(page).getByText('Pick a day on the calendar.')).toBeVisible();

        // Earlier days than the notice period are closed, and nothing in the past is open
        const iso = await full.getAttribute('data-iso');
        expect(iso).toBeTruthy();
        const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
        expect(iso! > today).toBe(true);
    });

    test('the server refuses a full day even when the page is bypassed', async ({ page }) => {
        await signIn(page);
        await page.goto('/book');
        await page.locator('main#main h2').first().waitFor();

        const full = await page.evaluate(async () => {
            const month = new Date().toISOString().slice(0, 7);
            const ask = async (m: string) => (await (await fetch(`/api/availability?branch=1&services[]=1&month=${m}`)).json()) as { days: Record<string, string> };
            const days = (await ask(month)).days;
            const date = Object.keys(days).find((day) => days[day] === 'full');

            if (!date) {
                return null;
            }

            const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
            const response = await fetch('/api/bookings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({
                    branch: 1,
                    services: [1],
                    date,
                    vehicle_year: 2021,
                    vehicle_model: 'Toyota Vios',
                    vehicle_type: 'sedan',
                    plate_number: 'BYPASS1',
                    name: 'Maria Santos',
                    phone: '0917 123 4567',
                    consent: true,
                    fax_number: '',
                }),
            });

            return { status: response.status, body: await response.json() };
        });

        test.skip(full === null, 'the full day is not in this month');
        expect(full!.status).toBe(422);
        expect(JSON.stringify(full!.body)).toMatch(/date/i);
    });

    test('a signed-in customer reserves a date and lands on the booking page', async ({ page }) => {
        const problems = watchProblems(page);
        await signIn(page);
        await pickBranch(page);
        await fillUnit(page);
        await pickService(page);
        await pickDate(page);
        await fillDetails(page);

        await expect(heading(page, 'Check and reserve')).toBeVisible();
        const summary = main(page);
        await expect(summary.getByText('Exterior detailing').first()).toBeVisible();
        await expect(summary.getByText(PERSON.name)).toBeVisible();
        await expect(summary.getByText('E2E 1234').first()).toBeVisible();
        await expectNoHorizontalOverflow(page);

        await main(page).getByRole('button', { name: 'Reserve this date' }).click();

        await expect(page).toHaveURL(/\/bookings\/\d+/);
        await expect(main(page).locator('h1').first()).toBeVisible();
        await expect(main(page).getByText('Exterior detailing').first()).toBeVisible();
        await expectNoHorizontalOverflow(page);
        expect(problems()).toEqual([]);

        // It shows in My bookings too
        await page.goto('/bookings');
        await expect(main(page).getByText('Exterior detailing').first()).toBeVisible();
    });

    test('a reservation made on the site is in the branch queue for the team', async ({ page, browser }, info) => {
        const plate = `E2Q ${info.project.name.toUpperCase()}`;

        await signIn(page);
        await pickBranch(page);
        await fillUnit(page, plate);
        await pickService(page);
        const day = await pickDate(page);
        await fillDetails(page);
        await main(page).getByRole('button', { name: 'Reserve this date' }).click();
        await expect(page).toHaveURL(/\/bookings\/\d+/);

        // A second browser: the team member, on the day the customer picked
        const { baseURL, viewport } = info.project.use;
        const team = await browser.newContext({ baseURL, viewport });

        try {
            const queue = await team.newPage();
            await adminLogin(queue, TEAM.operations);
            await adminOpen(queue, `/operations/queue?date=${day}`);

            const card = queue.locator('section[aria-labelledby="queue-confirmed"] article').filter({ hasText: plate.replace(' ', '') });
            await expect(card).toHaveCount(1);
            await expect(card).toContainText('Exterior detailing');
            await expect(card).toContainText(PERSON.name);
        } finally {
            await team.close();
        }
    });

    test('the booking page is private to its owner', async ({ page }) => {
        await page.goto('/bookings/1');
        await expect(main(page).locator('h1').first()).toBeVisible();
        await expect(main(page).getByText(/E2E Full Day/)).toHaveCount(0);
    });
});
