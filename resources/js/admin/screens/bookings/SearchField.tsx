import { Combobox, ComboboxContent, ComboboxEmpty, ComboboxInput, ComboboxItem, ComboboxItemIndicator, ComboboxList } from '@ui-sdk/react/combobox';
import { useState } from 'react';

import { useResource } from '../../lib/useResource';
import { controlClass, type FieldProps, FieldShell } from '../../ui/Field';
import { Icon } from '../../ui/icons';
import type { Option } from '../../ui/Select';
import { useDebounced } from './parts';

type Props = FieldProps & {
    /** Answers ?search= with {data: options} */
    path: string;
    /** The chosen one, with the words to show for it */
    value: Option | null;
    onChange: (value: Option | null) => void;
    placeholder?: string;
};

/** Pick one of many by typing part of it; the server does the finding */
export function SearchField({ path, value, onChange, placeholder = 'Type to search', ...field }: Props) {
    const [query, setQuery] = useState('');
    const settled = useDebounced(query);
    const { data, loading, error } = useResource<{ data: Option[] }>(`${path}?search=${encodeURIComponent(settled)}`);
    const items = data?.data ?? [];

    return (
        <FieldShell {...field}>
            <Combobox
                items={items}
                filter={false}
                value={value?.value ?? null}
                itemToLabel={(each) => (value?.value === each ? value.label : (items.find((item) => item.value === each)?.label ?? ''))}
                onQueryChange={setQuery}
                onValueChange={(next) => {
                    onChange(items.find((item) => item.value === next) ?? null);
                    setQuery('');
                }}
            >
                <ComboboxInput placeholder={placeholder} className={controlClass} />
                <ComboboxContent className="max-h-[min(18rem,var(--available-height))] min-w-(--anchor-width) overflow-y-auto overscroll-contain rounded-md border border-border bg-card p-1 text-foreground shadow-md" sideOffset={4}>
                    <ComboboxEmpty className="hidden px-2 py-1.5 text-sm text-muted-foreground data-empty:block">{error ?? (loading ? 'Searching…' : 'Nothing matches.')}</ComboboxEmpty>
                    <ComboboxList>
                        {items.map((item) => (
                            <ComboboxItem key={item.value} value={item.value} label={item.label} className="flex cursor-pointer items-center justify-between gap-3 rounded-sm px-2 py-1.5 text-sm select-none data-highlighted:bg-muted pointer-coarse:min-h-11">
                                {item.label}
                                <ComboboxItemIndicator>
                                    <Icon name="check" />
                                </ComboboxItemIndicator>
                            </ComboboxItem>
                        ))}
                    </ComboboxList>
                </ComboboxContent>
            </Combobox>
        </FieldShell>
    );
}
