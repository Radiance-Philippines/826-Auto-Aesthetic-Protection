{{-- A branch's services: UI SDK checkboxes in a plain form, one per branch in view --}}
<x-filament-panels::page>
    @php($groups = $this->services())

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Tick what the branch offers. Only ticked services can be booked there. Names, prices and details are set by the super admin.
    </p>

    @forelse ($this->branches() as $branch)
        <x-filament::section :heading="$branch->name">
            <form method="post" action="{{ route('panel.branch.services', $branch->getKey()) }}" class="space-y-5" wire:ignore>
                @csrf
                @php($offered = $branch->services->pluck('id')->all())

                @forelse ($groups as $category => $services)
                    <fieldset>
                        <legend class="mb-2 text-sm font-medium">{{ $category }}</legend>
                        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach ($services as $service)
                                <label class="offer-row">
                                    <ui-checkbox name="services[]" value="{{ $service->id }}" @if (in_array($service->id, $offered, true)) default-checked @endif>
                                        <ui-checkbox-indicator>{{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::Check) }}</ui-checkbox-indicator>
                                    </ui-checkbox>
                                    <span>{{ $service->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @empty
                    <p class="text-sm">No services yet. The super admin adds them in Shop → Services.</p>
                @endforelse

                @if ($groups->isNotEmpty())
                    <x-filament::button type="submit" icon="heroicon-o-check">Save services</x-filament::button>
                @endif
            </form>
        </x-filament::section>
    @empty
        <p class="text-sm">You’re not assigned to a branch yet. Ask the super admin to add you to one.</p>
    @endforelse

    @vite('resources/js/branch-services.ts')
</x-filament-panels::page>
