{{-- "Sign in with a passkey" under the password form on /admin/login --}}
@include('filament.passkeys.script')

<div
    x-data="window.passkeys826.login({ optionsUrl: @js(route('passkeys.options')), loginUrl: @js(route('passkeys.login')), token: @js(csrf_token()) })"
    x-show="ok"
    x-cloak
    class="mt-6 grid gap-3"
>
    <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400" aria-hidden="true">
        <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
        or
        <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
    </div>

    <x-filament::button type="button" color="gray" outlined icon="heroicon-o-finger-print" class="w-full" x-on:click="signIn()" x-bind:disabled="busy">
        Sign in with a passkey
    </x-filament::button>

    <p x-show="message" x-text="message" role="alert" class="text-sm text-danger-600 dark:text-danger-400"></p>
</div>
