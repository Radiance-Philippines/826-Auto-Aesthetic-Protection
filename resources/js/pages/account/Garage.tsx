import { CardBone, Loading, Problem } from '../../components/AccountParts';
import Button from '../../components/Button';
import { ArrowRight, Calendar } from '../../components/Icons';
import VehicleSilhouette from '../../components/VehicleSilhouette';
import { useLoad } from '../../lib/account';
import { copy } from '../../lib/copy';
import { vehicleTypeLabel } from '../../lib/site';
import { fetchMyVehicles, unitName } from '../../lib/vehicles';

const text = copy.me.garage;

export default function Garage() {
    const { load, reload } = useLoad(fetchMyVehicles);

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
                {(load.state === 'failed' || load.state === 'signedOut') && <Problem kind={load.state === 'failed' ? 'failed' : 'signedOut'} onRetry={reload} />}

                {load.state === 'ok' && load.data.vehicles.length === 0 && (
                    <div className="panel p-5 sm:p-6">
                        <p className="text-lg">{text.empty}</p>
                        <Button to="/book" className="mt-4">
                            <Calendar className="size-4" /> {text.emptyCta}
                        </Button>
                    </div>
                )}

                {load.state === 'ok' && load.data.vehicles.length > 0 && (
                    <ul className="grid gap-3">
                        {load.data.vehicles.map((unit) => (
                            <li key={unit.id} className="panel p-5 sm:p-6">
                                <div className="flex items-start justify-between gap-4">
                                    <div className="min-w-0">
                                        <h3 className="text-lg leading-snug font-bold">{unitName(unit.year, unit.model)}</h3>
                                        <p className="mt-1 text-muted">{[unit.plate, vehicleTypeLabel(unit.type)].filter(Boolean).join(' · ')}</p>
                                        {unit.sizeReviewed && <p className="mt-2 inline-flex rounded-full bg-accent/12 px-2.5 py-1 text-[0.8125rem] font-semibold">{text.sizeReviewed}</p>}
                                    </div>
                                    <span className="w-24 shrink-0 text-text/40 sm:w-28">
                                        <VehicleSilhouette type={unit.type} className="w-full [--silhouette-cut:var(--panel-top)]" />
                                    </span>
                                </div>
                                <div className="mt-4 flex flex-wrap gap-3 border-t border-line pt-4">
                                    <Button to={`/book?unit=${unit.id}`} className="min-h-11 px-4">
                                        <Calendar className="size-4" /> {text.bookAgain}
                                    </Button>
                                    <Button to={`/account/garage/${unit.id}`} variant="outline" className="min-h-11 px-4">
                                        {text.history} <ArrowRight className="size-4" />
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
