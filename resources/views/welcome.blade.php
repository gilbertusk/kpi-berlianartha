<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => __('Selamat datang')])
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <main class="flex min-h-svh items-center justify-center p-6 md:p-10">
            <div class="flex w-full max-w-lg flex-col items-center gap-6 rounded-xl border border-zinc-200 bg-white p-8 text-center shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <span class="flex size-12 items-center justify-center rounded-md bg-brand-700 text-white dark:bg-brand-400 dark:text-brand-950">
                    <x-app-logo-icon class="size-7 fill-current" />
                </span>

                <div class="flex flex-col gap-2">
                    <h1 class="text-2xl font-semibold">{{ config('app.name') }}</h1>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('Sistem manajemen KPI internal PT Berlian Artha Label. Akses hanya untuk pengguna yang berwenang.') }}
                    </p>
                </div>

                @auth
                    <x-ui.button :href="route('dashboard')">{{ __('Buka Dashboard') }}</x-ui.button>
                @else
                    <x-ui.button :href="route('login')">{{ __('Masuk') }}</x-ui.button>
                @endauth
            </div>
        </main>
    </body>
</html>
