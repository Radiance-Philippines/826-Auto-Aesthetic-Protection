import { CalendarDay, CalendarGrid, CalendarHeading, CalendarNext, CalendarPrevious, CalendarWeekday, CalendarWeekdays, CalendarWeeks, DatePicker, DatePickerContent, DatePickerInput, DatePickerTrigger } from '@ui-sdk/react/date-picker';

import { buttonClass } from './Button';
import { cx } from './cx';
import { controlClass, type FieldProps, FieldShell } from './Field';
import { Icon } from './icons';

type Props = FieldProps & {
    /** "2026-11-14" */
    value: string | null;
    onChange: (value: string) => void;
    min?: string;
    max?: string;
    /** Days that can't be picked */
    unavailable?: string[];
    /** Emptying the box gives "" (an optional date) */
    clearable?: boolean;
};

/** Type a date or pick one from the calendar (never the browser's own picker) */
export function DateField({ value, onChange, min, max, unavailable, clearable, ...field }: Props) {
    return (
        <FieldShell {...field}>
            <DatePicker value={value} onValueChange={(next) => (typeof next === 'string' ? onChange(next) : clearable && onChange(''))} min={min} max={max} unavailable={unavailable} locale="en-PH" weekStartsOn={0} presentation="auto" className="w-full">
                {/* The calendar button sits inside the box's end; web.css places it there, these sizes make it fit */}
                <div className="flex">
                    <DatePickerInput aria-label={field.label} className={cx(controlClass, 'pe-10 pointer-coarse:pe-12')} />
                    <DatePickerTrigger
                        aria-label={`Open the calendar: ${field.label.toLowerCase()}`}
                        className="ms-[-2.25rem] flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-md border-0 bg-transparent p-0 text-muted-foreground hover:text-foreground pointer-coarse:ms-[-2.75rem] pointer-coarse:size-11"
                    >
                        <Icon name="calendar" />
                    </DatePickerTrigger>
                </div>
                <DatePickerContent className="rounded-md border border-border bg-card p-3 text-foreground shadow-md" sideOffset={4}>
                    <div className="mb-2 flex items-center justify-between gap-2">
                        <CalendarPrevious aria-label="Earlier month" className={buttonClass('ghost', 'sm', true)}>
                            <Icon name="chevron-left" />
                        </CalendarPrevious>
                        <CalendarHeading className="text-sm font-semibold" />
                        <CalendarNext aria-label="Later month" className={buttonClass('ghost', 'sm', true)}>
                            <Icon name="chevron-right" />
                        </CalendarNext>
                    </div>
                    <CalendarGrid>
                        <CalendarWeekdays>{(index) => <CalendarWeekday index={index} />}</CalendarWeekdays>
                        <CalendarWeeks>{(cell) => <CalendarDay value={cell.date} />}</CalendarWeeks>
                    </CalendarGrid>
                </DatePickerContent>
            </DatePicker>
        </FieldShell>
    );
}
