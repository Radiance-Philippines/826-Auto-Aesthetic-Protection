import { useEffect, useRef, useState } from 'react';

import { fetchMonth, type JobQuery, jobKey } from '../lib/availability';
import { copy } from '../lib/copy';
import { addMonths, manilaToday } from '../lib/dates';
import type { MonthAvailability } from '../types';
import Calendar from './Calendar';
import { Warning } from './Icons';

function RetryLine({ message, onRetry }: { message: string; onRetry: () => void }) {
    return (
        <p role="alert" className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm font-medium text-danger">
            <Warning className="size-4 shrink-0" /> {message}
            <button type="button" onClick={onRetry} className="press -mx-2 inline-flex min-h-11 items-center rounded-control px-2 font-semibold text-text underline underline-offset-4 hover:bg-text/6">
                {copy.calendar.retry}
            </button>
        </p>
    );
}

type CalendarProps = {
    job: JobQuery;
    selected: string | null;
    onPick: (date: string) => void;
    /** Each month that comes in: its days and the job's estimate, for the page around it */
    onMonth?: (month: MonthAvailability) => void;
};

/**
 * The calendar for a job: which days can take a drop-off (open), are
 * fully booked (full) or are closed, a month at a time, between the first
 * and last bookable days. Another job (other services, another booking)
 * starts it over.
 */
export function AvailabilityCalendar(props: CalendarProps) {
    return <JobCalendar key={jobKey(props.job)} {...props} />;
}

function JobCalendar({ job, selected, onPick, onMonth }: CalendarProps) {
    const id = jobKey(job);
    const [month, setMonth] = useState<string | null>(selected ? selected.slice(0, 7) : null);
    const [months, setMonths] = useState<Record<string, MonthAvailability>>({});
    const [failed, setFailed] = useState(false);
    const [attempt, setAttempt] = useState(0);
    const advanced = useRef(false);
    const reported = useRef(onMonth);
    const loaded = useRef(months);
    loaded.current = months;

    useEffect(() => {
        reported.current = onMonth;
    }, [onMonth]);

    useEffect(() => {
        if (month && loaded.current[month]) {
            return;
        }

        let cancelled = false;
        setFailed(false);

        fetchMonth(job, month ?? undefined).then((result) => {
            if (cancelled) {
                return;
            }

            if (result.kind !== 'ok') {
                setFailed(true);
                return;
            }

            const data = result.data;
            setMonths((current) => ({ ...current, [data.month]: data }));
            reported.current?.(data);

            // The first look: when this month has no open day left, the next one
            if (!month && !advanced.current && !Object.values(data.days).includes('open') && data.month < data.last.slice(0, 7)) {
                advanced.current = true;
                setMonth(addMonths(data.month, 1));
                return;
            }

            if (month !== data.month) {
                setMonth(data.month);
            }
        });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id, month, attempt]);

    const shown = month ?? manilaToday().slice(0, 7);
    const data = months[shown];
    const any = Object.values(months)[0];
    const minMonth = any ? any.first.slice(0, 7) : shown;
    const maxMonth = any ? any.last.slice(0, 7) : shown;
    const noneOpen = data && !Object.values(data.days).includes('open');

    return (
        <Calendar
            month={shown}
            onMonthChange={setMonth}
            minMonth={minMonth < shown ? minMonth : shown}
            maxMonth={maxMonth > shown ? maxMonth : shown}
            stateOf={(iso) => (data ? (data.days[iso] ?? 'closed') : failed ? 'closed' : 'loading')}
            selected={selected}
            onPick={onPick}
            status={
                <div aria-live="polite">
                    {failed ? (
                        <RetryLine message={copy.calendar.failed} onRetry={() => setAttempt((count) => count + 1)} />
                    ) : !data ? (
                        <p className="text-sm text-muted">{copy.calendar.checking}</p>
                    ) : noneOpen ? (
                        <p className="text-sm text-muted">{copy.calendar.noneThisMonth}</p>
                    ) : null}
                </div>
            }
        />
    );
}
