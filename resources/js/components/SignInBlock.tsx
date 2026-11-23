import { Link } from 'react-router-dom';

import { accountPagePath, canSignIn, emailSignIn, localSignIn, signInHref } from '../lib/auth';
import { copy } from '../lib/copy';
import { site } from '../lib/site';
import { buttonClass } from './Button';
import GoogleButton from './GoogleButton';
import { Check, User } from './Icons';

type Props = {
    title: string;
    body: string;
    /** Where to come back to, site-relative ("/book?step=7") */
    returnPath: string;
    /** Before leaving for another page: keep what's typed */
    onLeave?: () => void;
    /** Say that the picks are kept */
    kept?: boolean;
    id?: string;
};

/**
 * Sign in or create an account (email and password), or go on with
 * Google, all coming back to the same place. Without any way to sign in
 * on this copy: say so, and the contact block around it takes over.
 */
export default function SignInBlock({ title, body, returnPath, onLeave, kept = false, id }: Props) {
    const wide = 'w-full sm:w-auto sm:min-w-[14rem]';

    return (
        <section aria-labelledby={id} className="rounded-card border border-accent/50 bg-surface-2 p-5 sm:p-6">
            <h2 id={id} className="text-lg font-bold">
                {title}
            </h2>
            {canSignIn ? (
                <>
                    <p className="mt-2 max-w-xl leading-relaxed text-muted">{body}</p>
                    <div className="mt-5 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                        {emailSignIn && (
                            <>
                                <Link to={accountPagePath('login', returnPath)} onClick={onLeave} className={buttonClass('accent', wide)}>
                                    <User className="size-5" /> {copy.account.signIn}
                                </Link>
                                <Link to={accountPagePath('register', returnPath)} onClick={onLeave} className={buttonClass('outline', wide)}>
                                    <User className="size-5" /> {copy.account.createAccount}
                                </Link>
                            </>
                        )}
                        {site.auth.googleEnabled && <GoogleButton href={signInHref(returnPath)} onClick={onLeave} className="w-full sm:w-auto sm:min-w-[14rem]" />}
                        {!emailSignIn && localSignIn && (
                            <a href={signInHref(returnPath)} onClick={onLeave} className={buttonClass('accent', wide)}>
                                <User className="size-5" /> {copy.login.sample}
                            </a>
                        )}
                    </div>
                    {kept && (
                        <p className="mt-3 flex items-start gap-2 text-sm text-muted">
                            <Check className="mt-0.5 size-4 shrink-0 text-accent" /> {copy.book.signIn.kept}
                        </p>
                    )}
                    {!emailSignIn && localSignIn && <p className="mt-2 text-sm text-muted">{copy.account.localNote}</p>}
                </>
            ) : (
                <p className="mt-2 max-w-xl leading-relaxed text-muted">{copy.book.signIn.unavailable}</p>
            )}
        </section>
    );
}
