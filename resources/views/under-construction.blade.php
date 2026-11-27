<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#050607">
    <meta name="robots" content="noindex">
    <title>Under construction | 826</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @vite('resources/js/construction.ts')
    {{-- The home page's night bay, in plain CSS: the lights, the car driving in once, then the words --}}
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html { color-scheme: dark; }
        body { margin: 0; min-height: 100dvh; display: flex; flex-direction: column; overflow-x: hidden; text-align: center; background: linear-gradient(180deg, #050607 0%, #0a0c0e 30%, #16191c 55%, #0b0d0f 66%, #060708 100%); color: #eef0f1; font-family: 'Archivo Variable', system-ui, sans-serif; line-height: 1.5; }
        header { position: relative; z-index: 1; padding: max(1.25rem, env(safe-area-inset-top)) 1rem 0; }
        .logo { width: 8rem; height: auto; -webkit-user-select: none; user-select: none; touch-action: manipulation; }
        main { flex: 1; display: flex; flex-direction: column; justify-content: center; padding: 0 1rem 2rem; }
        .lights { display: block; width: min(100%, 76rem); height: auto; margin: 0 auto -2rem; mask-image: linear-gradient(180deg, transparent, #000 35%); animation: lights 1.2s ease-out both; }

        .car { position: relative; width: min(94vw, 44rem, 100dvh); aspect-ratio: 1800 / 594; margin: 0 auto; animation: drive 2.6s cubic-bezier(0.2, 0.7, 0.2, 1) both; }
        .car img { position: absolute; display: block; }
        .body { inset: 0; width: 100%; height: 100%; }
        .wheel { height: auto; animation: roll 2.6s cubic-bezier(0.2, 0.7, 0.2, 1) both; }
        .shadow { position: absolute; left: 3%; right: 3%; bottom: -4%; height: 14%; background: radial-gradient(50% 50% at 50% 50%, rgb(0 0 0 / 0.92), transparent 70%); }
        .reflection { position: absolute; left: 0; top: 94%; width: 100%; transform: scaleY(-1); opacity: 0.25; mask-image: linear-gradient(to top, #000, transparent 34%); }

        h1 { margin: clamp(2rem, 7dvh, 4.5rem) 0 0.5rem; font-size: clamp(1.9rem, 6.5vw, 3.75rem); font-style: italic; font-weight: 800; font-stretch: 125%; line-height: 1.1; text-transform: uppercase; animation: rise 0.7s 1.9s ease-out both; }
        .lead { margin: 0; color: #9ca4a8; animation: rise 0.7s 2.1s ease-out both; }

        form[hidden] { display: none; }
        form { width: 100%; max-width: 22rem; margin: 1.5rem auto 0; display: grid; gap: 0.5rem; text-align: left; }
        label { font-weight: 600; }
        ui-input { display: block; }
        input, button { width: 100%; min-height: 2.75rem; border-radius: 0.6rem; font: inherit; }
        input { padding: 0 0.875rem; background: #181b1e; color: inherit; border: 1px solid #6f777b; }
        input[aria-invalid="true"] { border-color: #ff8a80; }
        input:focus-visible, button:focus-visible, a:focus-visible { outline: 2px solid #2ad3cb; outline-offset: 2px; }
        button { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; margin-top: 0.5rem; border: 0; background: #2ad3cb; color: #032120; font-weight: 700; cursor: pointer; }
        button:hover { background: #66e4de; }
        .hint, .error { margin: 0; font-size: 0.875rem; }
        .hint { color: #9ca4a8; }
        .error { color: #ff8a80; }
        footer { padding: 0.75rem 1rem max(0.75rem, env(safe-area-inset-bottom)); font-size: 0.75rem; color: #7c8488; }
        footer a { color: inherit; }

        @media (max-width: 639px) { .lights { margin-bottom: 0.5rem; } }

        @keyframes drive { from { transform: translateX(calc(-50vw - 60%)); } }
        @keyframes roll { from { transform: rotate(-1080deg); } }
        @keyframes lights { from { opacity: 0.2; } }
        @keyframes rise { from { opacity: 0; transform: translateY(0.9rem); } }

        /* Less motion wanted: the car is already parked */
        @media (prefers-reduced-motion: reduce) {
            .lights, .car, .wheel, h1, .lead { animation: none; }
        }
    </style>
</head>
<body>
    @php($car = 'cars/bmw-7-series-side-black')
    <header>
        <img class="logo" data-logo src="{{ asset('brand/826-mark.webp') }}" alt="826 Auto Aesthetic &amp; Protection">
    </header>

    <main>
        <svg class="lights" viewBox="0 0 1000 220" aria-hidden="true" focusable="false">
            <g fill="none" stroke="#e9f4f4" stroke-linecap="round">
                <path stroke-opacity="0.12" stroke-width="12" d="M80 24H920M165 66H835M236 100H764M281 126H719M325 150H675M0 0L325 168M1000 0L675 168"/>
                <path stroke-opacity="0.75" stroke-width="2.5" d="M80 24H920M165 66H835M236 100H764"/>
                <path stroke-opacity="0.5" stroke-width="2" d="M281 126H719M325 150H675M325 168H675M0 0L325 168M1000 0L675 168"/>
            </g>
        </svg>

        <div class="car" aria-hidden="true">
            <span class="shadow"></span>
            <img class="reflection" src="{{ asset($car.'-600.webp') }}" alt="" width="1800" height="594">
            <picture>
                <source type="image/avif" srcset="{{ asset($car.'-1000.avif') }}">
                <img class="body" src="{{ asset($car.'-1000.webp') }}" alt="" width="1800" height="594" fetchpriority="high">
            </picture>
            {{-- Where the wheels sit, as fractions of the picture: public/cars/manifest.json --}}
            @foreach (['front' => 0.84127, 'rear' => 0.21518] as $wheel => $cx)
                <img class="wheel" src="{{ asset($car."-wheel-{$wheel}.webp") }}" alt="" style="left: {{ ($cx - 0.06783) * 100 }}%; top: {{ (0.7475 - 0.06783 * 1800 / 594) * 100 }}%; width: {{ 0.06783 * 200 }}%;">
            @endforeach
        </div>

        <h1>Under construction</h1>
        <p class="lead">The 826 website is not open yet.</p>

        {{-- Out of sight until the team asks for it (construction.ts); shown again after a wrong PIN --}}
        <form method="post" action="{{ route('site.unlock') }}" data-pin @if (! $errors->has('pin')) hidden @endif>
            @csrf
            <label for="pin">Team PIN</label>
            <ui-input>
                <input id="pin" name="pin" type="password" @if ($errors->has('pin')) autofocus @endif inputmode="numeric" autocomplete="off" required maxlength="64"
                    aria-describedby="pin-hint{{ $errors->has('pin') ? ' pin-error' : '' }}" @if ($errors->has('pin')) aria-invalid="true" @endif>
            </ui-input>
            <p class="hint" id="pin-hint">For the 826 team only.</p>
            @error('pin')
                <p class="error" id="pin-error" role="alert">{{ $message }}</p>
            @enderror
            <button type="submit">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                Open the site
            </button>
        </form>
    </main>

    <footer>Car photo: <a href="https://commons.wikimedia.org/wiki/File:BMW_G70_740i_sDrive_M_Sport_Black_Sapphire_Metallic_(167).jpg">Damian B Oh, CC BY-SA 4.0</a>, background removed</footer>
</body>
</html>
