import { Button as UiButton } from '@ui-sdk/react/button';
import type { ComponentProps, ReactNode } from 'react';

import { cx } from './cx';
import { Icon, type IconName } from './icons';

export type ButtonVariant = 'primary' | 'outline' | 'ghost' | 'destructive';
export type ButtonSize = 'md' | 'sm';

const variants: Record<ButtonVariant, string> = {
    primary: 'border-primary bg-primary text-primary-foreground hover:bg-primary/90',
    outline: 'border-border bg-card text-foreground hover:bg-muted',
    ghost: 'border-transparent bg-transparent text-foreground hover:bg-muted',
    destructive: 'border-destructive/40 bg-card text-destructive hover:bg-destructive/10',
};

/** shadcn's button; at least 44px high where the finger is the pointer. Use it for links styled as buttons too. */
export const buttonClass = (variant: ButtonVariant = 'outline', size: ButtonSize = 'md', iconOnly = false) =>
    cx(
        'inline-flex shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md border text-sm font-medium whitespace-nowrap shadow-xs select-none',
        'pointer-coarse:min-h-11 data-disabled:cursor-not-allowed data-disabled:opacity-50 aria-disabled:opacity-50',
        size === 'sm' ? 'h-8 px-2.5' : 'h-9 px-3.5',
        iconOnly && (size === 'sm' ? 'w-8 !px-0 pointer-coarse:min-w-11' : 'w-9 !px-0 pointer-coarse:min-w-11'),
        variants[variant],
    );

type Props = Omit<ComponentProps<typeof UiButton>, 'children'> & {
    children?: ReactNode;
    /** Every button leads with an icon; one with no text needs an aria-label */
    icon: IconName;
    variant?: ButtonVariant;
    size?: ButtonSize;
    /** For work the button does not start itself, like a form being sent. (A Promise from onClick shows it alone.) */
    busy?: boolean;
};

export function Button({ icon, variant, size, busy = false, children, className, onClick, ...props }: Props) {
    return (
        <UiButton
            {...props}
            className={cx(buttonClass(variant, size, !children), typeof className === 'string' && className)}
            aria-busy={busy || undefined}
            onClick={busy ? (event) => event.preventDefault() : onClick}
            pendingAnnouncement="Working"
        >
            {busy ? (
                <Icon name="loader-circle" className="size-4 animate-spin" />
            ) : (
                <>
                    <Icon name={icon} className="size-4 in-data-pending:hidden" />
                    <Icon name="loader-circle" className="hidden size-4 animate-spin in-data-pending:block" />
                </>
            )}
            {children}
        </UiButton>
    );
}
