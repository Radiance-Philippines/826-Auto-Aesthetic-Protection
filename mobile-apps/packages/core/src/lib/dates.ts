/*
 * Calendar dates as "YYYY-MM-DD" strings on plain local dates (no time zone
 * moves a day). Server timestamps carry +08:00 and are shown in Manila time
 * (a fixed +8, no daylight saving).
 */
const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const MONTHS_LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

export const pad = (value: number) => String(value).padStart(2, '0');
export const isoDate = (date: Date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

export function parseDate(iso: string): Date {
    const [year, month, day] = iso.split('-').map(Number);

    return new Date(year, (month || 1) - 1, day || 1);
}

export const monthKey = (date: Date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}`;
export const monthStart = (key: string) => parseDate(`${key}-01`);

export function addMonths(key: string, count: number): string {
    const date = monthStart(key);

    return monthKey(new Date(date.getFullYear(), date.getMonth() + count, 1));
}

export const addDays = (date: Date, days: number) => new Date(date.getFullYear(), date.getMonth(), date.getDate() + days);
export const daysInMonth = (date: Date) => new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate();

const MANILA_OFFSET_MS = 8 * 60 * 60 * 1000;

/** Today in Manila, as "YYYY-MM-DD" */
export function manilaToday(now: Date = new Date()): string {
    const m = new Date(now.getTime() + MANILA_OFFSET_MS);

    return `${m.getUTCFullYear()}-${pad(m.getUTCMonth() + 1)}-${pad(m.getUTCDate())}`;
}

/** "2026-11-24" -> "Tue, 24 Nov" */
export function shortDate(iso: string): string {
    const d = parseDate(iso);

    return `${DAYS[d.getDay()]}, ${d.getDate()} ${MONTHS[d.getMonth()]}`;
}

/** "2026-11-24" -> "24 Nov 2026" */
export function dayMonthYear(iso: string): string {
    const d = parseDate(iso);

    return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
}

/** "2026-11-24" -> "Tuesday, 24 November" */
export function longDate(iso: string): string {
    const d = parseDate(iso);

    return `${['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][d.getDay()]}, ${d.getDate()} ${MONTHS_LONG[d.getMonth()]}`;
}

/** "2026-11" -> "November 2026" */
export function monthTitle(key: string): string {
    const d = monthStart(key);

    return `${MONTHS_LONG[d.getMonth()]} ${d.getFullYear()}`;
}

/** "2026-11-24" -> "November 2026" */
export const monthYear = (iso: string) => monthTitle(iso.slice(0, 7));

/** Short day name of an ISO date: "Mon" */
export const dayName = (iso: string) => DAYS[parseDate(iso).getDay()];

/**
 * A server timestamp read in Manila time: when it is (`ms`), which day (`day`), and whether it carries a real time
 * (`clock`). A value at exactly 00:00:00 is a date that was stored without a time, so it has no clock.
 * Null when missing or unreadable.
 */
export type Moment = { ms: number; day: string; clock: boolean; hours: number; minutes: number };

export function moment(value: string | null | undefined): Moment | null {
    if (!value) {
        return null;
    }

    const ms = new Date(value).getTime();

    if (Number.isNaN(ms)) {
        return null;
    }

    const m = new Date(ms + MANILA_OFFSET_MS);

    return {
        ms,
        day: `${m.getUTCFullYear()}-${pad(m.getUTCMonth() + 1)}-${pad(m.getUTCDate())}`,
        clock: m.getUTCHours() + m.getUTCMinutes() + m.getUTCSeconds() > 0,
        hours: m.getUTCHours(),
        minutes: m.getUTCMinutes(),
    };
}

const clockText = (m: Moment) => `${m.hours % 12 || 12}:${pad(m.minutes)} ${m.hours < 12 ? 'AM' : 'PM'}`;

/** A server timestamp as "Sat, 21 Nov, 9:12 AM" in Manila time; null when missing or unreadable */
export function stamp(value: string | null | undefined): string | null {
    const m = moment(value);

    return m ? `${shortDate(m.day)}, ${clockText(m)}` : null;
}

/** Like `stamp`, but a value with no real time (stored as a bare date) shows just the date: "Sat, 21 Nov" */
export function stampOrDate(value: string | null | undefined): string | null {
    const m = moment(value);

    return m ? (m.clock ? `${shortDate(m.day)}, ${clockText(m)}` : shortDate(m.day)) : null;
}

/** Whole minutes from `now` until a server timestamp; 0 when it has passed or is unreadable */
export function minutesUntil(value: string | null | undefined, now: number = Date.now()): number {
    const ms = value ? new Date(value).getTime() : NaN;

    return Number.isNaN(ms) ? 0 : Math.max(0, Math.ceil((ms - now) / 60000));
}

/** "Sedan", "SUV" in a sentence: "PPF" and "SUV" keep their capitals */
export const lower = (name: string) =>
    name
        .split(' ')
        .map((word) => (word.length > 1 && word === word.toUpperCase() ? word : word.toLowerCase()))
        .join(' ');

/** "21 Nov, 9:12 AM": a server timestamp without the weekday */
export const shortStamp = (value: string | null | undefined): string | null => stamp(value)?.replace(/^\w{3}, /, '') ?? null;
