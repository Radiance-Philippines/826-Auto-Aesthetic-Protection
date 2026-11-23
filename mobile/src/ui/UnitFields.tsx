import { View } from 'react-native';
import type { VehicleType } from '@/api';
import type { Errors, UnitDraft } from '@/lib/wizard';
import { Field } from './Field';
import { Select } from './Select';

/** Year, model, type and plate of a new unit: the same four fields the site asks for */
export function UnitFields({ unit, types, errors, onChange }: { unit: UnitDraft; types: VehicleType[]; errors: Errors; onChange: (unit: UnitDraft) => void }) {
    const set = (patch: Partial<UnitDraft>) => onChange({ ...unit, ...patch });

    return (
        <View style={{ gap: 14 }}>
            <Field label="Year" value={unit.year} onChangeText={(year) => set({ year: year.replace(/\D/g, '').slice(0, 4) })} keyboardType="number-pad" maxLength={4} placeholder="2025" error={errors.year} />
            <Field label="Make and model" value={unit.model} onChangeText={(model) => set({ model })} placeholder="Toyota Vios" hint="Like Toyota Vios or Mitsubishi Montero" autoCapitalize="words" error={errors.model} />
            <Select label="Type of unit" value={unit.type} options={types.map((t) => ({ value: t.key, label: t.label }))} onChange={(type) => set({ type })} placeholder="Pick the type" error={errors.type} />
            <Field label="Plate number" value={unit.plate} onChangeText={(plate) => set({ plate })} placeholder="ABC 1234" hint="Or the conduction sticker number for a new unit" autoCapitalize="characters" autoCorrect={false} error={errors.plate} />
        </View>
    );
}
