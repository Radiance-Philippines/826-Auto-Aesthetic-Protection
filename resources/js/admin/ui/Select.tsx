import { Combobox, ComboboxContent, ComboboxEmpty, ComboboxInput, ComboboxItem, ComboboxItemIndicator, ComboboxList, ComboboxTrigger } from '@ui-sdk/react/combobox';
import { Select, SelectContent, SelectItem, SelectItemIndicator, SelectTrigger, SelectValue } from '@ui-sdk/react/select';

import { controlClass, type FieldProps, FieldShell } from './Field';
import { Icon } from './icons';

export type Option = { value: string; label: string };

const listClass = 'max-h-[min(18rem,var(--available-height))] min-w-(--anchor-width) overflow-y-auto overscroll-contain rounded-md border border-border bg-card p-1 text-foreground shadow-md';
const itemClass = 'flex cursor-pointer items-center justify-between gap-3 rounded-sm px-2 py-1.5 text-sm select-none data-highlighted:bg-muted data-selected:font-medium pointer-coarse:min-h-11';

type Props = FieldProps & {
    options: Option[];
    value: string | null;
    onChange: (value: string) => void;
    placeholder?: string;
};

/** Pick one from a short list (never the browser's own select) */
export function SelectField({ options, value, onChange, placeholder = 'Pick one', ...field }: Props) {
    return (
        <FieldShell {...field}>
            <Select items={options} value={value} onValueChange={(next) => next !== null && onChange(next as string)} placeholder={placeholder}>
                <SelectTrigger className={`${controlClass} flex items-center justify-between gap-2 text-start`}>
                    <SelectValue className="truncate" />
                    <Icon name="chevrons-up-down" className="size-4 text-muted-foreground" />
                </SelectTrigger>
                <SelectContent className={listClass} sideOffset={4}>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value} className={itemClass}>
                            {option.label}
                            <SelectItemIndicator className="data-[selected]:visible">
                                <Icon name="check" />
                            </SelectItemIndicator>
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </FieldShell>
    );
}

/** Pick one from a long list by typing part of it */
export function ComboboxField({ options, value, onChange, placeholder = 'Type to search', ...field }: Props) {
    return (
        <FieldShell {...field}>
            <Combobox items={options} value={value} onValueChange={(next) => onChange((next as string | null) ?? '')}>
                <div className="relative">
                    <ComboboxInput placeholder={placeholder} className={`${controlClass} pe-10`} />
                    <ComboboxTrigger aria-label={`Show ${field.label.toLowerCase()} options`} className="absolute inset-y-0 end-0 flex w-9 cursor-pointer items-center justify-center border-0 bg-transparent p-0 text-muted-foreground">
                        <Icon name="chevrons-up-down" />
                    </ComboboxTrigger>
                </div>
                <ComboboxContent className={listClass} sideOffset={4}>
                    <ComboboxEmpty className="hidden px-2 py-1.5 text-sm text-muted-foreground data-empty:block">Nothing matches.</ComboboxEmpty>
                    <ComboboxList>
                        {/* The matches, not every option: with `items` on the root the list says which */}
                        {(list) =>
                            (list.items as Option[]).map((option) => (
                                <ComboboxItem key={option.value} value={option.value} label={option.label} className={itemClass}>
                                    {option.label}
                                    <ComboboxItemIndicator>
                                        <Icon name="check" />
                                    </ComboboxItemIndicator>
                                </ComboboxItem>
                            ))
                        }
                    </ComboboxList>
                </ComboboxContent>
            </Combobox>
        </FieldShell>
    );
}
