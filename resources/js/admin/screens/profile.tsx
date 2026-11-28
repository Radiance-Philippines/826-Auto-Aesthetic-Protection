import { useState } from 'react';

import { useResource } from '../lib/useResource';
import { PageHeader } from '../ui/PageHeader';
import { ErrorState, Loading } from '../ui/states';
import { Section } from './bookings/parts';
import { Account } from './profile/Account';
import { Passkeys } from './profile/Passkeys';
import { TwoFactor } from './profile/TwoFactor';
import type { Profile } from './profile/types';

/** The signed-in person's own account: details, second factors, passkeys */
export default function ProfileScreen() {
    const { data, error, reload } = useResource<Profile>('/profile');
    const [saved, setSaved] = useState<Profile | null>(null);
    // Sent here to set up two-factor: the way into the admin stays on screen once it is done
    const [forced, setForced] = useState(false);
    const profile = saved ?? data;

    if (profile?.setupRequired && !forced) {
        setForced(true);
    }

    return (
        <>
            <PageHeader title="Profile" description="Your own sign-in details." />
            {error && <ErrorState message={error} onRetry={reload} />}
            {!profile && !error && <Loading rows={5} />}
            {profile && (
                <div className="max-w-3xl space-y-4">
                    {!profile.setupRequired && !forced && <Account profile={profile} onSaved={setSaved} />}
                    <TwoFactor profile={profile} forced={forced} onSaved={setSaved} />
                    {!profile.setupRequired && !forced && (
                        <>
                            <Passkeys profile={profile} onSaved={setSaved} />
                            <Section title="Recent security activity">
                                {profile.events.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">Nothing yet.</p>
                                ) : (
                                    <ul aria-label="Recent security activity" className="m-0 list-none divide-y divide-border p-0 text-sm">
                                        {profile.events.map((event, index) => (
                                            <li key={index} className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2">
                                                <span className="min-w-0 break-words">
                                                    {event.label}
                                                    {event.note && <span className="text-muted-foreground">: {event.note}</span>}
                                                    {event.actor && <span className="text-muted-foreground"> (by {event.actor})</span>}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {event.at}
                                                    {event.ip && ` · ${event.ip}`}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Section>
                        </>
                    )}
                </div>
            )}
        </>
    );
}
