{{-- Profile → Passkeys: this person's passkeys, adding one, and their recent security activity --}}
@include('filament.passkeys.script')

@php
    $passkeys = $this->passkeys();
    $events = $this->securityEvents();
    $allowed = $this->passkeysAllowed();
@endphp

<div
    x-data="window.passkeys826.manage({})"
    x-on:passkeys-changed.window="sync()"
    class="grid gap-6"
>
    @unless ($allowed)
        <p class="text-sm text-gray-600 dark:text-gray-300">Passkey sign-in is turned off by a super admin right now (Settings, Security). Your passkeys are kept and work again when it is turned back on.</p>
    @endunless

    @if ($passkeys->isEmpty())
        <p class="text-sm text-gray-600 dark:text-gray-300">No passkeys yet. Add one for each device you sign in on.</p>
    @else
        <ul class="divide-y divide-gray-200 rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10" aria-label="Your passkeys">
            @foreach ($passkeys as $passkey)
                <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" wire:key="passkey-{{ $passkey->id }}">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $passkey->name }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Added {{ $passkey->created_at->timezone(config('app.timezone'))->format('M j, Y') }}
                            ·
                            {{ $passkey->last_used_at ? 'Last used '.$passkey->last_used_at->diffForHumans() : 'Not used to sign in yet' }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-filament::button size="sm" color="gray" icon="heroicon-m-pencil-square" wire:click="mountAction('renamePasskey', { passkey: {{ $passkey->id }} })">Rename</x-filament::button>
                        <x-filament::button size="sm" color="danger" icon="heroicon-m-trash" wire:click="mountAction('removePasskey', { passkey: {{ $passkey->id }} })">Remove</x-filament::button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($allowed)
        <div x-show="ok" x-cloak class="grid gap-2">
            <label for="passkey-name" class="text-sm font-medium text-gray-950 dark:text-white">Name for this device (optional)</label>
            <div class="flex flex-wrap items-start gap-3">
                <div class="w-full max-w-xs">
                    <x-filament::input.wrapper>
                        <x-filament::input id="passkey-name" type="text" maxlength="60" placeholder="Work laptop" x-model="name" x-on:keydown.enter.prevent="add()" />
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button type="button" icon="heroicon-m-plus" x-on:click="add()" x-bind:disabled="busy">Add a passkey</x-filament::button>
            </div>
            <p x-show="message" x-text="message" role="alert" class="text-sm text-danger-600 dark:text-danger-400"></p>
        </div>
        <p x-show="!ok" x-cloak class="text-sm text-gray-600 dark:text-gray-300">This browser can't make passkeys here (they need https or localhost, and a recent browser).</p>
    @endif

    <div class="grid gap-2">
        <h3 class="text-sm font-medium text-gray-950 dark:text-white">Recent security activity</h3>
        @if ($events->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-300">Nothing yet.</p>
        @else
            <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10" aria-label="Recent security activity">
                @foreach ($events as $event)
                    <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2">
                        <span class="text-gray-950 dark:text-white">
                            {{ $event->label() }}@if ($event->note): <span class="text-gray-600 dark:text-gray-300">{{ $event->note }}</span>@endif
                            @if ($event->actor)<span class="text-gray-500 dark:text-gray-400"> (by {{ $event->actor->name }})</span>@endif
                        </span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $event->created_at->timezone(config('app.timezone'))->format('M j, g:i A') }}@if ($event->ip) · {{ $event->ip }}@endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
