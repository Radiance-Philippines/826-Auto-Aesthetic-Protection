import { CardBone, Loading, Problem, Section, ShareCode } from '../../components/AccountParts';
import { fetchRewards, useLoad } from '../../lib/account';
import { copy } from '../../lib/copy';
import { shortDate } from '../../lib/dates';
import { useLive } from '../../lib/live';
import { peso, site } from '../../lib/site';

const text = copy.me.rewards;

export default function Rewards() {
    const { load, reload, refresh } = useLoad(fetchRewards);

    // Points change live: a friend's booking exits, or the team redeems some
    useLive(
        (event) => event.type === 'rewards.changed' && refresh(),
        refresh,
    );

    return (
        <>
            <h2 className="font-display text-[clamp(1.6rem,5vw,2.4rem)]">{text.title}</h2>
            <p className="mt-3 text-lg text-muted">{text.intro}</p>

            <div className="mt-8">
                {load.state === 'loading' && (
                    <Loading>
                        <div className="grid gap-3">
                            <CardBone className="h-8" />
                            <CardBone className="h-5" />
                        </div>
                    </Loading>
                )}
                {(load.state === 'failed' || load.state === 'signedOut' || load.state === 'missing') && <Problem kind={load.state === 'signedOut' ? 'signedOut' : 'failed'} onRetry={reload} />}

                {load.state === 'ok' && (
                    <>
                        {(!load.data.enabled || !site.booking.referral.enabled) && <p className="panel mb-6 p-5 leading-relaxed text-muted sm:p-6">{text.off}</p>}

                        <section aria-labelledby="balance-title" className="panel p-5 sm:p-6">
                            <h3 id="balance-title" className="eyebrow text-muted">
                                {text.balance}
                            </h3>
                            <p className="price mt-3 text-5xl tabular">{load.data.pointsBalance}</p>
                            <p className="mt-2 text-muted">
                                {text.earned(load.data.pointsEarned)} · {text.spent(load.data.pointsSpent)}
                            </p>
                            {load.data.pesoPerPoint !== null && <p className="mt-3 text-[0.95rem] leading-relaxed text-muted">{text.worth(peso(load.data.pesoPerPoint))}</p>}
                        </section>

                        {load.data.enabled && load.data.code && (
                            <div className="mt-10">
                                <Section id="code-title" title={text.codeTitle}>
                                    <ShareCode code={load.data.code} body={text.codeBody(load.data.discountLabel)} showLink />
                                </Section>
                            </div>
                        )}

                        <div className="mt-10">
                            <Section id="friends-title" title={text.friends}>
                                {load.data.friends.length === 0 ? (
                                    <p className="text-muted">{text.noFriends}</p>
                                ) : (
                                    <ul className="grid gap-3">
                                        {load.data.friends.map((friend, index) => (
                                            <li key={`${friend.name}-${friend.usedOn}-${index}`} className="panel flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-5 py-4">
                                                <span className="font-semibold">{friend.name}</span>
                                                <span className="text-sm text-muted">
                                                    {shortDate(friend.usedOn)} · {text.status[friend.status] ?? friend.status}
                                                    {friend.points > 0 ? ` · ${text.points(friend.points)}` : ''}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Section>
                        </div>

                        <div className="mt-10">
                            <Section id="ledger-title" title={text.ledger}>
                                {load.data.ledger.length === 0 ? (
                                    <p className="text-muted">{text.noLedger}</p>
                                ) : (
                                    <ul className="panel divide-y divide-line">
                                        {load.data.ledger.map((line, index) => (
                                            <li key={`${line.date}-${index}`} className="flex items-start justify-between gap-4 px-5 py-4">
                                                <span className="min-w-0">
                                                    <span className="block font-semibold">{line.note}</span>
                                                    <span className="block text-sm text-muted">
                                                        {shortDate(line.date)} · {text.kind[line.kind]}
                                                    </span>
                                                </span>
                                                <span className="price shrink-0 tabular">{text.points(line.points)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Section>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}
