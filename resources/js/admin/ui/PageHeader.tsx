import { type ReactNode, useEffect } from 'react';

/** A screen's h1, a line about it and its main actions; names the browser tab too */
export function PageHeader({ title, description, actions }: { title: string; description?: ReactNode; actions?: ReactNode }) {
    useEffect(() => {
        document.title = `${title} · 826`;
    }, [title]);

    return (
        <header className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
                <h1 className="page-title text-2xl">{title}</h1>
                {description && <p className="mt-1 max-w-prose text-sm text-muted-foreground">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </header>
    );
}
