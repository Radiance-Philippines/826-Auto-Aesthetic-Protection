import { Input } from '@ui-sdk/react/input';
import { type ReactNode, useId, useRef, useState } from 'react';

import { Button, buttonClass } from './Button';
import { controlClass } from './Field';
import { Icon } from './icons';
import { ActionMenu } from './Menu';
import { SortableBody, SortableHandle, SortableRow } from './Sortable';

type Props = {
    label: string;
    hint: ReactNode;
    error?: string | null;
    /** Errors of single lines, by position */
    lineErrors?: (string | undefined)[];
    value: string[];
    onChange: (value: string[]) => void;
    addLabel: string;
    maxLength?: number;
};

/** A list of one-line entries: add, remove, and order them by dragging the handle or from the row's menu */
export function Repeater({ label, hint, error, lineErrors, value, onChange, addLabel, maxLength }: Props) {
    const id = useId();
    // Keys that stay with their line while it moves
    const [keys, setKeys] = useState(() => value.map((_, index) => String(index)));
    const [added, setAdded] = useState<string | null>(null);
    const next = useRef(value.length);
    const add = useRef<HTMLDivElement>(null);

    const change = (order: string[], lines: string[]) => {
        setKeys(order);
        onChange(lines);
    };
    const reorder = (order: string[]) => change(order, order.map((key) => value[keys.indexOf(key)]));
    const move = (index: number, by: -1 | 1) => {
        const order = [...keys];
        [order[index], order[index + by]] = [order[index + by], order[index]];
        reorder(order);
    };
    const name = (index: number) => `${label}, line ${index + 1}`;

    return (
        <fieldset className="m-0 min-w-0 border-0 p-0" aria-describedby={`${id}-hint`}>
            <legend className="mb-1.5 p-0 text-sm font-medium">{label}</legend>
            {value.length > 0 && (
                <SortableBody render={<ol />} className="m-0 mb-2 list-none space-y-2 p-0" value={keys} onValueChange={reorder} labels={Object.fromEntries(keys.map((key, index) => [key, name(index)]))}>
                    {({ order }: { order: string[] }) =>
                        order.map((key) => {
                            const index = keys.indexOf(key);
                            const wrong = lineErrors?.[index];

                            return (
                                <SortableRow key={key} value={key} render={<li />} className="rounded-md bg-card data-grabbed:relative data-grabbed:z-10 data-grabbed:translate-y-(--drag-y,0) data-grabbed:shadow-lg">
                                    <div className="flex items-center gap-1">
                                        <SortableHandle value={key} aria-label={`Move ${name(index)}`} className={`${buttonClass('ghost', 'sm', true)} cursor-grab touch-none shadow-none`}>
                                            <Icon name="grip-vertical" />
                                        </SortableHandle>
                                        <Input
                                            aria-label={name(index)}
                                            aria-describedby={wrong ? `${id}-${key}` : `${id}-hint`}
                                            aria-invalid={wrong ? true : undefined}
                                            value={value[index] ?? ''}
                                            onValueChange={(line) => onChange(value.map((each, at) => (at === index ? line : each)))}
                                            autoFocus={key === added}
                                            maxLength={maxLength}
                                            autoComplete="off"
                                            className={controlClass}
                                        />
                                        <ActionMenu
                                            label={`More actions for ${name(index)}`}
                                            actions={[
                                                { id: 'up', label: 'Move up', icon: 'arrow-up', disabled: index === 0, onSelect: () => move(index, -1) },
                                                { id: 'down', label: 'Move down', icon: 'arrow-down', disabled: index === value.length - 1, onSelect: () => move(index, 1) },
                                            ]}
                                        />
                                        <Button
                                            type="button"
                                            icon="trash-2"
                                            size="sm"
                                            variant="ghost"
                                            aria-label={`Remove ${name(index)}`}
                                            onClick={() => {
                                                change(
                                                    keys.filter((each) => each !== key),
                                                    value.filter((_, at) => at !== index),
                                                );
                                                add.current?.querySelector('button')?.focus();
                                            }}
                                        />
                                    </div>
                                    {wrong && (
                                        <p id={`${id}-${key}`} role="alert" className="m-0 mt-1 ps-9 text-sm text-destructive">
                                            {wrong}
                                        </p>
                                    )}
                                </SortableRow>
                            );
                        })
                    }
                </SortableBody>
            )}
            <div ref={add}>
                <Button
                    type="button"
                    icon="plus"
                    size="sm"
                    onClick={() => {
                        const key = String(next.current++);
                        setAdded(key);
                        change([...keys, key], [...value, '']);
                    }}
                >
                    {addLabel}
                </Button>
            </div>
            <p id={`${id}-hint`} className="m-0 mt-1.5 text-xs text-muted-foreground">
                {hint}
            </p>
            {error && (
                <p role="alert" className="m-0 mt-1 flex items-start gap-1.5 text-sm text-destructive">
                    <Icon name="circle-alert" className="mt-0.5 size-4" />
                    {error}
                </p>
            )}
        </fieldset>
    );
}
