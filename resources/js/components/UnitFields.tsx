import { copy } from '../lib/copy';
import { vehicleTypes } from '../lib/site';
import { vehicleYears } from '../lib/vehicles';
import { ErrorText, SelectField, TextField } from './Form';
import { Check } from './Icons';
import VehicleSilhouette from './VehicleSilhouette';

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
 * What 826 needs to know about a unit: year, make and model, type (drawn)
 * and plate. No size: the team sets that after they've seen the unit.
 */
export default function UnitFields({ value, onChange, errorFor }: Props) {
    return (
        <div>
            <div className="grid gap-5 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]">
                <SelectField label={text.year.label} placeholder={text.year.placeholder} value={value.year} onChange={(year) => onChange({ year }, ['vehicle_year'])} options={YEARS} error={errorFor('vehicle_year')} name="vehicle_year" />
                <TextField
                    label={text.model.label}
                    hint={text.model.hint}
                    placeholder={text.model.placeholder}
                    value={value.model}
                    onChange={(model) => onChange({ model }, ['vehicle_model'])}
                    error={errorFor('vehicle_model')}
                    autoComplete="off"
                    maxLength={80}
                    name="vehicle_model"
                />
            </div>

            <fieldset className="mt-6" aria-describedby={errorFor('vehicle_type') ? 'type-error' : undefined}>
                <legend className="font-semibold">{text.type}</legend>
                <div className="mt-3 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                    {vehicleTypes.map((option) => {
                        const checked = value.type === option.key;

                        return (
                            <label
                                key={option.key}
                                className={`choice press relative flex min-h-11 cursor-pointer flex-col items-center gap-2 rounded-card border px-3 pt-3.5 pb-3 has-focus-visible:outline-2 has-focus-visible:outline-offset-3 has-focus-visible:outline-accent ${
                                    checked ? 'is-checked border-accent ring-1 ring-accent' : 'border-field/55 hover:border-text/55'
                                }`}
                            >
                                <input type="radio" name="vehicle_type" value={option.key} checked={checked} onChange={() => onChange({ type: option.key }, ['vehicle_type'])} className="peer sr-only" />
                                <span className={`block w-full ${checked ? 'text-accent' : 'text-text/45'}`}>
                                    <VehicleSilhouette type={option.key} className="w-full" />
                                </span>
                                <span className="flex items-center gap-1.5 text-center leading-snug font-semibold">
                                    {checked && <Check className="size-4 text-accent" />}
                                    {option.label}
                                </span>
                            </label>
                        );
                    })}
                </div>
                {errorFor('vehicle_type') && (
                    <span data-error-anchor tabIndex={-1}>
                        <ErrorText id="type-error">{errorFor('vehicle_type')}</ErrorText>
                    </span>
                )}
            </fieldset>

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
