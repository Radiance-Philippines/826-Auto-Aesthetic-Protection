import { copy } from '../lib/copy';
import { vehicleYears } from '../lib/vehicles';
import { SelectField, TextField } from './Form';
import ModelField from './ModelField';

export type UnitValue = { year: string; model: string; type: string; plate: string };

type Props = {
    value: UnitValue;
    onChange: (change: Partial<UnitValue>, fields: string[]) => void;
    /** The message for a field (year, model, type, plate_number), if any */
    errorFor: (field: string) => string | null;
};

const YEARS = vehicleYears().map((year) => ({ value: String(year), label: String(year) }));

const text = copy.book.vehicle;

/**
 * What 826 needs to know about a unit: year, make and model, and plate.
 * No type or size: the team sets that after they've seen the unit.
 */
export default function UnitFields({ value, onChange, errorFor }: Props) {
    return (
        <div>
            <div className="grid gap-5 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]">
                <SelectField label={text.year.label} placeholder={text.year.placeholder} value={value.year} onChange={(year) => onChange({ year }, ['vehicle_year'])} options={YEARS} error={errorFor('vehicle_year')} name="vehicle_year" />
                <ModelField value={value.model} onChange={(model) => onChange({ model }, ['vehicle_model'])} error={errorFor('vehicle_model')} />
            </div>


            <TextField
                className="mt-6 sm:max-w-sm"
                label={text.plate.label}
                hint={text.plate.hint}
                placeholder={text.plate.placeholder}
                value={value.plate}
                onChange={(plate) => onChange({ plate }, ['plate_number'])}
                error={errorFor('plate_number')}
                autoComplete="off"
                autoCapitalize="characters"
                maxLength={15}
                name="plate_number"
            />
        </div>
    );
}
