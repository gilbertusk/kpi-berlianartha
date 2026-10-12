@props([
    'status' => 'neutral',
])

{{-- The slot text carries the meaning; color and symbol are redundant cues (NFR 007). --}}
@php
    [$classes, $symbol] = match ($status) {
        'neutral' => ['bg-zinc-100 text-zinc-800 ring-zinc-300 dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-600', '•'],
        'info' => ['bg-info-50 text-info-800 ring-info-300 dark:bg-info-950 dark:text-info-200 dark:ring-info-700', 'i'],
        'success' => ['bg-success-50 text-success-800 ring-success-300 dark:bg-success-950 dark:text-success-200 dark:ring-success-700', '✓'],
        'warning' => ['bg-warning-50 text-warning-900 ring-warning-300 dark:bg-warning-950 dark:text-warning-200 dark:ring-warning-700', '!'],
        'danger' => ['bg-danger-50 text-danger-800 ring-danger-300 dark:bg-danger-950 dark:text-danger-200 dark:ring-danger-700', '✕'],
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset', $classes]) }}>
    <span aria-hidden="true">{{ $symbol }}</span>
    {{ $slot }}
</span>
