<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0f1113">
    <meta name="robots" content="noindex">
    <title>Under construction | 826</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @vite('resources/js/construction.ts')
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html { color-scheme: dark; }
        body { margin: 0; min-height: 100dvh; display: grid; place-items: center; padding: 1.5rem; background: #0f1113; color: #eceeef; font-family: system-ui, sans-serif; line-height: 1.5; }
        main { width: 100%; max-width: 22rem; text-align: center; }
        img { width: 11rem; height: auto; }
        h1 { margin: 1.5rem 0 0.25rem; font-size: 1.75rem; }
        p { margin: 0; color: #a9b1b5; }
        form[hidden] { display: none; }
        img { -webkit-user-select: none; user-select: none; touch-action: manipulation; }
        form { margin-top: 2rem; display: grid; gap: 0.5rem; text-align: left; }
        label { font-weight: 600; }
        ui-input { display: block; }
        input, button { width: 100%; min-height: 2.75rem; border-radius: 0.6rem; font: inherit; }
        input { padding: 0 0.875rem; background: #181b1e; color: inherit; border: 1px solid #6f777b; }
        input[aria-invalid="true"] { border-color: #ff8a80; }
        input:focus-visible, button:focus-visible { outline: 2px solid #2ad3cb; outline-offset: 2px; }
        button { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; margin-top: 0.5rem; border: 0; background: #2ad3cb; color: #032120; font-weight: 700; cursor: pointer; }
        button:hover { background: #66e4de; }
        .hint { font-size: 0.875rem; }
        .error { color: #ff8a80; font-size: 0.875rem; }
    </style>
</head>
<body>
    <main>
        <img src="{{ asset('brand/826-mark.webp') }}" alt="826 Auto Aesthetic &amp; Protection">
        <h1>Under construction</h1>
        <p>The 826 website is not open yet.</p>

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
</body>
</html>
