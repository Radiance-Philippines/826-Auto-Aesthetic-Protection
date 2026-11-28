/* Approve, reject, feature and delete, shared by the list and the view page */
import { type FormEvent, useState } from 'react';

import { api, errorMessage } from '../../lib/api';
import { useMe } from '../../lib/me';
import { Button } from '../../ui/Button';
import { confirm, Modal, ModalActions } from '../../ui/Dialog';
import { TextAreaField } from '../../ui/TextArea';
import { toast } from '../../ui/toast';
import { Alert, useSubmit } from '../bookings/parts';

export type Review = {
    id: number;
    displayName: string;
    reference: string | null;
    branch: string | null;
    service: string | null;
    rating: number;
    stars: string;
    excerpt: string;
    photoUrl: string | null;
    status: 'pending' | 'approved' | 'rejected';
    statusLabel: string;
    featured: boolean;
    canUpdate: boolean;
    canDelete: boolean;
};

export const tones: Record<Review['status'], string> = { pending: 'warning', approved: 'success', rejected: 'danger' };

export function Stars({ review }: { review: Review }) {
    return (
        <span className="whitespace-nowrap">
            <span aria-hidden="true" className="text-gold">
                {review.stars}
            </span>
            <span className="sr-only">{review.rating} out of 5</span>
        </span>
    );
}

function RejectForm({ review, onDone, onCancel }: { review: Review; onDone: () => void; onCancel: () => void }) {
    const [adminNote, setNote] = useState('');
    const { errors, failed, busy, submit } = useSubmit();

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();

        void submit(async () => {
            await api('POST', `/reviews/${review.id}/reject`, { adminNote });
            toast.success('Not shown');
            onDone();
        });
    };

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-4">
            {failed && <Alert title="Not saved">{failed}</Alert>}
            <TextAreaField label="Reason" hint="For the team only. The customer doesn’t see it." error={errors.adminNote} value={adminNote} onValueChange={setNote} rows={3} maxLength={250} />
            <ModalActions onCancel={onCancel}>
                <Button type="submit" icon="circle-x" variant="destructive" busy={busy}>
                    Reject
                </Button>
            </ModalActions>
        </form>
    );
}

/** `changed` reloads what is on screen; the sidebar's "waiting" badge follows. */
export function useReviewActions(changed: () => void) {
    const { reload } = useMe();
    const [rejecting, setRejecting] = useState<Review | null>(null);

    const after = () => {
        changed();
        reload();
    };

    const run = async (send: () => Promise<unknown>, done: string) => {
        try {
            await send();
            toast.success(done);
        } catch (failure) {
            toast.error(errorMessage(failure));
        }

        after();
    };

    const approve = async (review: Review) => {
        if (await confirm({ title: 'Show this review on the site?', description: 'It appears on the Home and Our work pages right away.', label: 'Confirm', icon: 'check' })) {
            await run(() => api('POST', `/reviews/${review.id}/approve`), 'Approved');
        }
    };

    const feature = (review: Review) => run(() => api('POST', `/reviews/${review.id}/feature`), review.featured ? 'Unfeatured' : 'Featured');

    const dialog = (
        <Modal open={rejecting !== null} onClose={() => setRejecting(null)} title="Don’t show this review?" description="The customer still sees it on their booking, marked as not shown.">
            {rejecting && (
                <RejectForm
                    review={rejecting}
                    onCancel={() => setRejecting(null)}
                    onDone={() => {
                        setRejecting(null);
                        after();
                    }}
                />
            )}
        </Modal>
    );

    return { approve, feature, reject: setRejecting, dialog, after };
}
