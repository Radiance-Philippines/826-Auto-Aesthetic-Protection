import { Menu, MenuContent, MenuGroup, MenuGroupLabel, MenuItem, MenuItemIndicator, MenuRadioGroup, MenuRadioItem, MenuSeparator, MenuTrigger } from '@ui-sdk/react/menu';
import { useRef } from 'react';

import { csrfToken } from '../lib/boot';
import type { Me } from '../lib/me';
import { setTheme, type ThemeChoice, useTheme } from '../lib/theme';
import { buttonClass } from '../ui/Button';
import { cx } from '../ui/cx';
import { Icon, type IconName } from '../ui/icons';
import { menuClass, menuItemClass } from '../ui/Menu';

/** web.css hangs the check over the start of the row; ours sits at its end, like a select's */
const indicator = 'static ms-auto flex';

/**
 * The branch the person is working in (#695): one or "All branches". Choosing
 * posts to the same endpoint as the Filament top bar and reloads the page in
 * that branch.
 */
export function BranchSelector({ branches }: { branches: Me['branches'] }) {
    const form = useRef<HTMLFormElement>(null);
    const input = useRef<HTMLInputElement>(null);
    const { options, chosen, locked, action } = branches;

    if (options.length === 0) {
        return null;
    }

    const label = locked ? options[0].name : (options.find((branch) => branch.id === chosen)?.name ?? 'All branches');
    const trigger = cx(buttonClass(), 'w-full max-w-none justify-start sm:w-auto sm:max-w-56');

    if (locked) {
        return (
            <span className={cx(trigger, 'cursor-default hover:bg-card')}>
                <Icon name="store" />
                <span className="truncate">
                    <span className="sr-only">Branch: </span>
                    {label}
                </span>
            </span>
        );
    }

    const choose = (value: string) => {
        input.current!.value = value === 'all' ? '' : value;
        form.current!.requestSubmit();
    };

    return (
        <>
            <form ref={form} method="post" action={action} hidden>
                <input type="hidden" name="_token" value={csrfToken()} />
                <input ref={input} type="hidden" name="branch" defaultValue={chosen ?? ''} />
            </form>
            <Menu presentation="auto">
                <MenuTrigger aria-label={`Branch: ${label}`} className={trigger}>
                    <Icon name="store" />
                    <span className="truncate">{label}</span>
                    <Icon name="chevrons-up-down" className="ms-auto size-4 text-muted-foreground" />
                </MenuTrigger>
                <MenuContent className={menuClass} align="end" sideOffset={4}>
                    <MenuRadioGroup value={chosen === null ? 'all' : String(chosen)} onValueChange={(value) => choose(value as string)}>
                        <MenuGroupLabel className="block px-2 py-1.5 text-xs font-semibold text-muted-foreground">Branch</MenuGroupLabel>
                        {[{ id: 'all' as const, name: 'All branches' }, ...options].map((branch) => (
                            <MenuRadioItem key={branch.id} value={String(branch.id)} closeOnSelect className={menuItemClass}>
                                {branch.name}
                                <MenuItemIndicator keepMounted className={indicator}>
                                    <Icon name="check" />
                                </MenuItemIndicator>
                            </MenuRadioItem>
                        ))}
                    </MenuRadioGroup>
                </MenuContent>
            </Menu>
        </>
    );
}

const glyphs: Record<string, IconName> = { store: 'store', banknote: 'banknote', columns: 'columns-3', settings: 'settings', users: 'users', wallet: 'wallet', calculator: 'calculator', archive: 'archive' };

/** The grid button: one tile per panel this person can open, then the planned apps */
export function Launcher({ tiles }: { tiles: Me['launcher'] }) {
    if (tiles.length === 0) {
        return null;
    }

    const tileClass = 'flex cursor-pointer flex-col items-center gap-1.5 rounded-lg p-2 text-center text-xs font-medium select-none data-highlighted:bg-muted data-disabled:cursor-not-allowed data-disabled:opacity-60';

    return (
        <Menu>
            <MenuTrigger aria-label="Switch panel" className={buttonClass('ghost', 'md', true)}>
                <Icon name="grip" className="size-5" />
            </MenuTrigger>
            <MenuContent className="grid w-72 max-w-[calc(100vw-2rem)] grid-cols-3 gap-1 rounded-xl border border-border bg-card p-2 text-foreground shadow-md" align="end" sideOffset={4}>
                {tiles.map((tile) => {
                    const inside = (
                        <>
                            <span className={cx('flex size-12 items-center justify-center rounded-xl bg-muted', tile.state === 'current' && 'ring-2 ring-primary')}>
                                <Icon name={glyphs[tile.glyph] ?? 'app-window'} className="size-6" />
                            </span>
                            {tile.label}
                            {tile.state === 'soon' && <span className="rounded-full bg-muted px-1.5 text-[0.6875rem] font-semibold">Soon</span>}
                            {tile.state === 'current' && <span className="sr-only">(current panel)</span>}
                        </>
                    );

                    return tile.href ? (
                        <MenuItem key={tile.key} value={tile.key} className={tileClass} render={<a href={tile.href} aria-current={tile.state === 'current' ? 'true' : undefined} />}>
                            {inside}
                        </MenuItem>
                    ) : (
                        <MenuItem key={tile.key} value={tile.key} disabled className={tileClass}>
                            {inside}
                        </MenuItem>
                    );
                })}
            </MenuContent>
        </Menu>
    );
}

