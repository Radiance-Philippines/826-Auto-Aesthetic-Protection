import { Drawer, DrawerBackdrop, DrawerClose, DrawerContent, DrawerTitle, DrawerTrigger } from '@ui-sdk/react/drawer';
import { Component, type ReactNode, Suspense, useEffect, useRef, useState } from 'react';
import { Link, Outlet, useLocation } from 'react-router-dom';

import { type Me, MeContext } from '../lib/me';
import { useResource } from '../lib/useResource';
import { useStored } from '../lib/useStored';
import { buttonClass } from '../ui/Button';
import { ConfirmHost } from '../ui/Dialog';
import { Icon } from '../ui/icons';
import { ErrorState, Loading } from '../ui/states';
import { Toaster } from '../ui/toast';
import { Brand, Nav } from './Nav';
import { NotificationBell } from './NotificationBell';
import { BranchSelector, ImpersonationBanner, Launcher, UserMenu } from './TopBar';

/** A screen that throws shows this instead of a blank page */
class ScreenBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    render() {
        return this.state.failed ? <ErrorState message="This screen stopped working. Load it again." onRetry={() => window.location.reload()} /> : this.props.children;
    }
}

/** Sidebar, top bar and the screen. Everything in it comes from `me`. */
export default function Shell() {
    const { data: me, error, reload } = useResource<Me>('/me');
    const [menuOpen, setMenuOpen] = useState(false);
    const [rail, setRail] = useStored('826.sidebar.rail', false);
    const main = useRef<HTMLElement>(null);
    const { pathname } = useLocation();
    const previous = useRef(pathname);

    // After a move inside the app, the keyboard and the screen reader start at the new screen
    useEffect(() => {
        if (previous.current !== pathname) {
            previous.current = pathname;
            window.scrollTo(0, 0);
            main.current?.focus({ preventScroll: true });
        }
    }, [pathname]);

    if (!me) {
        return <div className="mx-auto max-w-xl p-6">{error ? <ErrorState message={error} onRetry={reload} /> : <Loading rows={4} />}</div>;
    }

    return (
        <MeContext value={{ me, reload }}>
            <a href="#main" className="sr-only z-50 rounded-md bg-card px-3 py-2 font-semibold focus:not-sr-only focus:fixed focus:top-2 focus:left-2">
                Skip to content
            </a>

            <div className={`min-h-dvh lg:grid ${rail ? 'lg:grid-cols-[4rem_minmax(0,1fr)]' : 'lg:grid-cols-[15rem_minmax(0,1fr)]'}`}>
                <aside id="sidebar" className="sticky top-0 hidden h-dvh flex-col overflow-y-auto border-e border-border bg-card lg:flex">
                    <Link to="/" className={`flex h-14 shrink-0 items-center ${rail ? 'justify-center' : 'px-4'}`}>
                        <Brand me={me} showName={!rail} small={rail} />
                    </Link>
                    {/* The logo's gold rule */}
                    <div aria-hidden="true" className={`h-px shrink-0 bg-linear-to-r from-gold to-transparent ${rail ? 'mx-2' : 'mx-4'}`} />
                    <div className="p-2 pt-3">
                        <Nav me={me} rail={rail} />
                    </div>
                </aside>

                <div className="flex min-w-0 flex-col">
                    <div className="sticky top-0 z-30">
                        {me.impersonating && <ImpersonationBanner person={me.impersonating} />}
                        <header className="flex flex-wrap items-center gap-2 border-b border-border bg-card px-3 py-2 sm:flex-nowrap lg:px-6">
                            <Drawer open={menuOpen} onOpenChange={setMenuOpen} side="left">
                                <DrawerTrigger aria-label="Open the menu" className={`${buttonClass('ghost', 'md', true)} lg:hidden`}>
                                    <Icon name="menu" className="size-5" />
                                </DrawerTrigger>
                                <DrawerBackdrop className="bg-black/50" />
                                <DrawerContent className="flex h-full w-[min(18rem,85vw)] flex-col gap-0 border-0 border-e border-border bg-card p-0 text-foreground">
                                    <div className="flex h-14 shrink-0 items-center justify-between px-3">
                                        <DrawerTitle className="m-0">
                                            <Brand me={me} />
                                        </DrawerTitle>
                                        <DrawerClose aria-label="Close the menu" className={`${buttonClass('ghost', 'md', true)} shrink-0 shadow-none`}>
                                            <Icon name="x" />
                                        </DrawerClose>
                                    </div>
                                    <div className="min-h-0 flex-1 overflow-y-auto p-2">
                                        <Nav me={me} onNavigate={() => setMenuOpen(false)} />
                                    </div>
                                </DrawerContent>
                            </Drawer>
                            <button
                                type="button"
                                className={`${buttonClass('ghost', 'md', true)} shrink-0 shadow-none max-lg:hidden`}
                                aria-label={rail ? 'Expand the sidebar' : 'Collapse the sidebar'}
                                aria-expanded={!rail}
                                aria-controls="sidebar"
                                onClick={() => setRail(!rail)}
                            >
                                <Icon name="panel-left" className="size-5" />
                            </button>
                            <Link to="/" className="flex items-center lg:hidden">
                                <Brand me={me} showName={false} />
                                <span className="sr-only">Home</span>
                            </Link>
                            {/* On a phone the branch has its own row, with the whole name */}
                            <div className="order-last basis-full empty:hidden sm:order-none sm:ms-auto sm:basis-auto">
                                <BranchSelector branches={me.branches} />
                            </div>
                            <div className="ms-auto flex items-center gap-1 sm:ms-0">
                                {!me.setupRequired && <NotificationBell />}
                                <Launcher tiles={me.launcher} />
                                <UserMenu me={me} />
                            </div>
                        </header>
                    </div>

                    <main id="main" ref={main} tabIndex={-1} className="min-w-0 flex-1 space-y-4 p-4 outline-none lg:p-6">
                        <ScreenBoundary key={pathname.split('/')[1]}>
                            <Suspense fallback={<Loading rows={4} />}>
                                <Outlet />
                            </Suspense>
                        </ScreenBoundary>
                    </main>
                </div>
            </div>

            <Toaster />
            <ConfirmHost />
        </MeContext>
    );
}
