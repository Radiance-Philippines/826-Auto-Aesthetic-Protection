/* One GCash or Maya account, on a branch and in Settings → Payments */
import { SwitchField, TextField } from '../../ui/Field';
import { FileUpload } from '../../ui/FileUpload';

export type Account = { enabled: boolean; name: string | null; number: string | null };

type Props = {
    label: string;
    toggle: { label: string; hint: string };
    qrHint: string;
    account: Account;
    onChange: (changes: Partial<Account>) => void;
    /** A newly chosen QR, not yet sent */
    files: File[];
    onFiles: (files: File[]) => void;
    /** The saved QR; null once removed */
    qrUrl: string | null | undefined;
    onRemoveQr: () => void;
    errors: { qr?: string; name?: string; number?: string };
};

export function AccountFields({ label, toggle, qrHint, account, onChange, files, onFiles, qrUrl, onRemoveQr, errors }: Props) {
    return (
        <div className="space-y-4">
            <SwitchField label={toggle.label} hint={toggle.hint} checked={account.enabled} onChange={(enabled) => onChange({ enabled })} />
            <FileUpload label={`${label} QR code`} hint={qrHint} error={errors.qr} value={files} onChange={onFiles} maxMb={5} current={qrUrl} onRemoveCurrent={onRemoveQr} />
            <TextField label={`${label} account name`} hint="As the customer sees it before paying." error={errors.name} value={account.name ?? ''} onValueChange={(name) => onChange({ name })} maxLength={120} autoComplete="off" />
            <TextField label={`${label} number`} hint="Like 0917 123 4567." type="tel" error={errors.number} value={account.number ?? ''} onValueChange={(number) => onChange({ number })} maxLength={40} autoComplete="off" />
        </div>
    );
}
