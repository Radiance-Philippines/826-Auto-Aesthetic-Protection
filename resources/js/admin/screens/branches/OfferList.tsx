import type { ReactNode } from 'react';

import { Button } from '../../ui/Button';
import type { Option } from '../../ui/Select';
import { CheckboxListField } from '../bookings/parts';

type Props = { label: string; hint: ReactNode; error?: string | null; options: Option[]; value: number[]; onChange: (value: number[]) => void };

/** Tick several of a list of records, with Filament's "Select all" and "Deselect all" */
export function OfferList({ options, value, onChange, ...group }: Props) {
    return (
        <div className="space-y-2">
            <div className="flex flex-wrap justify-end gap-1.5">
                <Button type="button" size="sm" variant="ghost" icon="list-checks" onClick={() => onChange(options.map((option) => Number(option.value)))}>
                    Select all
                </Button>
                <Button type="button" size="sm" variant="ghost" icon="x" onClick={() => onChange([])}>
                    Deselect all
                </Button>
            </div>
            <CheckboxListField {...group} columns options={options} value={value.map(String)} onChange={(next) => onChange(next.map(Number))} />
        </div>
    );
}
