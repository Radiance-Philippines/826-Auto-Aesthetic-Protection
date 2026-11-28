import { type FormEvent, useState } from 'react';

import { api, errorMessage, fieldErrors } from '../../lib/api';
import { Button } from '../../ui/Button';
import { ModalActions } from '../../ui/Dialog';
import { SwitchField } from '../../ui/Field';
import { FileUpload, shrinkImage, toFormData } from '../../ui/FileUpload';
import { type Option, SelectField } from '../../ui/Select';
import { toast } from '../../ui/toast';
import { Alert } from '../bookings/parts';

/** ListPhotos::MAX_BATCH */
const MAX_BATCH = 30;

/**
 * A batch goes one picture at a time, in the order chosen, so each lands at
 * the end of the list and a refused one doesn't stop the rest.
 */
export function Upload({ categories, onAdded, onDone, onCancel }: { categories: Option[]; onAdded: () => void; onDone: () => void; onCancel: () => void }) {
    const [files, setFiles] = useState<File[]>([]);
    const [category, setCategory] = useState<string | null>(null);
    const [isFeatured, setFeatured] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [failed, setFailed] = useState<string | null>(null);
    const [sending, setSending] = useState<{ at: number; of: number } | null>(null);

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        const missing = { ...(files.length ? {} : { image: 'Choose at least one picture.' }), ...(category ? {} : { category: 'Pick a category.' }) };
        setErrors(missing);
        setFailed(null);

        if (Object.keys(missing).length) {
            return;
        }

        const refused: { file: File; why: string }[] = [];

        for (const [index, file] of files.entries()) {
            setSending({ at: index + 1, of: files.length });

            try {
                await api('POST', '/photos', toFormData({ image: await shrinkImage(file), category, isFeatured }));
            } catch (failure) {
                const fields = fieldErrors(failure);
                refused.push({ file, why: fields.image ?? fields.category ?? errorMessage(failure) });
            }
        }

        const added = files.length - refused.length;
        setSending(null);

        if (added) {
            toast.success(`${added} ${added === 1 ? 'photo' : 'photos'} added`);
            onAdded();
        }

        if (!refused.length) {
            onDone();

            return;
        }

        // Only the refused ones stay, ready to send again
        setFiles(refused.map((each) => each.file));
        setFailed(refused.map((each) => `${each.file.name}: ${each.why}`).join(' '));
    };

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-4">
            {failed && <Alert title="Some photos were not added">{failed}</Alert>}
            <FileUpload
                label="Photos"
                hint={`Up to ${MAX_BATCH} at a time, JPG, PNG or WebP, up to 20 MB each. They are added at the end of the list.`}
                error={errors.image}
                value={files}
                onChange={setFiles}
                maxMb={20}
                maxFiles={MAX_BATCH}
            />
            <SelectField label="Category" hint="Where they show in the gallery." error={errors.category} options={categories} value={category} onChange={setCategory} />
            <SwitchField label="Featured on Home" hint="Featured photos appear on the Home page." checked={isFeatured} onChange={setFeatured} />
            <p role="status" className="m-0 text-sm text-muted-foreground empty:hidden">
                {sending ? `Uploading ${sending.at} of ${sending.of}…` : ''}
            </p>
            <ModalActions onCancel={onCancel}>
                <Button type="submit" icon="upload" variant="primary" busy={sending !== null}>
                    Add photos
                </Button>
            </ModalActions>
        </form>
    );
}
