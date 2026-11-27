import { type FormEvent, useState } from 'react';

import { api } from '../../lib/api';
import { Button } from '../../ui/Button';
import { Modal, ModalActions } from '../../ui/Dialog';
import { TextField } from '../../ui/Field';
import { type Option, SelectField } from '../../ui/Select';
import { TextAreaField } from '../../ui/TextArea';
import { Alert, useSubmit } from '../bookings/parts';

type Props = { branches: Option[]; branch: string | null; onClose: () => void; onStarted: (id: number) => void };

export function NewThread({ branches, branch: start, onClose, onStarted }: Props) {
    const [branch, setBranch] = useState(start);
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [booking, setBooking] = useState('');
    const { errors, failed, busy, submit } = useSubmit();

    const save = (event: FormEvent) => {
        event.preventDefault();
        void submit(async () => {
            const made = await api<{ conversation: { id: number } }>('POST', '/inbox', { branch: branch ? Number(branch) : null, subject, body, booking: booking || null });
            onStarted(made.conversation.id);
        });
    };

    // A refusal about the whole thread (like a booking reference that isn't there) comes back under "body"
    return (
        <Modal open onClose={onClose} title="New team thread" description="Only your branch’s team sees this. It is never sent to a customer or to Messenger.">
            <form onSubmit={save} noValidate className="space-y-4">
                {(failed || errors.body) && <Alert title="Thread not started">{failed ?? errors.body}</Alert>}
                <SelectField label="Branch" hint="Whose team sees it." error={errors.branch} options={branches} value={branch} onChange={setBranch} />
                <TextField label="Subject" hint="What the thread is about." error={errors.subject} value={subject} onValueChange={setSubject} required maxLength={120} autoComplete="off" />
                <TextAreaField label="Message" hint="The first message." error={errors.body} value={body} onValueChange={setBody} required rows={5} maxLength={2000} />
                <TextField label="Booking reference (optional)" hint="Links the thread to a booking of that branch, like 826-2YD8F." error={errors.booking} value={booking} onValueChange={setBooking} maxLength={40} autoComplete="off" />
                <ModalActions onCancel={onClose}>
                    <Button type="submit" icon="arrow-right-to-line" variant="primary" busy={busy}>
                        Start thread
                    </Button>
                </ModalActions>
            </form>
        </Modal>
    );
}
