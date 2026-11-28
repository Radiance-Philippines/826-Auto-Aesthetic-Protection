import { useState } from 'react';

import { api } from '../lib/api';
import { SectionNote, SettingsForm, SettingsPage } from '../shell/SettingsPage';
import { TextField } from '../ui/Field';
import { FileUpload, shrinkImage, toFormData } from '../ui/FileUpload';
import { TextAreaField } from '../ui/TextArea';
import { toast } from '../ui/toast';
import { Section, useSubmit } from './bookings/parts';

type Text = 'tagline' | 'intro' | 'phone' | 'contactEmail' | 'facebookUrl' | 'instagramUrl' | 'messengerUrl';
type Picture = 'heroImage' | 'ogImage';
type Settings = Record<Text, string | null> & { heroImageUrl: string | null; ogImageUrl: string | null };

const pictures: Record<Picture, { label: string; hint: string }> = {
    heroImage: { label: 'Home page picture', hint: 'The big picture at the top of the Home page. JPG, PNG or WebP, up to 20 MB.' },
    ogImage: { label: 'Link-preview picture', hint: 'Optional. Used when a page is shared on Facebook or Messenger. Use a 1200×630 picture. JPG, PNG or WebP, up to 20 MB.' },
};

const links: { name: Text; label: string; placeholder: string }[] = [
    { name: 'facebookUrl', label: 'Facebook page', placeholder: 'https://www.facebook.com/…' },
    { name: 'instagramUrl', label: 'Instagram', placeholder: 'https://www.instagram.com/…' },
    { name: 'messengerUrl', label: 'Messenger link', placeholder: 'https://m.me/…' },
];

function Form({ record, onSaved }: { record: Settings; onSaved: (record: Settings) => void }) {
    const { heroImageUrl, ogImageUrl, ...saved } = record;
    const [values, setValues] = useState(saved);
    const [files, setFiles] = useState<Record<Picture, File[]>>({ heroImage: [], ogImage: [] });
    const [removed, setRemoved] = useState<Record<Picture, boolean>>({ heroImage: false, ogImage: false });
    const { errors, failed, busy, submit } = useSubmit();
    const current: Record<Picture, string | null> = { heroImage: heroImageUrl, ogImage: ogImageUrl };

    const text = (name: Text) => ({ value: values[name] ?? '', error: errors[name], onValueChange: (value: string) => setValues((all) => ({ ...all, [name]: value })) });

    const picture = (name: Picture) => (
        <FileUpload
            {...pictures[name]}
            error={errors[name]}
            value={files[name]}
            onChange={(chosen) => setFiles((all) => ({ ...all, [name]: chosen }))}
            maxMb={20}
            current={removed[name] ? null : current[name]}
            onRemoveCurrent={() => setRemoved((all) => ({ ...all, [name]: true }))}
        />
    );

    const save = () =>
        submit(async () => {
            const body = {
                ...values,
                removeHeroImage: removed.heroImage,
                removeOgImage: removed.ogImage,
                heroImage: files.heroImage[0] && (await shrinkImage(files.heroImage[0])),
                ogImage: files.ogImage[0] && (await shrinkImage(files.ogImage[0])),
            };
            const next = await api<Settings>('POST', '/site-settings', toFormData(body));
            toast.success('Site settings saved', { description: 'The site is updated.' });
            onSaved(next);
        });

    return (
        <SettingsForm onSubmit={save} errors={errors} failed={failed} busy={busy} saveLabel="Save settings">
            <Section title="About 826">
                <div className="space-y-4">
                    <TextField label="Tagline" hint="One short line under the name." maxLength={120} {...text('tagline')} />
                    <TextAreaField label="Intro" hint="A few sentences about 826, for the Home page, link previews and search engines." rows={4} maxLength={400} {...text('intro')} />
                    <div className="grid items-start gap-4 sm:grid-cols-2">
                        {picture('heroImage')}
                        {picture('ogImage')}
                    </div>
                </div>
            </Section>
            <Section title="Main contact">
                <SectionNote>Shown in the footer and when a branch has no number of its own. Leave a field blank to hide it.</SectionNote>
                <div className="grid items-start gap-4 sm:grid-cols-2">
                    <TextField label="Mobile (call or text)" type="tel" hint="Like 0917 000 0000." maxLength={40} autoComplete="off" {...text('phone')} />
                    <TextField label="Contact email" type="email" hint="Where visitors can write to you." maxLength={190} autoComplete="off" {...text('contactEmail')} />
                    {links.map((link) => (
                        <TextField key={link.name} label={link.label} type="url" hint="The full address, starting with https://." placeholder={link.placeholder} maxLength={255} autoComplete="off" {...text(link.name)} />
                    ))}
                </div>
            </Section>
        </SettingsForm>
    );
}

export default function SiteSettings() {
    return (
        <SettingsPage<Settings> title="Site settings" path="/site-settings">
            {(record, onSaved, version) => <Form key={version} record={record} onSaved={onSaved} />}
        </SettingsPage>
    );
}
