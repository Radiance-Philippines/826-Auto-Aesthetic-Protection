import { Link } from 'react-router-dom';

import { CardBone, Loading, Problem } from '../../components/AccountParts';
import Button from '../../components/Button';
import { Calendar, Shield } from '../../components/Icons';
import { fetchWarranty, useLoad } from '../../lib/account';
import { copy } from '../../lib/copy';
import { shortDate } from '../../lib/dates';

const text = copy.me.warranty;

const badge: Record<string, string> = {
    active: 'bg-accent/12 text-text',
    expired: 'bg-surface-3 text-muted',
    none: 'bg-surface-3 text-muted',
};

export default function Warranty() {
    const { load, reload } = useLoad(fetchWarranty);

    return (
        <>
            <h2 className="font-display text-[clamp(1.6rem,5vw,2.4rem)]">{text.title}</h2>
            <p className="mt-3 text-lg text-muted">{text.intro}</p>

            <div className="mt-8">
                {load.state === 'loading' && (
                    <Loading>
                        <div className="grid gap-3">
                            <CardBone className="h-5" />
                            <CardBone className="h-5" />
                        </div>
                    </Loading>
                )}
                {(load.state === 'failed' || load.state === 'signedOut' || load.state === 'missing') && <Problem kind={load.state === 'signedOut' ? 'signedOut' : 'failed'} onRetry={reload} />}

                {load.state === 'ok' && load.data.length === 0 && (
                    <div className="panel p-5 sm:p-6">
                        <p className="text-lg">{text.empty}</p>
                        <Button to="/book" className="mt-4">
                            <Calendar className="size-4" /> {copy.me.garage.emptyCta}
                        </Button>
                    </div>
                )}

                {load.state === 'ok' &&
                    load.data.map((unit, index) => (
                        <section key={`${unit.vehicleId ?? 'x'}-${index}`} aria-labelledby={`unit-${index}`} className="mt-8 first:mt-0">
                            <h3 id={`unit-${index}`} className="eyebrow text-muted">
                                {unit.vehicleId !== null ? (
                                    <Link to={`/account/garage/${unit.vehicleId}`} className="underline underline-offset-4">
                                        {unit.vehicleLabel}
                                    </Link>
                                ) : (
                                    unit.vehicleLabel
                                )}
                            </h3>
                            <ul className="mt-4 grid gap-3">
                                {unit.services.map((service, row) => (
                                    <li key={`${service.name}-${service.finishedOn}-${row}`} className="panel p-5 sm:p-6">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <p className="flex min-w-0 items-center gap-2.5 text-lg font-bold">
                                                <Shield className="size-5 shrink-0 text-accent" /> {service.name}
                                            </p>
                                            <span className={`inline-flex rounded-full px-2.5 py-1 text-[0.8125rem] font-semibold ${badge[service.status]}`}>{text[service.status]}</span>
                                        </div>
                                        <p className="mt-3 text-[0.95rem] text-muted">
                                            {text.finished(shortDate(service.finishedOn))}
                                            {service.months ? ` · ${text.months(service.months)}` : ''}
                                            {service.endsOn ? ` · ${service.status === 'active' ? text.ends(shortDate(service.endsOn)) : text.ended(shortDate(service.endsOn))}` : ''}
                                        </p>
                                        {service.backjobTypesCovered.length > 0 && (
                                            <p className="mt-3 border-t border-line pt-3 text-[0.95rem]">
                                                <span className="eyebrow mr-2 text-[0.68rem] text-muted">{text.covers}</span>
                                                {service.backjobTypesCovered.join(', ')}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}
            </div>
        </>
    );
}
