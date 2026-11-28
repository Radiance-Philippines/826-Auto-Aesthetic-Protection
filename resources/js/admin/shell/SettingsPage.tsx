import { type FormEvent, type ReactNode, useEffect, useRef, useState } from 'react';

import { useResource } from '../lib/useResource';
import { Alert } from '../screens/bookings/parts';
import { Button } from '../ui/Button';
import type { IconName } from '../ui/icons';
import { PageHeader } from '../ui/PageHeader';
import { ErrorState, Loading } from '../ui/states';

/**
 * A settings screen: one saved record, read once, then a form over it. A save
 * hands back the new record and the form starts again from it.
 */
export function SettingsPage<Record>({
    title,
    description,
    path,
    actions,
    children,
}: {
    title: string;
    description?: ReactNode;
    path: string;
    actions?: (record: Record, onSaved: (next: Record) => void) => ReactNode;
    children: (record: Record, onSaved: (next: Record) => void, version: number) => ReactNode;
}) {
    const { data, error, reload } = useResource<Record>(path);
    const [saved, setSaved] = useState<{ record: Record; version: number } | null>(null);
    const record = saved?.record ?? data;
    const body = useRef<HTMLDivElement>(null);
    const version = saved?.version ?? 0;

    // The form starts again after a save: the keyboard goes back to its Save button, not to the top of the page
    useEffect(() => {
        if (version > 0) {
            body.current?.querySelector<HTMLElement>('button[type="submit"]')?.focus({ preventScroll: true });
        }
    }, [version]);
    const onSaved = (next: Record) => setSaved((current) => ({ record: next, version: (current?.version ?? 0) + 1 }));

    return (
        <>
            <PageHeader title={title} description={description} actions={record && actions?.(record, onSaved)} />
            {error && <ErrorState message={error} onRetry={reload} />}
            {record ? (
                <div ref={body} className="space-y-4">
                    {children(record, onSaved, version)}
                </div>
            ) : (
                !error && <Loading rows={5} />
            )}
        </>
    );
}

type FormProps = {
    onSubmit: () => void;
    errors: { [field: string]: string };
    failed: string | null;
    busy: boolean;
    saveLabel?: string;
    saveIcon?: IconName;
    /** Buttons beside Save */
    extra?: ReactNode;
    children: ReactNode;
};

/** The form of a settings screen, with Save at its foot and on Ctrl/⌘+S */
export function SettingsForm({ onSubmit, errors, failed, busy, saveLabel = 'Save', saveIcon = 'save', extra, children }: FormProps) {
    const form = useRef<HTMLFormElement>(null);

    useEffect(() => {
        const save = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
                event.preventDefault();
                form.current?.requestSubmit();
            }
        };

        window.addEventListener('keydown', save);

        return () => window.removeEventListener('keydown', save);
    }, []);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        onSubmit();
    };

    return (
        <form ref={form} onSubmit={submit} noValidate className="max-w-3xl space-y-4">
            {failed && <Alert title="Not saved">{failed}</Alert>}
            {!failed && Object.keys(errors).length > 0 && <Alert title="Not saved">Check the fields marked below.</Alert>}
            {children}
            <div className="flex flex-wrap gap-2">
                <Button type="submit" icon={saveIcon} variant="primary" busy={busy}>
                    {saveLabel}
                </Button>
                {extra}
            </div>
        </form>
    );
}

export const SectionNote = ({ children }: { children: ReactNode }) => <p className="mt-0 mb-4 text-sm text-muted-foreground">{children}</p>;
