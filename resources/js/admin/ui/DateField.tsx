import { CalendarDay, CalendarGrid, CalendarHeading, CalendarNext, CalendarPrevious, CalendarWeekday, CalendarWeekdays, CalendarWeeks, DatePicker, DatePickerContent, DatePickerInput, DatePickerTrigger } from '@ui-sdk/react/date-picker';

import { buttonClass } from './Button';
import { controlClass, type FieldProps, FieldShell } from './Field';
import { Icon } from './icons';

type Props = FieldProps & {
    /** "2026-11-14" */
    value: string | null;
    onChange: (value: string) => void;
    min?: string;
    max?: string;
};

/** Type a date or pick one from the calendar (never the browser's own picker) */
export function DateField({ value, onChange, min, max, ...field }: Props) {
    return (
        <FieldShell {...field}>
            <DatePicker value={value} onValueChange={(next) => typeof next === 'string' && onChange(next)} min={min} max={max} locale="en-PH" weekStartsOn={0} presentation="auto">
                <div className="flex gap-1">
                    <DatePickerInput aria-label={field.label} className={controlClass} />
                    <DatePickerTrigger aria-label={`Open the calendar: ${field.label.toLowerCase()}`} className={buttonClass('outline', 'md', true)}>
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
