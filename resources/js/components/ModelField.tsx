import { Autocomplete, AutocompleteContent, AutocompleteEmpty, AutocompleteInput, AutocompleteItem, AutocompleteList } from '@ui-sdk/react/autocomplete';
import { useId } from 'react';

import { carModels } from '../lib/carModels';
import { copy } from '../lib/copy';
import { control, ErrorText } from './Form';

const text = copy.book.vehicle.model;

type Props = {
    value: string;
    onChange: (value: string) => void;
    error?: string | null;
};

/**
 * "Make and model": a text box that suggests units while typing. The text is
 * the value, so a unit that isn't on the list is typed as it is.
 */
export default function ModelField({ value, onChange, error }: Props) {
    const id = useId();
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;

    return (
        <div>
            <label htmlFor={id} className="block font-semibold">
                {text.label}
            </label>
            <Autocomplete value={value} onValueChange={onChange} filter="contains">
                <AutocompleteInput
                    id={id}
                    name="vehicle_model"
                    className={`${control} mt-2 min-h-12 ${error ? 'border-danger' : 'border-field'}`}
                    placeholder={text.placeholder}
                    maxLength={80}
                    autoComplete="off"
                    spellCheck={false}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={[hintId, error ? errorId : null].filter(Boolean).join(' ')}
                />
                <AutocompleteContent
                    sideOffset={6}
                    className="m-0 max-h-[min(18rem,var(--available-height))] w-(--anchor-width) overflow-y-auto overscroll-contain rounded-control border border-field/55 bg-surface-2 p-1 text-text shadow-xl"
                >
                    <AutocompleteEmpty className="hidden px-3 py-2.5 text-sm text-muted data-empty:block">{text.notListed}</AutocompleteEmpty>
                    <AutocompleteList>
                        {carModels.map((model) => (
                            <AutocompleteItem key={model} value={model} className="flex min-h-11 cursor-pointer items-center rounded-control px-3 select-none data-highlighted:bg-surface-3">
                                {model}
                            </AutocompleteItem>
                        ))}
                    </AutocompleteList>
                </AutocompleteContent>
            </Autocomplete>
            <p id={hintId} className="mt-2 text-sm text-muted">
                {text.hint}
            </p>
            {error && <ErrorText id={errorId}>{error}</ErrorText>}
        </div>
    );
}
