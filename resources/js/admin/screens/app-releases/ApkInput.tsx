import { FileInput, FileInputDropzone, FileInputItem, FileInputItemRemove, FileInputList, FileInputTrigger, formatFileSize } from '@ui-sdk/react/file-input';
import { useState } from 'react';

import { buttonClass } from '../../ui/Button';
import { type FieldProps, FieldShell } from '../../ui/Field';
import { Icon } from '../../ui/icons';

const MAX_MB = 300;

/** One .apk: chosen or dropped; sent with the form */
export function ApkInput({ value, onChange, replacing, ...field }: FieldProps & { value: File[]; onChange: (files: File[]) => void; replacing: boolean }) {
    const [rejected, setRejected] = useState<string | null>(null);

    return (
        <FieldShell {...field} error={rejected ?? field.error}>
            <FileInput
                value={value}
                onValueChange={(files) => {
                    setRejected(null);
                    onChange(files);
                }}
                onReject={(files) => setRejected(files[0] ? (files[0].reasons.includes('file-too-large') ? `${files[0].file.name} is over ${MAX_MB} MB.` : `${files[0].file.name} is not an APK file.`) : null)}
                accept=".apk"
                maxSize={MAX_MB * 1024 * 1024}
                className="flex min-w-0 flex-col items-stretch gap-2"
            >
                <FileInputDropzone className="flex flex-col items-center gap-2 rounded-md border border-dashed border-input bg-card p-4 text-center data-dragging:border-primary data-dragging:bg-selected">
                    <p className="m-0 text-sm text-muted-foreground">Drop the APK here, or</p>
                    <FileInputTrigger className={buttonClass('outline', 'sm')}>
                        <Icon name="upload" />
                        {replacing || value.length ? 'Replace the APK' : 'Choose the APK'}
                    </FileInputTrigger>
                </FileInputDropzone>
                <FileInputList aria-label="Chosen file" className="m-0 grid list-none gap-2 p-0 empty:hidden">
                    <FileInputItem className="flex min-w-0 items-center gap-3 rounded-md border border-border p-2">
                        {({ file }: { file: File }) => (
                            <>
                                <Icon name="smartphone" />
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
