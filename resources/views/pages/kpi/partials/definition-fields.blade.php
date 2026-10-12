{{-- Read-only view of one KPI definition version. $version: KpiDefinitionVersion, $strategyText: string --}}
@php
    use App\Modules\KpiGovernance\Domain\DefinitionContent;
    use App\Modules\KpiGovernance\Domain\Vocabulary;

    $vocabulary = [
        'evaluation_subject' => Vocabulary::EVALUATION_SUBJECTS,
        'unit' => Vocabulary::UNITS,
        'value_type' => Vocabulary::VALUE_TYPES,
        'direction' => Vocabulary::DIRECTIONS,
        'frequency' => Vocabulary::FREQUENCIES,
        'aggregation' => Vocabulary::AGGREGATIONS,
        'input_method' => Vocabulary::INPUT_METHODS,
        'evidence_requirement' => Vocabulary::EVIDENCE_REQUIREMENTS,
    ];
@endphp

<dl class="grid gap-x-6 gap-y-3 rounded-lg border border-zinc-200 bg-white p-4 sm:grid-cols-2 dark:border-zinc-700 dark:bg-zinc-900">
    @foreach (DefinitionContent::FIELDS as $field => $label)
        @continue(in_array($field, ['score_strategy_version'], true))
        <div class="min-w-0">
            <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ $label }}</dt>
            <dd class="text-sm break-words text-zinc-900 dark:text-zinc-100">
                @if ($field === 'score_parameters' || $field === 'score_strategy')
                    @if ($field === 'score_strategy')
                        {{ $version->score_strategy ? $version->score_strategy.' (v'.$version->score_strategy_version.')' : '—' }}
                    @else
                        {{ $strategyText }}
                    @endif
                @elseif (isset($vocabulary[$field]))
                    {{ Vocabulary::label($vocabulary[$field], $version->{$field}) }}
                @else
                    {{ $version->{$field} ?? '—' }}
                @endif
            </dd>
        </div>
    @endforeach
    <div>
        <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Berlaku sampai (eksklusif)') }}</dt>
        <dd class="text-sm">{{ $version->effective_until ?? __('Tanpa batas') }}</dd>
    </div>
    <div>
        <dt class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Alasan versi') }}</dt>
        <dd class="text-sm">{{ $version->change_reason ?? '—' }}</dd>
    </div>
</dl>
