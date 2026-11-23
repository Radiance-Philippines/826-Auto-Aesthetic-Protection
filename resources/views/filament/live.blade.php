{{-- Live updates for the signed-in team (Radiance Flow); nothing is loaded until the ticket secret is set --}}
@if (auth()->check() && \App\Support\Live\FlowLive::ticketsEnabled())
    <meta name="flow-live-ticket" content="{{ route('live.ticket') }}">
    @vite('resources/js/admin-live.ts')
@endif
