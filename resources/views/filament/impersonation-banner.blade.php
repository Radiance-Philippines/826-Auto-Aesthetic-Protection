{{-- A super admin viewing as someone else: sticky on every panel page, the same high-contrast amber in light and dark --}}
@if (auth()->check() && \App\Support\Impersonation::active())
    @php
        $target = auth()->user();
        $branches = \App\Support\Impersonation::branchesLabel($target);
    @endphp

    <style>
        .imp-banner { position: sticky; top: 0; z-index: 60; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem 1rem; padding: .625rem 1rem; background: #fcd34d; color: #1c1917; border-bottom: 2px solid #1c1917; font-size: .875rem; line-height: 1.35; }
        .imp-banner__text { flex: 1 1 18rem; margin: 0; min-width: 0; overflow-wrap: anywhere; }
        .imp-banner__stop { display: inline-flex; align-items: center; gap: .375rem; padding: .4rem .75rem; min-height: 2.5rem; border: 0; border-radius: .5rem; background: #1c1917; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        .imp-banner__stop:hover { background: #44403c; }
        .imp-banner__stop:focus-visible { outline: 3px solid #fff; outline-offset: 2px; box-shadow: 0 0 0 5px #1c1917; }
        .imp-banner__stop svg { width: 1.25rem; height: 1.25rem; flex: none; }
        .fi-topbar-ctn { top: var(--imp-h, 0px) !important; }
        @media (min-width: 1024px) { .fi-sidebar { top: var(--imp-h, 0px) !important; height: calc(100dvh - var(--imp-h, 0px)) !important; } }
    </style>

    <div class="imp-banner" role="region" aria-label="Viewing as another person" x-data x-init="const set = () => document.documentElement.style.setProperty('--imp-h', $el.offsetHeight + 'px'); set(); new ResizeObserver(set).observe($el)">
        <p class="imp-banner__text">
            You are viewing as <strong>{{ $target->name }}</strong> ({{ $target->roleLabel() }}, {{ $branches }}). Everything you do is recorded as this person.
        </p>
        <form method="POST" action="{{ route('impersonation.stop') }}">
            @csrf
            <button type="submit" class="imp-banner__stop">
                {{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedArrowUturnLeft) }}
                Stop impersonating
            </button>
        </form>
    </div>
@endif
