import type { ComponentType } from 'react';
import { Navigate, NavLink, Outlet, useLocation } from 'react-router-dom';

import { Calendar, Car, Gift, Grid, Shield, User } from '../../components/Icons';
import { PageHeader } from '../../components/Heading';
import { guest } from '../../lib/auth';
import { copy } from '../../lib/copy';
import usePageTitle from '../../lib/usePageTitle';

const text = copy.me;

type Tab = { to: string; label: string; end?: boolean; icon: ComponentType<{ className?: string }> };

const tabs: Tab[] = [
    { to: '/account', label: text.tabs.overview, end: true, icon: Grid },
    { to: '/account/garage', label: text.tabs.garage, icon: Car },
    { to: '/account/rewards', label: text.tabs.rewards, icon: Gift },
    { to: '/account/warranty', label: text.tabs.warranty, icon: Shield },
    { to: '/account/me', label: text.tabs.me, icon: User },
];

/**
 * Every /account page: a tab bar on phones, a list beside the page on
 * desktop. Signed out: to the sign-in page, and back here after.
 */
export default function Shell() {
    usePageTitle(text.title);
    const location = useLocation();

    if (!guest) {
        return <Navigate to={`/login?return=${encodeURIComponent(`${location.pathname}${location.search}`)}`} replace />;
    }

    return (
        <>
            <PageHeader eyebrow={text.eyebrow} title={text.title} />
            <div className="mx-auto grid max-w-7xl gap-6 px-4 py-6 sm:px-8 sm:py-10 lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-12 xl:px-12">
                <nav aria-label={text.navLabel} className="lg:sticky lg:top-28 lg:self-start">
                    <ul className="grid grid-cols-5 gap-1 rounded-card border border-line bg-surface-2 p-1 lg:grid-cols-1 lg:border-0 lg:bg-transparent lg:p-0">
                        {tabs.map(({ to, label, end, icon: Icon }) => (
                            <li key={to}>
                                <NavLink
                                    to={to}
                                    end={end}
                                    className={({ isActive }) =>
                                        `press flex min-h-14 flex-col items-center justify-center gap-1 rounded-control px-1 text-[0.8125rem] font-medium lg:min-h-11 lg:flex-row lg:justify-start lg:gap-3 lg:px-3 lg:text-[0.95rem] ${
                                            isActive ? 'bg-accent/12 text-text ring-1 ring-accent/60' : 'text-text/75 hover:bg-text/6 hover:text-text'
                                        }`
                                    }
                                >
                                    <Icon className="size-5 shrink-0 text-accent" /> {label}
                                </NavLink>
                            </li>
                        ))}
                    </ul>
                    <NavLink to="/bookings" className="press mt-2 hidden min-h-11 items-center gap-3 rounded-control px-3 text-[0.95rem] font-medium text-text/75 hover:bg-text/6 hover:text-text lg:flex">
                        <Calendar className="size-5 shrink-0 text-accent" /> {text.bookings}
                    </NavLink>
                </nav>
                <div className="min-w-0 max-w-3xl">
                    <Outlet />
                </div>
            </div>
        </>
    );
}
