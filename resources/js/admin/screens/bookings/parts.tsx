import { Checkbox, CheckboxGroup, CheckboxIndicator } from '@ui-sdk/react/checkbox';
import { Menu, MenuCheckboxItem, MenuContent, MenuTrigger } from '@ui-sdk/react/menu';
import { SegmentedControl, SegmentedControlIndicator, SegmentedControlItem } from '@ui-sdk/react/segmented-control';
import { type ReactNode, useEffect, useId, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

/* Pieces the bookings, customers, warranty, manual transaction and inbox screens share */
import { errorMessage, fieldErrors } from '../../lib/api';
import { Button, buttonClass } from '../../ui/Button';
import { cx } from '../../ui/cx';
import type { TableState } from '../../ui/DataTable';
import { Icon, type IconName } from '../../ui/icons';
import { menuClass, menuItemClass } from '../../ui/Menu';
import type { Option } from '../../ui/Select';
import { toast } from '../../ui/toast';

/** The value once it has stopped changing, so typing asks the server once */
export function useDebounced<T>(value: T, delay = 300): T {
    const [settled, setSettled] = useState(value);

    useEffect(() => {
        const timer = window.setTimeout(() => setSettled(value), delay);

        return () => window.clearTimeout(timer);
    }, [value, delay]);

    return settled;
}

/** Sending a form: busy while it goes, the server's errors by field, anything else as one message */
export function useSubmit() {
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [failed, setFailed] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const submit = async (send: () => Promise<void>) => {
        setBusy(true);
        setErrors({});
        setFailed(null);

        try {
            await send();
        } catch (failure) {
            const fields = fieldErrors(failure);
            setErrors(fields);
            setFailed(fields.booking ?? (Object.keys(fields).length === 0 ? errorMessage(failure) : null));
        }

        setBusy(false);
    };

    return { errors, failed, busy, submit };
}

export function Alert({ title, children, tone = 'error' }: { title?: string; children: ReactNode; tone?: 'error' | 'warning' }) {
    return (
        <div role="alert" className={cx('flex items-start gap-2 rounded-xl border px-4 py-3 text-sm', tone === 'error' ? 'border-destructive/40 bg-card text-destructive' : 'border-border bg-warning text-warning-foreground')}>
            <Icon name={tone === 'error' ? 'circle-alert' : 'triangle-alert'} className="mt-0.5 size-4" />
            <div className="min-w-0">
                {title && <p className="font-semibold">{title}</p>}
                <p className="break-words">{children}</p>
            </div>
        </div>
    );
}

export function Section({ title, children, className }: { title: string; children: ReactNode; className?: string }) {
    const id = useId();

    return (
        <section aria-labelledby={id} className={cx('rounded-xl border border-border bg-card p-4', className)}>
            <h2 id={id} className="mb-3 text-sm font-semibold">
                {title}
            </h2>
            {children}
        </section>
    );
}

export const Details = ({ children }: { children: ReactNode }) => <dl className="m-0 grid gap-3 sm:grid-cols-2">{children}</dl>;

export function Entry({ label, children, note, wide }: { label: string; children: ReactNode; note?: ReactNode; wide?: boolean }) {
    return (
        <div className={cx('min-w-0', wide && 'sm:col-span-2')}>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="m-0 mt-0.5 break-words">{children}</dd>
            {note && <dd className="m-0 text-xs text-muted-foreground">{note}</dd>}
        </div>
    );
}

const tones: Record<string, string> = {
    warning: 'bg-warning text-warning-foreground',
    info: 'bg-info text-info-foreground',
    primary: 'bg-selected text-selected-foreground',
    success: 'bg-selected text-selected-foreground',
    danger: 'border border-destructive/40 text-destructive',
    gray: 'bg-muted text-foreground',
};

/** A badge in the colour Filament gives it */
export function Tone({ tone, children }: { tone: string; children: ReactNode }) {
    return <span className={cx('inline-flex w-fit items-center rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap', tones[tone] ?? tones.gray)}>{children}</span>;
}

export async function copy(text: string, what: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(`${what} copied`);
    } catch {
        toast.error(`Could not copy. Select the ${what.toLowerCase()} and copy it.`);
    }
}

export function CopyButton({ text, what }: { text: string; what: string }) {
    return <Button icon="clipboard-check" size="sm" variant="ghost" aria-label={`Copy ${what.toLowerCase()}`} onClick={() => copy(text, what)} />;
}

type GroupProps = { label: string; hint: ReactNode; error?: string | null };

/** A group of controls under one legend, with its hint and error */
export function Group({ label, hint, error, children }: GroupProps & { children: (describedBy: string) => ReactNode }) {
    const id = useId();

    return (
        <fieldset className="m-0 min-w-0 border-0 p-0" aria-describedby={`${id}-hint`}>
            <legend className="mb-1.5 p-0 text-sm font-medium">{label}</legend>
            {children(`${id}-hint`)}
            <p id={`${id}-hint`} className="mt-1.5 text-xs text-muted-foreground">
                {hint}
            </p>
            {error && (
                <p role="alert" className="mt-1 flex items-start gap-1.5 text-sm text-destructive">
                    <Icon name="circle-alert" className="mt-0.5 size-4" />
                    {error}
                </p>
            )}
        </fieldset>
    );
}

