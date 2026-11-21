import type { BranchHours, MonthAvailability } from '../types';
import { type ApiResult, getJson, query } from './api';
import { addDays, isoDate, parseDate } from './dates';

/**
 * Whose dates these are for: a new booking's branch and services, or an
 * existing booking being moved (its own key opens it).
 */
export type JobQuery = { kind: 'new'; branch: number; services: number[] } | { kind: 'move'; booking: number; key: string | null };

function jobParams(job: JobQuery) {
    return job.kind === 'new' ? { branch: job.branch, services: [...job.services].sort((a, b) => a - b) } : { booking: job.booking, key: job.key };
}

/** The same job asks once a minute at most */
const FRESH_FOR_MS = 60_000;
const cache = new Map<string, { at: number; promise: Promise<ApiResult<unknown>> }>();

function cached<T>(path: string): Promise<ApiResult<T>> {
    const hit = cache.get(path);

    if (hit && Date.now() - hit.at < FRESH_FOR_MS) {
        return hit.promise as Promise<ApiResult<T>>;
    }

    const promise = getJson<T>(path).then((result) => {
        // Only good answers are kept; a failure asks again next time
        if (result.kind !== 'ok') {
            cache.delete(path);
        }

        return result;
    });

    cache.set(path, { at: Date.now(), promise });

    return promise;
}

export const jobKey = (job: JobQuery) => query(jobParams(job));

/**
 * Open, full and closed days for a month ("2026-11"), and the estimate for
 * the job; without a month, the server's current one.
 */
export const fetchMonth = (job: JobQuery, month?: string) => cached<MonthAvailability>(`/availability${query({ ...jobParams(job), month })}`);

/**
 * After a date turned out to be taken (or a booking moved): everything for
 * that job is asked again next time.
 */
export function forget(job: JobQuery): void {
    const key = jobKey(job).slice(1);

    for (const path of [...cache.keys()]) {
        if (path.includes(key)) {
            cache.delete(path);
        }
    }
}

/**
 * The day a job dropped off on `start` is done, worked out the way the
 * server does: the drop-off day is day 1 and closed days don't count.
 * Months that were loaded say which days are closed; others fall back to
 * the branch's weekly hours. Null when it's too far out to say.
 */
export function readyOn(start: string, days: number, months: Record<string, MonthAvailability>, weeklyHours: BranchHours[] | undefined): string | null {
    if (days < 1) {
        return null;
    }

    let date = parseDate(start);
    let counted = 0;

    for (let step = 0; step < 400; step++) {
        const iso = isoDate(date);
        const month = months[iso.slice(0, 7)];
        const isoWeekday = date.getDay() === 0 ? 7 : date.getDay();
        const open = month && iso >= month.first && iso <= month.last ? month.days[iso] !== 'closed' : weeklyHours ? Boolean(weeklyHours.find((day) => day.day === isoWeekday)?.open) : true;

        if (open && ++counted === days) {
            return step > 120 ? null : iso;
        }

        date = addDays(date, 1);
    }

    return null;
}
