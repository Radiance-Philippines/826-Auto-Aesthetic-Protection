import { Navigate, useSearchParams } from 'react-router-dom';

import { buttonClass } from '../components/Button';
import GoogleButton from '../components/GoogleButton';
import { PageHeader } from '../components/Heading';
import { Check, User, Warning } from '../components/Icons';
import { canSignIn, guest, localSignIn, safeReturn, signInHref } from '../lib/auth';
import { copy } from '../lib/copy';
import { site } from '../lib/site';
import usePageTitle from '../lib/usePageTitle';

const text = copy.login;

/**
 * The customer's sign-in page. Customers are created by signing in, so
 * there is no separate sign-up. Already signed in: on to `?return=` or the
 * account. The team's /admin login is a separate door, linked small.
 */
export default function Login() {
    usePageTitle(text.title);
    const [params] = useSearchParams();
    const back = safeReturn(params.get('return'));
    const failed = params.get('signin') === 'failed';

    if (guest) {
        return <Navigate to={back ?? '/account'} replace />;
    }

    const href = signInHref(back ?? '/account');

    return (
        <>
            <PageHeader eyebrow={text.eyebrow} title={text.title}>
                <p>{text.intro}</p>
            </PageHeader>
            <div className="mx-auto max-w-7xl px-4 py-10 sm:px-8 sm:py-14 xl:px-12">
                <section aria-labelledby="login-title" className="panel max-w-xl p-5 sm:p-7">
                    <h2 id="login-title" className="text-lg font-bold">
                        {text.panelTitle}
                    </h2>

                    {failed && (
                        <p role="alert" className="mt-4 flex items-start gap-2 font-medium text-danger">
                            <Warning className="mt-0.5 size-5 shrink-0" /> {text.failed}
                        </p>
                    )}

                    {canSignIn ? (
                        <>
                            {site.auth.googleEnabled && (
                                <>
                                    <p className="mt-2 leading-relaxed text-muted">{text.google}</p>
                                    <GoogleButton href={href} className="mt-5 w-full sm:w-auto sm:min-w-[18rem]" />
                                </>
                            )}
                            {localSignIn && (
                                <>
                                    <a href={href} className={buttonClass('accent', 'mt-5 w-full sm:w-auto sm:min-w-[18rem]')}>
                                        <User className="size-5" /> {text.sample}
                                    </a>
                                    <p className="mt-3 text-sm leading-relaxed text-muted">{text.sampleNote}</p>
                                </>
                            )}
                            <p className="mt-5 flex items-start gap-2 border-t border-line pt-4 text-[0.95rem] leading-relaxed text-muted">
                                <Check className="mt-1 size-4 shrink-0 text-accent" /> {text.first}
                            </p>
                        </>
                    ) : (
                        <p className="mt-3 leading-relaxed text-muted">{text.unavailable}</p>
                    )}
                </section>

                <p className="mt-6">
                    <a href={`${site.base}/admin/login`} className="press inline-flex min-h-11 items-center text-[0.95rem] text-muted underline underline-offset-4 hover:text-text">
                        {text.team}
                    </a>
                </p>
            </div>
        </>
    );
}
