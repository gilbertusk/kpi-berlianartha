@props([
    'variant' => 'primary',
    'type' => 'button',
    'href' => null,
])

@php
    $variantClasses = match ($variant) {
        'primary' => 'bg-brand-700 text-white hover:bg-brand-800 dark:bg-brand-400 dark:text-brand-950 dark:hover:bg-brand-300',
        'secondary' => 'border border-zinc-300 bg-white text-zinc-800 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700',
        'danger' => 'bg-danger-700 text-white hover:bg-danger-800',
        'ghost' => 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-700',
    };

    $classes = 'inline-flex min-h-10 items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 '.$variantClasses;
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
