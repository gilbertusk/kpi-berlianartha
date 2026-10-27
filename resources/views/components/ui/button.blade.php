@props([
    'variant' => 'primary',
    'type' => 'button',
    'href' => null,
])

@php
    $variantClasses = match ($variant) {
        'primary' => 'bg-brand-600 text-white hover:bg-brand-700 dark:bg-brand-400 dark:text-brand-950 dark:hover:bg-brand-300 shadow-sm',
        'secondary' => 'border border-zinc-200 bg-white text-zinc-900 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700',
        'danger' => 'bg-danger-600 text-white hover:bg-danger-700 dark:bg-danger-500 dark:hover:bg-danger-400 shadow-sm',
        'ghost' => 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700',
    };

    $classes = 'inline-flex min-h-10 items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 '.$variantClasses;
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
