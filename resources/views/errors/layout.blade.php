<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">

        <title>@yield('title') - {{ config('app.name') }}</title>

        {{-- Self-contained on purpose: error pages must render even when the
             asset build, database, or session is unavailable. Never output
             exception messages, traces, or configuration here. --}}
        <style>
            :root { color-scheme: light dark; }
            * { box-sizing: border-box; }
            body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem; font-family: ui-sans-serif, system-ui, sans-serif; background: #fafafa; color: #171717; }
            main { width: 100%; max-width: 32rem; padding: 2rem; border: 1px solid #e5e5e5; border-radius: 0.75rem; background: #ffffff; text-align: center; }
            .code { margin: 0; font-size: 0.875rem; font-weight: 600; letter-spacing: 0.05em; color: #1d4ed8; }
            h1 { margin: 0.5rem 0 0; font-size: 1.5rem; }
            p { margin: 0.75rem 0 0; line-height: 1.5; color: #525252; }
            a { display: inline-block; margin-top: 1.5rem; padding: 0.625rem 1rem; border-radius: 0.375rem; background: #1d4ed8; color: #ffffff; font-weight: 500; text-decoration: none; }
            a:hover { background: #1e40af; }
            a:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
            @media (prefers-color-scheme: dark) {
                body { background: #0a0a0a; color: #f5f5f5; }
                main { background: #171717; border-color: #404040; }
                .code { color: #93c5fd; }
                p { color: #a3a3a3; }
            }
        </style>
    </head>
    <body>
        <main>
            <p class="code">{{ __('Kode') }} @yield('code')</p>
            <h1>@yield('title')</h1>
            <p>@yield('message')</p>
            <a href="{{ url('/') }}">{{ __('Kembali ke beranda') }}</a>
        </main>
    </body>
</html>
