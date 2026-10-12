@props([
    'title' => null,
    'description' => null,
])

<div role="alert" {{ $attributes->class('flex flex-col items-center justify-center gap-2 px-6 py-12 text-center') }}>
    <svg class="size-10 text-danger-600 dark:text-danger-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
    </svg>

    <p class="text-base font-semibold text-zinc-900 dark:text-white">{{ $title ?? __('Data tidak dapat dimuat') }}</p>

    <p class="max-w-md text-sm text-zinc-600 dark:text-zinc-400">
        {{ $description ?? __('Terjadi kesalahan. Coba lagi beberapa saat lagi atau hubungi administrator bila masalah berlanjut.') }}
    </p>

    @if ($slot->isNotEmpty())
        <div class="mt-2">{{ $slot }}</div>
    @endif
</div>
