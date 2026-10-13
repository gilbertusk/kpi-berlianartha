{{-- Tabs of one period. $period: AssessmentPeriod. Links follow the server-side policy. --}}
@php
    $sections = array_filter([
        ['route' => 'periods.show', 'label' => __('Periode dan penugasan'), 'allowed' => true],
        ['route' => 'periods.targets', 'label' => __('Target'), 'allowed' => auth()->user()->can('viewTargets', $period)],
        ['route' => 'periods.targets.import', 'label' => __('Import target'), 'allowed' => auth()->user()->can('manageTargets', $period)],
    ], fn (array $section): bool => $section['allowed']);
@endphp

<nav aria-label="{{ __('Navigasi periode') }}" class="border-b border-zinc-200 dark:border-zinc-700">
    <ul class="-mb-px flex flex-wrap gap-x-4 text-sm">
        @foreach ($sections as $section)
            @php($current = request()->routeIs($section['route']))
            <li>
                <a
                    href="{{ route($section['route'], $period) }}"
                    wire:navigate
                    @class([
                        'inline-block border-b-2 px-1 py-2 font-medium',
                        'border-brand-700 text-brand-800 dark:border-brand-300 dark:text-brand-200' => $current,
                        'border-transparent text-zinc-700 hover:border-zinc-400 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white' => ! $current,
                    ])
                    @if ($current) aria-current="page" @endif
                >{{ $section['label'] }}</a>
            </li>
        @endforeach
    </ul>
</nav>
