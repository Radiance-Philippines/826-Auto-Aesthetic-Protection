{{-- The branch the person is working in (#695): UI SDK menu, "All branches" or one. --}}
@php
    use App\Support\CurrentBranch;
    use Filament\Support\Icons\Heroicon;

    $user = auth()->user();
    $branches = $user ? CurrentBranch::options($user) : [];
    $chosen = $user ? CurrentBranch::for($user) : null;
    $locked = $user && ! $user->isOwner() && count($branches) === 1;
    $label = $locked ? reset($branches) : ($branches[$chosen] ?? 'All branches');
    $store = \Filament\Support\generate_icon_html(Heroicon::OutlinedBuildingStorefront);
@endphp

@if ($branches !== [])
    <div data-branch-selector wire:ignore wire:key="branch-selector-{{ $chosen ?? 'all' }}">
        @if ($locked)
            <span class="branch-trigger">{{ $store }}<span class="truncate">{{ $label }}</span></span>
        @else
            <form method="post" action="{{ route('panel.branch') }}" hidden>
                @csrf
                <input type="hidden" name="branch" value="{{ $chosen }}">
            </form>
            <ui-menu>
                <ui-menu-trigger>
                    <button type="button" class="branch-trigger" aria-label="Branch: {{ $label }}">
                        {{ $store }}<span class="truncate">{{ $label }}</span>{{ \Filament\Support\generate_icon_html(Heroicon::OutlinedChevronUpDown) }}
                    </button>
                </ui-menu-trigger>
                <ui-menu-content class="branch-menu" align="end">
                    <ui-menu-radio-group default-value="{{ $chosen ?? 'all' }}">
                        <ui-menu-group-label>Branch</ui-menu-group-label>
                        @foreach (['all' => 'All branches'] + $branches as $id => $name)
                            <ui-menu-radio-item value="{{ $id }}" close-on-select>
                                <ui-menu-item-indicator keep-mounted>{{ \Filament\Support\generate_icon_html(Heroicon::Check) }}</ui-menu-item-indicator>
                                {{ $name }}
                            </ui-menu-radio-item>
                        @endforeach
                    </ui-menu-radio-group>
                </ui-menu-content>
            </ui-menu>
        @endif
    </div>
    @vite('resources/js/admin-branch.ts')
@endif
