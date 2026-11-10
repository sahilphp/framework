{{-- Default error page shell shipped with the framework.
     Run `php sahil error:publish` to copy every error page into this
     application's resources/views/errors/ directory and customize it there. --}}
<!doctype html>
<html lang="{{ lang ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="generator" content="SahilPHP">
    <title>{{ status }} · @yield('title')</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f7fc;
            --glow-one: rgba(11, 111, 232, 0.18);
            --glow-two: rgba(99, 91, 255, 0.14);
            --surface: #ffffff;
            --text: #101828;
            --muted: #5b6675;
            --line: #e3e8ef;
            --brand: #0b6fe8;
            --brand-contrast: #ffffff;
            --tint: #eef4ff;
            --debug-bg: #0f172a;
            --debug-text: #dbe6f7;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #080d1a;
                --glow-one: rgba(76, 155, 255, 0.22);
                --glow-two: rgba(129, 121, 255, 0.18);
                --surface: #121a2b;
                --text: #eef2f9;
                --muted: #9dabc2;
                --line: #222c44;
                --brand: #4c9bff;
                --brand-contrast: #07101f;
                --tint: #182238;
                --debug-bg: #0a1122;
                --debug-text: #dbe6f7;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 40px 20px;
            background:
                radial-gradient(900px 520px at 12% -10%, var(--glow-one), transparent 70%),
                radial-gradient(760px 480px at 92% 8%, var(--glow-two), transparent 72%),
                var(--bg);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }

        .error {
            width: 100%;
            max-width: 620px;
            padding: 42px 44px 34px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 22px;
            box-shadow: 0 32px 80px -46px rgba(16, 24, 40, 0.55);
        }

        .error__eyebrow {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 22px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .error__dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--brand);
            box-shadow: 0 0 0 4px var(--tint);
        }

        .error__code {
            display: flex;
            align-items: baseline;
            gap: 14px;
            margin: 0 0 6px;
            font-size: 66px;
            font-weight: 700;
            line-height: 1;
            letter-spacing: -0.045em;
            font-variant-numeric: tabular-nums;
            color: var(--brand);
        }

        .error__code span {
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 6px 10px;
            border-radius: 999px;
            color: var(--muted);
            background: var(--tint);
        }

        .error h1 {
            margin: 14px 0 10px;
            font-size: 26px;
            line-height: 1.25;
            letter-spacing: -0.01em;
        }

        .error p {
            margin: 0 0 10px;
            font-size: 16px;
            line-height: 1.65;
            color: var(--muted);
        }

        .error__actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-top: 26px;
        }

        .error__button,
        .error__link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 18px;
            border-radius: 11px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            transition: transform 120ms ease, background-color 120ms ease;
        }

        .error__button {
            background: var(--brand);
            color: var(--brand-contrast);
        }

        .error__button:hover { background: var(--brand); filter: brightness(0.94); }

        .error__link {
            color: var(--text);
            border: 1px solid var(--line);
            background: transparent;
        }

        .error__link:hover { background: var(--tint); }

        .error__button:focus-visible,
        .error__link:focus-visible {
            outline: 2px solid var(--brand);
            outline-offset: 3px;
        }

        .error__debug {
            margin-top: 26px;
            padding: 18px 20px;
            border-radius: 14px;
            background: var(--debug-bg);
            color: var(--debug-text);
            overflow-wrap: anywhere;
        }

        .error__debug h2 {
            margin: 0 0 6px;
            font-size: 13px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: var(--debug-text);
            opacity: 0.72;
        }

        .error__debug p {
            margin: 0 0 12px;
            color: var(--debug-text);
            font-size: 15px;
        }

        .error__debug pre {
            margin: 0;
            font-size: 12.5px;
            line-height: 1.6;
            white-space: pre-wrap;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            opacity: 0.82;
        }

        .error__hint {
            margin: 22px 0 0;
            padding-top: 18px;
            border-top: 1px solid var(--line);
            font-size: 13px;
            line-height: 1.6;
        }

        @media (max-width: 520px) {
            body { padding: 24px 14px; }
            .error { padding: 30px 22px 24px; border-radius: 18px; }
            .error__code { font-size: 50px; gap: 10px; }
            .error h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
    <main class="error" role="main">
        <p class="error__eyebrow"><span class="error__dot" aria-hidden="true"></span>SahilPHP</p>
        <p class="error__code">{{ status }}<span>{{ reason }}</span></p>

        @yield('content')

        <div class="error__actions">
            @yield('actions')
            <a class="error__button" href="/">Back to home</a>
        </div>

        @if (debug)
            <section class="error__debug" aria-label="Debug details">
                <h2>{{ exception_class }}</h2>
                <p>{{ exception_message }}</p>
                <pre>{{ exception_file }}:{{ exception_line }}</pre>
            </section>
        @endif

        <p class="error__hint">If you keep seeing this page, contact the site administrator.</p>
    </main>
</body>
</html>
