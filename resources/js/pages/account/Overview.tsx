import { Link } from 'react-router-dom';

import { Bone, CardBone, Loading, Problem, Section, ShareCode } from '../../components/AccountParts';
import { StageBadge } from '../../components/BookingStatus';
import Button from '../../components/Button';
import { ArrowRight, Calendar, Car, Gift, Shield } from '../../components/Icons';
import { fetchMyReferral, fetchBookings, LIVE_STAGES } from '../../lib/bookings';
import { useLoad } from '../../lib/account';
import { guest } from '../../lib/auth';
import { copy } from '../../lib/copy';
import { useLive } from '../../lib/live';
import { site } from '../../lib/site';
import { unitName } from '../../lib/vehicles';
import type { CustomerBooking } from '../../types';

const text = copy.me;
const page = text.overview;

/** Stages where the unit is with the team */
const IN_SHOP = ['arrived', 'in_progress', 'quality_check', 'ready'];

const unitLine = (booking: CustomerBooking) => [unitName(booking.vehicle.year, booking.vehicle.model), booking.vehicle.plate].filter(Boolean).join(' · ');

function BookingLine({ booking, showStage = false }: { booking: CustomerBooking; showStage?: boolean }) {
    return (
        <article className="relative panel p-5 transition-colors hover:border-field sm:p-6">
            <div className="flex flex-wrap items-center gap-2">
                <StageBadge stage={booking.stage} />
                <span className="ml-auto text-sm text-muted tabular">{copy.bookings.reference(booking.reference)}</span>
            </div>
            <h3 className="mt-4 text-lg leading-snug font-bold">
                <Link to={booking.url} aria-label={`${page.open}: ${booking.when}`} className="after:absolute after:inset-0 after:rounded-card focus-visible:outline-none after:focus-visible:outline-2 after:focus-visible:outline-offset-3 after:focus-visible:outline-accent">
                    {booking.when}
                </Link>
            </h3>
            <p className="mt-1 text-muted">{[booking.branch?.name, unitLine(booking)].filter(Boolean).join(' — ')}</p>
            {showStage && <p className="mt-3 border-t border-line pt-3 text-[0.95rem] leading-relaxed">{copy.stages[booking.stage].body}</p>}
        </article>
    );
}

/** Quick doors to the other pages */
function Door({ to, icon: Icon, title, body }: { to: string; icon: typeof Car; title: string; body: string }) {
    return (
        <Link to={to} className="press flex min-h-16 items-center gap-4 rounded-card border border-line bg-surface-2 px-4 py-3 hover:border-field">
            <Icon className="size-6 shrink-0 text-accent" />
            <span className="min-w-0 flex-1">
                <span className="block font-semibold">{title}</span>
                <span className="block text-sm text-muted">{body}</span>
            </span>
            <ArrowRight className="size-4 shrink-0 text-muted" />
        </Link>
    );
}

export default function Overview() {
    const bookings = useLoad(fetchBookings);
    const referral = useLoad(fetchMyReferral);

    // A booking moved (the team confirmed it, the unit arrived), or points changed: look again, quietly
    useLive(
        (event) => {
            if (event.type.startsWith('booking.')) bookings.refresh();
            if (event.type === 'rewards.changed') referral.refresh();
        },
        () => {
            bookings.refresh();
            referral.refresh();
        },
    );

    const name = guest?.name.trim().split(/\s+/)[0] ?? '';
    const list = bookings.load.state === 'ok' ? bookings.load.data.bookings : [];
    const inShop = list.filter((booking) => IN_SHOP.includes(booking.stage));
    const next = list.find((booking) => LIVE_STAGES.includes(booking.stage) && !IN_SHOP.includes(booking.stage));
    const code = referral.load.state === 'ok' && referral.load.data.enabled && site.booking.referral.enabled ? referral.load.data : null;

    return (
        <>
            <h2 className="font-display text-[clamp(1.6rem,5vw,2.4rem)]">{text.hi(name)}</h2>
            <p className="mt-3 text-lg text-muted">{page.intro}</p>

            <div className="mt-8">
                {bookings.load.state === 'loading' && (
                    <Loading>
                        <Bone className="h-4 w-32" />
                        <div className="mt-4">
                            <CardBone className="h-5" />
                        </div>
                    </Loading>
                )}
                {(bookings.load.state === 'failed' || bookings.load.state === 'signedOut') && <Problem kind={bookings.load.state === 'failed' ? 'failed' : 'signedOut'} onRetry={bookings.reload} />}

                {bookings.load.state === 'ok' && (
                    <>
                        {inShop.length > 0 && (
                            <Section id="in-shop" title={page.inShop}>
                                <ul className="grid gap-3">
                                    {inShop.map((booking) => (
                                        <li key={booking.id}>
                                            <BookingLine booking={booking} showStage />
                                        </li>
                                    ))}
                                </ul>
                            </Section>
                        )}
                        <Section id="next-booking" title={page.next} action={<Link to="/bookings" className="press inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-text underline underline-offset-4">{page.all}</Link>}>
                            {next ? (
                                <BookingLine booking={next} />
                            ) : (
                                <div className="panel p-5 sm:p-6">
                                    <p className="text-lg">{page.noNext}</p>
                                    <Button to="/book" className="mt-4">
                                        <Calendar className="size-4" /> {page.reserve}
                                    </Button>
                                </div>
                            )}
                        </Section>
                    </>
                )}
            </div>

            {referral.load.state === 'loading' && site.booking.referral.enabled && (
                <div className="mt-10">
                    <Loading>
                        <CardBone className="h-8" />
                    </Loading>
                </div>
            )}
            {code?.code && (
                <div className="mt-10">
                    <Section id="share" title={page.referral} action={<Link to="/account/rewards" className="press inline-flex min-h-11 items-center text-sm font-semibold text-text underline underline-offset-4">{page.seeRewards}</Link>}>
                        <ShareCode code={code.code} body={copy.bookings.referral.body(code.discountLabel)} />
                    </Section>
                </div>
            )}

            <div className="mt-10">
                <Section id="more" title={page.others}>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Door to="/account/garage" icon={Car} title={text.tabs.garage} body={page.garageLink} />
                        <Door to="/account/warranty" icon={Shield} title={text.tabs.warranty} body={page.warrantyLink} />
                        {site.booking.referral.enabled && <Door to="/account/rewards" icon={Gift} title={text.tabs.rewards} body={page.rewardsLink} />}
                    </div>
                </Section>
            </div>
        </>
    );
}
