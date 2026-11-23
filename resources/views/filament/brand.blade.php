{{-- Admin brand: the 826 mark (public/brand) beside the rest of the panel's name ("Owner", "Sales", "Operations") --}}
@php($brandName = (string) filament()->getBrandName())
<span class="app-brand">
    @if (is_file(public_path(\App\Http\Controllers\SiteController::LOGO)))
        <img class="app-brand-logo" src="{{ asset(\App\Http\Controllers\SiteController::LOGO) }}" alt="826" />
        <span class="app-brand-name"><span class="app-brand-rest">{{ \Illuminate\Support\Str::after($brandName, '826 ') }}</span></span>
    @else
        <span class="app-brand-name">826 <span class="app-brand-rest">{{ \Illuminate\Support\Str::after($brandName, '826 ') }}</span></span>
    @endif
</span>
