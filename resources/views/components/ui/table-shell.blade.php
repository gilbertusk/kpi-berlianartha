@props([
    'caption',
    'columns' => [],
])

<div {{ $attributes->class('overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900') }}>
    <div class="overflow-x-auto">
        <table class="ui-table min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
            <caption class="sr-only">{{ $caption }}</caption>

            <thead class="bg-zinc-50 dark:bg-zinc-800">
                <tr>
                    @foreach ($columns as $column)
                        <th scope="col" class="px-4 py-3 text-start font-semibold text-zinc-600 dark:text-zinc-300">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>

            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                {{-- Livewire adds comment markers around loops, so an empty loop is not an empty string. --}}
                @if (! $slot->hasActualContent())
                    <tr>
                        <td colspan="{{ max(count($columns), 1) }}" class="p-0">{{ $empty ?? '' }}</td>
                    </tr>
                @else
                    {{ $slot }}
                @endif
            </tbody>
        </table>
    </div>
</div>
