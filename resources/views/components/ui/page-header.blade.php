@props([
    'title',
    'description' => null,
])

<header {{ $attributes->class('flex flex-col gap-4 border-b border-zinc-200 pb-4 sm:flex-row sm:items-end sm:justify-between dark:border-zinc-800') }}>
    <div class="min-w-0">
        <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ $title }}</h1>

        @if ($description)
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap gap-2">{{ $actions }}</div>
    @endisset
</header>
