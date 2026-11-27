import { type FormEvent, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';

import { api, errorMessage } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { Button, buttonClass } from '../../ui/Button';
import { confirm } from '../../ui/Dialog';
import { SwitchField, TextField } from '../../ui/Field';
import { FileUpload, shrinkImage, toFormData } from '../../ui/FileUpload';
import { Icon } from '../../ui/icons';
import { PageHeader } from '../../ui/PageHeader';
import { type Option, SelectField } from '../../ui/Select';
import { ErrorState, Loading } from '../../ui/states';
import { toast } from '../../ui/toast';
import { Alert, Section, useSubmit } from '../bookings/parts';

type Photo = { id: number; imageUrl: string; caption: string | null; category: string; isFeatured: boolean; categories: Option[] };

function Fields({ photo }: { photo: Photo }) {
    const navigate = useNavigate();
    const [picture, setPicture] = useState<File[]>([]);
    const [caption, setCaption] = useState(photo.caption ?? '');
    const [category, setCategory] = useState(photo.category);
    const [isFeatured, setFeatured] = useState(photo.isFeatured);
    const { errors, failed, busy, submit } = useSubmit();

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();

        void submit(async () => {
            const image = picture[0] ? await shrinkImage(picture[0]) : undefined;
            const body = { caption, category, isFeatured, image };
            await api('POST', `/photos/${photo.id}`, image ? toFormData(body) : body);
            toast.success('Saved');
            navigate('/photos');
        });
    };

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-4">
            {failed && <Alert title="Not saved">{failed}</Alert>}
            <div className="grid items-start gap-4 lg:grid-cols-3">
                <Section title="Photo" className="min-w-0 lg:col-span-2">
                    <FileUpload label="Picture" hint="JPG, PNG or WebP, up to 20 MB. A new one replaces this." error={errors.image} value={picture} onChange={setPicture} maxMb={20} current={photo.imageUrl} />
                </Section>
                <Section title="Details" className="min-w-0">
                    <div className="space-y-4">
                        <TextField label="Caption" hint="Optional. Describes the work." error={errors.caption} value={caption} onValueChange={setCaption} maxLength={200} autoComplete="off" />
                        <SelectField label="Category" hint="Where it shows in the gallery." error={errors.category} options={photo.categories} value={category} onChange={setCategory} />
                        <SwitchField label="Featured on Home" hint="Featured photos appear on the Home page." checked={isFeatured} onChange={setFeatured} />
                    </div>
                </Section>
            </div>
            <div className="flex flex-wrap gap-2">
                <Button type="submit" icon="save" variant="primary" busy={busy}>
                    Save changes
                </Button>
                <Link to="/photos" className={buttonClass()}>
                    <Icon name="x" />
                    Cancel
                </Link>
            </div>
        </form>
    );
}

export function PhotoEdit() {
    const { id } = useParams();
    const navigate = useNavigate();
    const { data, error, reload } = useResource<Photo>(`/photos/${id}`);

    const remove = async () => {
        if (!(await confirm({ title: 'Delete photo', description: 'Are you sure you would like to do this?', label: 'Delete', icon: 'trash-2', destructive: true }))) {
            return;
        }

        try {
            await api('DELETE', `/photos/${id}`);
            toast.success('Deleted');
            navigate('/photos');
        } catch (failure) {
            toast.error(errorMessage(failure));
        }
    };

    return (
        <>
            <PageHeader
                title="Edit photo"
                actions={
                    <>
                        <Link to="/photos" className={buttonClass()}>
                            <Icon name="arrow-left" />
                            Photos
                        </Link>
                        {data && (
                            <Button icon="trash-2" variant="destructive" onClick={remove}>
                                Delete
                            </Button>
                        )}
                    </>
                }
            />
            {error && <ErrorState message={error} onRetry={reload} />}
            {data ? <Fields key={data.id} photo={data} /> : !error && <Loading rows={4} />}
        </>
    );
}
