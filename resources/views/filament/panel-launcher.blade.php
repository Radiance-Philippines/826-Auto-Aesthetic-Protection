{{-- Panel launcher: a grid button in the top bar that opens a card of tiles, one per panel this person can open, plus disabled "Soon" tiles for planned apps (super admin only). Hidden with a single panel. --}}
@php
    $user = auth()->user();
    $current = \Filament\Facades\Filament::getCurrentOrDefaultPanel()?->getId();
    $tiles = $user instanceof \App\Models\User ? \App\Support\AppLauncher::tiles($user, $current) : [];
    $icon = fn (array $tile) => \Filament\Support\generate_icon_html(constant(\Filament\Support\Icons\Heroicon::class.'::'.$tile['icon']));
@endphp

@if (\App\Support\AppLauncher::worthShowing($tiles))
    <style>
        [x-cloak] { display: none !important; }
        .pl { position: relative; display: flex; align-items: center; }
        .pl-btn { display: inline-flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; border: 0; border-radius: .5rem; background: transparent; color: #57534e; cursor: pointer; }
        .pl-btn:hover, .pl-btn[aria-expanded="true"] { background: rgba(120, 113, 108, .14); }
        .pl-btn:focus-visible, .pl-tile:focus-visible .pl-icon { outline: 2px solid #3d4148; outline-offset: 2px; }
        .pl-btn svg { width: 1.375rem; height: 1.375rem; }
        .pl-pop { position: fixed; z-index: 55; top: calc(var(--imp-h, 0px) + 4.25rem); left: 1rem; right: 1rem; max-width: 20rem; margin-inline: auto; padding: 1rem; border-radius: 1rem; background: #fff; color: #1c1917; border: 1px solid #e7e5e4; box-shadow: 0 12px 32px rgba(28, 25, 23, .18); animation: pl-in .14s ease-out; }
        @media (min-width: 640px) { .pl-pop { position: absolute; top: calc(100% + .5rem); left: auto; right: -.5rem; margin: 0; width: 19rem; } }
        .pl-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .5rem; margin: 0; padding: 0; list-style: none; }
        .pl-tile { display: flex; flex-direction: column; align-items: center; gap: .375rem; padding: .5rem .25rem; border-radius: .75rem; text-decoration: none; color: inherit; font-size: .8125rem; font-weight: 500; text-align: center; outline: none; }
        .pl-icon { display: flex; align-items: center; justify-content: center; width: 3.25rem; height: 3.25rem; border-radius: .875rem; background: #f5f5f4; color: #3d4148; }
        .pl-icon svg { width: 1.625rem; height: 1.625rem; }
        .pl-tile:hover .pl-icon, .pl-tile:focus-visible .pl-icon { background: #e7e5e4; }
        .pl-tile[aria-current="true"] .pl-icon { box-shadow: 0 0 0 2px #fff, 0 0 0 4px #3d4148; background: #e7e5e4; }
        .pl-soon { opacity: .55; cursor: not-allowed; }
        .pl-tag { margin-top: -.125rem; padding: 0 .375rem; border-radius: 999px; background: #e7e5e4; color: #57534e; font-size: .6875rem; line-height: 1.1rem; font-weight: 600; letter-spacing: .02em; }
        .dark .pl-tag { background: #3a3532; color: #d6d3d1; }
        .pl-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        .dark .pl-btn { color: #d6d3d1; }
        .dark .pl-btn:hover, .dark .pl-btn[aria-expanded="true"] { background: rgba(255, 255, 255, .1); }
        .dark .pl-btn:focus-visible, .dark .pl-tile:focus-visible .pl-icon { outline-color: #e7e5e4; }
        .dark .pl-pop { background: #1c1917; color: #fafaf9; border-color: rgba(255, 255, 255, .12); box-shadow: 0 12px 32px rgba(0, 0, 0, .55); }
        .dark .pl-icon { background: #292524; color: #e7e5e4; }
        .dark .pl-tile:hover .pl-icon, .dark .pl-tile:focus-visible .pl-icon { background: #3a3532; }
        .dark .pl-tile[aria-current="true"] .pl-icon { background: #3a3532; box-shadow: 0 0 0 2px #1c1917, 0 0 0 4px #e7e5e4; }
        @keyframes pl-in { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) { .pl-pop { animation: none; } }
    </style>

    <div
        class="pl"
        x-data="{
            open: false,
            toggle() { this.open = ! this.open; if (this.open) this.$nextTick(() => this.items()[0]?.focus()); },
            close(refocus = true) { this.open = false; if (refocus) this.$refs.button.focus(); },
            items() { return [...this.$refs.menu.querySelectorAll('a')]; },
            move(step) { const items = this.items(); const at = items.indexOf(document.activeElement); items[(at + step + items.length) % items.length]?.focus(); },
        }"
        x-on:keydown.escape="if (open) close()"
        x-on:click.outside="open = false"
        x-on:focusout="if (open && ! $el.contains($event.relatedTarget)) open = false"
    >
        <button type="button" class="pl-btn" x-ref="button" x-on:click="toggle()" aria-label="Switch panel" aria-haspopup="menu" x-bind:aria-expanded="open.toString()" aria-expanded="false">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                @foreach ([5, 12, 19] as $y)
                    @foreach ([5, 12, 19] as $x)
                        <circle cx="{{ $x }}" cy="{{ $y }}" r="1.9" />
                    @endforeach
                @endforeach
            </svg>
        </button>

        <div
            class="pl-pop"
            x-ref="menu"
            x-show="open"
            x-cloak
            style="display: none"
            role="menu"
            aria-label="Switch panel"
            x-on:keydown.arrow-right.prevent="move(1)"
            x-on:keydown.arrow-down.prevent="move(3)"
            x-on:keydown.arrow-left.prevent="move(-1)"
            x-on:keydown.arrow-up.prevent="move(-3)"
        >
            <ul class="pl-grid" role="none">
                @foreach ($tiles as $tile)
                    @if ($tile['state'] === \App\Support\AppLauncher::SOON)
                        <li role="none">
                            <span class="pl-tile pl-soon" role="menuitem" aria-disabled="true" tabindex="-1">
                                <span class="pl-icon">{{ $icon($tile) }}</span>
                                <span>{{ $tile['label'] }}<span class="pl-sr"> (coming soon)</span></span>
                                <span class="pl-tag" aria-hidden="true">Soon</span>
                            </span>
                        </li>
                    @else
                        @php($isCurrent = $tile['state'] === \App\Support\AppLauncher::CURRENT)
                        <li role="none">
                            <a
                                class="pl-tile"
                                role="menuitem"
                                href="{{ $isCurrent ? \Filament\Facades\Filament::getPanel($tile['key'])->getUrl() : \App\Support\Panels::switchUrl($tile['key'], request()) }}"
                                @if ($isCurrent) aria-current="true" @endif
                            >
                                <span class="pl-icon">{{ $icon($tile) }}</span>
                                <span>{{ $tile['label'] }}@if ($isCurrent)<span class="pl-sr"> (current panel)</span>@endif</span>
                            </a>
                        </li>
                    @endif
                @endforeach
            </ul>
        </div>
    </div>
@endif
