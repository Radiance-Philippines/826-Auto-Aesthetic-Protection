import { FileInput, FileInputDropzone, FileInputItem, FileInputItemRemove, FileInputList, FileInputTrigger, type FileRejection, formatFileSize } from '@ui-sdk/react/file-input';
import { useEffect, useState } from 'react';

import { Button, buttonClass } from './Button';
import { type FieldProps, FieldShell } from './Field';
import { Icon } from './icons';

/** What the admin takes as a picture (App\Support\UploadRules) */
export const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/** The long edge a photo is shrunk to before it is sent (ImagePipeline::MASTER_MAX_EDGE) */
export const MASTER_EDGE = 2400;

/** The picture no bigger than `maxEdge` on its long side, so camera photos upload in seconds. Never upscaled. */
export async function shrinkImage(file: File, maxEdge = MASTER_EDGE): Promise<File> {
    try {
        // Applies the camera's rotation, which the redrawn copy no longer carries
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = maxEdge / Math.max(bitmap.width, bitmap.height);

        if (scale >= 1) {
            bitmap.close();

            return file;
        }

        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext('2d')!.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();
        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, file.type, 0.92));

        return blob ? new File([blob], file.name, { type: blob.type }) : file;
    } catch {
        // Not a picture the browser can read: the server says so
        return file;
    }
}

/** Laravel's nested names, for a form that carries files: {a: {b: [true]}} is a[b][0]=1, an empty list is a="" */
export function toFormData(values: Record<string, unknown>): FormData {
    const form = new FormData();

    const add = (key: string, value: unknown) => {
        if (value === undefined) {
            return;
        }

        if (value instanceof Blob) {
            form.append(key, value);
        } else if (value !== null && typeof value === 'object') {
            const entries = Object.entries(value);

            if (entries.length === 0) {
                form.append(key, '');
            }

            entries.forEach(([name, each]) => add(`${key}[${name}]`, each));
        } else {
            form.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : value === null ? '' : String(value));
        }
    };

    Object.entries(values).forEach(([key, value]) => add(key, value));

    return form;
}

function Preview({ file }: { file: File }) {
    const [url, setUrl] = useState<string>();

    useEffect(() => {
        const next = URL.createObjectURL(file);
        setUrl(next);

        return () => URL.revokeObjectURL(next);
    }, [file]);

    return url ? <img src={url} alt="" className="size-14 shrink-0 rounded-md border border-border object-cover" /> : null;
}

type Props = FieldProps & {
    value: File[];
    onChange: (files: File[]) => void;
    maxMb: number;
    /** Above 1, the files add up to this many */
    maxFiles?: number;
    accept?: string[];
    /** The saved picture, shown until a new one is chosen */
    current?: string | null;
    onRemoveCurrent?: () => void;
};

/** Choose, drop or paste pictures. Nothing is sent until the form is. */
export function FileUpload({ value, onChange, maxMb, maxFiles = 1, accept = IMAGE_TYPES, current, onRemoveCurrent, ...field }: Props) {
    const [rejected, setRejected] = useState<string | null>(null);
    const many = maxFiles > 1;
    const name = field.label.toLowerCase();

    const explain = (files: FileRejection[]) => {
        const first = files[0];

        setRejected(
            !first
                ? null
                : first.reasons.includes('too-many-files')
                  ? `Only ${maxFiles} at a time: the rest were left out.`
                  : first.reasons.includes('file-too-large')
                    ? `${first.file.name} is over ${maxMb} MB.`
                    : `${first.file.name} is not a JPG, PNG or WebP picture.`,
        );
    };

    return (
        <FieldShell {...field} error={rejected ?? field.error}>
            <FileInput
                value={value}
                onValueChange={(files) => {
                    setRejected(null);
                    onChange(files);
                }}
                onReject={explain}
                accept={accept}
                maxSize={maxMb * 1024 * 1024}
                maxFiles={many ? maxFiles : undefined}
                multiple={many}
                className="flex min-w-0 flex-col items-stretch gap-2"
            >
                {current && value.length === 0 && (
                    <div className="flex flex-wrap items-center gap-3">
                        <img src={current} alt={`Saved ${name}`} className="max-h-40 max-w-full rounded-md border border-border bg-muted object-contain" />
                        {onRemoveCurrent && (
                            <Button type="button" icon="trash-2" size="sm" variant="destructive" aria-label={`Remove ${name}`} onClick={onRemoveCurrent}>
                                Remove
                            </Button>
                        )}
                    </div>
                )}
                <FileInputDropzone className="flex flex-col items-center gap-2 rounded-md border border-dashed border-input bg-card p-4 text-center data-dragging:border-primary data-dragging:bg-selected">
                    <p className="m-0 text-sm text-muted-foreground">Drop {many ? 'pictures' : 'a picture'} here, or</p>
                    <FileInputTrigger className={buttonClass('outline', 'sm')}>
                        <Icon name="upload" />
                        {many ? 'Choose pictures' : current || value.length ? 'Replace picture' : 'Choose a picture'}
                    </FileInputTrigger>
                </FileInputDropzone>
                <FileInputList aria-label={`Chosen: ${name}`} className="m-0 grid list-none gap-2 p-0 empty:hidden">
                    <FileInputItem className="flex min-w-0 items-center gap-3 rounded-md border border-border p-2">
                        {({ file }: { file: File }) => (
                            <>
                                <Preview file={file} />
                                <span className="min-w-0 flex-1 truncate text-sm">{file.name}</span>
                                <span className="text-xs whitespace-nowrap text-muted-foreground">{formatFileSize(file.size)}</span>
                                <FileInputItemRemove className={buttonClass('ghost', 'sm', true)}>
                                    <Icon name="x" />
                                </FileInputItemRemove>
                            </>
                        )}
                    </FileInputItem>
                </FileInputList>
            </FileInput>
        </FieldShell>
    );
}
