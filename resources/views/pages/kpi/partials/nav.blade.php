@php
    use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiDefinition;
    use App\Modules\KpiGovernance\Infrastructure\Persistence\Models\KpiTemplate;

    // Links are shown only when the server-side policy allows the page.
    $sections = array_filter([
        ['route' => 'kpi.definitions', 'label' => __('Kamus KPI'), 'allowed' => auth()->user()->can('viewAny', KpiDefinition::class)],
        ['route' => 'kpi.templates', 'label' => __('Template scorecard'), 'allowed' => auth()->user()->can('viewAny', KpiTemplate::class)],
    ], fn (array $section): bool => $section['allowed']);
@endphp

<nav aria-label="{{ __('Navigasi KPI') }}" class="border-b border-zinc-200 dark:border-zinc-700">
    <ul class="-mb-px flex flex-wrap gap-x-4 text-sm">
        @foreach ($sections as $section)
            @php($current = request()->routeIs($section['route'].'*'))
            <li>
                <a
                    href="{{ route($section['route']) }}"
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
