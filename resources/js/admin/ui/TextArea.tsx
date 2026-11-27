import { Textarea } from '@ui-sdk/react/textarea';
import type { ComponentProps } from 'react';

import { cx } from './cx';
import { controlClass, type FieldProps, FieldShell } from './Field';

/** More than one line of text */
export function TextAreaField({ label, hint, error, hideLabel, className, rows = 3, ...textarea }: FieldProps & Omit<ComponentProps<typeof Textarea>, 'className'>) {
    return (
        <FieldShell label={label} hint={hint} error={error} hideLabel={hideLabel} className={className}>
            <Textarea {...textarea} rows={rows} className={cx(controlClass, '!h-auto min-h-16 resize-y py-2')} />
        </FieldShell>
    );
}
