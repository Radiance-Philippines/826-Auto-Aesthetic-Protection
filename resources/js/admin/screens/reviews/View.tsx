import { type FormEvent, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';

import { api, errorMessage } from '../../lib/api';
import { useMe } from '../../lib/me';
import { useResource } from '../../lib/useResource';
import { Button, buttonClass } from '../../ui/Button';
import { confirm } from '../../ui/Dialog';
import { TextField } from '../../ui/Field';
import { Icon } from '../../ui/icons';
import { PageHeader } from '../../ui/PageHeader';
import { SelectField } from '../../ui/Select';
import { ErrorState, Loading } from '../../ui/states';
import { TextAreaField } from '../../ui/TextArea';
import { toast } from '../../ui/toast';
import { Alert, Details, Entry, Section, Tone, useSubmit } from '../bookings/parts';
import { type Review, Stars, tones, useReviewActions } from './actions';

type Full = Review & { body: string; customer: string | null; adminNote: string | null; sentAt: string | null };

const STATUSES = [
    { value: 'pending', label: 'Waiting for approval' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Not shown' },
];

const back = (
    <Link to="/reviews" className={buttonClass()}>
        <Icon name="arrow-left" />
        Reviews
    </Link>
);

export function ReviewView() {
    const { id } = useParams();
    const { data, error, reload } = useResource<Full>(`/reviews/${id}`);
    const actions = useReviewActions(reload);
    const review = data && String(data.id) === id ? data : null;

    return (
        <>
            <PageHeader
                title={review ? `View ${review.displayName}` : 'View review'}
                actions={
                    <>
                        {back}
                        {review?.canUpdate && (
                            <>
                                {review.status !== 'approved' && (
                                    <Button icon="circle-check" variant="primary" onClick={() => void actions.approve(review)}>
                                        Approve
                                    </Button>
                                )}
                                {review.status !== 'rejected' && (
                                    <Button icon="circle-x" variant="destructive" onClick={() => actions.reject(review)}>
                                        Reject
                                    </Button>
                                )}
                                {review.status === 'approved' && (
                                    <Button icon="star" onClick={() => void actions.feature(review)}>
                                        {review.featured ? 'Unfeature' : 'Feature'}
                                    </Button>
                                )}
                                <Link to={`/reviews/${review.id}/edit`} className={buttonClass()}>
                                    <Icon name="pencil" />
                                    Edit
                                </Link>
                            </>
                        )}
                    </>
                }
            />

            {error && <ErrorState message={error} onRetry={reload} />}

            {!review ? (
                !error && <Loading rows={4} />
            ) : (
                <div className="grid items-start gap-4 lg:grid-cols-3">
                    <Section title="Review" className="min-w-0 lg:col-span-2">
                        <Details>
                            <Entry label="Rating" wide>
                                <Stars review={review} /> <span aria-hidden="true">{review.rating} out of 5</span>
                            </Entry>
                            <Entry label="What they wrote" wide>
                                <span className="whitespace-pre-line">{review.body}</span>
                            </Entry>
                            <Entry label="Photo" wide>
                                {review.photoUrl ? <img src={review.photoUrl} alt="The photo they sent" className="max-h-40 rounded-md border border-border" /> : 'No photo'}
                            </Entry>
                        </Details>
                    </Section>
                    <Section title="Details" className="min-w-0">
                        <Details>
                            <Entry label="Shown as">{review.displayName}</Entry>
                            <Entry label="Customer">{review.customer ?? '—'}</Entry>
                            <Entry label="Booking">{review.reference ?? '—'}</Entry>
                            <Entry label="Branch">{review.branch ?? '—'}</Entry>
                            <Entry label="Status">
                                <Tone tone={tones[review.status]}>{review.statusLabel}</Tone>
                            </Entry>
                            <Entry label="Reason (team only)">{review.adminNote ?? '—'}</Entry>
                            <Entry label="Sent">{review.sentAt ?? '—'}</Entry>
                        </Details>
                    </Section>
                </div>
            )}

            {actions.dialog}
        </>
    );
}

function Fields({ review }: { review: Full }) {
    const navigate = useNavigate();
    const { reload } = useMe();
    const [body, setBody] = useState(review.body);
    const [displayName, setName] = useState(review.displayName);
    const [status, setStatus] = useState<string>(review.status);
    const { errors, failed, busy, submit } = useSubmit();

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();

        void submit(async () => {
            await api('PATCH', `/reviews/${review.id}`, { body, displayName, status });
            toast.success('Saved');
            reload();
            navigate('/reviews');
        });
    };

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-4">
            {failed && <Alert title="Not saved">{failed}</Alert>}
            <div className="grid items-start gap-4 lg:grid-cols-3">
                <Section title="Review" className="min-w-0 lg:col-span-2">
                    <TextAreaField label="What they wrote" hint="Fix typos only: this is shown as the customer’s own words." error={errors.body} value={body} onValueChange={setBody} rows={6} required maxLength={1000} />
                </Section>
                <Section title="Shown as" className="min-w-0">
                    <div className="space-y-4">
                        <TextField label="Name" hint="The name under the review on the site." error={errors.displayName} value={displayName} onValueChange={setName} required maxLength={80} autoComplete="off" />
                        <SelectField label="Status" hint="Only approved reviews reach the site." error={errors.status} options={STATUSES} value={status} onChange={setStatus} />
                    </div>
                </Section>
            </div>
            <div className="flex flex-wrap gap-2">
                <Button type="submit" icon="save" variant="primary" busy={busy}>
                    Save changes
                </Button>
                <Link to="/reviews" className={buttonClass()}>
                    <Icon name="x" />
                    Cancel
                </Link>
            </div>
        </form>
    );
}

export function ReviewEdit() {
    const { id } = useParams();
    const navigate = useNavigate();
    const { reload: reloadMe } = useMe();
    const { data, error, reload } = useResource<Full>(`/reviews/${id}`);
    const review = data && String(data.id) === id ? data : null;

    const remove = async () => {
        if (!(await confirm({ title: `Delete ${review!.displayName}`, description: 'Are you sure you would like to do this?', label: 'Delete', icon: 'trash-2', destructive: true }))) {
            return;
        }

        try {
            await api('DELETE', `/reviews/${id}`);
            toast.success('Deleted');
            reloadMe();
            navigate('/reviews');
        } catch (failure) {
            toast.error(errorMessage(failure));
        }
    };

    return (
        <>
            <PageHeader
                title={review ? `Edit ${review.displayName}` : 'Edit review'}
                actions={
                    <>
                        {back}
                        {review?.canDelete && (
                            <Button icon="trash-2" variant="destructive" onClick={remove}>
                                Delete
                            </Button>
                        )}
                    </>
                }
            />
            {error && <ErrorState message={error} onRetry={reload} />}
            {!review ? (
                !error && <Loading rows={4} />
            ) : review.canUpdate ? (
                <Fields key={review.id} review={review} />
            ) : (
                <ErrorState message="Your role doesn’t include this." onRetry={reload} />
            )}
        </>
    );
}
