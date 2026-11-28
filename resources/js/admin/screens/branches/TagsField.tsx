import { Input } from '@ui-sdk/react/input';
import { type ReactNode, useRef, useState } from 'react';

import { Button } from '../../ui/Button';
import { controlClass, type FieldProps, FieldShell } from '../../ui/Field';
import { Icon } from '../../ui/icons';

type Props = FieldProps & { hint: ReactNode; value: string[]; onChange: (value: string[]) => void; placeholder?: string };

/** A list of short words: type one and press Enter (or Add). Each has its own remove button. */
export function TagsField({ value, onChange, placeholder, ...field }: Props) {
    const [text, setText] = useState('');
    const input = useRef<HTMLInputElement>(null);

    const commit = () => {
        const tag = text.trim();

        if (tag && !value.includes(tag)) {
            onChange([...value, tag]);
        }

        setText('');
    };

    const add = () => {
        commit();
        input.current?.focus();
    };

    return (
        <div className="space-y-2">
            <FieldShell {...field}>
                <div className="flex gap-1.5">
                    <Input
                        ref={input}
                        value={text}
                        onValueChange={setText}
                        placeholder={placeholder}
                        autoComplete="off"
                        maxLength={255}
                        className={controlClass}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                add();
                            }
                        }}
                        // What was typed is not lost on the way to Save
                        onBlur={commit}
                    />
                    <Button type="button" icon="plus" onClick={add}>
                        Add
                    </Button>
                </div>
            </FieldShell>
            {value.length > 0 && (
                <ul aria-label={`${field.label}: added`} className="m-0 flex list-none flex-wrap gap-1.5 p-0">
                    {value.map((tag) => (
                        <li key={tag} className="inline-flex items-center gap-1 rounded-full bg-muted py-0.5 ps-3 pe-1 text-sm">
                            {tag}
                            <button type="button" aria-label={`Remove ${tag}`} onClick={() => onChange(value.filter((each) => each !== tag))} className="inline-flex size-7 cursor-pointer items-center justify-center rounded-full border-0 bg-transparent p-0 hover:bg-border pointer-coarse:size-11">
                                <Icon name="x" className="size-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
