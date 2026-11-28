import { useState } from 'react';

import { api } from '../lib/api';
import { SectionNote, SettingsForm, SettingsPage } from '../shell/SettingsPage';
import { SwitchField } from '../ui/Field';
import { NumberField } from '../ui/NumberField';
import type { Option } from '../ui/Select';
import { toast } from '../ui/toast';
import { ChoiceField, Section, useSubmit } from './bookings/parts';

type Settings = { enabled: boolean; discountType: string; discountValue: number | null; ownerPoints: number | null; pesoPerPoint: number | null; types: Option[] };

function Form({ record, onSaved }: { record: Settings; onSaved: (record: Settings) => void }) {
    const [values, setValues] = useState(record);
    const { errors, failed, busy, submit } = useSubmit();
    const set = <Key extends keyof Settings>(key: Key) => (value: Settings[Key]) => setValues((current) => ({ ...current, [key]: value }));
    const percent = values.discountType === 'percent';

    const save = () =>
        submit(async () => {
            const { types: _types, ...body } = values;
            const saved = await api<Settings>('POST', '/referral', body);
            toast.success('Referral saved');
            onSaved(saved);
        });

    return (
        <SettingsForm onSubmit={save} errors={errors} failed={failed} busy={busy} saveLabel="Save referral">
            <Section title="Referral codes">
                <SectionNote>Every customer gets a code to share. A friend with no earlier booking enters it when they reserve, and the discount shows once the branch has seen their unit and priced it.</SectionNote>
                <div className="space-y-4">
                    <SwitchField label="Accept referral codes" hint="Needs a discount below. Off hides the code box on the site." checked={values.enabled} onChange={set('enabled')} />
                    <div className="grid items-start gap-4 sm:grid-cols-2">
                        <ChoiceField
                            label="The friend gets"
                            hint="Pesos off the price, or a share of it."
                            error={errors.discountType}
                            options={values.types.map((type) => ({ ...type, icon: type.value === 'percent' ? ('percent' as const) : ('banknote' as const) }))}
                            value={values.discountType}
                            onChange={set('discountType')}
                        />
                        <NumberField label={percent ? 'Percent off the price' : 'Pesos off the price'} prefix={percent ? undefined : '₱'} suffix={percent ? '%' : undefined} hint="Never more than the price." error={errors.discountValue} value={values.discountValue} onChange={set('discountValue')} min={0} />
                        <NumberField label="Points for the code’s owner" hint="Earned once, when the friend’s booking exits. Leave blank for none." error={errors.ownerPoints} value={values.ownerPoints} onChange={set('ownerPoints')} min={0} />
                        <NumberField label="Pesos one point is worth" prefix="₱" hint="Set by you. Blank means points can’t be redeemed for a discount; the team can still adjust points with a note." error={errors.pesoPerPoint} value={values.pesoPerPoint} onChange={set('pesoPerPoint')} min={0} />
                    </div>
                </div>
            </Section>
        </SettingsForm>
    );
}

export default function Referral() {
    return (
        <SettingsPage<Settings> title="Referral" path="/referral">
            {(record, onSaved, version) => <Form key={version} record={record} onSaved={onSaved} />}
        </SettingsPage>
    );
}
