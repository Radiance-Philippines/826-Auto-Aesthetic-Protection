import { Collapsible, CollapsiblePanel, CollapsibleTrigger } from '@ui-sdk/react/collapsible';
import { useState } from 'react';

import { buttonClass } from './Button';
import { cx } from './cx';
import { Icon } from './icons';

export type BarPoint = { label: string; value: number };

/** The next round number at or above `value`: 1, 2 or 5 times a power of ten */
function niceStep(value: number): number {
    const base = 10 ** Math.floor(Math.log10(value));

    return base * ([1, 2, 5, 10].find((times) => base * times >= value) ?? 10);
}

/**
 * One series as bars from zero (no chart library). Hover or tap a bar for its
 * number; "Show the numbers" lists them all, for the keyboard and screen readers.
 */
export function BarChart({ label, points, format, columnName, valueName }: { label: string; points: BarPoint[]; format: (value: number) => string; columnName: string; valueName: string }) {
    const [at, setAt] = useState<number | null>(null);
    const [open, setOpen] = useState(false);
    const highest = Math.max(1, ...points.map((point) => point.value));
    const step = niceStep(highest / 4);
    const top = step * Math.ceil(highest / step);
    const ticks = Array.from({ length: Math.round(top / step) + 1 }, (_, index) => index * step).reverse();
    const read = at === null ? null : points[at];
    const ends = points.length > 2 ? [points[0], points[Math.floor((points.length - 1) / 2)], points[points.length - 1]] : points;

    return (
        <figure className="m-0 min-w-0" aria-label={label}>
            <p aria-hidden="true" className="mb-1 min-h-5 text-end text-xs text-muted-foreground tabular-nums">
                {read && `${read.label}: ${format(read.value)}`}
            </p>
            <div aria-hidden="true" className="flex gap-2">
                <div className="flex h-60 flex-col justify-between text-end text-xs text-muted-foreground tabular-nums">
                    {ticks.map((tick) => (
                        <span key={tick} className="-my-2 leading-4">
                            {format(tick)}
                        </span>
                    ))}
                </div>
                <div className="relative h-60 min-w-0 flex-1" onPointerLeave={() => setAt(null)}>
                    {ticks.map((tick) => (
                        <div key={tick} className="absolute inset-x-0 border-t border-border" style={{ bottom: `${(tick / top) * 100}%` }} />
                    ))}
                    <div className="absolute inset-0 flex items-end gap-px">
                        {points.map((point, index) => (
                            <div key={point.label} className="flex h-full min-w-0 flex-1 items-end justify-center" onPointerEnter={() => setAt(index)} onPointerDown={() => setAt(index)}>
                                <div className={cx('w-full max-w-8 rounded-t-sm bg-primary', at !== null && at !== index && 'opacity-50')} style={{ height: `${(point.value / top) * 100}%` }} />
                            </div>
                        ))}
                    </div>
                </div>
            </div>
            <div aria-hidden="true" className="mt-1 flex justify-between gap-2 ps-12 text-xs text-muted-foreground">
                {ends.map((point) => (
                    <span key={point.label}>{point.label}</span>
                ))}
            </div>

            <Collapsible open={open} onOpenChange={setOpen} className="mt-2">
                <CollapsibleTrigger className={buttonClass('ghost', 'sm')}>
                    <Icon name={open ? 'chevron-down' : 'chevron-right'} />
                    {open ? 'Hide the numbers' : 'Show the numbers'}
                </CollapsibleTrigger>
                <CollapsiblePanel className="mt-2 max-h-72 overflow-auto rounded-lg border border-border">
                    <table className="w-full border-collapse text-sm">
                        <caption className="sr-only">{label}</caption>
                        <thead className="bg-muted/60 text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" className="px-3 py-2 text-start font-medium">
                                    {columnName}
                                </th>
                                <th scope="col" className="px-3 py-2 text-end font-medium">
                                    {valueName}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {points.map((point) => (
                                <tr key={point.label}>
                                    <th scope="row" className="border-t border-border px-3 py-1.5 text-start font-normal">
                                        {point.label}
                                    </th>
                                    <td className="border-t border-border px-3 py-1.5 text-end tabular-nums">{format(point.value)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CollapsiblePanel>
            </Collapsible>
        </figure>
    );
}
