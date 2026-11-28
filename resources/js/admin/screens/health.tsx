import { useResource } from '../lib/useResource';
import { Button } from '../ui/Button';
import { Icon } from '../ui/icons';
import { PageHeader } from '../ui/PageHeader';
import { ErrorState, Loading } from '../ui/states';
import { Tone } from './bookings/parts';

type Health = { version: string | null; checks: { label: string; on: boolean }[] };

const row = 'flex items-center justify-between gap-4 border-b border-border px-4 py-3 last:border-b-0';

export default function SystemHealth() {
    const { data, error, loading, reload } = useResource<Health>('/health');

    return (
        <>
            <PageHeader
                title="System health"
                actions={
                    <Button icon="refresh-cw" busy={loading && data !== null} onClick={reload}>
                        Check again
                    </Button>
                }
            />

            {error && <ErrorState message={error} onRetry={reload} />}

            {!data ? (
                !error && <Loading rows={5} />
            ) : (
                <dl className="m-0 max-w-2xl overflow-hidden rounded-xl border border-border bg-card">
                    <div className={row}>
                        <dt>Customer app version</dt>
                        <dd className="m-0 font-semibold">{data.version ?? 'None published'}</dd>
                    </div>
                    {data.checks.map((check) => (
                        <div key={check.label} className={row}>
                            <dt>{check.label}</dt>
                            <dd className="m-0">
                                <Tone tone={check.on ? 'success' : 'gray'}>
                                    <Icon name={check.on ? 'circle-check' : 'circle-minus'} className="me-1 size-3.5" />
                                    {check.on ? 'Yes' : 'No'}
                                </Tone>
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
        </>
    );
}
