import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';

import { StageBadge } from '../components/BookingStatus';
import Button from '../components/Button';
import { PageHeader } from '../components/Heading';
import { ArrowRight, Check, Copy, House, Warning } from '../components/Icons';
import SignInBlock from '../components/SignInBlock';
import VehicleSilhouette from '../components/VehicleSilhouette';
import { guest } from '../lib/auth';
import { copyText, fetchBookings, fetchMyReferral, LIVE_STAGES } from '../lib/bookings';
import { copy } from '../lib/copy';
import { peso, site } from '../lib/site';
import { unitName } from '../lib/vehicles';
import usePageTitle from '../lib/usePageTitle';
import type { CustomerBooking, MyReferral } from '../types';

const text = copy.bookings;

function BookingCard({ booking }: { booking: CustomerBooking }) {
    return (
        <article className="relative panel p-5 transition-colors hover:border-field sm:p-6">
            <div className="flex flex-wrap items-center gap-2">
                <StageBadge stage={booking.stage} />
                {booking.kind === 'home' && (
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-surface-3 px-2.5 py-1 text-[0.8125rem] font-semibold">
                        <House className="size-3.5" /> {text.home}
                    </span>
                )}
                <span className="ml-auto text-sm text-muted tabular">{text.reference(booking.reference)}</span>
            </div>
            <h3 className="mt-4 text-lg leading-snug font-bold">
                <Link to={booking.url} className="after:absolute after:inset-0 after:rounded-card focus-visible:outline-none after:focus-visible:outline-2 after:focus-visible:outline-offset-3 after:focus-visible:outline-accent">
                    {booking.when}
                </Link>
            </h3>
            <div className="mt-1 flex items-end justify-between gap-4">
                <p className="text-muted">{[booking.branch?.name, [unitName(booking.vehicle.year, booking.vehicle.model), booking.vehicle.plate].filter(Boolean).join(' · ')].filter(Boolean).join(' — ')}</p>
                <span className="hidden w-24 shrink-0 text-text/40 sm:block">
                    <VehicleSilhouette type={booking.vehicle.type} className="w-full [--silhouette-cut:var(--panel-top)]" />
                </span>
            </div>
            <div className="mt-4 flex flex-wrap items-end justify-between gap-3 border-t border-line pt-4">
                <p className="min-w-0 text-[0.95rem]">{booking.items.map((item) => item.name).join(', ')}</p>
                {booking.price.status === 'set' && booking.price.total !== null ? <p className="price shrink-0 text-lg">{peso(booking.price.total)}</p> : <p className="shrink-0 text-sm text-muted">{text.priceReviewing}</p>}
            </div>
        </article>
    );
}

/** The customer's own code to share, with what it has earned (only while referral is on) */
function ReferralCard() {
    const [referral, setReferral] = useState<MyReferral | null>(null);
    const [copied, setCopied] = useState(false);
    const card = text.referral;

    useEffect(() => {
        let cancelled = false;

        fetchMyReferral().then((result) => {
            if (!cancelled && result.kind === 'ok' && result.data.enabled && result.data.code) {
                setReferral(result.data);
            }
        });

        return () => {
            cancelled = true;
        };
    }, []);

    if (!referral?.code) {
        return null;
    }

    const code = referral.code;

    return (
        <section aria-labelledby="referral-title" className="panel mb-10 p-5 sm:p-6">
            <h2 id="referral-title" className="eyebrow text-muted">
                {card.title}
            </h2>
            <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p className="price text-2xl tracking-wide tabular">{code}</p>
                <button
                    type="button"
                    onClick={async () => {
                        if (await copyText(code)) {
                            setCopied(true);
                            window.setTimeout(() => setCopied(false), 2000);
                        }
                    }}
                    className="press inline-flex min-h-11 items-center gap-2 rounded-control border border-field px-4 font-semibold hover:border-text hover:bg-text/5"
                >
                    {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                    <span role="status">{copied ? card.copied : card.copy}</span>
                </button>
            </div>
            <p className="mt-3 text-[0.95rem] text-muted">{card.body(referral.discountLabel)}</p>
            <p className="mt-1 text-[0.95rem] text-muted">
                {card.used(referral.usedCount)}
                {referral.pointsEarned > 0 || referral.pointsPending > 0 ? ` · ${card.points(referral.pointsEarned, referral.pointsPending)}` : ''}
            </p>
        </section>
    );
}

export default function Bookings() {
    usePageTitle(text.title);
    const [bookings, setBookings] = useState<CustomerBooking[] | null>(null);
    const [state, setState] = useState<'loading' | 'ok' | 'failed' | 'signedOut'>(guest ? 'loading' : 'signedOut');
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        if (!guest) {
            return;
        }

        let cancelled = false;
        setState('loading');

        fetchBookings().then((result) => {
            if (cancelled) {
                return;
            }

            if (result.kind === 'ok') {
                setBookings(result.data.bookings);
                setState('ok');
            } else {
                setState(result.kind === 'signIn' ? 'signedOut' : 'failed');
            }
        });

        return () => {
            cancelled = true;
        };
    }, [attempt]);

    const upcoming = (bookings ?? []).filter((booking) => LIVE_STAGES.includes(booking.stage));
    const past = (bookings ?? []).filter((booking) => !LIVE_STAGES.includes(booking.stage));

    return (
        <>
            <PageHeader eyebrow={text.eyebrow} title={text.title}>
                <p>{text.intro}</p>
            </PageHeader>
            <div className="mx-auto max-w-7xl px-4 py-10 sm:px-8 sm:py-14 xl:px-12">
                <div className="max-w-3xl">
                {state === 'ok' && site.booking.referral.enabled && <ReferralCard />}
                {state === 'signedOut' && <SignInBlock id="bookings-signin" title={text.signIn.title} body={text.signIn.body} returnPath="/bookings" />}

                {state === 'loading' && (
                    <p className="text-muted" role="status">
                        {text.loading}
                    </p>
                )}

                {state === 'failed' && (
                    <p role="alert" className="flex flex-wrap items-center gap-3 font-medium text-danger">
                        <Warning className="size-5" /> {text.failed}
                        <button type="button" onClick={() => setAttempt((count) => count + 1)} className="press min-h-11 rounded-control px-2 font-semibold text-text underline underline-offset-4">
                            {text.retry}
                        </button>
                    </p>
                )}

                {state === 'ok' && bookings?.length === 0 && (
                    <div className="panel p-6">
                        <p className="text-lg">{text.empty}</p>
                        <Button to="/book" className="mt-5">
                            {text.emptyCta} <ArrowRight className="size-4" />
                        </Button>
                    </div>
                )}

                {state === 'ok' && upcoming.length > 0 && (
                    <section aria-labelledby="upcoming-title">
                        <h2 id="upcoming-title" className="eyebrow text-muted">
                            {text.upcoming}
                        </h2>
                        <ul className="mt-4 grid gap-3">
                            {upcoming.map((booking) => (
                                <li key={booking.id}>
                                    <BookingCard booking={booking} />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {state === 'ok' && past.length > 0 && (
                    <section aria-labelledby="past-title" className={upcoming.length > 0 ? 'mt-12' : ''}>
                        <h2 id="past-title" className="eyebrow text-muted">
                            {text.past}
                        </h2>
                        <ul className="mt-4 grid gap-3">
                            {past.map((booking) => (
                                <li key={booking.id}>
                                    <BookingCard booking={booking} />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                </div>
            </div>
        </>
    );
}
