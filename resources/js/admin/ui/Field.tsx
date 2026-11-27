import { Field, FieldDescription, FieldError, FieldLabel } from '@ui-sdk/react/field';
import { Input } from '@ui-sdk/react/input';
import { Switch, SwitchThumb } from '@ui-sdk/react/switch';
import type { ComponentProps, ReactNode } from 'react';

import { cx } from './cx';
import { Icon } from './icons';

/** Text boxes and the triggers that look like them */
export const controlClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-card px-3 text-sm text-foreground shadow-xs placeholder:text-muted-foreground pointer-coarse:h-11 pointer-coarse:text-base aria-invalid:border-destructive data-invalid:border-destructive disabled:opacity-50';

export type FieldProps = {
    label: string;
    /** Every input says what to type or what it does */
    hint: ReactNode;
    /** From the server; the browser's own checks show here too */
    error?: string | null;
    /** The label is still read out; the control must say what it is by itself */
    hideLabel?: boolean;
    className?: string;
};

/** Label, control, hint and error, wired together by UI SDK */
export function FieldShell({ label, hint, error, hideLabel, className, children }: FieldProps & { children: ReactNode }) {
    const message = 'flex items-start gap-1.5 text-sm text-destructive';

    return (
        <Field invalid={error ? true : undefined} className={cx('flex min-w-0 flex-col items-stretch gap-1.5', className)}>
            <FieldLabel className={cx('text-sm font-medium', hideLabel && 'sr-only')}>{label}</FieldLabel>
            {children}
            <FieldDescription className="text-xs text-muted-foreground">{hint}</FieldDescription>
            {error ? (
                <FieldError match className={message}>
                    <Icon name="circle-alert" className="mt-0.5 size-4" />
                    {error}
                </FieldError>
            ) : (
                <FieldError className={message} />
            )}
        </Field>
    );
}

export function TextField({ label, hint, error, hideLabel, className, ...input }: FieldProps & Omit<ComponentProps<typeof Input>, 'className'>) {
    return (
        <FieldShell label={label} hint={hint} error={error} hideLabel={hideLabel} className={className}>
            <Input {...input} className={controlClass} />
        </FieldShell>
    );
}

export function SwitchField({ label, hint, checked, onChange }: { label: string; hint: ReactNode; checked: boolean; onChange: (checked: boolean) => void }) {
    return (
        <Field className="flex flex-row items-start gap-3">
            <Switch checked={checked} onCheckedChange={onChange} className="mt-0.5">
                <SwitchThumb />
            </Switch>
            <div className="min-w-0">
                <FieldLabel className="text-sm font-medium">{label}</FieldLabel>
                <FieldDescription className="text-xs text-muted-foreground">{hint}</FieldDescription>
            </div>
        </Field>
    );
}
