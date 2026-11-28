import { PasswordInput, PasswordInputControl, PasswordInputToggle } from '@ui-sdk/react/password-input';

import { buttonClass } from '../ui/Button';
import { controlClass, type FieldProps, FieldShell } from '../ui/Field';
import { Icon } from '../ui/icons';

/**
 * A password or key to type. Only what is typed here can be shown: a saved
 * secret never comes to the browser, and a blank box keeps it.
 */
export function SecretField({ value, onChange, reveal = true, required, maxLength, ...field }: FieldProps & { value: string; onChange: (value: string) => void; reveal?: boolean; required?: boolean; maxLength?: number }) {
    return (
        <FieldShell {...field}>
            <PasswordInput value={value} onValueChange={(next) => onChange(next)} autoComplete="new-password" required={required} className="flex w-full min-w-0 gap-1 border-0 bg-transparent p-0 shadow-none">
                <PasswordInputControl maxLength={maxLength} className={controlClass} />
                {reveal && (
                    <PasswordInputToggle aria-label={`Show ${field.label.toLowerCase()}`} className={buttonClass('outline', 'md', true)}>
                        {(state: { visible: boolean }) => <Icon name={state.visible ? 'eye-off' : 'eye'} />}
                    </PasswordInputToggle>
                )}
            </PasswordInput>
        </FieldShell>
    );
}
