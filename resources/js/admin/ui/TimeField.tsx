import { TimeInput } from '@ui-sdk/react/time-input';

import { cx } from './cx';
import { controlClass, type FieldProps, FieldShell } from './Field';

type Props = FieldProps & {
    /** "08:00" (24-hour), or "" while empty */
    value: string;
    onChange: (value: string) => void;
};

/** A time typed part by part on a 12-hour clock (never the browser's own picker) */
export function TimeField({ value, onChange, ...field }: Props) {
    return (
        <FieldShell {...field}>
            <TimeInput value={value || null} onValueChange={(next) => onChange(next ?? '')} hourCycle={12} locale="en-PH" aria-label={field.label} className={cx(controlClass, 'flex items-center tabular-nums')} />
        </FieldShell>
    );
}
