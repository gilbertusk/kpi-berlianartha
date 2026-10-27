@props([
    'title',
    'description' => null,
])

<section {{ $attributes->class('rounded-xl border border-zinc-200 bg-white p-4 sm:p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900') }}>
    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">{{ $title }}</h2>

    @if ($description)
        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $description }}</p>
    @endif

    <div class="mt-4">{{ $slot }}</div>
</section>
