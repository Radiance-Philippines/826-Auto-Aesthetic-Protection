import { type FormEvent, useState } from 'react';

import { api, errorMessage } from '../lib/api';
import { useResource } from '../lib/useResource';
import { Button } from '../ui/Button';
import { PageHeader } from '../ui/PageHeader';
import type { Option } from '../ui/Select';
import { EmptyState, ErrorState, Loading } from '../ui/states';
import { toast } from '../ui/toast';
import { CheckboxListField, Section } from './bookings/parts';

type Group = { category: string; services: Option[] };

type Offer = { id: number; name: string; services: number[] };

function BranchOffer({ branch, groups }: { branch: Offer; groups: Group[] }) {
    const [chosen, setChosen] = useState(() => branch.services.map(String));
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState<string | null>(null);

    const save = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setFailed(null);

        // Only what is on screen: hidden services keep their link on the server
        const shown = new Set(groups.flatMap((group) => group.services.map((service) => service.value)));

        try {
            await api('POST', `/branch-services/${branch.id}`, { services: chosen.filter((id) => shown.has(id)).map(Number) });
            toast.success(`${branch.name}: services saved`);
        } catch (failure) {
            setFailed(errorMessage(failure));
        }

        setBusy(false);
    };

    return (
        <Section title={branch.name}>
            <form onSubmit={save} className="space-y-4">
                {failed && (
                    <p role="alert" className="m-0 text-sm text-destructive">
                        {failed}
                    </p>
                )}
                {groups.map((group) => {
                    const ids = group.services.map((service) => service.value);
                    const ticked = chosen.filter((id) => ids.includes(id));

                    return (
                        <CheckboxListField
                            key={group.category}
                            label={group.category}
                            hint={`${ticked.length} of ${ids.length} offered here.`}
                            columns
                            options={group.services}
                            value={ticked}
                            onChange={(next) => setChosen((current) => [...current.filter((id) => !ids.includes(id)), ...next])}
                        />
                    );
                })}
                {groups.length === 0 ? (
                    <p className="m-0 text-sm text-muted-foreground">No services yet. The super admin adds them in Shop → Services.</p>
                ) : (
                    <Button type="submit" icon="check" variant="primary" busy={busy}>
                        Save services
                    </Button>
                )}
            </form>
        </Section>
    );
}

export default function BranchServices() {
    const { data, error, reload } = useResource<{ branches: Offer[]; groups: Group[] }>('/branch-services');

    return (
        <>
            <PageHeader title="Services" description="Tick what the branch offers. Only ticked services can be booked there. Names, prices and details are set by the super admin." />

            {error && <ErrorState message={error} onRetry={reload} />}

            {!data ? (
                !error && <Loading rows={4} />
            ) : data.branches.length === 0 ? (
                <EmptyState icon="store" title="You’re not assigned to a branch yet">
                    Ask the super admin to add you to one.
                </EmptyState>
            ) : (
                data.branches.map((branch) => <BranchOffer key={branch.id} branch={branch} groups={data.groups} />)
            )}
        </>
    );
}
