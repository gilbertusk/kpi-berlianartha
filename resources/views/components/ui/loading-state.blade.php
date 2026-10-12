@props([
    'label' => null,
])

<div role="status" aria-live="polite" {{ $attributes->class('flex items-center justify-center gap-3 px-6 py-12 text-sm text-zinc-600 dark:text-zinc-400') }}>
    <svg class="size-5 animate-spin motion-reduce:animate-none" fill="none" viewBox="0 0 24 24" aria-hidden="true">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"></path>
    </svg>
    <span>{{ $label ?? __('Memuat data…') }}</span>
</div>
