import { Link, useParams } from 'react-router-dom';

import { Bone, CardBone, Loading, Problem } from '../../components/AccountParts';
import { StageBadge } from '../../components/BookingStatus';
import Button from '../../components/Button';
import { ArrowLeft, Calendar } from '../../components/Icons';
import VehicleSilhouette from '../../components/VehicleSilhouette';
import { fetchUnit, useLoad } from '../../lib/account';
import { copy } from '../../lib/copy';
import { shortDate } from '../../lib/dates';
import { peso, vehicleTypeLabel } from '../../lib/site';
import { unitName } from '../../lib/vehicles';

const text = copy.me.garage;

export default function GarageUnit() {
    const { id = '' } = useParams();
    const { load, reload } = useLoad(() => fetchUnit(id), id);

    return (
        <>
            <Link to="/account/garage" className="press -ml-2 inline-flex min-h-11 items-center gap-2 rounded-control px-2 text-[0.95rem] font-medium text-muted hover:text-text">
                <ArrowLeft className="size-4" /> {text.back}
            </Link>

            <div className="mt-4">
                {load.state === 'loading' && (
                    <Loading>
                        <Bone className="h-9 w-2/3" />
                        <Bone className="mt-4 h-5 w-1/3" />
                        <div className="mt-8 grid gap-3">
                            <CardBone className="h-5" />
                            <CardBone className="h-5" />
                        </div>
                    </Loading>
                )}
                {(load.state === 'failed' || load.state === 'signedOut') && <Problem kind={load.state === 'failed' ? 'failed' : 'signedOut'} onRetry={reload} />}
                {load.state === 'missing' && (
                    <p role="alert" className="panel p-5 text-lg sm:p-6">
                        {text.notFound}
                    </p>
                )}

                {load.state === 'ok' && (
                    <>
                        <div className="flex items-start justify-between gap-4">
                            <div className="min-w-0">
                                <h2 className="font-display text-[clamp(1.6rem,5vw,2.4rem)]">{unitName(load.data.vehicle.year, load.data.vehicle.model)}</h2>
                                <p className="mt-3 text-lg text-muted">{[load.data.vehicle.plate, vehicleTypeLabel(load.data.vehicle.type)].filter(Boolean).join(' · ')}</p>
                            </div>
                            <span className="hidden w-32 shrink-0 text-text/40 sm:block">
                                <VehicleSilhouette type={load.data.vehicle.type} className="w-full" />
                            </span>
                        </div>
                        <Button to={`/book?unit=${load.data.vehicle.id}`} className="mt-6">
                            <Calendar className="size-4" /> {text.bookAgain}
                        </Button>

                        <h3 id="unit-history" className="eyebrow mt-10 text-muted">
                            {text.historyTitle}
                        </h3>
                        {load.data.history.length === 0 ? (
                            <p className="mt-4 text-muted">{text.noHistory}</p>
                        ) : (
                            <ul aria-labelledby="unit-history" className="mt-4 grid gap-3">
                                {load.data.history.map((line) => (
                                    <li key={line.id} className="relative panel p-5 transition-colors hover:border-field sm:p-6">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <StageBadge stage={line.status} />
                                            <span className="ml-auto text-sm text-muted tabular">{copy.bookings.reference(line.reference)}</span>
                                        </div>
                                        <p className="mt-3 text-lg leading-snug font-bold">
                                            <Link to={`/bookings/${line.id}`} aria-label={text.openBooking(line.reference)} className="after:absolute after:inset-0 after:rounded-card focus-visible:outline-none after:focus-visible:outline-2 after:focus-visible:outline-offset-3 after:focus-visible:outline-accent">
                                                {line.date ? shortDate(line.date) : line.reference}
                                            </Link>
                                        </p>
                                        <p className="mt-1 text-muted">{[line.branch, line.services.join(', ')].filter(Boolean).join(' — ')}</p>
                                        {line.price !== null && (
                                            <p className="mt-3 flex items-center justify-between border-t border-line pt-3">
                                                <span className="text-sm text-muted">{text.price}</span>
                                                <span className="price text-lg">{peso(line.price)}</span>
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </>
                )}
            </div>
        </>
    );
}
