import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';

import { api, errorMessage } from '../../lib/api';
import { useResource } from '../../lib/useResource';
import { Button } from '../../ui/Button';
import { type Column, DataTable } from '../../ui/DataTable';
import { confirm } from '../../ui/Dialog';
import { ActionMenu } from '../../ui/Menu';
import { PageHeader } from '../../ui/PageHeader';
import { EmptyState, ErrorState, Loading } from '../../ui/states';
import { TabBar } from '../../ui/Tabs';
import { toast } from '../../ui/toast';
import { Tick, Tone, useListParams } from '../bookings/parts';
import { type Review, Stars, tones, useReviewActions } from './actions';

type Page = { data: Review[]; meta: { total: number; perPage: number }; pending: number; canDeleteAny: boolean };

const TABS = [
    { value: 'pending', label: 'Pending', icon: 'clock' as const },
    { value: 'approved', label: 'Approved', icon: 'circle-check' as const },
    { value: 'rejected', label: 'Rejected', icon: 'circle-x' as const },
    { value: 'all', label: 'All', icon: 'message-square-text' as const },
];

export function ReviewList() {
    const { params, query, go, state, onStateChange } = useListParams();
    const tab = TABS.some((each) => each.value === params.get('activeTab')) ? params.get('activeTab')! : 'pending';
    const { data, error, loading, reload } = useResource<Page>(`/reviews?${query}`);
    const [selected, setSelected] = useState<number[]>([]);
    const navigate = useNavigate();
    const actions = useReviewActions(reload);

    const rows = data?.data ?? [];
    const chosen = selected.filter((id) => rows.some((review) => review.id === id));

    const removeChosen = async () => {
        if (!(await confirm({ title: 'Delete selected reviews', description: 'Are you sure you would like to do this?', label: 'Delete', icon: 'trash-2', destructive: true }))) {
            return;
        }

        try {
            await api('POST', '/reviews/delete', { ids: chosen });
            toast.success('Deleted');
            setSelected([]);
        } catch (failure) {
            toast.error(errorMessage(failure));
        }

        actions.after();
    };

    const columns: Column<Review>[] = [
        {
            id: 'customer',
            header: 'Customer',
            primary: true,
            cell: (review) => (
                <span className="flex items-center gap-3">
                    {data?.canDeleteAny && (
                        <Tick label={`Select ${review.displayName}`} checked={selected.includes(review.id)} onChange={(on) => setSelected((current) => (on ? [...current, review.id] : current.filter((id) => id !== review.id)))} />
                    )}
                    {review.photoUrl && <img src={review.photoUrl} alt="" loading="lazy" className="size-12 shrink-0 rounded-md bg-muted object-cover" />}
                    <span className="min-w-0">
                        <span className="block break-words">{review.displayName}</span>
                        <span className="block text-xs font-normal text-muted-foreground">{review.reference}</span>
                    </span>
                </span>
            ),
        },
        { id: 'branch', header: 'Branch', cell: (review) => review.branch ?? '—' },
        { id: 'service', header: 'Service', cell: (review) => review.service ?? '—' },
        { id: 'rating', header: 'Rating', sortValue: (review) => review.rating, cell: (review) => <Stars review={review} /> },
        { id: 'body', header: 'Review', cell: (review) => <span className="inline-block max-w-md break-words">{review.excerpt}</span> },
        {
            id: 'status',
            header: 'Status',
            cell: (review) => (
                <span className="inline-flex flex-wrap gap-1">
                    <Tone tone={tones[review.status]}>{review.statusLabel}</Tone>
                    {review.featured && <Tone tone="info">Featured</Tone>}
                </span>
            ),
        },
    ];

    return (
        <>
            <PageHeader title="Reviews" description="What customers wrote about a finished booking. Only approved reviews reach the site." />

            <TabBar
                label="Reviews by status"
                tabs={TABS.map((each) => (each.value === 'pending' ? { ...each, badge: data?.pending } : each))}
                value={tab}
                onChange={(value) => {
                    setSelected([]);
                    go({ activeTab: value === 'pending' ? null : value });
                }}
            >
                {error && <ErrorState message={error} onRetry={reload} />}

                {!data ? (
                    !error && <Loading rows={6} />
                ) : (
                    <div className="space-y-3" aria-busy={loading || undefined}>
                        {chosen.length > 0 && (
                            <div className="flex flex-wrap items-center gap-3">
                                <span role="status" className="text-sm font-medium">
                                    {chosen.length} selected
                                </span>
                                <Button icon="trash-2" size="sm" variant="destructive" onClick={() => void removeChosen()}>
                                    Delete selected
                                </Button>
                            </div>
                        )}
                        <DataTable
                            caption="Reviews"
                            columns={columns}
                            rows={rows}
                            rowId={(review) => String(review.id)}
                            rowName={(review) => review.displayName}
                            state={state}
                            onStateChange={onStateChange}
                            total={data.meta.total}
                            pageSize={data.meta.perPage}
                            searchHint="By the name shown on the review."
                            actions={(review) => (
                                <>
                                    {review.canUpdate && review.status !== 'approved' && (
                                        <Button icon="circle-check" size="sm" variant="primary" aria-label={`Approve ${review.displayName}`} onClick={() => void actions.approve(review)}>
                                            Approve
                                        </Button>
                                    )}
                                    {review.canUpdate && review.status !== 'rejected' && (
                                        <Button icon="circle-x" size="sm" variant="destructive" aria-label={`Reject ${review.displayName}`} onClick={() => actions.reject(review)}>
                                            Reject
                                        </Button>
                                    )}
                                    <ActionMenu
                                        label={`More actions for ${review.displayName}`}
                                        actions={[
                                            ...(review.canUpdate && review.status === 'approved' ? [{ id: 'feature', label: review.featured ? 'Unfeature' : 'Feature', icon: 'star' as const, onSelect: () => void actions.feature(review) }] : []),
                                            { id: 'view', label: 'View', icon: 'eye' as const, onSelect: () => void navigate(`/reviews/${review.id}`) },
                                            ...(review.canUpdate ? [{ id: 'edit', label: 'Edit', icon: 'pencil' as const, onSelect: () => void navigate(`/reviews/${review.id}/edit`) }] : []),
                                        ]}
                                    />
                                </>
                            )}
                            empty={
                                <EmptyState icon="message-square-text" title="No reviews">
                                    {tab === 'pending' ? 'Nothing is waiting for approval.' : undefined}
                                </EmptyState>
                            }
                        />
                    </div>
                )}
            </TabBar>

            {actions.dialog}
        </>
    );
}
