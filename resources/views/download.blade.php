<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="robots" content="noindex" />
    <meta name="theme-color" content="#0f1113" />
    <title>Get the 826 app | 826 Auto Aesthetic &amp; Protection</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" />
    <style>
        :root { --bg: #eceeef; --card: #ffffff; --text: #0f1214; --muted: #4a5258; --line: rgb(15 18 20 / 0.12); --accent: #0a6b69; --on-accent: #ffffff; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b0c0e; --card: #131518; --text: #eef0f1; --muted: #a4abb0; --line: rgb(238 240 241 / 0.09); --accent: #2ad3cb; --on-accent: #0b0c0e; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 30rem; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 28px 24px; }
        img { display: block; width: 72px; height: auto; margin-bottom: 16px; }
        h1 { margin: 0 0 8px; font-size: 1.5rem; line-height: 1.2; }
        p { margin: 0 0 12px; color: var(--muted); }
        .button { display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 0 22px; border-radius: 10px; background: var(--accent); color: var(--on-accent); font-weight: 700; text-decoration: none; }
        .button:focus-visible { outline: 2px solid var(--text); outline-offset: 3px; }
        dl { margin: 20px 0 0; display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; font-size: 0.875rem; }
        dt { color: var(--muted); }
        dd { margin: 0; overflow-wrap: anywhere; }
        .notes { white-space: pre-line; color: var(--text); margin-top: 16px; font-size: 0.9rem; }
    </style>
</head>
<body>
<main>
    <img src="{{ asset('brand/826-mark-240.webp') }}" alt="826" width="72" height="72" />
    <h1>The 826 app</h1>
    @if ($release)
        <p>Follow your car in the shop, book a service, and keep your rewards and warranty in one place. Android only for now.</p>
        <a class="button" href="{{ $release->downloadUrl() }}">Download for Android (version {{ $release->version }})</a>
        <dl>
            <dt>Version</dt><dd>{{ $release->version }}</dd>
            @if ($release->size)
                <dt>Size</dt><dd>{{ number_format($release->size / 1048576, 1) }} MB</dd>
            @endif
            @if ($release->sha256)
                <dt>SHA-256</dt><dd><code>{{ $release->sha256 }}</code></dd>
            @endif
        </dl>
        @if ($release->notes)
            <p class="notes">{{ $release->notes }}</p>
        @endif
        <p style="margin-top:16px;font-size:0.875rem">After downloading, open the file to install it. Your phone may ask you to allow installs from your browser.</p>
    @else
        <p>The Android app isn’t ready to download yet. Android only for now.</p>
    @endif
</main>
</body>
</html>
