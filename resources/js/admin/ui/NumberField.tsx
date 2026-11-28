import { NumberInput, NumberInputControl, NumberInputGroup } from '@ui-sdk/react/number-input';
import type { ReactNode } from 'react';

import { controlClass, type FieldProps, FieldShell } from './Field';

type Props = FieldProps & {
    value: number | null;
    onChange: (value: number | null) => void;
    /** Only for what the arrow keys may not go under: the server says what is out of range, rather than a number being changed without a word */
    min?: number;
    step?: number;
    /** Intl.NumberFormat options, e.g. { useGrouping: false } for a code */
    format?: Intl.NumberFormatOptions;
    /** Units beside the box; say them in the hint too */
    prefix?: ReactNode;
    suffix?: ReactNode;
    placeholder?: string;
    required?: boolean;
};

/** A number: typed, or stepped with the arrow keys. Null while empty. */
export function NumberField({ value, onChange, min, step = 1, format, prefix, suffix, placeholder, required, ...field }: Props) {
    return (
        <FieldShell {...field}>
            <NumberInput value={value} onValueChange={(next) => onChange(next)} min={min} step={step} format={format} required={required} locale="en-PH">
                <NumberInputGroup className="flex min-w-0 items-center gap-2 border-0 bg-transparent p-0 shadow-none">
                    {prefix && (
                        <span aria-hidden="true" className="text-muted-foreground">
                            {prefix}
                        </span>
                    )}
                    <NumberInputControl placeholder={placeholder} className={`${controlClass} text-start tabular-nums`} />
                    {suffix && (
                        <span aria-hidden="true" className="shrink-0 text-xs text-muted-foreground">
                            {suffix}
                        </span>
                    )}
                </NumberInputGroup>
            </NumberInput>
        </FieldShell>
    );
}
