@props([
    'variant' => 'info',
    'title' => null,
])

@php
    [$classes, $role, $label] = match ($variant) {
        'info' => ['border-info-200 bg-info-50 text-info-900 dark:border-info-800 dark:bg-info-950 dark:text-info-100', 'status', __('Informasi')],
        'success' => ['border-success-200 bg-success-50 text-success-900 dark:border-success-800 dark:bg-success-950 dark:text-success-100', 'status', __('Berhasil')],
        'warning' => ['border-warning-200 bg-warning-50 text-warning-900 dark:border-warning-800 dark:bg-warning-950 dark:text-warning-100', 'status', __('Peringatan')],
        'danger' => ['border-danger-200 bg-danger-50 text-danger-900 dark:border-danger-800 dark:bg-danger-950 dark:text-danger-100', 'alert', __('Kesalahan')],
    };
@endphp

<div role="{{ $role }}" {{ $attributes->class(['rounded-lg border p-4', $classes]) }}>
    <p class="text-sm font-semibold">
        @if ($title !== null)
            <span class="sr-only">{{ $label }}: </span>{{ $title }}
        @else
            {{ $label }}
        @endif
    </p>

    @if ($slot->isNotEmpty())
        <div class="mt-1 text-sm">{{ $slot }}</div>
    @endif
</div>
