import { createHmac } from 'node:crypto';
import { existsSync, readFileSync, rmSync } from 'node:fs';

import { expect, type Page, type TestInfo } from '@playwright/test';

/** The team accounts E2eSeeder creates (all with this password); the super admin comes from DatabaseSeeder */
export const PASSWORD = 'password';
export const TEAM = {
    owner: 'admin@826auto.test',
    twoFactor: 'twofa@826auto.test',
    emailCode: 'emailfa@826auto.test',
    operations: 'operations@826auto.test',
    sales: 'sales@826auto.test',
    manager: 'manager@826auto.test',
} as const;

/** The base32 secret E2eSeeder gives the two-factor admin */
export const TWO_FACTOR_SECRET = 'JBSWY3DPEHPK3PXP';

/** The bookings E2eSeeder made for this browser project (the tests change them, so each project has its own) */
export const unit = (info: TestInfo, kind: 'Arrive' | 'Queue' | 'Exit' | 'Cancel' | 'Roles') => `E2E ${kind} ${info.project.name}`;

export const main = (page: Page) => page.locator('main.fi-main, main').first();

/** Fill the sign-in form (it does not wait for the result) */
export async function submitLogin(page: Page, email: string, password = PASSWORD) {
    // Filament allows 5 sign-in tries a minute; the tests sign in far more often, so start each try with a clean counter
    rmSync('storage/framework/cache/data', { recursive: true, force: true });
    await page.goto('/admin/login');
    await page.getByLabel('Email address').fill(email);
    await page.locator('#form\\.password').fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
}

/** Sign in to /admin as one of the seeded team members (no two-factor) and wait for the panel */
export async function adminLogin(page: Page, email: string = TEAM.owner, password = PASSWORD) {
    await submitLogin(page, email, password);
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
    await page.waitForLoadState('networkidle');
}

/** Open an admin path and wait for Livewire to settle */
export async function adminOpen(page: Page, path: string) {
    const response = await page.goto(path);
    await page.waitForLoadState('networkidle');

    return response;
}

/** Type the code into Filament's six-box field; it sends the form by itself once complete */
export async function typeCode(page: Page, code: string) {
    await page.locator('.fi-one-time-code-input-ctn input').first().click();
    await page.keyboard.type(code);
}

/** The six-digit code an authenticator app would show now (RFC 6238, SHA-1, 30 s) */
export function totp(secret = TWO_FACTOR_SECRET, at = Date.now()): string {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';

    for (const char of secret.replace(/=+$/, '').toUpperCase()) {
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    }

    const key = Buffer.from(bits.match(/.{8}/g)!.map((byte) => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
    const hmac = createHmac('sha1', key).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    const value = ((hmac[offset] & 0x7f) << 24) | (hmac[offset + 1] << 16) | (hmac[offset + 2] << 8) | hmac[offset + 3];

    return String(value % 1_000_000).padStart(6, '0');
}

/** The raw mail log: serve.sh writes mail to storage/e2e/mail.log and never uses SMTP */
function rawMail(): string {
    const file = 'storage/e2e/mail.log';

    return existsSync(file) ? readFileSync(file, 'utf8') : '';
}

/** Everything the app "sent" so far (the log mailer writes the message readable: no quoted-printable to undo) */
export function sentMail(): string {
    return rawMail();
}

/** The newest set-password link in the captured mail for this address, signature intact */
export function inviteLinkFor(email: string): string | null {
    const blocks = rawMail()
        .split(/(?=^\[\d{4}-\d\d-\d\d)/m)
        .filter((block) => block.includes(email));
    const links = blocks.flatMap((block) => [...block.matchAll(/https?:\/\/[^\s"'<>)\]]*password-reset\/reset[^\s"'<>)\]]*/g)].map((match) => match[0].replace(/&amp;/g, '&')));

    return links.filter((link) => /[?&]signature=/.test(link)).at(-1) ?? null;
}

/** The row of a Filament table that mentions this text */
export const row = (page: Page, text: string) => page.locator('table tbody tr').filter({ hasText: text });

/** Open a booking from the Bookings list by the customer's name */
export async function openBooking(page: Page, name: string) {
    await adminOpen(page, '/admin/bookings');
    await page.getByPlaceholder('Search').first().fill(name);
    const found = row(page, name);
    await expect(found).toHaveCount(1);
    await found.getByRole('link', { name: 'View' }).click();
    await page.waitForURL(/\/admin\/bookings\/\d+/);
    await page.waitForLoadState('networkidle');
}

/** Click a header action and confirm its modal */
export async function actionThenConfirm(page: Page, action: string, confirm = 'Confirm') {
    await page.getByRole('button', { name: action, exact: true }).first().click();
    await page.locator('[role=dialog], [role=alertdialog]').getByRole('button', { name: confirm, exact: true }).click();
    await page.waitForLoadState('networkidle');
}

/** The newest six-digit sign-in code emailed to this address after `since` characters of mail */
export function emailedCodeFor(email: string, since = 0): string | null {
    const blocks = sentMail().slice(since).split(/(?=^\[\d{4}-\d\d-\d\d)/m).filter((block) => block.includes(email));
    const last = blocks.at(-1) ?? '';
    const body = last.replace(/<[^>]+>/g, ' ');

    return body.match(/(?:^|[^\d])(\d{3}\s?\d{3})(?:[^\d]|$)/m)?.[1].replace(/\s/g, '') ?? null;
}