export function CheckboxListField({ options, value, onChange, columns, ...group }: GroupProps & { options: Option[]; value: string[]; onChange: (value: string[]) => void; columns?: boolean }) {
    return (
        <Group {...group}>
            {() => (
                <CheckboxGroup value={value} onValueChange={(next) => onChange(next as string[])} className={cx('grid items-start gap-2', columns && 'sm:grid-cols-2')}>
                    {options.map((option) => (
                        <label key={option.value} className="flex min-w-0 cursor-pointer items-start gap-2 text-sm pointer-coarse:min-h-11 pointer-coarse:items-center">
                            <Checkbox value={option.value} className="mt-0.5 shrink-0">
                                <CheckboxIndicator>
                                    <Icon name="check" className="size-3.5" />
                                </CheckboxIndicator>
                            </Checkbox>
                            <span className="min-w-0 break-words">{option.label}</span>
                        </label>
                    ))}
                </CheckboxGroup>
            )}
        </Group>
    );
}

/** Toggle buttons: one of a few, shown side by side */
export function ChoiceField({ options, value, onChange, ...group }: GroupProps & { options: (Option & { icon?: IconName })[]; value: string; onChange: (value: string) => void }) {
    return (
        <Group {...group}>
            {() => (
                <SegmentedControl value={value} onValueChange={(next) => onChange(String(next))} aria-label={group.label} className="max-w-full flex-wrap">
                    <SegmentedControlIndicator />
                    {options.map((option) => (
                        <SegmentedControlItem key={option.value} value={option.value} className="inline-flex items-center gap-1.5 pointer-coarse:min-h-11">
                            {option.icon && <Icon name={option.icon} />}
                            {option.label}
                        </SegmentedControlItem>
                    ))}
                </SegmentedControl>
            )}
        </Group>
    );
}

/** One row's tick box */
export function Tick({ label, checked, indeterminate, onChange }: { label: string; checked: boolean; indeterminate?: boolean; onChange: (checked: boolean) => void }) {
    return (
        <Checkbox checked={indeterminate ? 'indeterminate' : checked} onCheckedChange={(next) => onChange(next === true)} aria-label={label} className="align-middle">
            <CheckboxIndicator>
                <Icon name="check" className="size-3.5" />
            </CheckboxIndicator>
        </Checkbox>
    );
}

/** Switch the optional columns of a table on and off */
export function ColumnsMenu({ columns, shown, onChange }: { columns: { id: string; label: string }[]; shown: Record<string, boolean>; onChange: (shown: Record<string, boolean>) => void }) {
    return (
        <Menu>
            <MenuTrigger className={buttonClass('outline', 'sm')}>
                <Icon name="columns-3" />
                Columns
            </MenuTrigger>
            <MenuContent className={menuClass} align="start" sideOffset={4}>
                {columns.map((column) => (
                    <MenuCheckboxItem key={column.id} value={column.id} checked={shown[column.id]} onCheckedChange={(on) => onChange({ ...shown, [column.id]: on })} className={menuItemClass}>
                        <Icon name="check" className={cx('size-4', !shown[column.id] && 'invisible')} />
                        {column.label}
                    </MenuCheckboxItem>
                ))}
            </MenuContent>
        </Menu>
    );
}

/** A list's search, sort and page, kept in the address (Filament's own names) */
export function useListParams() {
    const [params, setParams] = useSearchParams();
    const settled = useDebounced(params.get('search') ?? '');
    const asked = new URLSearchParams(params);
    asked.delete('search');

    if (settled) {
        asked.set('search', settled);
    }

    const go = (changes: Record<string, string | string[] | null>) =>
        setParams(
            (current) => {
                const next = new URLSearchParams(current);

                for (const [key, value] of Object.entries(changes)) {
                    next.delete(key);

                    for (const each of Array.isArray(value) ? value : value ? [value] : []) {
                        next.append(key, each);
                    }
                }

                // Anything but a page step starts at the first page
                if (!('page' in changes)) {
                    next.delete('page');
                }

                return next;
            },
            { replace: true },
        );

    const state: TableState = {
        search: params.get('search') ?? '',
        sort: params.get('sort') ? { id: params.get('sort')!, desc: params.get('desc') === '1' } : null,
        page: Number(params.get('page')) || 1,
    };

    const onStateChange = (next: TableState) =>
        go({ search: next.search, sort: next.sort?.id ?? null, desc: next.sort?.desc ? '1' : null, page: next.page > 1 ? String(next.page) : null });

    return { params, query: asked.toString(), go, state, onStateChange };
}