const themes: { value: ThemeChoice; label: string; icon: IconName }[] = [
    { value: 'light', label: 'Light', icon: 'sun' },
    { value: 'dark', label: 'Dark', icon: 'moon' },
    { value: 'system', label: 'Match device', icon: 'monitor' },
];

export function UserMenu({ me }: { me: Me }) {
    const signOut = useRef<HTMLFormElement>(null);
    const theme = useTheme();

    return (
        <>
            <form ref={signOut} method="post" action={me.links.logout} hidden>
                <input type="hidden" name="_token" value={csrfToken()} />
            </form>
            <Menu presentation="auto">
                <MenuTrigger aria-label={`Account: ${me.user.name}`} className="flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-full border-0 bg-foreground p-0 text-background pointer-coarse:size-11">
                    <Icon name="user" className="size-5" />
                </MenuTrigger>
                <MenuContent className={cx(menuClass, 'w-60')} align="end" sideOffset={4}>
                    <MenuGroup>
                        <MenuGroupLabel className="block px-2 py-1.5">
                            <span className="block truncate text-sm font-semibold">{me.user.name}</span>
                            <span className="block text-xs font-normal text-muted-foreground">{me.user.roleLabel}</span>
                        </MenuGroupLabel>
                        {me.links.profile && (
                            <MenuItem value="profile" className={menuItemClass} render={<a href={me.links.profile} />}>
                                <Icon name="user" />
                                Profile
                            </MenuItem>
                        )}
                    </MenuGroup>
                    <MenuSeparator className="my-1 h-px bg-border" />
                    <MenuRadioGroup value={theme} onValueChange={(value) => setTheme(value as ThemeChoice)}>
                        <MenuGroupLabel className="block px-2 py-1.5 text-xs font-semibold text-muted-foreground">Appearance</MenuGroupLabel>
                        {themes.map((option) => (
                            <MenuRadioItem key={option.value} value={option.value} className={menuItemClass}>
                                <Icon name={option.icon} />
                                {option.label}
                                <MenuItemIndicator keepMounted className={indicator}>
                                    <Icon name="check" />
                                </MenuItemIndicator>
                            </MenuRadioItem>
                        ))}
                    </MenuRadioGroup>
                    <MenuSeparator className="my-1 h-px bg-border" />
                    <MenuItem value="site" className={menuItemClass} render={<a href={me.links.site} target="_blank" rel="noopener" />}>
                        <Icon name="external-link" />
                        View site
                    </MenuItem>
                    <MenuItem value="classic" className={menuItemClass} render={<a href={me.links.classic} />}>
                        <Icon name="undo-2" />
                        Current admin
                    </MenuItem>
                    <MenuItem value="sign-out" className={menuItemClass} onSelect={() => signOut.current!.requestSubmit()}>
                        <Icon name="log-out" />
                        Sign out
                    </MenuItem>
                </MenuContent>
            </Menu>
        </>
    );
}

/** A super admin viewing as someone else: stays on screen, with the way back */
export function ImpersonationBanner({ person }: { person: NonNullable<Me['impersonating']> }) {
    return (
        <div role="region" aria-label="Viewing as another person" className="flex flex-wrap items-center justify-between gap-2 border-b-2 border-stone-900 bg-amber-300 px-4 py-2 text-sm text-stone-900">
            <p className="min-w-0 flex-1 basis-60 break-words">
                You are viewing as <strong>{person.name}</strong> ({person.roleLabel}, {person.branches}). Everything you do is recorded as this person.
            </p>
            <form method="post" action={person.stopUrl}>
                <input type="hidden" name="_token" value={csrfToken()} />
                <button type="submit" className="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-md border-0 bg-stone-900 px-3 font-semibold text-white">
                    <Icon name="undo-2" />
                    Stop impersonating
                </button>
            </form>
        </div>
    );
}
